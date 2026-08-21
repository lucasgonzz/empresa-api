<?php

namespace App\Http\Controllers\Helpers\puntos;

use App\Models\MovimientoPunto;
use App\Models\MovimientoPuntoConsumo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El saldo de puntos de un cliente y el FIFO sobre los lotes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 HAY DOS PREGUNTAS DISTINTAS Y NO SON EL MISMO NÚMERO. NO LAS UNIFIQUES.
 *
 *  1. EL PASIVO DEL PROGRAMA ("¿cuánto le debo hoy a mis clientes?"). Es `SUM(puntos)` de
 *     todo el libro, sin ninguna condición, y vive en el reporte
 *     (`PuntoController::saldo_vivo()`, que lo calcula hasta una fecha de corte). Es un
 *     número CONTABLE: mientras el barrido de `puntos:vencer` todavía no pasó, los puntos
 *     vencidos siguen siendo un pasivo, porque la fila negativa que los mata todavía no se
 *     escribió. Acá eso es `saldo_del_libro()`.
 *
 *  2. EL SALDO DISPONIBLE ("¿cuántos puntos puede gastar este cliente HOY?"). Es el que
 *     muestran VENDER y la ficha del cliente, y el que valida el canje. Eso es `saldo()`.
 *
 *  Hasta el 22/8/2026 las dos eran la misma frase (`SUM(puntos)`) y ESO ERA EL BUG: el
 *  consumo FIFO sale de `lotes_para_canje()`, que EXCLUYE los lotes vencidos, mientras que
 *  la validación medía contra el SUM, que los incluye hasta que el cron los barre. Con un
 *  lote vencido y el barrido todavía sin correr, el canje pasaba la validación, escribía el
 *  movimiento negativo, NO consumía un solo lote, y después `puntos:vencer` vencía el lote
 *  entero: el mismo punto se descontaba dos veces y el saldo quedaba negativo. Y de paso, la
 *  ficha del cliente le mostraba como disponibles puntos que ya estaban muertos.
 *
 *  🔴 LA CORRECCIÓN VA ACÁ Y EN UN SOLO LUGAR. `saldo()` es la única frase que leen los tres
 *  consumidores (VENDER, la ficha y el canje), así que la exclusión de vencidos se escribe
 *  UNA vez. Si mañana aparece un cuarto consumidor, hereda la regla por construcción. Lo que
 *  no se puede volver a hacer es que cada pantalla arme su propio SELECT: es la clase "el
 *  mismo invariante decidido con dos criterios distintos" (APRENDER_NO_PARCHEAR.md:997), que
 *  es exactamente lo que pasó entre la validación del canje y el FIFO.
 *
 *  La definición elegida —`saldo_del_libro()` menos el remanente de los lotes YA VENCIDOS
 *  que el barrido todavía no se llevó— tiene una propiedad que es la que la hace correcta:
 *  **es invariante frente a `puntos:vencer`**. Antes del barrido, el vencido se resta acá;
 *  después, ya lo restó la fila negativa del libro y el remanente del lote es 0. El número
 *  que ve el cliente no cambia por el paso del cron, que es justo lo que se rompió.
 *
 *  Y se define contra los LOTES y no contra "lo que el cron va a barrer" a propósito: si el
 *  comercio apaga el vencimiento, `puntos:vencer` no toca nada, pero `lotes_para_canje()`
 *  sigue dejando afuera los lotes con un `vence_at` viejo. Esos puntos no se pueden gastar,
 *  así que tampoco se muestran como disponibles.
 *
 *  Los revertidos siguen siendo FILAS NEGATIVAS del libro y no una condición del SELECT: esa
 *  parte de la doctrina original no cambió.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class PuntosSaldoHelper {

    /**
     * Tolerancia de un centavo, el mismo criterio que CurrentAcountPagoHelper:141.
     */
    const DELTA = 0.01;

    /**
     * Los tipos de movimiento que son LOTES, o sea de los que se puede consumir.
     *
     * 'ganados' es el caso normal. 'ajuste' entra porque un ajuste manual POSITIVO también le
     * agrega puntos al cliente, y si no fuera un lote, un cliente cuyo saldo entero viniera de
     * ajustes tendría saldo mayor a cero y nada de donde consumir al canjear: el canje
     * escribiría un movimiento negativo sin una sola fila de consumo y el vencimiento no
     * sabría qué mirar. Los ajustes NEGATIVOS no son lotes: consumen, como un canje.
     */
    const TIPOS_LOTE = ['ganados', 'ajuste'];

    /**
     * EL SALDO DISPONIBLE del cliente: lo que puede gastar hoy.
     *
     * Es `saldo_del_libro()` menos el remanente de los lotes que ya vencieron y que el
     * barrido de `puntos:vencer` todavía no se llevó. Ver el docblock de la clase: es la
     * única frase que leen VENDER, la ficha del cliente y la validación del canje, y es
     * invariante frente al paso del cron.
     *
     * @param  int  $user_id
     * @param  int  $client_id
     * @return float
     */
    static function saldo($user_id, $client_id) {

        if (is_null($user_id) || is_null($client_id)) {
            return 0;
        }

        $disponible = self::saldo_del_libro($user_id, $client_id)
                        - self::vencidos_sin_barrer($user_id, $client_id);

        return round($disponible, 2);
    }

    /**
     * `SUM(movimiento_puntos.puntos)` y nada más: el PASIVO del programa para este cliente.
     *
     * 🔴 No la uses para decidir si un canje entra ni para mostrarle un número al cliente:
     * para eso está `saldo()`. Esta es la mitad contable, la que incluye los puntos vencidos
     * que el barrido todavía no convirtió en fila negativa.
     *
     * @param  int  $user_id
     * @param  int  $client_id
     * @return float
     */
    static function saldo_del_libro($user_id, $client_id) {

        if (is_null($user_id) || is_null($client_id)) {
            return 0;
        }

        return round((float) MovimientoPunto::where('user_id', $user_id)
                                            ->where('client_id', $client_id)
                                            ->sum('puntos'), 2);
    }

    /**
     * Los puntos del cliente que YA VENCIERON y que el barrido todavía no se llevó.
     *
     * Es el remanente vivo de los lotes con `vence_at` en el pasado, o sea exactamente el
     * complemento de lo que `lotes_para_canje()` deja consumir. Cuando `puntos:vencer` corre,
     * este número se va a cero y la misma cantidad aparece como fila negativa del libro: por
     * eso `saldo()` no se mueve cuando pasa el cron.
     *
     * Se suma en SQL y no recorriendo la colección porque `query_lotes_vivos()` ya filtra
     * `(puntos - consumido) > DELTA`: todas las filas que entran aportan un remanente
     * positivo, así que el piso de `remanente_de_lote()` no puede cambiar el resultado.
     *
     * @param  int  $user_id
     * @param  int  $client_id
     * @return float
     */
    static function vencidos_sin_barrer($user_id, $client_id) {

        if (is_null($user_id) || is_null($client_id)) {
            return 0;
        }

        $total = self::query_lotes_vivos($user_id, $client_id)
                    ->whereNotNull('vence_at')
                    ->where('vence_at', '<=', Carbon::now())
                    ->sum(DB::raw('`puntos` - `consumido`'));

        return round((float) $total, 2);
    }

    /**
     * La query de los lotes con remanente vivo, sin ejecutar.
     *
     * Se devuelve la query y no la colección para que el comando de vencimiento pueda
     * agregarle su propio `where('vence_at', '<=', now())` y barrer por comercio sin cliente,
     * sin que la definición de "lote vivo" se escriba dos veces.
     *
     * Un lote está vivo si:
     *   - no fue anulado (`anulado_at IS NULL`): un lote de una venta anulada o editada no
     *     puede consumirse ni vencerse, ya se revirtió por el total;
     *   - le queda remanente (`puntos - consumido > 0`).
     *
     * 🔴 A propósito NO filtra por `vence_at`: quién puede consumir un lote vencido es una
     * pregunta del que llama. El canje sí lo excluye (ver lotes_para_canje()); el comando de
     * vencimiento busca exactamente los vencidos.
     *
     * @param  int       $user_id
     * @param  int|null  $client_id  null = todos los clientes del comercio.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function query_lotes_vivos($user_id, $client_id = null) {

        $query = MovimientoPunto::where('user_id', $user_id)
                                ->whereIn('tipo', self::TIPOS_LOTE)
                                ->where('puntos', '>', 0)
                                ->whereNull('anulado_at')
                                ->whereRaw('(`puntos` - `consumido`) > ?', [self::DELTA]);

        if (!is_null($client_id)) {
            $query->where('client_id', $client_id);
        }

        return $query;
    }

    /**
     * Los lotes de los que un canje puede sacar puntos, en orden FIFO.
     *
     * Los más viejos primero, que es lo que hace que el vencimiento no le coma puntos al
     * cliente que compra seguido. El desempate por `id` importa: dos lotes de la misma venta
     * (venta mixta con dos listas) se escriben en el mismo segundo y `created_at` no alcanza
     * para que el orden sea estable entre corridas.
     *
     * @param  int  $user_id
     * @param  int  $client_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    static function lotes_para_canje($user_id, $client_id) {

        return self::query_lotes_vivos($user_id, $client_id)
                    ->where(function ($q) {
                        $q->whereNull('vence_at')
                            ->orWhere('vence_at', '>', Carbon::now());
                    })
                    ->orderBy('created_at', 'ASC')
                    ->orderBy('id', 'ASC')
                    ->get();
    }

    /**
     * Cuántos puntos del cliente vencen dentro de los próximos N días.
     *
     * Es el remanente vivo de los lotes con `vence_at` entre ahora y el corte, no la suma de
     * `puntos`: un lote de 100 puntos del que ya se canjearon 80 solo puede hacer vencer 20.
     *
     * @param  int  $user_id
     * @param  int  $client_id
     * @param  int  $dias
     * @return float
     */
    static function puntos_por_vencer($user_id, $client_id, $dias = 90) {

        $lotes = self::query_lotes_vivos($user_id, $client_id)
                    ->whereNotNull('vence_at')
                    ->where('vence_at', '>', Carbon::now())
                    ->where('vence_at', '<=', Carbon::now()->addDays($dias))
                    ->get();

        $total = 0;

        foreach ($lotes as $lote) {
            $total += self::remanente_de_lote($lote);
        }

        return round($total, 2);
    }

    /**
     * Lo que le queda vivo a un lote.
     *
     * Sale de la columna `consumido` y no de sumar `movimiento_punto_consumos`: la columna es
     * la fuente y la tabla de consumos es el detalle auditable. Que la resta se haga siempre
     * contra la misma columna es lo que hace que deshacer un canje sea exacto.
     *
     * @param  \App\Models\MovimientoPunto  $lote
     * @return float
     */
    static function remanente_de_lote($lote) {

        $remanente = (float) $lote->puntos - (float) $lote->consumido;

        return $remanente > 0 ? round($remanente, 2) : 0;
    }

    /**
     * Consume puntos de los lotes del cliente, en orden FIFO, a nombre de un movimiento de
     * consumo ya creado.
     *
     * @param  \App\Models\MovimientoPunto  $movimiento_consumo  El 'canjeados', 'vencidos' o
     *                                                           'ajuste' negativo que se lleva
     *                                                           los puntos. Ya persistido.
     * @param  float                        $puntos_a_consumir   Siempre POSITIVO.
     * @return float  Cuánto se pudo consumir de verdad. Puede ser menos que lo pedido si los
     *                lotes no alcanzan; el que llama decide qué hacer con eso (el canje ya
     *                validó el saldo antes, el vencimiento pide exactamente el remanente).
     */
    static function consumir_fifo($movimiento_consumo, $puntos_a_consumir) {

        $lotes = self::lotes_para_canje($movimiento_consumo->user_id, $movimiento_consumo->client_id);

        return self::consumir_lotes($movimiento_consumo, $lotes, $puntos_a_consumir);
    }

    /**
     * Consume puntos de una lista de lotes ya elegida por el que llama.
     *
     * El comando de vencimiento la usa con los lotes vencidos y sin tope, porque ahí no se
     * consume "hasta juntar N puntos" sino "todo lo que le quede a estos lotes". El canje la
     * usa vía consumir_fifo() con el tope puesto.
     *
     * @param  \App\Models\MovimientoPunto  $movimiento_consumo
     * @param  iterable                     $lotes              En el orden en que se consumen.
     * @param  float|null                   $puntos_a_consumir  null = el remanente completo de
     *                                                          cada lote de la lista.
     * @return float  Total consumido.
     */
    static function consumir_lotes($movimiento_consumo, $lotes, $puntos_a_consumir = null) {

        $sin_tope = is_null($puntos_a_consumir);
        $restante = $sin_tope ? 0 : (float) $puntos_a_consumir;

        if (!$sin_tope && $restante <= self::DELTA) {
            return 0;
        }

        $consumido_total = 0;

        foreach ($lotes as $lote) {

            $remanente = self::remanente_de_lote($lote);

            if ($remanente <= self::DELTA) {
                continue;
            }

            if ($sin_tope) {
                $tomar = $remanente;
            } else {
                $tomar = $remanente < $restante ? $remanente : $restante;
            }

            $tomar = round($tomar, 2);

            if ($tomar <= 0) {
                continue;
            }

            MovimientoPuntoConsumo::create([
                'movimiento_origen_id'  => $lote->id,
                'movimiento_consumo_id' => $movimiento_consumo->id,
                'puntos'                => $tomar,
            ]);

            /*
             * Se escribe el valor calculado y no un `increment()`: el lote ya está en memoria,
             * la operación entera corre adentro de la transacción de la venta, y así el objeto
             * que tiene el que llama queda coherente con la base sin volver a leerla.
             */
            $lote->consumido = round((float) $lote->consumido + $tomar, 2);
            $lote->save();

            $consumido_total += $tomar;

            if (!$sin_tope) {
                $restante = round($restante - $tomar, 2);

                if ($restante <= self::DELTA) {
                    break;
                }
            }
        }

        return round($consumido_total, 2);
    }

    /**
     * Deshace un consumo: devuelve los puntos a los lotes de los que salieron y borra las filas
     * de consumo.
     *
     * 🔴 Devuelve a LOS LOTES DE LOS QUE SALIÓ, uno por uno, y no "los N puntos al lote más
     * viejo". Es todo el motivo por el que existe `movimiento_punto_consumos`: sin el detalle
     * por lote, deshacer un canje después de que venció un lote intermedio devolvería puntos a
     * un lote equivocado y el vencimiento siguiente los volvería a matar.
     *
     * NO borra el movimiento de consumo en sí: eso lo decide el que llama, porque el canje lo
     * borra y el vencimiento (que es un hecho con fecha, no una operación reversible) no.
     *
     * @param  \App\Models\MovimientoPunto  $movimiento_consumo
     * @return float  Cuántos puntos se devolvieron a los lotes.
     */
    static function deshacer_consumos($movimiento_consumo) {

        if (is_null($movimiento_consumo) || is_null($movimiento_consumo->id)) {
            return 0;
        }

        $consumos = MovimientoPuntoConsumo::where('movimiento_consumo_id', $movimiento_consumo->id)->get();

        $devuelto = 0;

        foreach ($consumos as $consumo) {

            $lote = MovimientoPunto::find($consumo->movimiento_origen_id);

            if (!is_null($lote)) {

                $nuevo_consumido = round((float) $lote->consumido - (float) $consumo->puntos, 2);

                /*
                 * Piso en 0: si por una corrida vieja el `consumido` quedara desalineado, se
                 * prefiere un lote entero disponible antes que un negativo que rompa el
                 * remanente de todos los cálculos que vienen después.
                 */
                $lote->consumido = $nuevo_consumido > 0 ? $nuevo_consumido : 0;
                $lote->save();
            }

            $devuelto += (float) $consumo->puntos;

            $consumo->delete();
        }

        return round($devuelto, 2);
    }
}
