<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\MasiveUpdate;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exploración listado/precios (1/9/2026) — invariante I2.
 *
 * La actualización masiva de costo es EL caso de uso de "cambiar precios" ("el proveedor
 * aumentó 5%"), y lo que fija este archivo es la cadena completa, predicha a mano:
 *
 *  - La masiva "+5% al costo" deja cada costo en exactamente costo × 1.05.
 *  - El precio final de los artículos CON margen la sigue: final × 1.05 (encadenamiento
 *    que promete el manual de filtros-y-acciones-masivas).
 *  - El precio final del artículo con PRECIO MANUAL no se mueve: su precio no depende
 *    del costo.
 *  - REVERTIR deja costo Y precio final de los tres exactamente como estaban (delta 0).
 *    El test existente de revertir (2_Revertir_masiva_restaura_null) solo cubre
 *    category_id; el camino del costo — que además re-dispara setFinalPrice — no lo
 *    cubría nadie.
 *
 * Fixture propio, mismo criterio que 3_Precio_final_sigue_al_costo_Test: los artículos
 * del seeder tienen expectativas absolutas en la suite e2e y no se tocan.
 *
 * QUEUE sync: ProcessMasiveUpdateJob corre inline dentro del request, igual que en
 * 2_Revertir_masiva_restaura_null_Test.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Masiva_de_costo_recalcula_y_revierte_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * @param \App\Models\User $user
     * @param string $nombre
     * @param array $atributos
     * @return \App\Models\Article
     */
    protected function crear_articulo($user, $nombre, $atributos)
    {
        $provider = Provider::firstOrCreate([
            'name'    => 'zz Proveedor Exploracion Masiva',
            'user_id' => $user->id,
        ]);

        $article = Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $user->id,
            'provider_id' => $provider->id,
        ], $atributos));

        /* Fresco antes de recalcular: los defaults de la base (iva_id=2, aplicar_iva=1)
           tienen que entrar al cálculo, igual que en el camino real del formulario.
           Ver el comentario largo en 3_Precio_final_sigue_al_costo_Test. */
        $article = Article::find($article->id);

        ArticleHelper::setFinalPrice($article, $user->id);

        return $article->fresh();
    }

    /**
     * I2 entero, en un solo test: masiva de +5% sobre tres artículos (dos con margen, uno
     * con precio manual) y su reversión. Va junto porque el revert sin la masiva previa no
     * existe, y porque la foto previa es una sola.
     *
     * @group exploracion-listado
     * @test
     */
    public function la_masiva_de_costo_encadena_el_recalculo_y_revertir_deja_todo_exacto()
    {
        $user = $this->autenticar();

        $margen_a = $this->crear_articulo($user, 'zz Exploracion masiva margen A', [
            'cost'            => 2000,
            'percentage_gain' => 50,
        ]);

        $margen_b = $this->crear_articulo($user, 'zz Exploracion masiva margen B', [
            'cost'            => 800,
            'percentage_gain' => 25,
        ]);

        $manual = $this->crear_articulo($user, 'zz Exploracion masiva manual', [
            'cost'            => 1200,
            'percentage_gain' => null,
            'price'           => 999,
        ]);

        /** Foto previa: la reversión se afirma por diferencia CERO contra esto. */
        $previo = [];

        foreach ([$margen_a, $margen_b, $manual] as $article) {
            $previo[$article->id] = [
                'cost'        => (float) $article->cost,
                'final_price' => (float) $article->final_price,
            ];
        }

        /* La masiva real: increment 5% sobre cost, por selección manual. */
        $response = $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => [$margen_a->id, $margen_b->id, $manual->id],
            'update_form' => [
                [
                    'type'  => 'number',
                    'key'   => 'increment_cost',
                    'value' => 5,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $masive_update_id = json_decode($response->getContent(), true)['masive_update_id'];

        $masive_update = MasiveUpdate::find($masive_update_id);
        $this->assertNotNull($masive_update, 'No quedó registrado el MasiveUpdate.');
        $this->assertSame('completed', $masive_update->status, 'La masiva no terminó de procesarse.');

        /* Los costos: exactamente × 1.05. */
        foreach ($previo as $id => $valores) {
            $this->assertEqualsWithDelta(
                $valores['cost'] * 1.05,
                (float) Article::find($id)->cost,
                0.01,
                'El costo del artículo ' . $id . ' no quedó en costo × 1.05.'
            );
        }

        /* El precio final de los DOS con margen la sigue, proporción exacta. */
        foreach ([$margen_a->id, $margen_b->id] as $id) {
            $this->assertEqualsWithDelta(
                1.05,
                (float) Article::find($id)->final_price / $previo[$id]['final_price'],
                0.001,
                'El precio final del artículo con margen ' . $id . ' no siguió al costo (+5%).'
            );
        }

        /* El de precio manual NO se mueve: su precio no depende del costo. */
        $this->assertEqualsWithDelta(
            $previo[$manual->id]['final_price'],
            (float) Article::find($manual->id)->final_price,
            0.01,
            'La masiva de costo movió el precio final de un artículo con PRECIO MANUAL.'
        );

        /* La reversión, contra el endpoint real. */
        $this->postJson('api/masive-update/' . $masive_update_id . '/revert')->assertStatus(200);

        $this->assertSame('reverted', $masive_update->fresh()->status);

        /* El corazón del invariante: TODO vuelve a la foto previa, costo y precio final. */
        foreach ($previo as $id => $valores) {
            $despues = Article::find($id);

            $this->assertEqualsWithDelta(
                $valores['cost'],
                (float) $despues->cost,
                0.01,
                'Revertir no devolvió el costo del artículo ' . $id . ' a su valor previo.'
            );

            $this->assertEqualsWithDelta(
                $valores['final_price'],
                (float) $despues->final_price,
                0.01,
                'Revertir no devolvió el PRECIO FINAL del artículo ' . $id . ' a su valor previo: '
                    . 'quedó ' . $despues->final_price . ' y tenía que volver a ' . $valores['final_price'] . '.'
            );
        }
    }
}
