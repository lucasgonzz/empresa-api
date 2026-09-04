<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Models\Afip\WS;
use App\Models\Afip\WSFE;
use Tests\TestCase;

/**
 * Bug real (El Kiosco Verde, produccion, 4/9/2026): un 500 "Malformed UTF-8 characters,
 * possibly incorrectly encoded" al emitir una factura, aunque el CAE ya habia salido bien en
 * ARCA. Causa: ARCA contesta en ISO-8859-1, y casi toda factura autorizada trae la observacion
 * ESTANDAR (Code 10245, sobre la "Condicion Frente al IVA del receptor... Resolucion General
 * 5616") con un byte que no es UTF-8 valido (aparecia como "Resoluci�n" en el log). Ese byte
 * terminaba persistido crudo y explotaba cuando SaleController::makeAfipTicket() armaba
 * response()->json(['sale' => $this->fullModel('Sale', $request->sale_id)], 201) -carga la
 * relacion afip_tickets, que tiene la columna `response` con el byte adentro-.
 *
 * Dos puntos se arreglaron:
 *  1. WS::__call() -PUNTO UNICO, compartido por WSFE y WSFEX (WSN extends WS), o sea por
 *     AfipWsfeHelper Y AfipFexHelper- ahora sanitiza 'request'/'response' (el XML crudo de ARCA,
 *     via __getLastRequest()/__getLastResponse()) antes de devolverlos. Cubre TODA persistencia
 *     de esas dos claves en los dos helpers sin tocar AfipFexHelper.php.
 *  2. AfipWsfeHelper::checkObservations() ahora convierte el array de observaciones ANTES de
 *     mirar el Code, porque el Msg vive adentro del arbol ya deserializado por SoapClient
 *     ($afip_result), que WS::__call() no puede sanitizar sin romper el acceso por '->' que usa
 *     el resto del archivo (checkErrors, update_afip_ticket, etc.). Con Code 10245 -la
 *     observacion mas comun- la rama vieja SALTEABA AfipObservation::create(), que era la UNICA
 *     que convertia el Msg.
 *
 * Como se ejercita SIN RED, mismo criterio que el resto de esta carpeta (ver docblock de
 * Importe_Personalizado_Por_Alicuota_Test): nunca se llama a MakeAfipTicket::make_afip_ticket()
 * ni AfipWsController (dispararian contra ARCA de verdad, y necesitarian TA_file + WSDL reales).
 * Los dos tests llaman al codigo de produccion DIRECTO:
 *  - checkObservations(), via reflection para saltear el constructor de AfipWsfeHelper (que lee
 *    TA_file de disco).
 *  - WS::__call(), el metodo REAL (no un doble de el), inyectandole un SoapClient FALSO por
 *    reflection: nada de red, pero __getLastRequest()/__getLastResponse() del doble devuelven
 *    exactamente el mismo tipo de string con byte invalido que devolveria el SoapClient real.
 *
 * @group facturacion
 * @group afip
 */
class Observacion_10245_Y_Response_Con_Byte_Invalido_Test extends TestCase
{
    /**
     * El byte ISO-8859-1 de una "ó" sin convertir, tal como lo manda ARCA y tal como quedo
     * registrado en el log de produccion ("Resoluci" + este byte + "n General").
     *
     * @return string
     */
    protected function fragmento_con_byte_invalido()
    {
        return "Resoluci" . chr(0xF3) . "n General 5616";
    }

