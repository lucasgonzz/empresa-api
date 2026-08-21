<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\puntos\PuntosSaldoHelper;
use App\Models\MovimientoPunto;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 9 — el comando `puntos:vencer`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUÉ ES UN COMANDO Y NO UN CÁLCULO PEREZOSO AL LEER EL SALDO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El saldo lo leen tres lugares distintos —VENDER al elegir el cliente, la ficha del cliente y el
 *  reporte de pasivo—. Si "vencido" fuera una condición del SELECT, los tres tendrían que
 *  implementar la misma exclusión y el primero que se la olvide muestra otro número. Con el
 *  comando, el vencimiento es una FILA NEGATIVA del libro y el saldo sigue siendo
 *  `SUM(movimiento_puntos.puntos)`, una sola frase imposible de implementar mal.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LO QUE VENCE ES EL REMANENTE, NO LOS PUNTOS QUE EL LOTE OTORGÓ
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un lote de 30 puntos del que el cliente ya canjeó 20 solo puede hacer vencer 10: los otros 20 ya
 *  salieron del saldo cuando se canjearon, y vencerlos otra vez sería cobrárselos dos veces. Y esa
 *  misma columna (`consumido`) es lo que hace que el comando sea idempotente sin una marca aparte:
 *  la segunda corrida ya no encuentra el lote, porque le quedó `puntos - consumido = 0`.
 */
