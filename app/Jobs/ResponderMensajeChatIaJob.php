<?php

namespace App\Jobs;

use App\Events\ChatIaMensajeActualizado;
use App\Exceptions\AsistenteIaException;
use App\Http\Controllers\AdminSync\AsistenteController;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ArchivosAdjuntosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AudioDelMensajeIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDeTurnosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TranscripcionDeAudioIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
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

        if (!$owner || !UserHelper::hasExtencion('agentes_ia', $owner)) {
            $this->marcar_error(
                $message,
                'La cuenta no tiene habilitado el asistente de IA en este momento.',
                'extension agentes_ia no activa al momento de generar la respuesta'
            );
            $this->avisar($conversation, $message);

            return;
        }

        /*
         * Misión app-ia-y-memoria (29/10/2026): lo que el dueño mandó desde la app se prepara ANTES de
         * llamar al modelo. Un audio se transcribe (y si no se pudo y no hay nada más, se contesta acá
         * con el texto fijo de WhatsApp, sin gastar una llamada a la IA); a los archivos se les saca el
         * texto. Para el resto del loop, después de esto es un mensaje de texto más.
         */
        $pedido = $this->mensaje_del_pedido($message);

        if (!is_null($pedido) && $this->contesto_un_audio_sin_transcribir($pedido, $message, $conversation)) {
            $this->avisar($conversation, $message);

            return;
        }

        if (!is_null($pedido)) {
            try {
                ArchivosAdjuntosIaHelper::extraer_pendientes($pedido);
            } catch (\Throwable $e) {
                Log::warning('ResponderMensajeChatIaJob: no se pudieron leer los archivos del mensaje', [
                    'ai_message_id' => $pedido->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        $service = new AsistenteIaService();

        // Guard por si el job quedó encolado y la clave se quitó después:
        // sin credenciales no se sale a la red y el usuario ve un error claro.
        // Se pregunta por el proveedor del DUEÑO (misión proveedores-ia-deepseek):
        // el elegido, o el que tenga clave si el elegido no la tiene.
        if (!$service->hay_credenciales($owner)) {
            $this->marcar_error(
                $message,
                'La cuenta no tiene la IA configurada. Avisale a quien administra el sistema.',
                'sin clave de ' . ProveedorIaHelper::nombre_de(ProveedorIaHelper::proveedor_de($owner))
                . ' (ni de otro proveedor) al momento de generar la respuesta'
            );
            $this->avisar($conversation, $message);

            return;
        }

        try {
            $texto = $service->responder($conversation, $message);

            $message->contenido = $texto;
            $message->estado = 'listo';
            /*
             * Misión agente-memoria-y-sin-techo (segunda ronda de arreglos, F3): si el turno terminó con lo
             * hecho armado sin el modelo (una falla a mitad de turno o un cierre que no escribió), el
             * mensaje queda `listo` —la persona tiene su respuesta— pero el detalle técnico de qué lo cortó
             * queda acá para soporte. Ninguna SPA pinta el `error_mensaje` de un mensaje y el admin solo lo
             * lee con el mensaje en `error` (ver AsistenteIaService::detalle_tecnico()). Si terminó normal,
             * null como siempre.
             */
            $detalle = $service->detalle_tecnico();
            $message->error_mensaje = is_null($detalle) ? null : mb_substr((string) $detalle, 0, 5000);
            /*
             * Misión agente-ia-mano-derecha (§1): las menciones se guardan CON el mensaje, en la
             * misma pasada, porque se arman cruzando los resultados de las tools de esta respuesta
             * contra este texto — y eso solo existe adentro del loop que acaba de terminar. Guardadas,
             * el dueño recarga la pantalla y siguen ahí.
             */
            $message->menciones = $service->menciones();
            /*
             * Misión asistente-omnisciente (§3): los adjuntos —las fotos de los artículos que el
             * dueño pidió ver— salen del tool_result de `mostrar_imagenes_de_articulos` y los
             * recolecta el servicio en el mismo loop, así que se guardan acá, junto a las menciones
             * y por el mismo motivo: fuera del loop ya no existen.
             */
            $message->adjuntos = $service->adjuntos();

            /*
             * Misión app-ia-y-memoria (§4 E1.5–E1.7): los gráficos, tablas e imágenes de la respuesta, por
             * el mismo motivo que las menciones (fuera del loop ya no existen), y la línea de progreso
             * vuelve a null. Solo se tocan si hay algo: así un mensaje del sistema guarda exactamente lo
             * de antes.
             */
            $bloques = $service->bloques();

            if (count($bloques)) {
                $message->bloques = $bloques;
            }

            if (! is_null($message->progreso)) {
                $message->progreso = null;
            }

            $message->save();

            /* Recién con el mensaje guardado (el bloque ya existe), los pedidos de imagen salen al admin. */
            $this->despachar_pedidos_de_imagen($service->pedidos_de_imagen());
        } catch (\Throwable $e) {
            Log::error('ResponderMensajeChatIaJob: falló la generación de la respuesta', [
                'ai_message_id'      => $this->ai_message_id,
                'ai_conversation_id' => $conversation->id,
                'motivo'             => $e instanceof AsistenteIaException ? $e->motivo() : 'sin_motivo',
                'message'            => $e->getMessage(),
            ]);

            $this->marcar_error($message, $this->contenido_para_la_persona($e, $message), $e->getMessage());

            $this->avisar_las_fotos_por_whatsapp($message);
        }

        $this->avisar($conversation, $message);
    }

    /**
     * Manda al admin los pedidos de imagen de la respuesta (EnviarPedidoDeImagenAlAdminJob, contrato
     * C2.1). Protegido: el mensaje ya quedó guardado y una falla al encolar (o un envío que falla en la
     * cola sync) no puede voltear la respuesta; si no sale, el pedido queda `fallida` por failed().
     *
     * @param  array<int, int>  $pedidos
     * @return void
     */
    protected function despachar_pedidos_de_imagen(array $pedidos)
    {
        foreach ($pedidos as $pedido_id) {
            try {
                dispatch(new EnviarPedidoDeImagenAlAdminJob($pedido_id));
            } catch (\Throwable $e) {
                Log::warning('ResponderMensajeChatIaJob: no se pudo mandar un pedido de imagen al admin', [
                    'ia_imagen_pedido_id' => $pedido_id,
                    'error'               => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * El mensaje del dueño que este assistant contesta (el último 'user' anterior), o null.
     *
     * @param  \App\Models\AiMessage  $message
     * @return \App\Models\AiMessage|null
     */
    protected function mensaje_del_pedido(AiMessage $message)
    {
        return AiMessage::where('ai_conversation_id', $message->ai_conversation_id)
            ->where('rol', 'user')
            ->where('id', '<', (int) $message->id)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Transcribe el audio del dueño, si lo trae y todavía no se transcribió (misión app-ia-y-memoria).
     *
     * Con la transcripción, el texto queda en `contenido` del mensaje del dueño (sumado a lo que haya
     * escrito) y, si la conversación no tiene título, se infiere de ese texto. Si falla, el mensaje queda
     * con AUDIO_SIN_TRANSCRIPCION; si además no trae nada más con qué contestar (ni texto, ni fotos, ni
     * archivos), el assistant se contesta ACÁ con RESPUESTA_AUDIO_SIN_TRANSCRIPCION, las mismas
     * constantes que el canal de WhatsApp, y devuelve true (no hay que llamar al modelo).
     *
     * @param  \App\Models\AiMessage  $pedido
     * @param  \App\Models\AiMessage  $message
     * @param  \App\Models\AiConversation  $conversation
     * @return bool  true si el turno ya quedó contestado.
     */
    protected function contesto_un_audio_sin_transcribir(AiMessage $pedido, AiMessage $message, AiConversation $conversation): bool
    {
        if (!AudioDelMensajeIaHelper::falta_transcribir($pedido)) {

            return false;
        }

        $datos   = AudioDelMensajeIaHelper::datos($pedido);
        $binario = AudioDelMensajeIaHelper::binario($pedido);
        $escrito = trim((string) $pedido->contenido);

        $resultado = is_null($binario) ? null : TranscripcionDeAudioIaHelper::transcribir(
            $binario,
            'audio.' . (string) ($datos['ext'] ?? 'webm'),
            [
                'user_id'            => (int) $conversation->user_id,
                'auth_user_id'       => (int) $conversation->auth_user_id,
                'ai_conversation_id' => (int) $conversation->id,
            ]
        );

        if (!is_null($resultado)) {
            $pedido->contenido = $escrito === '' ? $resultado['texto'] : $escrito . "\n" . $resultado['texto'];
            AudioDelMensajeIaHelper::marcar($pedido, AudioDelMensajeIaHelper::TRANSCRIPCION_LISTA, $resultado['duracion_seg']);

            if ($escrito === '') {
                $this->inferir_titulo_si_falta($conversation, $pedido);
            }

            return false;
        }

        $tiene_otra_cosa = $escrito !== ''
            || AiMessageImagen::where('ai_message_id', $pedido->id)->exists()
            || count(ArchivosAdjuntosIaHelper::datos($pedido)) > 0;

        $pedido->contenido = $escrito === ''
            ? AsistenteController::AUDIO_SIN_TRANSCRIPCION
            : $escrito . "\n" . AsistenteController::AUDIO_SIN_TRANSCRIPCION;
        AudioDelMensajeIaHelper::marcar($pedido, AudioDelMensajeIaHelper::TRANSCRIPCION_FALLIDA);

        if ($tiene_otra_cosa) {

            return false;
        }

        $message->contenido = AsistenteController::RESPUESTA_AUDIO_SIN_TRANSCRIPCION;
        $message->estado = 'listo';
        $message->error_mensaje = null;
        $message->save();

        return true;
    }

    /**
     * El título de una conversación que arrancó con un audio: se infiere de la transcripción (el POST no
     * tenía texto). Mismo criterio que send_message(): solo si no tiene título y es el primer mensaje del
     * dueño. Cosmético: una falla al encolarlo no voltea nada.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $pedido
     * @return void
     */
    protected function inferir_titulo_si_falta(AiConversation $conversation, AiMessage $pedido)
    {
        try {
            $primero = is_null($conversation->titulo)
                && AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'user')->count() === 1;

            if ($primero) {
                dispatch(new InferirTituloConversacionIaJob($conversation->id, (string) $pedido->contenido));
            }
        } catch (\Throwable $e) {
            Log::warning('ResponderMensajeChatIaJob: no se pudo encolar el título de una conversación de audio', [
                'ai_conversation_id' => $conversation->id,
                'error'              => $e->getMessage(),
            ]);
        }
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

        $this->marcar_error($message, $this->contenido_para_la_persona($exception, $message), $exception->getMessage());

        $this->avisar_las_fotos_por_whatsapp($message);

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
     * 🔴 Y SI EL DUEÑO HABÍA MANDADO FOTOS, SE LO DICE (misión agente-mejoras-uso-real, E1 punto 5,
     * 29/10/2026). En Ferre Total (CONV 15, mensaje 92) Pablo mandó tres fotos para los clavos que
     * faltaban, el turno se cortó por un timeout y le llegó «Se me cortó la conexión con el servicio
     * de IA» y nada más: no supo si las fotos habían llegado ni si se había cargado alguna. Ahora, si el
     * mensaje que este turno contestaba trae fotos que NINGUNA carga usó, el texto suma, determinista y
     * sin el modelo, cuántas llegaron, que quedaron guardadas y que con «seguí» continúa. Las fotos sin
     * usar vuelven a viajar con el mensaje siguiente (AsistenteIaService::con_las_fotos_de_la_ultima_tanda),
     * así que «seguí» las encuentra.
     *
     * @param \Throwable $exception
     * @param \App\Models\AiMessage|null $message  El assistant que falló (para mirar las fotos del pedido).
     * @return string
     */
    protected function contenido_para_la_persona(\Throwable $exception, $message = null)
    {
        $texto = self::CONTENIDO_ERROR_AMIGABLE;

        if ($exception instanceof AsistenteIaException) {
            $propio = $exception->mensaje_para_la_persona();

            if (!is_null($propio)) {

                $texto = $propio;
            }
        }

        return $texto . $this->aviso_de_fotos_sin_usar($message);
    }

    /**
     * La frase de las fotos que llegaron y no se usaron, con un espacio adelante, o vacío. Ver el 🔴 de
     * contenido_para_la_persona(). La arma AvisosDeTurnosIaHelper::frase_del_turno() (la misma que
     * sale por WhatsApp). Protegido: si la consulta falla, el texto de error sale igual.
     *
     * @param \App\Models\AiMessage|null $message  El assistant que falló.
     * @return string
     */
    protected function aviso_de_fotos_sin_usar($message)
    {
        if (!($message instanceof AiMessage)) {

            return '';
        }

        try {

            $frase = AvisosDeTurnosIaHelper::frase_del_turno($message, $this->mensaje_del_pedido($message));

        } catch (\Throwable $e) {

            Log::warning('ResponderMensajeChatIaJob: no se pudieron contar las fotos del pedido para el aviso de error', [
                'ai_message_id' => $message->id,
                'error'         => $e->getMessage(),
            ]);

            return '';
        }

        return $frase === '' ? '' : ' ' . $frase;
    }

    /**
     * 🔴 LA MISMA FRASE, POR WHATSAPP (ronda de arreglos de la revisión independiente, 30/10/2026). Con
     * el mensaje en `error`, el admin NO lee el contenido: manda su disculpa fija («Uf, no pude
     * contestarte esta vez…», EnviarMensajeAlAsistenteJob del admin-api). La frase de las fotos sin
     * cargar le quedaba al dueño solo en el sistema. Ahora se deja como aviso por el camino que el admin
     * ya consulta (AvisosDeTurnosIaHelper, servido por AvisosDelAsistenteHelper::pendientes()): llega
     * poco después de la disculpa. Uno por mensaje (handle() y failed() pueden pasar los dos por acá) y
     * solo por WhatsApp con el admin que anunció que avisa. Nunca lanza.
     *
     * @param \App\Models\AiMessage $message  El assistant que falló.
     * @return void
     */
    protected function avisar_las_fotos_por_whatsapp($message)
    {
        if (!($message instanceof AiMessage) || !$message->es_de_whatsapp()) {

            return;
        }

        $frase = trim($this->aviso_de_fotos_sin_usar($message));

        if ($frase !== '') {

            AvisosDeTurnosIaHelper::dejar($message, $frase);
        }
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

        // Misión app-ia-y-memoria: la línea de progreso no queda colgada en un mensaje en error.
        if (! is_null($message->progreso)) {
            $message->progreso = null;
        }

        $message->save();

        /* Los pedidos de imagen de una respuesta que falló no se van a mostrar nunca: se cierran. */
        PedidosDeImagenIaHelper::cerrar_los_del_mensaje($message->id);

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

        /*
         * Misión app-ia-y-memoria: un audio del dueño que no llegó a transcribirse (el turno falló antes)
         * queda `fallida`, para que la app no muestre "transcribiendo…" para siempre.
         */
        try {
            AudioDelMensajeIaHelper::cerrar_si_quedo_pendiente($this->mensaje_del_pedido($message));
        } catch (\Throwable $e) {
            Log::warning('ResponderMensajeChatIaJob: no se pudo cerrar el audio pendiente', [
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
