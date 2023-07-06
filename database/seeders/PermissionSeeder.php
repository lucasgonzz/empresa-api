<?php

namespace Database\Seeders;

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
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
                'singular'      => 'venta',
                'plural'        => 'ventas',
                'en'            => 'sale',
                'previus_days'  => true,
            ],
            [
                'singular'      => 'articulo',
                'plural'        => 'articulos',
                'en'            => 'article',
                'excel'         => true,
            ],
            [
                'singular'      => 'cliente',
                'plural'        => 'clientes',
                'en'            => 'client',
                'excel'         => true,
            ],
            [
                'singular'      => 'proveedor',
                'plural'        => 'proveedores',
                'en'            => 'provider',
                'excel'         => true,
            ],
            [
                'singular'      => 'pedido a proveedor',
                'plural'        => 'pedidos a proveedor',
                'en'            => 'provider_order',
                'previus_days'  => true,
            ],
            [
                'singular'      => 'pedido online',
                'plural'        => 'pedidos online',
                'en'            => 'order',
                'previus_days'  => true,
            ],
            [
                'singular'      => 'cliente online',
                'plural'        => 'clientes online',
                'en'            => 'buyer',
            ],
            [
                'singular'      => 'presupuesto',
                'plural'        => 'presupuesto',
                'en'            => 'budget',
            ],
            [
                'singular'      => 'orden de produccion',
                'plural'        => 'ordenes de produccion',
                'en'            => 'order_production',
            ],
            [
                'singular'      => 'movimiento de produccion',
                'plural'        => 'movimientos de produccion',
                'en'            => 'production_movement',
            ],
            [
                'singular'      => 'receta',
                'plural'        => 'recetas',
                'en'            => 'recipe',
            ],
        ];
        $scopes = [
            [
                'text'  => 'Listar',
                'slug'  => 'index',
            ],
            [
                'text'  => 'Crear',
                'slug'  => 'store',
            ],
            [
                'text'  => 'Actualizar',
                'slug'  => 'update',
            ],
            [
                'text'  => 'Eliminar',
                'slug'  => 'delete',
            ],
        ];
        foreach ($models as $model) {
            foreach ($scopes as $scope) {
                PermissionEmpresa::create([
                    'model_name'    => $model['plural'],
                    'name'          => $scope['text'].' '.$model['plural'],
                    'slug'          => $model['en'].'.'.$scope['slug'],
                ]);
            }
            if (isset($model['excel'])) {
                PermissionEmpresa::create([
                    'model_name'    => $model['plural'],
                    'name'          => 'Importar Excel con '.$model['plural'],
                    'slug'          => $model['en'].'.excel.import',
                ]);
                PermissionEmpresa::create([
                    'model_name'    => $model['plural'],
                    'name'          => 'Exportar Excel con '.$model['plural'],
                    'slug'          => $model['en'].'.excel.export',
                ]);
            }
            if (isset($model['previus_days'])) {
                PermissionEmpresa::create([
                    'model_name'    => $model['plural'],
                    'name'          => 'Ver '.$model['plural'].' de cualquier fecha',
                    'slug'          => $model['en'].'.index.previus_days',
                ]);
            }
        }
        $permissions = [
            [
                'singular'      => 'Reportes',
                'plural'        => 'Estadisticas',
                'en'            => 'caja.reports',
            ],
            [
                'singular'      => 'Ver Estadisticas',
                'plural'        => 'Estadisticas',
                'en'            => 'caja.charts',
            ],
            [
                'singular'      => 'Cambiar el precio de los articulos en VENDER',
                'plural'        => 'Vender',
                'en'            => 'article.vender.change_price',
            ],
            [
                'singular'      => 'Cambiar el empleado en VENDER',
                'plural'        => 'Vender',
                'en'            => 'vender.change_employee',
            ],
        ];
        foreach ($permissions as $permission) {
            PermissionEmpresa::create([
                'model_name'    => $permission['plural'],
                'name'          => $permission['singular'],
                'slug'          => $permission['en'],
            ]);
        }
    }
}
