<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Console\Command;

class update_all_articles_tienda_nube extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update_all_articles_tienda_nube';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea una sync pendiente para cada articulo que este disponible en tienda nube. Luego el comando sync_articles_to_tienda_nube se encarga de mandar cada una a Tienda Nube';

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
                            ->where('user_id', config('app.USER_ID'))
                            ->where('disponible_tienda_nube', 1)
                            ->orderBy('id', 'DESC')
                            ->get();


        $this->info(count($articles).' articulos');

        $index = 0;

        foreach ($articles as $article) {

            TiendaNubeSyncArticleService::add_article_to_sync($article);

            $this->info('Se agrego '.$article->name);

        }
        return 0;
    }
}
