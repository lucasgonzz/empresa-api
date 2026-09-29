<?php

namespace Tests\Feature\Precios\DescuentosYMasivasEnLote;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Article;
use App\Models\MasiveUpdate;
use App\Models\User;
use App\Services\Filter\FilterHistoryService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Mision `recalculo-precios-motor-rapido` (28/9/2026) — la ACTUALIZACION MASIVA de articulos y su
 * REVERSION, que dejaron de correr un setFinalPrice() por articulo: los cambios se aplican y se
 * guardan como siempre, y el precio se recalcula por tandas con el motor en bloque, con el
 * `employee_id` de la masiva y el dueño resuelto una vez.
 *
 * 🔴 LO QUE FIJA ESTE ARCHIVO: sobre los mismos datos, la masiva nueva deja la base EXACTAMENTE igual
 * que la de develop (masiva_como_hoy() / revertir_como_hoy(), copiadas textual de develop mas abajo):
 * precios, pivots de listas, price_changes con su `employee_id`, descuentos materializados, el
 * historial de la masiva (masive_update_article con sus changes_json) y los contadores de la masiva.
 *
 * Casos: masiva de costo con listas de precio y un empleado; masiva que asigna proveedor con la
 * preferencia prendida (la materializacion de descuentos va ANTES del precio); las dos reversiones; y
 * tandas del motor de a 2 para que el corte caiga en el medio.
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Masiva_en_lote_Test extends DescuentosYMasivasEnLoteTestCase
{
    /* ------------------------------------------------------------------------------------------
     * Las referencias: MasiveUpdateHelper de develop
     * ---------------------------------------------------------------------------------------- */

    /**
     * 🔴 REFERENCIA: MasiveUpdateHelper::process_update() de develop (commit base 496acac0), sin las
     * llamadas al registro visible (BackgroundProcessHelper: escribe `background_processes`, que no
     * entra en la comparacion, y usa metodos protegidos). Todo lo demas, textual: el setFinalPrice()
     * POR ARTICULO, en el lugar, antes del attach.
     *
     * @param  \App\Models\MasiveUpdate $masive_update
     * @return void
     */
    private function masiva_como_hoy(MasiveUpdate $masive_update)
    {
        $masive_update->status = 'processing';
        $masive_update->save();

        $criteria = json_decode($masive_update->criteria_json, true);
        $update_form = isset($criteria['update_form']) ? $criteria['update_form'] : [];

        $resolved = MasiveUpdateHelper::resolve_models_from_criteria($masive_update);
        $models = $resolved['models'];
        $used_filters = $resolved['used_filters'];

        $model_name = $masive_update->model_name;

        if (count($models) >= 3000) {
            throw new Exception('No se permitio actualizar ' . count($models) . ' registros');
        }

        $affected_count = 0;
        $changes_count = 0;
        $non_article_items = [];

        $user_del_comercio = $model_name == 'article' ? User::find($masive_update->user_id) : null;

        foreach ($models as $model) {

            if (!$model) {
                continue;
            }

            $article_changes = [];
            $model_changes = [];
            $model_had_changes = false;

            $provider_id_previo = $model_name == 'article' ? $model->provider_id : null;

            foreach ($update_form as $form) {
                $change = MasiveUpdateHelper::apply_form_change($model, $form, $user_del_comercio, $masive_update->employee_id);
                if ($change) {
                    $model_had_changes = true;
                    $changes_count++;
                    $change_payload = [
                        'old' => $change['old_value'],
                        'new' => $change['new_value'],
                        'operation' => $change['operation'],
                        'form_key' => $change['form_key'],
                    ];
                    if ($model_name == 'article') {
                        $article_changes[$change['prop_key']] = $change_payload;
                    } else {
                        $model_changes[$change['prop_key']] = $change_payload;
                    }
                }
            }

            if ($model_had_changes) {
                if ($model_name == 'article') {
                    ArticleProviderDiscountHelper::aplicar_al_asignar_proveedor(
                        $model,
                        $provider_id_previo,
                        $user_del_comercio
                    );

                    ArticleHelper::setFinalPrice(
                        $model,
                        $masive_update->user_id,
                        null,
                        $masive_update->employee_id
                    );
                    TiendaNubeSyncArticleService::add_article_to_sync($model);
                    $masive_update->articles()->attach($model->id, [
                        'changes_json' => json_encode($article_changes),
                    ]);
                } else {
                    $non_article_items[] = [
                        'model_id' => $model->id,
                        'changes' => $model_changes,
                    ];
                }
                $affected_count++;
            }
        }

        $criteria['used_filters_resolved'] = $used_filters;
        $masive_update->criteria_json = json_encode($criteria);
        $masive_update->non_article_items_json = count($non_article_items)
            ? json_encode($non_article_items)
            : null;
        $masive_update->affected_count = $affected_count;
        $masive_update->changes_count = $changes_count;
        $masive_update->status = 'completed';
        $masive_update->error_message = null;
        $masive_update->save();

        if ($model_name == 'article') {
            FilterHistoryService::log_action([
                'user_id' => $masive_update->user_id,
                'auth_user_id' => $masive_update->employee_id,
                'action' => 'actualizacion',
                'model_name' => 'article',
                'filtrados_count' => count($models),
                'afectados_count' => $changes_count,
                'used_filters' => $used_filters,
            ]);
        }
    }

    /**
     * 🔴 REFERENCIA: MasiveUpdateHelper::process_revert() + revert_article_pivot_changes() de develop,
     * sin el registro visible. La rama del stock (que usa metodos protegidos) no se copia: estos
     * tests no revierten stock, y si alguno lo intentara la referencia falla en vez de mentir.
     *
     * @param  \App\Models\MasiveUpdate $revert_masive_update
     * @param  \App\Models\MasiveUpdate $parent_masive_update
     * @return void
     */
    private function revertir_como_hoy(MasiveUpdate $revert_masive_update, MasiveUpdate $parent_masive_update)
    {
        $revert_masive_update->status = 'processing';
        $revert_masive_update->save();

        $parent_masive_update->load('articles');

        $user_del_comercio = User::find($parent_masive_update->user_id);

        foreach ($parent_masive_update->articles as $article) {

            $changes = json_decode($article->pivot->changes_json, true);
            if (!is_array($changes)) {
                continue;
            }

            $model = Article::where('id', $article->id)
                ->where('user_id', $parent_masive_update->user_id)
                ->first();

            if (!$model) {
                continue;
            }

            $revert_changes = [];

            foreach ($changes as $prop_key => $change) {
                if (!is_array($change) || !array_key_exists('old', $change)) {
                    continue;
                }
                $old_before_revert = $model->{$prop_key};

                if ($prop_key === 'stock') {
                    $this->fail('La referencia de la reversion no cubre stock.');
                }

                $model->{$prop_key} = $change['old'];
                $revert_changes[$prop_key] = [
                    'old' => $old_before_revert,
                    'new' => $change['old'],
                    'operation' => 'revert',
                ];
            }

            $model->save();

            if (isset($revert_changes['provider_id'])) {

                $provider_id_de_la_masiva = $revert_changes['provider_id']['old'];

                if (!is_null($model->provider_id)) {

                    ArticleProviderDiscountHelper::aplicar_al_asignar_proveedor(
                        $model,
                        $provider_id_de_la_masiva,
                        $user_del_comercio
                    );
                } else {

                    ArticleProviderDiscountHelper::revertir_materializacion_de_masiva(
                        $model,
                        $provider_id_de_la_masiva,
                        $user_del_comercio
                    );
                }
            }

            ArticleHelper::setFinalPrice(
                $model,
                $parent_masive_update->user_id,
                null,
                $revert_masive_update->employee_id
            );
            TiendaNubeSyncArticleService::add_article_to_sync($model);

            $revert_masive_update->articles()->attach($model->id, [
                'changes_json' => json_encode($revert_changes),
            ]);
        }

        $parent_masive_update->status = 'reverted';
        $parent_masive_update->reverted_at = now();
        $parent_masive_update->save();

        $revert_masive_update->affected_count = $parent_masive_update->affected_count;
        $revert_masive_update->changes_count = $parent_masive_update->changes_count;
        $revert_masive_update->status = 'completed';
        $revert_masive_update->save();
    }

    /* ------------------------------------------------------------------------------------------
     * Armado
     * ---------------------------------------------------------------------------------------- */

    /**
     * @param  \App\Models\User $dueno
     * @param  string           $nombre
     * @return \App\Models\User
     */
    private function empleado($dueno, $nombre)
    {
        return User::create([
            'name'         => 'zz ' . $nombre,
            'company_name' => 'zz Comercio recalculo en lote',
            'email'        => 'masiva-lote-' . uniqid('', true) . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $dueno->id,
            'admin_access' => 1,
        ]);
    }

    /**
     * Una masiva pendiente por seleccion manual, como la deja el endpoint.
     *
     * @param  \App\Models\User $dueno
     * @param  \App\Models\User $empleado
     * @param  array            $ids
     * @param  array            $update_form
     * @return \App\Models\MasiveUpdate
     */
    private function masiva_pendiente($dueno, $empleado, array $ids, array $update_form)
    {
        return MasiveUpdateHelper::create_pending_update($dueno->id, $empleado->id, 'article', false, [
            'from_filter'        => false,
            'used_filters'       => [['key' => 'Seleccion manual']],
            'update_form'        => $update_form,
            'models_id'          => $ids,
            'resolved_models_id' => $ids,
            'filter_form'        => [],
        ]);
    }

    /**
     * Lo que la masiva escribe fuera de los precios: su fila y su historial por articulo.
     *
     * @param  int $masive_update_id
     * @return callable
     */
    private function historial_de($masive_update_id)
    {
        return function () use ($masive_update_id) {

            $masiva = (array) DB::table('masive_updates')->where('id', $masive_update_id)->first();

            unset($masiva['id']);

            $filas = [];

            foreach (DB::table('masive_update_article')->where('masive_update_id', $masive_update_id)->orderBy('id')->get() as $fila) {
                $fila = (array) $fila;
                unset($fila['id']);
                $filas[] = $fila;
            }

            return ['masiva' => $masiva, 'articulos' => $filas];
        };
    }

    /**
     * Catalogo de la masiva: margenes, precio manual, descuentos, dolar y sin costo.
     *
     * @param  \App\Models\User $dueno
     * @return array [nombre => id]
     */
    private function catalogo($dueno)
    {
        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);

        $a = [];

        $a['margen'] = $this->crear_articulo($dueno, ['cost' => 2000, 'percentage_gain' => 50])->id;
        $a['margen_proveedor'] = $this->crear_articulo($dueno, ['cost' => 800, 'provider_id' => $provider->id])->id;

        $con_descuentos = $this->crear_articulo($dueno, ['cost' => 1234.56, 'percentage_gain' => 35]);
        $this->descuento_manual($con_descuentos, 10);
        $this->descuento_manual($con_descuentos, null, 25);
        $a['con_descuentos'] = $con_descuentos->id;

        $a['precio_manual'] = $this->crear_articulo($dueno, ['cost' => 1200, 'price' => 999, 'apply_provider_percentage_gain' => 0])->id;
        $a['en_dolares'] = $this->crear_articulo($dueno, ['cost' => 12.5, 'cost_in_dollars' => 1, 'percentage_gain' => 40])->id;
        $a['sin_costo'] = $this->crear_articulo($dueno, ['price' => 1500, 'apply_provider_percentage_gain' => 0])->id;

        $this->calentar(array_values($a), $dueno->id);

        return $a;
    }

    /* ------------------------------------------------------------------------------------------
     * Tests
     * ---------------------------------------------------------------------------------------- */

    /**
     * La masiva de costo (+5%), la de uso diario ("el proveedor aumento"), con listas de precio y
     * lanzada por un empleado: mismos precios, pivots, price_changes (con SU employee_id) e
     * historial que hoy.
     *
     * @test
     */
    public function la_masiva_de_costo_deja_la_misma_base_que_hoy_con_el_employee_id_del_que_la_lanzo()
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1]);
        $this->crear_lista($dueno, 'Mayorista', 10, 1);
        $this->crear_lista($dueno, 'Minorista', 25, 2, [['percentage' => 3]]);

        $a = $this->catalogo($dueno);
        $empleado = $this->empleado($dueno, 'Empleado que lanza');

        $masiva = $this->masiva_pendiente($dueno, $empleado, array_values($a), [
            ['type' => 'number', 'key' => 'increment_cost', 'value' => 5],
        ]);

        $masiva_id = $masiva->id;

        $r = $this->comparar_caminos_de(
            array_values($a),
            function () use ($masiva_id) {
                $this->masiva_como_hoy(MasiveUpdate::find($masiva_id));
            },
            function () use ($masiva_id) {
                MasiveUpdateHelper::process_update(MasiveUpdate::find($masiva_id));
            },
            'Masiva de costo',
            ['historial' => $this->historial_de($masiva_id)]
        );

        $this->assertNotEmpty($r['foto']['cambios'], 'Precondicion: la masiva cambio precios.');
        $this->assertNotEmpty($r['foto']['pivots'], 'Precondicion: hay pivots de listas.');

        foreach ($r['foto']['cambios'] as $cambios) {
            foreach ($cambios as $cambio) {
                $this->assertSame((int) $empleado->id, (int) $cambio['employee_id'], 'El price_change queda a nombre de quien lanzo la masiva.');
            }
        }

        $this->assertSame(5, (int) $r['foto']['historial']['masiva']['affected_count'], 'Los cinco con costo (el sin costo no cambia).');
    }

    /**
     * 🔴 La masiva que ASIGNA un proveedor con la preferencia prendida: la materializacion de los
     * descuentos de la ficha tiene que estar escrita ANTES de que se calcule el precio. En el camino
     * nuevo el precio se calcula despues, con la tanda: si el motor no viera esos descuentos, el
     * costo real quedaria sin ellos.
     *
     * @test
     */
    public function la_masiva_que_asigna_proveedor_calcula_el_precio_con_los_descuentos_materializados()
    {
        $dueno = $this->crear_dueno(['aplicar_descuentos_proveedor_al_asignar' => 1]);

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);
        $this->descuento_de_la_ficha($provider, 15, 'Bonif general');
        $this->descuento_de_la_ficha($provider, 5, 'Pronto pago');

        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 1000 * $i, 'apply_provider_percentage_gain' => 1])->id;
        }

        $this->calentar($ids, $dueno->id);

        $empleado = $this->empleado($dueno, 'Empleado que asigna');

        $masiva = $this->masiva_pendiente($dueno, $empleado, $ids, [
            ['type' => 'search', 'key' => 'provider_id', 'value' => $provider->id],
        ]);

        $masiva_id = $masiva->id;

        $r = $this->comparar_caminos_de(
            $ids,
            function () use ($masiva_id) {
                $this->masiva_como_hoy(MasiveUpdate::find($masiva_id));
            },
            function () use ($masiva_id) {
                MasiveUpdateHelper::process_update(MasiveUpdate::find($masiva_id));
            },
            'Masiva que asigna proveedor',
            ['historial' => $this->historial_de($masiva_id)]
        );

        foreach ($ids as $id) {
            $this->assertCount(2, $r['foto']['descuentos'][$id], 'Precondicion: se materializaron los dos de la ficha.');
        }

        /* 1000 con 15% y 5% en cascada: 807,50 de costo real. */
        $this->assertEqualsWithDelta(807.50, (float) $r['foto']['articles'][$ids[0]]['costo_real'], 0.001);
    }

    /**
     * La reversion de una masiva de costo, hecha por OTRO empleado: vuelve los precios y deja sus
     * price_changes a nombre del que revierte, igual que hoy.
     *
     * @test
     */
    public function revertir_la_masiva_de_costo_deja_la_misma_base_que_hoy()
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1]);
        $this->crear_lista($dueno, 'Mayorista', 10, 1);

        $a = $this->catalogo($dueno);
        $empleado = $this->empleado($dueno, 'Empleado que lanza');
        $otro = $this->empleado($dueno, 'Empleado que revierte');

        $masiva = $this->masiva_pendiente($dueno, $empleado, array_values($a), [
            ['type' => 'number', 'key' => 'increment_cost', 'value' => 7],
        ]);

        MasiveUpdateHelper::process_update(MasiveUpdate::find($masiva->id));

        $reversion = MasiveUpdateHelper::create_pending_revert(MasiveUpdate::find($masiva->id), $otro->id);

        $masiva_id = $masiva->id;
        $reversion_id = $reversion->id;

        $r = $this->comparar_caminos_de(
            array_values($a),
            function () use ($masiva_id, $reversion_id) {
                $this->revertir_como_hoy(MasiveUpdate::find($reversion_id), MasiveUpdate::find($masiva_id));
            },
            function () use ($masiva_id, $reversion_id) {
                MasiveUpdateHelper::process_revert(MasiveUpdate::find($reversion_id), MasiveUpdate::find($masiva_id));
            },
            'Reversion de la masiva de costo',
            [
                'historial_de_la_reversion' => $this->historial_de($reversion_id),
                'masiva_revertida'          => function () use ($masiva_id) {
                    return DB::table('masive_updates')->where('id', $masiva_id)->value('status');
                },
            ]
        );

        $this->assertSame('reverted', $r['foto']['masiva_revertida']);

        foreach ($r['foto']['cambios'] as $cambios) {
            foreach ($cambios as $cambio) {
                $this->assertSame((int) $otro->id, (int) $cambio['employee_id'], 'El price_change de la reversion queda a nombre de quien revierte.');
            }
        }
    }

    /**
     * La reversion de una masiva que asigno proveedor (null -> proveedor) con la preferencia
     * prendida: barre los descuentos que la masiva materializo y el precio vuelve sin ellos.
     *
     * @test
     */
    public function revertir_la_masiva_que_asigno_proveedor_deja_la_misma_base_que_hoy()
    {
        $dueno = $this->crear_dueno(['aplicar_descuentos_proveedor_al_asignar' => 1]);

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);
        $this->descuento_de_la_ficha($provider, 15);

        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 900 * $i])->id;
        }

        $this->calentar($ids, $dueno->id);

        $empleado = $this->empleado($dueno, 'Empleado');

        $masiva = $this->masiva_pendiente($dueno, $empleado, $ids, [
            ['type' => 'search', 'key' => 'provider_id', 'value' => $provider->id],
        ]);

        MasiveUpdateHelper::process_update(MasiveUpdate::find($masiva->id));

        $reversion = MasiveUpdateHelper::create_pending_revert(MasiveUpdate::find($masiva->id), $empleado->id);

        $masiva_id = $masiva->id;
        $reversion_id = $reversion->id;

        $r = $this->comparar_caminos_de(
            $ids,
            function () use ($masiva_id, $reversion_id) {
                $this->revertir_como_hoy(MasiveUpdate::find($reversion_id), MasiveUpdate::find($masiva_id));
            },
            function () use ($masiva_id, $reversion_id) {
                MasiveUpdateHelper::process_revert(MasiveUpdate::find($reversion_id), MasiveUpdate::find($masiva_id));
            },
            'Reversion de la masiva que asigno proveedor',
            ['historial_de_la_reversion' => $this->historial_de($reversion_id)]
        );

        foreach ($ids as $id) {
            $this->assertCount(0, $r['foto']['descuentos'][$id], 'La reversion barrio los descuentos que habia materializado la masiva.');
        }
    }

    /**
     * Tandas del motor de a 2 (RECALCULO_PRECIOS_LOTE): con 5 articulos la masiva recalcula en el
     * medio del recorrido (2 + 2) y otra vez al final (1). Mismo resultado que hoy.
     *
     * ⚠️ El articulo SIN costo del catalogo queda afuera de ESTA seleccion, y no para esconder nada:
     * `decrement_cost` sobre un costo null lo deja en 0 (apply_form_change() calcula null - 0 = 0,
     * lo guarda y no lo cuenta como cambio), y con el margen que cambia en el mismo guardado ese
     * articulo cae en la unica diferencia declarada entre los dos caminos: el de hoy calculaba con el
     * modelo en memoria y el nuevo con lo guardado. Esa diferencia tiene su propio test, abajo
     * (una_masiva_que_deja_el_costo_en_cero_calcula_el_precio_con_lo_guardado).
     *
     * @test
     */
    public function con_tandas_del_motor_de_a_dos_deja_la_misma_base_que_hoy()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $dueno = $this->crear_dueno(['redondear_precios_en_decenas' => 1]);

        $a = $this->catalogo($dueno);
        unset($a['sin_costo']);

        $empleado = $this->empleado($dueno, 'Empleado');

        $masiva = $this->masiva_pendiente($dueno, $empleado, array_values($a), [
            ['type' => 'number', 'key' => 'decrement_cost', 'value' => 3],
            ['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 45],
        ]);

        $masiva_id = $masiva->id;

        $this->comparar_caminos_de(
            array_values($a),
            function () use ($masiva_id) {
                $this->masiva_como_hoy(MasiveUpdate::find($masiva_id));
            },
            function () use ($masiva_id) {
                MasiveUpdateHelper::process_update(MasiveUpdate::find($masiva_id));
            },
            'Masiva con tandas de a 2',
            ['historial' => $this->historial_de($masiva_id)]
        );
    }

    /**
     * 🔴 LA UNICA DIFERENCIA DECLARADA con el camino de hoy, y es a favor: una masiva que deja un
     * costo en 0 calcula el precio con lo que quedo GUARDADO.
     *
     * El camino de hoy calculaba con el modelo en memoria, donde el costo nuevo es el float 0.0, y
     * setFinalPrice() pregunta `if ($article->cost)`: con 0.0 eso es falso, asi que se salteaba el
     * calculo del costo real y el precio salia del costo real VIEJO. Medido el 28/9/2026 con este
     * mismo escenario: costo 1000 y margen 50 -> masiva "costo = 0, margen = 45" -> quedaba costo 0,
     * costo real 1000 y precio final 1754,50. El recalculo siguiente (dolar, proveedor, lo que
     * fuera) lo llevaba a 0,00, porque lee de la base, donde el costo es '0.000000' (un string no
     * vacio: verdadero). O sea: un precio incoherente con su costo hasta el proximo recalculo.
     *
     * El motor lee de la base, como el recalculo de siempre: la masiva deja de entrada lo que dejaba
     * el recalculo siguiente. Este test fija eso: el resultado de la masiva es estable (un recalculo
     * posterior sobre lo guardado no cambia nada) y el precio sale del costo 0.
     *
     * Tambien cubre el caso que la genera sin querer: un articulo SIN costo al que una masiva de
     * costo le deja 0 (apply_form_change() calcula null * x = 0 y lo guarda sin contarlo como
     * cambio; si otra columna del mismo guardado si cambia, el articulo se recalcula).
     *
     * @test
     */
    public function una_masiva_que_deja_el_costo_en_cero_calcula_el_precio_con_lo_guardado()
    {
        $dueno = $this->crear_dueno();

        $con_costo = $this->crear_articulo($dueno, ['cost' => 1000, 'percentage_gain' => 50])->id;
        $sin_costo = $this->crear_articulo($dueno, ['price' => 1500, 'apply_provider_percentage_gain' => 0])->id;

        $ids = [$con_costo, $sin_costo];

        $this->calentar($ids, $dueno->id);

        $empleado = $this->empleado($dueno, 'Empleado');

        $masiva = $this->masiva_pendiente($dueno, $empleado, $ids, [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 0],
            ['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 45],
        ]);

        MasiveUpdateHelper::process_update(MasiveUpdate::find($masiva->id));

        $despues_de_la_masiva = DB::table('articles')->whereIn('id', $ids)->orderBy('id')
                                    ->get(['id', 'cost', 'costo_real', 'price', 'final_price'])
                                    ->map(function ($fila) { return (array) $fila; })
                                    ->all();

        foreach ($despues_de_la_masiva as $fila) {
            $this->assertEqualsWithDelta(0, (float) $fila['cost'], 0.000001);
            $this->assertNotNull($fila['costo_real'], 'El costo real se calcula con el costo guardado (0), no queda el viejo ni null.');
            $this->assertEqualsWithDelta(0, (float) $fila['costo_real'], 0.000001);
            $this->assertEqualsWithDelta(0, (float) $fila['final_price'], 0.001, 'Con costo 0 el precio es 0, no el que salia del costo real viejo.');
        }

        /* Estable: el recalculo de siempre, leyendo de la base, no tiene nada que corregir. */
        $marca = (int) DB::table('price_changes')->max('id');

        $cambiaron = $this->recalcular_como_hoy($ids, $dueno->id);

        $this->assertSame([], $cambiaron, 'Un recalculo posterior no cambia ningun precio.');
        $this->assertSame(0, DB::table('price_changes')->where('id', '>', $marca)->count());

        $despues_del_recalculo = DB::table('articles')->whereIn('id', $ids)->orderBy('id')
                                    ->get(['id', 'cost', 'costo_real', 'price', 'final_price'])
                                    ->map(function ($fila) { return (array) $fila; })
                                    ->all();

        $this->assertEquals($despues_de_la_masiva, $despues_del_recalculo);
    }

    /**
     * Arma la masiva de costo (+5%) de 5 articulos en tandas de 2 ([0, 1], [2, 3], [4]), calcula con
     * la masiva de develop (en un savepoint) lo que tiene que quedar, y registra el listener que
     * simula las fallas.
     *
     * El deadlock del motor se simula con un listener de consultas que tira SOLO ante la sentencia
     * del motor (`UPDATE `articles` SET ... WHERE `id` IN (...)`, con los ids escritos en el SQL) y
     * solo si la tanda trae al tercer articulo (la segunda tanda). El listener corre despues de
     * ejecutar la sentencia, adentro de la transaccion de la tanda del motor: la excepcion la
     * revierte, igual que un deadlock de verdad. El UPDATE por articulo de Eloquent tiene otra forma
     * (`update `articles` set ... where `id` = ?`, en minusculas) y pasa, salvo que se pida que el
     * tercer articulo tampoco se pueda calcular por articulo: entonces su save() tira ANTES de
     * escribir (evento `saving`), y solo despues de que tiro el motor, para no romper
     * apply_form_change(), que guarda antes.
     *
     * @param  bool $tambien_falla_por_articulo
     * @return array ['ids', 'empleado', 'antes', 'como_develop', 'masiva_id', 'marca', 'leer']
     */
    private function masiva_con_un_motor_que_tira($tambien_falla_por_articulo)
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $dueno = $this->crear_dueno(['listas_de_precio' => 1]);
        $this->crear_lista($dueno, 'Mayorista', 10, 1);

        $a = $this->catalogo($dueno);
        unset($a['sin_costo']);

        $ids = array_values($a);

        $empleado = $this->empleado($dueno, 'Empleado que lanza');

        $update_form = [['type' => 'number', 'key' => 'increment_cost', 'value' => 5]];

        $leer = function () use ($ids) {
            $filas = [];
            foreach (DB::table('articles')->whereIn('id', $ids)->orderBy('id')->get(['id', 'cost', 'costo_real', 'final_price']) as $fila) {
                $filas[(int) $fila->id] = (array) $fila;
            }
            return $filas;
        };

        $antes = $leer();

        /* Lo que tiene que quedar en los articulos que se procesan: la misma masiva por develop. */
        $referencia = $this->masiva_pendiente($dueno, $empleado, $ids, $update_form);

        DB::beginTransaction();
        $this->masiva_como_hoy(MasiveUpdate::find($referencia->id));
        $como_develop = $leer();
        DB::rollBack();

        $this->limpiar_estado_del_proceso();

        $masiva = $this->masiva_pendiente($dueno, $empleado, $ids, $update_form);

        $marca = (int) DB::table('price_changes')->max('id');

        $envenenado = $ids[2];
        $motor_tiro = false;

        DB::listen(function ($query) use ($envenenado, &$motor_tiro) {

            if (strpos($query->sql, 'UPDATE `articles` SET') === 0
                && preg_match('/WHERE `id` IN \(([^)]*)\)/', $query->sql, $coincidencia)
                && in_array($envenenado, array_map('intval', explode(',', $coincidencia[1])), true)) {

                $motor_tiro = true;

                throw new \RuntimeException('Deadlock simulado contra una venta');
            }
        });

        /*
         * La falla por articulo va en el evento `saving` de Eloquent y NO en el listener de consultas:
         * ese listener corre DESPUES de ejecutar la sentencia, y el camino por articulo no tiene
         * transaccion, asi que el UPDATE ya habria quedado escrito (medido: el costo real se escribia
         * igual). `saving` corre ANTES de escribir, como un error real de la base. El UPDATE en bloque
         * del motor no dispara eventos de Eloquent, asi que esto solo toca al camino por articulo.
         */
        if ($tambien_falla_por_articulo) {

            Article::saving(function ($article) use ($envenenado, &$motor_tiro) {

                if ($motor_tiro && (int) $article->id === $envenenado) {
                    throw new \RuntimeException('Columna desbordada en el articulo');
                }
            });
        }

        return [
            'ids'          => $ids,
            'empleado'     => $empleado,
            'antes'        => $antes,
            'como_develop' => $como_develop,
            'masiva_id'    => $masiva->id,
            'marca'        => $marca,
            'leer'         => $leer,
        ];
    }

    /**
     * 🔴 Si el motor tira en una tanda (un deadlock simulado en su UPDATE en bloque de la SEGUNDA
     * tanda), esa tanda cae al setFinalPrice() por articulo de siempre: sus articulos terminan con el
     * precio recalculado —el mismo que deja la masiva de develop— y la masiva termina en fallo con el
     * mensaje de la excepcion, como hoy (seguimiento del 29/9/2026).
     *
     * Sin el camino por articulo, los dos articulos de esa tanda quedarian con el costo nuevo y el
     * precio viejo, y una masiva fallida no se puede revertir desde la pantalla.
     *
     * @test
     */
    public function si_el_motor_tira_en_una_tanda_esa_tanda_se_recalcula_articulo_por_articulo_y_la_masiva_falla()
    {
        $e = $this->masiva_con_un_motor_que_tira(false);

        $ids = $e['ids'];

        (new \App\Jobs\ProcessMasiveUpdateJob($e['masiva_id']))->handle();

        $masiva = MasiveUpdate::find($e['masiva_id']);

        $this->assertSame('failed', $masiva->status, 'La masiva sigue terminando en fallo, como hoy.');
        $this->assertSame('Deadlock simulado contra una venta', $masiva->error_message, 'Con el mensaje de la excepcion del motor.');

        $despues = call_user_func($e['leer']);

        /* Las dos primeras tandas: costo nuevo Y precio recalculado, igual que develop. */
        foreach ([$ids[0], $ids[1], $ids[2], $ids[3]] as $id) {
            $this->assertEquals(
                $e['como_develop'][$id],
                $despues[$id],
                'El articulo ' . $id . ' tiene que quedar como con la masiva de develop (costo nuevo y precio recalculado).'
            );
            $this->assertNotEquals($e['antes'][$id]['cost'], $despues[$id]['cost'], 'Precondicion: la masiva le cambio el costo.');
        }

        /* La tanda que el motor no pudo escribir se recalculo por articulo, con el employee_id de la masiva. */
        foreach ([$ids[2], $ids[3]] as $id) {

            $cambios = DB::table('price_changes')->where('id', '>', $e['marca'])->where('article_id', $id)->get();

            if ($e['antes'][$id]['final_price'] != $despues[$id]['final_price']) {
                $this->assertCount(1, $cambios, 'Un price_change por el precio que cambio.');
                $this->assertSame((int) $e['empleado']->id, (int) $cambios->first()->employee_id);
            }
        }

        /* La tercera tanda no llego a correr: la masiva corto en la segunda, como corta develop. */
        $this->assertEquals($e['antes'][$ids[4]], $despues[$ids[4]], 'El articulo que venia despues de la tanda que fallo no se toca.');

        /* El historial dice lo que se toco (el attach va antes del precio): los cuatro, no el quinto. */
        $en_el_historial = DB::table('masive_update_article')
                                ->where('masive_update_id', $e['masiva_id'])
                                ->orderBy('id')
                                ->pluck('article_id')
                                ->map(function ($id) { return (int) $id; })
                                ->all();

        $this->assertSame([$ids[0], $ids[1], $ids[2], $ids[3]], $en_el_historial);
    }

    /**
     * El mismo motor que tira, y ademas el tercer articulo tampoco se puede calcular por articulo
     * (su save() tira): ese articulo queda como en develop cuando un articulo falla —costo nuevo,
     * precio viejo—, pero el que viene despues EN LA MISMA TANDA igual queda con su precio (se atrapa
     * por articulo). Y la masiva falla con la PRIMERA excepcion, la del motor, no con la del articulo.
     *
     * @test
     */
    public function si_ademas_un_articulo_no_se_puede_calcular_el_resto_de_la_tanda_igual_queda_con_precio()
    {
        $e = $this->masiva_con_un_motor_que_tira(true);

        $ids = $e['ids'];

        (new \App\Jobs\ProcessMasiveUpdateJob($e['masiva_id']))->handle();

        $masiva = MasiveUpdate::find($e['masiva_id']);

        $this->assertSame('failed', $masiva->status);
        $this->assertSame('Deadlock simulado contra una venta', $masiva->error_message, 'Se relanza la PRIMERA excepcion (la del motor).');

        $despues = call_user_func($e['leer']);

        /* El que no se puede calcular: costo nuevo, costo real y precio de antes. */
        $this->assertEquals($e['como_develop'][$ids[2]]['cost'], $despues[$ids[2]]['cost']);
        $this->assertEquals($e['antes'][$ids[2]]['costo_real'], $despues[$ids[2]]['costo_real']);
        $this->assertEquals($e['antes'][$ids[2]]['final_price'], $despues[$ids[2]]['final_price']);

        /* El siguiente de la misma tanda, y los de la primera: como develop. */
        foreach ([$ids[0], $ids[1], $ids[3]] as $id) {
            $this->assertEquals($e['como_develop'][$id], $despues[$id], 'El articulo ' . $id . ' queda con su precio recalculado.');
        }

        $this->assertEquals($e['antes'][$ids[4]], $despues[$ids[4]]);
    }

}
