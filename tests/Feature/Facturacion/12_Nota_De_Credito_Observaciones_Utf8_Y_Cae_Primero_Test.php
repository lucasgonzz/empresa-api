<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper;
use App\Models\Afip\WSFE;
use App\Models\AfipError;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Doble de AfipNotaCreditoHelper que NO sale a la red: deja el comprobante de la NC con CAE (lo que
 * ARCA ya autorizo) y despues, si el test lo pide, falla con una excepcion (lo que pasó en Los Peña el
 * 15/10/2026: algo posterior a la autorización tira y el controlador revierte todo).
 */
class EmisorDeNcQueFallaDespuesDelCae extends AfipNotaCreditoHelper
{
    /** @var bool true = el comprobante queda con CAE antes de fallar; false = falla sin CAE. */
    public static $con_cae = true;

    function init()
    {
        $this->create_afip_ticket();

        if (self::$con_cae) {
            $this->update_afip_ticket([
                'cuit_negocio'       => '20000000000',
                'cbte_nro'           => 1,
                'cbte_letra'         => 'B',
                'cbte_tipo'          => 8,
                'importe_total'      => (float) $this->nota_credito->haber,
                'moneda_id'          => 'PES',
                'resultado'          => 'A',
                'concepto'           => 1,
                'cuit_cliente'       => '0',
                'cae'                => '86427822401562',
                'cae_expired_at'     => '2026-10-25',
                'importe_iva'        => 0,
                'afip_fecha_emision' => '2026-10-15',
            ]);
        }

        throw new \RuntimeException('Falla posterior a la autorización de ARCA');
    }
}

/**
 * Las dos fallas de la nota de crédito electrónica medidas en producción en Los Peña (15/10/2026).
 *
 * 1) UTF-8. ARCA contesta en ISO-8859-1 y el SoapClient del sistema trabaja con `encoding =>
 *    ISO-8859-1`, así que el árbol que devuelve trae los textos en latin1. `AfipNotaCreditoHelper::
 *    checkObservations()` los guardaba tal cual en `afip_errors.message`: la observación 10245
 *    ("...Resolución General 5616") rompía el INSERT con MySQL 1366, la excepción subía al catch de
 *    `DevolucionesController::store()` y la transacción se revertía… con la nota YA autorizada por
 *    ARCA. Quedaba un CAE sin ningún registro en el sistema, y un reintento emitía una segunda nota.
 *
 * 2) `CondicionIVAReceptorId`. El código de la NC lo mandaba, igual que la factura, pero el WSDL de
 *    producción commiteado (`public/afip/wsdl/wsfe_wsdl.xml`, marzo de 2025) no declaraba el campo, y
 *    PHP SoapClient descarta en silencio lo que el WSDL no declara: ARCA contestaba 10245 en cada
 *    comprobante (la factura la ignora; la NC la guarda en `afip_errors`, por eso se vio ahí). Desde el
 *    1/12/2026 ARCA rechaza los comprobantes sin ese dato.
 *
 * 🔴 No toca la red: `registrar_respuesta_interna()` es el tramo de `interno()` que procesa la
 * respuesta, y se lo alimenta con el árbol que armaría el SoapClient.
 *
 * @group facturacion
 * @group afip
 * @group devoluciones
 */
class Nota_De_Credito_Observaciones_Utf8_Y_Cae_Primero_Test extends EmpresaTestCase
{
    /** @var array<string,array<int,int>> Ids sembrados, para borrarlos en el tearDown. */
    protected $sembrado = [
        'afip_tickets'     => [],
        'sales'            => [],
        'current_acounts'  => [],
        'afip_information' => [],
        'articles'         => [],
    ];

    protected function tearDown(): void
    {
        if (count($this->sembrado['afip_tickets'])) {
            AfipTicket::withTrashed()->whereIn('id', $this->sembrado['afip_tickets'])->forceDelete();
        }

        // La NC de la prueba por el endpoint la crea el controlador: se la barre por venta.
        if (count($this->sembrado['sales'])) {
            AfipTicket::withTrashed()->whereIn('sale_nota_credito_id', $this->sembrado['sales'])->forceDelete();
            CurrentAcount::whereIn('sale_id', $this->sembrado['sales'])->delete();
            Sale::whereIn('id', $this->sembrado['sales'])->forceDelete();
        }

        if (count($this->sembrado['current_acounts'])) {
            CurrentAcount::whereIn('id', $this->sembrado['current_acounts'])->delete();
        }

        if (count($this->sembrado['afip_information'])) {
            AfipInformation::whereIn('id', $this->sembrado['afip_information'])->delete();
        }

        if (count($this->sembrado['articles'])) {
            Article::whereIn('id', $this->sembrado['articles'])->forceDelete();
        }

        parent::tearDown();
    }

    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    protected function afip_information_de_prueba()
    {
        $iva = IvaCondition::where('name', 'Responsable inscripto')->first();

        if (is_null($iva)) {
            $this->fail('No existe la condición de IVA "Responsable inscripto" en la base de testing: es un problema del fixture, no algo para saltear.');
        }

        $info = AfipInformation::create([
            'user_id'                => $this->usuario_de_testing()->id,
            'iva_condition_id'       => $iva->id,
            'razon_social'           => 'Comercio de test',
            'cuit'                   => '20000000000',
            'punto_venta'            => 3,
            'afip_ticket_production' => 0,
        ]);

        $this->sembrado['afip_information'][] = $info->id;

        return $info;
    }

