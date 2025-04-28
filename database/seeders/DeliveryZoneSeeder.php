<?php

namespace Database\Seeders;

use App\Models\DeliveryZone;
use App\Models\User;
use Illuminate\Database\Seeder;

class DeliveryZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (env('FOR_USER') == 'pinocho') {
            
            $models = [
                [
                    'name' => 'Rosario',
                    'description' => 'Rosario y alrededores',
                    'price' => 500,
                ],
                [
                    'name' => 'Todo el Pais',
                    'description' => null,
                    'price' => 800,
                ],
            ];
            foreach ($models as $model) {
                DeliveryZone::create([
                    'name'          => $model['name'],
                    'description'   => $model['description'],
                    'price'         => $model['price'],
                    'user_id'       => env('USER_ID'),
                ]);
            }
        }
    }
}
