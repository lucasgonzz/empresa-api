<?php

namespace App\Console\Commands;

use App\Models\SyncToTNArticle;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Console\Command;

class sync_articles_to_tienda_nube extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync_articles_to_tienda_nube';

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

        $sync_articles = SyncToTNArticle::where('user_id', env('USER_ID'))
                            ->where('status', 'pendiente')
                            ->get();

        $this->info(count($sync_articles).' sincronizaciones');

        $service = new TiendaNubeSyncArticleService();

        foreach ($sync_articles as $sync) {
            $service->sync_article($sync);
        }

        return 0;
    }
}
