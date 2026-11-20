<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Http\Controllers\Helpers\Afip\TransporteDeFacturaHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Article;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\EmpresaTestCase;

/**
 * Misión arca-facturas-fallidas (20/11/2026) — lo que ve la persona en la pantalla de una venta con una
 * factura fallida, probado por los endpoints REALES de la interfaz (sin tocar controladores):
 * `POST api/afip-ticket`, `GET api/afip-ticket/consultar-comprobante/{id}`, `DELETE api/afip-ticket/{id}`
 * y `DELETE api/sale/{id}`.
 *
 * ─── La decisión de Lucas (20/11/2026 14:26) ──────────────────────────────────────────────────
 *
 * 🔴 Sólo se libera un intento con una prueba firme de que ARCA no lo procesó: el 600 de la EMISIÓN
 * (autenticación rechazada antes de procesar el comprobante), sin CAE. Un 602 al consultar («no existen
 * datos») NO es esa prueba —ARCA puede seguir procesando el envío—: el intento queda protegido contra el
 * borrado de la factura, de la venta y contra una segunda emisión, también 10 y 30 minutos después.
 *
 * ─── Cómo se prueba ───────────────────────────────────────────────────────────────────────────
 *
 * `TransporteDeFacturaHelper` (el borde de red de `MakeAfipTicket`) se reemplaza con
 * `$this->app->instance()` (patrón de `EmisionValidadaTest`) por una subclase que corre el
 * `AfipWsfeHelper` REAL con un doble de SOAP: se ejecutan de verdad la numeración, `checkErrors()`, la
 * escritura del ticket y la consulta. Sin WSAA, sin certificado y sin red.
 *
 * @group facturacion
 * @group afip
 */
class Intento_Rechazado_Se_Borra_Por_Los_Endpoints_Test extends EmpresaTestCase
{
    /** Código de ARCA de la Factura B. */
    const FACTURA_B = 6;

    /**
     * Ids de los artículos creados por este archivo, para borrarlos en el tearDown.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * Precio de la próxima venta (cada una con otro total por el candado antiduplicados).
     *
     * @var float
     */
    protected $proximo_precio = 12100.00;

    /**
     * Transporte simulado registrado en el contenedor.
     *
     * @var \Tests\Feature\Facturacion\TransporteDeFacturaConArcaSimulado
     */
    protected $transporte;

