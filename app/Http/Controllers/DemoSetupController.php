<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\ExtencionEmpresa;
use App\Models\Extension;
use App\Models\OnlineConfiguration;
use App\Models\PriceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DemoSetupController extends Controller
{
    public function form()
    {
        return view('demo.setup');
    }

    public function setup(Request $request)
    {
        $request->validate([
            'business_type' => 'required|string',
            'use_deposits' => 'nullable|boolean',
            'use_price_lists' => 'nullable|boolean',
        ]);
        
        Artisan::call('migrate:fresh', ['--force' => true]);


        // Crear usuario demo
        $user = User::create([
            'id'                            => config('app.USER_ID'),
            'api_url'                       => config('app.APP_URL').'/public',
            'name'                          => $request->user_name,
            'use_archivos_de_intercambio'   => 0,
            'company_name'                  => $request->company_name,
            'image_url'                     => 'https://comerciocity.com/img/logo.95c86b81.jpg',
            'doc_number'                    => '1234',
            'impresora'                     => 'XP-80',
            'email'                         => 'lucasgonzalez5500@gmail.com',
            'phone'                         => '3444622139',
            'sale_ticket_description'       => 'Aca iria alguna aclaracion que quieras hacer',
            'password'                      => bcrypt('1234'),
            'visible_password'              => null,
            'dollar'                        => 0,
            'home_position'                 => 1,
            'download_articles'             => 0,
            'online'                        => 'https://tienda.comerciocity.com',
            'payment_expired_at'            => Carbon::now()->addDays(12),
            'total_a_pagar'                 => 15000,
            'plan_id'                       => null,
            'plan_discount'                 => null,
            'article_ticket_info_id'        => 1,
            'estable_version'               => null,
            'iva_included'                  => $request->iva_included ? 1 : 0,
            'ask_amount_in_vender'          => 1,
            'redondear_centenas_en_vender'          => $request->redondear_centenas_en_vender ? 1 : 0,
            'siempre_omitir_en_cuenta_corriente'    => $request->usan_cuentas_corrientes ? 0 : 1,
            // 'online_configuration'          => [
            //     'online_price_type_id'          => 3,
            //     'register_to_buy'               => 1,
            //     'scroll_infinito_en_home'       => 1,
            //     'default_article_image_url'     => 'http://empresa.local:8000/storage/169705209718205.jpg',
            //     'pausar_tienda_online'          => 0,
            // ],
            'base_de_datos'                     => 'empresa_prueba_1',
            'google_custom_search_api_key'      => 'AIzaSyCgzE6haVi8uZnenfAvYJO5hn7m7Cl09Gw',
            'google_cuota'                      => 100,
        ]);

        $this->puntos_de_venta_afip($user);



        // Asignar extensiones según configuración
        $extencions = ['comerciocity_interno', 'ask_save_current_acount', 'online', 'costo_en_dolares', 'budgets', 'acopios', 'bar_code_scanner'];
        $seeders = [
            'MonedaSeeder',
            'SaleChannelSeeder',
            'CheckStatusSeeder',
            'OnlineTemplateSeeder',
            'ConceptoStockMovementSeeder',
            'UnidadMedidaSeeder',
            'PermissionSeeder',
            'OrderStatusSeeder',
            'ProviderOrderStatusSeeder',
            'OnlinePriceTypeSeeder',
            'DepositMovementStatusSeeder',

            'IvaSeeder',
            
            'IvaConditionSeeder',

            'OrderProductionStatusSeeder',
            'CurrentAcountPaymentMethodSeeder',
            'BudgetStatusSeeder',

            'ArticleTicketInfoSeeder',

            'UnidadFrecuenciaSeeder',

            'ConceptoMovimientoCajaSeeder',
            // 'CajaSeeder',

            'AfipTipoComprobanteSeeder',






            // Estos los llamaba despues en el DatabaseSeeder

            // 'CategorySeeder',
            // 'SubCategorySeeder',

            'ProviderSeeder',
            'ProviderPriceListSeeder',
            'ColorSeeder',
            'DepositSeeder',
            'ClientSeeder',
            'BuyerSeeder',
            'DiscountSeeder',
            'SurchageSeeder',
            // 'AddressSeeder',
            'ProviderOrderSeeder',
            'ProviderPagosSeeder',
            'TitleSeeder',
            'DeliveryZoneSeeder',
            // 'BudgetSeeder',
            'UpdateFeatureSeeder',
            // 'OrderSeeder',
            'InventoryLinkageScopeSeeder',
            
            'MessageSeeder',

            'ExpenseConceptSeeder',
            'ExpenseSeeder',
            'PendingSeeder',

            'EmployeeSeeder',
            'SellerSeeder',            
            'ChequeSeeder',            
        ];




        if ($request->business_type == 'ropa') {
            $seeders[] = 'ArticlePropertyTypeSeeder';
            $seeders[] = 'ArticlePropertyValueSeeder';
            $seeders[] = 'ArticlePropertySeeder';
            $seeders[] = 'ArticleVariantSeeder';
            $seeders[] = 'CategoryIndumentariaSeeder';
            $seeders[] = 'ArticleIndumentariaSeeder';

            $extencions[] = 'article_variants';
        } else if ($request->business_type == 'forrajeria') {
            $seeders[] = 'CategoryForrajeriaSeeder';
            $seeders[] = 'ArticleForrajeriaSeeder';
        } else {
            $seeders[] = 'CategorySeeder';
            $seeders[] = 'ArticleSeeder';
        }
        
        $seeders[] = 'BudgetSeeder';


        if ($request->codigos_de_barra_por_defecto) {
            $extencions[] = 'codigos_de_barra_por_defecto';
        }

        if ($request->produccion) {
            $extencions[] = 'production';
            $extencions[] = 'production.production_movement';
        }

        if ($request->ventas_con_fecha_de_entrega) {
            $extencions[] = 'ventas_con_fecha_de_entrega';
        }
        
        if ($request->use_deposits) {
            // $seeders[] = 'AddressSeeder';
            $this->crear_depositos($request);
            $extencions[] = 'deposit_movements';
        }

        if ($request->use_price_lists) {
            // $seeders[] = 'PriceTypeSeeder';
            
            $this->crear_price_types($request);
            $extencions[] = 'articulo_margen_de_ganancia_segun_lista_de_precios';
            $extencions[] = 'cambiar_price_type_en_vender';
            $extencions[] = 'cambiar_price_type_en_vender_item_por_item';
        }


        if (!$request->usar_codigos_de_barra) {
            $extencions[] = 'no_usar_codigos_de_barra';
        }

        // if ($request->budgets) {
        //     $extencions[] = 'budgets';
        // }

        if ($request->cajas) {
            $extencions[] = 'cajas';
            $seeders[] = 'CajaSeeder';
            Log::info('Se agrego caja seeder');
        }

        if ($request->consultora_de_precios) {
            $extencions[] = 'consultora_de_precios';
        }

        if ($request->imagenes) {
            $extencions[] = 'imagenes';
        }



        // Agregá más lógicas según el tipo de negocio
        if ($request->business_type === 'ferreteria') {
            $extencions[] = 'unidades_individuales_en_articulos';
        } 

        Artisan::call('db:seed', ['--class' => 'ExtencionSeeder', '--force' => true]);

        $extModels = ExtencionEmpresa::whereIn('slug', $extencions)->get();
        $user->extencions()->sync($extModels->pluck('id'));

        // Ejecutar seeders 
        // $seeders[] = 'ArticleSeeder';


        if ($request->produccion) {
            $seeders[] = 'RecipeArticleSeeder';
            $seeders[] = 'RecipeSeeder';
        }

        $seeders[] = 'SaleDemoSeeder';
        
        foreach ($seeders as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\sales\\SaleReporteSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\sales\\SaleReporteArticuloSeeder', '--force' => true]);

        Artisan::call('set_company_performances', ['--historico' => true]);


        $this->tienda();


        // Redirigir con mensaje
        return redirect()->route('demo.form')->with('status', 'Demo creada correctamente.');
    }

    function tienda() {

        $online_configuration = [
            'online_price_type_id'          => 3,
            'register_to_buy'               => 1,
            'scroll_infinito_en_home'       => 1,
            'default_article_image_url'     => 'http://empresa.local:8000/storage/169705209718205.jpg',
            'pausar_tienda_online'          => 0,
            'user_id'                       => config('app.USER_ID'),
            'facebook'                      => 'htts://facebook.com',
            'instagram'                     => 'htts://instagram.com',
            'mensaje_contacto'              => 'Comunicate con nosotros',
        ];

        OnlineConfiguration::create($online_configuration);
    }

    function puntos_de_venta_afip($user) {


        // RRII
        AfipInformation::create([
            'iva_condition_id'      => 1,
            'razon_social'          => 'Empresa de '.$user->company_name,
            'domicilio_comercial'   => 'Pellegrini 1876',
            'cuit'                  => '20381712010',
            'punto_venta'           => 4,
            'ingresos_brutos'       => '20381712010',
            'inicio_actividades'    => Carbon::now()->subYears(5),
            'user_id'               => $user->id,
            'description'           => 'Responsable Inscripto',
        ]);


        // Monotributista
        AfipInformation::create([
            'iva_condition_id'      => 2,
            'razon_social'          => 'Empresa de '.$user->company_name,
            'domicilio_comercial'   => 'Pellegrini 1876',
            'cuit'                  => '20381712010',
            'punto_venta'           => 4,
            'ingresos_brutos'       => '20381712010',
            'inicio_actividades'    => Carbon::now()->subYears(5),
            'user_id'               => $user->id,
            'description'           => 'Monotributista',
        ]);
    }

    function crear_depositos($request) {
        for ($i=1; $i <= 3 ; $i++) {

            $address = $request->{'address_'.$i}; 
            
            if ($address != '') {
                Log::info('creando address '.$address);
                Address::create([
                    'street'    => $address,
                    'user_id'   => config('app.USER_ID'),
                ]); 
            }
        }
    }

    function crear_price_types($request) {
        for ($i=1; $i <= 3 ; $i++) {

            $price_type = $request->{'price_type_'.$i}; 
            
            if ($price_type != '') {
                Log::info('creando lista de precios '.$price_type);
                PriceType::create([
                    'num'           => $i,
                    'name'          => $price_type,
                    'percentage'    => 5 * $i,
                    'position'      => $i,
                    'ocultar_al_publico'    => 0,
                    'user_id'       => config('app.USER_ID'),
                ]); 
            }
        }
    }
}
