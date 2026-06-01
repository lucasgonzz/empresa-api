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

class ProcessMasiveUpdateRevertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
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
    protected $revert_masive_update_id;

    /**
     * @param int $revert_masive_update_id
     */
    public function __construct($revert_masive_update_id)
    {
        $this->revert_masive_update_id = (int) $revert_masive_update_id;
    }

    /**
     * Ejecuta la reversión de una actualización masiva previa.
     *
     * @return void
     */
    public function handle()
    {
        $revert_masive_update = MasiveUpdate::find($this->revert_masive_update_id);

        if (!$revert_masive_update || !$revert_masive_update->parent_masive_update_id) {
            Log::warning('ProcessMasiveUpdateRevertJob: registro inválido', [
                'revert_masive_update_id' => $this->revert_masive_update_id,
            ]);
            return;
        }

        $parent_masive_update = MasiveUpdate::find($revert_masive_update->parent_masive_update_id);

        if (!$parent_masive_update) {
            MasiveUpdateHelper::mark_failed($revert_masive_update, 'Actualización original no encontrada');
            MasiveUpdateHelper::notify_result($revert_masive_update, false, 'Actualización original no encontrada');
            return;
        }

        try {
            MasiveUpdateHelper::process_revert($revert_masive_update, $parent_masive_update);
            MasiveUpdateHelper::notify_result($revert_masive_update, true);
        } catch (Exception $e) {
            Log::error('ProcessMasiveUpdateRevertJob: error', [
                'revert_masive_update_id' => $this->revert_masive_update_id,
                'message' => $e->getMessage(),
            ]);
            MasiveUpdateHelper::mark_failed($revert_masive_update, $e->getMessage());
            MasiveUpdateHelper::notify_result($revert_masive_update, false, $e->getMessage());
        }
    }
}
