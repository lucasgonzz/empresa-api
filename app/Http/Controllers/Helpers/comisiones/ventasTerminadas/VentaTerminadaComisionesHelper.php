<?php

namespace App\Http\Controllers\Helpers\comisiones\ventasTerminadas;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\ventasTerminadas\TruvariVentaTerminadaComision;
use Illuminate\Support\Facades\Log;

class VentaTerminadaComisionesHelper {
    
    public $sale;
    public $auth_user_id;

    function __construct($sale, $auth_user_id) {

        $this->sale = $sale;
        $this->auth_user_id = $auth_user_id;

        $this->set_comision();
    }

    function set_comision() {
        
        $comision_function = $this->sale->user->venta_terminada_comision_funcion;
        
        Log::info('comision_function: '.$comision_function);

        if ($comision_function == 'truvari') {

            Log::info('Se va a crear comision por venta termianda para user id: '.$this->auth_user_id);
            new TruvariVentaTerminadaComision($this->sale, $this->auth_user_id);
        }
    }

}