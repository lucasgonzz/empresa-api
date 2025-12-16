<?php

namespace App\Models\Afip;

use App\Models\Afip\WS;
use phpWsAfip\Exception\WsnException;
use Illuminate\Support\Facades\Log;

/**
 * WSN (WebService de Negocio).
 *
 * Gestiona el TA (Ticket de Acceso) para cualquier WSN (WebService de Negocio) de AFIP.
 *
 *
 * @author Juan Pablo Candioti (@JPCandioti)
 */
abstract class WSN extends WS
{
    /**
     * $ta
     *
     * @var string      TA activo.
     */
    private $ta;

    /**
     * $ta_expiration_time
     *
     * @var integer     TA activo.
     */
    private $ta_expiration_time;

    /**
     * $ta_cuit
     *
     * @var integer     TA activo.
     */
    private $ta_cuit;

    /**
     * $ta_token
     *
     * @var string      TA activo.
     */
    private $ta_token;

    /**
     * $ta_sign
     *
     * @var string      TA activo.
     */
    private $ta_sign;

    private $cuit_representada;


    /**
     * __construct
     *
     * Constructor de WSN.
     *
     *
     * @param   array   $config     Configuración de WS.
     */
    public function __construct(array $config = array())
    {
        $this->ta                   = null;
        $this->ta_expiration_time   = null;
        $this->ta_cuit              = null;
        $this->ta_token             = null;
        $this->ta_sign              = null;
        $this->cuit_representada    = $config['cuit_representada'];
        $this->for_constancia_de_inscripcion = isset($config['for_constancia_de_inscripcion']) ? $config['for_constancia_de_inscripcion'] : false;
        $this->for_wsfex            = isset($config['for_wsfex']) ? $config['for_wsfex'] : false;
        parent::__construct($config);
    }

    /**
     * setTa
     *
     * Define el TA activo.
     *
     *
     * @param       \SimpleXMLElement   $ta     TA activo.
     * @return      WSSession
     */
    public function setTa(\SimpleXMLElement $ta)
    {
        if (!isset($ta->header->expirationTime) || !isset($ta->credentials->token) || !isset($ta->credentials->sign)) {
            throw new WsnException('El TA es inválido.');
        }elseif (strtotime($ta->header->expirationTime) < time()) {
            throw new WsnException('El TA está vencido');
        }

        // Extraigo las variables de la empresa definidas en el TA.
        $variables = explode(',', $ta->header->destination);
        $destination = array();
        foreach ($variables as $asignacion) {
            list($campo, $valor) = explode('=', trim($asignacion));
            $destination[$campo] = $valor;
        }
        // Extraigo el CUIT definido en el TA.
        if (!isset($destination['SERIALNUMBER'])) {
            throw new WsnException('El TA es inválido. No se encontró el CUIT.');
        }
        preg_match('|^CUIT (\d{11})$|', $destination['SERIALNUMBER'], $arr);

        $this->ta                   = $ta;
        $this->ta_expiration_time   = strtotime($ta->header->expirationTime);
        $this->ta_cuit              = $this->cuit_representada;
        // $this->ta_cuit              = (float) $arr[1];
        $this->ta_token             = (string)$ta->credentials->token;
        $this->ta_sign              = (string)$ta->credentials->sign;
        // $this->for_wsfe             = $this->for_wsfe;
        return $this;
    }

    /**
     * getTa
     *
     * Retorna el TA activo.
     *
     *
     * @return  \SimpleXMLElement   TA activo.
     */
    public function getTa()
    {
        if (!is_null($this->ta_expiration_time) && $this->ta_expiration_time < time()) {
            $this->ta                   = null;
            $this->ta_expiration_time   = null;
            $this->ta_cuit              = null;
            $this->ta_token             = null;
            $this->ta_sign              = null;
        }

        return $this->ta;
    }

    /**
     * setTaExpirationTime
     *
     * Define el Unix Timestamp de expiración del TA activo.
     *
     *
     * @param       integer     $ta_expiration_time     Unix Timestamp de expiración del TA activo.
     * @return      WSSession
     */
    public function setTaExpirationTime(int $ta_expiration_time) {
        $this->ta_expiration_time = $ta_expiration_time;
        
        return $this;
    }
    
    /**
     * getTaExpirationTime
     *
     * Retorna el Unix Timestamp de expiración del TA activo.
     *
     *
     * @return  integer     Unix Timestamp de expiración del TA activo.
     */
    public function getTaExpirationTime()
    {
        return $this->ta_expiration_time;
    }

