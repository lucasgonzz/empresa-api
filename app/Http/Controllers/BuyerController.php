<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\BuyerHelper;
use App\Models\Buyer;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Alta de un comprador de la tienda. `POST buyer`.
     *
     * Llega por dos caminos: el ABM de Tienda online → Clientes (sin `id` en el cuerpo) y el botón
     * "Crear usuario para la tienda" de la ficha de un cliente del sistema, donde la SPA manda el
     * cliente entero y su `id` termina guardado como `comercio_city_client_id`.
     *
     * Responde 201 con el comprador creado, o 200 con `ya_existia: true` si el cliente ya tenía
     * uno (ver la guarda más abajo).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
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

        // El hash se calcula ANTES de abrir la transacción: bcrypt tarda unas decenas de ms y,
        // adentro, se haría con los locks de `users` y `buyers` del dueño tomados, frenando todos
        // los `num()` de ese comercio (ventas, compras) mientras tanto.
        $hash = bcrypt($password);

        $model = null;
        $existente = null;

        if ($request->filled('id')) {

            // 🔴 Un cliente del sistema tiene UN solo comprador. Sin esta guarda, un doble clic en
            // "Crear usuario para la tienda" de la ficha creaba dos compradores para el mismo
            // cliente con el mismo correo (medido el 8/10/2026 en demo2: ids 20 y 21 para el
            // cliente 29), y la tienda resuelve el login por email con `->first()`: uno de los
            // dos sobra.
            //
            // Cuando ya existe NO se responde 422: la SPA 4.3.8 que hay hoy en producción, ante
            // un error, mostraría "Error al crear usuario" sobre un usuario que sí existe. Con un
            // 200 y el comprador existente hace `buyer/add` (reemplaza por id, no duplica la
            // fila) y muestra "Usuario creado", que es verdad. `ya_existia` es un campo nuevo y
            // opcional para la SPA que sí lo entienda.
            //
            // El `lockForUpdate()` serializa dos POST simultáneos: sin él, los dos buscan,
            // ninguno encuentra al otro todavía, y crean los dos. Se bloquea la fila del DUEÑO en
            // `users` y no la del cliente, por tres motivos:
            //  - Es el mismo lock que `num()` toma enseguida (`users` del dueño y después la fila
            //    de `buyers` con mayor `num`): no suma ninguna espera nueva y el orden queda
            //    users → buyers, igual que en `num()`.
            //  - La fila del cliente es el candado de su cuenta corriente
            //    (`CuentaCorrienteLock`, que `SaleController@store` toma primero): bloquearla
            //    frenaba ventas y cobros de ese cliente mientras durara el alta.
            //  - `ClientController@store` toma `users` (vía `num('clients')`) y después la fila del
            //    cliente de mayor `num`: bloquear el cliente primero invertía el orden y, sobre el
            //    último cliente recién creado, podía terminar en deadlock (500).
            // El SELECT de abajo es la primera lectura consistente de la transacción, así que ya
            // ve lo que commiteó el POST que esperábamos.
            DB::transaction(function () use ($request, $password, $hash, &$model, &$existente) {

                DB::table('users')->where('id', $this->userId())->lockForUpdate()->first(['id']);

                // El de menor id: si ya hay duplicados viejos, se devuelve siempre el mismo y no
                // se suma un tercero.
                $existente = Buyer::where('user_id', $this->userId())
                                    ->where('comercio_city_client_id', $request->id)
                                    ->orderBy('id')
                                    ->first();

                if (is_null($existente)) {
                    $model = $this->crear_comprador($request, $password, $hash);
                }
            });
        } else {

            // Alta desde el ABM de compradores: sin cliente de por medio no hay nada que duplicar.
            $model = $this->crear_comprador($request, $password, $hash);
        }

        if (!is_null($existente)) {

            // 🔴 Acá NO va fullModel('Buyer'): su withAll() carga toda la conversación con
            // `article.images` (lo que tumbó el VPS el 9/9/2026, ver index() y
            // BuyerHelper::vincular). Se arma igual que ahí: direcciones y cliente, y `messages`
            // como colección vacía porque la SPA hace `buyer.messages.length`.
            //
            // Se ocultan el hash de la clave, el token de recordar y el código de verificación.
            // `visible_password` se deja: la respuesta alimenta `buyer/add`, que reemplaza la
            // fila entera del listado de Tienda online → Clientes, igual que el 201 de siempre.
            $existente->load('addresses', 'comercio_city_client');
            $existente->setRelation('messages', $existente->newCollection());
            $existente->makeHidden(['password', 'remember_token', 'verification_code']);

            return response()->json(['model' => $existente, 'ya_existia' => true], 200);
        }

        // Solo si se creó: avisarle a las otras pestañas de un comprador que no nació no sirve.
        $this->sendAddModelNotification('Buyer', $model->id);
        return response()->json(['model' => $this->fullModel('Buyer', $model->id)], 201);
    }

    /**
     * Crea la fila del comprador con los datos que manda la SPA.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $password  Clave en claro que se guarda en `visible_password`.
     * @param  string  $hash  Esa misma clave ya hasheada, para `password` (se calcula afuera de los locks).
     * @return \App\Models\Buyer
     */
    protected function crear_comprador(Request $request, $password, $hash) {

        return Buyer::create([
            'num'                       => $this->num('buyers'),
            'name'                      => $request->name,
            'email'                     => $request->email,
            'phone'                     => $request->phone,
            'ciudad'                     => $request->ciudad,
            'barrio'                     => $request->barrio,
            'address'                     => $request->address,
            'seller_id'                 => $request->seller_id,
            'visible_password'          => $password,
            'password'                  => $hash,
            'comercio_city_client_id'   => $request->id,
            'user_id'                   => $this->userId(),
        ]);
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
