<?php

namespace Tests\Feature\VariantesEnVenta;

use App\Http\Controllers\Helpers\sale\DeleteSaleHelper;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 4 — los caminos que escriben UN renglón de una venta ya guardada, con el mismo artículo en
 * varias variantes (misión variantes-mismo-articulo-en-vender, 8/10/2026): "Actualizar precios"
 * (`PUT sale/update-prices/{id}`, test 8 / B2), "Unidades entregadas" (`PUT
 * sale/unidades-entregadas/{id}`, test 9 / B3), y lo ya devuelto que lee la papelera al restaurar
 * una venta (B6).
 *
 * El contrato es compatible hacia atrás: la clave `article_variant_id` por ítem es OPCIONAL. Con la
 * clave se escribe solo la fila de esa variante; sin ella (la SPA vieja), lo de siempre.
 *
 * @group variantes_en_venta
 */
class Precios_entregas_y_papelera_Test extends VariantesEnVentaTestCase
{
    /**
     * Test 8 — dos variantes a precios distintos: cada fila conserva su precio y su ganancia.
     *
     * @test
     */
    public function actualizar_precios_por_variante_escribe_solo_la_fila_de_cada_variante()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
        ], ['address_id' => $e['suc1']->id]);

        $costo_m = (float) $this->fila_de($venta, $e['articulo'], $e['m'])->cost;
        $costo_l = (float) $this->fila_de($venta, $e['articulo'], $e['l'])->cost;

        $this->putJson('api/sale/update-prices/'.$venta->id, [
            'items' => [
                ['is_article' => true, 'id' => $e['articulo']->id, 'price_vender' => 350, 'article_variant_id' => $e['m']->id],
                ['is_article' => true, 'id' => $e['articulo']->id, 'price_vender' => 450, 'article_variant_id' => $e['l']->id],
            ],
        ])->assertStatus(200);

        $fila_m = $this->fila_de($venta, $e['articulo'], $e['m']);
        $fila_l = $this->fila_de($venta, $e['articulo'], $e['l']);

        $this->assertEquals(350.0, (float) $fila_m->price, 'La M queda con su precio nuevo.');
        $this->assertEquals(450.0, (float) $fila_l->price, 'La L queda con el suyo, no con el de la M ni al revés.');
        $this->assertEqualsWithDelta((350 - $costo_m) * 2, (float) $fila_m->ganancia, 0.01, 'La ganancia de la M es la de SU fila: (precio − costo) × 2.');
        $this->assertEqualsWithDelta((450 - $costo_l) * 3, (float) $fila_l->ganancia, 0.01, 'La ganancia de la L es la de SU fila: (precio − costo) × 3.');
    }

    /**
     * Test 8 bis — la SPA vieja (sin la clave `article_variant_id`) sigue como siempre: el precio va
     * a todas las filas del artículo.
     *
     * @test
     */
    public function actualizar_precios_sin_la_clave_de_variante_sigue_como_siempre()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon_testigo($e, 2),
        ], ['address_id' => $e['suc1']->id]);

        $this->putJson('api/sale/update-prices/'.$venta->id, [
            'items' => [
                ['is_article' => true, 'id' => $e['testigo']->id, 'price_vender' => 130],
            ],
        ])->assertStatus(200);

        $fila = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $e['testigo']->id)->first();

        $this->assertEquals(130.0, (float) $fila->price);
        $this->assertEqualsWithDelta((130 - (float) $fila->cost) * 2, (float) $fila->ganancia, 0.01);
    }

    /**
     * Test 9 — entregar 1 de la L: la fila de la L suma 1 entregada y la de la M no se toca.
     *
     * @test
     */
    public function unidades_entregadas_por_variante_escribe_solo_la_fila_de_esa_variante()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
        ], ['address_id' => $e['suc1']->id]);

        $this->putJson('api/sale/unidades-entregadas/'.$venta->id, [
            'articles' => [
                ['id' => $e['articulo']->id, 'add_delivered_amount' => 0, 'article_variant_id' => $e['m']->id],
                ['id' => $e['articulo']->id, 'add_delivered_amount' => 1, 'article_variant_id' => $e['l']->id],
            ],
        ])->assertStatus(200);

        $this->assertEquals(1.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->delivered_amount, 'La L entregó 1.');
        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['m'])->delivered_amount, 'La M no entregó nada.');

        $this->putJson('api/sale/unidades-entregadas/'.$venta->id, [
            'articles' => [
                ['id' => $e['articulo']->id, 'add_delivered_amount' => 2, 'article_variant_id' => $e['m']->id],
            ],
        ])->assertStatus(200);

        $this->assertEquals(2.0, (float) $this->fila_de($venta, $e['articulo'], $e['m'])->delivered_amount, 'La M entregó 2.');
        $this->assertEquals(1.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->delivered_amount, 'La L sigue con 1: no se le suma lo de la M.');
    }

    /**
     * B6 — lo ya devuelto por NC de un renglón SIN variante no cuenta las devoluciones de las filas
     * CON variante del mismo artículo (lo usa la papelera al restaurar una venta).
     *
     * @test
     */
    public function lo_ya_devuelto_de_un_renglon_sin_variante_no_suma_las_variantes()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            ['article' => $e['articulo'], 'amount' => 1, 'price' => self::PRECIO],
        ], ['address_id' => $e['suc1']->id]);

        $this->devolver($venta, [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['m']), 2)], $e['suc1']->id);

        $sin_variante = (object) [
            'id'    => $e['articulo']->id,
            'pivot' => (object) ['article_variant_id' => null],
        ];

        $con_m = (object) [
            'id'    => $e['articulo']->id,
            'pivot' => (object) ['article_variant_id' => $e['m']->id],
        ];

        $this->assertEquals(0.0, (float) DeleteSaleHelper::get_unidades_ya_devueltas_en_nota_de_credito($venta, $sin_variante), 'El renglón sin variante no devolvió nada: las 2 de la M no son suyas.');
        $this->assertEquals(2.0, (float) DeleteSaleHelper::get_unidades_ya_devueltas_en_nota_de_credito($venta, $con_m), 'La M devolvió 2.');
    }
}
