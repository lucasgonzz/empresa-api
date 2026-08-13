<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Devuelve en UNA sola respuesta todos los catalogos que empresa-spa pide de a uno al iniciar
 * sesion (mision 41, 12/8/2026).
 *
 * EL PROBLEMA QUE RESUELVE, medido: al arrancar, la SPA dispara cerca de 70 requests desde
 * `common-vue/components/download-resources/Index.vue`, con un `for` que tiene `await` adentro, o
 * sea una detras de otra. Con 100 ms de ida y vuelta por request son ~7 segundos de espera pura
 * antes de que el sistema este usable, y un endpoint lento taponea a todos los que vienen atras.
 *
 * ESTE ENDPOINT NO REEMPLAZA A NINGUNO. Los individuales siguen existiendo tal cual: la mision 43,
 * que es la que cambia el front, los necesita como repliegue y para los modelos que quedaron afuera
 * de la whitelist.
 */
class RecursosInicialesController extends Controller
{
    /**
     * 🔴 WHITELIST. Es un array LITERAL y esto no es negociable.
     *
     * El cliente manda nombres de modelo. Si ese string se usara para resolver una clase --con
     * `app('App\Http\Controllers\' . $nombre . 'Controller')`, con `class_exists`, o armando el
     * nombre del modelo a mano-- estariamos dejando que quien llama elija que codigo ejecuta el
     * servidor. No hay forma segura de sanitizar eso, y no hace falta: la lista de catalogos que la
     * SPA pide al iniciar es finita y conocida.
     *
     * Todo lo que no este aca se ignora y vuelve en `no_soportados`. Sin excepciones y sin "por
     * ahora".
     *
     * COMO SE ARMO: se cruzo la lista de `empresa-spa/src/mixins/call_methods.js` (79 modelos)
     * contra las rutas de `routes/api.php` y contra la firma y el cuerpo del `index()` de cada
     * controller. Entraron los 71 cuyo `index()` no recibe parametros, no lee `request()`, no
     * pagina y devuelve la clave `models`. Los 8 que quedaron afuera estan al pie de esta clase,
     * con el motivo de cada uno.
     *
     * PARA AGREGAR UNO: verificar esas cuatro condiciones sobre su `index()`. El test
     * `Tests\Feature\Catalogos\Recursos_iniciales_Test::cada_modelo_de_la_whitelist_devuelve_lo_mismo_que_su_endpoint_individual`
     * recorre esta lista sola, asi que no hay que escribir un test nuevo.
     *
     * @var array
     */
    private static $whitelist = array(
        'address'                                => 'App\Http\Controllers\AddressController',
        'afip_information'                       => 'App\Http\Controllers\AfipInformationController',
        'afip_selected_payment_method'           => 'App\Http\Controllers\AfipSelectedPaymentMethodController',
        'afip_tipo_comprobante'                  => 'App\Http\Controllers\AfipTipoComprobanteController',
        'article_pre_import_range'               => 'App\Http\Controllers\ArticlePreImportRangeController',
        'article_price_type_group'               => 'App\Http\Controllers\ArticlePriceTypeGroupController',
        'article_property_type'                  => 'App\Http\Controllers\ArticlePropertyTypeController',
        'article_property_value'                 => 'App\Http\Controllers\ArticlePropertyValueController',
        'article_ticket_info'                    => 'App\Http\Controllers\ArticleTicketInfoController',
        'article_ubication'                      => 'App\Http\Controllers\ArticleUbicationController',
        'bodega'                                 => 'App\Http\Controllers\BodegaController',
        'brand'                                  => 'App\Http\Controllers\BrandController',
        'budget_status'                          => 'App\Http\Controllers\BudgetStatusController',
        'c_a_payment_method_type'                => 'App\Http\Controllers\CAPaymentMethodTypeController',
        'caja'                                   => 'App\Http\Controllers\CajaController',
        'category'                               => 'App\Http\Controllers\CategoryController',
        'category_price_type_range'              => 'App\Http\Controllers\CategoryPriceTypeRangeController',
        'cepa'                                   => 'App\Http\Controllers\CepaController',
        'client_reputation'                      => 'App\Http\Controllers\ClientReputationController',
        'commission'                             => 'App\Http\Controllers\CommissionController',
        'concepto_movimiento_caja'               => 'App\Http\Controllers\ConceptoMovimientoCajaController',
        'concepto_stock_movement'                => 'App\Http\Controllers\ConceptoStockMovementController',
        'condition'                              => 'App\Http\Controllers\ConditionController',
        'cuota'                                  => 'App\Http\Controllers\CuotaController',
        'current_acount_payment_method'          => 'App\Http\Controllers\CurrentAcountPaymentMethodController',
        'current_acount_payment_method_discount' => 'App\Http\Controllers\CurrentAcountPaymentMethodDiscountController',
        'default_payment_method_caja'            => 'App\Http\Controllers\DefaultPaymentMethodCajaController',
        'delivery_day'                           => 'App\Http\Controllers\DeliveryDayController',
        'delivery_zone'                          => 'App\Http\Controllers\DeliveryZoneController',
        'deposit_movement_status'                => 'App\Http\Controllers\DepositMovementStatusController',
        'discount'                               => 'App\Http\Controllers\DiscountController',
        'employee'                               => 'App\Http\Controllers\CommonLaravel\EmployeeController',
        'expense_category'                       => 'App\Http\Controllers\ExpenseCategoryController',
        'expense_concept'                        => 'App\Http\Controllers\ExpenseConceptController',
        'inputs_size'                            => 'App\Http\Controllers\InputsSizeController',
        'inventory_linkage_scope'                => 'App\Http\Controllers\InventoryLinkageScopeController',
        'iva'                                    => 'App\Http\Controllers\IvaController',
        'iva_condition'                          => 'App\Http\Controllers\IvaConditionController',
        'location'                               => 'App\Http\Controllers\LocationController',
        'meli_buying_mode'                       => 'App\Http\Controllers\MeliBuyingModeController',
        'meli_item_condition'                    => 'App\Http\Controllers\MeliItemConditionController',
        'meli_listing_type'                      => 'App\Http\Controllers\MeliListingTypeController',
        'moneda'                                 => 'App\Http\Controllers\MonedaController',
        'online_price_type'                      => 'App\Http\Controllers\OnlinePriceTypeController',
        'online_template'                        => 'App\Http\Controllers\OnlineTemplateController',
        'order_production_status'                => 'App\Http\Controllers\OrderProductionStatusController',
        'order_status'                           => 'App\Http\Controllers\OrderStatusController',
        'pais_exportacion'                       => 'App\Http\Controllers\PaisExportacionController',
        'payment_method'                         => 'App\Http\Controllers\PaymentMethodController',
        'payment_method_type'                    => 'App\Http\Controllers\PaymentMethodTypeController',
        'permission'                             => 'App\Http\Controllers\CommonLaravel\PermissionController',
        'platform'                               => 'App\Http\Controllers\PlatformController',
        'price_type'                             => 'App\Http\Controllers\PriceTypeController',
        'production_batch_movement_type'         => 'App\Http\Controllers\ProductionBatchMovementTypeController',
        'production_batch_status'                => 'App\Http\Controllers\ProductionBatchStatusController',
        'provider_order_status'                  => 'App\Http\Controllers\ProviderOrderStatusController',
        'provincia'                              => 'App\Http\Controllers\ProvinciaController',
        'recipe'                                 => 'App\Http\Controllers\RecipeController',
        'recipe_route_type'                      => 'App\Http\Controllers\RecipeRouteTypeController',
        'sale_channel'                           => 'App\Http\Controllers\SaleChannelController',
        'sale_sender_info'                       => 'App\Http\Controllers\SaleSenderInfoController',
        'sale_status'                            => 'App\Http\Controllers\SaleStatusController',
        'sale_type'                              => 'App\Http\Controllers\SaleTypeController',
        'seller'                                 => 'App\Http\Controllers\SellerController',
        'sub_category'                           => 'App\Http\Controllers\SubCategoryController',
        'surchage'                               => 'App\Http\Controllers\SurchageController',
        'tienda_nube_order_status'               => 'App\Http\Controllers\TiendaNubeOrderStatusController',
        'tipo_envase'                            => 'App\Http\Controllers\TipoEnvaseController',
        'turno_caja'                             => 'App\Http\Controllers\TurnoCajaController',
        'unidad_frecuencia'                      => 'App\Http\Controllers\UnidadFrecuenciaController',
        'unidad_medida'                          => 'App\Http\Controllers\UnidadMedidaController',
    );

