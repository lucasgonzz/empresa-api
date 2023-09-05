<?php 

namespace App\Http\Controllers;

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

class AfipWsController extends Controller
{

    public $sale;
    public $monto_minimo_para_factura_de_credito = 546737;

    function __construct($sale) {
        $this->sale = $sale;
        $this->testing = !$this->sale->afip_information->afip_ticket_production;
    }

    function init() {
        $afip_wsaa = new AfipWSAAHelper($this->testing);
        $afip_wsaa->checkWsaa();

        $sale = $this->wsfe();
        return response()->json(['model' => $sale], 201);
    }

    function wsfe() {
        $user = UserHelper::getFullModel();
        $punto_venta = $this->sale->afip_information->punto_venta;
        $cuit_negocio = $this->sale->afip_information->cuit;
        if ($this->sale->client) {
            if ($this->sale->client->cuit) {
                $cod_client = $this->sale->client->cuit;
                $doc_type = 80;
            } else if ($this->sale->client->cuil) {
                $cod_client = $this->sale->client->cuil;
                $doc_type = 86;
            }
        } else {
            $cod_client = "NR";
            $doc_type = '99';
        }
        $cbte_tipo = $this->getTipoCbte();
        $wsfe = new WSFE(['testing'=> $this->testing, 'cuit_representada' => $cuit_negocio]);
        $wsfe->setXmlTa(file_get_contents(TA_file));
        $cbte_nro = AfipHelper::getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo);
        Log::info('Numero comprobante: '.$cbte_nro);

        $afip_helper = new AfipHelper($this->sale);
        $importes = $afip_helper->getImportes();
        Log::info('sigue con importes');
        Log::info('importes:');
        Log::info($importes);
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
                        'DocNro'       => $cod_client,
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
        // Se visualiza el resultado con el CAE correspondiente al comprobante.
        $result = $wsfe->FECAESolicitar($invoice);
        Log::info((array)$result);
        $this->saveAfipTicket($result, $cbte_nro, $importes['total'], $moneda_id);
        return true;
    }

    function saveAfipTicket($result, $cbte_nro, $importe_total, $moneda_id) {
        if (is_null($result->FECAESolicitarResult->Errors)) {
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
                'sale_id'           => $this->sale->id,
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

    // function getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo) {
    //     $pto_vta = [
    //         'PtoVta'    => $punto_venta,
    //         'CbteTipo'  => $cbte_tipo
    //     ];
    //     $result = $wsfe->FECompUltimoAutorizado($pto_vta);
    //     return $result->FECompUltimoAutorizadoResult->CbteNro + 1;
    // }

    function getPersona() {
        $this->define(true);
        $this->checkWsaa('ws_sr_constancia_inscripcion');

        // Configuración del servicio WSAA.
        // $config = [
        //     'testing'           => true,                    // Utiliza el servicio de homologación.
        //     'tra_tpl_file'      =>  TRA_tmp        // Define la ubicación de los archivos temporarios con el TRA.
        // ];

        // $wsaa = new WSAA('ws_sr_constancia_inscripcion', $this->cert, $this->private_key, $config);
        // if ($ta = $wsaa->requestTa()) {
        //     // Se visualiza los datos del encabezado.
        //     print_r($ta->header);

        //     // Guardar el XML en una variable. Luego puede almacenarse en una base de datos.
        //     //$xml = $ta->asXml();
        //     //echo $xml;

        //     // Guardar el TA en un archivo.
        //     $ta->asXml(TA_file);
        // }

        $this->ws_sr_constancia_inscripcion();
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
}
