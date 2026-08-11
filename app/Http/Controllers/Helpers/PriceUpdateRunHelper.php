<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PriceUpdateRun;
use Carbon\Carbon;

/**
 * Abre y cierra las corridas de recálculo de precios.
 */
class PriceUpdateRunHelper
{
    /** Lo que entra en error_detalle: la columna es string 500 y el texto lo lee una persona. */
    const MAX_LARGO_ERROR_DETALLE = 500;

    /**
     * Abre una corrida nueva. Siempre.
     *
     * 🔴 Antes esto era abrir_o_reusar: si el usuario ya tenía una corrida en_proceso, el
     * disparo nuevo se le colgaba encima para que no llegaran dos modales seguidos. Se cayó
     * el 11/8/2026 porque abría una condición de carrera que no se puede tapar: el flag
     * chunks_encolados y los contadores son de la CORRIDA y no del productor, así que con
     * dos productores en la misma fila el que terminaba primero cerraba por el otro,
     * notificaba números parciales y el segundo finalizador salía mudo porque la corrida ya
     * no estaba en_proceso. El usuario no veía nada raro: veía un número menor al real.
     *
     * Decisión de Lucas: cada disparo tiene su corrida, su contador y su notificación. Dos
     * disparos seguidos dan dos avisos, en orden — el segundo recalcula de nuevo lo del
     * primero y eso no rompe nada.
     *
     * Con esto se va también la guarda de "corrida colgada": existía sólo para que una
     * corrida que nadie iba a cerrar no se comiera por reuso todos los avisos siguientes.
     *
     * @param  int         $user_id
     * @param  string      $origen
     * @param  string|null $origen_detalle
     * @return \App\Models\PriceUpdateRun
     */
    public static function abrir($user_id, $origen = 'otro', $origen_detalle = null)
    {
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
        $run->stats_json       = json_encode(['proveedores' => []]);
        $run->chunks_encolados = 1;
        $run->finished_at      = Carbon::now();
        $run->save();
    }

    /**
     * Deja la corrida en error con un motivo legible, si todavía estaba abierta.
     *
     * Devuelve el motivo ya recortado para que el que la cierra mande exactamente lo mismo
     * en la notificación: si el texto de la base y el del aviso difieren, el soporte y el
     * usuario están mirando dos cosas distintas.
     *
     * Es idempotente: la primera que la cierra gana. Un chunk que falla y el finalizador
     * que muere después pueden llegar los dos acá, y el motivo que vale es el primero.
     *
     * @param  int|null    $price_update_run_id
     * @param  string|null $detalle
     * @return string|null
     */
    public static function cerrar_con_error($price_update_run_id, $detalle = null)
    {
        $detalle = self::recortar_detalle($detalle);

        if (is_null($price_update_run_id)) {
            return $detalle;
        }

        $run = PriceUpdateRun::find($price_update_run_id);

        if (is_null($run) || $run->status != 'en_proceso') {
            return $detalle;
        }

        $run->status        = 'error';
        $run->error_detalle = $detalle;
        $run->finished_at   = Carbon::now();
        $run->save();

        return $detalle;
    }

    /**
     * @param  string|null $detalle
     * @return string|null
     */
    public static function recortar_detalle($detalle)
    {
        if (is_null($detalle)) {
            return null;
        }

        $detalle = trim((string) $detalle);

        if ($detalle === '') {
            return null;
        }

        if (mb_strlen($detalle) > self::MAX_LARGO_ERROR_DETALLE) {
            $detalle = mb_substr($detalle, 0, self::MAX_LARGO_ERROR_DETALLE - 1) . '…';
        }

        return $detalle;
    }
}
