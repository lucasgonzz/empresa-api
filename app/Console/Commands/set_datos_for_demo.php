<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\Caja;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class set_datos_for_demo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_datos_for_demo {primera_vez?}';

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

        if ($this->argument('primera_vez')) {

            $this->crear_cajas();
            
            $this->set_article_prices();

            $this->set_user_info();

            $this->set_sales_prices();
        }

        $this->crear_sales();


        return 0;
    }

    function crear_sales() {

        $employees = User::whereNotNull('owner_id')->get();

        $num = 100000;

        $ventas = [];

        foreach ($employees as $employee) { 

            $price_vender = rand(10000, 10000);
            $address_id = rand(1, 2);

            $amount = rand(1, 10);
            $total = $price_vender * $amount;

            $ventas[] = [
                'num'               => $num,
                'total'             => $total,
                'employee_id'       => $employee->id,
                'address_id'        => $address_id,
                'client_id'         => $address_id < 3 ? 1 : null,
                'articles'          => [
                    [
                        'id'            => 1,
                        'price_vender'  => $price_vender,
                        'cost'          => $price_vender / 2,
                        'amount'        => $amount,
                    ],
                ],
                'payment_methods'   => [
                    [
                        'id'        => rand(1,2),
                        'amount'    => $total / 4,
                    ],
                    [
                        'id'        => rand(3,5),
                        'amount'    => ($total / 4) * 2,
                    ],
                    [
                        'id'        => 5,
                        'amount'    => $total / 4,
                    ],
                ],
                'created_at'    => Carbon::now(),
            ];
            $num++;
        }

        SaleSeederHelper::create_sales($ventas);

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

    function crear_cajas() {

        $cajas = Caja::all();

        foreach ($cajas as $caja) {
            $caja->delete();
        }
        
        $addresses = Address::all();
        $payment_methods = CurrentAcountPaymentMethod::all();
        $num = 0;
        foreach ($addresses as $address) {
            
            foreach ($payment_methods as $payment_method) {
                
                $num++;

                $model = [
                    'num'   => $num,
                    'name'      => $address->street.' '.$payment_method->name,
                    'address_id'    => $address->id,
                    'user_id'   => $address->user_id,
                    'saldo'     => 100000,
                ];

                $caja = Caja::create($model);

                $helper = new CajaAperturaHelper($caja->id);
                $helper->abrir_caja();

                DefaultPaymentMethodCaja::create([
                    'current_acount_payment_method_id'  => $payment_method->id,
                    'address_id'                        => $address->id,
                    'caja_id'                           => $caja->id,
                    'user_id'                           => $caja->user_id,
                ]);
            }
        }
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
        $this->user->google_cuota = 100;
        $this->user->google_custom_search_api_key = 'AIzaSyCgzE6haVi8uZnenfAvYJO5hn7m7Cl09Gw';

        $this->user->save();
    }

    function set_article_prices() {
        
        $articles = Article::orderBy('id', 'DESC')
                            ->take(2000)
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
