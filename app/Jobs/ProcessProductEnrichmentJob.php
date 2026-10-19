<?php

namespace App\Jobs;

use App\Models\ProductEnrichment;
use App\Services\ProductFacts\ProductEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessProductEnrichmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 150;
    public $tries = 35;
    protected $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function handle(ProductEnrichmentService $service)
    {
        $task = ProductEnrichment::find($this->id);
        if (!$task || !in_array($task->status, ['queued', 'waiting', 'searching'], true)) {
            return;
        }
        if ($task->created_at->lt(now()->subMinutes(8))) {
            ProductEnrichment::where('id', $task->id)->whereIn('status', ['queued', 'searching', 'waiting'])->update([
                'status' => 'failed', 'message' => 'La búsqueda demoró demasiado. Podés continuar manualmente o reintentar.',
            ]);
            return;
        }
        $won = ProductEnrichment::where('id', $task->id)->where(function ($query) {
            $query->whereIn('status', ['queued', 'waiting'])
                ->orWhere(function ($stale) { $stale->where('status', 'searching')->where('updated_at', '<', now()->subSeconds(180)); });
        })->update(['status' => 'searching', 'message' => 'Buscando información del producto…', 'updated_at' => now()]);
        if (!$won) {
            return;
        }
        $task->refresh();
        try {
            $service->process($task);
            if ($task->fresh()->status === 'waiting') {
                $this->release(8);
            }
        } catch (\Throwable $e) {
            ProductEnrichment::where('id', $task->id)->where('status', 'searching')->update([
                'status' => 'failed', 'message' => $e instanceof \App\Services\ProductFacts\ProductFactsException
                    ? mb_substr($e->getMessage(), 0, 255) : 'No se pudo completar la búsqueda. Podés continuar manualmente.',
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        ProductEnrichment::where('id', $this->id)->whereIn('status', ['queued', 'waiting', 'searching'])->update([
            'status' => 'failed', 'message' => 'No se pudo completar la búsqueda. Podés continuar manualmente.',
        ]);
    }
}
