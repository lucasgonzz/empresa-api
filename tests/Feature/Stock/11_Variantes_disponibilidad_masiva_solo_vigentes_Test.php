<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Helpers\article\ArticleVariantGeneratorHelper;
use App\Models\ArticleProperty;
use App\Models\ArticlePropertyType;
use App\Models\ArticlePropertyValue;
use App\Models\ArticleVariant;
use Illuminate\Support\Facades\DB;

/**
 * Disponibilidad masiva del modal de variantes (1/10/2026).
 *
 * Al agregar una propiedad nueva (ej: Talle a un artículo que solo tenía Color) las variantes de la
 * combinación vieja ("Plata", "Beige") quedan huérfanas: el back las oculta pero no las borra (pueden
 * estar en ventas) y la grilla del SPA no las muestra. "Habilitar todas" no debe reactivarlas: saldrían
 * a Vender y a la tienda sin que nadie las vea en la grilla.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Variantes_disponibilidad_masiva_solo_vigentes_Test extends AuditoriaStockTestCase
{
    /**
     * Arma el escenario del reporte: primero solo Color (Plata, Beige) y después se suma Talle (35, grande).
     *
     * @return \App\Models\Article
     */
    protected function articulo_con_huerfanas()
    {
        $articulo = $this->crear_articulo('zz Remera variantes huerfanas', ['stock' => 0]);

        $user_id = $this->usuario()->id;

        $color = ArticlePropertyType::create(['name' => 'zz Color', 'user_id' => $user_id]);
        $talle = ArticlePropertyType::create(['name' => 'zz Talle', 'user_id' => $user_id]);

        $propiedad_color = ArticleProperty::create(['article_id' => $articulo->id, 'article_property_type_id' => $color->id]);

        foreach (['Plata', 'Beige'] as $nombre) {
            $valor = ArticlePropertyValue::create(['name' => $nombre, 'article_property_type_id' => $color->id, 'user_id' => $user_id]);
            $propiedad_color->article_property_values()->attach($valor->id);
        }

        (new ArticleVariantGeneratorHelper($articulo->id))->generate();

        $propiedad_talle = ArticleProperty::create(['article_id' => $articulo->id, 'article_property_type_id' => $talle->id]);

        foreach (['35', 'grande'] as $nombre) {
            $valor = ArticlePropertyValue::create(['name' => $nombre, 'article_property_type_id' => $talle->id, 'user_id' => $user_id]);
            $propiedad_talle->article_property_values()->attach($valor->id);
        }

        (new ArticleVariantGeneratorHelper($articulo->id))->generate();

        return $articulo;
    }

    /**
     * @param int $articulo_id
     * @param string $descripcion
     * @return int 1 si la variante está oculta, 0 si está disponible.
     */
    protected function oculta($articulo_id, $descripcion)
    {
        return (int) DB::table('article_variants')
                        ->where('article_id', $articulo_id)
                        ->where('variant_description', $descripcion)
                        ->value('oculta');
    }

    /**
     * @group stock
     * @test
     */
    public function habilitar_todas_deja_disponibles_solo_las_cuatro_combinaciones_vigentes()
    {
        $articulo = $this->articulo_con_huerfanas();

        $this->assertEquals(6, ArticleVariant::where('article_id', $articulo->id)->count(), 'El escenario tiene que dejar 2 huerfanas.');

        $this->putJson('api/article/'.$articulo->id.'/variants-disponibilidad', ['accion' => 'todas'])
            ->assertStatus(200);

        $this->assertEquals(4, ArticleVariant::where('article_id', $articulo->id)->where('oculta', false)->count());

        // Las huerfanas ("Plata" y "Beige" sueltas) siguen ocultas.
        $this->assertEquals(1, $this->oculta($articulo->id, 'Plata'));
        $this->assertEquals(1, $this->oculta($articulo->id, 'Beige'));
    }

    /**
     * @group stock
     * @test
     */
    public function segun_stock_tampoco_reactiva_las_huerfanas()
    {
        $articulo = $this->articulo_con_huerfanas();

        // Una huerfana con stock: antes quedaba habilitada por "segun stock".
        DB::table('article_variants')
            ->where('article_id', $articulo->id)
            ->where('variant_description', 'Plata')
            ->update(['stock' => 10, 'oculta' => false]);

        DB::table('article_variants')
            ->where('article_id', $articulo->id)
            ->where('variant_description', 'Plata 35')
            ->update(['stock' => 3]);

        $this->putJson('api/article/'.$articulo->id.'/variants-disponibilidad', ['accion' => 'con_stock'])
            ->assertStatus(200);

        $this->assertEquals(0, $this->oculta($articulo->id, 'Plata 35'));
        $this->assertEquals(1, $this->oculta($articulo->id, 'Plata'), 'La huerfana con stock no debe quedar disponible.');
        $this->assertEquals(1, $this->oculta($articulo->id, 'Beige 35'));
    }
}
