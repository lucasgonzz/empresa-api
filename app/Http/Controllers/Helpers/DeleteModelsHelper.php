<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\Filter\FilterHistoryService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DeleteModelsHelper
{
    /**
     * Cantidad mínima de registros para procesar la eliminación en segundo plano.
     */
    const BACKGROUND_THRESHOLD = 20;

    /**
     * Devuelve una etiqueta legible del modelo para mensajes al usuario.
     *
     * @param string $model_name
     * @return string
     */
    public static function get_model_label($model_name)
    {
        if ($model_name == 'article') {
            return 'artículos';
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
     * @return array{deleted_count: int, total_count: int, deleted_models: array}
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

        /**
         * En eliminaciones masivas en background se suprimen notificaciones por registro
         * para evitar spam y dependencias de Auth() en cada destroy().
         */
        if ($suppress_per_model_notifications) {
            config(['app.suppress_delete_notifications' => true]);
        }

        try {
            foreach ($models_id as $model_id) {
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
                    $controller->destroy($model->id, $send_notification);
                } else {
                    $controller->destroy($model->id);
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
        ];
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
     * @return void
     */
    public static function notify_result(
        $owner_user_id,
        $auth_user_id,
        $model_name,
        $success,
        $deleted_count = 0,
        $error_message = null
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
        $used_filters
    ) {
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

            self::notify_result(
                $owner_user_id,
                $auth_user_id,
                $model_name,
                true,
                $result['deleted_count']
            );
        } finally {
            self::clear_auth_context();
        }
    }
}
