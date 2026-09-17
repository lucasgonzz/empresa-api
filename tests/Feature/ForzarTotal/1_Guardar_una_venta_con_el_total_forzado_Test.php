<?php

namespace Tests\Feature\ForzarTotal;

use App\Models\Sale;

/**
 * Archivo 1 — el guardado: el monto llega a la base, con su signo, y el total forzado es el que
 * queda en `sales.total`.
 *
 * Todo se verifica LEYENDO LA FILA DE LA BASE despues del POST, no el cuerpo de la respuesta: lo
 * que importa es lo que quedo guardado, que es lo que van a leer despues la cuenta corriente, el
 * comprobante y la factura.
 *
 * El ultimo test es el de NO REGRESION, y es el mas importante de los cuatro: la enorme mayoria de
 * las ventas del parque no fuerza nada, y una venta sin forzar tiene que comportarse exactamente
 * como antes de esta mision.
 *
 * @group sales
 * @group forzar_total
 */
class Guardar_una_venta_con_el_total_forzado_Test extends ForzarTotalTestCase
{
    /**
     * Test 1 — el caso de la especificacion: la venta da 4.012, el vendedor cobra 4.000.
     *
     * @group forzar_total
     * @test
     */
    public function el_total_forzado_hacia_abajo_se_guarda_con_el_monto_negativo()
    {
        $sale = $this->crear_venta_por_endpoint(
            $this->payload_venta(self::FORZADO, self::BRUTO, ['forzar_total_monto' => self::MONTO])
        );

        $fila = Sale::find($sale->id);

        $this->assertEqualsWithDelta(
            self::FORZADO,
            (float) $fila->total,
            self::DELTA,
            'sales.total tiene que ser el total forzado (4.000), que es lo que el cliente pago'
        );

        $this->assertEqualsWithDelta(
            self::BRUTO,
            (float) $fila->sub_total,
            self::DELTA,
            'sales.sub_total tiene que ser el total original (4.012): Lucas pidio que el bruto quede ahi y en ningun otro lado'
        );

        $this->assertEqualsWithDelta(
            self::MONTO,
            (float) $fila->forzar_total_monto,
            self::DELTA,
            'sales.forzar_total_monto tiene que guardar los -12, que es lo que explica la diferencia'
        );
    }

    /**
     * Test 2 — hacia arriba. El vendedor redondea para el otro lado: la venta da 4.012 y cobra
     * 4.020. Es un RECARGO y el monto se guarda positivo.
     *
     * Importa que este caso exista: el campo `sales.descuento` que usaba la extension vieja
     * representaba el recargo con un porcentaje NEGATIVO, que es una convencion al reves de la de
     * esta columna y una fuente garantizada de confusion.
     *
     * @group forzar_total
     * @test
     */
    public function el_total_forzado_hacia_arriba_se_guarda_con_el_monto_positivo()
    {
        $forzado = 4020.00;
        $monto = 8.00;

        $sale = $this->crear_venta_por_endpoint(
            $this->payload_venta($forzado, self::BRUTO, ['forzar_total_monto' => $monto])
        );

        $fila = Sale::find($sale->id);

        $this->assertEqualsWithDelta($forzado, (float) $fila->total, self::DELTA, 'el total tiene que ser el forzado hacia arriba');
        $this->assertEqualsWithDelta(self::BRUTO, (float) $fila->sub_total, self::DELTA, 'el sub_total sigue siendo el bruto');
        $this->assertEqualsWithDelta($monto, (float) $fila->forzar_total_monto, self::DELTA, 'un recargo se guarda POSITIVO');
    }

    /**
     * Test 3 — el cero se guarda como null.
     *
     * Un monto de 0 seria "se forzo el total y dio justo lo mismo", que es indistinguible de no
     * haber forzado nada pero obligaria a TODOS los lectores a preguntar por las dos cosas: el
     * renglon del comprobante, la caja de totales del PDF y el prorrateo de AFIP pasarian a
     * mostrar y a calcular un ajuste de $0. Ver `SaleHelper::normalized_forzar_total_monto()`.
     *
     * @group forzar_total
     * @test
     */
    public function un_monto_en_cero_se_guarda_como_null()
    {
        $sale = $this->crear_venta_por_endpoint(
            $this->payload_venta(3500.00, 3500.00, ['forzar_total_monto' => 0])
        );

        $this->assertNull(
            Sale::find($sale->id)->forzar_total_monto,
            'un monto de 0 tiene que quedar en null: es "no se forzo nada", no "se forzo y dio cero"'
        );
    }

    /**
     * Test 4 — NO REGRESION. Una venta que no manda el campo se guarda exactamente como antes: la
     * columna queda en null y el total es el que mando el front.
     *
     * @group forzar_total
     * @test
     */
    public function una_venta_sin_forzar_no_cambia_en_nada()
    {
        $total = 3300.00;

        $sale = $this->crear_venta_por_endpoint($this->payload_venta($total, $total));

        $fila = Sale::find($sale->id);

        $this->assertNull($fila->forzar_total_monto, 'sin el campo en el payload, la columna tiene que quedar en null');
        $this->assertEqualsWithDelta($total, (float) $fila->total, self::DELTA, 'el total tiene que ser el que mando el front');
        $this->assertEqualsWithDelta($total, (float) $fila->sub_total, self::DELTA, 'el sub_total tiene que ser el que mando el front');
    }
}
