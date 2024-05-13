<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Models\Afip\WSSRConstanciaInscripcion;
use Illuminate\Http\Request;

class AfipConstanciaInscripcionController extends Controller
{
    function get_constancia_inscripcion($cuit) {

        $testing = false;

        $afip_wsaa = new AfipWSAAHelper($testing, 'wsci');
        
        $afip_wsaa->checkWsaa('ws_sr_constancia_inscripcion');


        $ws = new WSSRConstanciaInscripcion(['testing'=> $testing, 'cuit_representada' => '20423548984']);
        $ws->setXmlTa(file_get_contents(TA_file));

        // 30718519531 Cuit Ferreteria Colman
        
        $response = $ws->getPersona_v2(['idPersona' => $cuit]);

        if (!$response['hubo_un_error']) {

            $result = $response['result'];

            $data = [
                'cuit'          => $result->personaReturn->datosGenerales->idPersona,
                'localidad'     => $result->personaReturn->datosGenerales->domicilioFiscal->localidad,
                'direccion'     => $result->personaReturn->datosGenerales->domicilioFiscal->direccion,
            ];

            if (isset($result->personaReturn->datosGenerales->nombre)) {
                $data['nombre'] = $result->personaReturn->datosGenerales->nombre;
            }

            if (isset($result->personaReturn->datosGenerales->apellido)) {
                $data['apellido'] = $result->personaReturn->datosGenerales->apellido;
            }

            if (isset($result->personaReturn->datosGenerales->razonSocial)) {
                $data['razon_social'] = $result->personaReturn->datosGenerales->razonSocial;
            }

            return [
                'hubo_un_error'     => $response['hubo_un_error'],
                'afip_data'         => $data,
            ];

        } else {

            return [
                'hubo_un_error'     => $response['hubo_un_error'],
                'error'             => $response['error'],
            ];
        }
        

    }
}
