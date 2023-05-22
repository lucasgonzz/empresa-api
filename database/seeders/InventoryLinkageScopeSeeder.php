<?php

namespace Database\Seeders;

use App\Models\InventoryLinkageScope;
use Illuminate\Database\Seeder;

class InventoryLinkageScopeSeeder extends Seeder
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
                'name'  => 'Todos los articulos',
            ],
        ];
        foreach ($models as $model) {
            InventoryLinkageScope::create($model);
        }
    }
}
