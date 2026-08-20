<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\StockSuggestion;
use App\Models\User;
use App\Services\StockSuggestion\ResumenIaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pide a la IA el resumen en criollo de una sugerencia terminada.
 *
 * Nunca bloquea el flujo: si Anthropic falla (529, timeout, excepción), la
 * sugerencia sigue 'terminado' con su tabla completa y el resumen queda en
 * 'error' con el detalle. Sin ANTHROPIC_API_KEY este job directamente no se
 * despacha y el estado queda null (no tener IA contratada no es un error).
 *
 * Y desde el arreglo post-chequeo de la misión chat-ia-y-modulo-ia, cuando
 * ProcessStockSuggestionChunkJob le pasa la posta (segundo argumento del
 * constructor), este job es también quien anuncia "Sugerencia de stock
 * terminada" — en TODOS sus finales, patrón de las cinco salidas de
 * RunExcelAnalysisJob (misión 61): éxito (con el botón del chat si la
 * conversación se creó), error, sin credenciales y failed(). El chunk job ya
 * no notifica en ese caso: con la cola database su aviso salía antes de que
 * la conversación existiera y el botón "Charlar con la IA" no aparecía nunca.
 */
class GenerarResumenSugerenciaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Sin reintentos a propósito: un 529 reintentado tres veces retrasa la
     * cola database que comparte con los chunks; el usuario puede volver a
     * pedir el resumen desde la vista.
     *
     * @var int
     */
    public $tries = 1;

    /** @var int */
    protected $stock_suggestion_id;

    /**
     * true cuando este job es el encargado de anunciar el fin de la corrida
     * (la posta que le pasa ProcessStockSuggestionChunkJob al despacharlo con
     * credenciales). El reintento manual de la vista (regenerar_resumen) lo
     * despacha SIN la posta: la corrida ya fue anunciada en su momento y
     * re-notificarla por cada reintento sería ruido.
     *
     * Pública para que los tests puedan assertPushed sobre ella; el default
     * false también cubre jobs viejos serializados antes de este cambio.
     *
     * @var bool
     */
    public $notificar_al_terminar = false;

    /**
     * @param int $stock_suggestion_id
     * @param bool $notificar_al_terminar
     */
    public function __construct($stock_suggestion_id, $notificar_al_terminar = false)
    {
        $this->stock_suggestion_id = $stock_suggestion_id;
        $this->notificar_al_terminar = $notificar_al_terminar;
    }

    public function handle()
    {
        // Se recarga y se verifica el estado al momento de correr: entre el
        // despacho y acá un reproceso (update/reintento del pipeline) pudo
        // resetear la sugerencia. Redactar sobre cero líneas gasta una llamada
        // a la API y muestra un resumen falso sobre datos que ya no existen.
        //
        // Salida 1 de 5, la ÚNICA muda a propósito: si la sugerencia se borró
        // no hay a quién anunciarle nada, y si un reproceso la reseteó,
        // anunciar "terminada" sería mentira — el pipeline nuevo va a avisar
        // cuando termine de verdad.
        $suggestion = StockSuggestion::find($this->stock_suggestion_id);

        if (!$suggestion || $suggestion->status !== 'terminado') {
            return;
        }

        $service = new ResumenIaService();

        // Salida 2 de 5: el job quedó encolado y la clave se quitó después.
        // El estado vuelve a null ('pendiente' sería un spinner eterno y no
        // tener IA contratada no es un error) y, si este job lleva la posta,
        // la corrida se anuncia igual — con los dos botones de siempre.
        if (!$service->hay_credenciales()) {
            if ($suggestion->resumen_ia_estado === 'pendiente') {
                $suggestion->resumen_ia_estado = null;
                $suggestion->save();
            }

            $this->notificar_fin_de_corrida($suggestion);

            return;
        }

        // 'pendiente' también acá (además de al despachar): el estado es
        // correcto aunque el job se encole por otro camino.
        $suggestion->resumen_ia_estado = 'pendiente';
        $suggestion->save();

        try {
            $prompt = $service->armar_prompt($suggestion);

            // Con user_id y suggestion_id el servicio registra el consumo de
            // tokens (proceso resumen_sugerencia_stock) — retrofit de metering
            // de la misión chat-ia-y-modulo-ia.
            $texto = $service->pedir_resumen($prompt, $suggestion->user_id, $suggestion->id);

            $suggestion->resumen_ia = $texto;
            $suggestion->resumen_ia_estado = 'listo';
            $suggestion->resumen_ia_error = null;
            $suggestion->save();

            // La entrega por el chat es un EXTRA del resumen: su falla se
            // loguea y no toca el estado 'listo' que ya quedó guardado.
            $this->crear_o_actualizar_conversacion($suggestion, $texto, $service);

            // Salida 3 de 5 (éxito): la conversación ya existe (o su creación
            // ya falló y quedó logueada), así que la notificación sale con el
            // botón del chat cuando corresponde. Nunca lanza: si lanzara, el
            // catch de abajo pisaría el 'listo' y notificaría de nuevo.
            $this->notificar_fin_de_corrida($suggestion);
        } catch (\Throwable $e) {
            Log::error('GenerarResumenSugerenciaJob: falló el resumen', [
                'stock_suggestion_id' => $this->stock_suggestion_id,
                'message'             => $e->getMessage(),
            ]);

            $this->marcar_error($suggestion, $e->getMessage());

            // Salida 4 de 5 (falla controlada): la tabla quedó usable y la
            // corrida se anuncia igual, sin el botón del chat.
            $this->notificar_fin_de_corrida($suggestion);
        }
    }

    /**
     * Entrega del resumen por el chat (misión chat-ia-y-modulo-ia, D25/D26):
     * si el dueño tiene la extensión asistente_ia, la sugerencia terminada
     * deja una conversación con origen 'sugerencia_stock', el bloque de DATOS
     * ya calculados como contexto de fondo (armar_datos, no el prompt de
     * redacción) y el resumen como primer mensaje del assistant.
     *
     * La conversación es del DUEÑO (auth_user_id = user_id): stock_suggestions
     * no sabe de personas y la generación automática corre desde un comando
     * sin sesión — límite conocido, declarado en el plan (D25).
     *
     * Idempotencia contra el botón de reintento (regenerar_resumen vuelve a
     * despachar este job): si la conversación de esa sugerencia ya existe, se
     * actualizan su contexto y el contenido de su primer mensaje assistant en
     * lugar de crear otra. Sin esto, tres reintentos dejaban tres
     * conversaciones iguales en la sidebar.
     *
     * Nunca lanza: el resumen es lo que el usuario vino a buscar y ya quedó
     * 'listo'; una falla acá se loguea y no lo voltea.
     *
     * @param StockSuggestion $suggestion Sugerencia terminada, con su resumen ya guardado
     * @param string $texto Resumen redactado por la IA
     * @param ResumenIaService $service Para armar_datos() sin duplicar la query
     * @return void
     */
    protected function crear_o_actualizar_conversacion($suggestion, $texto, $service)
    {
        try {
            $owner = User::find($suggestion->user_id);

            // Sin la extensión no se crea conversación: quien no la tiene ve
            // exactamente el flujo de hoy (resumen + notificación).
            if (!$owner || !UserHelper::hasExtencion('asistente_ia', $owner)) {
                return;
            }

            $contexto = $service->armar_datos($suggestion);

            $conversation = AiConversation::where('origen', 'sugerencia_stock')
                ->where('referencia_id', $suggestion->id)
                ->where('auth_user_id', $owner->id)
                ->first();

            if ($conversation) {
                $conversation->contexto = $contexto;
                $conversation->last_message_at = now();
                $conversation->save();

                $primer_assistant = AiMessage::where('ai_conversation_id', $conversation->id)
                    ->where('rol', 'assistant')
                    ->orderBy('id', 'ASC')
                    ->first();

                if ($primer_assistant) {
                    $primer_assistant->contenido = $texto;
                    $primer_assistant->estado = 'listo';
                    $primer_assistant->error_mensaje = null;
                    $primer_assistant->save();

                    return;
                }

                // Defensa: la conversación existía sin mensaje (no debería pasar).
                AiMessage::create([
                    'ai_conversation_id' => $conversation->id,
                    'rol'                => 'assistant',
                    'contenido'          => $texto,
                    'estado'             => 'listo',
                ]);

                return;
            }

            $conversation = AiConversation::create([
                'user_id'      => $owner->id,
                'auth_user_id' => $owner->id,
                /*
                 * Título fijo y no null: null significa "se está infiriendo"
                 * (la SPA muestra "Nueva conversación") y acá no hay nada que
                 * inferir — la conversación nace con nombre propio.
                 */
                'titulo'          => AiConversation::titulo_con_fecha('Sugerencia de stock', $suggestion->created_at),
                'origen'          => 'sugerencia_stock',
                'referencia_id'   => $suggestion->id,
                'contexto'        => $contexto,
                'last_message_at' => now(),
            ]);

            AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'rol'                => 'assistant',
                'contenido'          => $texto,
                'estado'             => 'listo',
            ]);
        } catch (\Throwable $e) {
            Log::warning('GenerarResumenSugerenciaJob: no se pudo crear la conversación del chat', [
                'stock_suggestion_id' => $suggestion->id,
                'message'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Red de seguridad para fallas no controladas (worker muerto, timeout):
     * sin esto, resumen_ia_estado quedaba 'pendiente' eterno y la vista
     * girando por un texto que no iba a llegar.
     *
     * Salida 5 de 5: con la posta, también anuncia el fin de la corrida — el
     * handle() nunca llegó a hacerlo (failed() solo corre cuando handle()
     * murió sin atrapar, y todos los caminos que notifican atrapan). Si el
     * resumen ya quedó 'listo' no se pisa NI se re-notifica: es el mismo
     * trade-off del patrón de RunExcelAnalysisJob — la ventana entre el save
     * de 'listo' y el aviso es de microsegundos y el aviso doble sería peor.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('GenerarResumenSugerenciaJob: failed()', [
            'stock_suggestion_id' => $this->stock_suggestion_id,
            'message'             => $exception->getMessage(),
        ]);

        $suggestion = StockSuggestion::find($this->stock_suggestion_id);

        // Si por alguna carrera ya quedó listo, no se pisa con error.
        if ($suggestion && $suggestion->resumen_ia_estado !== 'listo') {
            $this->marcar_error($suggestion, $exception->getMessage());
            $this->notificar_fin_de_corrida($suggestion);
        }
    }

    /**
     * Anuncia "Sugerencia de stock terminada" cuando este job lleva la posta
     * (arreglo post-chequeo de la misión chat-ia-y-modulo-ia). La arma
     * ProcessStockSuggestionChunkJob::notificar_corrida_terminada(), que suma
     * el botón "Charlar con la IA" si la conversación existe.
     *
     * NUNCA lanza: se llama en caminos donde el resumen ya quedó guardado
     * ('listo' o 'error') y un Pusher caído no puede mandar eso al catch (que
     * lo pisaría con error y volvería a notificar). Mismo criterio que
     * notificar_fin() de RunExcelAnalysisJob.
     *
     * @param StockSuggestion $suggestion
     * @return void
     */
    protected function notificar_fin_de_corrida($suggestion)
    {
        // Sin la posta (reintento manual de la vista) no se anuncia nada: la
        // corrida ya fue anunciada cuando terminó.
        if (!$this->notificar_al_terminar) {
            return;
        }

        // Un reproceso pudo resetear la corrida entre medio: anunciar
        // "terminada" una sugerencia que volvió a 'pendiente' sería mentira.
        if (!$suggestion || $suggestion->status !== 'terminado') {
            return;
        }

        try {
            ProcessStockSuggestionChunkJob::notificar_corrida_terminada($suggestion);
        } catch (\Throwable $e) {
            Log::warning('GenerarResumenSugerenciaJob: no se pudo notificar el fin de la corrida', [
                'stock_suggestion_id' => $suggestion->id,
                'message'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deja el resumen en 'error' sin tocar el status de la sugerencia: la
     * tabla es lo que el usuario vino a buscar y tiene que seguir visible.
     *
     * @param StockSuggestion $suggestion
     * @param string $mensaje
     * @return void
     */
    protected function marcar_error($suggestion, $mensaje)
    {
        $suggestion->resumen_ia = null;
        $suggestion->resumen_ia_estado = 'error';
        $suggestion->resumen_ia_error = $mensaje;
        $suggestion->save();
    }
}
