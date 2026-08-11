<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PriceUpdateRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Abre y cierra las corridas de recálculo de precios.
 */
class PriceUpdateRunHelper
{
    /**
     * Minutos después de los cuales una corrida abierta se considera colgada.
     *
     * Sin este límite, una corrida que quedó en_proceso por un worker muerto bloquearía
     * TODOS los avisos posteriores de ese usuario: cada recálculo nuevo se sumaría a una
     * corrida que nadie va a cerrar nunca.
     */
    const MINUTOS_PARA_CONSIDERARLA_COLGADA = 30;

    /**
     * Devuelve la corrida abierta del usuario, o abre una nueva.
     *
     * Se reusa a propósito (decisión de Lucas): dos cambios de configuración seguidos
     * producirían dos corridas con números casi iguales y dos modales encima del otro. Al
     * reusar, el usuario ve un solo aviso con el total real.
     *
     * @param  int         $user_id
     * @param  string      $origen
     * @param  string|null $origen_detalle
     * @return \App\Models\PriceUpdateRun
     */
    public static function abrir_o_reusar($user_id, $origen = 'otro', $origen_detalle = null)
    {
        $abierta = PriceUpdateRun::where('user_id', $user_id)
            ->where('status', 'en_proceso')
            ->orderBy('id', 'DESC')
            ->first();

        if (!is_null($abierta)) {
            if (self::esta_colgada($abierta)) {
                Log::warning('PriceUpdateRunHelper: corrida colgada, se cierra y se abre una nueva', [
                    'price_update_run_id' => $abierta->id,
                    'started_at'          => $abierta->started_at,
                ]);

                $abierta->status      = 'error';
                $abierta->finished_at = Carbon::now();
                $abierta->save();
            } else {
                return $abierta;
            }
        }

        return PriceUpdateRun::create([
            'user_id'          => $user_id,
            'origen'           => $origen,
            'origen_detalle'   => $origen_detalle,
            'status'           => 'en_proceso',
            'total_chunks'     => 0,
            'processed_chunks' => 0,
            'chunks_encolados' => 0,
            'articles_updated' => 0,
            'started_at'       => Carbon::now(),
        ]);
    }

    /**
     * @param  \App\Models\PriceUpdateRun $run
     * @return bool
     */
    protected static function esta_colgada($run)
    {
        if (is_null($run->started_at)) {
            return false;
        }

        return Carbon::parse($run->started_at)
            ->addMinutes(self::MINUTOS_PARA_CONSIDERARLA_COLGADA)
            ->isPast();
    }

    /**
     * Cierra una corrida que no tiene ningún artículo que recalcular.
     *
     * Se notifica igual: un recálculo que no encontró artículos es información, no
     * silencio, y el modal tiene su estado vacío para eso.
     *
     * @param  \App\Models\PriceUpdateRun $run
     * @return void
     */
    public static function cerrar_sin_articulos($run)
    {
        $run->status           = 'sin_cambios';
        $run->articles_updated = 0;
        $run->stats_json       = json_encode(['proveedores' => [], 'categorias' => []]);
        $run->chunks_encolados = 1;
        $run->finished_at      = Carbon::now();
        $run->save();
    }
}
