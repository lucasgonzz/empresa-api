<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PriceUpdateRun;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;

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
    /**
     * Cuántos grupos entran en el broadcast por lista. El resto lo pide el modal por el
     * endpoint de desglose, igual que hace el modal de importación con los artículos de
     * código repetido.
     */
    const TOP_GRUPOS = 8;

    /** Los nombres largos se truncan: un proveedor con un nombre de 200 caracteres no puede comerse el presupuesto de todos. */
    const MAX_LARGO_NOMBRE = 60;

    /**
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
     * Arma el payload del modal, capado para entrar en el límite de Pusher.
     *
     * 🔴 Pusher rechaza los mensajes de más de 10 KB, y cuando eso pasa la notificación
     * NO LLEGA y no falla nada visible: el usuario simplemente no ve el modal y nadie se
     * entera. Por eso van sólo los grupos más grandes y el resto se resume en un número.
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
        $categorias  = isset($stats['categorias']) ? $stats['categorias'] : [];

        return [
            'run_id'                 => $run->id,
            'articulos_actualizados' => (int) $run->articles_updated,
            'origen'                 => $run->origen,
            'origen_texto'           => $run->origen_texto,
            'origen_detalle'         => $run->origen_detalle,
            'sin_cambios'            => (int) $run->articles_updated === 0,
            'proveedores'            => self::recortar_grupos($proveedores),
            'categorias'             => self::recortar_grupos($categorias),
        ];
    }

    /**
     * Deja los TOP_GRUPOS más grandes y resume el resto.
     *
     * `otros_cantidad` son artículos y `otros_grupos` son proveedores (o categorías): son
     * dos números distintos y el modal los muestra distinto ("y 340 artículos más en otros
     * 12 proveedores").
     *
     * @param  array $grupos
     * @return array
     */
    protected static function recortar_grupos($grupos)
    {
        $top             = [];
        $otros_cantidad  = 0;
        $otros_grupos    = 0;
        $total_cantidad  = 0;
        $indice          = 0;

        foreach ($grupos as $grupo) {
            $cantidad = isset($grupo['cantidad']) ? (int) $grupo['cantidad'] : 0;
            $total_cantidad += $cantidad;

            if ($indice < self::TOP_GRUPOS) {
                $nombre = isset($grupo['nombre']) ? (string) $grupo['nombre'] : '';

                if (mb_strlen($nombre) > self::MAX_LARGO_NOMBRE) {
                    $nombre = mb_substr($nombre, 0, self::MAX_LARGO_NOMBRE - 1) . '…';
                }

                $top[] = [
                    'nombre'   => $nombre,
                    'cantidad' => $cantidad,
                ];
            } else {
                $otros_cantidad += $cantidad;
                $otros_grupos++;
            }

            $indice++;
        }

        return [
            'items'          => $top,
            'otros_cantidad' => $otros_cantidad,
            'otros_grupos'   => $otros_grupos,
            'total_grupos'   => count($grupos),
            'total_cantidad' => $total_cantidad,
        ];
    }

    /**
     * Notifica error cuando falla la orquestación del recálculo de precios.
     *
     * @param int $user_id ID del usuario que recibe la GlobalNotification.
     * @return void
     */
    /**
     * @param int $user_id
     * @param int|null $price_update_run_id Corrida a marcar en error, si la hay.
     * @return void
     */
    public static function notify_prices_update_failed($user_id, $price_update_run_id = null)
    {
        if (!is_null($price_update_run_id)) {
            $run = PriceUpdateRun::find($price_update_run_id);

            if (!is_null($run) && $run->status == 'en_proceso') {
                $run->status      = 'error';
                $run->finished_at = Carbon::now();
                $run->save();
            }
        }

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
