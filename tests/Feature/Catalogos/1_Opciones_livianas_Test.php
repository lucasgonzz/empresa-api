<?php

namespace Tests\Feature\Catalogos;

use App\Models\Client;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de los endpoints livianos provider/options y client/options (grupo 332, prompt 01).
 * Estrena la suite `paginacion` en empresa-api/develop.
 *
 * Protegen justo lo que rompía en silencio antes de este prompt: el catálogo se leía de
 * GET /api/provider (y /api/client), que pagina, así que cualquier consumidor que necesitara
 * la lista entera se quedaba con una página parcial sin ningún error visible (4/8/2026).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama está sembrada de
 * antes y un refresh la vaciaría. Cada test crea sus propios proveedores/clientes con user_id 500.
 */
class Opciones_livianas_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patrón que Expenses/Stock/Sales). Null si
     * la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * @group paginacion
     * @test
     */
    public function provider_options_devuelve_todos_los_proveedores_activos_aunque_sean_mas_de_cien()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        for ($i = 1; $i <= 105; $i++) {
            Provider::create([
                'name' => 'zz Proveedor opciones ' . $i,
                'user_id' => 500,
                'status' => 'active',
            ]);
        }

        $response = $this->getJson('api/provider/options');

        $response->assertStatus(200);

        $models = $response->json('models');

        $this->assertCount(105, array_filter($models, function ($m) {
            return strpos($m['name'], 'zz Proveedor opciones ') === 0;
        }));
    }

    /**
     * @group paginacion
     * @test
     */
    public function provider_options_no_trae_relaciones_ni_columnas_de_mas()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        Provider::create([
            'name' => 'zz Proveedor columnas',
            'user_id' => 500,
            'status' => 'active',
        ]);

        $response = $this->getJson('api/provider/options');

        $response->assertStatus(200);

        $models = $response->json('models');
        $encontrado = null;
        foreach ($models as $m) {
            if ($m['name'] === 'zz Proveedor columnas') {
                $encontrado = $m;
                break;
            }
        }

        $this->assertNotNull($encontrado);
        $this->assertEquals(['id', 'name'], array_keys($encontrado));
    }

    /**
     * @group paginacion
     * @test
     */
    public function provider_options_no_devuelve_proveedores_de_otro_usuario()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        Provider::create([
            'name' => 'zz Proveedor de otro user',
            'user_id' => 999999,
            'status' => 'active',
        ]);

        $response = $this->getJson('api/provider/options');

        $response->assertStatus(200);

        $nombres = array_column($response->json('models'), 'name');

        $this->assertNotContains('zz Proveedor de otro user', $nombres);
    }

    /**
     * @group paginacion
     * @test
     */
    public function client_options_devuelve_todos_los_clientes_aunque_sean_mas_de_veinticinco()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        for ($i = 1; $i <= 30; $i++) {
            Client::create([
                'name' => 'zz Cliente opciones ' . $i,
                'user_id' => 500,
            ]);
        }

        $response = $this->getJson('api/client/options');

        $response->assertStatus(200);

        $models = $response->json('models');

        $this->assertCount(30, array_filter($models, function ($m) {
            return strpos($m['name'], 'zz Cliente opciones ') === 0;
        }));
    }
}
