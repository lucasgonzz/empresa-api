<?php

namespace Tests\Feature\Puntos;

use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 2 — 🔴 EL CAMINO DE CUENTA CORRIENTE.
 *
 * La regla del negocio es "los puntos aparecen cuando la venta queda cobrada, todo o nada". Eso
 * significa tres momentos y tres respuestas distintas, y los tres se miden acá contra la base:
 *
 *   1. venta guardada, sin un peso pagado  -> ni un movimiento
 *   2. pago PARCIAL                        -> sigue sin un movimiento ('pagandose' no otorga nada)
 *   3. el pago que salda el débito         -> aparece el lote con los puntos exactos
 *
 * Los puntos exactos no son un número redondo elegido a dedo: la base es el NETO SIN IVA del
 * renglón (`article_sale.price_sin_iva`), no el total de la venta. Con el artículo centinela al
 * 21% y un total de $121.000, la base es $100.000 y el programa de 1 punto cada $1.000 otorga 100.
 * Si el módulo tomara `sales.total` en vez del neto, este test daría 121 puntos y lo diría.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ LOS DOS CAMINOS DE `POST api/current-acount/pago`
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El endpoint se bifurca según `current_date` (CurrentAcountController.php:128):
 *
 *    current_date = 0  ->  CurrentAcountHelper::check_saldos_y_pagos()  (recálculo completo)
 *    current_date = 1  ->  new CurrentAcountPagoHelper(...)->init()     (solo imputa este pago)
 *
 *  Los dos saldan el débito igual. Son dos caminos del MISMO hecho económico, así que los dos
 *  tienen que otorgar los mismos puntos: si uno solo anduviera, el cliente vería puntos que
 *  aparecen o no según qué casilla tildó el que cobró. El test
 *  `el_pago_con_fecha_de_hoy_tambien_otorga_los_puntos` es el que mide el segundo camino, y está
 *  escrito aparte justamente para que, si falla, se sepa exactamente cuál de los dos se rompió.
 */
class Acumulacion_al_cobrar_Test extends PuntosTestCase
{
    /** Total con IVA de la venta de referencia. Neto al 21%: $100.000. */
    const TOTAL_VENTA = 121000;

    /** Los puntos que corresponden: floor(100000 / 1000) * 1. */
    const PUNTOS_ESPERADOS = 100;

