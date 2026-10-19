<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ConceptoMovimientoCaja;
use App\Models\MovimientoCaja;
use Illuminate\Http\Request;

/**
 * ABM de los conceptos de movimiento de caja.
 *
 * Desde la misión destrabar-videos-centro (16/10/2026) cada concepto tiene dueño (`user_id`):
 * - `user_id` NULL = concepto del SISTEMA (Venta, Gasto, Pago de Cliente, ..., Pago a Vendedor).
 *   Los ven todos y no se renombran ni se borran: el código los usa por id fijo
 *   (`MovimientoCaja::CONCEPTO_*`) y por nombre.
 * - `user_id` del dueño = concepto propio del comercio. Lo ve, lo renombra y lo borra solo él.
 *
 * Lo ajeno responde 404 igual que lo inexistente (no se confirma que el id exista en otro
 * comercio). Los rechazos van como 422 con `message` + `errors` (mapa), que la SPA muestra en un
 * toast con el motivo.
 */
class ConceptoMovimientoCajaController extends Controller
{

    /** Motivos del rechazo al tocar un concepto del sistema. */
    const MENSAJE_SISTEMA_EDITAR = 'Los conceptos del sistema no se pueden modificar.';
    const MENSAJE_SISTEMA_ELIMINAR = 'Los conceptos del sistema no se pueden eliminar.';

    /** Motivo del rechazo al borrar un concepto que ya se usó. */
    const MENSAJE_CON_MOVIMIENTOS = 'Este concepto ya tiene movimientos de caja cargados: no se puede eliminar.';

    /** Lo ajeno y lo inexistente. */
    const MENSAJE_404 = 'No se encontró el concepto.';

    public function index() {
        $models = ConceptoMovimientoCaja::delDuenoConGlobales($this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ConceptoMovimientoCaja::create([
            // 'num'                   => $this->num('ConceptoMovimientoCaja'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ConceptoMovimientoCaja', $model->id);
        return response()->json(['model' => $this->fullModel('ConceptoMovimientoCaja', $model->id)], 201);
    }

    public function show($id) {
        if (is_null($this->concepto_visible($id))) {
            return response()->json(['message' => self::MENSAJE_404], 404);
        }
        return response()->json(['model' => $this->fullModel('ConceptoMovimientoCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = $this->concepto_visible($id);
        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_404], 404);
        }
        if (is_null($model->user_id)) {
            return $this->rechazo(self::MENSAJE_SISTEMA_EDITAR);
        }
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('ConceptoMovimientoCaja', $model->id);
        return response()->json(['model' => $this->fullModel('ConceptoMovimientoCaja', $model->id)], 200);
    }

    public function destroy($id) {
        $model = $this->concepto_visible($id);
        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_404], 404);
        }
        if (is_null($model->user_id)) {
            return $this->rechazo(self::MENSAJE_SISTEMA_ELIMINAR);
        }
        if (MovimientoCaja::where('concepto_movimiento_caja_id', $model->id)->exists()) {
            return $this->rechazo(self::MENSAJE_CON_MOVIMIENTOS);
        }
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ConceptoMovimientoCaja', $model->id);
        return response(null);
    }

    /**
     * El concepto si es del sistema o del dueño que pregunta; null si no existe o es de otro
     * comercio.
     *
     * @param  int  $id
     * @return \App\Models\ConceptoMovimientoCaja|null
     */
    protected function concepto_visible($id) {
        return ConceptoMovimientoCaja::delDuenoConGlobales($this->userId())
                    ->where('id', $id)
                    ->first();
    }

    /**
     * 422 con `message` + `errors` como mapa: la forma que la SPA muestra en un toast con el
     * motivo, y la que el borrado masivo lee para no contar el concepto como eliminado.
     *
     * @param  string  $mensaje
     * @return \Illuminate\Http\JsonResponse
     */
    protected function rechazo($mensaje) {
        return response()->json([
            'message' => $mensaje,
            'errors'  => ['concepto_movimiento_caja' => [$mensaje]],
        ], 422);
    }
}
