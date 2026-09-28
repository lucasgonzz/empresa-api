<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\Helpers\TiendaChatHelper;
use App\Jobs\NotificarRespuestaAlComprador;
use App\Models\Buyer;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El submódulo "Mensajes" de Tienda Online: la bandeja de conversaciones con los compradores de
 * la tienda, cada conversación, y la respuesta manual del comercio (misión mensajes-tienda-online,
 * 28/9/2026, contrato C3). Lo consume solo `empresa-spa`.
 *
 * 🔴 Todo filtra por `buyers.user_id = $this->userId()`, que resuelve al DUEÑO también cuando opera
 * un empleado. En las bases compartidas viejas conviven decenas de comercios: un comprador ajeno
 * da 404, igual que uno que no existe, y nunca se dice que existe en otro lado.
 *
 * 🔴 Nada de `withCount()` ni `withAll()` en el listado: son la clase de error que tumbó el VPS el
 * 9/9/2026 (ver `BuyerController::index()`). Los agregados salen de UN `GROUP BY buyer_id` sobre
 * `messages`, acotado a los compradores del comercio, y la cantidad de consultas no crece ni con
 * los compradores ni con los mensajes.
 *
 * Las formas de cada respuesta (`Chat`, `Mensaje`, `Buyer`) y el evento en vivo las arma
 * `TiendaChatHelper`, a mano y con los tipos del contrato.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class TiendaChatController extends Controller
{
    /** Conversaciones por página de la bandeja. */
    const POR_PAGINA_BANDEJA = 30;

    /** Mensajes por página de una conversación. */
    const POR_PAGINA_CONVERSACION = 40;

    /** Largo máximo de un mensaje escrito a mano por el comercio. */
    const LARGO_MAXIMO_TEXTO = 5000;

    /**
     * `GET tienda-chats?page=1&buscar=texto&solo_no_leidos=0|1`
     *
     * La bandeja: compradores del comercio con al menos un mensaje, ordenados por el id del último
     * mensaje (el más reciente arriba), de a 30. `buscar` es un `LIKE` sobre nombre, apellido
     * (también los dos juntos, para "Ana Pérez"), email y teléfono; `solo_no_leidos` deja los que
     * tienen algún mensaje del comprador sin leer.
     *
     * Consultas, siempre las mismas: el conteo del paginador, la página (con el GROUP BY adentro),
     * los últimos mensajes de la página (por id, en una sola) y los clientes vinculados.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse  200 `{ data: [Chat], current_page, last_page, per_page, total }`
     */
    public function index(Request $request)
    {
        $owner_id = $this->userId();

        // Un solo GROUP BY sobre `messages`, acotado a los compradores de este comercio (el
        // `whereIn` con subconsulta usa el índice de `messages.buyer_id`). El "último mensaje" es
        // el de id más alto, igual que `Buyer::last_message()`.
        $agregados = Message::query()
                        ->selectRaw('messages.buyer_id')
                        ->selectRaw('COUNT(*) AS messages_count')
                        ->selectRaw('COUNT(CASE WHEN messages.from_buyer = 1 AND messages.`read` = 0 THEN 1 END) AS unread_count')
                        ->selectRaw('MAX(messages.id) AS last_message_id')
                        ->whereIn('messages.buyer_id', function ($query) use ($owner_id) {
                            $query->select('id')
                                  ->from('buyers')
                                  ->where('user_id', $owner_id);
                        })
                        ->groupBy('messages.buyer_id');

        // `joinSub` y no `leftJoinSub`: la bandeja solo muestra compradores que tienen mensajes.
        $query = Buyer::query()
                    ->joinSub($agregados, 'chats', 'chats.buyer_id', '=', 'buyers.id')
                    ->where('buyers.user_id', $owner_id)
                    ->select(
                        'buyers.id',
                        'buyers.name',
                        'buyers.surname',
                        'buyers.email',
                        'buyers.phone',
                        'buyers.comercio_city_client_id',
                        'buyers.user_id'
                    )
                    ->selectRaw('chats.messages_count, chats.unread_count, chats.last_message_id')
                    ->with(['comercio_city_client' => function ($query) {
                        $query->select('id', 'name');
                    }])
                    ->orderBy('chats.last_message_id', 'DESC');

        $buscar = trim((string) $request->query('buscar', ''));

        if ($buscar !== '') {
            $like = '%'.$buscar.'%';

            $query->where(function ($query) use ($like) {
                $query->where('buyers.name', 'LIKE', $like)
                      ->orWhere('buyers.surname', 'LIKE', $like)
                      ->orWhere('buyers.email', 'LIKE', $like)
                      ->orWhere('buyers.phone', 'LIKE', $like)
                      ->orWhereRaw("CONCAT_WS(' ', buyers.name, buyers.surname) LIKE ?", [$like]);
            });
        }

        if ($request->boolean('solo_no_leidos')) {
            $query->where('chats.unread_count', '>', 0);
        }

        $paginador = $query->paginate(self::POR_PAGINA_BANDEJA);

        // Los últimos mensajes de la página, en UNA consulta por id (nunca uno por comprador).
        $ultimos = Message::whereIn('id', $paginador->getCollection()->pluck('last_message_id')->all())
                        ->get(['id', 'buyer_id', 'text', 'type', 'from_buyer', 'read', 'created_at'])
                        ->keyBy('id');

        $data = [];

        foreach ($paginador->getCollection() as $buyer) {

            $ultimo = $ultimos->get((int) $buyer->last_message_id);

            $data[] = [
                'buyer_id'        => (int) $buyer->id,
                'buyer'           => TiendaChatHelper::comprador_para_la_spa($buyer),
                'unread_count'    => (int) $buyer->unread_count,
                'messages_count'  => (int) $buyer->messages_count,
                // Sale del mismo mensaje que `last_message`: los dos campos nunca se contradicen.
                'last_message_at' => $ultimo ? TiendaChatHelper::fecha($ultimo->created_at) : null,
                'last_message'    => $ultimo ? TiendaChatHelper::ultimo_mensaje_para_la_bandeja($ultimo) : null,
            ];
        }

        return response()->json([
            'data'         => $data,
            'current_page' => (int) $paginador->currentPage(),
            'last_page'    => (int) $paginador->lastPage(),
            'per_page'     => (int) $paginador->perPage(),
            'total'        => (int) $paginador->total(),
        ], 200);
    }

    /**
     * `GET tienda-chats/resumen`
     *
     * Lo que alimenta los badges desde el login (Tienda Online, Alertas) y las tres tarjetas del
     * submódulo, en UNA consulta:
     *
     * - `chats_no_leidos`: compradores con al menos un mensaje suyo sin leer.
     * - `mensajes_no_leidos`: mensajes del comprador sin leer, sumando todos.
     * - `conversaciones_hoy`: compradores con al menos un mensaje (de cualquiera de los dos lados)
     *   creado hoy, en la hora del servidor.
     *
     * @return \Illuminate\Http\JsonResponse  200 `{ chats_no_leidos: int, mensajes_no_leidos: int, conversaciones_hoy: int }`
     */
    public function resumen()
    {
        $owner_id = $this->userId();

        $fila = Message::query()
                    ->selectRaw('COUNT(DISTINCT CASE WHEN from_buyer = 1 AND `read` = 0 THEN buyer_id END) AS chats_no_leidos')
                    ->selectRaw('COUNT(CASE WHEN from_buyer = 1 AND `read` = 0 THEN 1 END) AS mensajes_no_leidos')
                    ->selectRaw('COUNT(DISTINCT CASE WHEN created_at >= ? THEN buyer_id END) AS conversaciones_hoy', [Carbon::today()->toDateTimeString()])
                    ->whereIn('buyer_id', function ($query) use ($owner_id) {
                        $query->select('id')
                              ->from('buyers')
                              ->where('user_id', $owner_id);
                    })
                    ->toBase()
                    ->first();

        return response()->json([
            'chats_no_leidos'    => $fila ? (int) $fila->chats_no_leidos : 0,
            'mensajes_no_leidos' => $fila ? (int) $fila->mensajes_no_leidos : 0,
            'conversaciones_hoy' => $fila ? (int) $fila->conversaciones_hoy : 0,
        ], 200);
    }

    /**
     * `GET tienda-chats/{buyer_id}/mensajes?page=1`
     *
     * Una conversación, de a 40 mensajes. La página 1 son los 40 más recientes; dentro de cada
     * página van del más viejo al más nuevo (como se leen), así la SPA pinta la página tal cual y
     * al pedir la siguiente la antepone arriba.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|string  $buyer_id
     * @return \Illuminate\Http\JsonResponse  200 `{ data: [Mensaje], current_page, last_page, buyer: Buyer }` · 404
     */
    public function mensajes(Request $request, $buyer_id)
    {
        $buyer = BuyerHelper::comprador_del_comercio($buyer_id, $this->userId());

        if (is_null($buyer)) {
            return response()->json(['message' => BuyerHelper::MENSAJE_COMPRADOR_NO_ENCONTRADO], 404);
        }

        $paginador = Message::where('buyer_id', $buyer->id)
                        ->with(self::relaciones_del_mensaje())
                        ->orderBy('id', 'DESC')
                        ->paginate(self::POR_PAGINA_CONVERSACION);

        $data = [];

        // Vienen del más nuevo al más viejo (así la página 1 son los más recientes); se dan vuelta
        // para que dentro de la página queden en el orden en que se leen.
        foreach (array_reverse($paginador->items()) as $message) {
            $data[] = TiendaChatHelper::mensaje_para_la_spa($message);
        }

        return response()->json([
            'data'         => $data,
            'current_page' => (int) $paginador->currentPage(),
            'last_page'    => (int) $paginador->lastPage(),
            'buyer'        => TiendaChatHelper::comprador_para_la_spa($buyer),
        ], 200);
    }

    /**
     * `POST tienda-chats/{buyer_id}/mensajes` — `{ text, article_id? }`
     *
     * La respuesta manual del comercio. Se guarda con `user_id` = dueño, `from_buyer = 0`,
     * `read = 0` (en un mensaje del comercio, `read` = "el comprador ya lo leyó") y `type = null`.
     * El texto va TAL CUAL (solo el trim de siempre): un mensaje escrito a mano no se reescribe, a
     * diferencia del `POST message` viejo, que le pasaba `onlyFirstWordUpperCase`.
     *
     * Después de guardar:
     * 1. Evento C1 al canal del dueño (lo ven las otras pestañas y los empleados). En try/catch:
     *    si Pusher está caído, el 201 sale igual.
     * 2. El aviso al comprador (C2: broadcast a la tienda y, si corresponde, el mail), DESPUÉS de
     *    mandada la respuesta, con `NotificarRespuestaAlComprador`.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|string  $buyer_id
     * @return \Illuminate\Http\JsonResponse  201 `{ message: Mensaje }` · 404 comprador ajeno · 422 texto vacío
     */
    public function enviar(Request $request, $buyer_id)
    {
        $owner_id = $this->userId();

        // Primero el 404: a un comprador ajeno no se le contesta ni siquiera un 422.
        $buyer = BuyerHelper::comprador_del_comercio($buyer_id, $owner_id);

        if (is_null($buyer)) {
            return response()->json(['message' => BuyerHelper::MENSAJE_COMPRADOR_NO_ENCONTRADO], 404);
        }

        // `TrimStrings` + `ConvertEmptyStringsToNull` (globales) ya dejan un texto de puros
        // espacios en null, así que `required` cubre el vacío. El artículo, si viene, tiene que
        // ser de este comercio: si no, el comprador vería la ficha de un artículo ajeno.
        $request->validate([
            'text'       => 'required|string|max:'.self::LARGO_MAXIMO_TEXTO,
            'article_id' => [
                'nullable',
                'integer',
                Rule::exists('articles', 'id')->where('user_id', $owner_id)->whereNull('deleted_at'),
            ],
        ]);

        $message = Message::create([
            'user_id'    => $owner_id,
            'buyer_id'   => $buyer->id,
            'text'       => $request->input('text'),
            'article_id' => $request->input('article_id'),
            'type'       => null,
            'from_buyer' => 0,
            'read'       => 0,
        ]);

        // Se decide antes de responder (ver el docblock de corresponde_mail()).
        $enviar_mail = TiendaChatHelper::corresponde_mail($message);

        TiendaChatHelper::emitir($owner_id, $buyer, $message);

        NotificarRespuestaAlComprador::dispatchAfterResponse($message->id, $buyer->id, $owner_id, $enviar_mail);

        $message->load(self::relaciones_del_mensaje());

        return response()->json(['message' => TiendaChatHelper::mensaje_para_la_spa($message)], 201);
    }

    /**
     * `POST tienda-chats/{buyer_id}/leer`
     *
     * Marca leídos los mensajes del comprador con UN solo `UPDATE` (nunca un loop de `save()`), y
     * avisa por C1 con `message: null` y `unread_count: 0` para que las otras pestañas y los
     * empleados bajen el badge.
     *
     * @param  int|string  $buyer_id
     * @return \Illuminate\Http\JsonResponse  200 `{ unread_count: 0 }` · 404
     */
    public function leer($buyer_id)
    {
        $owner_id = $this->userId();

        $buyer = BuyerHelper::comprador_del_comercio($buyer_id, $owner_id);

        if (is_null($buyer)) {
            return response()->json(['message' => BuyerHelper::MENSAJE_COMPRADOR_NO_ENCONTRADO], 404);
        }

        Message::where('buyer_id', $buyer->id)
                ->where('from_buyer', 1)
                ->where('read', 0)
                ->update(['read' => 1]);

        TiendaChatHelper::emitir($owner_id, $buyer, null, 0);

        return response()->json(['unread_count' => 0], 200);
    }

    /**
     * El `article` liviano de un mensaje: id, nombre y slug, más sus imágenes para elegir la
     * primera. Nada de `colors/sizes/questions` (eso lo trae el `withAll()` del endpoint viejo).
     *
     * @return array
     */
    protected static function relaciones_del_mensaje()
    {
        return [
            'article' => function ($query) {
                $query->select('id', 'name', 'slug');
            },
            'article.images',
        ];
    }
}
