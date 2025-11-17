<?php

namespace Database\Seeders;

use App\Models\SaleChannel;
use Illuminate\Database\Seeder;

class SaleChannelSeeder extends Seeder
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
                'name'  => 'Sistema',
                'slug'  => 'sistema',
            ],
            [
                'name'  => 'E-commerce',
                'slug'  => 'ecommerce',
            ],
            [
                'name'  => 'Mercado Libre',
                'slug'  => 'mercado_libre',
            ],
            [
                'name'  => 'Tienda Nube',
                'slug'  => 'tienda_nube',
            ],
        ];

        foreach ($models as $model) {
            
            SaleChannel::create($model);
        }
    }
}
