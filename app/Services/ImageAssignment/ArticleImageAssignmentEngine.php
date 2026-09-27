<?php

namespace App\Services\ImageAssignment;

use App\Http\Controllers\Helpers\CodigoDeBarrasRealHelper;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Models\Article;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\User;
use App\Services\ArticleImageValidationService;
use App\Services\ImageSearch\ImageSearchProvider;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use App\Services\Traits\BusquedaDeImagenesEnGoogle;
use App\Services\Traits\GoogleSearchHelpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * El motor de la asignación inteligente de imágenes, artículo por artículo (misión
 * imagenes-catalogo-completo, 27/9/2026). Busca la MEJOR imagen, no la primera aceptable.
 *
 * Por artículo, en orden (plan §6.6):
 *   1. Si el artículo ya no existe → no_asignada / articulo_borrado.
 *   2. Si su código de barras es REAL (CodigoDeBarrasRealHelper) → búsqueda por código →
 *      candidatas (CandidateImageProcessor: descartes gratis, descarga, tamaño y fondo medidos) →
 *      IA comparando hasta 4 por llamada (ArticleImageValidationService::evaluar_candidatas).
 *   3. Ranking entre las candidatas (ver clave_de_orden()): primero las que se pueden asignar
 *      solas, después TAMAÑO (≥800 ideal, ≥600 bueno, ≥400 aceptable), después FONDO BLANCO
 *      (el orden que pidió Lucas: "primero tamaño, segundo fondo blanco"), después la confianza
 *      de la IA, menos problemas y la posición.
 *   4. La ganadora se ASIGNA sola solo si la IA dijo "si" con confianza alta, sin marca de agua,
 *      texto encima, collage ni "otro producto", y con lado menor ≥ 600 px. Si no, va A REVISAR.
 *   5. Si por código salió una asignable, listo. Si solo salió una para revisar, o nada, se busca
 *      por NOMBRE (+ marca) y gana la mejor de las dos. Decisión del plan: una búsqueda más a
 *      cambio de menos revisión manual.
 *   6. Nada → no_asignada con el motivo principal y el detalle de cada criterio.
 *   7. Asignada → fila de `images` + sincronización con Tienda Nube (igual que el job viejo). A
 *      revisar → solo el archivo `imgcand_*` y la fila del item: la tienda no la ve hasta aprobarla.
 *   8. Siempre: búsquedas, validaciones con IA, diagnóstico y procesado_at en el item, y los
 *      contadores de la asignación con incrementos atómicos.
 *
 * El cupo diario: si la asignación aplica el tope diario (selección / asistente), cada búsqueda
 * que el proveedor respondió bien se descuenta del contador del día del dueño (consumir_cuota() del
 * trait, el mismo de siempre) y no se busca si no queda cupo. Las de "todo el catálogo" (acceso
 * maestro) no lo tocan: si lo tocaran, esa mañana el dueño se quedaría sin búsqueda manual.
 *
 * 🔴 Todo lo que escribe va por el dueño de la asignación, explícito: en el worker no hay Auth y
 * UserHelper::userId() devolvería el USER_ID de config, o sea otro comercio.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class ArticleImageAssignmentEngine
{
    use GoogleSearchHelpers, BusquedaDeImagenesEnGoogle;

    const CRITERIO_CODIGO = 'codigo_de_barras';
    const CRITERIO_NOMBRE = 'nombre';

    /**
     * Techo de llamadas a la IA por artículo (plan §6.5). Cada llamada compara hasta 4 candidatas;
     * con dos criterios alcanza para una tanda por criterio y una más si la primera no sirvió.
     */
    const MAX_LLAMADAS_IA_POR_ARTICULO = 3;

    /** Niveles de tamaño del ranking, por lado menor en píxeles. */
    const LADO_IDEAL     = 800;
    const LADO_BUENO     = 600;
    const LADO_ACEPTABLE = 400;

    /** Lado menor mínimo para asignar sola una imagen (debajo va a revisar como "algo chica"). */
    const LADO_MINIMO_PARA_ASIGNAR = 600;

    /** Largo máximo de la consulta por nombre (nombre + marca). */
    const LARGO_MAXIMO_CONSULTA_POR_NOMBRE = 120;

    /** Problemas que la IA puede marcar y que impiden asignar sola una imagen. */
    const PROBLEMAS_QUE_IMPIDEN_ASIGNAR = ['marca_de_agua', 'texto_superpuesto', 'collage', 'otro_producto'];

    /**
     * Prioridad del motivo principal de una no asignada cuando hubo dos criterios (contrato §5.2):
     * el más alto gana.
     */
    const PRIORIDAD_MOTIVO_NO_ASIGNADA = [
        'no_corresponden'   => 6,
        'imagenes_chicas'   => 5,
        'no_descargables'   => 4,
        'sin_resultados'    => 3,
        'error_de_busqueda' => 2,
        'sin_datos'         => 1,
    ];

    /**
     * Motivos de "a revisar", en orden de prioridad (el primero que aplica es el principal; todos
     * los que aplican van a los avisos).
     */
    const MOTIVOS_A_REVISAR_EN_ORDEN = [
        'sin_validacion_ia',
        'ia_dudosa',
        'confianza_media',
        'marca_de_agua',
        'texto_superpuesto',
        'collage',
        'imagen_algo_chica',
    ];

    /** Aviso de las asignadas con fondo que no es blanco (contrato §5.2). */
    const AVISO_FONDO_NO_BLANCO = 'Fondo no blanco';

    /** @var \App\Models\ImageAssignmentRun */
    protected $run;

    /** @var \App\Models\User Dueño del comercio. */
    protected $owner;

    /** @var \App\Services\ImageSearch\ImageSearchProvider */
    protected $proveedor;

    /** @var \App\Services\ImageAssignment\CandidateImageProcessor */
    protected $procesador;

    /** @var \App\Services\ArticleImageValidationService */
    protected $validador;

    /** @var int Dueño (lo lee el trait BusquedaDeImagenesEnGoogle para el contador del día). */
    protected $user_id;

    /** @var string No se usa acá (lo declara el trait por la búsqueda de Google). */
    protected $google_api_key = '';

    /** @var string No se usa acá (idem). */
    protected $cx = '';

    /**
     * @param \App\Models\ImageAssignmentRun                         $run
     * @param \App\Models\User|null                                  $owner       Null = el dueño de la asignación.
     * @param \App\Services\ImageSearch\ImageSearchProvider|null     $proveedor   Null = el de la asignación.
     * @param \App\Services\ImageAssignment\CandidateImageProcessor|null $procesador
     * @param \App\Services\ArticleImageValidationService|null       $validador
     */
    public function __construct(
        ImageAssignmentRun $run,
        $owner = null,
        ImageSearchProvider $proveedor = null,
        CandidateImageProcessor $procesador = null,
        ArticleImageValidationService $validador = null
    ) {
        $this->run     = $run;
        $this->owner   = $owner instanceof User ? $owner : User::find($run->user_id);
        $this->user_id = (int) $run->user_id;

        if (is_null($this->owner)) {
            throw new \RuntimeException('La asignación '.$run->id.' no tiene dueño: el usuario '.$run->user_id.' no existe.');
        }

        // Una sola instancia del validador por motor: su techo de llamadas es por instancia.
        $this->validador  = is_null($validador) ? new ArticleImageValidationService() : $validador;
        $this->procesador = is_null($procesador) ? new CandidateImageProcessor($this->validador) : $procesador;
        $this->proveedor  = is_null($proveedor) ? ImageSearchProviderFactory::para($this->owner, $run->proveedor) : $proveedor;
    }

    /**
     * Procesa un artículo de la asignación de punta a punta y deja el item cerrado.
     *
     * @param  \App\Models\ImageAssignmentItem $item  Ya reclamado por el job (status procesando).
     * @return array {
     *     status:             string, el estado final del item.
     *     cupo_agotado:       bool,   se quedó sin cupo diario (el job corta la asignación).
     *     error_de_proveedor: bool,   todas las búsquedas de este artículo fallaron por el proveedor.
     * }
     */
    public function procesar(ImageAssignmentItem $item)
    {
        $article = Article::where('id', $item->article_id)
            ->where('user_id', $this->run->user_id)
            ->first();

        $contexto = $this->contexto_nuevo($article);

        if (is_null($article)) {
            return $this->cerrar($item, $contexto, [
                'status'         => ImageAssignmentItem::STATUS_NO_ASIGNADA,
                'motivo'         => 'articulo_borrado',
                'motivo_detalle' => 'El artículo se borró antes de que le llegara el turno.',
            ]);
        }

        $codigo_cargado  = trim((string) $article->bar_code);
        $codigo          = CodigoDeBarrasRealHelper::evaluar($codigo_cargado, $article);
        $consulta_nombre = $this->consulta_por_nombre($article);

        // Sin nombre ni código real no hay con qué buscar, y no se gasta nada.
        if (!$codigo['real'] && $consulta_nombre === '') {
            $contexto['diagnostico'][self::CRITERIO_CODIGO] = $this->criterio_no_usado(self::CRITERIO_CODIGO, $codigo_cargado, $codigo['motivo']);
            $contexto['diagnostico'][self::CRITERIO_NOMBRE] = $this->criterio_no_usado(self::CRITERIO_NOMBRE, '', 'El artículo no tiene nombre.');

            return $this->cerrar($item, $contexto, [
                'status'         => ImageAssignmentItem::STATUS_NO_ASIGNADA,
                'motivo'         => 'sin_datos',
                'motivo_detalle' => 'No hay con qué buscar: el artículo no tiene nombre ni un código de barras real. '.$codigo['motivo'],
            ]);
        }

        // Criterio 1: código de barras, solo si es REAL (pedido de Lucas).
        if ($codigo['real']) {
            $this->buscar_por_criterio(self::CRITERIO_CODIGO, $codigo['normalizado'], $contexto);
        } else {
            $contexto['diagnostico'][self::CRITERIO_CODIGO] = $this->criterio_no_usado(self::CRITERIO_CODIGO, $codigo_cargado, $codigo['motivo']);
        }

        $ganadora = $this->elegir_ganadora($contexto['pozo']);

        // Criterio 2: nombre, salvo que por código ya haya salido una para asignar sola.
        if (!is_null($ganadora) && $ganadora['asignable']) {
            $contexto['diagnostico'][self::CRITERIO_NOMBRE] = $this->criterio_no_usado(self::CRITERIO_NOMBRE, $consulta_nombre, 'No hizo falta: por código de barras ya salió una imagen para asignar.');
        } elseif ($consulta_nombre === '') {
            $contexto['diagnostico'][self::CRITERIO_NOMBRE] = $this->criterio_no_usado(self::CRITERIO_NOMBRE, '', 'El artículo no tiene nombre.');
        } elseif ($contexto['cupo_agotado']) {
            $contexto['diagnostico'][self::CRITERIO_NOMBRE] = $this->criterio_no_usado(self::CRITERIO_NOMBRE, $consulta_nombre, 'No se buscó: se agotó el cupo diario de búsquedas.');
        } else {
            $this->buscar_por_criterio(self::CRITERIO_NOMBRE, $consulta_nombre, $contexto);
            $ganadora = $this->elegir_ganadora($contexto['pozo']);
        }

        if (!is_null($ganadora)) {
            return $this->resolver_con_ganadora($item, $article, $ganadora, $contexto);
        }

        // Sin ganadora y sin cupo: si ni siquiera se llegó a buscar, el artículo queda sin procesar
        // (se puede volver a mandar mañana); si alcanzó a gastar una búsqueda, es una no asignada.
        if ($contexto['cupo_agotado']) {
            if ($contexto['busquedas'] === 0) {
                return $this->cerrar($item, $contexto, [
                    'status'         => ImageAssignmentItem::STATUS_SIN_PROCESAR,
                    'motivo'         => 'sin_cupo',
                    'motivo_detalle' => 'No se llegó a buscar: se agotó el cupo diario de búsquedas.',
                ], false);
            }

            return $this->cerrar($item, $contexto, [
                'status'         => ImageAssignmentItem::STATUS_NO_ASIGNADA,
                'motivo'         => 'sin_cupo',
                'motivo_detalle' => $this->detalle_de_no_asignada($contexto).' Se agotó el cupo diario de búsquedas antes de terminar.',
            ]);
        }

        return $this->cerrar($item, $contexto, [
            'status'         => ImageAssignmentItem::STATUS_NO_ASIGNADA,
            'motivo'         => $this->motivo_principal_de_no_asignada($contexto),
            'motivo_detalle' => $this->detalle_de_no_asignada($contexto),
        ]);
    }

    /* ----------------------------------------------------------------------------------------
     * Búsqueda por criterio
     * -------------------------------------------------------------------------------------- */

    /**
     * Una búsqueda (por código o por nombre) con todo lo que le sigue: candidatas, IA en tandas de
     * 4 hasta encontrar una asignable, y el diagnóstico del criterio. Deja en `$contexto['pozo']`
     * las candidatas que la IA no descartó, para el ranking entre criterios.
     *
     * @param  string $criterio
     * @param  string $consulta
     * @param  array  $contexto  (por referencia)
     * @return void
     */
    protected function buscar_por_criterio($criterio, $consulta, array &$contexto)
    {
        $entrada = [
            'criterio'        => $criterio,
            'consulta'        => (string) $consulta,
            'usado'           => true,
            'motivo_no_usado' => null,
            'busquedas'       => 0,
            'resultados'      => 0,
            'error'           => null,
            'resumen'         => '',
            'candidatas'      => [],
        ];

        if ($this->run->aplica_tope_diario && !$this->queda_cupo()) {
            $contexto['cupo_agotado'] = true;
            $contexto['diagnostico'][$criterio] = $this->criterio_no_usado($criterio, $consulta, 'No se buscó: se agotó el cupo diario de búsquedas.');

            return;
        }

        $respuesta = $this->proveedor->buscar($consulta);

        $contexto['busquedas_intentadas']++;

        if (!$respuesta['ok']) {
            // No cuenta como búsqueda: el proveedor no la cobró (misma regla que consumir_cuota()).
            $contexto['errores_de_proveedor']++;
            $entrada['error'] = (string) $respuesta['error'];
            $contexto['diagnostico'][$criterio] = $entrada;

            return;
        }

        // Respondió bien: cuenta, aunque haya venido vacía (el proveedor la cobró igual).
        $entrada['busquedas'] = 1;
        $contexto['busquedas']++;

        if ($criterio === self::CRITERIO_CODIGO) {
            $contexto['busquedas_codigo']++;
        } else {
            $contexto['busquedas_nombre']++;
        }

        $this->sumar_a_la_asignacion([
            'busquedas' => 1,
            ($criterio === self::CRITERIO_CODIGO ? 'busquedas_codigo' : 'busquedas_nombre') => 1,
        ]);

        $this->descontar_del_cupo_diario();

        $resultados = isset($respuesta['resultados']) && is_array($respuesta['resultados']) ? $respuesta['resultados'] : [];
        $entrada['resultados'] = count($resultados);

        if (empty($resultados)) {
            $contexto['diagnostico'][$criterio] = $entrada;

            return;
        }

        $preparadas = $this->procesador->preparar($resultados, $contexto['urls_vistas']);
        $contexto['urls_vistas'] = $preparadas['urls_vistas'];

        $candidatas = $preparadas['candidatas'];
        $restantes  = $preparadas['listas'];

        // IA en tandas de hasta 4, de la más grande a la más chica, hasta encontrar una asignable.
        while (!empty($restantes)) {
            if ($contexto['llamadas_ia'] >= self::MAX_LLAMADAS_IA_POR_ARTICULO) {
                $this->marcar_sin_revisar($candidatas, $restantes, 'No se llegó a revisar con IA: ya se habían hecho '.self::MAX_LLAMADAS_IA_POR_ARTICULO.' consultas para este artículo.');
                break;
            }

            $tanda = array_splice($restantes, 0, ArticleImageValidationService::MAX_CANDIDATAS_POR_LLAMADA);

            $para_la_ia = [];

            foreach (array_values($tanda) as $posicion_en_tanda => $lista) {
                $para_la_ia[] = [
                    'indice'     => $posicion_en_tanda + 1,
                    'base64'     => $lista['base64'],
                    'media_type' => $lista['media_type'],
                ];
            }

            $veredicto = $this->validador->evaluar_candidatas($para_la_ia, $contexto['article'], $this->run->user_id);

            $contexto['llamadas_ia']++;

            // Validaciones = llamadas que Anthropic respondió (las que se pagan).
            if ($veredicto['llamada_hecha']) {
                $contexto['validaciones']++;
                $this->sumar_a_la_asignacion(['validaciones_ia' => 1]);
            }

            $hay_asignable = false;

            foreach (array_values($tanda) as $posicion_en_tanda => $lista) {
                $ia = isset($veredicto['resultados'][$posicion_en_tanda + 1])
                    ? $veredicto['resultados'][$posicion_en_tanda + 1]
                    : ['es_el_producto' => 'sin_evaluar', 'confianza' => null, 'fondo_blanco' => null, 'problemas' => [], 'motivo' => 'La IA no dio un veredicto para esta imagen.'];

                $clave = $lista['clave'];

                if ($ia['es_el_producto'] === 'no') {
                    $candidatas[$clave]['resultado'] = 'ia_no_corresponde';
                    $candidatas[$clave]['motivo']    = (string) $ia['motivo'];
                    continue;
                }

                $del_pozo = $this->candidata_del_pozo($criterio, $candidatas[$clave], $lista, $ia);

                // Resultado provisional; el definitivo (elegida / alternativa) se marca al final.
                $candidatas[$clave]['resultado'] = $del_pozo['grupo'] === 3 ? 'alternativa' : ($del_pozo['grupo'] === 2 ? 'ia_dudosa' : 'no_evaluada');
                $candidatas[$clave]['motivo']    = (string) $ia['motivo'];

                $contexto['pozo'][] = $del_pozo;

                if ($del_pozo['asignable']) {
                    $hay_asignable = true;
                }
            }

            if ($hay_asignable) {
                $this->marcar_sin_revisar($candidatas, $restantes, 'No hizo falta revisarla: ya había una imagen para asignar.');
                break;
            }

            // La IA no respondió: otra tanda sería otra llamada que tampoco va a responder.
            if (!$veredicto['evaluada']) {
                $this->marcar_sin_revisar($candidatas, $restantes, 'No se llegó a revisar: la IA no respondió.');
                break;
            }
        }

        $entrada['candidatas'] = $candidatas;
        $contexto['diagnostico'][$criterio] = $entrada;
    }

    /**
     * Marca como "no_evaluada" las candidatas listas que no llegaron a la IA.
     *
     * @param  array  $candidatas  (por referencia) las del diagnóstico del criterio.
     * @param  array  $listas      las que quedaron sin revisar.
     * @param  string $motivo
     * @return void
     */
    protected function marcar_sin_revisar(array &$candidatas, array $listas, $motivo)
    {
        foreach ($listas as $lista) {
            $candidatas[$lista['clave']]['resultado'] = 'no_evaluada';
            $candidatas[$lista['clave']]['motivo']    = $motivo;
        }
    }

    /**
     * Arma la candidata del pozo (la que compite en el ranking) con el veredicto de la IA ya
     * interpretado: el grupo, si se puede asignar sola y por qué habría que revisarla.
     *
     * Grupos: 3 = la IA dijo "si" (con la confianza que sea: el plan ordena ENTRE LAS "SI" por
     * tamaño, fondo y recién después confianza); 2 = "dudoso"; 1 = sin evaluar. Una "si" con
     * confianza baja compite como "si" pero, si gana, va a revisar como dudosa (la regla
     * anti-complacencia manda "low" cuando no puede confirmar).
     *
     * @param  string $criterio
     * @param  array  $candidata  La del diagnóstico (url, pagina, dominio, posicion).
     * @param  array  $lista      La del procesador (ancho, alto, fondo, binario).
     * @param  array  $ia         Veredicto de evaluar_candidatas().
     * @return array
     */
    protected function candidata_del_pozo($criterio, array $candidata, array $lista, array $ia)
    {
        $veredicto = (string) $ia['es_el_producto'];
        $confianza = $ia['confianza'];
        $problemas = isset($ia['problemas']) && is_array($ia['problemas']) ? array_values($ia['problemas']) : [];
        $lado      = min((int) $lista['ancho'], (int) $lista['alto']);

        if ($veredicto === 'si') {
            $grupo = 3;
        } elseif ($veredicto === 'dudoso') {
            $grupo = 2;
        } else {
            $grupo = 1;
        }

        $bloqueantes = array_values(array_intersect($problemas, self::PROBLEMAS_QUE_IMPIDEN_ASIGNAR));

        $asignable = $veredicto === 'si'
            && $confianza === 'high'
            && empty($bloqueantes)
            && $lado >= self::LADO_MINIMO_PARA_ASIGNAR;

        // Por qué habría que revisarla (solo importa si no es asignable).
        $motivos = [];

        if ($grupo === 1) {
            $motivos[] = 'sin_validacion_ia';
        }

        // "si" con confianza baja: la regla anti-complacencia manda "low" cuando no puede confirmar,
        // así que para revisar es lo mismo que "dudoso".
        if ($grupo === 2 || ($veredicto === 'si' && $confianza !== 'high' && $confianza !== 'medium')) {
            $motivos[] = 'ia_dudosa';
        }

        if ($veredicto === 'si' && $confianza === 'medium') {
            $motivos[] = 'confianza_media';
        }

        foreach (['marca_de_agua', 'texto_superpuesto', 'collage'] as $problema) {
            if (in_array($problema, $problemas, true)) {
                $motivos[] = $problema;
            }
        }

        if ($lado < self::LADO_MINIMO_PARA_ASIGNAR) {
            $motivos[] = 'imagen_algo_chica';
        }

        return [
            'criterio'           => $criterio,
            'clave'              => $lista['clave'],
            'posicion'           => (int) $lista['posicion'],
            'url'                => isset($candidata['url']) ? $candidata['url'] : '',
            'pagina'             => isset($candidata['pagina']) ? $candidata['pagina'] : '',
            'dominio'            => isset($candidata['dominio']) ? $candidata['dominio'] : '',
            'ancho'              => (int) $lista['ancho'],
            'alto'               => (int) $lista['alto'],
            'lado'               => $lado,
            'fondo_blanco'       => (bool) $lista['fondo_blanco'],
            'fondo_blanco_ratio' => $lista['fondo_blanco_ratio'],
            'binario'            => $lista['binario'],
            'ia'                 => [
                'veredicto' => $veredicto,
                'confianza' => $confianza,
                'problemas' => $problemas,
                'motivo'    => (string) $ia['motivo'],
            ],
            'grupo'              => $grupo,
            'asignable'          => $asignable,
            'motivos_revision'   => $motivos,
        ];
    }

    /**
     * La mejor candidata del pozo, o null si no hay ninguna.
     *
     * @param  array $pozo
     * @return array|null
     */
    protected function elegir_ganadora(array $pozo)
    {
        $mejor = null;

        foreach ($pozo as $candidata) {
            if (is_null($mejor) || $this->clave_de_orden($candidata) > $this->clave_de_orden($mejor)) {
                $mejor = $candidata;
            }
        }

        return $mejor;
    }

    /**
     * Clave del ranking (se compara elemento por elemento; más alto gana).
     *
     * 🔴 "asignable" va PRIMERO, antes que el tamaño, aunque el plan diga "tamaño → fondo → ..." y
     * parezca que se lo saltea: ese orden es para elegir ENTRE las que la IA dio por buenas. Sin
     * esta clave, una foto de 1200 px con marca de agua le ganaba a una limpia de 700 px que se podía
     * asignar sola, y el artículo terminaba a revisar — justo lo que la búsqueda por nombre existe
     * para evitar ("una búsqueda más a cambio de menos revisión manual").
     *
     * Después: grupo del veredicto (si > dudoso > sin evaluar) y, adentro de cada grupo, el orden
     * del plan §6.6: nivel de TAMAÑO, FONDO BLANCO medido, confianza de la IA, menos problemas; a
     * igualdad, el criterio (la del código de barras, que es una búsqueda más precisa) y la posición
     * en los resultados.
     *
     * @param  array $candidata
     * @return array
     */
    protected function clave_de_orden(array $candidata)
    {
        return [
            $candidata['asignable'] ? 1 : 0,
            (int) $candidata['grupo'],
            $this->nivel_de_tamano($candidata['lado']),
            $candidata['fondo_blanco'] ? 1 : 0,
            $this->nivel_de_confianza($candidata['ia']['confianza']),
            -count($candidata['ia']['problemas']),
            $candidata['criterio'] === self::CRITERIO_CODIGO ? 1 : 0,
            -(int) $candidata['posicion'],
        ];
    }

    /**
     * @param  int $lado  Lado menor en px.
     * @return int  3 ideal (≥800), 2 bueno (≥600), 1 aceptable (≥400), 0 menos.
     */
    protected function nivel_de_tamano($lado)
    {
        if ($lado >= self::LADO_IDEAL) {
            return 3;
        }

        if ($lado >= self::LADO_BUENO) {
            return 2;
        }

        return $lado >= self::LADO_ACEPTABLE ? 1 : 0;
    }

    /**
     * @param  string|null $confianza
     * @return int
     */
    protected function nivel_de_confianza($confianza)
    {
        if ($confianza === 'high') {
            return 3;
        }

        if ($confianza === 'medium') {
            return 2;
        }

        return $confianza === 'low' ? 1 : 0;
    }

    /* ----------------------------------------------------------------------------------------
     * Cierre
     * -------------------------------------------------------------------------------------- */

    /**
     * Con ganadora: se guarda el archivo y el item queda asignada (con su fila de `images`) o a
     * revisar (solo el archivo imgcand_*).
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @param  \App\Models\Article             $article
     * @param  array                           $ganadora
     * @param  array                           $contexto
     * @return array
     */
    protected function resolver_con_ganadora(ImageAssignmentItem $item, Article $article, array $ganadora, array $contexto)
    {
        $this->marcar_resultados_finales($contexto, $ganadora);

        $asignable = (bool) $ganadora['asignable'];

        // Asignada: el nombre de siempre (<time><rand>.webp). A revisar: imgcand_<uuid>.webp, que
        // se renombra al aprobarla (el prefijo la distingue de las imágenes reales del storage).
        $archivo = $asignable
            ? time().rand(1, 100000).'.webp'
            : ImageAssignmentItem::PREFIJO_CANDIDATA.(string) Str::uuid().'.webp';

        try {
            $guardada = $this->procesador->guardar_final($ganadora['binario'], $archivo);
        } catch (\Throwable $e) {
            Log::warning('[ImagenesInteligentes] No se pudo guardar la imagen elegida.', [
                'run_id'     => $this->run->id,
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->cerrar($item, $contexto, [
                'status'         => ImageAssignmentItem::STATUS_NO_ASIGNADA,
                'motivo'         => 'error_interno',
                'motivo_detalle' => 'Se eligió una imagen pero no se pudo guardar en el servidor: '.Str::limit($e->getMessage(), 200, '…'),
            ]);
        }

        $avisos = $this->avisos_de($ganadora, $asignable);

        $meta = [
            'ancho'              => $ganadora['ancho'],
            'alto'               => $ganadora['alto'],
            'fondo_blanco'       => $ganadora['fondo_blanco'],
            'fondo_blanco_ratio' => $ganadora['fondo_blanco_ratio'],
            'dominio'            => $ganadora['dominio'],
            'pagina'             => $ganadora['pagina'],
            'url_original'       => $ganadora['url'],
            'lado_final'         => $guardada['lado'],
            'ia'                 => $ganadora['ia'],
            'avisos'             => $avisos,
        ];

        $etiqueta_criterio = $this->etiqueta_de_criterio($ganadora['criterio']);
        $medidas           = $ganadora['ancho'].'×'.$ganadora['alto'].' px';

        if ($asignable) {
            $detalle = 'Se asignó una imagen de '.$medidas.' encontrada buscando por '.$etiqueta_criterio.'.'
                .($ganadora['fondo_blanco'] ? '' : ' El fondo no es blanco.');

            try {
                // El closure queda atado a $this (PHP >= 5.4), así que puede llamar a cerrar() aunque sea protegido.
                return DB::transaction(function () use ($item, $article, $contexto, $ganadora, $guardada, $meta, $detalle) {
                    $imagen = Image::create([
                        'hosting_url'    => $guardada['url'],
                        'imageable_id'   => $article->id,
                        'imageable_type' => 'article',
                    ]);

                    /*
                     * Marca para Tienda Nube, igual que el job viejo. 🔴 SIN `timestamps = false`, a
                     * diferencia del job viejo: el save() tiene que actualizar updated_at para que el
                     * sync incremental del front (sync_articles.js) vuelva a bajar el artículo con su
                     * imagen nueva (mismo motivo documentado en ImageController::setImage()).
                     */
                    $article->needs_sync_with_tn = true;
                    $article->save();

                    TiendaNubeSyncArticleService::add_article_to_sync($article);

                    return $this->cerrar($item, $contexto, [
                        'status'         => ImageAssignmentItem::STATUS_ASIGNADA,
                        'motivo'         => null,
                        'motivo_detalle' => $detalle,
                        'criterio_usado' => $ganadora['criterio'],
                        'imagen_url'     => $guardada['url'],
                        'imagen_archivo' => $guardada['archivo'],
                        'imagen_meta'    => $meta,
                        'image_id'       => $imagen->id,
                    ]);
                });
            } catch (\Throwable $e) {
                // El cierre se deshizo (el artículo ya era de otro tramo, o falló la base): la fila
                // de images no quedó, así que el archivo recién guardado no lo usa nadie.
                $this->borrar_archivo_sin_usar($guardada['archivo']);

                throw $e;
            }
        }

        $motivo = $this->motivo_principal_de_revision($ganadora);

        $detalle = 'Buscando por '.$etiqueta_criterio.' apareció una imagen de '.$medidas.', pero hay que revisarla: '
            .$this->lista_legible($this->avisos_en_minuscula($avisos)).'.';

        try {
            return $this->cerrar($item, $contexto, [
                'status'         => ImageAssignmentItem::STATUS_A_REVISAR,
                'motivo'         => $motivo,
                'motivo_detalle' => $detalle,
                'criterio_usado' => $ganadora['criterio'],
                'imagen_url'     => $guardada['url'],
                'imagen_archivo' => $guardada['archivo'],
                'imagen_meta'    => $meta,
            ]);
        } catch (\Throwable $e) {
            // Mismo caso: el item no quedó apuntando a esta candidata.
            $this->borrar_archivo_sin_usar($guardada['archivo']);

            throw $e;
        }
    }

    /**
     * Borra del storage un archivo recién guardado cuyo cierre se deshizo (si no, queda un .webp
     * huérfano que ninguna fila de images ni ningún item nombra).
     *
     * @param  string $archivo
     * @return void
     */
    protected function borrar_archivo_sin_usar($archivo)
    {
        if (!is_string($archivo) || $archivo === '') {
            return;
        }

        try {
            Storage::disk('public')->delete($archivo);
        } catch (\Throwable $e) {
            Log::warning('[ImagenesInteligentes] No se pudo borrar un archivo que quedó sin usar.', [
                'run_id'  => $this->run->id,
                'archivo' => $archivo,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Marca en el diagnóstico la elegida y, entre las demás del pozo, las que también servían.
     *
     * @param  array $contexto  (por referencia)
     * @param  array $ganadora
     * @return void
     */
    protected function marcar_resultados_finales(array &$contexto, array $ganadora)
    {
        foreach ($contexto['pozo'] as $candidata) {
            $criterio = $candidata['criterio'];
            $clave    = $candidata['clave'];

            if (!isset($contexto['diagnostico'][$criterio]['candidatas'][$clave])) {
                continue;
            }

            if ($criterio === $ganadora['criterio'] && $clave === $ganadora['clave']) {
                $contexto['diagnostico'][$criterio]['candidatas'][$clave]['resultado'] = 'elegida';
                continue;
            }

            if ($candidata['grupo'] === 3) {
                $contexto['diagnostico'][$criterio]['candidatas'][$clave]['resultado'] = 'alternativa';
                $contexto['diagnostico'][$criterio]['candidatas'][$clave]['motivo']    = 'También era el producto, pero se eligió otra (más grande, con fondo más blanco o con menos problemas).';
            }
        }
    }

    /**
     * Cierra el item y suma sus números a la asignación, en una sola transacción: un tramo que
     * muere entre las dos escrituras no puede dejar el item cerrado sin sumar (o al revés).
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @param  array $contexto
     * @param  array $datos             status, motivo, motivo_detalle y lo que corresponda.
     * @param  bool  $cuenta_procesado  false para `sin_procesar` (no llegó a pasar por el motor).
     * @return array  Lo que devuelve procesar().
     */
    protected function cerrar(ImageAssignmentItem $item, array $contexto, array $datos, $cuenta_procesado = true)
    {
        $diagnostico = $this->diagnostico_final($contexto);

        $run_id = (int) $this->run->id;
        $ahora  = Carbon::now();

        DB::transaction(function () use ($item, $contexto, $datos, $cuenta_procesado, $diagnostico, $run_id, $ahora) {
            /*
             * 🔴 Solo se cierra si el artículo sigue siendo de ESTE tramo. Si mientras se procesaba
             * lo devolvió a pendiente una reanudación (o el failed() de otro tramo) y lo reclamó
             * otro tramo, cerrarlo acá lo dejaría con dos imágenes. La excepción deshace también la
             * fila de images de una asignada (esto corre adentro de su misma transacción).
             */
            $actual = ImageAssignmentItem::where('id', $item->id)->lockForUpdate()->first(['id', 'status', 'tramo']);

            if (is_null($actual)
                || $actual->status !== ImageAssignmentItem::STATUS_PROCESANDO
                || (string) $actual->tramo !== (string) $item->tramo) {
                throw new \RuntimeException('El artículo '.$item->article_id.' ya no es de este tramo: no se cierra dos veces.');
            }

            $item->fill(array_merge([
                'motivo'          => null,
                'motivo_detalle'  => null,
                'criterio_usado'  => null,
                'imagen_url'      => null,
                'imagen_archivo'  => null,
                'imagen_meta'     => null,
                'image_id'        => null,
            ], $datos, [
                'busquedas'       => min(255, (int) $contexto['busquedas']),
                'validaciones_ia' => min(255, (int) $contexto['validaciones']),
                'diagnostico'     => $diagnostico,
                'procesado_at'    => $ahora,
            ]));
            $item->save();

            // Incremento atómico: un tramo reanudado no pisa lo que sumó el anterior. Las búsquedas y
            // las validaciones ya se sumaron en el momento (sumar_a_la_asignacion()).
            DB::table('image_assignment_runs')
                ->where('id', $run_id)
                ->update([
                    'procesados'       => DB::raw('procesados + '.($cuenta_procesado ? 1 : 0)),
                    'last_progress_at' => $ahora,
                    'updated_at'       => $ahora,
                ]);
        });

        Log::info('[ImagenesInteligentes] Artículo procesado.', [
            'run_id'       => $run_id,
            'article_id'   => (int) $item->article_id,
            'status'       => $item->status,
            'motivo'       => $item->motivo,
            'busquedas'    => (int) $contexto['busquedas'],
            'validaciones' => (int) $contexto['validaciones'],
        ]);

        return [
            'status'             => (string) $item->status,
            'cupo_agotado'       => (bool) $contexto['cupo_agotado'],
            'error_de_proveedor' => $contexto['busquedas_intentadas'] > 0
                && $contexto['errores_de_proveedor'] === $contexto['busquedas_intentadas'],
        ];
    }

    /* ----------------------------------------------------------------------------------------
     * Textos y diagnóstico
     * -------------------------------------------------------------------------------------- */

    /**
     * El diagnóstico tal como se guarda: una entrada por criterio (primero código, después nombre),
     * con el resumen armado y las candidatas como lista.
     *
     * @param  array $contexto
     * @return array
     */
    protected function diagnostico_final(array $contexto)
    {
        $final = [];

        foreach ([self::CRITERIO_CODIGO, self::CRITERIO_NOMBRE] as $criterio) {
            if (!isset($contexto['diagnostico'][$criterio])) {
                continue;
            }

            $entrada = $contexto['diagnostico'][$criterio];

            // Las candidatas como lista y sin la `clave` interna (el índice con que el motor las
            // cruza entre el procesador y la IA): lo que se guarda es exactamente el contrato §5.2.
            $candidatas = [];

            foreach ($entrada['candidatas'] as $candidata) {
                unset($candidata['clave']);
                $candidatas[] = $candidata;
            }

            $entrada['candidatas'] = $candidatas;
            $entrada['resumen']    = $this->resumen_de_criterio($entrada);

            $final[] = $entrada;
        }

        return $final;
    }

    /**
     * Frase del resultado de UN criterio: "10 resultados: 3 muy chicas, 2 no se pudieron descargar y
     * 5 no eran el producto."
     *
     * @param  array $entrada
     * @return string
     */
    protected function resumen_de_criterio(array $entrada)
    {
        if (!$entrada['usado']) {
            return (string) $entrada['motivo_no_usado'];
        }

        if (!is_null($entrada['error'])) {
            return 'La búsqueda falló: '.$entrada['error'];
        }

        $total = (int) $entrada['resultados'];

        if ($total === 0) {
            return 'Sin resultados.';
        }

        $cabeza = $total === 1 ? '1 resultado' : $total.' resultados';

        $resultados = array_column(array_values($entrada['candidatas']), 'resultado');
        $conteo     = array_count_values(array_map('strval', $resultados));

        // Casos redondos, que se leen mejor como una frase.
        if (count($conteo) === 1 && isset($conteo['ia_no_corresponde'])) {
            return $cabeza.($total === 1 ? ', no era el producto.' : ', ninguno era el producto.');
        }

        if (count($conteo) === 1 && isset($conteo['chica'])) {
            return $cabeza.($total === 1 ? ', muy chica.' : ', todas muy chicas.');
        }

        return $cabeza.': '.$this->lista_legible($this->partes_de_conteo($conteo)).'.';
    }

    /**
     * "3 muy chicas", "2 no se pudieron descargar"... en un orden fijo.
     *
     * @param  array $conteo  resultado => cantidad
     * @return array
     */
    protected function partes_de_conteo(array $conteo)
    {
        // resultado => [singular, plural]
        $etiquetas = [
            'elegida'              => ['la elegida', 'elegidas'],
            'alternativa'          => ['también servía', 'también servían'],
            'ia_dudosa'            => ['dudosa para la IA', 'dudosas para la IA'],
            'ia_no_corresponde'    => ['no era el producto', 'no eran el producto'],
            'chica'                => ['muy chica', 'muy chicas'],
            'no_descargable'       => ['no se pudo descargar', 'no se pudieron descargar'],
            'no_es_imagen'         => ['no era una imagen', 'no eran imágenes'],
            'descartada_por_texto' => ['era una lista de precios o un catálogo', 'eran listas de precios o catálogos'],
            'duplicada'            => ['repetida', 'repetidas'],
            'no_evaluada'          => ['sin revisar', 'sin revisar'],
        ];

        $partes = [];

        foreach ($etiquetas as $resultado => $textos) {
            if (empty($conteo[$resultado])) {
                continue;
            }

            $cantidad = (int) $conteo[$resultado];
            $partes[] = $cantidad === 1 ? '1 '.$textos[0] : $cantidad.' '.$textos[1];
        }

        return $partes;
    }

    /**
     * El detalle de una no asignada: una frase por criterio ("Por código de barras: 10 resultados,
     * ninguno era el producto. Por nombre: 8 resultados: 5 muy chicas y 3 no eran el producto.").
     *
     * @param  array $contexto
     * @return string
     */
    protected function detalle_de_no_asignada(array $contexto)
    {
        $frases = [];

        foreach ($this->diagnostico_final($contexto) as $entrada) {
            $etiqueta = $this->etiqueta_de_criterio($entrada['criterio']);

            if (!$entrada['usado']) {
                $frases[] = 'Por '.$etiqueta.' no se buscó: '.$this->primera_minuscula((string) $entrada['motivo_no_usado']);
                continue;
            }

            $frases[] = 'Por '.$etiqueta.': '.$this->primera_minuscula($entrada['resumen']);
        }

        return implode(' ', $frases);
    }

    /**
     * El motivo principal de una no asignada (contrato §5.2): el de más prioridad entre los
     * criterios que se usaron.
     *
     * @param  array $contexto
     * @return string
     */
    protected function motivo_principal_de_no_asignada(array $contexto)
    {
        $mejor = 'sin_datos';

        foreach ($this->diagnostico_final($contexto) as $entrada) {
            $motivo = $this->motivo_de_criterio($entrada);

            if (is_null($motivo)) {
                continue;
            }

            if (self::PRIORIDAD_MOTIVO_NO_ASIGNADA[$motivo] > self::PRIORIDAD_MOTIVO_NO_ASIGNADA[$mejor]) {
                $mejor = $motivo;
            }
        }

        return $mejor;
    }

    /**
     * El motivo de UN criterio usado, o null si no se usó.
     *
     * @param  array $entrada
     * @return string|null
     */
    protected function motivo_de_criterio(array $entrada)
    {
        if (!$entrada['usado']) {
            return null;
        }

        if (!is_null($entrada['error'])) {
            return 'error_de_busqueda';
        }

        if ((int) $entrada['resultados'] === 0) {
            return 'sin_resultados';
        }

        $resultados = array_column($entrada['candidatas'], 'resultado');

        // Una lista de precios o un catálogo tampoco "corresponde": no es una foto del producto.
        if (in_array('ia_no_corresponde', $resultados, true) || in_array('descartada_por_texto', $resultados, true)) {
            return 'no_corresponden';
        }

        if (in_array('chica', $resultados, true)) {
            return 'imagenes_chicas';
        }

        if (in_array('no_descargable', $resultados, true) || in_array('no_es_imagen', $resultados, true)) {
            return 'no_descargables';
        }

        return 'sin_resultados';
    }

    /**
     * El motivo principal de una "a revisar": el primero de MOTIVOS_A_REVISAR_EN_ORDEN que aplica.
     *
     * @param  array $ganadora
     * @return string
     */
    protected function motivo_principal_de_revision(array $ganadora)
    {
        foreach (self::MOTIVOS_A_REVISAR_EN_ORDEN as $motivo) {
            if (in_array($motivo, $ganadora['motivos_revision'], true)) {
                return $motivo;
            }
        }

        // No debería pasar (una no asignable siempre tiene un motivo), pero sin IA confiable es esto.
        return 'ia_dudosa';
    }

    /**
     * Los avisos en texto humano: para una asignada, solo "Fondo no blanco" (contrato §5.2); para
     * una a revisar, todos los motivos que aplican y también el fondo.
     *
     * @param  array $ganadora
     * @param  bool  $asignable
     * @return array
     */
    protected function avisos_de(array $ganadora, $asignable)
    {
        $avisos = [];

        if (!$asignable) {
            $textos = [
                'sin_validacion_ia' => 'No se pudo validar con IA',
                'ia_dudosa'         => 'La IA no está segura de que sea el producto',
                'confianza_media'   => 'La IA lo reconoce con confianza media',
                'marca_de_agua'     => 'Tiene marca de agua',
                'texto_superpuesto' => 'Tiene texto encima de la foto',
                'collage'           => 'Es un collage de varias fotos',
                'imagen_algo_chica' => 'Imagen de '.$ganadora['lado'].' px',
            ];

            foreach (self::MOTIVOS_A_REVISAR_EN_ORDEN as $motivo) {
                if (in_array($motivo, $ganadora['motivos_revision'], true)) {
                    $avisos[] = $textos[$motivo];
                }
            }
        }

        if (!$ganadora['fondo_blanco']) {
            $avisos[] = self::AVISO_FONDO_NO_BLANCO;
        }

        return $avisos;
    }

    /**
     * Los avisos con la primera letra en minúscula, para meterlos adentro de una frase.
     *
     * @param  array $avisos
     * @return array
     */
    protected function avisos_en_minuscula(array $avisos)
    {
        $resultado = [];

        foreach ($avisos as $aviso) {
            $resultado[] = $this->primera_minuscula($aviso);
        }

        return $resultado;
    }

    /**
     * "a, b y c".
     *
     * @param  array $partes
     * @return string
     */
    protected function lista_legible(array $partes)
    {
        $partes = array_values($partes);

        if (count($partes) <= 1) {
            return implode('', $partes);
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes).' y '.$ultima;
    }

    /**
     * @param  string $texto
     * @return string
     */
    protected function primera_minuscula($texto)
    {
        $texto = (string) $texto;

        if ($texto === '') {
            return $texto;
        }

        return mb_strtolower(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($texto, 1, null, 'UTF-8');
    }

    /**
     * @param  string $criterio
     * @return string
     */
    protected function etiqueta_de_criterio($criterio)
    {
        return $criterio === self::CRITERIO_CODIGO ? 'código de barras' : 'nombre';
    }

    /**
     * Entrada del diagnóstico de un criterio que NO se usó (código inventado, sin nombre, sin cupo,
     * o no hizo falta). Va igual al diagnóstico: así se ve por qué no se buscó.
     *
     * @param  string      $criterio
     * @param  string      $consulta
     * @param  string|null $motivo
     * @return array
     */
    protected function criterio_no_usado($criterio, $consulta, $motivo)
    {
        return [
            'criterio'        => $criterio,
            'consulta'        => (string) $consulta,
            'usado'           => false,
            'motivo_no_usado' => (string) $motivo,
            'busquedas'       => 0,
            'resultados'      => 0,
            'error'           => null,
            'resumen'         => (string) $motivo,
            'candidatas'      => [],
        ];
    }

    /**
     * La consulta por nombre: el nombre del artículo, más la marca si el nombre no la contiene
     * (buscar "Yerba mate 1 kg" sin la marca trae cualquier yerba), como mucho
     * LARGO_MAXIMO_CONSULTA_POR_NOMBRE caracteres cortando en un espacio. La marca no se recorta:
     * es lo que más precisa la búsqueda.
     *
     * @param  \App\Models\Article $article
     * @return string
     */
    public function consulta_por_nombre(Article $article)
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) $article->name));

        if ($nombre === '') {
            return '';
        }

        $marca = '';

        if (!is_null($article->brand_id) && $article->brand && trim((string) $article->brand->name) !== '') {
            $marca = trim((string) $article->brand->name);
        }

        if ($marca !== '' && mb_strpos($this->normalizar($nombre), $this->normalizar($marca)) !== false) {
            $marca = '';
        }

        $lugar_para_el_nombre = self::LARGO_MAXIMO_CONSULTA_POR_NOMBRE - ($marca === '' ? 0 : mb_strlen($marca) + 1);

        if (mb_strlen($nombre) > $lugar_para_el_nombre) {
            $recortado = mb_substr($nombre, 0, max(1, $lugar_para_el_nombre));
            $espacio   = mb_strrpos($recortado, ' ');
            $nombre    = $espacio !== false && $espacio > 0 ? mb_substr($recortado, 0, $espacio) : $recortado;
        }

        return trim($nombre.($marca === '' ? '' : ' '.$marca));
    }

    /**
     * Minúsculas y sin acentos, para comparar nombre y marca.
     *
     * @param  string $texto
     * @return string
     */
    protected function normalizar($texto)
    {
        $texto = mb_strtolower((string) $texto, 'UTF-8');

        return str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'], ['a', 'e', 'i', 'o', 'u', 'n', 'u'], $texto);
    }

    /* ----------------------------------------------------------------------------------------
     * Cupo diario
     * -------------------------------------------------------------------------------------- */

    /**
     * Suma a los contadores de la asignación EN EL MOMENTO de cada búsqueda o llamada a la IA que
     * se pagó, con un incremento atómico. No al cerrar el artículo: si el motor revienta a mitad de
     * un artículo (y se lo reintenta), esas búsquedas ya se cobraron y el cupo del día ya se
     * descontó, así que la asignación tiene que mostrarlas igual.
     *
     * @param  array $sumas  columna => cantidad (busquedas, busquedas_codigo, busquedas_nombre, validaciones_ia).
     * @return void
     */
    protected function sumar_a_la_asignacion(array $sumas)
    {
        $cambios = ['updated_at' => Carbon::now()];

        foreach ($sumas as $columna => $cantidad) {
            if (in_array($columna, ['busquedas', 'busquedas_codigo', 'busquedas_nombre', 'validaciones_ia'], true)) {
                $cambios[$columna] = DB::raw($columna.' + '.(int) $cantidad);
            }
        }

        DB::table('image_assignment_runs')->where('id', $this->run->id)->update($cambios);
    }

    /**
     * ¿Queda cupo diario? Lo decide ImagenesAutomaticasHelper::cuota_de(), el mismo que usan la
     * pantalla y el asistente.
     *
     * @return bool
     */
    protected function queda_cupo()
    {
        return (int) ImagenesAutomaticasHelper::cuota_de($this->owner)['disponibles'] > 0;
    }

    /**
     * Descuenta una búsqueda del cupo del día del dueño, solo si la asignación aplica el tope
     * diario. Es consumir_cuota() del trait, sobre el contador del día (geocoder_counters): el
     * mismo que usan la búsqueda manual y el job viejo.
     *
     * @return void
     */
    protected function descontar_del_cupo_diario()
    {
        if (!$this->run->aplica_tope_diario) {
            return;
        }

        $this->consumir_cuota($this->get_or_create_counter());
    }

    /**
     * El estado de trabajo de un artículo.
     *
     * @param  \App\Models\Article|null $article
     * @return array
     */
    protected function contexto_nuevo($article)
    {
        return [
            'article'              => $article,
            'busquedas'            => 0,
            'busquedas_codigo'     => 0,
            'busquedas_nombre'     => 0,
            'validaciones'         => 0,
            'llamadas_ia'          => 0,
            'busquedas_intentadas' => 0,
            'errores_de_proveedor' => 0,
            'cupo_agotado'         => false,
            'diagnostico'          => [],
            'pozo'                 => [],
            'urls_vistas'          => [],
        ];
    }
}
