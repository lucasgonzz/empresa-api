<?php

namespace App\Http\Controllers;

use App\Models\CurrentAcount;
use Illuminate\Http\Request;

class PagoDeClienteController extends Controller
{
    public function index($from_date = null, $until_date = null) {

        // Los criterios y las relaciones viven en CurrentAcount::scopePagosDeClientes, porque las
        // usa tambien el buscador general (modo Historico de la pantalla).
        $models = CurrentAcount::where('user_id', $this->userId())
                                ->pagosDeClientes()
                                ->orderBy('created_at', 'DESC');

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
}
