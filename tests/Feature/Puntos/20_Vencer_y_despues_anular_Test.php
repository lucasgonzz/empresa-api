<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\puntos\PuntosSaldoHelper;
use App\Models\MovimientoPunto;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 20 — VENCER Y DESPUÉS ANULAR NO PUEDE RESTAR DOS VECES.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL DEFECTO QUE ESTOS TESTS DEJAN CLAVADO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `PuntosAcumulacionHelper::revertir_lote()` compensaba SIEMPRE el total otorgado por el lote,
 *  sin mirar qué se lo había comido. Para el CANJE eso es correcto y está decidido —el cliente
 *  ya se llevó el descuento en pesos, así que anularle la venta tiene que dejarle el saldo
 *  negativo—, pero para el VENCIMIENTO no: esos puntos ya salieron del saldo con la fila
 *  'vencidos', y el reverso los volvía a restar.
 *
 *  Medido contra la base antes del arreglo, con una venta de 100 puntos:
 *
 *      saldo con la venta      :  100
 *      saldo despues de vencer :    0
 *      saldo despues de anular : -100    ← el mismo punto restado dos veces
 *         ganados    100.00
 *         vencidos  -100.00
 *         revertidos -100.00
 *
 *  Ese -100 le queda al cliente PARA SIEMPRE y además le bloquea el canje: nunca vuelve a
 *  llegar al mínimo del programa. Y no se arregla solo: el reconciliador es idempotente, así
 *  que la próxima corrida mira el reverso, lo ve igual, y no escribe nada.
 *
 *  🔴 LOS DOS CASOS SE MIDEN JUNTOS A PROPÓSITO. El arreglo tiene que distinguir quién consumió
 *  el lote —`movimiento_punto_consumos` sabe si fue un 'canjeados' o un 'vencidos'— y no puede
 *  ser "revertir el remanente", que arreglaría el vencimiento y rompería el canje. Por eso el
 *  último test de este archivo vuelve a medir el comportamiento del canje: si alguien "arregla"
 *  esto perdonando todo lo consumido, ese test se pone rojo.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */
class Vencer_y_despues_anular_Test extends PuntosTestCase
{
    /**
     * Una venta de MOSTRADOR ya acumulada, con su lote a punto de vencer.
     *
     * El `vence_at` se escribe con el query builder para no depender de dejar correr un año:
     * el lote nace con el vencimiento del programa y acá se lo adelanta al pasado, que es
     * exactamente el estado en el que `puntos:vencer` lo encuentra en producción.
     *
     * @param  float  $total_con_iva
     * @return array  [\App\Models\Client, \App\Models\Sale, \App\Models\MovimientoPunto]
     */
    protected function escenario($total_con_iva = 121000)
    {
        $this->dar_extencion();

        $this->crear_programa([
            'puntos_cada'       => 1000,
            'puntos_por_tramo'  => 1,
            'valor_punto'       => 10,
            'vencimiento_meses' => 12,
            'minimo_canje'      => 1,
            'tope_porcentaje'   => 100,
        ]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, $total_con_iva, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $lotes = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $lotes, 'La venta tenía que dejar un lote de puntos.');

        $lote = $lotes->first();

        MovimientoPunto::where('id', $lote->id)->update(['vence_at' => Carbon::now()->subDay()]);

        $lote->refresh();

        return [$cliente, $venta, $lote];
    }

    /**
     * 🔴 EL DEFECTO, TAL CUAL LO MIDIÓ EL CHEQUEO INDEPENDIENTE.
     *
     * Venta de 100 puntos -> vencen -> se borra la venta. El saldo tiene que quedar en 0, no en
     * -100: los puntos ya se le habían sacado al cliente cuando vencieron.
     *
     * @group puntos
     * @test
     */
    public function vencer_y_despues_anular_deja_el_saldo_en_cero_y_no_en_negativo()
    {
        list($cliente, $venta, $lote) = $this->escenario();

        $this->assertEqualsWithDelta(100, $this->saldo_de_puntos($cliente), self::DELTA, 'El escenario no arrancó con 100 puntos.');

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->assertEqualsWithDelta(
            0,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Después de vencer, el saldo del cliente tiene que ser 0.'
        );

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEqualsWithDelta(
            0,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Anular una venta cuyos puntos YA VENCIERON no puede volver a restarlos: el saldo tiene '.
            'que quedar en 0. Un saldo negativo permanente además le bloquea el canje al cliente.'
        );
    }

    /**
     * El detalle del libro, no solo el total: el reverso tiene que valer 0, porque el lote ya no
     * aportaba nada al saldo cuando se lo anuló.
     *
     * @group puntos
     * @test
     */
    public function el_reverso_de_un_lote_ya_vencido_vale_cero()
    {
        list($cliente, $venta, $lote) = $this->escenario();

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $lote->refresh();

        $this->assertNotNull($lote->anulado_at, 'El lote de una venta borrada tiene que quedar anulado igual.');

        $reversos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $reversos, 'La anulación tiene que dejar su fila de reverso, aunque valga 0.');

