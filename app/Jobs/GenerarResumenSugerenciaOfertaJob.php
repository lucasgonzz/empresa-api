<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\User;
use App\Services\OfertasClientes\OfertaSugeridaService;
use App\Services\OfertasClientes\ResumenIaOfertasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Le pide a la IA el resumen en criollo de una corrida de ofertas terminada y, en la misma respuesta,
 * el porcentaje y la vigencia de cada línea del top.
 *
 * 🔴 LO QUE LA IA DEVUELVE PASA SIEMPRE POR EL RECORTE de
 * OfertaSugeridaService::aplicar_decision_de_la_ia(): el techo es determinista y sale del margen del
 * artículo, así que ningún número de la IA puede dejar una oferta por debajo del costo. Este job no
 * interpreta ni valida números por su cuenta: se los da al recorte y guarda lo que salga.
 *
 * Molde exacto de GenerarResumenSugerenciaCompraJob: nunca bloquea el flujo. Si Anthropic falla (529,
 * timeout, JSON roto), la corrida sigue 'terminado' con su tabla completa y los porcentajes
 * DETERMINISTAS, y el resumen queda en 'error' con el detalle. Sin ANTHROPIC_API_KEY este job no se
 * despacha con la posta y el estado queda null: no tener IA contratada no es un error.
 *
 * Cuando ProcessOfferSuggestionChunkJob le pasa la posta (segundo argumento del constructor), este job
 * es también quien anuncia "Ofertas sugeridas listas" — en TODOS sus finales, las cinco salidas del
 * molde: (1) borrada o ya no 'terminado' → muda a propósito; (2) sin credenciales → resumen_ia_estado
 * vuelve a null + notifica si lleva la posta; (3) éxito → guarda, aplica las decisiones, crea la
 * conversación, notifica; (4) catch → marcar_error() (nunca toca status) + notifica; (5) failed() →
 * si no quedó 'listo', marca error + notifica.
 */
class GenerarResumenSugerenciaOfertaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Sin reintentos a propósito: un 529 reintentado tres veces retrasa la cola database que comparte
     * con los lotes, y el usuario puede volver a pedirlo (POST api/offer-suggestion/{id}/resumen).
     * @var int
     */
    public $tries = 1;

    /** @var int */
    protected $offer_suggestion_id;

    /**
     * true cuando este job es el encargado de anunciar el fin de la corrida (la posta que le pasa
     * ProcessOfferSuggestionChunkJob al despacharlo con credenciales). El reintento manual desde la
     * vista (OfferSuggestionController::resumen) lo despacha SIN la posta: la corrida ya fue anunciada
     * y re-notificarla por cada reintento sería ruido. Pública para que los tests hagan assertPushed.
     * @var bool
     */
    public $notificar_al_terminar = false;

    /**
     * Firma CONGELADA: ProcessOfferSuggestionChunkJob y OfferSuggestionController la despachan contra
     * esta firma exacta -- no se cambia sin revisar antes esos dos llamadores.
     * @param int  $offer_suggestion_id
     * @param bool $notificar_al_terminar
     */
    public function __construct(int $offer_suggestion_id, bool $notificar_al_terminar = false)
    {
        $this->offer_suggestion_id   = $offer_suggestion_id;
        $this->notificar_al_terminar = $notificar_al_terminar;
    }

    public function handle()
    {
        // Salida 1 de 5, la ÚNICA muda a propósito: entre el despacho y acá la corrida pudo borrarse,
        // y sin corrida no hay a quién anunciarle nada.
        $suggestion = OfferSuggestion::find($this->offer_suggestion_id);

        if (!$suggestion || $suggestion->status !== 'terminado') {
            return;
        }

        $service = new ResumenIaOfertasService();

        // Salida 2 de 5: el job quedó encolado y la clave se quitó después. El estado vuelve a null
        // ('pendiente' sería un spinner eterno y no tener IA contratada no es un error) y, si este job
        // lleva la posta, la corrida se anuncia igual, con los dos botones de siempre.
        if (!$service->hay_credenciales()) {
            if ($suggestion->resumen_ia_estado === 'pendiente') {
                $suggestion->resumen_ia_estado = null;
                $suggestion->save();
            }

            $this->notificar_fin_de_corrida($suggestion);

            return;
        }

        // 'pendiente' también acá (además de al despachar): el estado es correcto aunque el job se
        // encole por otro camino.
        $suggestion->resumen_ia_estado = 'pendiente';
        $suggestion->save();

        try {
            $prompt = $service->armar_prompt($suggestion);

            // Con user_id y suggestion_id el servicio registra el consumo de tokens.
            $texto = $service->pedir_resumen($prompt, $suggestion->user_id, $suggestion->id);

            $decodificado = $service->decodificar_respuesta($texto);

            // JSON roto o sin la forma pedida: es una falla del resumen y no un problema de la
            // corrida. La tabla ya está calculada y sus porcentajes deterministas valen por sí solos
            // (para eso existe PorcentajeSugeridoService: que la caída de un tercero no deje la
            // corrida sin números).
            if (is_null($decodificado)) {
                throw new \RuntimeException('La IA no devolvió el JSON con la forma esperada: se conservan los porcentajes calculados por el sistema.');
            }

            $this->aplicar_decisiones($suggestion, $decodificado['lineas']);

            $suggestion->resumen_ia        = $decodificado['resumen'];
            $suggestion->resumen_ia_estado = 'listo';
            $suggestion->resumen_ia_error  = null;
            $suggestion->save();

            // La entrega por el chat es un EXTRA del resumen: su falla se loguea y no toca el
            // 'listo' que ya quedó guardado.
            $this->crear_o_actualizar_conversacion($suggestion, $decodificado['resumen'], $service);

            // Salida 3 de 5 (éxito): la conversación ya existe (o su creación ya falló y quedó
            // logueada), así que la notificación sale con el botón del chat cuando corresponde. Nunca
            // lanza: si lanzara, el catch de abajo pisaría el 'listo' y notificaría de nuevo.
            $this->notificar_fin_de_corrida($suggestion);
        } catch (\Throwable $e) {
            Log::error('GenerarResumenSugerenciaOfertaJob: falló el resumen',
                ['offer_suggestion_id' => $this->offer_suggestion_id, 'message' => $e->getMessage()]);

            $this->marcar_error($suggestion, $e->getMessage());

            // Salida 4 de 5 (falla controlada): la tabla quedó usable y la corrida se anuncia
            // igual, sin el botón del chat.
            $this->notificar_fin_de_corrida($suggestion);
        }
    }

    /**
     * Aplica al detalle de cada línea lo que decidió la IA, SIEMPRE pasando por el recorte. 🔴 Dos
     * cosas que no se pueden aflojar acá: (1) el id que devuelve la IA es texto que vino de afuera, así
     * que la consulta va SCOPEADA a offer_suggestion_id y una alucinación con el id de una línea de
     * otra corrida (o de otro comercio) no toca nada — sacar ese where convierte una respuesta de la
     * IA en un UPDATE arbitrario sobre la tabla; (2) ningún número se guarda directo:
     * aplicar_decision_de_la_ia() lo recorta contra el techo de ESA línea, tramos incluidos.
     *
     * @param  mixed $suggestion
     * @param  array $decisiones Lo que vino en la clave "lineas" de la respuesta.
     * @return void
     */
    protected function aplicar_decisiones($suggestion, array $decisiones)
    {
        $por_id = [];

        foreach ($decisiones as $decision) {
            if (is_array($decision) && isset($decision['id']) && is_numeric($decision['id'])) {
                $por_id[(int) $decision['id']] = $decision;
            }
        }

        if (empty($por_id)) {
            return;
        }

        $lineas = OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id)
            ->whereIn('id', array_keys($por_id))
            ->get();

        $dias_vigencia = (int) $suggestion->dias_vigencia_sugerida;

        foreach ($lineas as $linea) {
            $linea->update(OfertaSugeridaService::aplicar_decision_de_la_ia(
                $linea,
                $por_id[$linea->id],
                $dias_vigencia
            ));
        }
    }

    /**
     * Entrega del resumen por el chat: si el dueño tiene la extensión asistente_ia, la corrida deja
     * una conversación con origen 'sugerencia_oferta', el bloque de DATOS ya calculados como contexto
     * de fondo (armar_datos(), no el prompt de redacción) y el resumen como primer mensaje assistant.
     *
     * 🔴 SIN LA EXTENSIÓN NO SE CREA NINGUNA CONVERSACIÓN, y eso apaga el botón "Charlar con la IA" de
     * la notificación: es exactamente lo que pasó en la misión anterior y por eso va declarado en el
     * despliegue. Quien no tiene asistente_ia ve el flujo completo igual (tabla + resumen + aviso),
     * solo que sin el puente al chat.
     *
     * La conversación es del DUEÑO (auth_user_id = user_id): offer_suggestions no sabe de personas y
     * la generación automática corre desde un comando sin sesión. 🔴 Ese mismo trío
     * (origen/referencia_id/auth_user_id) es el que lee notificar_corrida_terminada() para decidir si
     * suma el botón: no cambiarlo sin revisar antes ese llamador. Idempotente contra el reintento: si
     * la conversación ya existe se actualizan su contexto y su primer mensaje en vez de crear otra.
     * Nunca lanza: el resumen ya quedó 'listo' y una falla acá se loguea sin voltearlo.
     *
     * @param  mixed $suggestion
     * @param  string $texto
     * @param  ResumenIaOfertasService $service Para armar_datos() sin duplicar la query
     * @return void
     */
    protected function crear_o_actualizar_conversacion($suggestion, $texto, $service)
    {
        try {
            $owner = User::find($suggestion->user_id);

            if (!$owner || !UserHelper::hasExtencion('asistente_ia', $owner)) {
                return;
            }

            // 🔴 SOLO datos ya calculados, jamás las instrucciones de redacción de armar_prompt():
            // hay un test que lo blinda.
            $contexto = $service->armar_datos($suggestion);

            $conversation = AiConversation::where('origen', 'sugerencia_oferta')
                ->where('referencia_id', $suggestion->id)
                ->where('auth_user_id', $owner->id)
                ->first();

            if ($conversation) {
                $conversation->contexto        = $contexto;
                $conversation->last_message_at = now();
                $conversation->save();

                $primer_assistant = AiMessage::where('ai_conversation_id', $conversation->id)
                    ->where('rol', 'assistant')
                    ->orderBy('id', 'ASC')
                    ->first();

                if ($primer_assistant) {
                    $primer_assistant->contenido     = $texto;
                    $primer_assistant->estado        = 'listo';
                    $primer_assistant->error_mensaje = null;
                    $primer_assistant->save();

                    return;
                }

                // Defensa: la conversación existía sin mensaje (no debería pasar).
                AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant',
                    'contenido' => $texto, 'estado' => 'listo']);

                return;
            }

            $conversation = AiConversation::create([
                'user_id'      => $owner->id,
                'auth_user_id' => $owner->id,
                // Título fijo y no null: null significa "se está infiriendo" (la SPA muestra "Nueva
                // conversación") y acá no hay nada que inferir.
                'titulo'          => AiConversation::titulo_con_fecha('Ofertas sugeridas', $suggestion->created_at),
                'origen'          => 'sugerencia_oferta',
                'referencia_id'   => $suggestion->id,
                'contexto'        => $contexto,
                'last_message_at' => now(),
            ]);

            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant',
                'contenido' => $texto, 'estado' => 'listo']);
        } catch (\Throwable $e) {
            Log::warning('GenerarResumenSugerenciaOfertaJob: no se pudo crear la conversación del chat',
                ['offer_suggestion_id' => $suggestion->id, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Red de seguridad para fallas no controladas (worker muerto, timeout): sin esto,
     * resumen_ia_estado quedaba 'pendiente' eterno y la vista girando por un texto que no iba a llegar.
     * Salida 5 de 5: con la posta también anuncia el fin de la corrida — el handle() nunca llegó a
     * hacerlo (failed() solo corre cuando handle() murió sin atrapar, y todos los caminos que notifican
     * atrapan). Si el resumen ya quedó 'listo' no se pisa NI se re-notifica.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('GenerarResumenSugerenciaOfertaJob: failed()',
            ['offer_suggestion_id' => $this->offer_suggestion_id, 'message' => $exception->getMessage()]);

        $suggestion = OfferSuggestion::find($this->offer_suggestion_id);

        // Si por alguna carrera ya quedó listo, no se pisa con error.
        if ($suggestion && $suggestion->resumen_ia_estado !== 'listo') {
            $this->marcar_error($suggestion, $exception->getMessage());
            $this->notificar_fin_de_corrida($suggestion);
        }
    }

    /**
     * Anuncia "Ofertas sugeridas listas" cuando este job lleva la posta. La arma
     * ProcessOfferSuggestionChunkJob::notificar_corrida_terminada() (firma congelada), que suma el
     * botón "Charlar con la IA" si la conversación existe. NUNCA lanza: se llama en caminos donde el
     * resumen ya quedó guardado ('listo' o 'error') y un Pusher caído no puede mandar eso al catch,
     * que lo pisaría con error y volvería a notificar.
     *
     * @param  mixed $suggestion
     * @return void
     */
    protected function notificar_fin_de_corrida($suggestion)
    {
        // Sin la posta (reintento manual de la vista) no se anuncia nada: la corrida ya fue
        // anunciada cuando terminó.
        if (!$this->notificar_al_terminar) {
            return;
        }

        // Defensivo: anunciar "terminada" una corrida que ya no lo está sería mentira.
        if (!$suggestion || $suggestion->status !== 'terminado') {
            return;
        }

        try {
            ProcessOfferSuggestionChunkJob::notificar_corrida_terminada($suggestion);
        } catch (\Throwable $e) {
            Log::warning('GenerarResumenSugerenciaOfertaJob: no se pudo notificar el fin de la corrida',
                ['offer_suggestion_id' => $suggestion->id, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Deja el resumen en 'error' sin tocar el status de la corrida: la tabla es lo que el usuario vino
     * a buscar y tiene que seguir visible. 🔴 NUNCA toca status: una falla del resumen no vuelve
     * fallida la corrida (son dos contratos independientes).
     *
     * @param  mixed  $suggestion
     * @param  string $mensaje
     * @return void
     */
    protected function marcar_error($suggestion, $mensaje)
    {
        $suggestion->resumen_ia        = null;
        $suggestion->resumen_ia_estado = 'error';
        $suggestion->resumen_ia_error  = $mensaje;
        $suggestion->save();
    }
}
