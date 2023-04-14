<?php

namespace App\Models\Afip;

use App\Models\Afip\WSN;

/**
 * WSFE (WebService de Facturación Electrónica).
 *
 * Permite interactuar con el WSFEv1.
 * Precisa un TA activo.
 *
 *
 * @author Juan Pablo Candioti (@JPCandioti)
 */
class WSSRConstanciaInscripcion extends WSN
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
     * Constructor de WSFE.
     *
     * Valores aceptados en $config:
     * - Todos los valores aceptados de phpWsAfip\WS\WS.
     * - testing            ¿Es servidor de homologación?.
     *
     *
     * @param   array   $config     Configuración de WSFE.
     */
    public function __construct(array $config = array())
    {
        $this->testing                  = isset($config['testing']) ? $config['testing'] : true;

        if (!isset($config['ws_url'])) {
            $config['ws_url']           = $this->testing ? 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5?WSDL' : 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5';
        }

        if (!isset($config['wsdl_cache_file'])) {
            $config['wsdl_cache_file']  = $this->testing ? public_path().'/afip/wsdl/wbsr_constancia_inscripcion_homo_wsdl.xml' : public_path().'/afip/wsdl/wbsr_constancia_inscripcion_wsdl.xml';
        }

        parent::__construct($config);
    }

    /**
     * isTesting
     *
     * Retorna si utiliza servicio de homologación.
     *
     *
     * @return      boolean                 ¿Utiliza servicio de homologación?
     */
    public function isTesting()
    {
        return $this->testing;
    }
}
