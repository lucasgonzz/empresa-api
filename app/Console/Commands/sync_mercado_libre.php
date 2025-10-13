<?php

namespace App\Console\Commands;

use App\Models\SyncToMeliArticle;
use App\Services\MercadoLibre\ProductService;
use Illuminate\Console\Command;

class sync_mercado_libre extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync_to_meli_articles';

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

        $sync_articles = SyncToMeliArticle::where('user_id', env('USER_ID'))
                            ->where('status', 'pendiente')
                            ->get();

        $this->info(count($sync_articles).' sincronizaciones');

        $service = new ProductService();

        foreach ($sync_articles as $sync) {
            $service->sync_article($sync);
        }

        return 0;
    }
}
