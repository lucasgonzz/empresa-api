<?php

namespace App\Http\Controllers;

use App\Models\CurrentAcount;
use Illuminate\Http\Request;

class PagoDeClienteController extends Controller
{
    public function index($from_date = null, $until_date = null) {

        $models = CurrentAcount::where('user_id', $this->userId())
                                ->where('status', 'pago_from_client')
                                ->whereNotNull('client_id')
                                ->whereNull('provider_id')
                                // 'client' agregado (grupo 332, 4/8/2026): el front lee la relacion
                                // embebida en vez de buscar en el store, que no descarga el catalogo
                                // completo de clientes.
                                ->with('current_acount_payment_methods', 'client')
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
