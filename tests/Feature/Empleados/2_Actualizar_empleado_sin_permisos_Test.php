<?php

namespace Tests\Feature\Empleados;

use App\Models\PermissionEmpresa;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * 1/10/2026 — Trama: actualizar un empleado devolvía 500 (`foreach() ... EmployeeController.php:28`).
 *
 * `update()` hacía `permissions()->sync([])` y después recorría `$request->permissions`. Cuando el
 * request no traía esa clave, el foreach reventaba con el empleado ya sin ningún permiso.
 *
 * @group empleados
 */
class Actualizar_empleado_sin_permisos_Test extends EmpresaTestCase
{
    protected $owner;

    protected $empleado;

    protected $permisos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $sufijo = uniqid();
        $this->permisos = collect([
            PermissionEmpresa::forceCreate(['name' => 'Permiso A', 'model_name' => 'Cajas', 'slug' => 'zz_upd.a.'.$sufijo]),
            PermissionEmpresa::forceCreate(['name' => 'Permiso B', 'model_name' => 'Vender', 'slug' => 'zz_upd.b.'.$sufijo]),
        ]);

        $this->empleado = User::create([
            'name'             => 'Empleado Update',
            'company_name'     => 'Ferreteria update',
            'email'            => 'upd-'.$sufijo.'@test.local',
            'doc_number'       => 'DOC-UPD-'.$sufijo,
            'password'         => Hash::make('secreta'),
            'visible_password' => 'secreta',
            'owner_id'         => $this->owner->id,
        ]);
        $this->empleado->permissions()->sync($this->permisos->pluck('id')->all());
    }

    public function test_actualizar_sin_la_clave_permissions_no_rompe_ni_borra_los_permisos()
    {
        $response = $this->putJson('api/employee/'.$this->empleado->id, [
            'id'               => $this->empleado->id,
            'name'             => 'Nombre nuevo',
            'visible_password' => 'secreta',
            'doc_number'       => $this->empleado->doc_number,
            'admin_access'     => 0,
            'puede_guardar_ventas_sin_cliente' => 0,
            'dias_alertar_empleados_ventas_no_cobradas' => 0,
            'ver_alertas_de_todos_los_empleados' => 0,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Nombre nuevo', User::find($this->empleado->id)->name);
        $this->assertCount(2, User::find($this->empleado->id)->permissions);
    }

    public function test_actualizar_con_permissions_los_reemplaza()
    {
        $response = $this->putJson('api/employee/'.$this->empleado->id, [
            'id'               => $this->empleado->id,
            'name'             => 'Empleado Update',
            'visible_password' => 'secreta',
            'doc_number'       => $this->empleado->doc_number,
            'admin_access'     => 0,
            'puede_guardar_ventas_sin_cliente' => 0,
            'dias_alertar_empleados_ventas_no_cobradas' => 0,
            'ver_alertas_de_todos_los_empleados' => 0,
            'permissions'      => [['id' => $this->permisos[0]->id]],
        ]);

        $response->assertStatus(200);
        $this->assertEquals([$this->permisos[0]->id], User::find($this->empleado->id)->permissions->pluck('id')->all());
    }

    public function test_actualizar_con_permissions_vacio_los_quita()
    {
        $response = $this->putJson('api/employee/'.$this->empleado->id, [
            'id'               => $this->empleado->id,
            'name'             => 'Empleado Update',
            'visible_password' => 'secreta',
            'doc_number'       => $this->empleado->doc_number,
            'admin_access'     => 0,
            'puede_guardar_ventas_sin_cliente' => 0,
            'dias_alertar_empleados_ventas_no_cobradas' => 0,
            'ver_alertas_de_todos_los_empleados' => 0,
            'permissions'      => [],
        ]);

        $response->assertStatus(200);
        $this->assertCount(0, User::find($this->empleado->id)->permissions);
    }
}
