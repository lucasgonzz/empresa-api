<?php

namespace Database\Seeders;

use App\Models\OrderStatus;
use Illuminate\Database\Seeder;

class OrderStatusSeeder extends Seeder
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
            [
                'name' => 'Terminado',
            ],
            [
                'name' => 'Entregado',
            ],
            [
                'name' => 'Cancelado',
            ],
        ];
        foreach ($models as $model) {
            OrderStatus::create([
                'name' => $model['name']
            ]);
        }
    }
}
