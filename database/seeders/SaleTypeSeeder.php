<?php

namespace Database\Seeders;

use App\Models\SaleType;
use App\Models\User;
use Illuminate\Database\Seeder;

class SaleTypeSeeder extends Seeder
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
                'name'  => 'Juguetes',
                'user_id'   => config('app.USER_ID'),
            ],
            [
                'name'  => 'Varios',
                'user_id'   => config('app.USER_ID'),
            ],
        ];

        foreach ($models as $model) {
            SaleType::create($model);
        }
    }
}
