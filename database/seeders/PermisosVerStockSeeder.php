<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermisosVerStockSeeder extends Seeder
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
                'name'          => 'Modificar stock solo de su sucursal',
                'model_name'    => 'articulos',
                'slug'          => 'article.edit_stock_only_sucursal',
            ],
        ];


        foreach ($models as $model) {
            PermissionEmpresa::create($model);
        }
    }
}
