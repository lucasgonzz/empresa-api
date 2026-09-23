<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Misión cuenta-corriente-carrera-y-velocidad (23/9/2026) — los endpoints reales de venta esperan el
 * candado de la cuenta corriente del cliente.
 *
 * Una SEGUNDA conexión (otro request, en la vida real) retiene `SELECT ... FOR UPDATE` sobre la fila
 * del cliente; la conexión del request corre con `innodb_lock_wait_timeout = 1`. Si el endpoint toma
 * el candado de la cuenta (CuentaCorrienteLock), se queda esperando y a los ~1 s corta con un lock
 * wait timeout en el `SELECT ... FOR UPDATE` sobre `clients`, que su catch reporta.
 *
 * Con el código de develop estos dos tests dan rojo, y por un motivo que vale la pena saber: el
 * request TAMBIÉN termina esperando la fila del cliente, pero recién al final, en el UPDATE de
 * `saldo_pesos` (`set_model_saldo()`), después de haber leído y recalculado toda la cuenta con lo
 * que veía. Lo que se fija acá es que la espera sea AL ENTRAR, antes de leer nada.
 *
 * Se prueban `POST api/sale` (alta) y `PUT api/sale/{id}` (edición), autenticados como los otros
 * feature tests de ventas (`actingAs` del usuario del fixture, guard `web`).
 *
 * 🔴 Sin DatabaseTransactions: la otra conexión tiene que ver al cliente, así que los datos se
 * commitean y se borran en tearDown(). Los requests que se prueban cortan y hacen rollback, así
 * que no dejan nada.
 *
 * @group cuenta-corriente
 */
class Los_endpoints_esperan_el_candado_Test extends TestCase
{
    use ArmaCadenas;

    /** Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** La otra conexión, clonada de la default en runtime. */
    const OTRA_CONEXION = 'cc_otro_request';

    /** @var array<int,int> */
    protected $clientes = [];

    /** @var array<int,int> */
    protected $ventas = [];

