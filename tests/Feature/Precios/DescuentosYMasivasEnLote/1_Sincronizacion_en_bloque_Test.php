<?php

namespace Tests\Feature\Precios\DescuentosYMasivasEnLote;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Jobs\ProcessSincronizarDescuentosProveedorJob;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\Provider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Mision `recalculo-precios-motor-rapido` (28/9/2026) — la SINCRONIZACION de descuentos del
 * proveedor (el boton "Sincronizar articulos"), que paso a escribir en bloque: por tanda, UN DELETE,
 * UN INSERT multi-fila y el motor de precios, en una transaccion.
 *
 * 🔴 LO QUE FIJA ESTE ARCHIVO: sobre los mismos datos, el camino nuevo deja la base EXACTAMENTE igual
 * que el de develop (sincronizar_como_hoy(), copiado textual en la base de estos tests): las filas de
 * `article_discounts` de cada articulo (todas las columnas y el ORDEN, que decide como se aplican), y
 * todo lo que escribe un recalculo (articles, pivots de listas, price_changes con sus listas). Y los
 * mismos contadores. En los tres modos (saltear / pisar / agregar), con y sin editados, con listas de
 * precio, y con tandas chicas que cortan en el medio.
 *
 * El escenario tiene a proposito los casos raros que el codigo nuevo resuelve distinto por dentro:
 * un articulo de OTRO proveedor que arrastra descuentos de este, uno de OTRO dueño, uno BORRADO con
 * descuentos colgando (se saltea), uno sin costo (precio manual) y descuentos manuales que no se
 * tocan nunca.
 *
 * Tambien fija el avance del registro visible: "X de Y articulos", por tanda.
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Sincronizacion_en_bloque_Test extends DescuentosYMasivasEnLoteTestCase
{
    /**
     * Arma el escenario completo y deja todos los precios calculados con el camino de siempre.
     *
     * Ficha del proveedor: 15% "Bonif general" y 5% "Pronto pago".
     *
     * @param  array $flags        Flags del dueño principal (listas_de_precio, ...).
     * @param  bool  $con_listas   Si se le crean tres listas de precio al dueño.
     * @return array ['dueno', 'otro_dueno', 'provider', 'articulos' => [nombre => id], 'ids']
     */
    private function escenario(array $flags = [], $con_listas = false)
    {
        /* El otro dueño se crea PRIMERO: crear_dueno() deja logueado al ultimo que crea. */
        $otro_dueno = $this->crear_dueno(['percentage_gain' => 20]);

        $dueno = $this->crear_dueno(array_merge(['percentage_gain' => null], $flags));

        if ($con_listas) {
            $this->crear_lista($dueno, 'Mayorista', 10, 1);
            $this->crear_lista($dueno, 'Minorista', 25, 2, [['percentage' => 3]]);
            $this->crear_lista($dueno, 'Tienda', 40, 3, [['amount' => 50]]);
        }

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);
        $otro     = $this->crear_proveedor($dueno, ['percentage_gain' => 10]);

        $this->descuento_de_la_ficha($provider, 15, 'Bonif general');
        $this->descuento_de_la_ficha($provider, 5, 'Pronto pago');

        $a = [];

        /* Grupo "sin descuentos": nada tagueado a este proveedor. */
        $a['sin_descuentos'] = $this->crear_articulo($dueno, ['cost' => 1000, 'provider_id' => $provider->id])->id;

        $sin_con_manual = $this->crear_articulo($dueno, ['cost' => 777.77, 'provider_id' => $provider->id]);
        $this->descuento_manual($sin_con_manual, 3);
        $a['sin_descuentos_con_manual'] = $sin_con_manual->id;

        $a['sin_costo'] = $this->crear_articulo($dueno, [
            'price' => 1500, 'apply_provider_percentage_gain' => 0, 'provider_id' => $provider->id,
        ])->id;

        /* Desactualizados: la copia vieja de la ficha, sin editar. */
        $desactualizado = $this->crear_articulo($dueno, ['cost' => 1234.56, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($desactualizado, $provider, 10, ['show_in_online' => 1]);
        $a['desactualizado_en_la_tienda'] = $desactualizado->id;

        $desactualizado_con_manual = $this->crear_articulo($dueno, ['cost' => 500, 'provider_id' => $provider->id]);
        $this->descuento_manual($desactualizado_con_manual, null, 20);
        $this->copia_de_la_ficha($desactualizado_con_manual, $provider, 15);
        $a['desactualizado_con_manual'] = $desactualizado_con_manual->id;

        /* Al dia: ya tiene los dos de la ficha. */
        $al_dia = $this->crear_articulo($dueno, ['cost' => 800, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($al_dia, $provider, 15);
        $this->copia_de_la_ficha($al_dia, $provider, 5);
        $a['al_dia'] = $al_dia->id;

        /* Editado a mano. */
        $editado = $this->crear_articulo($dueno, ['cost' => 900, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($editado, $provider, 12, ['editado_a_mano' => 1]);
        $a['editado_a_mano'] = $editado->id;

        /* Con descuentos de compra: un monto (el orden de aplicacion importa) y un porcentaje + ficha. */
        $compra_monto = $this->crear_articulo($dueno, ['cost' => 1500, 'provider_id' => $provider->id]);
        $this->descuento_de_compra($compra_monto, $provider, null, 100);
        $a['compra_con_monto'] = $compra_monto->id;

        $compra_y_ficha = $this->crear_articulo($dueno, ['cost' => 2000, 'provider_id' => $provider->id]);
        $this->descuento_de_compra($compra_y_ficha, $provider, 20);
        $this->copia_de_la_ficha($compra_y_ficha, $provider, 10);
        $a['compra_y_ficha'] = $compra_y_ficha->id;

        /* De OTRO proveedor, arrastrando una copia de la ficha de este (cambio de proveedor). */
        $de_otro_proveedor = $this->crear_articulo($dueno, ['cost' => 600, 'provider_id' => $otro->id]);
        $this->copia_de_la_ficha($de_otro_proveedor, $provider, 10);
        $a['de_otro_proveedor'] = $de_otro_proveedor->id;

        /* BORRADO, con una copia de la ficha colgando: se saltea en los dos caminos. */
        $borrado = $this->crear_articulo($dueno, ['cost' => 700, 'provider_id' => $otro->id]);
        $this->copia_de_la_ficha($borrado, $provider, 10);
        $a['borrado'] = $borrado->id;

        /* De OTRO dueño, con una copia de la ficha de este proveedor (dato raro, pero posible). */
        $de_otro_dueno = $this->crear_articulo($otro_dueno, ['cost' => 950]);
        $this->copia_de_la_ficha($de_otro_dueno, $provider, 10);
        $a['de_otro_dueno'] = $de_otro_dueno->id;

        /* Precios calculados como los de cualquier articulo real, cada uno con su dueño. */
        $del_dueno = array_values(array_diff($a, [$a['de_otro_dueno']]));
        $this->calentar($del_dueno, $dueno->id);
        $this->calentar([$a['de_otro_dueno']], $otro_dueno->id);

        Article::find($a['borrado'])->delete();

        return [
            'dueno'      => $dueno,
            'otro_dueno' => $otro_dueno,
            'provider'   => $provider,
            'articulos'  => $a,
            'ids'        => array_values($a),
        ];
    }

    /**
     * Compara los dos caminos de la sincronizacion con estos parametros.
     *
     * @param  array  $e
     * @param  string $alcance
     * @param  bool   $pisar_editados
     * @param  string $accion
     * @param  string $que
     * @param  int    $pasadas
     * @return array
     */
    private function comparar_sincronizacion(array $e, $alcance, $pisar_editados, $accion, $que, $pasadas = 1)
    {
        $provider_id = $e['provider']->id;

        return $this->comparar_caminos_de(
            $e['ids'],
            function () use ($provider_id, $alcance, $pisar_editados, $accion, $pasadas) {
                $resultados = [];
                for ($i = 0; $i < $pasadas; $i++) {
                    $resultados[] = $this->sincronizar_como_hoy(Provider::find($provider_id), $alcance, $pisar_editados, $accion);
                }
                return $resultados;
            },
            function () use ($provider_id, $alcance, $pisar_editados, $accion, $pasadas) {
                $resultados = [];
                for ($i = 0; $i < $pasadas; $i++) {
                    $resultados[] = ArticleProviderDiscountHelper::sincronizar_a_articulos(Provider::find($provider_id), $alcance, $pisar_editados, $accion);
                }
                return $resultados;
            },
            $que
        );
    }

    /* ==================================================================================
     * EQUIVALENCIA
     * ================================================================================== */

    /**
     * @test
     */
    public function todos_con_saltear_deja_la_misma_base_que_el_camino_de_hoy()
    {
        $e = $this->escenario();

        $r = $this->comparar_sincronizacion($e, ArticleProviderDiscountHelper::ALCANCE_TODOS, false, ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR, 'Todos + saltear');

        /* Precondiciones del escenario: que de verdad haya pasado por cada grupo. */
        $this->assertSame(3, $r['nuevo'][0]['creados'], 'sin_descuentos, con manual y sin costo.');
        $this->assertSame(4, $r['nuevo'][0]['actualizados'], 'Los dos desactualizados, el de otro proveedor y el de otro dueño (el borrado no).');
        $this->assertSame(1, $r['nuevo'][0]['respetados']);
        $this->assertSame(2, $r['nuevo'][0]['de_compra_salteados']);

        /* El manual y el "mostrar en la tienda" sobreviven, que es lo que el comercio ve. */
        $descuentos = $r['foto']['descuentos'];

        $manuales = array_values(array_filter($descuentos[$e['articulos']['sin_descuentos_con_manual']], function ($fila) {
            return is_null($fila['provider_id']);
        }));
        $this->assertCount(1, $manuales, 'El descuento manual no se toca.');

        foreach ($descuentos[$e['articulos']['desactualizado_en_la_tienda']] as $fila) {
            $this->assertSame(1, (int) $fila['show_in_online'], 'Los nuevos nacen visibles en la tienda si el reemplazado lo era.');
        }

        $this->assertCount(1, $descuentos[$e['articulos']['borrado']], 'Al articulo borrado no se le toca nada.');
    }

    /**
     * @test
     */
    public function todos_con_pisar_y_editados_deja_la_misma_base_que_el_camino_de_hoy()
    {
        $e = $this->escenario();

        $r = $this->comparar_sincronizacion($e, ArticleProviderDiscountHelper::ALCANCE_TODOS, true, ArticleProviderDiscountHelper::ACCION_COMPRAS_PISAR, 'Todos + pisar + editados');

        $this->assertSame(5, $r['nuevo'][0]['actualizados'], 'Los cuatro desactualizados y el editado a mano.');
        $this->assertSame(2, $r['nuevo'][0]['de_compra_pisados']);
    }

    /**
     * El modo que MAS depende del orden: "agregar" deja el descuento de la compra (id viejo) y le
     * suma los de la ficha (ids nuevos). Con un monto de por medio, el orden cambia el costo.
     *
     * @test
     */
    public function solo_con_descuentos_con_agregar_deja_la_misma_base_que_el_camino_de_hoy()
    {
        $e = $this->escenario();

        $r = $this->comparar_sincronizacion($e, ArticleProviderDiscountHelper::ALCANCE_SOLO_CON_DESCUENTOS, false, ArticleProviderDiscountHelper::ACCION_COMPRAS_AGREGAR, 'Solo con descuentos + agregar');

        $this->assertSame(0, $r['nuevo'][0]['creados']);
        $this->assertSame(2, $r['nuevo'][0]['de_compra_agregados']);
    }

    /**
     * Con listas de precio (pivots y price_change_price_type en juego) e IVA al costo.
     *
     * @test
     */
    public function con_listas_de_precio_deja_la_misma_base_que_el_camino_de_hoy()
    {
        $e = $this->escenario(['listas_de_precio' => 1, 'aplicar_iva_al_costo' => 1], true);

        $r = $this->comparar_sincronizacion($e, ArticleProviderDiscountHelper::ALCANCE_TODOS, true, ArticleProviderDiscountHelper::ACCION_COMPRAS_AGREGAR, 'Con listas');

        $this->assertNotEmpty($r['foto']['pivots'], 'Precondicion: hay pivots de listas que comparar.');
        $this->assertNotEmpty($r['foto']['cambios'], 'Precondicion: hay price_changes que comparar.');
    }

    /**
     * Tandas de a 2 (la misma config que usan los dos caminos): el corte cae en el medio de cada
     * grupo, y la segunda pasada no tiene que cambiar nada en ninguno.
     *
     * @test
     */
    public function con_tandas_chicas_y_dos_pasadas_deja_la_misma_base_que_el_camino_de_hoy()
    {
        config(['app.SINCRONIZAR_DESCUENTOS_PROVEEDOR_LOTE' => 2]);

        $e = $this->escenario(['redondear_precios_en_decenas' => 1]);

        $r = $this->comparar_sincronizacion($e, ArticleProviderDiscountHelper::ALCANCE_TODOS, false, ArticleProviderDiscountHelper::ACCION_COMPRAS_PISAR, 'Tandas de a 2', 2);

        $segunda = $r['nuevo'][1];

        $this->assertSame(0, $segunda['creados'], 'La segunda pasada no crea nada.');
        $this->assertSame(0, $segunda['actualizados'], 'La segunda pasada no actualiza nada.');
    }

    /* ==================================================================================
     * EN BLOQUE DE VERDAD
     * ================================================================================== */

    /**
     * 🔴 La escritura de descuentos es por TANDA, no por articulo: con 5 articulos desactualizados
     * y tandas de 2 son 3 DELETE y 3 INSERT en `article_discounts`, nunca 5 de cada uno.
     *
     * @test
     */
    public function los_descuentos_se_escriben_con_un_delete_y_un_insert_por_tanda()
    {
        config(['app.SINCRONIZAR_DESCUENTOS_PROVEEDOR_LOTE' => 2]);

        $dueno = $this->crear_dueno();
        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);
        $this->descuento_de_la_ficha($provider, 15, 'Bonif general');
        $this->descuento_de_la_ficha($provider, 5, 'Pronto pago');

        $ids = [];

        for ($i = 1; $i <= 5; $i++) {
            $article = $this->crear_articulo($dueno, ['cost' => 1000 + $i, 'provider_id' => $provider->id]);
            $this->copia_de_la_ficha($article, $provider, 10);
            $ids[] = $article->id;
        }

        $this->calentar($ids, $dueno->id);

        DB::enableQueryLog();

        $resultado = ArticleProviderDiscountHelper::sincronizar_a_articulos($provider->fresh(), ArticleProviderDiscountHelper::ALCANCE_SOLO_CON_DESCUENTOS);

        $consultas = DB::getQueryLog();

        DB::disableQueryLog();

        $this->assertSame(5, $resultado['actualizados'], 'Precondicion: los 5 desactualizados.');

        $deletes = array_filter($consultas, function ($q) {
            return stripos($q['query'], 'delete from `article_discounts`') === 0;
        });

        $inserts = array_filter($consultas, function ($q) {
            return stripos($q['query'], 'insert into `article_discounts`') === 0;
        });

        $this->assertCount(3, $deletes, '5 articulos en tandas de 2: 3 DELETE, no uno por articulo.');
        $this->assertCount(3, $inserts, '5 articulos en tandas de 2: 3 INSERT multi-fila, no uno por descuento.');

        foreach ($ids as $id) {
            $this->assertCount(2, $this->descuentos_de($id), 'Cada articulo queda con los dos de la ficha.');
        }
    }

    /* ==================================================================================
     * AVANCE
     * ================================================================================== */

    /**
     * El helper avisa el total de lo que va a tocar (con 0 procesados) y despues una vez por tanda,
     * acumulando, hasta llegar al total. El borrado no cuenta: no se va a tocar.
     *
     * @test
     */
    public function el_avance_se_informa_con_el_total_y_una_vez_por_tanda()
    {
        config(['app.SINCRONIZAR_DESCUENTOS_PROVEEDOR_LOTE' => 2]);

        $e = $this->escenario();

        $avisos = [];

        ArticleProviderDiscountHelper::sincronizar_a_articulos(
            Provider::find($e['provider']->id),
            ArticleProviderDiscountHelper::ALCANCE_TODOS,
            false,
            ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR,
            function ($procesados, $total) use (&$avisos) {
                $avisos[] = [$procesados, $total];
            }
        );

        /*
         * Todos + saltear: 3 sin descuentos (tandas 2 + 1) y 4 desactualizados (2 + 2). Total 7.
         * Avisos: el inicial y uno por cada una de las 4 tandas.
         */
        $this->assertSame([[0, 7], [2, 7], [3, 7], [5, 7], [7, 7]], $avisos);
    }

    /**
     * Por el job: el registro visible termina con el total de articulos tocados, en "artículos".
     *
     * @test
     */
    public function el_job_deja_el_registro_visible_con_total_y_procesados()
    {
        Notification::fake();

        $e = $this->escenario();

        (new ProcessSincronizarDescuentosProveedorJob(
            $e['provider']->id,
            $e['dueno']->id,
            $e['dueno']->id,
            ArticleProviderDiscountHelper::ALCANCE_TODOS,
            false,
            ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR,
            'op-' . uniqid()
        ))->handle();

        $proceso = BackgroundProcessHelper::por_referencia(Provider::find($e['provider']->id), false);

        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame('artículos', $proceso->unidad);
        $this->assertSame(7, (int) $proceso->total, 'El total son los articulos que se iban a tocar.');
        $this->assertSame(7, (int) $proceso->procesados);
        $this->assertSame(100, (int) $proceso->porcentaje);
    }

    /**
     * Un aviso de avance que tira no frena la sincronizacion (el avance es presentacion).
     *
     * @test
     */
    public function un_aviso_de_avance_que_tira_no_frena_la_sincronizacion()
    {
        $e = $this->escenario();

        $resultado = ArticleProviderDiscountHelper::sincronizar_a_articulos(
            Provider::find($e['provider']->id),
            ArticleProviderDiscountHelper::ALCANCE_TODOS,
            false,
            ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR,
            function ($procesados, $total) {
                throw new \RuntimeException('el aviso se cayo');
            }
        );

        $this->assertSame(3, $resultado['creados']);
        $this->assertSame(4, $resultado['actualizados']);
    }

    /**
     * 🔴 ATOMICIDAD DE LA TANDA (chequeo de mutantes del 29/9/2026, D06): los descuentos nuevos y los
     * precios que salen de ellos se escriben JUNTOS o no se escribe nada. Si el recalculo de la tanda
     * tira a mitad de camino, el DELETE y el INSERT de descuentos de esa tanda se deshacen con el:
     * sin la transaccion que los une, los articulos quedarian con los descuentos nuevos y el costo y
     * el precio calculados con los viejos, sin nada que lo avise.
     *
     * El recalculo se hace tirar con un impuesto sobre ventas del 100 % en el articulo del medio (la
     * cadena de precios divide por cero), como el test de la tanda que tira del motor. Los tres
     * articulos caen en la misma tanda (la del motor, sin forzar la de la sincronizacion).
     *
     * @test
     */
    public function una_tanda_que_tira_no_deja_descuentos_nuevos_sin_su_precio()
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1]);
        $this->crear_lista($dueno, 'Lista', 30, 1);

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);
        $this->descuento_de_la_ficha($provider, 15, 'Bonif');

        $ids = [];

        for ($i = 0; $i < 3; $i++) {

            $article = $this->crear_articulo($dueno, ['cost' => 1000 + $i, 'provider_id' => $provider->id]);

            /* Copia vieja de la ficha (10 contra 15): desactualizado, la sincronizacion lo rehace. */
            $this->copia_de_la_ficha($article, $provider, 10);

            $ids[] = $article->id;
        }

        $this->calentar($ids, $dueno->id);

        /* El del medio revienta al calcular: impuesto del 100 % solo para el (division por cero). */
        $impuesto = $this->impuesto_sobre_ventas($dueno, 100, false);
        DB::table('article_sale_tax')->insert(['article_id' => $ids[1], 'sale_tax_id' => $impuesto->id]);

        $marca = (int) DB::table('price_changes')->max('id');

        $antes = $this->foto_completa($ids, $marca);

        $tiro = null;

        try {
            ArticleProviderDiscountHelper::sincronizar_a_articulos(
                Provider::find($provider->id),
                ArticleProviderDiscountHelper::ALCANCE_SOLO_CON_DESCUENTOS
            );
        } catch (\Throwable $e) {
            $tiro = $e;
        }

        $this->assertNotNull($tiro, 'Precondicion: el articulo con el impuesto del 100 % tenia que tirar.');

        $despues = $this->foto_completa($ids, $marca);

        $this->assertEquals(
            $antes['descuentos'],
            $despues['descuentos'],
            'La tanda que tiro dejo los descuentos nuevos escritos sin su precio.'
        );

        $this->assertEquals($antes['articles'], $despues['articles'], 'Ni un costo ni un precio de esa tanda puede haber quedado escrito.');
        $this->assertEquals($antes['cambios'], $despues['cambios'], 'Ni un price_change.');
    }

    /**
     * Los descuentos de un articulo, en orden de id (atajo para las aserciones).
     *
     * @param  int $article_id
     * @return \Illuminate\Support\Collection
     */
    private function descuentos_de($article_id)
    {
        return \App\Models\ArticleDiscount::where('article_id', $article_id)->orderBy('id')->get();
    }
}
