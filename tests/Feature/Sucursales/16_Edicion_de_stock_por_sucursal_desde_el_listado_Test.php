<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Helpers\article\PermisoStockPorSucursalHelper;
use App\Models\PermissionEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 16 — la edición de stock por sucursal desde el Listado (`PUT api/article-update-addresses`)
 * no deshace movimientos ajenos ni escribe sucursales que la persona no puede editar (misión
 * stock-sucursal-desde-listado, 14/10/2026).
 *
 * El caso que originó la misión (trama, artículo 447): sucursales A y B en 0; el empleado 1 tiene
 * el listado abierto (ve 0 / 0); el empleado 2 hace "Mover stock" de un traslado A→B por 2 (A = -2,
 * B = 2); el empleado 1 guarda la edición con lo que tenía en pantalla y el back, que deja el stock
 * FINAL de cada sucursal por diferencia contra la base, genera +2 en A y -2 en B: el traslado queda
 * deshecho. Además el empleado 1 tenía `article.edit_stock_only_sucursal` (solo A) y el back le
 * escribió B igual.
 *
 * Lo que fija:
 *
 *  - con `pivot.amount_original` (SPA nueva), una sucursal que el usuario cambió y que en la base ya
 *    no tiene lo que él veía → 409 con el motivo, los conflictos y el artículo actualizado, SIN
 *    aplicar ningún renglón (D2);
 *  - un renglón con `amount == amount_original` no genera movimiento (D4), pero sí escribe el
 *    mínimo y el máximo;
 *  - el renglón de una sucursal que la persona no puede editar se saltea sin error, con o sin
 *    `amount_original` (D3);
 *  - sin `amount_original` (SPA vieja, asistente IA) el stock se comporta como siempre: stock final,
 *    nunca 409 (D5).
 *
 * Todo lo guardado se lee DIRECTO de `address_article` y `stock_movements`, nunca de la respuesta.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Edicion_de_stock_por_sucursal_desde_el_listado_Test extends SucursalesTestCase
{
    /** Numerador de los traslados del archivo (`deposit_movements.num`). */
    protected static $num_traslado = 991600;

    protected function setUp(): void
    {
        parent::setUp();

        SucursalVigenteHelper::olvidar();
    }

    // ==========================================================================================
    //  Helpers
    // ==========================================================================================

    /**
     * Un artículo con fila en 0 en cada sucursal dada: el "A y B en 0" del caso trama.
     *
     * @param  string  $nombre
     * @param  array   $sucursales  Addresses.
     * @return \App\Models\Article
     */
    protected function articulo_en_cero($nombre, $sucursales)
    {
        $articulo = $this->nuevo_articulo($nombre);

        foreach ($sucursales as $sucursal) {
            DB::table('address_article')->insert([
                'article_id' => $articulo->id,
                'address_id' => $sucursal->id,
                'amount'     => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $articulo;
    }

    /**
     * Un traslado entre depósitos con el botón "Mover stock" (el camino del empleado 2 en trama), por
     * el endpoint real. Lo hace el dueño.
     *
     * @param  \App\Models\Article  $articulo
     * @param  \App\Models\Address  $desde
     * @param  \App\Models\Address  $hacia
     * @param  float                $cantidad
     * @return void
     */
    protected function trasladar($articulo, $desde, $hacia, $cantidad)
    {
        $this->como($this->comercio());

        $traslado_id = DB::table('deposit_movements')->insertGetId([
            'num'                        => static::$num_traslado++,
            'from_address_id'            => $desde->id,
            'to_address_id'              => $hacia->id,
            'deposit_movement_status_id' => 1,
            'user_id'                    => $this->comercio()->id,
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        DB::table('article_deposit_movement')->insert([
            'article_id'          => $articulo->id,
            'deposit_movement_id' => $traslado_id,
            'amount'              => $cantidad,
        ]);

        $this->postJson('api/deposit-movement/'.$traslado_id.'/move-stock')->assertStatus(200);
    }

    /**
     * Cambia de persona logueada. El guard de Sanctum cachea el usuario entre requests del mismo
     * test, así que primero se olvidan los guards.
     *
     * @param  \App\Models\User  $persona
     * @return void
     */
    protected function como($persona)
    {
        $this->app['auth']->forgetGuards();

        $this->actingAs($persona, 'web');
    }

    /**
     * Un empleado con `article.edit_stock` + `article.edit_stock_only_sucursal`, asignado a una
     * sucursal (el empleado 1 de trama).
     *
     * @param  \App\Models\Address  $sucursal
     * @return \App\Models\User
     */
    protected function empleado_acotado_a($sucursal)
    {
        return $this->empleado_con(['article.edit_stock', 'article.edit_stock_only_sucursal'], $sucursal->id);
    }

    /**
     * Un empleado del comercio con los permisos dados por slug (se crean si la base no los tiene;
     * todo vive en la transacción del test).
     *
     * @param  array     $slugs
     * @param  int|null  $address_id
     * @return \App\Models\User
     */
    protected function empleado_con($slugs, $address_id)
    {
        $empleado = $this->nuevo_empleado('zz Empleado stock por sucursal', $address_id);

        $ids = [];

        foreach ($slugs as $slug) {

            $permiso = PermissionEmpresa::where('slug', $slug)->first();

            if (is_null($permiso)) {
                $permiso = PermissionEmpresa::forceCreate(['name' => $slug, 'slug' => $slug, 'model_name' => 'article']);
            }

            $ids[] = $permiso->id;
        }

        $empleado->permissions()->sync($ids);
        $empleado->load('permissions');

        return $empleado;
    }

    /**
     * Renglón de la SPA nueva: lleva lo que el usuario tenía en pantalla al abrir la edición.
     *
     * @param  \App\Models\Address  $sucursal
     * @param  float                $amount
     * @param  float                $amount_original
     * @param  int|null             $stock_min
     * @param  int|null             $stock_max
     * @return array
     */
    protected function renglon_nuevo($sucursal, $amount, $amount_original, $stock_min = null, $stock_max = null)
    {
        return [
            'id'    => $sucursal->id,
            'pivot' => [
                'amount'          => $amount,
                'amount_original' => $amount_original,
                'stock_min'       => $stock_min,
                'stock_max'       => $stock_max,
            ],
        ];
    }

    /**
     * Renglón de la SPA vieja (y del asistente IA): sin `amount_original`.
     *
     * @param  \App\Models\Address  $sucursal
     * @param  float                $amount
     * @param  int|null             $stock_min
     * @param  int|null             $stock_max
     * @return array
     */
    protected function renglon_viejo($sucursal, $amount, $stock_min = null, $stock_max = null)
    {
        return [
            'id'    => $sucursal->id,
            'pivot' => [
                'amount'    => $amount,
                'stock_min' => $stock_min,
                'stock_max' => $stock_max,
            ],
        ];
    }

    /**
     * @param  \App\Models\Article  $articulo
     * @param  array                $renglones
     * @return \Illuminate\Testing\TestResponse
     */
    protected function guardar($articulo, $renglones)
    {
        return $this->putJson('api/article-update-addresses', [
            'article_id' => $articulo->id,
            'addresses'  => $renglones,
        ]);
    }

    /**
     * Mínimo y máximo de la sucursal, leídos de la tabla.
     *
     * @param  \App\Models\Article  $articulo
     * @param  \App\Models\Address  $sucursal
     * @return array  [stock_min, stock_max]
     */
    protected function min_max_en($articulo, $sucursal)
    {
        $fila = DB::table('address_article')
                    ->where('article_id', $articulo->id)
                    ->where('address_id', $sucursal->id)
                    ->first(['stock_min', 'stock_max']);

        $this->assertNotNull($fila, 'El artículo no tiene fila en la sucursal '.$sucursal->id.'.');

        return [
            is_null($fila->stock_min) ? null : (int) $fila->stock_min,
            is_null($fila->stock_max) ? null : (int) $fila->stock_max,
        ];
    }

    /**
     * Stock de la sucursal en el `model` que devolvió el endpoint.
     *
     * @param  array  $model
     * @param  \App\Models\Address  $sucursal
     * @return float|null
     */
    protected function stock_en_respuesta($model, $sucursal)
    {
        foreach ($model['addresses'] as $address) {
            if ((int) $address['id'] === (int) $sucursal->id) {
                return (float) $address['pivot']['amount'];
            }
        }

        return null;
    }

    // ==========================================================================================
    //  Tests
    // ==========================================================================================

    /**
     * Test 1 — el caso trama con la SPA nueva: el usuario tenía 0 / 0 en pantalla, en el medio hubo
     * un traslado A→B por 2, y guarda valores cambiados con `amount_original` 0 → 409, el traslado
     * queda intacto y no se genera ningún movimiento.
     *
     * @test
     */
    public function el_caso_trama_da_409_y_no_deshace_el_traslado()
    {
        $a = $this->nueva_sucursal('zz Edicion stock trama A');
        $b = $this->nueva_sucursal('zz Edicion stock trama B');

        $articulo = $this->articulo_en_cero('zz Edicion stock trama', [$a, $b]);

        // Lo que el usuario ve al abrir la edición: 0 / 0. Mientras edita, otro mueve el stock.
        $this->trasladar($articulo, $a, $b, 2);

        $this->assertEqualsWithDelta(-2, $this->stock_en($articulo, $a->id), self::DELTA, 'El traslado deja A en -2.');
        $this->assertEqualsWithDelta(2, $this->stock_en($articulo, $b->id), self::DELTA, 'El traslado deja B en 2.');

        $movimientos = $this->movimientos_de($articulo)->count();

        $respuesta = $this->guardar($articulo, [
            $this->renglon_nuevo($a, 5, 0),
            $this->renglon_nuevo($b, 1, 0),
        ]);

        $respuesta->assertStatus(409);

        $mensaje = $respuesta->json('message');

        $this->assertStringContainsString('zz Edicion stock trama A', $mensaje, 'El motivo nombra la sucursal A.');
        $this->assertStringContainsString('zz Edicion stock trama B', $mensaje, 'El motivo nombra también la sucursal B.');
        $this->assertStringContainsString('tenías 0 en pantalla y ahora hay -2', $mensaje);
        $this->assertStringContainsString('tenías 0 en pantalla y ahora hay 2', $mensaje);
        $this->assertStringContainsString('No se guardó ningún cambio', $mensaje);
        $this->assertNull($respuesta->json('errors'), 'Sin `errors`: la SPA muestra el message tal cual.');

        $conflictos = $respuesta->json('conflictos');
        $this->assertCount(2, $conflictos);
        $this->assertSame($a->id, (int) $conflictos[0]['address_id']);
        $this->assertEqualsWithDelta(0, $conflictos[0]['en_pantalla'], self::DELTA);
        $this->assertEqualsWithDelta(-2, $conflictos[0]['actual'], self::DELTA);
        $this->assertSame($b->id, (int) $conflictos[1]['address_id']);
        $this->assertEqualsWithDelta(2, $conflictos[1]['actual'], self::DELTA);

        // El artículo actualizado, para que la fila muestre lo real.
        $model = $respuesta->json('model');
        $this->assertSame($articulo->id, (int) $model['id']);
        $this->assertEqualsWithDelta(-2, $this->stock_en_respuesta($model, $a), self::DELTA);
        $this->assertEqualsWithDelta(2, $this->stock_en_respuesta($model, $b), self::DELTA);

        // Nada se escribió.
        $this->assertEqualsWithDelta(-2, $this->stock_en($articulo, $a->id), self::DELTA, 'A sigue en -2: el traslado no se deshizo.');
        $this->assertEqualsWithDelta(2, $this->stock_en($articulo, $b->id), self::DELTA, 'B sigue en 2: el traslado no se deshizo.');
        $this->assertSame($movimientos, $this->movimientos_de($articulo)->count(), 'Un 409 no genera ningún movimiento.');
    }

    /**
     * Test 2 — el mismo caso, pero el usuario no cambió nada (los renglones llegan con
     * `amount == amount_original`, que es justo lo que en trama deshizo el traslado) → 200 y no se
     * mueve nada.
     *
     * @test
     */
    public function sin_cambios_no_se_mueve_nada_aunque_la_base_haya_cambiado()
    {
        $a = $this->nueva_sucursal('zz Edicion stock sin cambios A');
        $b = $this->nueva_sucursal('zz Edicion stock sin cambios B');

        $articulo = $this->articulo_en_cero('zz Edicion stock sin cambios', [$a, $b]);

        $this->trasladar($articulo, $a, $b, 2);

        $movimientos = $this->movimientos_de($articulo)->count();

        $this->guardar($articulo, [
            $this->renglon_nuevo($a, 0, 0),
            $this->renglon_nuevo($b, 0, 0),
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(-2, $this->stock_en($articulo, $a->id), self::DELTA);
        $this->assertEqualsWithDelta(2, $this->stock_en($articulo, $b->id), self::DELTA);
        $this->assertSame($movimientos, $this->movimientos_de($articulo)->count(), 'Sin cambios no hay movimiento.');
    }

    /**
     * Test 3 — empleado con `edit_stock` + `edit_stock_only_sucursal` asignado a A manda A y B
     * cambiadas (con `amount_original`): solo cambia A. B queda intacta (stock, mínimo y máximo) y
     * no hay 403.
     *
     * @test
     */
    public function el_empleado_acotado_solo_cambia_su_sucursal()
    {
        $a = $this->nueva_sucursal('zz Edicion stock acotado A');
        $b = $this->nueva_sucursal('zz Edicion stock acotado B');

        $articulo = $this->nuevo_articulo('zz Edicion stock acotado');
        $this->cargar_deposito($articulo, $a, 10);
        $this->cargar_deposito($articulo, $b, 10);

        DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $b->id)
            ->update(['stock_min' => 1, 'stock_max' => 50]);

        $movimientos = $this->movimientos_de($articulo)->count();

        $this->como($this->empleado_acotado_a($a));

        $this->guardar($articulo, [
            $this->renglon_nuevo($a, 7, 10, 2, 30),
            $this->renglon_nuevo($b, 3, 10, 5, 99),
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(7, $this->stock_en($articulo, $a->id), self::DELTA, 'Su sucursal se actualiza.');
        $this->assertSame([2, 30], $this->min_max_en($articulo, $a));

        $this->assertEqualsWithDelta(10, $this->stock_en($articulo, $b->id), self::DELTA, 'La sucursal ajena no se toca.');
        $this->assertSame([1, 50], $this->min_max_en($articulo, $b), 'Ni el mínimo ni el máximo de la ajena.');

        $nuevos = $this->movimientos_de($articulo)->slice($movimientos)->values();
        $this->assertCount(1, $nuevos, 'Un solo movimiento: el de su sucursal.');
        $this->assertSame($a->id, (int) $nuevos[0]->to_address_id);
    }

    /**
     * Test 4 — el mismo empleado acotado con la SPA vieja (sin `amount_original`), que manda B con
     * el valor viejo que tenía en pantalla: B queda intacta. A, la suya, como siempre (stock final).
     *
     * @test
     */
    public function el_empleado_acotado_con_la_spa_vieja_no_pisa_la_sucursal_ajena()
    {
        $a = $this->nueva_sucursal('zz Edicion stock vieja acotado A');
        $b = $this->nueva_sucursal('zz Edicion stock vieja acotado B');

        $articulo = $this->articulo_en_cero('zz Edicion stock vieja acotado', [$a, $b]);

        $this->trasladar($articulo, $a, $b, 2);

        $movimientos = $this->movimientos_de($articulo)->count();

        $this->como($this->empleado_acotado_a($a));

        $this->guardar($articulo, [
            $this->renglon_viejo($a, 4),
            $this->renglon_viejo($b, 0),
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(2, $this->stock_en($articulo, $b->id), self::DELTA, 'B (ajena) conserva el traslado.');
        $this->assertEqualsWithDelta(4, $this->stock_en($articulo, $a->id), self::DELTA, 'A (la suya) queda en el stock final que mandó.');

        $nuevos = $this->movimientos_de($articulo)->slice($movimientos)->values();
        $this->assertCount(1, $nuevos);
        $this->assertSame($a->id, (int) $nuevos[0]->to_address_id);
    }

    /**
     * Test 5 — el dueño sin `amount_original` (SPA vieja, o el asistente): stock final en todas las
     * sucursales, como siempre, y nunca 409 aunque la base haya cambiado en el medio (D5).
     *
     * @test
     */
    public function el_dueno_con_la_spa_vieja_deja_el_stock_final_como_siempre()
    {
        $a = $this->nueva_sucursal('zz Edicion stock vieja dueno A');
        $b = $this->nueva_sucursal('zz Edicion stock vieja dueno B');

        $articulo = $this->nuevo_articulo('zz Edicion stock vieja dueno');
        $this->cargar_deposito($articulo, $a, 10);

        // B todavía no tiene fila: el traslado se la abre (A = 7, B = 3).
        $this->trasladar($articulo, $a, $b, 3);

        $movimientos = $this->movimientos_de($articulo)->count();

        $this->guardar($articulo, [
            $this->renglon_viejo($a, 4),
            $this->renglon_viejo($b, 6),
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(4, $this->stock_en($articulo, $a->id), self::DELTA);
        $this->assertEqualsWithDelta(6, $this->stock_en($articulo, $b->id), self::DELTA);

        $nuevos = $this->movimientos_de($articulo)->slice($movimientos)->values();
        $this->assertCount(2, $nuevos, 'Un movimiento por sucursal, como siempre.');

        foreach ($nuevos as $movimiento) {
            $this->assertSame($this->concepto_id('Actualizacion de deposito'), (int) $movimiento->concepto_stock_movement_id);
        }
    }

    /**
     * Test 6 — conflicto en una sucursal y un cambio válido en otra: 409 y NINGUNA de las dos se
     * aplica (ni el stock ni el mínimo/máximo de la válida).
     *
     * @test
     */
    public function un_conflicto_frena_tambien_los_cambios_validos()
    {
        $a = $this->nueva_sucursal('zz Edicion stock mixto A');
        $b = $this->nueva_sucursal('zz Edicion stock mixto B');
        $c = $this->nueva_sucursal('zz Edicion stock mixto C');

        $articulo = $this->articulo_en_cero('zz Edicion stock mixto', [$a, $b]);
        $this->cargar_deposito($articulo, $c, 5);

        $this->trasladar($articulo, $a, $b, 2);

        $movimientos = $this->movimientos_de($articulo)->count();

        $respuesta = $this->guardar($articulo, [
            $this->renglon_nuevo($a, 3, 0),
            $this->renglon_nuevo($c, 8, 5, 1, 9),
        ]);

        $respuesta->assertStatus(409);

        $this->assertCount(1, $respuesta->json('conflictos'), 'Solo A está en conflicto.');
        $this->assertSame($a->id, (int) $respuesta->json('conflictos.0.address_id'));
        $this->assertStringContainsString('El stock de zz Edicion stock mixto A cambió mientras lo editabas (tenías 0 en pantalla y ahora hay -2).', $respuesta->json('message'));
        $this->assertStringNotContainsString('zz Edicion stock mixto C', $respuesta->json('message'));

        $this->assertEqualsWithDelta(-2, $this->stock_en($articulo, $a->id), self::DELTA);
        $this->assertEqualsWithDelta(2, $this->stock_en($articulo, $b->id), self::DELTA);
        $this->assertEqualsWithDelta(5, $this->stock_en($articulo, $c->id), self::DELTA, 'El cambio válido tampoco se aplica.');
        $this->assertSame([null, null], $this->min_max_en($articulo, $c), 'Ni su mínimo/máximo.');
        $this->assertSame($movimientos, $this->movimientos_de($articulo)->count());
    }

    /**
     * Test 7 — solo cambia el mínimo y el máximo (el `amount` igual al original): se escriben y no
     * hay movimiento de stock.
     *
     * @test
     */
    public function solo_minimo_y_maximo_no_genera_movimiento()
    {
        $a = $this->nueva_sucursal('zz Edicion stock min max A');

        $articulo = $this->nuevo_articulo('zz Edicion stock min max');
        $this->cargar_deposito($articulo, $a, 10);

        $movimientos = $this->movimientos_de($articulo)->count();

        $this->guardar($articulo, [
            $this->renglon_nuevo($a, 10, 10, 2, 40),
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(10, $this->stock_en($articulo, $a->id), self::DELTA);
        $this->assertSame([2, 40], $this->min_max_en($articulo, $a));
        $this->assertSame($movimientos, $this->movimientos_de($articulo)->count(), 'Solo mínimo/máximo: sin movimiento de stock.');
    }

    /**
     * Test 8 — la regla única de permiso es el espejo de `puede_editar_stock` de la SPA.
     *
     * @test
     */
    public function la_regla_de_permiso_es_el_espejo_de_la_pantalla()
    {
        $a = $this->nueva_sucursal('zz Edicion stock regla A');
        $b = $this->nueva_sucursal('zz Edicion stock regla B');

        $this->assertFalse(PermisoStockPorSucursalHelper::puede_editar(null, $a->id), 'Sin persona, no.');

        $this->assertTrue(PermisoStockPorSucursalHelper::puede_editar($this->comercio(), $a->id), 'El dueño puede todas.');

        $admin = $this->nuevo_empleado('zz Admin', null);
        $admin->admin_access = 1;
        $this->assertTrue(PermisoStockPorSucursalHelper::puede_editar($admin, $b->id), 'admin_access puede todas.');

        $acotado = $this->empleado_acotado_a($a);
        $this->assertTrue(PermisoStockPorSucursalHelper::puede_editar($acotado, $a->id), 'El acotado puede la suya.');
        $this->assertFalse(PermisoStockPorSucursalHelper::puede_editar($acotado, $b->id), 'El acotado no puede otra, aunque tenga edit_stock.');

        $general = $this->empleado_con(['article.edit_stock'], $a->id);
        $this->assertTrue(PermisoStockPorSucursalHelper::puede_editar($general, $b->id), 'Con edit_stock puede todas.');

        $sin = $this->empleado_con([], $a->id);
        $this->assertFalse(PermisoStockPorSucursalHelper::puede_editar($sin, $a->id), 'Sin permisos, ni la suya.');
    }

    // ==========================================================================================
    //  Segunda ronda (verificadores independientes)
    // ==========================================================================================

    /**
     * Foto de las filas del artículo en `address_article`: [address_id => [amount, stock_min, stock_max]].
     *
     * @param  \App\Models\Article  $articulo
     * @return array
     */
    protected function filas_del_articulo($articulo)
    {
        $filas = DB::table('address_article')->where('article_id', $articulo->id)->orderBy('id')
                    ->get(['address_id', 'amount', 'stock_min', 'stock_max']);

        $foto = [];

        foreach ($filas as $fila) {
            $foto[] = [(int) $fila->address_id, (float) $fila->amount, $fila->stock_min, $fila->stock_max];
        }

        return $foto;
    }

    /**
     * Test 9 — el id que valida el permiso tiene que ser el mismo con el que se escribe. Un empleado
     * acotado a A manda un id que `(int)` lee como A pero que no es un entero ("A.9", A + 0,9,
     * "Aabc"), o un array, o null: no se escribe nada en ninguna sucursal, sin 500. Con "A" (string
     * de dígitos) sigue andando como A.
     *
     * Medido por el verificador: MySQL estricto guardaba "A.9" como A + 1 sin aviso, o sea stock y
     * mínimo/máximo en la sucursal de al lado y una fila nueva.
     *
     * @test
     */
    public function un_id_de_sucursal_que_no_es_entero_no_escribe_nada()
    {
        $a = $this->nueva_sucursal('zz Edicion stock id A');
        $b = $this->nueva_sucursal('zz Edicion stock id B');

        $articulo = $this->nuevo_articulo('zz Edicion stock id');
        $this->cargar_deposito($articulo, $a, 10);
        $this->cargar_deposito($articulo, $b, 10);

        $foto = $this->filas_del_articulo($articulo);
        $movimientos = $this->movimientos_de($articulo)->count();

        $this->como($this->empleado_acotado_a($a));

        $ids_invalidos = [
            'string con decimales' => $a->id.'.9',
            'número con decimales' => $a->id + 0.9,
            'string con letras'    => $a->id.'abc',
            'array'                => [$a->id],
            'null'                 => null,
        ];

        foreach ($ids_invalidos as $que => $id) {

            $this->guardar($articulo, [
                ['id' => $id, 'pivot' => ['amount' => 50, 'stock_min' => 1, 'stock_max' => 99]],
            ])->assertStatus(200);

            $this->guardar($articulo, [
                ['id' => $id, 'pivot' => ['amount' => 50, 'amount_original' => 10, 'stock_min' => 1, 'stock_max' => 99]],
            ])->assertStatus(200);

            $this->assertSame($foto, $this->filas_del_articulo($articulo), 'Id inválido ('.$que.'): no se escribe ninguna fila.');
            $this->assertSame($movimientos, $this->movimientos_de($articulo)->count(), 'Id inválido ('.$que.'): ningún movimiento.');
        }

        // Un string de dígitos es un id válido.
        $this->guardar($articulo, [
            ['id' => (string) $a->id, 'pivot' => ['amount' => 50, 'stock_min' => 1, 'stock_max' => 99]],
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(50, $this->stock_en($articulo, $a->id), self::DELTA, '"A" como string sigue andando.');
        $this->assertSame([1, 99], $this->min_max_en($articulo, $a));
        $this->assertEqualsWithDelta(10, $this->stock_en($articulo, $b->id), self::DELTA, 'La de al lado no se toca.');
        $this->assertSame(1, DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $a->id)->count(), 'Sin filas duplicadas.');
    }

    /**
     * Test 10 — dos renglones para la misma sucursal: se aplica uno solo, el último.
     *
     * @test
     */
    public function un_renglon_repetido_se_aplica_una_sola_vez_y_gana_el_ultimo()
    {
        $a = $this->nueva_sucursal('zz Edicion stock repetido A');

        $articulo = $this->articulo_en_cero('zz Edicion stock repetido', [$a]);

        $movimientos = $this->movimientos_de($articulo)->count();

        $this->guardar($articulo, [
            $this->renglon_nuevo($a, 3, 0),
            $this->renglon_nuevo($a, 5, 0),
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(5, $this->stock_en($articulo, $a->id), self::DELTA, 'Gana el último renglón, aplicado una vez.');
        $this->assertSame($movimientos + 1, $this->movimientos_de($articulo)->count());

        // Lo mismo con la SPA vieja.
        $this->guardar($articulo, [
            $this->renglon_viejo($a, 8),
            $this->renglon_viejo($a, 6),
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(6, $this->stock_en($articulo, $a->id), self::DELTA);
        $this->assertSame($movimientos + 2, $this->movimientos_de($articulo)->count());
    }

    /**
     * Test 11 — con `amount_original`, el movimiento es lo que el usuario cambió
     * (`amount - amount_original`), no la diferencia contra la base: si entre el chequeo de
     * conflictos y la escritura se cuela una venta, la venta queda respetada.
     *
     * @test
     */
    public function con_amount_original_una_venta_en_el_medio_queda_respetada()
    {
        $a = $this->nueva_sucursal('zz Edicion stock carrera A');

        $articulo = $this->nuevo_articulo('zz Edicion stock carrera');
        $this->cargar_deposito($articulo, $a, 10);

        $helper = new \App\Http\Controllers\Helpers\article\UpdateAddressesStockHelper(
            $articulo->id,
            [$this->renglon_nuevo($a, 15, 10)],
            $this->comercio()
        );

        $this->assertSame([], $helper->conflictos(), 'Al chequear, la base tiene lo que el usuario veía.');

        // Una venta de 3 en esa sucursal, por afuera, después del chequeo.
        $this->cargar_deposito($articulo, $a, -3);
        $this->assertEqualsWithDelta(7, $this->stock_en($articulo, $a->id), self::DELTA);

        $helper->update_addresses();

        $this->assertEqualsWithDelta(12, $this->stock_en($articulo, $a->id), self::DELTA, 'Se suman los 5 que cargó el usuario y la venta queda: 7 + 5.');
        $this->assertEqualsWithDelta(5, (float) $this->movimientos_de($articulo)->last()->amount, self::DELTA);
    }

    /**
     * Test 12 — con la SPA nueva (renglón con `amount_original`), mínimo y máximo en null se
     * guardan: el usuario los vació. Y vaciarlos en una sucursal sin fila no le abre una.
     *
     * @test
     */
    public function con_la_spa_nueva_vaciar_minimo_y_maximo_los_borra()
    {
        $a = $this->nueva_sucursal('zz Edicion stock vaciar A');
        $b = $this->nueva_sucursal('zz Edicion stock vaciar B');

        $articulo = $this->nuevo_articulo('zz Edicion stock vaciar');
        $this->cargar_deposito($articulo, $a, 10);

        DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $a->id)
            ->update(['stock_min' => 2, 'stock_max' => 40]);

        $movimientos = $this->movimientos_de($articulo)->count();

        $this->guardar($articulo, [
            $this->renglon_nuevo($a, 10, 10, null, null),
            $this->renglon_nuevo($b, 0, 0, null, null),
        ])->assertStatus(200);

        $this->assertSame([null, null], $this->min_max_en($articulo, $a), 'Los dos en null se guardan.');
        $this->assertEqualsWithDelta(10, $this->stock_en($articulo, $a->id), self::DELTA);
        $this->assertNull($this->stock_en($articulo, $b->id), 'Vaciar en una sucursal sin fila no le abre una.');
        $this->assertSame($movimientos, $this->movimientos_de($articulo)->count());
    }

    /**
     * Test 13 — con la SPA vieja (y el asistente), mínimo y máximo los dos en null siguen siendo "no
     * tocar", como siempre.
     *
     * @test
     */
    public function con_la_spa_vieja_minimo_y_maximo_en_null_no_se_tocan()
    {
        $a = $this->nueva_sucursal('zz Edicion stock no tocar A');

        $articulo = $this->nuevo_articulo('zz Edicion stock no tocar');
        $this->cargar_deposito($articulo, $a, 10);

        DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $a->id)
            ->update(['stock_min' => 2, 'stock_max' => 40]);

        $this->guardar($articulo, [
            $this->renglon_viejo($a, 10, null, null),
        ])->assertStatus(200);

        $this->assertSame([2, 40], $this->min_max_en($articulo, $a), 'Sin amount_original, los dos en null no tocan nada.');
    }
}
