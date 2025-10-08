<?php

namespace App\Console\Commands;

use App\Services\MercadoLibre\ProductService;
use Illuminate\Console\Command;

class sync_mercado_libre extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync_mercado_libre';

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

        $articles = Article::where('user_id', env('USER_ID'))
                            ->where('need_sync_to_meli', 1)
                            ->get();

        $service = new ProductService();

        foreach ($articles as $article) {
            $service->sync_article($article);

            $article->need_sync_to_meli = 0;
            $article->save();
        }

        return 0;
    }
}
