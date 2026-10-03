<?php

namespace Tests\Feature\Stock;

use App\Models\ArticleVariant;
use Illuminate\Support\Facades\DB;

/**
 * Habilitar / ocultar una variante desde la grilla del modal de variantes (30/9/2026).
 *
 * Las variantes nacen ocultas (ArticleVariantGeneratorHelper) y el operador las va habilitando una por
 * una. La SPA reemplaza la variante del store con la respuesta de `PUT article-variant/{id}`: si esa
 * respuesta viene sin `addresses`, la fila de la grilla revienta al renderizar ("Cannot read properties
 * of undefined (reading 'find')") y el operador cree que no se guardó.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Variantes_habilitar_devuelve_relaciones_Test extends AuditoriaStockTestCase
{
    /**
     * @group stock
     * @test
     */
    public function habilitar_una_variante_devuelve_sus_depositos_y_propiedades()
    {
        $articulo = $this->crear_articulo('Remera variantes habilitar', ['stock' => 0]);

        $variante = ArticleVariant::create([
            'article_id'          => $articulo->id,
            'variant_description' => 'Rojo S',
            'oculta'              => true,
        ]);

        $respuesta = $this->putJson('api/article-variant/'.$variante->id, [
            'price'     => null,
            'image_url' => null,
            'oculta'    => false,
        ]);

        $respuesta->assertStatus(200);

        $model = $respuesta->json('model');

        $this->assertEquals($variante->id, $model['id']);
        $this->assertArrayHasKey('addresses', $model, 'La respuesta no trae addresses: la fila de la grilla revienta al renderizar.');
        $this->assertIsArray($model['addresses']);
        $this->assertArrayHasKey('article_property_values', $model);

        $this->assertEquals(0, DB::table('article_variants')->where('id', $variante->id)->value('oculta'));
    }

    /**
     * @group stock
     * @test
     */
    public function ocultar_una_variante_la_deja_oculta_y_sigue_devolviendo_las_relaciones()
    {
        $articulo = $this->crear_articulo('Remera variantes ocultar', ['stock' => 0]);

        $variante = ArticleVariant::create([
            'article_id'          => $articulo->id,
            'variant_description' => 'Azul M',
            'oculta'              => false,
        ]);

        $respuesta = $this->putJson('api/article-variant/'.$variante->id, [
            'price'     => null,
            'image_url' => null,
            'oculta'    => true,
        ]);

        $respuesta->assertStatus(200);

        $this->assertArrayHasKey('addresses', $respuesta->json('model'));
        $this->assertEquals(1, DB::table('article_variants')->where('id', $variante->id)->value('oculta'));
    }
}
