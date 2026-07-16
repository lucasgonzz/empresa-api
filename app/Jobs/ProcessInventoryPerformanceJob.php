<?php

namespace App\Jobs;

use App\Events\InventoryPerformanceGenerated;
use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessInventoryPerformanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout amplio: las cuentas de 400k artículos tardan minutos en procesarse.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * No reintentar: un reintento es otra pasada completa por el catálogo.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Id del usuario owner para el que se genera el reporte de inventario.
     *
     * @var int
     */
    protected $user_id;

    /**
     * Crea el job de generación del reporte de inventario en segundo plano.
     *
     * @param int $user_id
     */
    public function __construct($user_id)
    {
        $this->user_id = (int) $user_id;
    }

    /**
     * Genera el reporte de inventario y avisa al front por broadcast cuando termina.
     * Libera siempre el candado de generación, haya salido bien o mal.
     *
     * @return void
     */
    public function handle()
    {
        try {

            // El helper recibe el user_id explícito porque acá no hay sesión HTTP ni Auth.
            $helper = new InventoryPerformanceHelper($this->user_id);

            $inventory_performance = $helper->create();

            // Si se generó un reporte, se avisa al front con la cantidad de artículos bajo el mínimo.
            if (!is_null($inventory_performance)) {

                event(new InventoryPerformanceGenerated($this->user_id, (int) $inventory_performance->stock_minimo));
            }
        } catch (Exception $e) {

            Log::error('ProcessInventoryPerformanceJob: error al generar reporte de inventario', [
                'user_id' => $this->user_id,
                'message' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine(),
            ]);
        } finally {

            // Se libera el candado siempre, para que un futuro request pueda volver a encolar.
            Cache::forget('inventory_performance_generating_'.$this->user_id);
        }
    }

    /**
     * Cubre el caso de timeout o kill del worker, donde el finally del handle() puede no correr:
     * libera igual el candado de generación.
     *
     * @param Exception $e
     * @return void
     */
    public function failed(Exception $e)
    {
        Cache::forget('inventory_performance_generating_'.$this->user_id);
    }
}