    /**
     * Venta SIN cliente con su Factura B autorizada y una nota de crédito sin facturar: el caso de Los Peña.
     *
     * @return array
     */
    protected function escenario_de_venta_sin_cliente()
    {
        $usuario = $this->usuario_de_testing();
        $info = $this->afip_information_de_prueba();

        $venta = Sale::create([
            'user_id'   => $usuario->id,
            'moneda_id' => 1,
            'total'     => 1000,
            'total_facturado' => 1000,
            'terminada' => 1,
        ]);
        $this->sembrado['sales'][] = $venta->id;

        $factura = AfipTicket::create([
            'sale_id'             => $venta->id,
            'afip_information_id' => $info->id,
            'cbte_tipo'           => '6',
            'cbte_letra'          => 'B',
            'cbte_numero'         => '1',
            'punto_venta'         => 3,
            'resultado'           => 'A',
            'importe_total'       => 1000,
            'cuit_negocio'        => '20000000000',
            'cae'                 => '86427821922568',
        ]);

        $nota = CurrentAcount::create([
            'detalle'   => 'Nota Credito de prueba',
            'haber'     => 1000,
            'status'    => 'nota_credito',
            'sale_id'   => $venta->id,
            'user_id'   => $usuario->id,
            'moneda_id' => 1,
        ]);

        $articulo = Article::create(['name' => 'Artículo NC utf8', 'user_id' => $usuario->id]);
        $this->sembrado['articles'][] = $articulo->id;

        return ['venta' => $venta, 'factura' => $factura, 'nota' => $nota, 'info' => $info, 'articulo' => $articulo];
    }

    /**
     * El helper armado SIN pasar por el constructor (que arma el WSAA y lee el TA de disco) y con el
     * comprobante de la NC ya creado: es el estado en el que interno() llega a procesar la respuesta.
     *
     * @return array{0: AfipNotaCreditoHelper, 1: array}
     */
    protected function helper_listo_para_procesar_la_respuesta()
    {
        $e = $this->escenario_de_venta_sin_cliente();

        $helper = (new \ReflectionClass(AfipNotaCreditoHelper::class))->newInstanceWithoutConstructor();
        $helper->sale = $e['venta'];
        $helper->nota_credito = $e['nota'];
        $helper->afip_ticket = $e['factura'];
        $helper->create_afip_ticket();
        $this->sembrado['afip_tickets'][] = $helper->created_afip_ticket->id;

        return [$helper, $e];
    }

    /** "Resolución" tal como la manda ARCA: la ó en un solo byte (ISO-8859-1). */
    protected function texto_de_arca_en_latin1()
    {
        return 'El campo Condicion Frente al IVA del receptor resultara obligatorio conforme lo reglamentado por la Resoluci'.chr(0xF3).'n General Nro 5616';
    }

    /**
     * El árbol que arma el SoapClient para una respuesta de FECAESolicitar.
     *
     * @param  string  $resultado      'A' o 'R'.
     * @param  mixed   $observaciones  Contenido de ->Obs (un objeto, o un array de objetos), o null.
     * @return \stdClass
     */
    protected function respuesta_de_arca($resultado, $observaciones = null)
    {
        $detalle = (object) [
            'Concepto' => 1,
            'DocTipo'  => 99,
            'DocNro'   => 0,
            'CAE'      => $resultado == 'A' ? '86427822401562' : '',
            'CAEFchVto' => '20261025',
        ];

        if (!is_null($observaciones)) {
            $detalle->Observaciones = (object) ['Obs' => $observaciones];
        }

        return (object) [
            'FECAESolicitarResult' => (object) [
                'FeCabResp' => (object) ['Cuit' => 20000000000, 'CbteTipo' => 8, 'Resultado' => $resultado],
                'FeDetResp' => (object) ['FECAEDetResponse' => $detalle],
            ],
        ];
    }

