<?php

namespace Database\Seeders;

use App\Models\TiendaNubeOrderStatus;
use Illuminate\Database\Seeder;

class TiendaNubeOrderStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'name' => 'Sin confirmar',
            ],
            [
                'name' => 'Confirmado',
            ],
        ];
        foreach ($models as $model) {
            TiendaNubeOrderStatus::create([
                'name' => $model['name']
            ]);
        }
    }
}
