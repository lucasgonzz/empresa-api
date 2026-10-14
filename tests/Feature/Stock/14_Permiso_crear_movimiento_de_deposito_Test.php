<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Helpers\DepositMovementHelper;
use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Models\DepositMovement;
use App\Models\DepositMovementModification;
use App\Models\DepositMovementStatus;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Misión permiso-crear-movimiento-deposito (14/10/2026) — `deposit_movement.store` ("Crear
 * movimientos de depósito") deja de ser un permiso sin efecto.
 *
 * Hasta esta misión `DepositMovementController::store()` no chequeaba nada: cualquier empleado que
 * veía los movimientos los creaba. Lucas pidió un permiso para CREAR y otro para EDITAR LOS
 * ARTÍCULOS de un movimiento (este último ya existía desde la 4.3.6: `update_articles`). Lo que
 * estos tests custodian, por endpoint real y con artículos propios ("zz"):
 *
 *  - Sin `deposit_movement.store` → 403 `{"message": ...}` (sin clave `errors`: la SPA lo muestra
 *    como toast), ANTES de validar el estado y de escribir: no queda ninguna fila en
 *    `deposit_movements`, ni en el pivot de artículos, ni en las modificaciones.
 *  - Con SOLO `store` → crea el movimiento con sus artículos (el permiso cubre el alta entera:
 *    depósitos, estado, empleado, notas y artículos) y NO deja modificaciones (crear no es editar).
 *  - Los tres permisos son independientes: quien tiene `store` sin `update_articles` crea con
 *    artículos pero después no puede cambiarlos; quien tiene `update` + `update_articles` sin
 *    `store` no puede crear.
 *  - El dueño y los empleados con `admin_access` crean sin tener el slug tildado.
 *
 * El otro camino que crea movimientos (las sugerencias de stock) se prueba en
 * `Tests\Feature\SugerenciasStock\10_Permiso_crear_movimiento_desde_sugerencias_Test`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Permiso_crear_movimiento_de_deposito_Test extends AuditoriaStockTestCase
{
    /** @var \App\Models\Address Depósito de origen de los movimientos del test. */
    protected $origen;

    /** @var \App\Models\Address Depósito de destino de los movimientos del test. */
    protected $destino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->origen = $this->sucursal();
        $this->destino = $this->segunda_sucursal();
    }

    // ------------------------------------------------------------------------------------------
    // Helpers (los mismos del test 13, sin tocar la base compartida de la auditoría)
    // ------------------------------------------------------------------------------------------

    /**
     * Cambia el usuario autenticado para las requests que siguen.
     *
     * 🔴 El `Auth::forgetGuards()` no es decorativo: las rutas viven bajo `auth:sanctum`, cuyo
     * guard cachea el usuario que resolvió la primera vez. Sin olvidarlo, el segundo `actingAs()`
     * no cambia nada.
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * El permiso del catálogo con ese slug; si la base de testing no lo tiene, lo crea con el
     * grupo y el nombre del catálogo. `forceCreate` porque `PermissionEmpresa` no declara
     * `$fillable`.
     *
     * @param string $slug
     * @return \App\Models\PermissionEmpresa
     */
    protected function permiso($slug)
    {
        $permiso = PermissionEmpresa::where('slug', $slug)->first();

        if (is_null($permiso)) {

            $filas = PermisosCatalogoHelper::filas();

            $permiso = PermissionEmpresa::forceCreate([
                'slug'       => $slug,
                'name'       => isset($filas[$slug]) ? $filas[$slug]['nombre'] : $slug,
                'model_name' => isset($filas[$slug]) ? $filas[$slug]['grupo'] : 'Stock y depósitos',
            ]);
        }

        return $permiso;
    }

    /**
     * Un empleado del dueño del fixture con exactamente estos permisos.
     *
     * @param string $nombre
     * @param array $slugs
     * @param int $admin_access  1 para un empleado con acceso de administrador.
     * @return \App\Models\User
     */
    protected function crear_empleado($nombre, $slugs = [], $admin_access = 0)
    {
        $empleado = User::create([
            'name'         => $nombre,
            'company_name' => 'zz Ferreteria permiso crear movimiento',
            'email'        => 'zz-crear-movimiento-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->usuario()->id,
            'admin_access' => $admin_access,
        ]);

        $ids = [];
        foreach ($slugs as $slug) {
            $ids[] = $this->permiso($slug)->id;
        }

        if (count($ids) > 0) {
            $empleado->permissions()->attach($ids);
        }

        return $empleado->fresh();
    }

    /**
     * Le carga stock a un depósito por el endpoint real, como el modal de crear depósitos. Se usa
     * como el dueño del fixture (el stock de partida no es parte de lo que se prueba).
     *
     * @param \App\Models\Article $articulo
     * @param \App\Models\Address $address
     * @param float $cantidad
     * @return void
     */
    protected function cargar_deposito($articulo, $address, $cantidad)
    {
        $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo->id,
            'amount'                       => $cantidad,
            'to_address_id'                => $address->id,
            'concepto_stock_movement_name' => 'Creacion de deposito',
        ])->assertStatus(201);
    }

    /**
     * Estado fijo del sistema ("En proceso" o "Recibido"). Si la base de testing no lo tiene
     * sembrado lo crea: `DepositMovementStatusSeeder` es idempotente y el fixture de testing solo
     * lo corre cuando la tabla está vacía, así que una base armada sin él no puede hacer fallar un
     * test que habla de PERMISOS. `DatabaseTransactions` revierte la fila al terminar.
     *
     * @param string $nombre
     * @return \App\Models\DepositMovementStatus
     */
    protected function estado_fijo($nombre)
    {
        return DepositMovementStatus::firstOrCreate(['name' => $nombre, 'user_id' => null]);
    }

    /**
     * El payload de un movimiento con la forma que manda la SPA.
     *
     * @param array $items  Cada uno: [Article, cantidad].
     * @param array $extra  Claves a pisar.
     * @return array
     */
    protected function payload_movimiento($items, $extra = [])
    {
        $articles = [];

        foreach ($items as $item) {
            $articles[] = [
                'id'    => $item[0]->id,
                'pivot' => ['amount' => $item[1], 'article_variant_id' => null],
            ];
        }

        return array_merge([
            'from_address_id'            => $this->origen->id,
            'to_address_id'              => $this->destino->id,
            'deposit_movement_status_id' => $this->estado_fijo('En proceso')->id,
            'employee_id'                => null,
            'recibido_at'                => null,
            'notes'                      => 'zz movimiento del permiso de crear',
            'articles'                   => $articles,
        ], $extra);
    }

    /**
     * Foto de cuántas filas hay en las tres tablas que un alta puede escribir: los movimientos, el
     * pivot de artículos y las modificaciones. Sirve para probar que un 403 no dejó NADA, sin
     * depender de ids.
     *
     * @return array
     */
    protected function conteo_de_tablas()
    {
        return [
            'movimientos'    => DepositMovement::count(),
            'pivot'          => DB::table('article_deposit_movement')->count(),
            'modificaciones' => DepositMovementModification::count(),
        ];
    }

    /**
     * Artículos del movimiento leídos del pivot: `[article_id => cantidad]`.
     *
     * @param int $deposit_movement_id
     * @return array
     */
    protected function pivot($deposit_movement_id)
    {
        $resultado = [];

        $filas = DB::table('article_deposit_movement')
                    ->where('deposit_movement_id', $deposit_movement_id)
                    ->orderBy('article_id')
                    ->get();

        foreach ($filas as $fila) {
            $resultado[(int) $fila->article_id] = (float) $fila->amount;
        }

        return $resultado;
    }

    // ------------------------------------------------------------------------------------------
    // 1. Sin el permiso: 403 y ninguna fila
    // ------------------------------------------------------------------------------------------

    /**
     * El caso que motivó la misión: un empleado que VE los movimientos (`article.index`) pero no
     * tiene `deposit_movement.store`. El POST da 403, con el mensaje de la constante y sin clave
     * `errors`, y no queda nada escrito.
     *
     * @group stock
     * @return void
     */
    public function test_un_empleado_sin_el_permiso_no_crea_y_no_deja_ninguna_fila()
    {
        $articulo = $this->crear_articulo('zz Crear mov sin permiso');

        $empleado = $this->crear_empleado('zz Empleado que ve pero no crea', ['article.index']);
        $this->actuar_como($empleado);

        $antes = $this->conteo_de_tablas();

        $respuesta = $this->postJson('api/deposit-movement', $this->payload_movimiento([[$articulo, 4]]));

        $respuesta->assertStatus(403);
        $this->assertSame(DepositMovementHelper::MENSAJE_SIN_PERMISO_PARA_CREAR, $respuesta->json('message'));
        $this->assertSame('No tenés permiso para crear movimientos de depósito.', $respuesta->json('message'));
        $this->assertNull($respuesta->json('errors'), 'El 403 no lleva clave `errors`: la SPA la mostraría como errores de formulario.');

        $this->assertEquals($antes, $this->conteo_de_tablas(), 'Un 403 no puede dejar ni el movimiento, ni sus artículos, ni modificaciones.');
    }

    /**
     * El chequeo va ANTES de validar el estado: con un estado inválido un empleado sin permiso
     * recibe 403 y no el 422 de la validación (no tiene por qué enterarse de nada más).
     *
     * @group stock
     * @return void
     */
    public function test_el_403_va_antes_de_validar_el_estado()
    {
        $articulo = $this->crear_articulo('zz Crear mov 403 antes que 422');

        $empleado = $this->crear_empleado('zz Empleado sin permiso estado raro', ['article.index']);
        $this->actuar_como($empleado);

        $antes = $this->conteo_de_tablas();

        $respuesta = $this->postJson('api/deposit-movement', $this->payload_movimiento([[$articulo, 1]], [
            'deposit_movement_status_id' => 99999999,
        ]));

        $respuesta->assertStatus(403);
        $this->assertEquals($antes, $this->conteo_de_tablas());

        // Con el permiso, ese mismo estado sí llega a la validación y da el 422 de siempre.
        $con_permiso = $this->crear_empleado('zz Empleado con permiso estado raro', ['deposit_movement.store']);
        $this->actuar_como($con_permiso);

        $this->postJson('api/deposit-movement', $this->payload_movimiento([[$articulo, 1]], [
            'deposit_movement_status_id' => 99999999,
        ]))->assertStatus(422);

        $this->assertEquals($antes, $this->conteo_de_tablas(), 'El 422 tampoco puede dejar filas.');
    }

    /**
     * Los permisos son independientes: tener los de EDITAR (datos y artículos) y el de mover stock
     * no habilita CREAR. Es el caso del empleado que hoy edita movimientos y que el dueño tiene que
     * poder dejar sin alta.
     *
     * @group stock
     * @return void
     */
    public function test_editar_y_mover_stock_no_habilitan_crear()
    {
        $articulo = $this->crear_articulo('zz Crear mov solo edita');

        $empleado = $this->crear_empleado('zz Empleado edita pero no crea', [
            'article.index',
            'deposit_movement.update',
            'deposit_movement.update_articles',
            'deposit_movement.move_stock',
        ]);
        $this->actuar_como($empleado);

        $antes = $this->conteo_de_tablas();

        $this->postJson('api/deposit-movement', $this->payload_movimiento([[$articulo, 4]]))
            ->assertStatus(403);

        $this->assertEquals($antes, $this->conteo_de_tablas());
    }

    // ------------------------------------------------------------------------------------------
    // 2. Con el permiso: crea con sus artículos y sin modificaciones
    // ------------------------------------------------------------------------------------------

    /**
     * Con SOLO `deposit_movement.store` el alta anda de punta a punta: 201, el movimiento con sus
     * depósitos, estado, empleado y notas, y los artículos del alta en el pivot. Crear no es editar:
     * no queda ninguna `DepositMovementModification`. Y no mueve stock.
     *
     * @group stock
     * @return void
     */
    public function test_con_solo_el_permiso_de_crear_el_alta_anda_con_sus_articulos()
    {
        $tornillo = $this->crear_articulo('zz Crear mov tornillo');
        $tuerca = $this->crear_articulo('zz Crear mov tuerca');
        $this->cargar_deposito($tornillo, $this->origen, 10);

        $stock_antes = $this->stock($tornillo);

        $empleado = $this->crear_empleado('zz Empleado que solo crea', ['article.index', 'deposit_movement.store']);
        $this->actuar_como($empleado);

        $respuesta = $this->postJson('api/deposit-movement', $this->payload_movimiento([[$tornillo, 4], [$tuerca, 2]], [
            'employee_id' => $empleado->id,
            'notes'       => 'zz alta del empleado con permiso',
        ]));

        $respuesta->assertStatus(201);

        $movimiento = DepositMovement::find($respuesta->json('model.id'));
        $this->assertNotNull($movimiento, 'El POST no dejó el movimiento.');

        // Es del comercio del dueño y lleva todo lo del alta.
        $this->assertEquals($this->usuario()->id, (int) $movimiento->user_id);
        $this->assertEquals($this->origen->id, (int) $movimiento->from_address_id);
        $this->assertEquals($this->destino->id, (int) $movimiento->to_address_id);
        $this->assertEquals($empleado->id, (int) $movimiento->employee_id);
        $this->assertSame('zz alta del empleado con permiso', $movimiento->notes);

        $this->assertEquals(
            [$tornillo->id => 4.0, $tuerca->id => 2.0],
            $this->pivot($movimiento->id),
            'El permiso de crear incluye los artículos del alta.'
        );

        $this->assertEquals(0, DepositMovementModification::where('deposit_movement_id', $movimiento->id)->count(), 'Crear no es una modificación.');

        // Crear nunca mueve stock.
        $this->assertEquals($stock_antes, $this->stock($tornillo));
        $this->assertNull($movimiento->fresh()->stock_moved_at);
    }

    /**
     * Quien tiene `store` pero no `update_articles` crea con artículos y después NO puede cambiarlos:
     * el PUT con otra cantidad da 403 y el pivot queda como estaba. Las dos variantes de ese empleado
     * (sin ningún permiso de edición, y con solo el de editar datos) tienen su propio mensaje.
     *
     * @group stock
     * @return void
     */
    public function test_crear_con_articulos_no_da_derecho_a_cambiarlos_despues()
    {
        $articulo = $this->crear_articulo('zz Crear mov no edita articulos');

        // a) Solo `store`: no tiene ninguno de los dos permisos de edición.
        $solo_crea = $this->crear_empleado('zz Empleado solo crea y no edita', ['article.index', 'deposit_movement.store']);
        $this->actuar_como($solo_crea);

        $creado = $this->postJson('api/deposit-movement', $this->payload_movimiento([[$articulo, 4]]));
        $creado->assertStatus(201);
        $movimiento_id = (int) $creado->json('model.id');

        $respuesta = $this->putJson('api/deposit-movement/'.$movimiento_id, $this->payload_movimiento([[$articulo, 9]]));
        $respuesta->assertStatus(403);
        $this->assertSame('No tenés permiso para editar movimientos de depósito.', $respuesta->json('message'));

        // b) `store` + `update` (datos), sin `update_articles`: puede cambiar las notas pero no los artículos.
        $crea_y_edita_datos = $this->crear_empleado('zz Empleado crea y edita datos', [
            'article.index',
            'deposit_movement.store',
            'deposit_movement.update',
        ]);
        $this->actuar_como($crea_y_edita_datos);

        $respuesta = $this->putJson('api/deposit-movement/'.$movimiento_id, $this->payload_movimiento([[$articulo, 9]], [
            'notes' => 'zz notas cambiadas',
        ]));
        $respuesta->assertStatus(403);
        $this->assertSame('No tenés permiso para cambiar los artículos de un movimiento de depósito.', $respuesta->json('message'));

        $this->assertEquals([$articulo->id => 4.0], $this->pivot($movimiento_id), 'Sin `update_articles` el pivot no se toca.');
        $this->assertEquals(0, DepositMovementModification::where('deposit_movement_id', $movimiento_id)->count());

        // Y el mismo empleado sí cambia las notas reenviando los MISMOS artículos.
        $this->putJson('api/deposit-movement/'.$movimiento_id, $this->payload_movimiento([[$articulo, 4]], [
            'notes' => 'zz notas cambiadas',
        ]))->assertStatus(200);

        $this->assertSame('zz notas cambiadas', DB::table('deposit_movements')->where('id', $movimiento_id)->value('notes'));
        $this->assertEquals(0, DepositMovementModification::where('deposit_movement_id', $movimiento_id)->count());
    }

    // ------------------------------------------------------------------------------------------
    // 3. Dueño y admin_access: pueden todo sin tener el slug
    // ------------------------------------------------------------------------------------------

    /**
     * El dueño y el empleado con `admin_access` crean sin tener `deposit_movement.store` tildado
     * (mismo criterio que el resto de los permisos de este controller).
     *
     * @group stock
     * @return void
     */
    public function test_el_duenio_y_el_empleado_con_admin_access_crean_sin_el_permiso()
    {
        $articulo = $this->crear_articulo('zz Crear mov duenio y admin');

        // El dueño del fixture.
        $this->actuar_como($this->usuario());

        $this->postJson('api/deposit-movement', $this->payload_movimiento([[$articulo, 1]]))
            ->assertStatus(201);

        // Un empleado con acceso de administrador y sin ningún permiso tildado.
        $admin = $this->crear_empleado('zz Empleado con acceso de administrador', [], 1);
        $this->assertEquals(0, $admin->permissions()->count());
        $this->actuar_como($admin);

        $respuesta = $this->postJson('api/deposit-movement', $this->payload_movimiento([[$articulo, 2]]));
        $respuesta->assertStatus(201);

        $this->assertEquals([$articulo->id => 2.0], $this->pivot($respuesta->json('model.id')));
    }
}
