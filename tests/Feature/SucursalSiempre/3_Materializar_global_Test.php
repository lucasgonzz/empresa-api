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
     * Lo que RESTA desde una sucursal no materializa (corrección del verificador del contrato,
     * 23/10/2026): una venta desde la sucursal B sobre un artículo con global 100 sin filas sigue
     * descontando del global, sin abrirle filas. Si materializara, B quedaría en −1 y Vender frenaría
     * las ventas siguientes desde B con "No hay stock en B" (check_article_stock_en_vender). El mismo
     * artículo, con un ingreso de 10 a B (que SUMA), sí materializa: principal 100, B 10, global 110.
     *
     * @test
     */
    public function una_venta_que_resta_no_materializa_y_un_ingreso_si()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar venta desde B', ['stock' => 100]);

        $this->crear_venta([['article' => $articulo, 'amount' => 1, 'price' => 100]], ['address_id' => $b->id]);

        $this->assertSame(0, $this->cantidad_de_depositos($articulo), 'La venta no abre filas.');
        $this->assertEquals(99.0, $this->stock($articulo), 'La venta descuenta del global, como siempre.');
        $this->assertCount(0, $this->movimientos($articulo, 'Creacion de deposito'));

        $this->postJson('api/stock-movement', [
            'model_id'      => $articulo->id,
            'amount'        => 10,
            'to_address_id' => $b->id,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertEquals(99.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(109.0, $this->stock($articulo));
    }

    /**
     * El caso del plan, con global 100 justo: ingreso de 10 a B → principal 100, B 10, global 110
     * (sin una venta antes).
     *
     * @test
     */
    public function un_ingreso_a_b_sobre_global_100_deja_principal_100_b_10()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar ingreso B', ['stock' => 100]);

        $this->postJson('api/stock-movement', [
            'model_id'      => $articulo->id,
            'amount'        => 10,
            'to_address_id' => $b->id,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertEquals(100.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(110.0, $this->stock($articulo));
    }

    /**
     * Concurrencia: la segunda llamada, con la fila ya abierta (la venta del mostrador y el pedido de
     * Tienda Nube a la vez), relee bajo candado y no vuelve a materializar.
     *
     * @test
     */
    public function la_segunda_llamada_con_la_fila_ya_abierta_no_materializa()
    {
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar dos veces', ['stock' => 30]);

        $concepto_id = $this->concepto_id('Ingreso manual');

        $data = ['model_id' => $articulo->id, 'amount' => 1, 'to_address_id' => $b->id];

        $owner = $this->usuario()->id;

        $this->assertTrue(MaterializarGlobal::materializar_si_hace_falta($data, $articulo->fresh(), $concepto_id, $owner, $owner));
        $this->assertFalse(MaterializarGlobal::materializar_si_hace_falta($data, $articulo->fresh(), $concepto_id, $owner, $owner));

        $this->assertCount(1, $this->movimientos($articulo, 'Creacion de deposito'), 'El global entró una sola vez.');
        $this->assertEquals(30.0, $this->stock($articulo));
    }

    /**
     * La materialización no pisa `articles.stock_updated_at`: el stock no cambia.
     *
     * @test
     */
    public function la_materializacion_no_pisa_stock_updated_at()
    {
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Materializar fecha', ['stock' => 30]);

        \Illuminate\Support\Facades\DB::table('articles')->where('id', $articulo->id)->update(['stock_updated_at' => '2020-01-01 00:00:00']);

        $owner = $this->usuario()->id;

        MaterializarGlobal::materializar_si_hace_falta(
            ['model_id' => $articulo->id, 'amount' => 1, 'to_address_id' => $b->id],
            $articulo->fresh(), $this->concepto_id('Ingreso manual'), $owner, $owner
        );

        $this->assertCount(1, $this->movimientos($articulo, 'Creacion de deposito'));
        $this->assertSame('2020-01-01 00:00:00', (string) \Illuminate\Support\Facades\DB::table('articles')->where('id', $articulo->id)->value('stock_updated_at'));
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
