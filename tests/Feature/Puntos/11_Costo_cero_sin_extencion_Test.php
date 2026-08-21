<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Http\Controllers\Helpers\puntos\PuntosConfigHelper;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 11 — el COSTO PERMANENTE del módulo para el comercio que NO lo compró.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  POR QUÉ ESTO ES UN TEST Y NO UNA BUENA INTENCIÓN
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El reconciliador cuelga del final de `CurrentAcountHelper::checkPagos()`, que recorre todas las
 *  ventas de la cuenta corriente de un cliente y corre desde once lugares, dos de ellos jobs de
 *  fondo que barren TODAS las cuentas de TODOS los clientes (`ProcessCheckSaldosChunk`,
 *  `iniciar_credit_accounts`). Sin salida barata, un comercio que ni siquiera tiene la extensión
 *  pagaría dos SELECT por venta recorrida en esos jobs — o sea que los ~40 clientes pagarían la
 *  factura de una funcionalidad que no compraron.
 *
 *  El requisito es explícito: CERO consultas sobre las tablas de puntos, y una sola resolución del
 *  gate por request/corrida. Lo segundo lo da la memoización estática de `PuntosConfigHelper`, y es
 *  lo que mide el último test: si alguien la saca, el módulo sigue dando los mismos números y los
 *  jobs se ponen lentos sin que nada lo denuncie.
 */
class Costo_cero_sin_extencion_Test extends PuntosTestCase
{
    /** Las cuatro tablas del módulo. Ninguna se puede tocar sin la extensión. */
    const TABLAS_DE_PUNTOS = [
        'movimiento_puntos',
        'movimiento_punto_consumos',
        'sistemas_de_puntos',
        'price_type_sistema_de_puntos',
    ];

    /**
     * Arranca a medir de cero.
     *
     * @return void
     */
    protected function empezar_a_medir()
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    /**
     * Las consultas registradas que tocan alguna tabla del módulo de puntos.
     *
     * @return array
     */
    protected function consultas_de_puntos()
    {
        $encontradas = [];

        foreach (DB::getQueryLog() as $consulta) {

            foreach (self::TABLAS_DE_PUNTOS as $tabla) {

                if (strpos($consulta['query'], $tabla) !== false) {
                    $encontradas[] = $consulta['query'];
                    break;
                }
            }
        }

        return $encontradas;
    }

    /**
     * Las consultas que resuelven el gate por extensión (la tabla del catálogo y su pivot).
     *
     * @return array
     */
    protected function consultas_de_extencion()
    {
        $encontradas = [];

        foreach (DB::getQueryLog() as $consulta) {

            if (strpos($consulta['query'], 'extencion_empresa') !== false) {
                $encontradas[] = $consulta['query'];
            }
        }

        return $encontradas;
    }

