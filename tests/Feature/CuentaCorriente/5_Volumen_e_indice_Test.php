<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión cuenta-corriente-carrera-y-velocidad (23/9/2026) — con volumen de verdad, las consultas de
 * la cadena usan el índice.
 *
 * Fenix tiene ~165.000 movimientos en `current_acounts`; sin índice por cuenta, cada `getSaldo()` era
 * un full scan (187 ms) y `checkSaldos()` lo hacía una vez por movimiento. Acá se siembran 150.000
 * filas repartidas en 750 cuentas (INSERT por lotes, no factories), se captura el SQL que ejecutan de
 * verdad `getSaldo()` y `checkSaldos()`, y se corre `EXPLAIN` de cada consulta sobre
 * `current_acounts`: todas tienen que usar un índice. También se mide `checkSaldos()` sobre una
 * cuenta de 200 movimientos.
 *
 * 🔴 Sin DatabaseTransactions: la siembra se commitea (el optimizador tiene que ver la tabla con
 * volumen, y `ANALYZE TABLE` no corre adentro de una transacción) y se borra en tearDown().
 *
 * Tarda ~25 segundos en la máquina de los slots (casi todo es la siembra y la limpieza), y más en
 * una máquina cargada: va con su propio grupo para poder correrlo aparte de la suite.
 *
 * @group lento
 * @group cuenta-corriente
 */
class Volumen_e_indice_Test extends TestCase
{
    use ArmaCadenas;

    /** Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** Marca de las filas sembradas, para borrarlas. */
    const MARCA = 'zz-volumen-test-cc';

    /** Cuentas falsas (sin fila en credit_accounts: solo hacen volumen). */
    const CUENTAS_FALSAS = 749;

    /** Primer id de cuenta falsa: lejos de cualquier id real. */
    const CUENTA_FALSA_DESDE = 9100001;

    /** Movimientos por cuenta. */
    const FILAS_POR_CUENTA = 200;

    /** @var array<int,int> */
    protected $clientes = [];

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::table('current_acounts')->where('detalle', self::MARCA)->delete();

        if (count($this->clientes)) {
            DB::table('credit_accounts')->where('model_name', 'client')->whereIn('model_id', $this->clientes)->delete();
            DB::table('clients')->whereIn('id', $this->clientes)->delete();
        }

