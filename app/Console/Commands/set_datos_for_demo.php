<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Console\Command;
use Carbon\Carbon;

class set_datos_for_demo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_datos_for_demo';

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
        $this->set_article_prices();

        $this->set_user_info();

        $this->set_sales_prices();

        return 0;
    }

    function set_sales_prices() {
        
        $sales = Sale::orderBy('id', 'DESC')
                        ->take(100)
                        ->get();

        $this->info(count($sales).' ventas');

        $index = count($sales);

        foreach ($sales as $sale) {
            $total = 0;

            foreach ($sale->articles as $article) {
                
                $amount = rand(1, 10);
                $price = 1000;
                $sale->articles()->updateExistingPivot($article->id, [
                    'price' => $price,
                    'amount'    => $amount
                ]);

                $total_article = $amount * $price;

                $total += $total_article;
            }

            $sale->total = $total;
            $sale->timestamps = false;
        
            if ($index <= 30) {
                $sale->created_at = Carbon::now();
            }

            $sale->save();
            
            if ($sale->client_id) {

                SaleHelper::updateCurrentAcountsAndCommissions($sale);
            }
            $this->comment('Venta N° '.$sale->num.' ok');

            $index--;
        }

        $this->info('Listo ventas');


    }

    function set_user_info() {
        $this->user->image_url = 'https://comerciocity.com/img/logo.95c86b81.jpg';
        $this->user->name = 'Juan';
        $this->user->company_name = 'ComercioCity';
        $this->user->doc_number = '1234';
        $this->user->password = bcrypt('1234');
        $this->user->show_stock_min_al_iniciar = 0;
        $this->user->show_afip_errors_al_iniciar = 0;
        $this->user->default_version = null;
        $this->user->estable_version = null;

        $this->user->save();
    }

    function set_article_prices() {
        
        $articles = Article::orderBy('id', 'DESC')
                            // ->take(500)
                            ->get();

        $this->info(count($articles).' articles');

        $this->user = User::find($articles[0]->user_id);

        $price_types = PriceType::all();

        foreach ($articles as $article) {
            
            $article->cost = 100;
            $article->timestamps = false;
            $article->save();

            ArticleHelper::setFinalPrice($article, $this->user->id, $this->user, null, true, $price_types);
            $this->comment($article->id.' ok');
        }
        $this->info('Terminado articles');
    }
}
