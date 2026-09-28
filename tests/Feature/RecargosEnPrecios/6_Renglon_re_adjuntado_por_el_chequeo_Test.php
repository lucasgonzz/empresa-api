<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

/**
 * Archivo 6 — `SaleHelper::check_deleted_articles_from_check()` re-adjunta el renglon CON su base.
 *
 * Ese helper vuelve a pegar en una venta chequeada un articulo que ya no esta, con el mismo precio
 * que tenia. Si el precio traia el recargo adentro, lo sigue trayendo: la base tiene que viajar con
 * el, o la venta —con la opcion prendida— queda bloqueada en VENDER por un renglon que nadie toco.
 *
 * ⚠️ Se llama al helper directo y no por un endpoint porque HOY NINGUN CAMINO LO LLAMA (medido el
 * 28/9/2026: `grep -rn check_deleted_articles_from_check app/` solo encuentra la definicion). Se
 * cubre igual porque sigue siendo codigo publico que escribe renglones: si alguien lo vuelve a
 * enganchar, no puede volver a perder la base.
 *
 * @group recargos_en_precios
 */
class Renglon_re_adjuntado_por_el_chequeo_Test extends RecargosEnPreciosTestCase
{
    /**
     * @test
     */
    public function el_renglon_re_adjuntado_conserva_precio_y_base()
    {
        $articulo = $this->articulo_centinela();
        $recargo  = $this->recargo();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 110, 100, 3),
        ], 330.00, 1, [$this->recargo_del_payload($recargo)]));

        /* La foto de antes, con el pivot entero (base incluida), como la toma update(). */
        $previus_articles = $sale->articles()->get();

        $this->assertCount(1, $previus_articles, 'Control: la venta tiene que tener su renglon.');

        /* El renglon "desaparece" de la venta chequeada. */
        $sale->articles()->detach();

        $sale->checked = 1;
        $sale->save();

        SaleHelper::check_deleted_articles_from_check(Sale::find($sale->id), $previus_articles);

        $this->assert_precio_y_base(
            $this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id),
            110,
            100,
            'articulo re-adjuntado por el chequeo'
        );
    }
}
