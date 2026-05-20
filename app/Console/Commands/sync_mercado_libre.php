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

        $sync_articles = SyncToMeliArticle::where('user_id', config('app.USER_ID'))
                            ->where('status', 'pendiente')
                            ->get();

        $this->info(count($sync_articles).' sincronizaciones');

        $user_id = (int) config('app.USER_ID');

        foreach ($sync_articles as $sync) {
            try {
                $service = new ProductService($user_id);
                $service->sync_article($sync);
            } catch (\Exception $e) {
                $this->error('Sync #'.$sync->id.': '.$e->getMessage());
            }
        }

        return 0;
    }
}