    /**
     * Los modelos de call_methods.js que NO entran, con el motivo. La mision 43 los va a seguir
     * pidiendo de a uno, asi que esta lista es parte del contrato con ella:
     *
     * | modelo                  | por que queda afuera                                          |
     * |-------------------------|---------------------------------------------------------------|
     * | article                 | index(Request $request): filtra y pagina segun la request     |
     * | column_position         | index(Request $request)                                       |
     * | pdf_column_option       | index(Request $request)                                       |
     * | pdf_column_profile      | index(Request $request)                                       |
     * | provider                | index(Request $request)                                       |
     * | sale                    | index($modulo, ...): $modulo es OBLIGATORIO                   |
     * | table_column_preference | no tiene index(); su ruta es show/{model_name}/{tipo}         |
     * | order                   | ver el parrafo de abajo: NO es por la firma                   |
     *
     * Los primeros siete se dejan afuera y no se fuerzan: meterlos significaria inventarles valores
     * por defecto a sus parametros, y ahi el payload del masivo dejaria de ser identico al del
     * individual, que es justamente lo unico que este endpoint promete.
     *
     * 🔴 `order` es un caso aparte y conviene leerlo, porque la primera version de este comentario
     * decia "pide fechas" y ERA FALSO: su firma es `index($from_date = null, $until_date = null)`,
     * los dos parametros son opcionales y `index()` sin argumentos corre bien. Lo encontro la
     * verificacion independiente de esta mision leyendo OrderController.
     *
     * Queda afuera igual, por un motivo distinto y deliberado: no es un catalogo. Es una tabla
     * transaccional, sin tope de filas y que crece con el uso del cliente, asi que meterla en la
     * llamada del arranque es exactamente lo que haria explotar el peso de la respuesta --que hoy
     * mide 0,84 MB con los 71-- para el cliente que mas pedidos tiene. Los catalogos son chicos y
     * acotados; `order` no.
     */

