<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\AiConversationController;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * EL VIGÍA DE LOS TURNOS COLGADOS DEL ASISTENTE (misión agente-mejoras-uso-real, ronda de arreglos de la
 * revisión independiente, 30/10/2026). Lo corre el comando `asistente:vigilar-turnos` cada 5 minutos.
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 5 y 6 del 28/10/2026, «cargame un gasto de 207.500»). Si la cola se
 * queda sin consumidor, el mensaje del asistente queda en `pendiente` para siempre: el admin deja de
 * esperar a los 180 s y manda su disculpa, el pedido del dueño no lo contesta nadie, y en el sistema el
 * globo de "pensando" queda colgado hasta que alguien escriba de nuevo EN ESA conversación
 * (AiConversationController cierra los vencidos solo al mandar otro mensaje, y solo de esa
 * conversación). Peor: si la cola revive horas después, el job corría el turno viejo y podía cargar un
 * gasto que el dueño ya había vuelto a pedir.
 *
 * Lo que hace, por cada mensaje del asistente en `pendiente` con más de MINUTOS_VENCIMIENTO_PENDIENTE
 * minutos (los mismos 10 del controlador):
 *   1. lo pasa a `error` con el texto de siempre (ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE),
 *      con un UPDATE que exige `pendiente` (si el job terminó en el medio, no se pisa);
 *   2. hace lo mismo que el cierre de un error: descarta sus tarjetas propuestas, cierra el audio que no
 *      llegó a transcribirse y los pedidos de imagen;
 *   3. si era de WhatsApp, deja el aviso al dueño citando su pedido («No te pude contestar "cargame un
 *      gasto de 207.500…" (28/10 18:28). ¿Lo retomo?») por el camino de avisos que consulta el admin
 *      (AvisosDeTurnosIaHelper);
 *   4. avisa a la pantalla (ChatIaMensajeActualizado), para que el globo deje de girar.
 *
 * Y el turno revivido tarde NO corre: ResponderMensajeChatIaJob::handle() ya volvía sin hacer nada si su
 * mensaje no está `pendiente` (verificado en esta ronda; lo fija 25_Vigia_de_turnos_colgados_Test).
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class VigiaDeTurnosIaHelper
{
    /** Cuántos turnos colgados se cierran por corrida como mucho (el resto, en la siguiente). */
    const TOPE_POR_CORRIDA = 200;

    /**
     * Cierra los turnos colgados y devuelve cuántos cerró.
     *
     * @param  int|null  $minutos  Desde cuántos minutos en `pendiente` se considera colgado (null = los del controlador).
     * @return int
     */
    public static function cerrar_colgados($minutos = null)
    {
        $minutos = is_null($minutos) ? AiConversationController::MINUTOS_VENCIMIENTO_PENDIENTE : (int) $minutos;

        $colgados = self::colgados($minutos);

        $cerrados = 0;

        foreach ($colgados as $id) {

            if (self::cerrar((int) $id, $minutos)) {

                $cerrados++;
            }
        }

        return $cerrados;
    }

    /**
     * Los ids de los mensajes del asistente en `pendiente` desde hace más de `$minutos`, los más viejos
     * primero, con tope.
     *
     * @param  int  $minutos
     * @return array<int, int>
     */
    public static function colgados($minutos)
    {
        return AiMessage::where('rol', 'assistant')
                        ->where('estado', 'pendiente')
                        ->where('created_at', '<', Carbon::now()->subMinutes((int) $minutos))
                        ->orderBy('id')
                        ->limit(self::TOPE_POR_CORRIDA)
                        ->pluck('id')
                        ->all();
    }

    /**
     * Cierra UN turno colgado (ver los cuatro pasos del docblock). Protegido por pasos: si avisar o
     * descartar falla, el mensaje ya quedó en error y el resto sigue.
     *
     * @param  int  $id
     * @param  int  $minutos
     * @return bool  false si el mensaje ya no estaba `pendiente` (lo terminó el job en el medio).
     */
    protected static function cerrar($id, $minutos)
    {
        $cierre = [
            'contenido'     => ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE,
            'estado'        => 'error',
            'error_mensaje' => 'turno colgado: siguió en pendiente más de ' . (int) $minutos . ' minutos (cola sin consumidor o worker caído); lo cerró el vigía',
        ];

        if (ColumnasDeLaAppIaHelper::hay_columna('ai_messages', 'progreso')) {

            $cierre['progreso'] = null;
        }

        $actualizados = AiMessage::where('id', (int) $id)->where('estado', 'pendiente')->update($cierre);

        if ((int) $actualizados === 0) {

            return false;
        }

        $mensaje = AiMessage::find((int) $id);

        if (is_null($mensaje)) {

            return true;
        }

        $pedido = AiMessage::where('ai_conversation_id', (int) $mensaje->ai_conversation_id)
                            ->where('rol', 'user')
                            ->where('id', '<', (int) $mensaje->id)
                            ->orderBy('id', 'DESC')
                            ->first();

        try {

            AccionesIaHelper::descartar_de_mensaje((int) $mensaje->id);
            PedidosDeImagenIaHelper::cerrar_los_del_mensaje((int) $mensaje->id);
            AudioDelMensajeIaHelper::cerrar_si_quedo_pendiente($pedido);

        } catch (\Throwable $e) {

            Log::warning('VigiaDeTurnosIaHelper: no se pudo cerrar todo lo del turno colgado', [
                'ai_message_id' => (int) $mensaje->id,
                'error'         => $e->getMessage(),
            ]);
        }

        if ($mensaje->es_de_whatsapp()) {

            AvisosDeTurnosIaHelper::dejar($mensaje, AvisosDeTurnosIaHelper::texto_de_turno_colgado($pedido));
        }

        try {

            $conversation = AiConversation::find((int) $mensaje->ai_conversation_id);

            if (!is_null($conversation)) {

                event(new ChatIaMensajeActualizado((int) $conversation->auth_user_id, (int) $conversation->id, (int) $mensaje->id, 'error'));
            }

        } catch (\Throwable $e) {

            Log::warning('VigiaDeTurnosIaHelper: no se pudo avisar a la pantalla', [
                'ai_message_id' => (int) $mensaje->id,
                'error'         => $e->getMessage(),
            ]);
        }

        return true;
    }
}
