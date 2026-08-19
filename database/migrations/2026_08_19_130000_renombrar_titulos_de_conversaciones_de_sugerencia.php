<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reescribe los títulos VIEJOS de las conversaciones que nacieron de una sugerencia:
 * "Sugerencia de stock #47" pasa a "Sugerencia de stock 19/08/2026" (pedido de Lucas,
 * 19/8/2026).
 *
 * El cambio de formato vive en AiConversation::titulo_con_fecha() y aplica de acá en
 * adelante. Sin esta migración, las conversaciones que ya están en la bandeja se quedan
 * con el "#id" para siempre y conviven dos formatos en la misma lista — que es
 * justamente lo que se venía a sacar.
 *
 * 🔴 El filtro es doble —`origen` de la familia Y `titulo LIKE '<prefijo> #%'`— y hacen
 * falta los dos: por `origen` solo se pisarían los títulos que la IA infirió, y por
 * `titulo` solo se pisarían conversaciones de otra familia que arranquen igual.
 *
 * ⚠️ El `LIKE` lleva comodín abierto al final, o sea que "Sugerencia de stock #47 (revisar
 * con el proveedor)" también matchearía y perdería el texto agregado. Hoy eso NO puede
 * pasar: no existe endpoint para renombrar una conversación (`routes/api.php` expone
 * index, store, destroy, messages, send_message y show_message, y nada más), así que el
 * único que escribe estos títulos es el job y el formato es fijo. **Si algún día se agrega
 * el renombrado, esta migración ya corrió y no vuelve a correr — pero el patrón de acá no
 * sirve de molde para la próxima.**
 *
 * La fecha sale del `created_at` de la SUGERENCIA, igual que el título nuevo. Si la
 * sugerencia ya no existe (se borró y la conversación quedó), cae al `created_at` de la
 * conversación: nació a los segundos de la sugerencia, así que la fecha es la misma salvo
 * en el cruce de la medianoche. Ese caso vale más que dejar el "#id" colgado.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class RenombrarTitulosDeConversacionesDeSugerencia extends Migration
{
    /**
     * origen de la conversación => [tabla de la sugerencia, prefijo del título].
     *
     * Toda familia nueva de sugerencias que cree conversaciones agrega su fila acá si
     * quiere que sus títulos viejos se migren.
     *
     * @var array
     */
    private $familias = array(
        'sugerencia_stock'  => array('stock_suggestions', 'Sugerencia de stock'),
        'sugerencia_compra' => array('purchase_suggestions', 'Sugerencia de compra'),
        'sugerencia_oferta' => array('offer_suggestions', 'Ofertas sugeridas'),
    );

    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_conversations')) {
            return;
        }

        foreach ($this->familias as $origen => $datos) {
            $tabla   = $datos[0];
            $prefijo = $datos[1];

            if (!Schema::hasTable($tabla)) {
                continue;
            }

            /*
             * En tandas y no todo junto: una cuenta con muchas corridas puede tener
             * cientos de conversaciones, y esto corre en el despliegue de cada negocio.
             */
            DB::table('ai_conversations')
                ->where('origen', $origen)
                ->where('titulo', 'LIKE', $prefijo . ' #%')
                ->orderBy('id')
                ->chunkById(200, function ($conversaciones) use ($tabla, $prefijo) {
                    foreach ($conversaciones as $conversacion) {
                        $fecha = $this->fecha_de_la_sugerencia($tabla, $conversacion);

                        if (is_null($fecha)) {
                            continue;
                        }

                        DB::table('ai_conversations')
                            ->where('id', $conversacion->id)
                            ->update(array('titulo' => $prefijo . ' ' . $fecha));
                    }
                });
        }
    }

    /**
     * Vuelve al formato viejo "<prefijo> #<referencia_id>".
     *
     * Es exactamente reversible porque el id de la sugerencia sigue guardado en
     * `referencia_id`: el título viejo no llevaba ningún dato que no esté en la fila.
     *
     * ⚠️ Acá el LIKE es más ancho que el del `up()` (`'<prefijo> %'`, sin el `#`), porque
     * después del renombrado el `#` ya no está. Con los títulos que el sistema genera hoy
     * alcanza exactamente el mismo conjunto que tocó el `up()`; la asimetría solo
     * importaría si alguna vez se pudiera editar el título a mano.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_conversations')) {
            return;
        }

        foreach ($this->familias as $origen => $datos) {
            $prefijo = $datos[1];

            DB::table('ai_conversations')
                ->where('origen', $origen)
                ->whereNotNull('referencia_id')
                ->where('titulo', 'LIKE', $prefijo . ' %')
                ->orderBy('id')
                ->chunkById(200, function ($conversaciones) use ($prefijo) {
                    foreach ($conversaciones as $conversacion) {
                        DB::table('ai_conversations')
                            ->where('id', $conversacion->id)
                            ->update(array('titulo' => $prefijo . ' #' . $conversacion->referencia_id));
                    }
                });
        }
    }

    /**
     * Fecha d/m/Y de la sugerencia que originó la conversación, o la de la propia
     * conversación si la sugerencia ya no está. null si no hay ninguna de las dos, para
     * que la fila quede como está en vez de titularse con una fecha inventada.
     *
     * @param string $tabla Tabla de la sugerencia
     * @param object $conversacion Fila de ai_conversations
     * @return string|null
     */
    private function fecha_de_la_sugerencia($tabla, $conversacion)
    {
        $crudo = null;

        if (!is_null($conversacion->referencia_id)) {
            $sugerencia = DB::table($tabla)
                ->where('id', $conversacion->referencia_id)
                ->first();

            if ($sugerencia && !empty($sugerencia->created_at)) {
                $crudo = $sugerencia->created_at;
            }
        }

        if (is_null($crudo) && !empty($conversacion->created_at)) {
            $crudo = $conversacion->created_at;
        }

        if (is_null($crudo)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($crudo)->format('d/m/Y');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
