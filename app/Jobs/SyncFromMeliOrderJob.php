<?php

namespace App\Jobs;

use App\Models\SyncFromMeliOrder;
use App\Services\MercadoLibre\OrderDownloaderService;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncFromMeliOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $sync_from_meli_order_id;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($sync_from_meli_order_id)
    {
        $this->sync_from_meli_order_id = $sync_from_meli_order_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $sync_record = SyncFromMeliOrder::find($this->sync_from_meli_order_id);
        if (!$sync_record) {
            return;
        }

        try {
            $service = new OrderDownloaderService($sync_record->user_id);
            $service->get_all_orders($this->sync_from_meli_order_id);
        } catch (\Exception $e) {
            Log::error('SyncFromMeliOrderJob: '.$e->getMessage(), [
                'sync_from_meli_order_id' => $this->sync_from_meli_order_id,
            ]);
        }
    }
}
