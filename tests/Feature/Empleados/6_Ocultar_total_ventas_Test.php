<?php

namespace Tests\Feature\Empleados;

use App\Models\PermissionEmpresa;
use App\Models\User;
use Database\Seeders\PermissionOcultarTotalVentasSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/** Ticket #28: permiso prohibitivo; el alta del catálogo nunca lo asigna. */
class Ocultar_total_ventas_Test extends EmpresaTestCase
{
    protected function crear_empleado()
    {
        $owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        return User::create([
            'name' => 'Empleado ticket 28',
            'owner_id' => $owner->id,
            'email' => 'ticket28-'.uniqid().'@test.local',
            'doc_number' => 'ticket28-'.uniqid(),
            'password' => $owner->password,
            // El fixture del dueño puede no tener clave visible. El ABM exige una
            // no vacía; se genera solo para esta persona ficticia de la transacción.
            'visible_password' => bin2hex(random_bytes(12)),
        ]);
    }

    public function test_el_seeder_crea_la_definicion_sin_tildarla_y_es_idempotente()
    {
        $empleado = $this->crear_empleado();
        $ids = PermissionEmpresa::where('slug', PermissionOcultarTotalVentasSeeder::SLUG)->pluck('id');
        DB::table('permission_empresa_user')->whereIn('permission_empresa_id', $ids)->delete();
        PermissionEmpresa::whereIn('id', $ids)->delete();
        $pivots = $this->pivots();

        $this->seed(PermissionOcultarTotalVentasSeeder::class);
        $permiso = PermissionEmpresa::where('slug', PermissionOcultarTotalVentasSeeder::SLUG)->first();
        $this->assertNotNull($permiso);
        $this->assertSame('Ocultar el total de las ventas', $permiso->name);
        $this->assertSame('Ventas', $permiso->model_name);
        $this->assertFalse($empleado->permissions()->where('slug', $permiso->slug)->exists());
        $this->assertSame($pivots, $this->pivots());

        // Ya hay un tilde elegido por el dueño: repetir tampoco lo quita ni lo copia a otros.
        $empleado->permissions()->attach($permiso->id);
        $otro = $this->crear_empleado();
        $pivots = $this->pivots();
        $this->seed(PermissionOcultarTotalVentasSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->assertSame(1, PermissionEmpresa::where('slug', $permiso->slug)->count());
        $this->assertSame($permiso->id, PermissionEmpresa::where('slug', $permiso->slug)->value('id'));
        $this->assertSame($pivots, $this->pivots());
        $this->assertFalse($otro->permissions()->where('slug', $permiso->slug)->exists());
    }

    public function test_el_catalogo_y_el_abm_permiten_tildar_y_destildar_sin_perder_los_otros_permisos()
    {
        $this->seed(PermissionOcultarTotalVentasSeeder::class);
        $empleado = $this->crear_empleado();
        $listado = PermissionEmpresa::where('slug', 'sale.index')->first();
        $cuenta = PermissionEmpresa::where('slug', 'sale.index.total')->first();
        $ocultar = PermissionEmpresa::where('slug', PermissionOcultarTotalVentasSeeder::SLUG)->first();
        $response = $this->getJson('api/permission')->assertStatus(200);
        $fila = collect($response->json('models'))->firstWhere('slug', $ocultar->slug);
        $this->assertSame('Ocultar el total de las ventas', $fila['name']);
        $this->assertSame('Ver Cuenta corriente y cantidad de ventas arriba de la tabla', $cuenta->name);

        foreach ([[$listado, $cuenta, $ocultar], [$listado, $cuenta]] as $seleccion) {
            $datos = [
                'id' => $empleado->id,
                'name' => $empleado->name,
                'doc_number' => $empleado->doc_number,
                'visible_password' => $empleado->visible_password,
                'permissions' => array_map(function ($permiso) { return ['id' => $permiso->id]; }, $seleccion),
            ];
            $this->putJson('api/employee/'.$empleado->id, $datos)->assertStatus(200);
            $this->assertEquals(
                collect($seleccion)->pluck('id')->sort()->values()->all(),
                $empleado->permissions()->pluck('permission_empresas.id')->sort()->values()->all()
            );
            $lista = $this->getJson('api/employee')->assertStatus(200);
            $actual = collect($lista->json('models'))->firstWhere('id', $empleado->id);
            $this->assertEquals(collect($seleccion)->pluck('id')->sort()->values()->all(), collect($actual['permissions'])->pluck('id')->sort()->values()->all());
        }
    }

    protected function pivots()
    {
        // Comparar los valores persistidos, no la identidad de dos stdClass que
        // MySQL materializa como objetos distintos aunque sus campos coincidan.
        return DB::table('permission_empresa_user')->orderBy('id')->get()->map(function ($fila) {
            return (array) $fila;
        })->all();
    }
}
