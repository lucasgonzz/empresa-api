<?php

namespace Tests\Feature\Puntos;

use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 4 — la venta de MOSTRADOR.
 *
 * Es la mitad del sistema que no pasa nunca por la cuenta corriente, y por lo tanto tampoco por
 * `checkPagos()`: un comercio que cobra todo en el acto no tendría un solo punto si el módulo se
 * colgara únicamente del enganche de la cuenta corriente. Acá los puntos aparecen al guardar la
 * venta, sin ningún pago de por medio, y la condición es que la venta esté CONFIRMADA.
 *
 * Las dos formas de que una venta no entre a la cuenta corriente son distintas y las dos se prueban:
 * `save_current_acount = 0` (el comercio no la manda a la cuenta) y `omitir_en_cuenta_corriente = 1`
 * (la venta puntual se omite). `SaleHelper::va_a_volver_a_la_cuenta_corriente()` las mira a las dos.
 *
 * Y los dos casos en que NO corresponde: sin cliente no hay a quién darle puntos, y una venta de
 * depósito (`to_check = 1`) todavía no está confirmada.
 */
class Acumulacion_mostrador_Test extends PuntosTestCase
{
    /** Total con IVA. Neto al 21%: $100.000 -> 100 puntos. */
    const TOTAL_VENTA = 121000;

    const PUNTOS_ESPERADOS = 100;

    /**
     * La venta omitida de la cuenta corriente acumula al guardarse, sin ningún pago.
     *
     * @group puntos
     * @test
     */
    public function la_venta_omitida_de_la_cuenta_corriente_acumula_al_guardarse()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assertNull(
            $this->debito_de_la_venta($venta),
            'La venta omitida no tiene que haber entrado a la cuenta corriente (si entró, este test mide otra cosa).'
        );

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(
            1,
            $ganados,
            'La venta de mostrador tiene que acumular al guardarse: no hay ningún pago que pueda dispararla después.'
        );
        $this->assertEqualsWithDelta(self::PUNTOS_ESPERADOS, (float) $ganados->first()->puntos, self::DELTA);
        $this->assertEqualsWithDelta(self::PUNTOS_ESPERADOS, $this->saldo_de_puntos($cliente), self::DELTA);
    }

    /**
     * Y la otra forma de no entrar a la cuenta corriente: `save_current_acount = 0`.
     *
     * @group puntos
     * @test
     */
    public function la_venta_con_save_current_acount_en_cero_tambien_acumula()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
        ]));

        $this->assertNull($this->debito_de_la_venta($venta));

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados, 'Con save_current_acount = 0 la venta también es de mostrador y tiene que acumular.');
        $this->assertEqualsWithDelta(self::PUNTOS_ESPERADOS, (float) $ganados->first()->puntos, self::DELTA);
    }

    /**
     * Sin cliente no hay a quién darle puntos. Es la primera salida barata del reconciliador y es
     * también la venta más común de un comercio de mostrador.
     *
     * @group puntos
     * @test
     */
    public function la_venta_sin_cliente_no_acumula_nada()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $venta = $this->crear_venta($this->payload_venta(null, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Una venta sin cliente no puede generar un movimiento de puntos: no hay a quién acreditárselos.'
        );
    }

    /**
     * 🔴 La venta de depósito (`to_check = 1`) no acumula hasta que alguien la confirma.
     *
     * Es el mismo par de banderas con el que `SaleHelper::attachProperies()` decide si la venta
     * entra a la cuenta corriente y a la caja: mientras esté sin confirmar, no es un hecho
     * económico cerrado. Al confirmarla editándola, los puntos aparecen.
     *
     * @group puntos
     * @test
     */
    public function la_venta_to_check_no_acumula_hasta_que_se_confirma()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
            'to_check'                   => 1,
        ]));

        $this->assertEquals(1, (int) $venta->to_check, 'La venta tiene que haberse guardado como to_check.');

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Una venta de depósito sin confirmar no puede tener puntos otorgados.'
        );

        // Se confirma editándola: to_check pasa a 0.
        $this->actualizar_venta($venta, $this->payload_actualizar($venta, self::TOTAL_VENTA, [
            'to_check' => 0,
        ]))->assertStatus(200);

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(
            1,
            $ganados,
            'Al confirmar la venta de depósito tienen que aparecer sus puntos.'
        );
        $this->assertEqualsWithDelta(self::PUNTOS_ESPERADOS, (float) $ganados->first()->puntos, self::DELTA);
    }

    /**
     * Una venta de mostrador SIN confirmar (`terminada = 0`, que es lo que deja
     * `SaleHelper::get_terminada()` cuando la venta tiene fecha de entrega) tampoco acumula: la
     * mercadería todavía no se entregó.
     *
     * @group puntos
     * @test
     */
    public function la_venta_de_mostrador_con_fecha_de_entrega_no_acumula_hasta_terminarse()
    {
        $this->dar_extencion();
        // `SaleHelper::get_terminada()` solo deja una venta sin terminar si el comercio tiene esta
        // extensión: sin ella, la fecha de entrega no cambia nada y el test no mediría nada.
        $this->dar_otra_extencion('ventas_con_fecha_de_entrega', 'Ventas con fecha de entrega');
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
            'fecha_entrega'              => \Carbon\Carbon::now()->addDays(5)->format('Y-m-d'),
        ]));

        $this->assertEquals(
            0,
            (int) $venta->terminada,
            'Con fecha de entrega la venta tiene que quedar sin terminar (si no, este test mide otra cosa).'
        );

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Una venta de mostrador todavía sin entregar no cobra puntos, igual que una de cuenta corriente sin pagar.'
        );
    }

    /**
     * Guardar la misma venta de mostrador y después editarla no duplica el lote: el reconciliador
     * corre en el alta y otra vez en el update, y las dos veces tiene que llegar al mismo estado.
     *
     * @group puntos
     * @test
     */
    public function editar_la_venta_de_mostrador_no_duplica_el_lote()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $lote_original = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote_original);

        $this->actualizar_venta($venta, $this->payload_actualizar($venta, self::TOTAL_VENTA))->assertStatus(200);

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados, 'Editar la venta duplicó el lote.');
        $this->assertEquals($lote_original->id, $ganados->first()->id, 'Editar la venta borró y recreó el lote.');
        $this->assertEqualsWithDelta(self::PUNTOS_ESPERADOS, (float) $ganados->first()->puntos, self::DELTA);
    }
}
