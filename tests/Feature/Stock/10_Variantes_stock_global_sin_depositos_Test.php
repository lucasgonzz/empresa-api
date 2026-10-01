<?php

namespace Tests\Feature\Stock;

use App\Models\ArticleVariant;
use Illuminate\Support\Facades\DB;

/**
 * Stock de una variante desde la grilla del modal de variantes (1/10/2026).
 *
 * Un negocio SIN sucursales no tiene depositos, asi que la grilla manda el stock GLOBAL de cada
 * variante (`stock`) en vez de la lista de depositos. UpdateVariantsStockHelper tiene que llevar
 * `article_variants.stock` a ese valor generando un movimiento por la diferencia (nunca escribiendo
 * la columna a mano), y tiene que seguir andando igual que siempre cuando la variante manda depositos.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Variantes_stock_global_sin_depositos_Test extends AuditoriaStockTestCase
{
    /**
     * Crea un articulo con una variante habilitada y sin depositos.
     *
     * @return array [articulo, variante]
     */
    protected function articulo_con_variante()
    {
        $articulo = $this->crear_articulo('zz Remera variantes stock global', ['stock' => 0]);

        $variante = ArticleVariant::create([
            'article_id'          => $articulo->id,
            'variant_description' => 'Rojo S',
            'oculta'              => false,
        ]);

        return [$articulo, $variante];
    }

    /**
     * @param int $variante_id
     * @return float|null
     */
    protected function stock_de_variante($variante_id)
    {
        $stock = DB::table('article_variants')->where('id', $variante_id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * @group stock
     * @test
     */
    public function el_stock_global_de_una_variante_sin_depositos_se_guarda_con_un_movimiento()
    {
        list($articulo, $variante) = $this->articulo_con_variante();

        $respuesta = $this->putJson('api/article-update-varians-stock', [
            'article_id'        => $articulo->id,
            'variants_to_update' => [
                ['id' => $variante->id, 'stock' => 7],
            ],
        ]);

        $respuesta->assertStatus(200);

        $this->assertEquals(7, $this->stock_de_variante($variante->id));

        $movimientos = $this->movimientos($articulo);

        $this->assertCount(1, $movimientos);
        $this->assertEquals($variante->id, $movimientos[0]->article_variant_id);
        $this->assertEquals(7, (float) $movimientos[0]->amount);
        $this->assertNull($movimientos[0]->to_address_id, 'El stock global no lleva deposito.');
    }

    /**
     * @group stock
     * @test
     */
    public function bajar_el_stock_global_genera_un_movimiento_negativo_por_la_diferencia()
    {
        list($articulo, $variante) = $this->articulo_con_variante();

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [['id' => $variante->id, 'stock' => 7]],
        ])->assertStatus(200);

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [['id' => $variante->id, 'stock' => 4]],
        ])->assertStatus(200);

        $this->assertEquals(4, $this->stock_de_variante($variante->id));

        $movimientos = $this->movimientos($articulo);

        $this->assertCount(2, $movimientos);
        $this->assertEquals(-3, (float) $movimientos[1]->amount);
    }

    /**
     * @group stock
     * @test
     */
    public function mandar_el_mismo_stock_global_no_genera_movimientos()
    {
        list($articulo, $variante) = $this->articulo_con_variante();

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [['id' => $variante->id, 'stock' => 5]],
        ])->assertStatus(200);

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [['id' => $variante->id, 'stock' => 5]],
        ])->assertStatus(200);

        $this->assertCount(1, $this->movimientos($articulo));
        $this->assertEquals(5, $this->stock_de_variante($variante->id));
    }

    /**
     * @group stock
     * @test
     */
    public function una_variante_de_otro_articulo_no_se_toca()
    {
        list($articulo, $variante) = $this->articulo_con_variante();

        $otro_articulo = $this->crear_articulo('zz Otro articulo variantes', ['stock' => 0]);

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $otro_articulo->id,
            'variants_to_update' => [['id' => $variante->id, 'stock' => 9]],
        ])->assertStatus(200);

        $this->assertNotEquals(9, $this->stock_de_variante($variante->id));
        $this->assertCount(0, $this->movimientos($otro_articulo));
    }

    /**
     * El camino de siempre (stock por deposito) no cambia: el campo `stock` es opcional.
     *
     * @group stock
     * @test
     */
    public function la_variante_con_depositos_sigue_guardando_el_stock_por_deposito()
    {
        list($articulo, $variante) = $this->articulo_con_variante();

        $sucursal = $this->sucursal();

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [
                [
                    'id'        => $variante->id,
                    'addresses' => [
                        ['id' => $sucursal->id, 'amount' => 6, 'on_display' => 0],
                    ],
                ],
            ],
        ])->assertStatus(200);

        $this->assertEquals(6, (float) DB::table('address_article_variant')
                                            ->where('article_variant_id', $variante->id)
                                            ->where('address_id', $sucursal->id)
                                            ->value('amount'));

        $this->assertEquals(6, $this->stock_de_variante($variante->id));
    }

    /**
     * Si la variante ya reparte por depositos, un `stock` global se ignora: el stock de una
     * variante con depositos es la suma de ellos.
     *
     * @group stock
     * @test
     */
    public function el_stock_global_se_ignora_si_la_variante_ya_reparte_por_depositos()
    {
        list($articulo, $variante) = $this->articulo_con_variante();

        $sucursal = $this->sucursal();

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [
                [
                    'id'        => $variante->id,
                    'addresses' => [['id' => $sucursal->id, 'amount' => 3, 'on_display' => 0]],
                ],
            ],
        ])->assertStatus(200);

        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [['id' => $variante->id, 'stock' => 50]],
        ])->assertStatus(200);

        $this->assertEquals(3, $this->stock_de_variante($variante->id));
    }
}
