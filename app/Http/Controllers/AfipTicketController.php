<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;

class AfipTicketController extends Controller
{
    function problemas_al_facturar() {

        $sales_with_afip_errors = Sale::where('user_id', $this->userId())
                            ->with('address')
                            ->with('afip_errors')
                            ->with('afip_observations')
                            ->with('employee')
                            ->whereHas('afip_errors')
                            ->orderBy('created_at', 'ASC');

        if (!$this->is_admin()) {
            $sales_with_afip_errors = $sales_with_afip_errors->where('employee_id', $this->userId(false));
        }

        $sales_with_afip_errors = $sales_with_afip_errors->get();

        $sales_with_afip_observations = Sale::where('user_id', $this->userId())
                            ->with('address')
                            ->with('afip_errors')
                            ->with('afip_observations')
                            ->whereHas('afip_observations')
                            ->with('employee')
                            ->orderBy('created_at', 'ASC');

        if (!$this->is_admin()) {
            $sales_with_afip_observations = $sales_with_afip_observations->where('employee_id', $this->userId(false));
        }

        $sales_with_afip_observations = $sales_with_afip_observations->get();

        

        $errores_de_facturacion = [];

        foreach ($sales_with_afip_errors as $sale_afip_error) {
            
            if (!is_null($sale_afip_error->afip_ticket)
                && (
                    is_null($sale_afip_error->afip_ticket->cae)
                    || $sale_afip_error->afip_ticket->cae == ''
                )
            ) {

                $errores_de_facturacion[] = $sale_afip_error;
            }
        }

        foreach ($sales_with_afip_observations as $sale_afip_obs) {
            
            if (!is_null($sale_afip_obs->afip_ticket)
                && (
                    is_null($sale_afip_obs->afip_ticket->cae)
                    || $sale_afip_obs->afip_ticket->cae == ''
                )
            ) {

                $errores_de_facturacion[] = $sale_afip_obs;
            }
        }

        return response()->json(['models' => $errores_de_facturacion], 200);
    }       
}
