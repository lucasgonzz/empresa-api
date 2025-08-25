<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class corregir_stock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'corregir_stock {user_id} {--solo_informar}';

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
        
        $this->solo_informar = $this->option('solo_informar');

        if ($this->solo_informar) {
            
            $this->info('solo informar');

        } else {
            $this->info('se van a corregir los articulos');
        }

        sleep(3);

        // Articulos cuyo stock actual no coincide con el stock resultante del ultimo movimiento
        $articles = $this->get_articles_mal();

        // $articles = Article::where('num', 29599)
        //                     ->where('user_id', 2)
        //                     ->get();



        foreach ($articles as $article) {

            $movimientos = StockMovement::where('article_id', $article->id)
                                        ->orderBy('id', 'ASC')
                                        ->get();

            $stock = 0;

            foreach ($movimientos as $movimiento) {

                if ($movimiento->concepto_movement->name == 'Reseteo de Stock') {
                    $stock = 0;
                } else {
                    $stock += (float)$movimiento->amount;
                }


                if (!$this->solo_informar) {

                    $movimiento->stock_resultante = $stock;
                    $movimiento->save();
                }

            }

            if ($this->solo_informar) {

                $this->comment($article->name.' deberia tener '.$stock.'. Tiene '.$article->stock);

            } else {

                $article->stock = $stock;
                $article->timestamps = false;
                $article->save();

                $this->info($article->name.' corregido');
            }


        }


        $this->comment('Listo');
        
        return 0;
    }

    function get_articles_mal() {

        $articulos_mal = [];
        $articles = Article::where('user_id', $this->argument('user_id'))
                            ->get();

        $this->info(count($articles).' articulos');      

        foreach ($articles as $article) {
            
            $last_stock_movement = StockMovement::where('article_id', $article->id)
                                                ->orderBy('id', 'DESC')
                                                ->first();

            if ($last_stock_movement) {
                $stock_resultante = $last_stock_movement->stock_resultante;
                if ($article->stock != $stock_resultante) {
                    $articulos_mal[] = $article;
                }
            }
        }

        return $articulos_mal;
    }
}
