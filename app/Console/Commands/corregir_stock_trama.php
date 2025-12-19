<?php

namespace App\Console\Commands;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Console\Command;

class corregir_stock_trama extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'corregir_stock_trama';

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
        $articles = Article::all();
        // $articles = Article::where('id', 1324)->get();

        $this->info(count($articles).' articles');

        $user = User::find($articles[0]->user_id);

        $stock_movement_ct = new StockMovementController();

        foreach ($articles as $article) {

            $this->line($article->id.': '.$article->name);
            $movimientos = StockMovement::where('article_id', $article->id)
                                    ->where('created_at', '>=', '25-12-16 21:40:00')
                                    ->where('created_at', '<=', '25-12-16 21:50:00')
                                    ->where('concepto_stock_movement_id', 15)
                                    ->orderBy('id', 'ASC')
                                    ->get();


            $this->line(count($movimientos).' mov');

            foreach ($movimientos as $movimiento) {
                
                $data = [];

                $data['concepto_stock_movement_name'] = 'Ingreso manual';

                $data['model_id'] = $article->id;
                $data['amount'] = -(float)$movimiento->amount;
                $data['to_address_id'] = $movimiento->to_address_id;

                $stock_movement_ct->crear($data, true, $user, $user->id);
            }
        }
        $this->info('Listo');
        return 0;
    }
}
