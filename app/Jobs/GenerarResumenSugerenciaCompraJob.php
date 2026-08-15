<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\PurchaseSuggestion;
use App\Models\User;
use App\Services\PurchaseSuggestion\ResumenIaComprasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pide a la IA el resumen en criollo de una sugerencia de compra terminada.
 *
 * Molde exacto de App\Jobs\GenerarResumenSugerenciaJob (stock): nunca
 * bloquea el flujo — si Anthropic falla (529, timeout, excepción), la
 * sugerencia sigue 'terminado' con su tabla completa y el resumen queda en
 * 'error' con el detalle. Sin ANTHROPIC_API_KEY este job directamente no se
 * despacha con la posta y el estado queda null (no tener IA contratada no es
 * un error).
 *
 * Cuando ProcessPurchaseSuggestionChunkJob le pasa la posta (segundo
 * argumento del constructor), este job es también quien anuncia "Sugerencia
 * de compra terminada" — en TODOS sus finales, las cinco salidas del molde:
 * (1) borrada o ya no 'terminado' → muda a propósito; (2) sin credenciales →
 * resumen_ia_estado vuelve a null + notifica si lleva la posta; (3) éxito →
 * guarda, crea la conversación, notifica; (4) catch → marcar_error() (nunca
 * toca status) + notifica; (5) failed() → si no quedó 'listo', marca error +
 * notifica. El chunk job ya NO notifica en ese caso: con la cola database
 * real, su aviso saldría ANTES de que este job creara la conversación del
 * chat y el botón "Charlar con la IA" no aparecería nunca en producción
 * (arreglo ya aplicado en sugerencias de stock, commit e9670ae).
 */
class GenerarResumenSugerenciaCompraJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Sin reintentos a propósito: un 529 reintentado tres veces retrasa la
     * cola database que comparte con los chunks; el usuario puede volver a
     * pedir el resumen desde la vista (POST .../resumen).
     *
     * @var int
     */
    public $tries = 1;

    /** @var int */
    protected $purchase_suggestion_id;

    /**
     * true cuando este job es el encargado de anunciar el fin de la corrida
     * (la posta que le pasa ProcessPurchaseSuggestionChunkJob al despacharlo
     * con credenciales). El reintento manual desde la vista
     * (PurchaseSuggestionController::resumen) lo despacha SIN la posta: la
     * corrida ya fue anunciada en su momento y re-notificarla por cada
     * reintento sería ruido.
     *
     * Pública para que los tests puedan assertPushed sobre ella.
     *
     * @var bool
     */
    public $notificar_al_terminar = false;

    /**
     * Firma CONGELADA: ProcessPurchaseSuggestionChunkJob la despacha contra
     * esta firma exacta, sin declararla -- no se cambia sin revisar antes ese
     * llamador.
     *
     * @param int $purchase_suggestion_id
     * @param bool $notificar_al_terminar
     */
    public function __construct(int $purchase_suggestion_id, bool $notificar_al_terminar = false)
    {
        $this->purchase_suggestion_id = $purchase_suggestion_id;
        $this->notificar_al_terminar = $notificar_al_terminar;
    }

    public function handle()
    {
        // Se recarga y se verifica el estado al momento de correr: entre el
        // despacho y acá la sugerencia pudo borrarse. A diferencia de stock,
        // acá no hay endpoint de reproceso (D10: reprocesar es crear una
        // sugerencia nueva), así que el único motivo real de "ya no
        // terminado" es que se borró — pero el chequeo de status se conserva
        // por las dudas, mismo criterio defensivo que el molde.
        //
        // Salida 1 de 5, la ÚNICA muda a propósito: sin sugerencia no hay a
        // quién anunciarle nada.
        $suggestion = PurchaseSuggestion::find($this->purchase_suggestion_id);

        if (!$suggestion || $suggestion->status !== 'terminado') {
            return;
        }

        $service = new ResumenIaComprasService();

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
            // tokens (proceso resumen_sugerencia_compra).
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
            Log::error('GenerarResumenSugerenciaCompraJob: falló el resumen', [
                'purchase_suggestion_id' => $this->purchase_suggestion_id,
                'message'                => $e->getMessage(),
            ]);

            $this->marcar_error($suggestion, $e->getMessage());

            // Salida 4 de 5 (falla controlada): la tabla quedó usable y la
            // corrida se anuncia igual, sin el botón del chat.
            $this->notificar_fin_de_corrida($suggestion);
        }
    }

    /**
     * Entrega del resumen por el chat: si el dueño tiene la extensión
     * asistente_ia, la sugerencia terminada deja una conversación con origen
     * 'sugerencia_compra', el bloque de DATOS ya calculados como contexto de
     * fondo (armar_datos(), no el prompt de redacción) y el resumen como
     * primer mensaje del assistant.
     *
     * La conversación es del DUEÑO (auth_user_id = user_id): purchase_suggestions
     * no sabe de personas y la generación automática corre desde un comando
     * sin sesión — mismo límite conocido que sugerencias de stock. 🔴 Este
     * mismo trío (origen/referencia_id/auth_user_id=$suggestion->user_id) es
     * el que lee ProcessPurchaseSuggestionChunkJob::notificar_corrida_terminada()
     * para decidir si suma el botón "Charlar con la IA": no cambiarlo sin
     * revisar antes ese llamador.
     *
     * Idempotencia contra el botón de reintento (PurchaseSuggestionController::resumen
     * vuelve a despachar este job): si la conversación de esta sugerencia ya
     * existe, se actualizan su contexto y el contenido de su primer mensaje
     * assistant en lugar de crear otra. Sin esto, cada reintento dejaba una
     * conversación duplicada en la sidebar.
     *
     * Nunca lanza: el resumen es lo que el usuario vino a buscar y ya quedó
     * 'listo'; una falla acá se loguea y no lo voltea.
     *
     * @param PurchaseSuggestion $suggestion Sugerencia terminada, con su resumen ya guardado
     * @param string $texto Resumen redactado por la IA
     * @param ResumenIaComprasService $service Para armar_datos() sin duplicar la query
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

            // 🔴 SOLO datos ya calculados, jamás las instrucciones de
            // redacción de armar_prompt(): hay un test que lo blinda.
            $contexto = $service->armar_datos($suggestion);

            $conversation = AiConversation::where('origen', 'sugerencia_compra')
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
                'titulo'          => 'Sugerencia de compra #' . $suggestion->id,
                'origen'          => 'sugerencia_compra',
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
            Log::warning('GenerarResumenSugerenciaCompraJob: no se pudo crear la conversación del chat', [
                'purchase_suggestion_id' => $suggestion->id,
                'message'                => $e->getMessage(),
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
     * resumen ya quedó 'listo' no se pisa NI se re-notifica.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('GenerarResumenSugerenciaCompraJob: failed()', [
            'purchase_suggestion_id' => $this->purchase_suggestion_id,
            'message'                => $exception->getMessage(),
        ]);

        $suggestion = PurchaseSuggestion::find($this->purchase_suggestion_id);

        // Si por alguna carrera ya quedó listo, no se pisa con error.
        if ($suggestion && $suggestion->resumen_ia_estado !== 'listo') {
            $this->marcar_error($suggestion, $exception->getMessage());
            $this->notificar_fin_de_corrida($suggestion);
        }
    }

    /**
     * Anuncia "Sugerencia de compra terminada" cuando este job lleva la
     * posta. La arma ProcessPurchaseSuggestionChunkJob::notificar_corrida_terminada()
     * (firma congelada: no cambiarla sin revisar antes ese llamador), que
     * suma el botón "Charlar con la IA" si la conversación existe.
     *
     * NUNCA lanza: se llama en caminos donde el resumen ya quedó guardado
     * ('listo' o 'error') y un Pusher caído no puede mandar eso al catch (que
     * lo pisaría con error y volvería a notificar).
     *
     * @param PurchaseSuggestion $suggestion
     * @return void
     */
    protected function notificar_fin_de_corrida($suggestion)
    {
        // Sin la posta (reintento manual de la vista) no se anuncia nada: la
        // corrida ya fue anunciada cuando terminó.
        if (!$this->notificar_al_terminar) {
            return;
        }

        // Defensivo (ver comentario en handle()): anunciar "terminada" una
        // sugerencia que ya no lo está sería mentira.
        if (!$suggestion || $suggestion->status !== 'terminado') {
            return;
        }

        try {
            ProcessPurchaseSuggestionChunkJob::notificar_corrida_terminada($suggestion);
        } catch (\Throwable $e) {
            Log::warning('GenerarResumenSugerenciaCompraJob: no se pudo notificar el fin de la corrida', [
                'purchase_suggestion_id' => $suggestion->id,
                'message'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deja el resumen en 'error' sin tocar el status de la sugerencia: la
     * tabla es lo que el usuario vino a buscar y tiene que seguir visible.
     * 🔴 NUNCA toca `status`: una falla del resumen no vuelve fallida la
     * corrida (son dos contratos independientes, ver docblock del modelo).
     *
     * @param PurchaseSuggestion $suggestion
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
