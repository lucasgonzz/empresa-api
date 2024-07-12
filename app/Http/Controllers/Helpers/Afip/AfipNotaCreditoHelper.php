<?php 

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
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
        if ($this->sale->client) {
            $cuit_cliente = $this->sale->client->cuit;
            $doc_type = 80;
        } else {
            $cuit_cliente = "NR";
            $doc_type = '99';
        }
        $cbte_tipo = $this->getTipoCbte();
        $wsfe = new WSFE(['testing'=> $this->testing, 'cuit_representada' => $cuit_negocio]);
        $wsfe->setXmlTa(file_get_contents(TA_file));
        $cbte_nro = AfipHelper::getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo);
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
                        'DocNro'       => $cuit_cliente,
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
        // Se visualiza el resultado con el CAE correspondiente al comprobante.
        $result = $wsfe->FECAESolicitar($invoice);
        Log::info((array)$result);
        $this->saveAfipTicket($result['result'], $cbte_nro, $importes['total'], $moneda_id);
        return true;
    }

    function saveAfipTicket($result, $cbte_nro, $importe_total, $moneda_id) {
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
        if (SaleHelper::getTotalSale($this->sale) >= $this->monto_minimo_para_factura_de_credito) {
            if ($this->sale->afip_information->iva_condition->name == 'Responsable inscripto') {
                if (!is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) && $this->sale->client->iva_condition->name == 'Responsable inscripto') {
                    return 203; #A
                } else {
                    return 208; #B
                }
            } else if ($this->sale->afip_information->iva_condition->name == 'Monotributista') {
                return 213; #C
            }
        } else {
            if ($this->sale->afip_information->iva_condition->name == 'Responsable inscripto') {
                if (!is_null($this->sale->client) && !is_null($this->sale->client->iva_condition) && $this->sale->client->iva_condition->name == 'Responsable inscripto') {
                    return 3; #A
                } else {
                    return 8; #B
                }
            } else if ($this->sale->afip_information->iva_condition->name == 'Monotributista') {
                return 13; #C
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