    protected function importes()
    {
        return ['total' => 1000, 'iva' => 0];
    }

    /**
     * @test
     */
    public function la_observacion_10245_con_acento_en_latin1_se_guarda_en_utf8_y_la_nota_queda_con_cae()
    {
        list($helper, $e) = $this->helper_listo_para_procesar_la_respuesta();

        $obs = (object) ['Code' => 10245, 'Msg' => $this->texto_de_arca_en_latin1()];

        // Antes del arreglo esto tiraba MySQL 1366 ("Incorrect string value") y no llegaba a escribir el CAE.
        $helper->registrar_respuesta_interna($this->respuesta_de_arca('A', $obs), 1, $this->importes(), 'PES', Carbon::today());

        $error = AfipError::where('afip_ticket_id', $helper->created_afip_ticket->id)->first();

        $this->assertNotNull($error, 'La observación de ARCA tiene que quedar registrada.');
        $this->assertTrue(mb_check_encoding($error->message, 'UTF-8'), 'El mensaje se guarda en UTF-8 válido.');
        $this->assertStringContainsString('Resolución General Nro 5616', $error->message, 'La conversión no puede perder ni cambiar el acento.');
        $this->assertEquals('10245', (string) $error->code);

        $ticket = AfipTicket::find($helper->created_afip_ticket->id);
        $this->assertEquals('86427822401562', $ticket->cae, 'La nota autorizada queda con su CAE.');
        $this->assertEquals(8, (int) $ticket->cbte_tipo);
    }

    /**
     * @test
     */
    public function con_varias_observaciones_se_guardan_todas_en_utf8()
    {
        list($helper, $e) = $this->helper_listo_para_procesar_la_respuesta();

        // Con más de una observación ->Obs es una lista; la rama vieja miraba una clave que no existe y no guardaba ninguna.
        $obs = [
            (object) ['Code' => 10245, 'Msg' => $this->texto_de_arca_en_latin1()],
            (object) ['Code' => 10063, 'Msg' => 'Observaci'.chr(0xF3).'n de prueba'],
        ];

        $helper->registrar_respuesta_interna($this->respuesta_de_arca('A', $obs), 1, $this->importes(), 'PES', Carbon::today());

        $errores = AfipError::where('afip_ticket_id', $helper->created_afip_ticket->id)->orderBy('id')->get();

        $this->assertCount(2, $errores);
        $this->assertEquals(['10245', '10063'], $errores->pluck('code')->map(function ($c) { return (string) $c; })->all());
        $this->assertStringContainsString('Observación de prueba', $errores[1]->message);
    }

    /**
     * @test
     */
    public function un_error_de_arca_con_acento_en_latin1_tambien_se_guarda_en_utf8()
    {
        list($helper, $e) = $this->helper_listo_para_procesar_la_respuesta();

        $respuesta = $this->respuesta_de_arca('R');
        $respuesta->FECAESolicitarResult->Errors = (object) ['Err' => (object) ['Code' => 10016, 'Msg' => 'N'.chr(0xFA).'mero de comprobante inv'.chr(0xE1).'lido']];

        $helper->registrar_respuesta_interna($respuesta, 1, $this->importes(), 'PES', Carbon::today());

        $error = AfipError::where('afip_ticket_id', $helper->created_afip_ticket->id)->first();

        $this->assertNotNull($error);
        $this->assertEquals('Número de comprobante inválido', $error->message);
        $this->assertNull(AfipTicket::find($helper->created_afip_ticket->id)->cae, 'Una nota rechazada no queda con CAE.');
    }

    /**
     * @test
     */
    public function si_falla_el_guardado_de_las_observaciones_el_cae_ya_quedo_escrito()
    {
        list($base, $e) = $this->helper_listo_para_procesar_la_respuesta();

        // Mismo estado, pero con guardar-observaciones roto: cualquier falla posterior al CAE.
        $helper = new class extends AfipNotaCreditoHelper {
            function __construct() {}

            function checkObservations($result) {
                throw new \RuntimeException('falla al guardar la observación');
            }
        };
        $helper->sale = $e['venta'];
        $helper->nota_credito = $e['nota'];
        $helper->afip_ticket = $e['factura'];
        $helper->created_afip_ticket = $base->created_afip_ticket;

        $obs = (object) ['Code' => 10245, 'Msg' => 'x'];

        // No tiene que propagar: la nota ya está autorizada en ARCA.
        $helper->registrar_respuesta_interna($this->respuesta_de_arca('A', $obs), 1, $this->importes(), 'PES', Carbon::today());

        $ticket = AfipTicket::find($base->created_afip_ticket->id);
        $this->assertEquals('86427822401562', $ticket->cae, 'El CAE se escribe ANTES de las observaciones.');
        $this->assertEquals(0.0, (float) Sale::find($e['venta']->id)->total_facturado, 'Y el total facturado de la venta se descuenta.');
    }

