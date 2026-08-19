<?php

namespace Tests\Feature\Preferencias;

use App\Http\Controllers\Helpers\GlobalSearchDefaultsHelper;
use App\Models\Address;
use App\Models\Buyer;
use App\Models\Caja;
use App\Models\Client;
use App\Models\Order;
use App\Models\Sale;
use App\Models\TableColumnPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tarea 45 — feature tests de los defaults del buscador general (`GlobalSearchDefaultsSeeder` +
 * `GlobalSearchDefaultsHelper`).
 *
 * Los cinco primeros protegen el seeder: que siembre lo que dice la tabla, que se pueda volver a
 * correr, que pise la fila vieja, que deje a los empleados heredando la del dueño, y —el más
 * importante de los cinco— que NO toque ningún `preference_type` ni `model_name` que no sea suyo.
 *
 * El sexto es el que de verdad importa, porque los otros cinco pueden pasar con una tabla mal
 * armada: le pega al endpoint real de búsqueda con el payload que arma el front a partir de la
 * fila sembrada, y verifica que buscando por el dato de la relación aparezca el registro. Es lo
 * único que atrapa una relación tildada que no filtra nada — el modo de falla de `caja.address_id`
 * y de `order.buyer_id`, que es silencioso por diseño (`valid_relation_props()` descarta con
 * `method_exists` y sin avisar).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Defaults_del_buscador_general_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patrón que el resto de Preferencias).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Corre el seeder tal como lo corre Laravel en producción.
     *
     * @return void
     */
    protected function correr_seeder()
    {
        Artisan::call('db:seed', [
            '--class' => 'GlobalSearchDefaultsSeeder',
            '--force' => true,
        ]);
    }

    /**
     * Fila de preferencia de un usuario/modelo/tipo.
     *
     * @param int    $user_id
     * @param string $model_name
     * @param string $preference_type
     * @return \App\Models\TableColumnPreference|null
     */
    protected function preferencia($user_id, $model_name, $preference_type = 'global_search')
    {
        return TableColumnPreference::where('user_id', $user_id)
            ->where('model_name', $model_name)
            ->where('preference_type', $preference_type)
            ->first();
    }

    /**
     * Keys tildadas (visible: true) de una fila, en el orden guardado.
     *
     * @param \App\Models\TableColumnPreference $preference
     * @return array
     */
    protected function keys_tildadas($preference)
    {
        $keys = [];

        foreach ((array) $preference->columns as $column) {
            if (!empty($column['visible'])) {
                $keys[] = $column['key'];
            }
        }

        return $keys;
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_seeder_siembra_lo_que_dice_la_tabla()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->correr_seeder();

        foreach (GlobalSearchDefaultsHelper::model_names() as $model_name) {
            $preference = $this->preferencia($owner->id, $model_name);

            $this->assertNotNull($preference, 'Falta la fila global_search de '.$model_name);
            $this->assertEquals(
                GlobalSearchDefaultsHelper::keys_for($model_name),
                $this->keys_tildadas($preference),
                'Las keys tildadas de '.$model_name.' no son las de la tabla de defaults'
            );
            $this->assertEquals(['conector' => 'or'], (array) $preference->settings);
        }
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_seeder_es_idempotente()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->correr_seeder();
        $primera_corrida = $this->preferencia($owner->id, 'sale')->columns;

        // Si duplicara, esto explota contra el índice único de la tabla (migración
        // 2026_07_24_100000) en vez de dejar dos filas silenciosamente.
        $this->correr_seeder();

        $filas = TableColumnPreference::where('user_id', $owner->id)
            ->where('model_name', 'sale')
            ->where('preference_type', 'global_search')
            ->count();

        $this->assertEquals(1, $filas);
        $this->assertEquals($primera_corrida, $this->preferencia($owner->id, 'sale')->columns);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_seeder_pisa_la_seleccion_que_hubiera()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        TableColumnPreference::create([
            'user_id'         => $owner->id,
            'model_name'      => 'sale',
            'preference_type' => 'global_search',
            'columns'         => [
                ['key' => 'observations_ocultas', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
            ],
            'settings'        => ['conector' => 'and'],
        ]);

        $this->correr_seeder();

        $preference = $this->preferencia($owner->id, 'sale');

        $this->assertEquals(
            GlobalSearchDefaultsHelper::keys_for('sale'),
            $this->keys_tildadas($preference)
        );
        $this->assertEquals(['conector' => 'or'], (array) $preference->settings);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_seeder_borra_la_fila_del_empleado_y_este_hereda_la_del_dueno()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $empleado = User::create([
            'owner_id' => $owner->id,
            'password' => bcrypt('zz-password-testing'),
            'status'   => 'commerce',
        ]);

        TableColumnPreference::create([
            'user_id'         => $empleado->id,
            'model_name'      => 'sale',
            'preference_type' => 'global_search',
            'columns'         => [
                ['key' => 'observations_ocultas', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
            ],
        ]);

        $this->correr_seeder();

        $this->assertNull($this->preferencia($empleado->id, 'sale'));

        // El show resuelve el fallback empleado -> dueño, así que el empleado ve el default nuevo.
        $this->actingAs($empleado, 'web');
        $response = $this->getJson('api/table-column-preference/sale/global_search');
        $response->assertStatus(200);

        $keys = [];
        foreach ($response->json('model.columns') as $column) {
            if (!empty($column['visible'])) {
                $keys[] = $column['key'];
            }
        }

        $this->assertEquals(GlobalSearchDefaultsHelper::keys_for('sale'), $keys);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_seeder_no_toca_lo_que_no_es_suyo()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $columnas_ajenas = [
            ['key' => 'observations_ocultas', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
        ];

        // Mismo usuario y mismo modelo, otro preference_type: las columnas de la tabla de Ventas.
        $preferencia_de_tabla = TableColumnPreference::create([
            'user_id'         => $owner->id,
            'model_name'      => 'sale',
            'preference_type' => 'table',
            'columns'         => $columnas_ajenas,
        ]);

        // Mismo preference_type, modelo que no está en la tabla de defaults.
        $preferencia_de_otro_modelo = TableColumnPreference::create([
            'user_id'         => $owner->id,
            'model_name'      => 'deposit',
            'preference_type' => 'global_search',
            'columns'         => $columnas_ajenas,
        ]);

        $this->correr_seeder();

        $this->assertEquals($columnas_ajenas, $preferencia_de_tabla->fresh()->columns);
        $this->assertEquals($columnas_ajenas, $preferencia_de_otro_modelo->fresh()->columns);
    }

    /**
     * @group preferencias
     * @test
     */
    public function toda_relacion_de_la_tabla_tiene_su_metodo_en_el_modelo()
    {
        // Sin el método, GlobalSearchQueryHelper la descarta en silencio: la propiedad se ve
        // tildada en el desplegable y no filtra nada. Es el defecto que tenía caja.address_id.
        $this->assertEquals([], GlobalSearchDefaultsHelper::relation_methods_faltantes());
    }

    /**
     * Busca con el endpoint real, con el payload que arma el front a partir de la fila sembrada.
     *
     * @param string $model_name
     * @param string $query_value
     * @param array  $props           keys propias tildadas
     * @param array  $relation_props  [['relation' => ..., 'props' => [...]]]
     * @return \Illuminate\Testing\TestResponse
     */
    protected function buscar($model_name, $query_value, array $props, array $relation_props)
    {
        $props_payload = [];
        foreach ($props as $key) {
            $props_payload[] = ['key' => $key, 'keyword_mode' => 'alguna'];
        }

        $relation_props_payload = [];
        foreach ($relation_props as $relation_prop) {
            $relation_props_payload[] = [
                'relation'     => $relation_prop['relation'],
                'props'        => $relation_prop['props'],
                'keyword_mode' => 'alguna',
            ];
        }

        return $this->postJson('api/global-search/'.$model_name, [
            'query_value'    => $query_value,
            'props'          => $props_payload,
            'relation_props' => $relation_props_payload,
            'conector'       => 'or',
        ]);
    }

    /**
     * @group preferencias
     * @test
     */
    public function buscar_una_venta_por_el_nombre_del_cliente_la_encuentra()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($owner, 'web');

        $cliente = Client::create([
            'name'    => 'ZZClienteBuscador45',
            'user_id' => $owner->id,
        ]);
        $venta = Sale::create([
            'num'       => 999045,
            'client_id' => $cliente->id,
            'user_id'   => $owner->id,
        ]);

        // Payload equivalente a la fila sembrada de `sale`: num y observations son propias,
        // client_id y employee_id son relaciones.
        $response = $this->buscar(
            'sale',
            'ZZClienteBuscador45',
            ['num', 'observations'],
            [
                ['relation' => 'client', 'props' => ['name']],
                ['relation' => 'employee', 'props' => ['name']],
            ]
        );

        $response->assertStatus(200);
        $ids = [];
        foreach ($response->json('models.data') as $model) {
            $ids[] = $model['id'];
        }

        $this->assertContains($venta->id, $ids);
    }

    /**
     * @group preferencias
     * @test
     */
    public function buscar_un_pedido_por_el_nombre_del_comprador_lo_encuentra()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($owner, 'web');

        $comprador = Buyer::create([
            'name'    => 'ZZCompradorBuscador45',
            'user_id' => $owner->id,
        ]);
        $pedido = Order::create([
            'num'             => 999045,
            'status'          => 'confirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => 1,
            'user_id'         => $owner->id,
        ]);

        // Prueba la Pieza 3: hasta esta tarea `order.buyer_id` no era una relación buscable.
        $response = $this->buscar(
            'order',
            'ZZCompradorBuscador45',
            ['num'],
            [['relation' => 'buyer', 'props' => ['name']]]
        );

        $response->assertStatus(200);
        $ids = [];
        foreach ($response->json('models.data') as $model) {
            $ids[] = $model['id'];
        }

        $this->assertContains($pedido->id, $ids);
    }

    /**
     * @group preferencias
     * @test
     */
    public function buscar_una_caja_por_la_calle_de_la_sucursal_la_encuentra()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($owner, 'web');

        $sucursal = Address::create([
            'street'  => 'ZZSucursalBuscador45',
            'user_id' => $owner->id,
        ]);
        $caja = Caja::create([
            'num'        => 999045,
            'name'       => 'ZZCajaBuscador45',
            'address_id' => $sucursal->id,
            'user_id'    => $owner->id,
        ]);

        // Prueba la Pieza 4: sin Caja::address() esto devuelve vacío y sin ningún error.
        $response = $this->buscar(
            'caja',
            'ZZSucursalBuscador45',
            ['name'],
            [
                ['relation' => 'address', 'props' => ['street']],
                ['relation' => 'employee', 'props' => ['name']],
            ]
        );

        $response->assertStatus(200);
        $ids = [];
        foreach ($response->json('models.data') as $model) {
            $ids[] = $model['id'];
        }

        $this->assertContains($caja->id, $ids);
    }
}
