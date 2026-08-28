<?php

namespace Tests\Feature\Listado;

use App\Models\Article;
use App\Models\Category;
use App\Models\MasiveUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tanda correctivos 24/8 — ítem 6: la reversión de una actualización masiva
 * (MasiveUpdateHelper::revert_article_pivot_changes / revert_non_article_items) salteaba
 * los campos cuyo valor viejo era NULL, porque preguntaba con isset() sobre
 * $change['old'] — e isset da false cuando la clave existe con NULL. Justo el caso más
 * común: asignarle categoría a artículos que NO tenían. Revertir los dejaba con la
 * categoría puesta. Restaurar a NULL también es restaurar (array_key_exists).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Revertir_masiva_restaura_null_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * La masiva asigna categoría a artículos SIN categoría; revertirla los tiene que
     * dejar de nuevo sin categoría (category_id NULL), no con la asignada.
     *
     * @group listado
     * @test
     */
    public function revertir_una_masiva_que_asigno_categoria_deja_los_articulos_sin_categoria()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        /** Categoría destino de la masiva. */
        $categoria = Category::create([
            'name'    => 'zz Categoria masiva 2408',
            'user_id' => 500,
        ]);

        /** Dos artículos sin categoría (el estado que la reversión tiene que restaurar). */
        $articulo_a = Article::create([
            'name'        => 'zz Art masiva 2408 A',
            'user_id'     => 500,
            'category_id' => null,
        ]);

        $articulo_b = Article::create([
            'name'        => 'zz Art masiva 2408 B',
            'user_id'     => 500,
            'category_id' => null,
        ]);

        /*
         * La masiva por selección manual, contra el endpoint real. QUEUE sync: el job
         * ProcessMasiveUpdateJob corre inline dentro del request.
         */
        $response = $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => [$articulo_a->id, $articulo_b->id],
            'update_form' => [
                [
                    'type'  => 'select',
                    'key'   => 'category_id',
                    'value' => $categoria->id,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $masive_update_id = json_decode($response->getContent(), true)['masive_update_id'];

        $masive_update = MasiveUpdate::find($masive_update_id);
        $this->assertNotNull($masive_update, 'No quedó registrado el MasiveUpdate.');
        $this->assertSame('completed', $masive_update->status, 'La masiva no terminó de procesarse.');

        // La masiva aplicó de verdad (si no, revertir no probaría nada).
        $this->assertEquals($categoria->id, $articulo_a->fresh()->category_id);
        $this->assertEquals($categoria->id, $articulo_b->fresh()->category_id);

        /* La reversión, contra el endpoint real. */
        $this->postJson('api/masive-update/'.$masive_update_id.'/revert')->assertStatus(200);

        // El corazón del ítem: antes del fix, isset() salteaba el old NULL y la categoría
        // quedaba asignada después de revertir.
        $this->assertNull(
            $articulo_a->fresh()->category_id,
            'Revertir la masiva tiene que devolver el artículo A a SIN categoría (NULL).'
        );
        $this->assertNull(
            $articulo_b->fresh()->category_id,
            'Revertir la masiva tiene que devolver el artículo B a SIN categoría (NULL).'
        );

        $this->assertSame('reverted', $masive_update->fresh()->status);
    }

    /**
     * Control de no regresión: un old NO nulo se sigue restaurando igual que siempre.
     *
     * @group listado
     * @test
     */
    public function revertir_una_masiva_restaura_tambien_los_old_no_nulos()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $categoria_vieja = Category::create([
            'name'    => 'zz Categoria vieja 2408',
            'user_id' => 500,
        ]);

        $categoria_nueva = Category::create([
            'name'    => 'zz Categoria nueva 2408',
            'user_id' => 500,
        ]);

        $articulo = Article::create([
            'name'        => 'zz Art masiva 2408 C',
            'user_id'     => 500,
            'category_id' => $categoria_vieja->id,
        ]);

        $response = $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => [$articulo->id],
            'update_form' => [
                [
                    'type'  => 'select',
                    'key'   => 'category_id',
                    'value' => $categoria_nueva->id,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $masive_update_id = json_decode($response->getContent(), true)['masive_update_id'];

        $this->assertEquals($categoria_nueva->id, $articulo->fresh()->category_id);

        $this->postJson('api/masive-update/'.$masive_update_id.'/revert')->assertStatus(200);

        $this->assertEquals(
            $categoria_vieja->id,
            $articulo->fresh()->category_id,
            'Revertir tiene que restaurar la categoría anterior (old no nulo).'
        );
    }
}
