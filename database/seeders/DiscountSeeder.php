<?php

namespace Database\Seeders;

use App\Models\Discount;
use App\Models\User;
use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        Discount::create([
            'num'        => 1,
            'name'       => 'Efectivo',
            'percentage' => 50,
            'user_id'    => env('USER_ID'),
        ]);
        Discount::create([
            'num'        => 2,
            'name'       => 'Contado',
            'percentage' => 12,
            'user_id'    => env('USER_ID'),
        ]);
        Discount::create([
            'num'        => 3,
            'name'       => 'Placas',
            'percentage' => 5,
            'user_id'    => env('USER_ID'),
        ]);
    }
}
