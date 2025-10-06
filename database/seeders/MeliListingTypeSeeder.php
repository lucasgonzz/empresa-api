<?php

namespace Database\Seeders;

use App\Models\MeliListingType;
use Illuminate\Database\Seeder;

class MeliListingTypeSeeder extends Seeder
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
                "meli_id"        => "gold_pro",
                "name"      => "Premium"
            ],
            [
                "meli_id"        => "gold_special",
                "name"      => "Clásica"
            ],
            [
                "meli_id"        => "free",
                "name"      => "Gratuita"
            ]
        ];

        foreach ($models as $model) {
            MeliListingType::create($model);
        }
    }
}
