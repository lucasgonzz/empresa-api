<?php

namespace App\Jobs;

use App\Models\StockSuggestion;
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
        $suggestion = StockSuggestion::find($this->stock_suggestion_id);

        if (!$suggestion) {
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
            $texto = $service->pedir_resumen($prompt);

            $suggestion->resumen_ia = $texto;
            $suggestion->resumen_ia_estado = 'listo';
            $suggestion->resumen_ia_error = null;
            $suggestion->save();
        } catch (\Throwable $e) {
            Log::error('GenerarResumenSugerenciaJob: falló el resumen', [
                'stock_suggestion_id' => $this->stock_suggestion_id,
                'message'             => $e->getMessage(),
            ]);

            $this->marcar_error($suggestion, $e->getMessage());
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
