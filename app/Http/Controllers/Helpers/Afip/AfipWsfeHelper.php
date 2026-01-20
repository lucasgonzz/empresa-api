<?php 

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipSolicitarCaeHelper;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Afip\AfipWsHelper;
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

class AfipWsfeHelper extends Controller
{

    public $sale;
    public $errors;
    public $observations;
    public $afip_fecha_emision;
    public $monto_minimo_para_factura_de_credito = 1357480;

    public function __construct($afip_ticket, $testing = null) {

        $this->afip_ticket = $afip_ticket;

        $this->testing = $testing;
        
        if (is_null($testing)) {
            $this->testing = !$afip_ticket->afip_information->afip_ticket_production;
        } 

        $this->init_wsfe();
    
    }

    function procesar() {

        $this->solicitar_cae();

        $this->check_guardad_cuenta_corriente_despues_de_facturar();

        return [
            'errors'            => $this->errors,
            'observations'      => $this->observations,
            'afip_ticket'       => $this->afip_ticket->afip_ticket,
        ];
    }

    function init_wsfe() {

        $this->wsfe = new WSFE([
            'testing'=> $this->testing, 
            'cuit_representada' => $this->afip_ticket->afip_information->cuit
        ]);

        $this->wsfe->setXmlTa(file_get_contents(TA_file));

    }

    function eliminar_errores() {
        AfipObservation::where('sale_id', $this->afip_ticket->id)
                                    ->delete();

        AfipError::where('sale_id', $this->afip_ticket->id)
                                    ->delete();
    }

    function consultar_comprobante() {

        if (
            !is_null($this->afip_ticket->cbte_tipo) 
            && !is_null($this->afip_ticket->cbte_numero) 
            && !is_null($this->afip_ticket->punto_venta)
        ) {

            $invoice = [
                'FeCompConsReq' => [
                    'CbteTipo'  => $this->afip_ticket->cbte_tipo,
                    'CbteNro'   => $this->afip_ticket->cbte_numero,
                    'PtoVta'    => $this->afip_ticket->punto_venta,
               ]
            ];

            $result = $this->wsfe->FECompConsultar($invoice);
            
            Log::info('consultar_comprobante:');

            if (!$result['hubo_un_error']) {

                $afip_result = (array)$result['result'];
                Log::info($afip_result);

                Log::info('va por acaaaa');
                Log::info(isset($afip_result['FECompConsultarResult']));

                if (isset($afip_result['FECompConsultarResult'])) {

                    if (isset($afip_result['FECompConsultarResult']->ResultGet)) {

                        $this->afip_ticket->consultado = 1;
                        $this->afip_ticket->save();

                        $this->limpiar_errores();

                        $data = $afip_result['FECompConsultarResult']->ResultGet;

                        $total_a_facturar = $this->afip_ticket->sale->total;

                        if ($this->afip_ticket->facturar_importe_personalizado) {
                            $total_a_facturar = $this->afip_ticket->facturar_importe_personalizado;
                        }

                        Log::info('total_a_facturar:');
                        Log::info($total_a_facturar);

                        Log::info('$data->ImpTotal:');
                        Log::info($data->ImpTotal);

                        Log::info($data->ImpTotal == $total_a_facturar);
                        Log::info($data->PtoVta == $this->afip_ticket->punto_venta);
                        Log::info($data->CbteTipo == $this->afip_ticket->cbte_tipo);

                        if (
                            $data->ImpTotal == $total_a_facturar
                            && $data->PtoVta == $this->afip_ticket->punto_venta
                            && $data->CbteTipo == $this->afip_ticket->cbte_tipo
                        ) {

                            $this->afip_ticket->update([
                                'cbte_letra'        => AfipWsHelper::getTipoLetra($data->CbteTipo),
                                'importe_total'     => $data->ImpTotal,
                                'moneda_id'         => $data->MonId,
                                'resultado'         => $data->Resultado,
                                // 'concepto'          => $data->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                                // 'cuit_cliente'      => $data->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                                'cae'               => $data->CodAutorizacion,
                                'cae_expired_at'    => $data->FchVto,
                                'request'           => $result['request'],
                                'response'          => $result['response'],
                            ]);
                            Log::info('se actualizo la info del comprobante');

                            AfipWsHelper::update_sale_total_facturado($this->afip_ticket, $total_a_facturar);

                        } else {
                            Log::info('NO se actualizo la info del comprobante');
                        }

                    } else if (isset($result['FECompConsultarResult']->Errors)) {
                        Log::info('Entro en errors:');
                        Log::info((array)$result['FECompConsultarResult']->Errors);
                        $this->error_al_consultar_comprobante = true;
                    }
                } 

            } else {

                Log::info('Hubo un error a consultar comprobante');

                Log::info((array)$result);

                $this->afip_ticket->update([
                    'request'           => $result['request'],
                    'response'          => $result['response'],
                ]);

                $this->save_error($result);
            }
        } 
    }

