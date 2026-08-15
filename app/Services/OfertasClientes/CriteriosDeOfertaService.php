<?php

namespace App\Services\OfertasClientes;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A quién ofrecerle qué: los tres criterios de marketing del motor de ofertas (§A.5) y el filtro de
 * crédito. Devuelve candidatos `['client_id','article_id','criterio','score','detalle','compras']`;
 * el techo y el porcentaje son de otros servicios y este no los mira.
 *
 * 🔴 LAS DOS TABLAS DE TRACKING (buyer_tracking_events, buyer_tracking_daily) ESTÁN HOY EN CERO
 * FILAS. Medido el 15/8/2026 sobre empresa_testing_s2: se crearon con la misión 2 y las escribe la
 * tienda, que todavía no está desplegada. Por eso el motor tiene que dar sugerencias útiles SIN UNA
 * SOLA FILA DE TRACKING: afinidad (A.5.1) y reactivación (A.5.3) salen de article_purchases, que sí
 * tiene historia, y son los que funcionan desde el día uno. Carrito abandonado (A.5.2) degrada a
 * conjunto vacío y no lanza. Si una corrida vuelve vacía, el problema NO es el tracking.
 *
 * 🔴 EL MAL PAGADOR SE EXCLUYE ENTERO, ANTES DE CALCULAR NADA. Es una DECISIÓN DE NEGOCIO de Lucas
 * del 15/8/2026 —"al que debe hace mucho no se le ofrece nada"—, no una optimización. El plan
 * original le bajaba el techo a la mitad con un FACTOR_MAL_PAGADOR = 0.5; eso quedó sin efecto, y
 * convertirlo de vuelta en un factor es deshacer la decisión. El conteo de los que quedaron afuera
 * se devuelve para que el llamador lo guarde en offer_suggestions.total_clientes_excluidos_por_deuda
 * y se lo muestre al comerciante: es lo que le prueba que el sistema tuvo criterio.
 *
 * No escribe nada en la base. PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class CriteriosDeOfertaService
{
    const CRITERIO_AFINIDAD = 'afinidad';
    const CRITERIO_CARRITO_ABANDONADO = 'carrito_abandonado';
    const CRITERIO_REACTIVACION = 'reactivacion';

    /** Compras mínimas del par (cliente, artículo) para que la afinidad cuente. */
    const MIN_COMPRAS_AFINIDAD = 1;

    /** Un carrito abandonado es la señal más caliente que hay: score fijo y el más alto. */
    const SCORE_CARRITO_ABANDONADO = 3.0;

    /** Piso del score de reactivación, al que se le suma dias_dormido / 365. */
    const SCORE_BASE_REACTIVACION = 2.0;

    /** Cuánto se amplía la ventana de afinidad, solo para un dormido sin historia reciente. */
    const MULTIPLICADOR_VENTANA_DORMIDO = 4;

    /** @var mixed Cabecera de la corrida: de ahí salen los parámetros del algoritmo */
    protected $suggestion;

    /** @var mixed Dueño del comercio */
    protected $user;

    /** @var int */
    protected $user_id;

    /** @var int Clientes candidatos que quedaron afuera por tener ventas sin cobrar */
    protected $clientes_excluidos_por_deuda = 0;

    /** @var array Mapa client_id => true|false|null de la última corrida de candidatos() */
    protected $evaluacion_crediticia = [];

    /**
     * @param mixed $suggestion Cabecera (OfferSuggestion).
     * @param mixed $user       Dueño; si viene null se busca por suggestion->user_id.
     */
    public function __construct($suggestion, $user = null)
    {
        $this->suggestion = $suggestion;
        $this->user_id    = (int) $suggestion->user_id;
        $this->user       = is_null($user) ? User::find($this->user_id) : $user;
    }

    /**
     * El pipeline completo: los tres criterios, dedupe, filtro de crédito y tope por cliente.
     *
     * @return array Lista de ['client_id','article_id','criterio','score','detalle','compras']
     */
    public function candidatos(): array
    {
        $candidatos = $this->dedupe(array_merge(
            $this->afinidad(),
            $this->carrito_abandonado(),
            $this->reactivacion()
        ));

        return $this->tope_por_cliente($this->excluir_malos_pagadores($candidatos));
    }

    /**
     * A.5.1 — Afinidad de compra. EL NÚCLEO, y funciona sin tracking: article_purchases tiene
     * client_id, o sea que da directo "qué le vendí a quién y cuándo".
     *
     * @param  array    $client_ids Acotar a estos clientes (vacío = todos).
     * @param  int|null $dias       Ventana; null = dias_historial_afinidad de la corrida.
     * @return array
     */
    public function afinidad(array $client_ids = [], $dias = null): array
    {
        $dias = is_null($dias) ? (int) $this->suggestion->dias_historial_afinidad : (int) $dias;

        $q = DB::table('article_purchases')->selectRaw(
            'article_purchases.client_id as client_id, article_purchases.article_id as article_id,
             COUNT(*) as compras, MAX(article_purchases.created_at) as ultima'
        );
        self::filtro_de_ventas_reales($q, $this->user_id);
        $q->whereNotNull('article_purchases.client_id')
            // Usa article_purchases_created_at_index (2026_08_14_120400).
            ->where('article_purchases.created_at', '>=', Carbon::now()->subDays($dias))
            ->groupBy('article_purchases.client_id', 'article_purchases.article_id')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_COMPRAS_AFINIDAD]);

        if (!empty($client_ids)) {
            $q->whereIn('article_purchases.client_id', $client_ids);
        }

        $candidatos = [];

        foreach ($q->get() as $fila) {
            $candidatos[] = [
                'client_id'  => (int) $fila->client_id,
                'article_id' => (int) $fila->article_id,
                'criterio'   => self::CRITERIO_AFINIDAD,
                // Cuántas veces lo compró: la señal más directa de que le sirve.
                'score'      => (float) $fila->compras,
                'compras'    => (int) $fila->compras,
                'detalle'    => 'Compró este artículo ' . (int) $fila->compras
                    . ' veces, la última hace ' . $this->dias_desde($fila->ultima) . ' días',
            ];
        }

        return $candidatos;
    }

    /**
     * A.5.2 — Carrito abandonado. Necesita tracking, así que HOY devuelve [] (cero filas medidas), y
     * eso está bien: no lanza y no vacía la corrida.
     *
     * @return array
     */
    public function carrito_abandonado(): array
    {
        /*
         * 🔴 SIEMPRE por occurred_at, NUNCA por created_at (docblock de la migración
         * 2026_08_15_140000:148-151): created_at es cuándo la tienda mandó el evento, occurred_at es
         * cuándo el comprador lo hizo. Además es la columna del índice (user_id, occurred_at).
         *
         * 🔴 El vínculo buyers.comercio_city_client_id es OPCIONAL Y MANUAL (se setea en
         * BuyerController.php:50 y en ProcessArchivoDeIntercambioClientes.php:113): hay buyers sin
         * cliente asociado, y esos NO generan ninguna sugerencia. El join los deja afuera solo, que
         * es el comportamiento correcto: sin client_id no hay a quién ofrecerle nada.
         */
        $filas = DB::table('buyer_tracking_events as e')
            ->join('buyers as b', 'b.id', '=', 'e.buyer_id')
            ->where('e.user_id', $this->user_id)
            ->where('e.event_type', 'cart_add')
            ->where('e.occurred_at', '>=', Carbon::now()->subDays((int) $this->suggestion->dias_carrito_abandonado))
            ->whereNotNull('e.article_id')
            ->whereNotNull('b.comercio_city_client_id')
            ->groupBy('b.comercio_city_client_id', 'e.article_id')
            ->selectRaw('b.comercio_city_client_id as client_id, e.article_id as article_id, MAX(e.occurred_at) as ultimo')
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $ya_comprados = $this->ultima_compra_por_par($filas);
        $candidatos   = [];

        foreach ($filas as $fila) {
            $clave = (int) $fila->client_id . '-' . (int) $fila->article_id;

            /*
             * 🔴 El descarte de "ya lo compró" va ACÁ, en PHP, y no como un NOT EXISTS contra la
             * misma tabla de eventos: checkout_complete NO garantiza traer article_id
             * (BuyerTrackingEvent::TIPOS lo permite null), así que ese NOT EXISTS dejaría pasar
             * carritos que sí terminaron en compra. article_purchases es la verdad de "lo compró".
             */
            if (isset($ya_comprados[$clave]) && $ya_comprados[$clave] >= $fila->ultimo) {
                continue;
            }

            $candidatos[] = [
                'client_id'  => (int) $fila->client_id,
                'article_id' => (int) $fila->article_id,
                'criterio'   => self::CRITERIO_CARRITO_ABANDONADO,
                'score'      => self::SCORE_CARRITO_ABANDONADO,
                'compras'    => 0,
                'detalle'    => 'Lo puso en el carrito hace ' . $this->dias_desde($fila->ultimo) . ' días y no lo compró',
            ];
        }

        return $candidatos;
    }

    /**
     * A.5.3 — Reactivación por inactividad. Funciona sin tracking. El artículo sale del propio
     * historial del cliente dormido: ofrecerle algo que nunca compró no es reactivar, es adivinar.
     *
     * @return array
     */
    public function reactivacion(): array
    {
        $corte = Carbon::now()->subDays((int) $this->suggestion->dias_inactividad_reactivacion);

        $q = DB::table('article_purchases')
            ->selectRaw('article_purchases.client_id as client_id, MAX(article_purchases.created_at) as ultima_compra');
        self::filtro_de_ventas_reales($q, $this->user_id);
        $dormidos = $q->whereNotNull('article_purchases.client_id')
            ->groupBy('article_purchases.client_id')
            ->havingRaw('MAX(article_purchases.created_at) < ?', [$corte])
            ->get();

        if ($dormidos->isEmpty()) {
            return [];
        }

        $ids     = array_map('intval', $dormidos->pluck('client_id')->all());
        $mejores = $this->mejor_articulo_por_cliente($this->afinidad($ids));

        /*
         * Un cliente dormido puede haber comprado por última vez ANTES de la ventana de afinidad
         * (180 días) y entonces no tiene ninguna línea ahí. Para esos, y solo para esos, se amplía
         * la ventana: es una segunda consulta acotada a un puñado de clientes, no un barrido.
         */
        $faltantes = array_values(array_diff($ids, array_keys($mejores)));

        if (!empty($faltantes)) {
            $ventana = (int) $this->suggestion->dias_historial_afinidad * self::MULTIPLICADOR_VENTANA_DORMIDO;
            $mejores = $mejores + $this->mejor_articulo_por_cliente($this->afinidad($faltantes, $ventana));
        }

        $candidatos = [];

        foreach ($dormidos as $fila) {
            $client_id = (int) $fila->client_id;

            // Sin nada en su historial, ni con la ventana ampliada, el cliente queda afuera.
            if (!isset($mejores[$client_id])) {
                continue;
            }

            $dias = $this->dias_desde($fila->ultima_compra);

            $candidatos[] = [
                'client_id'  => $client_id,
                'article_id' => (int) $mejores[$client_id]['article_id'],
                'criterio'   => self::CRITERIO_REACTIVACION,
                'score'      => self::SCORE_BASE_REACTIVACION + ($dias / 365),
                'compras'    => (int) $mejores[$client_id]['compras'],
                'detalle'    => 'Hace ' . $dias . ' días que no te compra',
            ];
        }

        return $candidatos;
    }

    /** @return int Clientes que quedaron afuera por deuda en la última corrida de candidatos() */
    public function clientes_excluidos_por_deuda(): int
    {
        return $this->clientes_excluidos_por_deuda;
    }

    /** @return array Mapa client_id => true|false|null, para offer_suggestion_lines.es_buen_pagador */
    public function evaluacion_crediticia(): array
    {
        return $this->evaluacion_crediticia;
    }

    /**
     * 🔴 A.5.0 — El filtro de ventas reales, copiado LITERAL de
     * CoberturaService::velocidades_globales() (app/Services/StockSuggestion/CoberturaService.php:194-206).
     * Es estático y público porque PorcentajeSugeridoService lo necesita igual: vive en UN solo
     * método, y no copiado en las cinco consultas que lo llevan, para que no envejezca por su lado
     * en ninguna. 🔴 El scope por comercio lo da articles.user_id: article_purchases NO tiene user_id.
     *
     * @param  \Illuminate\Database\Query\Builder $q
     * @param  int $user_id
     * @return void
     */
    public static function filtro_de_ventas_reales($q, $user_id)
    {
        $q->join('articles', 'article_purchases.article_id', '=', 'articles.id')
            ->join('sales', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('articles.user_id', $user_id)
            ->whereNull('sales.deleted_at')
            // Condición de Sale::scopeSoloVentasReales, con prefijo de tabla porque el FROM
            // es article_purchases (las consolidaciones de facturación duplicarían las ventas
            // que agrupan).
            ->where(function ($q2) {
                $q2->whereNull('sales.is_consolidacion_facturacion')
                    ->orWhere('sales.is_consolidacion_facturacion', 0);
            });
    }

    /**
     * Última compra real de cada par (cliente, artículo) que trajo el tracking.
     *
     * @param  \Illuminate\Support\Collection $filas
     * @return array Mapa "client_id-article_id" => fecha
     */
    protected function ultima_compra_por_par($filas)
    {
        $q = DB::table('article_purchases')->selectRaw(
            'article_purchases.client_id as client_id, article_purchases.article_id as article_id,
             MAX(article_purchases.created_at) as ultima'
        );
        self::filtro_de_ventas_reales($q, $this->user_id);
        $q->whereIn('article_purchases.client_id', array_unique($filas->pluck('client_id')->all()))
            ->whereIn('article_purchases.article_id', array_unique($filas->pluck('article_id')->all()))
            ->groupBy('article_purchases.client_id', 'article_purchases.article_id');

        $mapa = [];

        foreach ($q->get() as $fila) {
            $mapa[(int) $fila->client_id . '-' . (int) $fila->article_id] = $fila->ultima;
        }

        return $mapa;
    }

    /**
     * @param  array $candidatos
     * @return array Mapa client_id => ['article_id' => int, 'compras' => int], el de más compras
     */
    protected function mejor_articulo_por_cliente(array $candidatos)
    {
        $mejores = [];

        foreach ($candidatos as $c) {
            if (!isset($mejores[$c['client_id']]) || $c['compras'] > $mejores[$c['client_id']]['compras']) {
                $mejores[$c['client_id']] = ['article_id' => $c['article_id'], 'compras' => $c['compras']];
            }
        }

        return $mejores;
    }

    /**
     * Un par (cliente, artículo) puede venir por los tres criterios a la vez: se queda el de mayor
     * score, que es el criterio más fuerte para ese par.
     *
     * @param  array $candidatos
     * @return array
     */
    protected function dedupe(array $candidatos)
    {
        $unicos = [];

        foreach ($candidatos as $c) {
            $clave = $c['client_id'] . '-' . $c['article_id'];

            if (!isset($unicos[$clave]) || $c['score'] > $unicos[$clave]['score']) {
                $unicos[$clave] = $c;
            }
        }

        return array_values($unicos);
    }

    /**
     * 🔴 El filtro de crédito: los candidatos de un mal pagador se descartan ENTEROS. Ver el
     * docblock de la clase — es una DECISIÓN DE NEGOCIO de Lucas del 15/8/2026, no una optimización,
     * y no hay que convertirla de vuelta en un factor sobre el techo.
     *
     * @param  array $candidatos
     * @return array
     */
    protected function excluir_malos_pagadores(array $candidatos)
    {
        $this->clientes_excluidos_por_deuda = 0;
        $this->evaluacion_crediticia        = [];

        if (empty($candidatos)) {
            return $candidatos;
        }

        $servicio = new HistorialCrediticioService($this->user);

        $this->evaluacion_crediticia = $servicio->evaluar(array_column($candidatos, 'client_id'));
        $sobrevivientes              = [];

        foreach ($candidatos as $c) {
            // null (sin datos de cuenta corriente) NO excluye: no saber no es deber.
            if ($this->evaluacion_crediticia[$c['client_id']] === false) {
                continue;
            }

            $sobrevivientes[] = $c;
        }

        foreach ($this->evaluacion_crediticia as $es_buen_pagador) {
            $this->clientes_excluidos_por_deuda += ($es_buen_pagador === false) ? 1 : 0;
        }

        return $sobrevivientes;
    }

    /**
     * A.5.6 — Tope de líneas por cliente. Un cliente con 40 ofertas no es una campaña, es spam: no
     * la mira nadie y encima quema el canal.
     *
     * @param  array $candidatos
     * @return array
     */
    protected function tope_por_cliente(array $candidatos)
    {
        $tope = (int) $this->suggestion->max_ofertas_por_cliente;

        if ($tope <= 0) {
            return $candidatos;
        }

        usort($candidatos, function ($a, $b) {
            // Desempate estable por artículo: sin él, dos corridas idénticas podrían devolver
            // conjuntos distintos y nadie entendería por qué.
            if ($a['score'] == $b['score']) {
                return $a['article_id'] - $b['article_id'];
            }

            return $a['score'] < $b['score'] ? 1 : -1;
        });

        $por_cliente = [];
        $elegidos    = [];

        foreach ($candidatos as $c) {
            $usados = isset($por_cliente[$c['client_id']]) ? $por_cliente[$c['client_id']] : 0;

            if ($usados >= $tope) {
                continue;
            }

            $por_cliente[$c['client_id']] = $usados + 1;
            $elegidos[]                   = $c;
        }

        return $elegidos;
    }

    /**
     * @param  mixed $fecha
     * @return int Días enteros desde esa fecha hasta hoy (nunca negativo)
     */
    protected function dias_desde($fecha)
    {
        return is_null($fecha)
            ? 0
            : (int) Carbon::parse($fecha)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }
}
