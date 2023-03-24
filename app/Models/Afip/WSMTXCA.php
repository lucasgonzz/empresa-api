<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use phpWsAfip\WS\WSN;

class WSMTXCA extends WSN
{
    /**
     * $testing
     *
     * @var boolean     ¿Es servidor de homologación?.
     */
    private $testing;


    /**
     * __construct
     *
     * Constructor de wsmtxca.
     *
     * Valores aceptados en $config:
     * - Todos los valores aceptados de phpWsAfip\WS\WS.
     * - testing            ¿Es servidor de homologación?.
     *
     *
     * @param   array   $config     Configuración de wsmtxca.
     */
    public function __construct(array $config = array())
    {
        $this->testing                  = isset($config['testing'])     ? $config['testing']    : true;

        if (!isset($config['ws_url'])) {
            $config['ws_url']           = $this->testing ? 'https://fwshomo.afip.gov.ar/wsmtxca/services/MTXCAService' : 'https://serviciosjava.afip.gob.ar/wsmtxca/services/MTXCAService';
        }
        
        if (!isset($config['wsdl_cache_file'])) {
            $config['wsdl_cache_file']  = $this->testing ? public_path().'/afip/wsmtxcahomo_wsdl.xml' : public_path().'/afip/wsmtxca_wsdl.xml';
        }

        parent::__construct($config);
    }
}
