<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Http\Controllers\Helpers\article\precios\PrecioFinalEnMasivaHelper;
use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Jobs\ProcessMasiveUpdateJob;
use App\Models\Article;
use App\Models\MasiveUpdate;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\Filter\FilterHistoryService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $masive_update = MasiveUpdate::create([
            'user_id' => (int) $owner_user_id,
            'employee_id' => (int) $auth_user_id,
            'model_name' => $model_name,
            'action' => 'update',
            'status' => 'pending',
            'from_filter' => (bool) $from_filter,
            'criteria_json' => json_encode($criteria),
        ]);

        /*
         * El registro visible nace ACÁ, en el request, y no cuando el worker levanta el job:
         * en el shared hosting el worker pasa una vez por minuto, y hasta entonces el usuario
         * que acaba de apretar "Actualizar" no vería ningún proceso. process_update() lo
         * retoma por referencia y le pone el total cuando resuelve los modelos.
         */
        BackgroundProcessHelper::iniciar(
            $masive_update->user_id,
            'actualizacion_masiva',
            'Actualización masiva de ' . DeleteModelsHelper::get_model_label($model_name),
            [
                'auth_user_id' => $masive_update->employee_id,
                'referencia'   => $masive_update,
                'unidad'       => 'registros',
                'status'       => 'pendiente',
                'etapa'        => 'En espera del procesador',
            ]
        );

        return $masive_update;
    }

    /**
     * Encola una actualización masiva: resuelve los registros alcanzados, guarda la masiva
     * pendiente con su criterio (incluido `resolved_models_id`) y despacha el job.
     *
     * Es el cuerpo que tenía UpdateController::update() (misión asistente-masivas-imagenes-y-remito,
     * 19/9/2026), movido acá para que la pantalla y el asistente encolen por EL MISMO camino: mismo
     * guard de "al menos un filtro efectivo", mismo tope de 3000, misma `criteria_json`, mismo
     * historial y misma reversión. El controller solo traduce el request y la respuesta:
     * `response()->json($r['body'], $r['status'])`. Ninguna respuesta observable del endpoint cambia.
     *
     * La resolución por filtro pasa por SearchController::search() vía Request::create, igual que
     * resolve_models_from_criteria(): ese search resuelve el dueño por Auth, así que quien llama
     * tiene que estar autenticado (la pantalla lo está por el request; el asistente confirma en el
     * request del clic o con la persona puesta en Auth por ConfirmacionPorTextoIaHelper).
     *
     * `$filtro_extra` es para lo que no es un filtro de columna (hoy, "imagen en blanco" del
     * asistente: las imágenes viven en otra tabla). Recibe la colección resuelta por el search y
     * devuelve `['models' => iterable, 'used_filter' => array|null]`; el `used_filter` se suma a
     * los del search ANTES del guard de filtros efectivos —"los artículos sin imagen" es un
     * criterio de filtrado tan válido como una columna— y queda en el historial como
     * `['key' => 'imagen', 'operator' => 'en_blanco', 'value' => true, 'type' => 'imagen']`.
     *
     * @param  string  $model_name  Nombre snake_case del modelo de la ruta ('article').
     * @param  bool  $from_filter
     * @param  array|null  $filter_form  Filtros de columna (solo con $from_filter).
     * @param  array|null  $update_form  [{type, key, value, round}]
     * @param  array|null  $models_id  Selección manual (solo sin $from_filter).
     * @param  int  $owner_id
     * @param  int  $auth_user_id
     * @param  callable|null  $filtro_extra
     * @param  array|null  $used_filters_manual  Solo sin $from_filter: el criterio legible que
     *                                           reemplaza a "Seleccion manual" en el historial.
     * @return array  ['status' => 200|422, 'body' => array]
     */
    public static function encolar_actualizacion($model_name, $from_filter, $filter_form, $update_form, $models_id, $owner_id, $auth_user_id, $filtro_extra = null, $used_filters_manual = null)
    {
        $models = [];
        $formated_model_name = GeneralHelper::getModelName($model_name);

        /*
         * 🔴 Los cheques NO se actualizan en forma masiva (misión cheque-edicion-acotada, 8/10/2026).
         * Esta actualización asigna cualquier clave del update_form al modelo y lo guarda, y resuelve
         * los registros por id sin filtrar por dueño: por acá se podía reescribir `client_id`, `amount`
         * o `current_acount_id` de un cheque, que es justo lo que `PUT cheque/{id}` blinda (solo
         * número, banco, notas y fechas). Se corta ANTES de buscar o encolar nada, para las dos
         * entradas (la pantalla genérica y el asistente de IA). Se compara la clase ya resuelta y en
         * minúsculas, así 'cheque', 'Cheque' o 'cheque-' dan lo mismo.
         */
        if (strtolower($formated_model_name) === strtolower(\App\Models\Cheque::class)) {
            Log::info('Se interrumpio actualizacion: los cheques no se actualizan en forma masiva.');
            return self::respuesta_de_encolado(422, ['message' => 'Los cheques no se actualizan en forma masiva.']);
        }

        /*
         * 🔴 Los movimientos de caja tampoco (misión movimientos-caja-manuales, 9/10/2026), por el
         * mismo motivo que los cheques: esta actualización resuelve los ids con find() sin dueño y
         * asigna cualquier columna sin recalcular saldos, así que por acá se podía reescribir el
         * movimiento de una venta, el de un turno ya arqueado o el de otro comercio, salteando todo
         * lo que MovimientoCajaController::update() blinda (tenencia, origen, apertura vigente,
         * importes y recálculo). Mismo corte: antes de buscar o encolar nada, para las dos
         * entradas, comparando la clase resuelta en minúsculas.
         */
        if (strtolower($formated_model_name) === strtolower(\App\Models\MovimientoCaja::class)) {
            Log::info('Se interrumpio actualizacion: los movimientos de caja no se actualizan en forma masiva.');
            return self::respuesta_de_encolado(422, ['message' => 'Los movimientos de caja no se actualizan en forma masiva.']);
        }

        $from_filter = (boolean) $from_filter;
        $models_id = is_array($models_id) ? $models_id : [];
        // Un filter_form ausente entra al search como lista vacía (sin filtros efectivos → 422),
        // no como null: con null search() devolvería un JsonResponse en vez del array.
        $filter_form = is_array($filter_form) ? $filter_form : [];

        if ($from_filter) {
            $request = Request::create('/', 'PUT', [
                'filter_form' => $filter_form,
            ]);
            $search_ct = new SearchController();
            $res = $search_ct->search($request, $model_name, $filter_form, 0, true);
            $models = $res['models'];
            $used_filters = $res['used_filters'];

            if (is_callable($filtro_extra)) {
                $filtrado = call_user_func($filtro_extra, $models);
                $models = isset($filtrado['models']) ? $filtrado['models'] : [];
                if (!empty($filtrado['used_filter']) && is_array($filtrado['used_filter'])) {
                    $used_filters[] = $filtrado['used_filter'];
                }
            }

            $effective_filters = array_filter($used_filters, function ($filter) {
                return isset($filter['operator']) && $filter['operator'] != 'order_by';
            });
            if (count($effective_filters) == 0) {
                Log::info('Se interrumpio actualizacion: filtros vacios o no restrictivos (solo order_by).');
                return self::respuesta_de_encolado(422, [
                    'message' => 'No se permite actualizar por filtro si no hay criterios de filtrado.',
                ]);
            }
        } else {
            if (count($models_id) == 0) {
                Log::info('Se interrumpio actualizacion: seleccion manual sin models_id.');
                return self::respuesta_de_encolado(422, [
                    'message' => 'No se permite actualizar sin selección de registros.',
                ]);
            }
            foreach ($models_id as $id) {
                $models[] = $formated_model_name::find($id);
            }
            // Una selección que resolvió el asistente por un criterio que no es una columna
            // (los artículos sin imagen) deja ese criterio legible en el historial en vez de
            // "Seleccion manual", que es lo que se guarda cuando la persona tildó de a uno.
            $used_filters = is_array($used_filters_manual) && count($used_filters_manual)
                ? $used_filters_manual
                : [
                    [
                        'key'       => 'Seleccion manual'
                    ],
                ];
        }

        if (count($models) >= 3000) {
            Log::info('NO se permitio actualizar los '.count($models).' '.$model_name);
            return self::respuesta_de_encolado(422, ['message' => 'No se permitio actualizar '.count($models).' registros']);
        }

        $resolved_models_id = [];
        foreach ($models as $model) {
            if ($model && isset($model->id)) {
                $resolved_models_id[] = $model->id;
            }
        }

        if (count($resolved_models_id) == 0) {
            return self::respuesta_de_encolado(422, [
                'message' => 'No hay registros para actualizar',
            ]);
        }

        $criteria = [
            'from_filter' => $from_filter,
            'used_filters' => $used_filters,
            'update_form' => $update_form,
            'models_id' => $from_filter ? [] : $models_id,
            'resolved_models_id' => $resolved_models_id,
            'filter_form' => $from_filter ? $filter_form : [],
        ];

        $masive_update = self::create_pending_update(
            $owner_id,
            $auth_user_id,
            $model_name,
            $from_filter,
            $criteria
        );

        /*
         * 🔴 `afterCommit()` EXPLÍCITO, mismo criterio que RunProviderOrderScanJob en
         * ProviderOrderScanAltaHelper. Desde la pantalla esto corre sin transacción y despacha de
         * inmediato, igual que siempre. Desde el asistente la cadena es EjecutorAccionesIaHelper →
         * DB::transaction → PropuestaActualizacionMasivaIaHelper::ejecutar → acá: sin esto, un
         * worker libre (redis, en el VPS) puede tomar el job ANTES del commit, no encontrar la
         * masiva (ProcessMasiveUpdateJob: "registro no encontrado") y la actualización que la
         * persona confirmó no corre nunca, sin ningún error que lo delate. El default de config
         * no alcanza: `after_commit` es true solo en la conexión `database`, en `redis` es false.
         */
        ProcessMasiveUpdateJob::dispatch($masive_update->id)->afterCommit();

        Log::info('UpdateController: actualizacion masiva encolada', [
            'masive_update_id' => $masive_update->id,
            'model_name' => $model_name,
            'records_count' => count($resolved_models_id),
        ]);

        return self::respuesta_de_encolado(200, [
            'message' => 'La actualización masiva se está procesando en segundo plano',
            'masive_update_id' => $masive_update->id,
            'queued_count' => count($resolved_models_id),
        ]);
    }

    /**
     * @param  int  $status
     * @param  array  $body
     * @return array
     */
    protected static function respuesta_de_encolado($status, array $body)
    {
        return [
            'status' => (int) $status,
            'body'   => $body,
        ];
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
        $revert_masive_update = MasiveUpdate::create([
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

        // Mismo motivo que en create_pending_update: que se vea desde que se pidió.
        BackgroundProcessHelper::iniciar(
            $revert_masive_update->user_id,
            'reversion_masiva',
            'Reversión de una actualización masiva',
            [
                'auth_user_id' => $revert_masive_update->employee_id,
                'referencia'   => $revert_masive_update,
                'unidad'       => 'registros',
                'status'       => 'pendiente',
                'etapa'        => 'En espera del procesador',
                'detalle'      => 'Actualización masiva de ' . DeleteModelsHelper::get_model_label($parent_masive_update->model_name) . ' #' . $parent_masive_update->id,
            ]
        );

        return $revert_masive_update;
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

        // Los costos declarados como BRUTOS van al final: ver ordenar_formulario_para_costo_bruto().
        $update_form = self::ordenar_formulario_para_costo_bruto($update_form);

        $resolved = self::resolve_models_from_criteria($masive_update);
        $models = $resolved['models'];
        $used_filters = $resolved['used_filters'];

        $model_name = $masive_update->model_name;

        /*
         * El registro visible (misión procesos-en-segundo-plano, 18/9/2026) se abre ANTES del
         * tope de 3000 a propósito: si la masiva se rechaza por tamaño, mark_failed() lo
         * encuentra por referencia y el usuario ve "falló" con el motivo, en vez de una masiva
         * que desapareció sin explicación. El total ya se conoce (los modelos están
         * resueltos), así que la barra arranca medible.
         */
        $proceso = self::retomar_o_abrir_proceso(
            $masive_update,
            'actualizacion_masiva',
            'Actualización masiva de ' . DeleteModelsHelper::get_model_label($model_name),
            [
                'total'   => count($models),
                'detalle' => count($models) . ' ' . DeleteModelsHelper::get_model_label($model_name),
                'etapa'   => 'Aplicando los cambios',
            ]
        );

        if (count($models) >= 3000) {
            throw new Exception('No se permitio actualizar ' . count($models) . ' registros');
        }

        $affected_count = 0;
        $changes_count = 0;
        $non_article_items = [];

        /** Modelos recorridos hasta ahora, para el avance del registro visible. */
        $recorridos = 0;

        /*
         * Usuario del comercio, resuelto UNA sola vez para toda la masiva.
         *
         * Se resuelve al objeto y no se pasa el id: `debe_aplicar_al_asignar()` hace `User::find()`
         * cuando recibe un id numerico, y aca eso serian hasta 3000 SELECT extra por corrida
         * —tambien con la preferencia apagada, porque la consulta pasa antes de leer la columna—.
         * Con el objeto ya resuelto no hay ninguna query por articulo.
         */
        $user_del_comercio = $model_name == 'article' ? User::find($masive_update->user_id) : null;

        /*
         * Memoria de ESTA corrida para la clave "visible en la tienda, lista X" (misión
         * catalogo-por-lista-tienda, B2 de la revisión independiente, 6/10/2026): la lista se valida
         * una vez y los avisos del log salen una vez, en lugar de una consulta y una línea por
         * artículo (hasta 3000). Es una variable local a propósito y no una estática: ver
         * CatalogoPorListaHelper::nueva_memoria_de_corrida().
         */
        $memoria_de_la_corrida = CatalogoPorListaHelper::nueva_memoria_de_corrida();

        /*
         * Articulos que ya tienen sus cambios guardados y a los que les falta el precio (mision
         * recalculo-precios-motor-rapido, 28/9/2026). Ver recalcular_precios_de_la_masiva(): el
         * setFinalPrice() por articulo paso a correr en tandas con el motor en bloque.
         */
        $pendientes_de_precio = [];

        $tamanio_de_tanda = RecalculoDePreciosEnLote::tamanio_de_lote();

        foreach ($models as $model) {
            // Se cuenta ANTES del continue: un modelo nulo también es un registro recorrido, y
            // si no la barra quedaba por debajo del total hasta que completar() la corrigiera.
            $recorridos++;

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
                $change = self::apply_form_change($model, $form, $user_del_comercio, $masive_update->employee_id, $memoria_de_la_corrida);
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
                     * Va antes del recalculo de precios, que es quien tiene que ver los descuentos
                     * nuevos: los escribe en la base ahora, y el motor lee el articulo de la base
                     * cuando le toca su tanda.
                     */
                    ArticleProviderDiscountHelper::aplicar_al_asignar_proveedor(
                        $model,
                        $provider_id_previo,
                        $user_del_comercio
                    );

                    /*
                     * El precio NO se calcula aca: el articulo queda pendiente y se recalcula con
                     * su tanda (ver recalcular_precios_de_la_masiva()). Sus cambios ya estan
                     * guardados (apply_form_change() y la materializacion escriben en la base), que
                     * es lo unico que el calculo lee.
                     *
                     * 🔴 El attach del historial va ACA, antes del precio, y no despues como
                     * cuando el precio se calculaba en el lugar: los cambios del articulo ya estan
                     * guardados, y el historial tiene que decir que se tocaron aunque el recalculo
                     * de su tanda falle.
                     *
                     * ⚠️ Lo que NO da: una reversion. Si el recalculo falla, la masiva termina en
                     * fallo, y una masiva fallida no se puede revertir desde la pantalla
                     * (can_revert() exige `completed`). Por eso, si el motor tira en una tanda,
                     * esa tanda se recalcula articulo por articulo (ver
                     * recalcular_precios_de_la_masiva()): quedan sin precio nuevo a lo sumo los
                     * articulos que de verdad no se pueden calcular, como en develop.
                     */
                    $pendientes_de_precio[] = $model;
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

            // Tanda llena: se recalcula ya, para no juntar en memoria mas de una tanda de modelos.
            if (count($pendientes_de_precio) >= $tamanio_de_tanda) {
                self::recalcular_precios_de_la_masiva(
                    $pendientes_de_precio,
                    $user_del_comercio,
                    $masive_update->user_id,
                    $masive_update->employee_id
                );

                $pendientes_de_precio = [];
            }

            /*
             * Avance del registro visible cada CADA_CUANTAS_UNIDADES modelos y no en cada uno:
             * cada llamada es un UPDATE, y en una masiva de 3000 serían 3000 escrituras de
             * más. El broadcast lo regula el helper. Los números parciales viajan para que el
             * detalle del modal ya muestre algo mientras corre.
             */
            if ($recorridos % BackgroundProcessHelper::CADA_CUANTAS_UNIDADES === 0) {
                BackgroundProcessHelper::avanzar($proceso, $recorridos, [
                    'resultado' => ['afectados' => $affected_count, 'cambios' => $changes_count],
                ]);
            }
        }

        // La ultima tanda, la que no llego a llenarse.
        self::recalcular_precios_de_la_masiva(
            $pendientes_de_precio,
            $user_del_comercio,
            $masive_update->user_id,
            $masive_update->employee_id
        );

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

        // Los mismos números que acaba de guardar la masiva: el detalle del modal y el
        // historial dicen lo mismo.
        BackgroundProcessHelper::completar($proceso, [
            'afectados' => $affected_count,
            'cambios'   => $changes_count,
        ]);

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

        /*
         * El registro visible de la reversión apunta a la masiva DE REVERSIÓN (la hija), que
         * es la que tiene status propio y la que mark_failed() recibe si algo se rompe. El
         * total son los registros que la masiva original tocó: el pivot para artículos, la
         * lista guardada en JSON para el resto. Los loops de abajo avanzan sobre esta fila.
         */
        self::retomar_o_abrir_proceso(
            $revert_masive_update,
            'reversion_masiva',
            'Reversión de una actualización masiva',
            [
                'total'   => self::cantidad_de_registros_a_revertir($parent_masive_update),
                'detalle' => 'Actualización masiva de ' . DeleteModelsHelper::get_model_label($parent_masive_update->model_name) . ' #' . $parent_masive_update->id,
                'etapa'   => 'Restaurando los valores anteriores',
            ]
        );

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

        BackgroundProcessHelper::completar(BackgroundProcessHelper::por_referencia($revert_masive_update), [
            'afectados' => (int) $revert_masive_update->affected_count,
            'cambios'   => (int) $revert_masive_update->changes_count,
        ]);
    }

    /**
     * El registro visible de la masiva, ya en `en_proceso` y con su total.
     *
     * Lo normal es que exista desde create_pending_update()/create_pending_revert() (nació en
     * el request, en `pendiente`) y acá solo se lo retome; si no existe —una masiva encolada
     * antes de este cambio, o un registro que no se pudo crear—, se abre recién ahora. En los
     * dos casos el resultado es el mismo: una fila en_proceso con el total, la etapa y el
     * detalle, sobre la que avanzan los loops.
     *
     * @param \App\Models\MasiveUpdate $masive_update
     * @param string $tipo
     * @param string $titulo
     * @param array  $opciones  total, detalle, etapa.
     * @return \App\Models\BackgroundProcess|null
     */
    protected static function retomar_o_abrir_proceso(MasiveUpdate $masive_update, $tipo, $titulo, array $opciones)
    {
        $proceso = BackgroundProcessHelper::por_referencia($masive_update);

        if (!is_null($proceso)) {
            $opciones['forzar_broadcast'] = true;

            return BackgroundProcessHelper::avanzar($proceso, 0, $opciones);
        }

        return BackgroundProcessHelper::iniciar($masive_update->user_id, $tipo, $titulo, array_merge($opciones, [
            'auth_user_id' => $masive_update->employee_id,
            'referencia'   => $masive_update,
            'unidad'       => 'registros',
        ]));
    }

    /**
     * Cuántos registros va a recorrer la reversión: el total de la barra del registro visible.
     *
     * @param \App\Models\MasiveUpdate $parent_masive_update
     * @return int
     */
    protected static function cantidad_de_registros_a_revertir(MasiveUpdate $parent_masive_update)
    {
        if ($parent_masive_update->model_name == 'article') {
            return (int) $parent_masive_update->articles()->count();
        }

        $items = json_decode($parent_masive_update->non_article_items_json, true);

        return is_array($items) ? count($items) : 0;
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
        // De la fila, no del modelo: un item anterior de la misma masiva pudo haberlo movido.
        $old_value = DB::table('articles')->where('id', $model->id)->value('stock');
        $old_value = is_null($old_value) ? null : (float) $old_value;

        if (count($model->addresses) >= 1 || count($model->article_variants) >= 1) {
            Log::info('Masiva de stock: el articulo '.$model->id.' reparte por depositos o variantes, no se le puede fijar un stock global. Se saltea.');
            return null;
        }

        /*
            Un articulo SIN stock (null: no lo lleva) al que se le fija 0 no necesita movimiento:
            se escribe la columna, que es lo unico que cambia. Revertirlo a null se resuelve en
            revert_masive_update(), que limpia la columna.
        */
        if (is_null($old_value) && (float) $nuevo == 0.0) {

            if ($operation === 'revert') {
                return null;
            }

            DB::table('articles')->where('id', $model->id)->update(['stock' => 0]);
            $model->stock = 0;
            $model->syncOriginalAttribute('stock');

            return [
                'prop_key' => 'stock',
                'old_value' => null,
                'new_value' => 0,
                'operation' => $operation,
                'form_key' => $form_key,
            ];
        }

        $actual = is_null($old_value) ? 0 : $old_value;
        $delta = (float) $nuevo - $actual;

        if (abs($delta) < 0.0001) {
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
        $model->syncOriginalAttribute('stock');

        return [
            'prop_key' => 'stock',
            'old_value' => $old_value,
            'new_value' => $model->stock,
            'operation' => $operation,
            'form_key' => $form_key,
        ];
    }

    /**
     * Misión `masiva-costo-neto-o-bruto` (3/10/2026) — ¿el ítem del formulario declara que el costo
     * que se está cargando es BRUTO (con IVA incluido)?
     *
     * Es la quinta vía que escribe `articles.cost` a partir de un número que fijó una persona, y
     * aplica la MISMA regla que el ABM, la compra y el import: **el que carga el costo declara si es
     * neto o bruto; si es bruto el sistema le saca el IVA con la alícuota del artículo; siempre se
     * guarda el neto**. La decisión final la toma el resolvedor único
     * ArticlePricesHelper::el_costo_cargado_es_bruto(), así que un Monotributista migrado nunca
     * descompone aunque le llegue `true`.
     *
     * 🔴 Solo cuenta si la clave `cost_incluye_iva` VIENE en el ítem. Ausente o null significa "como
     * siempre" (neto): es lo que mandan el asistente de IA (PropuestaActualizacionMasivaIaHelper),
     * las masivas que ya estaban encoladas y una SPA vieja en caché. Si acá se asumiera algo ante
     * la ausencia, un `set_cost` que hoy se guarda tal cual pasaría a descomponerse en silencio.
     *
     * Solo aplica a la propiedad `cost` de un Article: cualquier otro modelo o campo ignora la clave.
     *
     * @param  mixed                $model     Registro que se está actualizando.
     * @param  string               $prop_key  Propiedad que toca el ítem (`cost`, `stock`...).
     * @param  array                $form      Ítem del formulario.
     * @param  \App\Models\User|null $owner     Dueño del comercio (la masiva corre en cola, sin sesión).
     * @return bool                            true si el valor del ítem hay que tratarlo como bruto.
     */
    protected static function costo_declarado_como_bruto($model, $prop_key, $form, $owner = null)
    {
        if (!($model instanceof Article) || $prop_key !== 'cost') {
            return false;
        }

        if (!array_key_exists('cost_incluye_iva', $form) || is_null($form['cost_incluye_iva'])) {
            return false;
        }

        return ArticlePricesHelper::el_costo_cargado_es_bruto($owner, $form['cost_incluye_iva']);
    }

    /**
     * Costo NETO de un número que la persona declaró BRUTO, redondeado a los 6 decimales de la
     * columna `articles.cost`.
     *
     * Se redondea acá y no se deja que lo haga MySQL al guardar porque `apply_form_change()` decide
     * si hubo cambio comparando `old_value == $model->cost` ANTES de que MySQL redondee: con el
     * neto sin redondear (826,4462809917355) contra el guardado ("826.446281") una masiva repetida
     * contaba como cambio cada artículo, adjuntaba un pivot y metía 13 decimales en `changes_json`.
     * No dañaba datos, pero inflaba los números del historial.
     *
     * @param  \App\Models\Article $model  Artículo cuya alícuota se usa.
     * @param  float               $bruto  Costo con IVA incluido.
     * @return float                       Costo neto, a 6 decimales.
     */
    protected static function costo_neto_de_un_bruto($model, $bruto)
    {
        return round(ArticlePricesHelper::back_out_iva($model, $bruto), 6);
    }

    /**
     * Aumenta o disminuye el costo de un artículo en un porcentaje, REDONDEANDO EL BRUTO.
     *
     * Sin redondeo, un % sobre el neto y el mismo % sobre el bruto dan exactamente el mismo costo
     * (el IVA es un factor constante), así que apply_form_change() sigue por su camino de siempre.
     * El redondeo es lo único que los distingue: la persona que declara "mi costo es el bruto" y
     * pide redondear espera ver redondeado ESE número (1270,5 → 1271), no el neto (1050 → 1050). Por
     * eso se va al bruto, se aplica el %, se redondea y se vuelve al neto, que es lo que se guarda.
     *
     * @param  \App\Models\Article $model       Artículo con el costo neto actual.
     * @param  float               $porcentaje  Porcentaje con signo (positivo aumenta, negativo disminuye).
     * @return float                            Nuevo costo NETO (sin IVA).
     */
    protected static function variar_costo_en_bruto($model, $porcentaje)
    {
        /*
         * 🔴 El bruto se limpia a 4 decimales ANTES de aplicar el %, y el resultado también antes de
         * redondearlo a entero. `articles.cost` es decimal(22,6): el neto guardado ya viene
         * redondeado (100 / 1,21 = 82,644628) y al volver a sumarle el IVA el bruto no da exacto
         * (99,99999988 en vez de 100). Con eso un empate genuino en .5 (100 + 5,5% = 105,5) caía
         * a 105 en vez de 106 con ROUND_HALF_UP, y pasaba en la mitad de los empates (medido por el
         * checker adversarial de la Fase 7). Redondear a 4 decimales absorbe ese ruido sin mover
         * ningún valor que no sea un empate.
         */
        $bruto_actual = round(ArticlePricesHelper::sumar_iva($model, (float) $model->cost), 4);

        $bruto_nuevo = round(round($bruto_actual + ($bruto_actual * $porcentaje / 100), 4), 0, PHP_ROUND_HALF_UP);

        // A 6 decimales, que es lo que guarda la columna: ver costo_neto_de_un_bruto().
        return self::costo_neto_de_un_bruto($model, $bruto_nuevo);
    }

    /**
     * Deja al final del formulario los costos declarados como BRUTOS, respetando el orden relativo
     * del resto.
     *
     * Si la misma masiva cambia la alícuota (`iva_id`) y fija un costo bruto, el back-out tiene que
     * usar la alícuota NUEVA, igual que el ABM (que asigna `iva_id` antes de descomponer). El
     * formulario llega en el orden de las tarjetas de la pantalla, que no garantiza eso. Los ítems
     * sin la declaración no se mueven de lugar, así que una masiva sin costo bruto corre idéntica.
     *
     * @param  array $update_form Ítems del formulario tal como se guardaron al encolar.
     * @return array              Los mismos ítems, con los de costo bruto al final.
     */
    protected static function ordenar_formulario_para_costo_bruto($update_form)
    {
        if (!is_array($update_form)) {
            return $update_form;
        }

        $primeros = [];
        $costos_brutos = [];

        foreach ($update_form as $form) {
            /*
             * Misma conversión que usa la decisión final (el resolvedor castea con
             * FILTER_VALIDATE_BOOLEAN): con `!empty()` un string "false" o "off" se movía al final
             * aunque después se tratara como neto, y eso podía cambiar el orden de dos ítems de
             * costo en el mismo formulario. Desde la pantalla llega un booleano JSON; esto cubre la
             * API directa.
             */
            $es_costo_bruto = is_array($form)
                && isset($form['cost_incluye_iva'])
                && filter_var($form['cost_incluye_iva'], FILTER_VALIDATE_BOOLEAN)
                && isset($form['key'])
                && in_array($form['key'], ['set_cost', 'increment_cost', 'decrement_cost'], true);

            if ($es_costo_bruto) {
                $costos_brutos[] = $form;
            } else {
                $primeros[] = $form;
            }
        }

        return array_merge($primeros, $costos_brutos);
    }

    /**
     * Aplica un ítem del formulario de actualización y devuelve el cambio si hubo modificación.
     *
     * @param object $model
     * @param array $form
     * @param \App\Models\User|null $owner
     * @param int|null $employee_id
     * @param \ArrayObject|null $memoria_de_la_corrida  Memoria de la corrida para "visible en la tienda,
     *        lista X" (CatalogoPorListaHelper::nueva_memoria_de_corrida()). Opcional: sin ella la lista
     *        se valida en cada llamada, como antes.
     * @return array|null
     */
    public static function apply_form_change($model, $form, $owner = null, $employee_id = null, $memoria_de_la_corrida = null)
    {
        if (!is_array($form) || !isset($form['type']) || !isset($form['key'])) {
            return null;
        }

        /*
         * "Visible en la tienda, lista X" (misión catalogo-por-lista-tienda, 5/10/2026): la clave
         * `visible_en_tienda_lista_{id}` (checkbox, 0 o 1) no es una columna de `articles` sino
         * del pivote `article_price_type` de ESA lista, así que tiene su propia rama, y va ANTES
         * que todas las genéricas: la de checkbox de abajo haría `$model->visible_en_tienda_lista_5
         * = 1; $model->save()` y la masiva entera terminaría en "Unknown column". La rama valida
         * que la lista sea del dueño (con el `$owner` que llega, porque en la cola no hay sesión),
         * escribe el pivote y devuelve el cambio con la forma de siempre para poder revertirlo
         * (ver revert_article_pivot_changes()). Detalle en CatalogoPorListaHelper::aplicar_en_masiva().
         */
        if (CatalogoPorListaHelper::es_clave_de_masiva($form['key'])) {
            return CatalogoPorListaHelper::aplicar_en_masiva($model, $form, $owner, $memoria_de_la_corrida);
        }

        /*
         * "Precio final sube/baja X %" (misión asistente-masiva-precio-manual, 10/10/2026): no es una
         * columna sino el precio que se cobra, así que tiene su propia rama. En un artículo de precio
         * manual sube ese precio; en uno de costo + margen recalcula SU margen para que el final suba
         * exactamente X %; en uno sin costo ni precio no escribe nada. Devuelve el cambio con la forma
         * de siempre (sobre `price` o `percentage_gain`), así que la reversión de siempre lo
         * restaura. La memoria de la corrida va para que el margen del proveedor se lea una vez por
         * proveedor y no una consulta por artículo. Detalle en PrecioFinalEnMasivaHelper.
         */
        if (PrecioFinalEnMasivaHelper::es_de_la_masiva($form)) {
            return PrecioFinalEnMasivaHelper::aplicar($model, $form, $memoria_de_la_corrida);
        }

        if ($form['type'] == 'number' && strpos($form['key'], 'decrement') !== false && self::form_scalar_value_is_filled($form['value'])) {
            $prop_key = substr($form['key'], 10);
            $old_value = $model->{$prop_key};
            $value = $model->{$prop_key} * (float) $form['value'] / 100;
            $nuevo = $model->{$prop_key} - $value;
            if (!empty($form['round']) && self::costo_declarado_como_bruto($model, $prop_key, $form, $owner)) {
                // Costo BRUTO + redondear: se redondea el bruto, no el neto. Ver variar_costo_en_bruto().
                $nuevo = self::variar_costo_en_bruto($model, -1 * (float) $form['value']);
            } elseif (!empty($form['round'])) {
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
            if (!empty($form['round']) && self::costo_declarado_como_bruto($model, $prop_key, $form, $owner)) {
                // Costo BRUTO + redondear: se redondea el bruto, no el neto. Ver variar_costo_en_bruto().
                $nuevo = self::variar_costo_en_bruto($model, (float) $form['value']);
            } elseif (!empty($form['round'])) {
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

            /*
             * Misión `masiva-costo-neto-o-bruto` (3/10/2026): si la persona declaró que el valor
             * que fija es el costo BRUTO (con IVA), se le saca el IVA con la alícuota de ESTE
             * artículo y se guarda el neto, que es la convención del sistema para `articles.cost`.
             * Sin la declaración (o con ella en false) se guarda tal cual, como siempre.
             */
            if (self::costo_declarado_como_bruto($model, $prop_key, $form, $owner)) {
                $model->{$prop_key} = self::costo_neto_de_un_bruto($model, (float) $form['value']);
            } else {
                $model->{$prop_key} = (float) $form['value'];
            }
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

        /* Memoria de ESTA reversión para "visible en la tienda, lista X": ver process_update(). */
        $memoria_de_la_corrida = CatalogoPorListaHelper::nueva_memoria_de_corrida();

        /* El registro visible de la reversión, buscado una vez; el avance va cada CADA_CUANTAS_UNIDADES. */
        $proceso = BackgroundProcessHelper::por_referencia($revert_masive_update);
        $recorridos = 0;

        /*
         * Mismo criterio que process_update() (28/9/2026): los articulos ya revertidos se juntan y
         * el precio se recalcula por tandas con el motor (ver recalcular_precios_de_la_masiva()).
         */
        $pendientes_de_precio = [];

        $tamanio_de_tanda = RecalculoDePreciosEnLote::tamanio_de_lote();

        foreach ($parent_masive_update->articles as $article) {
            // Se cuenta antes de cualquier continue: un pivot ilegible o un artículo borrado
            // también son registros recorridos para la barra.
            $recorridos++;

            if ($recorridos % BackgroundProcessHelper::CADA_CUANTAS_UNIDADES === 0) {
                BackgroundProcessHelper::avanzar($proceso, $recorridos);
            }

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

                /*
                 * Espejo de la rama de apply_form_change() (misión catalogo-por-lista-tienda,
                 * 5/10/2026): "visible en la tienda, lista X" vive en el pivote, no en `articles`.
                 * Sin esta rama, la línea de abajo asignaría `$model->visible_en_tienda_lista_5` y
                 * el save() reventaría con "Unknown column", dejando la reversión en fallo. Se
                 * restaura el `old` exacto (NULL vuelve a NULL, nunca a 0).
                 */
                if (CatalogoPorListaHelper::es_clave_de_masiva($prop_key)) {
                    $revertido = CatalogoPorListaHelper::revertir_en_masiva($model, $prop_key, $change['old'], $user_del_comercio, $memoria_de_la_corrida);

                    if (!is_null($revertido)) {
                        $revert_changes[$prop_key] = $revertido;
                    }
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

                    /*
                        Si antes de la masiva el articulo NO llevaba stock (null), revertir es
                        volver a null: el movimiento de arriba lo deja en 0 (y el libro, en neto
                        cero) y la columna se limpia aparte, que es lo que "restaurar" significa.
                    */
                    if (is_null($change['old']) && !is_null(DB::table('articles')->where('id', $model->id)->value('stock'))) {
                        DB::table('articles')->where('id', $model->id)->update(['stock' => null]);
                        $model->stock = null;
                        $model->syncOriginalAttribute('stock');
                        $aplicado = true;
                    }

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

            /*
             * El precio se recalcula con la tanda, no aca (ver process_update()). El attach va
             * antes por el mismo motivo: el articulo ya quedo revertido en la base, y el historial
             * tiene que decirlo aunque el recalculo de la tanda fallara. Si el motor tira, la tanda
             * se recalcula articulo por articulo y la reversion termina en fallo con el motivo (ver
             * recalcular_precios_de_la_masiva()).
             */
            $pendientes_de_precio[] = $model;

            $revert_masive_update->articles()->attach($model->id, [
                'changes_json' => json_encode($revert_changes),
            ]);

            if (count($pendientes_de_precio) >= $tamanio_de_tanda) {
                self::recalcular_precios_de_la_masiva(
                    $pendientes_de_precio,
                    $user_del_comercio,
                    $parent_masive_update->user_id,
                    $revert_masive_update->employee_id
                );

                $pendientes_de_precio = [];
            }
        }

        self::recalcular_precios_de_la_masiva(
            $pendientes_de_precio,
            $user_del_comercio,
            $parent_masive_update->user_id,
            $revert_masive_update->employee_id
        );
    }

    /**
     * Recalcula el precio de los articulos de una masiva (o de su reversion) con el motor en bloque,
     * y los marca para sincronizar con Tienda Nube (mision recalculo-precios-motor-rapido,
     * 28/9/2026).
     *
     * 🔴 POR QUE ASI. Hasta esta mision, cada articulo de la masiva corria su propio
     * `ArticleHelper::setFinalPrice()` apenas se le aplicaban los cambios: leia sus relaciones de a
     * una (descuentos, recargos, proveedor, categoria, IVA, listas...) y escribia por su cuenta dos
     * UPDATE de `articles`, un INSERT de price_changes y tres o cuatro consultas por lista. En una
     * masiva de 3.000 articulos eso son decenas de miles de consultas. Ahora el MISMO calculo corre
     * con RecalculoDePreciosEnLote, que lee la tanda de una vez y escribe en bloque, dejando la base
     * exactamente igual (el invariante del motor).
     *
     * Lo que se conserva, a proposito:
     *   - El precio se calcula DESPUES de apply_form_change() y de la materializacion de descuentos
     *     de cada articulo, que ya guardaron en la base: el motor lee el articulo de ahi.
     *   - `employee_id` de los price_changes = el de la masiva (quien la lanzo o quien la revierte),
     *     igual que el `$auth_user_id` que se le pasaba a setFinalPrice().
     *   - El dueño es el de la masiva, resuelto una vez (antes, un User::find() por articulo adentro
     *     de setFinalPrice()).
     *   - `TiendaNubeSyncArticleService::add_article_to_sync()` por articulo, DESPUES del precio,
     *     como antes: solo deja una marca "pendiente" (no toma una foto del precio), pero asi el
     *     sincronizador de Tienda Nube nunca queda con la marca consumida antes de que el precio
     *     nuevo este escrito.
     *
     * Un articulo que aparezca dos veces en la misma tanda (ids repetidos en la seleccion) se
     * recalcula una sola vez, con el estado final: el resultado en `articles` es el mismo, pero queda
     * un solo price_change en vez de uno por pasada.
     *
     * Si el dueño no existe o es un empleado (dato roto: el motor se niega a calcular con un
     * empleado), se cae al setFinalPrice() por articulo de siempre, exactamente como antes.
     *
     * 🔴 SI EL MOTOR TIRA EN UNA TANDA (seguimiento del 29/9/2026, chequeo de lectura critica): a esa
     * altura los cambios de TODA la tanda ya estan guardados (apply_form_change(), la
     * materializacion y el historial pasaron articulo por articulo). Si la excepcion subiera tal
     * cual, la masiva terminaria en fallo con hasta una tanda entera (1.000 articulos) con el costo
     * nuevo y el precio viejo, y sin forma de revertirla desde la pantalla: can_revert() exige
     * `completed`. En develop ese mismo fallo dejaba asi a UN articulo, el que tiro.
     *
     * Por eso la tanda cae al setFinalPrice() por articulo de siempre (mismo usuario, mismo
     * employee_id, el modelo en memoria como antes), atrapando articulo por articulo: el que se puede
     * calcular queda con su precio y su marca de Tienda Nube; el que ni asi se puede queda como en
     * develop. Al terminar la tanda se relanza la PRIMERA excepcion (la del motor), asi que la masiva
     * sigue terminando en fallo con ese mensaje, como hoy — pero con a lo sumo los articulos que de
     * verdad no se pueden calcular sin precio. La caida al camino por articulo queda en el log con el
     * motivo.
     *
     * @param  \App\Models\Article[]  $modelos      Articulos con sus cambios ya guardados.
     * @param  \App\Models\User|null  $owner        Dueño del comercio (resuelto una vez).
     * @param  int                    $owner_id     Id del dueño (el user_id de la masiva).
     * @param  int|null               $employee_id  employee_id de los price_changes.
     * @return void
     * @throws \Throwable  La primera excepcion de la tanda, despues de recalcular lo que se pudo.
     */
    protected static function recalcular_precios_de_la_masiva(array $modelos, $owner, $owner_id, $employee_id)
    {
        if (count($modelos) === 0) {
            return;
        }

        if (!is_null($owner) && empty($owner->owner_id)) {

            $ids = [];

            foreach ($modelos as $modelo) {
                $ids[] = (int) $modelo->id;
            }

            try {

                RecalculoDePreciosEnLote::recalcular($ids, $owner, $employee_id);

            } catch (\Throwable $e) {

                /*
                 * La tanda del motor es atomica: si tiro, no escribio nada de esta tanda (ni precios,
                 * ni pivots, ni price_changes) y dejo el modo lote apagado. Los modelos en memoria
                 * de la masiva no los toco (el motor lee sus propias copias), asi que el camino por
                 * articulo arranca del mismo estado que tenia develop.
                 */
                Log::warning('MasiveUpdateHelper: el motor de precios fallo en una tanda de la masiva; se recalcula articulo por articulo', [
                    'articulos'   => count($ids),
                    'owner_id'    => (int) $owner_id,
                    'employee_id' => $employee_id,
                    'motivo'      => $e->getMessage(),
                    'archivo'     => $e->getFile(),
                    'linea'       => $e->getLine(),
                ]);

                self::recalcular_de_a_uno($modelos, $owner_id, $employee_id, $e);

                return;
            }

            foreach ($modelos as $modelo) {
                TiendaNubeSyncArticleService::add_article_to_sync($modelo);
            }

            /*
             * Combos calculados (misión combos-calculados, 30/9/2026): el motor ya escribió los
             * precios de la tanda (y los de cada lista), así que los combos calculados que incluyen
             * alguno de estos artículos rehacen su cuenta ahora. El motor no lo hace solo: escribe
             * en bloque y no pasa por el gancho de `setFinalPrice()`. Una consulta por tanda, y
             * nada más si ningún combo calculado incluye estos artículos.
             *
             * Sin el segundo parámetro (el dueño) a propósito: los ids ya son de artículos de este
             * dueño, y pasarlo agregaría una consulta a `users` por tanda que no hace falta (hay
             * tests que cuentan cuántas veces se busca al dueño en una masiva).
             */
            ComboCalculadoHelper::recalcular_por_articulos($ids);

            return;
        }

        self::recalcular_de_a_uno($modelos, $owner_id, $employee_id);
    }

    /**
     * El camino por articulo de siempre, para una tanda de la masiva: setFinalPrice() con el modelo
     * en memoria, el user_id del dueño y el employee_id de la masiva (exactamente la llamada que
     * hacia develop por cada articulo), y la marca de Tienda Nube despues del precio.
     *
     * 🔴 Atrapa POR ARTICULO: un articulo que no se puede calcular no deja sin precio al resto de la
     * tanda, que ya tiene sus cambios guardados. Al terminar relanza la primera excepcion —la que
     * vino de afuera (la del motor) o, si no vino ninguna, la del primer articulo que fallo— para que
     * la masiva termine en fallo, como hoy. Los demas fallos quedan en el log.
     *
     * @param  \App\Models\Article[] $modelos
     * @param  int                   $owner_id
     * @param  int|null              $employee_id
     * @param  \Throwable|null       $primera_excepcion  La que ya ocurrio antes (la del motor), si hubo.
     * @return void
     * @throws \Throwable  La primera excepcion, si hubo alguna.
     */
    protected static function recalcular_de_a_uno(array $modelos, $owner_id, $employee_id, $primera_excepcion = null)
    {
        foreach ($modelos as $modelo) {

            try {

                ArticleHelper::setFinalPrice($modelo, $owner_id, null, $employee_id);

            } catch (\Throwable $e) {

                Log::warning('MasiveUpdateHelper: no se pudo recalcular el precio de un articulo de la masiva', [
                    'article_id'  => (int) $modelo->id,
                    'employee_id' => $employee_id,
                    'motivo'      => $e->getMessage(),
                ]);

                if (is_null($primera_excepcion)) {
                    $primera_excepcion = $e;
                }

                // Sin precio nuevo no hay nada nuevo que mandar a Tienda Nube.
                continue;
            }

            TiendaNubeSyncArticleService::add_article_to_sync($modelo);
        }

        if (!is_null($primera_excepcion)) {
            throw $primera_excepcion;
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

        /* Mismo avance que en el camino de artículos. */
        $proceso = BackgroundProcessHelper::por_referencia($revert_masive_update);
        $recorridos = 0;

        foreach ($items as $item) {
            $recorridos++;

            if ($recorridos % BackgroundProcessHelper::CADA_CUANTAS_UNIDADES === 0) {
                BackgroundProcessHelper::avanzar($proceso, $recorridos);
            }

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

        /*
         * Cierra el registro visible con el mismo motivo. Si la masiva reventó antes de que
         * process_update() llegara a abrirlo, por_referencia() devuelve null y el helper no
         * hace nada: no hay fila que cerrar.
         */
        BackgroundProcessHelper::fallar(
            BackgroundProcessHelper::por_referencia($masive_update),
            (string) $error_message
        );
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
