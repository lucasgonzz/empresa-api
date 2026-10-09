<?php

namespace Tests\Feature\Empleados;

use App\Http\Controllers\Helpers\asistente_ia\PropuestaPermisoEmpleadoIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExtencionEmpresa;
use App\Models\PermissionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión empleados-alta-y-edicion (9/10/2026) — el alta y la edición de un empleado.
 *
 * Lo que Lucas midió en demo2 (4.3.8) y en el código de v4.3.8:
 *  1. `store()` guardaba un subconjunto: el perfil de vendedor, los días para alertar ventas no
 *     cobradas y "ver las de TODOS" se perdían en silencio al crear.
 *  2. Un alta con documento repetido contestaba `{model: false}` con 200: la SPA cerraba el
 *     formulario y se perdía lo escrito.
 *  3. `update()` con un documento que ya usaba otro lo ignoraba en silencio (200 con el viejo),
 *     después de haber sincronizado los permisos.
 *
 * Y lo que se decidió en el plan: nombre, documento y contraseña obligatorios en el alta y en la
 * edición; las versiones (`default_version` / `estable_version`) no las escribe esta pantalla;
 * `update()` y `destroy()` acotados a los empleados del dueño de la sesión.
 *
 * Todo por el camino real (`postJson` / `putJson` / `deleteJson`) y mirando la base, no la
 * respuesta sola.
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group empleados
 */
class Alta_y_edicion_de_empleado_Test extends EmpresaTestCase
{
    /** Los tres textos del 422, tal cual los devuelve el controller. */
    const MENSAJE_OBLIGATORIOS = 'Completá el nombre, el número de documento y la contraseña del empleado';

    const MENSAJE_LARGO = 'El nombre, el documento y la contraseña no pueden tener más de 128 caracteres, ni el teléfono más de 191';

    const MENSAJE_REPETIDO = 'Ya hay un empleado con ese número de documento';

    /** @var \App\Models\User */
    protected $owner;

    /**
     * Tres permisos propios del test (la base del slot no siembra permisos).
     *
     * @var \Illuminate\Support\Collection
     */
    protected $permisos;

    /**
     * Un empleado del dueño ya cargado, con permisos A y B y versiones propias.
     *
     * @var \App\Models\User
     */
    protected $empleado;

    /** @var string */
    protected $sufijo;

    /** @var array<int,ExtencionEmpresa> Extensiones enganchadas por este archivo. */
    protected $extensiones_enganchadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->sufijo = uniqid();

        $this->permisos = collect([
            PermissionEmpresa::forceCreate(['name' => 'Permiso alta A', 'model_name' => 'Cajas', 'slug' => 'zz_alta.a.'.$this->sufijo]),
            PermissionEmpresa::forceCreate(['name' => 'Permiso alta B', 'model_name' => 'Vender', 'slug' => 'zz_alta.b.'.$this->sufijo]),
            PermissionEmpresa::forceCreate(['name' => 'Permiso alta C', 'model_name' => 'Articulos', 'slug' => 'zz_alta.c.'.$this->sufijo]),
        ]);

