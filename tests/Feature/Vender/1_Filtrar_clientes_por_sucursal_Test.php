<?php

namespace Tests\Feature\Vender;

use App\Models\Address;
use App\Models\Client;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests del filtro por sucursal del buscador de clientes de VENDER (tarea 16, 11/8/2026).
 *
 * Protege la regla unica que decidio Lucas el 11/8/2026: un cliente SIN sucursal asignada
 * aparece en TODAS las sucursales. Por eso el parametro nuevo de searchFromModal no puede ser
 * el where estricto que ya existia (depends_on_key/depends_on_value): ese dejaria afuera
 * justamente a esos clientes.
 *
 * En la base conviven `null` y `0` para "sin sucursal", porque el select "Sucursal" del
 * formulario del cliente arranca en 0. Los dos casos se prueban por separado a proposito.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria. Cada test crea sus propios modelos con user_id 500.
 */
class Filtrar_clientes_por_sucursal_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patron que Catalogos/Sales/Stock).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Crea las dos sucursales y los cinco clientes del escenario: dos en la sucursal A, uno en
     * la B, uno con address_id null y uno con address_id 0.
     *
     * @return array ['address_a' => Address, 'address_b' => Address]
     */
    protected function sembrar_escenario()
    {
        $address_a = Address::create([
            'street' => 'zz Sucursal A',
            'user_id' => 500,
        ]);

        $address_b = Address::create([
            'street' => 'zz Sucursal B',
            'user_id' => 500,
        ]);

        Client::create([
            'name' => 'zzsucursal Cliente A1',
            'user_id' => 500,
            'address_id' => $address_a->id,
        ]);

        Client::create([
            'name' => 'zzsucursal Cliente A2',
            'user_id' => 500,
            'address_id' => $address_a->id,
        ]);

        Client::create([
            'name' => 'zzsucursal Cliente B1',
            'user_id' => 500,
            'address_id' => $address_b->id,
        ]);

        Client::create([
            'name' => 'zzsucursal Cliente sin sucursal null',
            'user_id' => 500,
            'address_id' => null,
        ]);

        Client::create([
            'name' => 'zzsucursal Cliente sin sucursal cero',
            'user_id' => 500,
            'address_id' => 0,
        ]);

        return [
            'address_a' => $address_a,
            'address_b' => $address_b,
        ];
    }

    /**
     * Nombres devueltos por el buscador, en el orden en que vinieron.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @return array
     */
    protected function nombres_de($response)
    {
        $models = $response->json('models.data');

        $nombres = [];
        foreach ($models as $model) {
            $nombres[] = $model['name'];
        }

        return $nombres;
    }

    /**
     * Criterio de aceptacion 1: con la sucursal A elegida se ven los clientes de A y los que no
     * tienen sucursal (null y 0), y no se ven los de la sucursal B.
     *
     * @group vender
     * @test
     */
    public function con_una_sucursal_elegida_trae_esa_sucursal_y_los_clientes_sin_sucursal()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $escenario = $this->sembrar_escenario();

        $response = $this->postJson('api/search-from-modal/client', [
            'query_value' => 'zzsucursal',
            'props_to_filter' => ['name'],
            'address_id_con_nulos' => $escenario['address_a']->id,
        ]);

        $response->assertStatus(200);

        $nombres = $this->nombres_de($response);

        $this->assertContains('zzsucursal Cliente A1', $nombres);
        $this->assertContains('zzsucursal Cliente A2', $nombres);
        $this->assertContains('zzsucursal Cliente sin sucursal null', $nombres);
        $this->assertContains('zzsucursal Cliente sin sucursal cero', $nombres);
        $this->assertNotContains('zzsucursal Cliente B1', $nombres);
    }

    /**
     * La contracara del test anterior: eligiendo la sucursal B no aparecen los clientes de A,
     * y los que no tienen sucursal siguen apareciendo. Sin esto, un filtro que devolviera todo
     * pasaria el test 1 igual.
     *
     * @group vender
     * @test
     */
    public function con_la_otra_sucursal_elegida_no_aparecen_los_clientes_de_la_primera()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $escenario = $this->sembrar_escenario();

        $response = $this->postJson('api/search-from-modal/client', [
            'query_value' => 'zzsucursal',
            'props_to_filter' => ['name'],
            'address_id_con_nulos' => $escenario['address_b']->id,
        ]);

        $response->assertStatus(200);

        $nombres = $this->nombres_de($response);

        $this->assertContains('zzsucursal Cliente B1', $nombres);
        $this->assertContains('zzsucursal Cliente sin sucursal null', $nombres);
        $this->assertContains('zzsucursal Cliente sin sucursal cero', $nombres);
        $this->assertNotContains('zzsucursal Cliente A1', $nombres);
        $this->assertNotContains('zzsucursal Cliente A2', $nombres);
    }

    /**
     * Criterio de aceptacion 2: sin sucursal elegida en VENDER (el store arranca en 0) el
     * buscador devuelve todos los clientes, como antes del cambio.
     *
     * @group vender
     * @test
     */
    public function con_el_parametro_en_cero_trae_todos_los_clientes()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->sembrar_escenario();

        $response = $this->postJson('api/search-from-modal/client', [
            'query_value' => 'zzsucursal',
            'props_to_filter' => ['name'],
            'address_id_con_nulos' => 0,
        ]);

        $response->assertStatus(200);

        $nombres = $this->nombres_de($response);

        $this->assertContains('zzsucursal Cliente A1', $nombres);
        $this->assertContains('zzsucursal Cliente A2', $nombres);
        $this->assertContains('zzsucursal Cliente B1', $nombres);
        $this->assertContains('zzsucursal Cliente sin sucursal null', $nombres);
        $this->assertContains('zzsucursal Cliente sin sucursal cero', $nombres);
    }

    /**
     * Criterio de aceptacion 3: con la extension inactiva el front no manda el parametro nuevo,
     * y el endpoint tiene que comportarse exactamente como antes.
     *
     * @group vender
     * @test
     */
    public function sin_el_parametro_trae_todos_los_clientes()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->sembrar_escenario();

        $response = $this->postJson('api/search-from-modal/client', [
            'query_value' => 'zzsucursal',
            'props_to_filter' => ['name'],
        ]);

        $response->assertStatus(200);

        $nombres = $this->nombres_de($response);

        $this->assertContains('zzsucursal Cliente A1', $nombres);
        $this->assertContains('zzsucursal Cliente A2', $nombres);
        $this->assertContains('zzsucursal Cliente B1', $nombres);
        $this->assertContains('zzsucursal Cliente sin sucursal null', $nombres);
        $this->assertContains('zzsucursal Cliente sin sucursal cero', $nombres);
    }

    /**
     * Criterio de aceptacion 4 — el que protege contra el dano colateral: search-from-modal es
     * generico y lo usa todo el sistema. Un modelo que no manda el parametro nuevo tiene que
     * devolver exactamente lo mismo que antes del cambio.
     *
     * @group vender
     * @test
     */
    public function la_busqueda_de_otros_modelos_no_cambia()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        Provider::create([
            'name' => 'zzsucursal Proveedor 1',
            'user_id' => 500,
            'status' => 'active',
        ]);

        Provider::create([
            'name' => 'zzsucursal Proveedor 2',
            'user_id' => 500,
            'status' => 'active',
        ]);

        $response = $this->postJson('api/search-from-modal/provider', [
            'query_value' => 'zzsucursal',
            'props_to_filter' => ['name'],
        ]);

        $response->assertStatus(200);

        $nombres = $this->nombres_de($response);

        $this->assertContains('zzsucursal Proveedor 1', $nombres);
        $this->assertContains('zzsucursal Proveedor 2', $nombres);
    }
}
