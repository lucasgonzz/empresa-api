<?php

namespace App\Models\Afip;

class WSSRPadronA13 extends WSN
{
    private $testing;

    public function __construct(array $config = array())
    {
        $this->testing = isset($config['testing']) ? $config['testing'] : true;

        if (!isset($config['ws_url'])) {
            $config['ws_url'] = $this->testing
                ? 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL'
                : 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13';
        }

        if (!isset($config['wsdl_cache_file'])) {
            $config['wsdl_cache_file'] = $this->testing
                ? public_path() . '/afip/wsdl/wbsr_padron_a13_homo_wsdl.xml'
                : public_path() . '/afip/wsdl/wbsr_padron_a13_wsdl.xml';
        }

        if (!isset($config['for_constancia_de_inscripcion'])) {

            $config['for_constancia_de_inscripcion'] = true;
        }

        parent::__construct($config);
    }

    // public function get_persona_a13($dni)
    // {
    //     $this->initializeSoapClient();

    //     try {
    //         $response = $this->soap_client->getPersona(['idPersona' => $dni]);

    //         return [
    //             'hubo_un_error' => false,
    //             'result' => $response,
    //         ];
    //     } catch (\Exception $e) {
    //         return [
    //             'hubo_un_error' => true,
    //             'error' => $e->getMessage(),
    //         ];
    //     }
    // }
}