        $this->empleado = User::create([
            'name'             => 'Empleado Existente',
            'email'            => 'alta-existente-'.$this->sufijo.'@test.local',
            'doc_number'       => 'DOC-ALTA-EXISTENTE-'.$this->sufijo,
            'phone'            => '1155550000',
            'password'         => Hash::make('secreta'),
            'visible_password' => 'secreta',
            'owner_id'         => $this->owner->id,
            'default_version'  => '4.3.0',
            'estable_version'  => '4.2.9',
        ]);
        $this->empleado->permissions()->sync([$this->permisos[0]->id, $this->permisos[1]->id]);
    }

    protected function tearDown(): void
    {
        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->owner->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Lo que manda el formulario de alta, con lo que se le cambie.
     *
     * @param  array  $cambios
     * @return array
     */
    protected function datos_del_alta(array $cambios = [])
    {
        return array_merge([
            'name'                                      => 'empleado nuevo',
            'doc_number'                                => 'DOC-ALTA-NUEVO-'.uniqid(),
            'visible_password'                          => 'clave-nueva',
            'phone'                                     => '1144440000',
            'admin_access'                              => 0,
            'address_id'                                => 0,
            'seller_id'                                 => 0,
            'dias_alertar_empleados_ventas_no_cobradas' => '',
            'ver_alertas_de_todos_los_empleados'        => 0,
            'puede_guardar_ventas_sin_cliente'          => 0,
            'permissions'                               => [],
        ], $cambios);
    }

    /**
     * Lo que manda el formulario de edición: el modelo entero, como `getModelToSend()` de la SPA.
     *
     * @param  array  $cambios
     * @return array
     */
    protected function datos_de_la_edicion(array $cambios = [])
    {
        return array_merge([
            'id'                                        => $this->empleado->id,
            'name'                                      => $this->empleado->name,
            'doc_number'                                => $this->empleado->doc_number,
            'visible_password'                          => 'secreta',
            'phone'                                     => $this->empleado->phone,
            'admin_access'                              => 0,
            'address_id'                                => null,
            'seller_id'                                 => null,
            'dias_alertar_empleados_ventas_no_cobradas' => null,
            'ver_alertas_de_todos_los_empleados'        => 0,
            'puede_guardar_ventas_sin_cliente'          => 0,
            'default_version'                           => '4.3.0',
            'estable_version'                           => '4.2.9',
            'permissions'                               => [
                ['id' => $this->permisos[0]->id],
                ['id' => $this->permisos[1]->id],
            ],
        ], $cambios);
    }

    /**
     * Ids de permisos de un usuario, ordenados.
     *
     * @param  int  $user_id
     * @return array<int,int>
     */
    protected function permisos_de($user_id)
    {
        $ids = User::find($user_id)->permissions->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        sort($ids);

        return $ids;
    }

    /**
     * Una tilde guardada en 0, y NO en null.
     *
     * `(int) null` también es 0: con columnas nullable (`admin_access`,
     * `ver_alertas_de_todos_los_empleados`) un `assertSame(0, (int) ...)` daría verde aunque se
     * guardara null. Por eso se afirman las dos cosas.
     *
     * @param  mixed  $valor
     * @param  string  $caso
     * @return void
     */
    protected function assertCeroNoNull($valor, $caso)
    {
        $this->assertNotNull($valor, $caso.': quedó en null, tenía que ser 0.');
        $this->assertSame(0, (int) $valor, $caso);
    }

    /**
     * Un dueño de OTRO comercio con un empleado propio (lo que hay en una base compartida).
     *
     * @return array{0: User, 1: User}
     */
    protected function otro_comercio()
    {
        $otro_dueno = User::create([
            'name'       => 'Otro dueño',
            'email'      => 'alta-otro-dueno-'.$this->sufijo.'@test.local',
            'doc_number' => 'DOC-ALTA-OTRO-DUENO-'.$this->sufijo,
            'password'   => Hash::make('x'),
        ]);

        $ajeno = User::create([
            'name'             => 'Empleado ajeno',
            'email'            => 'alta-ajeno-'.$this->sufijo.'@test.local',
            'doc_number'       => 'DOC-ALTA-AJENO-'.$this->sufijo,
            'password'         => Hash::make('ajena'),
            'visible_password' => 'ajena',
            'owner_id'         => $otro_dueno->id,
        ]);

        return [$otro_dueno, $ajeno];
    }

    // =====================================================================
    // Alta
    // =====================================================================

    /**
     * 🔴 El corazón del pedido 1: el alta guarda el perfil completo (antes se perdían el perfil de
     * vendedor y las alertas), y NO toma las versiones aunque vengan.
     *
     * @return void
     */
    public function test_el_alta_guarda_el_perfil_completo_y_no_las_versiones()
    {
        $datos = $this->datos_del_alta([
            'admin_access'                              => 1,
            'address_id'                                => 7,
            'seller_id'                                 => 9,
            'dias_alertar_empleados_ventas_no_cobradas' => '15',
            'ver_alertas_de_todos_los_empleados'        => true,
            'puede_guardar_ventas_sin_cliente'          => 1,
            'default_version'                           => 'https://frente-viejo.local',
            'estable_version'                           => 'https://estable-viejo.local',
            'permissions'                               => [
                ['id' => $this->permisos[0]->id, 'name' => 'Permiso alta A'],
                ['id' => $this->permisos[2]->id, 'name' => 'Permiso alta C'],
            ],
        ]);

        $response = $this->postJson('api/employee', $datos);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('model.permissions'), 'La respuesta tiene que traer los permisos cargados.');

        $creado = User::where('doc_number', $datos['doc_number'])->first();

        $this->assertNotNull($creado);
        $this->assertSame('Empleado nuevo', $creado->name, 'El nombre se guarda con la primera letra en mayúscula, como siempre.');
        $this->assertSame((int) $this->owner->id, (int) $creado->owner_id);
        $this->assertSame('1144440000', (string) $creado->phone);
        $this->assertSame('clave-nueva', (string) $creado->visible_password);
        $this->assertTrue(Hash::check('clave-nueva', $creado->password), 'El empleado no puede entrar con su contraseña.');

        $this->assertSame(1, (int) $creado->admin_access);
        $this->assertSame(7, (int) $creado->address_id);
        $this->assertSame(9, (int) $creado->seller_id, 'El perfil de vendedor se perdía al crear.');
        $this->assertSame(15, (int) $creado->dias_alertar_empleados_ventas_no_cobradas, 'Los días para alertar se perdían al crear.');
        $this->assertSame(1, (int) $creado->ver_alertas_de_todos_los_empleados, '"Ver las de TODOS" se perdía al crear.');
        $this->assertSame(1, (int) $creado->puede_guardar_ventas_sin_cliente);

        // Las versiones las escribe el admin: el alta las deja en null y el empleado usa las del dueño.
        $this->assertNull($creado->default_version);
        $this->assertNull($creado->estable_version);

        $esperados = [(int) $this->permisos[0]->id, (int) $this->permisos[2]->id];
        sort($esperados);
        $this->assertSame($esperados, $this->permisos_de($creado->id));
    }

    /**
     * Un alta sin la clave `permissions` daba 500 (`foreach` sobre null). Ahora es un alta válida,
     * sin permisos.
     *
     * @return void
     */
    public function test_el_alta_sin_la_clave_permissions_no_rompe()
    {
        $datos = $this->datos_del_alta();
        unset($datos['permissions']);

        $response = $this->postJson('api/employee', $datos);

        $response->assertStatus(201);
        $this->assertSame([], $this->permisos_de($response->json('model.id')));
    }

    /**
     * 🔴 `puede_guardar_ventas_sin_cliente` es NOT NULL: una tilde ausente o en null tiene que
     * guardarse como 0, no reventar con un error de SQL. Lo mismo para el resto de las tildes, y
     * los select sin elegir quedan en null.
     *
     * @return void
     */
    public function test_el_alta_con_las_tildes_ausentes_o_en_null_las_guarda_en_cero()
    {
        $con_null = $this->datos_del_alta([
            'admin_access'                              => null,
            'ver_alertas_de_todos_los_empleados'        => null,
            'puede_guardar_ventas_sin_cliente'          => null,
            'dias_alertar_empleados_ventas_no_cobradas' => null,
            'address_id'                                => '',
            'seller_id'                                 => null,
        ]);

        $sin_claves = [
            'name'             => 'Empleado minimo',
            'doc_number'       => 'DOC-ALTA-MINIMO-'.uniqid(),
            'visible_password' => 'clave-minima',
        ];

        foreach (['tildes en null' => $con_null, 'tildes ausentes' => $sin_claves] as $caso => $datos) {

            $this->postJson('api/employee', $datos)->assertStatus(201);

            $creado = User::where('doc_number', $datos['doc_number'])->first();

            $this->assertNotNull($creado, $caso);
            $this->assertCeroNoNull($creado->admin_access, $caso.' (admin_access)');
            $this->assertCeroNoNull($creado->ver_alertas_de_todos_los_empleados, $caso.' (ver_alertas_de_todos_los_empleados)');
            $this->assertCeroNoNull($creado->puede_guardar_ventas_sin_cliente, $caso.' (puede_guardar_ventas_sin_cliente)');
            $this->assertNull($creado->dias_alertar_empleados_ventas_no_cobradas, $caso);
            $this->assertNull($creado->address_id, $caso);
            $this->assertNull($creado->seller_id, $caso);
        }
    }

    /**
     * 🔴 El pedido 2: un documento repetido es un 422 con el motivo (el modal de la SPA queda
     * abierto con lo escrito), y no se crea nada. La unicidad es GLOBAL: el login busca por
     * documento sin mirar el dueño, así que choca también con el de otro comercio.
     *
     * @return void
     */
    public function test_el_alta_con_un_documento_repetido_da_422_y_no_crea_nada()
    {
        list(, $ajeno) = $this->otro_comercio();

        $casos = [
            'de un empleado del mismo dueño' => $this->empleado->doc_number,
            'de un usuario de otro dueño'    => $ajeno->doc_number,
            /*
                Con espacios alrededor, por HTTP: lo recorta el middleware global `TrimStrings`
                ANTES de llegar al controller, así que esto NO prueba el trim del controller (ese
                vale para pedidos armados por dentro, ver el test del documento viejo con espacios
                del asistente). Lo que prueba es que, de punta a punta, unos espacios no alcanzan
                para colar un documento repetido.
            */
            'con espacios alrededor, por HTTP' => '  '.$this->empleado->doc_number.'  ',
        ];

        foreach ($casos as $caso => $doc_number) {

            $usuarios_antes = User::count();

            $response = $this->postJson('api/employee', $this->datos_del_alta([
                'doc_number'  => $doc_number,
                'permissions' => [['id' => $this->permisos[0]->id]],
            ]));

            $response->assertStatus(422);
            $this->assertSame(self::MENSAJE_REPETIDO, $response->json('message'), $caso);
            $this->assertNull($response->json('model'), $caso.': la respuesta vieja era {model: false} con 200.');
            $this->assertSame($usuarios_antes, User::count(), $caso.': no se tiene que crear nada.');
        }
    }

    /**
     * Nombre, documento y contraseña obligatorios, texto y hasta 128 caracteres: si no, 422 y no
     * se crea nada (nunca un 500).
     *
     * @return void
     */
    public function test_el_alta_con_datos_que_no_sirven_da_422_y_no_crea_nada()
    {
        $largo = str_repeat('a', 129);

        $casos = [
            'sin nombre'                   => [['name' => ''], self::MENSAJE_OBLIGATORIOS],
            'nombre con espacios'          => [['name' => '   '], self::MENSAJE_OBLIGATORIOS],
            'sin documento'                => [['doc_number' => ''], self::MENSAJE_OBLIGATORIOS],
            'sin contraseña'               => [['visible_password' => ''], self::MENSAJE_OBLIGATORIOS],
            'contraseña en null'           => [['visible_password' => null], self::MENSAJE_OBLIGATORIOS],
            'nombre que es un array'       => [['name' => ['un', 'array']], self::MENSAJE_OBLIGATORIOS],
            'documento que es un array'    => [['doc_number' => ['un', 'array']], self::MENSAJE_OBLIGATORIOS],
            'nombre de 129'                => [['name' => $largo], self::MENSAJE_LARGO],
            'documento de 129'             => [['doc_number' => $largo], self::MENSAJE_LARGO],
            'contraseña de 129'            => [['visible_password' => $largo], self::MENSAJE_LARGO],
            'teléfono de 192'              => [['phone' => str_repeat('1', 192)], self::MENSAJE_LARGO],
        ];

        foreach ($casos as $caso => $par) {

            list($cambios, $mensaje) = $par;

            $usuarios_antes = User::count();

            $response = $this->postJson('api/employee', $this->datos_del_alta($cambios));

            $response->assertStatus(422);
            $this->assertSame($mensaje, $response->json('message'), $caso);
            $this->assertNull($response->json('errors'), $caso.': la forma es {message}, sin errors, igual que Duplicar.');
            $this->assertSame($usuarios_antes, User::count(), $caso.': no se tiene que crear nada.');
        }

        // Y el teléfono no es obligatorio: sin teléfono, el alta sale.
        $sin_telefono = $this->datos_del_alta();
        unset($sin_telefono['phone']);

        $this->postJson('api/employee', $sin_telefono)->assertStatus(201);

        /*
            El tope del teléfono es el de su columna, varchar(191) (`string()` sin largo y
            `Schema::defaultStringLength(191)`), no 128: uno de 191 entra.
        */
        $telefono_largo = $this->datos_del_alta(['phone' => str_repeat('1', 191)]);

        $this->postJson('api/employee', $telefono_largo)->assertStatus(201);
        $this->assertSame(191, mb_strlen((string) User::where('doc_number', $telefono_largo['doc_number'])->value('phone')));
    }

    // =====================================================================
    // Edición
    // =====================================================================

    /**
     * 🔴 El pedido 3: editar con el documento de OTRO usuario ya no se ignora en silencio. Es un 422
     * con el motivo y no cambia NADA: ni el nombre, ni los permisos (antes se sincronizaban antes
     * de descubrir el choque), ni el documento.
     *
     * @return void
     */
    public function test_la_edicion_con_el_documento_de_otro_da_422_y_no_cambia_nada()
    {
        list(, $ajeno) = $this->otro_comercio();

        $companero = User::create([
            'name'             => 'Compañero',
            'email'            => 'alta-companero-'.$this->sufijo.'@test.local',
            'doc_number'       => 'DOC-ALTA-COMPANERO-'.$this->sufijo,
            'password'         => Hash::make('x'),
            'visible_password' => 'x',
            'owner_id'         => $this->owner->id,
        ]);

        $permisos_antes = $this->permisos_de($this->empleado->id);
        $password_antes = User::find($this->empleado->id)->password;

        foreach (['de un compañero' => $companero->doc_number, 'de otro comercio' => $ajeno->doc_number] as $caso => $doc_number) {

            $response = $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
                'name'             => 'Nombre que no se tiene que guardar',
                'doc_number'       => $doc_number,
                'visible_password' => 'otra-clave',
                'permissions'      => [['id' => $this->permisos[2]->id]],
            ]));

            $response->assertStatus(422);
            $this->assertSame(self::MENSAJE_REPETIDO, $response->json('message'), $caso);

            $recargado = User::find($this->empleado->id);

            $this->assertSame('Empleado Existente', $recargado->name, $caso);
            $this->assertSame($this->empleado->doc_number, $recargado->doc_number, $caso);
            $this->assertSame('secreta', (string) $recargado->visible_password, $caso);
            $this->assertSame($password_antes, $recargado->password, $caso);
            $this->assertSame($permisos_antes, $this->permisos_de($this->empleado->id), $caso.': los permisos se tocaron antes de validar.');
        }
    }

    /**
     * Editar con su MISMO documento guarda (no choca consigo mismo), y con uno nuevo libre lo cambia.
     *
     * @return void
     */
    public function test_la_edicion_con_su_mismo_documento_o_uno_libre_guarda()
    {
        $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
            'name' => 'Nombre editado',
        ]))->assertStatus(200);

        $this->assertSame('Nombre editado', User::find($this->empleado->id)->name);

        $nuevo = 'DOC-ALTA-LIBRE-'.uniqid();

        $response = $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
            'name'       => 'Nombre editado',
            'doc_number' => $nuevo,
        ]));

        $response->assertStatus(200);
        $this->assertSame($nuevo, $response->json('model.doc_number'));
        $this->assertSame($nuevo, User::find($this->empleado->id)->doc_number);
    }

    /**
     * 🔴 Un empleado VIEJO cuyo documento ya choca con el de otro usuario (de antes de esta
     * validación) tiene que poder seguir guardándose sin tocarlo: la unicidad se chequea solo si el
     * documento cambió.
     *
     * @return void
     */
    public function test_un_empleado_cuyo_documento_ya_choca_se_puede_guardar_sin_tocarlo()
    {
        User::create([
            'name'             => 'Gemelo viejo',
            'email'            => 'alta-gemelo-'.$this->sufijo.'@test.local',
            'doc_number'       => $this->empleado->doc_number,
            'password'         => Hash::make('x'),
            'visible_password' => 'x',
            'owner_id'         => $this->owner->id,
        ]);

        $response = $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
            'name'        => 'Sigue guardando',
            'permissions' => [['id' => $this->permisos[2]->id]],
        ]));

        $response->assertStatus(200);
        $this->assertSame('Sigue guardando', User::find($this->empleado->id)->name);
        $this->assertSame([(int) $this->permisos[2]->id], $this->permisos_de($this->empleado->id));
    }

    /**
     * 🔴 La edición NO pisa las versiones aunque el pedido traiga otras: las escribe el admin en
     * cada rotación de frente, y un listado cargado antes de la rotación devolvería al empleado al
     * frente viejo con solo guardarlo.
     *
     * @return void
     */
    public function test_la_edicion_no_pisa_las_versiones()
    {
        $response = $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
            'default_version' => 'https://frente-viejo.local',
            'estable_version' => null,
        ]));

        $response->assertStatus(200);

        $recargado = User::find($this->empleado->id);

        $this->assertSame('4.3.0', $recargado->default_version);
        $this->assertSame('4.2.9', $recargado->estable_version);
    }

    /**
     * La edición guarda el perfil con la misma normalización que el alta.
     *
     * @return void
     */
    public function test_la_edicion_guarda_el_perfil_normalizado()
    {
        $response = $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
            'admin_access'                              => true,
            'address_id'                                => '7',
            'seller_id'                                 => 9,
            'dias_alertar_empleados_ventas_no_cobradas' => 20,
            'ver_alertas_de_todos_los_empleados'        => 1,
            'puede_guardar_ventas_sin_cliente'          => null,
        ]));

        $response->assertStatus(200);

        $recargado = User::find($this->empleado->id);

        $this->assertSame(1, (int) $recargado->admin_access);
        $this->assertSame(7, (int) $recargado->address_id);
        $this->assertSame(9, (int) $recargado->seller_id);
        $this->assertSame(20, (int) $recargado->dias_alertar_empleados_ventas_no_cobradas);
        $this->assertSame(1, (int) $recargado->ver_alertas_de_todos_los_empleados);
        $this->assertCeroNoNull($recargado->puede_guardar_ventas_sin_cliente, 'Un null en una columna NOT NULL tiene que ir como 0.');
    }

    /**
     * Nombre, documento y contraseña obligatorios también al editar: 422 y nada cambia (con la
     * contraseña vacía, además, el empleado no entraría más).
     *
     * @return void
     */
    public function test_la_edicion_sin_nombre_documento_o_contrasena_da_422_sin_cambios()
    {
        $password_antes = User::find($this->empleado->id)->password;
        $permisos_antes = $this->permisos_de($this->empleado->id);

        $casos = [
            'sin nombre'        => ['name' => ''],
            'sin documento'     => ['doc_number' => '  '],
            'sin contraseña'    => ['visible_password' => ''],
            'nombre en array'   => ['name' => ['x']],
        ];

        foreach ($casos as $caso => $cambios) {

            $response = $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion(array_merge($cambios, [
                'phone'       => '1199990000',
                'permissions' => [['id' => $this->permisos[2]->id]],
            ])));

            $response->assertStatus(422);
            $this->assertSame(self::MENSAJE_OBLIGATORIOS, $response->json('message'), $caso);

            $recargado = User::find($this->empleado->id);

            $this->assertSame('Empleado Existente', $recargado->name, $caso);
            $this->assertSame($this->empleado->doc_number, $recargado->doc_number, $caso);
            $this->assertSame('1155550000', (string) $recargado->phone, $caso);
            $this->assertSame('secreta', (string) $recargado->visible_password, $caso);
            $this->assertSame($password_antes, $recargado->password, $caso);
            $this->assertSame($permisos_antes, $this->permisos_de($this->empleado->id), $caso);
        }
    }

    /**
     * 🔴 Un documento VIEJO guardado con espacios, que ahora llega recortado (el formulario lo
     * manda tal cual y `TrimStrings` lo recorta), SÍ cuenta como cambio y se chequea por unicidad:
     * "cambió" se compara contra el valor crudo de la base. Si se comparara recortado, se
     * escribiría el recortado sin chequear y podrían quedar dos usuarios con el mismo documento.
     *
     * @return void
     */
    public function test_un_documento_viejo_con_espacios_que_llega_recortado_se_chequea()
    {
        $doc = 'DOC-ALTA-VIEJO-'.$this->sufijo;

        DB::table('users')->where('id', $this->empleado->id)->update(['doc_number' => ' '.$doc]);

        $otro = User::create([
            'name'             => 'Dueño del recortado',
            'email'            => 'alta-viejo-otro-'.$this->sufijo.'@test.local',
            'doc_number'       => $doc,
            'password'         => Hash::make('x'),
            'visible_password' => 'x',
            'owner_id'         => $this->owner->id,
        ]);

        $response = $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
            'doc_number' => ' '.$doc,
            'name'       => 'No se tiene que guardar',
        ]));

        $response->assertStatus(422);
        $this->assertSame(self::MENSAJE_REPETIDO, $response->json('message'));
        $this->assertSame(' '.$doc, User::find($this->empleado->id)->doc_number);
        $this->assertSame('Empleado Existente', User::find($this->empleado->id)->name);

        // Sin el otro usuario, el mismo guardado pasa y deja el documento recortado.
        DB::table('users')->where('id', $otro->id)->update(['doc_number' => 'DOC-ALTA-OTRO-'.uniqid()]);

        $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
            'doc_number' => ' '.$doc,
        ]))->assertStatus(200);

        $this->assertSame($doc, User::find($this->empleado->id)->doc_number);
    }

    /**
     * Un EMPLEADO logueado (sin acceso de administrador) que gestiona empleados: puede editar a un
     * compañero del mismo dueño (no se agregó el 403 de Duplicar), y no alcanza a un usuario de otro
     * comercio (404, nada cambia).
     *
     * @return void
     */
    public function test_un_empleado_logueado_edita_a_un_companero_pero_no_a_alguien_de_otro_comercio()
    {
        list(, $ajeno) = $this->otro_comercio();

        $logueado = User::create([
            'name'             => 'Empleado logueado',
            'email'            => 'alta-logueado-'.$this->sufijo.'@test.local',
            'doc_number'       => 'DOC-ALTA-LOGUEADO-'.$this->sufijo,
            'password'         => Hash::make('logueado'),
            'visible_password' => 'logueado',
            'owner_id'         => $this->owner->id,
            'admin_access'     => 0,
        ]);

        $this->actingAs($logueado, 'web');

        $this->putJson('api/employee/'.$this->empleado->id, $this->datos_de_la_edicion([
            'name' => 'Editado por un compañero',
        ]))->assertStatus(200);

        $this->assertSame('Editado por un compañero', User::find($this->empleado->id)->name);

        $ajeno_antes = User::find($ajeno->id);
        $permisos_antes = $this->permisos_de($ajeno->id);

        $this->putJson('api/employee/'.$ajeno->id, [
            'id'               => $ajeno->id,
            'name'             => 'Hackeado',
            'doc_number'       => 'DOC-HACK-'.uniqid(),
            'visible_password' => 'clave-hack',
            'permissions'      => [['id' => $this->permisos[0]->id]],
        ])->assertStatus(404);

        $recargado = User::find($ajeno->id);

        $this->assertSame($ajeno_antes->name, $recargado->name);
        $this->assertSame($ajeno_antes->doc_number, $recargado->doc_number);
        $this->assertSame($ajeno_antes->password, $recargado->password);
        $this->assertSame($permisos_antes, $this->permisos_de($ajeno->id));
    }

    // =====================================================================
    // Acotado al dueño
    // =====================================================================

    /**
     * 🔴 `update()` y `destroy()` solo alcanzan a los empleados del dueño de la sesión. Antes
     * resolvían el id contra TODA la tabla: el dueño mismo (cambiarle la contraseña) y, en una base
     * compartida, los empleados de otro comercio.
     *
     * @return void
     */
    public function test_editar_o_borrar_a_quien_no_es_empleado_del_dueno_da_404_y_no_toca_nada()
    {
        list($otro_dueno, $ajeno) = $this->otro_comercio();

        /*
            Se intercepta el borrado físico y se anota a quién se quiso borrar.

            ⚠️ No es para que el test pase: en el MySQL local un DELETE sobre `users` arrastra
            decenas de tablas hijas por FK y, con `table_definition_cache = 600` saturado por las
            bases de testing de todos los slots, da `1615 Prepared statement needs to be
            re-prepared` de forma determinística (medido 3 de 3 el 9/10/2026, con el mismo
            `$user->delete()` de siempre). Lo que este test mide es A QUIÉN alcanza `destroy()`, y
            eso se ve igual: el control del final (el empleado propio SÍ llega al delete) es lo que
            impide que un `destroy()` que contestara 404 a todo pase en verde.
        */
        $borrados = [];

        User::deleting(function ($user) use (&$borrados) {
            $borrados[] = (int) $user->id;

            return false;
        });

        $dueno_antes = User::find($this->owner->id);
        $ajeno_antes = User::find($ajeno->id);

        foreach (['el dueño mismo' => $dueno_antes, 'el empleado de otro comercio' => $ajeno_antes] as $caso => $usuario) {

            $permisos_antes = $this->permisos_de($usuario->id);

            $response = $this->putJson('api/employee/'.$usuario->id, [
                'id'               => $usuario->id,
                'name'             => 'Hackeado',
                'doc_number'       => 'DOC-HACK-'.uniqid(),
                'visible_password' => 'clave-hack',
                'permissions'      => [['id' => $this->permisos[0]->id]],
            ]);

            $response->assertStatus(404);

            $recargado = User::find($usuario->id);

            $this->assertSame($usuario->name, $recargado->name, $caso);
            $this->assertSame($usuario->doc_number, $recargado->doc_number, $caso);
            $this->assertSame($usuario->password, $recargado->password, $caso.': le cambiaron la contraseña.');
            $this->assertSame($permisos_antes, $this->permisos_de($usuario->id), $caso.': le tocaron los permisos.');

            $this->deleteJson('api/employee/'.$usuario->id)->assertStatus(404);

            $this->assertSame([], $borrados, $caso.': destroy() llegó a borrar un usuario que no es empleado del dueño.');
            $this->assertNotNull(User::find($usuario->id), $caso);
        }

        // Control: al empleado propio sí lo alcanza.
        $this->deleteJson('api/employee/'.$this->empleado->id)->assertStatus(200);
        $this->assertSame([(int) $this->empleado->id], $borrados);
    }

    // =====================================================================
    // El asistente
    // =====================================================================

    /**
     * 🔴 Al PROPONER: si al empleado le falta el documento (o el nombre, o la contraseña visible),
     * no se arma la tarjeta. `update()` exige los tres y contestaría 422 sin escribir nada: una
     * tarjeta para ese empleado no se podría confirmar nunca. Se dice qué falta y dónde se carga.
     *
     * @return void
     */
    public function test_el_asistente_no_propone_para_un_empleado_sin_documento()
    {
        $this->preparar_asistente();

        $sin_documento = $this->empleado_del_asistente(['doc_number' => null]);

        list($conversation, $assistant) = $this->conversacion('Sacale a Brisa el permiso A');

        $respuesta = $this->proponer_sacar_el_permiso_a($conversation, $assistant, $sin_documento);

        $this->assertFalse(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(
            $sin_documento->name.' no tiene cargado el número de documento, y sin eso no se pueden guardar sus permisos desde acá. Cargáselo en ABM > Empleados.',
            (string) $respuesta['error']
        );

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'No se tenía que armar la tarjeta.');
        $this->assertSame($this->ids_ab(), $this->permisos_de($sin_documento->id));
    }

    /**
     * 🔴 Al CONFIRMAR: la misma guarda, otra vez, porque la ficha pudo cambiar entre la propuesta y
     * el clic. Acá el documento se borra en el medio SIN tocar `updated_at` (con el query builder),
     * así que la guarda del 409 no lo ve: la que lo frena es la de la ficha.
     *
     * @return void
     */
    public function test_el_asistente_no_confirma_si_al_empleado_le_borraron_el_documento_en_el_medio()
    {
        $this->preparar_asistente();

        $empleado = $this->empleado_del_asistente();

        list($conversation, $assistant) = $this->conversacion('Sacale a Brisa el permiso A');

        $respuesta = $this->proponer_sacar_el_permiso_a($conversation, $assistant, $empleado);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        DB::table('users')->where('id', $empleado->id)->update(['doc_number' => null]);

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $esperado = $empleado->name.' no tiene cargado el número de documento, y sin eso no se pueden guardar sus permisos desde acá. Cargáselo en ABM > Empleados.';

        $http->assertStatus(422);
        $this->assertSame($esperado, $http->json('message'));

        $this->actuar_como_el_dueno();

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado(), 'La tarjeta no se puede dar por confirmada.');
        $this->assertSame($esperado, (string) $accion->error_mensaje);
        $this->assertSame($this->ids_ab(), $this->permisos_de($empleado->id), 'No se tiene que haber tocado ningún permiso.');
    }

    /**
     * 🔴 Si `update()` no guarda (422), el asistente lo dice con ESE motivo, y no con el engañoso
     * "el sistema no dejó los permisos como corresponde".
     *
     * El caso que solo ve `update()`: un empleado viejo con el documento guardado CON ESPACIOS
     * (" DOC-X") y otro usuario con "DOC-X". El asistente arma el pedido por dentro
     * (`Request::create()`, sin el middleware `TrimStrings`), así que acá sí trabaja el recorte del
     * controller: " DOC-X" llega como "DOC-X", eso cuenta como cambio (se compara contra el valor
     * crudo de la base) y choca. La ficha está completa, así que la guarda del helper no lo frena.
     *
     * @return void
     */
    public function test_si_update_no_guarda_el_asistente_lanza_con_ese_motivo()
    {
        $this->preparar_asistente();

        $doc = 'DOC-ALTA-ESPACIOS-'.$this->sufijo;

        $empleado = $this->empleado_del_asistente(['doc_number' => ' '.$doc]);

        User::create([
            'name'             => 'Dueño del documento recortado',
            'email'            => 'alta-recortado-'.$this->sufijo.'@test.local',
            'doc_number'       => $doc,
            'password'         => Hash::make('x'),
            'visible_password' => 'x',
            'owner_id'         => $this->owner->id,
        ]);

        $password_antes = User::find($empleado->id)->password;

        list($conversation, $assistant) = $this->conversacion('Sacale a Brisa el permiso A');

        $respuesta = $this->proponer_sacar_el_permiso_a($conversation, $assistant, $empleado);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $esperado = self::MENSAJE_REPETIDO.'. Corregilo en ABM > Empleados.';

        $http->assertStatus(422);
        $this->assertSame($esperado, $http->json('message'));
        $this->assertStringNotContainsString('no dejó los permisos como corresponde', (string) $http->json('message'));

        $this->actuar_como_el_dueno();

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado(), 'La tarjeta no se puede dar por confirmada.');
        $this->assertSame($esperado, (string) $accion->error_mensaje);

        $recargado = User::find($empleado->id);

        $this->assertSame($this->ids_ab(), $this->permisos_de($empleado->id), 'No se tiene que haber tocado ningún permiso.');
        $this->assertSame(' '.$doc, $recargado->doc_number, 'El documento no se tenía que tocar.');
        $this->assertSame($password_antes, $recargado->password);
    }

    // ---------------------------------------------------------------------
    // Armado del asistente (mismo que Tests\Feature\ChatIa\Cheque_y_permisos_de_empleado_Test)
    // ---------------------------------------------------------------------

    /**
     * Extensiones del asistente y de Empleados, y una clave falsa: este archivo no sale a la red.
     *
     * @return void
     */
    protected function preparar_asistente()
    {
        // 🔴 Nunca la clave real del .env.testing.
        config(['services.anthropic.api_key' => 'clave-de-prueba-empleados-3']);

        $this->dar_extension('asistente_ia');
        $this->dar_extension(PropuestaPermisoEmpleadoIaHelper::EXTENSION);
    }

    /**
     * Un empleado del dueño con la ficha completa y los permisos A y B, con lo que se le cambie.
     *
     * @param  array  $cambios
     * @return User
     */
    protected function empleado_del_asistente(array $cambios = [])
    {
        $empleado = User::create(array_merge([
            'name'             => 'zz-alta Brisa '.uniqid(),
            'email'            => 'alta-asistente-'.uniqid().'@test.local',
            'doc_number'       => 'DOC-ALTA-ASISTENTE-'.uniqid(),
            'password'         => Hash::make('clave-de-brisa'),
            'visible_password' => 'clave-de-brisa',
            'owner_id'         => $this->owner->id,
        ], $cambios));

        $empleado->permissions()->sync([$this->permisos[0]->id, $this->permisos[1]->id]);

        return $empleado;
    }

    /**
     * Los ids de los permisos A y B, ordenados (lo que tiene el empleado del asistente).
     *
     * @return array<int,int>
     */
    protected function ids_ab()
    {
        $ids = [(int) $this->permisos[0]->id, (int) $this->permisos[1]->id];
        sort($ids);

        return $ids;
    }

    /**
     * Corre la herramienta `proponer_permiso_de_empleado` para sacarle el permiso A.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  User  $empleado
     * @return array  La respuesta de negocio de la herramienta.
     */
    protected function proponer_sacar_el_permiso_a($conversation, $assistant, User $empleado)
    {
        $resultado = HerramientasDeCarga::ejecutar('proponer_permiso_de_empleado', [
            'empleado' => $empleado->name,
            'permiso'  => $this->permisos[0]->slug,
            'accion'   => 'sacar',
        ], $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: '.$resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * Confirma la tarjeta por HTTP, como el botón del chat.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  int  $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/'.$conversation->id.'/acciones/'.$tarjeta_id.'/confirmar');
    }

    /**
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $this->owner->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->owner->load('extencions');

        $this->extensiones_enganchadas[] = $extencion;
    }

    /**
     * Una conversación del dueño con el pedido del usuario y el mensaje del asistente que propone.
     * Mismo armado que `Tests\Feature\ChatIa\Cheque_y_permisos_de_empleado_Test`.
     *
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->owner->id,
            'auth_user_id' => $this->owner->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Vuelve a autenticar al dueño en el guard `web` después de una request HTTP: sanctum queda como
     * guard por defecto.
     *
     * @return void
     */
    protected function actuar_como_el_dueno()
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->owner, 'web');
    }
}
