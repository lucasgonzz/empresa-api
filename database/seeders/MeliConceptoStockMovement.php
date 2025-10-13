<?php

namespace Database\Seeders;

use App\Models\ConceptoStockMovement;
use Illuminate\Database\Seeder;

class MeliConceptoStockMovement extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        ConceptoStockMovement::create([
            'name'  => 'Mercado Libre',
        ]);
    }
}
