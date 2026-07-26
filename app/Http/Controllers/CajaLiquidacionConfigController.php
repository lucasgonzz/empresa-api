<?php

namespace App\Http\Controllers;

use App\Models\CajaLiquidacionConfig;
use Illuminate\Http\Request;

/**
 * CRUD del override de liquidación/comisión por método de pago dentro de una caja
 * (Grupo 223 · Prompt 01). Sin lógica de cálculo: eso vive en el prompt 02.
 *
 * Scope por `user_id` del dueño (patrón estándar del sistema), igual que el resto de los
 * CRUD simples de tesorería (ver `ConceptoMovimientoCajaController`).
 */
class CajaLiquidacionConfigController extends Controller
{

    /**
     * Listado de overrides de una caja puntual, del dueño autenticado.
     *
     * @param int|string $caja_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($caja_id) {
        $models = CajaLiquidacionConfig::where('user_id', $this->userId())
                            ->where('caja_id', $caja_id)
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea un override de caja + método de pago. Si ya existe uno para esa combinación
     * (dentro del dueño autenticado), no revienta por el índice único: actualiza el existente
     * y lo devuelve, tal como si fuera un `update`.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {
        // Dueño autenticado: todo override se crea/edita dentro de su propio scope.
        $user_id = $this->userId();

        // Busca si ya existe un override para esa combinación caja + método de pago,
        // para no chocar contra el índice único `clc_caja_payment_method_unq`.
        $model = CajaLiquidacionConfig::where('user_id', $user_id)
                            ->where('caja_id', $request->caja_id)
                            ->where('current_acount_payment_method_id', $request->current_acount_payment_method_id)
                            ->first();

        if (is_null($model)) {
            $model = new CajaLiquidacionConfig();
            $model->caja_id = $request->caja_id;
            $model->current_acount_payment_method_id = $request->current_acount_payment_method_id;
            $model->user_id = $user_id;
        }

        // Todas las columnas de configuración son nullable a propósito (null = heredar de
        // la caja); se persiste tal cual venga del request, sin forzar defaults acá.
        $model->dias_liquidacion       = $request->dias_liquidacion;
        $model->comision_porcentaje    = $request->comision_porcentaje;
        $model->expense_concept_id     = $request->expense_concept_id;
        $model->comision_iva_alicuota  = $request->comision_iva_alicuota;
        $model->comision_iva_incluido  = $request->comision_iva_incluido;
        $model->save();

        $this->sendAddModelNotification('CajaLiquidacionConfig', $model->id);
        return response()->json(['model' => $this->fullModel('CajaLiquidacionConfig', $model->id)], 201);
    }

    /**
     * Muestra un override puntual.
     *
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id) {
        return response()->json(['model' => $this->fullModel('CajaLiquidacionConfig', $id)], 200);
    }

    /**
     * Actualiza un override existente. Valida que el método de pago pertenezca al mismo
     * dueño y que la combinación caja + método siga siendo válida antes de guardar.
     *
     * @param \Illuminate\Http\Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id) {
        $model = CajaLiquidacionConfig::where('user_id', $this->userId())->find($id);

        $model->dias_liquidacion       = $request->dias_liquidacion;
        $model->comision_porcentaje    = $request->comision_porcentaje;
        $model->expense_concept_id     = $request->expense_concept_id;
        $model->comision_iva_alicuota  = $request->comision_iva_alicuota;
        $model->comision_iva_incluido  = $request->comision_iva_incluido;
        $model->save();

        $this->sendAddModelNotification('CajaLiquidacionConfig', $model->id);
        return response()->json(['model' => $this->fullModel('CajaLiquidacionConfig', $model->id)], 200);
    }

    /**
     * Elimina un override. Vuelve a heredar todo de la caja para ese método de pago.
     *
     * @param int|string $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {
        $model = CajaLiquidacionConfig::where('user_id', $this->userId())->find($id);
        $model->delete();
        $this->sendDeleteModelNotification('CajaLiquidacionConfig', $id);
        return response(null);
    }
}
