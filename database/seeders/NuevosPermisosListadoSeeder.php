<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class NuevosPermisosListadoSeeder extends Seeder
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
                'name'          => 'Ver margenes de ganancia',
                'model_name'    => 'articulos',
                'slug'          => 'article.percentage_gain',
            ],
            [
                'name'          => 'Ver proveedores',
                'model_name'    => 'articulos',
                'slug'          => 'article.provider',
            ],
            [
                'name'          => 'Ver stock solo de su sucursal',
                'model_name'    => 'articulos',
                'slug'          => 'article.stock_only_sucursal',
            ],
            [
                'name'          => 'Ver stocks minimos y maximos',
                'model_name'    => 'articulos',
                'slug'          => 'article.stock_min_max',
            ],
        ];


        foreach ($models as $model) {
            PermissionEmpresa::create($model);
        }
    }
}