    /**
     * Guardar una venta de cuenta corriente en un comercio sin la extensión no toca ninguna tabla
     * del módulo.
     *
     * @group puntos
     * @test
     */
    public function guardar_una_venta_sin_la_extencion_no_toca_las_tablas_de_puntos()
    {
        $this->quitar_extencion();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $this->empezar_a_medir();

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000));

        $consultas = $this->consultas_de_puntos();

        $this->assertCount(
            0,
            $consultas,
            'Guardar una venta en un comercio sin la extensión tocó las tablas de puntos: '.implode(' | ', $consultas)
        );
    }

    /**
     * Y una venta de mostrador tampoco (es el otro enganche, el de
     * `SaleHelper::attachProperies()`).
     *
     * @group puntos
     * @test
     */
    public function guardar_una_venta_de_mostrador_sin_la_extencion_no_toca_las_tablas_de_puntos()
    {
        $this->quitar_extencion();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $this->empezar_a_medir();

        $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $consultas = $this->consultas_de_puntos();

        $this->assertCount(0, $consultas, 'La venta de mostrador tocó las tablas de puntos: '.implode(' | ', $consultas));
    }

    /**
     * 🔴 Borrar un pago es el camino más caro: dispara `checkPagos()`, que re-imputa la cuenta
     * entera. Sin la extensión, cero consultas del módulo.
     *
     * @group puntos
     * @test
     */
    public function borrar_un_pago_sin_la_extencion_no_toca_las_tablas_de_puntos()
    {
        $this->quitar_extencion();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $this->crear_venta($this->payload_venta($cliente->id, 121000));

        $respuesta = $this->pagar($cliente, 121000, 0);
        $respuesta->assertStatus(201);

        $pago_id = json_decode($respuesta->getContent(), true)['current_acount']['id'];

        $this->empezar_a_medir();

        $this->deleteJson('api/current-acount/client/'.$pago_id)->assertStatus(200);

        $consultas = $this->consultas_de_puntos();

        $this->assertCount(0, $consultas, 'Borrar un pago tocó las tablas de puntos: '.implode(' | ', $consultas));
    }

    /**
     * Y el borrado de la venta, que llama a `deshacer()` y a `revertir_venta()` explícitamente.
     *
     * @group puntos
     * @test
     */
    public function borrar_una_venta_sin_la_extencion_no_toca_las_tablas_de_puntos()
    {
        $this->quitar_extencion();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->empezar_a_medir();

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $consultas = $this->consultas_de_puntos();

        $this->assertCount(0, $consultas, 'Borrar la venta tocó las tablas de puntos: '.implode(' | ', $consultas));
    }

    /**
     * 🔴 LA MEMOIZACIÓN. Veinte corridas de `checkPagos()` sobre la misma cuenta —que es lo que hace
     * un job de fondo recorriendo clientes— no pueden resolver el gate veinte veces.
     *
     * Se mide contra las consultas al catálogo de extensiones porque ése es el gate más barato que
     * el módulo tiene que resolver antes de decidir que no hace nada. Si el número crece con las
     * corridas, la memoización se rompió: nada falla, y los dos jobs de fondo se ponen lentos para
     * los ~40 clientes.
     *
     * @group puntos
     * @test
     */
    public function veinte_corridas_de_checkpagos_no_resuelven_el_gate_veinte_veces()
    {
        $this->quitar_extencion();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->asegurar_cuenta_corriente($cliente);

        $this->crear_venta($this->payload_venta($cliente->id, 121000));

        /*
         * La memoria arranca limpia, como al principio de un request o de una corrida de job: lo
         * que se mide es cuánto cuesta el gate DESDE CERO en veinte pasadas.
         */
        PuntosConfigHelper::limpiar_memo();

        $this->empezar_a_medir();

        for ($i = 0; $i < 20; $i++) {
            CurrentAcountHelper::checkPagos($cuenta->id);
        }

        $consultas_de_puntos = $this->consultas_de_puntos();

        $this->assertCount(
            0,
            $consultas_de_puntos,
            'Veinte corridas de checkPagos() tocaron las tablas de puntos sin la extensión: '.implode(' | ', $consultas_de_puntos)
        );

        $consultas_del_gate = $this->consultas_de_extencion();

        $this->assertLessThanOrEqual(
            2,
            count($consultas_del_gate),
            'El gate por extensión se resolvió '.count($consultas_del_gate).' veces en veinte corridas de '.
            'checkPagos(): la memoización de PuntosConfigHelper dejó de funcionar y los jobs de fondo '.
            'pagan una consulta por venta recorrida.'
        );
    }

    /**
     * El mismo control por el punto de entrada por venta: doscientas reconciliaciones seguidas
     * —el orden de magnitud de un cliente con cuenta corriente movida— no tocan una fila.
     *
     * @group puntos
     * @test
     */
    public function doscientas_reconciliaciones_sin_la_extencion_no_tocan_una_fila()
    {
        $this->quitar_extencion();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        PuntosConfigHelper::limpiar_memo();

        $this->empezar_a_medir();

        for ($i = 0; $i < 200; $i++) {
            PuntosAcumulacionHelper::reconciliar_venta($venta);
        }

        $consultas_de_puntos = $this->consultas_de_puntos();

        $this->assertCount(
            0,
            $consultas_de_puntos,
            'Doscientas reconciliaciones tocaron las tablas de puntos sin la extensión.'
        );

        $consultas_del_gate = $this->consultas_de_extencion();

        $this->assertLessThanOrEqual(
            2,
            count($consultas_del_gate),
            'El gate se resolvió '.count($consultas_del_gate).' veces en doscientas reconciliaciones: '.
            'sin memoización esto son 200 consultas por request en el camino caliente de guardar una venta.'
        );
    }

    /**
     * Contraste: CON la extensión y con programa activo, las tablas SÍ se tocan. Sin este test, los
     * de arriba pasarían igual si el módulo estuviera enteramente desconectado.
     *
     * @group puntos
     * @test
     */
    public function con_la_extencion_las_tablas_si_se_tocan()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $this->empezar_a_medir();

        $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assertGreaterThan(
            0,
            count($this->consultas_de_puntos()),
            'Con la extensión prendida el módulo tiene que leer y escribir: si acá también da cero, '.
            'los tests de costo cero no están midiendo nada.'
        );
    }
}
