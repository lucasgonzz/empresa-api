<?php

namespace App\Http\Controllers;

use App\Models\ExtencionEmpresa;
use App\Models\Extension;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserSetupController extends Controller
{
    public function form()
    {
        return view('user.setup');
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
            'id'                            => $request->user_id,
            'api_url'                       => env('APP_URL').'/public',
            'name'                          => $request->user_name,
            'use_archivos_de_intercambio'   => 0,
            'company_name'                  => $request->company_name,
            'image_url'                     => 'https://api-demo.comerciocity.com/public/storage/174292591094040.png',
            'doc_number'                    => $request->doc_number,
            'impresora'                     => 'XP-80',
            'email'                         => $request->email,
            'phone'                         => $request->phone,
            'sale_ticket_description'       => null,
            'password'                      => bcrypt('1234'),
            'visible_password'              => null,
            'dollar'                        => 1000,
            'home_position'                 => 1,
            'download_articles'             => 0,
            'online'                        => null,
            'payment_expired_at'            => Carbon::now()->addDays(20),
            'total_a_pagar'                 => $request->total_a_pagar,
            'plan_id'                       => null,
            'plan_discount'                 => null,
            'article_ticket_info_id'        => 1,
            'estable_version'               => null,
            'iva_included'                  => $request->iva_included ? 1 : 0,
            'ask_amount_in_vender'          => $request->ask_amount_in_vender ? 1 : 0,
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
            'google_custom_search_api_key'      => 'AIzaSyB8e-DlJMtkGxCK29tAo17lxBKStXtzeD4',
        ]);



        // Asignar extensiones según configuración
        $extencions = ['comerciocity_interno', 'ask_save_current_acount'];
        
        $seeders = [
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
            'MonedaSeeder',
            
            'IvaConditionSeeder',

            'OrderProductionStatusSeeder',
            'CurrentAcountPaymentMethodSeeder',
            'BudgetStatusSeeder',

            'ArticleTicketInfoSeeder',

            'UnidadFrecuenciaSeeder',

            'ConceptoMovimientoCajaSeeder',

            'AfipTipoComprobanteSeeder',


            // Estos los llamaba despues en el DatabaseSeeder

            // 'CategorySeeder',
            // 'SubCategorySeeder',

            // 'ProviderSeeder',
            // 'ProviderPriceListSeeder',
            'ColorSeeder',
            'ArticlePropertyTypeSeeder',
            'ArticlePropertyValueSeeder',
            'ArticlePropertySeeder',
            // 'DepositSeeder',
            // 'ClientSeeder',
            // 'BuyerSeeder',
            // 'DiscountSeeder',
            // 'SurchageSeeder',
            // 'AddressSeeder',
            // 'ProviderOrderSeeder',
            // 'ProviderPagosSeeder',
            // 'TitleSeeder',
            // 'DeliveryZoneSeeder',
            // 'BudgetSeeder',
            // 'UpdateFeatureSeeder',
            // 'OrderSeeder',
            // 'InventoryLinkageScopeSeeder',
            
            // 'MessageSeeder',

            // 'ExpenseConceptSeeder',
            // 'ExpenseSeeder',
            // 'PendingSeeder',

            // 'EmployeeSeeder',
            // 'SellerSeeder',            
        ];




        if ($request->business_type == 'ropa') {
            $seeders[] = 'ArticleVariantSeeder';

            $extencions[] = 'article_variants';
        }


        if ($request->produccion) {
            $extencions[] = 'production';
            $extencions[] = 'production.production_movement';
        }

        if ($request->codigos_de_barra_por_defecto) {
            $extencions[] = 'codigos_de_barra_por_defecto';
        }

        if ($request->ventas_con_fecha_de_entrega) {
            $extencions[] = 'ventas_con_fecha_de_entrega';
        }
        
        if ($request->use_deposits) {
            // $seeders[] = 'AddressSeeder';
            $extencions[] = 'deposit_movements';
        }

        if ($request->use_price_lists) {
            // $seeders[] = 'PriceTypeSeeder';
            $extencions[] = 'articulo_margen_de_ganancia_segun_lista_de_precios';
        }

        if ($request->cambiar_price_type_en_vender) {
            $extencions[] = 'cambiar_price_type_en_vender';
        }

        if ($request->cambiar_price_type_en_vender_item_por_item) {
            $extencions[] = 'cambiar_price_type_en_vender_item_por_item';
        }

        if (!$request->usar_codigos_de_barra) {
            $extencions[] = 'no_usar_codigos_de_barra';
        }

        if ($request->budgets) {
            $extencions[] = 'budgets';
        }

        if ($request->cajas) {
            $extencions[] = 'cajas';
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

        
        foreach ($seeders as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }


        $this->tienda();

        // Redirigir con mensaje
        return redirect()->route('user.form')->with('status', 'Usuario creado correctamente.');
    }

    function tienda() {

        $online_configuration = [
            'online_price_type_id'          => 3,
            'register_to_buy'               => 1,
            'scroll_infinito_en_home'       => 1,
            'default_article_image_url'     => 'http://empresa.local:8000/storage/169705209718205.jpg',
            'pausar_tienda_online'          => 0,
            'user_id'                       => env('USER_ID'),
            'facebook'                      => 'htts://facebook.com',
            'instagram'                     => 'htts://instagram.com',
            'mensaje_contacto'              => 'Comunicate con nosotros',
        ];

        OnlineConfiguration::create($online_configuration);
    }
}
