<?php 

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipSolicitarCaeHelper;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AfipError;
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
        Log::info('AfipWsController __construct');
        $this->sale = $sale;
        $this->testing = !$this->sale->afip_information->afip_ticket_production;

        $this->ya_se_obtuvo_cae_desde_consultar_comprobante = false;

        Log::info('AfipWsController sale id: '.$sale->id);
    }

    function init() {

        $afip_wsaa = new AfipWSAAHelper($this->testing);
        $afip_wsaa->checkWsaa();

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
            Log::info((array)$result);

            if (isset($result->FECompConsultarResult)) {
                if (isset($result->FECompConsultarResult->ResultGet)) {
                    $data = $result->FECompConsultarResult->ResultGet;
                    $this->sale->afip_ticket->update([
                        'cbte_letra'        => $this->getTipoLetra($data->CbteTipo),
                        'importe_total'     => $data->ImpTotal,
                        'moneda_id'         => $data->MonId,
                        'resultado'         => $data->Resultado,
                        'concepto'          => $data->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                        'cuit_cliente'      => $data->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                        'cae'               => $data->CodAutorizacion,
                        'cae_expired_at'    => $data->FchVto,
                    ]);

                    $this->ya_se_obtuvo_cae_desde_consultar_comprobante = true;

                    Log::info('se actualizo la info del comprobante');
                } else if (isset($result->FECompConsultarResult->Errors)) {
                    Log::info('Entro en errors:');
                    Log::info((array)$result->FECompConsultarResult->Errors);
                }
            }
        } 
    }

    function solicitar_cae() {

        Log::info('solicitar_cae');


        $res = AfipSolicitarCaeHelper::get_doc_client($this->sale);
        $this->doc_client   = $res['doc_client'];
        $this->doc_type     = $res['doc_type'];

        $this->set_numero_comprobante();

        $afip_helper = new AfipHelper($this->sale);
        $importes = $afip_helper->getImportes();

        Log::info('importes:');
        Log::info($importes);

        $moneda_id = 'PES';
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
                    ]
                ]
            ]
        ];
        
        if (!is_null($this->FchVtoPago())) {
            $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest']['FchVtoPago'] = $this->FchVtoPago();
        }

        // Si es Responsable inscripto se agregan los importes del IVA
        if ($this->sale->afip_information->iva_condition->name == 'Responsable inscripto' && $importes['iva'] > 0) {
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

            $this->update_afip_ticket($result, $importes['total'], $moneda_id);
        }


    }

    function checkErrors($result) {
        $errors = null;
        if (isset($result->FECAESolicitarResult->Errors)) {
            $errors = $result->FECAESolicitarResult->Errors;
            $errors = $this->convertir_utf8($errors);
            foreach ($errors as $error) {
                AfipError::create([
                    'message'   => $error['Msg'],
                    'code'      => $error['Code'],
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
            // $observations = $this->convertir_utf8($observations);
            Log::info('observations:');
            Log::info($observations);
            if (isset($observations['Msg'])) {
                AfipError::create([
                    'message'   => $observations['Msg'],
                    'code'      => $observations['Code'],
                    'sale_id'   => $this->sale->id,
                ]);
            } else {
                foreach ($observations as $observation) {
                    // Log::info('observation:');
                    // Log::info($observation);
                    AfipError::create([
                        'message'   => $observation['Msg'],
                        'code'      => $observation['Code'],
                        'sale_id'   => $this->sale->id,
                    ]);
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


    function update_afip_ticket($result, $importe_total, $moneda_id) {
        if (isset($result->FECAESolicitarResult->FeCabResp) && $result->FECAESolicitarResult->FeCabResp->Resultado == 'A') {
            $this->sale->afip_ticket->update([
                'cbte_letra'        => $this->getTipoLetra($result->FECAESolicitarResult->FeCabResp->CbteTipo),
                'importe_total'     => $importe_total,
                'moneda_id'         => $moneda_id,
                'resultado'         => $result->FECAESolicitarResult->FeCabResp->Resultado,
                'concepto'          => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                'cuit_cliente'      => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                'cae'               => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAE,
                'cae_expired_at'    => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAEFchVto,
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
                    return 1; #A
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
    }

    function getPersona() {
        $this->define(true);
        $this->checkWsaa('ws_sr_constancia_inscripcion');


        $this->ws_sr_constancia_inscripcion();
    }

    function set_numero_comprobante() {

        Log::info('POr aca set_numero_comprobante');

        $this->comprobante_tipo = $this->getTipoCbte();
        $this->comprobante_numero = AfipHelper::getNumeroComprobante(
                                        $this->wsfe, 
                                        $this->sale->afip_information->punto_venta, 
                                        $this->comprobante_tipo
                                    );

        $afip_ticket = $this->sale->afip_ticket;

        $afip_ticket->cbte_numero = $this->comprobante_numero;
        $afip_ticket->cbte_tipo = $this->comprobante_tipo;
        $afip_ticket->save();

        Log::info('Numero comprobante: '.$this->comprobante_numero);
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

    function convertir_utf8($value) {
        if (is_object($value)) {
            $value = (array)$value;
        }
        if(is_array($value)) {
            foreach($value as $key => $val) {
                $value[$key] = $this->convertir_utf8($val);
            }
            return $value;
        } else if(is_string($value)) {
            $value = $this->limpiar_cadena($value);
            $value = str_replace("\'", "", $value);
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } else {
            return $value;
        }
    }

    function limpiar_cadena($value) {
        return preg_replace('/[^\x{0020}-\x{007E}\x{00A0}-\x{00FF}]/u', '', $value);
    }

    function check_guardad_cuenta_corriente_despues_de_facturar() {
        if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')) {

            if ($this->sale->afip_ticket->resultado == 'A' 
                && !is_null($this->sale->client)
                && !$this->sale->client->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar) {
                $this->sale->save_current_acount = 1;
                $this->sale->save();

                SaleHelper::attachCurrentAcountsAndCommissions($this->sale);
            }

        }
    }
}
