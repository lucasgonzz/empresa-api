<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Models\Address;
use App\Models\Brand;
use App\Models\DepositMovement;
use App\Models\DepositMovementModification;
use App\Models\DepositMovementStatus;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Misión movimientos-deposito-auditoria (3/10/2026) — "Mover stock", bloqueo, auditoría de
 * artículos, permisos y estados configurables de los movimientos de depósito.
 *
 * Lo que Lucas pidió y estos tests custodian, por endpoint real y con artículos propios ("zz"):
 *
 *  - El stock NO se mueve al crear el movimiento ni al cambiarle el estado (ni siquiera a
 *    "Recibido"). Se mueve con `POST deposit-movement/{id}/move-stock`, que registra quién y
 *    cuándo, y una sola vez.
 *  - Con el stock movido, los artículos y los depósitos quedan bloqueados y el movimiento no se
 *    puede eliminar; estado y notas siguen editables.
 *  - Cada cambio de artículos de un movimiento ya creado queda como una modificación (quién,
 *    cuándo, foto antes y después). Reenviar los mismos artículos no es una modificación.
 *  - Permisos: `deposit_movement.update` (datos), `deposit_movement.update_articles` (artículos)
 *    y `deposit_movement.move_stock`. El dueño puede todo.
 *  - Estados: los fijos ("En proceso", "Recibido", `user_id` NULL) + los propios de cada comercio.
 *  - `en_curso` (alertas del empleado) = los suyos cuyo stock todavía no se movió.
 *
 * Ajustes tras el chequeo y la verificación en vivo (3/10/2026), casos 11 a 16:
 *  - el buscador del ABM (`global-search` / `search`) trae los estados fijos + los propios, y
 *    cualquier otro modelo con `user_id` sigue filtrando como siempre;
 *  - "dos frentes": `recibido_at` (la marca de la versión anterior) también cuenta como stock
 *    movido, "Mover stock" la llena, y el request ya no la escribe;
 *  - el estado de un movimiento tiene que ser fijo o propio; `show` filtra por dueño; "Mover
 *    stock" pide los dos depósitos.
 *  - caso 17: el asistente (consulta genérica de datos) también ve los estados fijos: lista fijos +
 *    propios y encuentra un movimiento por el estado fijo "Recibido".
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Movimientos_de_deposito_mover_stock_y_auditoria_Test extends AuditoriaStockTestCase
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
    // Helpers
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
     * Vuelve al dueño del fixture.
     *
     * @return void
     */
    protected function actuar_como_duenio()
    {
        $this->actuar_como($this->usuario());
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
     * Un empleado del dueño del fixture, sin admin_access, con exactamente estos permisos.
     *
     * @param string $nombre
     * @param array $slugs
     * @return \App\Models\User
     */
    protected function crear_empleado($nombre, $slugs = [])
    {
        $empleado = User::create([
            'name'         => $nombre,
            'company_name' => 'zz Ferreteria movimientos',
            'email'        => 'zz-movimientos-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->usuario()->id,
            'admin_access' => 0,
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
     * Le carga stock a un depósito por el endpoint real, como el modal de crear depósitos.
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
     * Estado fijo del sistema por nombre ("En proceso" o "Recibido").
     *
     * @param string $nombre
     * @return \App\Models\DepositMovementStatus
     */
    protected function estado_fijo($nombre)
    {
        $estado = DepositMovementStatus::whereNull('user_id')->where('name', $nombre)->orderBy('id')->first();

        $this->assertNotNull($estado, 'El fixture no tiene el estado fijo "'.$nombre.'".');

        return $estado;
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
            'notes'                      => 'zz movimiento de auditoria',
            'articles'                   => $articles,
        ], $extra);
    }

    /**
     * Crea el movimiento por el endpoint real (como el usuario autenticado) y lo devuelve.
     *
     * @param array $items
     * @param array $extra
     * @return \App\Models\DepositMovement
     */
    protected function crear_movimiento($items, $extra = [])
    {
        $respuesta = $this->postJson('api/deposit-movement', $this->payload_movimiento($items, $extra));

        $respuesta->assertStatus(201);

        $movimiento = DepositMovement::find($respuesta->json('model.id'));

        $this->assertNotNull($movimiento, 'El POST no dejó el movimiento.');

        return $movimiento;
    }

    /**
     * PUT del movimiento con la forma que manda la SPA.
     *
     * @param \App\Models\DepositMovement $movimiento
     * @param array $items
     * @param array $extra
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar_movimiento($movimiento, $items, $extra = [])
    {
        return $this->putJson('api/deposit-movement/'.$movimiento->id, $this->payload_movimiento($items, $extra));
    }

    /**
     * @param \App\Models\DepositMovement $movimiento
     * @return \Illuminate\Testing\TestResponse
     */
    protected function mover_stock($movimiento)
    {
        return $this->postJson('api/deposit-movement/'.$movimiento->id.'/move-stock');
    }

    /**
     * Artículos del movimiento leídos del pivot: `[article_id => cantidad]`.
     *
     * @param \App\Models\DepositMovement $movimiento
     * @return array
     */
    protected function pivot($movimiento)
    {
        return $this->filas_de_pivot('article_deposit_movement', 'deposit_movement_id', $movimiento->id);
    }

    /**
     * Filas de un pivot de artículos como `[article_id => cantidad]`, ordenadas por artículo.
     *
     * @param string $tabla
     * @param string $columna
     * @param int $id
     * @return array
     */
    protected function filas_de_pivot($tabla, $columna, $id)
    {
        $resultado = [];

        $filas = DB::table($tabla)->where($columna, $id)->orderBy('article_id')->get();

        foreach ($filas as $fila) {
            $resultado[(int) $fila->article_id] = (float) $fila->amount;
        }

        return $resultado;
    }

    /**
     * @param \App\Models\DepositMovement $movimiento
     * @return int
     */
    protected function cantidad_de_modificaciones($movimiento)
    {
        return DepositMovementModification::where('deposit_movement_id', $movimiento->id)->count();
    }

    // ------------------------------------------------------------------------------------------
    // 1. Crear no mueve stock
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function crear_un_movimiento_aunque_sea_recibido_no_mueve_stock()
    {
        $articulo = $this->crear_articulo('zz Mov dep crear no mueve');
        $this->cargar_deposito($articulo, $this->origen, 10);

        $movimiento = $this->crear_movimiento([[$articulo, 4]], [
            'deposit_movement_status_id' => $this->estado_fijo('Recibido')->id,
        ]);

        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $this->origen->id), 'Crear el movimiento no puede sacar stock del origen.');
        $this->assertNull($this->stock_en_deposito($articulo, $this->destino->id), 'Crear el movimiento no puede poner stock en el destino.');
        $this->assertEquals(0, $this->movimientos($articulo, 'Mov entre depositos')->count());
        $this->assertNull(DB::table('deposit_movements')->where('id', $movimiento->id)->value('stock_moved_at'));

        // Crear no es una modificación: la cuenta arranca en 0.
        $this->getJson('api/deposit-movement/'.$movimiento->id)
            ->assertStatus(200)
            ->assertJsonPath('model.deposit_movement_modifications_count', 0)
            ->assertJsonPath('model.stock_moved_at', null);
    }

    // ------------------------------------------------------------------------------------------
    // 2. Mover stock traslada y registra quién y cuándo
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function mover_stock_traslada_y_registra_quien_y_cuando()
    {
        $tornillo = $this->crear_articulo('zz Mov dep mover tornillo');
        $tuerca = $this->crear_articulo('zz Mov dep mover tuerca');

        $this->cargar_deposito($tornillo, $this->origen, 10);
        $this->cargar_deposito($tuerca, $this->origen, 5);

        $en_proceso = $this->estado_fijo('En proceso');

        $movimiento = $this->crear_movimiento([[$tornillo, 4], [$tuerca, 2]]);

        $empleado = $this->crear_empleado('zz Empleado que mueve stock', ['deposit_movement.move_stock']);
        $this->actuar_como($empleado);

        $respuesta = $this->mover_stock($movimiento);
        $respuesta->assertStatus(200);

        $this->assertEquals(6.0, $this->stock_en_deposito($tornillo, $this->origen->id));
        $this->assertEquals(4.0, $this->stock_en_deposito($tornillo, $this->destino->id));
        $this->assertEquals(3.0, $this->stock_en_deposito($tuerca, $this->origen->id));
        $this->assertEquals(2.0, $this->stock_en_deposito($tuerca, $this->destino->id));

        $this->assertEquals(10.0, $this->stock($tornillo), 'Un traslado no cambia el stock global.');
        $this->assertEquals(5.0, $this->stock($tuerca), 'Un traslado no cambia el stock global.');

        foreach ([$tornillo, $tuerca] as $articulo) {
            $traslados = $this->movimientos($articulo, 'Mov entre depositos');
            $this->assertCount(1, $traslados, 'Un "Mov entre depositos" por artículo.');
            $this->assertEquals($movimiento->id, (int) $traslados->first()->deposit_movement_id);
            $this->assertEquals($empleado->id, (int) $traslados->first()->employee_id, 'El movimiento de stock lo firma quien apretó el botón.');
        }

        $fila = DB::table('deposit_movements')->where('id', $movimiento->id)->first();
        $this->assertNotNull($fila->stock_moved_at, 'Tiene que quedar CUÁNDO se movió el stock.');
        $this->assertEquals($empleado->id, (int) $fila->stock_moved_user_id, 'Tiene que quedar QUIÉN movió el stock.');
        $this->assertEquals($en_proceso->id, (int) $fila->deposit_movement_status_id, 'Mover el stock no cambia el estado.');

        $respuesta->assertJsonPath('model.stock_moved_user.name', 'zz Empleado que mueve stock');
        $respuesta->assertJsonPath('model.stock_moved_user_id', $empleado->id);
    }

    // ------------------------------------------------------------------------------------------
    // 3. Un segundo "Mover stock" se rechaza
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function un_segundo_mover_stock_se_rechaza_sin_mover_nada()
    {
        $articulo = $this->crear_articulo('zz Mov dep doble clic');
        $this->cargar_deposito($articulo, $this->origen, 10);

        $movimiento = $this->crear_movimiento([[$articulo, 4]]);

        $this->mover_stock($movimiento)->assertStatus(200);

        $segundo = $this->mover_stock($movimiento);
        $segundo->assertStatus(422);
        $this->assertStringContainsString('ya se movió el', $segundo->json('message'));
        $this->assertArrayNotHasKey('errors', $segundo->json(), 'La SPA muestra data.message: sin clave errors.');

        $this->assertEquals(6.0, $this->stock_en_deposito($articulo, $this->origen->id));
        $this->assertEquals(4.0, $this->stock_en_deposito($articulo, $this->destino->id));
        $this->assertEquals(1, $this->movimientos($articulo, 'Mov entre depositos')->count());
    }

    // ------------------------------------------------------------------------------------------
    // 4. Con el stock movido: bloqueos
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function con_el_stock_movido_se_bloquean_articulos_depositos_y_borrado()
    {
        $articulo = $this->crear_articulo('zz Mov dep bloqueo');
        $otro = $this->crear_articulo('zz Mov dep bloqueo otro');
        $this->cargar_deposito($articulo, $this->origen, 10);

        $movimiento = $this->crear_movimiento([[$articulo, 4]]);
        $this->mover_stock($movimiento)->assertStatus(200);

        // Cambiar una cantidad → 422 y el pivot intacto.
        $this->actualizar_movimiento($movimiento, [[$articulo, 5]])->assertStatus(422);
        $this->assertEquals([$articulo->id => 4.0], $this->pivot($movimiento));

        // Agregar un artículo → 422 y el pivot intacto.
        $this->actualizar_movimiento($movimiento, [[$articulo, 4], [$otro, 1]])->assertStatus(422);
        $this->assertEquals([$articulo->id => 4.0], $this->pivot($movimiento));

        // Cambiar solo estado y notas → 200, y no mueve nada.
        $recibido = $this->estado_fijo('Recibido');

        $this->actualizar_movimiento($movimiento, [[$articulo, 4]], [
            'deposit_movement_status_id' => $recibido->id,
            'notes'                      => 'zz recibido en la sucursal',
        ])->assertStatus(200);

        $fila = DB::table('deposit_movements')->where('id', $movimiento->id)->first();
        $this->assertEquals($recibido->id, (int) $fila->deposit_movement_status_id);
        $this->assertEquals('zz recibido en la sucursal', $fila->notes);
        $this->assertEquals(6.0, $this->stock_en_deposito($articulo, $this->origen->id), 'Pasar a Recibido no mueve stock.');
        $this->assertEquals(1, $this->movimientos($articulo, 'Mov entre depositos')->count());

        // Cambiar el depósito de destino → 422 y queda el original.
        $tercera = Address::create([
            'street'          => 'zz Deposito tercero movimientos',
            'user_id'         => $this->usuario()->id,
            'default_address' => 0,
        ]);

        $this->actualizar_movimiento($movimiento, [[$articulo, 4]], [
            'deposit_movement_status_id' => $recibido->id,
            'notes'                      => 'zz recibido en la sucursal',
            'to_address_id'              => $tercera->id,
        ])->assertStatus(422);

        $this->assertEquals($this->destino->id, (int) DB::table('deposit_movements')->where('id', $movimiento->id)->value('to_address_id'));

        // Eliminar → 422 y el movimiento sigue.
        $this->deleteJson('api/deposit-movement/'.$movimiento->id)->assertStatus(422);
        $this->assertNotNull(DepositMovement::find($movimiento->id), 'Un movimiento con el stock movido no se borra.');

        $this->assertEquals(0, $this->cantidad_de_modificaciones($movimiento), 'Ningún intento rechazado deja modificación.');
    }

    // ------------------------------------------------------------------------------------------
    // 5. Empleado con solo "Editar movimientos" (datos)
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function empleado_con_solo_editar_datos_cambia_el_estado_pero_no_los_articulos()
    {
        $articulo = $this->crear_articulo('zz Mov dep solo datos');
        $this->cargar_deposito($articulo, $this->origen, 10);

        $movimiento = $this->crear_movimiento([[$articulo, 4]]);

        $recibido = $this->estado_fijo('Recibido');

        $empleado = $this->crear_empleado('zz Empleado solo datos', ['deposit_movement.update']);
        $this->actuar_como($empleado);

        $this->actualizar_movimiento($movimiento, [[$articulo, 4]], [
            'deposit_movement_status_id' => $recibido->id,
        ])->assertStatus(200);

        $this->assertEquals($recibido->id, (int) DB::table('deposit_movements')->where('id', $movimiento->id)->value('deposit_movement_status_id'));

        $respuesta = $this->actualizar_movimiento($movimiento, [[$articulo, 7]], [
            'deposit_movement_status_id' => $recibido->id,
        ]);
        $respuesta->assertStatus(403);
        $this->assertSame('No tenés permiso para cambiar los artículos de un movimiento de depósito.', $respuesta->json('message'));

        $this->assertEquals([$articulo->id => 4.0], $this->pivot($movimiento), 'Sin el permiso de artículos el pivot no se toca.');
        $this->assertEquals(0, $this->cantidad_de_modificaciones($movimiento));
    }

    // ------------------------------------------------------------------------------------------
    // 6. Empleado con "Editar los artículos": modificación con foto antes/después
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function empleado_con_editar_articulos_cambia_agrega_y_quita_y_queda_una_modificacion()
    {
        $tornillo = $this->crear_articulo('zz Mov dep audit tornillo');
        $tuerca = $this->crear_articulo('zz Mov dep audit tuerca');
        $arandela = $this->crear_articulo('zz Mov dep audit arandela');

        $movimiento = $this->crear_movimiento([[$tornillo, 4], [$tuerca, 2]]);

        $empleado = $this->crear_empleado('zz Empleado edita articulos', ['deposit_movement.update_articles']);
        $this->actuar_como($empleado);

        // Tornillo de 4 a 5, se quita la tuerca, se agrega la arandela. Los datos van iguales a
        // los guardados (la SPA manda el modelo entero): eso no pide el permiso de datos.
        $this->actualizar_movimiento($movimiento, [[$tornillo, 5], [$arandela, 3]])->assertStatus(200);

        $this->assertEquals([$tornillo->id => 5.0, $arandela->id => 3.0], $this->pivot($movimiento));

        $modificaciones = DepositMovementModification::where('deposit_movement_id', $movimiento->id)->get();
        $this->assertCount(1, $modificaciones, 'Un PUT que cambia artículos deja UNA modificación.');

        $modificacion = $modificaciones->first();
        $this->assertEquals($empleado->id, (int) $modificacion->user_id, 'La modificación la firma el empleado.');

        $this->assertEquals(
            [$tornillo->id => 4.0, $tuerca->id => 2.0],
            $this->filas_de_pivot('article_deposit_movement_modification_antes', 'deposit_movement_modification_id', $modificacion->id),
            'Foto de ANTES.'
        );
        $this->assertEquals(
            [$tornillo->id => 5.0, $arandela->id => 3.0],
            $this->filas_de_pivot('article_deposit_movement_modification_despues', 'deposit_movement_modification_id', $modificacion->id),
            'Foto de DESPUÉS.'
        );

        // El show trae la cuenta para el botón "Modificaciones (N)".
        $this->getJson('api/deposit-movement/'.$movimiento->id)
            ->assertStatus(200)
            ->assertJsonPath('model.deposit_movement_modifications_count', 1);

        // El historial.
        $historial = $this->getJson('api/deposit-movement-modifications/'.$movimiento->id);
        $historial->assertStatus(200);
        $historial->assertJsonCount(1, 'models');
        $historial->assertJsonPath('models.0.user.name', 'zz Empleado edita articulos');

        $antes = collect($historial->json('models.0.articulos_antes'))->pluck('pivot.amount', 'id')->map(function ($amount) {
            return (float) $amount;
        })->sortKeys()->all();
        $despues = collect($historial->json('models.0.articulos_despues'))->pluck('pivot.amount', 'id')->map(function ($amount) {
            return (float) $amount;
        })->sortKeys()->all();

        $this->assertEquals([$tornillo->id => 4.0, $tuerca->id => 2.0], $antes);
        $this->assertEquals([$tornillo->id => 5.0, $arandela->id => 3.0], $despues);

        // El mismo empleado no puede cambiar las notas (no tiene el permiso de datos).
        $this->actualizar_movimiento($movimiento, [[$tornillo, 5], [$arandela, 3]], [
            'notes' => 'zz nota que no tiene que entrar',
        ])->assertStatus(403);
        $this->assertEquals('zz movimiento de auditoria', DB::table('deposit_movements')->where('id', $movimiento->id)->value('notes'));
        $this->assertEquals(1, $this->cantidad_de_modificaciones($movimiento));

        // El historial de un movimiento de otro comercio no se lee.
        $ajeno = DepositMovement::create([
            'num'                        => 1,
            'from_address_id'            => 1,
            'to_address_id'              => 2,
            'deposit_movement_status_id' => $this->estado_fijo('En proceso')->id,
            'user_id'                    => $this->usuario()->id + 100000,
        ]);
        $this->getJson('api/deposit-movement-modifications/'.$ajeno->id)->assertStatus(404);
    }

    // ------------------------------------------------------------------------------------------
    // 7. Un PUT sin cambios de artículos no es una modificación
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function un_put_sin_cambios_de_articulos_no_crea_modificacion()
    {
        $tornillo = $this->crear_articulo('zz Mov dep sin cambios tornillo');
        $tuerca = $this->crear_articulo('zz Mov dep sin cambios tuerca');

        $movimiento = $this->crear_movimiento([[$tornillo, 4], [$tuerca, 2]]);

        // Los mismos artículos, en otro orden, con las cantidades como strings y la variante vacía:
        // es lo mismo que está guardado.
        $payload = $this->payload_movimiento([], ['notes' => 'zz solo cambia la nota']);
        $payload['articles'] = [
            ['id' => $tuerca->id, 'pivot' => ['amount' => '2', 'article_variant_id' => '']],
            ['id' => $tornillo->id, 'pivot' => ['amount' => '4.00', 'article_variant_id' => 0]],
        ];

        $this->putJson('api/deposit-movement/'.$movimiento->id, $payload)->assertStatus(200);

        $this->assertEquals(0, $this->cantidad_de_modificaciones($movimiento), 'Reenviar los mismos artículos no es una modificación.');
        $this->assertEquals([$tornillo->id => 4.0, $tuerca->id => 2.0], $this->pivot($movimiento));
        $this->assertEquals('zz solo cambia la nota', DB::table('deposit_movements')->where('id', $movimiento->id)->value('notes'));

        // Un PUT que ni siquiera manda `articles` no vacía el movimiento.
        $sin_articulos = $this->payload_movimiento([]);
        unset($sin_articulos['articles']);

        $this->putJson('api/deposit-movement/'.$movimiento->id, $sin_articulos)->assertStatus(200);

        $this->assertEquals([$tornillo->id => 4.0, $tuerca->id => 2.0], $this->pivot($movimiento));
        $this->assertEquals(0, $this->cantidad_de_modificaciones($movimiento));
    }

    // ------------------------------------------------------------------------------------------
    // 8. Sin permisos
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function sin_permisos_mover_stock_y_editar_dan_403()
    {
        $articulo = $this->crear_articulo('zz Mov dep sin permisos');
        $this->cargar_deposito($articulo, $this->origen, 10);

        $movimiento = $this->crear_movimiento([[$articulo, 4]]);

        // Con permiso de editar, pero no el de mover stock.
        $editor = $this->crear_empleado('zz Empleado sin mover stock', ['deposit_movement.update', 'deposit_movement.update_articles']);
        $this->actuar_como($editor);

        $respuesta = $this->mover_stock($movimiento);
        $respuesta->assertStatus(403);
        $this->assertSame('No tenés permiso para mover el stock de un movimiento de depósito.', $respuesta->json('message'));

        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $this->origen->id));
        $this->assertNull(DB::table('deposit_movements')->where('id', $movimiento->id)->value('stock_moved_at'));
        $this->assertEquals(0, $this->movimientos($articulo, 'Mov entre depositos')->count());

        // Sin ningún permiso de edición: 403 aunque no cambie nada.
        $sin_nada = $this->crear_empleado('zz Empleado sin permisos');
        $this->actuar_como($sin_nada);

        $respuesta = $this->actualizar_movimiento($movimiento, [[$articulo, 4]]);
        $respuesta->assertStatus(403);
        $this->assertSame('No tenés permiso para editar movimientos de depósito.', $respuesta->json('message'));

        $this->mover_stock($movimiento)->assertStatus(403);
    }

    // ------------------------------------------------------------------------------------------
    // 9. Estados fijos + propios
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function los_estados_son_fijos_mas_propios_y_los_fijos_no_se_tocan()
    {
        $duenio = $this->usuario();
        $en_proceso = $this->estado_fijo('En proceso');
        $recibido = $this->estado_fijo('Recibido');

        // Un estado propio de OTRO comercio de la misma base.
        $otro_duenio = User::create([
            'name'         => 'zz Otro dueño',
            'company_name' => 'zz Otro comercio',
            'email'        => 'zz-otro-duenio-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);
        $ajeno = DepositMovementStatus::create(['name' => 'zz Estado ajeno', 'user_id' => $otro_duenio->id]);

        // store guarda el user_id del dueño.
        $alta = $this->postJson('api/deposit-movement-status', ['name' => 'zz En el camion']);
        $alta->assertStatus(201);
        $alta->assertJsonPath('model.user_id', $duenio->id);
        $propio_id = $alta->json('model.id');

        // index: fijos primero, después los propios, nunca los de otro dueño.
        $index = $this->getJson('api/deposit-movement-status');
        $index->assertStatus(200);

        $modelos = collect($index->json('models'));
        $ids = $modelos->pluck('id')->all();

        $this->assertContains($en_proceso->id, $ids);
        $this->assertContains($recibido->id, $ids);
        $this->assertContains($propio_id, $ids);
        $this->assertNotContains($ajeno->id, $ids, 'El estado de otro comercio no se ve.');

        $ya_hubo_propio = false;
        foreach ($modelos as $modelo) {
            if (!is_null($modelo['user_id'])) {
                $ya_hubo_propio = true;
                $this->assertEquals($duenio->id, $modelo['user_id']);
            } else {
                $this->assertFalse($ya_hubo_propio, 'Los estados fijos van antes que los propios.');
            }
        }

        // Los fijos no se modifican ni se eliminan.
        $this->putJson('api/deposit-movement-status/'.$recibido->id, ['name' => 'zz Otro nombre'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Los estados En proceso y Recibido vienen con el sistema y no se pueden modificar.');
        $this->assertEquals('Recibido', DepositMovementStatus::find($recibido->id)->name);

        $this->deleteJson('api/deposit-movement-status/'.$en_proceso->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Los estados En proceso y Recibido vienen con el sistema y no se pueden eliminar.');
        $this->assertNotNull(DepositMovementStatus::find($en_proceso->id));

        // Los de otro comercio, para este, no existen.
        $this->putJson('api/deposit-movement-status/'.$ajeno->id, ['name' => 'zz Pisado'])->assertStatus(404);
        $this->deleteJson('api/deposit-movement-status/'.$ajeno->id)->assertStatus(404);
        $this->assertEquals('zz Estado ajeno', DepositMovementStatus::find($ajeno->id)->name);

        // El propio se renombra.
        $this->putJson('api/deposit-movement-status/'.$propio_id, ['name' => 'zz En el camion de reparto'])
            ->assertStatus(200)
            ->assertJsonPath('model.name', 'zz En el camion de reparto');

        // Propio EN USO por un movimiento → 422 y no se borra.
        $articulo = $this->crear_articulo('zz Mov dep estado en uso');
        $this->crear_movimiento([[$articulo, 1]], ['deposit_movement_status_id' => $propio_id]);

        $en_uso = $this->deleteJson('api/deposit-movement-status/'.$propio_id);
        $en_uso->assertStatus(422);
        $this->assertStringContainsString('1 movimiento de depósito', $en_uso->json('message'));
        $this->assertNotNull(DepositMovementStatus::find($propio_id));

        // Propio sin uso → se borra.
        $libre = $this->postJson('api/deposit-movement-status', ['name' => 'zz Estado sin uso']);
        $libre->assertStatus(201);

        $this->deleteJson('api/deposit-movement-status/'.$libre->json('model.id'))->assertStatus(200);
        $this->assertNull(DepositMovementStatus::find($libre->json('model.id')));
    }

    // ------------------------------------------------------------------------------------------
    // 10. en_curso
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function en_curso_devuelve_los_no_movidos_del_empleado()
    {
        $articulo = $this->crear_articulo('zz Mov dep en curso');
        $this->cargar_deposito($articulo, $this->origen, 10);

        $empleado = $this->crear_empleado('zz Empleado en curso', ['deposit_movement.move_stock']);

        $primero = $this->crear_movimiento([[$articulo, 1]], ['employee_id' => $empleado->id]);
        // En "Recibido" pero sin mover: sigue en curso (antes, con el filtro por estado 1, no salía).
        $segundo = $this->crear_movimiento([[$articulo, 2]], [
            'employee_id'                => $empleado->id,
            'deposit_movement_status_id' => $this->estado_fijo('Recibido')->id,
        ]);
        // De otro empleado: no es suyo.
        $this->crear_movimiento([[$articulo, 1]], ['employee_id' => null]);

        $this->actuar_como($empleado);

        $ids = collect($this->getJson('api/deposit-movement-en-curso')->assertStatus(200)->json('models'))
                    ->pluck('id')->sort()->values()->all();
        $this->assertEquals([$primero->id, $segundo->id], $ids);

        $this->mover_stock($primero)->assertStatus(200);

        $ids = collect($this->getJson('api/deposit-movement-en-curso')->assertStatus(200)->json('models'))
                    ->pluck('id')->sort()->values()->all();
        $this->assertEquals([$segundo->id], $ids, 'Con el stock movido deja de estar en curso.');
    }

    // ------------------------------------------------------------------------------------------
    // Ajustes tras el chequeo y la verificación en vivo (3/10/2026)
    // ------------------------------------------------------------------------------------------

    /**
     * Otro comercio (otro dueño) de la misma base.
     *
     * @return \App\Models\User
     */
    protected function otro_duenio()
    {
        return User::create([
            'name'         => 'zz Otro dueño',
            'company_name' => 'zz Otro comercio',
            'email'        => 'zz-otro-duenio-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Simula un movimiento que trasladó la versión ANTERIOR del sistema (el otro frente del
     * cliente, sobre la misma base): pasó a "Recibido", tiene `recibido_at` y no conoce
     * `stock_moved_at`.
     *
     * @param \App\Models\DepositMovement $movimiento
     * @param string $recibido_at
     * @return void
     */
    protected function marcar_como_movido_por_la_version_anterior($movimiento, $recibido_at)
    {
        DB::table('deposit_movements')->where('id', $movimiento->id)->update([
            'recibido_at'                => $recibido_at,
            'deposit_movement_status_id' => $this->estado_fijo('Recibido')->id,
        ]);
    }

    // ------------------------------------------------------------------------------------------
    // 11. El buscador del ABM trae fijos + propios
    // ------------------------------------------------------------------------------------------

    /**
     * Medido en vivo: el ABM de estados lista por `global-search`, y el filtro genérico
     * `user_id = dueño` escondía los fijos (`user_id` NULL). El gancho opt-in
     * `scopeDelDuenoConGlobales` lo arregla SOLO para este modelo.
     *
     * @group stock
     * @test
     */
    public function el_buscador_del_abm_de_estados_trae_fijos_y_propios()
    {
        $en_proceso = $this->estado_fijo('En proceso');
        $recibido = $this->estado_fijo('Recibido');

        $otro_duenio = $this->otro_duenio();
        $ajeno = DepositMovementStatus::create(['name' => 'zz Estado ajeno buscador', 'user_id' => $otro_duenio->id]);
        $propio = DepositMovementStatus::create(['name' => 'zz Estado propio buscador', 'user_id' => $this->usuario()->id]);

        // global-search (lo que usa el listado del ABM).
        $global = $this->postJson('api/global-search/deposit-movement-status', ['per_page' => 200]);
        $global->assertStatus(200);

        $ids = collect($global->json('models.data'))->pluck('id')->all();

        $this->assertContains($en_proceso->id, $ids, 'El estado fijo "En proceso" tiene que aparecer en el ABM.');
        $this->assertContains($recibido->id, $ids, 'El estado fijo "Recibido" tiene que aparecer en el ABM.');
        $this->assertContains($propio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids, 'El estado de otro comercio no aparece.');

        // search (el otro buscador genérico).
        $search = $this->postJson('api/search/deposit-movement-status', []);
        $search->assertStatus(200);

        $ids = collect($search->json('models'))->pluck('id')->all();

        $this->assertContains($en_proceso->id, $ids);
        $this->assertContains($recibido->id, $ids);
        $this->assertContains($propio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);

        // Cualquier otro modelo con user_id sigue filtrando como siempre (solo lo del dueño).
        $marca_propia = Brand::create(['name' => 'zz Marca propia buscador', 'user_id' => $this->usuario()->id]);
        $marca_ajena = Brand::create(['name' => 'zz Marca ajena buscador', 'user_id' => $otro_duenio->id]);

        $marcas = $this->postJson('api/global-search/brand', [
            'per_page'        => 200,
            'order_by'        => 'id',
            'order_direction' => 'DESC',
        ]);
        $marcas->assertStatus(200);

        $ids = collect($marcas->json('models.data'))->pluck('id')->all();

        $this->assertContains($marca_propia->id, $ids);
        $this->assertNotContains($marca_ajena->id, $ids, 'El gancho no puede aflojar el filtro de otros modelos.');
        foreach ($marcas->json('models.data') as $marca) {
            $this->assertEquals($this->usuario()->id, (int) $marca['user_id']);
        }
    }

    // ------------------------------------------------------------------------------------------
    // 12. Dos frentes: lo que movió la versión anterior queda bloqueado
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function un_movimiento_movido_por_la_version_anterior_queda_bloqueado()
    {
        $articulo = $this->crear_articulo('zz Mov dep version anterior');
        $this->cargar_deposito($articulo, $this->origen, 10);

        $empleado = $this->crear_empleado('zz Empleado dos frentes', ['deposit_movement.move_stock']);

        $viejo = $this->crear_movimiento([[$articulo, 4]], ['employee_id' => $empleado->id]);
        $pendiente = $this->crear_movimiento([[$articulo, 1]], ['employee_id' => $empleado->id]);

        $this->marcar_como_movido_por_la_version_anterior($viejo, '2026-09-20 10:30:00');

        // "Mover stock" no lo traslada otra vez y avisa de dónde salió.
        $respuesta = $this->mover_stock($viejo);
        $respuesta->assertStatus(422);
        $this->assertSame(
            'El stock de este movimiento ya se movió el 20/09/2026 10:30 (desde una versión anterior del sistema).',
            $respuesta->json('message')
        );
        $this->assertNull(DB::table('deposit_movements')->where('id', $viejo->id)->value('stock_moved_at'));
        $this->assertEquals(0, $this->movimientos($articulo, 'Mov entre depositos')->count());

        // Los artículos quedan bloqueados.
        $this->actualizar_movimiento($viejo, [[$articulo, 9]], [
            'employee_id'                => $empleado->id,
            'deposit_movement_status_id' => $this->estado_fijo('Recibido')->id,
        ])->assertStatus(422);
        $this->assertEquals([$articulo->id => 4.0], $this->pivot($viejo));

        // Y no se borra.
        $this->deleteJson('api/deposit-movement/'.$viejo->id)->assertStatus(422);
        $this->assertNotNull(DepositMovement::find($viejo->id));

        // No sale en las alertas del empleado; el que sigue pendiente, sí.
        $this->actuar_como($empleado);

        $ids = collect($this->getJson('api/deposit-movement-en-curso')->assertStatus(200)->json('models'))
                    ->pluck('id')->all();

        $this->assertNotContains($viejo->id, $ids, 'Lo que movió la versión anterior no está en curso.');
        $this->assertContains($pendiente->id, $ids);
    }

    // ------------------------------------------------------------------------------------------
    // 13. Dos frentes: "Mover stock" llena recibido_at y el request no lo escribe
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function mover_stock_llena_recibido_at_y_el_request_no_lo_escribe()
    {
        $articulo = $this->crear_articulo('zz Mov dep recibido_at');
        $this->cargar_deposito($articulo, $this->origen, 10);

        // El alta ignora el recibido_at del request: si no, nacería "movido" sin trasladar nada.
        $movimiento = $this->crear_movimiento([[$articulo, 4]], ['recibido_at' => '2026-09-01 10:00:00']);
        $this->assertNull(DB::table('deposit_movements')->where('id', $movimiento->id)->value('recibido_at'));

        // La edición tampoco lo escribe.
        $this->actualizar_movimiento($movimiento, [[$articulo, 4]], [
            'recibido_at' => '2026-09-02 11:00:00',
            'notes'       => 'zz con recibido_at en el request',
        ])->assertStatus(200);

        $fila = DB::table('deposit_movements')->where('id', $movimiento->id)->first();
        $this->assertNull($fila->recibido_at);
        $this->assertEquals('zz con recibido_at en el request', $fila->notes);

        // Mover stock deja las DOS marcas: la nueva y la que mira la versión anterior.
        $this->mover_stock($movimiento)->assertStatus(200);

        $fila = DB::table('deposit_movements')->where('id', $movimiento->id)->first();
        $this->assertNotNull($fila->stock_moved_at);
        $this->assertNotNull($fila->recibido_at, 'Sin recibido_at, el frente viejo lo trasladaría otra vez al pasarlo a Recibido.');
        $this->assertEquals($fila->stock_moved_at, $fila->recibido_at);
    }

    // ------------------------------------------------------------------------------------------
    // 14. El estado tiene que ser fijo o propio
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function el_estado_del_movimiento_tiene_que_ser_fijo_o_propio()
    {
        $articulo = $this->crear_articulo('zz Mov dep estado valido');

        $otro_duenio = $this->otro_duenio();
        $ajeno = DepositMovementStatus::create(['name' => 'zz Estado de otro comercio', 'user_id' => $otro_duenio->id]);
        $propio = DepositMovementStatus::create(['name' => 'zz Estado propio valido', 'user_id' => $this->usuario()->id]);

        $antes = DepositMovement::where('user_id', $this->usuario()->id)->count();

        // Alta con el estado de otro comercio → 422 y no se crea nada.
        $respuesta = $this->postJson('api/deposit-movement', $this->payload_movimiento([[$articulo, 1]], [
            'deposit_movement_status_id' => $ajeno->id,
        ]));
        $respuesta->assertStatus(422);
        $this->assertSame('El estado elegido no existe.', $respuesta->json('message'));
        $this->assertEquals($antes, DepositMovement::where('user_id', $this->usuario()->id)->count());

        // Alta con un estado propio → 201.
        $movimiento = $this->crear_movimiento([[$articulo, 1]], ['deposit_movement_status_id' => $propio->id]);

        // Edición al estado de otro comercio, o a uno que no existe → 422 y queda el que estaba.
        $this->actualizar_movimiento($movimiento, [[$articulo, 1]], ['deposit_movement_status_id' => $ajeno->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El estado elegido no existe.');

        $this->actualizar_movimiento($movimiento, [[$articulo, 1]], ['deposit_movement_status_id' => 999999999])
            ->assertStatus(422);

        $this->assertEquals($propio->id, (int) DB::table('deposit_movements')->where('id', $movimiento->id)->value('deposit_movement_status_id'));

        // A un fijo → 200.
        $recibido = $this->estado_fijo('Recibido');

        $this->actualizar_movimiento($movimiento, [[$articulo, 1]], ['deposit_movement_status_id' => $recibido->id])
            ->assertStatus(200);

        $this->assertEquals($recibido->id, (int) DB::table('deposit_movements')->where('id', $movimiento->id)->value('deposit_movement_status_id'));
    }

    // ------------------------------------------------------------------------------------------
    // 15. show por dueño y borrado de uno sin mover
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function show_filtra_por_duenio_y_un_movimiento_sin_mover_se_borra()
    {
        $articulo = $this->crear_articulo('zz Mov dep show y borrar');

        $movimiento = $this->crear_movimiento([[$articulo, 2]]);

        $this->getJson('api/deposit-movement/'.$movimiento->id)
            ->assertStatus(200)
            ->assertJsonPath('model.id', $movimiento->id);

        $ajeno = DepositMovement::create([
            'num'                        => 1,
            'from_address_id'            => 1,
            'to_address_id'              => 2,
            'deposit_movement_status_id' => $this->estado_fijo('En proceso')->id,
            'user_id'                    => $this->otro_duenio()->id,
        ]);

        $this->getJson('api/deposit-movement/'.$ajeno->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'No se encontró el movimiento de depósito.');

        // Uno sin mover se borra (el destroy ahora va en transacción con la fila bloqueada).
        $this->deleteJson('api/deposit-movement/'.$movimiento->id)->assertStatus(200);
        $this->assertNull(DepositMovement::find($movimiento->id));

        // Y el de otro comercio no se borra.
        $this->deleteJson('api/deposit-movement/'.$ajeno->id)->assertStatus(404);
        $this->assertNotNull(DepositMovement::find($ajeno->id));
    }

    // ------------------------------------------------------------------------------------------
    // 16. "Mover stock" pide los dos depósitos
    // ------------------------------------------------------------------------------------------

    /**
     * @group stock
     * @test
     */
    public function mover_stock_sin_alguno_de_los_depositos_da_422()
    {
        $articulo = $this->crear_articulo('zz Mov dep sin destino');
        $this->cargar_deposito($articulo, $this->origen, 10);

        $movimiento = $this->crear_movimiento([[$articulo, 4]]);

        // Sin destino (0, que es lo que queda en la columna NOT NULL cuando no se eligió).
        DB::table('deposit_movements')->where('id', $movimiento->id)->update(['to_address_id' => 0]);

        $respuesta = $this->mover_stock($movimiento);
        $respuesta->assertStatus(422);
        $this->assertSame('Elegí el depósito de origen y el de destino antes de mover el stock.', $respuesta->json('message'));

        $this->assertNull(DB::table('deposit_movements')->where('id', $movimiento->id)->value('stock_moved_at'));
        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $this->origen->id));
        $this->assertEquals(0, $this->movimientos($articulo, 'Mov entre depositos')->count());
    }

    // ------------------------------------------------------------------------------------------
    // 17. El asistente ve los estados fijos
    // ------------------------------------------------------------------------------------------

    /**
     * Regresión marcada en el chequeo: desde que `deposit_movement_statuses` tiene `user_id`, la
     * consulta genérica del asistente (`CatalogoDeDatosIaHelper`) le aplicaba `user_id = dueño` y
     * dejaba afuera los estados fijos (`user_id` NULL): "movimientos en estado Recibido" no
     * encontraba nada y el listado de estados salía sin "En proceso" ni "Recibido".
     *
     * @group stock
     * @test
     */
    public function el_asistente_ve_los_estados_fijos_y_encuentra_un_movimiento_por_recibido()
    {
        EsquemaDeDatosIaHelper::olvidar();

        $duenio = $this->usuario();
        $en_proceso = $this->estado_fijo('En proceso');
        $recibido = $this->estado_fijo('Recibido');

        $otro_duenio = $this->otro_duenio();
        $ajeno = DepositMovementStatus::create(['name' => 'zz Estado ajeno asistente', 'user_id' => $otro_duenio->id]);
        $propio = DepositMovementStatus::create(['name' => 'zz Estado propio asistente', 'user_id' => $duenio->id]);

        $articulo = $this->crear_articulo('zz Mov dep asistente');

        $en_recibido = $this->crear_movimiento([[$articulo, 1]], ['deposit_movement_status_id' => $recibido->id]);
        $en_proceso_mov = $this->crear_movimiento([[$articulo, 1]]);

        // Un movimiento del dueño por el NOMBRE del estado fijo (filtro por relación).
        $por_estado = CatalogoDeDatosIaHelper::consultar_datos($duenio->id, 'deposit_movement', [
            ['campo' => 'deposit_movement_status_id', 'operador' => 'igual', 'valor' => 'Recibido'],
        ], null, 1, 200);

        $this->assertArrayNotHasKey('error', $por_estado, json_encode($por_estado));
        $ids = collect($por_estado['registros'])->pluck('id')->all();
        $this->assertContains($en_recibido->id, $ids, 'El asistente tiene que encontrar el movimiento por el estado fijo "Recibido".');
        $this->assertNotContains($en_proceso_mov->id, $ids);

        // El listado de estados: fijos + propios, nunca los de otro comercio.
        $estados = CatalogoDeDatosIaHelper::consultar_datos($duenio->id, 'deposit_movement_status', [], null, 1, 200);

        $this->assertArrayNotHasKey('error', $estados, json_encode($estados));
        $ids = collect($estados['registros'])->pluck('id')->all();
        $this->assertContains($en_proceso->id, $ids, 'El asistente tiene que listar el estado fijo "En proceso".');
        $this->assertContains($recibido->id, $ids, 'El asistente tiene que listar el estado fijo "Recibido".');
        $this->assertContains($propio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids, 'El estado de otro comercio no se lista.');

        // Y el otro comercio no ve el estado propio de este (los fijos sí).
        $del_otro = collect(CatalogoDeDatosIaHelper::consultar_datos($otro_duenio->id, 'deposit_movement_status', [], null, 1, 200)['registros'])
                        ->pluck('id')->all();
        $this->assertNotContains($propio->id, $del_otro);
        $this->assertContains($ajeno->id, $del_otro);
        $this->assertContains($recibido->id, $del_otro);

        // Los dos opt-in (buscador de la SPA y asistente) tienen que nombrar las mismas tablas.
        $this->assertTrue(method_exists(new DepositMovementStatus(), 'scopeDelDuenoConGlobales'));
        $this->assertContains((new DepositMovementStatus())->getTable(), CatalogoDeDatosIaHelper::TABLAS_CON_FILAS_GLOBALES);
    }
}
