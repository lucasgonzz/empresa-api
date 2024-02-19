<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Models\InventoryLinkage;
use Illuminate\Database\Seeder;

class InventoryLinkageSeeder extends Seeder
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
                'client_id'                     => 2,
                'user_id'                       => 500,
                'inventory_linkage_scope_id'    => 1,
            ],
        ];
        foreach ($models as $model) {
            $linkage = InventoryLinkage::create($model);

            $inventory_linkage_helper = new InventoryLinkageHelper($linkage);
            $inventory_linkage_helper->setClientArticles();
        }
    }
}
