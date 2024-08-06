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
            [
                'singular'      => 'gasto',
                'plural'        => 'gastos',
                'en'            => 'expense',
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
                'singular'      => 'Ver la CAJA',
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
            [
                'singular'      => 'Crear un articulo no ingresado en VENDER',
                'plural'        => 'Vender',
                'en'            => 'vender.create_article',
            ],
            [
                'singular'      => 'Aplicar descuentos a los articulos en Vender',
                'plural'        => 'Vender',
                'en'            => 'vender.article_discount',
            ],

            [
                'singular'      => 'Utilizar modulo ABM',
                'plural'        => 'Modulo ABM',
                'en'            => 'abm',
            ],
            [
                'singular'      => 'Utilizar modulo de DEPOSITO VENTAS para checkear',
                'plural'        => 'Deposito para checkear',
                'en'            => 'deposito_para_checkear',
            ],
            [
                'singular'      => 'Utilizar modulo de DEPOSITO VENTAS checkeadas',
                'plural'        => 'Deposito checkeadas',
                'en'            => 'deposito_checkeadas',
            ],
            [
                'singular'      => 'Ver REPORTES',
                'plural'        => 'Estadisticas',
                'en'            => 'reportes',
            ],


            // Permisos de alertas
            [
                'singular'      => 'Ver alertas de Pedidos a proveedor',
                'plural'        => 'Alertas',
                'en'            => 'alerts.provider_orders',
            ],
            [
                'singular'      => 'Ver alertas de Pedidos de la tienda',
                'plural'        => 'Alertas',
                'en'            => 'alerts.orders',
            ],
            [
                'singular'      => 'Ver alertas de Mensajes de la tienda',
                'plural'        => 'Alertas',
                'en'            => 'alerts.messages',
            ],


            // Reportes

            [
                'singular'      => 'Acceder a reportes',
                'plural'        => 'Reportes',
                'en'            => 'reportes.index',
            ],
            [
                'singular'      => 'Ver la informacion general (tarjetas)',
                'plural'        => 'Reportes',
                'en'            => 'reportes.cards',
            ],
            [
                'singular'      => 'Ver Ingresos de la empresa',
                'plural'        => 'Reportes',
                'en'            => 'reportes.ingresos',
            ],

            [
                'singular'      => 'Ver ventas por sucursales',
                'plural'        => 'Reportes',
                'en'            => 'reportes.sucursales.index',
            ],
            [
                'singular'      => 'Ver ventas de todas las sucursales',
                'plural'        => 'Reportes',
                'en'            => 'reportes.sucursales.index.all',
            ],
            [
                'singular'      => 'Ver ventas solo de su sucursal',
                'plural'        => 'Reportes',
                'en'            => 'reportes.sucursales.index.only_your',
            ],

            [
                'singular'      => 'Ver ventas por empleados',
                'plural'        => 'Reportes',
                'en'            => 'reportes.empleados.index',
            ],
            [
                'singular'      => 'Ver ventas de todos los empleados',
                'plural'        => 'Reportes',
                'en'            => 'reportes.empleados.index.all',
            ],
            [
                'singular'      => 'Ver solo sus ventas',
                'plural'        => 'Reportes',
                'en'            => 'reportes.empleados.index.only_your',
            ],

            // Gastos
            [
                'singular'      => 'Ver Gastos',
                'plural'        => 'Reportes',
                'en'            => 'reportes.gastos',
            ],

            // Clientes
            [
                'singular'      => 'Ver Clientes',
                'plural'        => 'Reportes',
                'en'            => 'reportes.clientes',
            ],

            // Cheques
            [
                'singular'      => 'Ver Cheques',
                'plural'        => 'Reportes',
                'en'            => 'reportes.cheques',
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