    /**
     * @test
     */
    public function si_la_nota_fue_rechazada_un_fallo_al_guardar_el_motivo_si_se_propaga()
    {
        list($base, $e) = $this->helper_listo_para_procesar_la_respuesta();

        $helper = new class extends AfipNotaCreditoHelper {
            function __construct() {}

            function checkObservations($result) {
                throw new \RuntimeException('falla al guardar la observación');
            }
        };
        $helper->sale = $e['venta'];
        $helper->nota_credito = $e['nota'];
        $helper->afip_ticket = $e['factura'];
        $helper->created_afip_ticket = $base->created_afip_ticket;

        $this->expectException(\RuntimeException::class);

        $helper->registrar_respuesta_interna($this->respuesta_de_arca('R', (object) ['Code' => 1, 'Msg' => 'x']), 1, $this->importes(), 'PES', Carbon::today());
    }

    /** Cuerpo de `POST devoluciones` de solo plata sobre la venta, facturando la NC. */
    protected function payload_facturando($e)
    {
        return [
            'sale_id'                   => $e['venta']->id,
            'client_id'                 => null,
            'generar_current_acount'    => false,
            'total_devolucion'          => 1000,
            'observaciones'             => 'test',
            'items'                     => [[
                'is_article'         => true,
                'id'                 => $e['articulo']->id,
                'name'               => 'Artículo NC utf8',
                'unidades_devueltas' => 1,
                'price_vender'       => 1000,
                'costo_real'         => 0,
                'discount'           => 0,
            ]],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => false,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => $e['factura']->id,
        ];
    }

    /**
     * @test
     */
    public function la_devolucion_con_nota_ya_autorizada_no_se_revierte_si_algo_falla_despues()
    {
        $e = $this->escenario_de_venta_sin_cliente();

        EmisorDeNcQueFallaDespuesDelCae::$con_cae = true;
        $this->app->bind(AfipNotaCreditoHelper::class, function ($app, $parametros) {
            return new EmisorDeNcQueFallaDespuesDelCae($parametros['afip_ticket'], $parametros['nota_credito']);
        });

        $this->post('api/devoluciones/', $this->payload_facturando($e))->assertStatus(201);

        $nota = CurrentAcount::where('sale_id', $e['venta']->id)->where('status', 'nota_credito')->where('id', '!=', $e['nota']->id)->first();

        $this->assertNotNull($nota, 'La nota de crédito de la devolución tiene que existir: ARCA ya la autorizó.');

        $ticket = AfipTicket::where('nota_credito_id', $nota->id)->first();

        $this->assertNotNull($ticket, 'El comprobante autorizado no se pierde.');
        $this->assertEquals('86427822401562', $ticket->cae);
    }

    /**
     * @test
     */
    public function la_devolucion_sin_cae_se_revierte_entera_como_siempre()
    {
        $e = $this->escenario_de_venta_sin_cliente();

        EmisorDeNcQueFallaDespuesDelCae::$con_cae = false;
        $this->app->bind(AfipNotaCreditoHelper::class, function ($app, $parametros) {
            return new EmisorDeNcQueFallaDespuesDelCae($parametros['afip_ticket'], $parametros['nota_credito']);
        });

        $this->post('api/devoluciones/', $this->payload_facturando($e))->assertStatus(500);

        $this->assertEquals(
            0,
            CurrentAcount::where('sale_id', $e['venta']->id)->where('status', 'nota_credito')->where('id', '!=', $e['nota']->id)->count(),
            'Sin CAE no hay nada que conservar: la devolución no queda a medias.'
        );
    }

