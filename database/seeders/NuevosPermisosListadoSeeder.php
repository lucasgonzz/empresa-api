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


        /*
            `firstOrCreate` y no `create` (misión 63, siembra-local-igual-a-demo). Los cuatro
            slugs de arriba ya los siembra `PermissionSeeder`, así que este seeder dejó de
            llamarse desde `DatabaseSeeder::common_seeders()`: ver el comentario que quedó en su
            lugar. Sigue existiendo para las bases de producción viejas, creadas antes de que
            `PermissionSeeder` los incluyera, y ahí es donde importa que sea idempotente --
            `permission_empresas.slug` no tiene índice único, así que con `create()` correrlo dos
            veces (o correrlo sobre una base ya al día) dejaba el permiso repetido en la pantalla
            de empleados.
        */
        foreach ($models as $model) {
            PermissionEmpresa::firstOrCreate(
                ['slug' => $model['slug']],
                [
                    'name'       => $model['name'],
                    'model_name' => $model['model_name'],
                ]
            );
        }
    }
}
