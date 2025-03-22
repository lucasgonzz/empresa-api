<?php

namespace Database\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CurrentAcountAndCommissionHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SaleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $this->reportes();
        $this->reportes_articulos();

        // $this->pagos(500);

        // $this->venta_sin_confirmar_a_fin_de_mes();

    }

    function reportes() {

        require('sales/reportes.php');

        $this->create_sales($ventas_desde_principio_de_mes);
        
        $this->create_sales($ventas_meses_anterioires);

    }

    function reportes_articulos() {

        require('sales/reportes2.php');

        // $this->create_sales($ventas_desde_principio_de_mes);
        
        $this->create_sales($ventas_meses_anterioires);

    }

    function venta_sin_confirmar_a_fin_de_mes() {
        $data = [
            'num'               => 999999,
            'address_id'        => 1,
            'employee_id'       => null,
            'client_id'         => 1,
            'created_at'        => Carbon::now()->subMonths(1)->endOfMonth(),
            'user_id'           => env('USER_ID'),
            'terminada'         => 0,
            'confirmed'         => 1,
            'save_current_acount'=> 1,
        ];
        
        $created_sale = Sale::create($data);

        $price_vender = 10;
        $sale = [
            'client_id'         => 1,
            'articles'          => [
                [
                    'id'            => 1,
                    'price_vender'  => $price_vender,
                    'cost'          => $price_vender / 2,
                    'amount'        => 1,
                ],
            ],
            'payment_methods'   => [
                [
                    'id'        => rand(1,2),
                    'amount'    => $price_vender / 4,
                ],
                [
                    'id'        => rand(3,5),
                    'amount'    => ($price_vender / 4) * 2,
                ],
                [
                    'id'        => 5,
                    'amount'    => $price_vender / 4,
                ],
            ],
        ];

        SaleHelper::attachProperies($created_sale, $this->setRequest($sale));
    }

    function create_sales($sales) {
        $user = User::find(env('USER_ID'));

        foreach ($sales as $sale) {

            $data = [
                'num'               => $sale['num'],
                'total'             => $sale['total'],
                'address_id'        => $sale['address_id'],
                'employee_id'       => $sale['employee_id'],
                'client_id'         => $sale['client_id'],
                'created_at'        => $sale['created_at'],
                'user_id'           => $user->id,
                'save_current_acount'=> 1,
                'terminada'         => 1,
                'terminada_at'      => $sale['created_at'],
            ];
            
            $created_sale = Sale::create($data);
            SaleHelper::attachProperies($created_sale, $this->setRequest($sale));
        }
    }

    function ventas_meses_atras() {
        $this->ventas_en_mostrador();
        $this->ventas_a_cuenta_corriente();
    }

    function ventas_a_cuenta_corriente() {
        $user = User::find(env('USER_ID'));

        $models = [
            [
                'num'                   => 1,
                'client_id'             => 1,
                'employee_id'           => 503,
                'save_current_acount'   => 1,
                'user_id'               => $user->id,
            ],
        ];

        for ($meses=5; $meses > 0 ; $meses--) { 
            foreach ($models as $model) {

                $model['created_at'] = Carbon::now()->subMonths($meses);

                $sale = Sale::create($model);

                SaleHelper::attachProperies($sale, $this->setRequest($sale));

                $this->pago_para_la_venta($sale);
            }
        }

    }

    function pago_para_la_venta($sale) {

        $pago = CurrentAcount::create([
            'haber'                             => 10,
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $sale->user_id,
            'num_receipt'                       => 1,
            'detalle'                           => 'Pago N°'.$sale->num,
            'client_id'                         => $sale->client_id,
            'created_at'                        => $sale->created_at,
        ]);
        CurrentAcountPagoHelper::attachPaymentMethods($pago, $this->checks());
        $pago->saldo = CurrentAcountHelper::getSaldo('client', $sale->client_id, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('client', $sale->client_id, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'client', $sale->client_id);
    }

    function ventas_en_mostrador() {
        $user = User::find(env('USER_ID'));

        $models = [
            [
                'num'           => 1,
                'client_id'     => 1,
                'employee_id'   => 503,
                'save_current_acount'   => 0,
                'omitir_en_cuenta_corriente'   => 1,
                'current_acount_payment_method_id'  => 2,
                'user_id'       => $user->id,
            ],
        ];

        for ($meses=5; $meses > 0 ; $meses--) { 
            foreach ($models as $model) {

                $model['created_at'] = Carbon::now()->subMonths($meses);
                $model['current_acount_payment_method_id'] = $meses;

                $sale = Sale::create($model);

                SaleHelper::attachProperies($sale, $this->setRequest($sale));
            }
            $this->multiplo_price++;
        }

    }

    function ventas_sin_pagar() {
        $user = User::find(env('USER_ID'));

        $models = [
            [
                'num'           => 1,
                'client_id'     => 1,
                'employee_id'   => 503,
                'save_current_acount'   => 1,
                'user_id'       => $user->id,
            ],
            [
                'num'           => 2,
                'client_id'     => 1,
                'employee_id'   => 504,
                'save_current_acount'   => 1,
                'user_id'       => $user->id,
            ],
            [
                'num'           => 3,
                'client_id'     => 1,
                'employee_id'   => 503,
                'save_current_acount'   => 1,
                'user_id'       => $user->id,
            ],
            [
                'num'           => 4,
                'client_id'     => 1,
                'employee_id'   => 504,
                'save_current_acount'   => 1,
                'user_id'       => $user->id,
            ],
            [
                'num'           => 5,
                'client_id'     => 1,
                'employee_id'   => 504,
                'save_current_acount'   => 1,
                'user_id'       => $user->id,
            ],
            [
                'num'           => 6,
                'client_id'     => 1,
                'employee_id'   => 504,
                'save_current_acount'   => 1,
                'user_id'       => $user->id,
            ],
            [
                'num'           => 7,
                'client_id'     => 1,
                'employee_id'   => 504,
                'save_current_acount'   => 1,
                'user_id'       => $user->id,
            ],
        ];

        for ($dias=6; $dias >= 0 ; $dias--) { 

            $model = $models[$dias];

            $model['created_at'] = Carbon::now()->subDays($dias);
            $model['address_id'] = rand(1,2);
            $model['omitir_en_cuenta_corriente'] = 1;

            $sale = Sale::create($model);

            SaleHelper::attachProperies($sale, $this->setRequest($sale));
        }
    }

    function pagos($user_id) {
        
        $pago = CurrentAcount::create([
            'haber'                             => 200,
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $user_id,
            'num_receipt'                       => 1,
            'detalle'                           => 'Pago N°1',
            'client_id'                         => 1,
            'created_at'                        => Carbon::now(),
            'employee_id'                       => $user_id,
        ]);
        CurrentAcountPagoHelper::attachPaymentMethods($pago, $this->checks());
        $pago->saldo = CurrentAcountHelper::getSaldo('client', 1, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('client', 1, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'client', 1);

        $pago = CurrentAcount::create([
            'haber'                             => 5000,
            'detalle'                           => 'Pago N°2',
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $user_id,
            'num_receipt'                       => 2,
            'client_id'                         => 1,
            'created_at'                        => Carbon::now(),
            'employee_id'                       => $user_id,
        ]);

        CurrentAcountPagoHelper::attachPaymentMethods($pago, [
            [
                'amount'    => 3000,
                'current_acount_payment_method_id'  => 2,
                'bank'                          => null,
                'payment_date'                  => null,
                'num'                           => null,
                'credit_card_id'                => null,
                'credit_card_payment_plan_id'   => null,
            ],
            [
                'amount'    => 2000,
                'current_acount_payment_method_id'  => 3,
                'bank'                          => null,
                'payment_date'                  => null,
                'num'                           => null,
                'credit_card_id'                => null,
                'credit_card_payment_plan_id'   => null,
            ],
        ]);

        $pago->saldo = CurrentAcountHelper::getSaldo('client', 1, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('client', 1, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'client', 1);

        $pago = CurrentAcount::create([
            'detalle'                           => 'Pago N°3',
            'haber'                             => 7000,
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $user_id,
            'num_receipt'                       => 3,
            'client_id'                         => 1,
            'created_at'                        => Carbon::now(),
            'employee_id'                       => $user_id,
        ]);
        CurrentAcountPagoHelper::attachPaymentMethods($pago, [
            [
                'amount'    => 4000,
                'current_acount_payment_method_id'  => 4,
                'bank'                          => null,
                'payment_date'                  => null,
                'num'                           => null,
                'credit_card_id'                => null,
                'credit_card_payment_plan_id'   => null,
            ],          
            [
                'amount'    => 3000,
                'current_acount_payment_method_id'  => 3,
                'bank'                          => null,
                'payment_date'                  => null,
                'num'                           => null,
                'credit_card_id'                => null,
                'credit_card_payment_plan_id'   => null,
            ],
        ]);
        $pago->saldo = CurrentAcountHelper::getSaldo('client', 1, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('client', 1, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'client', 1);
    }

    function checks() {
        $request = new \stdClass();
        return [
            [
                'current_acount_payment_method_id'  => 1,
                'amount'                        => 100,
                'bank'                          => 'BERSA',
                'payment_date'                  => null,
                'num'                           => '123123',
                'credit_card_id'                => null,
                'credit_card_payment_plan_id'   => null,
            ],
            [
                'current_acount_payment_method_id'  => 1,
                'amount'                        => 100,
                'bank'                          => 'BNA',
                'payment_date'                  => null,
                'num'                           => '123123',
                'credit_card_id'                => null,
                'credit_card_payment_plan_id'   => null,
            ],
        ];
        return $request;
    }

    function videos() {
        $user = User::find(env('USER_ID'));
        $addresses = Address::where('user_id', $user->id)
                        ->get();
        $employees = User::where('owner_id', $user->id)
                        ->get();
        $ct = new Controller();
        for ($day=10; $day >= 0; $day--) { 
            $minutes = 100;
            foreach ($employees as $employee) {
                $sale = Sale::create([
                    'user_id'           => $user->id,
                    'num'               => $ct->num('sales', $user->id),
                    'created_at'        => Carbon::now()->subDays($day)->subMinutes($minutes),
                    'employee_id'       => $employee->id,
                    'address_id'        => count($addresses) >= 1 ? $addresses[0]->id : null,
                ]);
                $minutes -= 10;
                $articles = Article::where('user_id', $user->id)
                                    ->get();
                foreach ($articles as $article) {
                    $sale->articles()->attach($article->id, [
                                                'amount'      => rand(1, 10),
                                                'cost'        => $article->cost,
                                                'price'       => $article->final_price,
                                            ]);
                }
            }
        }
    }

    function setRequest($sale) {
        $request = new \stdClass();
        $request->items = [];
        $request->discounts = [];
        $request->surchages = [];
        $request->selected_payment_methods = $sale['payment_methods'];
        $request->current_acount_payment_method_id = null;
        $request->discount_amount = null;
        $request->discount_percentage = null;
        $request->client_id = $sale['client_id'];

        foreach ($sale['articles'] as $article) {
            $_article = [
                'id'            => $article['id'],
                'is_article'    => true,
                'name'          => null,
                'num'           => null,
                'amount'        => $article['amount'],
                'article_variant_id'        => null,
                'cost'          => $article['cost'],
                'price_vender'  => $article['price_vender'],
            ];
            $request->items[] = $_article; 
        }

        return $request;
    }
}
