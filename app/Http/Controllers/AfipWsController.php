<?php 

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AfipTicket;
use App\Models\Afip\WSAA;
use App\Models\Afip\WSFE;
use App\Models\Afip\WSSRConstanciaInscripcion;
use App\Models\Article;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AfipWsController extends Controller
{

    public $sale;

    function __construct($sale) {
        $this->sale = $sale;
        $this->testing = !$this->sale->afip_information->afip_ticket_production;
    }

    function init() {
        $this->define();
        $service = 'wsfe';
        $this->checkWsaa($service);
        $sale = $this->wsfe();
        return response()->json(['model' => $sale], 201);
    }

    function define() {
        define ('TRA_xml', public_path().'/afip/wsaa/TRA.xml'); 
        define ('TRA_tmp', public_path().'/afip/wsaa/TRA.tmp'); 
        define ('TA_file', public_path().'/afip/wsaa/TA.xml'); 
        define ('CMS_file', public_path().'/afip/wsaa/CMS.txt'); 
        if ($this->testing) {
            $this->cert = 'file://'.realpath(public_path().'/afip/testing/MiCertificado.pem');
            $this->private_key = 'file://'.realpath(public_path().'/afip/testing/MiClavePrivada.key');
            $this->url_wsaa = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
        } else {
            $this->cert = 'file://'.realpath(public_path().'/afip/production/comerciocity-alias_1775a2484a464aa3.crt');
            $this->private_key = 'file://'.realpath(public_path().'/afip/production/privada.key');
            $this->url_wsaa = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
        }
    }

    function checkWsaa($service) {
        if (file_exists(TA_file)) {
            Log::info('file_exists');
            $ta = new \SimpleXMLElement(file_get_contents(TA_file));
            if (!isset($ta->header->expirationTime) || !isset($ta->credentials->token) || !isset($ta->credentials->sign)) {
                Log::info('El TA no tiene los datos necesarios');
                $this->wsaa($service);
            } else if (strtotime($ta->header->expirationTime) < time()) {
                Log::info('El TA estaba vencido');
                $this->wsaa($service);
            } else {
                Log::info('Todo OK');
            }
        } else {
            Log::info('El TA no estaba creado');
            $this->wsaa($service);
        }
    }

    function wsaa($service) {
        $this->createTRA($service);
        $cms = $this->signTRA();
        $ta = $this->callWSAA($cms);
        file_put_contents(TA_file, $ta);
    }

    function createTRA($service) {
        $tra = new \SimpleXMLElement(
                    '<?xml version="1.0" encoding="UTF-8"?>' .
                    '<loginTicketRequest version="1.0">'.
                    '</loginTicketRequest>');
        $tra->addChild('header');
        $tra->header->addChild('uniqueId',date('U'));
        $tra->header->addChild('generationTime',date('c',date('U')-60));
        $tra->header->addChild('expirationTime',date('c',date('U')+60));
        $tra->addChild('service', $service);
        $tra->asXML(TRA_xml);
    }

    function signTRA() {
        $status = openssl_pkcs7_sign(
                                        TRA_xml, 
                                        TRA_tmp, 
                                        $this->cert,
                                        $this->private_key,
                                        array(),
                                        !PKCS7_DETACHED
                                    );
        if (!$status) { 
            exit("ERROR generating PKCS#7 signature\n"); 
        }
        $inf = fopen(TRA_tmp, "r");
        $i = 0;
        $cms = "";
        while (!feof($inf)) {
            $buffer = fgets($inf);
            if ( $i++ >= 4 ) {$cms.=$buffer;}
        }
        fclose($inf);
        unlink(TRA_tmp);
        file_put_contents(CMS_file,$cms);
        // Log::info('cms:');
        // Log::info($cms);
        return $cms;
    }

    function callWSAA($cms) { 
        Log::info('callWSAA');
        $client = new \SoapClient($this->url_wsaa.'?WSDL', array(
            'location' => $this->url_wsaa,
            'trace' => 1,
            'exceptions' => 0
        ));
        $results = $client->loginCms(array('in0'=>$cms));
        file_put_contents(public_path()."/afip/wsaa/request.xml",$client->__getLastRequest());
        file_put_contents(public_path()."/afip/wsaa/response.xml",$client->__getLastResponse());
        if (is_soap_fault($results)) {
            Log::info("SOAP Fault: ".$results->faultcode."\n".$results->faultstring);
            exit("SOAP Fault: ".$results->faultcode."\n".$results->faultstring."\n");
        }
        return $results->loginCmsReturn;
    }

    function wsfe() {
        $user = UserHelper::getFullModel();
        $punto_venta = $this->sale->afip_information->punto_venta;
        $cuit_negocio = $this->sale->afip_information->cuit;
        if ($this->sale->client) {
            $cuit_cliente = $this->sale->client->cuit;
            // $doc_type = AfipHelper::getDocType('Cuit');
            $doc_type = 80;
        } else {
            $cuit_cliente = "NR";
            $doc_type = '99';
        }
        $cbte_tipo = $this->getTipoCbte();
        $wsfe = new WSFE(['testing'=> $this->testing, 'cuit_representada' => $cuit_negocio]);
        // $wsfe = new WSFE(['testing'=> $this->testing, 'cuit_representada' => $cuit_negocio, 'for_wsfe' => true]);
        $wsfe->setXmlTa(file_get_contents(TA_file));
        $cbte_nro = AfipHelper::getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo);
        Log::info('Numero comprobante: '.$cbte_nro);
        Log::info('sigue con importes');

        $afip_helper = new AfipHelper($this->sale);
        $importes = $afip_helper->getImportes();
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
                    )
                )
            )
        );
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
        // print_r($result);
        $this->saveAfipTicket($result, $cbte_nro, $importes['total'], $moneda_id);
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
            'sale_id'           => $this->sale->id,
        ]);
    }

    function getTipoCbte() {
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

    function getTipoLetra($cbte_tipo) {
        Log::info('getTipoLetra: '.$cbte_tipo);
        if ($cbte_tipo == 1) {
            return 'A';
        }
        if ($cbte_tipo == 6) {
            return 'B';
        }
        if ($cbte_tipo == 11) {
            return 'C';
        }
    }

    function getNumeroComprobante($wsfe, $punto_venta, $cbte_tipo) {
        $pto_vta = [
            'PtoVta'    => $punto_venta,
            'CbteTipo'  => $cbte_tipo
        ];
        $result = $wsfe->FECompUltimoAutorizado($pto_vta);
        return $result->FECompUltimoAutorizadoResult->CbteNro + 1;
    }

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
