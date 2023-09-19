<?php

namespace Database\Seeders;

use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

class PlanFeatureSeeder extends Seeder
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
                'name'  => 'Sucursales/depósitos'
            ],
            [
                'name'  => 'Ventas por mes'
            ],
            [
                'name'  => 'Facturación '
            ],
            [
                'name'  => 'E-commerce propio',
                'description'   => 'sus clientes podrán acceder al e-commerce con sus propias cuentas, las cuales se podrán vincular a su correspondiente perfil cliente de ComercioCity, esto significa que luego de que el cliente haga su pedido desde la tienda, se generará el correspondiente movimiento en su cuenta corriente',
            ],
            [
                'name'  => 'Módulo de producción '
            ],
            [
                'name'  => 'Clientes y c/corriente'
            ],
            [
                'name'  => 'Presupuestos '
            ],
            [
                'name'  => 'Proveedores y c/corriente'
            ],
            [
                'name'  => 'Pedidos a proveedores '
            ],
            [
                'name'  => 'Actualización de ventas'
            ],
            [
                'name'  => 'Copias de seguridad diarias'
            ],
            [
                'name'  => 'Stock por sucursales'
            ],
            [
                'name'  => 'Estadísticas y métricas'
            ],
            [
                'name'  => 'Listas de precios',
                'description'   => 'Cuando se indique el cliente en una venta, los precios de la misma se calcularán en base a la lista de precios del cliente, lo mismo para cuando ese  cliente acceda al e-commerce desde su cuenta',
            ],
            [
                'name'  => 'Empleados con permisos'
            ],
            [
                'name'  => 'Aplicación móvil'
            ],
            [
                'name'  => 'Fotos automáticas',
                'description' => 'Sacadas desde Google',
            ],
            [
                'name'  => 'Ayuda con la integración '
            ],
            [
                'name'  => 'Soporte y atención al cliente'
            ],
            [
                'name'  => 'Cierre de caja'
            ],
            [
                'name'  => 'Compatibilidad con Excel'
            ],
        ];  
        foreach ($models as $model) {
            PlanFeature::create($model);
        }
    }
}
