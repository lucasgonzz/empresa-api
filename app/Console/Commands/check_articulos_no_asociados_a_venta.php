<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Sale;
use Illuminate\Console\Command;

class check_articulos_no_asociados_a_venta extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_articulos_no_asociados_a_venta';

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
        $ventas = Sale::with(['articles', 'stock_movements'])
                    ->get()
                    ->map(function ($sale) {
                        $articlesInSale = $sale->articles->pluck('id')->toArray();
                        $articlesWithStockMovement = $sale->stock_movements->pluck('article_id')->toArray();

                        $articleIdsMissing = array_diff($articlesWithStockMovement, $articlesInSale);

                        return [
                            'sale_id' => $sale->id,
                            'missing_articles' => Article::whereIn('id', $articleIdsMissing)->get()
                        ];
                    })
                    ->filter(fn ($sale) => $sale['missing_articles']->isNotEmpty());
        
        foreach ($ventas as $venta) {
            $sale = Sale::find($venta['sale_id']);
            if (!is_null($sale) ) {
                $this->info($sale->num);

                foreach ($venta['missing_articles'] as $article) {
                    if (!is_null($article)) {
                        $this->comment($article->num);
                    }
                }
            }
        }
    }
}
