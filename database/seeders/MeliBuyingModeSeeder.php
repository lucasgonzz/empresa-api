<?php

namespace Database\Seeders;

use App\Models\MeliBuyingMode;
use Illuminate\Database\Seeder;

class MeliBuyingModeSeeder extends Seeder
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
                "meli_id"        => "buy_it_now",
                "name"          => "Compre Ya (buy_it_now)"
            ],
        ];

        foreach ($models as $model) {
            MeliBuyingMode::create($model);
        }
    }
}
