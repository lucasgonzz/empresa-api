<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PriceUpdateRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Devuelve un array con:
     *  - `detalle`: el motivo ya recortado, para que la base y la notificación digan
     *    exactamente lo mismo (si difieren, el soporte y el usuario miran dos cosas
     *    distintas);
     *  - `avisar`: si corresponde notificarle al usuario.
     *
     * 🔴 `avisar` es false cuando la corrida YA estaba cerrada, y eso importa de verdad: con
     * `--tries=1`, un error sistémico hace fallar todos los chunks de la corrida, y sin este
     * corte una corrida de 500 lotes le manda al usuario 500 avisos de error apilados. El
     * motivo que queda es el del primero que llegó, que es el que vio la causa original.
     *
     * Es idempotente: la primera que cierra gana.
     *
     * @param  int|null    $price_update_run_id
     * @param  string|null $detalle
     * @return array
     */
    public static function cerrar_con_error($price_update_run_id, $detalle = null)
    {
        $detalle = self::recortar_detalle($detalle);

        if (is_null($price_update_run_id)) {
            return ['detalle' => $detalle, 'avisar' => true];
        }

        try {
            $run = PriceUpdateRun::find($price_update_run_id);

            if (is_null($run)) {
                return ['detalle' => $detalle, 'avisar' => true];
            }

            if ($run->status != 'en_proceso') {
                return ['detalle' => $detalle, 'avisar' => false];
            }

            $run->status        = 'error';
            $run->error_detalle = $detalle;
            $run->finished_at   = Carbon::now();
            $run->save();
        } catch (\Throwable $e) {
            /*
             * 🔴 Que no se pueda guardar el motivo no puede costarle el aviso al usuario.
             * Pasa, por ejemplo, en la base de un cliente donde la migración de
             * error_detalle todavía no corrió: el save tira "Unknown column" y, sin este
             * catch, la excepción se lleva puesta la notificación que venía después — y
             * encima en silencio, porque el failed() de un job se reporta y se sigue.
             */
            Log::error('PriceUpdateRunHelper: no se pudo guardar el motivo del error', [
                'price_update_run_id' => $price_update_run_id,
                'error'               => $e->getMessage(),
            ]);

            self::cerrar_sin_guardar_el_motivo($price_update_run_id);
        }

        return ['detalle' => $detalle, 'avisar' => true];
    }

    /**
     * Último intento de que la corrida no quede abierta cuando no se pudo guardar el motivo.
     *
     * @param  int $price_update_run_id
     * @return void
     */
    protected static function cerrar_sin_guardar_el_motivo($price_update_run_id)
    {
        try {
            DB::table('price_update_runs')
                ->where('id', $price_update_run_id)
                ->where('status', 'en_proceso')
                ->update([
                    'status'      => 'error',
                    'finished_at' => Carbon::now(),
                ]);
        } catch (\Throwable $e) {
            Log::error('PriceUpdateRunHelper: tampoco se pudo cerrar la corrida', [
                'price_update_run_id' => $price_update_run_id,
                'error'               => $e->getMessage(),
            ]);
        }
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

        /*
         * El mensaje de una QueryException trae la consulta entera pegada atrás
         * ("... (SQL: update articles set final_price = 1234 where id = 55)"). Eso lo lee el
         * usuario en el aviso: no le dice nada y encima le muestra datos de su propia base.
         */
        $posicion_del_sql = strpos($detalle, ' (SQL:');

        if ($posicion_del_sql !== false) {
            $detalle = rtrim(substr($detalle, 0, $posicion_del_sql));
        }

        if (mb_strlen($detalle) > self::MAX_LARGO_ERROR_DETALLE) {
            $detalle = mb_substr($detalle, 0, self::MAX_LARGO_ERROR_DETALLE - 1) . '…';
        }

        return $detalle;
    }
}
