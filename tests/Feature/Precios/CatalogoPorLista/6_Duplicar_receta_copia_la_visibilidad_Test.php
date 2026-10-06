<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use App\Models\Article;
use App\Models\PriceType;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProduccionV2\ProduccionV2TestCase;

/**
 * Duplicar una receta (`POST api/recipe/{id}/duplicar`) copia también en qué listas restringidas
 * se ve el artículo en la tienda (misión catalogo-por-lista-tienda, 5/10/2026): duplicar una
 * receta es copiar su configuración, y DuplicarRecetaHelper::copiar_price_types() copia el pivote
 * columna por columna.
 *
 * Reusa el armado de ProduccionV2 (el comercio sembrado por TestingFerreteriaSeeder) en vez del de
 * CatalogoPorListaTestCase: la receta y el endpoint son de ese módulo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class Duplicar_receta_copia_la_visibilidad_Test extends ProduccionV2TestCase
{
    /**
     * @return void
     */
    public function test_duplicar_la_receta_copia_visible_en_tienda_de_cada_lista()
    {
        $comercio = $this->comercio();

        $mayorista = PriceType::create([
            'name'                           => 'zz Mayorista duplicar',
            'percentage'                     => 20,
            'position'                       => 90,
            'catalogo_restringido_en_tienda' => 1,
            'user_id'                        => $comercio->id,
        ]);

        $minorista = PriceType::create([
            'name'       => 'zz Minorista duplicar',
            'percentage' => 30,
            'position'   => 91,
            'user_id'    => $comercio->id,
        ]);

        $original = $this->crear_articulo('Mesa catalogo por lista duplicar test', 3);
        $insumo   = $this->crear_articulo('Tabla catalogo por lista duplicar test', 50);

        DB::table('article_price_type')->insert([
            ['article_id' => $original->id, 'price_type_id' => $mayorista->id, 'percentage' => 20, 'visible_en_tienda' => 1],
            ['article_id' => $original->id, 'price_type_id' => $minorista->id, 'percentage' => 30, 'visible_en_tienda' => null],
        ]);

        $estado = $this->crear_estado('Corte catalogo por lista duplicar', 1);

        $receta = $this->crear_receta($original);

        $this->crear_ruta($receta, [
            ['article' => $insumo, 'amount' => 2, 'order_production_status_id' => $estado->id],
        ]);

        $this->post('api/recipe/' . $receta->id . '/duplicar', [
            'name' => 'Mesa catalogo por lista duplicada test',
        ])->assertStatus(201);

        $nuevo = Article::where('name', 'Mesa catalogo por lista duplicada test')
                        ->where('user_id', $comercio->id)
                        ->first();

        $this->assertNotNull($nuevo);

        $filas = DB::table('article_price_type')
                    ->where('article_id', $nuevo->id)
                    ->get()
                    ->keyBy('price_type_id');

        $this->assertSame(1, (int) $filas[$mayorista->id]->visible_en_tienda, 'Habilitado en Mayorista, como el original.');
        $this->assertNull($filas[$minorista->id]->visible_en_tienda, 'NULL en Minorista, como el original.');
    }
}
