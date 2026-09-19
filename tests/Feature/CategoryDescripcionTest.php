<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de la descripcion de categoria (mision cruzada catalogo-categorias-home,
 * 18/9/2026). La tienda online la va a mostrar en la tarjeta de categoria del Inicio cuando el
 * toggle mostrar_catalogo_categorias_home este activo (ver
 * OnlineConfigurationMostrarCatalogoCategoriasHomeTest) -- esa parte la construye tienda-spa en
 * otro slot, aca solo se protege que el campo persista en el ABM de empresa.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria. Cada test crea su propia categoria con user_id 500.
 */
class CategoryDescripcionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patron que Expenses/Catalogos). Null
     * si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * @group catalogos
     * @test
     */
    public function crear_una_categoria_persiste_la_descripcion()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->postJson('api/category', [
            'name' => 'zz Categoria con descripcion',
            'descripcion' => 'Se muestra en la tarjeta de la tienda online.',
            'price_types' => [],
        ]);

        $response->assertStatus(201);

        $categoria = Category::find($response->json('model.id'));
        $this->assertEquals('Se muestra en la tarjeta de la tienda online.', $categoria->descripcion);
    }

    /**
     * @group catalogos
     * @test
     */
    public function actualizar_una_categoria_persiste_la_descripcion()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $categoria = Category::create([
            'name' => 'zz Categoria sin descripcion',
            'user_id' => 500,
        ]);

        $response = $this->putJson('api/category/' . $categoria->id, [
            'name' => $categoria->name,
            'descripcion' => 'Descripcion agregada en la edicion.',
            'price_types' => [],
        ]);

        $response->assertStatus(200);

        $this->assertEquals('Descripcion agregada en la edicion.', $categoria->fresh()->descripcion);
    }
}
