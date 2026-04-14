<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Console\Command;

class actualizar_articulos_disponibles_en_tienda_nube extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actualizar_articulos_disponibles_en_tienda_nube';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manda a actualizar todos los articulos que estan actualmente disponibles en tienda nube';

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
        $articles = Article::where('disponible_tienda_nube', 1)
                            ->where('user_id', config('app.USER_ID'))
                            ->orderBy('id', 'DESC')
                            ->get();

        $this->info(count($articles).' articulos');


        foreach ($articles as $article) {
            
            TiendaNubeSyncArticleService::add_article_to_sync($article);

            $this->info('Se agrego '.$article->name);

        }

        $this->info('Terminado');
        
        return 0;
    }
}