    /**
     * setTaCuit
     *
     * Define el CUIT del TA activo.
     *
     *
     * @param       float       $ta_cuit    CUIT del TA activo.
     * @return      WSSession
     */
    public function setTaCuit(float $ta_cuit) {
        $this->ta_cuit = (float)$ta_cuit;
        
        return $this;
    }
    
    /**
     * getTaCuit
     *
     * Retorna el CUIT del TA activo.
     *
     *
     * @return  float       CUIT del TA activo.
     */
    public function getTaCuit()
    {
        return $this->ta_cuit;
    }

    /**
     * setTaToken
     *
     * Define el Token del TA activo.
     *
     *
     * @param       string      $ta_token       Token del TA activo.
     * @return      WSSession
     */
    public function setTaToken($ta_token) {
        $this->ta_token = $ta_token;
        
        return $this;
    }
    
    /**
     * getTaToken
     *
     * Retorna el Token del TA activo.
     *
     *
     * @return  string      Token del TA activo.
     */
    public function getTaToken()
    {
        return $this->ta_token;
    }

    /**
     * setTaSign
     *
     * Define la firma del TA activo.
     *
     *
     * @param       string      $ta_sign    Firma del TA activo.
     * @return      WSSession
     */
    public function setTaSign($ta_sign) {
        $this->ta_sign = $ta_sign;
        
        return $this;
    }
    
    /**
     * getTaSign
     *
     * Retorna la firma del TA activo.
     *
     *
     * @return  string      Firma del TA activo.
     */
    public function getTaSign()
    {
        return $this->ta_sign;
    }

    /**
     * setXmlTa
     *
     * Define el TA activo desde un XML.
     *
     *
     * @param       string      $xml    XML del TA activo.
     * @return      WSSession
     */
    public function setXmlTa($xml)
    {
        $ta = new \SimpleXMLElement($xml);
        $this->setTa($ta);

        return $this;
    }

    /**
     * __call
     *
     * Método mágico que ejecuta las funciones definidas en el WebService.
     *
     * @param   string      $name       Nombre de la función del WebService.
     * @param   array       $arguments  Arreglo con los parámetros de la función WebService.
     * @return  \stdClass   Objeto con la estructura de la respuesta del WebService.
     */
    function __call($name, array $arguments)
    {
        if ($this->ta_expiration_time < time()) {
            Log::info('EL wsaa Esta vencido');
        }


        if ($this->for_constancia_de_inscripcion) {

            $datos = array(
                'token'              => $this->ta_token,
                'sign'               => $this->ta_sign,
                'cuitRepresentada'   => $this->ta_cuit,
                'idPersona'          => $arguments[0]['idPersona'],
            );

        } else {

            $datos = array(
                'Auth' => array(
                   'Token'              => $this->ta_token,
                   'Sign'               => $this->ta_sign,
                   'Cuit'               => $this->ta_cuit
                )
            );

            if (
                isset($arguments[1])
                && $arguments[1] === 'incrustar_en_auth'
            ) {

                Log::info('Se va a incrustar_en_auth');

                $params = new \stdClass();

                $params->Auth = new \stdClass();
                $params->Auth->Token = $this->ta_token;
                $params->Auth->Sign = $this->ta_sign;
                $params->Auth->Cuit = $this->ta_cuit;

                $params->Auth->Pto_venta = $arguments[0]['Pto_venta'];
                $params->Auth->Cbte_Tipo = $arguments[0]['Cbte_Tipo'];

                return parent::__call($name, [$params]);

            } else if ($this->for_wsfex) {

                $params = $arguments[0];

                // $params = new \stdClass();

                $params->Auth = new \stdClass();
                $params->Auth->Token = $this->ta_token;
                $params->Auth->Sign = $this->ta_sign;
                $params->Auth->Cuit = $this->ta_cuit;


                return parent::__call($name, [$params]);

            } else if (isset($arguments[0])) {

                $datos += $arguments[0];

            }

        }

        return parent::__call($name, $datos);
        // return parent::__call($name, array($datos));
    }

    function get_manual($datos, $arguments) {

        $token = $datos['Auth']['Token'];
        $sign = $datos['Auth']['Sign'];
        $cuit = $datos['Auth']['Cuit'];

        $manual = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
            <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns="http://ar.gov.afip.dif.fexv1/">
                <soap:Body>
                    <ns1:FEXAuthorize>
                        <ns1:Auth>
                            <ns1:Token>{$token}</ns1:Token>
                            <ns1:Sign>{$sign}</ns1:Sign>
                            <ns1:Cuit>{$cuit}</ns1:Cuit>
                        </ns1:Auth>
        XML;

        $manual .= $arguments;

        $manual .= <<<XML
                    </ns1:FEXAuthorize>
                </soap:Body>
            </soap:Envelope>
        XML;


        return $manual;
    }
}
