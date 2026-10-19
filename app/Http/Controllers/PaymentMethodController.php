<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\TenenciaDeLaTiendaHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\CuotasTiendaEsquemaHelper;
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

    /**
     * Da de alta un método de pago de la tienda.
     *
     * `current_acount_payment_method_id` (misión tienda-cuotas-mercado-pago, 15/10/2026) es el
     * vínculo con el método de pago de cuenta corriente cuyas cuotas se le ofrecen al comprador de
     * la tienda cuando este método es de tipo Mercado Pago. Va por `CuotasTiendaEsquemaHelper`: sin
     * la columna (deploy que sube los archivos antes de migrar) el alta es la de siempre.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {

        if (CuotasTiendaEsquemaHelper::es_un_vinculo_invalido($request->current_acount_payment_method_id)) {
            return $this->respuesta_vinculo_invalido();
        }

        $model = PaymentMethod::create(CuotasTiendaEsquemaHelper::agregar_al_payload([
            // 'num'                       => $this->num('payment_methods'),
            'name'                      => $request->name,
            'description'               => $request->description,
            'discount'                  => $request->discount,
            'payment_method_type_id'    => $request->payment_method_type_id,
            'public_key'                => $request->public_key,
            'access_token'              => $request->access_token,
            'user_id'                   => $this->userId(),
        ], $request->current_acount_payment_method_id));
        $this->sendAddModelNotification('payment_method', $model->id);
        return response()->json(['model' => $this->fullModel('PaymentMethod', $model->id)], 201);
    }  

    public function show($id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

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
     * 🔴 `current_acount_payment_method_id` (el vínculo con el método de cuenta corriente, misión
     * tienda-cuotas-mercado-pago, 15/10/2026) sigue EXACTAMENTE el mismo patrón y por el mismo
     * motivo: SE ASIGNA SOLO SI EL REQUEST LO TRAE. Una SPA vieja (o cualquier cliente de la API que
     * no conozca el campo) edita un método de pago sin mandarlo, y una asignación incondicional
     * dejaría el vínculo en NULL: la tienda perdería sus cuotas sin que nadie toque nada. Con `has()`
     * mandarlo vacío o en 0 («Seleccione ...» del select) SÍ desvincula, que es lo que el dueño pide
     * cuando lo hace a propósito. Además va detrás de `CuotasTiendaEsquemaHelper`: sin la columna ni
     * se mira.
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        if ($request->has('current_acount_payment_method_id')
            && CuotasTiendaEsquemaHelper::es_un_vinculo_invalido($request->current_acount_payment_method_id)) {
            return $this->respuesta_vinculo_invalido();
        }

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
        if ($request->has('current_acount_payment_method_id')) {
            CuotasTiendaEsquemaHelper::asignar_al_modelo($model, $request->current_acount_payment_method_id);
        }
        $model->save();
        $this->sendAddModelNotification('payment_method', $model->id);
        return response()->json(['model' => $this->fullModel('PaymentMethod', $model->id)], 200);
    }

    public function destroy($id) {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = PaymentMethod::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('payment_method', $model->id);
        return response(null);
    }

    /**
     * 422 de un vínculo que apunta a un método de pago de cuenta corriente que no existe.
     *
     * No se escribe nada: ni el alta ni la edición llegan a tocar la base.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_vinculo_invalido() {
        return response()->json([
            'message' => 'El método de pago de cuenta corriente elegido no existe.',
            'errors'  => [
                'current_acount_payment_method_id' => ['El método de pago de cuenta corriente elegido no existe.'],
            ],
        ], 422);
    }

    /**
     * El método de pago de la tienda tiene que ser del comercio de la sesión.
     *
     * Devuelve null si el registro es del dueño de la sesión y, si no, el 404 que el método contesta
     * tal cual (ajeno, inexistente y no-id dan lo mismo). Ver `TenenciaDeLaTiendaHelper`.
     *
     * @param  mixed  $id  Lo que llegó en la ruta.
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function registro_ajeno_o_404($id) {
        return TenenciaDeLaTiendaHelper::respuesta_si_no_es_del_dueno(PaymentMethod::class, $id, $this->userId(), 'El método de pago no existe o no es de tu cuenta.');
    }
}
