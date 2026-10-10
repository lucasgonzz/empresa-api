<?php

namespace Tests\Feature\HojasDeRuta;

use App\Models\Client;
use App\Models\PermissionEmpresa;
use App\Models\RoadMap;
use App\Models\RoadMapClientObservation;
use App\Models\RoadMapClientPosition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión hojas-de-ruta-defectos (10/10/2026).
 *
 * 1. `road_map.terminadas.only_your` / `road_map.terminadas.all` no los miraba ningún código: un
 *    empleado con "Entrar a Rutas" veía las hojas de todos los repartidores.
 * 2. Eliminar una hoja daba 500 (faltaba el `use` de ImageController) aunque la borraba, y dejaba
 *    colgando las filas de `road_map_sale`, `road_map_client_positions` y las observaciones.
 * 4. Crear/editar/mostrar una hoja devuelve `clientes` (igual que el listado): sin eso el modal de
 *    Rutas salía sin clientes hasta recargar.
 *
 * @group hojas_de_ruta
 */
class Permisos_y_eliminar_Test extends EmpresaTestCase
{
    /** @var \App\Models\User */
    protected $owner;

    /** @var \App\Models\Client */
    protected $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->cliente = Client::create([
            'name'    => 'Cliente hoja de ruta',
            'user_id' => $this->owner->id,
        ]);
    }

    /**
     * Cambia el usuario autenticado (el guard de sanctum cachea al primero: ver los tests de Alertas).
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * @param  array  $slugs  Permisos que se le dan.
     * @return \App\Models\User
     */
    protected function repartidor($slugs)
    {
        $user = User::create([
            'name'         => 'Repartidor '.uniqid(),
            'company_name' => 'Ferreteria hoja de ruta',
            'email'        => 'ruta-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $this->owner->id,
        ]);

        $ids = [];
        foreach ($slugs as $slug) {
            $permiso = PermissionEmpresa::where('slug', $slug)->first();
            if (is_null($permiso)) {
                $permiso = PermissionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug, 'model_name' => 'Entregas y hojas de ruta']);
            }
            $ids[] = $permiso->id;
        }
        if (count($ids)) {
            $user->permissions()->sync($ids);
        }

        return $user;
    }

    /**
     * Una hoja del dueño del test con una venta del cliente del test.
     *
     * @param  \App\Models\User  $repartidor
     * @return \App\Models\RoadMap
     */
    protected function hoja_de($repartidor)
    {
        $hoja = RoadMap::create([
            'num'           => RoadMap::where('user_id', $this->owner->id)->max('num') + 1,
            'employee_id'   => $repartidor->id,
            'fecha_entrega' => '2026-10-10 00:00:00',
            'terminada'     => 0,
            'user_id'       => $this->owner->id,
        ]);

        $venta = Sale::create([
            'user_id'       => $this->owner->id,
            'client_id'     => $this->cliente->id,
            'fecha_entrega' => '2026-10-10 00:00:00',
        ]);

        $hoja->sales()->attach($venta->id);

        RoadMapClientPosition::create([
            'road_map_id' => $hoja->id,
            'client_id'   => $this->cliente->id,
            'position'    => 1,
        ]);

        return $hoja;
    }

    /**
     * Pega al listado y devuelve los ids de las hojas DE ESTE TEST (la base del slot puede tener
     * hojas de otras corridas: se filtra por el cliente de las ventas).
     *
     * @param  int  $employee_id  0 = todos los repartidores (lo que manda la SPA por defecto).
     * @return array
     */
    protected function ids_del_listado($employee_id = 0)
    {
        $response = $this->getJson('api/road-map/'.$employee_id.'/from-date/fecha_entrega/2026-10-10');

        $response->assertStatus(200);

        $cliente_id = $this->cliente->id;

        return collect($response->json('models'))
            ->filter(function ($hoja) use ($cliente_id) {
                return collect($hoja['sales'])->contains('client_id', $cliente_id);
            })
            ->pluck('id')->sort()->values()->all();
    }

    // ---------------------------------------------------------------------------------------------
    // 1. Permisos
    // ---------------------------------------------------------------------------------------------

    public function test_con_solo_sus_hojas_el_listado_trae_solo_las_suyas_aunque_pida_todas()
    {
        $yo = $this->repartidor(['road_map.terminadas.index', 'road_map.terminadas.only_your']);
        $otro = $this->repartidor(['road_map.terminadas.index']);

        $mia = $this->hoja_de($yo);
        $ajena = $this->hoja_de($otro);

        $this->actuar_como($yo);

        $this->assertEquals([$mia->id], $this->ids_del_listado(0), 'Pidiendo "todos" tiene que ver solo la suya');
        $this->assertEquals([$mia->id], $this->ids_del_listado($otro->id), 'Pidiendo a otro repartidor por la URL tampoco puede ver las de él');
    }

    public function test_con_solo_sus_hojas_no_puede_abrir_la_de_otro()
    {
        $yo = $this->repartidor(['road_map.terminadas.index', 'road_map.terminadas.only_your']);
        $otro = $this->repartidor([]);

        $mia = $this->hoja_de($yo);
        $ajena = $this->hoja_de($otro);

        $this->actuar_como($yo);

        $this->getJson('api/road-map/'.$mia->id)->assertStatus(200);
        $this->getJson('api/road-map/'.$ajena->id)->assertStatus(404);
    }

    public function test_si_tiene_tambien_ver_todas_gana_ver_todas()
    {
        $yo = $this->repartidor(['road_map.terminadas.index', 'road_map.terminadas.only_your', 'road_map.terminadas.all']);
        $otro = $this->repartidor([]);

        $mia = $this->hoja_de($yo);
        $ajena = $this->hoja_de($otro);

        $this->actuar_como($yo);

        $this->assertEquals(collect([$mia->id, $ajena->id])->sort()->values()->all(), $this->ids_del_listado(0));
    }

    public function test_sin_ninguno_de_los_dos_permisos_ve_todas_como_siempre()
    {
        $yo = $this->repartidor(['road_map.terminadas.index']);
        $otro = $this->repartidor([]);

        $mia = $this->hoja_de($yo);
        $ajena = $this->hoja_de($otro);

        $this->actuar_como($yo);

        $this->assertEquals(collect([$mia->id, $ajena->id])->sort()->values()->all(), $this->ids_del_listado(0));
    }

    public function test_el_dueno_ve_todas_y_puede_filtrar_por_repartidor()
    {
        $uno = $this->repartidor([]);
        $dos = $this->repartidor([]);

        $de_uno = $this->hoja_de($uno);
        $de_dos = $this->hoja_de($dos);

        $this->actuar_como($this->owner);

        $this->assertEquals(collect([$de_uno->id, $de_dos->id])->sort()->values()->all(), $this->ids_del_listado(0));
        $this->assertEquals([$de_dos->id], $this->ids_del_listado($dos->id));
    }

    public function test_un_empleado_con_acceso_de_administrador_ve_todas_aunque_tenga_solo_sus_hojas()
    {
        $admin = $this->repartidor(['road_map.terminadas.only_your']);
        $admin->admin_access = 1;
        $admin->save();
        $otro = $this->repartidor([]);

        $mia = $this->hoja_de($admin);
        $ajena = $this->hoja_de($otro);

        $this->actuar_como($admin);

        $this->assertEquals(collect([$mia->id, $ajena->id])->sort()->values()->all(), $this->ids_del_listado(0));
    }

    // ---------------------------------------------------------------------------------------------
    // 2. Eliminar
    // ---------------------------------------------------------------------------------------------

    public function test_eliminar_una_hoja_responde_bien_y_borra_la_hoja_y_todo_lo_que_colgaba_de_ella()
    {
        $repartidor = $this->repartidor([]);
        $hoja = $this->hoja_de($repartidor);

        RoadMapClientObservation::create([
            'road_map_id' => $hoja->id,
            'client_id'   => $this->cliente->id,
            'text'        => 'Dejar con el portero',
        ]);

        $venta_id = $hoja->sales()->first()->id;

        $this->deleteJson('api/road-map/'.$hoja->id)->assertStatus(200);

        $this->assertNull(RoadMap::find($hoja->id));
        $this->assertEquals(0, DB::table('road_map_sale')->where('road_map_id', $hoja->id)->count(), 'Quedó el pivot road_map_sale');
        $this->assertEquals(0, RoadMapClientPosition::where('road_map_id', $hoja->id)->count(), 'Quedaron las posiciones de clientes');
        $this->assertEquals(0, RoadMapClientObservation::where('road_map_id', $hoja->id)->count(), 'Quedaron las observaciones');

        $this->assertNotNull(Sale::find($venta_id), 'Borrar la hoja no puede borrar la venta');
    }

    public function test_eliminar_una_hoja_no_toca_las_otras()
    {
        $repartidor = $this->repartidor([]);
        $a_borrar = $this->hoja_de($repartidor);
        $a_conservar = $this->hoja_de($repartidor);

        $this->deleteJson('api/road-map/'.$a_borrar->id)->assertStatus(200);

        $this->assertNotNull(RoadMap::find($a_conservar->id));
        $this->assertEquals(1, DB::table('road_map_sale')->where('road_map_id', $a_conservar->id)->count());
        $this->assertEquals(1, RoadMapClientPosition::where('road_map_id', $a_conservar->id)->count());
    }

    public function test_eliminar_una_hoja_que_no_existe_o_es_de_otro_comercio_da_404_y_no_borra_nada()
    {
        $otro_comercio = User::create([
            'name'         => 'Otro comercio',
            'company_name' => 'Otro comercio hoja de ruta',
            'email'        => 'otro-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
        ]);

        $ajena = RoadMap::create([
            'num'         => 1,
            'employee_id' => null,
            'user_id'     => $otro_comercio->id,
        ]);

        $this->deleteJson('api/road-map/'.$ajena->id)->assertStatus(404);
        $this->assertNotNull(RoadMap::find($ajena->id));

        $this->deleteJson('api/road-map/99999999')->assertStatus(404);
    }

    // ---------------------------------------------------------------------------------------------
    // 4. La hoja recién guardada viene con `clientes`
    // ---------------------------------------------------------------------------------------------

    public function test_al_crear_una_hoja_la_respuesta_trae_los_clientes_agrupados()
    {
        $repartidor = $this->repartidor([]);

        $venta = Sale::create([
            'user_id'       => $this->owner->id,
            'client_id'     => $this->cliente->id,
            'fecha_entrega' => '2026-10-10 00:00:00',
        ]);

        $response = $this->postJson('api/road-map', [
            'employee_id'      => $repartidor->id,
            'fecha_entrega'    => '2026-10-10',
            'terminada'        => 0,
            'sales'            => [['id' => $venta->id]],
            'client_positions' => [['client' => ['id' => $this->cliente->id], 'position' => 1]],
        ]);

        $response->assertStatus(201);

        $clientes = $response->json('model.clientes');

        $this->assertCount(1, $clientes);
        $this->assertEquals($this->cliente->id, $clientes[0]['client']['id']);
        $this->assertEquals([$venta->id], collect($clientes[0]['sales'])->pluck('id')->all());
        $this->assertEquals($repartidor->id, $response->json('model.employee.id'));
    }
}
