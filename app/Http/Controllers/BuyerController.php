<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Models\Buyer;
use App\Models\Message;
use Illuminate\Http\Request;

class BuyerController extends Controller
{

    /**
     * Listado de compradores del comercio, con los agregados de su conversación en lugar de la
     * historia de mensajes.
     *
     * Cada comprador sale con `messages_count`, `unread_messages_count`, `last_message_at`,
     * `last_message` (sin `article.images`) y `messages` SIEMPRE como array vacío: la SPA
     * conserva la forma del payload y la conversación la carga aparte con `GET message/{buyer_id}`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index() {

        // 🔴 Acá NO va withAll(): scopeWithAll carga `messages` con `article.images`, y en Fenix
        // (1.710 compradores, 87.928 mensajes) esa sola llamada hidrataba ~280 MB por worker y
        // pasaba los 10 s adentro de json_encode: fue lo que tumbó el VPS el 9/9/2026. withAll()
        // queda para fullModel() en store/update/show, que traen UN comprador.
        //
        // Tampoco va withCount(): es una subconsulta correlacionada por comprador y `messages` no
        // tiene índice en `buyer_id`, así que son 1.710 escaneos completos de la tabla por request
        // (medido con el volumen de Fenix: 114 s contra 0,12 s de este único GROUP BY).
        $mensajes_agregados = Message::query()
                            ->selectRaw('buyer_id, COUNT(*) AS messages_count, COUNT(CASE WHEN from_buyer = 1 AND `read` = 0 THEN 1 END) AS unread_messages_count')
                            ->groupBy('buyer_id');

        $models = Buyer::query()
                            ->leftJoinSub($mensajes_agregados, 'mensajes_agregados', 'mensajes_agregados.buyer_id', '=', 'buyers.id')
                            ->where('buyers.user_id', $this->userId())
                            ->select('buyers.*')
                            ->selectRaw('COALESCE(mensajes_agregados.messages_count, 0) AS messages_count')
                            ->selectRaw('COALESCE(mensajes_agregados.unread_messages_count, 0) AS unread_messages_count')
                            ->with('addresses', 'comercio_city_client', 'last_message')
                            ->orderBy('buyers.created_at', 'DESC')
                            ->get();

        foreach ($models as $model) {
            // `last_message_at` sale del mismo mensaje que `last_message`, así los dos campos nunca
            // se contradicen (el "último" es por id, no por fecha).
            $model->last_message_at = $model->last_message ? $model->last_message->created_at : null;

            // Siempre un array vacío, nunca ausente: la SPA hace `buyer.messages.length`.
            $model->setRelation('messages', $model->newCollection());
        }

        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {

        $password = '1234';

        if (isset($request->num)) {
            $password .= $request->num;
        }

        if (
            strpos(config('app.APP_URL'), 'truvari') !== false
            && isset($request->phone)
        ) {
            $password = 'truvari'.substr($request->phone, -3);
        }

        if ($request->visible_password) {
            $password = $request->visible_password;
        }

        $model = Buyer::create([
            'num'                       => $this->num('buyers'),
            'name'                      => $request->name,
            'email'                     => $request->email,
            'phone'                     => $request->phone,
            'ciudad'                     => $request->ciudad,
            'barrio'                     => $request->barrio,
            'address'                     => $request->address,
            'seller_id'                 => $request->seller_id,
            'visible_password'          => $password,
            'password'                  => bcrypt($password),
            'comercio_city_client_id'   => $request->id,
            'user_id'                   => $this->userId(),
        ]);
        $this->sendAddModelNotification('Buyer', $model->id);
        return response()->json(['model' => $this->fullModel('Buyer', $model->id)], 201);
    }  

    public function update(Request $request, $id) {
        $model = Buyer::find($id);
        $model->name                    = $request->name;
        $model->email                   = $request->email;
        $model->phone                   = $request->phone;
        $model->ciudad                   = $request->ciudad;
        $model->barrio                   = $request->barrio;
        $model->address                   = $request->address;
        $model->seller_id               = $request->seller_id;
        $model->visible_password        = $request->visible_password;

        if ($request->visible_password && $request->visible_password != '') {
            $model->password     = bcrypt($request->visible_password);
        }

        $model->save();
        // $this->sendAddModelNotification('Buyer', $model->id);
        return response()->json(['model' => $this->fullModel('Buyer', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Buyer', $id)], 200);
    }

    public function destroy($id) {
        $model = Buyer::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Buyer', $model->id);
        return response(null);
    }

    /**
     * Coincidencias de clientes del sistema para vincular a un comprador de la tienda (misión
     * vincular-comprador-desde-pedidos, 24/9/2026). `GET buyer/{id}/clientes-para-vincular`.
     *
     * Query opcional: `q` (texto libre, máx. 100 caracteres; sin él salen sugerencias a partir de
     * los datos del comprador) y `limit` (1 a 50, por defecto 30). El comprador y los clientes se
     * scopean por el DUEÑO de la empresa (`userId()`), aunque opere un empleado. Toda la lógica
     * vive en `BuyerHelper`.
     *
     * @param  int|string  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function clientes_para_vincular($id, Request $request) {

        $user_id = $this->userId();

        $comprador = BuyerHelper::comprador_del_comercio($id, $user_id);

        if (is_null($comprador)) {
            return response()->json(['message' => BuyerHelper::MENSAJE_COMPRADOR_NO_ENCONTRADO], 404);
        }

        return response()->json(
            BuyerHelper::clientes_para_vincular($comprador, $user_id, $request->query('q'), $request->query('limit')),
            200
        );
    }

    /**
     * Vincula un comprador de la tienda con un cliente del sistema. `POST buyer/{id}/vincular-cliente`.
     *
     * Body: `client_id` (requerido) y `reemplazar` (bool, opcional: pisa un vínculo vivo a otro
     * cliente). Responde 200 `{model}`, 404, 409 (ya vinculado a otro cliente) o 422; los mensajes
     * y las reglas están en `BuyerHelper::vincular()`. Sin `$request->validate()` a propósito: los
     * códigos del contrato con la SPA se arman a mano.
     *
     * @param  int|string  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function vincular_cliente($id, Request $request) {

        $resultado = BuyerHelper::vincular(
            $id,
            $request->input('client_id'),
            $request->input('reemplazar'),
            $this->userId()
        );

        return response()->json($resultado['body'], $resultado['status']);
    }
}
