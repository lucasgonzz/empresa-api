<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Http\Controllers\Helpers\database\DatabaseArticleHelper;
use App\Http\Controllers\Helpers\database\DatabaseBuyerHelper;
use App\Http\Controllers\Helpers\database\DatabaseCartHelper;
use App\Http\Controllers\Helpers\database\DatabaseClientsHelper;
use App\Http\Controllers\Helpers\database\DatabaseCurrentAcountHelper;
use App\Http\Controllers\Helpers\database\DatabaseEmployeeHelper;
use App\Http\Controllers\Helpers\database\DatabaseOrderHelper;
use App\Http\Controllers\Helpers\database\DatabaseProviderHelper;
use App\Http\Controllers\Helpers\database\DatabaseProviderOrderHelper;
use App\Http\Controllers\Helpers\database\DatabaseSaleHelper;
use App\Http\Controllers\Helpers\database\DatabaseUserHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BaseDeDatosController extends Controller
{

    // Copiar las carts y sus articulos

    function copiar_usuario($company_name, $bbdd_destino) {
        DatabaseUserHelper::copiar_usuario($this->get_user($company_name), $bbdd_destino);
        echo 'TERMINO';
    }

    function get_user($company_name) {
        return User::where('company_name', $company_name)
                        ->first();
    }

    function copiar_carts($company_name, $bbdd_destino) {
        DatabaseCartHelper::copiar_carts($this->get_user($company_name), $bbdd_destino);
        echo 'TERMINO';
    }

    function copiar_orders($company_name, $bbdd_destino) {
        DatabaseOrderHelper::copiar_orders($this->get_user($company_name), $bbdd_destino);
        echo 'TERMINO';
    }

    function copiar_providers($company_name, $bbdd_destino, $from_id = 1) {
        DatabaseProviderHelper::copiar_providers($this->get_user($company_name), $bbdd_destino, $from_id = 1);
        echo 'TERMINO';
    }

    function copiar_clients($company_name, $bbdd_destino, $from_id = 1) {
        DatabaseClientsHelper::copiar_clients($this->get_user($company_name), $bbdd_destino, $from_id = 1);
        echo 'TERMINO';
    }

    function copiar_provider_orders($company_name, $bbdd_destino, $from_id = 1) {
        DatabaseProviderOrderHelper::copiar_provider_orders($this->get_user($company_name), $bbdd_destino, $from_id);
        echo 'TERMINO';
    }

    function copiar_employees($company_name, $bbdd_destino) {
        // Copiar los permisos
        DatabaseEmployeeHelper::copiar_employees($this->get_user($company_name), $bbdd_destino);
        echo 'TERMINO';
    }

    function copiar_current_acounts($company_name, $bbdd_destino, $from_id = 1) {
        // Copiar los pagados por
        DatabaseCurrentAcountHelper::copiar_current_acounts($this->get_user($company_name), $bbdd_destino, $from_id);
        echo 'TERMINO';
    }


    function copiar_modelos($company_name, $bbdd_destino) {
        $user = User::where('company_name', $company_name)
                        ->first();
        if (!is_null($user)) {

            foreach ($this->tablas as $table) {
                
                DatabaseHelper::set_user_conecction($bbdd_destino);

                $table = $this->pluralToSingular($table);

                $models = $table::where('user_id', $user->id)
                                ->orderBy('id', 'ASC')
                                ->get();

                
                foreach ($models as $model) {
                    $model->delete();
                }
            }

            foreach ($this->tablas as $table) {
                
                DatabaseHelper::set_user_conecction(env('DB_DATABASE'), false);

                $table = $this->pluralToSingular($table);

                $models = $table::where('user_id', $user->id)
                                ->orderBy('id', 'ASC')
                                ->get();

                DatabaseHelper::set_user_conecction($bbdd_destino);
                
                foreach ($models as $model) {
                    $created_model = $table::create($model->toArray());
                    echo 'Se creo '.$table.' id: '.$created_model->id.' </br>';
                }
                echo '------------------------------------------ </br>';
            }

        } else {
            echo 'No hay usuario';
        }
        echo 'Listo';
    }

    function copiar_buyers($company_name, $bbdd_destino) {
        $user = User::where('company_name', $company_name)
                        ->first();
        if (!is_null($user)) {
            DatabaseBuyerHelper::copiar_buyers($user, $bbdd_destino);
        } else {
            echo 'No hay usuario';
        }
        echo 'TERMINO';
    }

    function copiar_articulos($company_name, $bbdd_destino, $from_id = 1) {
        
        DatabaseArticleHelper::copiar_articulos($this->get_user($company_name), $bbdd_destino, $from_id);
        
        echo 'TERMINO';
    }

    function copiar_ventas($company_name, $bbdd_destino, $from_id = 1) {
        
        DatabaseSaleHelper::copiar_ventas($this->get_user($company_name), $bbdd_destino, $from_id);
        echo 'TERMINO';
    }

    function pluralToSingular($pluralTableName) {
        // Si el nombre está en plural y no termina en "s" (caso especial como las tablas pivot)
        if (Str::plural($pluralTableName) === $pluralTableName && Str::endsWith($pluralTableName, 's')) {
            return GeneralHelper::getModelName(Str::singular($pluralTableName));
        }

        return GeneralHelper::getModelName($pluralTableName);
    }

    // public $tablas_pivot = [
    //     'address_article',    
    //     'article_budget', 
    //     'article_cart',   
    //     'article_color',
    //     'article_combo',
    //     'article_current_acount', 
    //     'article_deposit',
    //     'article_discounts',  
    //     'article_order',  
    //     'article_order_production',
    //     'article_order_production_finished',
    //     'article_prices_list',
    //     'article_provider',   
    //     'article_provider_order', 
    //     'article_recipe', 
    //     'article_sale',   
    //     'article_size',
    //     'budget_discount',
    //     'budget_observations',
    //     'budget_products',
    //     'budget_product_article_stocks',
    //     'budget_product_deliveries',
    //     'budget_statuses',
    //     'budget_surchage',
    //     'combo_sale',
    //     'current_acount_current_acount_payment_method',   
    // ];

    public $tablas = [
        'addresses',
        // 'advises',
        // 'afip_errors',
        'afip_information',
        // 'afip_tickets',   
        // 'answers',
        // 'articles',   
        // 'articles_pre_imports',
        // 'article_articles_pre_import',    
        // 'article_performances',   
        // 'article_pre_import_ranges',
        // 'article_properties',  
        // 'article_property_article_property_value',  
        // 'article_property_types',
        // 'article_property_values',
        // 'article_property_value_article_variant',
        // 'article_special_price',
        // 'article_tag',
        // 'article_ticket_infos',
        // 'article_variants',  
        // 'bar_codes',
        'brands',
        'budgets',
        'buyers', 
        // 'buyer_messages',
        // 'buyer_message_default_responses',
        // 'calls',
        // 'cards',
        'carts',  
        'categories',
        // 'category_inventory_linkage',  
        // 'checks', 
        // 'clients',    
        // 'colors',
        // 'combos',
        // 'commissioners',
        // 'commissioner_sale',
        // 'commissions',
        // 'commission_except_seller',
        // 'commission_for_all_seller',
        // 'commission_for_only_seller',
        // 'commission_seller',
        // 'conditions',
        // 'configurations',
        // 'credit_cards',
        // 'credit_card_payment_plans',
        // 'cupons',
        // 'cupon_order',
        // 'current_acounts',    
        // 'current_acount_payment_methods',
        // 'current_acount_seller_commission',   
        // 'current_acount_service',
        // 'customers',
        // 'delivery_zones',
        'deposits',
        // 'descriptions',   
        'discounts',
        // 'discount_sale',  
        // 'documents',
        // 'errors',
        // Ver esto
        // 'extencions',
        // 'extencion_empresas',
        // 'extencion_empresa_user',
        // 'extencion_user',
        // 'features',
        // 'feature_plan',
        // 'icons',
        // 'images', 
        // 'import_histories',
        // 'impressions',    
        'inventory_linkages',  
        // 'inventory_linkage_scopes',
        // 'ivas',
        // 'iva_conditions',
        // 'last_searches',  
        // 'likeable_likes',
        // 'likeable_like_counters',
        'locations',
        // 'markers',
        // 'marker_groups',
        // 'messages',   
        // 'migrations',
        // 'notifications',
        'online_configurations',
        // 'online_price_types',
        'orders',
        'order_productions',
        'order_production_statuses',
        // 'order_statuses',
        // 'pagado_por', 
        // 'password_resets',
        // 'payments',
        // 'payment_card_infos',
        'payment_methods',
        // 'payment_method_installments',
        // 'payment_method_types',
        // Ver aca
        // 'permissions',
        // 'permission_betas',
        // 'permission_beta_plan',
        // 'permission_beta_user',
        // 'permission_empresas',
        // 'permission_empresa_user',
        // 'permission_plan',

        /* Permisos_user para los empleados
            Los id de los empleados no se repiten
            Los id de los permisos deben ser los mismos 
                (chequear en la bbdd ferretodo)
            Pasarlos asi como estan
        */
        // 'permission_user',
        // 'plans',
        // 'plan_features',
        // 'plan_plan_feature',
        'platelets',
        'prices_lists',
        // 'price_changes',  
        'price_types',
        // 'price_type_sub_category',  
        'production_movements',
        // 'providers',
        // 'provider_orders',
        // 'provider_order_afip_tickets',
        // 'provider_order_extra_costs',
        // 'provider_order_statuses',
        // 'provider_price_lists',
        // 'questions',
        'recipes',
        // 'sales',  
        // 'sale_service',   
        // 'sale_surchage',  
        // 'sale_times',
        // 'sale_types',
        // 'schedules',
        // 'schedule_workday',
        // 'sellers',  
        // 'seller_commissions',
        'services',   
        'sizes',
        // 'stock_movements',    
        // 'subscriptions',
        'sub_categories', 
        'surchages',
        'tags',
        // 'tasks',
        // 'task_user',
        'titles',
        // 'unidad_medidas',
        // 'update_features',
        // 'users',
        'user_configurations',
        'user_payments',
        // 'variants',
        // 'views',  
        // 'workdays',
    ];
}
