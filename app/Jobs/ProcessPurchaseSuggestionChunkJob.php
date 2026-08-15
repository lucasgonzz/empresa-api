<?php

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\PurchaseSuggestion\ContextoFinancieroService;
use App\Services\PurchaseSuggestion\PrioridadComprasService;
use App\Services\PurchaseSuggestion\PurchaseSuggestionService;
use App\Services\PurchaseSuggestion\ResumenIaComprasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Calcula e inserta las líneas de un chunk de artículos de una sugerencia de
 * compra. Cuando el chunk que procesa es el último (processed_chunks llega a
 * total_chunks), cierra la corrida entera: prioriza, arma el contexto
 * financiero y reparte quién avisa que terminó.
 *
 * 🔴 ResumenIaComprasService y GenerarResumenSugerenciaCompraJob los escribe
 * otro agente (U4) en paralelo sobre esta misma rama: este archivo los
 * referencia contra las firmas ya congeladas en el plan, pero no los
 * declara. Si todavía no existen en el momento de correr esto, es esperable.
 */
class ProcessPurchaseSuggestionChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Reintentos: un deadlock puntual no puede dejar la corrida a medias */
    public $tries = 3;

    /** Tamaño de lote del bulk insert de líneas */
    const LOTE_INSERT = 500;

    protected $article_ids;
    protected $purchase_suggestion_id;
    protected $suggestion;

    public function __construct(array $article_ids, int $purchase_suggestion_id)
    {
        $this->article_ids = $article_ids;
        $this->purchase_suggestion_id = $purchase_suggestion_id;
        $this->suggestion = PurchaseSuggestion::find($this->purchase_suggestion_id);
    }

    public function handle()
    {
        $service = new PurchaseSuggestionService($this->suggestion);

        $lineas = $service->calcular_para_articulos($this->article_ids);

        $ahora = now();
        $filas = [];

        foreach ($lineas as $linea) {
            $filas[] = array_merge($linea, [
                'purchase_suggestion_id' => $this->purchase_suggestion_id,
                // La prioridad global se materializa en una pasada final al
                // terminar la corrida: numerar por chunk daría varias
                // "prioridad 1" en la misma sugerencia.
                'prioridad' => null,
                // insert() no llena timestamps solo: van explícitos.
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // Bulk insert en lotes: antes era un INSERT por fila y un catálogo
        // grande convertía cada chunk en miles de round-trips.
        foreach (array_chunk($filas, self::LOTE_INSERT) as $lote) {
            PurchaseSuggestionArticle::insert($lote);
        }

        $this->suggestion->increment('processed_chunks');
        $this->suggestion->refresh();

        // >= por si total_chunks se actualizó tarde; > 0 evita marcar terminado sin lotes
        if (
            $this->suggestion->total_chunks > 0
            && $this->suggestion->processed_chunks >= $this->suggestion->total_chunks
        ) {
            // El ranking se asigna con todas las líneas ya insertadas, antes
            // de marcar terminado y avisar: la vista nunca ve una sugerencia
            // terminada a medio numerar.
            PrioridadComprasService::asignar_prioridades($this->purchase_suggestion_id);

            // Contexto financiero: informativo, corre UNA sola vez acá al
            // cerrar (nunca por chunk) porque el loop de cajas es N queries
            // para N cajas (D9 — la plata jamás participó del cálculo de
            // cantidades de arriba, esto es solo para mostrar al costado).
            $totales_por_proveedor = $this->calcular_totales_por_proveedor();

            $contexto = ContextoFinancieroService::armar(
                $this->suggestion->user_id,
                array_keys($totales_por_proveedor),
                $totales_por_proveedor
            );

            // Fallback a null ante UTF-8 inválido en algún nombre de
            // proveedor: json_encode() devuelve false y de lo contrario se
            // guardaría el booleano false en una columna longText.
            $this->suggestion->contexto_financiero = json_encode($contexto, JSON_UNESCAPED_UNICODE) ?: null;
            $this->suggestion->total_estimado = array_sum(array_column($totales_por_proveedor, 'total_estimado'));

            $this->suggestion->status = 'terminado';

            // El resumen IA se pide recién acá, sobre el resultado completo
            // (por chunk hablaría de datos parciales) y SOLO si hay clave:
            // sin ANTHROPIC_API_KEY el estado queda null, que la vista
            // muestra como "sin resumen", no como error.
            $resumen_service = new ResumenIaComprasService();
            $pedir_resumen = $resumen_service->hay_credenciales();

            if ($pedir_resumen) {
                // 'pendiente' desde antes del dispatch: la vista muestra "lo
                // estamos escribiendo" sin esperar a que el worker arranque.
                $this->suggestion->resumen_ia_estado = 'pendiente';
            }

            $this->suggestion->save();

            /*
             * Quién avisa que la corrida terminó depende de quién es el
             * ÚLTIMO en tocarla — mismo arreglo que sugerencias de stock
             * (commit e9670ae, ver ProcessStockSuggestionChunkJob.php:124-141):
             *
             * - CON credenciales, avisa GenerarResumenSugerenciaCompraJob en
             *   TODOS sus finales (recibe la posta por el segundo argumento
             *   del constructor). Si notificáramos acá en ese caso, con la
             *   cola 'database' real de producción el aviso saldría ANTES de
             *   que el job del resumen creara la conversación del chat: el
             *   botón "Charlar con la IA" no aparecería NUNCA — y en los
             *   tests, que corren en cola 'sync', se vería bien igual,
             *   porque ahí todo pasa en el mismo request. Por eso el bug no
             *   se detecta con tests que no lo buscan a propósito.
             *
             * - SIN credenciales no hay job de resumen ni conversación
             *   posible: se notifica acá mismo, con los dos botones de
             *   siempre (sin el de "Charlar con la IA").
             */
            if ($pedir_resumen) {
                dispatch(new GenerarResumenSugerenciaCompraJob($this->purchase_suggestion_id, true));
            } else {
                self::notificar_corrida_terminada($this->suggestion);
            }
        }
    }

    /**
     * Total estimado y cantidad de líneas por proveedor, sobre las líneas ya
     * insertadas de esta corrida. Solo cuenta líneas CON proveedor y CON
     * costo (las líneas "sin proveedor asignado" o sin costo de referencia
     * no aportan ningún monto: no hay con qué calcularlo). Este cálculo es
     * lo único que conecta purchase_suggestion_articles con
     * ContextoFinancieroService — el servicio en sí no conoce esa tabla.
     *
     * @return array [provider_id => ['total_estimado' => float, 'lineas' => int]]
     */
    protected function calcular_totales_por_proveedor(): array
    {
        $filas = PurchaseSuggestionArticle::where('purchase_suggestion_id', $this->purchase_suggestion_id)
            ->whereNotNull('provider_id')
            ->whereNotNull('costo_estimado')
            ->selectRaw('provider_id, SUM(costo_estimado * cantidad_sugerida) as total_estimado, COUNT(*) as lineas')
            ->groupBy('provider_id')
            ->get();

        $totales = [];

        foreach ($filas as $fila) {
            $totales[(int) $fila->provider_id] = [
                'total_estimado' => (float) $fila->total_estimado,
                'lineas' => (int) $fila->lineas,
            ];
        }

        return $totales;
    }

    /**
     * Marca la corrida como fallida cuando el job agotó sus reintentos: sin
     * esto, la sugerencia quedaba 'pendiente' para siempre y el usuario
     * esperando un resultado que no iba a llegar.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('ProcessPurchaseSuggestionChunkJob: failed()', [
            'purchase_suggestion_id' => $this->purchase_suggestion_id,
            'message'                => $exception->getMessage(),
        ]);

        $suggestion = PurchaseSuggestion::find($this->purchase_suggestion_id);

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
            'message_text'          => 'La sugerencia de compra falló y quedó marcada con error',
            'color_variant'         => 'danger',
            'functions_to_execute'  => [
                [
                    'btn_text'      => 'Entendido',
                    'btn_variant'   => 'primary',
                ],
            ],
            'info_to_show'          => [],
            'owner_id'              => $user->id,
            'is_only_for_auth_user' => false,
        ]));
    }

    /**
     * Notificación de "Sugerencia de compra terminada". Es estática y recibe
     * la sugerencia porque tiene DOS llamadores: este mismo job cuando no
     * hay credenciales de IA (no se despacha el resumen y nadie más
     * avisaría), y GenerarResumenSugerenciaCompraJob en todos sus finales
     * cuando el chunk le pasó la posta — recién ahí la conversación del chat
     * ya existe (o ya se sabe que no va a existir) y el botón "Charlar con
     * la IA" puede salir. Firma congelada con U4, que la llama pero no la
     * escribe.
     *
     * @param PurchaseSuggestion $suggestion Sugerencia terminada a anunciar.
     * @return void
     */
    public static function notificar_corrida_terminada(PurchaseSuggestion $suggestion): void
    {
        // El botón "Ver sugerencia" ejecuta ir_a_sugerencias_de_compra() del
        // mixin global_notification_functions de la SPA (mismo mecanismo que
        // el de sugerencias de stock).
        $functions_to_execute = [
            [
                'btn_text'      => 'Ver sugerencia',
                'btn_variant'   => 'primary',
                'function_name' => 'ir_a_sugerencias_de_compra',
            ],
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'outline-primary',
            ],
        ];

        // El primer elemento lleva la clave purchase_suggestion_id, que es
        // la que lee el mixin para armar la ruta del detalle; title/value
        // son lo que el modal renderiza.
        $info_to_show = [
            [
                'title'                  => 'Sugerencia de compra',
                'value'                  => '#' . $suggestion->id,
                'purchase_suggestion_id' => $suggestion->id,
            ],
        ];

        /*
         * Si la sugerencia dejó su conversación en el chat del asistente (la
         * crea GenerarResumenSugerenciaCompraJob, solo con la extensión
         * asistente_ia activa), la notificación suma el botón "Charlar con
         * la IA" y los dos ids que lee abrir_conversacion_de_sugerencia_compra()
         * en la SPA. Sin conversación —sin la extensión, o el resumen
         * falló— la notificación queda EXACTAMENTE como era: dos botones y
         * ninguna clave nueva.
         *
         * 🔴 El canal de esta notificación es público por empresa: acá van
         * ids y nada más; ni una letra de la conversación viaja por ella.
         */
        $conversation = AiConversation::where('origen', 'sugerencia_compra')
            ->where('referencia_id', $suggestion->id)
            ->where('auth_user_id', $suggestion->user_id)
            ->first();

        if ($conversation) {
            // "Charlar con la IA" va en el medio: la acción principal sigue
            // siendo ver la tabla; "Entendido" cierra como siempre.
            array_splice($functions_to_execute, 1, 0, [
                [
                    'btn_text'      => 'Charlar con la IA',
                    'btn_variant'   => 'outline-primary',
                    'function_name' => 'abrir_conversacion_de_sugerencia_compra',
                ],
            ]);

            $info_to_show[0]['ai_conversation_id'] = $conversation->id;
            // La SPA compara este id contra su user.id: si el logueado no es
            // el dueño, cae a ir_a_sugerencias_de_compra() sin pedir nada.
            $info_to_show[0]['ai_conversation_auth_user_id'] = $conversation->auth_user_id;
        }

        $user = User::find($suggestion->user_id);

        if (!$user) {
            return;
        }

        $user->notify(new GlobalNotification([
            'message_text'              => 'Sugerencia de compra terminada',
            'color_variant'             => 'primary',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }
}
