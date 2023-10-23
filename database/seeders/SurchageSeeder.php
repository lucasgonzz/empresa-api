<?php

namespace Database\Seeders;

use App\Models\Surchage;
use App\Models\User;
use Illuminate\Database\Seeder;

class SurchageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $users = User::where('company_name', 'Jugueteria Rosario')
                    ->get();

        foreach ($users as $user) {
            Surchage::create([
                'num'        => 1,
                'name'       => 'Iva 21',
                'percentage' => 21,
                'user_id'    => $user->id,
            ]);
            Surchage::create([
                'num'        => 2,
                'name'       => 'Envio',
                'percentage' => 50,
                'user_id'    => $user->id,
            ]);
        }
    }
}
