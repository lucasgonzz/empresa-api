<?php

namespace App\Services\OfertasClientes;

use App\Models\Article;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El motor de una corrida de ofertas: convierte los candidatos de CriteriosDeOfertaService en
 * líneas listas para insertar (cliente × artículo × porcentaje), para un lote de la corrida.
 *
 * 🔴 NO ESCRIBE NADA EN LA BASE: devuelve filas planas y el llamador (ProcessOfferSuggestionChunkJob)
 * les agrega offer_suggestion_id / prioridad / timestamps y hace el bulk insert. Mismo contrato que
 * PurchaseSuggestionService::calcular_para_articulos().
 * 🔴 EL CHUNK ES POR CLIENTE, NO POR ARTÍCULO, y es la única diferencia estructural con el molde de
 * sugerencias de compra (que chunkea por artículo). El motivo: el tope max_ofertas_por_cliente
 * (§A.5.6) se aplica POR CLIENTE, así que si dos chunks distintos pudieran tocar al mismo cliente
 * habría que coordinarlos entre jobs para no pasarse del tope; agrupando por cliente, cada chunk se
 * lleva clientes enteros y el tope se resuelve adentro sin hablar con nadie. No "normalizar al molde".
 * La cadena por línea, toda determinista y sin IA de por medio: TechoDeDescuentoService::evaluar() da
 * techo, piso, margen, precio y costo (o la exclusión); PorcentajeSugeridoService da la vendibilidad
 * 0..1 y el porcentaje adentro del rango; el historial de venta decide si el descuento es por unidad
 * o por cantidad, y de ahí salen los tramos. La IA entra DESPUÉS y solo por
 * aplicar_decision_de_la_ia(), que recorta lo que devuelva.
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class OfertaSugeridaService
{
    /** Lote de lectura de artículos con relaciones (price_types pesa en cuentas con listas). */
    const LOTE_LECTURA_ARTICULOS = 50;

    /** Vigencia mínima que se puede proponer: menos de 3 días no le da tiempo a nadie a entrar. */
    const DIAS_VIGENCIA_MINIMOS = 3;

    /**
     * 🔴 VIGENCIA MÁXIMA DE UNA OFERTA, EN DÍAS, Y EL ÚNICO LUGAR DONDE VIVE ESE NÚMERO. Más allá
     * deja de ser una oferta y pasa a ser el precio nuevo del artículo.
     *
     * ClientOfferController::MAX_DIAS_VIGENCIA apunta a esta constante en vez de tener la suya: hasta
     * el 15/8/2026 había DOS topes distintos —la IA podía proponer hasta dias_vigencia_sugerida * 2,
     * que con la config del formulario llega a 360, y el controller rechazaba cualquier `hasta` a más
     * de 180 días—, así que el datepicker del modal se abría con una fecha PRECARGADA por el motor que
     * el backend contestaba con 422. El comerciante no tenía cómo entender qué había hecho mal: él no
     * eligió esa fecha, se la propuso el sistema. Dos criterios para la misma regla es cómo se llega a
     * eso; por eso ahora hay uno solo y los dos lados lo leen de acá.
     */
    const DIAS_VIGENCIA_MAXIMOS = 180;

    /**
     * Cantidades donde arranca cada tramo de una oferta 'cantidad'. El primero SIEMPRE en 1 (§A.3); los
     * otros dos son una propuesta de arranque —el comerciante los edita en el modal antes de activar— y
     * no salen del historial a propósito: derivarlos del promedio de venta de cada artículo daría
     * tramos distintos por línea y una tabla que nadie puede leer de un vistazo.
     */
    const CORTES_DE_TRAMO = [1, 5, 10];

    /** @var mixed Cabecera de la corrida: de ahí salen los parámetros del algoritmo */
    protected $suggestion;
    /** @var mixed Dueño del comercio */
    protected $user;
    /** @var int */
    protected $user_id;
    /** @var CriteriosDeOfertaService */
    protected $criterios;

    /**
     * @param mixed $suggestion Cabecera (OfferSuggestion).
     * @param mixed $user       Dueño; si viene null se busca por suggestion->user_id.
     */
    public function __construct($suggestion, $user = null)
    {
        $this->suggestion = $suggestion;
        $this->user_id    = (int) $suggestion->user_id;
        $this->user       = is_null($user) ? User::find($this->user_id) : $user;
        $this->criterios  = new CriteriosDeOfertaService($this->suggestion, $this->user);
    }

    /**
     * Los candidatos de TODA la corrida: los tres criterios, el dedupe, la exclusión por deuda y el
     * tope por cliente. Corre UNA sola vez por corrida (en el job padre) y no una por chunk: son las
     * consultas caras del motor y repetirlas por lote las multiplicaría por la cantidad de lotes.
     * @return array
     */
    public function candidatos(): array
    {
        return $this->criterios->candidatos();
    }

    /** @return int Clientes que quedaron afuera por deuda en la última corrida de candidatos() */
    public function clientes_excluidos_por_deuda(): int
    {
        return $this->criterios->clientes_excluidos_por_deuda();
    }

    /** @return array Mapa client_id => true|false|null de la última corrida de candidatos() */
    public function evaluacion_crediticia(): array
    {
        return $this->criterios->evaluacion_crediticia();
    }

    /**
     * Calcula las líneas de un lote de candidatos (los de un chunk de clientes).
     * @param  array $candidatos            Salida de candidatos(), acotada a los clientes del chunk.
     * @param  array $evaluacion_crediticia Mapa client_id => true|false|null, de la misma corrida.
     * @return array Filas planas listas para insertar (sin offer_suggestion_id ni timestamps).
     */
    public function calcular_para_candidatos(array $candidatos, array $evaluacion_crediticia): array
    {
        $lineas = [];
        if (empty($candidatos)) {
            return $lineas;
        }

        $article_ids = array_values(array_unique(array_map('intval', array_column($candidatos, 'article_id'))));
        $client_ids  = array_values(array_unique(array_map('intval', array_column($candidatos, 'client_id'))));
        $articulos = $this->articulos_del_lote($article_ids);
        $clientes  = Client::where('user_id', $this->user_id)->whereIn('id', $client_ids)->get()->keyBy('id');
        // Las consultas agregadas del lote: una vez cada una para TODO el chunk y nunca una por
        // línea (article_purchases tiene 10⁵-10⁶ filas y acá se recorre par por par).
        $porcentaje_service = new PorcentajeSugeridoService($this->user_id);
        $vendibilidades     = $porcentaje_service->vendibilidades($article_ids);
        $tipos              = $this->tipos_de_descuento($article_ids);
        $dias_vigencia      = (int) $this->suggestion->dias_vigencia_sugerida;

        foreach ($candidatos as $candidato) {
            $article = isset($articulos[$candidato['article_id']]) ? $articulos[$candidato['article_id']] : null;
            $client  = isset($clientes[$candidato['client_id']]) ? $clientes[$candidato['client_id']] : null;

            // Un artículo inactivo, borrado o de otro comercio entre que se armaron los candidatos y
            // se calculó el lote: la línea no se sugiere, y no es un error de la corrida.
            if (is_null($article) || is_null($client)) {
                continue;
            }

            // factor_credito SIEMPRE 1.0: desde la decisión de Lucas del 15/8/2026 el mal pagador se
            // excluye entero en CriteriosDeOfertaService y nunca llega hasta acá, así que no queda
            // ninguna señal que baje el techo. El parámetro se pasa explícito igual porque el
            // invariante "el factor nunca es > 1" es lo que blinda el techo y tiene que quedar visible
            // en el llamador, no solo adentro del servicio.
            $techo = TechoDeDescuentoService::evaluar($article, $client, $this->user, 1.0);

            if (!is_null($techo['excluido_por'])) {
                continue;
            }

            $vendibilidad = isset($vendibilidades[$candidato['article_id']])
                ? (float) $vendibilidades[$candidato['article_id']]
                : 1.0;

            $porcentaje = $porcentaje_service->porcentaje($techo['piso'], $techo['techo'], $vendibilidad);
            $tipo       = isset($tipos[$candidato['article_id']]) ? $tipos[$candidato['article_id']] : 'unidad';

            $lineas[] = [
                'client_id'                  => (int) $candidato['client_id'],
                'article_id'                 => (int) $candidato['article_id'],
                'tipo_descuento'             => $tipo,
                'porcentaje_sugerido'        => $porcentaje,
                'porcentaje_techo'           => $techo['techo'],
                'porcentaje_piso'            => $techo['piso'],
                'margen_base'                => round((float) $techo['margen'], 2),
                'precio_vigente'             => $techo['precio_vigente'],
                'costo_real'                 => $techo['costo'],
                'price_type_id'              => $techo['price_type_id'],
                'criterio'                   => $candidato['criterio'],
                'criterio_detalle'           => $candidato['detalle'],
                // null (sin datos de cuenta corriente) no es false (mal pagador): no se colapsan.
                'es_buen_pagador'            => isset($evaluacion_crediticia[$candidato['client_id']])
                    ? $evaluacion_crediticia[$candidato['client_id']]
                    : null,
                'factor_credito'             => $techo['factor_credito'],
                'vendibilidad'               => round($vendibilidad, 3),
                'score'                      => round((float) $candidato['score'], 4),
                'fecha_vencimiento_sugerida' => Carbon::now()->addDays($dias_vigencia)->toDateString(),
                'tramos_sugeridos'           => $tipo === 'cantidad'
                    ? json_encode(self::armar_tramos($porcentaje, $techo['techo']))
                    : null,
            ];
        }
        return $lineas;
    }

    /**
     * 🔴 EL RECORTE DE LA DECISIÓN DE LA IA. Corre DESPUÉS de recibir la respuesta y es
     * INCONDICIONAL, sobre cualquier respuesta y sin mirar si "parece razonable": el techo es
     * determinista y sale del margen del artículo (TechoDeDescuentoService), así que ningún número
     * que devuelva la IA —ni 90, ni -5, ni "mucho"— puede dejar una oferta por debajo del costo. Hay
     * un test que le manda 999 y comprueba que la línea queda EXACTAMENTE en el techo.
     * 🔴 NO simplificar a "confiar en el prompt": el prompt es una sugerencia, esto es la garantía.
     * El fallback cuando el número no es numérico es el porcentaje que la línea YA tiene guardado, que
     * es el determinista de PorcentajeSugeridoService (la IA todavía no lo pisó): así la línea jamás
     * queda sin número.
     *
     * @param  mixed $linea                  OfferSuggestionLine ya persistida con sus deterministas.
     * @param  array $decision               Entrada de la IA: porcentaje, dias_vigencia, motivo, tramos.
     * @param  int   $dias_vigencia_sugerida De la cabecera: el tope es su doble.
     * @return array Atributos a guardar en la línea.
     */
    public static function aplicar_decision_de_la_ia($linea, array $decision, $dias_vigencia_sugerida)
    {
        $piso  = (float) $linea->porcentaje_piso;
        $techo = (float) $linea->porcentaje_techo;
        $porcentaje = self::recortar(
            isset($decision['porcentaje']) ? $decision['porcentaje'] : null,
            $piso,
            $techo,
            (float) $linea->porcentaje_sugerido
        );

        // Idéntico tratamiento para la vigencia: 3 días de piso y el tope de tope_de_vigencia(), que
        // es el que también valida el controller al activar. Una promoción de un año no es una oferta,
        // es el precio nuevo.
        $dias = (int) self::recortar(
            isset($decision['dias_vigencia']) ? $decision['dias_vigencia'] : null,
            self::DIAS_VIGENCIA_MINIMOS,
            self::tope_de_vigencia($dias_vigencia_sugerida),
            (int) $dias_vigencia_sugerida
        );

        $atributos = [
            'porcentaje_sugerido' => $porcentaje,
            'fecha_vencimiento_sugerida' => Carbon::now()->addDays($dias)->toDateString(),
            'motivo_ia' => isset($decision['motivo']) && is_string($decision['motivo']) ? trim($decision['motivo']) : null,
        ];
        if ($linea->tipo_descuento !== 'cantidad') {
            return $atributos;
        }

        /*
         * 🔴 Y los tramos por cantidad pasan por EL MISMO recorte, uno por uno. Es el agujero
         * evidente si el recorte se hiciera solo sobre el porcentaje de la línea: en una oferta
         * 'cantidad' el número que se le cobra al cliente está en los tramos, no en la columna.
         */
        $tramos = (isset($decision['tramos']) && is_array($decision['tramos']) && !empty($decision['tramos']))
            ? self::recortar_tramos($decision['tramos'], $piso, $techo)
            : self::armar_tramos($porcentaje, $techo);

        // El porcentaje de la línea acompaña al primer tramo: es lo que paga una unidad suelta.
        $atributos['porcentaje_sugerido'] = $tramos[0]['porcentaje'];
        $atributos['tramos_sugeridos']    = json_encode($tramos);

        return $atributos;
    }

    /**
     * 🔴 EL TOPE DE VIGENCIA QUE SE LE PERMITE PROPONER A LA IA, en UN solo lugar. Es el doble de la
     * vigencia elegida para la corrida —para que la IA tenga margen de decisión— pero nunca más que
     * DIAS_VIGENCIA_MAXIMOS, que es lo que el controller acepta al activar. Sin este min(), una
     * corrida con dias_vigencia_sugerida = 90 (el formulario lo permite) proponía fechas a 180 días y
     * el 422 del controller aparecía sobre una fecha que el propio motor había precargado.
     *
     * Lo usan aplicar_decision_de_la_ia() para recortar y ResumenIaOfertasService para decirle a la IA
     * el rango: los dos tienen que decir lo mismo, si no el prompt promete un número que el recorte
     * después baja sin explicación.
     *
     * @param  int $dias_vigencia_sugerida De la cabecera de la corrida.
     * @return int
     */
    public static function tope_de_vigencia($dias_vigencia_sugerida)
    {
        $doble = (int) $dias_vigencia_sugerida * 2;

        return $doble > self::DIAS_VIGENCIA_MAXIMOS ? self::DIAS_VIGENCIA_MAXIMOS : $doble;
    }

    /**
     * Los tramos de una oferta 'cantidad' (§A.3): 2 o 3, contiguos y sin huecos, el primero desde la
     * unidad y el último sin techo de cantidad (max null). El porcentaje CRECE con la cantidad, arranca
     * en el sugerido de la línea y 🔴 el último toca el techo, que es el máximo que el margen del
     * artículo tolera: comprar de a muchos da todo lo que se puede dar, y ni un punto más. Ningún
     * tramo puede superar el techo, por construcción.
     * @param  float $desde Porcentaje del primer tramo (el de una unidad suelta).
     * @param  float $techo Porcentaje del último tramo.
     * @return array Lista de ['min','max','porcentaje']
     */
    public static function armar_tramos($desde, $techo)
    {
        $desde = (float) $desde;
        $techo = (float) $techo;
        $desde = $desde > $techo ? $techo : $desde;
        // Tres tramos solo si hay lugar para un escalón intermedio que se note; si no, dos: una
        // escalera de 12%, 12% y 13% no le mueve la decisión de compra a nadie.
        $cortes = ($techo - $desde) >= 2 ? self::CORTES_DE_TRAMO : [self::CORTES_DE_TRAMO[0], self::CORTES_DE_TRAMO[1]];
        $ultimo = count($cortes) - 1;
        $tramos = [];
        foreach ($cortes as $i => $min) {
            $tramos[] = [
                'min' => $min,
                // Contiguos y sin huecos: el max de uno es el min del siguiente menos 1, y el último
                // queda en null (sin techo de cantidad).
                'max'        => $i === $ultimo ? null : $cortes[$i + 1] - 1,
                'porcentaje' => floor($desde + ($techo - $desde) * ($i / $ultimo)),
            ];
        }
        return $tramos;
    }

    /**
     * Recorta a [piso, techo] los porcentajes que devolvió la IA y NORMALIZA LA ESCALERA de tramos a
     * lo que el backend acepta al activar.
     *
     * 🔴 LA CONTIGÜIDAD NO ES COSMÉTICA Y ACÁ NO SE PUEDE CONFIAR EN EL PROMPT. Hasta el 15/8/2026
     * esto recortaba los porcentajes y copiaba los `min` tal cual venían, sin exigir que arrancaran en
     * 1 ni que fueran seguidos; ClientOfferController::validar_tramos() sí lo exige. Un solo hueco
     * (max 5 y el siguiente min 8) se guardaba en la sugerencia sin protestar y recién saltaba como
     * 422 cuando el comerciante apretaba "Activar", contra unos tramos que él no escribió. Y en el
     * medio, el hueco significa que el que lleva 6 unidades no tiene ningún descuento aplicable.
     *
     * Se conservan los ANCHOS que propuso la IA (su decisión de negocio) y se re-anclan los `min` para
     * que no queden ni huecos ni solapamientos: el primero arranca en 1, cada uno empieza donde
     * terminó el anterior, y el último va sin techo de cantidad —si la IA le puso uno, un pedido más
     * grande se quedaría sin descuento, que es lo contrario de la idea—. Es exactamente el juego de
     * reglas de validar_tramos(): si allá se agrega una, acá también.
     *
     * Lo que NO se toca es que los porcentajes crezcan con la cantidad: el backend no lo exige y
     * reescribir eso sería inventarle la decisión a la IA, no normalizarla.
     *
     * @param  array $tramos
     * @param  float $piso
     * @param  float $techo
     * @return array
     */
    protected static function recortar_tramos(array $tramos, $piso, $techo)
    {
        $tramos  = array_values($tramos);
        $ultimo  = count($tramos) - 1;
        $limpios = [];
        $min     = 1;

        foreach ($tramos as $i => $tramo) {
            $max = null;

            if ($i !== $ultimo) {
                $max = isset($tramo['max']) && !is_null($tramo['max']) ? (int) $tramo['max'] : null;
                // Un max nulo o menor que el min propio dejaría un tramo vacío o invertido: se lo
                // achica al mínimo posible (una unidad) en vez de descartar el tramo, que le cambiaría
                // la cantidad de escalones a la propuesta.
                $max = (is_null($max) || $max < $min) ? $min : $max;
            }
            $limpios[] = [
                'min'        => $min,
                'max'        => $max,
                'porcentaje' => self::recortar(isset($tramo['porcentaje']) ? $tramo['porcentaje'] : null, $piso, $techo, $piso),
            ];
            $min = is_null($max) ? $min : $max + 1;
        }

        return $limpios;
    }

    /**
     * Un valor al rango [piso, techo], con fallback cuando no es un número. floor y no round, por lo
     * mismo que el techo (TechoDeDescuentoService, paso 10): hacia abajo nunca se rompe el margen.
     * @param  mixed $valor
     * @param  float $piso
     * @param  float $techo
     * @param  float $fallback
     * @return float
     */
    protected static function recortar($valor, $piso, $techo, $fallback)
    {
        $numero = is_numeric($valor) ? (float) $valor : (float) $fallback;
        if ($numero > $techo) {
            $numero = (float) $techo;
        }
        if ($numero < $piso) {
            $numero = (float) $piso;
        }
        return floor($numero);
    }

    /**
     * Qué tipo de descuento le corresponde a cada artículo del lote: 'cantidad' cuando el historial
     * muestra que se vende de a varios (AVG(article_purchases.amount) > 1), 'unidad' si no. Es
     * DETERMINISTA y no lo decide la IA: proponer tramos por cantidad sobre un artículo que siempre se
     * vende de a uno es una tabla que nadie va a usar.
     * @param  array $article_ids
     * @return array Mapa article_id => 'unidad'|'cantidad'
     */
    protected function tipos_de_descuento(array $article_ids)
    {
        $q = DB::table('article_purchases')
            ->selectRaw('article_purchases.article_id as article_id, AVG(article_purchases.amount) as promedio');
        // A.5.0, el mismo filtro de ventas reales de los criterios: en UN solo lugar.
        CriteriosDeOfertaService::filtro_de_ventas_reales($q, $this->user_id);

        $filas = $q->whereIn('article_purchases.article_id', $article_ids)
            ->groupBy('article_purchases.article_id')
            ->get();

        $tipos = [];
        foreach ($filas as $fila) {
            $tipos[(int) $fila->article_id] = ((float) $fila->promedio) > 1 ? 'cantidad' : 'unidad';
        }
        return $tipos;
    }

    /**
     * Los artículos del lote, del comercio y no inactivos, con price_types cargado porque
     * resolver_precio_de_venta() lo recorre en cuentas con listas (sin eager load es una query por
     * artículo).
     * @param  array $article_ids
     * @return \Illuminate\Support\Collection Mapa article_id => Article
     */
    protected function articulos_del_lote(array $article_ids)
    {
        $articulos = collect();
        Article::where('user_id', $this->user_id)
            ->where('status', '!=', 'inactive')
            ->whereIn('id', $article_ids)
            ->with('price_types')
            ->chunk(self::LOTE_LECTURA_ARTICULOS, function ($lote) use (&$articulos) {
                foreach ($lote as $article) {
                    $articulos[(int) $article->id] = $article;
                }
            });
        return $articulos;
    }
}
