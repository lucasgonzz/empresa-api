<?php

namespace App\Services\Mostrador;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hechos del informe "Tu tienda" (tipo 'tienda'): pedidos, compradores, productos,
 * carritos abandonados, búsquedas, quién miró y no compró y los clientes del local que
 * andan por la tienda.
 *
 * El día del informe es AYER; lo que dice "7 días" es la ventana [ayer - 6, ayer].
 * Todo sale de orders / buyers / buyer_tracking_events (por occurred_at; los eventos
 * crudos se conservan 90 días, más que de sobra para 7) y las tablas del ERP con las
 * que se cruza (articles, clients, sales). No aplica si el comercio no tiene tienda
 * online (users.online vacío y ningún pedido).
 *
 * Carrito abandonado = cart_add del día de un comprador o visitante que no tiene un
 * checkout_complete en las 24 horas siguientes a ese cart_add.
 */
class RecolectorTienda extends RecolectorBase
{
    /** Ventana de contexto en días (incluido el día del informe). */
    const DIAS_CONTEXTO = 7;

    /** Horas que se le dan a un carrito para convertirse antes de darlo por abandonado. */
    const HORAS_ABANDONO = 24;

    /** order_statuses.id de "Sin confirmar" (el que usa OrderController::indexUnconfirmed). */
    const ESTADO_SIN_CONFIRMAR_ID = 1;

    /** Nombre de cada valor del enum orders.status, para pedidos sin order_status_id. */
    const NOMBRES_STATUS = [
        'unconfirmed' => 'Sin confirmar',
        'confirmed'   => 'Confirmado',
        'finished'    => 'Terminado',
        'delivered'   => 'Entregado',
        'canceled'    => 'Cancelado',
    ];

    /**
     * @param User $owner
     * @param Carbon $fecha El día del que habla el informe (ayer, por defecto)
     * @return array
     */
    public function recolectar(User $owner, Carbon $fecha): array
    {
        if (!$this->tiene_tienda($owner)) {
            return $this->no_aplica($fecha, 'El comercio no tiene tienda online: sin URL en su configuración y sin pedidos.');
        }

        $inicio = $fecha->copy()->startOfDay();
        $fin    = $fecha->copy()->endOfDay();
        $desde_7 = $fecha->copy()->subDays(self::DIAS_CONTEXTO - 1)->startOfDay();

        $url = trim((string) $owner->online);

        return [
            'aplica'       => true,
            'fecha'        => $fecha->format('Y-m-d'),
            'url_tienda'   => $url !== '' ? $url : null,
            'pedidos'      => $this->pedidos($owner, $inicio, $fin, $desde_7),
            'compradores'  => $this->compradores($owner, $inicio, $fin),
            'productos'    => $this->productos($owner, $inicio, $fin),
            'carritos_abandonados' => $this->carritos_abandonados($owner, $inicio, $fin),
            'busquedas'    => $this->busquedas($owner, $inicio, $fin),
            'vieron_y_no_compraron' => $this->vieron_y_no_compraron($owner, $desde_7, $fin),
            'clientes_del_local_en_la_tienda' => $this->clientes_del_local($owner, $desde_7, $fin),
        ];
    }

