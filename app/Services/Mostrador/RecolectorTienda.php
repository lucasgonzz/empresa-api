<?php

namespace App\Services\Mostrador;

use App\Http\Controllers\Helpers\UserHelper;
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
 * Carrito abandonado = lo que un comprador o visitante agregó al carrito en el día y
 * no quitó (cart_remove) ni compró (checkout_complete posterior al agregado, dentro de
 * las 24 horas siguientes). Ver carritos_abandonados().
 *
 * Los bloques que salen del tracking de comportamiento (compradores, productos,
 * carritos abandonados, búsquedas, vieron y no compraron) EXISTEN SOLO si el comercio
 * tiene la extensión `tracking_buyers` (el interruptor con el que la tienda escribe
 * buyer_tracking_events; el mismo gate que mira el Kernel para agregar y purgar). Sin
 * ella no hay eventos, y viajan null —no ceros— para que la redacción no diga "nadie
 * visitó la tienda" cuando lo que pasa es que no se mide. Pedidos y clientes del local
 * salen de orders y siguen andando igual.
 */
class RecolectorTienda extends RecolectorBase
{
    /** Ventana de contexto en días (incluido el día del informe). */
    const DIAS_CONTEXTO = 7;

    /** Horas que se le dan a un carrito para convertirse antes de darlo por abandonado. */
    const HORAS_ABANDONO = 24;

    /** order_statuses.id de "Sin confirmar" (el que usa OrderController::indexUnconfirmed). */
    const ESTADO_SIN_CONFIRMAR_ID = 1;

    /** Extensión con la que la tienda registra buyer_tracking_events (BuyerTrackingHelper de tienda-api). */
    const EXTENSION_TRACKING = 'tracking_buyers';

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
        $tracking = $this->tracking_activo($owner);

