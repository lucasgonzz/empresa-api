<?php 

namespace App\Http\Controllers\Helpers\Afip;

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

class AfipNotaCreditoHelper
{

    public $nota_credito;
    public $monto_minimo_para_factura_de_credito = 546737;

    function __construct($sale, $nota_credito) {
        $this->sale = $sale;
        $this->nota_credito = $nota_credito;
        $this->testing = !$this->nota_credito->sale->afip_information->afip_ticket_production;
    }

    function init() {
        $afip_wsaa = new AfipWSAAHelper($this->testing);
        $afip_wsaa->checkWsaa();

        $sale = $this->notaCredito();
        return response()->json(['model' => $sale], 201);
    }

    function notaCredito() {
        $user = UserHelper::getFullModel();
        $punto_venta = $this->sale->afip_information->punto_venta;
        $cuit_negocio = $this->sale->afip_information->cuit;


        $res = AfipSolicitarCaeHelper::get_doc_client($this->sale);
        $doc_client   = $res['doc_client'];
        $doc_type     = $res['doc_type'];

        $cbte_tipo = $this->getTipoCbte();

        $wsfe = new WSFE(['testing'=> $this->testing, 'cuit_representada' => $cuit_negocio]);
        $wsfe->setXmlTa(file_get_contents(TA_file));

        $cbte_nro = AfipHelper::getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo);

        if (!$cbte_nro['hubo_un_error']) {

            $cbte_nro = $cbte_nro['numero_comprobante'];
        }

        Log::info('articulos para obtener importes:');
        Log::info($this->nota_credito->articles);

        $afip_helper = new AfipHelper($this->sale, $this->nota_credito->articles);
        $importes = $afip_helper->getImportes();
        $today = date('Ymd');
        $moneda_id = 'PES';
        $invoice = array(
            'FeCAEReq' => array(
                'FeCabReq' => array(
                    'CantReg'      => 1,
                    'CbteTipo'     => $cbte_tipo,                   
                    'PtoVta'       => $punto_venta,
                ),
                'FeDetReq' => array(
                    'FECAEDetRequest' => array(
                        'Concepto'     => 1,                
                        'DocTipo'      => $doc_type,           
                        'DocNro'       => $doc_client,
                        'CbteDesde'    => $cbte_nro,
                        'CbteHasta'    => $cbte_nro,
                        'CbteFch'      => $today,
                        'ImpTotal'     => $importes['total'],
                        'ImpTotConc'   => $importes['neto_no_gravado'],
                        'ImpNeto'      => $importes['gravado'],
                        'ImpOpEx'      => $importes['exento'],
                        'ImpIVA'       => $importes['iva'],
                        'ImpTrib'      => 0,
                        'MonId'        => $moneda_id,
                        'MonCotiz'     => 1,
                        'Opcionales'   => $this->getOpcionales(),
                        'CbtesAsoc'    => [
                            [
                                'Tipo'      => $this->sale->afip_ticket->cbte_tipo,
                                'PtoVta'    => $this->sale->afip_ticket->punto_venta,
                                'Nro'       => $this->sale->afip_ticket->cbte_numero,
                                'Cuit'      => $this->sale->afip_ticket->cuit_negocio,
                                'CbteFch'   => date_format($this->sale->afip_ticket->created_at, 'Ymd'),
                            ],
                        ],
                    )
                )
            )
        );
        if (!is_null($this->FchVtoPago())) {
            $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest']['FchVtoPago'] = $this->FchVtoPago();
        }
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

        Log::info('se va a enviar a afip:');
        Log::info($invoice);

        $result = $wsfe->FECAESolicitar($invoice);
        
        Log::info('Resultado:');
        Log::info((array)$result);

        if (!$result['hubo_un_error']) {

            $result = $result['result'];

            $this->checkObservations($result);

            $this->checkErrors($result);

            $this->saveAfipTicket($result, $cbte_nro, $importes['total'], $moneda_id);
        } else {
            Log::info('HUBO UN ERROR:');
            $this->save_error($result);
        }

        return true;
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
    }

    function checkObservations($result) {
        $observations = null;
        if (isset($result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones)) {
            $observations = (array)$result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Observaciones->Obs;

            if (isset($observations['Msg'])) {
                AfipError::create([
                    'message'   => $observations['Msg'],
                    'code'      => $observations['Code'],
                    'sale_id'   => $this->sale->id,
                ]);
            } else {
                foreach ($observations as $observation) {
                    AfipError::create([
                        'message'   => $observation['Msg'],
                        'code'      => $observation['Code'],
                        'sale_id'   => $this->sale->id,
                    ]);
                }
            }
        }
    }

    function save_error($result) {
        if (isset($result['error'])) {
            AfipError::create([
                'message'   => $result['error'],
                'code'      => 'Error del lado de AFIP',
                'sale_id'   => $this->sale->id,
            ]);
        }
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

    function saveAfipTicket($result, $cbte_nro, $importe_total, $moneda_id) {
        if (isset($result->FECAESolicitarResult->FeCabResp) && $result->FECAESolicitarResult->FeCabResp->Resultado == 'A') {
            
            AfipTicket::create([
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
                'nota_credito_id'   => $this->nota_credito->id,
                'sale_nota_credito_id' => $this->sale->id,
            ]);
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

        $cbte_tipo = $this->sale->afip_ticket->cbte_tipo;

        if (SaleHelper::getTotalSale($this->sale) >= $this->monto_minimo_para_factura_de_credito) {


            if ($cbte_tipo == '201') {
                #A
                return  203;
            } else if ($cbte_tipo == '206') {
                #B
                return  208;
            } else if ($cbte_tipo == '211') {
                #C
                return  213;
            }

        } else {

            if ($cbte_tipo == '1') {
                #A
                return  3;
            } else if ($cbte_tipo == '6') {
                #B
                return  8;
            } else if ($cbte_tipo == '11') {
                #C
                return  13;
            }
        } 
    }

    function getTipoLetra($cbte_tipo) {
        Log::info('getTipoLetra: '.$cbte_tipo);
        if ($cbte_tipo == 3 || $cbte_tipo == 203) {
            return 'A';
        }
        if ($cbte_tipo == 8 || $cbte_tipo == 208) {
            return 'B';
        }
        if ($cbte_tipo == 13 || $cbte_tipo == 213) {
            return 'C';
        }
    }

}
