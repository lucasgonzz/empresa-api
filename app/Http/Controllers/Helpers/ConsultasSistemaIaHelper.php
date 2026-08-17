<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Services\ActividadDeClientes\ActividadDeClientesService;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de LECTURA sobre los datos del negocio, compartidas entre el
 * endpoint AdminSync (SistemaQueryController, canal "sistema:" de WhatsApp)
 * y las tools del asistente de IA (AsistenteIaService).
 *
 * Extraídas del controller (misión chat-ia-y-modulo-ia) para no duplicar las
 * queries: acá vive la consulta, y cada consumidor le pone su cáscara. El
 * shape de salida de cada método es EXACTAMENTE el que el endpoint devolvía
 * (mismas claves, mismos tipos), con una sola excepción declarada: clientes()
 * suma la clave `id`, que la tool de movimientos del chat necesita para
 * encadenar consultas; el controller la quita antes de responder para
 * conservar su contrato byte a byte con admin-api.
 *
 * Todos los métodos filtran por el user_id del DUEÑO y respetan MAX_RESULTS:
 * el que llama es responsable de resolver el owner (nunca se lee Auth acá,
 * porque el chat consulta desde un job sin sesión).
 */
class ConsultasSistemaIaHelper
{
    /**
     * Cantidad máxima de registros que se devuelven por consulta. El mismo
     * tope que siempre tuvo SistemaQueryController: acota el JSON que viaja
     * al prompt de Claude, no el negocio.
     *
     * @var int
     */
    const MAX_RESULTS = 20;

    /**
     * Artículos activos del dueño con precio y stock, filtrados por nombre,
     * código de barras o código de proveedor.
     *
     * @param  int     $owner_id  Id del dueño (articles.user_id).
     * @param  string  $busqueda  Texto ya depurado a buscar; vacío trae los primeros sin filtrar.
     * @return array<int, array<string, mixed>>
     */
    public static function stock_de_articulos(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);

        $articles_query = Article::query()
            ->where('user_id', $owner_id)
            ->where('status', 'active');

        // Si hay una palabra clave útil, se filtra por nombre / código de barras / código de proveedor.
        if ($busqueda !== '') {
            $articles_query->where(function ($sub) use ($busqueda) {
                $sub->where('name', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $busqueda . '%');
            });
        }

