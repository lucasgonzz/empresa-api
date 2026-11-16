<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\asistente_ia\VentasSinCobrarIaHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-deudores-saldos-iniciales (16/11/2026, ticket de soporte 131, misión 34 de Martín).
 *
 * 🔴 EL CASO REAL (ronda 9 del ensayo /grunon). El dueño importó por Soporte diez clientes con sus
 * saldos, le vendió a cuenta corriente a uno ("Don Pichi": saldo inicial 4.750, venta 9.400, pago
 * 5.000 → debe 9.150) y le preguntó al asistente "¿quién me debe más plata?". Contestó que Don Pichi
 * era el único deudor: `consultar_ventas_sin_cobrar` mira solo VENTAS impagas, y un saldo inicial no es
 * una venta. "Electro Vilches SRL" debía $174.200,75.
 *
 * Lo que protege este archivo:
 *  - `cuenta_corriente` trae el saldo vivo de TODOS los clientes (`credit_accounts.saldo`), con el
 *    primero de la lista siendo el que más debe aunque no tenga ventas;
 *  - el saldo a favor de un cliente no le resta a la deuda de otro, y los dólares no se suman a pesos;
 *  - las claves de ventas de antes no cambiaron de significado;
 *  - `monto_minimo` filtra el bloque, `dias` no;
 *  - un empleado recortado a sus ventas no recibe el saldo de todos;
 *  - tenencia y clientes borrados;
 *  - si la lectura falla, la respuesta de ventas sale igual;
 *  - los recordatorios de cobro llegan a los mismos clientes que antes;
 *  - los textos que guían al modelo.
 *
 * 🔴 EL DATO SE ARMA POR LOS CAMINOS REALES: el saldo inicial en pesos con
 * `LocalImportHelper::crearSaldoInicialPorImportacion()` (lo que `procesarSaldoImportacion()` le hace a
 * un cliente NUEVO de la importación de clientes, la que usa Soporte), el de dólares con el botón
 * "Saldo inicial" (`POST api/current-acount/saldo-inicial`), la venta a cuenta corriente con
 * `SaleHelper::create_current_acount()` y el pago con `POST api/current-acount/pago`. Nada de escribir
 * `credit_accounts.saldo` a mano.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, promoción de
 * constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Deudores_con_saldos_iniciales_Test extends EmpresaTestCase
{
    /** @var User El dueño del negocio del test (propio, para no mezclarse con la ferretería sembrada). */
    protected $dueno;

    /** @var array<string, Client> Los clientes del escenario, por apodo. */
    protected $clientes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::create([
            'name'         => 'ZZD Negocio Deudores',
            'company_name' => 'ZZD Negocio Deudores',
            'email'        => 'zzd-deudores-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'dias_alertar_administradores_ventas_no_cobradas' => 0,
            'dias_alertar_empleados_ventas_no_cobradas'       => 0,
        ]);

        // Los endpoints del saldo inicial y del pago sacan el dueño de la sesión.
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->actingAs($this->dueno, 'web');
    }

    // ------------------------------------------------------------------ armado del dato

    /**
     * Un cliente del dueño con sus dos cuentas corrientes (pesos y dólares), como lo deja el alta.
     *
     * @param  string  $nombre
     * @param  int|null  $owner_id  Para armar un cliente de OTRO negocio.
     * @return Client
     */
    protected function cliente($nombre, $owner_id = null)
    {
        $owner_id = is_null($owner_id) ? $this->dueno->id : $owner_id;

        $cliente = Client::create([
            'num'     => (int) Client::where('user_id', $owner_id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $owner_id,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $owner_id);

        return $cliente;
    }

    /**
     * La cuenta de un cliente en una moneda.
     *
     * @param  Client  $cliente
     * @param  int  $moneda_id
     * @return CreditAccount
     */
    protected function cuenta(Client $cliente, $moneda_id = 1)
    {
        return CreditAccount::where('model_name', 'client')
            ->where('model_id', $cliente->id)
            ->where('moneda_id', $moneda_id)
            ->firstOrFail();
    }

    /**
     * El saldo inicial en pesos de la IMPORTACIÓN de clientes (negativo = a favor): lo que
     * `LocalImportHelper::procesarSaldoImportacion()` le hace a un cliente nuevo.
     *
     * @param  Client  $cliente
     * @param  float  $saldo
     * @return void
     */
    protected function saldo_importado(Client $cliente, $saldo)
    {
        $cargado = LocalImportHelper::crearSaldoInicialPorImportacion($saldo, $this->cuenta($cliente), 'client', $cliente);

        $this->assertTrue($cargado, 'La cuenta estaba vacía: el saldo inicial se tiene que cargar.');
    }

    /**
     * El saldo inicial cargado a mano con el botón "Saldo inicial" de la cuenta (cualquier moneda).
     *
     * @param  Client  $cliente
     * @param  int  $moneda_id
     * @param  float  $monto
     * @return void
     */
    protected function saldo_inicial_a_mano(Client $cliente, $moneda_id, $monto)
    {
        $cuenta = $this->cuenta($cliente, $moneda_id);

        $this->postJson('api/current-acount/saldo-inicial', [
            'credit_account_id' => $cuenta->id,
            'model_name'        => 'client',
            'model_id'          => $cliente->id,
            'is_for_debe'       => true,
            'saldo_inicial'     => $monto,
        ])->assertStatus(201);
    }

    /**
     * Una venta a cuenta corriente por el camino real del alta de venta.
     *
     * @param  Client  $cliente
     * @param  float  $total
     * @return Sale
     */
    protected function venta_a_cuenta_corriente(Client $cliente, $total)
    {
        $venta = Sale::create([
            'num'                        => (int) Sale::where('user_id', $this->dueno->id)->max('num') + 1,
            'user_id'                    => $this->dueno->id,
            'client_id'                  => $cliente->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 1,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'caja_id'                    => null,
            'moneda_id'                  => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
            'created_at'                 => Carbon::now(),
        ]);

        $this->assertNotNull(SaleHelper::create_current_acount($venta), 'La venta tiene que ir a la cuenta corriente.');

        return $venta;
    }

    /**
     * Un pago del cliente a su cuenta en pesos, por el endpoint del cobro.
     *
     * @param  Client  $cliente
     * @param  float  $monto
     * @return void
     */
    protected function pago(Client $cliente, $monto)
    {
        $metodo = CurrentAcountPaymentMethod::firstOrFail();

        $respuesta = $this->postJson('api/current-acount/pago', [
            'description'                    => 'Pago a cuenta',
            'credit_account_id'              => $this->cuenta($cliente)->id,
            'is_provisorio'                  => 0,
            'model_name'                     => 'client',
            'model_id'                       => $cliente->id,
            'haber'                          => $monto,
            'current_acount_payment_methods' => [
                ['current_acount_payment_method_id' => $metodo->id, 'amount' => $monto],
            ],
        ]);

        $this->assertTrue($respuesta->status() < 300, 'El pago tiene que entrar: ' . $respuesta->getContent());
    }

    /**
     * EL ESCENARIO DEL TICKET 131, reducido: tres clientes con solo saldo importado, Don Pichi (saldo
     * inicial + venta + pago parcial), uno con saldo a favor, uno que debe en dólares, uno borrado con
     * deuda y un cliente de OTRO negocio con deuda.
     *
     * @return void
     */
    protected function armar_escenario()
    {
        $this->clientes['vilches'] = $this->cliente('Electro Vilches SRL');
        $this->saldo_importado($this->clientes['vilches'], 174200.75);

        $this->clientes['sur'] = $this->cliente('Ferreteria del Sur');
        $this->saldo_importado($this->clientes['sur'], 120000);

        $this->clientes['ana'] = $this->cliente('Kiosco Ana');
        $this->saldo_importado($this->clientes['ana'], 30000);

        // Don Pichi: 4.750 iniciales + 9.400 de una venta − 5.000 de un pago = 9.150.
        $this->clientes['pichi'] = $this->cliente('Don Pichi');
        $this->saldo_importado($this->clientes['pichi'], 4750);
        $this->venta_a_cuenta_corriente($this->clientes['pichi'], 9400);
        $this->pago($this->clientes['pichi'], 5000);

        // Saldo a favor (importado con signo negativo): no le resta nada a nadie.
        $this->clientes['favor'] = $this->cliente('Cliente con saldo a favor');
        $this->saldo_importado($this->clientes['favor'], -13200);

        // Debe en dólares y nada en pesos.
        $this->clientes['dolar'] = $this->cliente('Importadora Dolar');
        $this->saldo_inicial_a_mano($this->clientes['dolar'], 2, 300);

        // Borrado (soft delete) con deuda: afuera, como clientes_con_saldo_pendiente().
        $this->clientes['borrado'] = $this->cliente('Cliente borrado con deuda');
        $this->saldo_importado($this->clientes['borrado'], 50000);
        $this->clientes['borrado']->delete();

        // Otro negocio, con más deuda que nadie: no puede aparecer.
        $otro = User::create([
            'name'     => 'ZZD Otro negocio',
            'email'    => 'zzd-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $this->clientes['ajeno'] = $this->cliente('Cliente de otro negocio', $otro->id);
        $this->saldo_importado($this->clientes['ajeno'], 999999);
    }

    /**
     * Un empleado del dueño.
     *
     * @param  string  $nombre
     * @param  array  $extra
     * @return User
     */
    protected function empleado($nombre, array $extra = [])
    {
        return User::create(array_merge([
            'name'     => $nombre,
            'email'    => 'zzd-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ], $extra));
    }

    // ------------------------------------------------------------------ 1

    /**
     * 🔴 "¿Quién me debe más?": el que más debe encabeza la lista aunque no tenga ninguna venta, el
     * total es la suma de los positivos en pesos, el saldo a favor no resta y los dólares van aparte.
     *
     * @test
     */
    public function la_cuenta_corriente_trae_a_todos_los_que_deben_aunque_no_tengan_ventas()
    {
        $this->armar_escenario();

        // El dato quedó como lo dejaron los caminos reales.
        $this->assertEqualsWithDelta(9150, (float) $this->cuenta($this->clientes['pichi'])->saldo, 0.01);
        $this->assertEqualsWithDelta(-13200, (float) $this->cuenta($this->clientes['favor'])->saldo, 0.01);
        $this->assertEqualsWithDelta(300, (float) $this->cuenta($this->clientes['dolar'], 2)->saldo, 0.01);

        $respuesta = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, null, $this->dueno);

        $this->assertArrayHasKey('cuenta_corriente', $respuesta);
        $cc = $respuesta['cuenta_corriente'];
        $this->assertIsArray($cc, json_encode($respuesta));

        // 174.200,75 + 120.000 + 30.000 + 9.150: el saldo a favor NO resta y los dólares NO suman.
        $this->assertEqualsWithDelta(333350.75, $cc['total_que_te_deben_en_pesos'], 0.001);
        $this->assertSame(4, $cc['clientes_que_deben']);
        $this->assertSame(4, $cc['clientes_en_esta_lista']);

        $this->assertSame(
            ['Electro Vilches SRL', 'Ferreteria del Sur', 'Kiosco Ana', 'Don Pichi'],
            array_column($cc['clientes'], 'cliente'),
            'De mayor a menor saldo, el primero aunque no tenga ventas; sin el borrado, sin el de otro negocio, sin el de dólares ni el de saldo a favor.'
        );

        $vilches = $cc['clientes'][0];
        $this->assertSame((int) $this->clientes['vilches']->id, $vilches['cliente_id']);
        $this->assertEqualsWithDelta(174200.75, $vilches['saldo_en_pesos'], 0.001);
        $this->assertFalse($vilches['tiene_ventas_sin_cobrar']);
        $this->assertEqualsWithDelta(0.0, $vilches['de_eso_en_ventas_sin_cobrar_en_pesos'], 0.001);

        // Don Pichi: el saldo de la cuenta es 9.150 y la parte que está en ventas sin cobrar es la de
        // su venta (el pago se imputó primero al saldo inicial, el más viejo).
        $pichi = $cc['clientes'][3];
        $this->assertEqualsWithDelta(9150, $pichi['saldo_en_pesos'], 0.001);
        $this->assertTrue($pichi['tiene_ventas_sin_cobrar']);
        $this->assertEqualsWithDelta(9150, $pichi['de_eso_en_ventas_sin_cobrar_en_pesos'], 0.001);

        $this->assertSame(3, $cc['clientes_que_deben_sin_ventas_sin_cobrar']);

        $this->assertSame(1, $cc['clientes_con_saldo_a_favor']);
        $this->assertEqualsWithDelta(13200, $cc['total_a_favor_en_pesos'], 0.001);

        $this->assertCount(1, $cc['en_otras_monedas']);
        $this->assertSame(2, $cc['en_otras_monedas'][0]['moneda_id']);
        $this->assertSame(1, $cc['en_otras_monedas'][0]['clientes_que_deben']);
        $this->assertEqualsWithDelta(300, $cc['en_otras_monedas'][0]['total_que_te_deben'], 0.001);

        // La guía de qué datos contestan qué pregunta viaja en la respuesta.
        $this->assertStringContainsString('cuenta_corriente', $respuesta['como_contestar']);
        $this->assertStringContainsString('quien me debe mas', $respuesta['como_contestar']);
        $this->assertArrayNotHasKey('cuenta_corriente_error', $respuesta);
    }

    // ------------------------------------------------------------------ 2

    /**
     * Las claves de antes siguen hablando SOLO de ventas: para el mismo escenario, una venta impaga de
     * Don Pichi y nada más.
     *
     * @test
     */
    public function las_claves_de_ventas_no_cambiaron()
    {
        $this->armar_escenario();

        $respuesta = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, null, $this->dueno);

        $this->assertSame(1, $respuesta['ventas_sin_cobrar']);
        $this->assertEqualsWithDelta(9150, $respuesta['total_pendiente_en_pesos'], 0.001);
        $this->assertSame(1, $respuesta['clientes_con_deuda']);
        $this->assertSame(1, $respuesta['clientes_en_esta_lista']);
        $this->assertSame(['Don Pichi'], array_column($respuesta['clientes'], 'cliente'));
        $this->assertSame('Todas las ventas del negocio.', $respuesta['alcance']);
        $this->assertSame(0, $respuesta['dias_de_antiguedad_pedidos']);
        $this->assertEqualsWithDelta(9150, $respuesta['deuda_total_en_pesos_de_estos_clientes'], 0.001);
        $this->assertSame('Don Pichi', $respuesta['venta_mas_vieja']['cliente']);
    }

    // ------------------------------------------------------------------ 3

    /**
     * `monto_minimo` acota el bloque por saldo (y dice cuántos eran sin él); `dias` no lo acota y la
     * respuesta lo dice.
     *
     * @test
     */
    public function el_monto_minimo_filtra_el_bloque_y_los_dias_no()
    {
        $this->armar_escenario();

        $con_monto = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, null, $this->dueno, 100000);
        $cc = $con_monto['cuenta_corriente'];

        $this->assertSame(['Electro Vilches SRL', 'Ferreteria del Sur'], array_column($cc['clientes'], 'cliente'));
        $this->assertSame(2, $cc['clientes_que_deben']);
        $this->assertEqualsWithDelta(294200.75, $cc['total_que_te_deben_en_pesos'], 0.001);
        $this->assertSame(4, $cc['clientes_que_deben_sin_el_monto_minimo']);
        $this->assertEquals(100000, $cc['monto_minimo_pedido']);
        $this->assertStringContainsString('saldo_en_pesos', $cc['filtro_de_monto']);
        $this->assertSame(2, $cc['clientes_que_deben_sin_ventas_sin_cobrar']);

        // El ranking de ventas sigue con su propio filtro: Don Pichi debe 9.150, no llega.
        $this->assertSame(0, $con_monto['clientes_con_deuda']);

        // Con dias = 30 la venta de hoy queda afuera de las ventas, pero el saldo viene entero.
        $con_dias = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, 30, $this->dueno);

        $this->assertSame(0, $con_dias['ventas_sin_cobrar']);
        $this->assertSame(4, $con_dias['cuenta_corriente']['clientes_que_deben']);
        $this->assertEqualsWithDelta(333350.75, $con_dias['cuenta_corriente']['total_que_te_deben_en_pesos'], 0.001);
        $this->assertStringContainsString('dias = 30', $con_dias['cuenta_corriente']['como_leer']);
        $this->assertStringContainsString('no tiene antiguedad', $con_dias['cuenta_corriente']['como_leer']);

        // Sin dias, como_leer no habla de antigüedad.
        $sin_dias = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, null, $this->dueno);
        $this->assertStringNotContainsString('no tiene antiguedad', $sin_dias['cuenta_corriente']['como_leer']);
    }

    // ------------------------------------------------------------------ 4

    /**
     * Un empleado recortado a sus ventas no recibe el saldo de todos; el dueño, quien tiene
     * `ver_alertas_de_todos_los_empleados` y una llamada sin persona, sí.
     *
     * @test
     */
    public function el_empleado_recortado_no_recibe_el_saldo_de_todos()
    {
        $this->armar_escenario();

        $vendedor = $this->empleado('Vendedor deudores');
        $supervisor = $this->empleado('Supervisor deudores', ['ver_alertas_de_todos_los_empleados' => 1]);
        $admin_sin_permiso = $this->empleado('Admin deudores', ['admin_access' => 1]);

        foreach ([$vendedor, $admin_sin_permiso] as $recortado) {
            $respuesta = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, null, $recortado);

            $this->assertArrayHasKey('cuenta_corriente', $respuesta);
            $this->assertNull($respuesta['cuenta_corriente'], $recortado->name);
            $this->assertStringContainsString('Solo ves tus ventas', $respuesta['cuenta_corriente_no_incluida']);
            $this->assertStringNotContainsString('Electro Vilches', json_encode($respuesta));
        }

        foreach ([$this->dueno, $supervisor, null] as $persona) {
            $respuesta = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->dueno->id, null, $persona);

            $this->assertIsArray($respuesta['cuenta_corriente']);
            $this->assertSame(4, $respuesta['cuenta_corriente']['clientes_que_deben']);
            $this->assertArrayNotHasKey('cuenta_corriente_no_incluida', $respuesta);
        }
    }

    // ------------------------------------------------------------------ 5 y 6

    /**
     * Tenencia y borrados: el otro negocio no ve los saldos de éste, y un cliente borrado no aparece
     * ni suma.
     *
     * @test
     */
    public function los_saldos_de_otro_negocio_y_de_clientes_borrados_no_aparecen()
    {
        $this->armar_escenario();

        $propio = VentasSinCobrarIaHelper::bloque_de_cuenta_corriente($this->dueno->id);

        $nombres = array_column($propio['clientes'], 'cliente');
        $this->assertNotContains('Cliente de otro negocio', $nombres);
        $this->assertNotContains('Cliente borrado con deuda', $nombres);
        $this->assertEqualsWithDelta(333350.75, $propio['total_que_te_deben_en_pesos'], 0.001);

        // Y el otro negocio ve solo lo suyo.
        $ajeno = VentasSinCobrarIaHelper::bloque_de_cuenta_corriente($this->clientes['ajeno']->user_id);

        $this->assertSame(['Cliente de otro negocio'], array_column($ajeno['clientes'], 'cliente'));
        $this->assertEqualsWithDelta(999999, $ajeno['total_que_te_deben_en_pesos'], 0.001);
        $this->assertSame([], $ajeno['en_otras_monedas']);
    }

    // ------------------------------------------------------------------ 7

    /**
     * Si la lectura del saldo falla, la respuesta de ventas sale igual, con el bloque en null y el
     * aviso. El fallo se fuerza con una subclase de prueba que tira en `bloque_de_cuenta_corriente()`
     * (`ventas_sin_cobrar()` lo llama con `static::`).
     *
     * @test
     */
    public function si_la_lectura_del_saldo_falla_las_ventas_salen_igual()
    {
        $this->armar_escenario();

        $respuesta = VentasSinCobrarConLecturaRota::ventas_sin_cobrar($this->dueno->id, null, $this->dueno);

        $this->assertArrayNotHasKey('error', $respuesta);
        $this->assertSame(1, $respuesta['ventas_sin_cobrar']);
        $this->assertEqualsWithDelta(9150, $respuesta['total_pendiente_en_pesos'], 0.001);
        $this->assertNull($respuesta['cuenta_corriente']);
        $this->assertSame(
            'No se pudo leer el saldo de las cuentas corrientes: los numeros de arriba son solo de ventas sin cobrar y pueden no ser toda la deuda.',
            $respuesta['cuenta_corriente_error']
        );
    }

    // ------------------------------------------------------------------ 8

    /**
     * Los recordatorios de cobro no cambian de destinatarios: siguen siendo los clientes con ventas
     * impagas (Don Pichi), no los que deben solo un saldo inicial.
     *
     * @test
     */
    public function los_recordatorios_de_cobro_siguen_llegando_a_los_mismos_clientes()
    {
        $this->armar_escenario();

        $deudores = VentasSinCobrarIaHelper::clientes_deudores($this->dueno->id, null, $this->dueno);

        $this->assertSame([(int) $this->clientes['pichi']->id], array_column($deudores['clientes'], 'cliente_id'));
    }

    // ------------------------------------------------------------------ 9

    /**
     * Los textos que le dicen al modelo qué herramienta usar: la descripción de
     * `consultar_ventas_sin_cobrar` manda "quién me debe" a `cuenta_corriente` y ya no dice que el
     * ranking de ventas es "quién me debe más"; la de los recordatorios dice a quién NO le llega.
     *
     * @test
     */
    public function los_textos_mandan_quien_me_debe_a_la_cuenta_corriente()
    {
        $descripcion = null;

        foreach ((new AsistenteIaService())->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'consultar_ventas_sin_cobrar') {
                $descripcion = $herramienta['description'];
            }
        }

        $this->assertNotNull($descripcion, 'No está la tool consultar_ventas_sin_cobrar.');
        $this->assertStringContainsString('cuenta_corriente', $descripcion);
        $this->assertStringContainsString('saldos iniciales', $descripcion);
        $this->assertStringNotContainsString('el ranking de clientes que más deben', $descripcion);
        $this->assertStringNotContainsString('Es la herramienta de "cuánto me deben", "cuánta plata tengo en la calle" y "quién me debe más"', $descripcion);

        $recordatorios = null;

        foreach (HerramientasDeCarga::definiciones() as $herramienta) {
            if ($herramienta['name'] === 'proponer_recordatorios_de_cobro') {
                $recordatorios = $herramienta['description'];
            }
        }

        $this->assertNotNull($recordatorios, 'No está la tool proponer_recordatorios_de_cobro.');
        $this->assertStringContainsString('uno que debe solo un saldo inicial (importado o cargado a mano) no entra', $recordatorios);
    }
}

/**
 * Doble mínimo para el test 7: la lectura del saldo de cuenta corriente tira, como tiraría la base
 * caída a mitad de la respuesta. Todo lo demás es el helper real.
 */
class VentasSinCobrarConLecturaRota extends VentasSinCobrarIaHelper
{
    /**
     * @param  int  $owner_id
     * @param  int  $dias
     * @param  float|null  $monto_minimo
     * @return array
     */
    public static function bloque_de_cuenta_corriente($owner_id, $dias = 0, $monto_minimo = null)
    {
        throw new \RuntimeException('Lectura de cuentas corrientes rota a propósito (test 125).');
    }
}
