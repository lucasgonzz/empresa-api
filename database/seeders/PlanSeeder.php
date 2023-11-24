<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            
            // Oficial
            [
                'name'          => 'Basico',
                'price'         => 8,
                'user_price'    => 3, 
                'e_commerce'    => 4, 
                'official'      => 1,
                'features'      => [
                    [
                        'name'  => 'Sucursales/depósitos',
                        'value' => '1',
                    ],
                    [
                        'name'  => 'Ventas por mes',
                        'value' => '150',
                    ],
                    [
                        'name'  => 'Facturación ',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'E-commerce propio',
                        'value' => '$3000 x mes',
                    ],
                    [
                        'name'  => 'Módulo de producción ',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Clientes y c/corriente',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Presupuestos ',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Proveedores y c/corriente',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Pedidos a proveedores ',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Actualización de ventas',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Copias de seguridad diarias',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Stock por sucursales',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Estadísticas y métricas',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Listas de precios',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Empleados con permisos',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Aplicación móvil',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Fotos automáticas',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Ayuda con la integración ',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Soporte y atención al cliente',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Cierre de caja',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Compatibilidad con Excel',
                        'value' => 'No',
                    ],
                ],  
            ],
            [
                'name'      => 'Plus',
                'price'     => 13,
                'user_price'    => 3, 
                'e_commerce'    => 6, 
                'official'  => 1,
                'features'  => [
                    [
                        'name'  => 'Sucursales/depósitos',
                        'value' => '2',
                    ],
                    [
                        'name'  => 'Ventas por mes',
                        'value' => '300',
                    ],
                    [
                        'name'  => 'Facturación ',
                        'value' => 'Si',
                    ],
                    [
                        'name' => 'E-commerce propio',
                        'value' => '$5000 x mes',
                    ],
                    [
                        'name'  => 'Módulo de producción',
                        'value' => 'No',
                    ],
                    [
                        'name'  => 'Clientes y c/corriente',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Presupuestos ',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Proveedores y c/corriente',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Pedidos a proveedores ',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Actualización de ventas',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Copias de seguridad diarias',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Stock por sucursales',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Estadísticas y métricas',
                        'value' => 'Si',
                    ],
                    [
                        'name' => 'Listas de precios',
                        'value' => 'Si',
                    ],
                    [
                        'name' => 'Empleados con permisos',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Aplicación móvil',
                        'value' => 'Si',
                    ],
                    [
                        'name' => 'Fotos automáticas',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Ayuda con la integración',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Soporte y atención al cliente',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Compatibilidad con Excel',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Cierre de caja',
                        'value' => 'Si',
                    ],
                ],
            ],
            [
                'name'      => 'Premium',
                'price'     => 22,
                'user_price'    => 4, 
                'e_commerce'    => 8, 
                'official'  => 1,
                'features'  => [
                    [
                        'name'  => 'Sucursales/depósitos',
                        'value' => 'Ilimitado',
                    ],
                    [
                        'name'  => 'Ventas por mes',
                        'value' => 'Ilimitado',
                    ],
                    [
                        'name'  => 'Facturación ',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'E-commerce propio',
                        'value' => '$8000 x mes',
                    ],
                    [
                        'name'  => 'Módulo de producción',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Clientes y c/corriente',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Presupuestos ',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Proveedores y c/corriente',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Pedidos a proveedores ',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Actualización de ventas',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Copias de seguridad diarias',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Stock por sucursales',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Estadísticas y métricas',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Listas de precios',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Empleados con permisos',
                        // mostrar precio si es que tiene permiso 
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Aplicación móvil',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Fotos automáticas',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Ayuda con la integración',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Soporte y atención al cliente',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Compatibilidad con Excel',
                        'value' => 'Si',
                    ],
                    [
                        'name'  => 'Cierre de caja',
                        'value' => 'Si',
                    ],
                ],
            ],
        ];

        foreach ($models as $model) {
            $plan = Plan::create([
                'name'  => $model['name'],
                'price'  => $model['price'],
                'user_price'  => $model['user_price'],
                'e_commerce'  => $model['e_commerce'],
                'official'  => $model['official'],
            ]);
            foreach ($model['features'] as $feature) {
                $feature_id = PlanFeature::where('name', $feature['name'])
                                        ->first()->id;
                $plan->plan_features()->attach($feature_id, [
                    'value' => $feature['value'],
                ]); 
            }
        }
    }
}
