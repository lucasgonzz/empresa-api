<?php 

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipSolicitarCaeHelper;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\Utf8Helper;
use App\Models\AfipError;
use App\Models\AfipObservation;
use App\Models\AfipTicket;
use App\Models\Afip\WSAA;
use App\Models\Afip\WSFE;
use App\Models\Afip\WSSRConstanciaInscripcion;
use App\Models\Article;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AfipWsController extends Controller
{

    public $sale;
    public $errors;
    public $observations;
    public $monto_minimo_para_factura_de_credito = 1357480;
    // public $monto_minimo_para_factura_de_credito = 546737;

    function __construct($sale) {
        Log::info('AfipWsController __construct sale:');
        $this->sale = $sale;
        Log::info($this->sale);
        $this->testing = !$this->sale->afip_information->afip_ticket_production;

        $this->ya_se_obtuvo_cae_desde_consultar_comprobante = false;

        Log::info('AfipWsController sale id: '.$sale->id.' testing: '.$this->testing);
    }

    function init() {

        $afip_wsaa = new AfipWSAAHelper($this->testing);
        $afip_wsaa->checkWsaa();

        $this->eliminar_errores();
        
        $this->init_wsfe();


        // Si la venta ya tiene un ticket, veo que es lo que paso
        if (!is_null($this->sale->afip_ticket)) {
            $this->consultar_comprobante();
        } else {
            $this->create_afip_ticket();
        }

        if (!$this->ya_se_obtuvo_cae_desde_consultar_comprobante) {


            $this->solicitar_cae();
        }

        $this->check_guardad_cuenta_corriente_despues_de_facturar();


        return [
            'errors'            => $this->errors,
            'observations'      => $this->observations,
            'afip_ticket'       => $this->sale->afip_ticket,
        ];
    }

    function init_wsfe() {

        $this->wsfe = new WSFE([
                            'testing'=> $this->testing, 
                            'cuit_representada' => $this->sale->afip_information->cuit
                        ]);

        $this->wsfe->setXmlTa(file_get_contents(TA_file));

    }

    function eliminar_errores() {
        AfipObservation::where('sale_id', $this->sale->id)
                                    ->delete();

        AfipError::where('sale_id', $this->sale->id)
                                    ->delete();
    }

    function consultar_comprobante() {
        if (!is_null($this->sale->afip_ticket->cbte_tipo) && !is_null($this->sale->afip_ticket->cbte_numero) && !is_null($this->sale->afip_ticket->punto_venta)) {

            $invoice = [
                'FeCompConsReq' => [
                    'CbteTipo' => $this->sale->afip_ticket->cbte_tipo,
                    'CbteNro' => $this->sale->afip_ticket->cbte_numero,
                    'PtoVta' => $this->sale->afip_ticket->punto_venta,
               ]
            ];

            $result = $this->wsfe->FECompConsultar($invoice);
            
            Log::info('consultar_comprobante:');

            if (!$result['hubo_un_error']) {

                $result = (array)$result['result'];
                Log::info($result);

                Log::info('va por acaaaa');
                Log::info(isset($result['FECompConsultarResult']));

                if (isset($result['FECompConsultarResult'])) {
                    if (isset($result['FECompConsultarResult']->ResultGet)) {
                        $data = $result['FECompConsultarResult']->ResultGet;
                        $this->sale->afip_ticket->update([
                            'cbte_letra'        => $this->getTipoLetra($data->CbteTipo),
                            'importe_total'     => $data->ImpTotal,
                            'moneda_id'         => $data->MonId,
                            'resultado'         => $data->Resultado,
                            // 'concepto'          => $data->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                            // 'cuit_cliente'      => $data->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                            'cae'               => $data->CodAutorizacion,
                            'cae_expired_at'    => $data->FchVto,
                        ]);

                        $this->ya_se_obtuvo_cae_desde_consultar_comprobante = true;

                        Log::info('se actualizo la info del comprobante');
                    } else if (isset($result['FECompConsultarResult']->Errors)) {
                        Log::info('Entro en errors:');
                        Log::info((array)$result['FECompConsultarResult']->Errors);
                    }
                } 
            } else {
                Log::info('Hubo un error');
            }
        } 
    }

    function solicitar_cae() {

        Log::info('solicitar_cae');


        $res = AfipSolicitarCaeHelper::get_doc_client($this->sale);
        $this->doc_client   = $res['doc_client'];
        $this->doc_type     = $res['doc_type'];

        $ok = $this->set_numero_comprobante();

        if (!$ok) {
            return;
        }

        $afip_helper = new AfipHelper($this->sale);
        $importes = $afip_helper->getImportes();

        Log::info('importes:');
        Log::info($importes);

        if ($importes['total'] <= 0) {
            $this->save_importe_0();
            return; 
        }

        // if (is_null($this->sale->total_a_facturar)) {
            $this->sale->total_a_facturar = $importes['total'];
            $this->sale->save();
        // }

        $moneda_id = 'PES';
        $iva_receptor = CondicionIvaReceptorHelper::get_iva_receptor($this->sale);
        $invoice = [
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg'      => 1,
                    'CbteTipo'     => $this->comprobante_tipo,                   
                    'PtoVta'       => $this->sale->afip_information->punto_venta,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => [
                        'Concepto'     => 1,                
                        'DocTipo'      => $this->doc_type,           
                        'DocNro'       => $this->doc_client,
                        'CbteDesde'    => $this->comprobante_numero,
                        'CbteHasta'    => $this->comprobante_numero,
                        'CbteFch'      => date('Ymd'),
                        'ImpTotal'     => $importes['total'],
                        'ImpTotConc'   => $importes['neto_no_gravado'],
                        'ImpNeto'      => $importes['gravado'],
                        'ImpOpEx'      => $importes['exento'],
                        'ImpIVA'       => $importes['iva'],
                        'ImpTrib'      => 0,
                        'MonId'        => $moneda_id,
                        'MonCotiz'     => 1,
                        'Opcionales'   => $this->getOpcionales(),
                        'CondicionIVAReceptorId'    => $iva_receptor,
                    ]
                ]
            ]
        ];
        
        if (!is_null($this->FchVtoPago())) {
            $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest']['FchVtoPago'] = $this->FchVtoPago();
        }

        // Si es Responsable inscripto se agregan los importes del IVA
        if ($this->sale->afip_information->iva_condition->name == 'Responsable inscripto') {
        // if ($this->sale->afip_information->iva_condition->name == 'Responsable inscripto' && $importes['iva'] > 0) {
            $ivas = [];
            foreach ($importes['ivas'] as $iva) {
                if ($iva['BaseImp'] > 0) {
                    $ivas[] = [
                        'Id'      => $iva['Id'],
                        'BaseImp' => $iva['BaseImp'],
                        'Importe' => $iva['Importe'],
                    ];
                }
            }
            $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest']['Iva'] = $ivas;
        }
        
        Log::info('invoice:');
        Log::info((array)$invoice);

        // Se visualiza el resultado con el CAE correspondiente al comprobante.
        $result = $this->wsfe->FECAESolicitar($invoice);
        

        if (!$result['hubo_un_error']) {
            Log::info('Resultado:');
            Log::info((array)$result);

            $result = $result['result'];

            $this->checkObservations($result);

            $this->checkErrors($result);

            $this->update_afip_ticket($result, $importes, $moneda_id);
        } else {
            Log::info('HUBO UN ERROR:');
            Log::info((array)$result);
            $this->save_error($result);
        }


    }

    function save_importe_0() {
        AfipError::create([
            'message'   => 'El importe a Facturar debe ser mayor a 0',
            'code'      => 'Omision',
            'sale_id'   => $this->sale->id,
        ]);
    }

    function save_error($result) {
        if (isset($result['error'])) {

            $message = $result['error']; 
            if (
                $message == 'Could not connect to host'
                || $message == 'Error Fetching http headers'
            ) {
                $message = 'No se pudo establecer conexion con AFIP. Intente nuevamente en unos minutos';
            }
            
            AfipError::create([
                'message'   => $message,
                'code'      => 'Error del lado de AFIP',
                'sale_id'   => $this->sale->id,
            ]);
        }
    }

    function checkErrors($result) {
        $errors = null;
        if (isset($result->FECAESolicitarResult->Errors)) {
            $errors = $result->FECAESolicitarResult->Errors;
            $errors = Utf8Helper::convertir_utf8($errors);
            foreach ($errors as $error) {

                $code = $error['Code']; 
                
                if ($code == 10245) {
                    continue;
                }

                if (
                    $code == 'Could not connect to host'
                    || $code == 'Error Fetching http headers'
                ) {
                    $code = 'No se pudo establecer conexion con AFIP. Intente nuevamente en unos minutos';
                }
                AfipError::create([
                    'message'   => $error['Msg'],
                    'code'      => $code,
                    'sale_id'   => $this->sale->id,
                ]);
            }
        }
        $this->errors = $errors;
    }

    function checkObservations($result) {
        $observations = null;
        if (isset($result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones)) {
            $observations = (array)$result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones->Obs;
            // $observations = Utf8Helper::convertir_utf8($observations);
            // Log::info('observations:');
            // Log::info($observations);
            if (isset($observations['Msg'])) {
                if ($observations['Code'] != 10245) {
                    AfipObservation::create([
                        'message'   => $observations['Msg'],
                        'code'      => $observations['Code'],
                        'sale_id'   => $this->sale->id,
                    ]);
                }
            } else {
                foreach ($observations as $observation) {
                    // Log::info('observation:');
                    // Log::info($observation);
                    $observation = (array)$observation;
                    if ($observations['Code'] != 10245) {
                        AfipObservation::create([
                            'message'   => $observation['Msg'],
                            'code'      => $observation['Code'],
                            'sale_id'   => $this->sale->id,
                        ]);
                    }
                }
            }
        }
        $this->observations = $observations;
    }

    function create_afip_ticket() {
        $this->created_afip_ticket = AfipTicket::create([
            'cuit_negocio'      => $this->sale->afip_information->cuit,
            'iva_negocio'       => $this->sale->afip_information->iva_condition->name,
            'punto_venta'       => $this->sale->afip_information->punto_venta,

            'iva_negocio'       => $this->sale->afip_information->iva_condition->name,
            'iva_cliente'       => !is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) ? $this->sale->client->iva_condition->name : '',
            'sale_id'           => $this->sale->id,
        ]);

        $this->sale->load('afip_ticket');
    }


    function update_afip_ticket($result, $importes, $moneda_id) {
        if (isset($result->FECAESolicitarResult->FeCabResp) && $result->FECAESolicitarResult->FeCabResp->Resultado == 'A') {
            $this->sale->afip_ticket->update([
                'cbte_letra'        => $this->getTipoLetra($result->FECAESolicitarResult->FeCabResp->CbteTipo),
                'importe_total'     => $importes['total'],
                'moneda_id'         => $moneda_id,
                'resultado'         => $result->FECAESolicitarResult->FeCabResp->Resultado,
                'concepto'          => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                'cuit_cliente'      => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                'cae'               => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAE,
                'cae_expired_at'    => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAEFchVto,
                'importe_iva'       => $importes['iva'],
            ]);
        } 
    }


    function saveAfipTicket($result, $cbte_nro, $importe_total, $moneda_id) {
        if (!isset($result->FECAESolicitarResult->Errors)) {
        // if (!isset($result->FECAESolicitarResult->Errors) && !isset($result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones)) {
            $this->deletePreviusAfipTicket();
            $afip_ticket = AfipTicket::create([
                'cuit_negocio'      => $result->FECAESolicitarResult->FeCabResp->Cuit,
                'iva_negocio'       => $this->sale->afip_information->iva_condition->name,
                'punto_venta'       => $result->FECAESolicitarResult->FeCabResp->PtoVta,
                'cbte_numero'       => $cbte_nro,
                'cbte_letra'        => $this->getTipoLetra($result->FECAESolicitarResult->FeCabResp->CbteTipo),
                'cbte_tipo'         => $result->FECAESolicitarResult->FeCabResp->CbteTipo,
                'importe_total'     => $importe_total,
                'moneda_id'         => $moneda_id,
                'resultado'         => $result->FECAESolicitarResult->FeCabResp->Resultado,
                'concepto'          => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                'cuit_cliente'      => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                'iva_cliente'       => !is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) ? $this->sale->client->iva_condition->name : '',
                'cae'               => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAE,
                'cae_expired_at'    => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAEFchVto,
                'sale_id'           => $this->sale->id,
            ]);
            return $afip_ticket;
            // echo 'Se creo afip_ticket id: '.$afip_ticket->id.' </br>';
        } 
    }

    function deletePreviusAfipTicket() {
        $afip_ticket = $this->sale->afip_ticket;
        if (!is_null($afip_ticket)) {
            // echo 'Se elimino el ticket id: '.$afip_ticket->id.' </br>';
            $afip_ticket->delete();
        }
    }

    function FchVtoPago() {
        if ($this->getTipoCbte() > 200) {
            return Carbon::today()->addDays(30)->format('Ymd');
        }
        return null;
    }

    function getOpcionales() {
        if ($this->getTipoCbte() > 200) {
            return [
                [
                    'Id'    => '2101',
                    'Valor' => '0070177420000002704140',
                ],
                [
                    'Id'    => 27,
                    'Valor' => 'SCA',
                ],
            ];
        }
        return null;
    }

    function getTipoCbte() {

        if (!is_null($this->sale->afip_tipo_comprobante_id)) {
            Log::info('hay afip_tipo_comprobante_id: ');
            Log::info($this->sale->afip_tipo_comprobante->name.', codigo: '.$this->sale->afip_tipo_comprobante->codigo);
            return $this->sale->afip_tipo_comprobante->codigo;
        } else {
            Log::info('No entro en afip_tipo_comprobante_id: '.$this->sale->afip_tipo_comprobante_id);
        }

        if (SaleHelper::getTotalSale($this->sale) >= $this->monto_minimo_para_factura_de_credito) {

            Log::info('Entro con mas al monto_minimo_para_factura_de_credito: '.SaleHelper::getTotalSale($this->sale));

            if ($this->sale->afip_information->iva_condition->name == 'Responsable inscripto') {
                if (!is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) && $this->sale->client->iva_condition->name == 'Responsable inscripto') {
                    return 201; #A
                } else {
                    return 206; #B
                }
            } else if ($this->sale->afip_information->iva_condition->name == 'Monotributista') {
                return 211; #C
            }
        } else {
            if ($this->sale->afip_information->iva_condition->name == 'Responsable inscripto') {
                if (!is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) && $this->sale->client->iva_condition->name == 'Responsable inscripto') {

                    if (env('FACTURA_M', false)) {

                        return 51; #A
                    } else {

                        return 1; #A
                    }
                } else {
                    return 6; #B
                }
            } else if ($this->sale->afip_information->iva_condition->name == 'Monotributista') {
                return 11; #C
            }
        } 
    }

    function getTipoLetra($cbte_tipo) {
        Log::info('getTipoLetra: '.$cbte_tipo);
        if ($cbte_tipo == 1 || $cbte_tipo == 201) {
            return 'A';
        }
        if ($cbte_tipo == 6 || $cbte_tipo == 206) {
            return 'B';
        }
        if ($cbte_tipo == 11 || $cbte_tipo == 211) {
            return 'C';
        }
        if ($cbte_tipo == 51) {
            return 'M';
        }
    }

    function getPersona() {
        $this->define(true);
        $this->checkWsaa('ws_sr_constancia_inscripcion');


        $this->ws_sr_constancia_inscripcion();
    }

    function set_numero_comprobante() {

        Log::info('POr aca set_numero_comprobante');

        $this->comprobante_tipo = $this->getTipoCbte();
        $result = AfipHelper::getNumeroComprobante(
                                        $this->wsfe, 
                                        $this->sale->afip_information->punto_venta, 
                                        $this->comprobante_tipo
                                    );

        if ($result['hubo_un_error']) {

            $this->save_error($result);

            return false;
        } 

        $this->comprobante_numero = $result['numero_comprobante'];

        $afip_ticket = $this->sale->afip_ticket;

        $afip_ticket->cbte_numero = $this->comprobante_numero;
        $afip_ticket->cbte_tipo = $this->comprobante_tipo;
        $afip_ticket->save();

        Log::info('Numero comprobante: '.$this->comprobante_numero);

        return true;
    }

    function ws_sr_constancia_inscripcion() {
        $ws = new WSSRConstanciaInscripcion(['testing'=> false, 'cuit_representada' => '20423548984']);
        $ws->setXmlTa(file_get_contents(TA_file));
        
        $result = $ws->getPersona(['idPersona' => '20175018841']);
        // $result = $ws->getPersona_v2(['idPersona' => '20175018841']);
        // Log::info($result);
        // print($result);
        dd($result);
        // print_r($result);
    }

    // function convertir_utf8($value) {
    //     if (is_object($value)) {
    //         $value = (array)$value;
    //     }
    //     if(is_array($value)) {
    //         foreach($value as $key => $val) {
    //             $value[$key] = $this->convertir_utf8($val);
    //         }
    //         return $value;
    //     } else if(is_string($value)) {
    //         $value = $this->limpiar_cadena($value);
    //         $value = str_replace("\'", "", $value);
    //         return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    //     } else {
    //         return $value;
    //     }
    // }

    // function limpiar_cadena($value) {
    //     return preg_replace('/[^\x{0020}-\x{007E}\x{00A0}-\x{00FF}]/u', '', $value);
    // }

    function check_guardad_cuenta_corriente_despues_de_facturar() {
        if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')) {

            if ($this->sale->afip_ticket->resultado == 'A' 
                && !is_null($this->sale->client)
                && !$this->sale->client->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar) {
                $this->sale->save_current_acount = 1;
                $this->sale->save();

                SaleHelper::create_current_acount($this->sale);
            }

        }
    }
}
