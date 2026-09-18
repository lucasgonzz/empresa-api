<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Models\MasiveUpdate;
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
        } catch (\Throwable $e) {
            Log::error('ProcessMasiveUpdateJob: error', [
                'masive_update_id' => $this->masive_update_id,
                'message' => $e->getMessage(),
            ]);
            MasiveUpdateHelper::mark_failed($masive_update, $e->getMessage());
            MasiveUpdateHelper::notify_result($masive_update, false, $e->getMessage());
        }
    }

    /**
     * Cubre lo que el catch de handle() no ve: un \Error (TypeError, memoria) o una muerte
     * sin catch (timeout de 3600 s, worker reiniciado), donde Laravel llama a failed() en un
     * proceso fresco. Sin esto la masiva quedaba en `processing` y su registro visible en
     * `en_proceso` hasta que el listado lo diera por muerto tres horas después.
     *
     * Idempotente: si el catch ya la marcó (o terminó bien), no toca nada.
     *
     * @param  \Throwable $e
     * @return void
     */
    public function failed($e)
    {
        $masive_update = MasiveUpdate::find($this->masive_update_id);

        if (!$masive_update || in_array($masive_update->status, ['completed', 'failed', 'reverted'])) {
            return;
        }

        $motivo = !is_null($e) && $e->getMessage() !== ''
            ? $e->getMessage()
            : 'El proceso se interrumpió sin dejar traza (probable falta de memoria, timeout o worker reiniciado).';

        MasiveUpdateHelper::mark_failed($masive_update, $motivo);
        MasiveUpdateHelper::notify_result($masive_update, false, $motivo);
    }
}
