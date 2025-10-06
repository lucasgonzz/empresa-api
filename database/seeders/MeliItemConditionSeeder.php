<?php

namespace Database\Seeders;

use App\Models\MeliItemCondition;
use Illuminate\Database\Seeder;

class MeliItemConditionSeeder extends Seeder
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
                "meli_id"        => "new",
                // "meli_id"        => "2230284",
                "name"          => "Nuevo"
            ],
            [
                "meli_id"        => "used",
                // "meli_id"        => "2230581",
                "name"          => "Usado"
            ],
            [
                // "meli_id"        => "2230582",
                "meli_id"        => "reconditioned",
                "name"          => "Reacondicionado"
            ],
        ];

        foreach ($models as $model) {
            MeliItemCondition::create($model);
        }
    }
}
