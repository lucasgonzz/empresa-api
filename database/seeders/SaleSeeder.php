<?php

namespace Database\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CurrentAcountAndCommissionHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Address;
use App\Models\Article;
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

    function pagos() {
        $user = User::where('company_name', 'lucas')->first();
        $addresses = Address::where('user_id', $user->id)
                        ->get();
        $employees = User::where('owner_id', $user->id)
                        ->get();
        $ct = new Controller();
        for ($day=3; $day >= 0; $day--) { 
            $minutes = 100;
            foreach ($employees as $employee) {
                $sale = Sale::create([
                    'user_id'           => $user->id,
                    'num'               => $ct->num('sales', $user->id),
                    'created_at'        => Carbon::now()->subDays($day)->subMinutes($minutes),
                    'employee_id'       => $employee->id,
                    'address_id'        => count($addresses) >= 1 ? $addresses[0]->id : null,
                    'sale_type_id'      => 1,
                    'client_id'         => 1,
                    'save_current_acount'   => 1,
                ]);
                $minutes -= 10;
                // $articles = Article::where('user_id', $user->id)
                //                     ->get();

                SaleHelper::attachProperies($sale, $this->setRequest($sale));
                // foreach ($articles as $article) {
                //     $sale->articles()->attach($article->id, [
                //                                 'amount'      => rand(1, 10),
                //                                 'cost'        => $article->cost,
                //                                 'price'       => $article->final_price,
                //                             ]);
                // }
                // $discounts = GeneralHelper::getModelsFromId('Discount', []);
                // $surchages = GeneralHelper::getModelsFromId('Surchage', []);
                // $helper = new CurrentAcountAndCommissionHelper($sale, $discounts, $surchages);
                // $helper->attachCommissionsAndCurrentAcounts();
            }
        }
    }

    function setRequest($sale) {
        $request = new \stdClass();
        $request->items = [];
        $request->discounts_id = [];
        $request->surchages_id = [];
        $request->client_id = $sale->id;
        $articles = Article::where('user_id', $sale->user_id)
                            ->get();
        foreach ($articles as $article) {
            $_article = [
                'id'            => $article->id,
                'is_article'    => true,
                'amount'        => rand(1,10),
                'cost'          => $article->cost,
                'price_vender'  => $article->final_price,
            ];
            $request->items[] = $_article; 
        }
        return $request;
    }
}
