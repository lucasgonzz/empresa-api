<?php

namespace Tests\Feature\SucursalSiempre;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\import\article\motor\StockEnLote;
use App\Models\Address;
use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * D15 de la misión sucursal-siempre: con UNA sola sucursal, los caminos que escriben "el stock
 * global" de un artículo que reparte lo escriben en esa sucursal (stock final → diferencia contra la
 * fila). Con dos o más sucursales, como siempre: se ignora.
 *
 * El fixture deja al dueño 500 con una sola sucursal ("Principal").
 */
class Una_sola_sucursal_stock_global_Test extends SucursalSiempreTestCase
{
    /**
     * @return \App\Models\Address
     */
    protected function unica()
    {
        $unica = Address::unica_sucursal_de($this->usuario()->id);

        $this->assertNotNull($unica, 'El fixture tiene que dejar al dueño con UNA sola sucursal.');

        return $unica;
    }

    /**
     * @param  \App\Models\Article  $articulo
     * @param  float                $valor
     * @return void
     */
    protected function masiva_de_stock($articulo, $valor)
    {
        $user = $this->usuario();

        $criteria = [
            'from_filter'        => false,
            'used_filters'       => [],
            'update_form'        => [
                ['type' => 'number', 'key' => 'set_stock', 'value' => $valor],
            ],
            'models_id'          => [$articulo->id],
            'resolved_models_id' => [$articulo->id],
            'filter_form'        => [],
        ];

        $masiva = MasiveUpdateHelper::create_pending_update($user->id, $user->id, 'article', false, $criteria);

        MasiveUpdateHelper::process_update($masiva);
    }

    /**
     * @test
     */
    public function la_masiva_con_una_sola_sucursal_escribe_en_ella()
    {
        $unica = $this->unica();

        $articulo = $this->crear_articulo('zz D15 masiva una');
        $this->con_fila($articulo, $unica->id, 4);

        $this->masiva_de_stock($articulo, 25);

        $this->assertEquals(25.0, $this->stock_en_deposito($articulo, $unica->id));
        $this->assertEquals(25.0, $this->stock($articulo));
    }

    /**
     * @test
     */
    public function la_masiva_con_dos_sucursales_sigue_salteando()
    {
        $unica = $this->unica();
        $b = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz D15 masiva dos');
        $this->con_fila($articulo, $unica->id, 4);
        $this->con_fila($articulo, $b->id, 1);

        $this->masiva_de_stock($articulo, 25);

        $this->assertEquals(4.0, $this->stock_en_deposito($articulo, $unica->id));
        $this->assertEquals(5.0, $this->stock($articulo), 'Con dos sucursales un número global no dice a cuál va: se saltea.');
    }

    /**
     * La columna de stock global del Excel (StockEnLote, el motor de la importación) con objetivo.
     *
     * @test
     */
    public function el_excel_con_stock_global_y_una_sola_sucursal_va_a_ella()
    {
        $unica = $this->unica();
        $user = $this->usuario();

        $articulo = $this->crear_articulo('zz D15 excel una');
        $this->con_fila($articulo, $unica->id, 6);

        $lote = new StockEnLote($user, $user->id);
        $lote->agregar_global(Article::find($articulo->id), 14, 20);
        $lote->volcar();

        $this->assertEquals(20.0, $this->stock_en_deposito($articulo, $unica->id));
        $this->assertEquals(20.0, $this->stock($articulo));

        $movimiento = $this->movimientos($articulo)->last();
        $this->assertEquals(14.0, (float) $movimiento->amount);
        $this->assertEquals($unica->id, $movimiento->to_address_id);
    }

    /**
     * @test
     */
    public function el_excel_con_stock_global_y_dos_sucursales_queda_como_hoy()
    {
        $unica = $this->unica();
        $b = $this->otra_sucursal();
        $user = $this->usuario();

        $articulo = $this->crear_articulo('zz D15 excel dos');
        $this->con_fila($articulo, $unica->id, 6);
        $this->con_fila($articulo, $b->id, 2);

        $lote = new StockEnLote($user, $user->id);
        $lote->agregar_global(Article::find($articulo->id), 12, 20);
        $lote->volcar();

        $this->assertEquals(6.0, $this->stock_en_deposito($articulo, $unica->id));
        $this->assertEquals(8.0, $this->stock($articulo), 'Con dos sucursales el global de un artículo que reparte es la suma: no cambia.');
    }

    /**
     * El stock de una variante que reparte, mandado como total (la SPA con una sola sucursal no
     * muestra sucursales): `PUT api/article-update-varians-stock`.
     *
     * @test
     */
    public function el_stock_de_una_variante_con_una_sola_sucursal_va_a_ella()
    {
        $unica = $this->unica();

        $articulo = $this->crear_articulo('zz D15 variante una');
        $variante = $this->variante($articulo, 0);
        $variante->addresses()->attach($unica->id, ['amount' => 3]);
        DB::table('article_variants')->where('id', $variante->id)->update(['stock' => 3]);

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [['id' => $variante->id, 'stock' => 9]],
        ])->assertStatus(200);

        $this->assertEquals(9.0, $this->stock_de_variante_en($variante, $unica->id));
        $this->assertEquals(9.0, $this->stock_de_variante($variante));
        $this->assertEquals(9.0, $this->stock($articulo));
    }

    /**
     * @test
     */
    public function el_stock_de_una_variante_con_dos_sucursales_se_ignora_como_hoy()
    {
        $unica = $this->unica();
        $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz D15 variante dos');
        $variante = $this->variante($articulo, 0);
        $variante->addresses()->attach($unica->id, ['amount' => 3]);
        DB::table('article_variants')->where('id', $variante->id)->update(['stock' => 3]);

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [['id' => $variante->id, 'stock' => 9]],
        ])->assertStatus(200);

        $this->assertEquals(3.0, $this->stock_de_variante_en($variante, $unica->id));
    }
}
