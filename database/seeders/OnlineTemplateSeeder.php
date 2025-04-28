<?php

namespace Database\Seeders;

use App\Models\OnlineTemplate;
use Illuminate\Database\Seeder;

class OnlineTemplateSeeder extends Seeder
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
                'name'  => 'Moderno',
                'slug'  => 'moderno',
            ],
            [
                'name'  => 'Clasico',
                'slug'  => 'clasico',
            ],
        ];

        foreach ($models as $model) {
            
            OnlineTemplate::create($model);
        }
    }
}