class Vencimiento_Test extends PuntosTestCase
{
    /**
     * Escenario de referencia: tres lotes con historias distintas.
     *
     *   A) 40 puntos, VENCIDO, sin usar          -> vencen los 40
     *   B) 25 puntos, vigente                    -> no se toca
     *   C) 30 puntos, VENCIDO, con 20 canjeados  -> vencen solo 10
     *
     * @return array  [\App\Models\Client, $a, $b, $c]
     */
    protected function escenario()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12, 'valor_punto' => 100]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $a = $this->sembrar_lote($cliente, $sistema, 40, Carbon::now()->subDays(3), Carbon::now()->subMonths(13));
        $b = $this->sembrar_lote($cliente, $sistema, 25, Carbon::now()->addMonths(6), Carbon::now()->subMonths(6));
        $c = $this->sembrar_lote($cliente, $sistema, 30, Carbon::now()->subDays(1), Carbon::now()->subMonths(12));

        /*
         * El canje viejo del lote C se arma con el MISMO helper que usa el canje de verdad, para
         * que la fixture quede exactamente como la deja producción: el movimiento negativo, la fila
         * de consumo y el `consumido` del lote, los tres coherentes entre sí. Escribir el
         * `consumido` a mano dejaría un estado que el sistema no puede producir.
         */
        $canje = MovimientoPunto::create([
            'user_id'              => $this->comercio()->id,
            'client_id'            => $cliente->id,
            'sistema_de_puntos_id' => $sistema->id,
            'tipo'                 => 'canjeados',
            'puntos'               => -20,
            'sale_id'              => null,
            'price_type_id'        => 0,
            'monto_base'           => 2000,
            'detalle'              => 'Canje viejo (fixture del test)',
            'consumido'            => 0,
        ]);

        PuntosSaldoHelper::consumir_lotes($canje, [$c], 20);

        return [$cliente, $a, $b, $c];
    }

    /**
     * El barrido: un solo movimiento `vencidos` por cliente, por el remanente exacto, y el lote
     * vigente intacto.
     *
     * @group puntos
     * @test
     */
    public function el_comando_vence_el_remanente_de_los_lotes_vencidos_y_no_toca_los_vigentes()
    {
        list($cliente, $a, $b, $c) = $this->escenario();

        $saldo_antes = $this->saldo_de_puntos($cliente);

        // 40 + 25 + 30 - 20 = 75
        $this->assertEqualsWithDelta(75, $saldo_antes, self::DELTA, 'El escenario no arrancó con el saldo esperado.');

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $vencidos = $this->movimientos_del_cliente($cliente, 'vencidos');

        $this->assertCount(
            1,
            $vencidos,
            'Tiene que haber UN solo movimiento de vencimiento por cliente y por corrida, no uno por lote.'
        );

        $this->assertEqualsWithDelta(
            -50,
            (float) $vencidos->first()->puntos,
            self::DELTA,
            'Tienen que vencer 40 (lote A entero) + 10 (el remanente del lote C), no los 70 que otorgaron.'
        );
        $this->assertEquals('Vencimiento de puntos', $vencidos->first()->detalle);
        $this->assertNull($vencidos->first()->sale_id, 'Un vencimiento no viene de una venta.');
        $this->assertEquals(0, (int) $vencidos->first()->price_type_id, 'Un vencimiento junta lotes de listas distintas: va con el centinela 0.');
        $this->assertNull($vencidos->first()->employee_id, 'Lo hizo el cron, no una persona.');

        // Los lotes vencidos quedaron sin remanente; el vigente, intacto.
        $a->refresh();
        $b->refresh();
        $c->refresh();

        $this->assertEqualsWithDelta(40, (float) $a->consumido, self::DELTA, 'El lote A tenía que quedar consumido entero.');
        $this->assertEqualsWithDelta(0, (float) $b->consumido, self::DELTA, 'El lote VIGENTE no se puede tocar.');
        $this->assertEqualsWithDelta(30, (float) $c->consumido, self::DELTA, 'El lote C tenía que quedar consumido entero (20 canjeados + 10 vencidos).');

        // Y el detalle auditable: una fila de consumo por lote.
        $consumos = $this->consumos_de($vencidos->first());

        $this->assertCount(2, $consumos, 'Tiene que quedar una fila de consumo por cada lote que aportó al vencimiento.');

        $por_lote = [];

        foreach ($consumos as $consumo) {
            $por_lote[(int) $consumo->movimiento_origen_id] = (float) $consumo->puntos;
        }

        $this->assertEqualsWithDelta(40, $por_lote[$a->id], self::DELTA);
        $this->assertEqualsWithDelta(10, $por_lote[$c->id], self::DELTA);
        $this->assertArrayNotHasKey($b->id, $por_lote, 'El lote vigente no puede figurar en los consumos del vencimiento.');

        $this->assertEqualsWithDelta(
            25,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Después del vencimiento el cliente tiene que quedar solo con el lote vigente.'
        );
    }

    /**
     * 🔴 La segunda corrida no crea nada nuevo. La idempotencia no la da una marca aparte: la da la
     * misma columna `consumido` que impide que un lote ya canjeado se vuelva a vencer.
     *
     * @group puntos
     * @test
     */
    public function la_segunda_corrida_no_vence_nada_de_nuevo()
    {
        list($cliente, $a, $b, $c) = $this->escenario();

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $saldo_tras_la_primera = $this->saldo_de_puntos($cliente);
        $movimientos_tras_la_primera = $this->movimientos_del_cliente($cliente)->count();

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->assertCount(
            1,
            $this->movimientos_del_cliente($cliente, 'vencidos'),
            'La segunda corrida creó otro movimiento de vencimiento: el comando no es idempotente.'
        );
        $this->assertEquals(
            $movimientos_tras_la_primera,
            $this->movimientos_del_cliente($cliente)->count(),
            'La segunda corrida escribió filas nuevas en el libro.'
        );
        $this->assertEqualsWithDelta(
            $saldo_tras_la_primera,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'La segunda corrida le movió el saldo al cliente.'
        );
    }

    /**
     * 🔴 Un lote ANULADO (venta revertida) no se vence: ya se descontó entero por su reverso, y
     * vencerlo otra vez sería restarlo dos veces.
     *
     * @group puntos
     * @test
     */
    public function un_lote_anulado_no_se_vence()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $lote = $this->sembrar_lote($cliente, $sistema, 40, Carbon::now()->subDays(3), Carbon::now()->subMonths(13));

        $lote->anulado_at = Carbon::now();
        $lote->save();

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->assertCount(
            0,
            $this->movimientos_del_cliente($cliente, 'vencidos'),
            'Un lote anulado ya se descontó por su reverso: vencerlo otra vez lo restaría dos veces.'
        );

        $lote->refresh();

        $this->assertEqualsWithDelta(0, (float) $lote->consumido, self::DELTA);
    }

    /**
     * 🔴 `vencimiento_meses` en null significa "los puntos de este programa NO vencen", no "vencen a
     * los cero meses". Sin ese corte, un comercio que apaga el vencimiento le mataría de una todos
     * los lotes que arrastran un `vence_at` de cuando sí vencían.
     *
     * @group puntos
     * @test
     */
    public function con_el_programa_sin_vencimiento_el_comando_no_toca_nada()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        // El lote arrastra un vence_at vencido, de cuando el programa sí vencía puntos.
        $lote = $this->sembrar_lote($cliente, $sistema, 40, Carbon::now()->subDays(3), Carbon::now()->subMonths(13));

        $sistema->vencimiento_meses = null;
        $sistema->save();

        \App\Http\Controllers\Helpers\puntos\PuntosConfigHelper::limpiar_memo();

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->assertCount(
            0,
            $this->movimientos_del_cliente($cliente, 'vencidos'),
            'Con vencimiento_meses en null el comando no puede vencer ni un punto.'
        );

        $lote->refresh();

        $this->assertEqualsWithDelta(0, (float) $lote->consumido, self::DELTA);
        $this->assertEqualsWithDelta(40, $this->saldo_de_puntos($cliente), self::DELTA);
    }

    /**
     * Sin la extensión el comando sale sin tocar la base, aunque haya lotes vencidos y un programa
     * activo. La extensión es lo que se le vendió (o no) al cliente.
     *
     * @group puntos
     * @test
     */
    public function sin_la_extencion_el_comando_no_vence_nada()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $lote = $this->sembrar_lote($cliente, $sistema, 40, Carbon::now()->subDays(3), Carbon::now()->subMonths(13));

        $this->quitar_extencion();

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->assertCount(
            0,
            $this->movimientos_del_cliente($cliente, 'vencidos'),
            'Sin la extensión el comando no puede escribir una sola fila.'
        );

        $lote->refresh();

        $this->assertEqualsWithDelta(0, (float) $lote->consumido, self::DELTA);
    }

    /**
     * Con `--user_id` se vence un solo comercio, y la bandera NO saltea el gate por extensión.
     *
     * @group puntos
     * @test
     */
    public function la_bandera_user_id_no_saltea_el_gate_por_extencion()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $lote = $this->sembrar_lote($cliente, $sistema, 40, Carbon::now()->subDays(3), Carbon::now()->subMonths(13));

        $this->quitar_extencion();

        $this->artisan('puntos:vencer', ['--user_id' => $this->comercio()->id])->assertExitCode(0);

        $this->assertCount(
            0,
            $this->movimientos_del_cliente($cliente, 'vencidos'),
            'La bandera --user_id no puede destrabar un módulo que el comercio no compró.'
        );

        // Y con la extensión puesta, la misma bandera sí vence.
        $this->dar_extencion();

        $this->artisan('puntos:vencer', ['--user_id' => $this->comercio()->id])->assertExitCode(0);

        $this->assertCount(
            1,
            $this->movimientos_del_cliente($cliente, 'vencidos'),
            'Con la extensión puesta, --user_id tiene que vencer ese comercio.'
        );
    }

    /**
     * Un lote SIN `vence_at` (programa que no vencía cuando se otorgó) no se vence nunca, aunque el
     * programa hoy tenga vencimiento configurado. En SQL una comparación contra NULL nunca da
     * verdadera, y eso es exactamente lo que se quiere: esos puntos se prometieron sin vencimiento.
     *
     * @group puntos
     * @test
     */
    public function un_lote_sin_fecha_de_vencimiento_no_vence_nunca()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa(['vencimiento_meses' => 12]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $lote = $this->sembrar_lote($cliente, $sistema, 40, null, Carbon::now()->subMonths(24));

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->assertCount(
            0,
            $this->movimientos_del_cliente($cliente, 'vencidos'),
            'Un lote sin vence_at no puede vencer.'
        );
        $this->assertEqualsWithDelta(40, $this->saldo_de_puntos($cliente), self::DELTA);
    }

    /**
     * Los puntos vencidos ya no se pueden canjear: el saldo bajó por la fila negativa y los lotes
     * quedaron sin remanente. Es la comprobación de que las dos mitades —el saldo y los lotes— no
     * se desalinean.
     *
     * @group puntos
     * @test
     */
    public function despues_de_vencer_el_saldo_y_los_lotes_cuentan_lo_mismo()
    {
        list($cliente, $a, $b, $c) = $this->escenario();

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $saldo = $this->saldo_de_puntos($cliente);

        $remanente_de_los_lotes = 0;

        foreach ($this->movimientos_del_cliente($cliente, 'ganados') as $lote) {
            $remanente_de_los_lotes += PuntosSaldoHelper::remanente_de_lote($lote);
        }

        $this->assertEqualsWithDelta(
            $saldo,
            round($remanente_de_los_lotes, 2),
            self::DELTA,
            'El saldo (SUM de puntos) y el remanente vivo de los lotes empezaron a contar cosas distintas: '.
            'es el bug más caro que puede tener este módulo.'
        );
    }
}
