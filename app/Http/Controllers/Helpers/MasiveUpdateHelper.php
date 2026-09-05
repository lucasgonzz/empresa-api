<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\MasiveUpdate;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\Filter\FilterHistoryService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MasiveUpdateHelper
{
    /**
     * Crea un registro pendiente de actualización masiva.
     *
     * @param int $owner_user_id
     * @param int $auth_user_id
     * @param string $model_name
     * @param bool $from_filter
     * @param array $criteria
     * @return \App\Models\MasiveUpdate
     */
    public static function create_pending_update($owner_user_id, $auth_user_id, $model_name, $from_filter, $criteria)
    {
        return MasiveUpdate::create([
            'user_id' => (int) $owner_user_id,
            'employee_id' => (int) $auth_user_id,
            'model_name' => $model_name,
            'action' => 'update',
            'status' => 'pending',
            'from_filter' => (bool) $from_filter,
            'criteria_json' => json_encode($criteria),
        ]);
    }

    /**
     * Crea un registro pendiente de reversión sobre una actualización previa.
     *
     * @param \App\Models\MasiveUpdate $parent_masive_update
     * @param int $auth_user_id
     * @return \App\Models\MasiveUpdate
     */
    public static function create_pending_revert(MasiveUpdate $parent_masive_update, $auth_user_id)
    {
        return MasiveUpdate::create([
            'user_id' => (int) $parent_masive_update->user_id,
            'employee_id' => (int) $auth_user_id,
            'model_name' => $parent_masive_update->model_name,
            'action' => 'revert',
            'status' => 'pending',
            'from_filter' => false,
            'parent_masive_update_id' => $parent_masive_update->id,
            'criteria_json' => json_encode([
                'revert_of_masive_update_id' => $parent_masive_update->id,
            ]),
        ]);
    }

    /**
     * Resuelve modelos a actualizar según criterios guardados en el registro.
     *
     * @param \App\Models\MasiveUpdate $masive_update
     * @return array{models: array, used_filters: array}
     */
    public static function resolve_models_from_criteria(MasiveUpdate $masive_update)
    {
        $criteria = json_decode($masive_update->criteria_json, true);
        if (!is_array($criteria)) {
            $criteria = [];
        }

        $model_name = $masive_update->model_name;
        $formated_model_name = GeneralHelper::getModelName($model_name);
        $models = [];
        $used_filters = isset($criteria['used_filters']) ? $criteria['used_filters'] : [];

        $resolved_models_id = isset($criteria['resolved_models_id']) ? $criteria['resolved_models_id'] : [];

        if (count($resolved_models_id)) {
            foreach ($resolved_models_id as $id) {
                $model = $formated_model_name::find($id);
                if ($model) {
                    $models[] = $model;
                }
            }
            return [
                'models' => $models,
                'used_filters' => $used_filters,
            ];
        }

        if ($masive_update->from_filter) {
            $request = Request::create('/', 'PUT', [
                'filter_form' => isset($criteria['filter_form']) ? $criteria['filter_form'] : [],
            ]);
            $search_ct = new SearchController();
            $res = $search_ct->search(
                $request,
                $model_name,
                isset($criteria['filter_form']) ? $criteria['filter_form'] : [],
                0,
                true
            );
            $models = $res['models'];
            $used_filters = $res['used_filters'];
        } else {
            $models_id = isset($criteria['models_id']) ? $criteria['models_id'] : [];
            foreach ($models_id as $id) {
                $model = $formated_model_name::find($id);
                if ($model) {
                    $models[] = $model;
                }
            }
            $used_filters = [
                [
                    'key' => 'Seleccion manual',
                ],
            ];
        }

        return [
            'models' => $models,
            'used_filters' => $used_filters,
        ];
    }

    /**
     * Ejecuta la actualización masiva y persiste cambios por artículo u otros modelos.
     *
     * @param \App\Models\MasiveUpdate $masive_update
     * @return void
     */
    public static function process_update(MasiveUpdate $masive_update)
    {
        $masive_update->status = 'processing';
        $masive_update->save();

        $criteria = json_decode($masive_update->criteria_json, true);
        $update_form = isset($criteria['update_form']) ? $criteria['update_form'] : [];

        $resolved = self::resolve_models_from_criteria($masive_update);
        $models = $resolved['models'];
        $used_filters = $resolved['used_filters'];

        if (count($models) >= 3000) {
            throw new Exception('No se permitio actualizar ' . count($models) . ' registros');
        }

        $model_name = $masive_update->model_name;
        $affected_count = 0;
        $changes_count = 0;
        $non_article_items = [];

        /*
         * Usuario del comercio, resuelto UNA sola vez para toda la masiva.
         *
         * Se resuelve al objeto y no se pasa el id: `debe_aplicar_al_asignar()` hace `User::find()`
         * cuando recibe un id numerico, y aca eso serian hasta 3000 SELECT extra por corrida
         * —tambien con la preferencia apagada, porque la consulta pasa antes de leer la columna—.
         * Con el objeto ya resuelto no hay ninguna query por articulo.
         */
        $user_del_comercio = $model_name == 'article' ? User::find($masive_update->user_id) : null;

        foreach ($models as $model) {
            if (!$model) {
                continue;
            }

            $article_changes = [];
            $model_changes = [];
            $model_had_changes = false;

            /*
             * Proveedor que tenia el articulo ANTES de aplicar los cambios de esta masiva. Se lee
             * aca porque apply_form_change() asigna y guarda de una, asi que despues del foreach ya
             * no hay forma de saber cual era.
             *
             * `provider_id` entra a la masiva porque en el SPA la propiedad tiene
             * `use_to_update: true` (src/models/article.js), y apply_form_change() asigna cualquier
             * prop_key de forma generica (`$model->{$prop_key}`), sin nombrar ninguna columna.
             */
            $provider_id_previo = $model_name == 'article' ? $model->provider_id : null;

            foreach ($update_form as $form) {
                $change = self::apply_form_change($model, $form, $user_del_comercio, $masive_update->employee_id);
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
                    /*
                     * Mision descuentos-proveedor-al-asignar (4/9/2026): cambiar el proveedor en
                     * tanda desde el listado es el mismo acto que cambiarlo de a uno, y el comercio
                     * que prendio la preferencia lo espera igual. Antes del merge de refractor este
                     * camino andaba solo, porque el mecanismo viejo vivia DENTRO del pipeline de
                     * precio (ArticlePricesHelper::aplicar_provider_discounts, eliminada en el
                     * prompt 261) y cualquier setFinalPrice lo disparaba.
                     *
                     * 🔴 El usuario va EXPLICITO. Esto corre en ProcessMasiveUpdateJob, que es
                     * ShouldQueue: en el worker no hay sesion ni Auth::user(), asi que dejar que el
                     * helper resuelva por su cuenta daria false siempre y la preferencia quedaria
                     * muerta acá, sin ningun error que lo delate.
                     *
                     * Va antes de setFinalPrice, que es quien tiene que ver los descuentos nuevos.
                     */
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

        Log::info('MasiveUpdateHelper: actualizacion masiva completada', [
            'masive_update_id' => $masive_update->id,
            'affected_count' => $affected_count,
            'changes_count' => $changes_count,
        ]);
    }

    /**
     * Revierte los cambios registrados en una actualización masiva previa.
     *
     * @param \App\Models\MasiveUpdate $revert_masive_update
     * @param \App\Models\MasiveUpdate $parent_masive_update
     * @return void
     */
    public static function process_revert(MasiveUpdate $revert_masive_update, MasiveUpdate $parent_masive_update)
    {
        $revert_masive_update->status = 'processing';
        $revert_masive_update->save();

        if ($parent_masive_update->model_name != 'article') {
            self::revert_non_article_items($revert_masive_update, $parent_masive_update);
        } else {
            self::revert_article_pivot_changes($revert_masive_update, $parent_masive_update);
        }

        $parent_masive_update->status = 'reverted';
        $parent_masive_update->reverted_at = now();
        $parent_masive_update->save();

        $revert_masive_update->affected_count = $parent_masive_update->affected_count;
        $revert_masive_update->changes_count = $parent_masive_update->changes_count;
        $revert_masive_update->status = 'completed';
        $revert_masive_update->save();
    }

    /**
     * Indica si un valor de formulario numérico/texto trae dato para aplicar.
     *
     * @param mixed $value
     * @return bool
     */
    protected static function form_scalar_value_is_filled($value)
    {
        return !is_null($value) && $value !== '';
    }

    /**
     * Indica si un checkbox de actualización masiva debe aplicarse (solo 0 o 1 explícitos).
     *
     * @param mixed $value
     * @return bool
     */
    protected static function checkbox_value_means_modify($value)
    {
        return $value === 0
            || $value === 1
            || $value === '0'
            || $value === '1';
    }

    /**
     * Si la propiedad que la masiva quiere tocar es el stock de un articulo.
     *
     * @param  mixed   $model
     * @param  string  $prop_key
     * @return bool
     */
    protected static function es_stock_de_articulo($model, $prop_key)
    {
        return $model instanceof Article && $prop_key === 'stock';
    }

    /**
     * Lleva el stock de un articulo al valor que pide la masiva, pero POR UN MOVIMIENTO DE STOCK
     * y no escribiendo la columna (auditoria de stock, 5/9/2026).
     *
     * Escribir `articles.stock` a mano era el unico camino de la aplicacion que cambiaba el stock
     * sin dejar rastro en `stock_movements`: el historial del articulo no explicaba el salto, y en
     * un articulo con depositos la columna quedaba desalineada de la suma de depositos hasta el
     * siguiente movimiento, que la pisaba (era ademas el estado desde el que borrar una venta
     * perdia stock, ver el test 17 de ventas). Por eso:
     *
     *  - Un articulo con stock GLOBAL (sin depositos ni variantes) recibe un "Ingreso manual" por
     *    la diferencia, con la observacion que dice de donde salio. La cantidad ya esta en
     *    unidades (`sin_unidades_individuales`), porque la masiva escribe el stock final que el
     *    usuario ve en el listado, no bultos.
     *  - Un articulo con depositos o variantes NO se toca: su stock es la suma de esas partes y un
     *    numero global no dice a cual deposito o variante va. Se saltea y se deja constancia en el
     *    log; el articulo no cuenta como modificado.
     *
     * @param  \App\Models\Article    $model
     * @param  float                  $nuevo        Stock final que pide la masiva.
     * @param  string                 $operation    set | increment | decrement | revert (para el registro).
     * @param  string                 $form_key
     * @param  \App\Models\User|null  $owner        Dueño del comercio (la masiva corre en cola, sin sesion).
     * @param  int|null               $employee_id  Quien disparo la masiva.
     * @return array|null  El cambio aplicado, con la misma forma que devuelve apply_form_change().
     */
    protected static function aplicar_stock_por_movimiento($model, $nuevo, $operation, $form_key, $owner = null, $employee_id = null)
    {
        $old_value = $model->stock;
        $actual = is_null($old_value) ? 0 : (float) $old_value;
        $delta = (float) $nuevo - $actual;

        if (abs($delta) < 0.0001) {
            return null;
        }

        if (count($model->addresses) >= 1 || count($model->article_variants) >= 1) {
            Log::info('Masiva de stock: el articulo '.$model->id.' reparte por depositos o variantes, no se le puede fijar un stock global. Se saltea.');
            return null;
        }

        $ct = new StockMovementController();
        $ct->crear([
            'model_id'                      => $model->id,
            'amount'                        => $delta,
            'concepto_stock_movement_name'  => 'Ingreso manual',
            'observations'                  => 'Actualizacion masiva del listado ('.$operation.')',
            'sin_unidades_individuales'     => true,
        ], false, $owner, $employee_id);

        // El modelo en memoria sigue en uso por el resto de la masiva (precios, sync).
        $model->stock = $model->fresh()->stock;

        return [
            'prop_key' => 'stock',
            'old_value' => $old_value,
            'new_value' => $model->stock,
            'operation' => $operation,
            'form_key' => $form_key,
        ];
    }

    /**
     * Aplica un ítem del formulario de actualización y devuelve el cambio si hubo modificación.
     *
     * @param object $model
     * @param array $form
     * @return array|null
     */
    public static function apply_form_change($model, $form, $owner = null, $employee_id = null)
    {
        if (!is_array($form) || !isset($form['type']) || !isset($form['key'])) {
            return null;
        }

        if ($form['type'] == 'number' && strpos($form['key'], 'decrement') !== false && self::form_scalar_value_is_filled($form['value'])) {
            $prop_key = substr($form['key'], 10);
            $old_value = $model->{$prop_key};
            $value = $model->{$prop_key} * (float) $form['value'] / 100;
            $nuevo = $model->{$prop_key} - $value;
            if (!empty($form['round'])) {
                $nuevo = round($nuevo, 0, PHP_ROUND_HALF_UP);
            }

            if (self::es_stock_de_articulo($model, $prop_key)) {
                return self::aplicar_stock_por_movimiento($model, $nuevo, 'decrement', $form['key'], $owner, $employee_id);
            }

            $model->{$prop_key} = $nuevo;
            $model->save();

            if ($old_value == $model->{$prop_key}) {
                return null;
            }

            return [
                'prop_key' => $prop_key,
                'old_value' => $old_value,
                'new_value' => $model->{$prop_key},
                'operation' => 'decrement',
                'form_key' => $form['key'],
            ];
        }

        if ($form['type'] == 'number' && strpos($form['key'], 'increment') !== false && self::form_scalar_value_is_filled($form['value'])) {
            $prop_key = substr($form['key'], 10);
            $old_value = $model->{$prop_key};
            $value = $model->{$prop_key} * (float) $form['value'] / 100;
            $nuevo = $model->{$prop_key} + $value;
            if (!empty($form['round'])) {
                $nuevo = round($nuevo, 0, PHP_ROUND_HALF_UP);
            }

            if (self::es_stock_de_articulo($model, $prop_key)) {
                return self::aplicar_stock_por_movimiento($model, $nuevo, 'increment', $form['key'], $owner, $employee_id);
            }

            $model->{$prop_key} = $nuevo;
            $model->save();

            if ($old_value == $model->{$prop_key}) {
                return null;
            }

            return [
                'prop_key' => $prop_key,
                'old_value' => $old_value,
                'new_value' => $model->{$prop_key},
                'operation' => 'increment',
                'form_key' => $form['key'],
            ];
        }

        if ($form['type'] == 'number' && strpos($form['key'], 'set_') !== false && self::form_scalar_value_is_filled($form['value'])) {
            $prop_key = substr($form['key'], 4);
            $old_value = $model->{$prop_key};

            if (self::es_stock_de_articulo($model, $prop_key)) {
                return self::aplicar_stock_por_movimiento($model, (float) $form['value'], 'set', $form['key'], $owner, $employee_id);
            }

            $model->{$prop_key} = (float) $form['value'];
            $model->save();

            if ($old_value == $model->{$prop_key}) {
                return null;
            }

            return [
                'prop_key' => $prop_key,
                'old_value' => $old_value,
                'new_value' => $model->{$prop_key},
                'operation' => 'set',
                'form_key' => $form['key'],
            ];
        }

        if (
            $form['type'] == 'search'
            && strpos($form['key'], '_id') !== false
            && self::form_scalar_value_is_filled($form['value'])
            && $form['value'] != 0
        ) {
            $prop_key = $form['key'];
            $old_value = $model->{$prop_key};
            $model->{$prop_key} = $form['value'];
            $model->save();

            if ($old_value == $model->{$prop_key}) {
                return null;
            }

            return [
                'prop_key' => $prop_key,
                'old_value' => $old_value,
                'new_value' => $model->{$prop_key},
                'operation' => 'set',
                'form_key' => $form['key'],
            ];
        }

        if (
            $form['type'] == 'select'
            && strpos($form['key'], '_id') !== false
            && self::form_scalar_value_is_filled($form['value'])
            && $form['value'] != 0
        ) {
            $prop_key = $form['key'];
            $old_value = $model->{$prop_key};
            $model->{$prop_key} = $form['value'];
            $model->save();

            if ($old_value == $model->{$prop_key}) {
                return null;
            }

            return [
                'prop_key' => $prop_key,
                'old_value' => $old_value,
                'new_value' => $model->{$prop_key},
                'operation' => 'set',
                'form_key' => $form['key'],
            ];
        }

        if ($form['type'] == 'checkbox' && self::checkbox_value_means_modify($form['value'] ?? null)) {
            $prop_key = $form['key'];
            $old_value = $model->{$prop_key};
            $model->{$prop_key} = (int) $form['value'];
            $model->save();

            if ($old_value == $model->{$prop_key}) {
                return null;
            }

            return [
                'prop_key' => $prop_key,
                'old_value' => $old_value,
                'new_value' => $model->{$prop_key},
                'operation' => 'set',
                'form_key' => $form['key'],
            ];
        }

        return null;
    }

    /**
     * Revierte cambios guardados en el pivot de artículos.
     *
     * @param \App\Models\MasiveUpdate $revert_masive_update
     * @param \App\Models\MasiveUpdate $parent_masive_update
     * @return void
     */
    protected static function revert_article_pivot_changes(MasiveUpdate $revert_masive_update, MasiveUpdate $parent_masive_update)
    {
        $parent_masive_update->load('articles');

        /* Mismo motivo que en process_update(): resuelto una vez, cero queries por articulo. */
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
                /*
                 * array_key_exists y no isset (tanda correctivos 2408, ítem 6): isset da false
                 * cuando 'old' existe con valor NULL, que es justo el caso más común de una
                 * masiva (asignar categoría a artículos que no tenían). Con isset, revertir
                 * salteaba esos campos y los artículos quedaban con la categoría asignada.
                 * Restaurar a NULL es restaurar.
                 */
                if (!is_array($change) || !array_key_exists('old', $change)) {
                    continue;
                }
                $old_before_revert = $model->{$prop_key};

                /*
                    El stock se revierte igual que se aplico: con un movimiento, nunca escribiendo
                    la columna. Si el articulo paso a repartir por depositos entre la masiva y su
                    reversion, aplicar_stock_por_movimiento() lo saltea y lo deja en el log.
                */
                if (self::es_stock_de_articulo($model, $prop_key)) {
                    $aplicado = self::aplicar_stock_por_movimiento($model, is_null($change['old']) ? 0 : (float) $change['old'], 'revert', 'revert_stock', $user_del_comercio, $revert_masive_update->employee_id);
                    if ($aplicado) {
                        $revert_changes[$prop_key] = [
                            'old' => $old_before_revert,
                            'new' => $model->stock,
                            'operation' => 'revert',
                        ];
                    }
                    continue;
                }

                $model->{$prop_key} = $change['old'];
                $revert_changes[$prop_key] = [
                    'old' => $old_before_revert,
                    'new' => $change['old'],
                    'operation' => 'revert',
                ];
            }

            $model->save();

            /*
             * Contraparte de la materializacion que hace process_update(): si la masiva que se esta
             * revirtiendo cambio el proveedor, revertirla tiene que devolver tambien los descuentos
             * al estado del proveedor original. Sin esto, revertir dejaria el articulo con el
             * proveedor viejo pero con los descuentos del nuevo — el peor de los dos estados, y
             * ademas silencioso.
             *
             * `$revert_changes['provider_id']['old']` es el proveedor que el articulo tenia JUSTO
             * ANTES de revertir (lo escribe el foreach de arriba como `$old_before_revert`), que es
             * el que hay que barrer; el `provider_id` que quedo en el modelo es el original.
             *
             * 🔴 SON DOS CAMINOS, no uno, y el segundo se descubrio en el revisor de merge:
             *
             *   - Si el proveedor original NO era null (masiva A -> B), alcanza con el helper de
             *     asignacion: barre los de B y recrea los de A.
             *   - Si el proveedor original ERA null (masiva null -> B, que es el caso mas comun de
             *     una masiva: asignarle proveedor a los que no tenian), el helper de asignacion sale
             *     por su guarda de "sin proveedor nuevo no se toca nada" — la que protege al usuario
             *     que le saca el proveedor a un articulo a mano — y los descuentos de B quedaban
             *     huerfanos, con el setFinalPrice() de abajo recalculando el costo con ellos puestos.
             *     Para ese caso va el metodo dedicado, que barre porque ACA sabemos que esos
             *     descuentos los puso esta misma masiva (ver su docblock).
             *
             * Usuario explicito, mismo motivo que en process_update(): esto tambien corre en cola.
             */
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
    }

    /**
     * Revierte cambios de modelos que no son artículo usando JSON almacenado.
     *
     * @param \App\Models\MasiveUpdate $revert_masive_update
     * @param \App\Models\MasiveUpdate $parent_masive_update
     * @return void
     */
    protected static function revert_non_article_items(MasiveUpdate $revert_masive_update, MasiveUpdate $parent_masive_update)
    {
        $items = json_decode($parent_masive_update->non_article_items_json, true);
        if (!is_array($items)) {
            return;
        }

        $formated_model_name = GeneralHelper::getModelName($parent_masive_update->model_name);

        foreach ($items as $item) {
            if (!isset($item['model_id']) || !isset($item['changes'])) {
                continue;
            }
            $model = $formated_model_name::find($item['model_id']);
            if (!$model) {
                continue;
            }
            foreach ($item['changes'] as $prop_key => $change) {
                // Mismo criterio que revert_article_pivot_changes: un old en NULL también
                // se restaura (array_key_exists, no isset).
                if (is_array($change) && array_key_exists('old', $change)) {
                    $model->{$prop_key} = $change['old'];
                }
            }
            $model->save();
        }
    }

    /**
     * Indica si una actualización puede revertirse.
     *
     * @param \App\Models\MasiveUpdate $masive_update
     * @return bool
     */
    public static function can_revert(MasiveUpdate $masive_update)
    {
        if ($masive_update->action != 'update') {
            return false;
        }
        if ($masive_update->status != 'completed') {
            return false;
        }
        if (!is_null($masive_update->reverted_at)) {
            return false;
        }

        $has_completed_revert = $masive_update->child_reverts()
            ->where('action', 'revert')
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->exists();

        return !$has_completed_revert;
    }

    /**
     * Marca el registro como fallido.
     *
     * @param \App\Models\MasiveUpdate $masive_update
     * @param string $error_message
     * @return void
     */
    public static function mark_failed(MasiveUpdate $masive_update, $error_message)
    {
        $masive_update->status = 'failed';
        $masive_update->error_message = $error_message;
        $masive_update->save();
    }

    /**
     * Notifica al owner el resultado de una operación masiva.
     *
     * @param \App\Models\MasiveUpdate $masive_update
     * @param bool $success
     * @param string|null $error_message
     * @return void
     */
    public static function notify_result(MasiveUpdate $masive_update, $success, $error_message = null)
    {
        $owner_user = User::find($masive_update->user_id);
        if (!$owner_user) {
            return;
        }

        $is_revert = $masive_update->action == 'revert';
        $model_label = $masive_update->model_name == 'article' ? 'artículos' : $masive_update->model_name;

        if ($success) {
            $message = $is_revert
                ? 'La reversión de la actualización masiva finalizó correctamente'
                : 'La actualización masiva de ' . $model_label . ' finalizó correctamente';

            $info_to_show = [
                [
                    'title' => 'Resultado',
                    'parrafos' => [
                        'Registros afectados: ' . (int) $masive_update->affected_count,
                        'Cambios aplicados: ' . (int) $masive_update->changes_count,
                    ],
                ],
            ];

            $entendido_button = [
                'btn_text' => 'Entendido',
                'btn_variant' => 'primary',
            ];

            if ($masive_update->model_name == 'article') {
                $entendido_button['function_name'] = 'refresh_articles_after_masive_update';
            }

            $functions_to_execute = [
                [
                    'btn_text' => 'Ver historial',
                    'btn_variant' => 'outline-primary',
                    'function_name' => 'open_masive_update_history',
                ],
                $entendido_button,
            ];
        } else {
            $message = $is_revert
                ? 'No se pudo completar la reversión de la actualización masiva'
                : 'No se pudo completar la actualización masiva de ' . $model_label;

            $info_to_show = [];
            if ($error_message) {
                $info_to_show[] = [
                    'title' => 'Detalle del error',
                    'parrafos' => [$error_message],
                ];
            }

            $functions_to_execute = [
                [
                    'btn_text' => 'Entendido',
                    'btn_variant' => 'primary',
                ],
            ];
        }

        $owner_user->notify(new GlobalNotification([
            'message_text' => $message,
            'color_variant' => $success ? 'success' : 'danger',
            'functions_to_execute' => $functions_to_execute,
            'info_to_show' => $info_to_show,
            'owner_id' => $owner_user->id,
            'is_only_for_auth_user' => $masive_update->employee_id,
        ]));
    }
}
