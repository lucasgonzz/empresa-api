<?php

namespace App\Console\Commands;

use App\Services\MercadoLibre\OrderDownloaderService;
use Illuminate\Console\Command;

class sync_orders_mercado_libre extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync_orders_mercado_libre';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Comenzando');
        $service = new OrderDownloaderService(config('app.USER_ID'));
        $service->get_all_orders();
        $this->info('Termino');

        return 0;
    }
}
