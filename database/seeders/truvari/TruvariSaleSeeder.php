<?php

namespace Database\Seeders\Truvari;

use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TruvariSaleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $ventas_con_fecha_de_entrega = [

            // Con fecha de entrega AYER
                // Para cliente LUCAS GONZALEZ
                [
                    'client_id'     => 1,
                    'employee_id'   => 504,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->subDays(1),
                ],
                [
                    'client_id'     => 1,
                    'employee_id'   => null,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->addDays(1),
                ],
                // Para cliente Marcos perez
                [
                    'client_id'     => 2,
                    'employee_id'   => 504,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->subDays(1),
                ],
                [
                    'client_id'     => 2,
                    'employee_id'   => null,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->addDays(1),
                ],
                // Para cliente Sabrina Herrero
                [
                    'client_id'     => 3,
                    'employee_id'   => 504,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->subDays(1),
                ],
                [
                    'client_id'     => 3,
                    'employee_id'   => null,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->addDays(1),
                ],

            // Con fecha de entrega Hoy
                // Para cliente LUCAS GONZALEZ
                [
                    'client_id'     => 1,
                    'employee_id'   => 504,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now(),
                ],
                [
                    'client_id'     => 1,
                    'employee_id'   => null,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now(),
                ],
                // Para cliente Marcos GONZALEZ
                [
                    'client_id'     => 2,
                    'employee_id'   => 504,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now(),
                ],
                [
                    'client_id'     => 2,
                    'employee_id'   => null,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now(),
                ],

            // Con fecha de entrega MAÑANA
                // Para cliente LUCAS GONZALEZ
                [
                    'client_id'     => 1,
                    'employee_id'   => 504,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now(),
                ],
                [
                    'client_id'     => 1,
                    'employee_id'   => null,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->addDays(1),
                ],
                // Para cliente Marcos GONZALEZ
                [
                    'client_id'     => 2,
                    'employee_id'   => 504,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->addDays(1),
                ],
                [
                    'client_id'     => 2,
                    'employee_id'   => null,
                    'created_at'    => Carbon::now()->subDays(4),
                    'fecha_entrega' => Carbon::now()->addDays(1),
                ],
        ];

        $num = 1;
        foreach ($ventas_con_fecha_de_entrega as &$venta) {

            $venta['num']           = $num;
            $venta['total']         = 120000;
            $venta['address_id']    = 2;
            $venta['terminada']     = 0;
            $venta['articles']      = [
                [
                    'id'                => 1,
                    'amount'            => 5,
                    'cost'              => 1000,
                    'price_vender'      => 12000,
                ],
                [
                    'id'                => 2,
                    'amount'            => 5,
                    'cost'              => 1000,
                    'price_vender'      => 12000,
                ],
            ];

            $num++;
        }


        SaleSeederHelper::create_sales($ventas_con_fecha_de_entrega);
    }
}
