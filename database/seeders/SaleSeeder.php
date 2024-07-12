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

        $this->multiplo_price = 1;

        $this->ventas_sin_pagar();

        // Este es para las company_performances
        $this->ventas_meses_atras();

        // $this->pagos();
    }

    function ventas_meses_atras() {
        $this->ventas_en_mostrador();
        $this->ventas_a_cuenta_corriente();
    }

    function ventas_a_cuenta_corriente() {
        $user = User::where('company_name', 'Autopartes Boxes')->first();

        $models = [
            [
                'num'                   => 1,
                'client_id'             => 1,
                'employee_id'           => 3,
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
        $user = User::where('company_name', 'Autopartes Boxes')->first();

        $models = [
            [
                'num'           => 1,
                'client_id'     => 1,
                'employee_id'   => 3,
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
        $user = User::where('company_name', 'Autopartes Boxes')->first();

        $models = [
            [
                'num'           => 1,
                'client_id'     => 1,
                'employee_id'   => 3,
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

            $sale = Sale::create($model);

            SaleHelper::attachProperies($sale, $this->setRequest($sale));
        }
    }

    function pagos() {
        
        $pago = CurrentAcount::create([
            'haber'                             => 10,
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $user->id,
            'num_receipt'                       => 1,
            'detalle'                           => 'Pago N°1',
            'client_id'                         => 1,
            'created_at'                        => Carbon::now()->subDays(2)->addHours(1),
        ]);
        CurrentAcountPagoHelper::attachPaymentMethods($pago, $this->checks());
        $pago->saldo = CurrentAcountHelper::getSaldo('client', 1, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('client', 1, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'client', 1);

        $pago = CurrentAcount::create([
            'haber'                             => 40,
            'detalle'                           => 'Pago N°2',
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $user->id,
            'num_receipt'                       => 2,
            'client_id'                         => 1,
            'created_at'                        => Carbon::now()->subDays(2)->addHours(2),
        ]);
        $pago->saldo = CurrentAcountHelper::getSaldo('client', 1, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('client', 1, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'client', 1);

        $pago = CurrentAcount::create([
            'detalle'                           => 'Pago N°3',
            'haber'                             => 140,
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $user->id,
            'num_receipt'                       => 3,
            'client_id'                         => 1,
            'created_at'                        => Carbon::now()->subDays(2)->addHours(3),
        ]);
        $pago->saldo = CurrentAcountHelper::getSaldo('client', 1, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('client', 1, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'client', 1);

        $sale = Sale::create([
            'user_id'               => $user->id,
            'num'                   => $ct->num('sales', $user->id),
            'created_at'            => Carbon::now()->subDays(1),
            'address_id'            => 1,
            'sale_type_id'          => 1,
            'client_id'             => 1,
            'save_current_acount'   => 1,
        ]);
        SaleHelper::attachProperies($sale, $this->setRequest($sale));

        $pago = CurrentAcount::create([
            'detalle'                           => 'Pago N°4',
            'haber'                             => 110,
            'description'                       => null,
            'status'                            => 'pago_from_client',
            'user_id'                           => $user->id,
            'num_receipt'                       => 4,
            'client_id'                         => 1,
            'created_at'                        => Carbon::now()->subDays(1)->addHours(1),
        ]);
        $pago->saldo = CurrentAcountHelper::getSaldo('client', 1, $pago) - $pago->haber;
        $pago->save();
        $pago_helper = new CurrentAcountPagoHelper('client', 1, $pago);
        $pago_helper->init();
        CurrentAcountHelper::updateModelSaldo($pago, 'client', 1);

        for ($i=0; $i < 10; $i++) { 
            $sale = Sale::create([
                'user_id'               => $user->id,
                'num'                   => $ct->num('sales', $user->id),
                'address_id'            => 1,
                'sale_type_id'          => 1,
                'client_id'             => 1,
                'save_current_acount'   => 1,
            ]);
            SaleHelper::attachProperies($sale, $this->setRequest($sale));
        }
    }

    function checks() {
        $request = new \stdClass();
        $request->current_acount_payment_methods = [
            'current_acount_payment_method_id'  => 1,
            'amount'                        => 100,
            'bank'                          => 'BERSA',
            'payment_date'                  => null,
            'num'                           => '123123',
            'credit_card_id'                => null,
            'credit_card_payment_plan_id'   => null,
        ];
        return $request;
    }

    function videos() {
        $user = User::where('company_name', 'Autopartes Boxes')->first();
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
        $request->metodos_de_pago_seleccionados = [];
        $request->client_id = $sale->id;
        $articles = Article::take(7)
                            ->get();
        foreach ($articles as $article) {
            $_article = [
                'id'            => $article->id,
                'is_article'    => true,
                'amount'        => 1,
                // 'amount'        => rand(1,7),
                'cost'          => $article->cost,
                'name'          => $article->name,
                'price_vender'  => 1000 * $this->multiplo_price,
                // 'price_vender'  => $article->final_price,
            ];
            $request->items[] = $_article; 
        }

        $alAzar = rand(2,4);

        for ($i= 0; $i < $alAzar ; $i++) { 
            
            $precioTotal = 0;
           
            foreach ($request->items as $item) {

                $precioTotal += $item['amount'] * $item['price_vender'];
            };

            $_metodosDePagoAlAzar =[
                    'id'        => rand(1,5),
                    'monto'    => $precioTotal / $alAzar,
            ];
           
           $request->metodos_de_pago_seleccionados[] = $_metodosDePagoAlAzar;
        };


        return $request;
    }
}
