<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class corregir_depositos_feito_todos_los_articulos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'corregir_depositos_feito_todos_los_articulos { article_num? }';

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

        $this->set_addresses();

        $articles = Article::whereDoesntHave('article_variants');

        if (!is_null($this->argument('article_num'))) {
            $articles = $articles->where('num', $this->argument('article_num'));
        }

        $articles = $articles->get();

        $this->info('Hay '.count($articles));


        foreach ($articles as $article) {
            
            $stock_movements = StockMovement::where('article_id', $article->id)
                                            ->orderBy('created_at', 'ASC')
                                            ->get();


            $stock = 0;

            if (
                count($article->article_variants) == 0
                && count($article->addresses) >= 1
            ) {

                $addresses = $this->get_addresses();

                foreach ($stock_movements as $stock_movement) {
                    
                    $concepto = $stock_movement->concepto;

                    $amount = (int)$stock_movement->amount;


                    if (
                        !is_null($stock_movement->from_address_id)
                        && isset($addresses[$stock_movement->from_address_id])
                        ) {

                        if ($stock_movement->concepto == 'Movimiento de depositos') {

                            $addresses[$stock_movement->from_address_id] -= $amount;
                        } else {

                            $addresses[$stock_movement->from_address_id] += $amount;
                        }
                    }

                    if (
                        !is_null($stock_movement->to_address_id)
                        && isset($addresses[$stock_movement->to_address_id])
                        ) {
                        $addresses[$stock_movement->to_address_id] += $amount;
                    }
                    

                    $stock += $amount;
                }


                $article->addresses()->sync([]);
                
                foreach ($addresses as $address_id => $amount) {
                    
                    $this->comment($amount.' en '.Address::find($address_id)->street);
                    $article->addresses()->attach($address_id, [
                        'amount'    => $amount,
                    ]);    
                }
                $this->info($stock.' de stock');
                $article->stock = $stock;
                $article->save();
            }

        }

        return 0;
    }

    function set_addresses() {
        $this->addresses = Address::all();
    }

    function get_addresses() {

        $addresses = [];

        foreach ($this->addresses as $address) {
            
            $addresses[$address->id] = 0;
        }

        return $addresses;
    }
}
