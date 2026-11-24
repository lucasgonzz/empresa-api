<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\caja\CajaVisibilidadHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use Illuminate\Http\Request;
use App\Exports\AperturaCajaExport;
use Maatwebsite\Excel\Facades\Excel;

class AperturaCajaController extends Controller
{
    /** El 403 de una caja que el empleado no ve en Tesorería (misión cajas-permisos-claros). */
    const MENSAJE_CAJA_NO_VISIBLE = 'No tenés permiso para ver esta caja en Tesorería. Pedile al dueño que te agregue en la caja, en «Quiénes la ven en Tesorería».';

    /**
     * Respuesta 403 si el usuario autenticado no ve la caja en Tesorería; null si la ve (o si la caja
     * no existe, caso que las acciones resuelven como siempre). Ver CajaVisibilidadHelper.
     *
     * @param int|string|null $caja_id Id de la caja a consultar.
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function rechazo_si_no_ve_la_caja($caja_id) {
        $auth_user = $this->user(false);
        $caja = Caja::where('id', $caja_id)->withAll()->first();

        if (is_null($auth_user) || is_null($caja) || CajaVisibilidadHelper::ve_en_tesoreria($caja, $auth_user)) {
            return null;
        }

        return response()->json(['message' => self::MENSAJE_CAJA_NO_VISIBLE], 403);
    }


    /**
     * Aperturas de una caja, de la más nueva a la más vieja.
     *
     * Sin `page` responde como siempre (`models` con TODAS las aperturas), para que una SPA
     * anterior a la paginación siga andando. Con `page` responde solo esa página y suma el
     * paginador (`total`, `current_page`, `last_page`, `per_page`) al costado de `models`.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $caja_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $caja_id) {
        $rechazo = $this->rechazo_si_no_ve_la_caja($caja_id);

        if (!is_null($rechazo)) {
            return $rechazo;
        }

        $query = AperturaCaja::where('caja_id', $caja_id)
                            ->orderBy('created_at', 'DESC')
                            ->orderBy('id', 'DESC')
                            ->withAll();

        if (!$request->filled('page')) {
            return response()->json(['models' => $query->get()], 200);
        }

        $per_page = (int) $request->input('per_page', 15);
        $per_page = max(1, min($per_page, 100));

        $paginador = $query->paginate($per_page);

        return response()->json([
            'models'        => $paginador->items(),
            'total'         => $paginador->total(),
            'current_page'  => $paginador->currentPage(),
            'last_page'     => $paginador->lastPage(),
            'per_page'      => $paginador->perPage(),
        ], 200);
    }


    public function store(Request $request) {
        $model = AperturaCaja::create([
            'num'                   => $this->num('AperturaCaja'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('AperturaCaja', $model->id);
        return response()->json(['model' => $this->fullModel('AperturaCaja', $model->id)], 201);
    }  

    public function show($id) {
        $apertura = AperturaCaja::find($id);

        if (!is_null($apertura)) {
            $rechazo = $this->rechazo_si_no_ve_la_caja($apertura->caja_id);

            if (!is_null($rechazo)) {
                return $rechazo;
            }
        }

        return response()->json(['model' => $this->fullModel('AperturaCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = AperturaCaja::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('AperturaCaja', $model->id);
        return response()->json(['model' => $this->fullModel('AperturaCaja', $model->id)], 200);
    }

    function reabrir($apertura_caja_id) {
        $apertura_caja = AperturaCaja::find($apertura_caja_id);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($apertura_caja) {
            $caja = Caja::where('id', $apertura_caja->caja_id)->lockForUpdate()->first();
            $apertura_caja = AperturaCaja::where('id', $apertura_caja->id)->lockForUpdate()->first();
            $apertura_caja->cerrada_at = null;
            $apertura_caja->cierre_employee_id = null;
            $apertura_caja->saldo_cierre = null;
            $apertura_caja->save();

            $caja->abierta = 1;
            $caja->cerrada_at = null;
            $caja->current_apertura_caja_id = $apertura_caja->id;
            $caja->save();

            return response(null, 200);
        });
    }

    public function destroy($id) {
        $model = AperturaCaja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('AperturaCaja', $model->id);
        return response(null);
    }

    public function export($id) {
        $apertura_caja = AperturaCaja::find($id);
        return Excel::download(new AperturaCajaExport($id), $apertura_caja->caja->name.' '.$apertura_caja->created_at->format('d-m-y') . '.xlsx');
    }
}
