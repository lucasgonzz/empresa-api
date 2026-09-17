<?php

namespace App\Jobs;

use App\Events\ChatIaMensajeActualizado;
use App\Exceptions\AsistenteIaException;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera la respuesta del asistente de IA para un mensaje 'pendiente'
 * (misión chat-ia-y-modulo-ia).
 *
 * El POST del chat guarda el mensaje del usuario más un assistant vacío en
 * 'pendiente' y despacha este job; acá corre el loop de tool use completo
 * (AsistenteIaService) y el mensaje termina 'listo' con el texto o 'error'
 * con un contenido amigable que el usuario SÍ ve en la conversación (nunca
 * queda un globo vacío ni un "pensando" eterno).
 *
 * 🔴 El evento ChatIaMensajeActualizado se dispara SIEMPRE al terminar —
 * también en error—: es lo que corta la espera de la SPA. Y va protegido con
 * try/catch propio: un Pusher caído no puede mandar a failed() un mensaje que
 * ya quedó bien guardado.
 */
class ResponderMensajeChatIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Sin reintentos a propósito (mismo criterio que GenerarResumenSugerenciaJob):
     * un 529 reintentado retrasa la cola database compartida con las
     * importaciones, y el usuario tiene el botón de reintentar en la SPA.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Coherente con el presupuesto del servicio: el peor caso del loop es
     * PRESUPUESTO_SEGUNDOS (210) más una llamada HTTP de 60s ya en vuelo = 270s;
     * 300 deja margen para las tools, los saves y el broadcast. En WAMP/Windows
     * sin pcntl este timeout NO rige (por eso existe el presupuesto adentro del
     * servicio); donde sí rige, tiene que ser MAYOR que ese peor caso o mataría
     * al worker antes del corte prolijo. La cadena completa de los cuatro techos
     * está comentada en AsistenteIaService::PRESUPUESTO_SEGUNDOS.
     *
     * ⚠️ El quinto escalón vive en la SPA: ai_chat.js deja de pollear y muestra el
     * aviso de demora a los 360s. NO a los 300: contra este timeout eso sería un
     * empate, y además los dos relojes no arrancan juntos — el de la SPA arranca al
     * DESPACHAR el job y éste recién cuando el worker lo levanta, así que la espera en
     * la cola corre solo del lado de la SPA y con los dos en 300 se rendía antes de
     * que el job muriera.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * Texto amigable que ve el usuario cuando la generación falla SIN un motivo más fino. El
     * detalle técnico va aparte, en la columna error_mensaje.
     *
     * 🔴 Es el GENÉRICO, no "el" mensaje de error: cuando la falla trae un motivo
     * (AsistenteIaException) gana el texto de ese motivo. Ver contenido_para_la_persona().
     *
     * @var string
     */
    const CONTENIDO_ERROR_AMIGABLE = 'Se me cortó la conexión con el servicio de IA. Probá de nuevo en unos segundos.';

    /**
     * Id del mensaje assistant 'pendiente' a completar. Solo el id (no el
     * modelo): entre el dispatch y el worker pueden pasar minutos y el estado
     * se verifica al momento de correr.
     *
     * @var int
     */
    protected $ai_message_id;

    /**
     * @param int $ai_message_id
     */
    public function __construct($ai_message_id)
    {
        $this->ai_message_id = $ai_message_id;
    }

    public function handle()
    {
        /*
         * R3 del plan: entre el POST y el worker el usuario pudo borrar la
         * conversación (destroy borra también los mensajes). Se recargan los
         * dos por id y, si falta alguno o el mensaje ya no está 'pendiente',
         * el job vuelve sin gastar una llamada a la API.
         */
        $message = AiMessage::find($this->ai_message_id);

        if (!$message || $message->estado !== 'pendiente') {
            return;
        }

        $conversation = AiConversation::find($message->ai_conversation_id);

        if (!$conversation) {
            return;
        }

        /*
         * D28: si el dueño perdió la extensión entre el POST y el worker, el
         * mensaje queda en error amigable sin gastar una llamada a la API.
         */
        $owner = User::find($conversation->user_id);

        if (!$owner || !UserHelper::hasExtencion('asistente_ia', $owner)) {
            $this->marcar_error(
                $message,
                'La cuenta no tiene habilitado el asistente de IA en este momento.',
                'extension asistente_ia no activa al momento de generar la respuesta'
            );
            $this->avisar($conversation, $message);

            return;
        }

        $service = new AsistenteIaService();

        // Guard por si el job quedó encolado y la clave se quitó después:
        // sin credenciales no se sale a la red y el usuario ve un error claro.
        if (!$service->hay_credenciales()) {
            $this->marcar_error(
                $message,
                'La cuenta no tiene la IA configurada. Avisale a quien administra el sistema.',
                'sin ANTHROPIC_API_KEY al momento de generar la respuesta'
            );
            $this->avisar($conversation, $message);

            return;
        }

        try {
            $texto = $service->responder($conversation, $message);

            $message->contenido = $texto;
            $message->estado = 'listo';
            $message->error_mensaje = null;
            /*
             * Misión agente-ia-mano-derecha (§1): las menciones se guardan CON el mensaje, en la
             * misma pasada, porque se arman cruzando los resultados de las tools de esta respuesta
             * contra este texto — y eso solo existe adentro del loop que acaba de terminar. Guardadas,
             * el dueño recarga la pantalla y siguen ahí.
             */
            $message->menciones = $service->menciones();
            $message->save();
        } catch (\Throwable $e) {
            Log::error('ResponderMensajeChatIaJob: falló la generación de la respuesta', [
                'ai_message_id'      => $this->ai_message_id,
                'ai_conversation_id' => $conversation->id,
                'motivo'             => $e instanceof AsistenteIaException ? $e->motivo() : 'sin_motivo',
                'message'            => $e->getMessage(),
            ]);

            $this->marcar_error($message, $this->contenido_para_la_persona($e), $e->getMessage());
        }

        $this->avisar($conversation, $message);
    }

    /**
     * Red de seguridad para fallas no controladas (worker muerto, timeout):
     * sin esto, el mensaje quedaba 'pendiente' eterno y la SPA mostrando el
     * "pensando" por una respuesta que no iba a llegar. Mismo patrón que
     * GenerarResumenSugerenciaJob::failed().
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('ResponderMensajeChatIaJob: failed()', [
            'ai_message_id' => $this->ai_message_id,
            'message'       => $exception->getMessage(),
        ]);

        $message = AiMessage::find($this->ai_message_id);

        // Si por alguna carrera ya quedó listo, no se pisa con error.
        if (!$message || $message->estado === 'listo') {
            return;
        }

        $this->marcar_error($message, $this->contenido_para_la_persona($exception), $exception->getMessage());

        $conversation = AiConversation::find($message->ai_conversation_id);

        if ($conversation) {
            $this->avisar($conversation, $message);
        }
    }

    /**
     * El texto que ve el dueño para una falla.
     *
     * 🔴 POR QUÉ NO ES SIEMPRE EL MISMO. Hasta la misión agente-ia-mano-derecha este catch
     * aplastaba CUATRO modos de falla distintos —presupuesto agotado, techo de iteraciones sin
     * respuesta, 529 de Anthropic y cualquier otro \Throwable— contra el mismo texto rojo, pisando
     * los mensajes finos que AsistenteIaService sí sabía producir. Y "probá de nuevo en unos
     * segundos" es un consejo correcto para el 529 y uno inútil para una consulta que se hizo
     * larga: repetirla igual vuelve a chocar con el mismo techo.
     *
     * 🔴 El texto sale del MAPA FIJO de AsistenteIaException, nunca de getMessage(): ese mensaje
     * puede traer el body crudo de la respuesta de Anthropic y eso no llega a la pantalla. Va a la
     * columna error_mensaje y al log, como siempre.
     *
     * @param \Throwable $exception
     * @return string
     */
    protected function contenido_para_la_persona(\Throwable $exception)
    {
        if ($exception instanceof AsistenteIaException) {
            $propio = $exception->mensaje_para_la_persona();

            if (!is_null($propio)) {

                return $propio;
            }
        }

        return self::CONTENIDO_ERROR_AMIGABLE;
    }

    /**
     * Deja el mensaje en 'error' con el texto amigable como contenido (el
     * usuario lo ve en la conversación) y el detalle técnico en su columna.
     *
     * Misión asistente-ia-acciones: las tarjetas de carga que el mensaje
     * alcanzó a proponer antes de fallar quedan 'descartadas' (handle() y
     * failed() pasan los dos por acá).
     *
     * @param AiMessage $message
     * @param string $contenido_amigable
     * @param string $detalle_tecnico
     * @return void
     */
    protected function marcar_error($message, $contenido_amigable, $detalle_tecnico)
    {
        $message->contenido = $contenido_amigable;
        $message->estado = 'error';
        // Recorte defensivo: la columna es text, pero un body de error HTTP puede ser enorme.
        $message->error_mensaje = mb_substr((string) $detalle_tecnico, 0, 5000);
        $message->save();

        /*
         * Una tarjeta de una respuesta que falló no se puede confirmar: nació
         * de un texto que la persona nunca va a leer (la SPA no la pinta).
         * Protegido: si el descarte falla, el mensaje ya quedó en error y el
         * aviso a la SPA tiene que salir igual.
         */
        try {
            AccionesIaHelper::descartar_de_mensaje($message->id);
        } catch (\Throwable $e) {
            Log::warning('ResponderMensajeChatIaJob: no se pudieron descartar las tarjetas del mensaje en error', [
                'ai_message_id' => $message->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispara el evento del canal privado de la persona. Protegido: si Pusher
     * está caído, el mensaje ya quedó guardado y el polling de respaldo de la
     * SPA lo va a encontrar igual — un fallo acá se loguea y no voltea nada.
     *
     * @param AiConversation $conversation
     * @param AiMessage $message
     * @return void
     */
    protected function avisar($conversation, $message)
    {
        try {
            event(new ChatIaMensajeActualizado(
                (int) $conversation->auth_user_id,
                (int) $conversation->id,
                (int) $message->id,
                (string) $message->estado
            ));
        } catch (\Throwable $e) {
            Log::warning('ResponderMensajeChatIaJob: no se pudo emitir el evento de actualización', [
                'ai_message_id' => $message->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
