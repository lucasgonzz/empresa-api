<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Utf8Helper;
use App\Models\Afip\WSSRConstanciaInscripcion;
use App\Models\Afip\WSSRPadronA13;
use Illuminate\Support\Facades\Log;

class AfipConstanciaInscripcionController extends Controller
{

    /**
     * Resuelve datos de persona para el modal de alta de cliente: CUIT (11 dígitos) por constancia
     * de inscripción; DNI (7 u 8 dígitos) por padrón A13 (lista por documento + detalle por idPersona).
     *
     * @param string $normalized_digits Solo dígitos (sin guiones).
     * @return array Estructura alineada con get_constancia_inscripcion: hubo_un_error, error opcional, afip_data opcional.
     */
    public function resolve_afip_lookup_for_client_modal(string $normalized_digits)
    {
        $length = strlen($normalized_digits);

        if ($length === 11) {
            return $this->get_constancia_inscripcion($normalized_digits);
        }

        if ($length >= 7 && $length <= 8) {
            return $this->get_afip_data_padron_a13_by_dni($normalized_digits);
        }

        return [
            'hubo_un_error' => true,
            'error'         => 'El identificador debe ser un CUIT (11 dígitos) o un DNI (7 u 8 dígitos).',
        ];
    }

    /**
     * Obtiene idPersona (CUIT) por número de documento y luego el detalle con getPersona del A13,
     * mapeado al mismo formato plano que usa el frontend (ModalResult).
     *
     * @param string $dni_digits DNI solo dígitos (7 u 8).
     * @return array
     */
    private function get_afip_data_padron_a13_by_dni(string $dni_digits)
    {
        $testing = false;

        $afip_wsaa = new AfipWSAAHelper($testing, 'ws_sr_padron_a13');
        $afip_wsaa->checkWsaa('ws_sr_padron_a13');

        $ws = new WSSRPadronA13([
            'testing'           => $testing,
            'cuit_representada' => '20423548984',
        ]);

        $ws->setXmlTa(file_get_contents(TA_file));

        $list_response = $ws->getIdPersonaListByDocumento(['documento' => $dni_digits]);

        Log::info('getIdPersonaListByDocumento response padron A13');
        Log::info($list_response);

        if ($list_response['hubo_un_error']) {
            return [
                'hubo_un_error' => true,
                'error'         => $list_response['error'],
            ];
        }

        $list_result = $list_response['result'];

        if (!is_object($list_result) || !isset($list_result->idPersonaListReturn)) {
            return [
                'hubo_un_error' => true,
                'error'         => 'Respuesta inválida del padrón A13 (sin idPersonaListReturn).',
            ];
        }

        $id_list_return = $list_result->idPersonaListReturn;
        $id_persona_values = [];

        if (isset($id_list_return->idPersona)) {
            $raw_ids = $id_list_return->idPersona;

            if (is_array($raw_ids)) {
                foreach ($raw_ids as $one_id) {
                    if ($one_id !== null && $one_id !== '') {
                        $id_persona_values[] = (int) $one_id;
                    }
                }
            } else {
                if ($raw_ids !== null && $raw_ids !== '') {
                    $id_persona_values[] = (int) $raw_ids;
                }
            }
        }

        if (count($id_persona_values) === 0) {
            return [
                'hubo_un_error' => true,
                'error'         => 'No se encontró persona con ese DNI en AFIP.',
            ];
        }

        // Se usa el primer CUIT devuelto (varios son poco frecuentes en consumo interno).
        $id_persona = $id_persona_values[0];

        $detail_response = $ws->getPersona(['idPersona' => $id_persona]);

        if ($detail_response['hubo_un_error']) {
            return [
                'hubo_un_error' => true,
                'error'         => $detail_response['error'],
            ];
        }

        $mapped = $this->map_padron_a13_get_persona_result_to_afip_modal_data($detail_response['result'], $dni_digits);

        if (count($mapped) === 0) {
            return [
                'hubo_un_error' => true,
                'error'         => 'No se pudo interpretar la respuesta de AFIP para ese DNI.',
            ];
        }

        return [
            'hubo_un_error' => false,
            'afip_data'     => Utf8Helper::convertir_utf8($mapped),
        ];
    }

