<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

/**
 * Archivo 6 — `SaleHelper::check_deleted_articles_from_check()` re-adjunta el renglon CON sus bases.
 *
 * Ese helper vuelve a pegar en una venta chequeada un articulo que ya no esta, con el mismo precio
 * que tenia. Si el precio traia el descuento adentro, lo sigue trayendo: la base de descuentos tiene
 * que viajar con el, o el renglon volveria sin su precio original.
 *
 * ⚠️ Se llama al helper directo y no por un endpoint porque HOY NINGUN CAMINO LO LLAMA (medido el
 * 8/10/2026: `git grep check_deleted_articles_from_check -- app` solo encuentra la definicion). Se
 * cubre igual porque sigue siendo codigo publico que escribe renglones: si alguien lo vuelve a
 * enganchar, no puede volver a perder la base.
 *
 * @group descuentos_en_precios
 */
class Renglon_re_adjuntado_por_el_chequeo_Test extends DescuentosEnPreciosTestCase
{
    /**
     * @test
     */
    public function el_renglon_re_adjuntado_conserva_precio_y_las_dos_bases()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        /* Descuento Y recargo adentro a la vez: original 100, sin recargos 90, final 99. */
        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 99, 100, 3, 90),
        ], 297.00, 1, [$this->descuento_del_payload($descuento)], [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => [$this->recargo_del_payload($this->recargo())],
        ]));

        /* La foto de antes, con el pivot entero (bases incluidas), como la toma update(). */
        $previus_articles = $sale->articles()->get();

        $this->assertCount(1, $previus_articles, 'Control: la venta tiene que tener su renglon.');

        /* El renglon "desaparece" de la venta chequeada. */
        $sale->articles()->detach();

        $sale->checked = 1;
        $sale->save();

        SaleHelper::check_deleted_articles_from_check(Sale::find($sale->id), $previus_articles);

        $this->assert_precio_y_base(
            $this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id),
            99,
            100,
            'articulo re-adjuntado por el chequeo',
            90
        );
    }
}
