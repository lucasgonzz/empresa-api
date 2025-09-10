<?php

namespace Database\Seeders;

use App\Models\Moneda;
use Illuminate\Database\Seeder;

class MonedaSeeder extends Seeder
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
                'name'  => 'Peso',
            ],
            [
                'name'  => 'Dolar',
            ],
        ];

        foreach ($models as $model) {
            
            Moneda::create($model);
        }
    }
}