        $articles = $articles_query
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'bar_code', 'provider_code', 'price', 'final_price', 'stock']);

        // Aplanamos a un array simple y legible para Claude.
        $result = [];
        foreach ($articles as $article) {
            $result[] = [
                'id'            => (int) $article->id,
                'nombre'        => (string) $article->name,
                'codigo'        => (string) ($article->bar_code ?? $article->provider_code ?? ''),
                'precio'        => $article->final_price !== null ? (float) $article->final_price : (float) $article->price,
                'stock'         => $article->stock !== null ? (float) $article->stock : 0,
            ];
        }

        return $result;
    }

    /**
     * Clientes del dueño con teléfono, email y saldo de cuenta corriente
     * (saldo positivo = el cliente debe), filtrados por nombre.
     *
     * A diferencia del resto, incluye `id`: la tool de movimientos del chat
     * lo necesita para encadenar. El endpoint AdminSync lo quita al responder.
     *
     * @param  int     $owner_id  Id del dueño (clients.user_id).
     * @param  string  $busqueda  Nombre o parte del nombre; vacío trae los primeros.
     * @return array<int, array<string, mixed>>
     */
    public static function clientes(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);

        $clients_query = Client::query()->where('user_id', $owner_id);

        if ($busqueda !== '') {
            $clients_query->where('name', 'LIKE', '%' . $busqueda . '%');
        }

        $clients = $clients_query
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'phone', 'email', 'saldo']);

        $result = [];
        foreach ($clients as $client) {
            $result[] = [
                'id'        => (int) $client->id,
                'cliente'   => (string) $client->name,
                'telefono'  => (string) ($client->phone ?? ''),
                'email'     => (string) ($client->email ?? ''),
                'saldo'     => $client->saldo !== null ? (float) $client->saldo : 0,
            ];
        }

        return $result;
    }

    /**
     * Últimos movimientos de cuenta corriente de UN cliente del dueño:
     * fecha, detalle, debe, haber y saldo, del más nuevo al más viejo.
     *
     * Query nueva de la misión chat-ia-y-modulo-ia (el endpoint AdminSync no
     * la tenía): current_acounts no maneja soft deletes, así que no hay
     * filtro de borrados que aplicar.
     *
     * @param  int  $owner_id   Id del dueño (current_acounts.user_id).
     * @param  int  $client_id  Id del cliente (current_acounts.client_id).
     * @return array<int, array<string, mixed>>
     */
    public static function movimientos_de_cuenta_corriente(int $owner_id, int $client_id): array
    {
        $movimientos = CurrentAcount::query()
            ->where('user_id', $owner_id)
            ->where('client_id', $client_id)
            ->orderBy('created_at', 'DESC')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'detalle', 'description', 'debe', 'haber', 'saldo', 'created_at']);

        $result = [];
        foreach ($movimientos as $movimiento) {
            // detalle es el campo principal; description queda de respaldo para filas viejas.
            $detalle = trim((string) ($movimiento->detalle ?? ''));
            if ($detalle === '') {
                $detalle = trim((string) ($movimiento->description ?? ''));
            }

            $result[] = [
                'fecha'   => $movimiento->created_at ? $movimiento->created_at->format('d/m/Y H:i') : '',
                'detalle' => $detalle,
                'debe'    => $movimiento->debe !== null ? (float) $movimiento->debe : 0,
                'haber'   => $movimiento->haber !== null ? (float) $movimiento->haber : 0,
                'saldo'   => $movimiento->saldo !== null ? (float) $movimiento->saldo : 0,
            ];
        }

        return $result;
    }

    /**
     * Artículos más vendidos del dueño en los últimos N días, con las
     * unidades vendidas. Excluye ventas borradas (soft delete de sales).
     *
     * Los ítems de venta viven en la tabla pivot article_purchases
     * (article_id, sale_id, amount); se agrupan por artículo sumando cantidades.
     *
     * @param  int  $owner_id  Id del dueño (articles.user_id / sales.user_id).
     * @param  int  $dias      Ventana de días hacia atrás.
     * @return array<int, array<string, mixed>>
     */
    public static function mas_vendidos(int $owner_id, int $dias = 30): array
    {
        $top = DB::table('article_purchases')
            ->join('articles', 'article_purchases.article_id', '=', 'articles.id')
            ->join('sales', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('articles.user_id', $owner_id)
            ->where('article_purchases.created_at', '>=', now()->subDays($dias))
            ->whereNull('sales.deleted_at')
            ->select('articles.name as nombre', DB::raw('SUM(article_purchases.amount) as total_vendido'))
            ->groupBy('articles.id', 'articles.name')
            ->orderByDesc('total_vendido')
            ->limit(self::MAX_RESULTS)
            ->get();

        $result = [];
        foreach ($top as $row) {
            $result[] = [
                'nombre'        => (string) $row->nombre,
                'total_vendido' => (float) $row->total_vendido,
            ];
        }

        return $result;
    }

    /**
     * Clientes del dueño con saldo pendiente de cobro (deuda en cuenta
     * corriente), ordenados por deuda descendente.
     *
     * @param  int  $owner_id  Id del dueño (clients.user_id).
     * @return array<int, array<string, mixed>>
     */
    public static function clientes_con_saldo_pendiente(int $owner_id): array
    {
        // clients.saldo positivo = el cliente debe dinero (pendiente de cobro).
        $clients = Client::query()
            ->where('user_id', $owner_id)
            ->where('saldo', '>', 0)
            ->orderByDesc('saldo')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'phone', 'saldo']);

        $result = [];
        foreach ($clients as $client) {
            $result[] = [
                'cliente'           => (string) $client->name,
                'telefono'          => (string) ($client->phone ?? ''),
                'saldo_pendiente'   => (float) $client->saldo,
            ];
        }

        return $result;
    }

    /**
     * Última oferta vigente por (artículo, proveedor) que coincida con la
     * búsqueda (nombre de artículo o de proveedor), la más reciente primero.
     * Tool de lectura del chat de IA (misión sugerencias de compra): la IA
     * la usa para responder a cuánto ofrece un proveedor un artículo, desde
     * el histórico real (provider_price_offers), nunca inventado.
     *
     * @param  int     $owner_id  Id del dueño. Nunca Auth: esta tool corre en un job sin sesión.
     * @param  string  $busqueda  Nombre de artículo o de proveedor; vacío trae lo más reciente.
     * @return array<int, array<string, mixed>>
     */
    public static function precios_de_proveedores(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);

        $query = DB::table('provider_price_offers')
            ->join('articles', 'articles.id', '=', 'provider_price_offers.article_id')
            ->join('providers', 'providers.id', '=', 'provider_price_offers.provider_id')
            ->where('provider_price_offers.user_id', $owner_id);

        if ($busqueda !== '') {
            $query->where(function ($sub) use ($busqueda) {
                $sub->where('articles.name', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('providers.name', 'LIKE', '%' . $busqueda . '%');
            });
        }

        // Orden global por fecha (no agrupado por par primero) para que el
        // tope no se gaste entero en el historial de un solo par.
        $filas = $query->orderBy('provider_price_offers.fecha', 'DESC')
            ->orderBy('provider_price_offers.id', 'DESC')
            ->limit(self::MAX_RESULTS)
            ->get(['provider_price_offers.article_id', 'provider_price_offers.provider_id',
                'provider_price_offers.cost', 'provider_price_offers.fecha', 'provider_price_offers.origen',
                'articles.name as articulo_nombre', 'providers.name as proveedor_nombre']);

        // Una sola fila por par (artículo, proveedor): la primera que aparece es la más nueva por el ORDER BY de arriba.
        $vistos = [];
        $result = [];

        foreach ($filas as $fila) {
            $clave = $fila->article_id . '-' . $fila->provider_id;

            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;

            $result[] = [
                'articulo'  => (string) $fila->articulo_nombre,
                'proveedor' => (string) $fila->proveedor_nombre,
                'costo'     => (float) $fila->cost,
                'fecha'     => (string) $fila->fecha,
                'origen'    => (string) $fila->origen,
            ];
        }

        return $result;
    }

    /**
     * Ofertas personalizadas VIGENTES hoy: qué descuento tiene cada cliente
     * sobre cada artículo en la tienda. Tool de lectura del chat de IA (misión
     * motor de ofertas): la IA responde "qué le estoy ofreciendo a Fulano"
     * desde client_offers, nunca inventado.
     *
     * 🔴 La vigencia la da la FECHA, no solo `estado`. Una oferta cuyo `hasta`
     * ya pasó sigue con estado 'activa' hasta que el barrido de higiene de
     * ofertas:generar la marque 'vencida' (puede tardar hasta un día). Filtrar
     * solo por estado le haría contar al chat ofertas que la tienda ya no
     * aplica — es exactamente la misma condición que corre la tienda (query
     * textual en el docblock de 2026_08_17_100200_create_client_offers_table).
     *
     * Los tramos por cantidad NO se traen: `porcentaje` es null en ese caso
     * (por diseño, para que nadie lea el campo equivocado) y se informa el tipo
     * de descuento, que es lo que la IA necesita para contestar sin mentir un
     * número que depende de cuántas unidades lleve el cliente.
     *
     * @param  int     $owner_id  Id del dueño (client_offers.user_id). Nunca Auth: corre en un job sin sesión.
     * @param  string  $busqueda  Nombre de cliente o de artículo; vacío trae las que vencen primero.
     * @return array<int, array<string, mixed>>
     */
    public static function ofertas_activas(int $owner_id, string $busqueda): array
    {
        $busqueda = trim($busqueda);
        $hoy = date('Y-m-d');

        $query = DB::table('client_offers')
            ->join('clients', 'clients.id', '=', 'client_offers.client_id')
            ->join('articles', 'articles.id', '=', 'client_offers.article_id')
            ->where('client_offers.user_id', $owner_id)
            ->where('client_offers.estado', 'activa')
            ->where('client_offers.desde', '<=', $hoy)
            ->where('client_offers.hasta', '>=', $hoy);

        if ($busqueda !== '') {
            $query->where(function ($sub) use ($busqueda) {
                $sub->where('clients.name', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('articles.name', 'LIKE', '%' . $busqueda . '%');
            });
        }

        // Primero las que se vencen antes: es lo urgente y lo que el
        // comerciante suele estar preguntando.
        $filas = $query->orderBy('client_offers.hasta', 'ASC')
            ->orderBy('client_offers.id', 'ASC')
            ->limit(self::MAX_RESULTS)
            ->get(['client_offers.id', 'client_offers.tipo_descuento', 'client_offers.porcentaje',
                'client_offers.desde', 'client_offers.hasta', 'client_offers.notificada_email_at',
                'clients.name as cliente_nombre', 'articles.name as articulo_nombre']);

        $result = [];

        foreach ($filas as $fila) {
            $result[] = [
                'cliente'        => (string) $fila->cliente_nombre,
                'articulo'       => (string) $fila->articulo_nombre,
                'tipo_descuento' => (string) $fila->tipo_descuento,
                // null real cuando es 'cantidad': el descuento vive en los tramos.
                'porcentaje'     => $fila->porcentaje !== null ? (float) $fila->porcentaje : null,
                'desde'          => (string) $fila->desde,
                'hasta'          => (string) $fila->hasta,
                'notificada'     => !is_null($fila->notificada_email_at),
            ];
        }

        return $result;
    }

    /**
     * Qué estuvo haciendo un cliente en la tienda online: qué artículos miró y cuánto tiempo, qué
     * buscó (marcando lo que buscó y NO encontró), qué puso en el carrito y qué cerró. Tool de
     * lectura del chat de IA (misión actividad-de-clientes-y-oferta-por-whatsapp).
     *
     * El dato sale del tracking de la tienda (`buyer_tracking_events`) a través de
     * ActividadDeClientesService, que es quien sabe de qué tabla leer según la antigüedad pedida y
     * quien SUMA los varios compradores que un mismo cliente del ERP puede tener
     * (`buyers.comercio_city_client_id`, mismo criterio que CriteriosDeOfertaService:408-412). Acá
     * no se toca el tracking por afuera de ese servicio: una segunda forma de leer las mismas
     * tablas es una segunda verdad, y el día que una cambie las dos van a contestar distinto sin
     * que nada lo denuncie.
     *
     * 🔴 Todas las filas llevan SIEMPRE las mismas siete claves, con null donde no aplica. Un JSON
     * con filas de forma distinta según el tipo obliga a Claude a adivinar el shape, y adivina mal.
     *
     * 🔴 `ultima_vez` en null es "no lo sé", NUNCA "no pasó". Las tres filas de resumen (compró /
     * empezó a comprar / sacó del carrito) y la del carrito por artículo sacan su momento de la
     * línea de tiempo, que está topeada en ActividadDeClientesService::MAX_EVENTOS_LINEA_DE_TIEMPO:
     * un evento más viejo que ese tope se informa sin fecha en vez de con una inventada.
     *
     * 🔴 Los minutos van SOLO en la fila 'vio'. El lector suma el dwell del artículo entero (lo que
     * miró más lo que carreteó) en un único total, así que repetirlo en la fila 'carrito' lo
     * contaría dos veces.
     *
     * El orden de las filas es de más a menos accionable, porque MAX_RESULTS corta la cola: lo que
     * cerró, lo que dejó por la mitad, lo que buscó sin encontrar, y recién después el detalle
     * largo de lo que miró.
     *
     * @param  int  $owner_id   Id del dueño. Nunca Auth: esta tool corre en un job sin sesión.
     * @param  int  $client_id  Id del cliente del ERP, tal como lo devolvió consultar_clientes.
     * @param  int  $dias       Ventana hacia atrás; un valor fuera de la lista blanca cae a 30.
     * @return array<int, array<string, mixed>>
     */
    public static function actividad_de_un_cliente(int $owner_id, int $client_id, int $dias): array
    {
        $service = new ActividadDeClientesService($owner_id);

        /*
         * Un valor fuera de la lista blanca cae al default en vez de cambiar de tabla por lo bajo:
         * con $dias = 0 el servicio contesta desde el agregado, que no tiene hora ni línea de
         * tiempo, y esta tool quedaría sin ninguno de los momentos que informa.
         */
        if (! ActividadDeClientesService::es_periodo_valido($dias) || $dias <= 0) {
            $dias = 30;
        }

        /*
         * La tenencia la da la resolución de compradores (buyers.user_id = $owner_id): un cliente
         * de otro comercio devuelve lista vacía, y con lista vacía el bloque de actividad vuelve
         * en cero SIN tocar el tracking.
         */
        $actividad = $service->actividad($service->buyer_ids_de_un_cliente($client_id), $dias);

        if (! $actividad['hay_datos']) {
            return [];
        }

        // Un strtotime que falla no puede terminar en un 01/01/1970 con cara de fecha real.
        $fecha = function ($valor) {
            if (is_null($valor) || $valor === '') {
                return null;
            }

            $momento = strtotime($valor);

            return $momento === false ? null : date('d/m/Y', $momento);
        };

        /*
         * El momento más nuevo por tipo de evento, y por (tipo, artículo) cuando lo hay. La línea
         * de tiempo ya viene del más nuevo al más viejo, así que el primero que aparece gana.
         */
        $ultimo_de = [];

        foreach ($actividad['linea_de_tiempo'] as $evento) {
            $claves = [$evento['tipo']];

            if (! is_null($evento['article_id'])) {
                $claves[] = $evento['tipo'] . ':' . $evento['article_id'];
            }

            foreach ($claves as $clave) {
                if (! isset($ultimo_de[$clave])) {
                    $ultimo_de[$clave] = $evento['cuando'];
                }
            }
        }

        $momento_de = function ($clave) use ($ultimo_de, $fecha) {
            return $fecha(isset($ultimo_de[$clave]) ? $ultimo_de[$clave] : null);
        };

        // Las siete claves se arman en un solo lugar para que ninguna fila salga con una de menos.
        $armar = function ($tipo, $detalle, $veces, $segundos, $resultados, $monto, $ultima_vez) {
            return [
                'tipo'       => $tipo,
                'detalle'    => $detalle,
                'veces'      => (int) $veces,
                'minutos'    => (int) round(((int) $segundos) / 60),
                'resultados' => is_null($resultados) ? null : (int) $resultados,
                'monto'      => (float) $monto,
                'ultima_vez' => $ultima_vez,
            ];
        };

        $totales = $actividad['totales'];
        $filas   = [];

        if ($totales['compras'] > 0) {
            $filas[] = $armar('compro', null, $totales['compras'], 0, null, $totales['monto_comprado'], $momento_de('checkout_complete'));
        }

        if ($totales['checkouts_empezados'] > 0) {
            $filas[] = $armar('empezo_a_comprar', null, $totales['checkouts_empezados'], 0, null, 0, $momento_de('checkout_start'));
        }

        if ($totales['quitados_del_carrito'] > 0) {
            $filas[] = $armar('saco_del_carrito', null, $totales['quitados_del_carrito'], 0, null, 0, $momento_de('cart_remove'));
        }

        /*
         * 🔴 Primero lo que buscó y NO encontró: es el dato más accionable que hay (hay demanda y
         * no hay oferta), así que no puede ser lo primero que se coma el tope. Adentro de cada
         * grupo se respeta el orden del lector, que ya viene por veces descendente.
         */
        foreach ([true, false] as $sin_resultado) {
            foreach ($actividad['busquedas'] as $busqueda) {
                if ($busqueda['sin_resultado'] !== $sin_resultado) {
                    continue;
                }

                $filas[] = $armar('busco', $busqueda['termino'], $busqueda['veces'], 0, $busqueda['resultados'], 0, $fecha($busqueda['ultima_vez']));
            }
        }

        foreach ($actividad['articulos'] as $articulo) {
            if ($articulo['agregados_al_carrito'] > 0) {
                // El momento sale de la línea de tiempo y no del artículo: el `ultima_vez` del
                // artículo es la última vez que anduvo con él de cualquier forma, y usarlo acá
                // diría "lo puso en el carrito" un día en que solamente lo miró.
                $filas[] = $armar('carrito', $articulo['nombre'], $articulo['agregados_al_carrito'], 0, null, 0, $momento_de('cart_add:' . $articulo['article_id']));
            }
        }

        foreach ($actividad['articulos'] as $articulo) {
            if ($articulo['vistas'] > 0) {
                $filas[] = $armar('vio', $articulo['nombre'], $articulo['vistas'], $articulo['tiempo_segundos'], null, 0, $fecha($articulo['ultima_vez']));
            }
        }

        return array_slice($filas, 0, self::MAX_RESULTS);
    }

    /**
     * Quién anduvo mirando o carreteando un artículo en la tienda online y TODAVÍA NO LO COMPRÓ.
     * Tool de lectura del chat de IA (misión actividad-de-clientes-y-oferta-por-whatsapp): la IA
     * contesta "a quién le puedo ofrecer esto" con gente que de verdad lo miró, nunca inventada.
     *
     * El dato sale de `buyer_tracking_events` a través de ActividadDeClientesService, y el descarte
     * de "ya lo compró" lo hace ese servicio contra `article_purchases` con el filtro de ventas
     * reales del motor de ofertas (CriteriosDeOfertaService::filtro_de_ventas_reales(), que es
     * público estático justamente para que ese criterio viva en un solo lugar).
     *
     * 🔴 Los visitantes ANÓNIMOS de la tienda no aparecen acá, y no es un olvido: sin cliente
     * asociado no hay a quién nombrar ni a quién llamar. La tool contesta una lista de contactos, y
     * una fila sin nombre en esa lista es una fila que no sirve para nada.
     *
     * 🔴 Cada fila dice de QUÉ artículo está hablando. La búsqueda es difusa (nombre o código, con
     * LIKE) y puede matchear más de un artículo: sin el nombre resuelto adentro de la respuesta, la
     * IA contestaría con total seguridad sobre un artículo que no es el que le preguntaron. Mismo
     * criterio que precios_de_proveedores(), que también informa el artículo que resolvió.
     *
     * @param  int     $owner_id  Id del dueño. Nunca Auth: esta tool corre en un job sin sesión.
     * @param  string  $busqueda  Nombre (o parte), código de barras o código de proveedor.
     * @param  int     $dias      Ventana hacia atrás; un valor fuera de la lista blanca cae a 30.
     * @return array<int, array<string, mixed>>
     */
    public static function interesados_en_un_articulo(int $owner_id, string $busqueda, int $dias): array
    {
        $busqueda = trim($busqueda);

        /*
         * Sin búsqueda no hay pregunta que contestar. Traer "el primer artículo del catálogo" y
         * listar a sus interesados sería una respuesta perfectamente plausible sobre algo que nadie
         * preguntó, que es la peor forma de estar equivocado.
         */
        if ($busqueda === '') {
            return [];
        }

        if (! ActividadDeClientesService::es_periodo_valido($dias) || $dias <= 0) {
            $dias = 30;
        }

        $candidatos = Article::query()
            ->where('user_id', $owner_id)
            ->where(function ($sub) use ($busqueda) {
                $sub->where('name', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('bar_code', 'LIKE', '%' . $busqueda . '%')
                    ->orWhere('provider_code', 'LIKE', '%' . $busqueda . '%');
            })
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name']);

        if ($candidatos->isEmpty()) {
            return [];
        }

        /*
         * Un solo artículo por respuesta, elegido de forma determinística: si el texto es
         * exactamente el nombre de uno —que es lo normal, porque la IA lo saca de
         * consultar_stock_de_articulos— gana ése; si no, el primero por nombre.
         */
        $elegido = $candidatos->first();

        foreach ($candidatos as $candidato) {
            if (self::normalize_text((string) $candidato->name) === self::normalize_text($busqueda)) {
                $elegido = $candidato;
                break;
            }
        }

        $service = new ActividadDeClientesService($owner_id);
        // La tenencia del artículo la vuelve a controlar el servicio: sin artículo del dueño, lista
        // vacía y no se toca el tracking.
        $interesados = $service->interesados_en_un_articulo((int) $elegido->id, $dias);

        $result = [];

        foreach (array_slice($interesados, 0, self::MAX_RESULTS) as $fila) {
            $momento = is_null($fila['ultima_vez']) ? false : strtotime($fila['ultima_vez']);

            $result[] = [
                'articulo'   => (string) $elegido->name,
                'cliente'    => (string) $fila['cliente'],
                'telefono'   => (string) $fila['telefono'],
                'vistas'     => (int) $fila['vistas'],
                'minutos'    => (int) round(((int) $fila['segundos']) / 60),
                'al_carrito' => (int) $fila['al_carrito'],
                'ultima_vez' => $momento === false ? null : date('d/m/Y', $momento),
            ];
        }

        return $result;
    }

    /**
     * Extrae una palabra clave de producto/entidad desde el texto de la consulta.
     *
     * Quita los disparadores conocidos (stock, cuánto tengo, de, etc.) y devuelve el resto.
     *
     * @param  string  $query
     * @return string  Palabra clave depurada (puede quedar vacía).
     */
    public static function extract_product_keyword(string $query): string
    {
        // Palabras de relleno que no aportan al filtro de nombre.
        $stop_words = [
            'cuanto', 'cuanta', 'cuantos', 'cuantas', 'stock', 'inventario', 'tengo', 'hay', 'queda', 'quedan',
            'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'mi', 'mis', 'me', 'que', 'cual', 'cuales',
            'existencia', 'existencias', 'producto', 'productos', 'articulo', 'articulos', 'sistema',
            'cliente', 'clientes', 'factura', 'facturas', 'deuda', 'saldo', 'pendiente', 'pendientes',
        ];

        $normalized = self::normalize_text($query);
        // Separamos en palabras y descartamos las de relleno.
        $words = preg_split('/\s+/', $normalized) ?: [];

        $kept = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || in_array($word, $stop_words, true)) {
                continue;
            }
            $kept[] = $word;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * Normaliza texto a minúsculas sin acentos para comparaciones de palabras clave.
     *
     * @param  string  $text
     * @return string
     */
    public static function normalize_text(string $text): string
    {
        $text = mb_strtolower(trim($text));

        // Reemplazo simple de vocales acentuadas y ñ.
        $replacements = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        return strtr($text, $replacements);
    }
}
