<?php

namespace Tests\Feature\Empleados;

use App\Models\Caja;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión empleados-duplicar-y-permisos (29/9/2026) — duplicar un empleado.
 *
 * El pedido de Lucas: "que se copie toda la información del perfil que estoy copiando. Permisos y
 * todo". Lo que define qué puede hacer y ver un empleado no está solo en los permisos: también
 * están las cajas (dos pivot), la sucursal, el perfil de vendedor y las alertas. `store()` del
 * controller solo guardaba un subconjunto de eso, así que la copia se hace en el API.
 *
 * @group empleados
 */
class Duplicar_empleado_Test extends EmpresaTestCase
{
    /**
     * @var \App\Models\User
     */
    protected $owner;

    /**
     * Empleado que se duplica, con el perfil completo cargado.
     *
     * @var \App\Models\User
     */
    protected $origen;

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $permisos;

    /**
     * @var \App\Models\Caja
     */
    protected $caja_de_vender;

    /**
     * @var \App\Models\Caja
     */
    protected $caja_de_tesoreria;

    /**
     * @var \App\Models\Caja
     */
    protected $caja_propia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        /*
            La base del slot no siembra permisos: se crean los que hacen falta. Slugs propios del
            test para no chocar con los reales si la base los tuviera.
        */
        $sufijo = uniqid();
        $this->permisos = collect([
            PermissionEmpresa::forceCreate(['name' => 'Ver cajas de prueba', 'model_name' => 'Cajas', 'slug' => 'zz_dup.caja.'.$sufijo]),
            PermissionEmpresa::forceCreate(['name' => 'Aplicar descuentos de prueba', 'model_name' => 'Vender', 'slug' => 'zz_dup.descuento.'.$sufijo]),
            PermissionEmpresa::forceCreate(['name' => 'Ver costos de prueba', 'model_name' => 'Articulos', 'slug' => 'zz_dup.costos.'.$sufijo]),
        ]);

        $cajas = Caja::where('user_id', $this->owner->id)->orderBy('id')->get();
        if ($cajas->count() < 3) {
            $this->fail('El fixture de testing tiene que tener al menos tres cajas del dueño.');
        }
        $this->caja_de_vender = $cajas[0];
        $this->caja_de_tesoreria = $cajas[1];
        $this->caja_propia = $cajas[2];

        $this->origen = User::create([
            'name'                                      => 'Empleado Original',
            'company_name'                              => 'Ferreteria duplicar',
            'email'                                     => 'dup-original-'.$sufijo.'@test.local',
            'doc_number'                                => 'DOC-ORIG-'.$sufijo,
            'phone'                                     => '1155550000',
            'password'                                  => Hash::make('secreta'),
            'visible_password'                          => 'secreta',
            'owner_id'                                  => $this->owner->id,
            'address_id'                                => 7,
            'seller_id'                                 => 9,
            'admin_access'                              => 0,
            'dias_alertar_empleados_ventas_no_cobradas' => 12,
            'ver_alertas_de_todos_los_empleados'        => 1,
            'puede_guardar_ventas_sin_cliente'          => 1,
            'default_version'                           => '4.3.0',
            'estable_version'                           => '4.2.9',
        ]);

        $this->origen->permissions()->sync($this->permisos->pluck('id')->all());

