<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\TwilioHelper;
use App\Models\Buyer;
use App\Models\Message;
use App\Notifications\MessageSend;
use Illuminate\Http\Request;

class MessageController extends Controller
{

    /**
     * Endpoint viejo (lo sigue usando la SPA de los clientes sin actualizar; el submódulo nuevo usa
     * `GET tienda-chats/{buyer_id}/mensajes`).
     *
     * Misión mensajes-tienda-online (28/9/2026): solo devuelve mensajes de un comprador de ESTE
     * comercio. Hasta acá no filtraba nada, y en las bases compartidas se leían chats ajenos por id.
     * Un comprador ajeno da la lista vacía (no un 404), así la SPA vieja no cambia de camino; un
     * pedido legítimo da exactamente lo mismo que antes.
     */
    function fromBuyer($buyer_id) {
        $buyer = BuyerHelper::comprador_del_comercio($buyer_id, $this->userId());
        if (is_null($buyer)) {
            return response()->json(['models' => []], 200);
        }
        $models = Message::where('buyer_id', $buyer->id)
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    /**
     * Endpoint viejo (el nuevo es `POST tienda-chats/{buyer_id}/leer`). Mismo filtro por comercio
     * que `fromBuyer()`, y un solo `UPDATE` en vez de un `save()` por mensaje (mismo resultado:
     * `read = 1` y `updated_at` al día en cada mensaje del comprador sin leer).
     */
    function setRead($buyer_id) {
        $buyer = BuyerHelper::comprador_del_comercio($buyer_id, $this->userId());
        if (!is_null($buyer)) {
            Message::where('buyer_id', $buyer->id)
                    ->where('read', 0)
                    ->where('from_buyer', 1)
                    ->update(['read' => 1]);
        }
        return response(null, 200);
    }

    function store(Request $request) {
        $model = Message::create([
            'user_id' => $this->userId(),
            'buyer_id' => $request->buyer_id,
            'text' => StringHelper::onlyFirstWordUpperCase($request->text),
            'article_id' => $request->article_id,
        ]);
        $model = $this->fullModel('Message', $model->id);
        // $buyer = Buyer::find($request->buyer_id);
        // $buyer->notify(new MessageSend($model));
        return response()->json(['model' => $model], 201);
    }
}
