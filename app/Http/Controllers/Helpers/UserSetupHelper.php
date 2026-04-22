<?php

namespace App\Http\Controllers\Helpers;

use App\Models\ExtencionEmpresa;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Helper que concentra la lógica de "configurar un sistema para un cliente real":
 * migrar, crear el User con sus datos reales, asignar extensiones y correr
 * los seeders de datos de referencia (sin datos de demo).
 *
 * Se extrajo de UserSetupController::setup para poder reutilizarla desde el
 * endpoint admin-sync/user-setup que dispara admin-api al promover un Lead
 * a Cliente.
 *
 * Diferencias principales con DemoSetupHelper:
 * - Usa datos reales del array $data (user_id, doc_number, email, phone, total_a_pagar).
 * - No corre SaleDemoSeeder ni SaleReporteSeeder (base limpia de producción).
 * - No crea puntos de venta AFIP de ejemplo.
 * - No llama a set_company_performances.
 * - Respeta el user_id que llega en el payload (no usa config('app.USER_ID')).
 *
 * Nota: ejecuta `migrate:fresh`, por lo tanto vacía toda la base del sistema
 * destino. Solo debe correrse sobre instancias recién instaladas.
 */
class UserSetupHelper
{
    /**
     * Ejecuta el setup completo de un sistema de producción.
     *
     * @param array<string, mixed> $data Claves esperadas (las opcionales se asumen falsy):
     *                                   user_id (required), user_name, company_name,
     *                                   doc_number, email, phone, total_a_pagar,
     *                                   business_type (required),
     *                                   use_deposits, use_price_lists, iva_included,
     *                                   ask_amount_in_vender, redondear_centenas_en_vender,
     *                                   omitir_cuentas_corrientes, ventas_con_fecha_de_entrega,
     *                                   cajas, usar_codigos_de_barra, codigos_de_barra_por_defecto,
     *                                   consultora_de_precios, imagenes, produccion,
     *                                   address_1..3, price_type_1..3
     *
     * @return User Usuario creado
     */
    public static function run(array $data)
    {
        // `migrate:fresh` limpia la base antes de cualquier inserción
        Artisan::call('migrate:fresh', ['--force' => true]);

        // Crear el usuario dueño del sistema con sus datos reales
        $user = self::create_user($data);

        // Extensiones y seeders se arman dinámicamente según los flags del form
        $extencions = self::base_extencions();
        $seeders = self::base_seeders();

        self::apply_business_type_rules($data, $extencions, $seeders);
        self::apply_flag_rules($data, $extencions, $seeders);

        // El ExtencionSeeder debe existir antes del sync para tener los registros
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

        foreach ($seeders as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        // Tienda online por defecto para que el sistema tenga URL pública
        self::tienda();

        return $user;
    }

    /**
     * Crea el registro User con los datos reales del cliente.
     * A diferencia del DemoSetupHelper, usa user_id, doc_number, email y phone
     * del array de datos en lugar de valores hardcodeados de demo.
     *
     * @param array<string, mixed> $data
     *
     * @return User
     */
    private static function create_user(array $data)
    {
        return User::create([
            'id'                            => $data['user_id'] ?? null,
            'api_url'                       => config('app.APP_URL').'/public',
            'name'                          => $data['user_name'] ?? null,
            'use_archivos_de_intercambio'   => 0,
            'company_name'                  => $data['company_name'] ?? null,
            'image_url'                     => 'https://api-demo.comerciocity.com/public/storage/174292591094040.png',
            'doc_number'                    => $data['doc_number'] ?? null,
            'impresora'                     => 'comerciocity',
            'email'                         => $data['email'] ?? null,
            'phone'                         => $data['phone'] ?? null,
            'sale_ticket_description'       => null,
            'password'                      => bcrypt('1234'),
            'visible_password'              => null,
            'dollar'                        => 1000,
            'home_position'                 => 1,
            'download_articles'             => 0,
            'online'                        => null,
            'payment_expired_at'            => Carbon::now()->addDays(20),
            'total_a_pagar'                 => $data['total_a_pagar'] ?? null,
            'plan_id'                       => null,
            'plan_discount'                 => null,
            'article_ticket_info_id'        => 1,
            'estable_version'               => null,
            'iva_included'                  => !empty($data['iva_included']) ? 1 : 0,
            'ask_amount_in_vender'          => !empty($data['ask_amount_in_vender']) ? 1 : 0,
            'redondear_centenas_en_vender'  => !empty($data['redondear_centenas_en_vender']) ? 1 : 0,
            // omitir_cuentas_corrientes llega como booleano directo (al revés del demo)
            'siempre_omitir_en_cuenta_corriente' => !empty($data['omitir_cuentas_corrientes']) ? 1 : 0,
            'base_de_datos'                 => 'empresa_prueba_1',
            'google_custom_search_api_key'  => 'AIzaSyB8e-DlJMtkGxCK29tAo17lxBKStXtzeD4',
            'google_cuota'                  => 10,
        ]);
    }

    /**
     * Extensiones de base que todos los sistemas de producción reciben.
     * Son menos que en demo (sin 'online', sin datos de ejemplo).
     *
     * @return string[]
     */
    private static function base_extencions()
    {
        return [
            'comerciocity_interno',
            'ask_save_current_acount',
        ];
    }

    /**
     * Seeders de datos de referencia que siempre corren en un sistema nuevo.
     * No incluye seeders de datos de demo (Clients, Providers, Sales, etc.).
     *
     * @return string[]
     */
    private static function base_seeders()
    {
        return [
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
            'MonedaSeeder',

            'IvaConditionSeeder',

            'OrderProductionStatusSeeder',
            'CurrentAcountPaymentMethodSeeder',
            'BudgetStatusSeeder',

            'ArticleTicketInfoSeeder',

            'UnidadFrecuenciaSeeder',

            'ConceptoMovimientoCajaSeeder',

            'AfipTipoComprobanteSeeder',

            'ColorSeeder',
            'ArticlePropertyTypeSeeder',
            'ArticlePropertyValueSeeder',
            'ArticlePropertySeeder',

            'ProductionBatchStatusSeeder',
            'ProductionBatchMovementTypeSeeder',
            'RecipeRouteTypeSeeder',
        ];
    }

    /**
     * Aplica seeders y extensiones según el tipo de negocio.
     *
     * @param array<string, mixed> $data
     * @param string[]             $extencions Referencia: se mutan extensiones a aplicar
     * @param string[]             $seeders    Referencia: se mutan seeders a correr
     */
    private static function apply_business_type_rules(array $data, array &$extencions, array &$seeders)
    {
        $type = $data['business_type'] ?? null;

        if ($type === 'ropa') {
            $seeders[] = 'ArticleVariantSeeder';
            $extencions[] = 'article_variants';
        }

        if ($type === 'ferreteria') {
            $extencions[] = 'unidades_individuales_en_articulos';
        }
    }

    /**
     * Aplica extensiones y seeders adicionales según los flags del formulario.
     *
     * @param array<string, mixed> $data
     * @param string[]             $extencions Referencia
     * @param string[]             $seeders    Referencia
     */
    private static function apply_flag_rules(array $data, array &$extencions, array &$seeders)
    {
        if (!empty($data['produccion'])) {
            $extencions[] = 'production';
            $extencions[] = 'production.production_movement';
        }
        if (!empty($data['codigos_de_barra_por_defecto'])) {
            $extencions[] = 'codigos_de_barra_por_defecto';
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

        // budgets y cajas siempre activados en producción (comportamiento del controller original)
        $extencions[] = 'budgets';
        $extencions[] = 'cajas';
        $extencions[] = 'imagenes';

        if (!empty($data['consultora_de_precios'])) {
            $extencions[] = 'consultora_de_precios';
        }
    }

    /**
     * Crea la OnlineConfiguration por defecto para la tienda online del sistema.
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
     * Persiste hasta 3 direcciones de sucursal a partir de los campos address_1..3.
     *
     * @param array<string, mixed> $data
     */
    private static function crear_depositos(array $data)
    {
        // Importamos Address aquí para no cargarla en el namespace global si no se usa
        $addressClass = '\App\Models\Address';
        for ($i = 1; $i <= 3; $i++) {
            $address = $data['address_'.$i] ?? null;
            if (!empty($address)) {
                Log::info('UserSetupHelper: creando address '.$address);
                $addressClass::create([
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
        // Importamos PriceType aquí para no cargarlo si no se usa
        $priceTypeClass = '\App\Models\PriceType';
        for ($i = 1; $i <= 3; $i++) {
            $price_type = $data['price_type_'.$i] ?? null;
            if (!empty($price_type)) {
                Log::info('UserSetupHelper: creando lista de precios '.$price_type);
                $priceTypeClass::create([
                    'num'                => $i,
                    'name'               => $price_type,
                    'percentage'         => 5 * $i,
                    'position'           => $i,
                    'ocultar_al_publico' => 0,
                    'user_id'            => config('app.USER_ID'),
                ]);
            }
        }
    }
}
