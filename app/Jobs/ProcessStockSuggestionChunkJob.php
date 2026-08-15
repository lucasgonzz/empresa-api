<?php

namespace App\Jobs;

use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\StockSuggestion\CoberturaService;
use App\Services\StockSuggestion\ResumenIaService;
use App\Services\StockSuggestion\StockSuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessStockSuggestionChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Reintentos: un deadlock puntual no puede dejar la corrida a medias */
    public $tries = 3;

    /** Tamaño de lote del bulk insert de líneas */
    const LOTE_INSERT = 500;

    protected $article_ids;
    protected $stock_suggestion_id;
    protected $suggestion;

    public function __construct(array $article_ids, int $stock_suggestion_id)
    {
        $this->article_ids = $article_ids;
        $this->stock_suggestion_id = $stock_suggestion_id;
        $this->suggestion = StockSuggestion::find($this->stock_suggestion_id);
    }

    public function handle()
    {
        $service = new StockSuggestionService($this->suggestion);

        $suggestions = $service->getSuggestionsForArticles($this->article_ids);

        $cobertura_service = new CoberturaService($this->suggestion->user_id);

        // Una sola consulta agregada por chunk: la velocidad de venta de cada
        // par (artículo, sucursal destino), nunca una consulta por artículo.
        $pares = [];
        foreach ($suggestions as $item) {
            $pares[] = [
                'article_id' => $item['article_id'],
                'address_id' => $item['to_address_id'],
            ];
        }
        $velocidades = $cobertura_service->velocidades_para($pares);

        $ahora = now();
        $filas = [];

        foreach ($suggestions as $item) {
            $clave = $cobertura_service->clave_para($item['article_id'], $item['to_address_id']);
            $velocidad = isset($velocidades[$clave]) ? $velocidades[$clave] : 0.0;
            $stock_destino = isset($item['stock_destino']) ? $item['stock_destino'] : null;

            $filas[] = [
                'stock_suggestion_id' => $this->stock_suggestion_id,
                'article_id' => $item['article_id'],
                'from_address_id' => $item['from_address_id'],
                'to_address_id' => $item['to_address_id'],
                'suggested_amount' => $item['suggested_amount'],
                'velocidad_diaria' => round($velocidad, 4),
                'cobertura_dias' => $cobertura_service->cobertura($stock_destino, $velocidad),
                'stock_destino' => $stock_destino,
                // La prioridad global se materializa en una pasada final al
                // terminar la corrida: numerar por chunk daría varias
                // "prioridad 1" en la misma sugerencia.
                'prioridad' => null,
                // insert() no llena timestamps solo: van explícitos.
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        // Bulk insert en lotes: antes era un INSERT por fila y un catálogo
        // grande convertía cada chunk en miles de round-trips.
        foreach (array_chunk($filas, self::LOTE_INSERT) as $lote) {
            StockSuggestionArticle::insert($lote);
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
            $cobertura_service->asignar_prioridades($this->stock_suggestion_id);

            $this->suggestion->status = 'terminado';

            // El resumen IA se pide recién acá, sobre el resultado completo
            // (por chunk hablaría de datos parciales) y SOLO si hay clave:
            // sin ANTHROPIC_API_KEY el estado queda null, que la vista
            // muestra como "sin resumen", no como error.
            $resumen_service = new ResumenIaService();
            $pedir_resumen = $resumen_service->hay_credenciales();

            if ($pedir_resumen) {
                // 'pendiente' desde antes del dispatch: la vista muestra "lo
                // estamos escribiendo" sin esperar a que el worker arranque.
                $this->suggestion->resumen_ia_estado = 'pendiente';
            }

            $this->suggestion->save();

            if ($pedir_resumen) {
                dispatch(new GenerarResumenSugerenciaJob($this->stock_suggestion_id));
            }

            $this->notificacion();
        }
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
        Log::error('ProcessStockSuggestionChunkJob: failed()', [
            'stock_suggestion_id' => $this->stock_suggestion_id,
            'message'             => $exception->getMessage(),
        ]);

        $suggestion = StockSuggestion::find($this->stock_suggestion_id);

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
            'message_text'          => 'La sugerencia de stock falló y quedó marcada con error',
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

    function notificacion() {

        // El botón "Ver sugerencia" ejecuta ir_a_sugerencias_de_stock() del
        // mixin global_notification_functions de la SPA (mismo mecanismo que
        // open_masive_update_history): navega al detalle si el usuario tiene
        // la extensión, o al listado como fallback.
        $functions_to_execute = [
            [
                'btn_text'      => 'Ver sugerencia',
                'btn_variant'   => 'primary',
                'function_name' => 'ir_a_sugerencias_de_stock',
            ],
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'outline-primary',
            ],
        ];

        // El primer elemento lleva la clave stock_suggestion_id, que es la
        // que lee el mixin para armar la ruta del detalle; title/value son lo
        // que el modal renderiza.
        $info_to_show = [
            [
                'title'               => 'Sugerencia',
                'value'               => '#' . $this->suggestion->id,
                'stock_suggestion_id' => $this->suggestion->id,
            ],
        ];

        $user = User::find($this->suggestion->user_id);

        if (!$user) {
            return;
        }

        $user->notify(new GlobalNotification([
            'message_text'              => 'Sugerencia de stock terminada',
            'color_variant'             => 'primary',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }
}