    /** @var array<int,\Throwable> Excepciones que el handler reportó durante el request. */
    protected $reportadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::OTRA_CONEXION => config('database.connections.'.config('database.default'))]);

        $this->actingAs(User::find(self::USER_ID), 'web');

        // Lo que report() manda al handler termina en Log::error('Error: ', ['error' => $e]).
        Event::listen(MessageLogged::class, function ($mensaje) {
            if (isset($mensaje->context['error']) && $mensaje->context['error'] instanceof \Throwable) {
                $this->reportadas[] = $mensaje->context['error'];
            }
        });
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::connection(self::OTRA_CONEXION)->rollBack();
        DB::purge(self::OTRA_CONEXION);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

        if (count($this->ventas)) {
            DB::table('current_acounts')->whereIn('sale_id', $this->ventas)->delete();
            DB::table('sales')->whereIn('id', $this->ventas)->delete();
        }

        if (count($this->clientes)) {
            DB::table('current_acounts')->whereIn('client_id', $this->clientes)->delete();
            DB::table('credit_accounts')->where('model_name', 'client')->whereIn('model_id', $this->clientes)->delete();
            DB::table('clients')->whereIn('id', $this->clientes)->delete();
        }

        parent::tearDown();
    }

    /**
     * La otra conexión toma la cuenta del cliente y la retiene; la del request espera 1 s como mucho.
     *
     * @param  int  $client_id
     * @return void
     */
    protected function otro_request_retiene_la_cuenta_de($client_id)
    {
        $otra = DB::connection(self::OTRA_CONEXION);

        $otra->beginTransaction();
        $otra->select('SELECT id FROM clients WHERE id = ? FOR UPDATE', [$client_id]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /**
     * El request cortó por esperar la fila del cliente: hay un lock wait timeout reportado cuyo SQL
     * es el SELECT ... FOR UPDATE sobre `clients`.
     *
     * @param  float   $segundos
     * @param  string  $contexto
     * @return void
     */
    protected function assert_espero_la_cuenta($segundos, $contexto)
    {
        $this->assertGreaterThanOrEqual(0.9, $segundos, $contexto.': el request no esperó el candado.');

        $esperas = array_filter($this->reportadas, function ($e) {
            return strpos($e->getMessage(), 'Lock wait timeout') !== false
                && stripos($e->getMessage(), 'from `clients`') !== false
                && stripos($e->getMessage(), 'for update') !== false;
        });

        $this->assertNotEmpty($esperas, $contexto.': no se reportó un lock wait timeout sobre la fila del cliente. Reportadas: '.implode(' | ', array_map(function ($e) {
            return substr($e->getMessage(), 0, 200);
        }, $this->reportadas)));
    }

    /**
     * @test
     */
    public function la_edicion_de_una_venta_espera_el_candado_de_la_cuenta()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta(self::USER_ID, 'Edicion espera');
        $this->clientes[] = $cliente->id;

        $venta = $this->venta($cliente, 150, Carbon::parse('2026-09-15 10:00:00'));
        $this->ventas[] = $venta->id;

        SaleHelper::create_current_acount($venta);

        $this->otro_request_retiene_la_cuenta_de($cliente->id);

        $inicio = microtime(true);

        $respuesta = $this->putJson('api/sale/'.$venta->id, [
            'client_id'              => $cliente->id,
            'save_current_acount'    => 1,
            'items'                  => [],
            'discounts'              => [],
            'surchages'              => [],
            'returned_items'         => [],
            'sub_total'              => 175,
            'total'                  => 175,
            'discounts_in_services'  => 0,
            'surchages_in_services'  => 0,
            'to_check'               => 0,
            'checked'                => 0,
            'confirmed'              => 0,
        ]);

        $segundos = microtime(true) - $inicio;

        $this->assertEquals(500, $respuesta->getStatusCode(), 'La edición tenía que cortar esperando la cuenta: '.$respuesta->getContent());

        $this->assert_espero_la_cuenta($segundos, 'PUT api/sale');

        // Y cortó sin escribir nada: el total y el movimiento siguen como estaban.
        $this->assertEqualsWithDelta(150, (float) DB::table('sales')->where('id', $venta->id)->value('total'), 0.01);
        $this->assertEqualsWithDelta(150, (float) DB::table('current_acounts')->where('sale_id', $venta->id)->value('debe'), 0.01);
    }

    /**
     * @test
     */
    public function el_alta_de_una_venta_espera_el_candado_de_la_cuenta()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta(self::USER_ID, 'Alta espera');
        $this->clientes[] = $cliente->id;

        $ventas_antes = DB::table('sales')->where('client_id', $cliente->id)->count();

        $this->otro_request_retiene_la_cuenta_de($cliente->id);

        $inicio = microtime(true);

        $respuesta = $this->postJson('api/sale', [
            'client_id'              => $cliente->id,
            'save_current_acount'    => 1,
            'omitir_en_cuenta_corriente' => 0,
            'items'                  => [],
            'discounts'              => [],
            'surchages'              => [],
            'sub_total'              => 90,
            'total'                  => 90,
            'discounts_in_services'  => 0,
            'surchages_in_services'  => 0,
            'to_check'               => 0,
            'moneda_id'              => 1,
        ]);

        $segundos = microtime(true) - $inicio;

        $this->assertEquals(500, $respuesta->getStatusCode(), 'El alta tenía que cortar esperando la cuenta: '.$respuesta->getContent());

        $this->assertStringContainsString('Lock wait timeout', (string) $respuesta->json('message'));

        $this->assert_espero_la_cuenta($segundos, 'POST api/sale');

        $this->assertEquals($ventas_antes, DB::table('sales')->where('client_id', $cliente->id)->count(), 'El alta que cortó no puede dejar la venta.');
    }
}
