<?php

namespace Database\Seeders;

use App\Models\ProviderPriceList;
use Illuminate\Database\Seeder;

class ProviderPriceListSeeder extends Seeder
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
                'num'           => 1,
                'name'          => 'Lista 1',
                'percentage'    => 10,
                'provider_id'   => 1,     
            ],
            [
                'num'           => 2,
                'name'          => 'Lista 2',
                'percentage'    => 100,
                'provider_id'   => 1,     
            ],
        ];
        foreach ($models as $model) {
            ProviderPriceList::create($model);
        }
    }
}
