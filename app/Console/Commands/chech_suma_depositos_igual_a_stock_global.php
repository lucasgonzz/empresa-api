<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;

class chech_suma_depositos_igual_a_stock_global extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chech_suma_depositos_igual_a_stock_global {user_id}';

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
                                ->whereHas('addresses')
                                ->get();
        $this->comment(count($articles).' articulos');

        $count = 0;

        foreach ($articles as $article) {

            $count++;
            
            $total = 0;
            
            foreach ($article->addresses as $address) {
                
                $total += $address->pivot->amount;    
            }

            if ($total != $article->stock) {
                $this->info("Article {$article->name}, num: {$article->num}. Stock: {$article->stock} y suma depositos: $total");
            }


            if ($count % 500 == 0) {
                $this->info('Se chequearon '.$count);
            }
            
        }
        return 0;
    }
}
