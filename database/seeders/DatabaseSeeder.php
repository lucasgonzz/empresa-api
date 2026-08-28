<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\Seeders\ExcluirListaDePrecioExcelHelper;
use Database\Seeders\sales\SaleReporteArticuloSeeder;
use Database\Seeders\sales\SaleReporteSeeder;
use Database\Seeders\sales\SaleRoadMapSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // $this->call(ExtencionSeeder::class);
        // $this->call(UserSeeder::class);

        // return;

        $for_user = config('app.FOR_USER');
        Log::info('env user_Id: '.config('app.USER_ID'));

        if (env('FOR_SERVER') == 'la_barraca') {
            $this->call(UserSeeder::class);
            $this->call(SheetTypeSeeder::class);
            $this->call(PdfColumnProfileSeeder::class);
            $this->call(ExtencionSeeder::class);
            $this->call(ExtencionDuplicarPresupuestosSeeder::class);
            $this->call(ExtencionEnviarMailClientesSeeder::class);
            $this->call(ExtencionCrearArticulosDesdeVenderSeeder::class);
            /* Misión 54: describe las extensiones recién sembradas. Esta rama no pasa por common_seeders(). */
            $this->call(ExtencionEmpresaDescriptionSeeder::class);
            $this->call(PermissionSeeder::class);
            // $this->call(FeaturesSeeder::class);
            // $this->call(PlansSeeder::class);
            $this->call(OrderStatusSeeder::class);
            $this->call(ProviderOrderStatusSeeder::class);


            $this->call(ColorSeeder::class);
            $this->call(IvaSeeder::class);
            $this->call(IvaConditionSeeder::class);
            // $this->call(WorkdaySeeder::class);
            $this->call(CAPaymentMethodType::class);
            $this->call(CurrentAcountPaymentMethodSeeder::class);
            $this->call(BudgetStatusSeeder::class);
        } else {

            // Se usan tanto para local y produccion
            $this->common_seeders();    



            $this->call(UserSeeder::class);

            // Engancha al usuario dueño las extensiones de IA que la semilla de datos de
            // prueba necesita activas (grupo seeders-demo-datos-completos, unidad U2). Va
            // aca porque necesita al usuario ya creado (UserSeeder, arriba) y las filas de
            // extencion_empresas ya sembradas (common_seeders(), antes de UserSeeder).
            $this->call(ExtencionesIaUserSeeder::class);

            if ($for_user == 'golo_norte') {

                // Llamo antes para despues poder relacionarlos con las categorias
                $this->call(PriceTypeSeeder::class);

            }


            // Se usan en local y para la version demo
            $this->local_y_demo();



            if ($for_user == 'truvari') {

                if (env('APP_ENV') == 'local') {
                    $this->call(BodegaSeeder::class);
                    $this->call(CepaSeeder::class);
                    $this->call(ArticleSeeder::class);
                    $this->call(PromocionVinotecaSeeder::class);
                }
                // $this->call(DeliveryDaySeeder::class);
                $this->call(ArticlePdfObservationSeeder::class);

            } else if ($for_user == 'colman') {

                $this->call(ArticleSeeder::class);
                $this->call(PriceTypeSeeder::class);
                $this->call(RecipeArticleSeeder::class);
                $this->call(RecipeSeeder::class);

            } else if ($for_user == 'feito') {

                $this->article_variants();
                
                $this->call(ArticleSeeder::class);
                
                $this->call(CurrentAcountPaymentMethodDiscountSeeder::class);
                $this->call(AfipSelectedPaymentMethodSeeder::class);



            } else if ($for_user == 'fenix') {

                $this->call(ArticleSeeder::class);
                $this->call(SaleTypeSeeder::class);
                $this->call(CommissionSeeder::class);
                
            } else if ($for_user == 'pack_descartables') {

                $this->call(PriceTypeSeeder::class);
                $this->call(ArticleSeeder::class);
                // $this->call(CajaSeeder::class);

            } else if ($for_user == 'ferretodo') {

                $this->call(ArticleSeeder::class);
                $this->call(PriceTypeSeeder::class);

            } else if ($for_user == 'ros_mar') {

                $this->call(ArticleSeeder::class);

            } else if ($for_user == 'hipermax') {

                $this->call(ArticleSeeder::class);
                
            } else if ($for_user == 'mza_group') {

                Log::info('mza_group seeder');
                $this->call(PriceTypeSeeder::class);
                $this->call(ArticlesTiendaNubeSeeder::class);
                // $this->call(ArticleSeeder::class);
                // $this->article_variants();
                

            } else if ($for_user == 'bad_girls') {

                Log::info('bad_girls seeder');
                $this->call(PriceTypeSeeder::class);
                $this->call(AddressSeeder::class);
                $this->call(ArticleSeeder::class);
                $this->article_variants();
                

            } else if ($for_user == 'trama') {

                $this->call(PriceTypeSeeder::class);
                $this->call(AddressSeeder::class);
                $this->call(ArticleSeeder::class);
                

            } else if ($for_user == 'electro_lacarra') {

                $this->call(PriceTypeSeeder::class);
                $this->call(ArticleSeeder::class);
                
                ExcluirListaDePrecioExcelHelper::set_articles();

            } else if ($for_user == 'racing_carts') {

                $this->call(AddressSeeder::class);
                $this->call(ArticleUbicationSeeder::class);

                $this->call(PriceTypeSeeder::class);
                $this->call(ArticleSeeder::class);
                // $this->call(ArticleDolarSeeder::class);

                
                $this->call(SaleReporteSeeder::class);
                $this->call(SaleReporteArticuloSeeder::class);

            } else if ($for_user == 'leudinox') {

                $this->call(PriceTypeSeeder::class);
                $this->call(MercadoLibreTokenSeeder::class);
                $this->call(AddressSeeder::class);
                // $this->call(MeliArticleSeeder::class);

            } else if ($for_user == 'san_blas') {

                $this->call(AddressSeeder::class);
                $this->call(ArticleSeeder::class);

            } else if ($for_user == 'arfren') {

                $this->call(ArticleSeeder::class);

            }  else if ($for_user == 'ht5') {

                $this->call(AddressSeeder::class);
                $this->call(ArticleSeeder::class);
                $this->call(ProductionBatchStatusSeeder::class);
                $this->call(ProductionBatchMovementTypeSeeder::class);
                $this->call(RecipeRouteTypeSeeder::class);
                $this->call(OrderProductionStatusSeeder::class);
                $this->call(HT5ProductionSeeder::class);
                $this->call(SaleStatusSeeder::class);
                
            } else {

                if (
                    env('APP_ENV') == 'local'
                    || $for_user == 'demo'
                ) {

                    $this->call(ArticleSeeder::class);

                    /*
                        Misión 63 (siembra-local-igual-a-demo): los cuatro catálogos del módulo
                        de producción. `DemoSetupHelper::base_seeders()` los siembra en TODA
                        demo, sin mirar la casilla `produccion` del formulario, así que una
                        corrida local tiene que tenerlos para quedar igual.

                        Van en ESTA rama y no en `local_y_demo()` a propósito: los cuatro usan
                        `create()` pelado (no son idempotentes) y la rama `ht5` de más arriba ya
                        los llama. Puestos en `local_y_demo()` -- que también corre para un ht5
                        en local -- quedarían duplicados. Esta rama es la del FOR_USER genérico
                        (o sea `demo`), que es la única que nunca los llamó.
                    */
                    $this->call(OrderProductionStatusSeeder::class);
                    $this->call(ProductionBatchStatusSeeder::class);
                    $this->call(ProductionBatchMovementTypeSeeder::class);
                    $this->call(RecipeRouteTypeSeeder::class);
                }
            }

            $this->call(ChequeSeeder::class);
            $this->call(CajaSeeder::class);
            $this->call(TurnoCajaSeeder::class);
            $this->call(FerreteriaArticlesSeeder::class);

            /*
                Misión 63: `BudgetSeeder` bajó a DESPUÉS de FerreteriaArticlesSeeder. Arriba
                corría sin un solo Article en la base, así que sus presupuestos nacían sin
                renglones: medido, local terminaba con 4 filas en `article_budget` (las cuatro
                las ponía después `semilla:datos`) contra las 50 de una demo, donde
                `apply_business_type_rules()` ya lo encadena detrás del catálogo.
            */
            $this->call(BudgetSeeder::class);

            if ($for_user == 'golo_norte') {

                $this->call(ArticleSeeder::class);
                $this->call(ComboSeeder::class);
                $this->call(CategoryPriceTypeRangeSeeder::class);
                $this->call(ArticlePriceTypeGroupSeeder::class);
                $this->call(GolonorteCategoryPriceTypeSeeder::class);
            } 

            $this->call(DeliveryDaySeeder::class);

            // ReportesMesSeeder queda desenganchado (grupo 321, prompt 05): lo reemplaza
            // `php artisan semilla:datos`. Ver el comentario en la cabecera de ese archivo.
            // (develop lo habia enganchado aca en el grupo 318; la decision del 321 es
            // posterior y es la que rige en refractor.)

            if ($for_user == 'truvari') {
                if (env('APP_ENV') == 'local') {
                    $this->call(SaleRoadMapSeeder::class);
                    $this->call(RoadMapSeeder::class);
                    // $this->call(CartSeeder::class);
                }
                $this->call(VentaTerminadaCommissionSeeder::class);
                $this->call(PromocionVinotecaCommissionSeeder::class);
                $this->call(CommissionSeeder::class);
            }
            
            /*
                Misión 63: `OrderSeeder` bajó acá desde `local_y_demo()`, al lado de
                `CartSeeder`, que es el otro seeder que necesita el catálogo ya cargado. Los dos
                leen `Article::take(N)` y los dos tienen que quedar detrás de
                `FerreteriaArticlesSeeder`. En la demo corren en este mismo orden relativo, al
                final de la lista de `DemoSetupHelper::run()`.
            */
            $this->call(OrderSeeder::class);
            $this->call(CartSeeder::class);

            // $this->call(SaleDemoSeeder::class);

            /*
                Misión semilla-datos-en-local: el historial de compra y la actividad de tienda que
                necesita el motor de sugerencias de ofertas los siembra `semilla:datos`, y hasta
                acá ese comando se disparaba SOLO del lado de la demo (`DemoSetupHelper::run()`,
                donde vive la misma llamada). En local había que acordarse de correrlo a mano
                después del `migrate:fresh --seed`, así que una base local recién sembrada mostraba
                el módulo de ofertas vacío -- cero ventas históricas, cero eventos de tracking,
                cero carritos abandonados -- mientras que a un lead en la demo le aparecía lleno.
                Que las dos corridas dejen los mismos datos es justamente lo que cuida
                `tests/Feature/Semilla/AlineacionLocalDemoTest.php`.

                🔴 La condición es LA MISMA, letra por letra, que la de `local_y_demo()`, y eso no
                es coincidencia de estilo: `cargar_catalogo()` TIRA una excepción si no hay Client,
                Provider, Address, ExpenseConcept o Article del `config('semilla.user_id')`, y esas
                cinco cosas se siembran justamente bajo esa condición (las cuatro primeras en
                `local_y_demo()`, los artículos en `FerreteriaArticlesSeeder`). Si las dos guardas
                se separan -- por ejemplo poniendo acá `config('app.env')` mientras allá quedó
                `env('APP_ENV')`, que difieren con `config:cache` activo -- aparece un entorno donde
                esta llamada corre sin catálogo y revienta el `migrate:fresh --seed` ENTERO, porque
                la consola de Artisan corre con `setCatchExceptions(false)`. Se tocan las dos
                juntas o ninguna. Y por lo mismo va DENTRO del `else`: la rama `la_barraca` no pasa
                por `local_y_demo()`, o sea que ahí no hay ni un cliente que buscar.

                🔴 Y va ÚLTIMA, después de `CartSeeder`, por la misma razón por la que del lado de
                la demo va después del `foreach` de seeders: `semilla:datos` no siembra catálogo,
                lo CONSUME (lee artículos, clientes, sucursales y conceptos de gasto ya creados) y
                su último paso, `ActividadTiendaHelper`, calcula saldos y carritos contra las
                ventas que el mismo acaba de generar. Un seeder agregado después de esta línea
                queda fuera de todo lo que la semilla ve.

                Sin `--reset` a propósito, mismo criterio que `DemoSetupHelper`: la base viene de un
                `migrate:fresh`, no hay corrida anterior que limpiar. Y sin mirar el código de
                salida, también igual que allá: la guarda de entorno del propio comando devuelve 1
                sin sembrar nada cuando no corresponde, y eso es lo correcto, no una falla.

                Cuesta minutos. Un dev que necesite un reseed rápido tiene la válvula ya existente:
                `SEMILLA_MESES_ATRAS=1` en su `.env` (ver `config/semilla.php`).
            */
            if (
                env('APP_ENV') == 'local'
                || $for_user == 'demo'
            ) {

                // El tercer argumento reenvía la salida del comando a la consola del `db:seed`:
                // `semilla:datos` tarda minutos y con un `Artisan::call()` pelado el
                // `migrate:fresh --seed` se queda mudo todo ese rato y parece colgado.
                // `isset($this->command)` es el mismo chequeo que hace
                // `Illuminate\Database\Seeder::call()` para escribir sus "Seeding: ...".
                Artisan::call(
                    'semilla:datos',
                    [],
                    isset($this->command) ? $this->command->getOutput() : null
                );
            }

        }

        /*
            Defaults del buscador general (preference_type global_search). Va acá, al final del
            run() y fuera del if/else, y NO adentro de common_seeders(): common_seeders() corre
            ANTES que UserSeeder, así que ahí no habría todavía ningún usuario y el seeder
            sembraría cero filas sin fallar. Al final del run() cubre las dos ramas de una vez.
        */
        $this->call(GlobalSearchDefaultsSeeder::class);
    }

    function local_y_demo() {

        if (
            env('APP_ENV') == 'local'
            || config('app.FOR_USER') == 'demo'
        ) {

            // $this->call(CategorySeeder::class);
            // $this->call(SubCategorySeeder::class);

            $this->call(CuotaSeeder::class);


            // Por alguna rezon, a estos los llamaba despues de los seeders de cada negocio

            $this->call(ProviderSeeder::class);
            $this->call(ProviderDiscountSeeder::class);
            $this->call(ProviderPriceListSeeder::class);
            $this->call(ColorSeeder::class);
            $this->call(DepositSeeder::class);

            // Sucursales antes que los clientes: ClientSeeder reparte address_id entre ellas.
            // Sin esto no hay cajas por sucursal ni ventas repartidas entre locales, que es
            // justamente lo que la semilla de datos de reportes necesita poder mostrar.
            $this->call(AddressSeeder::class);
            $this->call(ClientSeeder::class);
            $this->call(BuyerSeeder::class);
            $this->call(DiscountSeeder::class);
            $this->call(SurchageSeeder::class);
            $this->call(TitleSeeder::class);
            $this->call(DeliveryZoneSeeder::class);
            $this->call(UpdateFeatureSeeder::class);
            /*
                `OrderSeeder` se mudó al final del run() (misión 63, siembra-local-igual-a-demo).
                Acá corría ANTES de FerreteriaArticlesSeeder, así que su `Article::take(10)`
                devolvía la colección vacía y los dos pedidos de demo quedaban con CERO renglones
                -- medido: 2 filas en `orders` y 0 en `article_order`. Ver el bloque del final.
            */
            $this->call(InventoryLinkageScopeSeeder::class);
            
            // $this->call(MessageSeeder::class);

            $this->call(ExpenseCategorySeeder::class);
            $this->call(ExpenseConceptSeeder::class);
            $this->call(PendingSeeder::class);

            $this->call(EmployeeSeeder::class);
            $this->call(SellerSeeder::class);

            $this->call(MeliPlatformConnectorSeeder::class);

            // Sin esto, ContabilidadRepository::iibb_determinado() no encuentra ningún
            // sale_tax activo y la línea de IIBB del Estado de Resultados y de la Posición
            // Fiscal da siempre cero.
            $this->call(SaleTaxSeeder::class);

        }
    }

    function common_seeders() {
        $this->call(SaleChannelSeeder::class);
        $this->call(PlatformSeeder::class);
        $this->call(MonedaSeeder::class);
        $this->call(MeliListingTypeSeeder::class);
        $this->call(MeliBuyingModeSeeder::class);
        $this->call(MeliItemConditionSeeder::class);
        $this->call(ProvinciaSeeder::class);
        $this->call(PaisExportacionSeeder::class);
        $this->call(CheckStatusSeeder::class);
        $this->call(OnlineTemplateSeeder::class);
        $this->call(ExtencionSeeder::class);
        $this->call(ExtencionDuplicarPresupuestosSeeder::class);
        $this->call(ExtencionEnviarMailClientesSeeder::class);
        $this->call(ExtencionCrearArticulosDesdeVenderSeeder::class);
        /* Extensión que habilita, por empresa, el input de nombre personalizado en VENDER. */
        $this->call(ExtencionPersonalizarNombreEnVenderSeeder::class);
        /* Extensión para la importación de artículos asistida por Claude IA. */
        $this->call(ExtencionEmpresaAiExcelImportSeeder::class);
        /* Extensión de la v2 de sugerencias de traslado de stock entre sucursales. */
        $this->call(ExtencionSugerenciasInteligentesSeeder::class);
        /* Extensión del chat con el asistente de IA del negocio (misión chat-ia-y-modulo-ia). */
        $this->call(ExtencionAsistenteIaSeeder::class);
        /* Extensión del seguimiento de comportamiento de compradores en la tienda (misión tracking-buyers-tienda). */
        $this->call(ExtencionTrackingBuyersSeeder::class);
        /* Extensión del motor de sugerencias de compra a proveedores (misión sugerencias-compra-proveedores). */
        $this->call(ExtencionSugerenciasComprasSeeder::class);
        /* Extensión del motor de ofertas personalizadas por cliente (misión motor-de-ofertas-por-cliente). */
        $this->call(ExtencionMotorDeOfertasSeeder::class);
        /* Extensión del escaneo de facturas de compra con IA (misión escaneo-factura-compra). */
        $this->call(ExtencionEscaneoFacturaCompraSeeder::class);
        /* Extensión + permisos del módulo de chats de WhatsApp con clientes (grupo 137). */
        $this->call(ExtencionEmpresaWhatsappSeeder::class);
        $this->call(PermissionEmpresaWhatsappSeeder::class);
        /* Plantillas estándar `cc_cli_*` para las empresas con el bot ya configurado (grupo 137, Prompt 04). */
        $this->call(WhatsappTemplateStandardSeeder::class);
        /*
         * 🔴 `PermissionEmpresaRecordatorioCobroSeeder` NO VA ACÁ, Y NO ES UN OLVIDO (misión
         * recordatorio-cobro-whatsapp). El permiso `alerts.recordatorio_cobro` ya lo siembra
         * `PermissionSeeder`, que se llama unas líneas más abajo. Llamar también al seeder suelto
         * dejaba DOS filas con el mismo slug en toda base nueva: `permission_empresas.slug` no
         * tiene índice único y ningún seeder trunca la tabla, así que el permiso aparecía
         * duplicado en la pantalla de empleados.
         *
         * El seeder suelto SIGUE EXISTIENDO y es el que hay que correr a mano sobre las bases de
         * producción que ya están creadas (usa `firstOrCreate`, es idempotente):
         *   php artisan db:seed --class=PermissionEmpresaRecordatorioCobroSeeder
         *
         * ⚠️ Y la entrada que se saca es ESTA, no la de `PermissionSeeder`:
         * `UserSetupHelper::base_seeders()` incluye `'PermissionSeeder'` pero ninguno de los
         * seeders sueltos, así que un negocio creado desde la app recibe sus permisos SÓLO por
         * `PermissionSeeder`. Sacándolo de allá, el permiso no le llegaría nunca a un negocio
         * nuevo.
         */
        $this->call(ConceptoStockMovementSeeder::class);
        $this->call(UnidadMedidaSeeder::class);
        $this->call(PermissionSeeder::class);
        /*
         * 🔴 `NuevosPermisosListadoSeeder` NO VA ACÁ, Y NO ES UN OLVIDO (misión 63,
         * siembra-local-igual-a-demo). Es el mismo caso que
         * `PermissionEmpresaRecordatorioCobroSeeder`, unas líneas más abajo: sus cuatro slugs
         * (`article.percentage_gain`, `article.provider`, `article.stock_only_sucursal`,
         * `article.stock_min_max`) ya los siembra `PermissionSeeder`, en el segundo array del
         * archivo, el que crea una fila por entrada con `slug => $permission['en']`.
         *
         * Como `permission_empresas.slug` no tiene índice único y ningún seeder trunca la tabla,
         * llamarlo también acá dejaba los cuatro permisos DUPLICADOS en la pantalla de empleados
         * de toda base nueva. Medido el 25/8/2026 en `empresa_testing_s2`: una corrida local
         * terminaba con 139 filas y el mismo sistema armado por `DemoSetupHelper` -- que nunca
         * llamó a este seeder -- con 133. Las seis de diferencia son estas cuatro duplicadas más
         * las dos de `PermissionEmpresaWhatsappSeeder`, que sí faltaban del lado de la demo.
         *
         * El seeder suelto sigue existiendo para las bases de producción viejas que se crearon
         * antes de que `PermissionSeeder` incluyera los cuatro, y se lo pasó a `firstOrCreate`
         * en la misma misión para que correrlo ahí tampoco duplique:
         *   php artisan db:seed --class=NuevosPermisosListadoSeeder
         */
        $this->call(OrderStatusSeeder::class);
        $this->call(TiendaNubeOrderStatusSeeder::class);
        $this->call(ProviderOrderStatusSeeder::class);
        $this->call(OnlinePriceTypeSeeder::class);
        $this->call(DepositMovementStatusSeeder::class);
        $this->call(ArticlePdfSeeder::class);


        // Estos estaban a lo ultimo
        $this->call(IvaSeeder::class);
        
        $this->call(IvaConditionSeeder::class);

        // $this->call(OrderProductionStatusSeeder::class);
        $this->call(CAPaymentMethodTypeSeeder::class);
        $this->call(CurrentAcountPaymentMethodSeeder::class);
        $this->call(BudgetStatusSeeder::class);

        $this->call(ArticleTicketInfoSeeder::class);

        $this->call(UnidadFrecuenciaSeeder::class);

        $this->call(ConceptoMovimientoCajaSeeder::class);
        $this->call(ConceptoMovimientoCajaCompensacionSeeder::class);

        $this->call(AfipTipoComprobanteSeeder::class);

        /*
            Orden obligatorio: PdfColumnOptionSeeder sincroniza el catalogo global de columnas
            (tabla pdf_column_options, sin user_id) y los TRES seeders de perfiles que siguen lo
            necesitan poblado. PdfColumnProfileSeeder crea los perfiles de venta (Remito, Factura
            comun) y PdfColumnProfileArticleSeeder el de listado de articulos. No reordenar.

            🔴 Son TRES, y el del medio es el que se cae al copiar este bloque. Hasta el 28/8/2026
            PdfColumnProfileArticleSeeder existia SOLO en UserSetupHelper::base_seeders(): faltaba
            aca y en DemoSetupHelper, asi que toda cuenta nacida por esos dos caminos quedaba sin
            plantillas de articulo y el item "PDF tabla (plantillas)" del listado mostraba "Sin
            plantillas". No falla nada ni avisa nadie: la funcion simplemente no tiene contenido.
        */
        $this->call(SheetTypeSeeder::class);
        $this->call(PdfColumnOptionSeeder::class);
        $this->call(PdfColumnProfileSeeder::class);
        $this->call(PdfColumnProfileArticleSeeder::class);
        $this->call(PdfColumnProfileComisionesSeeder::class);
        $this->call(InputsSizeSeeder::class);

        /*
         * Va ÚLTIMO a propósito (misión 54): no inserta extensiones, le escribe la descripción,
         * el módulo y el marcado de desuso a las que ya insertaron los seeders de arriba. Si se
         * lo llamara antes, las que se siembran después quedarían sin describir.
         */
        $this->call(ExtencionEmpresaDescriptionSeeder::class);
    }

    function article_variants() {
        $this->call(ArticlePropertyTypeSeeder::class);
        $this->call(ArticlePropertyValueSeeder::class);
        $this->call(ArticlePropertySeeder::class);

        $this->call(ArticleVariantSeeder::class);
    }
}
