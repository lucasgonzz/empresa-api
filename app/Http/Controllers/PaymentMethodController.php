<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{

    public function index() {
        $models = PaymentMethod::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = PaymentMethod::create([
            // 'num'                       => $this->num('payment_methods'),
            'name'                      => $request->name,
            'description'               => $request->description,
            'discount'                  => $request->discount,
            'payment_method_type_id'    => $request->payment_method_type_id,
            'public_key'                => $request->public_key,
            'access_token'              => $request->access_token,
            'user_id'                   => $this->userId(),
        ]);
        $this->sendAddModelNotification('payment_method', $model->id);
        return response()->json(['model' => $this->fullModel('PaymentMethod', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PaymentMethod', $id)], 200);
    }

    /**
     * Actualiza un método de pago.
     *
     * 🔴 `public_key` y `access_token` SE ASIGNAN SOLO SI EL REQUEST LOS TRAE, y esto no es una
     * complicación gratuita: es la mitad de un par con el `$hidden` de `App\Models\PaymentMethod`.
     *
     * Desde la misión de ABM -> Integraciones el token no se serializa más, así que el formulario
     * de la SPA (que arma el payload con `{...this.model}` sobre lo que devolvió la API) ya no lo
     * tiene para devolverlo. Si esto se "simplificara" a `$model->access_token = $request->
     * access_token`, la primera edición de un método de pago —cambiarle el nombre, el descuento,
     * cualquier cosa— dejaría el token en null y el comercio en producción dejaría de cobrar sin
     * que nadie toque nada de Mercado Pago.
     *
     * Se usa `has()` y no `filled()` a propósito: mandar el campo explícitamente vacío TIENE que
     * poder borrar la credencial. Lo que no puede es borrarla por omisión.
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id) {
        $model = PaymentMethod::find($id);
        $model->name                        = $request->name;
        $model->description                 = $request->description;
        $model->discount                    = $request->discount;
        $model->payment_method_type_id      = $request->payment_method_type_id;
        if ($request->has('public_key')) {
            $model->public_key              = $request->public_key;
        }
        if ($request->has('access_token')) {
            $model->access_token            = $request->access_token;
        }
        $model->save();
        $this->sendAddModelNotification('payment_method', $model->id);
        return response()->json(['model' => $this->fullModel('PaymentMethod', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PaymentMethod::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('payment_method', $model->id);
        return response(null);
    }
}
