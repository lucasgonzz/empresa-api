<?php

namespace Tests\Feature\SucursalSiempre;

/**
 * D4 de la misión sucursal-siempre, corrección del 23/10/2026: los TRASLADOS entre depósitos ("Mov
 * entre depositos", el de los movimientos de depósito, y "Mov manual entre depositos", el del modal)
 * sobre un artículo o una variante sin filas y con stock global ≠ 0 primero pasan el global entero a
 * la principal y recién después trasladan. Antes el destino nacía con lo trasladado y se perdía el
 * resto del global.
 */
class Traslados_materializan_el_global_Test extends SucursalSiempreTestCase
{
    /**
     * @param  \App\Models\Article  $articulo
     * @param  int                  $desde
     * @param  int                  $hacia
     * @param  float                $cantidad
     * @param  string               $concepto
     * @param  int|null             $variante_id
     * @return void
     */
    protected function trasladar($articulo, $desde, $hacia, $cantidad, $concepto = 'Mov manual entre depositos', $variante_id = null)
    {
        $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo->id,
            'article_variant_id'           => $variante_id,
            'amount'                       => $cantidad,
            'from_address_id'              => $desde,
            'to_address_id'                => $hacia,
            'concepto_stock_movement_name' => $concepto,
        ])->assertStatus(201);
    }

    /**
     * Global 100 sin filas, traslado principal → B de 5: principal 95, B 5, global 100.
     *
     * @test
     */
    public function un_traslado_desde_la_principal_no_pierde_el_resto()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal('zz Traslado B');

        $articulo = $this->crear_articulo('zz Traslado desde principal', ['stock' => 100]);

        $this->trasladar($articulo, $principal->id, $b->id, 5);

        $this->assertEquals(95.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(5.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(100.0, $this->stock($articulo), 'Un traslado no cambia el global.');

        $this->assertCount(1, $this->movimientos($articulo, 'Creacion de deposito'), 'Primero se materializó el global.');
    }

    /**
     * Global 100 sin filas, traslado B → C de 5: principal 100, B −5, C 5, global 100.
     *
     * @test
     */
    public function un_traslado_entre_otras_dos_sucursales_deja_el_origen_en_negativo()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal('zz Traslado B');
        $c = $this->otra_sucursal('zz Traslado C');

        $articulo = $this->crear_articulo('zz Traslado entre otras', ['stock' => 100]);

        $this->trasladar($articulo, $b->id, $c->id, 5, 'Mov entre depositos');

        $this->assertEquals(100.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(-5.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(5.0, $this->stock_en_deposito($articulo, $c->id));
        $this->assertEquals(100.0, $this->stock($articulo));
    }

    /**
     * Lo mismo con una variante (el traslado de variantes pasa por el motor con su article_variant_id,
     * igual que DepositMovementHelper::crear_stock_movement()).
     *
     * @test
     */
    public function con_una_variante_tambien()
    {
        $principal = $this->principal();
        $b = $this->otra_sucursal('zz Traslado variante B');
        $c = $this->otra_sucursal('zz Traslado variante C');

        $articulo = $this->crear_articulo('zz Traslado variante', ['stock' => 100]);
        $variante = $this->variante($articulo, 100);

        $this->trasladar($articulo, $principal->id, $b->id, 5, 'Mov entre depositos', $variante->id);

        $this->assertEquals(95.0, $this->stock_de_variante_en($variante, $principal->id));
        $this->assertEquals(5.0, $this->stock_de_variante_en($variante, $b->id));
        $this->assertEquals(100.0, $this->stock_de_variante($variante));
        $this->assertEquals(100.0, $this->stock($articulo));

        $otro = $this->crear_articulo('zz Traslado variante entre otras', ['stock' => 100]);
        $otra_variante = $this->variante($otro, 100);

        $this->trasladar($otro, $b->id, $c->id, 5, 'Mov manual entre depositos', $otra_variante->id);

        $this->assertEquals(100.0, $this->stock_de_variante_en($otra_variante, $principal->id));
        $this->assertEquals(-5.0, $this->stock_de_variante_en($otra_variante, $b->id));
        $this->assertEquals(5.0, $this->stock_de_variante_en($otra_variante, $c->id));
        $this->assertEquals(100.0, $this->stock_de_variante($otra_variante));
        $this->assertEquals(100.0, $this->stock($otro));
    }
}
