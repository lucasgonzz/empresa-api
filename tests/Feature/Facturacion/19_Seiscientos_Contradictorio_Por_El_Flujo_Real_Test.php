<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Http\Controllers\Helpers\Afip\TransporteDeFacturaHelper;
use App\Models\AfipError;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Article;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use ReflectionClass;
use Tests\EmpresaTestCase;

/**
 * Misión arca-facturas-fallidas (20/11/2026) — segunda corrección de la revisión 2 (QA rechazado dos veces):
 * el flujo REAL de emisión con un 600 contradictorio.
 *
 * ─── El defecto ───────────────────────────────────────────────────────────────────────────────
 *
 * `FECAESolicitar` contesta `600` + `FeCabResp->Resultado = 'R'` + `FECAEDetResponse->Resultado = 'A'`
 * (con o sin CAE). En `AfipWsfeHelper::solicitar_cae()` la respuesta pasa por dos escrituras seguidas:
 *
 *  1. `checkErrors()` → `rechazar_si_arca_no_proceso_el_pedido()`: ve la señal de autorización y NO escribe `R`
 *     (corregido en el commit `cb6e9815`, archivo 18).
 *  2. `update_afip_ticket()`: su rama `R` mira SÓLO la cabecera y hace `update(['resultado' => 'R', …])` sin
 *     condiciones → escribe `R` igual. El intento queda `rechazada`, se puede borrar y reemitir.
 *
 * El archivo 18 llama a `checkErrors()` suelto: nunca pasa por el segundo paso, por eso no lo detectó.
 *
 * ─── Lo que se exige ──────────────────────────────────────────────────────────────────────────
 *
 * Un 600 con señal de autorización (CAE o `Resultado = 'A'`, en cabecera o detalle) deja el intento INCIERTO
 * hasta el final de la emisión: `resultado` NULL, sin CAE, protegido contra el borrado y la reemisión, y una
 * consulta posterior que informe el CAE lo adopta (autorización tardía). Las guardas de abajo fijan lo que
 * NO tiene que cambiar (600 con detalle `R`, rechazo sin 600, 600 con cabecera `A`).
 *
 * ─── Cómo se prueba ───────────────────────────────────────────────────────────────────────────
 *
 * Por el flujo real: `AfipWsfeHelper::procesar()` (`solicitar_cae → checkErrors → update_afip_ticket`) y los
 * endpoints reales de la interfaz, con un doble de SOAP que sólo reemplaza `$helper->wsfe` (patrón de 17).
 * Nunca se llama a `checkErrors()` suelto. Sin WSAA, sin certificado y sin red. Las clases auxiliares de este
 * archivo llevan nombres propios: las de 17 están declaradas en el mismo namespace y phpunit carga la carpeta
 * entera.
 *
 * @group facturacion
 * @group afip
 */
class Seiscientos_Contradictorio_Por_El_Flujo_Real_Test extends EmpresaTestCase
{
    /** Código de ARCA de la Factura B. */
    const FACTURA_B = 6;

    /** CAE sintético de las respuestas de este archivo. */
    const CAE = '71279049124261';

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
    protected $proximo_precio = 13100.00;

    /**
     * Precio de la última venta creada (lo necesita la consulta con CAE para coincidir con lo enviado).
     *
     * @var float
     */
    protected $ultimo_precio = 0;

    /**
     * Transporte simulado registrado en el contenedor.
     *
     * @var \Tests\Feature\Facturacion\TransporteDeFacturaConSeiscientosContradictorio
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

        $this->transporte = new TransporteDeFacturaConSeiscientosContradictorio();
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
    // El defecto, a nivel de `procesar()` (R1–R3)
    // =========================================================================================

    /**
     * R1 — 600 + cabecera `R` + detalle `A` SIN CAE, por `procesar()` real: el intento queda incierto.
     * Contra el código anterior falla: `update_afip_ticket()` lo deja en `R`.
     *
     * @test
     */
    public function test_r1_600_con_cabecera_r_y_detalle_a_sin_cae_deja_el_intento_incierto()
    {
        $ticket = $this->emitir_por_procesar('contradictorio_a_sin_cae');

        $this->assertIntentoIncierto($ticket);
        $this->assertErrorSeiscientosSinInvitarAEliminar($ticket);
    }

