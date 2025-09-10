<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Utf8Helper;
use App\Models\Afip\WSSRConstanciaInscripcion;
use App\Models\Afip\WSSRPadronA13;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AfipConstanciaInscripcionController extends Controller
{


    public function get_persona_padron_a13($dni)
    {
        $testing = false;

        $afip_wsaa = new AfipWSAAHelper($testing, 'ws_sr_padron_a13');
        $afip_wsaa->checkWsaa('ws_sr_padron_a13');

        $ws = new WSSRPadronA13([
            'testing' => $testing,
            'cuit_representada' => '20423548984',
        ]);

        $ws->setXmlTa(file_get_contents(TA_file));

        $response = $ws->getPersona(['idPersona' => $dni]);

        Log::info('$response:');
        Log::info($response);

        if (!$response['hubo_un_error']) {
            return [
                'hubo_un_error' => false,
                'afip_data' => Utf8Helper::convertir_utf8($response['result']),
            ];
        } else {
            return [
                'hubo_un_error' => true,
                'error' => $response['error'],
            ];
        }
    }

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

            Log::info($response);

            $data = [];

            if (isset($result->personaReturn->datosGenerales->nombre)) {
                $data['nombre'] = $result->personaReturn->datosGenerales->nombre;
            } else if (isset($result->personaReturn->datosGenerales->razonSocial)) {
                $data['nombre'] = $result->personaReturn->datosGenerales->razonSocial;
            }

            if (isset($result->personaReturn->datosGenerales->apellido)) {
                $data['apellido'] = $result->personaReturn->datosGenerales->apellido;
            } else {
                $data['apellido'] = '';
            }

            if (isset($result->personaReturn->datosGenerales->razonSocial)) {
                $data['razon_social'] = $result->personaReturn->datosGenerales->razonSocial;
            }

            $data['cuit']          = $result->personaReturn->datosGenerales->idPersona;
            $data['localidad']     = property_exists($result->personaReturn->datosGenerales->domicilioFiscal, 'localidad') ? $result->personaReturn->datosGenerales->domicilioFiscal->localidad : 'S/A';
            $data['direccion']     = property_exists($result->personaReturn->datosGenerales->domicilioFiscal, 'direccion') ? $result->personaReturn->datosGenerales->domicilioFiscal->direccion : null;
            $data['condicion_iva'] = $this->obtener_condicion_iva($result->personaReturn);

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

    private function obtener_condicion_iva($persona_return)
    {
        if (isset($persona_return->datosMonotributo)) {
            return 'MONOTRIBUTO';
        }

        if (
            isset($persona_return->datosRegimenGeneral->impuesto) &&
            is_array($persona_return->datosRegimenGeneral->impuesto)
        ) {
            foreach ($persona_return->datosRegimenGeneral->impuesto as $imp) {
                if ($imp->descripcionImpuesto === 'IVA') {
                    return 'RESPONSABLE INSCRIPTO';
                }
            }
        }

        return 'NO DETERMINADO';
    }
}
