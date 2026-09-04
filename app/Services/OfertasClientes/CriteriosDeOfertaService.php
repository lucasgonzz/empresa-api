<?php

namespace App\Services\OfertasClientes;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A quién ofrecerle qué: los cuatro criterios de marketing del motor de ofertas (§A.5) y el filtro de
 * crédito. Devuelve candidatos `['client_id','article_id','criterio','score','detalle','compras']`;
 * el techo y el porcentaje son de otros servicios y este no los mira.
 *
 * Los cuatro criterios, y las CUATRO señales del pedido de Lucas ("qué productos vio y cuánto
 * tiempo, qué buscó, qué agregó al carrito, qué compró"):
 *   - afinidad (A.5.1)              -> qué compró        (article_purchases)
 *   - reactivación (A.5.3)          -> qué compró        (article_purchases)
 *   - carrito abandonado (A.5.2)    -> qué agregó al carrito (buyer_tracking_events, cart_add)
 *   - interés en el ecommerce       -> qué vio y cuánto tiempo, y qué buscó
 *                                      (buyer_tracking_events, product_view + dwell_ms y search)
 *
 * 🔴 LAS DOS TABLAS DE TRACKING (buyer_tracking_events, buyer_tracking_daily) ESTÁN HOY EN CERO
 * FILAS. Medido el 15/8/2026 sobre empresa_testing_s2: se crearon con la misión 2 y las escribe la
 * tienda, que todavía no está desplegada. Por eso el motor tiene que dar sugerencias útiles SIN UNA
 * SOLA FILA DE TRACKING: afinidad (A.5.1) y reactivación (A.5.3) salen de article_purchases, que sí
 * tiene historia, y son los que funcionan desde el día uno. Carrito abandonado (A.5.2) e interés en
 * el ecommerce degradan a conjunto vacío y no lanzan. Si una corrida vuelve vacía, el problema NO es
 * el tracking.
 *
 * 🔴 EL MAL PAGADOR SE EXCLUYE ENTERO, ANTES DE CALCULAR NADA. Es una DECISIÓN DE NEGOCIO de Lucas
 * del 15/8/2026 —"al que debe hace mucho no se le ofrece nada"—, no una optimización. El plan
 * original le bajaba el techo a la mitad con un FACTOR_MAL_PAGADOR = 0.5; eso quedó sin efecto, y
 * convertirlo de vuelta en un factor es deshacer la decisión. El conteo de los que quedaron afuera
 * se devuelve para que el llamador lo guarde en offer_suggestions.total_clientes_excluidos_por_deuda
 * y se lo muestre al comerciante: es lo que le prueba que el sistema tuvo criterio.
 *
 * 🔴 A.5.0 — LOS CUATRO CRITERIOS EXIGEN BUYER VINCULADO, no solo los dos que lo necesitan para el
 * join de tracking. La query que corre la tienda (textual en
 * database/migrations/2026_08_17_100200_create_client_offers_table.php:16-26) filtra por
 * `co.client_id = buyers.comercio_city_client_id del buyer logueado`: una oferta de un cliente sin
 * buyer no la ve nadie, nunca. carrito_abandonado() e interes_en_el_ecommerce() ya lo exigían porque
 * su fuente de datos (buyer_tracking_events) sale de un JOIN con buyers; afinidad() lo agrega
 * explícito (:157 y siguientes, subconsulta contra buyers) y reactivacion() lo HEREDA de afinidad()
 * sin repetirlo — ver el docblock de cada método para el motivo puntual.
 *
 * No escribe nada en la base. PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class CriteriosDeOfertaService
{
    const CRITERIO_AFINIDAD = 'afinidad';
    const CRITERIO_CARRITO_ABANDONADO = 'carrito_abandonado';
    const CRITERIO_REACTIVACION = 'reactivacion';
    const CRITERIO_INTERES_ECOMMERCE = 'interes_ecommerce';

    /** Compras mínimas del par (cliente, artículo) para que la afinidad cuente. */
    const MIN_COMPRAS_AFINIDAD = 1;

    /** Un carrito abandonado es la señal más caliente que hay: score fijo y el más alto. */
    const SCORE_CARRITO_ABANDONADO = 3.0;

    /** Piso del score de reactivación, al que se le suma dias_dormido / 365. */
    const SCORE_BASE_REACTIVACION = 2.0;

    /** Cuánto se amplía la ventana de afinidad, solo para un dormido sin historia reciente. */
    const MULTIPLICADOR_VENTANA_DORMIDO = 4;

    /*
     * 🔴 LA ESCALA DE SCORES DEL CRITERIO NUEVO, Y POR QUÉ VIVE ENTERA POR DEBAJO DE 1.0.
     * Los otros tres criterios ya fijan la escala: carrito abandonado 3.0, reactivación 2.0 + los
     * días dormido, y afinidad = CUÁNTAS VECES lo compró, o sea >= 1.0 siempre. Mirar no es comprar
     * y buscar tampoco: una vista tiene que valer MENOS que un carrito abandonado y MENOS que una
     * compra previa, así que el techo de todo este criterio (0.40 + 0.20 + 0.20 = 0.80, y 0.80 para
     * una búsqueda) queda por debajo del 1.0 de UNA sola compra. Si alguien sube estos números por
     * encima de 1, el dedupe por mayor score empieza a tapar afinidad con vistas y el tope por
     * cliente (A.5.6) se llena de gente que solo miró.
     */
    const SCORE_BASE_VISTA = 0.40;
    const SCORE_BUSQUEDA = 0.80;
    const BONUS_MAXIMO_POR_VISITAS = 0.20;
    const BONUS_MAXIMO_POR_DWELL = 0.20;

    /** Visitas al mismo artículo a partir de las cuales el bonus por repetición es máximo. */
    const VISITAS_PARA_BONUS_MAXIMO = 5;

    /** Permanencia acumulada (2 minutos) a partir de la cual el bonus por tiempo es máximo. */
    const DWELL_MS_PARA_BONUS_MAXIMO = 120000;

    /**
     * La ventana del interés es el doble que la del carrito abandonado. Mirar y buscar son señales
     * más débiles pero también mucho más frecuentes que un cart_add, y 7 días dejarían afuera al que
     * recorrió la tienda el fin de semana anterior. NO se agrega un parámetro nuevo a la cabecera:
     * multiplicar la configuración por una señal que hoy da vacío es pedirle al comerciante que
     * ajuste algo que todavía no puede ver.
     */
    const MULTIPLICADOR_VENTANA_INTERES = 2;

    /** Largo mínimo de un término buscado para tomarlo en serio: con 1 o 2 letras matchea medio catálogo. */
    const MIN_LARGO_TERMINO_BUSCADO = 3;

    /** Techo de términos distintos que se resuelven contra el catálogo en una corrida. */
    const MAX_TERMINOS_BUSCADOS = 30;

    /** Techo de artículos que puede traer UN término: un LIKE amplio no puede inundar la corrida. */
    const MAX_ARTICULOS_POR_TERMINO = 5;

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
     * El pipeline completo: los cuatro criterios, dedupe, filtro de crédito y tope por cliente.
     *
     * @return array Lista de ['client_id','article_id','criterio','score','detalle','compras']
     */
    public function candidatos(): array
    {
        $candidatos = $this->dedupe(array_merge(
            $this->afinidad(),
            $this->carrito_abandonado(),
            $this->reactivacion(),
            $this->interes_en_el_ecommerce()
        ));

        return $this->tope_por_cliente($this->excluir_malos_pagadores($candidatos));
    }

    /**
     * A.5.1 — Afinidad de compra. EL NÚCLEO, y funciona sin tracking: article_purchases tiene
     * client_id, o sea que da directo "qué le vendí a quién y cuándo".
     *
     * 🔴 EL FILTRO DE BUYER VIVE ACÁ Y NO EN candidatos(). Es el único de los cuatro criterios que
     * no lo traía (carrito_abandonado() e interes_en_el_ecommerce() lo heredan del JOIN con buyers
     * que necesitan igual para leer el tracking), y sin él el motor generaba sugerencias para
     * clientes que la tienda nunca le muestra a nadie (ver el docblock de la clase). Filtrarlo acá,
     * en la query, y no después del array_merge en candidatos(), evita traer a PHP filas que la base
     * puede descartar sola —medido: 228 pares candidatos de los cuales 73 se tiran— y mantiene a
     * afinidad() y reactivacion() diciendo la verdad sobre lo que generan.
     *
     * reactivacion() NO repite este filtro: hereda el que hay acá porque arma sus candidatos
     * exclusivamente a partir de $mejores, que sale de llamar a este método (ver su propio
     * docblock). Agregarlo también ahí sería la misma condición escrita dos veces.
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

        /*
         * 🔴 whereIn con subconsulta, NO un JOIN buyers: un cliente puede tener más de un Buyer
         * (vistas_con_tiempo(), :407-411) y con GROUP BY client_id, article_id + COUNT(*) un JOIN
         * duplicaría filas e inflaría el score. Tampoco whereExists: buyers.comercio_city_client_id
         * no tiene índice (medido 26/8/2026: SHOW INDEX FROM buyers solo devuelve PRIMARY), y un
         * EXISTS correlacionado escanearía buyers una vez por fila candidata; el IN con subconsulta
         * MySQL 8 lo materializa una sola vez. Y sí se filtra por buyers.user_id: el buyer que ve la
         * oferta es un buyer DE ESTE COMERCIO, el mismo criterio que ya usa
         * OfertaComunicacionHelper::buyer_del_cliente() (:237-244).
         */
        $user_id = $this->user_id;

        $q->whereIn('article_purchases.client_id', function ($sub) use ($user_id) {
            $sub->select('buyers.comercio_city_client_id')
                ->from('buyers')
                ->whereNotNull('buyers.comercio_city_client_id')
                ->where('buyers.user_id', $user_id);
        });

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
     * Interés en el ecommerce: LO QUE VIO Y CUÁNTO TIEMPO, Y LO QUE BUSCÓ.
     *
     * Es la mitad del pedido de Lucas que faltaba. El motor consumía dos de las cuatro señales
     * (`cart_add` y `article_purchases`) y nadie leía `product_view`, `dwell_ms`, `search` ni
     * `search_term`, que la misión 2 puebla y estaban ahí sin usar. Un cliente que entró tres veces
     * a la misma ficha y se quedó dos minutos mirándola, o que buscó el artículo por su nombre, ya
     * dijo que lo quiere: es intención de compra, más débil que un carrito pero de la misma familia.
     *
     * 🔴 HOY DEVUELVE [] Y ESO ESTÁ BIEN: buyer_tracking_events está en cero filas hasta que la
     * tienda se despliegue (ver el docblock de la clase). No lanza, no vacía la corrida y no cambia
     * en nada lo que devuelven afinidad y reactivación, que son las que funcionan desde el día uno.
     *
     * @return array
     */
    public function interes_en_el_ecommerce(): array
    {
        $desde = Carbon::now()->subDays(
            (int) $this->suggestion->dias_carrito_abandonado * self::MULTIPLICADOR_VENTANA_INTERES
        );

        $candidatos = array_merge($this->vistas_con_tiempo($desde), $this->busquedas($desde));

        if (empty($candidatos)) {
            return [];
        }

        return $this->descartar_los_ya_comprados($candidatos);
    }

    /**
     * A.5.3 — Reactivación por inactividad. Funciona sin tracking. El artículo sale del propio
     * historial del cliente dormido: ofrecerle algo que nunca compró no es reactivar, es adivinar.
     *
     * 🔴 EL FILTRO DE BUYER SE HEREDA DE afinidad(), NO SE AGREGA ACÁ. La query de `dormidos`
     * (debajo) queda intacta a propósito: sigue trayendo a todos, con o sin buyer. Lo que decide
     * quién termina en el resultado es $mejores, que sale ÍNTEGRO de llamar a afinidad($ids) y
     * afinidad($faltantes, $ventana) — los dos únicos lugares que lo llenan. Como afinidad() ya
     * filtra por buyer, un dormido sin buyer no tiene entrada en $mejores por ninguno de los dos
     * pases, y el `continue` de más abajo lo descarta solo. Agregar el filtro también sobre
     * `dormidos` sería la misma condición escrita dos veces, y el día que una de las dos cambie el
     * motor contestaría distinto según por dónde entre.
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
     * "Qué productos vio y cuánto tiempo": product_view agrupado por (cliente, artículo), contando
     * visitas y sumando dwell_ms.
     *
     * 🔴 Se agrupa por comercio_city_client_id y NO por buyer_id, igual que carrito_abandonado(): un
     * cliente del ERP puede tener más de un Buyer (se registró dos veces, o la familia comparte la
     * cuenta) y el candidato es del CLIENTE, no del comprador. Agrupando por buyer saldrían dos
     * filas del mismo par que el dedupe colapsaría igual, pero quedándose con el score de una sola
     * de las dos sesiones en vez de con el interés sumado.
     *
     * 🔴 La ventana va por occurred_at, NUNCA por created_at (docblock de la migración
     * 2026_08_15_140000:148-151): created_at es cuándo la tienda mandó el lote, occurred_at es
     * cuándo el comprador miró. Además es la columna del índice (user_id, occurred_at).
     *
     * @param  \Carbon\Carbon $desde
     * @return array
     */
    protected function vistas_con_tiempo($desde)
    {
        $filas = DB::table('buyer_tracking_events as e')
            ->join('buyers as b', 'b.id', '=', 'e.buyer_id')
            ->where('e.user_id', $this->user_id)
            ->where('e.event_type', 'product_view')
            ->where('e.occurred_at', '>=', $desde)
            ->whereNotNull('e.article_id')
            ->whereNotNull('b.comercio_city_client_id')
            ->groupBy('b.comercio_city_client_id', 'e.article_id')
            ->selectRaw(
                'b.comercio_city_client_id as client_id, e.article_id as article_id,
                 COUNT(*) as visitas, SUM(e.dwell_ms) as dwell_ms_total, MAX(e.occurred_at) as ultimo'
            )
            ->get();

        $candidatos = [];

        foreach ($filas as $fila) {
            $visitas = (int) $fila->visitas;
            // dwell_ms es nullable y SUM() de puros nulls devuelve NULL: sin el cast el bonus por
            // tiempo se calcularía sobre null y una tienda que todavía no manda el reloj daría
            // scores distintos a los de una que lo manda en cero.
            $dwell = (int) $fila->dwell_ms_total;

            $candidatos[] = [
                'client_id'  => (int) $fila->client_id,
                'article_id' => (int) $fila->article_id,
                'criterio'   => self::CRITERIO_INTERES_ECOMMERCE,
                // Volver varias veces y quedarse un rato son dos señales distintas del mismo
                // interés, así que suman por separado y cada una tiene su propio techo.
                'score'      => self::SCORE_BASE_VISTA
                    + min(1, $visitas / self::VISITAS_PARA_BONUS_MAXIMO) * self::BONUS_MAXIMO_POR_VISITAS
                    + min(1, $dwell / self::DWELL_MS_PARA_BONUS_MAXIMO) * self::BONUS_MAXIMO_POR_DWELL,
                'compras'    => 0,
                'ultimo'     => $fila->ultimo,
                'detalle'    => $this->detalle_de_vista($visitas, $dwell),
            ];
        }

        return $candidatos;
    }

    /**
     * "Qué buscó": los términos que el cliente escribió en el buscador de la tienda, resueltos
     * contra el catálogo.
     *
     * 🔴 buyer_tracking_events NO ATA LA BÚSQUEDA A UN article_id — mirá el esquema: guarda
     * search_term y results_count, y nada más (2026_08_15_140000:116-125). El único puente hacia el
     * catálogo es el texto, así que el match se hace POR TEXTO contra articles, y eso obliga a
     * acotarlo por los dos lados:
     *   - por término: MAX_ARTICULOS_POR_TERMINO, para que un "cable" no meta 400 líneas de una;
     *   - por corrida: MAX_TERMINOS_BUSCADOS términos DISTINTOS resueltos, memoizados, porque
     *     muchos clientes buscan lo mismo y el catálogo no cambia entre uno y otro.
     * El costo queda en un scan acotado al catálogo del comercio, a lo sumo 30 veces por corrida y
     * una sola vez por término. 🔴 Lo que NO se hace es el producto cartesiano evidente (cruzar cada
     * fila de búsqueda contra articles en un solo JOIN con LIKE), que con 5.000 búsquedas y 50.000
     * artículos son 250 millones de comparaciones para el mismo resultado.
     *
     * @param  \Carbon\Carbon $desde
     * @return array
     */
    protected function busquedas($desde)
    {
        $filas = DB::table('buyer_tracking_events as e')
            ->join('buyers as b', 'b.id', '=', 'e.buyer_id')
            ->where('e.user_id', $this->user_id)
            ->where('e.event_type', 'search')
            ->where('e.occurred_at', '>=', $desde)
            ->whereNotNull('e.search_term')
            ->where('e.search_term', '!=', '')
            ->whereNotNull('b.comercio_city_client_id')
            ->groupBy('b.comercio_city_client_id', 'e.search_term')
            ->selectRaw('b.comercio_city_client_id as client_id, e.search_term as termino, MAX(e.occurred_at) as ultimo')
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $por_termino = [];
        $resueltos   = 0;
        $candidatos  = [];

        foreach ($filas as $fila) {
            $termino = trim((string) $fila->termino);

            // Con 1 o 2 letras el LIKE matchea medio catálogo y el "interés" sería ruido.
            if (mb_strlen($termino) < self::MIN_LARGO_TERMINO_BUSCADO) {
                continue;
            }

            if (!array_key_exists($termino, $por_termino)) {
                if ($resueltos >= self::MAX_TERMINOS_BUSCADOS) {
                    continue;
                }

                $por_termino[$termino] = $this->articulos_que_matchean($termino);
                $resueltos++;
            }

            foreach ($por_termino[$termino] as $article_id) {
                $candidatos[] = [
                    'client_id'  => (int) $fila->client_id,
                    'article_id' => $article_id,
                    'criterio'   => self::CRITERIO_INTERES_ECOMMERCE,
                    // Fijo: buscar algo por su nombre es una intención declarada, no una escala.
                    'score'      => self::SCORE_BUSQUEDA,
                    'compras'    => 0,
                    'ultimo'     => $fila->ultimo,
                    'detalle'    => 'Buscó "' . $termino . '" en la tienda y todavía no lo compró',
                ];
            }
        }

        return $candidatos;
    }

    /**
     * Los artículos del comercio que matchean un término buscado, acotados y en orden estable.
     *
     * @param  string $termino
     * @return array Lista de article_id
     */
    protected function articulos_que_matchean($termino)
    {
        /*
         * 🔴 Los comodines del LIKE se escapan: un término real como "50%" o "cable_2" los trae
         * adentro, y sin escaparlos "50%" matchea todo lo que empieza con 50 y "cable_2" matchea
         * "cableX2". La barra invertida va también porque es el carácter de escape del propio LIKE.
         */
        $escapado = addcslashes($termino, '%_\\');

        $ids = DB::table('articles')
            ->where('user_id', $this->user_id)
            ->where('status', '!=', 'inactive')
            ->where(function ($q) use ($escapado, $termino) {
                // El nombre por contenido (es como busca la gente: "hdmi" contra "Cable HDMI 2m") y
                // los dos códigos por igualdad exacta, que es la única forma en que alguien pega un
                // código en el buscador.
                $q->where('name', 'like', '%' . $escapado . '%')
                    ->orWhere('bar_code', $termino)
                    ->orWhere('provider_code', $termino);
            })
            // Orden explícito para que el recorte de MAX_ARTICULOS_POR_TERMINO sea el MISMO en dos
            // corridas iguales: sin ORDER BY, el LIMIT se queda con lo que el motor devuelva primero.
            ->orderBy('id')
            ->limit(self::MAX_ARTICULOS_POR_TERMINO)
            ->pluck('id');

        $limpios = [];

        foreach ($ids as $id) {
            $limpios[] = (int) $id;
        }

        return $limpios;
    }

    /**
     * 🔴 El mismo descarte que hace carrito_abandonado(): si el par ya tiene una compra REAL
     * posterior a la señal, no hay nada que ofrecerle — ya lo compró. Va en PHP y contra
     * article_purchases por el mismo motivo que allá: checkout_complete no garantiza traer
     * article_id, así que un NOT EXISTS contra la propia tabla de eventos dejaría pasar lo comprado.
     *
     * De paso saca la clave 'ultimo', que es de uso interno de este criterio y no forma parte del
     * contrato de un candidato.
     *
     * @param  array $candidatos
     * @return array
     */
    protected function descartar_los_ya_comprados(array $candidatos)
    {
        $ya_comprados = $this->ultima_compra_por_par(collect($candidatos));
        $sobreviven   = [];

        foreach ($candidatos as $candidato) {
            $clave = $candidato['client_id'] . '-' . $candidato['article_id'];

            if (isset($ya_comprados[$clave]) && $ya_comprados[$clave] >= $candidato['ultimo']) {
                continue;
            }

            unset($candidato['ultimo']);
            $sobreviven[] = $candidato;
        }

        return $sobreviven;
    }

    /**
     * La frase en criollo de una vista, con el tiempo en la unidad que se entiende. La escribe el
     * código y no la IA, igual que el detalle de los otros tres criterios.
     *
     * @param  int $visitas
     * @param  int $dwell_ms
     * @return string
     */
    protected function detalle_de_vista($visitas, $dwell_ms)
    {
        $texto = $visitas > 1
            ? 'Miró este artículo ' . $visitas . ' veces en la tienda'
            : 'Miró este artículo en la tienda';

        $segundos = (int) round($dwell_ms / 1000);

        if ($segundos >= 60) {
            $texto .= ' y le dedicó ' . (int) round($segundos / 60) . ' minutos';
        } elseif ($segundos > 0) {
            $texto .= ' y le dedicó ' . $segundos . ' segundos';
        }

        return $texto . ', y todavía no lo compró';
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
