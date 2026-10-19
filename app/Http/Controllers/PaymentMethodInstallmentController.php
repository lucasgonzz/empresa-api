<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodInstallment;
use Illuminate\Http\Request;

class PaymentMethodInstallmentController extends Controller
{

    public function store(Request $request) {
        $ajeno = $this->metodo_de_pago_del_cuerpo_ajeno_o_404($request->model_id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = PaymentMethodInstallment::create([
            'payment_method_id'     => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'name'                  => $request->name,
            'installments'          => $request->installments,
        ]);
        return response()->json(['model' => $this->fullModel('PaymentMethodInstallment', $model->id)], 201);
    }  

    public function show($id) {
        $ajeno = $this->cuota_ajena_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        return response()->json(['model' => $this->fullModel('PaymentMethodInstallment', $id)], 200);
    }

    public function update(Request $request, $id) {
        $ajeno = $this->cuota_ajena_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = PaymentMethodInstallment::find($id);
        $model->name                        = $request->name;
        $model->installments                = $request->installments;
        $model->save();
        return response()->json(['model' => $this->fullModel('PaymentMethodInstallment', $model->id)], 200);
    }

    public function destroy($id) {
        $ajeno = $this->cuota_ajena_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = PaymentMethodInstallment::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('PaymentMethodInstallment', $model->id);
        return response(null);
    }

    /**
     * 🔴 La cuota (`payment_method_installments`, misión tenencia-tienda-online, 19/10/2026) no tiene
     * `user_id`: su dueño es el del método de pago del que cuelga. Devuelve null si la cuota es de
     * un método de pago del dueño de la sesión y, si no, el 404 que el método contesta tal cual
     * (ajena, inexistente y no-id dan lo mismo).
     *
     * Una cuota sin método de pago todavía (`payment_method_id` null: el formulario la crea con
     * `temporal_id` antes de guardar el método) no se puede atribuir a nadie y se deja pasar, como
     * siempre; es el hueco de los hijos con `temporal_id`, anotado en el informe.
     *
     * @param  mixed  $id  Lo que llegó en la ruta.
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function cuota_ajena_o_404($id) {

        $id_pedido = ChequeHelper::id_del_pedido($id);
        $cuota = $id_pedido === 0 ? null : PaymentMethodInstallment::find($id_pedido);

        $es_ajena = !is_null($cuota)
            && !is_null($cuota->payment_method_id)
            && is_null(ChequeHelper::id_del_dueno(PaymentMethod::class, $cuota->payment_method_id, $this->userId()));

        if (is_null($cuota) || $es_ajena) {
            return response()->json(['message' => 'La cuota no existe o no es de tu cuenta.'], 404);
        }

        return null;
    }

    /**
     * El `model_id` del alta (el método de pago del que cuelga la cuota) tiene que ser del dueño de
     * la sesión; sin él (alta con `temporal_id`) no hay nada que cruzar.
     *
     * @param  mixed  $model_id
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function metodo_de_pago_del_cuerpo_ajeno_o_404($model_id) {

        if (is_null($model_id) || $model_id === '' || $model_id === 0 || $model_id === '0') {
            return null;
        }

        if (is_null(ChequeHelper::id_del_dueno(PaymentMethod::class, $model_id, $this->userId()))) {
            return response()->json(['message' => 'El método de pago no existe o no es de tu cuenta.'], 404);
        }

        return null;
    }
}
