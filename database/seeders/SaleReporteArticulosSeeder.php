<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SaleReporteArticulosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        
        SaleSeederHelper::create_sales($this->ventas_meses_anterioires());

    }

    function ventas_meses_anterioires() {
        $ventas_meses_anterioires = [];
        $num = 1;
        for ($mes=9; $mes >= 1; $mes--) { 


            $price_vender = 1000;
            $amount = $mes;
            $total = $price_vender * $amount;

            $ventas_meses_anterioires[] = [
                'num'               => $num,
                'total'             => $total,
                'employee_id'       => 504,
                'address_id'        => 2,
                'client_id'         => $this->get_client($mes),
                'articles'          => [
                    [
                        'id'            => 1,
                        'price_vender'  => $price_vender,
                        'cost'          => $price_vender / 2,
                        'amount'        => $amount,
                    ],
                    [
                        'id'            => 2,
                        'price_vender'  => $price_vender,
                        'cost'          => $price_vender / 2,
                        'amount'        => $amount,
                    ],
                    [
                        'id'            => 3,
                        'price_vender'  => $price_vender,
                        'cost'          => $price_vender / 2,
                        'amount'        => $amount,
                    ],
                    [
                        'id'            => 4,
                        'price_vender'  => $price_vender,
                        'cost'          => $price_vender / 2,
                        'amount'        => $amount,
                    ],
                    [
                        'id'            => 5,
                        'price_vender'  => $price_vender,
                        'cost'          => $price_vender / 2,
                        'amount'        => $amount,
                    ],
                ],
                'payment_methods'   => [
                    [
                        'id'        => rand(1,2),
                        'amount'    => $total / 3,
                    ],
                    [
                        'id'        => rand(2,5),
                        'amount'    => ($total / 3) * 2,
                    ],
                ],
                'created_at'    => Carbon::now()->startOfMonth()->subMonths($mes),
            ];
            $num++;
        }

        return $ventas_meses_anterioires;

    }


    function get_client($mes) {
        if ($mes == 9 || $mes == 6 || $mes == 3) {
            return 1;
        }

        if ($mes == 8 || $mes == 5 || $mes == 2) {
            return 2;
        }

        if ($mes == 7 || $mes == 4 || $mes == 1) {
            return 3;
        }

    }
}
