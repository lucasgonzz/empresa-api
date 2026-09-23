<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión cuenta-corriente-carrera-y-velocidad (23/9/2026) — la carrera de Fenix, con dos conexiones
 * de verdad.
 *
 * El caso real (venta 54160, informe `20260923-fenix-saldo-cc-venta-54160`): dos requests escriben la
 * misma cuenta corriente a la vez. El que queda esperando sigue con la foto de la base que tomó en su
 * primera lectura (MySQL en REPEATABLE READ) y recalcula la cadena con SELECT comunes, que no ven lo
 * que el otro commiteó. Resultado: saldos calculados sobre totales que ya no existían.
 *
 * Acá se reproduce igual: la conexión por defecto (B) abre una transacción y hace una lectura común
 * —toma la foto—; una SEGUNDA conexión (A), clonada en runtime, le cambia el total a una venta del
 * mismo cliente y commitea; y recién ahí B corre `checkSaldos()`. La cadena tiene que quedar con el
 * valor nuevo de A. Con el `checkSaldos()` anterior a la misión este test da ROJO (comprobado antes
 * de cambiar el helper): leía la foto vieja y dejaba el saldo corto exactamente por el aumento.
 *
 * 🔴 SIN DatabaseTransactions, A PROPÓSITO. Ese trait envuelve la conexión por defecto en una
 * transacción que la segunda conexión no ve (y cuya foto se toma en el setUp), así que los datos se
 * escriben con commit real y se borran a mano en tearDown().
 *
 * @group cuenta-corriente
 */
class Carrera_entre_dos_conexiones_Test extends TestCase
{
    use ArmaCadenas;

    /** Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** Nombre de la segunda conexión, clonada de la default en runtime. */
    const CONEXION_A = 'cc_carrera_a';

    /** @var array<int,int> Clientes creados, para borrarlos en tearDown. */
    protected $clientes = [];

    /** @var array<int,int> Cuentas creadas, para borrarlas en tearDown. */
    protected $cuentas = [];

    protected function setUp(): void
    {
        parent::setUp();

        $default = config('database.default');

        config(['database.connections.'.self::CONEXION_A => config('database.connections.'.$default)]);
    }

    protected function tearDown(): void
    {
        // Si el test cortó con una transacción abierta, se cierra antes de limpiar.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::purge(self::CONEXION_A);

        if (count($this->cuentas)) {
            DB::table('pagado_por')->whereIn('debe_id', DB::table('current_acounts')->whereIn('credit_account_id', $this->cuentas)->pluck('id'))->delete();
            DB::table('current_acounts')->whereIn('credit_account_id', $this->cuentas)->delete();
        }

        if (count($this->clientes)) {
            DB::table('current_acounts')->whereIn('client_id', $this->clientes)->delete();
        }

        if (count($this->clientes)) {
            DB::table('credit_accounts')->where('model_name', 'client')->whereIn('model_id', $this->clientes)->delete();
            DB::table('clients')->whereIn('id', $this->clientes)->delete();
        }

        parent::tearDown();
    }

    /**
     * Cliente con cuenta, commiteado, y registrado para la limpieza.
     *
     * @param  string  $nombre
     * @return array
     */
    protected function cliente_commiteado($nombre)
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta(self::USER_ID, $nombre);

        $this->clientes[] = $cliente->id;
        $this->cuentas[] = $cuenta->id;

