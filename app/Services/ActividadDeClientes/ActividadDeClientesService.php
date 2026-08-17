<?php

namespace App\Services\ActividadDeClientes;

use App\Console\Commands\PurgarBuyerTracking;
use App\Models\Article;
use App\Services\OfertasClientes\CriteriosDeOfertaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Qué hizo un cliente en la tienda online: lo que miró y cuánto tiempo, lo que buscó (y si no
 * encontró nada), lo que puso en el carrito y cuántas compras cerró. Es LECTURA PURA: no escribe
 * una sola fila, no llama a la IA y no lee `Auth` — el `owner_id` llega por constructor porque las
 * tools del chat corren adentro de un job sin sesión.
 *
 * -------------------------------------------------------------------------------------------
 * 1. LA REGLA DE CORTE POR ANTIGÜEDAD, QUE ES LA DECISIÓN QUE DEFINE ESTA CLASE
 * -------------------------------------------------------------------------------------------
 *
 *     periodo ∈ {7, 30, 90}  ->  buyer_tracking_events  (fuente 'eventos')
 *     periodo = 0            ->  buyer_tracking_daily   (fuente 'agregado')
 *
 * Los cinco porqués, para que nadie la "simplifique" a leer siempre de un lado:
 *
 *   1. Pedirle a `buyer_tracking_events` una ventana MAYOR a la retención devuelve una mentira
 *      tranquilizadora. La purga borra todo lo anterior a PurgarBuyerTracking::DIAS_RETENCION, así
 *      que un "total de siempre" leído de ahí es la cola de los últimos 90 días con cara de total.
 *      Es exactamente la clase de error que el propio comando de purga documenta en su docblock
 *      (líneas 30-36): la medición que falla devolviendo un valor que tranquiliza.
 *   2. El agregado NO tiene con qué contestar una ventana corta con detalle. `buyer_tracking_daily`
 *      agrupa por (user_id, fecha, buyer_id, article_id, event_type, search_term): no guarda la hora
 *      del evento, ni `visitor_id`, ni `session_id`, ni `dwell_ms` por evento. Con él NO HAY LÍNEA
 *      DE TIEMPO POSIBLE. Por eso los periodos cortos —los que el comerciante realmente mira— van a
 *      los crudos.
 *   3. El agregado llega hasta AYER. `tracking:agregar-buyers` corre a las 03:30 sobre el día
 *      anterior (app/Console/Kernel.php:109-111) y sin `--fecha` procesa ayer
 *      (AgregarBuyerTracking::resolver_fecha():191-193). Pedirle los últimos 7 días al agregado se
 *      comería justo lo de hoy, que es lo primero que el comerciante va a buscar. Por eso el `hasta`
 *      del histórico es ayer, y la pantalla lo dice.
 *   4. El filtro va por RANGO de `occurred_at`, NUNCA por `DATE(occurred_at)`. Una función sobre la
 *      columna anula el índice y convierte la consulta en un full scan de la tabla más grande del
 *      sistema. Está escrito en AgregarBuyerTracking::agregar_dia() (líneas 233-235) y vale igual
 *      acá.
 *   5. Siempre `occurred_at`, nunca `created_at`. `created_at` es cuándo la tienda mandó el lote a
 *      la API; `occurred_at` es cuándo el comprador lo hizo en el navegador (docblock de la
 *      migración 2026_08_15_140000:148-151). Además `occurred_at` es la columna de los tres índices
 *      que sirven acá.
 *
 * -------------------------------------------------------------------------------------------
 * 2. 🔴 LA MEDICIÓN QUE DEFINE EL DISEÑO DE LAS CONSULTAS: EL `user_id` LAS ARRUINA
 * -------------------------------------------------------------------------------------------
 *
 *   EXPLAIN SELECT e.event_type, COUNT(*) FROM buyer_tracking_events e
 *   WHERE e.buyer_id IN (1,2) AND e.occurred_at >= '...' GROUP BY e.event_type;
 *     -> key: bte_buyer_ocurrido_index          ✅
 *
 *   EXPLAIN SELECT e.event_type, COUNT(*) FROM buyer_tracking_events e
 *   WHERE e.user_id = 500 AND e.buyer_id IN (1,2) AND e.occurred_at >= '...' GROUP BY e.event_type;
 *     -> possible_keys: bte_user_ocurrido_index, bte_buyer_ocurrido_index
 *        key: bte_user_ocurrido_index           ❌
 *
 * Agregarle `user_id` a la consulta LE DA VUELTA LA ELECCIÓN AL OPTIMIZADOR, y en este sistema eso
 * es grave y no cosmético: cada cliente tiene su propia base, así que `user_id` toma casi siempre un
 * único valor (está escrito textual en la migración 2026_08_15_140000:158-165). Entrar por
 * (user_id, occurred_at) para una consulta de UN cliente es barrer por fecha TODOS los eventos del
 * comercio y descartar el 99% después. Lo mismo pasa en el agregado: con `user_id` elige
 * `btd_user_fecha_index` en vez de `btd_buyer_fecha_index`.
 *
 * 🔴 POR ESO LAS CONSULTAS POR COMPRADOR NO LLEVAN `user_id`, Y LA TENENCIA LA DA `buyers`. Y es un
 * filtro MÁS estricto, no menos: los `buyer_id` se resuelven con `WHERE buyers.user_id = :owner`,
 * así que un evento con esos `buyer_id` es de un comprador de este comercio por construcción.
 * Filtrar además por `buyer_tracking_events.user_id` no agregaría seguridad —esa columna la escribe
 * la tienda desde su propio `VUE_APP_COMMERCE_ID`, que es otra variable de entorno en otro repo
 * (docblock de PurgarBuyerTracking:28-36)— y sí destruiría el plan de ejecución.
 *
 * Índice que usa cada consulta, con el EXPLAIN corrido contra empresa_testing_s2:
 *   C1 totales/artículos por comprador (eventos)   -> bte_buyer_ocurrido_index      (range)
 *   C2 búsquedas por comprador (eventos)           -> bte_buyer_ocurrido_index      (range)
 *   C3 línea de tiempo por comprador               -> bte_buyer_ocurrido_index      (range + filesort)
 *   C4/C5 totales y búsquedas del agregado         -> btd_buyer_fecha_index         (range)
 *   C6 interesados en un artículo                  -> bte_articulo_ocurrido_index en `e`,
 *                                                     PRIMARY en `b` (eq_ref)
 *   C7 anónimos sobre un artículo                  -> bte_articulo_ocurrido_index   (range)
 *
 * 🔴 En C7 los anónimos se cuentan con un `CASE WHEN` y NO con `WHERE buyer_id IS NULL`. Medido con
 * EXPLAIN: con ese predicado el optimizador elige `bte_buyer_ocurrido_index` —el peor índice
 * posible, porque en una tienda el grueso del tráfico es anónimo y ese predicado matchea casi toda
 * la tabla—; sin el predicado elige `bte_articulo_ocurrido_index`, que es el que corresponde. No lo
 * "simplifiques" de vuelta a un WHERE.
 *
 * Nota medida sobre `buyers`: la tabla NO tiene ningún índice secundario (SHOW INDEX FROM buyers
 * devuelve sólo PRIMARY), así que buyer_ids_de_un_cliente() es un full scan de `buyers`. Se acepta:
 * `buyers` es una fila por comprador registrado, no una por vista de página, y el motor de ofertas
 * ya la recorre entera en cada corrida. NO se agrega un índice acá; si alguna vez aparece como
 * problema, es una medición y una decisión aparte.
 *
 * -------------------------------------------------------------------------------------------
 * 3. SE SUMA POR CLIENTE, NO POR COMPRADOR
 * -------------------------------------------------------------------------------------------
 *
 * Un cliente del ERP puede tener MÁS DE UN `Buyer` (se registró dos veces, o la familia comparte la
 * cuenta), así que toda la actividad viene SUMADA entre ellos. Es el mismo criterio de
 * CriteriosDeOfertaService::vistas_con_tiempo() (líneas 408-412) y carrito_abandonado() (203-212),
 * reusado y no reinventado. La diferencia con el motor de ofertas es sólo la forma de llegar: allá
 * se hace con un JOIN buyers porque la consulta barre todo el comercio; acá se resuelven primero los
 * `buyer_id` porque la consulta es de UN cliente, y eso además es lo que fija el índice (punto 2).
 *
 * 🔴 El vínculo `buyers.comercio_city_client_id` es OPCIONAL Y MANUAL (se setea en
 * BuyerController.php:50 y en ProcessArchivoDeIntercambioClientes.php:113). Hay buyers sin cliente
 * asociado y ése es el caso NORMAL, no el borde. Consecuencia visible y a propósito: un buyer sin
 * cliente NO aparece entrando por `client_id` (no hay client_id que lo alcance) y SÍ aparece
 * entrando por `buyer_id`. Es la diferencia entre las dos puertas de la pantalla.
 *
 * -------------------------------------------------------------------------------------------
 * 4. 🔴 POR QUÉ NO HAY CLAVE `comprado` POR ARTÍCULO
 * -------------------------------------------------------------------------------------------
 *
 * Porque `checkout_complete` NO garantiza traer `article_id` (BuyerTrackingEvent::TIPOS lo permite
 * null; está explicado en CriteriosDeOfertaService:224-229), así que "lo compró" NO SE PUEDE
 * CONTESTAR DESDE EL TRACKING. La verdad de la compra es `article_purchases`, y cruzarla acá por
 * artículo sería reimplementar `ultima_compra_por_par()` con otro criterio, que es cómo se llega a
 * que dos pantallas del mismo sistema contesten distinto la misma pregunta. Esta clase informa lo
 * que el tracking sabe: lo vio, lo buscó, lo puso en el carrito, y cuántas compras cerró en total.
 * El único lugar donde sí se cruza `article_purchases` es interesados_en_un_articulo(), y ahí se
 * LLAMA a CriteriosDeOfertaService::filtro_de_ventas_reales() —que es `public static` justamente
 * para eso—, no se copia.
 *
 * -------------------------------------------------------------------------------------------
 * 5. 🔴 LAS DOS TABLAS ESTÁN HOY EN CERO FILAS EN TODOS LOS ENTORNOS
 * -------------------------------------------------------------------------------------------
 *
 * Medido sobre empresa_testing_s2: `buyer_tracking_events` 0 filas y `buyer_tracking_daily` 0 filas,
 * porque las escribe la tienda y tienda todavía no se desplegó. Los EXPLAIN de arriba fijan la
 * ELECCIÓN DE ÍNDICE, que es lo válido sobre tablas vacías; la verificación con datos se hace con el
 * sembrador `tracking:sembrar-actividad-de-prueba`. Verificar esta clase contra las tablas en cero
 * es mirar una pantalla vacía y declararla andando.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class ActividadDeClientesService
{
    /** Los eventos crudos: tienen hora, dwell por evento y línea de tiempo. */
    const FUENTE_EVENTOS = 'eventos';

    /** El agregado diario: sobrevive a la purga, pero agrupa por día y no tiene hora. */
    const FUENTE_AGREGADO = 'agregado';

    /** "Todo el historial": es el único periodo que se contesta con el agregado. */
    const PERIODO_HISTORICO = 0;

    /** Lista blanca de periodos. Cualquier otro valor no es un periodo de esta pantalla. */
    const PERIODOS_VALIDOS = [0, 7, 30, 90];

    /*
     * 🔴 EL CORTE NO ES UN 90 ESCRITO ACÁ: apunta a la constante de la purga, que es quien decide de
     * verdad hasta dónde llegan los eventos crudos. Si alguien baja la retención a 30, un pedido de
     * 90 días degrada solo al agregado en vez de seguir contestando con la cola de lo que quedó y
     * cara de total.
     */
    const PERIODO_MAXIMO_CON_DETALLE = PurgarBuyerTracking::DIAS_RETENCION;

    /** Techo de artículos del detalle: es una pantalla de lectura, no un export. */
    const MAX_ARTICULOS = 20;

    /** Techo de términos buscados del detalle. */
    const MAX_BUSQUEDAS = 20;

    /** Techo de eventos de la línea de tiempo. */
    const MAX_EVENTOS_LINEA_DE_TIEMPO = 50;

    /** Techo de clientes que devuelve interesados_en_un_articulo(). */
    const MAX_INTERESADOS = 20;

    /**
     * El texto en criollo de cada tipo de evento. Tabla única de la misión.
     *
     * Un `event_type` que no esté acá se informa con su propio valor crudo y NUNCA se descarta en
     * silencio: si la tienda empieza a mandar un tipo nuevo, tiene que verse en la pantalla en vez
     * de desaparecer.
     *
     * @var array<string, string>
     */
    const TIPOS_TEXTO = [
        'product_view'      => 'Miró un artículo',
        'search'            => 'Buscó en la tienda',
        'cart_add'          => 'Agregó al carrito',
        'cart_remove'       => 'Sacó del carrito',
        'checkout_start'    => 'Empezó a comprar',
        'checkout_complete' => 'Cerró una compra',
    ];

    /**
     * Qué contador de `totales` alimenta cada tipo de evento.
     *
     * @var array<string, string>
     */
    const TOTALES_POR_TIPO = [
        'product_view'      => 'vistas',
        'search'            => 'busquedas',
        'cart_add'          => 'agregados_al_carrito',
        'cart_remove'       => 'quitados_del_carrito',
        'checkout_start'    => 'checkouts_empezados',
        'checkout_complete' => 'compras',
    ];

    /**
     * Los tipos que arman el detalle por artículo. `checkout_complete` NO está, y el porqué es el
     * punto 4 del docblock de la clase.
     *
     * @var array<int, string>
     */
    const TIPOS_CON_DETALLE_POR_ARTICULO = ['product_view', 'cart_add'];

    /**
     * Dueño del comercio. NO sale de Auth: las tools del chat corren adentro de un job sin sesión.
     *
     * @var int
     */
    protected $owner_id;

    /**
     * @param int $owner_id Dueño del comercio (el que resuelve Controller::userId() del lado del ERP)
     */
    public function __construct($owner_id)
    {
        $this->owner_id = (int) $owner_id;
    }

    /**
     * ¿Es un periodo de los que esta pantalla sabe contestar?
     *
     * Un '30' que llegó por la query string vale lo mismo que un 30: lo que se controla es el VALOR,
     * no el tipo con el que viajó por HTTP. Pero '30.5', ' 30' o 'abc' no son 30 y no pasan —
     * ctype_digit los separa sin las sorpresas de un casteo permisivo, que convertiría 'abc' en 0 y
     * dejaría entrar el histórico por un typo.
     *
     * @param  mixed $periodo
     * @return bool
     */
    public static function es_periodo_valido($periodo)
    {
        if (is_int($periodo)) {
            return in_array($periodo, self::PERIODOS_VALIDOS, true);
        }

        if (!is_string($periodo) || !ctype_digit($periodo)) {
            return false;
        }

        return in_array((int) $periodo, self::PERIODOS_VALIDOS, true);
    }

    /**
     * Los compradores de la tienda atados a un cliente del ERP.
     *
     * La tenencia la da esta consulta —`buyers.user_id`— y es de la que después cuelga todo: por eso
     * las consultas de tracking pueden no llevar `user_id` sin perder aislamiento (punto 2 del
     * docblock de la clase).
     *
     * @param  int $client_id
     * @return array<int, int> Lista de buyer_id; vacía si el cliente no tiene ninguno
     */
    public function buyer_ids_de_un_cliente($client_id)
    {
        $client_id = (int) $client_id;

        if ($client_id <= 0) {
            return [];
        }

        return $this->ids_limpios(
            DB::table('buyers')
                ->where('user_id', $this->owner_id)
                ->where('comercio_city_client_id', $client_id)
                ->pluck('id')
                ->all()
        );
    }

    /**
     * El comprador, si es de este comercio. La otra puerta de la pantalla (Tienda Online → Clientes),
     * y la única por la que se ve un buyer SIN cliente asociado.
     *
     * @param  int $buyer_id
     * @return array<int, int> 0 o 1 elemento
     */
    public function buyer_ids_de_un_comprador($buyer_id)
    {
        $buyer_id = (int) $buyer_id;

        if ($buyer_id <= 0) {
            return [];
        }

        return $this->ids_limpios(
            DB::table('buyers')
                ->where('user_id', $this->owner_id)
                ->where('id', $buyer_id)
                ->pluck('id')
                ->all()
        );
    }

    /**
     * @param  int $client_id
     * @return string|null Nombre del cliente, o null si no existe o no es de este comercio
     */
    public function nombre_del_cliente($client_id)
    {
        $client_id = (int) $client_id;

        if ($client_id <= 0) {
            return null;
        }

        $nombre = DB::table('clients')
            ->where('user_id', $this->owner_id)
            ->where('id', $client_id)
            ->value('name');

        return is_null($nombre) ? null : (string) $nombre;
    }

    /**
     * Los compradores con nombre, para que la pantalla pueda decir entre quiénes está sumando.
     *
     * @param  array $buyer_ids
     * @return array<int, array> Filas ['buyer_id' => int, 'nombre' => string]
     */
    public function compradores($buyer_ids)
    {
        $ids = $this->ids_limpios(is_array($buyer_ids) ? $buyer_ids : []);

        if (empty($ids)) {
            return [];
        }

        $filas = DB::table('buyers')
            ->where('user_id', $this->owner_id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'name']);

        $compradores = [];

        foreach ($filas as $fila) {
            $compradores[] = [
                'buyer_id' => (int) $fila->id,
                'nombre'   => (string) $fila->name,
            ];
        }

        return $compradores;
    }

    /**
     * El bloque `actividad` completo: fuente, ventana, totales, artículos, búsquedas y línea de
     * tiempo. TODAS las claves están siempre presentes; los vacíos son [], 0 o null.
     *
     * 🔴 GUARDA TEMPRANA: con `$buyer_ids` vacío devuelve el bloque con `hay_datos = false` SIN
     * TOCAR LA BASE. No es una optimización: un `whereIn` con lista vacía es `IN ()`, que Laravel
     * traduce a `0 = 1` — anda, pero deja el resto de la consulta armada y viajando, y sobre todo
     * deja el caso "cliente sin ningún comprador de la tienda" contestándose con seis consultas que
     * no pueden devolver nada.
     *
     * `cliente` se resuelve desde los propios compradores (el `comercio_city_client_id` que
     * comparten), y por eso viene null cuando se entró por un buyer sin cliente asociado, que es lo
     * que pide el contrato. Con `$buyer_ids` vacío también viene null: el controller, que sí sabe
     * por dónde se entró, lo pisa con lo que resolvió él.
     *
     * @param  array $buyer_ids
     * @param  int   $periodo Uno de PERIODOS_VALIDOS
     * @return array
     */
    public function actividad(array $buyer_ids, $periodo)
    {
        $periodo = (int) $periodo;
        $fuente  = $this->fuente_para($periodo);
        $ids     = $this->ids_limpios($buyer_ids);

        if (empty($ids)) {
            return $this->bloque_vacio($fuente, $periodo);
        }

        $desde = ($fuente === self::FUENTE_EVENTOS) ? Carbon::now()->subDays($periodo) : null;

        $grupos    = ($fuente === self::FUENTE_EVENTOS) ? $this->grupos_de_eventos($ids, $desde) : $this->grupos_del_agregado($ids);
        $busquedas = ($fuente === self::FUENTE_EVENTOS) ? $this->busquedas_de_eventos($ids, $desde) : $this->busquedas_del_agregado($ids);
        $eventos   = ($fuente === self::FUENTE_EVENTOS) ? $this->linea_de_tiempo($ids, $desde) : [];

        $resumen = $this->resumir_grupos($grupos);

        // Un solo viaje al catálogo para el detalle Y para la línea de tiempo: nunca un find() por fila.
        $nombres = $this->nombres_de_articulos(array_merge(
            array_column($resumen['articulos'], 'article_id'),
            array_column($eventos, 'article_id')
        ));

        return [
            'fuente'          => $fuente,
            'periodo'         => $periodo,
            'desde'           => $this->desde_informado($fuente, $periodo, $resumen['primera']),
            'hasta'           => $this->hasta_informado($fuente),
            'hay_datos'       => !empty($grupos),
            'cliente'         => $this->cliente_de_los_compradores($ids),
            'compradores'     => $this->compradores($ids),
            'totales'         => $this->totales_informados($resumen, $fuente),
            'articulos'       => $this->articulos_informados($resumen['articulos'], $nombres, $fuente),
            'busquedas'       => $this->busquedas_informadas($busquedas, $fuente),
            'linea_de_tiempo' => $this->linea_de_tiempo_informada($eventos, $nombres),
        ];
    }

    /**
     * Quién anduvo mirando (o carreteando) un artículo, agrupado por CLIENTE del ERP.
     *
     * La tenencia la da el artículo: si no es de este comercio, la lista viene vacía y no se toca el
     * tracking. El filtro `b.user_id = :owner` va sobre la tabla UNIDA, que entra por PRIMARY en
     * eq_ref, así que no le cambia el índice a la tabla que manda (C6 del plan, verificado con
     * EXPLAIN).
     *
     * 🔴 Los visitantes ANÓNIMOS no aparecen acá, y no es un olvido: sin `comercio_city_client_id`
     * no hay a quién nombrar. Se cuentan aparte, con anonimos_de_un_articulo().
     *
     * 🔴 El descarte de "ya lo compró" va en PHP y contra `article_purchases`, llamando a
     * CriteriosDeOfertaService::filtro_de_ventas_reales() —que es público estático justamente para
     * que ese criterio viva en un solo lugar—. NO va como un NOT EXISTS contra la propia tabla de
     * eventos: `checkout_complete` no garantiza traer `article_id`, así que ese NOT EXISTS dejaría
     * pasar lo que sí se compró (CriteriosDeOfertaService:224-229).
     *
     * @param  int  $article_id
     * @param  int  $dias
     * @param  bool $solo_sin_comprar
     * @return array<int, array> Filas ['client_id','cliente','telefono','vistas','segundos','al_carrito','ultima_vez']
     */
    public function interesados_en_un_articulo($article_id, $dias, $solo_sin_comprar = true)
    {
        $article_id = (int) $article_id;
        $dias       = (int) $dias;

        if ($article_id <= 0 || $dias <= 0 || !$this->articulo_del_dueno($article_id)) {
            return [];
        }

        $filas = DB::table('buyer_tracking_events as e')
            ->join('buyers as b', 'b.id', '=', 'e.buyer_id')
            ->where('e.article_id', $article_id)
            ->where('e.occurred_at', '>=', Carbon::now()->subDays($dias))
            ->where('b.user_id', $this->owner_id)
            ->whereNotNull('b.comercio_city_client_id')
            ->whereIn('e.event_type', self::TIPOS_CON_DETALLE_POR_ARTICULO)
            ->groupBy('b.comercio_city_client_id')
            ->selectRaw(
                "b.comercio_city_client_id as client_id,
                 SUM(CASE WHEN e.event_type = 'product_view' THEN 1 ELSE 0 END) as vistas,
                 COALESCE(SUM(e.dwell_ms), 0) as dwell_ms_total,
                 SUM(CASE WHEN e.event_type = 'cart_add' THEN 1 ELSE 0 END) as al_carrito,
                 MAX(e.occurred_at) as ultimo"
            )
            ->orderByDesc('vistas')
            // Desempate explícito: sin él, dos corridas iguales podrían recortar distinto en el LIMIT.
            ->orderBy('client_id')
            ->limit(self::MAX_INTERESADOS)
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $client_ids   = $this->ids_limpios($filas->pluck('client_id')->all());
        $ya_comprados = $solo_sin_comprar ? $this->ultima_compra_del_articulo($client_ids, $article_id) : [];
        $datos        = $this->datos_de_clientes($client_ids);
        $interesados  = [];

        foreach ($filas as $fila) {
            $client_id = (int) $fila->client_id;

            // Sólo sale de la lista si la compra es POSTERIOR a la señal: que lo haya comprado el
            // año pasado no dice nada de que lo esté mirando de nuevo esta semana.
            if (isset($ya_comprados[$client_id]) && $ya_comprados[$client_id] >= $fila->ultimo) {
                continue;
            }

            $interesados[] = [
                'client_id'  => $client_id,
                'cliente'    => isset($datos[$client_id]) ? $datos[$client_id]['nombre'] : 'Cliente #' . $client_id,
                'telefono'   => isset($datos[$client_id]) ? $datos[$client_id]['telefono'] : '',
                'vistas'     => (int) $fila->vistas,
                'segundos'   => $this->a_segundos($fila->dwell_ms_total),
                'al_carrito' => (int) $fila->al_carrito,
                'ultima_vez' => $this->formatear_momento($fila->ultimo, self::FUENTE_EVENTOS),
            ];
        }

        return $interesados;
    }

    /**
     * Los visitantes SIN cuenta que anduvieron sobre un artículo. Van aparte de los clientes
     * nombrados a propósito: son gente que no se puede contactar, y mezclarlos con la lista de
     * clientes inflaría un número que después alguien usa para decidir una oferta.
     *
     * 🔴 Se cuentan con `CASE WHEN` y NO con `WHERE buyer_id IS NULL`. Medido con EXPLAIN: con ese
     * predicado el optimizador se va a `bte_buyer_ocurrido_index`, que en una tienda —donde el
     * grueso del tráfico es anónimo— matchea casi toda la tabla. Sin el predicado se queda en
     * `bte_articulo_ocurrido_index`, que es el que corresponde.
     *
     * @param  int $article_id
     * @param  int $dias
     * @return array{'eventos': int, 'visitantes': int}
     */
    public function anonimos_de_un_articulo($article_id, $dias)
    {
        $vacio      = ['eventos' => 0, 'visitantes' => 0];
        $article_id = (int) $article_id;
        $dias       = (int) $dias;

        if ($article_id <= 0 || $dias <= 0 || !$this->articulo_del_dueno($article_id)) {
            return $vacio;
        }

        $fila = DB::table('buyer_tracking_events as e')
            ->where('e.article_id', $article_id)
            ->where('e.occurred_at', '>=', Carbon::now()->subDays($dias))
            ->selectRaw(
                'COUNT(*) as eventos,
                 COUNT(DISTINCT e.visitor_id) as visitantes,
                 SUM(CASE WHEN e.buyer_id IS NULL THEN 1 ELSE 0 END) as eventos_anonimos,
                 COUNT(DISTINCT CASE WHEN e.buyer_id IS NULL THEN e.visitor_id ELSE NULL END) as visitantes_anonimos'
            )
            ->first();

        if (!$fila) {
            return $vacio;
        }

        return [
            'eventos'    => (int) $fila->eventos_anonimos,
            'visitantes' => (int) $fila->visitantes_anonimos,
        ];
    }

    /**
     * El artículo, si es de este comercio. Es LA tenencia de las dos consultas por artículo.
     *
     * @param  int $article_id
     * @return Article|null
     */
    public function articulo_del_dueno($article_id)
    {
        $article_id = (int) $article_id;

        if ($article_id <= 0) {
            return null;
        }

        return Article::where('id', $article_id)->where('user_id', $this->owner_id)->first();
    }

    /**
     * De qué tabla se lee, según la antigüedad pedida. Es la regla de corte del punto 1 del docblock
     * de la clase, escrita en un solo lugar.
     *
     * El `>` contra PERIODO_MAXIMO_CON_DETALLE no es defensivo de más: hoy la lista blanca termina en
     * 90 y la retención es 90, pero si mañana alguien baja la retención, un pedido de 90 días tiene
     * que degradar al agregado en vez de contestar con la cola de lo que sobrevivió a la purga.
     *
     * @param  int $periodo
     * @return string
     */
    protected function fuente_para($periodo)
    {
        $periodo = (int) $periodo;

        if ($periodo === self::PERIODO_HISTORICO || $periodo > self::PERIODO_MAXIMO_CON_DETALLE) {
            return self::FUENTE_AGREGADO;
        }

        return self::FUENTE_EVENTOS;
    }

    /**
     * C1 — Totales Y artículos en UNA sola consulta: se agrupa por (event_type, article_id) y después
     * se recorre en PHP, sumando por tipo para los totales y quedándose con las filas
     * product_view / cart_add para el detalle. Una consulta y no cuatro.
     *
     * `sin_resultado` cuenta los eventos que devolvieron CERO resultados. El 0 no es un vacío a
     * ignorar: "buscó y no encontró nada" es el dato más accionable que hay (hay demanda y no hay
     * oferta). Un `results_count` NULL es "no aplica" y el CASE no lo cuenta.
     *
     * @param  array          $buyer_ids No vacío
     * @param  \Carbon\Carbon $desde
     * @return \Illuminate\Support\Collection
     */
    protected function grupos_de_eventos(array $buyer_ids, $desde)
    {
        return DB::table('buyer_tracking_events as e')
            ->whereIn('e.buyer_id', $buyer_ids)
            ->where('e.occurred_at', '>=', $desde)
            ->groupBy('e.event_type', 'e.article_id')
            ->selectRaw(
                'e.event_type as event_type, e.article_id as article_id,
                 COUNT(*) as total,
                 COALESCE(SUM(e.dwell_ms), 0) as dwell_ms_total,
                 COALESCE(SUM(e.amount), 0) as monto_total,
                 COUNT(DISTINCT e.visitor_id) as visitantes,
                 SUM(CASE WHEN e.results_count = 0 THEN 1 ELSE 0 END) as sin_resultado,
                 MIN(e.occurred_at) as primero,
                 MAX(e.occurred_at) as ultimo'
            )
            ->get();
    }

    /**
     * C4 — Lo mismo que C1 pero del agregado, y SIN filtro de fecha: `periodo = 0` es "todo el
     * historial" y el agregado no se purga nunca.
     *
     * `sin_resultado` suma el `total` de los días cuyo `results_count` fue 0. Allá la columna guarda
     * el MÁXIMO del día (y está bien allá: MAX = 0 significa "ninguna vez devolvió nada", ver la
     * migración 2026_08_15_140100:77-84), así que un día con máximo 0 es un día en que ninguna de
     * esas búsquedas encontró nada.
     *
     * @param  array $buyer_ids No vacío
     * @return \Illuminate\Support\Collection
     */
    protected function grupos_del_agregado(array $buyer_ids)
    {
        return DB::table('buyer_tracking_daily as d')
            ->whereIn('d.buyer_id', $buyer_ids)
            ->groupBy('d.event_type', 'd.article_id')
            ->selectRaw(
                'd.event_type as event_type, d.article_id as article_id,
                 SUM(d.total) as total,
                 SUM(d.dwell_ms_total) as dwell_ms_total,
                 SUM(d.amount_total) as monto_total,
                 SUM(d.visitantes) as visitantes,
                 SUM(CASE WHEN d.results_count = 0 THEN d.total ELSE 0 END) as sin_resultado,
                 MIN(d.fecha) as primero,
                 MAX(d.fecha) as ultimo'
            )
            ->get();
    }

    /**
     * C2 — Qué buscó, de los eventos crudos.
     *
     * 🔴 `MIN(results_count)`, NO `MAX`, y no es un descuido. Acá se está contestando "¿alguna vez
     * buscó esto y no encontró nada?", que es lo accionable para el comerciante. En
     * `buyer_tracking_daily` la misma columna guarda el MÁXIMO del grupo del día, y allá está bien
     * por el motivo escrito en su migración (2026_08_15_140100:77-84). Son dos preguntas distintas y
     * por eso son dos agregaciones distintas.
     *
     * @param  array          $buyer_ids No vacío
     * @param  \Carbon\Carbon $desde
     * @return \Illuminate\Support\Collection
     */
    protected function busquedas_de_eventos(array $buyer_ids, $desde)
    {
        return DB::table('buyer_tracking_events as e')
            ->whereIn('e.buyer_id', $buyer_ids)
            ->where('e.occurred_at', '>=', $desde)
            ->where('e.event_type', 'search')
            ->whereNotNull('e.search_term')
            ->where('e.search_term', '!=', '')
            ->groupBy('e.search_term')
            ->selectRaw(
                'e.search_term as termino, COUNT(*) as veces,
                 MIN(e.results_count) as resultados, MAX(e.occurred_at) as ultimo'
            )
            ->orderByDesc('veces')
            ->orderBy('termino')
            ->limit(self::MAX_BUSQUEDAS)
            ->get();
    }

    /**
     * C5 — Qué buscó, del agregado. El `MIN` sobre los máximos diarios sigue contestando la misma
     * pregunta que C2: si algún día el máximo fue 0, ese día no encontró nada.
     *
     * @param  array $buyer_ids No vacío
     * @return \Illuminate\Support\Collection
     */
    protected function busquedas_del_agregado(array $buyer_ids)
    {
        return DB::table('buyer_tracking_daily as d')
            ->whereIn('d.buyer_id', $buyer_ids)
            ->where('d.event_type', 'search')
            ->whereNotNull('d.search_term')
            ->where('d.search_term', '!=', '')
            ->groupBy('d.search_term')
            ->selectRaw(
                'd.search_term as termino, SUM(d.total) as veces,
                 MIN(d.results_count) as resultados, MAX(d.fecha) as ultimo'
            )
            ->orderByDesc('veces')
            ->orderBy('termino')
            ->limit(self::MAX_BUSQUEDAS)
            ->get();
    }

    /**
     * C3 — La línea de tiempo, del más nuevo al más viejo. Sólo existe con la fuente `eventos`: el
     * agregado no guarda la hora del evento (punto 1.2 del docblock de la clase).
     *
     * @param  array          $buyer_ids No vacío
     * @param  \Carbon\Carbon $desde
     * @return array<int, array>
     */
    protected function linea_de_tiempo(array $buyer_ids, $desde)
    {
        $filas = DB::table('buyer_tracking_events as e')
            ->whereIn('e.buyer_id', $buyer_ids)
            ->where('e.occurred_at', '>=', $desde)
            ->orderByDesc('e.occurred_at')
            // Desempate por id: dos eventos del mismo segundo tienen que salir siempre en el mismo
            // orden, o el recorte del LIMIT cambia entre dos lecturas iguales.
            ->orderByDesc('e.id')
            ->limit(self::MAX_EVENTOS_LINEA_DE_TIEMPO)
            ->get([
                'e.id', 'e.event_type', 'e.article_id', 'e.search_term', 'e.results_count',
                'e.quantity', 'e.amount', 'e.dwell_ms', 'e.occurred_at',
            ]);

        $eventos = [];

        foreach ($filas as $fila) {
            $eventos[] = (array) $fila;
        }

        return $eventos;
    }

    /**
     * Recorre los grupos de C1/C4 y arma de una sola pasada los totales y el detalle por artículo.
     *
     * @param  \Illuminate\Support\Collection $grupos
     * @return array
     */
    protected function resumir_grupos($grupos)
    {
        $totales   = $this->totales_vacios();
        $articulos = [];
        $dwell_ms  = 0;
        $primera   = null;
        $ultima    = null;

        foreach ($grupos as $grupo) {
            $tipo  = (string) $grupo->event_type;
            $total = (int) $grupo->total;

            if (isset(self::TOTALES_POR_TIPO[$tipo])) {
                $totales[self::TOTALES_POR_TIPO[$tipo]] += $total;
            }

            /*
             * 🔴 El cast a (int) del SUM(dwell_ms) no es cosmético: dwell_ms es nullable y SUM() de
             * puros nulls devuelve NULL, así que sin el cast la pantalla mostraría "null segundos".
             * Mismo cuidado, y por el mismo motivo, que CriteriosDeOfertaService:441-443.
             */
            $dwell_ms += (int) $grupo->dwell_ms_total;

            if ($tipo === 'search') {
                $totales['busquedas_sin_resultado'] += (int) $grupo->sin_resultado;
            }

            if ($tipo === 'checkout_complete') {
                $totales['monto_comprado'] += (float) $grupo->monto_total;
            }

            $primera = $this->menor($primera, $grupo->primero);
            $ultima  = $this->mayor($ultima, $grupo->ultimo);

            if (!in_array($tipo, self::TIPOS_CON_DETALLE_POR_ARTICULO, true) || is_null($grupo->article_id)) {
                continue;
            }

            $article_id = (int) $grupo->article_id;

            if (!isset($articulos[$article_id])) {
                $articulos[$article_id] = [
                    'article_id'           => $article_id,
                    'vistas'               => 0,
                    'dwell_ms'             => 0,
                    'agregados_al_carrito' => 0,
                    'ultima_vez'           => null,
                ];
            }

            if ($tipo === 'product_view') {
                $articulos[$article_id]['vistas'] += $total;
            } else {
                $articulos[$article_id]['agregados_al_carrito'] += $total;
            }

            $articulos[$article_id]['dwell_ms'] += (int) $grupo->dwell_ms_total;
            $articulos[$article_id]['ultima_vez'] = $this->mayor($articulos[$article_id]['ultima_vez'], $grupo->ultimo);
        }

        $totales['tiempo_total_segundos'] = $this->a_segundos($dwell_ms);
        $totales['ultima_actividad']      = $ultima;

        /*
         * Los artículos distintos se cuentan ANTES del recorte: con el conteo tomado después, un
         * cliente que miró 40 artículos informaría "20 artículos distintos" y el número sería una
         * consecuencia del tope de la pantalla en vez de un dato del cliente.
         */
        $totales['articulos_distintos'] = count($articulos);

        return [
            'totales'   => $totales,
            'articulos' => $this->ordenar_articulos($articulos),
            'primera'   => $primera,
        ];
    }

    /**
     * Los más mirados primero, con desempate estable para que el recorte no baile entre dos lecturas.
     *
     * @param  array $articulos
     * @return array<int, array> Ya recortado a MAX_ARTICULOS
     */
    protected function ordenar_articulos(array $articulos)
    {
        $lista = array_values($articulos);

        usort($lista, function ($a, $b) {
            if ($a['vistas'] !== $b['vistas']) {
                return ($a['vistas'] < $b['vistas']) ? 1 : -1;
            }

            if ($a['dwell_ms'] !== $b['dwell_ms']) {
                return ($a['dwell_ms'] < $b['dwell_ms']) ? 1 : -1;
            }

            return $a['article_id'] - $b['article_id'];
        });

        return array_slice($lista, 0, self::MAX_ARTICULOS);
    }

    /**
     * @param  array  $resumen
     * @param  string $fuente
     * @return array
     */
    protected function totales_informados(array $resumen, $fuente)
    {
        $totales = $resumen['totales'];

        $totales['monto_comprado']   = round((float) $totales['monto_comprado'], 2);
        $totales['ultima_actividad'] = $this->formatear_momento($totales['ultima_actividad'], $fuente);

        return $totales;
    }

    /**
     * @param  array  $articulos
     * @param  array  $nombres
     * @param  string $fuente
     * @return array<int, array>
     */
    protected function articulos_informados(array $articulos, array $nombres, $fuente)
    {
        $lista = [];

        foreach ($articulos as $articulo) {
            $article_id = $articulo['article_id'];

            $lista[] = [
                'article_id'           => $article_id,
                'nombre'               => isset($nombres[$article_id]) ? $nombres[$article_id] : 'Artículo #' . $article_id,
                'vistas'               => $articulo['vistas'],
                'tiempo_segundos'      => $this->a_segundos($articulo['dwell_ms']),
                'agregados_al_carrito' => $articulo['agregados_al_carrito'],
                'ultima_vez'           => $this->formatear_momento($articulo['ultima_vez'], $fuente),
            ];
        }

        return $lista;
    }

    /**
     * 🔴 `sin_resultado` es `resultados === 0` y nada más. Un `resultados` en null es "no aplica" y
     * NO se castea a 0: un product_view no tiene resultados y convertirlo en cero lo haría figurar
     * como "buscó y no encontró nada", que es justo el dato que el comerciante va a salir a atender.
     *
     * @param  \Illuminate\Support\Collection $busquedas
     * @param  string                         $fuente
     * @return array<int, array>
     */
    protected function busquedas_informadas($busquedas, $fuente)
    {
        $lista = [];

        foreach ($busquedas as $busqueda) {
            $resultados = is_null($busqueda->resultados) ? null : (int) $busqueda->resultados;

            $lista[] = [
                'termino'       => (string) $busqueda->termino,
                'veces'         => (int) $busqueda->veces,
                'resultados'    => $resultados,
                'sin_resultado' => $resultados === 0,
                'ultima_vez'    => $this->formatear_momento($busqueda->ultimo, $fuente),
            ];
        }

        return $lista;
    }

    /**
     * @param  array $eventos
     * @param  array $nombres
     * @return array<int, array>
     */
    protected function linea_de_tiempo_informada(array $eventos, array $nombres)
    {
        $lista = [];

        foreach ($eventos as $evento) {
            $tipo       = (string) $evento['event_type'];
            $article_id = is_null($evento['article_id']) ? null : (int) $evento['article_id'];

            $lista[] = [
                'id'         => (int) $evento['id'],
                'tipo'       => $tipo,
                // Un tipo que no está en la tabla se informa con su valor crudo: si la tienda empieza
                // a mandar uno nuevo tiene que VERSE, no desaparecer de la pantalla en silencio.
                'tipo_texto' => isset(self::TIPOS_TEXTO[$tipo]) ? self::TIPOS_TEXTO[$tipo] : $tipo,
                'article_id' => $article_id,
                'articulo'   => is_null($article_id)
                    ? null
                    : (isset($nombres[$article_id]) ? $nombres[$article_id] : 'Artículo #' . $article_id),
                'termino'    => is_null($evento['search_term']) ? null : (string) $evento['search_term'],
                'resultados' => is_null($evento['results_count']) ? null : (int) $evento['results_count'],
                'cantidad'   => is_null($evento['quantity']) ? null : (int) $evento['quantity'],
                'monto'      => is_null($evento['amount']) ? null : (float) $evento['amount'],
                'segundos'   => is_null($evento['dwell_ms']) ? null : $this->a_segundos($evento['dwell_ms']),
                'cuando'     => $this->formatear_momento($evento['occurred_at'], self::FUENTE_EVENTOS),
            ];
        }

        return $lista;
    }

    /**
     * Los nombres del catálogo, en UNA sola consulta. Nunca un Article::find() por fila: el detalle
     * puede traer 20 artículos y la línea de tiempo 50, y eso serían 70 viajes a la base para
     * dibujar un modal.
     *
     * Un id que no vuelva —artículo borrado, o de otro comercio— se informa como "Artículo #N": la
     * pantalla no se rompe por un artículo que se fue del catálogo.
     *
     * @param  array $article_ids
     * @return array<int, string> Mapa article_id => nombre
     */
    protected function nombres_de_articulos(array $article_ids)
    {
        $ids = [];

        foreach ($article_ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if (empty($ids)) {
            return [];
        }

        $nombres = [];

        $filas = DB::table('articles')
            ->where('user_id', $this->owner_id)
            ->whereIn('id', array_values($ids))
            ->get(['id', 'name']);

        foreach ($filas as $fila) {
            $nombres[(int) $fila->id] = (string) $fila->name;
        }

        foreach ($ids as $id) {
            if (!isset($nombres[$id]) || $nombres[$id] === '') {
                $nombres[$id] = 'Artículo #' . $id;
            }
        }

        return $nombres;
    }

    /**
     * Nombre y teléfono de los clientes de la lista de interesados, en una sola consulta.
     *
     * @param  array $client_ids
     * @return array<int, array> Mapa client_id => ['nombre' => string, 'telefono' => string]
     */
    protected function datos_de_clientes(array $client_ids)
    {
        if (empty($client_ids)) {
            return [];
        }

        $datos = [];

        $filas = DB::table('clients')
            ->where('user_id', $this->owner_id)
            ->whereIn('id', $client_ids)
            ->get(['id', 'name', 'phone']);

        foreach ($filas as $fila) {
            $datos[(int) $fila->id] = [
                'nombre'   => (string) $fila->name,
                'telefono' => is_null($fila->phone) ? '' : (string) $fila->phone,
            ];
        }

        return $datos;
    }

    /**
     * Última compra REAL de un artículo por cada cliente de la lista.
     *
     * 🔴 Se LLAMA a CriteriosDeOfertaService::filtro_de_ventas_reales(), no se copia: su docblock
     * dice textual que vive en un solo método "para que no envejezca por su lado en ninguna" de las
     * consultas que lo llevan. El scope por comercio lo da `articles.user_id` — `article_purchases`
     * no tiene `user_id`.
     *
     * @param  array $client_ids
     * @param  int   $article_id
     * @return array<int, string> Mapa client_id => fecha de la última compra
     */
    protected function ultima_compra_del_articulo(array $client_ids, $article_id)
    {
        if (empty($client_ids)) {
            return [];
        }

        $q = DB::table('article_purchases')->selectRaw(
            'article_purchases.client_id as client_id, MAX(article_purchases.created_at) as ultima'
        );
        CriteriosDeOfertaService::filtro_de_ventas_reales($q, $this->owner_id);
        $q->whereIn('article_purchases.client_id', $client_ids)
            ->where('article_purchases.article_id', $article_id)
            ->groupBy('article_purchases.client_id');

        $mapa = [];

        foreach ($q->get() as $fila) {
            $mapa[(int) $fila->client_id] = $fila->ultima;
        }

        return $mapa;
    }

    /**
     * El cliente del ERP al que pertenecen estos compradores, si hay exactamente uno.
     *
     * Viene null cuando el comprador no tiene cliente asociado —el caso NORMAL de un buyer de la
     * tienda— y también en el caso imposible de que la lista mezcle compradores de dos clientes
     * distintos, donde nombrar a uno de los dos sería inventar.
     *
     * @param  array $buyer_ids No vacío
     * @return array|null ['client_id' => int, 'nombre' => string]
     */
    protected function cliente_de_los_compradores(array $buyer_ids)
    {
        $client_ids = $this->ids_limpios(
            DB::table('buyers')
                ->where('user_id', $this->owner_id)
                ->whereIn('id', $buyer_ids)
                ->whereNotNull('comercio_city_client_id')
                ->distinct()
                ->pluck('comercio_city_client_id')
                ->all()
        );

        if (count($client_ids) !== 1) {
            return null;
        }

        $client_id = $client_ids[0];
        $nombre    = $this->nombre_del_cliente($client_id);

        return [
            'client_id' => $client_id,
            // El fallback es el mismo criterio que el de los artículos: la pantalla no se rompe
            // porque el cliente se haya borrado después de que el comprador quedó atado a él.
            'nombre'    => is_null($nombre) ? 'Cliente #' . $client_id : $nombre,
        ];
    }

    /**
     * El bloque completo sin una sola consulta: es lo que devuelve un cliente que no tiene ningún
     * comprador de la tienda asociado, que es el caso más común de todos.
     *
     * @param  string $fuente
     * @param  int    $periodo
     * @return array
     */
    protected function bloque_vacio($fuente, $periodo)
    {
        return [
            'fuente'          => $fuente,
            'periodo'         => (int) $periodo,
            'desde'           => $this->desde_informado($fuente, $periodo, null),
            'hasta'           => $this->hasta_informado($fuente),
            'hay_datos'       => false,
            'cliente'         => null,
            'compradores'     => [],
            'totales'         => $this->totales_informados(['totales' => $this->totales_vacios()], $fuente),
            'articulos'       => [],
            'busquedas'       => [],
            'linea_de_tiempo' => [],
        ];
    }

    /**
     * @return array Todos los contadores en cero, con las claves SIEMPRE presentes
     */
    protected function totales_vacios()
    {
        return [
            'vistas'                  => 0,
            'articulos_distintos'     => 0,
            'tiempo_total_segundos'   => 0,
            'busquedas'               => 0,
            'busquedas_sin_resultado' => 0,
            'agregados_al_carrito'    => 0,
            'quitados_del_carrito'    => 0,
            'checkouts_empezados'     => 0,
            'compras'                 => 0,
            'monto_comprado'          => 0.0,
            'ultima_actividad'        => null,
        ];
    }

    /**
     * Desde cuándo se está informando. Con los eventos es la ventana pedida; con el agregado es el
     * primer día que tiene registro, porque "todo el historial" no tiene una ventana que anunciar.
     *
     * @param  string $fuente
     * @param  int    $periodo
     * @param  mixed  $primera Primer momento con datos, o null
     * @return string|null
     */
    protected function desde_informado($fuente, $periodo, $primera)
    {
        if ($fuente === self::FUENTE_EVENTOS) {
            return Carbon::now()->subDays((int) $periodo)->format('Y-m-d');
        }

        return $this->formatear_momento($primera, self::FUENTE_AGREGADO);
    }

    /**
     * 🔴 Con el agregado el `hasta` es AYER, siempre. `tracking:agregar-buyers` corre a las 03:30
     * sobre el día anterior, así que el histórico NUNCA incluye lo de hoy. Informar hoy sería decir
     * que el número está al día cuando no lo está.
     *
     * @param  string $fuente
     * @return string
     */
    protected function hasta_informado($fuente)
    {
        if ($fuente === self::FUENTE_AGREGADO) {
            return Carbon::now()->subDay()->format('Y-m-d');
        }

        return Carbon::now()->format('Y-m-d');
    }

    /**
     * Milisegundos a segundos enteros. El (int) de entrada es lo que hace que un SUM() de puros
     * nulls valga cero y no null.
     *
     * @param  mixed $milisegundos
     * @return int
     */
    protected function a_segundos($milisegundos)
    {
        return (int) round(((int) $milisegundos) / 1000);
    }

    /**
     * @param  mixed  $valor
     * @param  string $fuente
     * @return string|null Con el agregado, fecha sin hora: agrupa por día y no sabe la hora
     */
    protected function formatear_momento($valor, $fuente)
    {
        if (is_null($valor) || $valor === '') {
            return null;
        }

        $formato = ($fuente === self::FUENTE_AGREGADO) ? 'Y-m-d' : 'Y-m-d H:i:s';

        return Carbon::parse($valor)->format($formato);
    }

    /**
     * @param  mixed $actual
     * @param  mixed $candidato
     * @return mixed El mayor de los dos, ignorando nulls
     */
    protected function mayor($actual, $candidato)
    {
        if (is_null($candidato)) {
            return $actual;
        }

        return (is_null($actual) || $candidato > $actual) ? $candidato : $actual;
    }

    /**
     * @param  mixed $actual
     * @param  mixed $candidato
     * @return mixed El menor de los dos, ignorando nulls
     */
    protected function menor($actual, $candidato)
    {
        if (is_null($candidato)) {
            return $actual;
        }

        return (is_null($actual) || $candidato < $actual) ? $candidato : $actual;
    }

    /**
     * Enteros positivos, sin repetidos y reindexados. Lo que entra a un whereIn tiene que ser una
     * lista de ids y no lo que haya devuelto una consulta.
     *
     * @param  array $ids
     * @return array<int, int>
     */
    protected function ids_limpios(array $ids)
    {
        $limpios = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $limpios[$id] = $id;
            }
        }

        return array_values($limpios);
    }
}
