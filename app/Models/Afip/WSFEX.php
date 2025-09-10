<?php

namespace App\Models\Afip;

use SoapClient;
use SoapFault;
use App\Models\Afip\WSN;

class WSFEX extends WSN
{
    protected $client;
    protected $auth;
    protected $wsdl = 'https://wswhomo.afip.gov.ar/wsfexv1/service.asmx?WSDL'; // testing
    protected $wsdl_production = 'https://servicios1.afip.gov.ar/wsfexv1/service.asmx?WSDL';
    protected $service = 'wsfexv1';

    public function __construct($config)
    {
        $this->testing = $config['testing'];

        if (!isset($config['ws_url'])) {
            $config['ws_url']           = $this->testing ? 'https://wswhomo.afip.gov.ar/wsfexv1/service.asmx' : 'https://servicios1.afip.gov.ar/wsfexv1/service.asmx';
        }

        if (!isset($config['wsdl_cache_file'])) {
            $config['wsdl_cache_file']  = $this->testing ? public_path().'/afip/wsdl/wsfexhomo_wsdl.xml' : public_path().'/afip/wsdl/wsfex_wsdl.xml';
        }

        $config['for_constancia_de_inscripcion'] = false;
        $config['for_wsfex'] = true;

        parent::__construct($config);
    }

}