        parent::tearDown();
    }

    /**
     * @test
     */
    public function con_150000_movimientos_las_consultas_de_la_cadena_usan_el_indice()
    {
        $indice = DB::select("SHOW INDEX FROM current_acounts WHERE Seq_in_index = 1 AND Column_name = 'credit_account_id'");

        $this->assertNotEmpty($indice, 'current_acounts no tiene índice por credit_account_id: falta correr la migración 2026_09_23_120000_add_cuenta_corriente_indexes.');

        list($cliente, $cuenta) = $this->cliente_con_cuenta(self::USER_ID, 'Volumen');

        $this->clientes[] = $cliente->id;

        $this->sembrar($cuenta->id, $cliente->id);

        DB::statement('ANALYZE TABLE current_acounts');

        $this->assertGreaterThanOrEqual(150000, DB::table('current_acounts')->where('detalle', self::MARCA)->count());

        $medio = CurrentAcount::where('credit_account_id', $cuenta->id)->orderBy('created_at')->orderBy('id')->skip(100)->first();

        // El SQL que ejecutan de verdad getSaldo() (con y sin tope) y checkSaldos(), adentro de una
        // transacción como en un request.
        DB::beginTransaction();
        DB::flushQueryLog();
        DB::enableQueryLog();

        CurrentAcountHelper::getSaldo($cuenta->id, $medio);
        CurrentAcountHelper::getSaldo($cuenta->id);
        CurrentAcountHelper::checkSaldos($cuenta->id);

        $consultas = DB::getQueryLog();

        DB::disableQueryLog();
        DB::commit();

        $explicadas = 0;

        foreach ($consultas as $consulta) {

            if (stripos($consulta['query'], 'select') !== 0 || stripos($consulta['query'], 'from `current_acounts`') === false) {
                continue;
            }

            foreach (DB::select('EXPLAIN '.$consulta['query'], $consulta['bindings']) as $fila) {

                if ($fila->table != 'current_acounts') {
                    continue;
                }

                $this->assertNotNull($fila->key, 'Sin índice (type='.$fila->type.', '.$fila->rows.' filas): '.$consulta['query']);
                $this->assertNotEquals('ALL', $fila->type, 'Full scan: '.$consulta['query']);

                $explicadas++;
            }
        }

        $this->assertGreaterThanOrEqual(3, $explicadas, 'Se esperaban al menos las consultas de getSaldo (x2) y la lectura de checkSaldos.');

        // Tiempo de checkSaldos sobre la cuenta de 200 movimientos: con la cadena entera en NULL
        // (escribe las 200 filas) y ya correcta (no escribe ninguna).
        DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->update(['saldo' => null]);

        $inicio = microtime(true);
        CurrentAcountHelper::checkSaldos($cuenta->id);
        $ms_escribiendo = (microtime(true) - $inicio) * 1000;

        $inicio = microtime(true);
        CurrentAcountHelper::checkSaldos($cuenta->id);
        $ms_sin_cambios = (microtime(true) - $inicio) * 1000;

        fwrite(STDERR, PHP_EOL.'checkSaldos, cuenta de '.self::FILAS_POR_CUENTA.' movimientos sobre 150.000 filas: '.round($ms_escribiendo).' ms reescribiendo la cadena, '.round($ms_sin_cambios).' ms con la cadena ya correcta.'.PHP_EOL);

        $this->assert_cadena_cierra($cuenta->id, 'Volumen');

        // Márgenes anchos a propósito (la máquina de tests no es el hosting): sin el índice y con
        // el checkSaldos anterior esto eran decenas de segundos.
        $this->assertLessThan(5000, $ms_escribiendo);
        $this->assertLessThan(1000, $ms_sin_cambios);
    }

    /**
     * 200 movimientos para la cuenta real y 200 para cada una de las 749 falsas, intercalados en el
     * orden de inserción como en producción (cada cliente compra de a poco, mezclado con los demás).
     *
     * @param  int  $credit_account_id
     * @param  int  $client_id
     * @return void
     */
    protected function sembrar($credit_account_id, $client_id)
    {
        $cuentas = [$credit_account_id => $client_id];

        for ($k = 0; $k < self::CUENTAS_FALSAS; $k++) {
            $cuentas[self::CUENTA_FALSA_DESDE + $k] = 9100001 + $k;
        }

        $inicio = strtotime('2025-01-01 08:00:00');
        $ahora = date('Y-m-d H:i:s');
        $lote = [];

        for ($i = 0; $i < self::FILAS_POR_CUENTA; $i++) {

            foreach ($cuentas as $cuenta_id => $cliente_id) {

                $es_pago = ($i % 4 == 3);

                $lote[] = [
                    'detalle'           => self::MARCA,
                    'status'            => $es_pago ? 'pago_from_client' : 'sin_pagar',
                    'debe'              => $es_pago ? null : 100 + ($i % 7),
                    'haber'             => $es_pago ? 150 : null,
                    'saldo'             => null,
                    'client_id'         => $cliente_id,
                    'credit_account_id' => $cuenta_id,
                    'user_id'           => self::USER_ID,
                    'is_provisorio'     => 0,
                    // Cada tanto, dos movimientos de la misma cuenta en el mismo segundo.
                    'created_at'        => date('Y-m-d H:i:s', $inicio + intdiv($i, 2) * 3600 + ($cuenta_id % 50)),
                    'updated_at'        => $ahora,
                ];

                if (count($lote) >= 2000) {
                    DB::table('current_acounts')->insert($lote);
                    $lote = [];
                }
            }
        }

        if (count($lote)) {
            DB::table('current_acounts')->insert($lote);
        }
    }
}