        return [$cliente, $cuenta];
    }

    /**
     * @test
     */
    public function el_recalculo_ve_lo_que_otra_conexion_commiteo_despues_de_la_foto()
    {
        list($cliente, $cuenta) = $this->cliente_commiteado('Carrera Andrino');

        $hora = Carbon::parse('2026-09-23 14:00:00');

        // La cadena de Andrino, reducida: tres ventas y un pago, con los saldos bien.
        $this->movimiento($cuenta, ['detalle' => 'Venta 54159', 'debe' => 103727.20, 'saldo' => 103727.20, 'created_at' => $hora->copy()]);
        $venta_54160 = $this->movimiento($cuenta, ['detalle' => 'Venta 54160', 'debe' => 130359.60, 'saldo' => 234086.80, 'created_at' => $hora->copy()->addMinutes(1)]);
        $this->movimiento($cuenta, ['detalle' => 'Pago', 'haber' => 50000, 'saldo' => 184086.80, 'created_at' => $hora->copy()->addMinutes(2)]);
        $this->movimiento($cuenta, ['detalle' => 'Venta 54161', 'debe' => 24570, 'saldo' => 208656.80, 'created_at' => $hora->copy()->addMinutes(3)]);

        DB::table('credit_accounts')->where('id', $cuenta->id)->update(['saldo' => 208656.80]);

        // B: abre transacción y hace una lectura común. Acá se fija la foto.
        //
        // 🔴 En REPEATABLE READ a propósito: desde esta misión la conexión corre en READ COMMITTED
        // (config/database.php), donde cada lectura ve lo último commiteado y el checkSaldos de
        // develop daría bien por casualidad. El test prueba el caso peor —una conexión en RR, como
        // corría el VPS—: ahí el checkSaldos viejo da rojo y el nuevo, con lecturas FOR UPDATE, verde.
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();

        $foto = DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->sum('debe');

        $this->assertEqualsWithDelta(258656.80, (float) $foto, 0.01);

        // A: otra conexión edita la venta 54160 (130.359,60 -> 147.801,60) y commitea.
        DB::connection(self::CONEXION_A)->table('current_acounts')->where('id', $venta_54160->id)->update(['debe' => 147801.60]);

        // B: recalcula la cadena adentro de su transacción, con la foto vieja todavía vigente.
        CurrentAcountHelper::checkSaldos($cuenta->id);

        DB::commit();

        $saldo_final = $this->assert_cadena_cierra($cuenta->id, 'Recalculo con la foto vieja');

        // 208.656,80 + (147.801,60 - 130.359,60) = 226.098,80: el aumento que la foto no veía.
        $this->assertEqualsWithDelta(226098.80, $saldo_final, 0.01);

        $this->assertEqualsWithDelta(226098.80, (float) DB::table('clients')->where('id', $cliente->id)->value('saldo_pesos'), 0.01, 'El saldo del cliente tampoco puede quedar con la foto vieja.');
    }

    /**
     * @test
     */
    public function el_candado_de_la_cuenta_hace_esperar_al_segundo_y_no_toca_a_otro_cliente()
    {
        list($cliente_1, $cuenta_1) = $this->cliente_commiteado('Candado uno');
        list($cliente_2, $cuenta_2) = $this->cliente_commiteado('Candado dos');

        $default = config('database.default');

        // Primero el request que tiene la cuenta.
        DB::beginTransaction();

        $this->assertTrue(CuentaCorrienteLock::bloquear('client', $cliente_1->id));

        DB::connection(self::CONEXION_A)->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // El segundo request corre por la otra conexión: se la pone como default para que el helper
        // la use tal cual la usaría un request de verdad.
        DB::setDefaultConnection(self::CONEXION_A);

        try {

            DB::beginTransaction();

            $espero = false;

            try {
                CuentaCorrienteLock::bloquear('client', $cliente_1->id);
            } catch (QueryException $e) {
                $espero = strpos($e->getMessage(), 'Lock wait timeout') !== false;
            }

            $this->assertTrue($espero, 'El segundo request tenía que quedarse esperando la cuenta del mismo cliente (lock wait timeout).');

            // Otro cliente no espera a nadie.
            $this->assertTrue(CuentaCorrienteLock::bloquear('client', $cliente_2->id));

            DB::rollBack();

        } finally {

            DB::setDefaultConnection($default);
        }

        DB::rollBack();
    }

    /**
     * El recálculo de una cuenta no frena el alta de un movimiento en la cuenta VECINA del índice,
     * que es de otro cliente (en una base compartida, de otro comercio).
     *
     * En REPEATABLE READ el `SELECT ... FOR UPDATE` por rango sobre `cc_cuenta_orden_idx` toma un
     * candado de hueco que llega hasta el primer registro de la cuenta siguiente, así que un INSERT
     * ahí espera a que termine el recálculo. Por eso la conexión corre en READ COMMITTED
     * (config/database.php), que no toma candados de hueco. Se prueban los dos: en RC no espera, y en
     * RR sí (que es lo que justifica el cambio de nivel).
     *
     * @test
     */
    public function en_read_committed_el_recalculo_de_una_cuenta_no_frena_el_alta_en_la_cuenta_vecina()
    {
        $this->assertEquals('READ-COMMITTED', DB::selectOne('SELECT @@transaction_isolation AS nivel')->nivel, 'La conexión tiene que venir en READ COMMITTED desde config/database.php.');

        list($cliente_a, $cuenta_a_pesos) = $this->cliente_commiteado('Vecina A');
        list($cliente_b, $cuenta_b_pesos) = $this->cliente_commiteado('Vecina B');

        // La cuenta en dólares de A es la última de A; la siguiente en el índice es la de pesos de B.
        $cuenta_a = \App\Models\CreditAccount::where('model_name', 'client')->where('model_id', $cliente_a->id)->where('moneda_id', 2)->first();

        $this->assertLessThan($cuenta_b_pesos->id, $cuenta_a->id);

        $t = Carbon::parse('2026-09-20 10:00:00');

        for ($i = 0; $i < 5; $i++) {
            $this->movimiento($cuenta_a, ['detalle' => 'Venta A '.$i, 'debe' => 10, 'created_at' => $t->copy()->addMinutes($i)]);
        }

        // B ya tiene movimientos: el primero de B es el registro que cierra el hueco después de A.
        // El alta de más abajo (con fecha anterior) cae justo en ese hueco. Medido: sin un registro
        // de B después, MySQL 8.3 no bloquea el supremum y el alta no espera ni en RR.
        $this->movimiento($cuenta_b_pesos, ['detalle' => 'Venta B', 'debe' => 20, 'created_at' => $t->copy()->addDays(5)]);

        DB::connection(self::CONEXION_A)->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // Primero RR y después RC, y no al revés: el alta de RC se inserta y se borra, y hasta que
        // InnoDB purga esa fila borrada queda en el índice entre A y B. Un recálculo de A en RR
        // cortaría el hueco en ESA fila y el alta siguiente caería después de ella, fuera del hueco
        // bloqueado: el test daría verde o rojo según cuándo purgue el servidor.
        $this->assertTrue($this->alta_en_la_vecina_espera($cuenta_a, $cuenta_b_pesos, $cliente_b, 'REPEATABLE READ'), 'En REPEATABLE READ el candado de hueco frena el alta en la cuenta vecina: es el motivo del cambio de nivel.');

        $this->assertFalse($this->alta_en_la_vecina_espera($cuenta_a, $cuenta_b_pesos, $cliente_b, null), 'En READ COMMITTED el alta en la cuenta de otro cliente no puede esperar al recálculo.');
    }

    /**
     * Abre un recálculo de la cuenta A en la conexión por defecto (sin commitear) y prueba un INSERT
     * en la cuenta B por la otra conexión.
     *
     * @param  \App\Models\CreditAccount  $cuenta_a
     * @param  \App\Models\CreditAccount  $cuenta_b
     * @param  \App\Models\Client         $cliente_b
     * @param  string|null                $nivel  Nivel de aislamiento para ESA transacción, o null.
     * @return bool  true si el INSERT se quedó esperando (lock wait timeout).
     */
    protected function alta_en_la_vecina_espera($cuenta_a, $cuenta_b, $cliente_b, $nivel)
    {
        if (!is_null($nivel)) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL '.$nivel);
        }

        DB::beginTransaction();

        $espero = false;

        try {

            CurrentAcountHelper::checkSaldos($cuenta_a->id);

            try {

                DB::connection(self::CONEXION_A)->table('current_acounts')->insert([
                    'detalle'           => 'Alta en la vecina',
                    'debe'              => 5,
                    'status'            => 'sin_pagar',
                    'client_id'         => $cliente_b->id,
                    'credit_account_id' => $cuenta_b->id,
                    'user_id'           => self::USER_ID,
                    'is_provisorio'     => 0,
                    'created_at'        => '2026-09-01 08:00:00',
                    'updated_at'        => '2026-09-01 08:00:00',
                ]);

                // Se borra enseguida: si quedara, el próximo alta caería DESPUÉS de ésta en el índice,
                // fuera del hueco que se quiere probar.
                DB::connection(self::CONEXION_A)->table('current_acounts')->where('credit_account_id', $cuenta_b->id)->where('detalle', 'Alta en la vecina')->delete();

            } catch (QueryException $e) {

                $espero = strpos($e->getMessage(), 'Lock wait timeout') !== false;

                if (!$espero) {
                    throw $e;
                }
            }

        } finally {

            DB::rollBack();
        }

        return $espero;
    }

    /**
     * @test
     */
    public function fuera_de_una_transaccion_el_candado_no_hace_nada_y_no_rompe()
    {
        list($cliente, $cuenta) = $this->cliente_commiteado('Candado sin transaccion');

        $this->assertEquals(0, DB::transactionLevel());

        $this->assertFalse(CuentaCorrienteLock::bloquear('client', $cliente->id));

        // Varios ids: ordenados, sin repetidos y sin nulos.
        $this->assertEquals([3, 7, 12], CuentaCorrienteLock::ids_ordenados([12, null, 7, '3', 7, 0]));
    }
}
