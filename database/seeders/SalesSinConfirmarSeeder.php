<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use App\Models\Sale;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SalesSinConfirmarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $data = [
            'num'               => 999999,
            'address_id'        => 1,
            'employee_id'       => null,
            'client_id'         => 1,
            'created_at'        => Carbon::now()->subMonths(1)->endOfMonth(),
            'user_id'           => env('USER_ID'),
            'terminada'         => 0,
            'confirmed'         => 1,
            'fecha_entrega'     => Carbon::today()->addDays(2),
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

        SaleHelper::attachProperies($created_sale, SaleSeederHelper::setRequest($sale));
    }
}
