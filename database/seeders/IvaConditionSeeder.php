<?php

namespace Database\Seeders;

use App\Models\IvaCondition;
use Illuminate\Database\Seeder;

class IvaConditionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $ivas = ['Responsable inscripto', 'Monotributista', 'Consumidor final', 'Exento'];
        foreach ($ivas as $iva) {
            IvaCondition::create([
                'name' => $iva,
            ]);
        }
    }
}
