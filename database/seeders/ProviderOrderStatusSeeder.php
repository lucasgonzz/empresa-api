<?php

namespace Database\Seeders;

use App\Models\ProviderOrderStatus;
use Illuminate\Database\Seeder;

class ProviderOrderStatusSeeder extends Seeder
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
                'name' => 'En proceso'
            ],
            [
                'name' => 'Recibido'
            ],
        ];
        foreach ($models as $model) {
            ProviderOrderStatus::create([
                'name' => $model['name']
            ]);
        }
    }
}
