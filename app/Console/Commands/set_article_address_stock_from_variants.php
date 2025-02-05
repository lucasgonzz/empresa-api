<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Article;
use App\Models\Mantenimiento;
use Illuminate\Console\Command;

class set_article_address_stock_from_variants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_article_address_stock_from_variants { user_id }';

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

        $articles = Article::where('user_id', $this->argument('user_id'))
                            // ->where('num', 12559)
                            ->get();

        foreach ($articles as $article) {

            if (count($article->article_variants) >= 1) {
                
                $addresses = $this->get_addresses();
                
                foreach ($article->article_variants as $variant) {
                    

                    foreach ($variant->addresses as $variant_address) {
                        // $this->comment($variant->variant_description);
                        // $this->comment('Sumando '.$variant_address->pivot->amount.' de '.$variant_address->street);
                        
                        $addresses[$variant_address->pivot->address_id] += (float)$variant_address->pivot->amount;

                        // $this->comment('Va en '.$addresses[$variant_address->pivot->address_id]);
                        // $this->info('');

                    }
                }

                $this->actualizar_article_addresses($article, $addresses);

                $this->actualizar_article_stock($article);

                $this->info('Se actualizo '.$article->name);
            }

        }

        $this->info('Termino');

        Mantenimiento::create([
            'notas' => 'Se repasaron las variantes',
        ]);

        return 0;
    }

    function actualizar_article_stock($article) {
        $article->load('addresses');

        $stock = 0;

        foreach ($article->addresses as $address) {
            
            $stock += $address->pivot->amount;
        }

        $article->stock = $stock;
        $article->timestamps = false;
        $article->save();
    }

    function actualizar_article_addresses($article, $addresses) {
            
        $article->addresses()->sync([]);
        
        foreach ($addresses as $address_id => $amount) {

            $_address = Address::find($address_id);  
            
            // $this->info($_address->street.' = '.$amount);
            
            $article->addresses()->attach($address_id, [
                'amount'    => $amount,
            ]); 
        }
    }

    function set_addresses() {

        $this->addresses = Address::where('user_id', $this->argument('user_id'))
                            ->get();
    }

    function get_addresses() {

        $addresses = [];

        foreach ($this->addresses as $address) {
            
            $addresses[$address->id] = 0;
        }

        return $addresses;
    }
}
