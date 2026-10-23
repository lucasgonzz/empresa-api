<?php

namespace Tests\Feature\SucursalSiempre;

use App\Http\Controllers\Stock\SetArticleStock\MaterializarGlobal;

/**
 * D4 de la misión sucursal-siempre: un artículo sin filas y con stock global ≠ 0 que recibe un
 * movimiento CON sucursal (con un concepto que no reparte) primero pasa su global entero a la
 * principal ("Creacion de deposito") y recién después se aplica el movimiento. Los conceptos de
 * reparto (el modal de crear depósitos) no materializan: sus porciones ya suman el total.
 */
class Materializar_global_Test extends SucursalSiempreTestCase
{
    /**
     * El ejemplo del plan: global 100 sin filas + "Ingreso manual" de 10 a la sucursal B →
     * principal 100, B 10, global 110.
     *
     * @test
     */
    public function un_ingreso_a_otra_sucursal_deja_el_global_en_la_principal()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar ingreso', ['stock' => 100]);

        $this->postJson('api/stock-movement', [
            'model_id'      => $articulo->id,
            'amount'        => 10,
            'to_address_id' => $b->id,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertEquals(100.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(110.0, $this->stock($articulo));

        $movimientos = $this->movimientos($articulo);
        $this->assertCount(2, $movimientos, 'Dos renglones: la materialización y el ingreso.');
        $this->assertEquals($this->concepto_id('Creacion de deposito'), $movimientos[0]->concepto_stock_movement_id);
        $this->assertEquals(100.0, (float) $movimientos[0]->amount);
        $this->assertEquals($principal->id, $movimientos[0]->to_address_id);
        $this->assertStringStartsWith(MaterializarGlobal::OBSERVACION, (string) $movimientos[0]->observations);
        $this->assertEquals(110.0, (float) $movimientos[1]->stock_resultante);
    }

    /**
     * Una venta desde Vender con la sucursal elegida, sobre un artículo que todavía lleva global.
     *
     * @test
     */
    public function una_venta_con_sucursal_materializa_y_descuenta_de_la_sucursal()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Materializar venta', ['stock' => 20]);

        $this->crear_venta([['article' => $articulo, 'amount' => 3, 'price' => 100]]);

        $this->assertEquals(17.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(17.0, $this->stock($articulo));
    }

    /**
     * Global negativo: se materializa igual (la fila nace en negativo, el global no cambia).
     *
     * @test
     */
    public function con_global_negativo_tambien()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar negativo', ['stock' => -5]);

        $this->postJson('api/stock-movement', [
            'model_id'      => $articulo->id,
            'amount'        => 8,
            'to_address_id' => $b->id,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertEquals(-5.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(8.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(3.0, $this->stock($articulo));
    }

    /**
     * Global null (no lleva stock): no hay nada que materializar.
     *
     * @test
     */
    public function con_global_null_no_materializa()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar null', ['stock' => null]);

        $this->postJson('api/stock-movement', [
            'model_id'      => $articulo->id,
            'amount'        => 4,
            'to_address_id' => $b->id,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertNull($this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(4.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(4.0, $this->stock($articulo));
        $this->assertCount(1, $this->movimientos($articulo));
    }

    /**
     * Variante sin filas con stock: se materializa la VARIANTE en la principal.
     *
     * @test
     */
    public function una_variante_sin_filas_se_materializa_por_variante()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar variante', ['stock' => 7]);
        $variante = $this->variante($articulo, 7);

        $this->postJson('api/stock-movement', [
            'model_id'           => $articulo->id,
            'article_variant_id' => $variante->id,
            'amount'             => 3,
            'to_address_id'      => $b->id,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertEquals(7.0, $this->stock_de_variante_en($variante, $principal->id));
        $this->assertEquals(3.0, $this->stock_de_variante_en($variante, $b->id));
        $this->assertEquals(10.0, $this->stock_de_variante($variante));
        $this->assertEquals(10.0, $this->stock($articulo));
    }

    /**
     * El reparto del modal de crear depósitos ("Creacion de deposito" en porciones que suman el total)
     * NO materializa: si lo hiciera, el stock quedaría duplicado.
     *
     * @test
     */
    public function el_reparto_en_porciones_no_duplica()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar reparto', ['stock' => 100]);

        foreach ([[$principal->id, 60], [$b->id, 40]] as $porcion) {
            $this->postJson('api/stock-movement', [
                'model_id'      => $articulo->id,
                'amount'        => $porcion[1],
                'to_address_id' => $porcion[0],
                'concepto'      => 'Creacion de deposito',
            ])->assertStatus(201);
        }

        $this->assertEquals(60.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(40.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(100.0, $this->stock($articulo), 'Las porciones suman el total, sin duplicar.');
        $this->assertCount(2, $this->movimientos($articulo));
    }
}
