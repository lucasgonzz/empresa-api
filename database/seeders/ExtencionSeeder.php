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
                'name' => 'Produccion',
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
            // [
                // 23
                // 'name' => 'Unidades individuales para los articulos',
                // 'slug' => 'unidades_individuales_en_articulos',
            // ],
            [
                // 24
                'name' => 'Mostrar maximo descuento posible para un articulo en VENDER',
                'slug' => 'maximo_descuento_posible_por_articulo_en_vender',
            ],

            // Esta se usa para pack descartables
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
            [
                // 32
                'name' => 'No usar codigos de barra',
                'slug' => 'no_usar_codigos_de_barra',
            ],
            [
                // 33
                'name' => 'Costo en Dolares',
                'slug' => 'costo_en_dolares',
            ],
            [
                // 34
                'name' => 'Variantes de articulos',
                'slug' => 'article_variants',
            ],
            [
                // 35
                'name' => 'Consultora de precios',
                'slug' => 'consultora_de_precios',
            ],
            [
                // 36
                'name' => 'Forzar Total en VENDER',
                'slug' => 'forzar_total',
            ],
            [
                // 37
                'name' => 'Tener que asignar clientes a las ventas',
                'slug' => 'check_guardar_ventas_con_cliente',
            ],
            [
                // 38
                'name' => 'Cajas',
                'slug' => 'cajas',
            ],
            [
                // 39
                'name' => 'Codigos de barra basado en numero interno',
                'slug' => 'codigos_de_barra_basados_en_numero_interno',
            ],
            [
                // 40
                'name' => 'Filtrar clientes por sucursal en vender',
                'slug' => 'filtrar_clientes_por_sucursal_en_vender',
            ],
            [
                // 41
                'name' => 'Lista de precios por categoria',
                'slug' => 'lista_de_precios_por_categoria',
            ],
            [
                // 42
                'name' => 'Lista de precios rango de cantidad vendida',
                'slug' => 'lista_de_precios_por_rango_de_cantidad_vendida',
            ],
            [
                // 43
                'name' => 'Atajo buscar por nombre',
                'slug' => 'atajo_buscar_por_nombre',
            ],
            [
                // 44
                'name' => 'Imagenes',
                'slug' => 'imagenes',
            ],
            [
                // 45
                'name' => 'Codigos de barra en la tabla de vender',
                'slug' => 'bar_codes_in_vender_table',
            ],
            [
                // 46
                'name' => 'Fecha impresion en article tickets',
                'slug' => 'fecha_impresion_en_article_tickets',
            ],
            [
                // 47
                'name' => 'Vinoteca',
                'slug' => 'vinoteca',
            ],
            [
                // 48
                'name' => 'comisiones_por_categoria',
                'slug' => 'comisiones_por_categoria',
            ],
            [
                // 49
                'name' => 'Ventas con fecha de entrega',
                'slug' => 'ventas_con_fecha_de_entrega',
            ],
            [
                // 50
                /* 
                    Esta extencion se la aplico a truvari, 
                    para que en el detalle de la hoja de ruta le aparezcan los articulos de todas las ventas, y no cada venta 
                
                */
                'name' => 'Ventas con fecha de entrega',
                'slug' => 'road_map_detalle_por_articulos_y_no_por_venta',
            ],
            [
                // 51
                // Para maxi ferreteria
                'name' => 'mostrar diferenia de precios en excel para clientes',
                'slug' => 'mostrar_diferenia_de_precios_en_excel_para_clientes',
            ],
            [
                // 52
                // Para maxi ferreteria
                'name' => 'excluir lista de precios de excel',
                'slug' => 'elegir_si_incluir_lista_de_precios_de_excel',
            ],
            [
                // 53
                'name' => 'cambiar price type en vender item por item',
                'slug' => 'cambiar_price_type_en_vender_item_por_item',
            ],
            [
                // 54
                'name' => 'articulos unidades individuales',
                'slug' => 'articulos_unidades_individuales',
            ],
            [
                // 55
                // Setea el codigo de barras con el id del articulo
                'name' => 'Codigos de barra por defecto',
                'slug' => 'codigos_de_barra_por_defecto',
            ],
            [
                // 56
                // Setea el codigo de barras con el id del articulo
                'name' => 'costos_en_nota_credito_pdf',
                'slug' => 'costos_en_nota_credito_pdf',
            ],
            [
                // 57
                // Setea el codigo de barras con el id del articulo
                'name' => 'setear_precio_final_en_listas_de_precio',
                'slug' => 'setear_precio_final_en_listas_de_precio',
            ],
            [
                // 58
                'name' => 'Chequear stock de articulos en VENDER',
                'slug' => 'warn_article_stock_en_vender',
            ],
            [
                // 59
                'name' => 'Cambiar el empleado en VENDER',
                'slug' => 'cambiar_empleado_en_vender',
            ],
            [
                // 60
                'name' => 'Usa tienda nube',
                'slug' => 'usa_tienda_nube',
            ],
            [
                // 61
                'name' => 'articulos_en_exhibicion',
                'slug' => 'articulos_en_exhibicion',
            ],
            [
                // 61
                'name' => 'autopartes',
                'slug' => 'autopartes',
            ],
            [
                // 62
                'name' => 'ventas_en_dolares',
                'slug' => 'ventas_en_dolares',
            ],
            [
                // 63
                'name' => 'pagos_provisorios',
                'slug' => 'pagos_provisorios',
            ],
            [
                // 64
                'name' => 'firma_entrega_en_pdf_ventas',
                'slug' => 'firma_entrega_en_pdf_ventas',
            ],
            [
                // 65
                'name' => 'Usa Mercado Libre',
                'slug' => 'usa_mercado_libre',
            ],
            [
                // 66
                'name' => 'Buscar por categoria en vender',
                'slug' => 'buscar_por_categoria_en_vender',
            ],

            [
                'name' => 'Codigos de barra de balanzas',
                'slug' => 'balanza_bar_code',
            ],

            // Para La Martina
            [
                'name' => 'Articulos con rangos de precio segun cantidad vendida',
                'slug' => 'article_price_range',
            ],

            // Para Arfren
            [
                'name' => 'Articulo con multiple proveedores',
                'slug' => 'articulo_multi_proveedor',
            ],
            [
                'name' => 'Resumen caja',
                'slug' => 'resumen_caja',
            ],
            [
                'name' => 'vendedor_en_sale_pdf',
                'slug' => 'vendedor_en_sale_pdf',
            ],
            [
                'name' => 'PLU Balanza codigos de barra',
                'slug' => 'plu_balanza_bar_code',
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
