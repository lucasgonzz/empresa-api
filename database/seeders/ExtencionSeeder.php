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
            [
                // 20
                'name' => 'Chequear stock de articulos en VENDER',
                'slug' => 'check_article_stock_en_vender',
            ],
            [
                // 21
                'name' => 'Preguntar si se quiere o no guardar una venta en la cuenta corriente',
                'slug' => 'ask_save_current_acount',
            ],
            [
                // 22
                'name' => 'Numero orden de compra para las ventas',
                'slug' => 'numero_orden_de_compra_para_las_ventas',
            ],
            [
                // 23
                'name' => 'Unidades individuales para los articulos',
                'slug' => 'unidades_individuales_en_articulos',
            ],
            [
                // 24
                'name' => 'Mostrar maximo descuento posible para un articulo en VENDER',
                'slug' => 'maximo_descuento_posible_por_articulo_en_vender',
            ],
            [
                // 25
                'name' => 'Articulos con Margenes de ganancia segun lista de precios',
                'slug' => 'articulo_margen_de_ganancia_segun_lista_de_precios',
            ],
            [
                // 26
                'name' => 'Cambiar dinamicamente la Lista de Precios en VENDER',
                'slug' => 'cambiar_price_type_en_vender',
            ],
            [
                // 27
                'name' => 'Articulos con precios en BLANCO',
                'slug' => 'articulos_precios_en_blanco',
            ],
            [
                // 28
                'name' => 'Articulos con propiedades de distribuidoras',
                'slug' => 'articulos_con_propiedades_de_distribuidora',
            ],
            [
                // 29
                'name' => 'Indicar vendedor en VENDER',
                'slug' => 'indicar_vendedor_en_vender',
            ],
            [
                // 30
                'name' => 'Porcentajes de comision por proveedores, en base a ventas en NEGRO o en BLANCO',
                'slug' => 'comision_por_proveedores',
            ],
            [
                // 31
                'name' => 'Movimientos de Depositos masivos',
                'slug' => 'deposit_movements',
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
