<?php

namespace Database\Seeders;

use App\Models\Extencion;
use App\Models\Permission;
use App\Models\PermissionBeta;
use Illuminate\Database\Seeder;

class PermissionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        // $this->permissions();
        $this->permissionsBeta();

        
    }

    function permissionsBeta() {
        /*
        |--------------------------------------------------------------------------
        | Articulos
        |--------------------------------------------------------------------------
        */
        PermissionBeta::create([
            'name' => 'Ingresar y editar articulos',
            'slug' => 'article.store',
        ]);
        PermissionBeta::create([
            'name' => 'Ver articulos',
            'slug' => 'article.index',
        ]);
        PermissionBeta::create([
            'name' => 'Ver costos de los articulos',
            'slug' => 'article.cost',
        ]);
        PermissionBeta::create([
            'name' => 'Eliminar articulos',
            'slug' => 'article.delete',
        ]);
        /*
        |--------------------------------------------------------------------------
        | Ventas
        |--------------------------------------------------------------------------
        */
        PermissionBeta::create([
            'name' => 'Crear y editar ventas',
            'slug' => 'sale.store',
        ]);
        PermissionBeta::create([
            'name' => 'Ver ventas',
            'slug' => 'sale.index',
        ]);
        PermissionBeta::create([
            'name' => 'Ver ventas de cualquier fecha',
            'slug' => 'sale.index.all',
        ]);
        PermissionBeta::create([
            'name' => 'Eliminar ventas',
            'slug' => 'sale.delete',
        ]);
        Permission::create([
            'name' => 'Hacer Factura electronica',
            'slug' => 'afip_ticket',
        ]);
        /*
        |--------------------------------------------------------------------------
        | Clientes
        |--------------------------------------------------------------------------
        */
        PermissionBeta::create([
            'name' => 'Crear clientes',
            'slug' => 'client.store',
        ]);
        PermissionBeta::create([
            'name' => 'Ver clientes',
            'slug' => 'client.index',
        ]);
        PermissionBeta::create([
            'name' => 'Eliminar clientes',
            'slug' => 'client.delete',
        ]);
        /*
        |--------------------------------------------------------------------------
        | Empleados
        |--------------------------------------------------------------------------
        */
        PermissionBeta::create([
            'name' => 'Ver, crear y eliminar Empleados',
            'slug' => 'employee',
        ]);
        /*
        |--------------------------------------------------------------------------
        | Online
        |--------------------------------------------------------------------------
        */
        PermissionBeta::create([
            'name' => 'Ver pedidos en la Tienda Online',
            'slug' => 'online.orders',
        ]);
        PermissionBeta::create([
            'name' => 'Ver preguntas en la Tienda Online',
            'slug' => 'online.questions',
        ]);
        PermissionBeta::create([
            'name' => 'Ver clientes en la Tienda Online',
            'slug' => 'online.buyers',
        ]);
        PermissionBeta::create([
            'name' => 'Ver mensajes en la Tienda Online',
            'slug' => 'online.messages',
        ]);
        PermissionBeta::create([
            'name' => 'Ver cupones en la Tienda Online',
            'slug' => 'online.cupons',
        ]);
        PermissionBeta::create([
            'name' => 'Recibir ordenes de llamada en la Tienda Online',
            'slug' => 'online.calls',
        ]);
        /*
        |--------------------------------------------------------------------------
        | Caja
        |--------------------------------------------------------------------------
        */
        PermissionBeta::create([
            'name' => 'Ver CAJA',
            'slug' => 'caja',
        ]);
        /*
        |--------------------------------------------------------------------------
        | EXTENCIONES
        |--------------------------------------------------------------------------
        */
        /*
        |--------------------------------------------------------------------------
        | Ordenes de produccion
        |--------------------------------------------------------------------------
        */
        $order_productions_extencion = Extencion::where('slug', 'order_productions')->first();
        $permissions = [
            [
                'name' => 'Crear Ordenes de Produccion',
                'slug' => 'order_production.store',
            ],
            [
                'name' => 'Ver Ordenes de Produccion',
                'slug' => 'order_production.index',
            ],
            [
                'name' => 'Ver Precio de los articulos de las Ordenes de Produccion',
                'slug' => 'order_production.article.price',
            ],
            [
                'name' => 'Ver Recetas',
                'slug' => 'recipe.index',
            ],
            [
                'name' => 'Crear Recetas',
                'slug' => 'recipe.store',
            ],
            // [
            //     'name' => 'Ver precios de los artículos en las Ordenes de Produccion',
            //     'slug' => 'order_productions.articles.price',
            // ],
        ];
        foreach ($permissions as $permission) {
            PermissionBeta::create([
                'name'          => $permission['name'],
                'slug'          => $permission['slug'],
                'extencion_id'  => $order_productions_extencion->id,
            ]);
        }
        /*
        |--------------------------------------------------------------------------
        | Presupuestos
        |--------------------------------------------------------------------------
        */
        $budgets_extencion = Extencion::where('slug', 'budgets')->first();
        $permissions = [
            [
                'name' => 'Crear Presupuestos',
                'slug' => 'budget.store',
            ],
            [
                'name' => 'Ver Presupuestos',
                'slug' => 'budget.index',
            ],
            // [
            //     'name' => 'Ver precios de los productos en los Presupuestos',
            //     'slug' => 'budgets.articles.price',
            // ],
        ];
        foreach ($permissions as $permission) {
            PermissionBeta::create([
                'name'          => $permission['name'],
                'slug'          => $permission['slug'],
                'extencion_id'  => $budgets_extencion->id,
            ]);
        }
        /*
        |--------------------------------------------------------------------------
        | Proveedores
        |--------------------------------------------------------------------------
        */
        $providers_extencion = Extencion::where('slug', 'providers')->first();
        $permissions = [
            [
                'name' => 'Ver Proveedores',
                'slug' => 'provider.index',
            ],
            [
                'name' => 'Hacer pedidos a los Proveedores',
                'slug' => 'provider_order.store',
            ],
            [
                'name' => 'Ver los pedidos hechos a los Proveedores',
                'slug' => 'provider_order.index',
            ],
        ];
        foreach ($permissions as $permission) {
            PermissionBeta::create([
                'name'          => $permission['name'],
                'slug'          => $permission['slug'],
                'extencion_id'  => $providers_extencion->id,
            ]);
        }
    }

    function permissions() {
        // Articles
        Permission::create([
            'name' => 'Ingresar articulos',
            'slug' => 'articles.store',
        ]);
        Permission::create([
            'name' => 'Ver articulos',
            'slug' => 'articles.index',
        ]);
        Permission::create([
            'name' => 'Costos en los articulos',
            'slug' => 'articles.cost',
        ]);
        Permission::create([
            'name' => 'Stock en los articulos',
            'slug' => 'articles.stock',
        ]);
        Permission::create([
            'name' => 'Descuentos en los articulos',
            'slug' => 'articles.discounts',
        ]);

        
        // Fotos
        Permission::create([
            'name' => 'Fotos en los articulos',
            'slug' => 'articles.images',
        ]);
        
        // Proveedores
        Permission::create([
            'name' => 'Usar proveedores',
            'slug' => 'providers',
        ]);
        // Precios especiales
        Permission::create([
            'name' => 'Usar precios especiales',
            'slug' => 'special_prices',
        ]);
        // Categorias
        Permission::create([
            'name' => 'Usar categorias',
            'slug' => 'categories',
        ]);
        // Colores
        Permission::create([
            'name' => 'Usar colores',
            'slug' => 'colors',
        ]);
        // Talles
        Permission::create([
            'name' => 'Usar talles',
            'slug' => 'sizes',
        ]);
        // Marcas
        Permission::create([
            'name' => 'Usar marcas',
            'slug' => 'brands',
        ]);
        // Descripciones
        Permission::create([
            'name' => 'Usar descripciones',
            'slug' => 'descriptions',
        ]);
        // Condiciones
        Permission::create([
            'name' => 'Usar condiciones',
            'slug' => 'conditions',
        ]);
        // Tags
        Permission::create([
            'name' => 'Usar tags',
            'slug' => 'tags',
        ]);
        // Precios en dolares
        Permission::create([
            'name' => 'Usar precios en dolares',
            'slug' => 'articles.with_dolar',
        ]);

        // Clientes
        Permission::create([
            'name' => 'Ver clientes',
            'slug' => 'clients',
        ]);

        // Empleados
        Permission::create([
            'name' => 'Empleados',
            'slug' => 'employees',
        ]);


        // Ventas
        Permission::create([
            'name' => 'Vender',
            'slug' => 'sales.store',
        ]);
        Permission::create([
            'name' => 'Ver ventas',
            'slug' => 'sales.index',
        ]);
        Permission::create([
            'name' => 'Descuentos y vendedores',
            'slug' => 'discounts_sellers',
        ]);
        Permission::create([
            'name' => 'Factura electronica',
            'slug' => 'afip_tickets',
        ]);

        // Online
        Permission::create([
            'name' => 'Ver pedidos en la Tienda Online',
            'slug' => 'online.orders',
        ]);
        Permission::create([
            'name' => 'Ver preguntas en la Tienda Online',
            'slug' => 'online.questions',
        ]);
        Permission::create([
            'name' => 'Ver clientes en la Tienda Online',
            'slug' => 'online.buyers',
        ]);
        Permission::create([
            'name' => 'Ver mensajes en la Tienda Online',
            'slug' => 'online.messages',
        ]);
        Permission::create([
            'name' => 'Ver cupones en la Tienda Online',
            'slug' => 'online.cupons',
        ]);
        Permission::create([
            'name' => 'Recibir ordenes de llamada en la Tienda Online',
            'slug' => 'online.calls',
        ]);

        /*
        |--------------------------------------------------------------------------
        | EXTENCIONES
        |--------------------------------------------------------------------------
        */

        /*
        |--------------------------------------------------------------------------
        | Ordenes de produccion
        |--------------------------------------------------------------------------
        */
        $order_productions_extencion = Extencion::where('slug', 'production')->first();
        $permissions = [
            [
                'name' => 'Ver Ordenes de Produccion',
                'slug' => 'order_productions.index',
            ],
            // [
            //     'name' => 'Ver precios de los artículos en las Ordenes de Produccion',
            //     'slug' => 'order_productions.articles.price',
            // ],
        ];
        foreach ($permissions as $permission) {
            Permission::create([
                'name'          => $permission['name'],
                'slug'          => $permission['slug'],
                'extencion_id'  => $order_productions_extencion->id,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Ordenes de produccion
        |--------------------------------------------------------------------------
        */
        $budgets_extencion = Extencion::where('slug', 'budgets')->first();
        $permissions = [
            [
                'name' => 'Ver Presupuestos',
                'slug' => 'budgets.index',
            ],
            [
                'name' => 'Crear Presupuestos',
                'slug' => 'budgets.store',
            ],
            // [
            //     'name' => 'Ver precios de los productos en los Presupuestos',
            //     'slug' => 'budgets.articles.price',
            // ],
        ];
        foreach ($permissions as $permission) {
            Permission::create([
                'name'          => $permission['name'],
                'slug'          => $permission['slug'],
                'extencion_id'  => $budgets_extencion->id,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Proveedores
        |--------------------------------------------------------------------------
        */
        $providers_extencion = Extencion::where('slug', 'providers')->first();
        $permissions = [
            [
                'name' => 'Ver Proveedores',
                'slug' => 'providers.index',
            ],
            [
                'name' => 'Hacer pedidos a los Proveedores',
                'slug' => 'providers.orders.create',
            ],
            [
                'name' => 'Ver los pedidos hechos a los Proveedores',
                'slug' => 'providers.orders.index',
            ],
        ];
        foreach ($permissions as $permission) {
            Permission::create([
                'name'          => $permission['name'],
                'slug'          => $permission['slug'],
                'extencion_id'  => $providers_extencion->id,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Margen de ganancia en los precios
        |--------------------------------------------------------------------------
        */
        // $extencion = Extencion::where('slug', 'articles.percentage_gain')->first();
        // $permissions = [
        //     [
        //         'name' => 'Margen de ganancia en los precios',
        //         'slug' => 'articles.percentage_gain.index',
        //     ],
        // ];
        // foreach ($permissions as $permission) {
        //     Permission::create([
        //         'name'          => $permission['name'],
        //         'slug'          => $permission['slug'],
        //         'extencion_id'  => $extencion->id,
        //     ]);
        // }
    }
}
