<?php

namespace Database\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\RoadMap;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class RoadMapSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $models = [
            // Fecha de entrega Ayer
                // Repartidor: Repartidor
                [
                    'fecha_entrega' => Carbon::now()->subDays(1),
                    'employee_id'   => 502,
                    'sales'         => [1,2,3,4,5,6,7,8,9,10],
                    // 'sales'         => [1,2,3,4],
                ],
                // Repartidor: Vendedor y Repartidor
                [
                    'fecha_entrega' => Carbon::now()->subDays(1),
                    'employee_id'   => 503,
                    'sales'         => [5,6],
                ],

            // Fecha de entrega Hoy
                // Repartidor: Repartidor
                [
                    'fecha_entrega' => Carbon::now(),
                    'employee_id'   => 502,
                    'sales'         => [7,8],
                ],
                // Repartidor: Vendedor y Repartidor
                [
                    'fecha_entrega' => Carbon::now(),
                    'employee_id'   => 503,
                    'sales'         => [9,10],
                ],

            // Fecha de entrega Mañana
                // Repartidor: Repartidor
                [
                    'fecha_entrega' => Carbon::now()->addDays(1),
                    'employee_id'   => 502,
                    'sales'         => [11,12],
                ],
                // Repartidor: Vendedor y Repartidor
                [
                    'fecha_entrega' => Carbon::now()->addDays(1),
                    'employee_id'   => 503,
                    'sales'         => [13,14],
                ],
        ];

        $num = 1;
        foreach ($models as $model) {
            $road_map = RoadMap::create([
                'num'           => $num,
                'fecha_entrega' => $model['fecha_entrega'],
                'employee_id'   => $model['employee_id'],
                'user_id'       => config('app.USER_ID'),
            ]);
            
            $num++;

            foreach ($model['sales'] as $sale_id) {
                $road_map->sales()->attach($sale_id);
            }

        }
    }
}
