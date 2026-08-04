<?php

namespace Tests\Feature\Paginacion;

use App\Models\Client;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests del contrato de `per_page` en los index paginados (grupo 333, prompt 01).
 * Estrena la suite `paginacion` en empresa-api/develop junto con el grupo 332.
 *
 * Protege que el front pueda pedir un tamaño de página explícito y que, cuando no lo pide,
 * el backend siga devolviendo exactamente el default histórico de cada endpoint (100 en
 * provider, 25 en client) — antes ese default vivía duplicado y desincronizado del per_page
 * del store del front (medido el 4/8/2026: client declaraba 100 y el backend paginaba de a
 * 25, así que el modo offline se cortaba en la primera página sin avisar).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Per_page_en_index_paginados_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patrón que Sales/Stock/Preferencias).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    protected function crear_providers($user_id, $cantidad)
    {
        for ($i = 0; $i < $cantidad; $i++) {
            Provider::create([
                'name'    => 'zz-provider-paginacion-' . $i,
                'user_id' => $user_id,
                'status'  => 'active',
            ]);
        }
    }

    protected function crear_clients($user_id, $cantidad)
    {
        for ($i = 0; $i < $cantidad; $i++) {
            Client::create([
                'name'    => 'zz-client-paginacion-' . $i,
                'user_id' => $user_id,
            ]);
        }
    }

    /**
     * @group paginacion
     * @test
     */
    public function provider_index_respeta_el_per_page_pedido()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->crear_providers($user->id, 15);

        $response = $this->getJson('api/provider?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 10);
        $this->assertCount(10, $response->json('models.data'));
    }

    /**
     * @group paginacion
     * @test
     */
    public function provider_index_sin_per_page_devuelve_el_default_historico()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->getJson('api/provider');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 100);
    }

    /**
     * @group paginacion
     * @test
     */
    public function provider_index_acota_el_per_page_al_techo()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->getJson('api/provider?per_page=5000');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 2000);
    }

    /**
     * @group paginacion
     * @test
     */
    public function provider_index_con_per_page_invalido_cae_al_default()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        foreach (['0', '-5', 'no-es-un-numero'] as $valor_invalido) {
            $response = $this->getJson('api/provider?per_page=' . $valor_invalido);
            $response->assertStatus(200);
            $response->assertJsonPath('models.per_page', 100);
        }
    }

    /**
     * @group paginacion
     * @test
     */
    public function client_index_respeta_el_per_page_pedido()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
        $this->crear_clients($user->id, 15);

        $response = $this->getJson('api/client?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 10);
        $this->assertCount(10, $response->json('models.data'));
    }

    /**
     * @group paginacion
     * @test
     */
    public function client_index_sin_per_page_devuelve_el_default_historico()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->getJson('api/client');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 25);
    }

    /**
     * @group paginacion
     * @test
     */
    public function client_index_acota_el_per_page_al_techo()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->getJson('api/client?per_page=5000');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 2000);
    }

    /**
     * @group paginacion
     * @test
     */
    public function client_index_con_per_page_invalido_cae_al_default()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        foreach (['0', '-5', 'no-es-un-numero'] as $valor_invalido) {
            $response = $this->getJson('api/client?per_page=' . $valor_invalido);
            $response->assertStatus(200);
            $response->assertJsonPath('models.per_page', 25);
        }
    }
}
