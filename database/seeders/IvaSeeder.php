<?php

namespace Database\Seeders;

use App\Models\Iva;
use Illuminate\Database\Seeder;

class IvaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $percetages = ['27', '21', '10.5', '5', '2.5', '0', 'Exento', 'No Gravado', '50'];
        foreach ($percetages as $percetage) {
            Iva::create([
                'percentage' => $percetage,
            ]);
        }
    }
}
