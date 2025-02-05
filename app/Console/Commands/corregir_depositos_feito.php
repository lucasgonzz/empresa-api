<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class corregir_depositos_feito extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'corregir_depositos_feito';

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

        $articles = Article::whereHas('lastStockMovement', function ($query) {
                                $query->where('concepto', 'Compra a proveedor')
                                      ->whereNotNull('to_address_id');
                            })->get();

        $this->info('Hay '.count($articles));


        foreach ($articles as $article) {

            // if ($article->num == 12559) {

                $stock = 0;

                $stock_movements = StockMovement::where('article_id', $article->id)
                                                ->orderBy('created_at', 'DESC')
                                                ->get();

                $this->info($article->name);

                foreach ($stock_movements as $stock_movement) {
                    
                    if (
                        $stock_movement->concepto == 'Compra a proveedor'
                        && !is_null($stock_movement->to_address_id)
                    ) {

                        $stock += (int)$stock_movement->amount;

                        $this->comment(Address::find($stock_movement->to_address_id)->street.' = '.$stock_movement->amount);

                        $article->addresses()->updateExistingPivot($stock_movement->to_address_id, [
                            'amount'    => $stock_movement->amount,
                        ]);
                    }
                }

                $this->comment('stock: '.$stock);

                $article->stock = $stock;
                // $article->timestamps = false;
                $article->save();
            // }
            

        }

        return 0;
    }
}
