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
        $this->pagos();
    }

    function pagos() {
        $user = User::where('company_name', 'lucas')->first();
        $addresses = Address::where('user_id', $user->id)
                        ->get();
        $ct = new Controller();
        $sale = Sale::create([
            'user_id'               => $user->id,
            'num'                   => $ct->num('sales', $user->id),
            'created_at'            => Carbon::now()->subDays(3),
            'address_id'            => 1,
            'sale_type_id'          => 1,
            'client_id'             => 1,
            'save_current_acount'   => 1,
        ]);
        SaleHelper::attachProperies($sale, $this->setRequest($sale));

        $sale = Sale::create([
            'user_id'               => $user->id,
            'num'                   => $ct->num('sales', $user->id),
            'created_at'            => Carbon::now()->subDays(2),
            'address_id'            => 1,
            'sale_type_id'          => 1,
            'client_id'             => 1,
            'save_current_acount'   => 1,
        ]);
        SaleHelper::attachProperies($sale, $this->setRequest($sale));

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
    }

    function videos() {
        $user = User::where('company_name', 'lucas')->first();
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
        $request->discounts_id = [];
        $request->surchages_id = [];
        $request->client_id = $sale->id;
        $articles = Article::where('id', 1)
                            ->get();
        foreach ($articles as $article) {
            $_article = [
                'id'            => $article->id,
                'is_article'    => true,
                'amount'        => 1,
                'cost'          => $article->cost,
                'price_vender'  => $article->final_price,
            ];
            $request->items[] = $_article; 
        }
        return $request;
    }
}
