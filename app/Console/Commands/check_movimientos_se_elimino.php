<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class check_movimientos_se_elimino extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_movimientos_se_elimino {user_id}';

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
        $articles = Article::where('user_id', $this->argument('user_id'))
                            ->get();

        foreach ($articles as $article) {
            
            $stock_restado = 0;

            $stock_movements = StockMovement::where('article_id', $article->id)
                                                ->orderBy('created_at', 'ASC')
                                                ->get();
            
            foreach ($stock_movements as $stock_movement) {
                
                if (str_contains($stock_movement->concepto, 'Se elimino de la venta')) {

                    $num_venta = substr($stock_movement->concepto, 23);
                    

                    $stock_movement_de_la_venta = StockMovement::where('article_id', $article->id)
                                            ->where('concepto', 'Venta N° '.$num_venta)
                                            ->first();

                    if (is_null($stock_movement_de_la_venta)) {
                        $stock_restado += $stock_movement->amount;

                        $this->comment($article->num. ' - '.$article->name);
                        $this->comment('Stock restado: '.$stock_restado);
                        // $this->comment('Article: '.$article->name. '. Num: '.$article->num);

                        // $this->info('N° venta: '.$num_venta);
                        
                        // $this->info('NO TIENE');
                    }

                }
            }
        }

        $this->info('TERMINO');

        return 0;
    }
}
