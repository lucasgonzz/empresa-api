<?php

namespace App\Jobs;

use App\Services\MercadoLibre\OrderDownloaderService;
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
        $service = new OrderDownloaderService();

        $service->get_all_orders($this->sync_from_meli_order_id);
    }
}