    /**
     * R2 — Igual pero el detalle `A` trae CAE: no se adopta ese CAE contradictorio, el intento queda incierto
     * y sin CAE (una consulta posterior es la que puede confirmarlo).
     *
     * @test
     */
    public function test_r2_600_con_cabecera_r_y_detalle_a_con_cae_deja_el_intento_incierto()
    {
        $ticket = $this->emitir_por_procesar('contradictorio_a_con_cae');

        $this->assertIntentoIncierto($ticket);
        $this->assertErrorSeiscientosSinInvitarAEliminar($ticket);
    }

    /**
     * R3 — El detalle como lista `[R, A]` (forma que deja SoapClient con varios `FECAEDetResponse`).
     *
     * @test
     */
    public function test_r3_600_con_cabecera_r_y_lista_de_detalles_r_a_deja_el_intento_incierto()
    {
        $ticket = $this->emitir_por_procesar('contradictorio_lista');

        $this->assertIntentoIncierto($ticket);
        $this->assertErrorSeiscientosSinInvitarAEliminar($ticket);
    }

    // =========================================================================================
    // El defecto, por los endpoints reales (R4, R5)
    // =========================================================================================

    /**
     * R4 — `POST api/afip-ticket` con la respuesta contradictoria: el endpoint informa
     * `pendiente_verificacion`, no se puede borrar la factura ni la venta y un segundo `POST` sólo concilia
     * (no crea otro ticket ni emite de nuevo).
     *
     * @test
     */
    public function test_r4_endpoint_con_600_contradictorio_queda_pendiente_y_protegido()
    {
        $venta = $this->crear_venta();
        $this->transporte->guiones_de_emision = [['fecae' => 'contradictorio_a_sin_cae']];

        $respuesta = $this->postJson('api/afip-ticket', $this->cuerpo($venta));

        $respuesta->assertStatus(201);
        $this->assertSame('pendiente_verificacion', $respuesta->json('estado'), 'Una respuesta con señal de autorización no puede informarse como rechazada.');

        $ticket = AfipTicket::where('sale_id', $venta->id)->firstOrFail();
        $this->assertNull($ticket->resultado);

        $this->deleteJson('api/afip-ticket/'.$ticket->id)->assertStatus(422);
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(422);

        $reintento = $this->postJson('api/afip-ticket', $this->cuerpo($venta));
        $reintento->assertStatus(201);
        $this->assertSame('pendiente_verificacion', $reintento->json('estado'));

        $this->assertSame(1, AfipTicket::withTrashed()->where('sale_id', $venta->id)->count(), 'No se crea un segundo intento.');
        $this->assertSame(1, $this->transporte->emisiones, 'Sólo se concilia: no se emite de nuevo.');
        $this->assertNotNull(Sale::find($venta->id));
        $this->assertNotNull(AfipTicket::find($ticket->id));
    }

    /**
     * R5 — Autorización tardía: tras el `POST` contradictorio, `GET api/afip-ticket/consultar-comprobante/{id}`
     * con una consulta que sí informa el CAE para el número 1 (mismo receptor, mismo importe) adopta el
     * comprobante: queda `A` con CAE y un solo ticket.
     *
     * @test
     */
    public function test_r5_la_consulta_posterior_adopta_el_cae_tardio()
    {
        $venta = $this->crear_venta();
        $this->transporte->guiones_de_emision = [['fecae' => 'contradictorio_a_sin_cae']];

        $this->postJson('api/afip-ticket', $this->cuerpo($venta))->assertStatus(201);
        $ticket = AfipTicket::where('sale_id', $venta->id)->firstOrFail();
        $this->assertNull($ticket->resultado, 'Precondición: el intento tiene que haber quedado incierto.');

        $this->transporte->guion_de_consulta = ['consulta' => 'con_cae', 'importe' => $this->ultimo_precio];

        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);

