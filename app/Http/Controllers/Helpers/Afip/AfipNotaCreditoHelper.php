<?php 

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\Afip\AfipSolicitarCaeHelper;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Afip\AfipWsHelper;
use App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\Utf8Helper;
use App\Models\AfipError;
use App\Models\AfipTicket;
use App\Models\Afip\WSAA;
use App\Models\Afip\WSFE;
use App\Models\Afip\WSFEX;
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

    function __construct($afip_ticket, $nota_credito) {
        $this->afip_ticket = $afip_ticket;
        $this->sale = $afip_ticket->sale;
        $this->nota_credito = $nota_credito;
        
        $this->testing = true;

        if ($this->afip_ticket->afip_information->afip_ticket_production) {

            $this->testing = false;
        }
    }

    function init() {

        $this->create_afip_ticket();

        $this->notaCredito();
    }

    function notaCredito() {

        $user = UserHelper::getFullModel();

        $punto_venta = $this->afip_ticket->afip_information->punto_venta;
        $cuit_negocio = $this->afip_ticket->afip_information->cuit;


        $res = AfipSolicitarCaeHelper::get_doc_client($this->sale);
        $doc_client   = $res['doc_client'];
        $doc_type     = $res['doc_type'];

        $cbte_tipo = $this->getTipoCbte();
        Log::info('cbte_tipo para nota de credito: '.$cbte_tipo);

        if ((int)$this->afip_ticket->cbte_tipo == '19') {

            Log::info('Exportacion');
            $this->exportacion($punto_venta, $cuit_negocio, $doc_client, $doc_type, $cbte_tipo);

        } else {

            $this->interno($punto_venta, $cuit_negocio, $doc_client, $doc_type, $cbte_tipo);
        }

        
    }

    function exportacion($punto_venta, $cuit_negocio, $doc_client, $doc_type, $cbte_tipo) {

        $afip_wsaa = new AfipWSAAHelper($this->testing, 'wsfex');
        $afip_wsaa->checkWsaa();

        $wsfex = new WSFEX([
            'testing'           => $this->testing,
            'cuit_representada' => $this->afip_ticket->afip_information->cuit,
        ]);

        $wsfex->setXmlTa(file_get_contents(TA_file));

        $res = AfipFexHelper::set_numero_comprobante($wsfex, $punto_venta, $cbte_tipo);

        if ($res['hubo_un_error']) {
            Log::info('Stop, hubo error');
            return;
        }  

        $cbte_nro = $res['numero_comprobante'];

        $pais_destino = $this->sale->client->pais_exportacion->codigo_afip;
        $idioma_cbte = 1;     // Español
        
        $moneda = 'DOL';
        $moneda_cotiz = $this->sale->valor_dolar;
        if ($this->sale->moneda_id == 1) {
            $moneda = 'PES';
            $moneda_cotiz = 1;
        }



        $data = [
            'Id'                    => $this->sale->id.rand(0,99999),
            'Fecha_cbte'            => date('Ymd'),
            'Cbte_Tipo'             => 21, // Nota de credito de Exportacion
            'Punto_vta'             => $this->afip_ticket->afip_information->punto_venta,
            'Cbte_nro'              => $cbte_nro,
            'Tipo_expo'             => 1,
            'pais_destino'          => $pais_destino,
            'Cliente'               => mb_convert_encoding($this->sale->client->name ?? 'Consumidor Final', 'UTF-8'),
            'Domicilio_cliente'     => mb_convert_encoding($this->sale->client->address ?? '', 'UTF-8'),
            'Id_impositivo'         => $this->sale->client->cuit ?? 'CF',
            'Moneda_Id'             => $moneda,
            'Moneda_cotiz'          => $moneda_cotiz,
            'Idioma_cbte'           => $idioma_cbte,
            'Incoterms'             => $this->sale->incoterms,
            'cuit_emisor'           => $this->afip_ticket->afip_information->cuit,
            'Permiso_existente'     => '',
        ];

        $importe_total = 0;

        foreach ($this->nota_credito->articles as $article) {
            
            $amount = (float)$article->pivot->amount;
            $price = (float)$article->pivot->price;

            $total_item = $price * $amount;

            $item = [];
            $item['Pro_codigo']         = $article->id;
            $item['Pro_ds']             = $article->name;
            $item['Pro_qty']            = $amount;
            $item['Pro_umed']           = 1; // FEXGetPARAM_UMed 
            $item['Pro_precio_uni']     = $price;
            $item['Pro_bonificacion']   = 0;
            $item['Pro_total_item']     = $total_item;

            $importe_total += $total_item;

            $data['items'][] = $item;
        }

        $data['Imp_total'] = $importe_total;

        $data['CbtesAsoc'] = [
            [
                'Tipo'      => 19,
                'PtoVta'    => $this->afip_ticket->afip_information->punto_venta,
                'Nro'       => $this->afip_ticket->cbte_numero,
            ],
        ];

        $params = AfipFexHelper::get_fex_params($data);

        $result = $wsfex->FEXAuthorize($params);

        Log::info('Result nota credito Exportacion:');
        Log::info((array)$result);

        if (!$result['hubo_un_error']) {

            $result = $result['result'];

            $this->checkObservations($result);

            $this->checkErrors($result);

          //   'result' => 
          // (object) array(
          //    'FEXAuthorizeResult' => 
          //   (object) array(
          //      'FEXResultAuth' => 
          //     (object) array(
          //        'Id' => 21418,
          //        'Cuit' => 30716582899,
          //        'Cbte_tipo' => 21,
          //        'Punto_vta' => 4,
          //        'Cbte_nro' => 18,
          //        'Cae' => '75509574157500',
          //        'Fch_venc_Cae' => '20251212',
          //        'Fch_cbte' => '20251212',
          //        'Resultado' => 'A',
          //        'Reproceso' => 'N',
          //        'Motivos_Obs' => '',
          //     ),
          //      'FEXErr' => 
          //     (object) array(
          //        'ErrCode' => 0,
          //        'ErrMsg' => 'OK',
          //     ),
          //      'FEXEvents' => 
          //     (object) array(
          //        'EventCode' => 0,
          //        'EventMsg' => 'Ok',
          //     ),
          //   ),


            if (
                isset($result->FEXAuthorizeResult) 
                && isset($result->FEXAuthorizeResult->FEXResultAuth) 
                && $result->FEXAuthorizeResult->FEXResultAuth->Resultado == 'A'
            ) {

                $data = [
                    'cuit_negocio'      => $result->FEXAuthorizeResult->FEXResultAuth->Cuit,
                    'cbte_nro'          => $result->FEXAuthorizeResult->FEXResultAuth->Cbte_nro,
                    'cbte_letra'        => AfipWsHelper::getTipoLetra($result->FEXAuthorizeResult->FEXResultAuth->Cbte_tipo),
                    'cbte_tipo'         => $result->FEXAuthorizeResult->FEXResultAuth->Cbte_tipo,
                    'importe_total'     => $importe_total,
                    'moneda_id'         => $moneda,
                    'resultado'         => $result->FEXAuthorizeResult->FEXResultAuth->Resultado,
                    'concepto'          => null,
                    'cuit_cliente'      => $this->sale->client->cuit,
                    'cae'               => $result->FEXAuthorizeResult->FEXResultAuth->Cae,
                    'cae_expired_at'    => $result->FEXAuthorizeResult->FEXResultAuth->Fch_venc_Cae,
                ];

                $this->update_afip_ticket($data);
            }
            
        } else {
            Log::info('HUBO UN ERROR:');
            $this->save_error($result);
        }

    }

    function interno($punto_venta, $cuit_negocio, $doc_client, $doc_type, $cbte_tipo) {

        $afip_wsaa = new AfipWSAAHelper($this->testing, 'wsfe');
        $afip_wsaa->checkWsaa();

        $wsfe = new WSFE(['testing'=> $this->testing, 'cuit_representada' => $cuit_negocio]);
        $wsfe->setXmlTa(file_get_contents(TA_file));

        $cbte_nro = AfipHelper::getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo);

        if (!$cbte_nro['hubo_un_error']) {

            $cbte_nro = $cbte_nro['numero_comprobante'];
        }

        // Log::info('articulos para obtener importes:');
        // Log::info($this->nota_credito->articles);

        // Log::info('services para obtener importes:');
        // Log::info($this->nota_credito->services);

        $afip_helper = new AfipHelper($this->afip_ticket, $this->nota_credito->articles, $this->nota_credito->services);
        $importes = $afip_helper->getImportes();
        $today = date('Ymd');
        $moneda_id = 'PES';
        $iva_receptor = CondicionIvaReceptorHelper::get_iva_receptor($this->sale);
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
                        'CondicionIVAReceptorId'    => $iva_receptor,
                        'CbtesAsoc'    => [
                            [
                                'Tipo'      => $this->afip_ticket->cbte_tipo,
                                'PtoVta'    => $this->afip_ticket->punto_venta,
                                'Nro'       => $this->afip_ticket->cbte_numero,
                                'Cuit'      => $this->afip_ticket->cuit_negocio,
                                'CbteFch'   => date_format($this->afip_ticket->created_at, 'Ymd'),
                            ],
                        ],
                    )
                )
            )
        );
        if (!is_null($this->FchVtoPago())) {
            $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest']['FchVtoPago'] = $this->FchVtoPago();
        }
        if ($this->afip_ticket->afip_information->iva_condition->name == 'Responsable inscripto' && $importes['iva'] > 0) {
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


            if (isset($result->FECAESolicitarResult->FeCabResp) && $result->FECAESolicitarResult->FeCabResp->Resultado == 'A') {

                $data = [
                    'cuit_negocio'      => $result->FECAESolicitarResult->FeCabResp->Cuit,
                    'cbte_nro'          => $cbte_nro,
                    'cbte_letra'        => AfipWsHelper::getTipoLetra($result->FECAESolicitarResult->FeCabResp->CbteTipo),
                    'cbte_tipo'         => $result->FECAESolicitarResult->FeCabResp->CbteTipo,
                    'importe_total'     => $importes['total'],
                    'moneda_id'         => $moneda_id,
                    'resultado'         => $result->FECAESolicitarResult->FeCabResp->Resultado,
                    'concepto'          => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->Concepto,
                    'cuit_cliente'      => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->DocNro,
                    'cae'               => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAE,
                    'cae_expired_at'    => $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse->CAEFchVto,
                ];

                $this->update_afip_ticket($data);
            }

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
            $errors = Utf8Helper::convertir_utf8($errors);
            foreach ($errors as $error) {
                AfipError::create([
                    'message'   => $error['Msg'],
                    'code'      => $error['Code'],
                    'sale_id'   => $this->sale->id,
                    'afip_ticket_id'   => $this->created_afip_ticket->id
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
                    'afip_ticket'   => $this->created_afip_ticket->id
                ]);
            } else if (
                isset($observations['Obs'])
                && is_array($observations['Obs'])
            ) {
                foreach ($observations['Obs'] as $observation) {
                    AfipError::create([
                        'message'   => $observation['Msg'],
                        'code'      => $observation['Code'],
                        'sale_id'   => $this->sale->id,
                        'afip_ticket'   => $this->created_afip_ticket->id
                    ]);
                }
            }
        }
    }

    function save_error($result) {
        if (isset($result['error'])) {

            AfipError::create([
                'message'           => $result['error'],
                'code'              => 'Error del lado de AFIP',
                'sale_id'           => $this->sale->id,
                'afip_ticket_id'    => $this->created_afip_ticket->id,
                'request'           => isset($result['request']) ? $result['request'] : null,
                'response'          => isset($result['response']) ? $result['response'] : null,
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


    function create_afip_ticket() {
            
        $this->created_afip_ticket = AfipTicket::create([
            'afip_information_id'               => $this->afip_ticket->afip_information_id,
            'afip_tipo_comprobante_id'          => $this->afip_ticket->afip_tipo_comprobante_id,
            'iva_negocio'                       => $this->afip_ticket->afip_information->iva_condition->name,
            'punto_venta'                       => $this->afip_ticket->afip_information->punto_venta,
            'nota_credito_id'                   => $this->nota_credito->id,
            'iva_cliente'                       => !is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) ? $this->sale->client->iva_condition->name : '',
            'sale_nota_credito_id'              => $this->sale->id,
        ]);
    }


    function update_afip_ticket($data) {
            
        $this->created_afip_ticket->update([
            'cuit_negocio'      => $data['cuit_negocio'],
            'cbte_numero'       => $data['cbte_nro'],
            'cbte_letra'        => $data['cbte_letra'],
            'cbte_tipo'         => $data['cbte_tipo'],
            'importe_total'     => $data['importe_total'],
            'moneda_id'         => $data['moneda_id'],
            'resultado'         => $data['resultado'],
            'concepto'          => $data['concepto'],
            'cuit_cliente'      => $data['cuit_cliente'],
            'cae'               => $data['cae'],
            'cae_expired_at'    => $data['cae_expired_at'],
        ]);
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

        $cbte_tipo = $this->afip_ticket->cbte_tipo;

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


        if ($cbte_tipo == '19') {
            #E
            return  21;
        } 
    }


}