    /**
     * @test
     */
    public function checkObservations_con_code_10245_deja_el_mensaje_en_utf8_valido()
    {
        /**
         * @var AfipWsfeHelper $helper Instanciado SIN pasar por el constructor real: este
         * llama a init_wsfe(), que lee TA_file de disco (no hace falta para probar
         * checkObservations(), que no toca WSFE ni el TA).
         */
        $helper = (new \ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();

        /*
         * afip_ticket no esta declarada como propiedad de la clase (se asigna dinamicamente en
         * el constructor real), asi que un objeto minimo con "id" alcanza: con Code 10245 esta
         * propiedad ni se lee, porque es justo la rama que saltea AfipObservation::create().
         */
        $helper->afip_ticket = (object) ['id' => 999999];

        // Arma el arbol tal como lo devuelve el SoapClient para FECAESolicitar con UNA sola
        // observacion (la mas comun: Code 10245).
        $obs = new \stdClass();
        $obs->Code = 10245;
        $obs->Msg = 'Condicion Frente al IVA del receptor: ' . $this->fragmento_con_byte_invalido();

        $observaciones = new \stdClass();
        $observaciones->Obs = $obs;

        $fe_cae_det_response = new \stdClass();
        $fe_cae_det_response->Observaciones = $observaciones;

        $fe_det_resp = new \stdClass();
        $fe_det_resp->FECAEDetResponse = $fe_cae_det_response;

        $fecae_solicitar_result = new \stdClass();
        $fecae_solicitar_result->FeDetResp = $fe_det_resp;

        $afip_result = new \stdClass();
        $afip_result->FECAESolicitarResult = $fecae_solicitar_result;

        // No debe tirar ninguna excepcion (ni por el Msg invalido, ni por afip_ticket minimo).
        $helper->checkObservations($afip_result, ['request' => 'req', 'response' => 'resp']);

        $this->assertIsArray(
            $helper->observations,
            'checkObservations() tiene que dejar el array crudo en $this->observations, incluso con Code 10245 (no crea AfipObservation en ese caso, pero la propiedad se sigue seteando).'
        );
        $this->assertTrue(
            mb_check_encoding($helper->observations['Msg'], 'UTF-8'),
            'El Msg de la observacion 10245 tiene que quedar en UTF-8 valido. Antes del fix, esta rama saltea AfipObservation::create() -la unica que convertia el Msg- y el byte crudo de ARCA quedaba en $this->observations tal cual.'
        );
        $this->assertStringContainsString(
            'n General 5616',
            $helper->observations['Msg'],
            'La conversion no puede vaciar ni recortar el mensaje: tiene que seguir siendo la misma observacion, solo en UTF-8 valido.'
        );
    }

    /**
     * @test
     */
    public function ws_call_sanitiza_request_y_response_antes_de_devolverlos()
    {
        $wsfe = new WSFE(['testing' => true, 'cuit_representada' => '20111111112']);

        /**
         * @var object $fake_soap_client Doble de \SoapClient con los tres metodos que
         * WS::__call() usa (el llamado dinamico + los dos getters de ultimo request/response).
         * Ninguno toca la red: es exactamente el punto donde se inyectaria si el codigo de
         * produccion usara un SoapClient real contra ARCA.
         */
        $fake_soap_client = new class($this->fragmento_con_byte_invalido()) {
            private $byte_invalido;

            public function __construct($byte_invalido)
            {
                $this->byte_invalido = $byte_invalido;
            }

            public function FECAESolicitar($params)
            {
                return (object) ['ok' => true];
            }

            public function __getLastRequest()
            {
                return '<request>' . $this->byte_invalido . '</request>';
            }

            public function __getLastResponse()
            {
                return '<response>' . $this->byte_invalido . '</response>';
            }
        };

        // WS::$soap_client es protected: se inyecta el doble por reflection para que
        // WS::__call() NO intente crear un \SoapClient real (que bajaria el WSDL de AFIP).
        $propiedad = new \ReflectionProperty(WS::class, 'soap_client');
        $propiedad->setAccessible(true);
        $propiedad->setValue($wsfe, $fake_soap_client);

        // Mismo llamado que hace AfipWsfeHelper::solicitar_cae(): $this->wsfe->FECAESolicitar($invoice).
        $result = $wsfe->FECAESolicitar(['FeCAEReq' => []]);

        $this->assertTrue(
            mb_check_encoding($result['request'], 'UTF-8'),
            "'request' tiene que salir de WS::__call() ya en UTF-8 valido: es el punto UNICO donde se sanitiza, compartido por WSFE y WSFEX."
        );
        $this->assertTrue(
            mb_check_encoding($result['response'], 'UTF-8'),
            "'response' -la columna que AfipWsfeHelper::update_afip_ticket() persiste en TODA factura autorizada, no solo cuando hay observacion- tiene que salir de WS::__call() ya en UTF-8 valido."
        );
        $this->assertStringContainsString(
            'n General 5616',
            $result['response'],
            'Sanitizar no puede vaciar el contenido: tiene que seguir siendo el mismo XML, solo con la "ó" ya convertida.'
        );

        // Cierre del sintoma original: json_encode sobre lo que quedaria persistido no puede
        // fallar con "Malformed UTF-8 characters", que es exactamente el 500 que veia el cliente.
        json_encode(['sale' => ['afip_ticket' => ['response' => $result['response']]]]);
        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            'json_encode no puede fallar sobre el response ya sanitizado: es el mismo armado que hace SaleController::makeAfipTicket() con response()->json().'
        );
    }
}
