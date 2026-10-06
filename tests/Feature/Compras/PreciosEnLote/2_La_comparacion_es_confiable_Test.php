<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use Carbon\Carbon;

/**
 * La comparación de ComprasPreciosEnLoteTestCase vale algo solo si la foto es DETERMINÍSTICA: la
 * misma compra, confirmada dos veces por el mismo camino sobre la misma base, tiene que dejar
 * exactamente la misma foto. Si no la deja (un id que se coló, una hora que no se congeló, un
 * valor al azar), cualquier diferencia entre el camino de hoy y el nuevo podría ser ruido, y los
 * tests de equivalencia no probarían nada.
 *
 * La compra es la más completa que arman estos tests: bonificaciones del proveedor que se
 * materializan, un flete tipado que se prorratea, movimiento de stock, cuenta corriente, factura
 * automática, y un artículo nuevo además de los del catálogo del fixture.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class La_comparacion_es_confiable_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * Dos corridas del camino de HOY (interruptor prendido las dos) dejan la misma foto.
     *
     * @group compras
     * @test
     */
    public function dos_corridas_del_camino_de_hoy_dejan_la_misma_foto()
    {
        $this->assert_dos_corridas_iguales(true);
    }

    /**
     * Y lo mismo con el camino NUEVO: si el recálculo diferido no fuera determinístico, la
     * equivalencia daría verde o rojo según la corrida.
     *
     * @group compras
     * @test
     */
    public function dos_corridas_del_camino_nuevo_dejan_la_misma_foto()
    {
        $this->assert_dos_corridas_iguales(false);
    }

    /**
     * Corre la compra dos veces por el mismo camino y exige fotos idénticas, más las guardas de que
     * la foto no está vacía (si lo estuviera, "iguales" no diría nada).
     *
     * @param  bool $por_articulo
     * @return void
     */
    protected function assert_dos_corridas_iguales($por_articulo)
    {
        $compra = $this->compra_completa();

        Carbon::setTestNow(self::AHORA);

        try {
            $marcas  = $this->marcas();
            $primera = $this->correr_camino($por_articulo, $compra, $marcas);
            $segunda = $this->correr_camino($por_articulo, $compra, $marcas);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($primera['articulos'], $segunda['articulos']);

        $this->assertNotSame($primera['compra_id'], $segunda['compra_id'], 'Guarda: las dos corridas tenían que crear compras con ids distintos (el rollback no reusa autoincrementales), que es justo lo que la foto tiene que absorber.');

        $this->assert_identico($primera['foto'], $segunda['foto'], 'Dos corridas del mismo camino no dejaron la misma foto: la comparación de los tests de equivalencia no es confiable.');

        $foto = $primera['foto'];

        $this->assertCount(3, $foto['articles'], 'La foto tiene que traer los tres artículos de la compra.');
        $this->assertNotEmpty($foto['cambios'], 'La compra tenía que cambiar precios.');
        $this->assertNotEmpty($foto['stock_movements'], 'La compra tenía que mover stock.');
        $this->assertNotEmpty($foto['current_acounts'], 'La compra tenía que generar el movimiento de la cuenta corriente.');
        $this->assertNotEmpty($foto['provider_order_afip_tickets'], 'La compra tenía que generar la factura automática.');
        $this->assertNotEmpty($foto['article_discounts'], 'La compra tenía que materializar las bonificaciones del proveedor.');
        $this->assertNotEmpty($foto['article_surchages'], 'La compra tenía que materializar el flete como recargo.');
        $this->assertNotEmpty($foto['movimientos_con_proveedor'], 'La compra tenía que dejar movimientos de stock con proveedor.');
        $this->assertSame(self::LA_COMPRA, $foto['stock_movements'][0]['provider_order_id'], 'El id de la compra tenía que quedar reemplazado por la marca.');
    }

    /**
     * Bonificaciones de Buenos Aires (10% y 5%, precargadas), un flete tipado y tres renglones con
     * costo nuevo: dos artículos del catálogo y uno creado para el test.
     *
     * @return callable
     */
    protected function compra_completa()
    {
        $pinza   = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $nuevo   = $this->crear_articulo(['cost' => 750]);

        return function () use ($pinza, $alicate, $nuevo) {

            $flete = $this->costo_extra_del_alta(['value' => 1300]);

            $compra_id = $this->alta($this->payload_compra([
                'childrens' => [$flete],
                'articles'  => [
                    $this->renglon($pinza, 1100, 10),
                    $this->renglon($alicate, 320, 5),
                    $this->renglon($nuevo, 800, 3),
                ],
            ]));

            return [
                'articulos' => [$pinza->id, $alicate->id, $nuevo->id],
                'compra_id' => $compra_id,
            ];
        };
    }
}
