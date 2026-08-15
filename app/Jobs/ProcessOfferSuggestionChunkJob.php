<?php

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\OfertasClientes\OfertaSugeridaService;
use App\Services\OfertasClientes\PrioridadOfertasService;
use App\Services\OfertasClientes\ResumenIaOfertasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Calcula e inserta las líneas de un lote de CLIENTES de una corrida de ofertas. Cuando el lote que
 * procesa es el último (processed_chunks llega a total_chunks), cierra la corrida entera: prioriza,
 * llena los tres informativos y reparte quién avisa que terminó.
 */
class ProcessOfferSuggestionChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Reintentos: un deadlock puntual no puede dejar la corrida a medias */
    public $tries = 3;

    /** Tamaño de lote del bulk insert de líneas */
    const LOTE_INSERT = 500;

    /** @var array Candidatos de este lote (clientes enteros, ver GenerateOfferSuggestionChunksJob) */
    protected $candidatos;

    /** @var int */
    protected $offer_suggestion_id;

    /** @var array Mapa client_id => true|false|null, calculado una vez para toda la corrida */
    protected $evaluacion_crediticia;

    /** @var int Clientes que quedaron afuera por deuda en TODA la corrida (no en este lote) */
    protected $clientes_excluidos_por_deuda;

    /** @var mixed */
    protected $suggestion;

    /**
     * @param array $candidatos                   Candidatos del lote.
     * @param int   $offer_suggestion_id          Corrida.
     * @param array $evaluacion_crediticia        Mapa client_id => true|false|null de la corrida.
     * @param int   $clientes_excluidos_por_deuda Conteo de toda la corrida, para el cierre.
     */
    public function __construct(
        array $candidatos,
        int $offer_suggestion_id,
        array $evaluacion_crediticia = [],
        int $clientes_excluidos_por_deuda = 0
    ) {
        $this->candidatos                   = $candidatos;
        $this->offer_suggestion_id          = $offer_suggestion_id;
        $this->evaluacion_crediticia        = $evaluacion_crediticia;
        $this->clientes_excluidos_por_deuda = $clientes_excluidos_por_deuda;
        $this->suggestion                   = OfferSuggestion::find($this->offer_suggestion_id);
    }

    public function handle()
    {
        // Sin cabecera (se borró, o el id nunca existió) el increment() de más abajo reventaría con
        // "Call to a member function increment() on null". Mismo guard que el job padre.
        if (!$this->suggestion) {
            return;
        }

        $service = new OfertaSugeridaService($this->suggestion);

        $lineas = $service->calcular_para_candidatos($this->candidatos, $this->evaluacion_crediticia);

        $ahora = now();
        $filas = [];

        foreach ($lineas as $linea) {
            $filas[] = array_merge($linea, [
                'offer_suggestion_id' => $this->offer_suggestion_id,
                // La prioridad global se materializa en una pasada final al cerrar la corrida:
                // numerar por lote daría varias "prioridad 1" en la misma corrida.
                'prioridad'  => null,
                // insert() no llena timestamps solo: van explícitos.
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // Bulk insert en lotes en vez de un INSERT por fila: una corrida grande son miles de
        // round-trips de otro modo.
        foreach (array_chunk($filas, self::LOTE_INSERT) as $lote) {
            OfferSuggestionLine::insert($lote);
        }

        $this->suggestion->increment('processed_chunks');
        $this->suggestion->refresh();

        // >= por si total_chunks se actualizó tarde; > 0 evita marcar terminado sin lotes.
        if (
            $this->suggestion->total_chunks > 0
            && $this->suggestion->processed_chunks >= $this->suggestion->total_chunks
        ) {
            $this->cerrar_corrida();
        }
    }

    /**
     * El cierre de la corrida: ranking, los tres informativos y el reparto de la posta.
     *
     * @return void
     */
    protected function cerrar_corrida()
    {
        // El ranking se asigna con todas las líneas ya insertadas, antes de marcar terminado y
        // avisar: la vista nunca ve una corrida terminada a medio numerar.
        PrioridadOfertasService::asignar_prioridades($this->offer_suggestion_id);

        $base = OfferSuggestionLine::where('offer_suggestion_id', $this->offer_suggestion_id);

        $this->suggestion->total_lineas   = (clone $base)->count();
        $this->suggestion->total_clientes = (clone $base)->distinct()->count('client_id');
        /*
         * El conteo de excluidos no se puede recalcular acá: los clientes con deuda NO dejaron
         * ninguna línea (se descartan antes de calcular, decisión de Lucas del 15/8/2026), así que
         * la única forma de saber cuántos fueron es el número que el job padre midió al armar los
         * candidatos y que viaja por el constructor.
         */
        $this->suggestion->total_clientes_excluidos_por_deuda = $this->clientes_excluidos_por_deuda;

        $this->suggestion->status = 'terminado';

        // El resumen se pide recién acá, sobre el resultado completo (por lote hablaría de datos
        // parciales) y SOLO si hay clave: sin ANTHROPIC_API_KEY el estado queda null, que la vista
        // muestra como "sin resumen", no como error.
        $resumen_service = new ResumenIaOfertasService();
        $pedir_resumen   = $resumen_service->hay_credenciales();

        if ($pedir_resumen) {
            // 'pendiente' desde antes del dispatch: la vista muestra "lo estamos escribiendo" sin
            // esperar a que el worker arranque.
            $this->suggestion->resumen_ia_estado = 'pendiente';
        }

        $this->suggestion->save();

        /*
         * 🔴 QUIÉN AVISA QUE LA CORRIDA TERMINÓ DEPENDE DE QUIÉN ES EL ÚLTIMO EN TOCARLA. Mismo
         * arreglo que sugerencias de stock (commit e9670ae) y que sugerencias de compra
         * (ProcessPurchaseSuggestionChunkJob.php:119-157):
         *
         * - CON credenciales avisa GenerarResumenSugerenciaOfertaJob, en TODOS sus finales (recibe
         *   la posta por el segundo argumento del constructor). Si notificáramos acá también, con
         *   la cola 'database' real de producción el aviso saldría ANTES de que ese job creara la
         *   conversación del chat y el botón "Charlar con la IA" no aparecería NUNCA — y en los
         *   tests, que corren en cola 'sync', se vería bien igual porque ahí todo pasa en el mismo
         *   request. Por eso este bug no se detecta con tests que no lo busquen a propósito.
         *
         * - SIN credenciales no hay job de resumen ni conversación posible: se notifica acá mismo,
         *   con los dos botones de siempre.
         *
         * 🔴 Si los dos notificaran, el comerciante recibe DOS avisos de la misma corrida; si no
         * notificara ninguno, no recibe ninguno. Es un if/else y tiene que seguir siéndolo.
         */
        if ($pedir_resumen) {
            dispatch(new GenerarResumenSugerenciaOfertaJob($this->offer_suggestion_id, true));
        } else {
            self::notificar_corrida_terminada($this->suggestion);
        }
    }

    /**
     * Marca la corrida como fallida cuando el job agotó sus reintentos.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('ProcessOfferSuggestionChunkJob: failed()', [
            'offer_suggestion_id' => $this->offer_suggestion_id,
            'message'             => $exception->getMessage(),
        ]);

        $suggestion = OfferSuggestion::find($this->offer_suggestion_id);

        // Si por alguna carrera ya quedó terminada, no se pisa con error.
        if (!$suggestion || $suggestion->status === 'terminado') {
            return;
        }

        $suggestion->status = 'error';
        $suggestion->error_mensaje = $exception->getMessage();
        $suggestion->save();

        $user = User::find($suggestion->user_id);

        if (!$user) {
            return;
        }

        $user->notify(new GlobalNotification([
            'message_text'          => 'La corrida de ofertas falló y quedó marcada con error',
            'color_variant'         => 'danger',
            'functions_to_execute'  => [
                [
                    'btn_text'    => 'Entendido',
                    'btn_variant' => 'primary',
                ],
            ],
            'info_to_show'          => [],
            'owner_id'              => $user->id,
            'is_only_for_auth_user' => false,
        ]));
    }

    /**
     * Notificación de "Ofertas sugeridas listas". Es estática y recibe la corrida porque tiene DOS
     * llamadores: este mismo job cuando no hay credenciales de IA (no se despacha el resumen y
     * nadie más avisaría), y GenerarResumenSugerenciaOfertaJob en todos sus finales cuando el lote
     * le pasó la posta — recién ahí la conversación del chat ya existe (o ya se sabe que no va a
     * existir) y el botón "Charlar con la IA" puede salir.
     *
     * @param  mixed $suggestion Corrida terminada a anunciar.
     * @return void
     */
    public static function notificar_corrida_terminada($suggestion): void
    {
        // 🔴 'ir_a_ofertas' matchea EXACTAMENTE el nombre del método del mixin
        // global_notification_functions.js de la SPA: renombrar cualquiera de las dos puntas deja
        // el botón sin hacer nada, y sin ningún error visible.
        $functions_to_execute = [
            [
                'btn_text'      => 'Ver las ofertas',
                'btn_variant'   => 'primary',
                'function_name' => 'ir_a_ofertas',
            ],
            [
                'btn_text'    => 'Entendido',
                'btn_variant' => 'outline-primary',
            ],
        ];

        // El primer elemento lleva la clave offer_suggestion_id, que es la que lee el mixin para
        // armar la ruta del detalle; title/value son lo que el modal renderiza.
        $info_to_show = [
            [
                'title'               => 'Ofertas sugeridas',
                'value'               => '#' . $suggestion->id,
                'offer_suggestion_id' => $suggestion->id,
            ],
        ];

        /*
         * Si la corrida dejó su conversación en el chat del asistente (la crea
         * GenerarResumenSugerenciaOfertaJob, y SOLO con la extensión asistente_ia activa), la
         * notificación suma el botón "Charlar con la IA" y los dos ids que lee
         * abrir_conversacion_de_oferta() en la SPA. Sin conversación —sin la extensión, o el
         * resumen falló— queda con dos botones y ninguna clave nueva.
         *
         * 🔴 El canal de esta notificación es público por empresa: acá van ids y nada más; ni una
         * letra de la conversación viaja por ella.
         */
        $conversation = AiConversation::where('origen', 'sugerencia_oferta')
            ->where('referencia_id', $suggestion->id)
            ->where('auth_user_id', $suggestion->user_id)
            ->first();

        if ($conversation) {
            // "Charlar con la IA" va en el medio: la acción principal sigue siendo ver la tabla.
            array_splice($functions_to_execute, 1, 0, [
                [
                    'btn_text'      => 'Charlar con la IA',
                    'btn_variant'   => 'outline-primary',
                    'function_name' => 'abrir_conversacion_de_oferta',
                ],
            ]);

            $info_to_show[0]['ai_conversation_id'] = $conversation->id;
            // La SPA compara este id contra su user.id: si el logueado no es el dueño, cae a
            // ir_a_ofertas() sin pedir nada.
            $info_to_show[0]['ai_conversation_auth_user_id'] = $conversation->auth_user_id;
        }

        $user = User::find($suggestion->user_id);

        if (!$user) {
            return;
        }

        $user->notify(new GlobalNotification([
            'message_text'          => 'Ofertas sugeridas listas',
            'color_variant'         => 'primary',
            'functions_to_execute'  => $functions_to_execute,
            'info_to_show'          => $info_to_show,
            'owner_id'              => $user->id,
            'is_only_for_auth_user' => false,
        ]));
    }
}
