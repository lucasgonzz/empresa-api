<?php

namespace Tests\Feature\Busqueda;

use App\Models\Client;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Feature tests de la suma de saldos que devuelve `globalSearch()` (pedido de Lucas,
 * 18/9/2026): la tarjeta de saldos de Clientes/Proveedores sumaba en el frontend sobre
 * `state.filtered`, que solo trae la PAGINA que se ve en pantalla — con mas de una pagina el
 * total mostrado quedaba subestimado. El fix agrega `saldos` a la respuesta de `globalSearch()`,
 * calculado con SUM() en SQL sobre el MISMO WHERE que arma la tabla, sin LIMIT/OFFSET.
 *
 * Los tests de acá arriba (1 y 2) ya cubren que los filtros de columna se apliquen bien; estos
 * cubren especificamente que la suma nueva:
 * - vea TODO el universo filtrado, no solo la pagina que devuelve `models.data`;
 * - respete los mismos filtros que acota la tabla (una busqueda mas angosta suma menos);
 * - sea `null` en un modelo que no tiene columnas de saldo (no revienta el endpoint generico);
 * - funcione igual para `client` y para `provider` (el mismo mecanismo generico, sin caso
 *   especial por modelo).
 */
class Suma_De_Saldos_Filtrados_Test extends BusquedaTestCase
{
    /**
     * Id del usuario del fixture de testing, resuelto por email (nunca hardcodeado).
     *
     * @return int
     */
    protected function user_id_fixture()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first()->id;
    }

    /**
     * Crea clientes de prueba con saldo conocido, con un prefijo unico en el nombre para poder
     * aislarlos del resto del fixture con una busqueda de texto.
     *
     * @param  string $prefijo
     * @param  array<array{saldo_pesos:float,saldo_dolares:float}> $saldos
     * @return \Illuminate\Support\Collection<\App\Models\Client>
     */
    protected function crear_clientes_con_saldo($prefijo, array $saldos)
    {
        $user_id = $this->user_id_fixture();
        $creados = collect();

        foreach ($saldos as $i => $saldo) {
            $creados->push(Client::create([
                'name'          => $prefijo . '-' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'user_id'       => $user_id,
                'saldo_pesos'   => $saldo['saldo_pesos'],
                'saldo_dolares' => $saldo['saldo_dolares'],
            ]));
        }

        return $creados;
    }

    /**
     * Crea proveedores de prueba con saldo conocido, activos (globalSearch filtra `provider` por
     * `status = 'active'`, ver `$model_names_solo_activos` en SearchController).
     *
     * @param  string $prefijo
     * @param  array<array{saldo_pesos:float,saldo_dolares:float}> $saldos
     * @return \Illuminate\Support\Collection<\App\Models\Provider>
     */
    protected function crear_providers_con_saldo($prefijo, array $saldos)
    {
        $user_id = $this->user_id_fixture();
        $creados = collect();

        foreach ($saldos as $i => $saldo) {
            $creados->push(Provider::create([
                'name'          => $prefijo . '-' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'user_id'       => $user_id,
                'status'        => 'active',
                'saldo_pesos'   => $saldo['saldo_pesos'],
                'saldo_dolares' => $saldo['saldo_dolares'],
            ]));
        }

        return $creados;
    }

    /**
     * La suma que devuelve el endpoint ve TODO el universo filtrado, no solo la pagina que
     * `models.data` devuelve. Se piden 5 clientes con `per_page: 2` (dos paginas y monedas), y la
     * suma tiene que ser la de los 5, no la de los 2 que trae la primera pagina.
     *
     * @group busqueda
     * @test
     */
    public function la_suma_de_clientes_ve_todo_el_universo_filtrado_no_solo_la_pagina()
    {
        $prefijo = 'zz-saldos-cli-' . uniqid();

        $this->crear_clientes_con_saldo($prefijo, [
            ['saldo_pesos' => 1000.50, 'saldo_dolares' => 10.00],
            ['saldo_pesos' => 2000.25, 'saldo_dolares' => 20.00],
            ['saldo_pesos' => 3000.00, 'saldo_dolares' => 30.50],
            ['saldo_pesos' => -500.00, 'saldo_dolares' => 0.00],
            ['saldo_pesos' => 100.25,  'saldo_dolares' => 5.25],
        ]);

        $response = $this->postJson('api/global-search/client', $this->payload_global_search([
            'query_value' => $prefijo,
            'props'       => ['name'],
            'per_page'    => 2,
        ]));

        $response->assertStatus(200);

        // La tabla si pagino: la pagina trae 2, pero el universo filtrado son 5.
        $this->assertCount(2, $response->json('models.data'), 'la pagina deberia traer solo 2 filas (per_page)');
        $this->assertEquals(5, $response->json('models.total'), 'el total filtrado deberian ser los 5 clientes creados');

        // La suma, en cambio, tiene que ver los 5 -- no los 2 de la pagina.
        $this->assertEqualsWithDelta(5601.00, $response->json('saldos.saldo_pesos'), 0.01, 'saldo_pesos deberia sumar los 5 clientes filtrados, no solo la pagina');
        $this->assertEqualsWithDelta(65.75, $response->json('saldos.saldo_dolares'), 0.01, 'saldo_dolares deberia sumar los 5 clientes filtrados, no solo la pagina');
    }

    /**
     * Un filtro mas angosto (una busqueda que matchea menos clientes) tiene que sumar MENOS: la
     * suma respeta el mismo WHERE que acota la tabla, no el universo completo del usuario.
     *
     * @group busqueda
     * @test
     */
    public function la_suma_respeta_el_mismo_filtro_que_acota_la_tabla()
    {
        $prefijo = 'zz-saldos-cli-' . uniqid();

        $clientes = $this->crear_clientes_con_saldo($prefijo, [
            ['saldo_pesos' => 1000.00, 'saldo_dolares' => 0.00],
            ['saldo_pesos' => 2000.00, 'saldo_dolares' => 0.00],
        ]);

        // Filtro de columna exacto sobre UN solo cliente de los dos creados.
        $response = $this->postJson('api/global-search/client', $this->payload_global_search([
            'filters' => [
                ['key' => 'name', 'type' => 'text', 'igual_que' => $clientes[0]->name],
            ],
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('models.total'));
        $this->assertEqualsWithDelta(1000.00, $response->json('saldos.saldo_pesos'), 0.01, 'con el filtro acotado a 1 cliente, la suma no puede incluir al otro');
    }

    /**
     * Un modelo que no tiene columnas de saldo (article) no revienta el endpoint generico: la
     * clave `saldos` viaja en `null`.
     *
     * @group busqueda
     * @test
     */
    public function un_modelo_sin_columnas_de_saldo_devuelve_saldos_null()
    {
        $response = $this->postJson('api/global-search/article', $this->payload_global_search());

        $response->assertStatus(200);
        $this->assertNull($response->json('saldos'), 'article no tiene saldo_pesos/saldo_dolares: saldos deberia ser null');
    }

    /**
     * El mismo mecanismo, ahora sobre `provider`: mismo patron, sin caso especial por modelo.
     * `provider` ademas pasa por el filtro `status = 'active'` de globalSearch (ver
     * `$model_names_solo_activos`), asi que se crean activos a proposito.
     *
     * @group busqueda
     * @test
     */
    public function la_suma_de_proveedores_ve_todo_el_universo_filtrado_no_solo_la_pagina()
    {
        $prefijo = 'zz-saldos-prov-' . uniqid();

        $this->crear_providers_con_saldo($prefijo, [
            ['saldo_pesos' => 500.00, 'saldo_dolares' => 15.00],
            ['saldo_pesos' => 1500.00, 'saldo_dolares' => 25.00],
            ['saldo_pesos' => 250.75, 'saldo_dolares' => 0.00],
        ]);

        $response = $this->postJson('api/global-search/provider', $this->payload_global_search([
            'query_value' => $prefijo,
            'props'       => ['name'],
            'per_page'    => 1,
        ]));

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('models.data'), 'la pagina deberia traer solo 1 fila (per_page)');
        $this->assertEquals(3, $response->json('models.total'));
        $this->assertEqualsWithDelta(2250.75, $response->json('saldos.saldo_pesos'), 0.01, 'saldo_pesos deberia sumar los 3 proveedores filtrados, no solo la pagina');
        $this->assertEqualsWithDelta(40.00, $response->json('saldos.saldo_dolares'), 0.01, 'saldo_dolares deberia sumar los 3 proveedores filtrados, no solo la pagina');
    }
}
