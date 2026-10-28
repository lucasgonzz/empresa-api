<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\asistente_ia\ModelosIaHelper;
use App\Jobs\ProcessDimensionSearchRunJob;
use App\Models\Article;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use App\Models\User;
use App\Services\DimensionSearch\DimensionApplier;
use App\Services\DimensionSearch\DimensionValueValidator;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use App\Services\ProductFacts\TopeSerperDemo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La lógica de negocio de la búsqueda masiva de peso y medidas (misión medidas-masivas-catalogo,
 * 27/10/2026). DimensionSearchRunController solo traduce a HTTP. Es el espejo de ImageAssignmentRunHelper.
 *
 * - Crear la corrida de «todo el catálogo» (o solo los artículos con stock): la fila de la corrida, un
 *   item por artículo EN EL ORDEN de prioridad, y el job despachado con afterCommit().
 * - La selección del catálogo: artículos del dueño, activos, a los que les falte AL MENOS UNO de los
 *   cuatro campos (peso, alto, ancho, profundidad); sin una propuesta esperando revisión, sin otra corrida
 *   activa y sin una búsqueda sin éxito en los últimos 90 días. Primero los publicados en la tienda,
 *   después los que tienen stock, después el resto; hasta el tope.
 * - La previa de costo, los payloads del contrato con la SPA, los conteos por estado y el resumen del badge.
 * - Aprobar (con valores editados), rechazar y quitar, de a uno o en lote; detener y reanudar; el cierre
 *   de una corrida y la purga de las viejas.
 *
 * 🔴 Todo por dueño: cada consulta filtra por `user_id` y un id de otro comercio es «no existe».
 *
 * 🔴 Definición de «activo» y de «con stock»: son las que ya usa la selección de imágenes
 * (ImageAssignmentRunHelper::base_del_catalogo e ids_del_catalogo): `articles.status = 'active'` y
 * `articles.stock > 0` (la columna `stock`, que ArticleHelper mantiene con el total de las sucursales),
 * las mismas que usó la prueba con Masquito (conteo.php). No se inventó otra.
 *
 * 🔴 Consumo: la búsqueda registra su gasto en `serper_usage_events` y `ai_token_usages`, NO en
 * `image_service_calls` (que es del circuito de imágenes y el admin lo muestra como tal). Decisión tomada;
 * el porqué está en ArticleDimensionSearchEngine.
 *
 * 🔴 Sin la extensión `agentes_ia`: la búsqueda de medidas se puede usar sin «Agentes de IA» (decisión de
 * producto del 27/10/2026); lo que la protege es la tenencia por dueño y el acceso maestro.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionSearchRunHelper
{
    /** Un artículo buscado sin éxito no se vuelve a buscar en el catálogo durante estos días. */
    const DIAS_SIN_VOLVER_A_BUSCAR = 90;

    /**
     * Motivos de sin_datos que cuentan como «ya se buscó y no había datos». Los de error o de corte
     * (articulo_borrado, ya_tenia_medidas, error_de_busqueda, ia_sin_respuesta, sin_cupo, error_interno) NO:
     * esos no dicen nada del producto y hay que volver a intentarlos.
     */
    const MOTIVOS_YA_BUSCADO_SIN_EXITO = ['sin_resultados', 'no_coincide', 'sin_medidas_en_la_pagina', 'paginas_bloqueadas', 'pdf_no_leido'];

    /** Corridas terminadas de más de estos días, sin nada a revisar, se borran. */
    const DIAS_PARA_PURGAR = 180;

    /** Paginado de los listados de la SPA. */
    const POR_PAGINA_DEFECTO = 25;
    const POR_PAGINA_MINIMO  = 10;
    const POR_PAGINA_MAXIMO  = 100;

    /** Cuántos ids acepta como mucho un aprobar / rechazar en lote. */
    const MAXIMO_EN_LOTE = 200;

    /**
     * Al reanudar, un artículo «procesando» vuelve a la fila solo si no se tocó hace más de estos minutos.
     * El timeout de un tramo es de 7 minutos: uno más nuevo lo está terminando un tramo VIVO.
     */
    const MINUTOS_PARA_DAR_POR_MUERTO_UN_ARTICULO = 10;

    /**
     * Estimación que se muestra antes de lanzar. Medida en la prueba real del 27/10/2026 (10 artículos de
     * Masquito, Serper + DeepSeek Flash): 1,8 búsquedas por artículo y ~1.870 tokens de entrada. Va del lado
     * alto a propósito (2,2 búsquedas): la ve Lucas antes de gastar, y quedarse corto en plata es peor que sobrar.
     */
    const BUSQUEDAS_ESTIMADAS_POR_ARTICULO = 2.2;
    const SEGUNDOS_ESTIMADOS_POR_ARTICULO  = 12;
    const USD_POR_MIL_BUSQUEDAS            = 1.0;
    const USD_DE_IA_POR_ARTICULO           = 0.0007;

    /** Solapas del detalle y los estados que muestra cada una. */
    const SOLAPAS = [
        'asignadas' => DimensionSearchItem::ESTADOS_ASIGNADAS,
        'a_revisar' => DimensionSearchItem::ESTADOS_A_REVISAR,
        'sin_datos' => DimensionSearchItem::ESTADOS_SIN_DATOS,
    ];

    /** Nombre que se muestra como «lanzada por» en las de todo el catálogo. */
    const LANZADA_POR_ACCESO_MAESTRO = 'ComercioCity';

    /** Los cuatro campos y cómo se llaman en pantalla. */
    const ETIQUETAS = [
        'peso'        => 'Peso',
        'alto'        => 'Alto',
        'ancho'       => 'Ancho',
        'profundidad' => 'Largo',
    ];

    /* ----------------------------------------------------------------------------------------
     * Disponibilidad
     * -------------------------------------------------------------------------------------- */

    /**
     * ¿La instalación puede leer páginas con IA? Con clave de DeepSeek o de Anthropic alcanza.
     *
     * @param  \App\Models\User|null $owner
     * @return array  ['configurada' => bool, 'motivo' => string|null]
     */
    public static function ia_disponible($owner = null)
    {
        if (is_null(ModelosIaHelper::resolver($owner, ModelosIaHelper::TAREA_BUSQUEDA_MEDIDAS))) {
            return [
                'configurada' => false,
                'motivo'      => 'Falta la clave de la IA en el servidor de este cliente: ni la de DeepSeek (DEEPSEEK_API_KEY) ni la de Anthropic (ANTHROPIC_API_KEY); con cualquiera de las dos alcanza.',
            ];
        }

        return ['configurada' => true, 'motivo' => null];
    }

    /**
     * ¿La sesión actual entró por el login maestro? «Todo el catálogo», detener y reanudar son solo para
     * esa sesión. Es la misma marca que usa la asignación de imágenes.
     *
     * @return bool
     */
    public static function es_acceso_maestro()
    {
        return ImageAssignmentRunHelper::es_acceso_maestro();
    }

    /* ----------------------------------------------------------------------------------------
     * Crear
     * -------------------------------------------------------------------------------------- */

    /**
     * Crea una corrida y la manda a procesar.
     *
     * Los artículos se toman EN EL ORDEN recibido (sin repetidos) y solo los del dueño que existen: uno
     * ajeno o borrado no entra. El despacho va con afterCommit() EXPLÍCITO: un worker libre podría tomar
     * el job antes del commit y no encontrar la corrida.
     *
     * @param  \App\Models\User $owner
     * @param  array            $article_ids
     * @param  string           $origen         catalogo | seleccion
     * @param  int|null         $creada_por     Quién la lanzó.
     * @param  array            $opciones       solo_con_stock (bool), purgar (bool; false si quien llama ya
     *                                          purgó fuera de su transacción).
     * @return \App\Models\DimensionSearchRun
     */
    public static function crear(User $owner, array $article_ids, $origen, $creada_por = null, array $opciones = [])
    {
        // Ids enteros, sin repetidos, en el orden recibido.
        $ids    = [];
        $vistos = [];

        foreach ($article_ids as $article_id) {
            $id = (int) $article_id;

            if ($id > 0 && !isset($vistos[$id])) {
                $vistos[$id] = true;
                $ids[]       = $id;
            }
        }

        // Snapshot de nombre y códigos: el diagnóstico tiene que mostrar con qué datos se buscó.
        $articulos = [];

        foreach (array_chunk($ids, 1000) as $lote) {
            $filas = Article::where('user_id', $owner->id)
                ->whereIn('id', $lote)
                ->get(['id', 'name', 'bar_code', 'provider_code']);

            foreach ($filas as $fila) {
                $articulos[(int) $fila->id] = $fila;
            }
        }

        $validos = [];

        foreach ($ids as $id) {
            if (isset($articulos[$id])) {
                $validos[] = $id;
            }
        }

        if (!array_key_exists('purgar', $opciones) || $opciones['purgar']) {
            self::purgar_viejas((int) $owner->id);
        }

        $solo_con_stock = !empty($opciones['solo_con_stock']);
        $total          = count($validos);

        return DB::transaction(function () use ($owner, $origen, $creada_por, $solo_con_stock, $total, $validos, $articulos) {
            $run = DimensionSearchRun::create([
                'user_id'        => (int) $owner->id,
                'creada_por'     => is_null($creada_por) ? null : (int) $creada_por,
                'origen'         => (string) $origen,
                'proveedor'      => DimensionSearchRun::PROVEEDOR_SERPER,
                'status'         => DimensionSearchRun::STATUS_PENDIENTE,
                'solo_con_stock' => $solo_con_stock,
                'total'          => $total,
            ]);

            $ahora = Carbon::now();
            $filas = [];
            $orden = 0;

            foreach ($validos as $id) {
                $orden++;
                $articulo = $articulos[$id];

                $filas[] = [
                    'run_id'                => (int) $run->id,
                    'user_id'               => (int) $owner->id,
                    'article_id'            => $id,
                    'article_name'          => is_null($articulo->name) ? null : mb_substr((string) $articulo->name, 0, 255),
                    'article_bar_code'      => is_null($articulo->bar_code) ? null : mb_substr((string) $articulo->bar_code, 0, 100),
                    'article_provider_code' => is_null($articulo->provider_code) ? null : mb_substr((string) $articulo->provider_code, 0, 100),
                    'orden'                 => $orden,
                    'status'                => DimensionSearchItem::STATUS_PENDIENTE,
                    'created_at'            => $ahora,
                    'updated_at'            => $ahora,
                ];
            }

            foreach (array_chunk($filas, 500) as $lote) {
                DB::table('dimension_search_items')->insert($lote);
            }

            ProcessDimensionSearchRunJob::dispatch((int) $run->id)->afterCommit();

            return $run;
        });
    }

    /**
     * Lanza «buscar peso y medidas de todo el catálogo» (o de los artículos con stock).
     *
     * 🔴 Se serializa por dueño con un lockForUpdate sobre su fila de users: sin eso, dos clics seguidos
     * pasaban los dos el «¿hay otra activa?» y quedaban dos corridas buscando lo mismo (y pagando dos veces).
     *
     * @param  \App\Models\User $owner
     * @param  int|null         $creada_por
     * @param  bool             $solo_con_stock
     * @param  int|null         $limite          Artículos como máximo (además del tope de la configuración).
     * @param  bool             $reintentar_sin_datos  true = también entran los que quedaron `sin_datos` en una búsqueda
     *                                           anterior (misión medidas-sin-datos); siguen afuera los rechazados o quitados
     *                                           hace poco, los que esperan revisión y los que están en una corrida activa.
     * @return array  ['status' => 201|422, 'message' => string|null, 'run' => DimensionSearchRun|null]
     */
    public static function crear_del_catalogo(User $owner, $creada_por = null, $solo_con_stock = false, $limite = null, $reintentar_sin_datos = false)
    {
        // Una demo sin búsquedas no lanza nada.
        if (TopeSerperDemo::agotado($owner)) {
            return ['status' => 422, 'message' => TopeSerperDemo::MENSAJE, 'run' => null];
        }

        $problema = self::problema_para_lanzar($owner);

        if (!is_null($problema)) {
            return ['status' => 422, 'message' => $problema, 'run' => null];
        }

        // Las purgas, FUERA de la transacción: la transacción toma el lock del dueño y no tiene que esperar un DELETE.
        self::purgar_viejas((int) $owner->id);

        return DB::transaction(function () use ($owner, $creada_por, $solo_con_stock, $limite, $reintentar_sin_datos) {
            User::where('id', $owner->id)->lockForUpdate()->first();

            if (!is_null(self::corrida_de_catalogo_activa((int) $owner->id))) {
                return ['status' => 422, 'message' => 'Ya hay una búsqueda de medidas de todo el catálogo en curso: esperá a que termine o detenela.', 'run' => null];
            }

            $tope = self::tope_del_catalogo();

            if (!is_null($limite) && (int) $limite > 0) {
                $tope = min($tope, (int) $limite);
            }

            $ids = self::ids_del_catalogo((int) $owner->id, (bool) $solo_con_stock, $tope, (bool) $reintentar_sin_datos);

            if (empty($ids)) {
                return [
                    'status'  => 422,
                    'message' => 'No hay artículos para buscar: todos los activos'.($solo_con_stock ? ' con stock' : '').' ya tienen peso y medidas, tienen una propuesta esperando revisión, ya se buscaron sin éxito en los últimos '.self::DIAS_SIN_VOLVER_A_BUSCAR.' días'.($reintentar_sin_datos ? ' (rechazados o quitados)' : '').' o están en otra corrida.',
                    'run'     => null,
                ];
            }

            $run = self::crear($owner, $ids, DimensionSearchRun::ORIGEN_CATALOGO, $creada_por, [
                'solo_con_stock' => (bool) $solo_con_stock,
                // Ya se purgó arriba, fuera de la transacción.
                'purgar'         => false,
            ]);

            return ['status' => 201, 'message' => null, 'run' => $run];
        });
    }

    /**
     * Qué impide lanzar (null si nada): sin clave de Serper o sin IA. Sin ellas se pagaría para no conseguir nada.
     *
     * @param  \App\Models\User $owner
     * @return string|null
     */
    public static function problema_para_lanzar(User $owner)
    {
        if (!ImageSearchProviderFactory::serper_configurado($owner)) {
            return 'Para buscar el peso y las medidas del catálogo hace falta la clave de Serper: SERPER_API_KEY en el servidor de este cliente, o la del comercio (la manda el admin al instalar el sistema).';
        }

        $ia = self::ia_disponible($owner);

        if (!$ia['configurada']) {
            return 'No se puede buscar el peso y las medidas del catálogo sin la IA que lee las páginas. '.$ia['motivo'];
        }

        return null;
    }

    /**
     * La corrida de catálogo en curso del dueño, si hay.
     *
     * @param  int $owner_id
     * @return \App\Models\DimensionSearchRun|null
     */
    public static function corrida_de_catalogo_activa($owner_id)
    {
        return DimensionSearchRun::where('user_id', (int) $owner_id)
            ->where('origen', DimensionSearchRun::ORIGEN_CATALOGO)
            ->activas()
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * El tope de artículos por lanzamiento del catálogo (config medidas_masivas.tope_catalogo).
     *
     * @return int
     */
    public static function tope_del_catalogo()
    {
        return max(1, (int) config('services.medidas_masivas.tope_catalogo', 5000));
    }

    /* ----------------------------------------------------------------------------------------
     * Selección del catálogo
     * -------------------------------------------------------------------------------------- */

    /**
     * Los números de la selección del catálogo (sin traer ids).
     *
     * @param  int  $owner_id
     * @param  bool $solo_con_stock
     * @param  bool $reintentar_sin_datos  true = los `sin_datos` de búsquedas anteriores NO cuentan como «ya buscados».
     * @return array  activos, con_medidas_completas, sin_medidas, excluidos_sin_stock,
     *                excluidos_pendientes_de_revision, excluidos_ya_buscados, excluidos_en_otra_corrida, candidatos
     *                y reintento_sin_datos (cuántos artículos entran —o entrarían— por la opción de reintentar).
     */
    public static function conteos_del_catalogo($owner_id, $solo_con_stock = false, $reintentar_sin_datos = false)
    {
        $activos = (int) self::activos($owner_id)->count();

        // Los que les falta al menos un campo: el universo de la búsqueda.
        $sin_medidas = (int) self::activos($owner_id)->where(self::falta_alguna_medida())->count();

        $base = self::base_del_catalogo($owner_id, false);

        $sin_stock = 0;

        if ($solo_con_stock) {
            $sin_stock = (int) (clone $base)->where(function ($condicion) {
                $condicion->whereNull('articles.stock')->orWhere('articles.stock', '<=', 0);
            })->count();

            $base = self::base_del_catalogo($owner_id, true);
        }

        $pendientes_de_revision = (int) (clone $base)->whereExists(self::subconsulta_a_revisar($owner_id))->count();

        $ya_buscados = (int) (clone $base)
            ->whereNotExists(self::subconsulta_a_revisar($owner_id))
            ->whereExists(self::subconsulta_ya_buscado_sin_exito($owner_id, $reintentar_sin_datos))
            ->count();

        // Los que ya esperan su turno en otra corrida en curso.
        $en_otra_corrida = (int) (clone $base)
            ->whereNotExists(self::subconsulta_a_revisar($owner_id))
            ->whereNotExists(self::subconsulta_ya_buscado_sin_exito($owner_id, $reintentar_sin_datos))
            ->whereExists(self::subconsulta_en_corrida_activa($owner_id))
            ->count();

        // Los que entran (o entrarían) solo por reintentar los «sin datos»: sin la opción figuran como «ya buscados
        // sin éxito», con la opción dejan de figurar; y no esperan revisión ni están en una corrida en curso.
        $reintento_sin_datos = (int) (clone $base)
            ->whereNotExists(self::subconsulta_a_revisar($owner_id))
            ->whereExists(self::subconsulta_ya_buscado_sin_exito($owner_id, false))
            ->whereNotExists(self::subconsulta_ya_buscado_sin_exito($owner_id, true))
            ->whereNotExists(self::subconsulta_en_corrida_activa($owner_id))
            ->count();

        $en_universo = (int) (clone $base)->count();

        return [
            'activos'                          => $activos,
            'con_medidas_completas'            => max(0, $activos - $sin_medidas),
            'sin_medidas'                      => $sin_medidas,
            'excluidos_sin_stock'              => $sin_stock,
            'excluidos_pendientes_de_revision' => $pendientes_de_revision,
            'excluidos_ya_buscados'            => $ya_buscados,
            'excluidos_en_otra_corrida'        => $en_otra_corrida,
            'candidatos'                       => max(0, $en_universo - $pendientes_de_revision - $ya_buscados - $en_otra_corrida),
            'reintento_sin_datos'              => $reintento_sin_datos,
        ];
    }

    /**
     * Los ids del catálogo a buscar, en orden de prioridad y hasta el tope: primero los publicados en la
     * tienda (`online`), después los que tienen stock, después el resto por id. Así, con más artículos que
     * el tope, lo primero que se completa es lo que ve el cliente final.
     *
     * @param  int  $owner_id
     * @param  bool $solo_con_stock
     * @param  int  $tope
     * @param  bool $reintentar_sin_datos  true = los `sin_datos` de búsquedas anteriores vuelven a entrar.
     * @return array
     */
    public static function ids_del_catalogo($owner_id, $solo_con_stock, $tope, $reintentar_sin_datos = false)
    {
        return self::base_del_catalogo($owner_id, $solo_con_stock)
            ->whereNotExists(self::subconsulta_a_revisar($owner_id))
            ->whereNotExists(self::subconsulta_ya_buscado_sin_exito($owner_id, $reintentar_sin_datos))
            ->whereNotExists(self::subconsulta_en_corrida_activa($owner_id))
            ->orderByRaw('COALESCE(articles.online, 0) DESC')
            ->orderByRaw('CASE WHEN articles.stock > 0 THEN 1 ELSE 0 END DESC')
            ->orderBy('articles.id')
            ->limit(max(1, (int) $tope))
            ->pluck('articles.id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    /**
     * La previa que ve el acceso maestro antes de lanzar.
     *
     * @param  \App\Models\User $owner
     * @param  bool             $solo_con_stock
     * @param  bool             $reintentar_sin_datos  true = la previa cuenta como candidatos a los `sin_datos` anteriores.
     * @return array
     */
    public static function previa_del_catalogo(User $owner, $solo_con_stock = false, $reintentar_sin_datos = false)
    {
        $tope    = self::tope_del_catalogo();
        $conteos = self::conteos_del_catalogo((int) $owner->id, $solo_con_stock, $reintentar_sin_datos);

        $a_buscar  = min($tope, $conteos['candidatos']);
        $busquedas = (int) round($a_buscar * self::BUSQUEDAS_ESTIMADAS_POR_ARTICULO);

        // El respaldo por el scrape de Serper (páginas que bloquean la descarga y PDF): un promedio conservador
        // por artículo (config `creditos_estimados_por_articulo`: 1,5 scrapes x 4 créditos). Cada scrape cuesta de 2 a
        // 10 créditos y hay un tope de scrapes por artículo: es una estimación, no un techo.
        $creditos_respaldo = (int) round($a_buscar * (float) config('services.medidas_masivas.creditos_estimados_por_articulo', 6));
        $usd_respaldo      = round($creditos_respaldo / 1000 * self::USD_POR_MIL_BUSQUEDAS, 2);

        $configurado = ImageSearchProviderFactory::serper_configurado($owner);
        $ia          = self::ia_disponible($owner);
        $activa      = self::corrida_de_catalogo_activa((int) $owner->id);

        $previa = [
            'solo_con_stock'                   => (bool) $solo_con_stock,
            'reintentar_sin_datos'             => (bool) $reintentar_sin_datos,
            'reintento_sin_datos'              => $conteos['reintento_sin_datos'],
            'activos'                          => $conteos['activos'],
            'con_medidas_completas'            => $conteos['con_medidas_completas'],
            'sin_medidas'                      => $conteos['sin_medidas'],
            'excluidos_sin_stock'              => $conteos['excluidos_sin_stock'],
            'excluidos_pendientes_de_revision' => $conteos['excluidos_pendientes_de_revision'],
            'excluidos_ya_buscados'            => $conteos['excluidos_ya_buscados'],
            'excluidos_en_otra_corrida'        => $conteos['excluidos_en_otra_corrida'],
            'a_buscar'                         => $a_buscar,
            'tope'                             => $tope,
            'quedan_para_otra_corrida'         => max(0, $conteos['candidatos'] - $a_buscar),
            'proveedor_configurado'            => $configurado,
            'proveedor'                        => $configurado ? DimensionSearchRun::PROVEEDOR_SERPER : null,
            'ia_configurada'                   => $ia['configurada'],
            'ia_motivo'                        => $ia['motivo'],
            'se_puede_lanzar'                  => $configurado && $ia['configurada'] && is_null($activa) && $a_buscar > 0,
            'estimacion'                       => [
                'busquedas'     => $busquedas,
                'minutos'       => (int) round($a_buscar * self::SEGUNDOS_ESTIMADOS_POR_ARTICULO / 60),
                // `usd_busquedas` es solo de las búsquedas (el contrato de siempre); el respaldo va aparte en `usd_respaldo`
                // y el costo total de la corrida es usd_busquedas + usd_respaldo + usd_ia.
                'usd_busquedas'     => round($busquedas / 1000 * self::USD_POR_MIL_BUSQUEDAS, 2),
                'usd_ia'            => round($a_buscar * self::USD_DE_IA_POR_ARTICULO, 2),
                'creditos_respaldo' => $creditos_respaldo,
                'usd_respaldo'      => $usd_respaldo,
                // 🔴 Es un PISO: el promedio supone que pocos artículos tienen páginas bloqueadas; en los que sí las
                // tienen cada página cuesta de 2 a 10 créditos. Hay un tope por artículo y otro por corrida.
                'texto_respaldo'    => 'Si una página bloquea la descarga o es un PDF, se pide su texto a un servicio de respaldo (2 a 10 créditos por página, hasta '.(int) config('services.medidas_masivas.max_scrapes_por_articulo', 3).' por artículo). Se estima aparte, como piso: al menos ~'.$creditos_respaldo.' créditos de Serper (USD '.number_format($usd_respaldo, 2, '.', '').') además de las búsquedas; puede ser más en los artículos que tienen páginas bloqueadas. El respaldo se apaga solo al llegar a '.(int) config('services.medidas_masivas.max_creditos_por_corrida', 30000).' créditos en la corrida.',
            ],
            'corrida_activa'                   => is_null($activa) ? null : self::payload_de_corrida($activa),
        ];

        // Solo en una demo: cuántas búsquedas de Serper le quedan al dueño.
        $tope_demo = TopeSerperDemo::estado($owner);

        if ($tope_demo['aplica']) {
            $previa['tope_demo'] = $tope_demo;
        }

        return $previa;
    }

    /**
     * Los artículos activos del dueño (el universo del catálogo). «Activo» es `articles.status = 'active'`,
     * como en la selección de imágenes.
     *
     * @param  int $owner_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function activos($owner_id)
    {
        return Article::where('articles.user_id', (int) $owner_id)->where('articles.status', 'active');
    }

    /**
     * Artículos activos del dueño a los que les falta al menos una medida (y, si se pide, con stock > 0).
     *
     * @param  int  $owner_id
     * @param  bool $solo_con_stock
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function base_del_catalogo($owner_id, $solo_con_stock)
    {
        $query = self::activos($owner_id)->where(self::falta_alguna_medida());

        if ($solo_con_stock) {
            $query->where('articles.stock', '>', 0);
        }

        return $query;
    }

    /**
     * La condición «le falta al menos uno de los cuatro campos» (null o 0, que es lo que guarda el
     * formulario cuando no se carga nada).
     *
     * @return \Closure
     */
    protected static function falta_alguna_medida()
    {
        return function ($query) {
            foreach (array_keys(DimensionApplier::COLUMNA_DE_PROPUESTA) as $campo) {
                $query->orWhereNull('articles.'.$campo)->orWhere('articles.'.$campo, '<=', 0);
            }
        };
    }

    /**
     * EXISTS: el artículo tiene una propuesta esperando revisión en alguna corrida del dueño.
     *
     * @param  int $owner_id
     * @return \Closure
     */
    protected static function subconsulta_a_revisar($owner_id)
    {
        return function ($query) use ($owner_id) {
            $query->select(DB::raw(1))
                ->from('dimension_search_items')
                ->where('dimension_search_items.user_id', (int) $owner_id)
                ->whereColumn('dimension_search_items.article_id', 'articles.id')
                ->where('dimension_search_items.status', DimensionSearchItem::STATUS_A_REVISAR);
        };
    }

    /**
     * EXISTS: el artículo se buscó sin éxito en los últimos DIAS_SIN_VOLVER_A_BUSCAR días (no había datos,
     * alguien rechazó la propuesta o quitó lo que se había cargado). Para las rechazadas y quitadas manda la
     * fecha del rechazo: una propuesta de hace 100 días que alguien rechazó ayer es un «no» de ayer.
     *
     * @param  int $owner_id
     * @return \Closure
     */
    protected static function subconsulta_ya_buscado_sin_exito($owner_id, $reintentar_sin_datos = false)
    {
        $desde = Carbon::now()->subDays(self::DIAS_SIN_VOLVER_A_BUSCAR);

        return function ($query) use ($owner_id, $desde, $reintentar_sin_datos) {
            $query->select(DB::raw(1))
                ->from('dimension_search_items')
                ->where('dimension_search_items.user_id', (int) $owner_id)
                ->whereColumn('dimension_search_items.article_id', 'articles.id')
                ->where(function ($condicion) use ($desde, $reintentar_sin_datos) {
                    // Con `reintentar_sin_datos` el «no había datos» no cuenta como búsqueda sin éxito: se vuelve a
                    // buscar. El «no» de una persona (rechazada / quitada) sigue contando siempre.
                    $condicion->where(function ($sin_datos) use ($desde, $reintentar_sin_datos) {
                        $sin_datos->where('dimension_search_items.status', DimensionSearchItem::STATUS_SIN_DATOS)
                            ->whereIn('dimension_search_items.motivo', $reintentar_sin_datos ? [] : self::MOTIVOS_YA_BUSCADO_SIN_EXITO)
                            ->where('dimension_search_items.procesado_at', '>=', $desde);
                    })->orWhere(function ($revisada) use ($desde) {
                        $revisada->whereIn('dimension_search_items.status', [
                            DimensionSearchItem::STATUS_RECHAZADA,
                            DimensionSearchItem::STATUS_QUITADA,
                        ])->whereRaw('COALESCE(dimension_search_items.revisado_at, dimension_search_items.procesado_at) >= ?', [$desde]);
                    });
                });
        };
    }

    /**
     * EXISTS: el artículo está pendiente o procesándose en una corrida EN CURSO del dueño: ya le va a tocar
     * el turno ahí, meterlo en otra lo buscaría dos veces.
     *
     * @param  int $owner_id
     * @return \Closure
     */
    protected static function subconsulta_en_corrida_activa($owner_id)
    {
        return function ($query) use ($owner_id) {
            $query->select(DB::raw(1))
                ->from('dimension_search_items')
                ->join('dimension_search_runs', 'dimension_search_runs.id', '=', 'dimension_search_items.run_id')
                ->where('dimension_search_items.user_id', (int) $owner_id)
                ->whereColumn('dimension_search_items.article_id', 'articles.id')
                ->whereIn('dimension_search_items.status', DimensionSearchItem::ESTADOS_PENDIENTES)
                ->whereIn('dimension_search_runs.status', [DimensionSearchRun::STATUS_PENDIENTE, DimensionSearchRun::STATUS_EN_PROCESO]);
        };
    }

    /* ----------------------------------------------------------------------------------------
     * Listados y payloads
     * -------------------------------------------------------------------------------------- */

    /**
     * El listado de Alertas → Catálogo → Medidas: las corridas del dueño, más nuevas primero, paginadas.
     *
     * @param  int $owner_id
     * @param  int $pagina
     * @param  int $por_pagina
     * @return array  Un paginador (`data`, `total`, ...) con RunPayload y el `resumen` del badge.
     */
    public static function listado($owner_id, $pagina, $por_pagina)
    {
        $paginador = DimensionSearchRun::where('user_id', (int) $owner_id)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->paginate(self::por_pagina($por_pagina), ['*'], 'page', max(1, (int) $pagina));

        $runs    = $paginador->getCollection();
        $conteos = self::conteos_de($runs->pluck('id')->all());
        $nombres = self::nombres_de_usuarios($runs->pluck('creada_por')->all());

        $paginador->setCollection($runs->map(function ($run) use ($conteos, $nombres) {
            return self::payload_de_corrida($run, $conteos[$run->id], $nombres);
        }));

        return ['paginador' => $paginador, 'resumen' => self::resumen($owner_id)];
    }

    /**
     * El resumen liviano del badge de Alertas.
     *
     * @param  int $owner_id
     * @return array  ['a_revisar', 'sin_ver', 'badge', 'en_proceso']
     */
    public static function resumen($owner_id)
    {
        $a_revisar = (int) DimensionSearchItem::where('user_id', (int) $owner_id)
            ->where('status', DimensionSearchItem::STATUS_A_REVISAR)
            ->count();

        // Las que ya salieron de proceso y nadie abrió todavía.
        $sin_ver = (int) DimensionSearchRun::where('user_id', (int) $owner_id)
            ->whereIn('status', [DimensionSearchRun::STATUS_TERMINADA, DimensionSearchRun::STATUS_DETENIDA, DimensionSearchRun::STATUS_FALLIDA])
            ->whereNull('visto_at')
            ->count();

        return [
            'a_revisar'  => $a_revisar,
            'sin_ver'    => $sin_ver,
            // El número rojo: lo que espera una decisión más lo que nadie vio.
            'badge'      => $a_revisar + $sin_ver,
            'en_proceso' => (int) DimensionSearchRun::where('user_id', (int) $owner_id)->activas()->count(),
        ];
    }

    /**
     * Los artículos de una solapa del detalle, paginados en el servidor.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  string $solapa      asignadas | a_revisar | sin_datos
     * @param  int    $pagina
     * @param  int    $por_pagina  Se acota a 10..100 (25 por defecto).
     * @param  string $buscar      Por nombre o código (sobre el snapshot del item).
     * @return array  ['paginador' => paginador de ItemPayload, 'conteos' => conteos]
     */
    public static function items_de(DimensionSearchRun $run, $solapa, $pagina, $por_pagina, $buscar = '')
    {
        $query = DimensionSearchItem::where('run_id', $run->id)
            ->where('user_id', $run->user_id)
            ->whereIn('status', self::SOLAPAS[$solapa]);

        $buscar = trim((string) $buscar);

        if ($buscar !== '') {
            $patron = '%'.addcslashes($buscar, '%_\\').'%';

            $query->where(function ($condicion) use ($patron) {
                $condicion->where('article_name', 'like', $patron)
                    ->orWhere('article_bar_code', 'like', $patron)
                    ->orWhere('article_provider_code', 'like', $patron);
            });
        }

        $paginador = $query->orderBy('orden')
            ->orderBy('id')
            ->paginate(self::por_pagina($por_pagina), ['*'], 'page', max(1, (int) $pagina));

        $items   = $paginador->getCollection();
        $nombres = self::nombres_de_usuarios($items->pluck('revisado_por')->all());

        $paginador->setCollection($items->map(function ($item) use ($nombres) {
            return self::payload_de_item($item, $nombres);
        }));

        return ['paginador' => $paginador, 'conteos' => self::conteos_de([$run->id])[$run->id]];
    }

    /**
     * Conteos por grupo de estados de varias corridas, en una sola consulta agrupada.
     *
     * @param  array $run_ids
     * @return array  run_id => ['asignadas', 'a_revisar', 'sin_datos', 'pendientes', 'errores']
     */
    public static function conteos_de(array $run_ids)
    {
        $conteos = [];

        foreach ($run_ids as $run_id) {
            $conteos[(int) $run_id] = ['asignadas' => 0, 'a_revisar' => 0, 'sin_datos' => 0, 'pendientes' => 0, 'errores' => 0];
        }

        if (empty($conteos)) {
            return $conteos;
        }

        $filas = DB::table('dimension_search_items')
            ->select('run_id', 'status', DB::raw('COUNT(*) as cantidad'))
            ->whereIn('run_id', array_keys($conteos))
            ->groupBy('run_id', 'status')
            ->get();

        foreach ($filas as $fila) {
            $grupo = self::grupo_de_estado((string) $fila->status);

            if (!is_null($grupo)) {
                $conteos[(int) $fila->run_id][$grupo] += (int) $fila->cantidad;
            }

            // Los errores internos son un subconjunto de «sin datos» (se listan ahí), contados aparte.
            if ((string) $fila->status === DimensionSearchItem::STATUS_ERROR_INTERNO) {
                $conteos[(int) $fila->run_id]['errores'] += (int) $fila->cantidad;
            }
        }

        return $conteos;
    }

    /**
     * RunPayload. Todos los campos están siempre presentes.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  array|null $conteos  Los de conteos_de() para esta corrida (null = se calculan).
     * @param  array|null $nombres  id => nombre de usuario (null = se busca).
     * @return array
     */
    public static function payload_de_corrida(DimensionSearchRun $run, $conteos = null, $nombres = null)
    {
        if (is_null($conteos)) {
            $conteos = self::conteos_de([$run->id])[$run->id];
        }

        if (is_null($nombres)) {
            $nombres = self::nombres_de_usuarios([$run->creada_por]);
        }

        $procesados = (int) $run->procesados;
        $trabada    = $run->esta_trabada();

        return [
            'id'                     => (int) $run->id,
            'origen'                 => (string) $run->origen,
            'proveedor'              => (string) $run->proveedor,
            'status'                 => (string) $run->status,
            'motivo_estado'          => $run->motivo_estado,
            'solo_con_stock'         => (bool) $run->solo_con_stock,
            'total'                  => (int) $run->total,
            'procesados'             => $procesados,
            'conteos'                => $conteos,
            'busquedas'              => (int) $run->busquedas,
            'busquedas_por_articulo' => $procesados > 0 ? round($run->busquedas / $procesados, 2) : null,
            'validaciones_ia'        => (int) $run->validaciones_ia,
            'tokens_entrada'         => (int) $run->tokens_entrada,
            'tokens_salida'          => (int) $run->tokens_salida,
            'lanzada_por'            => self::lanzada_por($run, $nombres),
            'es_de_catalogo'         => $run->origen === DimensionSearchRun::ORIGEN_CATALOGO,
            'created_at'             => self::fecha($run->created_at),
            'started_at'             => self::fecha($run->started_at),
            'finished_at'            => self::fecha($run->finished_at),
            'last_progress_at'       => self::fecha($run->last_progress_at),
            'esta_trabada'           => $trabada,
            'puede_detener'          => $run->esta_activa(),
            'puede_reanudar'         => $trabada || in_array($run->status, [DimensionSearchRun::STATUS_DETENIDA, DimensionSearchRun::STATUS_FALLIDA], true),
            'visto'                  => !is_null($run->visto_at),
        ];
    }

    /**
     * ItemPayload.
     *
     * `diagnostico` (qué se probó, por plan de búsqueda) solo va en los artículos de la solapa «Sin datos»:
     * pesa, y en las otras solapas no se muestra.
     *
     * @param  \App\Models\DimensionSearchItem $item
     * @param  array|null $nombres  id => nombre (para revisado_por; null = se busca).
     * @return array
     */
    public static function payload_de_item(DimensionSearchItem $item, $nombres = null)
    {
        if (is_null($nombres)) {
            $nombres = self::nombres_de_usuarios([$item->revisado_por]);
        }

        $evidencia = is_array($item->evidencia) ? $item->evidencia : [];
        $aplicado  = is_array($item->aplicado) ? $item->aplicado : [];

        $propuesta = [];
        $campos    = [];
        $faltan    = [];

        foreach (DimensionApplier::COLUMNA_DE_PROPUESTA as $campo => $columna) {
            $valor = is_null($item->{$columna}) ? null : (float) $item->{$columna};

            $propuesta[$campo] = $valor;

            if (is_null($valor)) {
                $faltan[] = $campo;
                continue;
            }

            $e = isset($evidencia[$campo]) && is_array($evidencia[$campo]) ? $evidencia[$campo] : [];

            $campos[] = [
                'clave'           => $campo,
                'etiqueta'        => self::ETIQUETAS[$campo],
                'valor'           => $valor,
                'unidad'          => DimensionValueValidator::UNIDAD_CANONICA[DimensionValueValidator::CAMPOS[$campo]],
                'valor_original'  => isset($e['valor_original']) ? (float) $e['valor_original'] : $valor,
                'unidad_original' => isset($e['unidad_original']) ? (string) $e['unidad_original'] : DimensionValueValidator::UNIDAD_CANONICA[DimensionValueValidator::CAMPOS[$campo]],
                'convertido'      => !empty($e['convertido']),
                'cita'            => isset($e['cita']) ? (string) $e['cita'] : null,
                'url'             => isset($e['url']) ? (string) $e['url'] : null,
                'alcance'         => isset($e['alcance']) ? (string) $e['alcance'] : null,
                'editado'         => !empty($e['editado']),
                // Avisos del dato: «1.200 g» (puede ser 1,2 o 1.200), rango («10 a 20 cm») y si la cita dice de qué medida habla.
                'ambiguo'         => !empty($e['ambiguo']),
                'rango'           => !empty($e['rango']),
                'etiquetada'      => !array_key_exists('etiquetada', $e) || !empty($e['etiquetada']),
                'aviso'           => isset($e['aviso']) ? (string) $e['aviso'] : null,
                // Misión medidas-sin-datos: true = la IA propuso este valor pero el sistema no pudo comprobar la
                // cita en la página (`valor_sin_respaldo`); `motivo_rechazo` dice por qué. Sin esos datos: false / null.
                'sin_respaldo'    => !empty($e['sin_respaldo']),
                'motivo_rechazo'  => isset($e['motivo_rechazo']) ? (string) $e['motivo_rechazo'] : null,
                // true = este campo se escribió de verdad en el artículo.
                'aplicado'        => isset($aplicado[$campo]),
            ];
        }

        $fuentes = [];

        foreach (is_array($item->fuentes) ? $item->fuentes : [] as $fuente) {
            if (is_array($fuente) && !empty($fuente['url'])) {
                $host = parse_url((string) $fuente['url'], PHP_URL_HOST);

                $fuentes[] = [
                    'url'     => (string) $fuente['url'],
                    'titulo'  => isset($fuente['titulo']) ? $fuente['titulo'] : null,
                    'dominio' => is_string($host) ? preg_replace('/^www\./i', '', strtolower($host)) : '',
                ];
            }
        }

        $revisor = is_null($item->revisado_por) ? null : (int) $item->revisado_por;

        return [
            'id'                    => (int) $item->id,
            'article_id'            => (int) $item->article_id,
            'article_name'          => $item->article_name,
            'article_bar_code'      => $item->article_bar_code,
            'article_provider_code' => $item->article_provider_code,
            'status'                => (string) $item->status,
            'motivo'                => $item->motivo,
            'motivo_detalle'        => $item->motivo_detalle,
            'criterio_usado'        => $item->criterio_usado,
            'propuesta'             => $propuesta,
            'faltan'                => $faltan,
            'campos'                => $campos,
            'fuentes'               => $fuentes,
            'nota_ia'               => $item->nota_ia,
            'busquedas'             => (int) $item->busquedas,
            'validaciones_ia'       => (int) $item->validaciones_ia,
            // El diagnóstico de páginas (leída / bloqueada / vía respaldo, créditos) viaja en los «sin datos» y también en
            // los `valor_sin_respaldo`: ahí la persona que revisa necesita ver de qué páginas salió la propuesta.
            'diagnostico'           => (in_array($item->status, DimensionSearchItem::ESTADOS_SIN_DATOS, true) || ($item->status === DimensionSearchItem::STATUS_A_REVISAR && $item->motivo === 'valor_sin_respaldo')) && is_array($item->diagnostico) ? $item->diagnostico : [],
            'revisado_por'          => !is_null($revisor) && isset($nombres[$revisor]) ? $nombres[$revisor] : null,
            'revisado_at'           => self::fecha($item->revisado_at),
            'procesado_at'          => self::fecha($item->procesado_at),
        ];
    }

    /**
     * Marca como vista una corrida que ya salió de proceso. Una en curso no se marca: quien la mira a
     * mitad de camino todavía no vio el resultado.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return \App\Models\DimensionSearchRun
     */
    public static function marcar_vista(DimensionSearchRun $run)
    {
        if ($run->salio_de_proceso() && is_null($run->visto_at)) {
            $run->visto_at = Carbon::now();
            $run->save();
        }

        return $run;
    }

    /* ----------------------------------------------------------------------------------------
     * Revisión: aprobar, rechazar, quitar
     * -------------------------------------------------------------------------------------- */

    /**
     * Aprueba una propuesta «a revisar»: escribe las medidas en el artículo (solo los campos que sigan
     * vacíos, D5).
     *
     * `$valores` (opcional) es lo que la persona decidió en el lugar, campo por campo (kg / cm):
     *   - clave AUSENTE = se usa lo propuesto (lo de siempre);
     *   - clave presente con un NÚMERO = se escribe ese número;
     *   - clave presente con `null` (o vacía) = NO se escribe ese campo («aprobar el peso pero no el largo
     *     de una variante dudosa»). Si no queda ningún campo para escribir, es un 422.
     * Solo los campos cuyo número realmente difiere de la propuesta se marcan `editado` y pierden su
     * cita / valor y unidad originales (ya no son lo que dice la página); los demás quedan como estaban.
     *
     * Si el artículo se completó a mano mientras tanto (no queda nada por llenar), responde 409 y además
     * CIERRA el item solo como `rechazada` / `completado_a_mano`, para que el badge baje y no quede colgado.
     *
     * @param  int        $owner_id
     * @param  int        $item_id
     * @param  int|null   $auth_user_id  Quién aprueba.
     * @param  array|null $valores
     * @return array  ['status' => 200|404|409|422, 'message' => string|null, 'item' => DimensionSearchItem|null,
     *                'extra' => array (llenados, salteados, excluidos)]
     */
    public static function aprobar($owner_id, $item_id, $auth_user_id = null, $valores = null)
    {
        $item = DimensionSearchItem::where('user_id', (int) $owner_id)->where('id', (int) $item_id)->first();

        if (is_null($item)) {
            return self::respuesta(404, 'No se encontró ese artículo de la búsqueda de medidas.');
        }

        if ($item->status !== DimensionSearchItem::STATUS_A_REVISAR) {
            return self::respuesta(422, 'Este artículo ya no está esperando revisión.', $item);
        }

        $decisiones = self::valores_editados($valores);

        if (is_string($decisiones)) {
            return self::respuesta(422, $decisiones, $item);
        }

        return DB::transaction(function () use ($owner_id, $item_id, $auth_user_id, $decisiones) {
            $item = DimensionSearchItem::where('user_id', (int) $owner_id)->where('id', (int) $item_id)->lockForUpdate()->first();

            if (is_null($item)) {
                return self::respuesta(404, 'No se encontró ese artículo de la búsqueda de medidas.');
            }

            // Entre la lectura de arriba y el lock pudo resolverlo otro.
            if ($item->status !== DimensionSearchItem::STATUS_A_REVISAR) {
                return self::respuesta(422, 'Este artículo ya no está esperando revisión.', $item);
            }

            $editados  = $decisiones['editados'];
            $excluidos = $decisiones['excluidos'];

            // Lo que se va a escribir: lo propuesto, pisado por lo que la persona escribió, sin lo que excluyó.
            $valores = [];

            foreach (DimensionApplier::COLUMNA_DE_PROPUESTA as $campo => $columna) {
                if (in_array($campo, $excluidos, true)) {
                    continue;
                }

                if (isset($editados[$campo])) {
                    $valores[$campo] = $editados[$campo];
                } elseif (!is_null($item->{$columna})) {
                    $valores[$campo] = (float) $item->{$columna};
                }
            }

            if (empty($valores)) {
                return self::respuesta(422, 'No quedó ningún valor para guardar. Si no sirven, rechazá la propuesta.', $item, ['excluidos' => $excluidos]);
            }

            $aplicacion = DimensionApplier::aplicar($owner_id, $item->article_id, $valores);

            if (!$aplicacion['existe']) {
                $item->fill([
                    'status'         => DimensionSearchItem::STATUS_SIN_DATOS,
                    'motivo'         => 'articulo_borrado',
                    'motivo_detalle' => 'El artículo ya no existe.',
                    'revisado_por'   => self::entero_o_null($auth_user_id),
                    'revisado_at'    => Carbon::now(),
                ]);
                $item->save();

                return self::respuesta(422, 'El artículo ya no existe: no se pueden guardar sus medidas.', $item);
            }

            $llenados  = array_keys($aplicacion['escritos']);
            $salteados = array_keys($aplicacion['salteados']);

            // D5: si todo lo que había para escribir ya estaba cargado, no se guarda nada y se avisa. El item
            // se cierra solo (rechazada / completado_a_mano): nadie tiene nada más que decidir y, si quedara a
            // revisar, el número rojo del badge no bajaría nunca.
            if (empty($llenados)) {
                $item->fill(array_merge(self::propuesta_vacia(), [
                    'status'         => DimensionSearchItem::STATUS_RECHAZADA,
                    'motivo'         => 'completado_a_mano',
                    'motivo_detalle' => 'El artículo ya tenía cargado '.self::lista_de_campos($salteados).' (se completó a mano): no se guardó nada.',
                    'revisado_por'   => self::entero_o_null($auth_user_id),
                    'revisado_at'    => Carbon::now(),
                ]));
                $item->save();

                return self::respuesta(409, 'No se guardó nada: el artículo ya tiene cargado '.self::lista_de_campos($salteados).'. No se pisa lo que ya estaba.', $item, [
                    'llenados'  => [],
                    'salteados' => $salteados,
                    'excluidos' => $excluidos,
                ]);
            }

            // Lo aprobado queda como propuesta del artículo.
            $evidencia = is_array($item->evidencia) ? $item->evidencia : [];
            $cambios   = [];

            foreach (DimensionApplier::COLUMNA_DE_PROPUESTA as $campo => $columna) {
                // Lo que la persona excluyó no se escribió: sale de la propuesta del item.
                if (in_array($campo, $excluidos, true)) {
                    $cambios[$columna] = null;
                    unset($evidencia[$campo]);
                    continue;
                }

                // Solo si el número realmente difiere de lo propuesto es «editado»: ya no es lo que dice la
                // página, así que pierde su cita, su valor y unidad originales y sus avisos.
                if (isset($editados[$campo]) && (is_null($item->{$columna}) || abs((float) $item->{$columna} - $editados[$campo]) >= 0.00005)) {
                    $cambios[$columna] = $editados[$campo];

                    $unidad = DimensionValueValidator::UNIDAD_CANONICA[DimensionValueValidator::CAMPOS[$campo]];
                    $previa = isset($evidencia[$campo]) && is_array($evidencia[$campo]) ? $evidencia[$campo] : [];

                    $evidencia[$campo] = array_merge($previa, [
                        'valor'           => $editados[$campo],
                        'unidad'          => $unidad,
                        'valor_original'  => $editados[$campo],
                        'unidad_original' => $unidad,
                        'convertido'      => false,
                        'cita'            => null,
                        'editado'         => true,
                        'ambiguo'         => false,
                        'rango'           => false,
                        'aviso'           => null,
                    ]);
                }
            }

            $detalle = $item->motivo_detalle;

            if (!empty($salteados)) {
                $detalle = rtrim((string) $detalle).' Al aprobar no se pisó lo ya cargado: '.self::lista_de_campos($salteados).'.';
            }

            $item->fill(array_merge($cambios, [
                'status'         => DimensionSearchItem::STATUS_APROBADA,
                'motivo_detalle' => $detalle,
                'evidencia'      => $evidencia,
                'aplicado'       => $aplicacion['escritos'],
                'revisado_por'   => self::entero_o_null($auth_user_id),
                'revisado_at'    => Carbon::now(),
            ]));
            $item->save();

            return self::respuesta(200, null, $item, ['llenados' => $llenados, 'salteados' => $salteados, 'excluidos' => $excluidos]);
        });
    }
    /**
     * Rechaza una propuesta «a revisar»: no se escribe nada y el artículo queda en «Sin datos». Lo
     * propuesto se descarta (el diagnóstico queda).
     *
     * @param  int      $owner_id
     * @param  int      $item_id
     * @param  int|null $auth_user_id
     * @return array
     */
    public static function rechazar($owner_id, $item_id, $auth_user_id = null)
    {
        return DB::transaction(function () use ($owner_id, $item_id, $auth_user_id) {
            $item = DimensionSearchItem::where('user_id', (int) $owner_id)->where('id', (int) $item_id)->lockForUpdate()->first();

            if (is_null($item)) {
                return self::respuesta(404, 'No se encontró ese artículo de la búsqueda de medidas.');
            }

            if ($item->status !== DimensionSearchItem::STATUS_A_REVISAR) {
                return self::respuesta(422, 'Este artículo ya no está esperando revisión.', $item);
            }

            $quien = self::nombre_de($auth_user_id);

            $item->fill(array_merge(self::propuesta_vacia(), [
                'status'         => DimensionSearchItem::STATUS_RECHAZADA,
                'motivo'         => 'rechazada',
                'motivo_detalle' => 'Se propusieron medidas y '.(is_null($quien) ? 'se rechazaron' : 'las rechazó '.$quien).'.',
                'revisado_por'   => self::entero_o_null($auth_user_id),
                'revisado_at'    => Carbon::now(),
            ]));
            $item->save();

            return self::respuesta(200, null, $item);
        });
    }

    /**
     * Quita lo que la búsqueda cargó en un artículo (asignada o aprobada): cada campo vuelve a su valor
     * anterior (vacío) SOLO si todavía vale lo que puso la búsqueda; uno que alguien editó después se
     * conserva (D6).
     *
     * @param  int      $owner_id
     * @param  int      $item_id
     * @param  int|null $auth_user_id
     * @return array
     */
    public static function quitar($owner_id, $item_id, $auth_user_id = null)
    {
        return DB::transaction(function () use ($owner_id, $item_id, $auth_user_id) {
            $item = DimensionSearchItem::where('user_id', (int) $owner_id)->where('id', (int) $item_id)->lockForUpdate()->first();

            if (is_null($item)) {
                return self::respuesta(404, 'No se encontró ese artículo de la búsqueda de medidas.');
            }

            if (!in_array($item->status, DimensionSearchItem::ESTADOS_ASIGNADAS, true)) {
                return self::respuesta(422, 'Solo se pueden quitar las medidas de un artículo asignado.', $item);
            }

            $reversion = DimensionApplier::revertir($owner_id, $item->article_id, is_array($item->aplicado) ? $item->aplicado : []);

            $quien   = self::nombre_de($auth_user_id);
            $detalle = 'Se habían cargado medidas y '.(is_null($quien) ? 'se quitaron' : 'las quitó '.$quien).'.';

            if (!empty($reversion['conservados'])) {
                $detalle .= ' Se conservó '.self::lista_de_campos($reversion['conservados']).' porque se modificó después.';
            }

            $item->fill(array_merge(self::propuesta_vacia(), [
                'status'         => DimensionSearchItem::STATUS_QUITADA,
                'motivo'         => 'quitada',
                'motivo_detalle' => $detalle,
                'aplicado'       => null,
                'revisado_por'   => self::entero_o_null($auth_user_id),
                'revisado_at'    => Carbon::now(),
            ]));
            $item->save();

            return self::respuesta(200, null, $item, ['revertidos' => $reversion['revertidos'], 'conservados' => $reversion['conservados']]);
        });
    }

    /**
     * Aprueba o rechaza varios de una vez. Cada uno va por su lado (uno que falla no frena a los demás) y
     * se devuelve cuántos salieron y por qué falló cada uno de los otros.
     *
     * @param  string   $accion        'aprobar' | 'rechazar'
     * @param  int      $owner_id
     * @param  mixed    $ids
     * @param  int|null $auth_user_id
     * @return array  ['hechos' => int, 'fallidos' => [['id', 'message']]]
     */
    public static function en_lote($accion, $owner_id, $ids, $auth_user_id = null)
    {
        $hechos   = 0;
        $fallidos = [];
        $vistos   = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            $id = (int) $id;

            if ($id <= 0 || isset($vistos[$id])) {
                continue;
            }

            $vistos[$id] = true;

            try {
                $resultado = $accion === 'aprobar'
                    ? self::aprobar($owner_id, $id, $auth_user_id)
                    : self::rechazar($owner_id, $id, $auth_user_id);
            } catch (\Throwable $e) {
                Log::warning('[MedidasMasivas] Falló '.$accion.' en lote.', ['item_id' => $id, 'error' => $e->getMessage()]);

                $resultado = self::respuesta(500, 'No se pudo '.$accion.': error interno.');
            }

            if ($resultado['status'] === 200) {
                $hechos++;
            } else {
                $fallidos[] = ['id' => $id, 'message' => (string) $resultado['message']];
            }
        }

        return ['hechos' => $hechos, 'fallidos' => $fallidos];
    }

    /**
     * Valida lo que la persona decidió al aprobar (ver aprobar()): una clave ausente usa lo propuesto, una
     * clave con `null` (o vacía) EXCLUYE el campo, una clave con un número lo reemplaza.
     *
     * @param  mixed $valores  Lo que llegó en el cuerpo: campo => número o null.
     * @return array|string  ['editados' => campo => float, 'excluidos' => [campo, ...]], o el mensaje de error en español.
     */
    protected static function valores_editados($valores)
    {
        if (is_null($valores)) {
            return ['editados' => [], 'excluidos' => []];
        }

        if (!is_array($valores)) {
            return 'Los valores tienen que ser un objeto con peso, alto, ancho y profundidad.';
        }

        $editados  = [];
        $excluidos = [];

        foreach (DimensionValueValidator::CAMPOS as $campo => $tipo) {
            if (!array_key_exists($campo, $valores)) {
                continue;
            }

            // Presente pero sin valor: la persona sacó el campo.
            if (is_null($valores[$campo]) || (is_string($valores[$campo]) && trim($valores[$campo]) === '')) {
                $excluidos[] = $campo;
                continue;
            }

            $crudo = $valores[$campo];
            $crudo = is_string($crudo) ? str_replace(',', '.', trim($crudo)) : $crudo;

            $maximo   = $tipo === DimensionValueValidator::TIPO_PESO ? DimensionValueValidator::MAXIMO_PESO_KG : DimensionValueValidator::MAXIMO_MEDIDA_CM;
            $unidad   = DimensionValueValidator::UNIDAD_CANONICA[$tipo];
            $etiqueta = $campo === 'profundidad' ? 'largo' : $campo;

            if (!is_numeric($crudo) || (float) $crudo <= 0 || (float) $crudo > $maximo) {
                return 'El '.$etiqueta.' tiene que ser un número mayor a 0 y hasta '.number_format($maximo, 0, ',', '.').' '.$unidad.'.';
            }

            $editados[$campo] = round((float) $crudo, DimensionApplier::DECIMALES);
        }

        return ['editados' => $editados, 'excluidos' => $excluidos];
    }
    /**
     * Las columnas de la propuesta en null (lo que queda de un artículo rechazado o quitado).
     *
     * @return array
     */
    protected static function propuesta_vacia()
    {
        return [
            'peso_kg'        => null,
            'alto_cm'        => null,
            'ancho_cm'       => null,
            'profundidad_cm' => null,
            'evidencia'      => null,
            'fuentes'        => null,
        ];
    }

    /**
     * «el peso», «el peso y el alto», «el peso, el alto y el largo»: la lista de campos en una frase.
     *
     * @param  array $campos  Claves de campo.
     * @return string
     */
    protected static function lista_de_campos(array $campos)
    {
        $nombres = [];

        foreach ($campos as $campo) {
            $nombres[] = 'el '.mb_strtolower(isset(self::ETIQUETAS[$campo]) ? self::ETIQUETAS[$campo] : $campo);
        }

        if (count($nombres) <= 1) {
            return implode('', $nombres);
        }

        $ultimo = array_pop($nombres);

        return implode(', ', $nombres).' y '.$ultimo;
    }

    /* ----------------------------------------------------------------------------------------
     * Detener, reanudar, cerrar
     * -------------------------------------------------------------------------------------- */

    /**
     * Detiene una corrida en curso. El tramo que esté trabajando termina el artículo que tiene entre
     * manos, ve el estado y corta. (Quién puede: el controlador.)
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return array  ['status' => 200|422, 'message' => string|null]
     */
    public static function detener(DimensionSearchRun $run)
    {
        if (!$run->esta_activa()) {
            return ['status' => 422, 'message' => 'La búsqueda no está en proceso.'];
        }

        $motivo = $run->origen === DimensionSearchRun::ORIGEN_CATALOGO
            ? 'La búsqueda se detuvo desde el acceso maestro. Los artículos que faltaban quedaron pendientes: se puede reanudar.'
            : 'La búsqueda se detuvo a mano. Los artículos que faltaban quedaron pendientes: se puede reanudar.';

        if (!self::terminar($run, DimensionSearchRun::STATUS_DETENIDA, $motivo)) {
            return ['status' => 422, 'message' => 'La búsqueda ya había terminado.'];
        }

        return ['status' => 200, 'message' => null];
    }

    /**
     * Reanuda una corrida detenida, fallida o trabada: los artículos que habían quedado a medias vuelven a
     * pendiente y se despacha un tramo. Una de catálogo no se reanuda si hay OTRA de catálogo en curso.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return array  ['status' => 200|422, 'message' => string|null]
     */
    public static function reanudar(DimensionSearchRun $run)
    {
        // En una demo sin búsquedas, reanudar solo volvería a cortarse.
        if (TopeSerperDemo::agotado((int) $run->user_id)) {
            return ['status' => 422, 'message' => TopeSerperDemo::MENSAJE];
        }

        return DB::transaction(function () use ($run) {
            // La decisión se toma sobre la fila BLOQUEADA, no sobre la copia que llegó: si un tramo la
            // terminó entre que se leyó y ahora, reanudarla con la copia vieja la dejaría en proceso sin dueño.
            $run = DimensionSearchRun::where('id', $run->id)->lockForUpdate()->first();

            $trabada = !is_null($run) && $run->esta_trabada();

            if (is_null($run) || (!$trabada && !in_array($run->status, [DimensionSearchRun::STATUS_DETENIDA, DimensionSearchRun::STATUS_FALLIDA], true))) {
                return ['status' => 422, 'message' => 'Solo se puede reanudar una búsqueda detenida, fallida o que parece trabada.'];
            }

            if ($run->origen === DimensionSearchRun::ORIGEN_CATALOGO) {
                $otra = DimensionSearchRun::where('user_id', (int) $run->user_id)
                    ->where('origen', DimensionSearchRun::ORIGEN_CATALOGO)
                    ->where('id', '!=', (int) $run->id)
                    ->activas()
                    ->exists();

                if ($otra) {
                    return ['status' => 422, 'message' => 'Ya hay otra búsqueda de todo el catálogo en curso: esperá a que termine o detenela antes de reanudar esta.'];
                }
            }

            $ahora = Carbon::now();

            // Lo que un worker muerto dejó a medias vuelve a la fila (conserva sus intentos). Solo lo que no
            // se tocó hace rato: uno reciente lo está terminando un tramo vivo.
            DB::table('dimension_search_items')
                ->where('run_id', $run->id)
                ->where('status', DimensionSearchItem::STATUS_PROCESANDO)
                ->where('updated_at', '<', $ahora->copy()->subMinutes(self::MINUTOS_PARA_DAR_POR_MUERTO_UN_ARTICULO))
                ->update(['status' => DimensionSearchItem::STATUS_PENDIENTE, 'updated_at' => $ahora]);

            // Los que no se pudieron buscar por el proveedor o por la IA (no por el producto) se vuelven a
            // intentar: dejaron de contar como procesados.
            $reintentables = DB::table('dimension_search_items')
                ->where('run_id', $run->id)
                ->where('status', DimensionSearchItem::STATUS_SIN_PROCESAR)
                ->whereIn('motivo', ['error_de_busqueda', 'ia_sin_respuesta'])
                ->update(['status' => DimensionSearchItem::STATUS_PENDIENTE, 'motivo' => null, 'motivo_detalle' => null, 'intentos' => 0, 'procesado_at' => null, 'updated_at' => $ahora]);

            $run->fill([
                // La trabada sigue «en proceso» (el tramo nuevo la toma así); las otras vuelven a esperar al worker.
                'status'                     => $trabada ? DimensionSearchRun::STATUS_EN_PROCESO : DimensionSearchRun::STATUS_PENDIENTE,
                'motivo_estado'              => null,
                'finished_at'                => null,
                'procesados'                 => max(0, (int) $run->procesados - (int) $reintentables),
                'fallos_consecutivos'        => 0,
                'errores_proveedor_seguidos' => 0,
                'errores_ia_seguidos'        => 0,
                'last_progress_at'           => $ahora,
                'visto_at'                   => null,
            ]);
            $run->save();

            ProcessDimensionSearchRunJob::dispatch((int) $run->id)->afterCommit();

            return ['status' => 200, 'message' => null];
        });
    }

    /**
     * Saca una corrida de proceso (terminada, detenida o fallida), UNA sola vez: el cambio de estado es
     * atómico y solo el que lo consigue cuenta.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  string      $status  terminada | detenida | fallida
     * @param  string|null $motivo  Por qué terminó así (para mostrar).
     * @return bool  true si esta llamada la cerró.
     */
    public static function terminar(DimensionSearchRun $run, $status, $motivo = null)
    {
        $ahora = Carbon::now();

        $cambiadas = DB::table('dimension_search_runs')
            ->where('id', $run->id)
            ->whereIn('status', [DimensionSearchRun::STATUS_PENDIENTE, DimensionSearchRun::STATUS_EN_PROCESO])
            ->update([
                'status'        => (string) $status,
                'motivo_estado' => $motivo,
                'finished_at'   => $ahora,
                'updated_at'    => $ahora,
            ]);

        if ($cambiadas === 0) {
            return false;
        }

        $run->refresh();

        return true;
    }

    /**
     * Borra las corridas terminadas hace más de DIAS_PARA_PURGAR días que no tienen nada esperando
     * revisión (esas se quedan: todavía hay una decisión pendiente). Nunca voltea a quien la llama.
     *
     * @param  int $owner_id
     * @return int  Corridas borradas.
     */
    public static function purgar_viejas($owner_id)
    {
        try {
            $ids = DimensionSearchRun::where('user_id', (int) $owner_id)
                ->whereIn('status', [DimensionSearchRun::STATUS_TERMINADA, DimensionSearchRun::STATUS_DETENIDA, DimensionSearchRun::STATUS_FALLIDA])
                ->where('finished_at', '<', Carbon::now()->subDays(self::DIAS_PARA_PURGAR))
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('dimension_search_items')
                        ->whereColumn('dimension_search_items.run_id', 'dimension_search_runs.id')
                        ->where('dimension_search_items.status', DimensionSearchItem::STATUS_A_REVISAR);
                })
                ->pluck('id')
                ->all();

            foreach (array_chunk($ids, 200) as $lote) {
                DB::table('dimension_search_items')->whereIn('run_id', $lote)->delete();
                DB::table('dimension_search_runs')->whereIn('id', $lote)->delete();
            }

            return count($ids);
        } catch (\Throwable $e) {
            Log::warning('[MedidasMasivas] No se pudieron purgar corridas viejas: '.$e->getMessage());

            return 0;
        }
    }

    /* ----------------------------------------------------------------------------------------
     * Internos
     * -------------------------------------------------------------------------------------- */

    /**
     * A qué grupo del contrato pertenece un estado de item.
     *
     * @param  string $status
     * @return string|null
     */
    protected static function grupo_de_estado($status)
    {
        if (in_array($status, DimensionSearchItem::ESTADOS_ASIGNADAS, true)) {
            return 'asignadas';
        }

        if (in_array($status, DimensionSearchItem::ESTADOS_A_REVISAR, true)) {
            return 'a_revisar';
        }

        if (in_array($status, DimensionSearchItem::ESTADOS_SIN_DATOS, true)) {
            return 'sin_datos';
        }

        if (in_array($status, DimensionSearchItem::ESTADOS_PENDIENTES, true)) {
            return 'pendientes';
        }

        return null;
    }

    /**
     * «Lanzada por»: ComercioCity para las del catálogo (solo las lanza el acceso maestro, que entra con
     * el usuario del dueño: mostrar el nombre del dueño diría que la lanzó él), y el nombre de quien la
     * pidió para el resto.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  array $nombres
     * @return string|null
     */
    protected static function lanzada_por(DimensionSearchRun $run, array $nombres)
    {
        if ($run->origen === DimensionSearchRun::ORIGEN_CATALOGO) {
            return self::LANZADA_POR_ACCESO_MAESTRO;
        }

        $id = is_null($run->creada_por) ? null : (int) $run->creada_por;

        return !is_null($id) && isset($nombres[$id]) ? $nombres[$id] : null;
    }

    /**
     * El nombre de un usuario (null si no hay o no existe).
     *
     * @param  int|null $id
     * @return string|null
     */
    protected static function nombre_de($id)
    {
        $nombres = self::nombres_de_usuarios([$id]);

        return !is_null($id) && isset($nombres[(int) $id]) ? $nombres[(int) $id] : null;
    }

    /**
     * Nombres de varios usuarios en una consulta.
     *
     * @param  array $ids
     * @return array  id => nombre
     */
    protected static function nombres_de_usuarios(array $ids)
    {
        $limpios = [];

        foreach ($ids as $id) {
            if (!is_null($id) && (int) $id > 0) {
                $limpios[(int) $id] = true;
            }
        }

        if (empty($limpios)) {
            return [];
        }

        $nombres = [];

        foreach (User::whereIn('id', array_keys($limpios))->get(['id', 'name']) as $usuario) {
            $nombres[(int) $usuario->id] = (string) $usuario->name;
        }

        return $nombres;
    }

    /**
     * Acota el tamaño de página a 10..100 (25 por defecto).
     *
     * @param  mixed $por_pagina
     * @return int
     */
    protected static function por_pagina($por_pagina)
    {
        $por_pagina = (int) $por_pagina;

        if ($por_pagina <= 0) {
            return self::POR_PAGINA_DEFECTO;
        }

        return max(self::POR_PAGINA_MINIMO, min(self::POR_PAGINA_MAXIMO, $por_pagina));
    }

    /**
     * @param  int         $status
     * @param  string|null $message
     * @param  \App\Models\DimensionSearchItem|null $item
     * @param  array       $extra   Datos que el controlador agrega a un error (409: llenados / salteados).
     * @return array
     */
    protected static function respuesta($status, $message = null, $item = null, array $extra = [])
    {
        return ['status' => (int) $status, 'message' => $message, 'item' => $item, 'extra' => $extra];
    }

    /**
     * @param  mixed $valor
     * @return int|null
     */
    protected static function entero_o_null($valor)
    {
        return is_null($valor) || (int) $valor <= 0 ? null : (int) $valor;
    }

    /**
     * @param  mixed $fecha
     * @return string|null  ISO 8601 con zona.
     */
    protected static function fecha($fecha)
    {
        return is_null($fecha) ? null : Carbon::parse($fecha)->toIso8601String();
    }
}
