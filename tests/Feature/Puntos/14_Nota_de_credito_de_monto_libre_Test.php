<?php

namespace Tests\Feature\Puntos;

use App\Models\CurrentAcount;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 14 — 🔴 LA NOTA DE CRÉDITO DE MONTO LIBRE, LA QUE NO VIENE ATADA A NINGUNA VENTA.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL AGUJERO QUE ESTE ARCHIVO CIERRA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El archivo 8 ya cubre las notas de crédito que SÍ vienen atadas a una venta: las de
 *  `POST api/devoluciones`, que nacen con `current_acounts.sale_id` y que los dos tapones de
 *  `PuntosBaseHelper` ven sin problema.
 *
 *  Esta es la otra: `POST api/current-acount/nota-credito`, la que se carga desde la ficha de
 *  la cuenta corriente del cliente. El request manda monto, descripción y cliente, y NADA MÁS
 *  —es un ajuste a la CUENTA y puede no corresponder a ninguna venta—, así que la NC nace con
 *  `sale_id` en NULL y con `to_pay_id` en NULL. Consecuencia: entra a la cola FIFO, salda la
 *  deuda más vieja del cliente y deja el débito de esa venta en `status = 'pagado'` SIN QUE
 *  HAYA ENTRADO UN PESO.
 *
 *  Con la regla ingenua ("el débito está pagado, dale los puntos"), una venta impaga más una
 *  NC de monto libre por el total le REGALA los puntos a un cliente que no pagó nada. Y el
 *  tapón que existía —`factor_nota_credito()`— no la veía, porque buscaba por `sale_id`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LAS DOS PREGUNTAS, QUE NO SON LA MISMA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   ¿SE COBRÓ?      -> `PuntosAcumulacionHelper::corresponde_acumular()`, mirando `pagado_por`
 *                      y el `status` de los haberes que saldaron el débito. Un débito saldado
 *                      ÚNICAMENTE con notas de crédito no es un cobro: no acumula nada.
 *
 *   ¿SOBRE CUÁNTO?  -> `PuntosBaseHelper::factor_nota_credito()`, que ahora suma también lo
 *                      que las NC ajenas le imputaron al débito. Una venta cobrada mitad con
 *                      plata y mitad con NC acumula por la mitad, no por el total y no por
 *                      cero.
 *
 *  Por eso son dos tests y no uno: si mañana alguien colapsa las dos preguntas en una sola
 *  regla, uno de los dos se pone rojo y dice cuál de las dos mitades se perdió.
 */
class Nota_de_credito_de_monto_libre_Test extends PuntosTestCase
{
    /** Total con IVA de la venta de referencia. Neto al 21%: $100.000. */
    const TOTAL_VENTA = 121000;

    /**
     * Carga una nota de crédito de MONTO LIBRE por el endpoint real, con el mismo cuerpo que
     * manda `src/components/common/current-acounts/NotaCredito.vue`.
     *
     * 🔴 Sin `sale_id`, y eso es el punto del archivo: el componente no lo tiene y no lo manda.
     *
     * @param  \App\Models\Client  $cliente
     * @param  float               $monto
     * @return \Illuminate\Testing\TestResponse
     */
    protected function nota_credito_de_monto_libre($cliente, $monto)
    {
        $cuenta = $this->credit_account($cliente);

        return $this->postJson('api/current-acount/nota-credito', [
            'credit_account_id' => $cuenta->id,
            'model_id'          => $cliente->id,
            'model_name'        => 'client',
            'form'              => [
                'nota_credito' => $monto,
                'description'  => 'Nota de crédito de monto libre (test de puntos)',
            ],
        ]);
    }

    /**
     * La NC de monto libre del comercio, la que no declara ninguna venta.
     *
     * @param  \App\Models\Client  $cliente
     * @return \App\Models\CurrentAcount|null
     */
    protected function nota_credito_libre_del_cliente($cliente)
    {
        return CurrentAcount::where('client_id', $cliente->id)
                            ->where('status', 'nota_credito')
                            ->whereNull('sale_id')
                            ->orderBy('id', 'DESC')
                            ->first();
    }

