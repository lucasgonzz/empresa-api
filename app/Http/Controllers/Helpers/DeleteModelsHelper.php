<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\BackgroundProcess;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\Filter\FilterHistoryService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DeleteModelsHelper
{
    /**
     * Cantidad mínima de registros para procesar la eliminación en segundo plano.
     */
    const BACKGROUND_THRESHOLD = 1;

    /**
     * Modelos cuyo destroy() puede NEGARSE (responder 4xx sin lanzar) y para los que el borrado
     * masivo respeta esa negativa: no los cuenta ni los devuelve como eliminados. Ver
     * process_delete() y motivo_de_rechazo().
     */
    const MODELOS_QUE_RESPETAN_RECHAZO = [
        // Compra con notas de crédito a proveedor en cuenta corriente (misión
        // devoluciones-compras-y-rediseno, 1/10/2026): ProviderOrderController::destroy() la frena.
        'provider_order',
        // Venta facturada, o incluida en una factura consolidada (misión
        // venta-facturada-no-se-borra, 5/10/2026): SaleController::destroy() la frena con 422. Sin
        // esto la masiva la devolvía en `deleted_models` y el listado la sacaba de pantalla aunque
        // seguía viva. Los otros 4xx de ese destroy() no llegan por acá: el 404 lo evita el find()
        // de process_delete() y el 422 de cajas cerradas exige compensar_caja, que la masiva no manda.
        'sale',
        // Orden de producción cuya venta está facturada (misión orden-produccion-baja-de-venta,
        // 5/10/2026): OrderProductionController::destroy() la frena con 422 sin tocar nada. Llega
        // por el `PUT api/delete/order_production` genérico; sin esto la masiva la contaba como
        // eliminada aunque la orden, su venta y su cuenta corriente seguían intactas.
        'order_production',
    ];

    /**
     * El registro visible (misión procesos-en-segundo-plano, 18/9/2026) del borrado que está
     * corriendo en este worker.
     *
     * Es estático y no un parámetro porque la eliminación masiva no tiene modelo propio al que
     * apuntar con `por_referencia()`, y son tres los lugares que lo necesitan sin poder
     * pasárselo entre sí: process_background_delete() lo abre y lo cierra, process_delete() lo
     * avanza dentro de su foreach (sin cambiarle la firma, que también usa el camino
     * síncrono), y el catch de ProcessDeleteModelsJob lo cierra en fallo DESPUÉS de que el
     * finally de acá ya corrió. Un worker procesa un job a la vez, así que nunca hay dos
     * borrados compartiendo esta propiedad; y cada process_background_delete() la pisa al
     * arrancar, así que un valor viejo no sobrevive a un job que murió sin cerrarlo.
     *
     * @var \App\Models\BackgroundProcess|null
     */
    protected static $proceso_en_curso = null;

    /**
     * Devuelve una etiqueta legible del modelo para mensajes al usuario.
     *
     * @param string $model_name
     * @return string
     */
    public static function get_model_label($model_name)
    {
        /*
         * Los tres modelos que hoy pasan por la eliminación masiva, la actualización masiva y
         * las exportaciones. Hasta la misión procesos-en-segundo-plano (18/9/2026) sólo estaba
         * "artículos" y el resto salía crudo ("Exportación de client"); ahora este texto va
         * también en el título de la píldora, que el usuario lee todo el tiempo.
         */
        switch ($model_name) {
            case 'article':
                return 'artículos';
            case 'client':
                return 'clientes';
            case 'provider':
                return 'proveedores';
            case 'sale':
                return 'ventas';
        }

        return $model_name;
    }

    /**
     * Establece el contexto de autenticación necesario para ejecutar destroy() en jobs.
     *
     * @param int $auth_user_id
     * @return bool
     */
    public static function setup_auth_context($auth_user_id)
    {
        /** Usuario autenticado que inició la eliminación. */
        $auth_user = User::find((int) $auth_user_id);

        if (!$auth_user) {
            return false;
        }

        Auth::loginUsingId($auth_user->id);
        UserHelper::set_sessions($auth_user);

        return true;
    }

    /**
     * Limpia el contexto de autenticación temporal del job.
     *
     * @return void
     */
    public static function clear_auth_context()
    {
        Auth::logout();
    }

    /**
     * Elimina los modelos indicados reutilizando el destroy() de cada controlador.
     *
     * @param string $model_name
     * @param array $models_id
     * @param bool $suppress_per_model_notifications
     * @return array{deleted_count: int, total_count: int, deleted_models: array, not_deleted: array}
     *         `not_deleted`: [{id, message}] de los registros cuyo destroy() se negó.
     */
    public static function process_delete($model_name, $models_id, $suppress_per_model_notifications = false)
    {
        /** Nombre de clase Eloquent del modelo a eliminar. */
        $formated_model_name = GeneralHelper::getModelName($model_name);

        /** Controlador específico del modelo (ArticleController, ClientController, etc.). */
        $controller_name = 'App\\Http\\Controllers\\' . explode('\\', $formated_model_name)[2] . 'Controller';
        $controller = new $controller_name();

        /** Total de IDs recibidos para eliminar. */
        $total_count = count($models_id);

        /** Cantidad efectivamente eliminada. */
        $deleted_count = 0;

        /** Modelos eliminados para responder al frontend en operaciones síncronas. */
        $deleted_models = [];

        /** Registros cuyo destroy() se negó (respuesta no 2xx), con el motivo. */
        $not_deleted = [];

        /**
         * En eliminaciones masivas en background se suprimen notificaciones por registro
         * para evitar spam y dependencias de Auth() en cada destroy().
         */
        if ($suppress_per_model_notifications) {
            config(['app.suppress_delete_notifications' => true]);
        }

        /**
         * Registros recorridos, para el avance del registro visible. Solo cuenta en el camino
         * en segundo plano: el síncrono (el listado con pocos registros) no abre proceso.
         */
        $recorridos = 0;

        try {
            foreach ($models_id as $model_id) {
                $recorridos++;

                if ($suppress_per_model_notifications && $recorridos % BackgroundProcessHelper::CADA_CUANTAS_UNIDADES === 0) {
                    BackgroundProcessHelper::avanzar(self::$proceso_en_curso, $recorridos, [
                        'resultado' => ['eliminados' => $deleted_count],
                    ]);
                }

                /** Instancia del modelo a eliminar. */
                $model = $formated_model_name::find($model_id);

                if (!$model) {
                    continue;
                }

                Log::info('DeleteModelsHelper: eliminando ' . $model_name . ' id: ' . $model->id);

                if ($model_name == 'article') {
                    /**
                     * En artículos se respeta el flag de notificación individual del destroy().
                     * En background siempre va en false.
                     */
                    $send_notification = !$suppress_per_model_notifications && $total_count <= 300;
                    $respuesta = $controller->destroy($model->id, $send_notification);
                } elseif ($model_name == 'sale') {
                    /**
                     * 🔴 SaleController::destroy() recibe (Request, $id), no ($id). Sin este caso
                     * aparte el borrado masivo de ventas moria con un TypeError y devolvia 500 sin
                     * borrar nada --medido el 31/8/2026 con el circuito e2e de ventas--.
                     *
                     * El Request va VACIO a proposito: `compensar_caja` queda en false, que es lo
                     * unico que promete el texto del confirm del listado ("Se repondran los
                     * articulos"). El borrado de UNA venta entra por otro lado --el modal de la
                     * venta, con el checkbox de compensar caja-- y ahi por defecto SI la compensa.
                     */
                    $respuesta = $controller->destroy(new Request(), $model->id);
                } else {
                    $respuesta = $controller->destroy($model->id);
                }

                /*
                    🔴 Un destroy() que se NIEGA (responde 4xx/5xx sin lanzar excepción) no borró
                    nada: no se cuenta ni se devuelve como eliminado (misión
                    devoluciones-compras-y-rediseno, 1/10/2026). El caso que lo trajo: una compra
                    con notas de crédito a proveedor en cuenta corriente, que
                    ProviderOrderController::destroy() frena con 422. Hasta acá la respuesta se
                    tiraba y el registro volvía en `deleted_models`: el listado lo sacaba de la
                    pantalla aunque seguía existiendo. Un destroy() que no devuelve nada, o devuelve
                    2xx, sigue contando como antes.

                    ⚠️ Por ahora SOLO para los modelos de MODELOS_QUE_RESPETAN_RECHAZO: hay una
                    veintena de destroy() que en algún caso responden 4xx (gastos,
                    comprobantes...), y cambiarles a todos de una qué devuelve el borrado masivo
                    excede esta misión. Agregar uno es sumarlo a la lista.
                */
                $motivo_rechazo = in_array($model_name, self::MODELOS_QUE_RESPETAN_RECHAZO)
                    ? self::motivo_de_rechazo($respuesta)
                    : null;

                if (!is_null($motivo_rechazo)) {
                    Log::info('DeleteModelsHelper: el destroy de ' . $model_name . ' id ' . $model->id . ' se negó: ' . $motivo_rechazo);
                    $not_deleted[] = [
                        'id'      => $model->id,
                        'message' => $motivo_rechazo,
                    ];
                    continue;
                }

                $deleted_count++;
                $deleted_models[] = $model;
            }
        } finally {
            config(['app.suppress_delete_notifications' => false]);
        }

        return [
            'deleted_count' => $deleted_count,
            'total_count' => $total_count,
            'deleted_models' => $deleted_models,
            'not_deleted' => $not_deleted,
        ];
    }

    /**
     * El motivo por el que un destroy() se negó a borrar, o null si borró.
     *
     * Se considera rechazo solo una respuesta HTTP con código fuera de 2xx: un destroy() que no
     * devuelve nada (varios controladores no devuelven) o devuelve 2xx borró, como siempre. El
     * motivo es el `message` del JSON si lo hay, o un texto genérico con el código.
     *
     * @param mixed $respuesta  Lo que devolvió el destroy() del controlador.
     * @return string|null
     */
    public static function motivo_de_rechazo($respuesta)
    {
        if (!($respuesta instanceof \Symfony\Component\HttpFoundation\Response)) {
            return null;
        }

        $codigo = $respuesta->getStatusCode();

        if ($codigo >= 200 && $codigo < 300) {
            return null;
        }

        $cuerpo = json_decode((string) $respuesta->getContent(), true);

        if (is_array($cuerpo) && isset($cuerpo['message']) && $cuerpo['message'] !== '') {
            return (string) $cuerpo['message'];
        }

        return 'No se pudo eliminar (código ' . $codigo . ').';
    }

    /**
     * Registra historial de filtro cuando la eliminación masiva aplica a artículos.
     *
     * @param int $owner_user_id
     * @param int $auth_user_id
     * @param array $used_filters
     * @param int $filtrados_count
     * @param int $afectados_count
     * @return void
     */
    public static function log_article_filter_history(
        $owner_user_id,
        $auth_user_id,
        $used_filters,
        $filtrados_count,
        $afectados_count
    ) {
        FilterHistoryService::log_action([
            'user_id' => (int) $owner_user_id,
            'auth_user_id' => (int) $auth_user_id,
            'action' => 'eliminacion',
            'model_name' => 'article',
            'filtrados_count' => (int) $filtrados_count,
            'afectados_count' => (int) $afectados_count,
            'used_filters' => $used_filters,
        ]);
    }

    /**
     * Notifica al usuario que solicitó la eliminación el resultado final del proceso.
     *
     * @param int $owner_user_id
     * @param int $auth_user_id
     * @param string $model_name
     * @param bool $success
     * @param int $deleted_count
     * @param string|null $error_message
     * @param array $not_deleted  [{id, message}] de los registros cuyo destroy() se negó (ver
     *                            MODELOS_QUE_RESPETAN_RECHAZO). Opcional y al final: ningún
     *                            llamador existente cambia.
     * @return void
     */
    public static function notify_result(
        $owner_user_id,
        $auth_user_id,
        $model_name,
        $success,
        $deleted_count = 0,
        $error_message = null,
        $not_deleted = []
    ) {
        /** Usuario owner que recibe el broadcast global_notification.{owner_id}. */
        $owner_user = User::find((int) $owner_user_id);

        if (!$owner_user) {
            return;
        }

        /** Etiqueta legible del recurso eliminado. */
        $model_label = self::get_model_label($model_name);

        if ($success) {
            /** Mensaje principal de éxito. */
            $message_text = 'La eliminación masiva de ' . $model_label . ' finalizó correctamente';

            /** Detalle con la cantidad eliminada. */
            $info_to_show = [
                [
                    'title' => 'Resultado de la eliminación',
                    'parrafos' => [
                        $deleted_count . ' ' . $model_label . ' eliminados',
                    ],
                ],
            ];

            /** Botón de cierre; en artículos refresca el listado al confirmar. */
            $entendido_button = [
                'btn_text' => 'Entendido',
                'btn_variant' => 'primary',
            ];

            if ($model_name == 'article') {
                $entendido_button['function_name'] = 'refresh_articles_after_masive_update';
            }

            /*
                Los que no se pudieron eliminar, con su motivo (misión
                devoluciones-compras-y-rediseno, 1/10/2026): sin esto el aviso decía "finalizó
                correctamente" y el usuario no sabía por qué una compra seguía en el listado.
            */
            if (is_array($not_deleted) && count($not_deleted) > 0) {

                $parrafos = [];

                foreach ($not_deleted as $rechazo) {
                    $parrafos[] = $rechazo['message'];
                }

                $info_to_show[] = [
                    'title' => count($not_deleted) . ' no se pudieron eliminar',
                    'parrafos' => $parrafos,
                ];
            }

            $functions_to_execute = [$entendido_button];
            $color_variant = 'success';
        } else {
            /** Mensaje principal de error. */
            $message_text = 'No se pudo completar la eliminación masiva de ' . $model_label;
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
            $color_variant = 'danger';
        }

        $owner_user->notify(new GlobalNotification([
            'message_text' => $message_text,
            'color_variant' => $color_variant,
            'functions_to_execute' => $functions_to_execute,
            'info_to_show' => $info_to_show,
            'owner_id' => $owner_user->id,
            'is_only_for_auth_user' => (int) $auth_user_id,
        ]));
    }

    /**
     * Ejecuta una eliminación masiva en contexto de job (con auth temporal y notificación final).
     *
     * @param string $model_name
     * @param array $models_id
     * @param int $owner_user_id
     * @param int $auth_user_id
     * @param array $used_filters
     * @return void
     */
    public static function process_background_delete(
        $model_name,
        $models_id,
        $owner_user_id,
        $auth_user_id,
        $used_filters,
        $background_process_id = null
    ) {
        /*
         * Se resuelve antes de cualquier cosa que pueda tirar (incluido el usuario que no
         * existe, justo abajo): así cualquier salida por el catch del job encuentra la fila y
         * la deja en fallo con su motivo, en vez de un borrado que desapareció sin explicación.
         *
         * Lo normal es que la fila exista desde DeleteController (nació en `pendiente` al
         * encolar) y acá solo pase a en_proceso; si no llegó id (job encolado antes de este
         * cambio) se abre recién ahora.
         */
        $pendiente = is_null($background_process_id) ? null : BackgroundProcess::find((int) $background_process_id);

        if (!is_null($pendiente) && !$pendiente->esta_terminado()) {
            self::$proceso_en_curso = BackgroundProcessHelper::avanzar($pendiente, 0, [
                'total'            => count($models_id),
                'etapa'            => 'Eliminando',
                'forzar_broadcast' => true,
            ]);
        } else {
            self::$proceso_en_curso = BackgroundProcessHelper::iniciar(
                $owner_user_id,
                'eliminacion_masiva',
                'Eliminación de ' . self::get_model_label($model_name),
                [
                    'auth_user_id' => $auth_user_id,
                    'total'        => count($models_id),
                    'unidad'       => 'registros',
                    'detalle'      => count($models_id) . ' ' . self::get_model_label($model_name),
                    'etapa'        => 'Eliminando',
                ]
            );
        }

        if (!self::setup_auth_context($auth_user_id)) {
            throw new Exception('Usuario autenticado no encontrado para procesar la eliminación');
        }

        try {
            /** Resultado de la eliminación masiva. */
            $result = self::process_delete($model_name, $models_id, true);

            if ($model_name == 'article') {
                self::log_article_filter_history(
                    $owner_user_id,
                    $auth_user_id,
                    $used_filters,
                    $result['total_count'],
                    $result['deleted_count']
                );
            }

            BackgroundProcessHelper::completar(self::$proceso_en_curso, [
                'eliminados' => (int) $result['deleted_count'],
            ]);
            self::$proceso_en_curso = null;

            self::notify_result(
                $owner_user_id,
                $auth_user_id,
                $model_name,
                true,
                $result['deleted_count'],
                null,
                $result['not_deleted']
            );
        } finally {
            self::clear_auth_context();
        }
    }

    /**
     * Cierra en fallo el registro visible del borrado que estaba corriendo. Lo llama el catch
     * de ProcessDeleteModelsJob, que es el único que ve la excepción: el finally de
     * process_background_delete() corre antes que ese catch y no sabe si hubo error.
     *
     * Si no había proceso abierto (o ya se cerró bien), no hace nada: el helper tolera null y
     * fallar() es idempotente.
     *
     * @param string   $error_message
     * @param int|null $owner_user_id  Dueño del comercio, para buscar la fila cuando el estático
     *                                 está vacío (failed() en un proceso fresco).
     * @return void
     */
    public static function fallar_proceso_en_curso($error_message, $owner_user_id = null)
    {
        $proceso = self::$proceso_en_curso;

        /*
         * Sin proceso en memoria y con dueño conocido: es el failed() del job corriendo en un
         * proceso FRESCO (OOM, timeout, worker reiniciado), donde la propiedad estática nació
         * vacía. Se toma el borrado abierto más nuevo del comercio; si ya se cerró (bien o
         * mal), activos() no lo devuelve y no se toca nada.
         */
        if (is_null($proceso) && !is_null($owner_user_id)) {
            $proceso = BackgroundProcessHelper::ultimo_activo($owner_user_id, 'eliminacion_masiva');
        }

        BackgroundProcessHelper::fallar($proceso, (string) $error_message);

        self::$proceso_en_curso = null;
    }
}