        $ticket = AfipTicket::findOrFail($ticket->id);
        $this->assertSame('A', $ticket->resultado);
        $this->assertSame(self::CAE, (string) $ticket->cae);
        $this->assertSame(1, AfipTicket::withTrashed()->where('sale_id', $venta->id)->count());
    }

    // =========================================================================================
    // Guardas: lo que no cambia (G1–G4)
    // =========================================================================================

    /**
     * G1 — 600 + cabecera `R` + detalle `R` sin CAE, por `procesar()`: sigue siendo rechazo firme
     * (`R`, con el texto que invita a eliminar el intento).
     *
     * @test
     */
    public function test_g1_600_con_detalle_r_sigue_siendo_rechazo_firme()
    {
        $ticket = $this->emitir_por_procesar('auth_600_detalle_r');

        $this->assertSame('R', $ticket->resultado);
        $this->assertTrue(empty($ticket->cae));
        $errores = AfipError::where('afip_ticket_id', $ticket->id)->where('code', '600')->get();
        $this->assertSame(1, $errores->count());
        $this->assertStringContainsString('Podés eliminar este intento', $errores->first()->message);
    }

    /**
     * G2 — G1 por los endpoints: `rechazada`, y se puede borrar la factura (200) y la venta (200).
     *
     * @test
     */
    public function test_g2_endpoint_con_600_y_detalle_r_permite_borrar_factura_y_venta()
    {
        $venta = $this->crear_venta();
        $this->transporte->guiones_de_emision = [['fecae' => 'auth_600_detalle_r']];

        $respuesta = $this->postJson('api/afip-ticket', $this->cuerpo($venta));

        $respuesta->assertStatus(201);
        $this->assertSame('rechazada', $respuesta->json('estado'));
        $ticket = AfipTicket::where('sale_id', $venta->id)->firstOrFail();

        $this->deleteJson('api/afip-ticket/'.$ticket->id)->assertStatus(200);
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);
    }

    /**
     * G3 — Rechazo sin 600 (10016 + cabecera `R` + detalle `R`): la rama `R` de `update_afip_ticket()`,
     * anterior a esta misión, sigue escribiendo `R`.
     *
     * @test
     */
    public function test_g3_rechazo_sin_600_sigue_quedando_r_por_la_cabecera()
    {
        $ticket = $this->emitir_por_procesar('rechazo_10016');

        $this->assertSame('R', $ticket->resultado);
        $this->assertSame(1, AfipError::where('afip_ticket_id', $ticket->id)->where('code', '10016')->count());
    }

    /**
     * G4 — 600 + cabecera `A` + detalle `A` con CAE: la autorización de la cabecera se adopta (`A` + CAE).
     *
     * @test
     */
    public function test_g4_600_con_cabecera_a_y_cae_queda_autorizada()
    {
        $ticket = $this->emitir_por_procesar('600_con_cabecera_a');

        $this->assertSame('A', $ticket->resultado);
        $this->assertSame(self::CAE, (string) $ticket->cae);
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Crea una venta y su ticket como lo hace `MakeAfipTicket::emitir_con_candados()` y corre la emisión
     * REAL (`procesar()`) con el guion indicado. Devuelve el ticket recargado de la base.
     *
     * @param  string $modo Modo de `FECAESolicitar` del doble.
     * @return \App\Models\AfipTicket
     */
    protected function emitir_por_procesar($modo)
    {
        $venta = $this->crear_venta();
        $afip_information = $this->punto_de_venta();

        $ticket = AfipTicket::create([
            'cuit_negocio'                    => $afip_information->cuit,
            'iva_negocio'                     => $afip_information->iva_condition->name,
            'punto_venta'                     => $afip_information->punto_venta,
            'iva_cliente'                     => '',
            'cuit_cliente'                    => 'NR',
            'sale_id'                         => $venta->id,
            'afip_information_id'             => $afip_information->id,
            'afip_tipo_comprobante_id'        => AfipTipoComprobante::firstOrCreate(['codigo' => self::FACTURA_B], ['name' => 'Factura B'])->id,
            'afip_fecha_emision'              => date('Y-m-d'),
            'facturar_importe_personalizado'  => null,
            'importe_personalizado_ivas_json' => null,
            'forma_de_pago'                   => null,
            'permiso_existente'               => 'N',
        ]);

        $this->transporte->guiones_de_emision = [['fecae' => $modo]];
        $this->transporte->emitir($ticket);

        return AfipTicket::findOrFail($ticket->id);
    }

    /**
     * El intento sigue incierto: numerado, sin resultado, sin CAE y con el request/response que tenía
     * (la evidencia queda en el `AfipError`).
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return void
     */
    protected function assertIntentoIncierto($ticket)
    {
        $this->assertNull($ticket->resultado, 'Con una señal de autorización en la respuesta el intento no puede quedar rechazado.');
        $this->assertTrue(empty($ticket->cae), 'No se adopta el CAE de una respuesta contradictoria.');
        $this->assertSame(1, (int) $ticket->cbte_numero);
        $this->assertNull($ticket->request, 'La rama R no tiene que pisar el request del ticket.');
        $this->assertNull($ticket->response, 'La rama R no tiene que pisar el response del ticket.');
    }

    /**
     * El 600 queda guardado como `AfipError` y su mensaje NO invita a eliminar el intento.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return void
     */
    protected function assertErrorSeiscientosSinInvitarAEliminar($ticket)
    {
        $errores = AfipError::where('afip_ticket_id', $ticket->id)->where('code', '600')->get();

        $this->assertSame(1, $errores->count(), 'El 600 tiene que quedar guardado una sola vez.');
        $this->assertStringNotContainsString('Podés eliminar', $errores->first()->message);
    }

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
                'razon_social'     => 'zz Test 600 contradictorio',
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
        $this->ultimo_precio = $precio;

        $articulo = Article::create([
            'name'       => 'zz Test 600 contradictorio '.uniqid(),
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
 * Subclase de prueba del borde de red de `MakeAfipTicket`: corre el `AfipWsfeHelper` REAL contra un doble
 * de SOAP, cuenta emisiones y consultas y no abre ninguna conexión (no pasa por WSAA).
 *
 * `emitir()` consume el siguiente guion de `$guiones_de_emision`; las consultas usan `$guion_de_consulta`.
 * Nombre propio para no chocar con la clase equivalente de 17 (mismo namespace).
 */
class TransporteDeFacturaConSeiscientosContradictorio extends TransporteDeFacturaHelper
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
     * Emisión real del helper (`procesar()`) con el doble de SOAP.
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
        $helper->wsfe = new DobleDeWsfeConSeiscientosContradictorio($guion);

        return $helper;
    }
}

/**
 * Doble de WSFE de este archivo: contesta según un guion con la forma de `WS::__call()`.
 *
 * Guion (claves opcionales):
 *  - `fecae`: `contradictorio_a_sin_cae`, `contradictorio_a_con_cae`, `contradictorio_lista` (600 + cabecera
 *    `R` + detalle con `A`), `auth_600_detalle_r` (600 + `R`/`R`), `rechazo_10016` (10016 sin 600 + `R`/`R`),
 *    `600_con_cabecera_a` (600 + `A`/`A` con CAE) o `error_de_red`.
 *  - `consulta`: `602` (no existen datos) o `con_cae` (el comprobante 1 con CAE; usa `importe`).
 */
class DobleDeWsfeConSeiscientosContradictorio
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
     * ARCA contesta que no hay comprobantes: la numeración empieza en 1.
     *
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
     * Respuesta de `FECAESolicitar` según el guion.
     *
     * @param  array $invoice
     * @return array
     */
    public function FECAESolicitar($invoice)
    {
        $modo = isset($this->guion['fecae']) ? $this->guion['fecae'] : 'error_de_red';

        $cabecera = $invoice['FeCAEReq']['FeCabReq'];
        $detalle = $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest'];

        // Campos que ARCA repite del pedido en el detalle de la respuesta.
        $eco = [
            'Concepto'  => $detalle['Concepto'],
            'DocTipo'   => $detalle['DocTipo'],
            'DocNro'    => $detalle['DocNro'] == 'NR' ? 0 : $detalle['DocNro'],
            'CbteDesde' => $detalle['CbteDesde'],
            'CbteHasta' => $detalle['CbteHasta'],
            'CbteFch'   => $detalle['CbteFch'],
        ];

        $cab = function ($resultado) use ($cabecera) {
            return (object) ['PtoVta' => $cabecera['PtoVta'], 'CbteTipo' => $cabecera['CbteTipo'], 'Resultado' => $resultado];
        };

        $det = function ($resultado, $cae = null) use ($eco) {
            $nodo = $eco + ['Resultado' => $resultado];
            if (!is_null($cae)) {
                $nodo['CAE'] = $cae;
                $nodo['CAEFchVto'] = '20261231';
            }
            return (object) $nodo;
        };

        $err_600 = (object) ['Err' => (object) ['Code' => 600, 'Msg' => 'ValidacionDeToken: No apareció CUIT en lista de relaciones: 20111111112']];

        switch ($modo) {

            case 'contradictorio_a_sin_cae':
                return $this->respuesta((object) ['FECAESolicitarResult' => (object) [
                    'FeCabResp' => $cab('R'),
                    'FeDetResp' => (object) ['FECAEDetResponse' => $det('A')],
                    'Errors'    => $err_600,
                ]], '<emision-solicitud/>', '<emision-respuesta-contradictoria/>');

            case 'contradictorio_a_con_cae':
                return $this->respuesta((object) ['FECAESolicitarResult' => (object) [
                    'FeCabResp' => $cab('R'),
                    'FeDetResp' => (object) ['FECAEDetResponse' => $det('A', '71279049124261')],
                    'Errors'    => $err_600,
                ]], '<emision-solicitud/>', '<emision-respuesta-contradictoria/>');

            case 'contradictorio_lista':
                return $this->respuesta((object) ['FECAESolicitarResult' => (object) [
                    'FeCabResp' => $cab('R'),
                    'FeDetResp' => (object) ['FECAEDetResponse' => [$det('R'), $det('A')]],
                    'Errors'    => $err_600,
                ]], '<emision-solicitud/>', '<emision-respuesta-contradictoria/>');

            case 'auth_600_detalle_r':
                return $this->respuesta((object) ['FECAESolicitarResult' => (object) [
                    'FeCabResp' => $cab('R'),
                    'FeDetResp' => (object) ['FECAEDetResponse' => $det('R')],
                    'Errors'    => $err_600,
                ]], '<emision-solicitud/>', '<emision-respuesta-600-r/>');

            case 'rechazo_10016':
                return $this->respuesta((object) ['FECAESolicitarResult' => (object) [
                    'FeCabResp' => $cab('R'),
                    'FeDetResp' => (object) ['FECAEDetResponse' => $det('R')],
                    'Errors'    => (object) ['Err' => (object) ['Code' => 10016, 'Msg' => 'El numero o fecha del comprobante no se corresponde con el proximo a autorizar']],
                ]], '<emision-solicitud/>', '<emision-respuesta-10016/>');

            case '600_con_cabecera_a':
                return $this->respuesta((object) ['FECAESolicitarResult' => (object) [
                    'FeCabResp' => $cab('A'),
                    'FeDetResp' => (object) ['FECAEDetResponse' => $det('A', '71279049124261')],
                    'Errors'    => $err_600,
                ]], '<emision-solicitud/>', '<emision-respuesta-600-a/>');
        }

        return $this->error_de_red();
    }

    /**
     * Respuesta de `FECompConsultar` según el guion.
     *
     * @param  array $invoice
     * @return array
     */
    public function FECompConsultar($invoice)
    {
        $modo = isset($this->guion['consulta']) ? $this->guion['consulta'] : null;

        if ($modo === '602') {
            return $this->respuesta((object) [
                'FECompConsultarResult' => (object) [
                    'Errors' => (object) [
                        'Err' => (object) ['Code' => 602, 'Msg' => 'No existen datos en nuestros registros para los parámetros ingresados'],
                    ],
                ],
            ], '<consulta-solicitud/>', '<consulta-respuesta-602/>');
        }

        if ($modo === 'con_cae') {
            return $this->respuesta((object) [
                'FECompConsultarResult' => (object) ['ResultGet' => (object) [
                    'PtoVta'          => $invoice['FeCompConsReq']['PtoVta'],
                    'CbteTipo'        => $invoice['FeCompConsReq']['CbteTipo'],
                    'CbteDesde'       => $invoice['FeCompConsReq']['CbteNro'],
                    'CbteHasta'       => $invoice['FeCompConsReq']['CbteNro'],
                    'ImpTotal'        => $this->guion['importe'],
                    'MonId'           => 'PES',
                    'Resultado'       => 'A',
                    'CodAutorizacion' => '71279049124261',
                    'FchVto'          => '20261231',
                    'DocTipo'         => 99,
                    'DocNro'          => 0,
                ]],
            ], '<consulta-solicitud/>', '<consulta-respuesta-con-cae/>');
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