        $this->assertEqualsWithDelta(
            0,
            (float) $reversos->first()->puntos,
            self::DELTA,
            'El reverso tiene que compensar lo que el lote TODAVÍA aportaba al saldo. Como el '.
            'vencimiento ya se lo llevó entero, eso es 0.'
        );
    }

    /**
     * El caso MEZCLADO, que es donde se ve si el arreglo distingue de verdad quién consumió qué.
     *
     * Lote de 100: el cliente canjea 40 y después vencen los 60 que quedaban. Al anular la venta
     * hay que revertir SOLO los 40 del canje —esos sí se los llevó el cliente en pesos— y no los
     * 60 vencidos, que ya salieron del saldo.
     *
     *   ganados +100, canjeados -40, vencidos -60  -> saldo 0
     *   revertidos -40                             -> saldo -40
     *
     * @group puntos
     * @test
     */
    public function con_canje_y_vencimiento_solo_se_revierte_la_parte_canjeada()
    {
        list($cliente, $venta, $lote) = $this->escenario();

        /*
         * El canje se arma con el MISMO helper que usa el canje de verdad, para que el estado
         * quede exactamente como lo deja producción: el movimiento negativo, la fila de consumo
         * y el `consumido` del lote, los tres coherentes entre sí.
         */
        $canje = MovimientoPunto::create([
            'user_id'              => $this->comercio()->id,
            'client_id'            => $cliente->id,
            'sistema_de_puntos_id' => $lote->sistema_de_puntos_id,
            'tipo'                 => 'canjeados',
            'puntos'               => -40,
            'sale_id'              => null,
            'price_type_id'        => 0,
            'monto_base'           => null,
            'detalle'              => 'Canje del test 20',
            'consumido'            => 0,
        ]);

        PuntosSaldoHelper::consumir_lotes($canje, [$lote], 40);

        $this->assertEqualsWithDelta(60, $this->saldo_de_puntos($cliente), self::DELTA, 'Después del canje tienen que quedar 60.');

        $this->artisan('puntos:vencer')->assertExitCode(0);

        $this->assertEqualsWithDelta(0, $this->saldo_de_puntos($cliente), self::DELTA, 'Los 60 que quedaban tenían que vencer.');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $reversos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $reversos);

        $this->assertEqualsWithDelta(
            -40,
            (float) $reversos->first()->puntos,
            self::DELTA,
            'Hay que revertir los 40 que el cliente CANJEÓ (ya se llevó el descuento) y no los 60 '.
            'que VENCIERON (ya salieron del saldo con su propia fila).'
        );

        $this->assertEqualsWithDelta(
            -40,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El saldo tiene que quedar en -40: la deuda del canje, y nada más.'
        );
    }

    /**
     * 🔴 EL CONTRASTE QUE PROTEGE LA OTRA MITAD DE LA REGLA.
     *
     * Sin vencimiento de por medio, anular una venta cuyos puntos YA SE CANJEARON tiene que
     * seguir dejando el saldo negativo. Es una decisión declarada del módulo: perdonar la
     * diferencia sería que el negocio pague dos veces el mismo descuento.
     *
     * Si alguien "arreglara" el defecto de arriba revirtiendo el remanente en vez de distinguir
     * quién consumió el lote, este test se pone rojo.
     *
     * @group puntos
     * @test
     */
    public function anular_una_venta_ya_canjeada_sigue_dejando_el_saldo_negativo()
    {
        list($cliente, $venta, $lote) = $this->escenario();

        // Se le saca el vencimiento adelantado: acá no tiene que vencer nada.
        MovimientoPunto::where('id', $lote->id)->update(['vence_at' => Carbon::now()->addYear()]);
        $lote->refresh();

        $canje = MovimientoPunto::create([
            'user_id'              => $this->comercio()->id,
            'client_id'            => $cliente->id,
            'sistema_de_puntos_id' => $lote->sistema_de_puntos_id,
            'tipo'                 => 'canjeados',
            'puntos'               => -100,
            'sale_id'              => null,
            'price_type_id'        => 0,
            'monto_base'           => null,
            'detalle'              => 'Canje del test 20',
            'consumido'            => 0,
        ]);

        PuntosSaldoHelper::consumir_lotes($canje, [$lote], 100);

        $this->assertEqualsWithDelta(0, $this->saldo_de_puntos($cliente), self::DELTA);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $reversos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $reversos);

        $this->assertEqualsWithDelta(
            -100,
            (float) $reversos->first()->puntos,
            self::DELTA,
            'Lo CANJEADO se revierte entero: el cliente ya se llevó el descuento en pesos.'
        );

        $this->assertEqualsWithDelta(
            -100,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El saldo negativo acá es deliberado y está documentado: perdonar la diferencia sería '.
            'que el negocio pague dos veces el mismo descuento.'
        );
    }

    /**
     * Y el caso base, sin canje ni vencimiento: se revierte todo, como siempre.
     *
     * @group puntos
     * @test
     */
    public function anular_una_venta_sin_consumos_revierte_el_total()
    {
        list($cliente, $venta, $lote) = $this->escenario();

        MovimientoPunto::where('id', $lote->id)->update(['vence_at' => Carbon::now()->addYear()]);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $reversos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $reversos);

        $this->assertEqualsWithDelta(-100, (float) $reversos->first()->puntos, self::DELTA);

        $this->assertEqualsWithDelta(0, $this->saldo_de_puntos($cliente), self::DELTA);
    }
}
