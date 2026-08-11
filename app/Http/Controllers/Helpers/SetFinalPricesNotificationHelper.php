<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use App\Notifications\GlobalNotification;

/**
 * Centraliza notificaciones globales asociadas al recálculo de precios finales de artículos.
 *
 * El aviso sale cuando la corrida TERMINA, no cuando se encolan los chunks: los números que
 * ve el usuario tienen que ser los reales.
 */
class SetFinalPricesNotificationHelper
{
    /**
     * Largo máximo del detalle del origen que viaja en el broadcast.
     *
     * La columna admite 255, pero el chip del modal es una línea: más que esto no se lee y
     * sólo ocupa presupuesto de un mensaje que Pusher corta en 10 KB sin avisar.
     */
    const MAX_LARGO_ORIGEN_DETALLE = 120;

    /**
     * Notifica al usuario dueño que su recálculo de precios terminó.
     *
     * @param int $user_id ID del usuario que recibe la GlobalNotification.
     * @param \App\Models\PriceUpdateRun|null $run Corrida ya cerrada. Sin ella se comporta
     *                                             como antes, por compatibilidad.
     * @return void
     */
    public static function notify_prices_updated($user_id, $run = null)
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

        $data = [
            'message_text'              => 'Precios actualizados',
            'color_variant'             => 'primary',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => [],
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ];

        if (!is_null($run)) {
            $data['notification_modal'] = 'price_update_result';
            $data['price_stats']        = self::build_price_stats($run);
        }

        $user->notify(new GlobalNotification($data));
    }

    /**
     * Arma el payload del modal.
     *
     * 🔴 Va por Pusher, que rechaza los mensajes de más de 10 KB, y cuando eso pasa la
     * notificación NO LLEGA y no falla nada visible: el usuario simplemente no ve el modal y
     * nadie se entera. Por eso acá viajan sólo números y un texto corto: el tamaño queda
     * acotado por construcción y no por un recorte que hay que mantener. La lista de nombres
     * de los proveedores la pide el modal por endpoint, que no tiene ese límite.
     *
     * @param  \App\Models\PriceUpdateRun $run
     * @return array
     */
    public static function build_price_stats($run)
    {
        $stats = json_decode($run->stats_json, true);

        if (!is_array($stats)) {
            $stats = [];
        }

        $proveedores = isset($stats['proveedores']) ? $stats['proveedores'] : [];

        return [
            'run_id'                 => $run->id,
            'articulos_actualizados' => (int) $run->articles_updated,
            'proveedores_cantidad'   => count($proveedores),
            'origen'                 => $run->origen,
            'origen_texto'           => $run->origen_texto,
            'origen_detalle'         => self::recortar_origen_detalle($run->origen_detalle),
            'sin_cambios'            => (int) $run->articles_updated === 0,
        ];
    }

    /**
     * @param  string|null $origen_detalle
     * @return string|null
     */
    protected static function recortar_origen_detalle($origen_detalle)
    {
        if (is_null($origen_detalle)) {
            return null;
        }

        $origen_detalle = (string) $origen_detalle;

        if (mb_strlen($origen_detalle) > self::MAX_LARGO_ORIGEN_DETALLE) {
            $origen_detalle = mb_substr($origen_detalle, 0, self::MAX_LARGO_ORIGEN_DETALLE - 1) . '…';
        }

        return $origen_detalle;
    }

    /**
     * Notifica que el recálculo de precios falló, y deja la corrida en error con el motivo.
     *
     * 🔴 Los tres caminos de falla (el chunk que muere, el finalizador que no puede cerrar y
     * la excepción del productor) terminan acá. Ahora que el aviso sale al final, si el final
     * no llega no llega nada: sin esta notificación el usuario se queda esperando un modal
     * que no va a aparecer nunca, y no tiene forma de darse cuenta.
     *
     * @param int $user_id
     * @param int|null $price_update_run_id Corrida a marcar en error, si la hay.
     * @param string|null $detalle Motivo legible por una persona. Nunca un stack trace: lo
     *                             lee el usuario en el aviso y el soporte en la base.
     * @return void
     */
    public static function notify_prices_update_failed($user_id, $price_update_run_id = null, $detalle = null)
    {
        /* El mismo texto va a la base y al aviso: si difieren, el soporte y el usuario están
           mirando dos cosas distintas. */
        $detalle = PriceUpdateRunHelper::cerrar_con_error($price_update_run_id, $detalle);

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

        $info_to_show = [];

        if (!is_null($detalle)) {
            $info_to_show[] = [
                'title' => 'Qué pasó',
                'value' => $detalle,
            ];
        }

        $user->notify(new GlobalNotification([
            'message_text'              => 'Error al actualizar Precios',
            'color_variant'             => 'danger',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }
}
