<?php

namespace Tests\Feature\Costeo;

use App\Models\Article;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión php84-errores-fatales (1/11/2026), caso B4: guardar un artículo con el costo escrito con
 * formato («$88.782,13», pegado desde un Excel o la edición rápida) daba
 * `TypeError: Unsupported operand types: string + string` en `Numbers::price()` (PHP 8 del VPS,
 * Masquito, San Cayetano y Lamartina) y `Incorrect decimal value` en el shared.
 */
class CostoConFormatoTest extends EmpresaTestCase
{
    protected function articulo_de_prueba()
    {
        $article = Article::where('name', TestingFerreteriaSeeder::ARTICULO_CENTINELA)->first();
        $this->assertNotNull($article, 'La base de testing no tiene el artículo centinela sembrado.');

        return $article;
    }

    protected function payload_de_costo($article, $overrides = [])
    {
        return array_merge($article->getAttributes(), [
            'id'                 => $article->id,
            'cost_incluye_iva'   => 0,
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ], $overrides);
    }

    /** @test */
    public function el_costo_con_formato_argentino_se_guarda_como_numero()
    {
        $article = $this->articulo_de_prueba();

        $this->putJson('api/article/'.$article->id, $this->payload_de_costo($article, ['cost' => '$88.782,13']))->assertStatus(200);

        $this->assertEqualsWithDelta(88782.13, (float) $article->fresh()->cost, 0.001);
    }

    /** @test */
    public function el_costo_numerico_de_siempre_no_cambia()
    {
        $article = $this->articulo_de_prueba();

        $this->putJson('api/article/'.$article->id, $this->payload_de_costo($article, ['cost' => '1500.5']))->assertStatus(200);
        $this->assertEqualsWithDelta(1500.5, (float) $article->fresh()->cost, 0.001);

        $this->putJson('api/article/'.$article->id, $this->payload_de_costo($article, ['cost' => 2000]))->assertStatus(200);
        $this->assertEqualsWithDelta(2000, (float) $article->fresh()->cost, 0.001);
    }

    /** @test */
    public function un_costo_que_no_es_un_numero_responde_422_y_no_toca_el_articulo()
    {
        $article = $this->articulo_de_prueba();
        $antes = $article->cost;

        $this->putJson('api/article/'.$article->id, $this->payload_de_costo($article, ['cost' => 'abc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cost');

        $this->assertEquals($antes, $article->fresh()->cost);
    }
}