    /**
     * Devuelve varios catalogos de una sola vez.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $pedidos = $request->input('models');

        if (!is_array($pedidos)) {
            // Las tres claves de la respuesta 200 van tambien aca, aunque vayan vacias: un cliente
            // que lea `con_error` sin mirar el status no se tiene que comer un undefined. El
            // contrato es el mismo en los dos caminos.
            return response()->json(array(
                'models'         => array(),
                'no_soportados'  => array(),
                'con_error'      => array(),
                'error'          => 'El campo models tiene que ser un array de nombres de modelo.',
            ), 422);
        }

        // 🔴 Deduplicar ANTES de recorrer, y no es una optimizacion: sin esto, un cliente ya
        // autenticado que mande `array('brand') x 5000` dispara 5000 rondas de consultas en un solo
        // request. Con los endpoints individuales eso le costaba 5000 requests --y el throttle y el
        // servidor lo veian--; concentrarlo en uno solo convierte el ahorro de este endpoint en una
        // amplificacion. Lo levanto la verificacion independiente de la mision.
        //
        // `array_unique` alcanza porque la whitelist se compara exacto: dos nombres distintos nunca
        // resuelven al mismo controller. El tope de 200 es holgado --la whitelist tiene 71-- y esta
        // para que un array gigante de basura no cueste ni siquiera el recorrido.
        $pedidos = array_slice(array_unique($pedidos, SORT_REGULAR), 0, 200);

        $models = array();
        $no_soportados = array();
        $con_error = array();

        foreach ($pedidos as $nombre) {
            // El nombre tiene que ser un string: un array o un objeto anidado no puede ser clave de
            // la whitelist, y sin este chequeo `isset` con un array tira warning en PHP 7.4.
            if (!is_string($nombre)) {
                $no_soportados[] = $nombre;
                continue;
            }

            if (!isset(self::$whitelist[$nombre])) {
                $no_soportados[] = $nombre;
                continue;
            }

            // 🔴 Un modelo que revienta NO se lleva puesta la respuesta entera. Sin este try, el
            // arranque completo de la SPA dependeria del peor de los 71 catalogos: es exactamente
            // el modo de falla que el endpoint viene a sacar del camino, y seria peor que el
            // original --alla el que fallaba se llevaba uno solo--.
            //
            // ALCANCE REAL DE ESTA PROTECCION, dicho sin sobrevender: cubre todo lo capturable, o
            // sea excepciones y errores de PHP. NO cubre los fatales que PHP no deja atrapar --el
            // `memory_limit` agotado y el `max_execution_time`--, y esos dos SI pasaron a ser un
            // modo de falla compartido que antes no existia: hoy un OOM se lleva el arranque entero
            // y antes se llevaba un solo catalogo. Medido, el pico es de 62 MB con 5000 localidades
            // sembradas, bien lejos del limite, pero el que agregue modelos pesados a la whitelist
            // tiene que saber que ese es el techo que importa.
            try {
                $controller = app(self::$whitelist[$nombre]);
                $respuesta = $controller->index();
                $models[$nombre] = $this->cuerpoDe($respuesta);
            } catch (\Exception $e) {
                $con_error[] = $nombre;
                Log::error('recursos-iniciales: fallo el modelo ' . $nombre . ': ' . $e->getMessage());
            } catch (\Throwable $e) {
                // 🔴 El segundo catch NO es redundante en PHP 7.4: un TypeError o un Error de
                // llamada a metodo inexistente NO son Exception, asi que sin esto se escapan y
                // tumban la respuesta igual. `catch (Exception | Throwable)` de una sola linea es
                // sintaxis valida en 7.1+, pero se escribe separado para que se lea el porque.
                $con_error[] = $nombre;
                Log::error('recursos-iniciales: error fatal en el modelo ' . $nombre . ': ' . $e->getMessage());
            }
        }

        return response()->json(array(
            'models'        => $models,
            'no_soportados' => $no_soportados,
            'con_error'     => $con_error,
        ), 200);
    }

    /**
     * Saca el cuerpo ya decodificado de la respuesta que devolvio un index().
     *
     * Los index() devuelven `response()->json(['models' => ...])`, asi que lo que se guarda es ese
     * array tal cual: el payload del masivo termina siendo identico al del individual, con los
     * mismos `with`, los mismos scopes y los mismos filtros por usuario. Esa es toda la razon por
     * la que se llama al controller real en vez de reescribir 71 consultas.
     *
     * @param  mixed  $respuesta
     * @return mixed
     */
    private function cuerpoDe($respuesta)
    {
        if ($respuesta instanceof \Illuminate\Http\JsonResponse) {
            return $respuesta->getData(true);
        }

        if ($respuesta instanceof \Symfony\Component\HttpFoundation\Response) {
            return json_decode($respuesta->getContent(), true);
        }

        return $respuesta;
    }

    /**
     * La whitelist, para que la lea el test que compara contra los endpoints individuales.
     *
     * @return array
     */
    public static function whitelist()
    {
        return self::$whitelist;
    }
}