    /**
     * El segundo defecto: el campo tiene que VIAJAR. PHP SoapClient serializa según el WSDL y descarta
     * lo que el WSDL no declara, así que se arma el request de una NC de una venta sin cliente con el
     * SoapClient real sobre cada WSDL commiteado y se lee el XML que saldría.
     *
     * @test
     */
    public function el_wsdl_commiteado_hace_viajar_la_condicion_iva_del_receptor_en_la_nc_sin_cliente()
    {
        $e = $this->escenario_de_venta_sin_cliente();

        // Lo mismo que interno() manda como CondicionIVAReceptorId para la NC B (cbte 8) de esa venta.
        $iva_receptor = CondicionIvaReceptorHelper::get_iva_receptor($e['venta'], 8);
        $this->assertEquals(5, $iva_receptor, 'Venta sin cliente + NC B = Consumidor Final.');

        foreach (['wsfe_wsdl.xml', 'wsfehomo_wsdl.xml'] as $wsdl) {

            $xml = $this->request_que_saldria_para_arca(public_path('afip/wsdl/'.$wsdl), 8, $iva_receptor);

            $this->assertStringContainsString(
                '<ns1:CondicionIVAReceptorId>5</ns1:CondicionIVAReceptorId>',
                $xml,
                $wsdl.' no declara CondicionIVAReceptorId: PHP lo descarta en silencio y ARCA contesta 10245 (desde el 1/12/2026 rechaza).'
            );
            $this->assertStringContainsString('<ns1:CbteTipo>8</ns1:CbteTipo>', $xml);
            $this->assertStringContainsString('<ns1:CbtesAsoc>', $xml, 'La NC sigue llevando el comprobante asociado.');
        }
    }

    /**
     * @test
     */
    public function un_wsdl_en_cache_sin_el_campo_se_considera_vencido_aunque_tenga_fecha_reciente()
    {
        $viejo = tempnam(sys_get_temp_dir(), 'wsdl');
        file_put_contents($viejo, '<definitions><element name="Concepto"/></definitions>');

        $actual = tempnam(sys_get_temp_dir(), 'wsdl');
        file_put_contents($actual, '<definitions><element name="CondicionIVAReceptorId"/></definitions>');

        try {
            $this->assertFalse($this->cache_es_valido($viejo), 'Un WSDL sin CondicionIVAReceptorId no sirve: se tiene que volver a descargar.');
            $this->assertTrue($this->cache_es_valido($actual), 'Un WSDL que declara el campo, recién escrito, sí sirve.');
        } finally {
            @unlink($viejo);
            @unlink($actual);
        }
    }

    protected function cache_es_valido($archivo)
    {
        $wsfe = new WSFE(['testing' => false, 'cuit_representada' => '20000000000', 'wsdl_cache_file' => $archivo]);

        $metodo = new \ReflectionMethod($wsfe, 'is_wsdl_cache_valid');
        $metodo->setAccessible(true);

        return $metodo->invoke($wsfe);
    }

    /**
     * XML SOAP que mandaría FECAESolicitar con una NC de una venta sin cliente, armado por el
     * SoapClient real de PHP sobre un WSDL, sin enviar nada (se corta en __doRequest).
     *
     * @param  string  $wsdl
     * @param  int     $cbte_tipo
     * @param  int     $iva_receptor
     * @return string
     */
    protected function request_que_saldria_para_arca($wsdl, $cbte_tipo, $iva_receptor)
    {
        $cliente = new class($wsdl, ['soap_version' => SOAP_1_1, 'trace' => 1, 'encoding' => 'ISO-8859-1', 'exceptions' => 0, 'cache_wsdl' => WSDL_CACHE_NONE]) extends \SoapClient {
            public $xml;

            function __doRequest($request, $location, $action, $version, $one_way = 0)
            {
                $this->xml = $request;

                return '';
            }
        };

        $cliente->FECAESolicitar([
            'Auth'     => ['Token' => 't', 'Sign' => 's', 'Cuit' => 20000000000],
            'FeCAEReq' => [
                'FeCabReq' => ['CantReg' => 1, 'CbteTipo' => $cbte_tipo, 'PtoVta' => 3],
                'FeDetReq' => [
                    'FECAEDetRequest' => [
                        'Concepto'               => 1,
                        'DocTipo'                => 99,
                        'DocNro'                 => 0,
                        'CbteDesde'              => 1,
                        'CbteHasta'              => 1,
                        'CbteFch'                => '20261015',
                        'ImpTotal'               => 1000,
                        'ImpTotConc'             => 0,
                        'ImpNeto'                => 1000,
                        'ImpOpEx'                => 0,
                        'ImpIVA'                 => 0,
                        'ImpTrib'                => 0,
                        'MonId'                  => 'PES',
                        'MonCotiz'               => 1,
                        'Opcionales'             => null,
                        'CondicionIVAReceptorId' => $iva_receptor,
                        'CbtesAsoc'              => [['Tipo' => 6, 'PtoVta' => 3, 'Nro' => 1, 'Cuit' => '20000000000', 'CbteFch' => '20261015']],
                    ],
                ],
            ],
        ]);

        return (string) $cliente->xml;
    }
}