    /**
     * Convierte el resultado de getPersona del padrón A13 al mismo shape que get_constancia_inscripcion
     * (nombre, apellido, cuit, dirección, localidad, provincia, condicion_iva, dni consultado).
     *
     * @param mixed  $soap_wrapper Objeto devuelto por el cliente SOAP (contiene personaReturn).
     * @param string $consulted_dni DNI original consultado (solo dígitos), para persistir en cliente.
     * @return array
     */
    private function map_padron_a13_get_persona_result_to_afip_modal_data($soap_wrapper, string $consulted_dni)
    {
        if (!is_object($soap_wrapper) || !isset($soap_wrapper->personaReturn)) {
            return [];
        }

        $persona_return = $soap_wrapper->personaReturn;

        if (!isset($persona_return->persona) || !is_object($persona_return->persona)) {
            return [];
        }

        $p = $persona_return->persona;

        $data = [];

        if (isset($p->razonSocial) && (string) $p->razonSocial !== '') {
            $data['nombre']       = (string) $p->razonSocial;
            $data['apellido']     = '';
            $data['razon_social'] = (string) $p->razonSocial;
        } else {
            $data['nombre']   = isset($p->nombre) ? (string) $p->nombre : '';
            $data['apellido'] = isset($p->apellido) ? (string) $p->apellido : '';
        }

        $data['cuit'] = isset($p->idPersona) ? (string) $p->idPersona : '';
        $data['dni']  = isset($p->numeroDocumento) && (string) $p->numeroDocumento !== ''
            ? (string) $p->numeroDocumento
            : $consulted_dni;

        $domicilio = null;

        if (isset($p->domicilio)) {
            if (is_array($p->domicilio) && count($p->domicilio) > 0) {
                $domicilio = $p->domicilio[0];
            } elseif (is_object($p->domicilio)) {
                $domicilio = $p->domicilio;
            }
        }

        if (is_object($domicilio)) {
            $data['localidad'] = isset($domicilio->localidad) && (string) $domicilio->localidad !== ''
                ? (string) $domicilio->localidad
                : 'Sin asignar';
            $data['provincia'] = isset($domicilio->descripcionProvincia) && (string) $domicilio->descripcionProvincia !== ''
                ? (string) $domicilio->descripcionProvincia
                : 'Sin asignar';
            if (isset($domicilio->direccion) && (string) $domicilio->direccion !== '') {
                $data['direccion'] = (string) $domicilio->direccion;
            } elseif (isset($domicilio->calle)) {
                $calle  = isset($domicilio->calle) ? (string) $domicilio->calle : '';
                $numero = isset($domicilio->numero) ? (string) $domicilio->numero : '';
                $data['direccion'] = trim($calle.' '.$numero) ?: null;
            } else {
                $data['direccion'] = null;
            }
        } else {
            $data['localidad'] = 'Sin asignar';
            $data['provincia'] = 'Sin asignar';
            $data['direccion'] = null;
        }

        // El padrón A13 no expone el mismo bloque de régimen que constancia; se deja coherente con el modal.
        $data['condicion_iva'] = 'NO DETERMINADO';

        return $data;
    }

    /**
     * @deprecated Uso interno legacy; preferir resolve_afip_lookup_for_client_modal o get_afip_data_padron_a13_by_dni.
     */
    public function get_persona_padron_a13($dni)
    {
        return $this->get_afip_data_padron_a13_by_dni($dni);
    }

    function get_constancia_inscripcion($cuit) {

        Log::info('get_constancia_inscripcion');

        $testing = false;

        $afip_wsaa = new AfipWSAAHelper($testing, 'ws_sr_constancia_inscripcion');
        $afip_wsaa->checkWsaa();
        
        // $afip_wsaa = new AfipWSAAHelper($testing, 'wsci');
        // $afip_wsaa->checkWsaa('ws_sr_constancia_inscripcion');

        Log::info('WSSRConstanciaInscripcion');

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

            $data['localidad']     =  property_exists($result->personaReturn->datosGenerales, 'domicilioFiscal') && property_exists($result->personaReturn->datosGenerales->domicilioFiscal, 'localidad') ? $result->personaReturn->datosGenerales->domicilioFiscal->localidad : 'Sin asignar';
			$data['provincia']     = property_exists($result->personaReturn->datosGenerales->domicilioFiscal, 'descripcionProvincia') ? $result->personaReturn->datosGenerales->domicilioFiscal->descripcionProvincia : 'Sin asignar';

            $data['direccion']     = property_exists($result->personaReturn->datosGenerales->domicilioFiscal, 'direccion') ? $result->personaReturn->datosGenerales->domicilioFiscal->direccion : null;

            $data['condicion_iva'] = $this->obtener_condicion_iva($result->personaReturn);

            return [
                'hubo_un_error'     => $response['hubo_un_error'],
                'afip_data'         => Utf8Helper::convertir_utf8($data),
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
