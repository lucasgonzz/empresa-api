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
        
        if (!defined('TRA_xml')) {
            define ('TRA_xml', public_path().'/afip/wsaa/'.$this->ws_name.'/TRA.xml'); 
        }
        if (!defined('TRA_tmp')) {
            define ('TRA_tmp', public_path().'/afip/wsaa/'.$this->ws_name.'/TRA.tmp'); 
        }
        if (!defined('TA_file')) {
            define ('TA_file', public_path().'/afip/wsaa/'.$this->ws_name.'/TA.xml'); 
        }
        if (!defined('CMS_file')) {
            define ('CMS_file', public_path().'/afip/wsaa/'.$this->ws_name.'/CMS.txt'); 
        }

        if ($this->testing) {
            $this->cert = 'file://'.realpath(public_path().'/afip/testing/MiCertificado.pem');
            $this->private_key = 'file://'.realpath(public_path().'/afip/testing/MiClavePrivada.key');
            $this->url_wsaa = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
        } else {
            $this->cert = 'file://'.realpath(public_path().'/afip/production/comerciocity-alias_43833c08ea3711fd.crt');
            $this->private_key = 'file://'.realpath(public_path().'/afip/production/privada.key');
            $this->url_wsaa = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
        }
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
        file_put_contents(public_path()."/afip/wsaa/".$this->ws_name."/request.xml",$client->__getLastRequest());
        file_put_contents(public_path()."/afip/wsaa/".$this->ws_name."/response.xml",$client->__getLastResponse());
        if (is_soap_fault($results)) {
            Log::info("SOAP Fault: ".$results->faultcode."\n".$results->faultstring);
            exit("SOAP Fault: ".$results->faultcode."\n".$results->faultstring."\n");
        }
        return $results->loginCmsReturn;
    }
}