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
    	$mayorista = User::where('company_name', 'lucas')->first();
        $user_id = $mayorista->id;
        SaleType::create([
        	'name' => 'Normal',
        	'user_id' => $user_id,
        ]);
        SaleType::create([
        	'name' => 'Varios',
        	'user_id' => $user_id,
        ]);
    }
}
