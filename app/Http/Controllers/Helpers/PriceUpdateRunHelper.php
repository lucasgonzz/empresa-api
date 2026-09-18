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
        $run = PriceUpdateRun::create([
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

        /*
         * El registro visible para el usuario (misión procesos-en-segundo-plano, 18/9/2026)
         * nace junto con la corrida, acá y no en el productor: abrir() es el único punto por el
         * que pasan las dos puertas (ProcessSetFinalPrices y PriceTypeHelper), así que un
         * recálculo que arranque por cualquiera de las dos aparece en la píldora. El total en
         * lotes todavía no se conoce —lo fija el productor cuando termina de encolar—, por eso
         * arranca sin total y con la barra indeterminada. El helper nunca tira: si el registro
         * falla, la corrida sigue igual.
         */
        BackgroundProcessHelper::iniciar($user_id, 'recalculo_precios', 'Recálculo de precios', [
            'referencia' => $run,
            'unidad'     => 'lotes',
            'etapa'      => 'Preparando los artículos',
            'detalle'    => self::detalle_del_origen($run),
            'resultado'  => ['origen_texto' => $run->origen_texto],
        ]);

        return $run;
    }

    /**
     * Lo que lee el usuario debajo del título en la píldora: el origen traducido y, si lo hay,
     * el detalle ("Se recalcularon por un cambio en un proveedor · Bulonera").
     *
     * @param  \App\Models\PriceUpdateRun $run
     * @return string
     */
    protected static function detalle_del_origen($run)
    {
        $detalle = (string) $run->origen_texto;

        if (!is_null($run->origen_detalle) && trim((string) $run->origen_detalle) !== '') {
            $detalle .= ' · ' . trim((string) $run->origen_detalle);
        }

        return $detalle;
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

        // Cierra también en la píldora: sin artículos no es un error, es un proceso que terminó
        // sin nada que cambiar, y la etapa se lo dice al usuario.
        BackgroundProcessHelper::completar(
            BackgroundProcessHelper::por_referencia($run),
            ['articulos_actualizados' => 0, 'proveedores' => 0],
            'Sin cambios'
        );
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

        /*
         * Declarada afuera del try: el catch la necesita para cerrar también el registro
         * visible, y si el find() mismo tiró, queda en null y no hay nada que cerrar.
         */
        $run = null;

        try {
            $run = PriceUpdateRun::find($price_update_run_id);

            if (is_null($run)) {
                return ['detalle' => $detalle, 'avisar' => true];
            }

            /* Ya está en error: del error avisó el primero que la cerró, con la causa
               original. Este es uno de los otros 499 lotes que cayeron por lo mismo. */
            if ($run->status == 'error') {
                return ['detalle' => $detalle, 'avisar' => false];
            }

            /*
             * Cerró bien (terminado / sin_cambios) y algo falló DESPUÉS. Pasa cuando lo que
             * falla es la notificación de éxito misma: la corrida ya está guardada, pero el
             * usuario no recibió nada. Callarse acá lo dejaría sin el aviso bueno y sin el
             * malo, que es peor que los dos juntos.
             */
            if ($run->status != 'en_proceso') {
                return ['detalle' => $detalle, 'avisar' => true];
            }

            $run->status        = 'error';
            $run->error_detalle = $detalle;
            $run->finished_at   = Carbon::now();
            $run->save();

            /*
             * El registro visible cae junto con la corrida, y SOLO acá: los dos returns de
             * arriba son corridas que ya estaban cerradas (por este mismo camino o por el
             * finalizador), y cerrarlas de nuevo en la píldora pisaría un "Terminado" legítimo
             * con un "Falló". fallar() es idempotente de todas formas, pero la regla de quién
             * cierra se decide acá, no en el helper.
             */
            BackgroundProcessHelper::fallar(
                BackgroundProcessHelper::por_referencia($run),
                self::mensaje_para_el_registro($detalle)
            );
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

            /*
             * La corrida se cerró igual (sin motivo), así que la píldora también tiene que
             * dejar de decir "en proceso". Si el find() fue lo que tiró, $run es null y
             * por_referencia() devuelve null: no hay registro que cerrar.
             */
            if (!is_null($run)) {
                BackgroundProcessHelper::fallar(
                    BackgroundProcessHelper::por_referencia($run),
                    self::mensaje_para_el_registro($detalle)
                );
            }
        }

        return ['detalle' => $detalle, 'avisar' => true];
    }

    /**
     * Texto del error para el registro visible. El detalle ya viene recortado y sin SQL; lo
     * único que se agrega es un texto fijo cuando no hay ninguno, porque una fila en "fallo"
     * sin motivo deja al usuario sin nada que leer.
     *
     * @param  string|null $detalle
     * @return string
     */
    protected static function mensaje_para_el_registro($detalle)
    {
        return is_null($detalle) ? 'El recálculo de precios se interrumpió y quedó incompleto.' : $detalle;
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

        /*
         * El mensaje de una QueryException trae la consulta entera pegada atrás
         * ("... (SQL: update articles set final_price = 1234 where id = 55)"). Eso lo lee el
         * usuario en el aviso: no le dice nada y encima le muestra datos de su propia base.
         * Se busca sin el espacio de adelante porque el trim ya se lo comió si el mensaje
         * empezaba ahí.
         */
        $posicion_del_sql = strpos($detalle, '(SQL:');

        if ($posicion_del_sql !== false) {
            $detalle = rtrim(substr($detalle, 0, $posicion_del_sql));
        }

        /* Después del recorte, no antes: si lo único que había era el SQL, no queda nada que
           mostrar y un "Qué pasó" en blanco es peor que no poner nada. */
        if ($detalle === '') {
            return null;
        }

        if (mb_strlen($detalle) > self::MAX_LARGO_ERROR_DETALLE) {
            $detalle = mb_substr($detalle, 0, self::MAX_LARGO_ERROR_DETALLE - 1) . '…';
        }

        return $detalle;
    }
}
