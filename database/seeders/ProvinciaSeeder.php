<?php

namespace Database\Seeders;

use App\Models\Provincia;
use Illuminate\Database\Seeder;

class ProvinciaSeeder extends Seeder
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
                'name'    => 'Entre Rios',
                'user_id'   => env('USER_ID'),
            ],
            [
                'name'    => 'Santa Fe',
                'user_id'   => env('USER_ID'),
            ],
            [
                'name'    => 'Buenos Aires',
                'user_id'   => env('USER_ID'),
            ],
        ];
        foreach ($models as $model) {
            Provincia::create($model);
        }
    }
}
