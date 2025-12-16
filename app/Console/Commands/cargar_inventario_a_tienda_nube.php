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
    protected $signature = 'cargar_inventario_a_tienda_nube {cantidad} {omitir_los_primeros?}';

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
                            ->take($this->argument('cantidad'))
                            ->get();

        $this->info(count($articles).' articulos');

        $index = 0;

        foreach ($articles as $article) {
            
            $index++;

            if ($this->argument('omitir_los_primeros') && $index <= (int)$this->argument('omitir_los_primeros')) {

                $this->info('Se omitio '.$index);
                continue;

            } else {

                $article->disponible_tienda_nube = 1;
                $article->timestamps = false;
                $article->save();

                TiendaNubeSyncArticleService::add_article_to_sync($article);

                $this->info('Se agrego '.$article->name);
            }


        }
        return 0;
    }
}
