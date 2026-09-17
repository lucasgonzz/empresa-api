<?php

namespace Tests\Feature\ForzarTotal;

use App\Models\CurrentAcount;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 5 — editar una venta ya guardada. Es la mitad del alcance que pidio Lucas y el punto
 * donde la extension vieja perdia el forzado sin decir nada.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LO QUE ESTE ARCHIVO FIJA, Y POR QUE EL ULTIMO TEST NO ES UN DESCUIDO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `sales.forzar_total_monto` se asigna PELADO en `SaleController::update()`, sin guarda de
 *  `$request->exists()` —al reves de `omitir_en_cuenta_corriente`, que si la tiene—. El motivo es
 *  que el monto NO es independiente de `total`: esta definido contra el ("la venta daba 4.012 y se
 *  cobro 4.000, entonces -12"), y `total` tambien se asigna pelado.
 *
 *  Si el monto se preservara cuando el request no lo manda —la SPA anterior, durante la ventana
 *  entre el despliegue de la api y el de la spa—, la venta quedaria con el total recalculado SIN
 *  forzar y con el monto del forzado viejo todavia puesto. En ese estado `getTotalSale()` volveria
 *  a restar los 12 y el prorrateo de AFIP sacaria el factor contra una base equivocada.
 *
 *  Por eso el test 3 afirma que un PUT sin la clave DEJA LA COLUMNA EN NULL. No es una perdida de
 *  dato por descuido: es la consecuencia correcta de que los dos campos se escriban juntos.
 *
 * @group sales
 * @group forzar_total
 */
class Actualizar_una_venta_forzada_Test extends ForzarTotalTestCase
{
    /**
     * Crea por el endpoint una venta a cuenta corriente con el total forzado.
     *
     * Va a cuenta corriente y no de mostrador porque una venta de mostrador cobrada en un comercio
     * CON cajas configuradas —y el fixture tiene tres— no se puede editar
     * (`SaleHelper::motivo_por_el_que_no_se_puede_editar()`): editarla descuadraria el arqueo.
     *
     * @param  float  $total
     * @param  float  $monto
     * @return \App\Models\Sale
     */
    protected function venta_forzada_en_cuenta_corriente($total, $monto)
    {
        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

        return $this->crear_venta_por_endpoint($this->payload_venta($total, self::BRUTO, [
            'client_id'                  => $cliente->id,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'forzar_total_monto'         => $monto,
        ]));
    }

    /**
     * Test 1 — se edita una venta forzada mandando el mismo forzado: el monto sobrevive.
     *
     * @group forzar_total
     * @test
     */
    public function el_monto_sobrevive_a_una_actualizacion()
    {
        $sale = $this->venta_forzada_en_cuenta_corriente(self::FORZADO, self::MONTO);

        $this->actualizar_venta($sale, $this->payload_actualizar($sale, self::FORZADO, self::BRUTO, [
            'forzar_total_monto' => self::MONTO,
        ]))->assertStatus(200);

        $fila = Sale::find($sale->id);

        $this->assertEqualsWithDelta(self::FORZADO, (float) $fila->total, self::DELTA, 'el total forzado tiene que seguir siendo 4.000');
        $this->assertEqualsWithDelta(self::MONTO, (float) $fila->forzar_total_monto, self::DELTA, 'el monto tiene que seguir siendo -12');
    }

    /**
     * Test 2 — se cambia el forzado en la edicion: gana el nuevo, y la cuenta corriente se entera.
     *
     * @group forzar_total
     * @test
     */
    public function cambiar_el_forzado_al_editar_cambia_el_total_y_la_cuenta_corriente()
    {
        $sale = $this->venta_forzada_en_cuenta_corriente(self::FORZADO, self::MONTO);

        // Ahora el vendedor cobra 3.900: el monto pasa a ser -112 sobre el mismo bruto de 4.012.
        $this->actualizar_venta($sale, $this->payload_actualizar($sale, 3900.00, self::BRUTO, [
            'forzar_total_monto' => -112.00,
        ]))->assertStatus(200);

        $fila = Sale::find($sale->id);

        $this->assertEqualsWithDelta(3900.00, (float) $fila->total, self::DELTA, 'el total tiene que ser el forzado nuevo');
        $this->assertEqualsWithDelta(-112.00, (float) $fila->forzar_total_monto, self::DELTA, 'el monto tiene que ser el nuevo, no el de cuando se creo la venta');

        $movimiento = CurrentAcount::where('sale_id', $sale->id)->first();

        $this->assertNotNull($movimiento, 'La venta tiene que seguir teniendo su movimiento de cuenta corriente.');

        $this->assertEqualsWithDelta(
            3900.00,
            (float) $movimiento->debe,
            self::DELTA,
            'la cuenta corriente tiene que quedar con el total forzado nuevo'
        );
    }

    /**
     * Test 3 — un PUT que NO manda la clave deja la columna en null.
     *
     * Es el comportamiento buscado, no un olvido: ver el bloque de arriba. Lo que ese request esta
     * pidiendo, al mandar el total sin forzar, es exactamente que el forzado no exista mas.
     *
     * @group forzar_total
     * @test
     */
    public function un_put_sin_la_clave_deja_la_venta_sin_forzado()
    {
        $sale = $this->venta_forzada_en_cuenta_corriente(self::FORZADO, self::MONTO);

        // El total que manda un front que no conoce el campo: el bruto, sin forzar.
        $this->actualizar_venta($sale, $this->payload_actualizar($sale, self::BRUTO, self::BRUTO))
            ->assertStatus(200);

        $fila = Sale::find($sale->id);

        $this->assertNull(
            $fila->forzar_total_monto,
            'sin la clave en el request, la columna tiene que quedar en null: el monto y el total se escriben juntos o la venta queda incoherente'
        );

        $this->assertEqualsWithDelta(
            self::BRUTO,
            (float) $fila->total,
            self::DELTA,
            'el total tiene que ser el que mando el request'
        );
    }
}