    /**
     * 🔴 EL BLOQUEANTE: venta IMPAGA + nota de crédito de monto libre por el total.
     *
     * El débito llega a 'pagado' porque la NC lo saldó por la cola FIFO. El cliente no pagó un
     * peso. No puede aparecer ni un punto.
     *
     * @group puntos
     * @test
     */
    public function una_venta_saldada_solo_con_una_nota_de_credito_no_otorga_ni_un_punto()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA));

        $debito = $this->debito_de_la_venta($venta);

        $this->assertNotNull($debito, 'La venta de cuenta corriente tiene que haber generado su débito.');
        $this->assertEquals('sin_pagar', $debito->status);
        $this->assertCount(0, $this->movimientos_de_la_venta($venta), 'Una venta impaga no puede tener movimientos.');

        $this->nota_credito_de_monto_libre($cliente, self::TOTAL_VENTA)->assertStatus(201);

        /*
         * Las dos aserciones de fixture: si alguna se cayera, el test de abajo estaría pasando
         * por el motivo equivocado (no habría NC, o no habría saldado nada).
         */
        $nc = $this->nota_credito_libre_del_cliente($cliente);

        $this->assertNotNull($nc, 'El endpoint tiene que haber creado la nota de crédito.');
        $this->assertNull(
            $nc->sale_id,
            'Esta NC tiene que nacer SIN sale_id: si viniera atada a la venta, este test estaría midiendo el otro camino.'
        );
        $this->assertNull($nc->to_pay_id, 'Sin sale_id no hay imputación dirigida: la NC cae en la cola FIFO.');

        $debito->refresh();

        $this->assertEquals(
            'pagado',
            $debito->status,
            'La NC tiene que haber SALDADO el débito: es justo lo que hace que el motor lo confunda con un cobro.'
        );

        /*
         * 🔴 Y acá está todo el archivo: saldado no es cobrado.
         */
        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(
            0,
            $ganados,
            'Una venta que quedó saldada SOLO con una nota de crédito no se cobró: no puede otorgar puntos.'
        );

        $this->assertEqualsWithDelta(
            0,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El cliente no pagó un peso: su saldo de puntos tiene que seguir en cero.'
        );
    }

    /**
     * Y la otra mitad: cobrada MITAD con plata y MITAD con una NC de monto libre.
     *
     * Acá sí hubo cobro, así que la venta acumula — pero solo por la parte que se cobró. Con
     * $60.500 de plata sobre un total de $121.000, el factor es 0,5: la base baja de $100.000 a
     * $50.000 y los puntos de 100 a 50.
     *
     * 🔴 Este es el test que impide "arreglar" el bloqueante con la regla gruesa ("si tocó una
     * NC, no acumula"): con esa regla, una devolución parcial de una venta de cuenta corriente
     * le comería al cliente TODOS los puntos de la parte que sí pagó.
     *
     * @group puntos
     * @test
     */
    public function una_venta_cobrada_mitad_con_plata_y_mitad_con_nota_de_credito_acumula_por_la_mitad()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA));

        $mitad = self::TOTAL_VENTA / 2;

        $this->pagar($cliente, $mitad, 1)->assertStatus(201);

        $debito = $this->debito_de_la_venta($venta);

        $this->assertEquals(
            'pagandose',
            $debito->status,
            'Con medio pago el débito tiene que quedar en pagandose (si no, lo de abajo no mide lo que dice).'
        );
        $this->assertCount(0, $this->movimientos_de_la_venta($venta), 'Un pago parcial no otorga nada.');

        $this->nota_credito_de_monto_libre($cliente, $mitad)->assertStatus(201);

        $debito->refresh();

        $this->assertEquals('pagado', $debito->status, 'La NC tiene que haber completado el saldado del débito.');

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(
            1,
            $ganados,
            'Hubo plata de por medio: la venta acumula. Que la NC haya completado el saldado no borra el cobro.'
        );

        $this->assertEqualsWithDelta(
            50,
            (float) $ganados->first()->puntos,
            self::DELTA,
            'La NC de monto libre tiene que bajar la base en proporción: $50.000 de base, 50 puntos, no 100 y no 0.'
        );

        $this->assertEqualsWithDelta(
            50000,
            (float) $ganados->first()->monto_base,
            self::DELTA,
            'El monto_base guardado tiene que ser el neto ya descontado por la nota de crédito.'
        );
    }
}
