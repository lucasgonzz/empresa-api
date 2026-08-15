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
     * @param int $stock_suggestion_id
     */
    public function __construct($stock_suggestion_id)
    {
        $this->stock_suggestion_id = $stock_suggestion_id;
    }

    public function handle()
    {
        // Se recarga y se verifica el estado al momento de correr: entre el
        // despacho y acá un reproceso (update/reintento del pipeline) pudo
        // resetear la sugerencia. Redactar sobre cero líneas gasta una llamada
        // a la API y muestra un resumen falso sobre datos que ya no existen.
        $suggestion = StockSuggestion::find($this->stock_suggestion_id);

        if (!$suggestion || $suggestion->status !== 'terminado') {
            return;
        }

        $service = new ResumenIaService();

        // Guard por si el job quedó encolado y la clave se quitó después.
        if (!$service->hay_credenciales()) {
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
        } catch (\Throwable $e) {
            Log::error('GenerarResumenSugerenciaJob: falló el resumen', [
                'stock_suggestion_id' => $this->stock_suggestion_id,
                'message'             => $e->getMessage(),
            ]);

            $this->marcar_error($suggestion, $e->getMessage());
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
                'titulo'          => 'Sugerencia de stock #' . $suggestion->id,
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
