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
    	$user = User::where('company_name', 'Autopartes Boxes')->first();
        $models = [
            [
                'name'  => 'Juguetes',
                'user_id'   => $user->id,
            ],
            [
                'name'  => 'Varios',
                'user_id'   => $user->id,
            ],
        ];

        foreach ($models as $model) {
            SaleType::create($model);
        }
    }
}
