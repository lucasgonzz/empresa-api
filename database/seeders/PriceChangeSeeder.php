<?php

namespace Database\Seeders;

use App\Models\PriceChange;
use Illuminate\Database\Seeder;

class PriceChangeSeeder extends Seeder
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
                'article_id'    => 16,
                'cost'          => 120,
                'price'         => 200,
                'final_price'   => 200,
                'employee_id'   => 1,
            ],
            [
                'article_id'    => 16,
                'cost'          => 160,
                'price'         => 260,
                'final_price'   => 260,
                'employee_id'   => 7,
            ],
            [
                'article_id'    => 16,
                'cost'          => 200,
                'price'         => 310,
                'final_price'   => 310,
                'employee_id'   => 7,
            ],
            [
                'article_id'    => 16,
                'cost'          => 260,
                'price'         => 400,
                'final_price'   => 400,
                'employee_id'   => 8,
            ],
        ];
        foreach ($models as $model) {
            PriceChange::create([
                'article_id'    => $model['article_id'],
                'cost'          => $model['cost'],
                'price'         => $model['price'],
                'final_price'   => $model['final_price'],
                'employee_id'   => $model['employee_id'],
            ]);
        }
    }
}
