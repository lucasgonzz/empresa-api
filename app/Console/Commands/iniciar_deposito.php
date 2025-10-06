<?php

namespace App\Console\Commands;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\Article;
use Illuminate\Console\Command;

class iniciar_deposito extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'iniciar_deposito';

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
        $address = Address::where('street', 'Esperanza')
                        ->first();

        $articles = Article::where('user_id', env('USER_ID'))
                            ->get();

        $ct_stock_movement = new StockMovementController();

        foreach ($articles as $article) {

            if (count($article->addresses) == 0) {

                $data = [];

                $data['model_id'] = $article->id;

                $data['amount'] = $article->stock;

                $data['to_address_id'] = $address->id;

                $data['concepto_stock_movement_name'] = 'Creacion de deposito';
                
                $ct_stock_movement->crear($data, false, null, null);

                $this->comment($article->name.' listo');
            } else {
                $this->info('Se omitio '.$article->name);
            }

        }

        $this->info('Termino');


        return 0;
    }
}
