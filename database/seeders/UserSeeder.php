<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\ExtencionEmpresa;
use App\Models\OnlineConfiguration;
use App\Models\User;
use App\Models\UserConfiguration;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (env('FOR_SERVER') == 'la_barraca') {
            $this->la_barraca();
            return;
        } 

        $this->for_user = env('FOR_USER');


        $ct = new Controller();
        $models = [
            [
                'id'                            => env('USER_ID'),
                'name'                          => 'Lucas',
                'use_archivos_de_intercambio'   => 0,
                'company_name'                  => 'Autopartes Boxes',
                // 'image_url'                     => null,
                'image_url'                     => env('APP_ENV') == 'local' ? env('APP_URL').'/storage/icon.png' : 'https://comerciocity.com/img/logo.95c86b81.jpg',
                'doc_number'                    => '1234',
                'impresora'                     => 'XP-80',
                'email'                         => 'lucasgonzalez5500@gmail.com',
                'phone'                         => '3444622139',
                'sale_ticket_description'       => 'Hasta 15 dias de cambio trayendo este ticket',
                'password'                      => bcrypt('1234'),
                'visible_password'              => null,
                'dollar'                        => 1000,
                'home_position'                 => 1,
                'download_articles'             => 0,
                'online'                        => 'http://tienda.local:8081',
                // 'payment_expired_at'            => Carbon::now()->subDays(12),
                'payment_expired_at'            => Carbon::now()->addDays(12),
                'last_user_activity'            => Carbon::now(),
                'total_a_pagar'                 => 15000,
                'plan_id'                       => 3,
                'plan_discount'                 => 27,
                'article_ticket_info_id'        => 1,
                'estable_version'               => null,
                'siempre_omitir_en_cuenta_corriente'    => 0,
                'online_configuration'          => [
                    'online_price_type_id'          => 3,
                    'register_to_buy'               => 1,
                    'scroll_infinito_en_home'       => 1,
                    'default_article_image_url'     => 'http://empresa.local:8000/storage/169705209718205.jpg',
                    'pausar_tienda_online'          => 0,
                ],
                'base_de_datos'                     => 'empresa_prueba_1',

                // San blas
                'google_custom_search_api_key'      => 'AIzaSyCgzE6haVi8uZnenfAvYJO5hn7m7Cl09Gw',
                
                // Comun para todos
                // 'google_custom_search_api_key'      => 'AIzaSyB8e-DlJMtkGxCK29tAo17lxBKStXtzeD4',
                'info_afip_del_primer_punto_de_venta'   => 0,
                'comision_funcion' => 'distri_creo',
            ],
        ];


        if ($this->for_user == 'renacer') {

            $models[0]['name'] = 'Anye';
            $models[0]['company_name'] = 'Renacer Joyas y Perfumes';
            $models[0]['iva_included'] = 1;
            $models[0]['iva_condition_id'] = 2;
            $models[0]['doc_number'] = '34702455';
            $models[0]['default_version'] = 'https://renacer.comerciocity.com';

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                'bar_code_scanner',
                'ask_save_current_acount',
                'imagenes',
                'codigos_de_barra_por_defecto',
                // 'forzar_total',
                // 'cambiar_price_type_en_vender',
            ];

        } else if ($this->for_user == '3dtisk') {

            $models[0]['name'] = 'Juance';
            $models[0]['company_name'] = '3d Tisk';
            $models[0]['iva_included'] = 0;
            $models[0]['iva_condition_id'] = 1;
            $models[0]['doc_number'] = '42385504';
            $models[0]['info_afip_del_primer_punto_de_venta'] = 1;
            
            // $models[0]['default_version'] = 'https://electro-lacarra.comerciocity.com';
            $models[0]['default_version'] = null;

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                // 'bar_code_scanner',
                'ask_save_current_acount',
                'imagenes',
                // 'forzar_total',
                'cajas',
                // 'ventas_con_fecha_de_entrega',
                // 'road_map_detalle_por_articulos_y_no_por_venta',
                // 'online',
            ];

        } else if ($this->for_user == 'leudinox') {

            $models[0]['name'] = 'Ariel';
            $models[0]['company_name'] = 'Leudinox';
            $models[0]['iva_included'] = 0;
            $models[0]['iva_condition_id'] = 1;
            $models[0]['info_afip_del_primer_punto_de_venta'] = 1;
            
            $models[0]['default_version'] = null;

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                'ask_save_current_acount',
                'imagenes',
                'cajas',
                'ventas_con_fecha_de_entrega',
                'road_map_detalle_por_articulos_y_no_por_venta',
                'online',
                'usa_mercado_libre',

                'articulo_margen_de_ganancia_segun_lista_de_precios',
                'cambiar_price_type_en_vender',
            ];

        } else if ($this->for_user == 'san_blas') {

            $models[0]['name'] = 'Fabian';
            $models[0]['company_name'] = 'San blas';
            $models[0]['iva_included'] = 0;
            $models[0]['iva_condition_id'] = 1;
            $models[0]['doc_number'] = '1234';
            $models[0]['info_afip_del_primer_punto_de_venta'] = 1;

            
            // $models[0]['default_version'] = 'https://electro-lacarra.comerciocity.com';
            $models[0]['default_version'] = null;

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                // 'bar_code_scanner',
                'ask_save_current_acount',
                'imagenes',
                // 'forzar_total',
                'cajas',
                // 'ventas_con_fecha_de_entrega',
                // 'road_map_detalle_por_articulos_y_no_por_venta',
                'acopios',
                'articulos_unidades_individuales',
                'check_article_stock_en_vender',
                'article_price_range',
                // 'warn_article_stock_en_vender',
            ];

        } else if ($this->for_user == 'arfren') {

            $models[0]['name'] = 'Alejandro';
            $models[0]['company_name'] = 'San blas';
            $models[0]['iva_included'] = 0;
            $models[0]['iva_condition_id'] = 1;
            $models[0]['doc_number'] = '1234';
            $models[0]['info_afip_del_primer_punto_de_venta'] = 1;
            $models[0]['comparar_precios_de_proveedores_en_excel'] = 1;

            
            // $models[0]['default_version'] = 'https://electro-lacarra.comerciocity.com';
            $models[0]['default_version'] = null;

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                // 'bar_code_scanner',
                'ask_save_current_acount',
                'imagenes',
                // 'forzar_total',
                'cajas',
                // 'ventas_con_fecha_de_entrega',
                // 'road_map_detalle_por_articulos_y_no_por_venta',
                'acopios',
                'articulos_unidades_individuales',
                'check_article_stock_en_vender',
                'article_price_range',
                // 'warn_article_stock_en_vender',
            ];

        }  else if ($this->for_user == 'racing_carts') {

            $models[0]['name'] = 'Rafa';
            $models[0]['company_name'] = 'Racing carts';

            // $models[0]['cotizar_precios_en_dolares'] = 0;
            $models[0]['iva_included'] = 0;
            $models[0]['iva_condition_id'] = 1;
            $models[0]['doc_number'] = '1234';
            $models[0]['info_afip_del_primer_punto_de_venta'] = 1;
            
            // $models[0]['default_version'] = 'https://electro-lacarra.comerciocity.com';
            $models[0]['default_version'] = null;

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                'ask_save_current_acount',
                'imagenes',
                'cajas',
                'articulos_unidades_individuales',
                'autopartes',
                'pagos_provisorios',

                // 'articulo_margen_de_ganancia_segun_lista_de_precios',
                // 'cambiar_price_type_en_vender',

                'ventas_en_dolares',
            ];

        } else if ($this->for_user == 'demo') {

            $models[0]['name'] = 'Lucas';
            $models[0]['company_name'] = 'Autopartes Boxes';
            $models[0]['iva_included'] = 0;
            $models[0]['iva_condition_id'] = 1;
            // $models[0]['default_version'] = 'https://electro-lacarra.comerciocity.com';
            $models[0]['default_version'] = null;

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                'bar_code_scanner',
                'ask_save_current_acount',
                'imagenes',
                'forzar_total',
                'cajas',
                'ventas_con_fecha_de_entrega',
                'road_map_detalle_por_articulos_y_no_por_venta',
                'cambiar_price_type_en_vender',
                'online',
            ];

        } else if ($this->for_user == 'electro_lacarra') {

            $models[0]['name'] = 'Maxi';
            $models[0]['company_name'] = 'Electro lacarra';
            $models[0]['doc_number'] = '34505584';
            $models[0]['iva_included'] = 0;
            $models[0]['iva_condition_id'] = 2;
            $models[0]['default_version'] = 'https://electro-lacarra.comerciocity.com';

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                'bar_code_scanner',
                'ask_save_current_acount',
                'imagenes',
                'forzar_total',
                'mostrar_diferenia_de_precios_en_excel_para_clientes',
                'elegir_si_incluir_lista_de_precios_de_excel',
                'articulo_margen_de_ganancia_segun_lista_de_precios',
                'cambiar_price_type_en_vender',
                'articulos_unidades_individuales',
            ];

        } else if ($this->for_user == 'secure_pack') {

            $models[0]['name'] = 'Federico';
            $models[0]['iva_included'] = 0;

            $models[0]['extencions'] = [

                'comerciocity_interno',
                'budgets',
                'bar_code_scanner',
                'ask_save_current_acount',
                'imagenes',
                'forzar_total',
                'cajas',
            ];

        } else if ($this->for_user == 'colman') {

            $models[0]['name'] = 'Colman';

            $models[0]['extencions'] = [

                'budgets',
                'production',
                'acopios',
                'online',
                'article.costo_real',
                'bar_code_scanner',
                'comerciocity_interno',
                'article_num_in_online',
                'check_sales',
                'sale.observations',
                'production.production_movement',
                'check_article_stock_en_vender',
                'costo_en_dolares',
                'check_guardar_ventas_con_cliente',
            ];

        } else if ($this->for_user == 'feito') {

            $models[0]['name'] = 'Feito';

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 1;
            $models[0]['redondear_centenas_en_vender'] = 1;
            $models[0]['ask_amount_in_vender'] = 0;

            $models[0]['extencions'] = [

                'bar_code_scanner',
                'comerciocity_interno',
                'ask_save_current_acount',
                'article_variants',
                'deposit_movements',
                'consultora_de_precios',
                'cajas',
                'budgets',
                'codigos_de_barra_basados_en_numero_interno',
                'imagenes',
            ];

        } else if ($this->for_user == 'hipermax') {

            $models[0]['name'] = 'Hipermax';

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 1;
            $models[0]['ask_amount_in_vender'] = 0;

            $models[0]['extencions'] = [

                'bar_code_scanner',
                'forzar_total',
                'comerciocity_interno',
                'ask_save_current_acount',
                'articles_default_in_vender',
                'fecha_impresion_en_article_tickets',
                'balanza_bar_code',
            ];

        } else if ($this->for_user == 'fenix') {

            $models[0]['name'] = 'Fenix';

            $models[0]['comision_funcion'] = 'fenix';

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'budgets',
                'online',
                'bar_code_scanner',
                'comerciocity_interno',
                'article_num_in_online',
                'sales.hide',
                'costos_en_nota_credito_pdf',
            ];

        } else if ($this->for_user == 'pack_descartables') {

            $models[0]['name'] = 'Pack Descartables';

            $models[0]['iva_included'] = 0;

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'ask_save_current_acount',
                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                'articulo_margen_de_ganancia_segun_lista_de_precios',
                'cambiar_price_type_en_vender',
                'no_usar_codigos_de_barra',
                'deposit_movements',
                'online',
                'forzar_total',
                'cajas',
                'filtrar_clientes_por_sucursal_en_vender',
                'cambiar_price_type_en_vender_item_por_item',
                'article.costo_real',
            ];

        } else if ($this->for_user == 'mza_group') {

            $models[0]['name'] = 'Gabi';

            $models[0]['iva_included'] = 0;

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                'articulo_margen_de_ganancia_segun_lista_de_precios',
                'cambiar_price_type_en_vender',
                'online',
                'article.costo_real',
                'setear_precio_final_en_listas_de_precio',
                'articulos_unidades_individuales',
                'usa_tienda_nube',
                'cajas',
                'article_variants',
            ];

        } else if ($this->for_user == 'bad_girls') {

            $models[0]['name'] = 'Angeles';

            $models[0]['iva_included'] = 0;

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                'articulo_margen_de_ganancia_segun_lista_de_precios',
                'cambiar_price_type_en_vender',
                'online',
                'article.costo_real',
                'setear_precio_final_en_listas_de_precio',
                'cajas',
                'article_variants',
                'articulos_en_exhibicion',
            ];

        } else if ($this->for_user == 'trama') {

            $models[0]['name'] = 'Luis';

            $models[0]['iva_included'] = 0;

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                'articulo_margen_de_ganancia_segun_lista_de_precios',
                'cambiar_price_type_en_vender',
                'online',
                'article.costo_real',
                'setear_precio_final_en_listas_de_precio',
                'cajas',
                'resumen_caja',
            ];

        } else if ($this->for_user == 'truvari') {

            $models[0]['name'] = 'Fernando';

            $models[0]['iva_included'] = 0;

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;
            // $models[0]['redondear_miles_en_vender'] = 1;
            $models[0]['article_pdf_personalizado'] = 'truvari';

            $models[0]['comision_funcion'] = 'truvari';
            $models[0]['venta_terminada_comision_funcion'] = 'truvari';
            $models[0]['default_version'] = 'https://truvari.comerciocity.com';
            $models[0]['google_custom_search_api_key'] = 'AIzaSyDSOX6FoW1AWN1w7ArrV_OYrrlDxMGIhuE';
            
            $models[0]['default_article_iva_id'] = 6;


            $models[0]['online_configuration'] = [
                'online_price_type_id'          => 3,
                'register_to_buy'               => 1,
                'scroll_infinito_en_home'       => 1,
                'default_article_image_url'     => 'http://empresa.local:8000/storage/169705209718205.jpg',
                'pausar_tienda_online'          => 0,
                'online_template_id'            => 2,
                'cantidad_tarjetas_en_telefono' => 2,
                'cantidad_tarjetas_en_tablet'   => 3,
                'cantidad_tarjetas_en_notebook' => 4,
                'cantidad_tarjetas_en_escritorio' => 4,
                'enviar_whatsapp_al_terminar_pedido'    => 1,
                'titulo_quienes_somos'    => 'Forma de compra',
                'retiro_por_local'              => 0,
                'pedir_barrio_al_registrarse'   => 1,
                'logear_cliente_al_registrar'   => 0,
            ];

            $models[0]['extencions'] = [

                'ask_save_current_acount',
                'article.costo_real',
                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                // 'cambiar_price_type_en_vender',
                'deposit_movements',
                'online',
                'forzar_total',
                'cajas',
                // 'filtrar_clientes_por_sucursal_en_vender',
                // 'lista_de_precios_por_categoria',
                // 'lista_de_precios_por_rango_de_cantidad_vendida',
                'check_article_stock_en_vender',
                'atajo_buscar_por_nombre',
                'bar_codes_in_vender_table',
                'vinoteca',
                'ventas_con_fecha_de_entrega',
                'road_map_detalle_por_articulos_y_no_por_venta',
            ];

        } else if ($this->for_user == 'golo_norte') {

            $models[0]['name'] = 'Golo Norte';

            $models[0]['iva_included'] = 0;
            $models[0]['article_ticket_print_function'] = 'golonorte';

            $models[0]['comision_funcion'] = 'golonorte';

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'ask_save_current_acount',
                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                'deposit_movements',
                'online',
                'forzar_total',
                'cajas',
                // 'filtrar_clientes_por_sucursal_en_vender',
                'lista_de_precios_por_categoria',
                'lista_de_precios_por_rango_de_cantidad_vendida',
                'check_article_stock_en_vender',
                'combos',
                'atajo_buscar_por_nombre',
                'bar_codes_in_vender_table',
                'comisiones_por_categoria',
                'indicar_vendedor_en_vender',
                'cambiar_price_type_en_vender_item_por_item',
                'ventas_con_fecha_de_entrega',
                'road_map_detalle_por_articulos_y_no_por_venta',
            ];


            $models[0]['online_configuration'] = [
                'online_price_type_id'          => 3,
                'register_to_buy'               => 1,
                'scroll_infinito_en_home'       => 1,
                'default_article_image_url'     => 'http://empresa.local:8000/storage/169705209718205.jpg',
                'pausar_tienda_online'          => 0,
                'online_template_id'            => 1,
                'cantidad_tarjetas_en_telefono' => 2,
                'cantidad_tarjetas_en_tablet'   => 3,
                'cantidad_tarjetas_en_notebook' => 4,
                'cantidad_tarjetas_en_escritorio' => 4,
                'enviar_whatsapp_al_terminar_pedido'    => 0,
            ];

        } else if ($this->for_user == 'ros_mar') {

            $models[0]['name'] = 'Ros Mar';

            $models[0]['comision_funcion'] = 'ros_mar';

            $models[0]['iva_included'] = 1;

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                'cambiar_price_type_en_vender',
                'articulos_precios_en_blanco',
                'articulos_con_propiedades_de_distribuidora',
                'indicar_vendedor_en_vender',
                'comision_por_proveedores',
                'ask_save_current_acount',
                'cajas',
                'no_usar_codigos_de_barra',
            ];

        } else if ($this->for_user == 'ferretodo') {

            $models[0]['name'] = 'Ferretodo';

            $models[0]['comision_funcion'] = null;

            $models[0]['iva_included'] = 0;

            $models[0]['siempre_omitir_en_cuenta_corriente'] = 0;

            $models[0]['extencions'] = [

                'budgets',
                'bar_code_scanner',
                'comerciocity_interno',
                'ask_save_current_acount',
                'article.costo_real',
                'guardad_cuenta_corriente_despues_de_facturar',
                'codigo_proveedor_en_vender',
                'numero_orden_de_compra_para_las_ventas',
                'maximo_descuento_posible_por_articulo_en_vender',
            ];

        }


        foreach ($models as $model) {
            $user = User::create([
                'id'                            => isset($model['id']) ? $model['id'] : null, 
                'api_url'                       => 'http://empresa.local:8000',
                'name'                          => $model['name'], 
                'phone'                         => $model['phone'], 
                'dollar'                        => $model['dollar'], 
                'use_archivos_de_intercambio'                  => isset($model['use_archivos_de_intercambio']) ? $model['use_archivos_de_intercambio'] : null,  
                'company_name'                  => isset($model['company_name']) ? $model['company_name'] : null,  
                'iva_included'                  => isset($model['iva_included']) ? $model['iva_included'] : 0, 
                'cotizar_precios_en_dolares'    => isset($model['cotizar_precios_en_dolares']) ? $model['cotizar_precios_en_dolares'] : 1, 
                'comision_funcion'                  => isset($model['comision_funcion']) ? $model['comision_funcion'] : null, 
                'venta_terminada_comision_funcion'                  => isset($model['venta_terminada_comision_funcion']) ? $model['venta_terminada_comision_funcion'] : null, 
                'comparar_precios_de_proveedores_en_excel'                  => isset($model['comparar_precios_de_proveedores_en_excel']) ? $model['comparar_precios_de_proveedores_en_excel'] : null, 
                'impresora'                     => isset($model['impresora']) ? $model['impresora'] : null, 
                'doc_number'                    => $model['doc_number'], 
                'info_afip_del_primer_punto_de_venta'                    => $model['info_afip_del_primer_punto_de_venta'],                 
                'tamano_letra'                  => 2.5,
                'email'                         => $model['email'], 
                'password'                      => $model['password'],  
                'image_url'                     => $model['image_url'],  
                'visible_password'              => $model['visible_password'],  
                'download_articles'             => isset($model['download_articles']) ? $model['download_articles'] : null,  
                'address_id'                    => isset($model['address_id']) ? $model['address_id'] : null,  
                'owner_id'                      => isset($model['owner_id']) ? $model['owner_id'] : null,  
                'admin_access'                  => isset($model['admin_access']) ? $model['admin_access'] : null, 
                'redondear_centenas_en_vender'  => isset($model['redondear_centenas_en_vender']) ? $model['redondear_centenas_en_vender'] : 0, 
                'redondear_miles_en_vender'  => isset($model['redondear_miles_en_vender']) ? $model['redondear_miles_en_vender'] : 0, 
                'article_pdf_personalizado'  => isset($model['article_pdf_personalizado']) ? $model['article_pdf_personalizado'] : null, 
                'ask_amount_in_vender'  => isset($model['ask_amount_in_vender']) ? $model['ask_amount_in_vender'] : 1, 
                'payment_expired_at'            => isset($model['payment_expired_at']) ? $model['payment_expired_at'] : null,  
                'online'                        => isset($model['online']) ? $model['online'] : null,
                'home_position'                 => isset($model['home_position']) ? $model['home_position'] : null,
                'plan_id'                       => isset($model['plan_id']) ? $model['plan_id'] : null,
                'plan_discount'                 => isset($model['plan_discount']) ? $model['plan_discount'] : null,
                'article_ticket_info_id'                 => isset($model['article_ticket_info_id']) ? $model['article_ticket_info_id'] : null,
                'siempre_omitir_en_cuenta_corriente'                 => isset($model['siempre_omitir_en_cuenta_corriente']) ? $model['siempre_omitir_en_cuenta_corriente'] : null,
                'article_ticket_print_function'                 => isset($model['article_ticket_print_function']) ? $model['article_ticket_print_function'] : null,
                'total_a_pagar'                 => isset($model['total_a_pagar']) ? $model['total_a_pagar'] : null,
                'app_url'                       => isset($model['app_url']) ? $model['app_url'] : null,
                'base_de_datos'                 => isset($model['base_de_datos']) ? $model['base_de_datos'] : null,
                'google_custom_search_api_key'                 => isset($model['google_custom_search_api_key']) ? $model['google_custom_search_api_key'] : null,
                'default_article_iva_id'                 => isset($model['default_article_iva_id']) ? $model['default_article_iva_id'] : null,
                'dias_alertar_empleados_ventas_no_cobradas'        => 1,
                'dias_alertar_administradores_ventas_no_cobradas'  => 1,
                'default_version'               => null,
                // 'default_version'               => env('APP_ENV') == 'local' ? 'http://empresa.local:8080' : $model['default_version'],
                'estable_version'               => null,
                // 'estable_version'               => env('APP_ENV') == 'local' ? 'http://empresa.local:8081' : $model['estable_version'],
            ]);

            
            if (is_null($user->owner_id)) {

                if (isset($model['extencions'])) {
                    $model['extencions'][] = 'costo_en_dolares';
                    foreach ($model['extencions'] as $extencion_slug) {
                        $extencion = ExtencionEmpresa::where('slug', $extencion_slug)
                                                    ->first();
                        $user->extencions()->attach($extencion->id);
                    }
                }
                
                Log::info('env user_Id: '.env('USER_ID'));
                Log::info('env FOR_USER: '.env('FOR_USER'));
                UserConfiguration::create([
                    'current_acount_pagado_details'         => 'Saldado',
                    'current_acount_pagandose_details'      => 'Recibo de pago',
                    'iva_included'                          => 1,
                    'limit_items_in_sale_per_page'          => null,
                    'can_make_afip_tickets'                 => 1,
                    'user_id'                               => env('USER_ID'),
                ]);


                if (
                    env('APP_ENV') == 'local'
                    || $this->for_user == 'demo'
                ) {
                    
                    AfipInformation::create([
                        'iva_condition_id'      => 1,
                        'razon_social'          => 'RRII Ferretodo',
                        'domicilio_comercial'   => 'Pellegrini 1876',
                        'cuit'                  => '20381712010',
                        // 20175018841 papa 
                        // 20167430490 felix
                        // 20381712010 Nico Ferretodo
                        'punto_venta'           => 4,
                        'ingresos_brutos'       => '20381712010',
                        'inicio_actividades'    => Carbon::now()->subYears(5),
                        'user_id'               => env('USER_ID'),
                    ]);
                    
                    AfipInformation::create([
                        'iva_condition_id'      => 2,
                        'razon_social'          => 'Lucas Mono',
                        'domicilio_comercial'   => 'Pellegrini 1876',
                        'cuit'                  => '20423548984',
                        'punto_venta'           => 2,
                        'ingresos_brutos'       => '20423548984',
                        'inicio_actividades'    => Carbon::now()->subYears(5),
                        'user_id'               => env('USER_ID'),
                    ]);
                    AfipInformation::create([
                        'iva_condition_id'      => 2,
                        'razon_social'          => 'Lucas Mono E',
                        'domicilio_comercial'   => 'Pellegrini 1876',
                        'cuit'                  => '20423548984',
                        'punto_venta'           => 3,
                        'ingresos_brutos'       => '20423548984',
                        'inicio_actividades'    => Carbon::now()->subYears(5),
                        'user_id'               => env('USER_ID'),
                    ]);
                }

                if (isset($model['online_configuration'])) {
                    $online_configuration               = $model['online_configuration'];
                    $online_configuration['user_id']    = env('USER_ID');
                    $online_configuration['quienes_somos']    = 'Somos un negocio que se dedica a muchas cosas';
                    $online_configuration['facebook']    = 'htts://facebook.com';
                    $online_configuration['instagram']    = 'htts://instagram.com';
                    $online_configuration['mensaje_contacto']    = 'Comunicate con nosotros';
                    
                    OnlineConfiguration::create($online_configuration);
                }
            }
            if (!is_null($user->owner_id)) {
                foreach ($model['permissions_slug'] as $permission_slug) {
                    $user->permissions()->attach($ct->getModelBy('permission_empresas', 'slug', $permission_slug, false, 'id'));
                }
            }
        }
    }

    function la_barraca() {
        $commerce = User::create([
            'id'                            => 3,
            'name'                          => 'Oscar',
            'email'                         => null,
            // 'hosting_image_url'             => 'http://miregistrodeventas.local:8001/storage/brljlnhpojrrnk0hfapz.jpeg',
            // 'phone'                         => '3444622139',
            'company_name'                  => 'La barraca',
            'status'                        => 'commerce',
            'plan_id'                       => 6,
            'type'                          => 'provider',
            'password'                      => bcrypt('1234'),
            'percentage_card'               => 0,
            // 'has_delivery'                  => 1,
            'dollar'                        => 200,
            'doc_number'                    => '21491373',
            // 'delivery_price'                => 70,
            // 'online_prices'                 => 'all',
            // 'online'                        => 'http://kioscoverde.local:8080',
            // 'order_description'             => 'Observaciones',
            // 'show_articles_without_images'  => 1,
            // 'default_article_image_url'     => 'http://miregistrodeventas.local:8001/storage/ajx4wszusy7hp2vditgb.webp',
            'created_at'                    => Carbon::now()->subMonths(2),
        ]);

        Address::create([
            'street'        => 'Alfredo palacios 333',
            'street_number' => 3322,
            'city'          => 'Gualeguay',
            'lat'           => '1',
            'lng'           => '1',
            'province'      => 'Entre Rios',
            'user_id'       => $commerce->id,
        ]);

        $commerce->extencions()->attach([1, 2, 4, 5, 7, 8, 9]);
        UserConfiguration::create([
            'current_acount_pagado_details'         => 'Recibo de pago (saldado)',
            'current_acount_pagandose_details'      => 'Recibo de pago',
            'iva_included'                          => 1,
            'limit_items_in_sale_per_page'          => null,
            'can_make_afip_tickets'                 => 1,
            'user_id'                               => $commerce->id,
        ]);

        AfipInformation::create([
            'iva_condition_id'      => 1,
            'razon_social'          => 'La Barraca',
            'domicilio_comercial'   => 'Alfredo palacios 333',
            'cuit'                  => '20175018841',
            'punto_venta'           => 4,
            'ingresos_brutos'       => '20175018841',
            'inicio_actividades'    => Carbon::now()->subYears(5),
            'user_id'               => $commerce->id,
        ]);
    }
}
