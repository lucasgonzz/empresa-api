<?php

namespace App\Http\Controllers\Helpers\Afip;

use Illuminate\Support\Facades\Log;

class AfipWSAAHelper {
	
	function __construct($testing, $ws_name = 'wsfe') {
		$this->testing = $testing;
        $this->ws_name = $ws_name;
		$this->define();
	}

    function define() {

        // Directorio de trabajo de WSAA (TRA/TA/CMS por ws_name): ya no vive en public/, porque
        // TA.xml es una credencial viva (token+sign) y estaba siendo descargable por HTTP.
        // Ahora cuelga de storage/app/afip/wsaa (config('services.afip.wsaa_path')), fuera del docroot.
        $wsaa_base_path = config('services.afip.wsaa_path').'/'.$this->ws_name;

        // El directorio de trabajo de WSAA se crea al vuelo: en un servidor recien provisionado no existe,
        // y estos archivos son temporales de firma, no assets versionados.
        if (!is_dir($wsaa_base_path)) {
            mkdir($wsaa_base_path, 0700, true);
        }

        if (!defined('TRA_xml')) {
            define ('TRA_xml', $wsaa_base_path.'/TRA.xml');
        }
        if (!defined('TRA_tmp')) {
            define ('TRA_tmp', $wsaa_base_path.'/TRA.tmp');
        }
        if (!defined('TA_file')) {
            define ('TA_file', $wsaa_base_path.'/TA.xml');
        }
        if (!defined('CMS_file')) {
            define ('CMS_file', $wsaa_base_path.'/CMS.txt');
        }

        if ($this->testing) {
            // Certificado y clave privada de testing: se leen de storage/app/afip (fuera de public/),
            // con ruta configurable por .env vía config/services.php (bloque 'afip').
            $cert_path = config('services.afip.cert_path_testing');
            $key_path = config('services.afip.key_path_testing');
            $this->url_wsaa = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
        } else {
            // Certificado y clave privada de producción: idem testing, ruta configurable por .env.
            $cert_path = config('services.afip.cert_path');
            $key_path = config('services.afip.key_path');
            $this->url_wsaa = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
        }

        // Si falta el certificado o la clave, avisar explícitamente en vez de dejar que falle más
        // abajo con un error de OpenSSL indescifrable (realpath() de un archivo inexistente da false).
        if (!file_exists($cert_path)) {
            throw new \Exception("Falta el certificado de AFIP en {$cert_path}. Ver docs/afip.md.");
        }
        if (!file_exists($key_path)) {
            throw new \Exception("Falta la clave privada de AFIP en {$key_path}. Ver docs/afip.md.");
        }

        $this->cert = 'file://'.realpath($cert_path);
        $this->private_key = 'file://'.realpath($key_path);
    }

    function checkWsaa() {
        if (file_exists(TA_file)) {

            Log::info('file_exists');
            $ta = new \SimpleXMLElement(file_get_contents(TA_file));

            if (!isset($ta->header->expirationTime) || !isset($ta->credentials->token) || !isset($ta->credentials->sign)) {
                Log::info('El TA no tiene los datos necesarios');
                $this->wsaa();
            } else if (strtotime($ta->header->expirationTime) < time()) {

                Log::info('El TA estaba vencido');
                $this->wsaa();
            } else {

                Log::info('Todo TA esta OK');
            }
        } else {

            Log::info('El TA no estaba creado');
            $this->wsaa();
        }
    }

    function wsaa() {

        $this->createTRA();

        $cms = $this->signTRA();
        $ta = $this->callWSAA($cms);
        file_put_contents(TA_file, $ta);
    }

    function createTRA() {

        $tra = new \SimpleXMLElement(
                    '<?xml version="1.0" encoding="UTF-8"?>' .
                    '<loginTicketRequest version="1.0">'.
                    '</loginTicketRequest>');

        $tra->addChild('header');
        $tra->header->addChild('uniqueId',date('U'));
        $tra->header->addChild('generationTime',date('c',date('U')-60));
        $tra->header->addChild('expirationTime',date('c',date('U')+60));
        $tra->addChild('service', $this->ws_name);
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
        // request.xml/response.xml de depuración de WSAA: mismo directorio de trabajo fuera de public/.
        $wsaa_base_path = config('services.afip.wsaa_path').'/'.$this->ws_name;
        file_put_contents($wsaa_base_path."/request.xml",$client->__getLastRequest());
        file_put_contents($wsaa_base_path."/response.xml",$client->__getLastResponse());
        if (is_soap_fault($results)) {
            Log::info("SOAP Fault: ".$results->faultcode."\n".$results->faultstring);
            exit("SOAP Fault: ".$results->faultcode."\n".$results->faultstring."\n");
        }
        return $results->loginCmsReturn;
    }
}