    /**
     * Bloque `pedidos`: los del día (con apertura por estado), los pendientes de
     * confirmar en este momento y los de los últimos 7 días.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @param Carbon $desde_7
     * @return array
     */
    protected function pedidos(User $owner, Carbon $inicio, Carbon $fin, Carbon $desde_7): array
    {
        $del_dia = DB::table('orders')
            ->leftJoin('order_statuses', 'order_statuses.id', '=', 'orders.order_status_id')
            ->where('orders.user_id', $owner->id)
            ->whereBetween('orders.created_at', [$inicio, $fin])
            ->get(['orders.total', 'orders.status', 'order_statuses.name as estado']);

        $por_estado = [];

        foreach ($del_dia as $pedido) {
            $estado = trim((string) $pedido->estado);

            if ($estado === '') {
                $estado = isset(self::NOMBRES_STATUS[$pedido->status])
                    ? self::NOMBRES_STATUS[$pedido->status]
                    : (string) ($pedido->status ?: 'Sin estado');
            }

            if (!isset($por_estado[$estado])) {
                $por_estado[$estado] = ['estado' => $estado, 'cantidad' => 0];
            }

            $por_estado[$estado]['cantidad']++;
        }

        $pendientes = DB::table('orders')
            ->where('user_id', $owner->id)
            ->where('order_status_id', self::ESTADO_SIN_CONFIRMAR_ID)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as total, MIN(created_at) as mas_viejo')
            ->first();

        $ultimos_7 = DB::table('orders')
            ->where('user_id', $owner->id)
            ->whereBetween('created_at', [$desde_7, $fin])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as total')
            ->first();

        return [
            'ayer' => [
                'cantidad'   => $del_dia->count(),
                'total'      => $this->monto($del_dia->sum('total')),
                'por_estado' => array_values($por_estado),
            ],
            'pendientes_de_confirmar' => [
                'cantidad'        => (int) $pendientes->cantidad,
                'total'           => $this->monto($pendientes->total),
                'mas_viejo_horas' => empty($pendientes->mas_viejo)
                    ? null
                    : Carbon::parse($pendientes->mas_viejo)->diffInHours(now()),
            ],
            'ultimos_7_dias' => [
                'cantidad' => (int) $ultimos_7->cantidad,
                'total'    => $this->monto($ultimos_7->total),
            ],
        ];
    }

