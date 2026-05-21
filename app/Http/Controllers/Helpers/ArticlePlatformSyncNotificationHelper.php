<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Log;

/**
 * Notificaciones globales (broadcast) cuando falla la sincronización saliente de un artículo
 * hacia Tienda Nube o Mercado Libre.
 */
class ArticlePlatformSyncNotificationHelper
{
    /**
     * Botones por defecto del modal de notificación.
     *
     * @return array<int, array<string, string>>
     */
    public static function default_functions_to_execute(): array
    {
        return [
            [
                'btn_text'    => 'Entendido',
                'btn_variant' => 'primary',
            ],
        ];
    }

    /**
     * Notifica error al sincronizar un artículo hacia Tienda Nube.
     *
     * @param int $user_id Usuario dueño del artículo / registro de sync.
     * @param string $article_label Nombre del artículo o identificador legible.
     * @param string $error_message Detalle del error persistido en sync.
     * @return void
     */
    public static function notify_tienda_nube_sync_failed($user_id, $article_label, $error_message)
    {
        self::notify_sync_failed(
            (int) $user_id,
            'Error al sincronizar artículo con Tienda Nube',
            $article_label,
            $error_message
        );
    }

    /**
     * Notifica error al sincronizar un artículo hacia Mercado Libre.
     *
     * @param int $user_id Usuario dueño del artículo / registro de sync.
     * @param string $article_label Nombre del artículo o identificador legible.
     * @param string $error_message Detalle del error persistido en sync.
     * @return void
     */
    public static function notify_mercado_libre_sync_failed($user_id, $article_label, $error_message)
    {
        self::notify_sync_failed(
            (int) $user_id,
            'Error al sincronizar artículo con Mercado Libre',
            $article_label,
            $error_message
        );
    }

    /**
     * Envía GlobalNotification al dueño de la empresa (canal global_notification.{owner_id}).
     *
     * @param int $user_id Usuario asociado al artículo o al registro SyncTo*Article.
     * @param string $message_text Título del modal.
     * @param string $article_label Nombre del artículo.
     * @param string $error_message Texto de error para el cuerpo del modal.
     * @return void
     */
    protected static function notify_sync_failed($user_id, $message_text, $article_label, $error_message)
    {
        // Usuario dueño de la empresa para el canal de broadcast del SPA.
        $owner_user = self::resolve_owner_user($user_id);
        if (!$owner_user) {
            Log::warning('ArticlePlatformSyncNotificationHelper: sin usuario para notificar user_id='.$user_id);

            return;
        }

        // Detalle mostrado en el modal global.
        $detail = trim($error_message);
        if (strlen($detail) > 2000) {
            $detail = substr($detail, 0, 2000).'…';
        }

        $info_to_show = [
            [
                'title'    => $article_label,
                'parrafos' => [$detail !== '' ? $detail : 'No se registró detalle del error.'],
            ],
        ];

        $owner_user->notify(new GlobalNotification([
            'message_text'              => $message_text,
            'color_variant'             => 'danger',
            'functions_to_execute'      => self::default_functions_to_execute(),
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $owner_user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    /**
     * Resuelve el usuario dueño de la empresa (owner) a partir del user_id del artículo o sync.
     *
     * @param int $user_id
     * @return User|null
     */
    protected static function resolve_owner_user($user_id)
    {
        $user = User::find($user_id);
        if (!$user) {
            return null;
        }

        if ($user->owner_id) {
            $owner = User::find($user->owner_id);

            return $owner ?: $user;
        }

        return $user;
    }
}
