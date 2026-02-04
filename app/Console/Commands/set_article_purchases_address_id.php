<?php

namespace App\Console\Commands;

use App\Models\Sale;
use Illuminate\Console\Command;

class set_article_purchases_address_id extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_article_purchases_address_id {user_id?}';

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

        $user_id = config('app.USER_ID');
        if (!$user_id) {
            $user_id = $this->argument('user_id');
        }

        $sales = Sale::where('user_id', $user_id)
                        ->orderBy('id', 'ASC')
                        ->get();

        $this->info(count($sales).' ventas');

        foreach ($sales as $sale) {
            
            foreach ($sale->article_purchases as $article_purchase) {
                
                $article_purchase->address_id = $sale->address_id;
                $article_purchase->timestamps = false;
                $article_purchase->save();
            }
            $this->comment('Listo venta '.$sale->id);
        }

        $this->info('Termino');

        return 0;
    }
}
