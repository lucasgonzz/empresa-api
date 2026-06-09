<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\DeleteModelsHelper;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDeleteModelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout amplio para lotes grandes de eliminación.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Evita reprocesos duplicados sobre la misma solicitud.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Nombre del modelo en ruta API (article, client, etc.).
     *
     * @var string
     */
    protected $model_name;

    /**
     * IDs de registros a eliminar.
     *
     * @var array
     */
    protected $models_id;

    /**
     * Usuario owner del tenant.
     *
     * @var int
     */
    protected $owner_user_id;

    /**
     * Usuario autenticado que solicitó la eliminación.
     *
     * @var int
     */
    protected $auth_user_id;

    /**
     * Filtros utilizados (historial de artículos).
     *
     * @var array
     */
    protected $used_filters;

    /**
     * Crea el job de eliminación masiva en segundo plano.
     *
     * @param string $model_name
     * @param array $models_id
     * @param int $owner_user_id
     * @param int $auth_user_id
     * @param array $used_filters
     */
    public function __construct($model_name, $models_id, $owner_user_id, $auth_user_id, $used_filters = [])
    {
        $this->model_name = $model_name;
        $this->models_id = $models_id;
        $this->owner_user_id = (int) $owner_user_id;
        $this->auth_user_id = (int) $auth_user_id;
        $this->used_filters = is_array($used_filters) ? $used_filters : [];
    }

    /**
     * Ejecuta la eliminación masiva y notifica al usuario solicitante.
     *
     * @return void
     */
    public function handle()
    {
        try {
            DeleteModelsHelper::process_background_delete(
                $this->model_name,
                $this->models_id,
                $this->owner_user_id,
                $this->auth_user_id,
                $this->used_filters
            );
        } catch (Exception $e) {
            Log::error('ProcessDeleteModelsJob: error', [
                'model_name' => $this->model_name,
                'owner_user_id' => $this->owner_user_id,
                'auth_user_id' => $this->auth_user_id,
                'records_count' => count($this->models_id),
                'message' => $e->getMessage(),
            ]);

            DeleteModelsHelper::notify_result(
                $this->owner_user_id,
                $this->auth_user_id,
                $this->model_name,
                false,
                0,
                $e->getMessage()
            );
        }
    }
}
