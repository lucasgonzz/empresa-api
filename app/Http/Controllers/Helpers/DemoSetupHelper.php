<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\ExtencionEmpresa;
use App\Models\OnlineConfiguration;
use App\Models\PriceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Helper que concentra la lógica de "configurar un sistema para una demo":
 * crear el User, asignar extensiones según los flags del formulario y correr
 * todos los seeders iniciales.
 *
 * Se extrajo desde DemoSetupController::setup para poder reutilizarla desde
 * el nuevo endpoint admin-sync/demo-setup (llamado por admin-api al dar de
 * alta un Lead) sin duplicar lógica.
 *
 * Nota: este helper ejecuta `migrate:fresh`, por lo tanto vacía toda la base
 * del sistema destino. Solo debe correrse en sistemas recién instalados o
 * dedicados a demos.
 */
class DemoSetupHelper
{
    /**
     * Ejecuta el setup completo de una demo para los datos recibidos.
     *
     * @param array<string, mixed> $data Claves esperadas (las opcionales se asumen falsy):
     *                                   user_name, company_name, business_type (required),
     *                                   use_deposits, use_price_lists, iva_included, cajas,
     *                                   usar_codigos_de_barra, codigos_de_barra_por_defecto,
     *                                   consultora_de_precios, imagenes, produccion,
     *                                   ask_amount_in_vender, redondear_centenas_en_vender,
     *                                   usan_cuentas_corrientes, ventas_con_fecha_de_entrega,
     *                                   address_1..3, price_type_1..3
     *
     * @return User Usuario creado
     */
    public static function run(array $data)
    {
        // `migrate:fresh` resetea la base. Obligatorio dejarlo limpio antes de los seeders.
        Artisan::call('migrate:fresh', ['--force' => true]);

        // Crear el usuario "dueño" del sistema con datos mayormente de demo
        $user = self::create_demo_user($data);

        // Puntos de venta de AFIP (RRII + Monotributo) asociados al user
        self::puntos_de_venta_afip($user);

        // Extensiones y seeders se arman dinámicamente según los flags del form
        $extencions = self::base_extencions();
        $seeders = self::base_seeders();

        self::apply_business_type_rules($data, $extencions, $seeders);
        self::apply_flag_rules($data, $extencions, $seeders);

        // El ExtencionSeeder debe correr antes del sync para que existan los registros
        Artisan::call('db:seed', ['--class' => 'ExtencionSeeder', '--force' => true]);

        // Vinculamos las extensiones elegidas al usuario
        $extModels = ExtencionEmpresa::whereIn('slug', $extencions)->get();
        $user->extencions()->sync($extModels->pluck('id'));

        if (!empty($data['use_price_lists'])) {
            $user->listas_de_precio = 1;
            $user->save();
        }

        // Sucursales y listas de precios dependen de inputs del formulario
        if (!empty($data['use_deposits'])) {
            self::crear_depositos($data);
        }
        if (!empty($data['use_price_lists'])) {
            self::crear_price_types($data);
        }

        // Seeders específicos de producción van DESPUÉS de las extensiones
        if (!empty($data['produccion'])) {
            $seeders[] = 'RecipeArticleSeeder';
            $seeders[] = 'RecipeSeeder';
        }

        // Datos de ejemplo de ventas para que la demo tenga movimientos visibles
        $seeders[] = 'SaleDemoSeeder';

        foreach ($seeders as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        // Reportes pre-calculados que usa el dashboard de ventas
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\sales\\SaleReporteSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\sales\\SaleReporteArticuloSeeder', '--force' => true]);

        // Performance histórica del usuario (costos, márgenes, etc.)
        Artisan::call('set_company_performances', ['--historico' => true]);

        // Tienda online por defecto para que la demo tenga URL pública
        self::tienda();

        return $user;
    }

    /**
     * Crea el registro User principal de la demo con defaults tomados del
     * formulario original. Aísla la carga de campos del setup principal.
     *
     * @param array<string, mixed> $data
     *
     * @return User
     */
    private static function create_demo_user(array $data)
    {
        return User::create([
            'id'                            => config('app.USER_ID'),
            'api_url'                       => config('app.APP_URL').'/public',
            'name'                          => $data['user_name'] ?? null,
            'use_archivos_de_intercambio'   => 0,
            'company_name'                  => $data['company_name'] ?? null,
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
            'iva_included'                  => !empty($data['iva_included']) ? 1 : 0,
            'ask_amount_in_vender'          => 1,
            'redondear_centenas_en_vender'  => !empty($data['redondear_centenas_en_vender']) ? 1 : 0,
            'siempre_omitir_en_cuenta_corriente' => !empty($data['usan_cuentas_corrientes']) ? 0 : 1,
            'base_de_datos'                 => 'empresa_prueba_1',
            'google_custom_search_api_key'  => 'AIzaSyCgzE6haVi8uZnenfAvYJO5hn7m7Cl09Gw',
            'google_cuota'                  => 100,
            'listas_de_precio'              => !empty($data['use_price_lists']) ? 1 : 0,
        ]);
    }

    /**
     * Extensiones de base que todas las demos reciben, independientes de los flags.
     *
     * @return string[]
     */
    private static function base_extencions()
    {
        return [
            'comerciocity_interno',
            'ask_save_current_acount',
            'online',
            'costo_en_dolares',
            'budgets',
            'acopios',
            'bar_code_scanner',
        ];
    }

    /**
     * Listado base de seeders que siempre corren en una demo nueva.
     *
     * @return string[]
     */
    private static function base_seeders()
    {
        return [
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
            'CAPaymentMethodTypeSeeder',

            'IvaSeeder',

            'IvaConditionSeeder',

            'OrderProductionStatusSeeder',
            'CurrentAcountPaymentMethodSeeder',
            'BudgetStatusSeeder',

            'ArticleTicketInfoSeeder',

            'UnidadFrecuenciaSeeder',

            'ConceptoMovimientoCajaSeeder',

            'AfipTipoComprobanteSeeder',

            'ProviderSeeder',
            'ProviderPriceListSeeder',
            'ColorSeeder',
            'DepositSeeder',
            'ClientSeeder',
            'BuyerSeeder',
            'DiscountSeeder',
            'SurchageSeeder',
            'ProviderOrderSeeder',
            'ProviderPagosSeeder',
            'TitleSeeder',
            'DeliveryZoneSeeder',
            'UpdateFeatureSeeder',
            'InventoryLinkageScopeSeeder',

            'MessageSeeder',

            'ExpenseConceptSeeder',
            'ExpenseSeeder',
            'PendingSeeder',

            'EmployeeSeeder',
            'SellerSeeder',
            'ChequeSeeder',

            'ProductionBatchStatusSeeder',
            'ProductionBatchMovementTypeSeeder',
            'RecipeRouteTypeSeeder',
        ];
    }

    /**
     * Ajustes específicos por `business_type` (ropa, forrajería, resto).
     *
     * @param array<string, mixed> $data
     * @param string[]             $extencions Referencia: se mutan extensiones a aplicar
     * @param string[]             $seeders    Referencia: se mutan seeders a correr
     */
    private static function apply_business_type_rules(array $data, array &$extencions, array &$seeders)
    {
        $type = $data['business_type'] ?? null;

        if ($type === 'ropa') {
            $seeders[] = 'ArticlePropertyTypeSeeder';
            $seeders[] = 'ArticlePropertyValueSeeder';
            $seeders[] = 'ArticlePropertySeeder';
            $seeders[] = 'ArticleVariantSeeder';
            $seeders[] = 'CategoryIndumentariaSeeder';
            $seeders[] = 'ArticleIndumentariaSeeder';
            $extencions[] = 'article_variants';
        } elseif ($type === 'forrajeria') {
            $seeders[] = 'CategoryForrajeriaSeeder';
            $seeders[] = 'ArticleForrajeriaSeeder';
        } else {
            $seeders[] = 'CategorySeeder';
            $seeders[] = 'ArticleSeeder';
        }

        // Seeder transversal de presupuestos, se encadena al final del bloque de tipo
        $seeders[] = 'BudgetSeeder';

        if ($type === 'ferreteria') {
            $extencions[] = 'unidades_individuales_en_articulos';
        }
    }

    /**
     * Ajusta extensiones y seeders de acuerdo a las checkboxes del formulario.
     *
     * @param array<string, mixed> $data
     * @param string[]             $extencions Referencia
     * @param string[]             $seeders    Referencia
     */
    private static function apply_flag_rules(array $data, array &$extencions, array &$seeders)
    {
        if (!empty($data['codigos_de_barra_por_defecto'])) {
            $extencions[] = 'codigos_de_barra_por_defecto';
        }
        if (!empty($data['produccion'])) {
            $extencions[] = 'production';
            $extencions[] = 'production.production_movement';
        }
        if (!empty($data['ventas_con_fecha_de_entrega'])) {
            $extencions[] = 'ventas_con_fecha_de_entrega';
        }
        if (!empty($data['use_deposits'])) {
            $extencions[] = 'deposit_movements';
        }
        if (!empty($data['use_price_lists'])) {
            $extencions[] = 'articulo_margen_de_ganancia_segun_lista_de_precios';
            $extencions[] = 'cambiar_price_type_en_vender';
            $extencions[] = 'cambiar_price_type_en_vender_item_por_item';
        }
        if (empty($data['usar_codigos_de_barra'])) {
            $extencions[] = 'no_usar_codigos_de_barra';
        }
        if (!empty($data['cajas'])) {
            $extencions[] = 'cajas';
            $seeders[] = 'CajaSeeder';
            Log::info('DemoSetupHelper: se agrego caja seeder');
        }
        if (!empty($data['consultora_de_precios'])) {
            $extencions[] = 'consultora_de_precios';
        }
        if (!empty($data['imagenes'])) {
            $extencions[] = 'imagenes';
        }
    }

    /**
     * Crea la OnlineConfiguration por defecto para que la tienda online
     * asociada al usuario quede operativa.
     */
    private static function tienda()
    {
        $online_configuration = [
            'online_price_type_id'      => 3,
            'register_to_buy'           => 1,
            'scroll_infinito_en_home'   => 1,
            'default_article_image_url' => 'http://empresa.local:8000/storage/169705209718205.jpg',
            'pausar_tienda_online'      => 0,
            'user_id'                   => config('app.USER_ID'),
            'facebook'                  => 'htts://facebook.com',
            'instagram'                 => 'htts://instagram.com',
            'mensaje_contacto'          => 'Comunicate con nosotros',
        ];

        OnlineConfiguration::create($online_configuration);
    }

    /**
     * Alta de los dos puntos de venta AFIP de ejemplo (RRII y Monotributo).
     *
     * @param User $user
     */
    private static function puntos_de_venta_afip($user)
    {
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

    /**
     * Persiste hasta 3 direcciones de sucursal a partir de los campos address_1..3.
     *
     * @param array<string, mixed> $data
     */
    private static function crear_depositos(array $data)
    {
        for ($i = 1; $i <= 3; $i++) {
            $address = $data['address_'.$i] ?? null;
            if (!empty($address)) {
                Log::info('DemoSetupHelper: creando address '.$address);
                Address::create([
                    'street'  => $address,
                    'user_id' => config('app.USER_ID'),
                ]);
            }
        }
    }

    /**
     * Persiste hasta 3 listas de precios a partir de los campos price_type_1..3.
     *
     * @param array<string, mixed> $data
     */
    private static function crear_price_types(array $data)
    {
        for ($i = 1; $i <= 3; $i++) {
            $price_type = $data['price_type_'.$i] ?? null;
            if (!empty($price_type)) {
                Log::info('DemoSetupHelper: creando lista de precios '.$price_type);
                PriceType::create([
                    'num'                 => $i,
                    'name'                => $price_type,
                    'percentage'          => 5 * $i,
                    'position'            => $i,
                    'ocultar_al_publico'  => 0,
                    'user_id'             => config('app.USER_ID'),
                ]);
            }
        }
    }
}
