<?php

namespace App\Jobs;

use App\Services\MercadoLibre\ErrorHandler;
use App\Services\MercadoLibre\OrderDownloaderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Procesa una notificación de Mercado Libre (p. ej. orders_v2) descargando el recurso vía API.
 * Se despacha afterResponse para cumplir el tiempo de respuesta que exige ML al callback.
 */
class ProcessMeliOrderNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Usuario interno (empresa) dueño del token ML */
    protected $empresa_user_id;

    /** @var string Ruta del recurso sin slash inicial (ej. orders/123) */
    protected $resource_path;

    /**
     * @param int $empresa_user_id
     * @param string $resource_path
     */
    public function __construct($empresa_user_id, $resource_path)
    {
        $this->empresa_user_id = (int) $empresa_user_id;
        $this->resource_path = $resource_path;
    }

    /**
     * Ejecuta la descarga y persistencia del pedido o ítem notificado.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $service = new OrderDownloaderService($this->empresa_user_id);
            $service->obtener_order($this->resource_path);
        } catch (\Exception $e) {
            Log::error('ProcessMeliOrderNotificationJob: '.$e->getMessage(), [
                'user_id' => $this->empresa_user_id,
                'resource' => $this->resource_path,
            ]);
            ErrorHandler::notify_exception(
                $this->empresa_user_id,
                $e,
                'Error al procesar notificación de Mercado Libre',
                true
            );
        }
    }
}
