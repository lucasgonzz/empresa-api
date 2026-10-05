<?php

namespace Tests\Feature\CategoryProposals;

use App\Http\Controllers\Helpers\category_proposal\CategoryMargenesHelper;
use Illuminate\Support\Facades\DB;

/**
 * Las reglas de plata y de Tienda Nube que bloquean elegir un sistema de categorías nuevo (misión
 * categorizacion-tres-modelos, 5/10/2026): `CategoryMargenesHelper`, plan §4.5 y §4.6.
 *
 * Qué protege:
 *  - Cada motivo de margen R1 a R6 por separado, con su caso "no cuenta" (NULL, 0.00, categoría
 *    borrada, dato de otro comercio): una SQL que cuente de más bloquea a un comercio que no usa
 *    márgenes; una que cuente de menos le deja mover precios sin avisar.
 *  - Un caso Doblep (listas de precio por artículo, sin categorías): tiene que dar `false`.
 *  - Los avisos A1 a A3 (informan, no bloquean), Tienda Nube y las advertencias de §4.6.
 *  - Que todo se acota por el DUEÑO: el comercio vecino con todo cargado no cambia nada, y un
 *    empleado se resuelve a su dueño.
 *
 * Las SQL son las de `relevamiento/R1-efectos-de-aplicar-categorias.md` §2.5. Acá corren en MySQL 8;
 * Doblep corre MariaDB, y por eso son ANSI simples (sin `<=>`, sin funciones propias de MySQL).
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Margenes_y_bloqueos_Test extends CategoryProposalsTestCase
{
    use AyudasDeLaEleccion;

    /**
     * Los códigos de motivo que da `usa_margenes_por_categoria` para el comercio, en orden.
     *
     * @param  int $user_id
     * @return array  [['codigo' => 'R1', 'cantidad' => n], ...]
     */
    protected function motivos_de($user_id)
    {
        return CategoryMargenesHelper::usa_margenes_por_categoria($user_id)['motivos'];
    }

    /**
     * Un par de lista de precios y su pivote con una categoría, sin pasar por el alta del sistema.
     *
     * @param  \App\Models\Category $categoria
     * @param  mixed $porcentaje  El `percentage` del pivote (null, 0, 5...).
     * @return void
     */
    protected function pivote_de_lista($categoria, $porcentaje)
    {
        DB::table('category_price_type')->insert([
            'category_id'   => $categoria->id,
            'price_type_id' => 1,
            'percentage'    => $porcentaje,
        ]);
    }

    /**
     * Una vinculación de inventario del comercio (las columnas obligatorias con un valor cualquiera).
     *
     * @param  \App\Models\User $dueno
     * @return int  El id de la vinculación.
     */
    protected function vinculacion_de($dueno)
    {
        return DB::table('inventory_linkages')->insertGetId([
            'client_id'                  => 1,
            'inventory_linkage_scope_id' => 1,
            'user_id'                    => $dueno->id,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // El caso Doblep y el comercio vacío
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 El caso real que motivó la misión: Doblep trabaja con listas de precio (`listas_de_precio = 1`)
     * pero con el margen en el pivote por artículo, sin una sola categoría y sin la extensión de
     * listas por categoría. NO usa márgenes por categoría: la función tiene que dar `false` y el
     * bloqueo no puede estar activo (si no, Pablo no podría elegir ningún sistema).
     *
     * @test
     * @group categorias_ia
     */
    public function un_comercio_como_doblep_no_usa_margenes_por_categoria()
    {
        $this->owner->update(['listas_de_precio' => 1]);
        $this->crear_articulos(['Bisagra', 'Corredera', 'Tornillo']);

        $resultado = CategoryMargenesHelper::usa_margenes_por_categoria($this->owner->id);

        $this->assertFalse($resultado['usa']);
        $this->assertSame([], $resultado['motivos']);
        // `listas_de_precio = 1` solo es informativo (aviso A3): no bloquea.
        $this->assertSame([['codigo' => 'A3', 'cantidad' => 1]], $resultado['avisos']);

        $this->assertSame(
            ['nuevo_modelo_bloqueado' => false, 'motivos' => []],
            CategoryMargenesHelper::bloqueo_para($this->owner->id)
        );
    }

    /**
     * Un comercio sin nada: no usa márgenes y no tiene avisos.
     *
     * @test
     * @group categorias_ia
     */
    public function un_comercio_vacio_no_usa_margenes_ni_tiene_avisos()
    {
        $this->assertSame(
            ['usa' => false, 'motivos' => [], 'avisos' => []],
            CategoryMargenesHelper::usa_margenes_por_categoria($this->owner->id)
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Cada motivo por separado
    // ---------------------------------------------------------------------------------------------

    /**
     * R1: el margen propio (`percentage_gain`) de una categoría viva. Vale en cualquier cuenta, sin
     * extensión.
     *
     * @test
     * @group categorias_ia
     */
    public function r1_cuenta_el_margen_propio_de_una_categoria()
    {
        $this->categoria_real('Con margen', null, ['percentage_gain' => 15]);
        $this->categoria_real('Con otro margen', null, ['percentage_gain' => 8.5]);
        $this->categoria_real('Sin margen');

        $resultado = CategoryMargenesHelper::usa_margenes_por_categoria($this->owner->id);

        $this->assertTrue($resultado['usa']);
        $this->assertSame([['codigo' => 'R1', 'cantidad' => 2]], $resultado['motivos']);
    }

    /**
     * 🔴 `percentage_gain` NULL no cuenta, `0.00` tampoco (suma cero y no mueve el precio: por eso la
     * SQL compara con `<> 0` y no con `IS NOT NULL` a secas) y un negativo (un descuento) SÍ.
     *
     * @test
     * @group categorias_ia
     */
    public function r1_distingue_null_cero_y_negativo()
    {
        $this->categoria_real('Margen nulo', null, ['percentage_gain' => null]);
        $this->assertSame([], $this->motivos_de($this->owner->id), 'NULL no cuenta.');

        $this->categoria_real('Margen cero', null, ['percentage_gain' => '0.00']);
        $this->assertSame([], $this->motivos_de($this->owner->id), '0.00 no cuenta: no mueve el precio.');

        $this->categoria_real('Descuento', null, ['percentage_gain' => -5]);
        $this->assertSame([['codigo' => 'R1', 'cantidad' => 1]], $this->motivos_de($this->owner->id), 'Un negativo cuenta.');
    }

    /**
     * R1 ignora las categorías borradas (soft delete) y las de otro comercio.
     *
     * @test
     * @group categorias_ia
     */
    public function r1_ignora_las_categorias_borradas_y_las_del_vecino()
    {
        $borrada = $this->categoria_real('Borrada con margen', null, ['percentage_gain' => 20]);
        $borrada->delete();

        $this->categoria_real('Del vecino con margen', $this->vecino, ['percentage_gain' => 20]);

        $this->assertSame([], $this->motivos_de($this->owner->id));
        $this->assertSame([['codigo' => 'R1', 'cantidad' => 1]], $this->motivos_de($this->vecino->id), 'El vecino sí usa márgenes.');
    }

    /**
     * R2: alguna de las dos extensiones de listas por categoría prendida. Cuenta aunque no haya ni un
     * porcentaje cargado: una categoría nueva nace con los pivotes en NULL y lo que se mueva ahí
     * pierde el precio de lista.
     *
     * @test
     * @group categorias_ia
     */
    public function r2_cuenta_las_extensiones_de_listas_por_categoria_y_por_rango()
    {
        $this->dar_extension('lista_de_precios_por_categoria');
        $this->assertSame([['codigo' => 'R2', 'cantidad' => 1]], $this->motivos_de($this->owner->id));

        // La otra extensión sola también bloquea (se saca la primera para aislarla).
        $this->owner->extencions()->detach();
        $this->assertSame([], $this->motivos_de($this->owner->id));

        $this->dar_extension('lista_de_precios_por_rango_de_cantidad_vendida');
        $this->assertSame([['codigo' => 'R2', 'cantidad' => 1]], $this->motivos_de($this->owner->id));

        // Una extensión que no tiene que ver no bloquea.
        $this->owner->extencions()->detach();
        $this->dar_extension('usa_tienda_nube');
        $this->assertSame([], $this->motivos_de($this->owner->id));
    }

    /**
     * R3: listas por categoría con datos. Un pivote en NULL, en 0, de una categoría borrada o de una
     * categoría ajena no cuenta. `category_price_type` no tiene `user_id`: se cruza por `categories`.
     *
     * @test
     * @group categorias_ia
     */
    public function r3_cuenta_solo_los_porcentajes_distintos_de_cero_de_categorias_vivas_del_dueno()
    {
        $propia  = $this->categoria_real('Propia');
        $borrada = $this->categoria_real('Borrada');
        $ajena   = $this->categoria_real('Ajena', $this->vecino);

        $this->pivote_de_lista($propia, null);
        $this->pivote_de_lista($propia, 0);
        $this->assertSame([], $this->motivos_de($this->owner->id), 'NULL y 0 no cuentan.');

        $this->pivote_de_lista($borrada, 12);
        $borrada->delete();
        $this->pivote_de_lista($ajena, 12);
        $this->assertSame([], $this->motivos_de($this->owner->id), 'Ni una categoría borrada ni una ajena.');

        $this->pivote_de_lista($propia, 7);
        $this->assertSame([['codigo' => 'R3', 'cantidad' => 1]], $this->motivos_de($this->owner->id));
    }

    /**
     * R4: listas por subcategoría con datos (`price_type_sub_category`, también sin `user_id`: se cruza por
     * `sub_categories`).
     *
     * @test
     * @group categorias_ia
     */
    public function r4_cuenta_solo_los_porcentajes_distintos_de_cero_de_subcategorias_vivas_del_dueno()
    {
        $categoria = $this->categoria_real('Propia');
        $propia    = $this->subcategoria_real('Sub propia', $categoria);
        $borrada   = $this->subcategoria_real('Sub borrada', $categoria);
        $ajena     = $this->subcategoria_real('Sub ajena', $this->categoria_real('Del vecino', $this->vecino), $this->vecino);

        DB::table('price_type_sub_category')->insert(['price_type_id' => 1, 'sub_category_id' => $propia->id, 'percentage' => 0]);
        DB::table('price_type_sub_category')->insert(['price_type_id' => 1, 'sub_category_id' => $propia->id, 'percentage' => null]);
        $this->assertSame([], $this->motivos_de($this->owner->id), 'NULL y 0 no cuentan.');

        DB::table('price_type_sub_category')->insert(['price_type_id' => 1, 'sub_category_id' => $borrada->id, 'percentage' => 9]);
        $borrada->delete();
        DB::table('price_type_sub_category')->insert(['price_type_id' => 1, 'sub_category_id' => $ajena->id, 'percentage' => 9]);
        $this->assertSame([], $this->motivos_de($this->owner->id), 'Ni una subcategoría borrada ni una ajena.');

        DB::table('price_type_sub_category')->insert(['price_type_id' => 1, 'sub_category_id' => $propia->id, 'percentage' => 4]);
        $this->assertSame([['codigo' => 'R4', 'cantidad' => 1]], $this->motivos_de($this->owner->id));
    }

    /**
     * R5: rangos por cantidad que apuntan a una categoría o subcategoría del dueño. Una fila sin
     * categoría ni subcategoría (rango general) o de otro comercio no cuenta.
     *
     * @test
     * @group categorias_ia
     */
    public function r5_cuenta_los_rangos_por_cantidad_que_apuntan_a_una_categoria_o_subcategoria_del_dueno()
    {
        $categoria = $this->categoria_real('Propia');

        // Un rango general (sin categoría ni subcategoría) y uno del vecino no cuentan.
        DB::table('category_price_type_ranges')->insert(['category_id' => null, 'sub_category_id' => null, 'price_type_id' => 1, 'user_id' => $this->owner->id]);
        DB::table('category_price_type_ranges')->insert(['category_id' => 999, 'sub_category_id' => null, 'price_type_id' => 1, 'user_id' => $this->vecino->id]);
        $this->assertSame([], $this->motivos_de($this->owner->id));

        // Uno por categoría y otro por subcategoría: dos filas.
        DB::table('category_price_type_ranges')->insert(['category_id' => $categoria->id, 'sub_category_id' => null, 'price_type_id' => 1, 'user_id' => $this->owner->id]);
        DB::table('category_price_type_ranges')->insert(['category_id' => null, 'sub_category_id' => 77, 'price_type_id' => 1, 'user_id' => $this->owner->id]);

        $this->assertSame([['codigo' => 'R5', 'cantidad' => 2]], $this->motivos_de($this->owner->id));
    }

    /**
     * R6: descuento de vinculación de inventario por categoría. Sin descuento (NULL o 0), de una
     * categoría borrada o de la vinculación de otro comercio no cuenta.
     *
     * @test
     * @group categorias_ia
     */
    public function r6_cuenta_solo_los_descuentos_de_vinculacion_de_categorias_vivas_del_dueno()
    {
        $vinculacion_propia = $this->vinculacion_de($this->owner);
        $vinculacion_ajena  = $this->vinculacion_de($this->vecino);

        $propia  = $this->categoria_real('Propia');
        $borrada = $this->categoria_real('Borrada');

        DB::table('category_inventory_linkage')->insert(['category_id' => $propia->id, 'inventory_linkage_id' => $vinculacion_propia, 'percentage_discount' => null]);
        DB::table('category_inventory_linkage')->insert(['category_id' => $propia->id, 'inventory_linkage_id' => $vinculacion_propia, 'percentage_discount' => 0]);
        $this->assertSame([], $this->motivos_de($this->owner->id), 'NULL y 0 no cuentan (y la vinculación sola es solo un aviso).');

        DB::table('category_inventory_linkage')->insert(['category_id' => $borrada->id, 'inventory_linkage_id' => $vinculacion_propia, 'percentage_discount' => 10]);
        $borrada->delete();
        DB::table('category_inventory_linkage')->insert(['category_id' => $propia->id, 'inventory_linkage_id' => $vinculacion_ajena, 'percentage_discount' => 10]);
        $this->assertSame([], $this->motivos_de($this->owner->id), 'Ni una categoría borrada ni la vinculación de otro comercio.');

        DB::table('category_inventory_linkage')->insert(['category_id' => $propia->id, 'inventory_linkage_id' => $vinculacion_propia, 'percentage_discount' => 10]);
        $this->assertSame([['codigo' => 'R6', 'cantidad' => 1]], $this->motivos_de($this->owner->id));
    }

    /**
     * Varios motivos a la vez salen cada uno con su código y en el orden R1 a R6; `usa` es true.
     *
     * @test
     * @group categorias_ia
     */
    public function varios_motivos_salen_juntos_en_orden()
    {
        $categoria = $this->categoria_real('Con todo', null, ['percentage_gain' => 10]);
        $this->pivote_de_lista($categoria, 5);
        $this->dar_extension('lista_de_precios_por_categoria');

        $resultado = CategoryMargenesHelper::usa_margenes_por_categoria($this->owner->id);

        $this->assertTrue($resultado['usa']);
        $this->assertSame(
            [['codigo' => 'R1', 'cantidad' => 1], ['codigo' => 'R2', 'cantidad' => 1], ['codigo' => 'R3', 'cantidad' => 1]],
            $resultado['motivos']
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Avisos
    // ---------------------------------------------------------------------------------------------

    /**
     * Los avisos A1 a A3 informan pero NO bloquean: `usa` queda en false.
     *
     * @test
     * @group categorias_ia
     */
    public function los_avisos_no_bloquean()
    {
        $categoria = $this->categoria_real('Con comision');

        // A1: comisión del vendedor por categoría.
        DB::table('category_seller')->insert(['category_id' => $categoria->id, 'seller_id' => 1, 'percentage' => 3]);
        // A2: tiene vinculaciones de inventario (aunque sin descuento).
        $this->vinculacion_de($this->owner);
        // A3: trabaja con listas de precio.
        $this->owner->update(['listas_de_precio' => 1]);

        $resultado = CategoryMargenesHelper::usa_margenes_por_categoria($this->owner->id);

        $this->assertFalse($resultado['usa']);
        $this->assertSame([], $resultado['motivos']);
        $this->assertSame(
            [['codigo' => 'A1', 'cantidad' => 1], ['codigo' => 'A2', 'cantidad' => 1], ['codigo' => 'A3', 'cantidad' => 1]],
            $resultado['avisos']
        );
        $this->assertFalse(CategoryMargenesHelper::bloqueo_para($this->owner->id)['nuevo_modelo_bloqueado']);
    }

    /**
     * A1 con porcentaje 0, de una categoría borrada o de una categoría ajena no avisa.
     *
     * @test
     * @group categorias_ia
     */
    public function el_aviso_a1_ignora_el_cero_las_borradas_y_las_ajenas()
    {
        $propia  = $this->categoria_real('Propia');
        $borrada = $this->categoria_real('Borrada');
        $ajena   = $this->categoria_real('Ajena', $this->vecino);

        DB::table('category_seller')->insert(['category_id' => $propia->id, 'seller_id' => 1, 'percentage' => 0]);
        DB::table('category_seller')->insert(['category_id' => $borrada->id, 'seller_id' => 1, 'percentage' => 5]);
        $borrada->delete();
        DB::table('category_seller')->insert(['category_id' => $ajena->id, 'seller_id' => 1, 'percentage' => 5]);

        $this->assertSame([], CategoryMargenesHelper::usa_margenes_por_categoria($this->owner->id)['avisos']);
    }

    // ---------------------------------------------------------------------------------------------
    // Tenencia: el dueño y sus empleados
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Todo se acota por el dueño: el vecino con TODO cargado (margen, listas, rangos, vinculación)
     * no mueve nada en el comercio del test, y un empleado se resuelve a su dueño.
     *
     * @test
     * @group categorias_ia
     */
    public function todo_se_acota_por_el_dueno_y_un_empleado_se_resuelve_a_su_dueno()
    {
        // El vecino usa de todo...
        $de_vecino = $this->categoria_real('Del vecino', $this->vecino, ['percentage_gain' => 30]);
        $this->pivote_de_lista($de_vecino, 5);
        $this->dar_extension('lista_de_precios_por_categoria', $this->vecino);
        $this->vinculacion_de($this->vecino);

        // ...y el comercio del test, nada.
        $this->assertFalse(CategoryMargenesHelper::usa_margenes_por_categoria($this->owner->id)['usa']);
        $this->assertSame([], CategoryMargenesHelper::usa_margenes_por_categoria($this->owner->id)['avisos']);
        $this->assertFalse(CategoryMargenesHelper::bloqueo_para($this->owner->id)['nuevo_modelo_bloqueado']);

        // El empleado del vecino ve lo del vecino (su dueño), no lo del comercio del test.
        $empleado_del_vecino = $this->crear_empleado_de($this->vecino);
        $this->assertTrue(CategoryMargenesHelper::usa_margenes_por_categoria($empleado_del_vecino->id)['usa']);
        $this->assertSame(
            CategoryMargenesHelper::usa_margenes_por_categoria($this->vecino->id),
            CategoryMargenesHelper::usa_margenes_por_categoria($empleado_del_vecino->id)
        );

        $empleado_del_test = $this->crear_empleado_de($this->owner);
        $this->assertFalse(CategoryMargenesHelper::usa_margenes_por_categoria($empleado_del_test->id)['usa']);
    }

    // ---------------------------------------------------------------------------------------------
    // Tienda Nube
    // ---------------------------------------------------------------------------------------------

    /**
     * Tienda Nube se detecta por la extensión `usa_tienda_nube` del dueño (la que usa el scheduler) o por
     * `config('app.USA_TIENDA_NUBE')` (lo que el plan nombra). La de otro comercio no cuenta y un
     * empleado se resuelve a su dueño.
     *
     * @test
     * @group categorias_ia
     */
    public function tienda_nube_se_detecta_por_la_extension_o_por_el_flag()
    {
        $this->assertFalse(CategoryMargenesHelper::usa_tienda_nube($this->owner->id));

        // La extensión del vecino no es la del comercio del test.
        $this->dar_extension('usa_tienda_nube', $this->vecino);
        $this->assertFalse(CategoryMargenesHelper::usa_tienda_nube($this->owner->id));
        $this->assertTrue(CategoryMargenesHelper::usa_tienda_nube($this->vecino->id));

        // La extensión propia, también para el empleado.
        $this->dar_extension('usa_tienda_nube');
        $this->assertTrue(CategoryMargenesHelper::usa_tienda_nube($this->owner->id));
        $this->assertTrue(CategoryMargenesHelper::usa_tienda_nube($this->crear_empleado_de($this->owner)->id));

        // El flag de config, sin extensión.
        $this->owner->extencions()->detach();
        $this->assertFalse(CategoryMargenesHelper::usa_tienda_nube($this->owner->id));

        config(['app.USA_TIENDA_NUBE' => true]);
        $this->assertTrue(CategoryMargenesHelper::usa_tienda_nube($this->owner->id));
    }

    /**
     * 🔴 Tienda Nube también se detecta por `env('USA_TIENDA_NUBE')`, que es lo que leen los observers de
     * categoría y subcategoría (B-11 del verificador). Un comercio con la variable prendida y SIN la extensión
     * (configuración a medias) no quedaba bloqueado y cada categoría que creaba el aplicar llamaba a Tienda
     * Nube adentro de la transacción. "false", "0" y vacío cuentan como apagado.
     *
     * @test
     * @group categorias_ia
     */
    public function tienda_nube_se_detecta_tambien_por_el_env_que_leen_los_observers()
    {
        // Sin extensión, sin flag de config y con el env apagado (el .env.testing lo tiene en false).
        $this->assertFalse(CategoryMargenesHelper::usa_tienda_nube($this->owner->id));

        // Un empleado del comercio, para comprobar que se resuelve a su dueño.
        $empleado_del_test = $this->crear_empleado_de($this->owner);

        // Los valores que cuentan como apagado.
        foreach (['false', '0', ''] as $apagado) {
            $anterior = $this->prender_tienda_nube($apagado);

            try {
                $this->assertFalse(CategoryMargenesHelper::usa_tienda_nube($this->owner->id), 'USA_TIENDA_NUBE="'.$apagado.'" es apagado.');
                $this->assertFalse(CategoryMargenesHelper::bloqueo_para($this->owner->id)['nuevo_modelo_bloqueado'], 'USA_TIENDA_NUBE="'.$apagado.'" no bloquea.');
            } finally {
                $this->restaurar_tienda_nube($anterior);
            }
        }

        // Los que cuentan como prendido: lo detecta para el dueño y para el empleado, y el bloqueo lo informa.
        foreach (['true', '1'] as $prendido) {
            $anterior = $this->prender_tienda_nube($prendido);

            try {
                $this->assertTrue(CategoryMargenesHelper::usa_tienda_nube($this->owner->id), 'USA_TIENDA_NUBE="'.$prendido.'" es prendido.');
                $this->assertTrue(CategoryMargenesHelper::usa_tienda_nube($empleado_del_test->id));
                $this->assertSame(
                    ['nuevo_modelo_bloqueado' => true, 'motivos' => [['codigo' => 'tienda_nube', 'cantidad' => 1]]],
                    CategoryMargenesHelper::bloqueo_para($this->owner->id)
                );
            } finally {
                $this->restaurar_tienda_nube($anterior);
            }
        }

        // Ya restaurado el entorno, vuelve a estar apagado.
        $this->assertFalse(CategoryMargenesHelper::usa_tienda_nube($this->owner->id));
    }

    // ---------------------------------------------------------------------------------------------
    // El bloqueo
    // ---------------------------------------------------------------------------------------------

    /**
     * `bloqueo_para` junta los motivos de márgenes (R1 a R6) y el de Tienda Nube, en ese orden.
     *
     * @test
     * @group categorias_ia
     */
    public function el_bloqueo_junta_margenes_y_tienda_nube()
    {
        $this->assertSame(
            ['nuevo_modelo_bloqueado' => false, 'motivos' => []],
            CategoryMargenesHelper::bloqueo_para($this->owner->id)
        );

        // Solo Tienda Nube.
        $this->dar_extension('usa_tienda_nube');
        $this->assertSame(
            ['nuevo_modelo_bloqueado' => true, 'motivos' => [['codigo' => 'tienda_nube', 'cantidad' => 1]]],
            CategoryMargenesHelper::bloqueo_para($this->owner->id)
        );

        // Tienda Nube y un margen por categoría.
        $this->categoria_real('Con margen', null, ['percentage_gain' => 10]);
        $this->assertSame(
            [
                'nuevo_modelo_bloqueado' => true,
                'motivos'                => [['codigo' => 'R1', 'cantidad' => 1], ['codigo' => 'tienda_nube', 'cantidad' => 1]],
            ],
            CategoryMargenesHelper::bloqueo_para($this->owner->id)
        );

        // Solo el margen.
        $this->owner->extencions()->detach();
        $this->assertSame(
            ['nuevo_modelo_bloqueado' => true, 'motivos' => [['codigo' => 'R1', 'cantidad' => 1]]],
            CategoryMargenesHelper::bloqueo_para($this->owner->id)
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Advertencias (no bloquean)
    // ---------------------------------------------------------------------------------------------

    /**
     * Las advertencias de §4.6: `vinculacion_de_inventario` (cualquier tipo), `urls_de_la_tienda` (solo
     * un sistema `nueva` sobre un catálogo que ya tenía categorías) y `precios_a_recalcular` (solo
     * "mantener" con márgenes por categoría).
     *
     * @test
     * @group categorias_ia
     */
    public function las_advertencias_dependen_del_tipo_de_propuesta()
    {
        // Sin nada: ninguna advertencia, sea cual sea el tipo.
        foreach ([null, 'nueva', 'mantener'] as $tipo) {
            $this->assertSame([], CategoryMargenesHelper::advertencias_para($this->owner->id, $tipo));
        }

        // Con categorías previas: las URLs de la tienda cambian, pero solo con un sistema nuevo.
        $this->categoria_real('Previa');
        $this->assertSame([], CategoryMargenesHelper::advertencias_para($this->owner->id));
        $this->assertSame(['urls_de_la_tienda'], CategoryMargenesHelper::advertencias_para($this->owner->id, 'nueva'));
        $this->assertSame([], CategoryMargenesHelper::advertencias_para($this->owner->id, 'mantener'));

        // Con vinculación de inventario: vale para cualquier tipo, y va primero.
        $this->vinculacion_de($this->owner);
        $this->assertSame(['vinculacion_de_inventario'], CategoryMargenesHelper::advertencias_para($this->owner->id));
        $this->assertSame(['vinculacion_de_inventario', 'urls_de_la_tienda'], CategoryMargenesHelper::advertencias_para($this->owner->id, 'nueva'));
        $this->assertSame(['vinculacion_de_inventario'], CategoryMargenesHelper::advertencias_para($this->owner->id, 'mantener'));

        // Con márgenes por categoría: "mantener" recalcula precios.
        $this->categoria_real('Con margen', null, ['percentage_gain' => 5]);
        $this->assertSame(['vinculacion_de_inventario', 'precios_a_recalcular'], CategoryMargenesHelper::advertencias_para($this->owner->id, 'mantener'));
    }

    /**
     * Las advertencias también se acotan por el dueño: las categorías y las vinculaciones del vecino no
     * le suman nada al comercio del test.
     *
     * @test
     * @group categorias_ia
     */
    public function las_advertencias_ignoran_lo_del_vecino()
    {
        $this->categoria_real('Del vecino', $this->vecino, ['percentage_gain' => 5]);
        $this->vinculacion_de($this->vecino);

        foreach ([null, 'nueva', 'mantener'] as $tipo) {
            $this->assertSame([], CategoryMargenesHelper::advertencias_para($this->owner->id, $tipo));
        }
    }
}
