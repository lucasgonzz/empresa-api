<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use App\Notifications\GlobalNotification;

/**
 * Centraliza notificaciones globales asociadas al recálculo de precios finales de artículos.
 *
 * Usado cuando se encolan jobs ProcessChunkSetFinalPrices u orquestación similar.
 */
class SetFinalPricesNotificationHelper
{
    /**
     * Notifica al usuario dueño que el proceso de actualización de precios fue encolado / completado en orquestación.
     *
     * @param int $user_id ID del usuario que recibe la GlobalNotification.
     * @return void
     */
    public static function notify_prices_updated($user_id)
    {
        // Usuario objetivo; si no existe no se intenta notificar.
        $user = User::find($user_id);
        if (!$user) {
            return;
        }

        // Botón único de cierre en el modal de notificación global.
        $functions_to_execute = [
            [
                'btn_text'      => 'Aceptar',
                'function_name' => 'update_articles_after_import',
                'btn_variant'   => 'primary',
            ],
        ];

        $user->notify(new GlobalNotification([
            'message_text'              => 'Precios actualizados',
            'color_variant'             => 'primary',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => [],
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    /**
     * Notifica error cuando falla la orquestación del recálculo de precios.
     *
     * @param int $user_id ID del usuario que recibe la GlobalNotification.
     * @return void
     */
    public static function notify_prices_update_failed($user_id)
    {
        $user = User::find($user_id);
        if (!$user) {
            return;
        }

        $functions_to_execute = [
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'primary',
            ],
        ];

        $user->notify(new GlobalNotification([
            'message_text'              => 'Error al actualizar Precios',
            'color_variant'             => 'danger',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => [],
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }
}