    /**
     * Registra el transporte simulado antes de cada test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->transporte = new TransporteDeFacturaConArcaSimulado();
        $this->app->instance(TransporteDeFacturaHelper::class, $this->transporte);
    }

    /**
     * Borra los artículos que creó el test antes del rollback de `DatabaseTransactions`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->articulos_creados) >= 1) {
            Article::whereIn('id', $this->articulos_creados)->forceDelete();
        }

        parent::tearDown();
    }

    // =========================================================================================
    // CA4 + CA7 — el 600 firme se destraba por los endpoints
    // =========================================================================================

    /**
     * CA4/CA7 — `POST api/afip-ticket` con un 600 de autenticación en `FECAESolicitar`: el endpoint
     * responde `rechazada` y deja el intento en `R`. Después se borra la factura (200) y la venta (200).
     *
     * Contra el código anterior falla: el intento quedaba `pendiente_verificacion` y el `DELETE` daba 422.
     *
     * @test
     */
    public function test_rechazo_600_firme_permite_borrar_factura_y_venta()
    {
        $venta = $this->crear_venta();
        $this->transporte->guiones_de_emision = [['fecae' => 'auth_600']];

        $respuesta = $this->postJson('api/afip-ticket', $this->cuerpo($venta));

        $respuesta->assertStatus(201);
        $this->assertSame('rechazada', $respuesta->json('estado'), 'Un 600 firme tiene que informarse como rechazada, no como pendiente.');
        $ticket = AfipTicket::where('sale_id', $venta->id)->firstOrFail();
        $this->assertSame('R', $ticket->resultado);
        $this->assertTrue(empty($ticket->cae));

        $this->deleteJson('api/afip-ticket/'.$ticket->id)->assertStatus(200);
        $this->assertSoftDeleted('afip_tickets', ['id' => $ticket->id]);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);
        $this->assertNull(Sale::find($venta->id), 'La venta tiene que quedar borrada.');
        $this->assertSame(1, $this->transporte->emisiones);
    }

    /**
     * CA7 — Tras el rechazo 600, en vez de borrar se vuelve a emitir: nace UN ticket nuevo (2 en total
     * contando el rechazado, que queda borrado por la factura que lo supera) y se registran 2 emisiones.
     *
     * @test
     */
    public function test_tras_rechazo_600_reemitir_crea_un_solo_ticket_nuevo()
    {
        $venta = $this->crear_venta();
        $this->transporte->guiones_de_emision = [['fecae' => 'auth_600'], ['fecae' => 'autorizada']];

        $this->postJson('api/afip-ticket', $this->cuerpo($venta))->assertStatus(201);
        $respuesta = $this->postJson('api/afip-ticket', $this->cuerpo($venta));

        $respuesta->assertStatus(201);
        $this->assertSame('emitida', $respuesta->json('estado'));
        $this->assertSame(2, AfipTicket::withTrashed()->where('sale_id', $venta->id)->count(), 'Un ticket rechazado y UNO nuevo.');
        $this->assertSame(1, AfipTicket::where('sale_id', $venta->id)->whereNotNull('cae')->count(), 'Una sola factura autorizada.');
        $this->assertSame(2, $this->transporte->emisiones);
    }

    // =========================================================================================
    // CA7 contra-caso — el 602 deja el intento protegido
    // =========================================================================================

    /**
     * CA7 — Un envío que se cortó por la red y cuya consulta contesta 602 queda PROTEGIDO: ni a los
     * pocos minutos ni a los 10 ni a los 31 se puede borrar la factura, borrar la venta ni crear un
     * segundo intento; consultar informa pero no resuelve.
     *
     * @test
     */
    public function test_602_deja_el_intento_protegido_tambien_a_los_31_minutos()
    {
        $venta = $this->crear_venta();
        $this->transporte->guiones_de_emision = [['fecae' => 'error_de_red', 'consulta' => '602']];
        $this->transporte->guion_de_consulta = ['consulta' => '602'];

        $primera = $this->postJson('api/afip-ticket', $this->cuerpo($venta));
        $primera->assertStatus(201);
        $this->assertSame('pendiente_verificacion', $primera->json('estado'));
        $ticket = AfipTicket::where('sale_id', $venta->id)->firstOrFail();
        $this->assertNull($ticket->resultado, 'Ni el error de red ni el 602 de la consulta automática lo rechazan.');
        $this->assertSame(1, $this->transporte->emisiones);

        foreach ([2, 10, 31] as $minutos) {

            DB::table('afip_tickets')->where('id', $ticket->id)->update(['created_at' => Carbon::now()->subMinutes($minutos)]);

            $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
            $this->assertNull(AfipTicket::find($ticket->id)->resultado, 'Consultar con 602 no resuelve el intento ('.$minutos.' min).');

            $this->deleteJson('api/afip-ticket/'.$ticket->id)->assertStatus(422);
            $this->deleteJson('api/sale/'.$venta->id)->assertStatus(422);

            $reintento = $this->postJson('api/afip-ticket', $this->cuerpo($venta));
            $reintento->assertStatus(201);
            $this->assertSame('pendiente_verificacion', $reintento->json('estado'));

            $this->assertSame(1, AfipTicket::withTrashed()->where('sale_id', $venta->id)->count(), 'No se crea un segundo intento ('.$minutos.' min).');
            $this->assertSame(1, $this->transporte->emisiones, 'Sólo se concilia: no se emite de nuevo ('.$minutos.' min).');
            $this->assertNotNull(Sale::find($venta->id));
            $this->assertNotNull(AfipTicket::find($ticket->id));
        }
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Cuerpo que manda la interfaz para facturar la venta con el punto de venta del fixture.
     *
     * @param  \App\Models\Sale $venta
     * @return array
     */
    protected function cuerpo($venta)
    {
        $afip_information = $this->punto_de_venta();

        return [
            'sale_id'                    => $venta->id,
            'ventas_afip_information_id' => $afip_information->id,
            'afip_tipo_comprobante_id'   => AfipTipoComprobante::firstOrCreate(['codigo' => self::FACTURA_B], ['name' => 'Factura B'])->id,
            'afip_fecha_emision'         => date('Y-m-d'),
        ];
    }

    /**
     * El punto de venta del fixture como Responsable inscripto en producción.
     *
     * @return \App\Models\AfipInformation
     */
    protected function punto_de_venta()
    {
        $afip_information = AfipInformation::with('iva_condition')
                                ->where('user_id', $this->user_id())
                                ->where('punto_venta', TestingFerreteriaSeeder::PUNTO_VENTA)
                                ->first();

        if (is_null($afip_information) || is_null($afip_information->iva_condition)) {

            $condicion = IvaCondition::firstOrCreate(['name' => 'Responsable inscripto']);

            $afip_information = AfipInformation::create([
                'user_id'          => $this->user_id(),
                'punto_venta'      => TestingFerreteriaSeeder::PUNTO_VENTA,
                'iva_condition_id' => $condicion->id,
                'razon_social'     => 'zz Test endpoints de ARCA',
                'cuit'             => '20111111112',
            ]);
        }

        $afip_information->afip_ticket_production = 1;
        $afip_information->save();

        return $afip_information;
    }

    /**
     * Id del usuario dueño del fixture de testing.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Crea una venta de una sola línea por el endpoint real. Cada una con otro total.
     *
     * @return \App\Models\Sale
     */
    protected function crear_venta()
    {
        $precio = $this->proximo_precio;
        $this->proximo_precio += 10;

        $articulo = Article::create([
            'name'       => 'zz Test endpoints de ARCA '.uniqid(),
            'user_id'    => $this->user_id(),
            'costo_real' => 100,
        ]);

        $this->articulos_creados[] = $articulo->id;

        $response = $this->postJson('api/sale', [
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $precio,
            'total'                            => $precio,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [[
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => $precio,
                'amount'       => 1,
                'costo_real'   => 100,
            ]],
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return Sale::find($response->json('model.id'));
    }
}

/**
 * Subclase de prueba del borde de red de `MakeAfipTicket`: corre el `AfipWsfeHelper` REAL contra un
 * doble de SOAP, cuenta emisiones y consultas y no abre ninguna conexión (no pasa por WSAA).
 *
 * `emitir()` consume el siguiente guion de `$guiones_de_emision`; las consultas (`conciliar()` y
 * `consultar()`) usan `$guion_de_consulta`.
 */
class TransporteDeFacturaConArcaSimulado extends TransporteDeFacturaHelper
{
    /** @var int Emisiones (`FECAESolicitar`) pedidas. */
    public $emisiones = 0;

    /** @var int Consultas (`FECompConsultar`) pedidas por conciliación o por el botón Consultar. */
    public $consultas = 0;

    /** @var array Guiones de las próximas emisiones, uno por emisión. */
    public $guiones_de_emision = [];

    /** @var array Guion de las consultas. */
    public $guion_de_consulta = ['consulta' => '602'];

    /**
     * Emisión real del helper con el doble de SOAP.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return void
     */
    public function emitir($ticket): void
    {
        $this->emisiones++;

        $guion = count($this->guiones_de_emision) > 0 ? array_shift($this->guiones_de_emision) : ['fecae' => 'error_de_red'];

        $this->helper($ticket, $guion)->procesar();
    }

    /**
     * Helper de consulta con el doble de SOAP en vez de pasar por WSAA y el webservice.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return \App\Http\Controllers\Helpers\Afip\AfipWsfeHelper
     */
    protected function helper_para_consultar($ticket)
    {
        $this->consultas++;

        return $this->helper($ticket, $this->guion_de_consulta);
    }

    /**
     * `AfipWsfeHelper` sin constructor (abriría el webservice) con el doble de SOAP.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  array $guion
     * @return \App\Http\Controllers\Helpers\Afip\AfipWsfeHelper
     */
    private function helper($ticket, array $guion)
    {
        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $ticket;
        $helper->testing = !$ticket->afip_information->afip_ticket_production;
        $helper->wsfe = new DobleDeWsfeParaEndpointsDeArca($guion);

        return $helper;
    }
}

/**
 * Doble de WSFE de los endpoints: contesta según un guion con la forma de `WS::__call()`.
 *
 * Guion (claves opcionales):
 *  - `fecae`: `auth_600` (600 de autenticación, sin CAE), `autorizada` o `error_de_red`.
 *  - `consulta`: `602` (no existen datos); lo que contesta `FECompConsultar`. Si falta, error de red.
 */
class DobleDeWsfeParaEndpointsDeArca
{
    /** @var array */
    private $guion;

    /**
     * @param  array $guion
     */
    public function __construct(array $guion)
    {
        $this->guion = $guion;
    }

    /**
     * @param  array $pto_vta
     * @return array
     */
    public function FECompUltimoAutorizado($pto_vta)
    {
        return $this->respuesta((object) [
            'FECompUltimoAutorizadoResult' => (object) [
                'PtoVta'   => $pto_vta['PtoVta'],
                'CbteTipo' => $pto_vta['CbteTipo'],
                'CbteNro'  => 0,
            ],
        ]);
    }

    /**
     * @param  array $invoice
     * @return array
     */
    public function FECAESolicitar($invoice)
    {
        $modo = isset($this->guion['fecae']) ? $this->guion['fecae'] : 'error_de_red';

        if ($modo === 'auth_600') {
            return $this->respuesta((object) [
                'FECAESolicitarResult' => (object) [
                    'Errors' => (object) [
                        'Err' => (object) ['Code' => 600, 'Msg' => 'ValidacionDeToken: No apareció CUIT en lista de relaciones: 20111111112'],
                    ],
                ],
            ], '<emision-solicitud/>', '<emision-respuesta-600/>');
        }

        if ($modo === 'autorizada') {

            $cabecera = $invoice['FeCAEReq']['FeCabReq'];
            $detalle = $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest'];

            return $this->respuesta((object) [
                'FECAESolicitarResult' => (object) [
                    'FeCabResp' => (object) [
                        'PtoVta'    => $cabecera['PtoVta'],
                        'CbteTipo'  => $cabecera['CbteTipo'],
                        'Resultado' => 'A',
                    ],
                    'FeDetResp' => (object) [
                        'FECAEDetResponse' => (object) [
                            'Concepto'  => $detalle['Concepto'],
                            'DocTipo'   => $detalle['DocTipo'],
                            'DocNro'    => $detalle['DocNro'] == 'NR' ? 0 : $detalle['DocNro'],
                            'CbteDesde' => $detalle['CbteDesde'],
                            'CbteHasta' => $detalle['CbteHasta'],
                            'CbteFch'   => $detalle['CbteFch'],
                            'Resultado' => 'A',
                            'CAE'       => '71279049124261',
                            'CAEFchVto' => '20261231',
                        ],
                    ],
                ],
            ]);
        }

        return $this->error_de_red();
    }

    /**
     * @param  array $invoice
     * @return array
     */
    public function FECompConsultar($invoice)
    {
        if (isset($this->guion['consulta']) && $this->guion['consulta'] === '602') {
            return $this->respuesta((object) [
                'FECompConsultarResult' => (object) [
                    'Errors' => (object) [
                        'Err' => (object) ['Code' => 602, 'Msg' => 'No existen datos en nuestros registros para los parámetros ingresados'],
                    ],
                ],
            ], '<consulta-solicitud/>', '<consulta-respuesta-602/>');
        }

        return $this->error_de_red();
    }

    /**
     * @param  object $result
     * @param  string $request
     * @param  string $response
     * @return array
     */
    private function respuesta($result, $request = '<request/>', $response = '<response/>')
    {
        return [
            'hubo_un_error' => false,
            'result'        => $result,
            'request'       => $request,
            'response'      => $response,
        ];
    }

    /**
     * Lo que devuelve `WS::__call()` cuando la llamada no llega (el helper lo reconoce como error de red).
     *
     * @return array
     */
    private function error_de_red()
    {
        return [
            'hubo_un_error' => true,
            'result'        => null,
            'error'         => 'Could not connect to host',
            'request'       => '<request/>',
            'response'      => null,
        ];
    }
}