    /**
     * El recorrido completo: nada, nada con el pago parcial, y el lote exacto al saldar.
     *
     * @group puntos
     * @test
     */
    public function la_venta_de_cuenta_corriente_acumula_recien_cuando_el_debito_queda_saldado()
    {
        $this->dar_extencion();
        $sistema = $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA));

        $debito = $this->debito_de_la_venta($venta);

        $this->assertNotNull($debito, 'La venta de cuenta corriente tiene que haber generado su débito.');
        $this->assertEquals('sin_pagar', $debito->status);

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Una venta de cuenta corriente sin pagar no puede tener ni un movimiento de puntos.'
        );

        /*
         * Pago PARCIAL. El débito queda en 'pagandose', que es exactamente el estado que la
         * decisión de negocio dice que NO otorga nada: los puntos son todo o nada.
         */
        $this->pagar($cliente, 50000, 0)->assertStatus(201);

        $debito->refresh();

        $this->assertEquals(
            'pagandose',
            $debito->status,
            'Con un pago parcial el débito tiene que quedar en pagandose (si no, la aserción siguiente no mide lo que dice medir).'
        );
        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Un pago PARCIAL no otorga ni un punto: la regla es todo o nada.'
        );

        /*
         * El pago que salda. Acá y recién acá aparecen los puntos.
         */
        $this->pagar($cliente, self::TOTAL_VENTA - 50000, 0)->assertStatus(201);

        $debito->refresh();

        $this->assertEquals(
            'pagado',
            $debito->status,
            'El segundo pago tiene que haber saldado el débito de la venta.'
        );

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(
            1,
            $ganados,
            'Al saldarse el débito tiene que haber UN movimiento ganados de esta venta, ni cero ni dos.'
        );

        $lote = $ganados->first();

        $this->assertEqualsWithDelta(
            self::PUNTOS_ESPERADOS,
            (float) $lote->puntos,
            self::DELTA,
            'Los puntos salen del NETO SIN IVA del renglón ($100.000), no del total de la venta ($121.000).'
        );
        $this->assertEqualsWithDelta(100000, (float) $lote->monto_base, self::DELTA, 'El monto_base guardado no es el neto sin IVA.');
        $this->assertEquals($cliente->id, (int) $lote->client_id);
        $this->assertEquals($this->comercio()->id, (int) $lote->user_id);
        $this->assertEquals($sistema->id, (int) $lote->sistema_de_puntos_id, 'El lote tiene que recordar con qué configuración se otorgó.');
        $this->assertEquals('Venta N° '.$venta->num, $lote->detalle);
        $this->assertNull($lote->anulado_at);
        $this->assertEqualsWithDelta(0, (float) $lote->consumido, self::DELTA);

        $this->assertEqualsWithDelta(
            self::PUNTOS_ESPERADOS,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El saldo del cliente es SUM(puntos) y tiene que reflejar el lote recién otorgado.'
        );
    }

    /**
     * 🔴 EL MISMO HECHO ECONÓMICO, POR EL OTRO CAMINO DEL MISMO ENDPOINT.
     *
     * `current_date = 1` es el default de la SPA: `src/components/common/current-acounts/pago/
     * Index.vue:116` inicializa `pago.current_date` en `true`, o sea que el cobro de todos los
     * días —el que se hace con la fecha de hoy— viaja por esta rama. Es el camino MÁS común de los
     * dos, no un caso de borde.
     *
     * En esta rama `CurrentAcountController@pago` NO llama a `CurrentAcountHelper::checkPagos()`:
     * instancia `CurrentAcountPagoHelper` y llama a `init()` derecho
     * (CurrentAcountController.php:128-139). El débito llega a `pagado` igual —eso se afirma acá
     * abajo, para que quede claro que el problema no es la imputación—, pero el enganche del
     * módulo de puntos vive al final de `checkPagos()`, así que por esta rama no corre nadie.
     *
     * @group puntos
     * @test
     */
    public function el_pago_con_fecha_de_hoy_tambien_otorga_los_puntos()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA));

        // current_date = 1: el default de la SPA, el cobro de todos los días.
        $this->pagar($cliente, self::TOTAL_VENTA, 1)->assertStatus(201);

        $debito = $this->debito_de_la_venta($venta);
        $debito->refresh();

        $this->assertEquals(
            'pagado',
            $debito->status,
            'El pago con fecha de hoy tiene que saldar el débito igual que el de fecha pasada.'
        );

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(
            1,
            $ganados,
            'La venta quedó saldada por el camino de current_date = 1 (el default de la SPA, el cobro '.
            'de todos los días) y NO se otorgó ni un punto. Ese camino no pasa por '.
            'CurrentAcountHelper::checkPagos(), que es donde está el único enganche del módulo para '.
            'la cuenta corriente.'
        );
        $this->assertEqualsWithDelta(self::PUNTOS_ESPERADOS, (float) $ganados->first()->puntos, self::DELTA);
    }

    /**
     * El vencimiento del lote se calcula al otorgarlo, con los meses del programa.
     *
     * @group puntos
     * @test
     */
    public function el_lote_nace_con_su_fecha_de_vencimiento_segun_el_programa()
    {
        $this->dar_extencion();
        $this->crear_programa(['vencimiento_meses' => 6]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA));

        $this->pagar($cliente, self::TOTAL_VENTA, 0)->assertStatus(201);

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote, 'La venta saldada tiene que tener su lote.');
        $this->assertNotNull($lote->vence_at, 'Con vencimiento_meses configurado el lote tiene que nacer con vence_at.');

        $esperado = Carbon::now()->addMonths(6);

        $this->assertEqualsWithDelta(
            0,
            $esperado->diffInDays(Carbon::parse($lote->vence_at)),
            1,
            'El vence_at del lote no cae a los 6 meses de hoy.'
        );
    }

    /**
     * Con `vencimiento_meses` en null los puntos no vencen, y el lote nace sin `vence_at`.
     *
     * Es distinto de "vencen a los cero meses", que sería matarlos de una: la diferencia la tiene
     * que sostener el dato, no una condición escondida en el comando de vencimiento.
     *
     * @group puntos
     * @test
     */
    public function con_el_programa_sin_vencimiento_el_lote_nace_sin_vence_at()
    {
        $this->dar_extencion();
        $this->crear_programa(['vencimiento_meses' => null]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA));

        $this->pagar($cliente, self::TOTAL_VENTA, 0)->assertStatus(201);

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote);
        $this->assertNull($lote->vence_at, 'Con vencimiento_meses en null el lote no puede tener fecha de vencimiento.');
    }

    /**
     * Sin programa activo (la extensión prendida pero el comercio todavía sin configurar), una
     * venta saldada no otorga nada. La extensión prende el módulo; el programa prende la emisión.
     *
     * @group puntos
     * @test
     */
    public function con_la_extencion_pero_sin_programa_activo_no_se_otorga_nada()
    {
        $this->dar_extencion();
        $this->crear_programa(['activo' => 0]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA));

        $this->pagar($cliente, self::TOTAL_VENTA, 0)->assertStatus(201);

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Con el programa apagado no se emite un solo punto.'
        );
    }

    /**
     * Y sin la extensión tampoco, aunque haya un programa activo escrito en la base: el gate de la
     * empresa manda sobre la configuración.
     *
     * @group puntos
     * @test
     */
    public function sin_la_extencion_no_se_otorga_nada_aunque_haya_programa_activo()
    {
        $this->crear_programa();
        $this->quitar_extencion();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA));

        $this->pagar($cliente, self::TOTAL_VENTA, 0)->assertStatus(201);

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Sin la extensión el módulo no escribe una sola fila, tenga o no programa configurado.'
        );
    }
}
