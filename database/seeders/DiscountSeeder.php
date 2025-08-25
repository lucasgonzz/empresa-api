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
            'percentage' => 10,
            'user_id'    => env('USER_ID'),
        ]);
        Discount::create([
            'num'        => 2,
            'name'       => 'Debito',
            'percentage' => 5,
            'user_id'    => env('USER_ID'),
        ]);
    }
}
