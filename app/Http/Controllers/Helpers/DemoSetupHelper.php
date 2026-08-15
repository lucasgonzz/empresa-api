<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\DemoIngresoTokenHelper;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\PdfColumnProfileWhatsappDefaultHelper;
use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\OnlineConfiguration;
use App\Models\PriceType;
use App\Models\User;
use App\Services\DemoEventoEmitter;
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
     * Key historica de Google Custom Search de demos. Queda como respaldo para
     * cuando admin-api no manda 'google_custom_search_api_key' en el payload
     * (llamada directa al endpoint, instalación vieja, o setting todavía sin cargar
     * en admin-spa).
     *
     * TODO (grupo 220, prompt 02): este literal debería pasar a
     * config('services.google_search.api_key') como el resto de los fallbacks de Google
     * Custom Search, pero este archivo lo toca el grupo 218 — no se resuelve acá para no
     * pisarse con esa otra tarea.
     */
    private const GOOGLE_API_KEY_FALLBACK = 'AIzaSyCgzE6haVi8uZnenfAvYJO5hn7m7Cl09Gw';

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
     *                                   address_1..3, price_type_1..3,
     *                                   demo_eventos_token, demo_eventos_url, demo_plan,
     *                                   demo_media_urls (mision 50, las cuatro opcionales)
     *
     * @return User Usuario creado
     */
    public static function run(array $data)
    {
        /**
         * POR QUE ESTAS DOS LINEAS, ANTES DE QUE ALGUIEN LAS "LIMPIE":
         *
         * Este metodo ARRANCA vaciando la base entera con `migrate:fresh` y despues dispara 52
         * `db:seed` mas `set_company_performances --historico`. Medido el 14/8/2026 contra una
         * base virgen, por CLI: 109 segundos, de los cuales 66 son el migrate:fresh, 31,5 los
         * seeders y 4,5 el set_company_performances. O sea que este metodo se pasa del
         * max_execution_time con el que corre PHP en casi cualquier servidor web -- el default
         * que trae PHP de fabrica son 30 segundos.
         *
         * - set_time_limit(0): levanta el techo de PHP, y SOLO el de PHP: no toca el
         *   request_terminate_timeout de FPM ni el read timeout del proxy que haya adelante.
         *   Mismo patron que ya usa este repo en Exports, Imports y PDFs. Va en el helper y no en
         *   el controller para que cubra los DOS puntos de entrada, el de AdminSync y el legacy
         *   de /demo/setup. Comprobado el mismo dia con un control A/B contra `php -S` forzando
         *   max_execution_time=60: sin esta linea el POST muere con "Maximum execution time of 60
         *   seconds exceeded" a los 61,66 s; con ella devuelve 200 a los 64,23 s. Ese 60 es un
         *   valor impuesto para que el control sea reproducible -- la maquina donde se midio
         *   declara 120 en su php.ini --, asi que el numero no es el techo de ningun servidor en
         *   particular: lo que prueba el control es que sin la linea el techo mata al request y
         *   con la linea deja de existir.
         *
         * - ignore_user_abort(true): si el cliente HTTP corta antes (timeout de admin-api, red),
         *   el setup tiene que terminar IGUAL. Cortarlo a mitad no deja la instancia "como
         *   estaba": la deja con la base vaciada o a medio sembrar, porque lo primero que se
         *   ejecuta es el migrate:fresh. Una instancia armada de la que el admin no se entero se
         *   arregla re-consultando; una instancia con la base vacia no se arregla sola.
         */
        set_time_limit(0);
        ignore_user_abort(true);

        // `migrate:fresh` resetea la base. Obligatorio dejarlo limpio antes de los seeders.
        Artisan::call('migrate:fresh', ['--force' => true]);

        // Crear el usuario "dueño" del sistema con datos mayormente de demo
        $user = self::create_demo_user($data);

        /**
         * Seeders y modelos auxiliares usan config('app.USER_ID'); debe coincidir con el owner creado.
         */
        config(['app.USER_ID' => $user->id]);

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

        self::assign_pdf_whatsapp_defaults_for_owner($user->id);

        self::crear_client_con_mail_del_user_demo($data);

        // Reportes pre-calculados que usa el dashboard de ventas
        // Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\sales\\SaleReporteSeeder', '--force' => true]);
        // Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\sales\\SaleReporteArticuloSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ReportesMesSeeder', '--force' => true]);

        // Performance histórica del usuario (costos, márgenes, etc.)
        Artisan::call('set_company_performances', ['--historico' => true]);

        // Tienda online por defecto para que la demo tenga URL pública
        self::tienda();

        // El token de ingreso lo emite admin-api y viaja en el payload. Se guarda aca, al final,
        // porque el migrate:fresh del arranque de este metodo vacia la tabla.
        if (!empty($data['demo_ingreso_token'])) {
            DemoIngresoTokenHelper::guardar(
                $data['demo_ingreso_token'],
                $user->id,
                isset($data['demo_ingreso_token_expira_at']) ? $data['demo_ingreso_token_expira_at'] : null
            );
        }

        /**
         * Canal de eventos de la demo (mision 50). Mismo motivo que el token de ingreso para
         * guardarlo al final: el migrate:fresh del arranque de este metodo vacia la tabla.
         *
         * Las cuatro claves son OPCIONALES. Un payload sin ellas —un lead de la dinamica
         * anterior, o un admin todavia sin desplegar— deja el setup funcionando exactamente
         * igual que antes de esta mision: sin fila, no hay canal, y nada de la mision 50 hace
         * nada en esta instancia.
         */
        $canal = null;

        if (!empty($data['demo_eventos_token']) && !empty($data['demo_eventos_url'])) {
            $canal = DemoTrackingConfigHelper::guardar(
                $data['demo_eventos_token'],
                $data['demo_eventos_url'],
                isset($data['demo_plan']) && is_array($data['demo_plan']) ? $data['demo_plan'] : null,
                isset($data['demo_media_urls']) && is_array($data['demo_media_urls']) ? $data['demo_media_urls'] : null
            );
        }

        /**
         * Aviso de vuelta al admin: "la instancia quedo armada".
         *
         * Mision cruzada `demo-v2-conexion-admin-empresa` (14/8/2026), que corre desde la raiz del
         * pool y toca los dos proyectos a la vez -- no es una tarea numerada de `tareas/`. El
         * informe esta en `claude-comerciocity/informes/20260814-demo-v2-conexion-admin-empresa.md`.
         *
         * POR QUE ESTO NO SE PUEDE SUBIR DE LUGAR NI SIMPLIFICAR:
         *
         * 1. Va AL FINAL, y no arriba junto al resto del setup, por el mismo motivo por el que
         *    estan al final las dos escrituras de aca arriba: este metodo ARRANCA con
         *    `migrate:fresh`, que vacia la base entera. Un evento emitido antes queda borrado
         *    y el admin no se entera de nada.
         *
         * 2. Se usa el RESULTADO de guardar() y no `!empty($data['demo_eventos_token'])`.
         *    Parecen lo mismo y no lo son: guardar() devuelve null cuando el token no entra en
         *    la columna o cuando el insert falla, y en esos casos el canal no existe. Emitir
         *    ahi seria escribir una fila en demo_eventos que nadie va a poder empujar nunca,
         *    porque el push necesita el token y la url que no se guardaron.
         *
         * 3. La condicion tambien es la que sostiene el costo cero en las instancias de los
         *    ~40 clientes REALES. Un cliente real corre este mismo helper al instalarse y su
         *    payload no trae `demo_eventos_token`: entonces $canal queda null, no se llama al
         *    emisor, y no se agrega ni una query. La guarda de adentro del emisor existe igual,
         *    pero no se paga ni siquiera esa.
         *
         * 4. La clave de idempotencia sale del token del canal, que es el identificador del
         *    lead del lado del admin (no viaja lead_id). Con eso, dos corridas del setup contra
         *    el MISMO canal dejan una sola fila en vez de dos avisos del mismo hecho: el uuid
         *    del evento pasa a ser un uuid v5 determinista sobre esa clave y choca contra el
         *    indice unico. Pasa de verdad — admin-api reintenta el RunDemoSetupJob cuando la
         *    respuesta HTTP se corta, que es exactamente el escenario que este evento viene a
         *    cubrir. El token no queda expuesto por esto: la clave se digiere en el uuid v5 y
         *    no se persiste ni se loguea en ningun lado.
         *
         * 5. `datos` lleva el user_id del user demo y NADA mas, igual que el resto de los
         *    eventos de negocio. El admin no necesita el resto y lo que no viaja no se filtra.
         *
         * El envio no le agrega tiempo al POST que el admin esta esperando: el emisor agenda
         * el push en app()->terminating(), o sea despues de que este request ya respondio.
         */
        if (!is_null($canal)) {
            DemoEventoEmitter::emitir(
                'demo.setup.completado',
                null,
                ['user_id' => $user->id],
                (string) $canal->eventos_token
            );
        }

        return $user;
    }

    /**
     * Marca perfiles Remito y Factura comun como predeterminados para WhatsApp del owner.
     *
     * @param int $owner_id
     * @return void
     */
    private static function assign_pdf_whatsapp_defaults_for_owner($owner_id)
    {
        PdfColumnProfileWhatsappDefaultHelper::apply_whatsapp_defaults_for_owner($owner_id, false);
    }

    static function crear_client_con_mail_del_user_demo($data) {
        $client = Client::create([
            'name'      => $data['name'],
            'user_id'   => config('app.USER_ID'),
            'email'     => $data['email'] ?? null,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $client->id);
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
            'img_auto_timeout'              => 2,
            // El /public lo decide ApiUrlHelper según VPS; hardcodearlo dejaba a los clientes en VPS con la columna api_url apuntando a una ruta inexistente.
            'api_url'                       => ApiUrlHelper::public_base(),
            'name'                          => $data['name'] ?? 'Demo',
            'use_archivos_de_intercambio'   => 0,
            'company_name'                  => $data['company_name'] ?? null,
            'image_url'                     => 'https://comerciocity.com/img/logo.95c86b81.jpg',
            'doc_number'                    => $data['doc_number'],
            'impresora'                     => 'XP-80',
            'email'                         => $data['email'],
            'phone'                         => '3444622139',
            'sale_ticket_description'       => '--- Aca iria alguna aclaracion que quieras hacer ---',
            'password'                      => bcrypt($data['doc_number']),
            'visible_password'              => null,
            'dollar'                        => 1000,
            'home_position'                 => 1,
            'download_articles'             => 0,
            'online'                        => $data['online'],
            'payment_expired_at'            => Carbon::now()->addMonths(12),
            'total_a_pagar'                 => 15000,
            'plan_id'                       => null,
            'plan_discount'                 => null,
            'article_ticket_info_id'        => 1,
            'estable_version'               => null,
            'iva_included'                  => !empty($data['iva_included']) ? 1 : 0,
            'ask_amount_in_vender'          => 1,
            'redondear_centenas_en_vender'  => 0,
            'siempre_omitir_en_cuenta_corriente' => 0,
            'base_de_datos'                 => 'empresa_prueba_1',
            // API key de Google Custom Search: la manda admin-api (RunDemoSetupService, configurable
            // desde admin-spa via AdminSetting); si no llega, se usa la key historica de demo.
            'google_custom_search_api_key'  => (isset($data['google_custom_search_api_key']) && trim((string) $data['google_custom_search_api_key']) !== '')
                ? trim((string) $data['google_custom_search_api_key'])
                : self::GOOGLE_API_KEY_FALLBACK,
            // Cuota de Google de la demo: la manda admin-api (RunDemoSetupService, configurable
            // desde admin-spa via AdminSetting); si no llega (llamada directa, instalación vieja), 100.
            'google_cuota'                  => (isset($data['google_cuota']) && is_numeric($data['google_cuota']))
                ? (int) $data['google_cuota']
                : 100,
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
            'enviar_mail_a_clientes',
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
            'TitleSeeder',
            'DeliveryZoneSeeder',
            'UpdateFeatureSeeder',
            'InventoryLinkageScopeSeeder',

            // 'MessageSeeder',

            'ExpenseConceptSeeder',
            'PendingSeeder',

            'EmployeeSeeder',
            'SellerSeeder',
            'ChequeSeeder',

            'ProductionBatchStatusSeeder',
            'ProductionBatchMovementTypeSeeder',
            'RecipeRouteTypeSeeder',

            'SheetTypeSeeder',
            'PdfColumnOptionSeeder',
            'PdfColumnProfileSeeder',
            'PdfColumnProfileComisionesSeeder',
            'InputsSizeSeeder',

            /*
                Defaults del buscador general. Tiene que quedar DESPUÉS de EmployeeSeeder: el
                seeder borra las filas `global_search` de los empleados para que hereden las del
                dueño, y si corriera antes no agarraría a los que la demo acaba de crear.
            */
            'GlobalSearchDefaultsSeeder',
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

        // if ($type === 'ropa') {
        //     $seeders[] = 'ArticlePropertyTypeSeeder';
        //     $seeders[] = 'ArticlePropertyValueSeeder';
        //     $seeders[] = 'ArticlePropertySeeder';
        //     $seeders[] = 'ArticleVariantSeeder';
        //     $seeders[] = 'CategoryIndumentariaSeeder';
        //     $seeders[] = 'ArticleIndumentariaSeeder';
        //     $extencions[] = 'article_variants';
        // } elseif ($type === 'forrajeria') {
        //     $seeders[] = 'CategoryForrajeriaSeeder';
        //     $seeders[] = 'ArticleForrajeriaSeeder';
        // } else {
        //     $seeders[] = 'CategorySeeder';
        //     $seeders[] = 'ArticleSeeder';
        // }

        // Seeder transversal de presupuestos, se encadena al final del bloque de tipo
        $seeders[] = 'FerreteriaArticlesSeeder';
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

        // if (!empty($data['codigos_de_barra_por_defecto'])) {
        //     $extencions[] = 'codigos_de_barra_por_defecto';
        // }

        // if (!empty($data['produccion'])) {
        //     $extencions[] = 'production';
        //     $extencions[] = 'production.production_movement';
        // }

        // if (!empty($data['ventas_con_fecha_de_entrega'])) {
        //     $extencions[] = 'ventas_con_fecha_de_entrega';
        // }

        if (!empty($data['use_deposits'])) {
            $extencions[] = 'deposit_movements';
        }
        
        if (!empty($data['use_price_lists'])) {
            $extencions[] = 'articulo_margen_de_ganancia_segun_lista_de_precios';
            $extencions[] = 'cambiar_price_type_en_vender';
            $extencions[] = 'cambiar_price_type_en_vender_item_por_item';
        }

        // if (empty($data['usar_codigos_de_barra'])) {
        //     $extencions[] = 'no_usar_codigos_de_barra';
        // }

        // if (!empty($data['cajas'])) {
            $extencions[] = 'cajas';
            $seeders[] = 'CajaSeeder';
            // Log::info('DemoSetupHelper: se agrego caja seeder');
        // }

        // if (!empty($data['consultora_de_precios'])) {
        //     $extencions[] = 'consultora_de_precios';
        // }

        // if (!empty($data['imagenes'])) {
            $extencions[] = 'imagenes';
        // }
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
            'pausar_tienda_online'      => 0,
            'user_id'                   => config('app.USER_ID'),
            'facebook'                  => 'htts://facebook.com',
            'instagram'                 => 'htts://instagram.com',
            'mensaje_contacto'          => 'Comunicate con nosotros',


            'default_article_image_url'     => 'https://api.comerciocity.com/public/storage/comerciocity-logo-cuadrado.webp',
            'logo_url'                      => 'https://api.comerciocity.com/public/storage/logo-redondo.png',
            'primary_color'                 => '#005FCC',
            'secondary_color'               => '#333333',
            'text_color'                    => '#EDEDED',
            'hover_text_color'              => '#FFFFFF',
            'background_color'              => '#FFFFFF',
            'titulo_quienes_somos'              => 'Quienes somos',
            'article_description_font_size' => 16,

            'quienes_somos'                 => '<h2>Un equipo cerca tuyo</h2><p>Somos una <strong>pyme ferretera</strong> arraigada en la región: nos mueve el barrio, el obrador y el vecino que arma el trabajo en casa. Trabajamos con <strong>precios competitivos</strong>, stock pensado para lo cotidiano y un equipo que apuesta a la <strong>buena atención</strong>: asesoramiento honesto, respuesta rápida y resolver en el acto lo que hace falta en herramientas, pinturería, electricidad, plomería y más.</p><h2>Precios claros y confianza de verdad</h2><p>No vendemos solo productos: vendemos tranquilidad. Nos ocupamos de orientarte cuando no tenés certeza del repuesto, de recomendar alternativas cuando conviene y de sostener una relación basada en la confianza con mayoristas, cuentas corrientes y vecinos. Esa mezcla de <strong>precio justo</strong>, disponibilidad y trato cercano es lo que nos diferencia día a día.</p><h2>Tu catálogo online y pedidos sin vueltas</h2><p>Nuestra <strong>tienda online</strong> está pensada para ahorrarte tiempo: clientes y cuentas pueden <strong>recorrer el catálogo con rapidez</strong>, comparar referencias y <strong>enviar pedidos de forma práctica</strong>, reduciendo llamadas repetidas y malentendidos en el pedido. Si tu ferretería o casa de materiales vive de la confianza del barrio y querés digitalizar ventas sin complicarte la operación diaria, <strong>un ecommerce como el de ComercioCity</strong> es la herramienta que muchos negocios como el tuyo están buscando para estar a la altura de lo que hoy esperan quienes compran.</p>',

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
            'cuit'                  => '20175018841',
            'punto_venta'           => 4,
            'ingresos_brutos'       => '20175018841',
            'inicio_actividades'    => Carbon::now()->subYears(5),
            'user_id'               => $user->id,
            'description'           => 'Responsable Inscripto',
        ]);

        AfipInformation::create([
            'iva_condition_id'      => 2,
            'razon_social'          => 'Empresa de '.$user->company_name,
            'domicilio_comercial'   => 'Pellegrini 1876',
            'cuit'                  => '20423548984',
            'punto_venta'           => 2,
            'ingresos_brutos'       => '20423548984',
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