    function solicitar_cae() {

        Log::info('solicitar_cae');


        $res = AfipSolicitarCaeHelper::get_doc_client($this->afip_ticket->sale);

        $this->doc_client   = $res['doc_client'];
        $this->doc_type     = $res['doc_type'];

        $ok = $this->set_numero_comprobante();

        if (!$ok) return;

        $afip_helper = new AfipHelper($this->afip_ticket);
        $importes = $afip_helper->getImportes();

        Log::info('importes:');
        Log::info($importes);

        if ($importes['total'] <= 0) {
            $this->save_importe_0();
            return; 
        }

        $this->afip_ticket->total_a_facturar = $importes['total'];
        $this->afip_ticket->save();

        $moneda_id = 'PES';
        $iva_receptor = CondicionIvaReceptorHelper::get_iva_receptor($this->afip_ticket->sale);

        $afip_fecha_emision = !is_null($this->afip_ticket->afip_fecha_emision) ? Carbon::parse($this->afip_ticket->afip_fecha_emision)->format('Ymd') : date('Ymd');

        Log::info('afip_fecha_emision');
        Log::info($afip_fecha_emision);

        $invoice = [
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg'      => 1,
                    'CbteTipo'     => $this->comprobante_tipo,                   
                    'PtoVta'       => $this->afip_ticket->afip_information->punto_venta,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => [
                        'Concepto'     => 1,                
                        'DocTipo'      => $this->doc_type,           
                        'DocNro'       => $this->doc_client,
                        'CbteDesde'    => $this->comprobante_numero,
                        'CbteHasta'    => $this->comprobante_numero,
                        'CbteFch'      => $afip_fecha_emision,
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
        if ($this->afip_ticket->afip_information->iva_condition->name == 'Responsable inscripto') {
        // if ($this->afip_ticket->afip_information->iva_condition->name == 'Responsable inscripto' && $importes['iva'] > 0) {
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

            $afip_result = $result['result'];

            $this->checkObservations($afip_result, $result);

            $this->checkErrors($afip_result, $result);

            $this->update_afip_ticket($afip_result, $importes, $moneda_id, $result);
            
            if ($this->afip_ticket->resultado == 'A') {

                AfipWsHelper::update_sale_total_facturado($this->afip_ticket, $importes['total']);
            }

        } else {
            Log::info('HUBO UN ERROR:');
            Log::info((array)$result);

            $this->afip_ticket->update([
                'request'           => $result['request'],
                'response'          => $result['response'],
            ]);

            $this->save_error($result);
        }


    }

    function save_importe_0() {
        AfipError::create([
            'message'           => 'El importe a Facturar debe ser mayor a 0',
            'code'              => 'Omision',
            'afip_ticket_id'    => $this->afip_ticket->id,
            'sale_id'           => $this->afip_ticket->sale->id,
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
                'message'           => $message,
                'code'              => 'Error del lado de AFIP',
                'afip_ticket_id'    => $this->afip_ticket->id,
                'sale_id'           => $this->afip_ticket->sale->id,
                'request'           => isset($result['request']) ? $result['request'] : null,
                'response'          => isset($result['response']) ? $result['response'] : null,
            ]);
        }
    }

    function checkErrors($afip_result, $result) {
        $errors = null;
        if (isset($afip_result->FECAESolicitarResult->Errors)) {
            $errors = $afip_result->FECAESolicitarResult->Errors;
            $errors = Utf8Helper::convertir_utf8($errors);
            Log::info('Errores que van a guardarse:');
            Log::info($errors);
            foreach ($errors as $error) {

                $code = $error['Code']; 
                
                if ($code == 10245) {
                    continue;
                }

                Log::info('Error Mensaje:');
                Log::info($error['Msg']);

                if (
                    $code == 'Could not connect to host'
                    || $code == 'Error Fetching http headers'
                ) {
                    $code = 'No se pudo establecer conexion con AFIP. Intente nuevamente en unos minutos';
                }
                AfipError::create([
                    'message'           => Utf8Helper::convertir_utf8($error['Msg']),
                    'code'              => $code,
                    'sale_id'           => $this->afip_ticket->id,
                    'afip_ticket_id'    => $this->afip_ticket->id,
                    'request'           => $result['request'],
                    'response'          => $result['response'],
                ]);
            }
        }
        $this->errors = $errors;
    }

    function checkObservations($afip_result, $result) {
        $observations = null;
        if (isset($afip_result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones)) {
            $observations = (array)$afip_result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones->Obs;
            // $observations = Utf8Helper::convertir_utf8($observations);
            // Log::info('observations:');
            // Log::info($observations);
            if (isset($observations['Msg'])) {
                if ($observations['Code'] != 10245) {
                    AfipObservation::create([
                        'message'           => Utf8Helper::convertir_utf8($observations['Msg']),
                        'code'              => $observations['Code'],
                        'sale_id'           => $this->afip_ticket->id,
                        'afip_ticket_id'    => $this->afip_ticket->id,
                        'request'           => $result['request'],
                        'response'          => $result['response'],
                    ]);
                }
            } else {
                foreach ($observations as $observation) {
                    $observation = (array)$observation;
                    Log::info('observation:');
                    Log::info($observation);
                    if (
                        $observation['Code'] != 10245
                    ) {
                        AfipObservation::create([
                            'message'           => Utf8Helper::convertir_utf8($observation['Msg']),
                            'code'              => $observation['Code'],
                            'sale_id'           => $this->afip_ticket->id,
                            'afip_ticket_id'    => $this->afip_ticket->id,
                            'request'           => $result['request'],
                            'response'          => $result['response'],
                        ]);
                    }
                }
            }
        }
        $this->observations = $observations;
    }

    function create_afip_ticket() {
        $this->created_afip_ticket = AfipTicket::create([
            'cuit_negocio'      => $this->afip_ticket->afip_information->cuit,
            'iva_negocio'       => $this->afip_ticket->afip_information->iva_condition->name,
            'punto_venta'       => $this->afip_ticket->afip_information->punto_venta,

            'iva_negocio'       => $this->afip_ticket->afip_information->iva_condition->name,
            'iva_cliente'       => !is_null($this->afip_ticket->client) && !is_null($this->afip_ticket->client->iva_condition) ? $this->afip_ticket->client->iva_condition->name : '',
            'sale_id'           => $this->afip_ticket->id,
            'afip_information_id'        => $this->afip_ticket->afip_information_id,
            'afip_tipo_comprobante_id'   => $this->afip_ticket->afip_tipo_comprobante_id,
            'afip_fecha_emision'             => $this->afip_fecha_emision,
        ]);

        $this->afip_ticket->load('afip_ticket');
    }


    function update_afip_ticket($afip_result, $importes, $moneda_id, $result) {
        if (isset($afip_result->FECAESolicitarResult->FeCabResp) && $afip_result->FECAESolicitarResult->FeCabResp->Resultado == 'A') {
            $this->afip_ticket->update([
                'cbte_letra'        => AfipWsHelper::getTipoLetra($afip_result->FECAESolicitarResult->FeCabResp->CbteTipo),
                'importe_total'     => $importes['total'],
                'moneda_id'         => $moneda_id,
                'resultado'         => $afip_result->FECAESolicitarResult->FeCabResp->Resultado,
                'concepto'          => $afip_result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                'cuit_cliente'      => $afip_result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                'cae'               => $afip_result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAE,
                'cae_expired_at'    => $afip_result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAEFchVto,
                'importe_iva'       => $importes['iva'],
                'request'           => $result['request'],
                'response'          => $result['response'],
            ]);
        } 
    }


    function saveAfipTicket($result, $cbte_nro, $importe_total, $moneda_id) {
        if (!isset($result->FECAESolicitarResult->Errors)) {
        // if (!isset($result->FECAESolicitarResult->Errors) && !isset($result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones)) {
            
            // $this->deletePreviusAfipTicket();
            
            $afip_ticket = AfipTicket::create([
                'cuit_negocio'      => $result->FECAESolicitarResult->FeCabResp->Cuit,
                'iva_negocio'       => $this->afip_ticket->afip_information->iva_condition->name,
                'punto_venta'       => $result->FECAESolicitarResult->FeCabResp->PtoVta,
                'cbte_numero'       => $cbte_nro,
                'cbte_letra'        => AfipWsHelper::getTipoLetra($result->FECAESolicitarResult->FeCabResp->CbteTipo),
                'cbte_tipo'         => $result->FECAESolicitarResult->FeCabResp->CbteTipo,
                'importe_total'     => $importe_total,
                'moneda_id'         => $moneda_id,
                'resultado'         => $result->FECAESolicitarResult->FeCabResp->Resultado,
                'concepto'          => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                'cuit_cliente'      => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                'iva_cliente'       => !is_null($this->afip_ticket->client) && !is_null($this->afip_ticket->client->iva_condition) ? $this->afip_ticket->client->iva_condition->name : '',
                'cae'               => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAE,
                'cae_expired_at'    => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAEFchVto,
                'sale_id'           => $this->afip_ticket->id,
            ]);
            return $afip_ticket;
            // echo 'Se creo afip_ticket id: '.$afip_ticket->id.' </br>';
        } 
    }

    function deletePreviusAfipTicket() {
        $afip_ticket = $this->afip_ticket;
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

        if (!is_null($this->afip_ticket->afip_tipo_comprobante_id)) {
            Log::info('hay afip_tipo_comprobante_id: ');
            Log::info($this->afip_ticket->afip_tipo_comprobante->name.', codigo: '.$this->afip_ticket->afip_tipo_comprobante->codigo);
            return $this->afip_ticket->afip_tipo_comprobante->codigo;
        } else {
            Log::info('No entro en afip_tipo_comprobante_id: '.$this->afip_ticket->afip_tipo_comprobante_id);
        }

        if (SaleHelper::getTotalSale($this->afip_ticket) >= $this->monto_minimo_para_factura_de_credito) {

            Log::info('Entro con mas al monto_minimo_para_factura_de_credito: '.SaleHelper::getTotalSale($this->afip_ticket));

            if ($this->afip_ticket->afip_information->iva_condition->name == 'Responsable inscripto') {
                if (!is_null($this->afip_ticket->client) && !is_null($this->afip_ticket->client->iva_condition) && $this->afip_ticket->client->iva_condition->name == 'Responsable inscripto') {
                    return 201; #A
                } else {
                    return 206; #B
                }
            } else if ($this->afip_ticket->afip_information->iva_condition->name == 'Monotributista') {
                return 211; #C
            }
        } else {
            if ($this->afip_ticket->afip_information->iva_condition->name == 'Responsable inscripto') {
                if (!is_null($this->afip_ticket->client) && !is_null($this->afip_ticket->client->iva_condition) && $this->afip_ticket->client->iva_condition->name == 'Responsable inscripto') {

                    if (env('FACTURA_M', false)) {

                        return 51; #A
                    } else {

                        return 1; #A
                    }
                } else {
                    return 6; #B
                }
            } else if ($this->afip_ticket->afip_information->iva_condition->name == 'Monotributista') {
                return 11; #C
            }
        } 
    }

    // function getTipoLetra($cbte_tipo) {
    //     Log::info('getTipoLetra: '.$cbte_tipo);
    //     if ($cbte_tipo == 1 || $cbte_tipo == 201) {
    //         return 'A';
    //     }
    //     if ($cbte_tipo == 6 || $cbte_tipo == 206) {
    //         return 'B';
    //     }
    //     if ($cbte_tipo == 11 || $cbte_tipo == 211) {
    //         return 'C';
    //     }
    //     if ($cbte_tipo == 51) {
    //         return 'M';
    //     }
    // }

    function getPersona() {
        $this->define(true);
        $this->checkWsaa('ws_sr_constancia_inscripcion');


        $this->ws_sr_constancia_inscripcion();
    }

    function set_numero_comprobante() {

        Log::info('Por aca set_numero_comprobante');

        $this->comprobante_tipo = $this->getTipoCbte();

        $result = AfipHelper::getNumeroComprobante(
                                $this->wsfe, 
                                $this->afip_ticket->afip_information->punto_venta, 
                                $this->comprobante_tipo
                            );

        if ($result['hubo_un_error']) {

            $this->save_error($result);

            return false;
        } 

        $this->comprobante_numero = $result['numero_comprobante'];


        $this->afip_ticket->cbte_numero = $this->comprobante_numero;
        $this->afip_ticket->cbte_tipo = $this->comprobante_tipo;
        $this->afip_ticket->save();

        Log::info('Numero comprobante: '.$this->comprobante_numero);

        return true;
    }

    function ws_sr_constancia_inscripcion() {
        $ws = new WSSRConstanciaInscripcion(['testing'=> false, 'cuit_representada' => '20423548984']);
        $ws->setXmlTa(file_get_contents(TA_file));
        
        $result = $ws->getPersona(['idPersona' => '20175018841']);
        dd($result);
    }

    function check_guardad_cuenta_corriente_despues_de_facturar() {
        if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')) {

            if ($this->afip_ticket->afip_ticket->resultado == 'A' 
                && !is_null($this->afip_ticket->client)
                && !$this->afip_ticket->client->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar) {
                $this->afip_ticket->save_current_acount = 1;
                $this->afip_ticket->save();

                SaleHelper::create_current_acount($this->afip_ticket);
            }

        }
    }

    function limpiar_errores() {
        foreach ($this->afip_ticket->afip_errors as $afip_error) {
             $afip_error->delete();
         } 
    }
}