    /**
     * Bloque `compradores`: visitantes distintos del día (visitor_id), compradores
     * identificados con actividad y cuentas nuevas.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function compradores(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $fila = DB::table('buyer_tracking_events')
            ->where('user_id', $owner->id)
            ->whereBetween('occurred_at', [$inicio, $fin])
            ->selectRaw('COUNT(DISTINCT visitor_id) as visitantes, COUNT(DISTINCT buyer_id) as activos')
            ->first();

        $nuevos = DB::table('buyers')
            ->where('user_id', $owner->id)
            ->whereBetween('created_at', [$inicio, $fin])
            ->count();

        return [
            'visitantes_ayer' => (int) $fila->visitantes,
            'activos_ayer'    => (int) $fila->activos,
            'nuevos_ayer'     => (int) $nuevos,
        ];
    }

    /**
     * Bloque `productos`: los más vistos del día (con tiempo promedio en pantalla, stock
     * y precio actuales), los más agregados al carrito y los vistos que están en cero.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function productos(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $vistas = DB::table('buyer_tracking_events as e')
            ->join('articles as a', 'a.id', '=', 'e.article_id')
            ->where('e.user_id', $owner->id)
            ->where('e.event_type', 'product_view')
            ->whereBetween('e.occurred_at', [$inicio, $fin])
            ->groupBy('e.article_id', 'a.name', 'a.stock', 'a.final_price', 'a.price')
            ->selectRaw(
                'e.article_id as article_id, a.name as nombre, a.stock as stock,
                 COALESCE(a.final_price, a.price) as precio,
                 COUNT(*) as vistas, AVG(e.dwell_ms) as dwell_promedio'
            )
            ->orderByDesc('vistas')
            ->orderBy('e.article_id')
            ->limit(self::TOPE_LISTA)
            ->get();

        $imagenes = $this->imagenes_de($vistas->pluck('article_id')->all());
        $mas_vistos = [];

        foreach ($vistas as $fila) {
            $article_id = (int) $fila->article_id;

            $mas_vistos[] = [
                'article_id'          => $article_id,
                'nombre'              => (string) $fila->nombre,
                'vistas'              => (int) $fila->vistas,
                'tiempo_promedio_seg' => is_null($fila->dwell_promedio) ? null : (int) round($fila->dwell_promedio / 1000),
                'stock'               => (float) ($fila->stock ?: 0),
                'precio'              => $this->monto($fila->precio),
                'imagen_url'          => isset($imagenes[$article_id]) ? $imagenes[$article_id] : null,
            ];
        }

        $carrito = DB::table('buyer_tracking_events as e')
            ->join('articles as a', 'a.id', '=', 'e.article_id')
            ->where('e.user_id', $owner->id)
            ->where('e.event_type', 'cart_add')
            ->whereBetween('e.occurred_at', [$inicio, $fin])
            ->groupBy('e.article_id', 'a.name')
            ->selectRaw('e.article_id as article_id, a.name as nombre, COUNT(*) as veces')
            ->orderByDesc('veces')
            ->orderBy('e.article_id')
            ->limit(self::TOPE_LISTA)
            ->get();

        $mas_agregados = [];

        foreach ($carrito as $fila) {
            $mas_agregados[] = [
                'article_id' => (int) $fila->article_id,
                'nombre'     => (string) $fila->nombre,
                'veces'      => (int) $fila->veces,
            ];
        }

        // En cero = stock cargado y <= 0; con stock null el artículo no controla stock y la
        // tienda lo vende como disponible (RecolectorBase::en_cero).
        $sin_stock = $this->en_cero(DB::table('buyer_tracking_events as e'), 'a.stock')
            ->join('articles as a', 'a.id', '=', 'e.article_id')
            ->where('e.user_id', $owner->id)
            ->where('e.event_type', 'product_view')
            ->whereBetween('e.occurred_at', [$inicio, $fin])
            ->groupBy('e.article_id', 'a.name')
            ->selectRaw('e.article_id as article_id, a.name as nombre, COUNT(*) as vistas')
            ->orderByDesc('vistas')
            ->orderBy('e.article_id')
            ->limit(self::TOPE_LISTA)
            ->get();

        $vistos_sin_stock = [];

        foreach ($sin_stock as $fila) {
            $vistos_sin_stock[] = [
                'article_id' => (int) $fila->article_id,
                'nombre'     => (string) $fila->nombre,
                'vistas'     => (int) $fila->vistas,
            ];
        }

        return [
            'mas_vistos'               => $mas_vistos,
            'mas_agregados_al_carrito' => $mas_agregados,
            'vistos_sin_stock'         => $vistos_sin_stock,
        ];
    }

    /**
     * Carritos abandonados del día: cart_add de un comprador (o visitante anónimo) sin
     * checkout_complete en las 24 horas siguientes. Los compradores identificados van
     * primero (son los que se pueden contactar); el monto se estima con el precio
     * actual del artículo cuando el evento no trajo importe.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function carritos_abandonados(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $agregados = DB::table('buyer_tracking_events as e')
            ->leftJoin('articles as a', 'a.id', '=', 'e.article_id')
            ->where('e.user_id', $owner->id)
            ->where('e.event_type', 'cart_add')
            ->whereBetween('e.occurred_at', [$inicio, $fin])
            ->orderBy('e.occurred_at')
            ->get([
                'e.buyer_id', 'e.visitor_id', 'e.article_id', 'e.quantity', 'e.amount', 'e.occurred_at',
                'a.name as nombre', 'a.final_price', 'a.price',
            ]);

        if ($agregados->isEmpty()) {
            return [];
        }

        // Los checkouts que cierran un carrito: del mismo comprador o del mismo visitante,
        // dentro de las 24 horas después del último cart_add del día.
        $checkouts = DB::table('buyer_tracking_events')
            ->where('user_id', $owner->id)
            ->where('event_type', 'checkout_complete')
            ->whereBetween('occurred_at', [$inicio, $fin->copy()->addHours(self::HORAS_ABANDONO)])
            ->get(['buyer_id', 'visitor_id', 'occurred_at']);

        $grupos = [];

        foreach ($agregados as $evento) {
            $clave = $evento->buyer_id ? 'b' . $evento->buyer_id : 'v' . $evento->visitor_id;

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'buyer_id'   => $evento->buyer_id ? (int) $evento->buyer_id : null,
                    'visitor_id' => $evento->visitor_id,
                    'articulos'  => [],
                    'monto'      => 0.0,
                    'ultimo'     => $evento->occurred_at,
                ];
            }

            $cantidad = (int) ($evento->quantity ?: 1);
            $nombre = (string) ($evento->nombre ?: 'Artículo #' . $evento->article_id);

            if (!isset($grupos[$clave]['articulos'][$nombre])) {
                $grupos[$clave]['articulos'][$nombre] = 0;
            }

            $grupos[$clave]['articulos'][$nombre] += $cantidad;

            $precio = !is_null($evento->amount)
                ? (float) $evento->amount
                : (float) ($evento->final_price ?: $evento->price ?: 0) * $cantidad;

            $grupos[$clave]['monto'] += $precio;

            if ($evento->occurred_at > $grupos[$clave]['ultimo']) {
                $grupos[$clave]['ultimo'] = $evento->occurred_at;
            }
        }

        // Se descartan los carritos que sí convirtieron.
        foreach ($grupos as $clave => $grupo) {
            $limite = Carbon::parse($grupo['ultimo'])->addHours(self::HORAS_ABANDONO);

            foreach ($checkouts as $checkout) {
                $mismo = ($grupo['buyer_id'] && (int) $checkout->buyer_id === $grupo['buyer_id'])
                    || ($grupo['visitor_id'] && $checkout->visitor_id === $grupo['visitor_id']);

                if ($mismo && Carbon::parse($checkout->occurred_at)->lte($limite)) {
                    unset($grupos[$clave]);
                    break;
                }
            }
        }

        if (empty($grupos)) {
            return [];
        }

        $buyer_ids = [];

        foreach ($grupos as $grupo) {
            if ($grupo['buyer_id']) {
                $buyer_ids[] = $grupo['buyer_id'];
            }
        }

        $compradores = $this->datos_de_compradores($owner, $buyer_ids);

        $lista = [];

        foreach ($grupos as $grupo) {
            $articulos = [];

            foreach ($grupo['articulos'] as $nombre => $cantidad) {
                $articulos[] = ['nombre' => $nombre, 'cantidad' => $cantidad];
            }

            $comprador = $grupo['buyer_id'] && isset($compradores[$grupo['buyer_id']])
                ? $compradores[$grupo['buyer_id']]
                : null;

            $lista[] = [
                'buyer_id'       => $grupo['buyer_id'],
                'nombre'         => $comprador ? $comprador['nombre'] : null,
                'client_id'      => $comprador ? $comprador['client_id'] : null,
                'articulos'      => $articulos,
                'monto_estimado' => $this->monto($grupo['monto']),
                'hace_horas'     => Carbon::parse($grupo['ultimo'])->diffInHours(now()),
            ];
        }

        // Identificados primero, después por monto; usort no es estable: desempate por orden.
        foreach ($lista as $indice => $fila) {
            $lista[$indice]['_orden'] = $indice;
        }

        usort($lista, function ($a, $b) {
            $a_identificado = is_null($a['buyer_id']) ? 0 : 1;
            $b_identificado = is_null($b['buyer_id']) ? 0 : 1;

            if ($a_identificado !== $b_identificado) {
                return $b_identificado <=> $a_identificado;
            }

            if ($a['monto_estimado'] == $b['monto_estimado']) {
                return $a['_orden'] <=> $b['_orden'];
            }

            return $b['monto_estimado'] <=> $a['monto_estimado'];
        });

        $resultado = [];

        foreach (array_slice($lista, 0, self::TOPE_LISTA) as $fila) {
            unset($fila['_orden']);
            $resultado[] = $fila;
        }

        return $resultado;
    }

    /**
     * Bloque `busquedas`: los términos más buscados del día (con el promedio de
     * resultados) y los que no devolvieron nada.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function busquedas(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $base = function () use ($owner, $inicio, $fin) {
            return DB::table('buyer_tracking_events')
                ->where('user_id', $owner->id)
                ->where('event_type', 'search')
                ->whereNotNull('search_term')
                ->where('search_term', '!=', '')
                ->whereBetween('occurred_at', [$inicio, $fin]);
        };

        $mas_buscadas = [];

        $filas = $base()
            ->groupBy('search_term')
            ->selectRaw('search_term, COUNT(*) as veces, AVG(results_count) as resultados_promedio')
            ->orderByDesc('veces')
            ->orderBy('search_term')
            ->limit(self::TOPE_LISTA)
            ->get();

        foreach ($filas as $fila) {
            $mas_buscadas[] = [
                'termino'             => (string) $fila->search_term,
                'veces'               => (int) $fila->veces,
                'resultados_promedio' => is_null($fila->resultados_promedio) ? null : round((float) $fila->resultados_promedio, 1),
            ];
        }

        $sin_resultados = [];

        $filas = $base()
            ->where('results_count', 0)
            ->groupBy('search_term')
            ->selectRaw('search_term, COUNT(*) as veces')
            ->orderByDesc('veces')
            ->orderBy('search_term')
            ->limit(self::TOPE_LISTA)
            ->get();

        foreach ($filas as $fila) {
            $sin_resultados[] = [
                'termino' => (string) $fila->search_term,
                'veces'   => (int) $fila->veces,
            ];
        }

        return [
            'mas_buscadas'   => $mas_buscadas,
            'sin_resultados' => $sin_resultados,
        ];
    }

    /**
     * Compradores identificados que miraron un artículo en los últimos 7 días y no lo
     * compraron: sin un pedido de la tienda con ese artículo en la ventana y sin venta
     * del ERP (article_purchases del cliente asociado) posterior a la última vista.
     *
     * 🔴 "Compró en la tienda" se resuelve por el PEDIDO, no por el evento: el
     * checkout_complete que emite la tienda (tienda-spa, mixins/cart.js) trae order_id y
     * amount, NUNCA article_id, así que buscar el artículo en el evento no excluía a
     * nadie. Se toman los pedidos (orders, del dueño) que llegan por el order_id de un
     * checkout_complete del comprador en la ventana, más —por si el evento vino sin
     * order_id (API vieja de la tienda) o se perdió— los pedidos del propio buyer_id en
     * la ventana; y de ahí, los renglones (article_order) que contienen el artículo.
     *
     * @param User $owner
     * @param Carbon $desde
     * @param Carbon $hasta
     * @return array
     */
    protected function vieron_y_no_compraron(User $owner, Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('buyer_tracking_events as e')
            ->join('buyers as b', 'b.id', '=', 'e.buyer_id')
            ->join('articles as a', 'a.id', '=', 'e.article_id')
            ->where('e.user_id', $owner->id)
            ->where('e.event_type', 'product_view')
            ->whereBetween('e.occurred_at', [$desde, $hasta])
            ->groupBy('e.buyer_id', 'e.article_id', 'b.name', 'b.surname', 'b.comercio_city_client_id', 'a.name', 'a.final_price', 'a.price')
            ->selectRaw(
                'e.buyer_id as buyer_id, e.article_id as article_id,
                 b.name as nombre, b.surname as apellido, b.comercio_city_client_id as client_id,
                 a.name as nombre_articulo, COALESCE(a.final_price, a.price) as precio,
                 COUNT(*) as vistas, COALESCE(SUM(e.dwell_ms), 0) as dwell_total, MAX(e.occurred_at) as ultima_vista'
            )
            ->orderByDesc('vistas')
            ->orderByDesc('dwell_total')
            ->orderBy('e.buyer_id')
            ->limit(self::TOPE_LISTA * 3)
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $buyer_ids = $filas->pluck('buyer_id')->unique()->map(function ($id) {
            return (int) $id;
        })->all();

        $article_ids = $filas->pluck('article_id')->unique()->map(function ($id) {
            return (int) $id;
        })->all();

        $comprado_en_tienda = $this->comprado_en_la_tienda($owner, $buyer_ids, $article_ids, $desde);

        // Compras del ERP del cliente asociado, posteriores al inicio de la ventana.
        $client_ids = $filas->pluck('client_id')->filter()->unique()->map(function ($id) {
            return (int) $id;
        })->all();

        $comprado_en_local = [];

        if (!empty($client_ids)) {
            $compras = $this->ventas_reales_desde_article_purchases(DB::table('article_purchases'), $owner->id)
                ->whereIn('article_purchases.client_id', $client_ids)
                ->whereIn('article_purchases.article_id', $article_ids)
                ->where('article_purchases.created_at', '>=', $desde)
                ->groupBy('article_purchases.client_id', 'article_purchases.article_id')
                ->selectRaw('article_purchases.client_id as client_id, article_purchases.article_id as article_id, MAX(article_purchases.created_at) as ultima')
                ->get();

            foreach ($compras as $compra) {
                $comprado_en_local[(int) $compra->client_id . '-' . (int) $compra->article_id] = $compra->ultima;
            }
        }

        $lista = [];

        foreach ($filas as $fila) {
            $buyer_id   = (int) $fila->buyer_id;
            $article_id = (int) $fila->article_id;
            $client_id  = $fila->client_id ? (int) $fila->client_id : null;

            if (isset($comprado_en_tienda[$buyer_id . '-' . $article_id])) {
                continue;
            }

            if ($client_id && isset($comprado_en_local[$client_id . '-' . $article_id])
                && $comprado_en_local[$client_id . '-' . $article_id] >= $fila->ultima_vista) {
                continue;
            }

            $lista[] = [
                'buyer_id'        => $buyer_id,
                'nombre'          => trim((string) $fila->nombre . ' ' . (string) $fila->apellido),
                'article_id'      => $article_id,
                'nombre_articulo' => (string) $fila->nombre_articulo,
                'vistas'          => (int) $fila->vistas,
                'tiempo_seg'      => (int) round($fila->dwell_total / 1000),
                'precio'          => $this->monto($fila->precio),
            ];

            if (count($lista) >= self::TOPE_LISTA) {
                break;
            }
        }

        return $lista;
    }

