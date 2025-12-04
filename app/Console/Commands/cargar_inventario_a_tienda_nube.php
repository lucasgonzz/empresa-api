<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Console\Command;

class cargar_inventario_a_tienda_nube extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cargar_inventario_a_tienda_nube';

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

        $articles = Article::whereHas('images')
                            ->where('user_id', env('USER_ID'))
                            ->orderBy('id', 'DESC')
                            ->take(10)
                            ->get();

        foreach ($articles as $article) {
            $article->disponible_tienda_nube = 1;
            $article->timestamps = false;
            $article->save();

            TiendaNubeSyncArticleService::add_article_to_sync($article);
        }
        return 0;
    }
}
