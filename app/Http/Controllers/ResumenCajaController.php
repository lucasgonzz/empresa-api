<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Pdf\ResumenCajaPdf;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\ResumenCaja;
use App\Models\Sale;
use App\Models\TurnoCaja;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResumenCajaController extends Controller
{


    // FROM DATES
    public function index($from_date = null, $until_date = null) {
        $models = ResumenCaja::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {

        $turno = TurnoCaja::findOrFail($request->turno_caja_id);

        if (property_exists($request, 'fecha')) {
            $fecha = $request->fecha;

        } else {

            $fecha = Carbon::today()->format('Y/m/d');
        }

        Log::info('fecha:');
        Log::info($fecha);


        $desde = Carbon::parse($fecha .' '.$turno->hora_inicio);
        $hasta = Carbon::parse($fecha .' '.$turno->hora_fin);

        Log::info('desde: '.$desde->format('d/m/Y H:i:s'));
        Log::info('hasta: '.$hasta->format('d/m/Y H:i:s'));

        // Política: NO obligamos cierre de cajas.
        // Tomamos snapshot de saldo actual de cada caja al momento de generar el resumen.
        // Movimientos: sólo los dentro del [desde, hasta])

        $cajas = Caja::where('address_id', $request->address_id)
                        ->where('employee_id', $request->employee_id)
                        ->get();

        $sales_cc = Sale::where('user_id', $this->userId())
                        ->where('employee_id', $request->employee_id)
                        ->where('created_at', '>=', $desde)
                        ->where('created_at', '<=' ,$hasta)
                        ->whereHas('current_acount')
                        ->where('address_id', $request->address_id)
                        ->get();

        Log::info('cajas: ');
        Log::info($cajas);

        // if ($cajas->count() === 0) {
        //     return response()->json(['message' => 'No hay cajas para la dirección indicada'], 422);
        // }

        return DB::transaction(function () use ($request, $turno, $cajas, $desde, $hasta, $sales_cc, $fecha) {

            $resumen = ResumenCaja::create([
                'address_id'     => $request->address_id ,
                'employee_id'    => $request->employee_id ,
                'turno_caja_id'  => $turno->id,
                'fecha'          => $fecha ,
                'total_ingresos' => 0,
                'total_egresos'  => 0,
                'saldo_apertura' => 0,
                'saldo_cierre'   => 0,
                'user_id'        => $this->userId(),
            ]);

            $total_ingresos = 0;
            $total_egresos  = 0;
            $saldo_apertura    = 0;
            $saldo_cierre    = 0;

            foreach ($cajas as $caja) {

                $apertura = AperturaCaja::where('caja_id', $caja->id)
                                    ->where('created_at', '>=', $desde)
                                    ->where('cerrada_at', '<=' ,$hasta)
                                    ->orderBy('id', 'DESC')
                                    ->first();

                Log::info('apertura de '.$caja->name.' : ');
                Log::info($apertura->toArray());

                if (!$apertura) continue;


                // snapshot de saldo actual al momento de generar el resumen
                // (coincide con tu política de "saldo al momento del resumen")
                $saldo_cierre_turno = (float) ($caja->saldo ?? 0);
                $saldo_inicio_turno = (float) ($caja->saldo ?? 0);

                $resumen->cajas()->attach($caja->id, [
                    'saldo_apertura'   => $apertura->saldo_apertura ?? 0,
                    'saldo_cierre'     => $apertura->saldo_cierre ?? 0,
                    'total_ingresos'   => $apertura->total_ingresos ?? 0,
                    'total_egresos'    => $apertura->total_egresos ?? 0,
                ]);

                $total_ingresos += $apertura->total_ingresos;
                $total_egresos  += $apertura->total_egresos;
                $saldo_apertura  += $apertura->saldo_apertura;
                $saldo_cierre  += $apertura->saldo_cierre;
            }

            $saldo_cuenta_corriente = 0;
            foreach ($sales_cc as $sale) {
                $saldo_cuenta_corriente += $sale->total;
            }
            $total_ingresos += $saldo_cuenta_corriente;

            $resumen->total_ingresos = $total_ingresos;
            $resumen->total_egresos  = $total_egresos;
            $resumen->saldo_apertura  = $saldo_apertura;
            $resumen->saldo_cierre  = $saldo_cierre;
            $resumen->saldo_cuenta_corriente  = $saldo_cuenta_corriente;
            $resumen->save();

            return response()->json(['model' => $this->fullModel('ResumenCaja', $resumen->id)], 201);
        });
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ResumenCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ResumenCaja::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('ResumenCaja', $model->id);
        return response()->json(['model' => $this->fullModel('ResumenCaja', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ResumenCaja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ResumenCaja', $model->id);
        return response(null);
    }

    function pdf($id) {
        $model = ResumenCaja::find($id);
        new ResumenCajaPdf($model);
    }
}