    /**
     * Pares (buyer_id, article_id) que el comprador compró en la tienda en la ventana:
     * los pedidos que llegan por el order_id de sus checkout_complete más los pedidos
     * del propio buyer_id, con sus renglones de article_order (ver vieron_y_no_compraron).
     *
     * @param User $owner
     * @param array $buyer_ids
     * @param array $article_ids
     * @param Carbon $desde
     * @return array Mapa "buyer_id-article_id" => true
     */
    protected function comprado_en_la_tienda(User $owner, array $buyer_ids, array $article_ids, Carbon $desde): array
    {
        $mapa = [];

        if (empty($buyer_ids) || empty($article_ids)) {
            return $mapa;
        }

        $checkouts = DB::table('buyer_tracking_events')
            ->where('user_id', $owner->id)
            ->where('event_type', 'checkout_complete')
            ->whereIn('buyer_id', $buyer_ids)
            ->whereNotNull('order_id')
            ->where('occurred_at', '>=', $desde)
            ->get(['buyer_id', 'order_id']);

        // El comprador de cada pedido: el del evento que lo trajo, o el del pedido mismo.
        $buyer_por_pedido = [];

        foreach ($checkouts as $checkout) {
            $buyer_por_pedido[(int) $checkout->order_id] = (int) $checkout->buyer_id;
        }

        $pedidos = DB::table('orders')
            ->where('user_id', $owner->id)
            ->where('created_at', '>=', $desde)
            ->where(function ($q) use ($buyer_por_pedido, $buyer_ids) {
                $q->whereIn('buyer_id', $buyer_ids);

                if (!empty($buyer_por_pedido)) {
                    $q->orWhereIn('id', array_keys($buyer_por_pedido));
                }
            })
            ->get(['id', 'buyer_id']);

        if ($pedidos->isEmpty()) {
            return $mapa;
        }

        foreach ($pedidos as $pedido) {
            if (!isset($buyer_por_pedido[(int) $pedido->id]) && $pedido->buyer_id) {
                $buyer_por_pedido[(int) $pedido->id] = (int) $pedido->buyer_id;
            }
        }

        $renglones = DB::table('article_order')
            ->whereIn('order_id', $pedidos->pluck('id')->all())
            ->whereIn('article_id', $article_ids)
            ->get(['order_id', 'article_id']);

        foreach ($renglones as $renglon) {
            $order_id = (int) $renglon->order_id;

            if (isset($buyer_por_pedido[$order_id])) {
                $mapa[$buyer_por_pedido[$order_id] . '-' . (int) $renglon->article_id] = true;
            }
        }

        return $mapa;
    }

