<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class corregir_stock_golonorte extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'corregir_stock_golonorte {article_num}';

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

        set_time_limit(9999999);

        $articles = Article::where('user_id', 800)
                            ->orderBy('id', 'ASC');
        if ($this->argument('article_num') != '') {

            $article = Article::where('num', $this->argument('article_num'))
                                ->where('user_id', 800)
                                ->first();

            $articles = $articles->where('id', $article->id);
        }
        $articles = $articles->get();

        $this->info(count($articles).' articulos');

        foreach ($articles as $article) {
            
            $primer_movimiento = StockMovement::where('article_id', $article->id)
                                                    ->orderBy('id', 'ASC')
                                                    ->first();
            
            $movimientos_para_eliminar = [];

            if (
                !is_null($primer_movimiento)
                && $primer_movimiento->concepto_movement->name == 'Importacion de excel'
            ) {

                $siguiente_movimiento = StockMovement::where('article_id', $article->id)
                                                    ->where('id', '>', $primer_movimiento->id)
                                                    ->orderBy('id', 'ASC')
                                                    ->first();

                $movimientos_para_eliminar[] = $primer_movimiento;
                
                while (
                    $siguiente_movimiento
                    && $siguiente_movimiento->concepto_movement->name == 'Importacion de excel'
                ) {
                
                    $movimientos_para_eliminar[] = $siguiente_movimiento;


                    $siguiente_movimiento = StockMovement::where('article_id', $article->id)
                                                    ->where('id', '>', $siguiente_movimiento->id)
                                                    ->orderBy('id', 'ASC')
                                                    ->first();

                }

                if (
                    $siguiente_movimiento
                    && $siguiente_movimiento->concepto_movement->name == 'Ingreso manual'
                ) {

                    $siguiente_movimiento->stock_resultante = $siguiente_movimiento->amount;
                    $siguiente_movimiento->timestamps = false;
                    $siguiente_movimiento->save();

                    $this->info('article num '.$article->num.'. '.count($movimientos_para_eliminar).' mov para eliminar');

                    foreach ($movimientos_para_eliminar as $movimiento_para_eliminar) {
                        $movimiento_para_eliminar->delete();
                        $this->info('Se elimino movimiento de article num: '.$article->num);
                    }
                }
            }
        }

        $this->comment('listo');

        return 0;
    }
}