        DB::table('caja_user')->insert([
            'user_id' => $this->origen->id, 'caja_id' => $this->caja_de_vender->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('caja_treasury_user')->insert([
            'user_id' => $this->origen->id, 'caja_id' => $this->caja_de_tesoreria->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // La caja propia del empleado (`cajas.employee_id`) pertenece a UNA persona: no se copia.
        Caja::where('id', $this->caja_propia->id)->update(['employee_id' => $this->origen->id]);
    }

    /**
     * @return array
     */
    protected function datos_del_nuevo(array $cambios = [])
    {
        return array_merge([
            'name'              => 'Empleado Nuevo',
            'doc_number'        => 'DOC-NUEVO-'.uniqid(),
            'visible_password'  => 'clave-nueva',
            'phone'             => '1144440000',
        ], $cambios);
    }

    /**
     * El corazón del pedido: la copia tiene el mismo perfil, y los datos de la persona son los nuevos.
     *
     * @return void
     */
    public function test_la_copia_hereda_permisos_cajas_y_configuracion_del_original()
    {
        $datos = $this->datos_del_nuevo();

        $response = $this->postJson('api/employee/'.$this->origen->id.'/duplicate', $datos);

        $response->assertStatus(201);

        $copia = User::find($response->json('model.id'));

        $this->assertNotNull($copia);
        $this->assertNotEquals($this->origen->id, $copia->id);
        $this->assertEquals($this->owner->id, $copia->owner_id);

        // Datos de la persona: los que se mandaron, no los del original.
        $this->assertEquals('Empleado Nuevo', $copia->name);
        $this->assertEquals($datos['doc_number'], $copia->doc_number);
        $this->assertEquals('clave-nueva', $copia->visible_password);
        $this->assertTrue(Hash::check('clave-nueva', $copia->password));
        $this->assertEquals('1144440000', $copia->phone);

        // Permisos: los mismos, y la respuesta ya los trae para que la tabla los muestre.
        $this->assertEquals(
            $this->permisos->pluck('id')->sort()->values()->all(),
            $copia->permissions()->pluck('permission_empresas.id')->sort()->values()->all()
        );
        $this->assertCount(3, $response->json('model.permissions'));

        // Configuración del perfil.
        $this->assertEquals(7, $copia->address_id);
        $this->assertEquals(9, $copia->seller_id);
        $this->assertEquals(0, $copia->admin_access);
        $this->assertEquals(12, $copia->dias_alertar_empleados_ventas_no_cobradas);
        $this->assertEquals(1, $copia->ver_alertas_de_todos_los_empleados);
        $this->assertEquals(1, $copia->puede_guardar_ventas_sin_cliente);
        $this->assertEquals('4.3.0', $copia->default_version);
        $this->assertEquals('4.2.9', $copia->estable_version);

        // Cajas: las mismas, en las dos pivot.
        $this->assertEquals(
            [$this->caja_de_vender->id],
            DB::table('caja_user')->where('user_id', $copia->id)->pluck('caja_id')->all()
        );
        $this->assertEquals(
            [$this->caja_de_tesoreria->id],
            DB::table('caja_treasury_user')->where('user_id', $copia->id)->pluck('caja_id')->all()
        );
    }

    /**
     * Lo que NO se hereda: la caja propia sigue siendo del original y la sesión no se arrastra.
     *
     * @return void
     */
    public function test_la_copia_no_se_lleva_la_caja_propia_ni_la_sesion_del_original()
    {
        User::where('id', $this->origen->id)->update([
            'session_id'    => 'sesion-del-original',
            'last_activity' => now(),
            'login_at'      => now(),
        ]);

        $response = $this->postJson('api/employee/'.$this->origen->id.'/duplicate', $this->datos_del_nuevo());

        $response->assertStatus(201);

        $copia = User::find($response->json('model.id'));

        $this->assertNull($copia->session_id);
        $this->assertNull($copia->login_at);
        $this->assertEquals(
            $this->origen->id,
            Caja::find($this->caja_propia->id)->employee_id,
            'La caja propia tiene que seguir siendo del original'
        );
        $this->assertEquals(0, Caja::where('employee_id', $copia->id)->count());
    }

    /**
     * Duplicar no le toca nada al original.
     *
     * @return void
     */
    public function test_el_original_queda_intacto()
    {
        $this->postJson('api/employee/'.$this->origen->id.'/duplicate', $this->datos_del_nuevo())->assertStatus(201);

        $this->origen->refresh();

        $this->assertEquals('Empleado Original', $this->origen->name);
        $this->assertEquals('secreta', $this->origen->visible_password);
        $this->assertCount(3, $this->origen->permissions);
        $this->assertEquals(1, DB::table('caja_user')->where('user_id', $this->origen->id)->count());
        $this->assertEquals(1, DB::table('caja_treasury_user')->where('user_id', $this->origen->id)->count());
    }

    /**
     * El documento es con lo que se ingresa: no puede repetirse, y si falla no queda nada a medias.
     *
     * @return void
     */
    public function test_con_un_documento_repetido_no_crea_nada()
    {
        $antes = User::where('owner_id', $this->owner->id)->count();

        $response = $this->postJson(
            'api/employee/'.$this->origen->id.'/duplicate',
            $this->datos_del_nuevo(['doc_number' => $this->origen->doc_number])
        );

        $response->assertStatus(422);
        $this->assertEquals('Ya hay un empleado con ese numero de documento', $response->json('message'));
        $this->assertEquals($antes, User::where('owner_id', $this->owner->id)->count());
    }

    /**
     * @return void
     */
    public function test_pide_nombre_documento_y_contrasena()
    {
        $antes = User::where('owner_id', $this->owner->id)->count();

        foreach (['name', 'doc_number', 'visible_password'] as $campo) {
            $this->postJson(
                'api/employee/'.$this->origen->id.'/duplicate',
                $this->datos_del_nuevo([$campo => ''])
            )->assertStatus(422);
        }

        $this->assertEquals($antes, User::where('owner_id', $this->owner->id)->count());
    }

    /**
     * Un empleado de otro dueño no se puede duplicar aunque se conozca su id.
     *
     * @return void
     */
    public function test_no_duplica_un_empleado_de_otro_dueno()
    {
        $otro_dueno = User::create([
            'name'         => 'Otro dueño',
            'company_name' => 'Otra empresa',
            'email'        => 'dup-otro-dueno-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
        ]);
        $ajeno = User::create([
            'name'         => 'Empleado ajeno',
            'company_name' => 'Otra empresa',
            'email'        => 'dup-ajeno-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $otro_dueno->id,
        ]);

        $this->postJson('api/employee/'.$ajeno->id.'/duplicate', $this->datos_del_nuevo())
            ->assertStatus(404);
    }

    /**
     * Un empleado común (sin acceso de administrador) no puede armarse copias con permisos de otro.
     *
     * @return void
     */
    public function test_un_empleado_sin_acceso_de_administrador_no_puede_duplicar()
    {
        $this->actingAs($this->origen, 'web');

        $this->postJson('api/employee/'.$this->origen->id.'/duplicate', $this->datos_del_nuevo())
            ->assertStatus(403);
    }
}
