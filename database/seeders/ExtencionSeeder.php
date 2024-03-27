<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtencionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $extencions = [
            [
                // 1
                'name' => 'Presupuestos',
                'slug' => 'budgets',
            ],
            [
                // 2
                'name' => 'Ordenes de Produccion',
                'slug' => 'production',
            ],
            [
                // 3
                'name' => 'Combos',
                'slug' => 'combos',
            ],
            [
                // 4
                'name' => 'Esconder ventas',
                'slug' => 'sales.hide',
            ],
            [
                // 5
                'name' => 'Acopios',
                'slug' => 'acopios',
            ],
            [
                // 6
                'name' => 'Online',
                'slug' => 'online',
            ],
            [
                // 7
                'name' => 'Costo real',
                'slug' => 'article.costo_real',
            ],
            [
                // 8
                'name' => 'Escanear Codigos de Barra',
                'slug' => 'bar_code_scanner',
            ],
            [
                // 9
                'name' => 'Usar sistema de administracion',
                'slug' => 'comerciocity_interno',
            ],
            [
                // 10
                'name' => 'Articulos por defecto en VENDER',
                'slug' => 'articles_default_in_vender',
            ],
            [
                // 11
                'name' => 'Mostrar Codigo interno en e-commerce',
                'slug' => 'article_num_in_online',
            ],
            [
                // 12
                'name' => 'Chequear ventas',
                'slug' => 'check_sales',
            ],
            [
                // 13
                'name' => 'Observaciones en ventas',
                'slug' => 'sale.observations',
            ],
            [
                // 14
                'name' => 'Ordenes de Produccion',
                'slug' => 'production.order_production',
            ],
            [
                // 15
                'name' => 'Movimientos de Produccion',
                'slug' => 'production.production_movement',
            ],
            [
                // 16
                'name' => 'Mercado Libre',
                'slug' => 'mercado_libre',
            ],
            [
                // 17
                'name' => 'Pre Improtacion de Articulos',
                'slug' => 'articles_pre_import',
            ],
            [
                // 18
                'name' => 'Guardad cuenta corriente despues de facturar',
                'slug' => 'guardad_cuenta_corriente_despues_de_facturar',
            ],
            [
                // 19
                'name' => 'Codigo proveedor en vender',
                'slug' => 'codigo_proveedor_en_vender',
            ],
        ];
        foreach ($extencions as $extencion) {
            ExtencionEmpresa::create([
                'name' => $extencion['name'],
                'slug' => $extencion['slug'],
            ]);
        }
    }
}
