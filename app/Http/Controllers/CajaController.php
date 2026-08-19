<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\caja\CajaCierreHelper;
use App\Http\Controllers\Helpers\caja\CajaLiquidacionHelper;
use App\Models\Caja;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CajaController extends Controller
{

    /**
     * Listado de cajas del comercio (dueño) con relaciones cargadas.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index() {
        $models = Caja::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();

        /*
         * Grupo 223 · Prompt 02: cada caja expone tres saldos en vez de uno.
         * `saldo_contable` es el saldo actual sin tocar (no se cambia esa fórmula). Los otros dos
         * se calculan con una sola query de agregación por caja (sin traer movimientos a memoria,
         * hay clientes con cajas de decenas de miles de movimientos).
         */
        foreach ($models as $model) {

            $model->saldo_contable = $model->saldo;

            $saldos_liquidez = CajaLiquidacionHelper::calcular_saldos_liquidez($model->id);

            $model->saldo_disponible = $saldos_liquidez['saldo_disponible'];
            $model->saldo_a_liquidar = $saldos_liquidez['saldo_a_liquidar'];
        }

        return response()->json(['models' => $models], 200);
    }

    /**
     * Grupo 223 · Prompt 02 — línea de tiempo de liquidaciones pendientes de una caja: ingresos
     * cuya `fecha_liquidacion_estimada` todavía no llegó, agrupados por día con el neto de cada uno.
     * Alimenta la línea de tiempo del prompt 03 y el flujo de caja del grupo 226.
     *
     * @param int $id Id de la caja.
     * @return \Illuminate\Http\JsonResponse
     */
    public function liquidaciones_pendientes($id) {

        /** Fecha de corte: solo ingresos que liquidan después de hoy. */
        $hoy = Carbon::today()->format('Y-m-d');

        $items = DB::table('movimiento_cajas')
                    ->where('caja_id', $id)
                    ->whereNotNull('ingreso')
                    ->whereNotNull('fecha_liquidacion_estimada')
                    ->whereDate('fecha_liquidacion_estimada', '>', $hoy)
                    ->selectRaw('fecha_liquidacion_estimada as fecha, SUM(COALESCE(monto_neto_estimado, ingreso)) as neto')
                    ->groupBy('fecha_liquidacion_estimada')
                    ->orderBy('fecha_liquidacion_estimada', 'ASC')
                    ->get();

        return response()->json(['models' => $items], 200);
    }

    /**
     * Crea la caja y persiste pivots: `users` (operación / vender) y `treasury_users` (visibilidad en tesorería).
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {
        $model = Caja::create([
            'num'                   => $this->num('cajas'),
            'name'                  => $request->name,
            'moneda_id'             => $request->moneda_id,
            'address_id'            => $request->address_id,
            'employee_id'            => $request->employee_id,
            'notas'                 => $request->notas,
            'user_id'               => $this->userId(),
        ]);

        // GeneralHelper::attachModels($model, 'current_acount_payment_methods', $request->current_acount_payment_methods);

        GeneralHelper::attachModels($model, 'users', $request->users);

        GeneralHelper::attachModels($model, 'treasury_users', $request->input('treasury_users') ?? []);
        
        $this->sendAddModelNotification('Caja', $model->id);
        return response()->json(['model' => $this->fullModel('Caja', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Caja', $id)], 200);
    }

    /**
     * Actualiza datos de la caja y sincroniza pivots, incluido `treasury_users` (lista vacía limpia la tabla pivot).
     *
     * @param \Illuminate\Http\Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id) {
        $model = Caja::find($id);
        $model->name                = $request->name;
        $model->notas               = $request->notas;
        $model->moneda_id           = $request->moneda_id;
        $model->address_id          = $request->address_id;
        $model->employee_id          = $request->employee_id;
        $model->save();

        GeneralHelper::attachModels($model, 'current_acount_payment_methods', $request->current_acount_payment_methods);

        GeneralHelper::attachModels($model, 'users', $request->users);

        GeneralHelper::attachModels($model, 'treasury_users', $request->input('treasury_users') ?? []);
        
        $this->sendAddModelNotification('Caja', $model->id);
        return response()->json(['model' => $this->fullModel('Caja', $model->id)], 200);
    }

    function abrir_caja($caja_id) {

        $helper = new CajaAperturaHelper($caja_id);
        $helper->abrir_caja();

        return response()->json(['model' => $this->fullModel('Caja', $caja_id)], 200);
    }

    function cerrar_caja($caja_id) {

        $helper = new CajaCierreHelper($caja_id);
        $helper->cerrar_caja();

        return response()->json(['model' => $this->fullModel('Caja', $caja_id)], 200);
    }

    public function destroy($id) {
        $model = Caja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Caja', $model->id);
        return response(null);
    }
}
