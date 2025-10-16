<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SaleReportesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        SaleSeederHelper::create_sales($this->ventas_desde_principio_de_mes());
        
        SaleSeederHelper::create_sales($this->ventas_meses_anterioires());
    }


    function ventas_desde_principio_de_mes() {
        $ventas_desde_principio_de_mes = [];
        $num = 1000;
        for ($dia_atras = 4; $dia_atras >= 0 ; $dia_atras--) { 

            for ($employee_id=501; $employee_id <= 504; $employee_id++) { 

                if ($employee_id == 501) {

                    $price_vender = 1000;
                    $address_id = 1;
                } else if ($employee_id == 502) {

                    $price_vender = 1500;
                    $address_id = 2;
                } else if ($employee_id == 503) {

                    $price_vender = 2000;
                    $address_id = 3;
                } else if ($employee_id == 504) {

                    $price_vender = 2500;
                    $address_id = 4;
                }

                $amount = 1;
                $total = $price_vender * $amount;

                $ventas_desde_principio_de_mes[] = [
                    'num'               => $num,
                    'total'             => $total,
                    'employee_id'       => $employee_id,
                    'address_id'        => $address_id,
                    'client_id'         => $address_id < 3 ? 1 : null,
                    'moneda_id'         => 1,
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
                    'created_at'    => Carbon::now()->now()->subDays($dia_atras),
                ];
                $num++;
            }
            return $ventas_desde_principio_de_mes;
        }
    }

    function ventas_meses_anterioires() {
        $ventas_meses_anterioires = [];

        /*
            Hace 4 meses
                1 dia = 1000 * 4 = 4.000
                2 dia = 1000 * 4 = 4.000
                3 dia = 1000 * 4 = 4.000
                4 dia = 1000 * 4 = 4.000

                Total vendido del mes = 16.000

            Hace 3 meses
                1 dia = 1000 * 3 = 3.000
                2 dia = 1000 * 3 = 3.000
                3 dia = 1000 * 3 = 3.000
                4 dia = 1000 * 3 = 3.000

                Total vendido del mes = 12.000

            Hace 2 meses
                1 dia = 1000 * 2 = 2.000
                2 dia = 1000 * 2 = 2.000
                3 dia = 1000 * 2 = 2.000
                4 dia = 1000 * 2 = 2.000

                Total vendido del mes = 8.000

            Hace 1 meses
                1 dia = 1000 * 1 = 1.000
                2 dia = 1000 * 1 = 1.000
                3 dia = 1000 * 1 = 1.000
                4 dia = 1000 * 1 = 1.000

                Total vendido del mes = 4.000
        */

        $num = 1;
        for ($mes=13; $mes >= 1; $mes--) { 

            for ($dia_del_mes = 1; $dia_del_mes <= 4 ; $dia_del_mes++) { 

                $price_vender = 1000;
                $amount = $mes;
                $total = $price_vender * $amount;

                $ventas_meses_anterioires[] = [
                    'num'               => $num,
                    'total'             => $total,
                    'employee_id'       => 504,
                    'address_id'        => 2,
                    'client_id'         => null,
                    'moneda_id'         => 1,
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
                            'amount'    => $total / 3,
                        ],
                        [
                            'id'        => rand(2,5),
                            'amount'    => ($total / 3) * 2,
                        ],
                    ],
                    'created_at'    => Carbon::now()->startOfMonth()->subMonths($mes)->addDays($dia_del_mes),
                ];
                $num++;
            }
        }
        return $ventas_meses_anterioires;
    }
}
