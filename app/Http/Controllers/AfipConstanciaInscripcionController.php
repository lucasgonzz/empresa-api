<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Models\Afip\WSSRConstanciaInscripcion;
use Illuminate\Http\Request;

class AfipConstanciaInscripcionController extends Controller
{
    function get_constancia_inscripcion($cuit) {

        $testing = false;

        $afip_wsaa = new AfipWSAAHelper($testing);
        
        $afip_wsaa->checkWsaa('ws_sr_constancia_inscripcion');


        // $ws = new WSSRConstanciaInscripcion(['testing'=> $testing, 'cuit_representada' => '20423548984']);
        $ws = new WSSRConstanciaInscripcion(['testing'=> $testing, 'cuit_representada' => '20175018841']);
        $ws->setXmlTa(file_get_contents(TA_file));
        
        $result = $ws->getPersona();
        // $result = $ws->getPersona(['idPersona' => '20175018841']);
        
        dd($result);
    }
}
