<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * LO QUE EL DUEÑO TIENE QUE SABER CUANDO UN TURNO DE WHATSAPP NO TERMINÓ (misión agente-mejoras-uso-real,
 * ronda de arreglos de la revisión independiente, 30/10/2026).
 *
 * 🔴 POR QUÉ HACE FALTA UN AVISO APARTE. Con el mensaje del asistente en `error`, el admin NO lee el
 * contenido: manda su disculpa fija («Uf, no pude contestarte esta vez…», EnviarMensajeAlAsistenteJob
 * del admin-api). Así, el texto del empresa que le decía al dueño «me llegaron 3 fotos y quedaron
 * guardadas: escribime "seguí"» (ResponderMensajeChatIaJob) nunca le llegaba por WhatsApp. Y un turno que
 * se queda en `pendiente` (la cola sin consumidor) tampoco le avisaba nada: el admin deja de esperar a
 * los 180 s, manda la disculpa, y el pedido —«cargame un gasto de 207.500»— queda olvidado.
 *
 * Este helper deja el aviso en `ai_avisos_del_asistente` y AvisosDelAsistenteHelper lo sirve por el
 * camino que el admin YA consulta (avisos-pendientes / avisos/avisado, id `turno_sin_terminar:{uuid}`),
 * el mismo del aviso de «ya leí la factura». Sin tocar el admin.
 *
 * 🔴 TRES GUARDAS, Y NINGUNA LANZA (lo llaman el catch y el failed() del job y el vigía de turnos):
 *   - solo para mensajes del canal WhatsApp (en el sistema y en la app el texto del error se ve);
 *   - solo si el admin de este dueño anunció que consulta los avisos (AvisosDelAsistenteHelper::
 *     admin_avisa): un aviso que nadie va a mandar no se promete;
 *   - UNO por mensaje del asistente: si ya hay uno para ese mensaje, no se deja otro.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class AvisosDeTurnosIaHelper
{
    /** Horas en las que el aviso todavía se manda (la ventana de 24 h de WhatsApp, como los otros avisos). */
    const HORAS_DEL_AVISO = 24;

    /** Cuántos caracteres del pedido del dueño se citan en el aviso de un turno colgado. */
    const LARGO_DE_LA_CITA = 60;

    /** @var bool|null  Si la tabla existe en esta base (se pregunta una vez por proceso). */
    protected static $hay_tabla = null;

    /**
     * Deja el aviso de un turno que no terminó, o null si no corresponde (ver las tres guardas del
     * docblock). Nunca lanza.
     *
     * @param  \App\Models\AiMessage  $assistant  El mensaje del asistente que no terminó.
     * @param  string  $texto  Lo que sale por WhatsApp, tal cual.
     * @return \App\Models\AiAvisoDelAsistente|null
     */
    public static function dejar(AiMessage $assistant, $texto)
    {
        try {

            $texto = trim((string) $texto);

            if ($texto === '' || !$assistant->es_de_whatsapp() || !self::hay_tabla()) {

                return null;
            }

            $conversation = AiConversation::find((int) $assistant->ai_conversation_id);

            if (is_null($conversation) || !AvisosDelAsistenteHelper::admin_avisa((int) $conversation->user_id)) {

                return null;
            }

            /* Uno por mensaje: el job y el vigía no pueden dejar dos para el mismo turno. */
            $ya = AiAvisoDelAsistente::where('ai_message_id', (int) $assistant->id)->first();

            if (!is_null($ya)) {

                return $ya;
            }

            return AiAvisoDelAsistente::create([
                'uuid'               => (string) Str::uuid(),
                'owner_id'           => (int) $conversation->user_id,
                'ai_conversation_id' => (int) $conversation->id,
                'ai_message_id'      => (int) $assistant->id,
                'tipo'               => AiAvisoDelAsistente::TIPO_TURNO_SIN_TERMINAR,
                'texto'              => $texto,
            ]);

        } catch (\Throwable $e) {

            Log::warning('AvisosDeTurnosIaHelper: no se pudo dejar el aviso del turno', [
                'ai_message_id' => (int) $assistant->id,
                'error'         => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Los avisos del dueño que todavía no salieron, de las últimas HORAS_DEL_AVISO horas, del más viejo
     * al más nuevo. Vacío si la tabla no existe (un upgrade a medio correr).
     *
     * @param  \App\Models\User  $dueno
     * @param  int  $tope
     * @return \Illuminate\Support\Collection
     */
    public static function pendientes_de_aviso(User $dueno, $tope)
    {
        if (!self::hay_tabla()) {

            return collect();
        }

        return AiAvisoDelAsistente::where('owner_id', (int) $dueno->id)
                                    ->whereNull('avisado_por_whatsapp_at')
                                    ->where('created_at', '>=', Carbon::now()->subHours(self::HORAS_DEL_AVISO))
                                    ->orderBy('created_at')
                                    ->orderBy('id')
                                    ->limit((int) $tope)
                                    ->get();
    }

    /**
     * La frase de las fotos que llegaron con el pedido y no se cargaron, o vacío si no hay ninguna.
     * Es la misma para el contenido del mensaje en error (lo que se ve en el sistema y queda en el
     * historial) y para el aviso por WhatsApp.
     *
     * @param  int  $sin_usar  Cuántas fotos del pedido no usó ninguna carga.
     * @return string  Sin espacio adelante.
     */
    public static function frase_de_fotos_sin_cargar($sin_usar)
    {
        $sin_usar = (int) $sin_usar;

        if ($sin_usar <= 0) {

            return '';
        }

        return $sin_usar === 1
            ? 'Me llegó la foto y quedó guardada, pero no llegué a cargarla. Escribime «seguí» y continúo.'
            : 'Me llegaron ' . $sin_usar . ' fotos y quedaron guardadas, pero no llegué a cargarlas. Escribime «seguí» y continúo.';
    }

    /**
     * La frase de las fotos sin cargar de un turno que no terminó (frase_de_fotos_sin_cargar() con las
     * fotos del pedido que ninguna carga usó), o vacío. Protegido: si algo falla, vacío (no se dice nada).
     *
     * @param  \App\Models\AiMessage  $assistant  El mensaje del asistente que no terminó.
     * @param  \App\Models\AiMessage|null  $pedido  El mensaje del dueño que contestaba.
     * @return string
     */
    public static function frase_del_turno(AiMessage $assistant, $pedido)
    {
        try {

            $sin_usar = $pedido instanceof AiMessage
                ? AiMessageImagen::where('ai_message_id', (int) $pedido->id)->whereNull('gestionada_at')->count()
                : 0;

            return self::frase_de_fotos_sin_cargar($sin_usar);

        } catch (\Throwable $e) {

            Log::warning('AvisosDeTurnosIaHelper: no se pudieron contar las fotos del turno', [
                'ai_message_id' => (int) $assistant->id,
                'error'         => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * El aviso de un turno que quedó colgado y cerró el vigía: cita lo que pidió el dueño y cuándo, y
     * pregunta si lo retoma. «No te pude contestar "cargame un gasto de 207.500…" (28/10 18:28). ¿Lo
     * retomo?». Si el pedido eran solo fotos, lo dice; si quedaron fotos sin cargar, también.
     *
     * @param  \App\Models\AiMessage|null  $pedido  El mensaje del dueño que el turno contestaba.
     * @return string
     */
    public static function texto_de_turno_colgado($pedido)
    {
        if (!($pedido instanceof AiMessage)) {

            return 'No te pude contestar tu último mensaje. ¿Lo retomo?';
        }

        $cuando = is_null($pedido->created_at) ? '' : ' (' . Carbon::parse($pedido->created_at)->format('d/m H:i') . ')';

        $texto = trim(preg_replace('/\s+/u', ' ', (string) $pedido->contenido));

        $fotos = AiMessageImagen::where('ai_message_id', (int) $pedido->id)->count();

        if ($texto === '') {

            $que = $fotos > 0
                ? ($fotos === 1 ? 'la foto que me mandaste' : 'las ' . $fotos . ' fotos que me mandaste')
                : 'tu último mensaje';

            return 'No te pude contestar ' . $que . $cuando . '. ¿Lo retomo?';
        }

        $cita = mb_strlen($texto) > self::LARGO_DE_LA_CITA
            ? rtrim(mb_substr($texto, 0, self::LARGO_DE_LA_CITA)) . '…'
            : $texto;

        $sin_usar = AiMessageImagen::where('ai_message_id', (int) $pedido->id)->whereNull('gestionada_at')->count();

        return 'No te pude contestar "' . $cita . '"' . $cuando . '. ¿Lo retomo?'
            . ($sin_usar > 0 ? ' ' . ($sin_usar === 1 ? 'La foto quedó guardada.' : 'Las ' . $sin_usar . ' fotos quedaron guardadas.') : '');
    }

    /**
     * true si la tabla de los avisos existe en esta base. Se pregunta una vez por proceso: un empresa con
     * la migración sin correr no se cae, simplemente no deja avisos.
     *
     * @return bool
     */
    public static function hay_tabla()
    {
        if (is_null(self::$hay_tabla)) {

            try {

                self::$hay_tabla = Schema::hasTable('ai_avisos_del_asistente');

            } catch (\Throwable $e) {

                return false;
            }
        }

        return (bool) self::$hay_tabla;
    }
}
