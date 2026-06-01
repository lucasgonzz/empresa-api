<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Models\MasiveUpdate;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMasiveUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout amplio para lotes grandes de artículos.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * @var int
     */
    public $tries = 1;

    /**
     * @var int
     */
    protected $masive_update_id;

    /**
     * @param int $masive_update_id
     */
    public function __construct($masive_update_id)
    {
        $this->masive_update_id = (int) $masive_update_id;
    }

    /**
     * Ejecuta la actualización masiva registrada.
     *
     * @return void
     */
    public function handle()
    {
        $masive_update = MasiveUpdate::find($this->masive_update_id);

        if (!$masive_update) {
            Log::warning('ProcessMasiveUpdateJob: registro no encontrado', [
                'masive_update_id' => $this->masive_update_id,
            ]);
            return;
        }

        try {
            MasiveUpdateHelper::process_update($masive_update);
            MasiveUpdateHelper::notify_result($masive_update, true);
        } catch (Exception $e) {
            Log::error('ProcessMasiveUpdateJob: error', [
                'masive_update_id' => $this->masive_update_id,
                'message' => $e->getMessage(),
            ]);
            MasiveUpdateHelper::mark_failed($masive_update, $e->getMessage());
            MasiveUpdateHelper::notify_result($masive_update, false, $e->getMessage());
        }
    }
}
