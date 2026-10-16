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
class WSFE extends WSN
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
            $config['ws_url']           = $this->testing ? 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx' : 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
        }

        if (!isset($config['wsdl_cache_file'])) {
            $config['wsdl_cache_file']  = $this->testing ? public_path().'/afip/wsdl/wsfehomo_wsdl.xml' : public_path().'/afip/wsdl/wsfe_wsdl.xml';
        }

        $config['for_constancia_de_inscripcion'] = false;

        parent::__construct($config);
    }

    /**
     * El WSDL de WSFE tiene que declarar `CondicionIVAReceptorId` (RG 5616): es el campo que el
     * sistema manda en cada comprobante, y si el WSDL no lo conoce PHP lo descarta sin avisar.
     * Un WSDL en cache sin ese campo se trata como vencido y se vuelve a descargar.
     *
     * @return bool
     */
    protected function wsdl_cache_tiene_lo_necesario()
    {
        $contenido = @file_get_contents($this->wsdl_cache_file);

        return $contenido !== false && strpos($contenido, 'CondicionIVAReceptorId') !== false;
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
