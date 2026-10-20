<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\TenenciaDeLaTiendaHelper;
use App\Models\DeliveryDay;
use Illuminate\Http\Request;

class DeliveryDayController extends Controller
{
    public function index()
    {
        $activeDays = DeliveryDay::where('user_id', $this->userId())
                                ->orderBy('day_of_week', 'ASC')
                                ->get();

        return response()->json(['models' => $activeDays], 200);
    }

    public function store(Request $request)
    {

        $model = DeliveryDay::create([
            'day_of_week'   => $request->day_of_week,
            'user_id'       => $this->userId(),
        ]);

        return response()->json(['model' => $model], 201);
    }

    public function update(Request $request, $id)
    {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = DeliveryDay::find($id);

        $model->day_of_week = $request->day_of_week;
        $model->save();

        return response()->json(['model' => $model], 200);
    }

    public function destroy($id)
    {
        $ajeno = $this->registro_ajeno_o_404($id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = DeliveryDay::find($id);

        $model->delete();

        return response(null, 200);
    }

    /**
     * El día de entrega tiene que ser del comercio de la sesión.
     *
     * Devuelve null si el registro es del dueño de la sesión y, si no, el 404 que el método contesta
     * tal cual (ajeno, inexistente y no-id dan lo mismo). Ver `TenenciaDeLaTiendaHelper`.
     *
     * @param  mixed  $id  Lo que llegó en la ruta.
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function registro_ajeno_o_404($id) {
        return TenenciaDeLaTiendaHelper::respuesta_si_no_es_del_dueno(DeliveryDay::class, $id, $this->userId(), 'El día de entrega no existe o no es de tu cuenta.');
    }
}