        return [
            'aplica'          => true,
            'fecha'           => $fecha->format('Y-m-d'),
            'url_tienda'      => $url !== '' ? $url : null,
            'tracking_activo' => $tracking,
            'pedidos'         => $this->pedidos($owner, $inicio, $fin, $desde_7),
            'compradores'     => $tracking ? $this->compradores($owner, $inicio, $fin) : null,
            'productos'       => $tracking ? $this->productos($owner, $inicio, $fin) : null,
            'carritos_abandonados'  => $tracking ? $this->carritos_abandonados($owner, $inicio, $fin) : null,
            'busquedas'             => $tracking ? $this->busquedas($owner, $inicio, $fin) : null,
            'vieron_y_no_compraron' => $tracking ? $this->vieron_y_no_compraron($owner, $desde_7, $fin) : null,
            'clientes_del_local_en_la_tienda' => $this->clientes_del_local($owner, $desde_7, $fin, $tracking),
        ];
    }

    /**
     * true si el comercio tiene la extensión tracking_buyers (la misma condición con la
     * que la tienda escribe los eventos y el Kernel los agrega y purga).
     *
     * @param User $owner
     * @return bool
     */
    protected function tracking_activo(User $owner): bool
    {
        return UserHelper::hasExtencion(self::EXTENSION_TRACKING, $owner);
    }

    /**
     * Bloque `pedidos`: los del día (con apertura por estado), los pendientes de
     * confirmar en este momento y los de los últimos 7 días. Los cancelados no cuentan
     * en ninguno de los tres (RecolectorBase::sin_pedidos_cancelados).
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @param Carbon $desde_7
     * @return array
     */
    protected function pedidos(User $owner, Carbon $inicio, Carbon $fin, Carbon $desde_7): array
    {
        $del_dia = $this->sin_pedidos_cancelados(DB::table('orders'))
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

        $pendientes = $this->sin_pedidos_cancelados(DB::table('orders'))
            ->where('orders.user_id', $owner->id)
            ->where('orders.order_status_id', self::ESTADO_SIN_CONFIRMAR_ID)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(orders.total), 0) as total, MIN(orders.created_at) as mas_viejo')
            ->first();

        $ultimos_7 = $this->sin_pedidos_cancelados(DB::table('orders'))
            ->where('orders.user_id', $owner->id)
            ->whereBetween('orders.created_at', [$desde_7, $fin])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(orders.total), 0) as total')
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
        // Todos los joins a articles / buyers / clients llevan también el user_id del
        // dueño: defensa en profundidad en la base compartida por varios comercios, por
        // si un evento trajera un article_id ajeno.
        $vistas = DB::table('buyer_tracking_events as e')
            ->join('articles as a', 'a.id', '=', 'e.article_id')
            ->where('a.user_id', $owner->id)
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
            ->where('a.user_id', $owner->id)
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
            ->where('a.user_id', $owner->id)
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
     * Carritos abandonados del día: lo que cada comprador (o visitante anónimo) agregó
     * al carrito en el día y todavía tiene adentro. Los eventos del carrito se recorren
     * EN ORDEN DE OCURRENCIA —los cart_add del día y los cart_remove y checkout_complete
     * hasta 24 horas después del día— y cada uno mueve un carrito en memoria:
     *
     *   - cart_add suma la cantidad del artículo (con su precio: amount / quantity si el
     *     evento trajo importe, si no el precio actual del artículo);
     *   - cart_remove resta la cantidad quitada (sin cantidad, la línea entera); un
     *     carrito que quedó vacío no está abandonado;
     *   - checkout_complete POSTERIOR a los agregados, y dentro de las 24 horas del
     *     último, convierte el carrito: lo que había hasta ahí ya no está abandonado. Un
     *     checkout anterior al primer cart_add del día (el de un carrito previo) no cierra
     *     nada: lo agregado después es un carrito nuevo.
     *
     * El comprador y el visitante se cruzan en los dos sentidos: un checkout con buyer_id
     * cierra también el carrito anónimo del mismo visitor_id (agregó sin loguearse y
     * compró logueado). Los compradores identificados van primero (son los que se pueden
     * contactar). `ventana_abierta` dice si el último agregado tiene menos de 24 horas:
     * ahí el carrito todavía puede convertirse y la redacción no lo da por abandonado.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function carritos_abandonados(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $hasta = $fin->copy()->addHours(self::HORAS_ABANDONO);

        $eventos = DB::table('buyer_tracking_events as e')
            ->leftJoin('articles as a', function ($join) use ($owner) {
                $join->on('a.id', '=', 'e.article_id')->where('a.user_id', $owner->id);
            })
            ->where('e.user_id', $owner->id)
            ->where(function ($q) use ($inicio, $fin, $hasta) {
                $q->where(function ($q2) use ($inicio, $fin) {
                    $q2->where('e.event_type', 'cart_add')
                        ->whereBetween('e.occurred_at', [$inicio, $fin]);
                })->orWhere(function ($q2) use ($inicio, $hasta) {
                    $q2->whereIn('e.event_type', ['cart_remove', 'checkout_complete'])
                        ->whereBetween('e.occurred_at', [$inicio, $hasta]);
                });
            })
            ->orderBy('e.occurred_at')
            ->orderBy('e.id')
            ->get([
                'e.event_type', 'e.buyer_id', 'e.visitor_id', 'e.article_id', 'e.quantity', 'e.amount', 'e.occurred_at',
                'a.name as nombre', 'a.final_price', 'a.price',
            ]);

        if ($eventos->isEmpty()) {
            return [];
        }

        $grupos = [];

        foreach ($eventos as $evento) {
            $claves = $this->claves_de_carrito($evento);

            if ($evento->event_type === 'checkout_complete') {
                foreach ($claves as $clave) {
                    if (isset($grupos[$clave])
                        && Carbon::parse($evento->occurred_at)->lte(Carbon::parse($grupos[$clave]['ultimo'])->addHours(self::HORAS_ABANDONO))) {
                        unset($grupos[$clave]);
                    }
                }

                continue;
            }

            $article_id = (int) $evento->article_id;

            if ($article_id <= 0) {
                continue;
            }

            if ($evento->event_type === 'cart_remove') {
                foreach ($claves as $clave) {
                    if (!isset($grupos[$clave]['articulos'][$article_id])) {
                        continue;
                    }

                    // Sin cantidad en el evento se quitó la línea entera.
                    $quitado = is_null($evento->quantity) ? PHP_INT_MAX : (int) $evento->quantity;
                    $grupos[$clave]['articulos'][$article_id]['cantidad'] -= $quitado;

                    if ($grupos[$clave]['articulos'][$article_id]['cantidad'] <= 0) {
                        unset($grupos[$clave]['articulos'][$article_id]);
                    }

                    if (empty($grupos[$clave]['articulos'])) {
                        unset($grupos[$clave]);
                    }
                }

                continue;
            }

            // cart_add: va al carrito del comprador si está identificado, si no al del visitante.
            $clave = $claves[0];

            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'buyer_id'   => $evento->buyer_id ? (int) $evento->buyer_id : null,
                    'visitor_id' => $evento->visitor_id,
                    'articulos'  => [],
                    'ultimo'     => $evento->occurred_at,
                ];
            }

            $cantidad = (int) ($evento->quantity ?: 1);

            $precio_unitario = !is_null($evento->amount) && $cantidad > 0
                ? (float) $evento->amount / $cantidad
                : (float) ($evento->final_price ?: $evento->price ?: 0);

            if (!isset($grupos[$clave]['articulos'][$article_id])) {
                $grupos[$clave]['articulos'][$article_id] = [
                    'nombre'   => (string) ($evento->nombre ?: 'Artículo #' . $article_id),
                    'cantidad' => 0,
                    'precio'   => $precio_unitario,
                ];
            }

            $grupos[$clave]['articulos'][$article_id]['cantidad'] += $cantidad;
            $grupos[$clave]['articulos'][$article_id]['precio'] = $precio_unitario;

            if ($evento->occurred_at > $grupos[$clave]['ultimo']) {
                $grupos[$clave]['ultimo'] = $evento->occurred_at;
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
            $monto = 0.0;

            foreach ($grupo['articulos'] as $articulo) {
                $articulos[] = ['nombre' => $articulo['nombre'], 'cantidad' => $articulo['cantidad']];
                $monto += $articulo['cantidad'] * $articulo['precio'];
            }

            $comprador = $grupo['buyer_id'] && isset($compradores[$grupo['buyer_id']])
                ? $compradores[$grupo['buyer_id']]
                : null;

            $ultimo = Carbon::parse($grupo['ultimo']);

            $lista[] = [
                'buyer_id'        => $grupo['buyer_id'],
                'nombre'          => $comprador ? $comprador['nombre'] : null,
                'client_id'       => $comprador ? $comprador['client_id'] : null,
                'articulos'       => $articulos,
                'monto_estimado'  => $this->monto($monto),
                'hace_horas'      => $ultimo->diffInHours(now()),
                'ventana_abierta' => $ultimo->copy()->addHours(self::HORAS_ABANDONO)->isFuture(),
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
     * Las claves de carrito que toca un evento: la del comprador identificado primero
     * (si lo hay) y la del visitante. Un cart_add va a la primera; un cart_remove o un
     * checkout_complete actúan sobre las dos (el que agregó anónimo y compró logueado).
     *
     * @param object $evento
     * @return array
     */
    protected function claves_de_carrito($evento): array
    {
        $claves = [];

        if ($evento->buyer_id) {
            $claves[] = 'b' . (int) $evento->buyer_id;
        }

        if (!empty($evento->visitor_id)) {
            $claves[] = 'v' . $evento->visitor_id;
        }

        if (empty($claves)) {
            $claves[] = 'sin-identidad';
        }

        return $claves;
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
            ->where('b.user_id', $owner->id)
            ->where('a.user_id', $owner->id)
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
     * "Actividad" son los pedidos de la ventana (orders.buyer_id) y, si el comercio mide
     * el tracking, también los eventos de buyer_tracking_events: así el bloque sigue
     * andando —con lo que hay— en una tienda sin la extensión tracking_buyers.
     *
     * @param User $owner
     * @param Carbon $desde
     * @param Carbon $hasta
     * @param bool $tracking Si el comercio tiene la extensión tracking_buyers
     * @return array
     */
    protected function clientes_del_local(User $owner, Carbon $desde, Carbon $hasta, bool $tracking): array
    {
        // Última actividad por comprador: pedidos siempre, eventos si hay tracking.
        $ultima_actividad = [];

        $pedidos = $this->sin_pedidos_cancelados(DB::table('orders'))
            ->where('orders.user_id', $owner->id)
            ->whereNotNull('orders.buyer_id')
            ->whereBetween('orders.created_at', [$desde, $hasta])
            ->groupBy('orders.buyer_id')
            ->selectRaw('orders.buyer_id as buyer_id, MAX(orders.created_at) as ultima')
            ->get();

        foreach ($pedidos as $pedido) {
            $ultima_actividad[(int) $pedido->buyer_id] = (string) $pedido->ultima;
        }

        if ($tracking) {
            $eventos = DB::table('buyer_tracking_events')
                ->where('user_id', $owner->id)
                ->whereNotNull('buyer_id')
                ->whereBetween('occurred_at', [$desde, $hasta])
                ->groupBy('buyer_id')
                ->selectRaw('buyer_id, MAX(occurred_at) as ultima')
                ->get();

            foreach ($eventos as $evento) {
                $buyer_id = (int) $evento->buyer_id;

                if (!isset($ultima_actividad[$buyer_id]) || (string) $evento->ultima > $ultima_actividad[$buyer_id]) {
                    $ultima_actividad[$buyer_id] = (string) $evento->ultima;
                }
            }
        }

        if (empty($ultima_actividad)) {
            return [];
        }

        $filas = DB::table('buyers as b')
            ->join('clients as c', 'c.id', '=', 'b.comercio_city_client_id')
            ->where('b.user_id', $owner->id)
            ->where('c.user_id', $owner->id)
            ->whereIn('b.id', array_keys($ultima_actividad))
            ->whereNotNull('b.comercio_city_client_id')
            ->get(['b.id as buyer_id', 'b.comercio_city_client_id as client_id', 'c.name as nombre']);

        if ($filas->isEmpty()) {
            return [];
        }

        // Los de actividad más reciente primero; desempate por buyer_id.
        $filas = $filas->sort(function ($a, $b) use ($ultima_actividad) {
            $ua = $ultima_actividad[(int) $a->buyer_id];
            $ub = $ultima_actividad[(int) $b->buyer_id];

            if ($ua !== $ub) {
                return strcmp($ub, $ua);
            }

            return (int) $a->buyer_id <=> (int) $b->buyer_id;
        })->values()->take(self::TOPE_LISTA);

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