    /**
     * Compradores de la tienda asociados a un cliente del ERP con actividad en los
     * últimos 7 días: su deuda en cuenta corriente (credit_accounts en pesos, la fuente
     * de verdad: clients.saldo es una columna muerta) y su última compra en el local.
     *
     * @param User $owner
     * @param Carbon $desde
     * @param Carbon $hasta
     * @return array
     */
    protected function clientes_del_local(User $owner, Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('buyer_tracking_events as e')
            ->join('buyers as b', 'b.id', '=', 'e.buyer_id')
            ->join('clients as c', 'c.id', '=', 'b.comercio_city_client_id')
            ->where('e.user_id', $owner->id)
            ->whereBetween('e.occurred_at', [$desde, $hasta])
            ->whereNotNull('b.comercio_city_client_id')
            ->groupBy('e.buyer_id', 'b.comercio_city_client_id', 'c.name')
            ->selectRaw('e.buyer_id as buyer_id, b.comercio_city_client_id as client_id, c.name as nombre, MAX(e.occurred_at) as ultima_actividad')
            ->orderByDesc('ultima_actividad')
            ->orderBy('e.buyer_id')
            ->limit(self::TOPE_LISTA)
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $client_ids = $filas->pluck('client_id')->map(function ($id) {
            return (int) $id;
        })->all();

        $deudas = $this->deudas_en_pesos($owner, 'client', $client_ids);

        $ultimas_compras = DB::table('sales')
            ->where('user_id', $owner->id)
            ->whereNull('deleted_at')
            ->whereIn('client_id', $client_ids)
            ->groupBy('client_id')
            ->selectRaw('client_id, MAX(created_at) as ultima')
            ->get();

        $ultima_por_cliente = [];

        foreach ($ultimas_compras as $compra) {
            $ultima_por_cliente[(int) $compra->client_id] = $this->fecha_ymd($compra->ultima);
        }

        $lista = [];

        foreach ($filas as $fila) {
            $client_id = (int) $fila->client_id;

            $lista[] = [
                'buyer_id'            => (int) $fila->buyer_id,
                'client_id'           => $client_id,
                'nombre'              => (string) $fila->nombre,
                'deuda'               => $this->monto(isset($deudas[$client_id]) ? $deudas[$client_id] : 0.0),
                'ultima_compra_local' => isset($ultima_por_cliente[$client_id]) ? $ultima_por_cliente[$client_id] : null,
            ];
        }

        return $lista;
    }

    /**
     * Nombre y cliente asociado de un lote de compradores.
     *
     * @param User $owner
     * @param array $buyer_ids
     * @return array Mapa buyer_id => ['nombre' => string, 'client_id' => int|null]
     */
    protected function datos_de_compradores(User $owner, array $buyer_ids): array
    {
        $mapa = [];

        $buyer_ids = array_values(array_unique(array_filter(array_map('intval', $buyer_ids))));

        if (empty($buyer_ids)) {
            return $mapa;
        }

        $filas = DB::table('buyers')
            ->where('user_id', $owner->id)
            ->whereIn('id', $buyer_ids)
            ->get(['id', 'name', 'surname', 'comercio_city_client_id']);

        foreach ($filas as $fila) {
            $mapa[(int) $fila->id] = [
                'nombre'    => trim((string) $fila->name . ' ' . (string) $fila->surname),
                'client_id' => $fila->comercio_city_client_id ? (int) $fila->comercio_city_client_id : null,
            ];
        }

        return $mapa;
    }
}
