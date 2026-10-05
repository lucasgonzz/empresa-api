<?php

namespace Tests\Feature\CuentaCorriente;

use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Misión saldo-inicial-cuenta-corriente (5/10/2026) — el saldo inicial toma el candado de la cuenta
 * AL ENTRAR, antes de mirar si la cuenta está vacía.
 *
 * Es lo que hace segura la guarda contra el doble clic: dos pedidos de saldo inicial sobre la misma
 * cuenta se esperan uno al otro en el candado, y el segundo recién mira "¿la cuenta tiene
 * movimientos?" cuando el primero ya commiteó. `10_Saldo_inicial_Test` prueba el segundo intento en
 * serie; este prueba la espera, que es lo que en la vida real separa a los dos pedidos.
 *
 * Mismo armado que `7_Los_endpoints_esperan_el_candado_Test`: una SEGUNDA conexión retiene
 * `SELECT ... FOR UPDATE` sobre la fila del cliente y la del request corre con
 * `innodb_lock_wait_timeout = 1`. Si el endpoint espera el candado, corta a los ~1 s con un lock wait
 * timeout sobre `clients` que su catch reporta, y no deja ningún movimiento.
 *
 * 🔴 Sin DatabaseTransactions: la otra conexión tiene que ver al cliente, así que los datos se
 * commitean y se borran en tearDown().
 *
 * @group cuenta-corriente
 */
class Saldo_inicial_espera_el_candado_Test extends TestCase
{
    use ArmaCadenas;

    /** Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** La otra conexión, clonada de la default en runtime. */
    const OTRA_CONEXION = 'cc_otro_saldo_inicial';

    /** @var array<int,int> Clientes creados por el test, para borrarlos al final. */
    protected $clientes = [];

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

        if (count($this->clientes)) {
            DB::table('current_acounts')->whereIn('client_id', $this->clientes)->delete();
            DB::table('credit_accounts')->where('model_name', 'client')->whereIn('model_id', $this->clientes)->delete();
            DB::table('clients')->whereIn('id', $this->clientes)->delete();
        }

        parent::tearDown();
    }

    /**
     * @test
     */
    public function el_saldo_inicial_espera_el_candado_de_la_cuenta_antes_de_escribir()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta(self::USER_ID, 'Saldo inicial espera');
        $this->clientes[] = $cliente->id;

        // Otro pedido (otra pestaña, el segundo clic) tiene tomada la cuenta del cliente.
        $otra = DB::connection(self::OTRA_CONEXION);
        $otra->beginTransaction();
        $otra->select('SELECT id FROM clients WHERE id = ? FOR UPDATE', [$cliente->id]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $inicio = microtime(true);

        $respuesta = $this->postJson('api/current-acount/saldo-inicial', [
            'credit_account_id' => $cuenta->id,
            'model_name'        => 'client',
            'model_id'          => $cliente->id,
            'is_for_debe'       => true,
            'saldo_inicial'     => 1000,
        ]);

        $segundos = microtime(true) - $inicio;

        $this->assertEquals(500, $respuesta->getStatusCode(), 'El saldo inicial tenía que cortar esperando la cuenta: '.$respuesta->getContent());

        $this->assertGreaterThanOrEqual(0.9, $segundos, 'El saldo inicial no esperó el candado.');

        $esperas = array_filter($this->reportadas, function ($e) {
            return strpos($e->getMessage(), 'Lock wait timeout') !== false
                && stripos($e->getMessage(), 'from `clients`') !== false
                && stripos($e->getMessage(), 'for update') !== false;
        });

        $this->assertNotEmpty($esperas, 'No se reportó un lock wait timeout sobre la fila del cliente. Reportadas: '.implode(' | ', array_map(function ($e) {
            return substr($e->getMessage(), 0, 200);
        }, $this->reportadas)));

        $this->assertEquals(0, DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->count(), 'El pedido que cortó no puede dejar el saldo inicial.');
    }
}
