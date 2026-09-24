<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * QUÉ PUEDE CREAR, EDITAR Y BORRAR EL ASISTENTE POR EL CAMINO GENÉRICO, Y CON QUÉ CAMPOS
 * (misión asistente-omnisciente, 21/9/2026, bloque B).
 *
 * El genérico no tiene lógica de negocio propia: al confirmar, EjecutorGenericoIaHelper arma un
 * request igual al de la SPA y llama al MISMO controller que usa la pantalla (`ProviderController@store`,
 * `CategoryController@update`, `SaleController@destroy`...). Lo único que este catálogo decide es
 * QUÉ entidades entran, con QUÉ operaciones y con QUÉ campos, y de dónde sale cada cosa:
 *
 *   - La entidad, su tabla y su controller salen de las rutas de recurso (`POST api/{slug}` →
 *     `@store`, `PUT api/{slug}/{id}` → `@update`, `DELETE api/{slug}/{id}` → `@destroy`), leídas
 *     de Route::getRoutes(). `entidad = str_replace('-', '_', slug)`, tabla = Str::plural(entidad).
 *   - La tabla tiene que existir y estar scopeada por dueño (columna `user_id`): sin eso el
 *     genérico no puede garantizar que un registro sea de quien lo edita.
 *   - Los campos salen de information_schema (tipo, si admite null, si tiene default), menos las
 *     columnas de sistema, menos las sensibles (misma regex que el esquema de lectura), menos las
 *     `solo_lectura` de cada entidad (las que calcula el sistema y la pantalla no deja tipear),
 *     menos las que el controller NO LEE del request.
 *
 * 🔴 CADA ENTIDAD QUE ENTRA ESTÁ REVISADA A MANO, Y LA LISTA SE CIERRA CON UNA GUARDA MECÁNICA.
 * La derivación por rutas encuentra 141 recursos con `store()`. Muchos no son un formulario de
 * ABM sino un flujo (turnos de caja, lotes de producción, sincronizaciones con Mercado Libre,
 * sugerencias de la IA, tickets de soporte), y otros hacen algo hacia afuera al crearse. Por eso
 * la entrada es doble: ENTIDADES (las revisadas, con la nota de qué se miró en su controller) y
 * EXCLUIDAS (las del plan más las técnicas, cada una con su motivo). Lo que la derivación encuentra
 * y no está en ninguna de las dos listas lo devuelve entidades_sin_revisar(), y el test 34 exige
 * que esa lista esté vacía: una ruta de recurso nueva hace fallar el test hasta que alguien la
 * revise y la ponga de un lado o del otro. Nada entra por omisión.
 *
 * 🔴 LO QUE EL CONTROLLER NO LEE, NO SE OFRECE. `ProviderController::store()` lee campo por
 * campo del request (`'phone' => $request->phone`), así que una columna que existe en la tabla
 * pero el controller no lee (`providers.saldo`, `brands.image_url`, `addresses.phone` al crear)
 * nunca llegaría a la fila aunque la tarjeta la muestre. Se lee el cuerpo del método por reflexión,
 * SIN comentarios (un `// $model->stock = $request->stock;` comentado no cuenta), y se marca
 * `no_la_lee_la_pantalla` lo que no aparece. Es una guarda por regex, no un análisis del PHP: un
 * controller que delega el request entero a un helper (`AgendaTareaHelper::validar($request->all())`)
 * puede leer más de lo que la regex ve; para esos casos está LEIDOS_POR_HELPER, curado a mano.
 *
 * 🔴 LAS CLAVES QUE LA PANTALLA SIEMPRE MANDA VAN EN `claves_de_pantalla`. `CategoryController::store()`
 * hace `foreach ($request->price_types ...)` sin preguntar si vino: la SPA manda siempre
 * `price_types: []` (el modelo vacío de src/models/category.js) y con null el `foreach` lanza
 * ErrorException. El genérico no arregla el controller (regla de la misión): manda la clave como la
 * manda la pantalla.
 *
 * Los tipos y las relaciones se derivan con las MISMAS reglas que el esquema de lectura
 * (EsquemaDeDatosIaHelper, bloque A): tinyint(1) → checkbox, numéricos → number, fechas → date,
 * text → textarea, resto → text; una columna `x_id` cuya tabla `xs` existe y tiene nombre es una
 * relación que se acepta POR NOMBRE. Si el esquema de lectura está cargado, sus etiquetas mandan
 * sobre las de acá (una sola forma de nombrar cada campo para el modelo).
 */
class CatalogoDeEscrituraIaHelper
{
    const OP_ALTA = 'alta';

    const OP_EDICION = 'edicion';

    const OP_BAJA = 'baja';

    /** Las tres operaciones, en el orden en que se declaran. */
    const OPERACIONES = [self::OP_ALTA, self::OP_EDICION, self::OP_BAJA];

    /**
     * Columnas que ningún formulario tipea: las escribe el sistema (`num` lo asigna
     * Controller::num(), `user_id` sale de la sesión, `temporal_id` es mecánica del alta con
     * relaciones de la SPA).
     */
    const COLUMNAS_DE_SISTEMA = ['id', 'num', 'user_id', 'created_at', 'updated_at', 'deleted_at', 'temporal_id'];

    /**
     * Columnas sensibles: nunca se leen ni se escriben desde el asistente. Es la misma regex del
     * esquema de lectura (bloque A) para que las dos capas tapen lo mismo.
     */
    const REGEX_SENSIBLES = '/pass|token|secret|api_key|clave|embedding|cert|private|credential|_key$|^key$|hash|cookie|session|access|refresh|bypass/i';

    /**
     * De dónde sale el nombre de una fila, en orden de preferencia. `street` es la sucursal
     * (addresses no tiene `name`), `percentage` es la alícuota de IVA (ivas tampoco).
     */
    const COLUMNAS_DE_NOMBRE = ['name', 'nombre', 'titulo', 'detalle', 'street', 'header', 'value', 'code', 'numero', 'percentage'];

    /**
     * Columnas que se escriben con el nombre de la pantalla ("Ver en Clientes"), por si el
     * esquema de lectura no está cargado. Las etiquetas de A mandan cuando están.
     *
     * @var array<string, string>
     */
    const ETIQUETAS = [
        'name'                      => 'nombre',
        'phone'                     => 'teléfono',
        'email'                     => 'email',
        'mail'                      => 'email',
        'address'                   => 'domicilio',
        'razon_social'              => 'razón social',
        'cuit'                      => 'CUIT',
        'cuil'                      => 'CUIL',
        'dni'                       => 'DNI',
        'observations'              => 'observaciones',
        'description'               => 'descripción',
        'descripcion'               => 'descripción',
        'percentage'                => 'porcentaje',
        'percentage_gain'           => 'margen de ganancia',
        'percentage_gain_blanco'    => 'margen de ganancia en blanco',
        'percentage_commission'     => 'porcentaje de comisión',
        'price'                     => 'precio',
        'cost'                      => 'costo',
        'amount'                    => 'importe',
        'total'                     => 'total',
        'stock_min'                 => 'stock mínimo',
        'bar_code'                  => 'código de barras',
        'provider_code'             => 'código de proveedor',
        'sku'                       => 'SKU',
        'plu'                       => 'PLU',
        'online'                    => 'publicado en la tienda',
        'in_offer'                  => 'en oferta',
        'precio_pausado'            => 'precio pausado',
        'precio_promocional'        => 'precio promocional',
        'default_in_vender'         => 'por defecto en Vender',
        'personalizar_price_en_vender' => 'precio personalizable en Vender',
        'es_insumo'                 => 'es insumo',
        'omitir_en_lista_pdf'       => 'omitir en la lista de precios PDF',
        'unidades_individuales'     => 'unidades individuales',
        'medida'                    => 'medida',
        'unidad_medida_id'          => 'unidad de medida',
        'apply_provider_percentage_gain' => 'aplica el margen del proveedor',
        'aplicar_iva'               => 'aplica IVA',
        'iva_id'                    => 'IVA',
        'iva_condition_id'          => 'condición frente al IVA',
        'cost_in_dollars'           => 'costo en dólares',
        'provider_cost_in_dollars'  => 'costo del proveedor en dólares',
        'costo_mano_de_obra'        => 'costo de mano de obra',
        'provider_id'               => 'proveedor',
        'provider_price_list_id'    => 'lista de precios del proveedor',
        'category_id'               => 'categoría',
        'sub_category_id'           => 'subcategoría',
        'brand_id'                  => 'marca',
        'client_id'                 => 'cliente',
        'seller_id'                 => 'vendedor',
        'price_type_id'             => 'lista de precios',
        'location_id'               => 'localidad',
        'provincia_id'              => 'provincia',
        'address_id'                => 'sucursal',
        'caja_id'                   => 'caja',
        'employee_id'               => 'empleado',
        'moneda_id'                 => 'moneda',
        'expense_concept_id'        => 'subcategoría de gasto',
        'expense_category_id'       => 'categoría de gasto',
        'client_reputation_id'      => 'reputación',
        'current_acount_payment_method_id' => 'método de pago',
        'payment_method_id'         => 'método de pago de la tienda',
        'order_production_status_group_id' => 'grupo de estados',
        'article_property_type_id'  => 'tipo de propiedad',
        'default_afip_information_id' => 'identidad fiscal por defecto',
        'pais_exportacion_id'       => 'país de exportación',
        'bodega_id'                 => 'bodega',
        'cepa_id'                   => 'cepa',
        'tipo_envase_id'            => 'tipo de envase',
        'unidad_frecuencia_id'      => 'unidad de repetición',
        'dolar'                     => 'cotización del dólar',
        'saldo'                     => 'saldo',
        'importe_iva'               => 'importe de IVA',
        'created_at'                => 'fecha',
        'fecha_realizacion'         => 'fecha',
        'detalle'                   => 'detalle',
        'notas'                     => 'notas',
        'street'                    => 'nombre de la sucursal',
        'street_number'             => 'número',
        'city'                      => 'ciudad',
        'province'                  => 'provincia',
        'default_address'           => 'sucursal por defecto',
        'es_deposito_origen'        => 'es depósito de origen',
        'link_google_maps'          => 'link de Google Maps',
        'pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar' => 'pasa las ventas a cuenta corriente sin esperar a facturar',
        'porcentaje_comision_negro' => 'comisión en negro (%)',
        'porcentaje_comision_blanco' => 'comisión en blanco (%)',
        'price_from_cost_mas_iva'   => 'precio desde el costo más IVA',
        'image_url'                 => 'imagen',
        'show_in_pdf_personalizado' => 'se muestra en el PDF personalizado',
        'show_in_vender'            => 'se muestra en Vender',
        'position'                  => 'posición',
        'ocultar_al_publico'        => 'oculta al público',
        'incluir_en_lista_de_precios_de_excel' => 'incluir en la lista de precios de Excel',
        'setear_precio_final'       => 'setea el precio final',
        'se_usa_en_tienda_nube'     => 'se usa en Tienda Nube',
        'se_usa_en_ml'              => 'se usa en Mercado Libre',
        'update_existing_articles_percentage_mode' => 'modo de actualización de los artículos existentes',
        'commission_after_pay_sale' => 'comisión al cobrar la venta',
        'commission_with_iva'       => 'comisión con IVA',
        'monto_fijo'                => 'monto fijo',
        'cantidad_cuotas'           => 'cantidad de cuotas',
        'descuento'                 => 'descuento (%)',
        'recargo'                   => 'recargo (%)',
        'min_amount'                => 'monto mínimo',
        'code'                      => 'código',
        'day_of_week'               => 'día de la semana (0 = domingo)',
        'hora_inicio'               => 'hora de inicio',
        'hora_fin'                  => 'hora de fin',
        'localidad'                 => 'localidad',
        'postal_code'               => 'código postal',
        'codigo_postal'             => 'código postal',
        'min'                       => 'mínimo',
        'max'                       => 'máximo',
        'video_url'                 => 'URL del video',
        'seo_title'                 => 'título SEO',
        'seo_description'           => 'descripción SEO',
        'requires_shipping'         => 'requiere envío',
        'free_shipping'             => 'envío gratis',
        'disponible_tienda_nube'    => 'disponible en Tienda Nube',
        'mercado_libre'             => 'publicado en Mercado Libre',
        'meli_descripcion'          => 'descripción para Mercado Libre',
        'meli_listing_type_id'      => 'tipo de publicación de Mercado Libre',
        'meli_buying_mode_id'       => 'modo de compra de Mercado Libre',
        'meli_item_condition_id'    => 'condición del ítem en Mercado Libre',
        'peso'                      => 'peso',
        'profundidad'               => 'profundidad',
        'ancho'                     => 'ancho',
        'alto'                      => 'alto',
    ];

    /**
     * Cómo se lee el valor de `moneda_id`, que no es una tabla: 1 pesos, 2 dólares.
     */
    const MONEDAS = [1 => 'pesos', 2 => 'dólares'];

    /**
     * LAS ENTIDADES REVISADAS. Cada una con:
     *
     *   etiqueta / singular / genero  → cómo se nombra en las tarjetas ("Nueva categoría").
     *   descripcion                   → una línea para que_puedo_cargar.
     *   operaciones                   → null = las tres que tengan ruta; o la lista permitida.
     *   solo_lectura                  → columnas que el sistema calcula y la pantalla no deja
     *                                   tipear, aunque el controller las lea.
     *   de_sistema_editables          → columnas de sistema que ESTA pantalla sí edita (la fecha
     *                                   de un gasto es created_at). Opcional.
     *   claves_de_pantalla            → claves que la SPA manda siempre y el controller itera.
     *   defaults_de_pantalla          → valor con el que la SPA manda un sí/no que la persona no
     *                                   tocó, cuando NO coincide con el default de la columna
     *                                   (src/models/<modelo>.js). Opcional; el resto va con el
     *                                   default de la columna, o 0.
     *   ruta                          → pantalla donde ver lo registrado ({name, params, texto},
     *                                   la forma que lee AccionCard.vue), o null.
     *   aviso_de_baja / aviso_de_alta → texto fijo de la tarjeta.
     *   extension                     → slug de extensión que tiene que tener el comercio, o null.
     *   revisado                      → qué se miró en el controller (la nota de la revisión).
     *
     * Las etiquetas salen de src/models/<modelo>.js de la SPA (singular_model_name_spanish), con
     * los acentos que el modelo JS no pone.
     *
     * @var array<string, array<string, mixed>>
     */
    const ENTIDADES = [
        'article' => [
            'etiqueta'           => 'artículos',
            'singular'           => 'artículo',
            'genero'             => 'm',
            'descripcion'        => 'Los artículos del catálogo (Listado de artículos). El precio final, el stock y el costo real los calcula el sistema: el stock se mueve por sus pantallas y el precio sale del costo, el margen, el IVA y la lista.',
            'operaciones'        => null,
            'solo_lectura'       => [
                'status', 'stock', 'final_price', 'final_price_blanco', 'previus_final_price', 'final_price_updated_at',
                'costo_real', 'base_margen', 'slug', 'featured', 'stock_updated_at', 'needs_sync_with_tn',
                'tiendanube_product_id', 'tiendanube_variant_id', 'me_li_id', 'meli_category_id', 'meli_category_name',
                'listing_type_id', 'need_sync_to_meli', 'handle', 'chunk_number', 'articles_pages', 'provider_article_id',
                'embedding_generated_at', 'embedding_source_hash', 'condition_id', 'unidades_por_bulto', 'tipo_envase_id',
                'mercado_libre', 'meli_listing_type_id', 'meli_buying_mode_id', 'meli_item_condition_id', 'meli_descripcion',
                'disponible_tienda_nube', 'requires_shipping', 'free_shipping', 'seo_title', 'seo_description', 'video_url',
                'peso', 'profundidad', 'ancho', 'alto', 'espesor', 'modelo', 'pastilla', 'diametro', 'litros', 'cm3', 'calipers', 'juego',
                'provider_price_list_id', 'provider_cost_in_dollars', 'percentage_gain_blanco', 'costo_mano_de_obra',
                /*
                 * `cost_in_dollars` SALIÓ de esta lista el 24/9/2026 (misión
                 * asistente-fotos-barras-y-compras): en demo3 (conv 11) el dueño pidió "costo en
                 * dólares de diez dólares" y el asistente contestó que el alta no tenía ese campo. La
                 * pantalla sí lo tiene (el tilde "costo en dólares" de la ficha) y store()/update() lo
                 * leen. `provider_cost_in_dollars` sigue acá: se carga desde la lista del proveedor.
                 */
            ],
            'claves_de_pantalla' => ['price_types' => [], 'tags' => [], 'price_type_monedas' => [], 'addresses' => [], 'childrens' => []],
            // La ficha nace con "aplica el margen del proveedor" APAGADO aunque la columna tenga default 1.
            'defaults_de_pantalla' => ['apply_provider_percentage_gain' => 0],
            'ruta'               => ['name' => 'article', 'params' => [], 'texto' => 'Ver en el Listado'],
            'aviso_de_baja'      => 'El artículo va a la papelera (se puede restaurar desde ABM > Papelera). Si está publicado en Tienda Nube, también se saca de ahí.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ArticleController: store() y update() leen campo por campo y calculan el precio final (setFinalPrice); update(Request) NO recibe $id (lo lee de $request->id); los dos iteran price_types y tags sin guarda (claves_de_pantalla). destroy() es soft delete + baja en Tienda Nube solo si USA_TIENDA_NUBE. Las columnas de Mercado Libre, Tienda Nube, vinoteca y autopartes quedan solo_lectura: se cargan desde sus pantallas.',
        ],
        'client' => [
            'etiqueta'           => 'clientes',
            'singular'           => 'cliente',
            'genero'             => 'm',
            'descripcion'        => 'Los clientes del comercio (pantalla Clientes). El saldo de cuenta corriente no se edita acá: lo mueven las ventas y los pagos.',
            'operaciones'        => null,
            'solo_lectura'       => ['saldo', 'saldo_pesos', 'saldo_dolares', 'status', 'pagos_checkeados', 'client_pesos_id', 'comercio_city_user_id'],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'client', 'params' => [], 'texto' => 'Ver en Clientes'],
            'aviso_de_baja'      => 'El cliente va a la papelera con su cuenta corriente. Sus ventas quedan.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ClientController: store() crea con num() y sus cuentas corrientes (CreditAccountHelper); update() reasigna todos los campos; destroy() soft delete. Sin efectos hacia afuera.',
        ],
        'provider' => [
            'etiqueta'           => 'proveedores',
            'singular'           => 'proveedor',
            'genero'             => 'm',
            'descripcion'        => 'Los proveedores del comercio (pantalla Proveedores). Cambiar el margen o el dólar de un proveedor recalcula los precios de sus artículos en segundo plano, como desde la pantalla.',
            'operaciones'        => null,
            'solo_lectura'       => ['saldo', 'saldo_pesos', 'saldo_dolares', 'status', 'pagos_checkeados', 'comercio_city_user_id', 'should_update_prices', 'precios_incluyen_iva'],
            'claves_de_pantalla' => ['childrens' => []],
            'ruta'               => ['name' => 'provider', 'params' => [], 'texto' => 'Ver en Proveedores'],
            'aviso_de_baja'      => 'El proveedor va a la papelera. Sus artículos y sus compras quedan.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ProviderController: store() crea con num() y sus cuentas corrientes; update() reasigna los campos y, si cambió percentage_gain o dolar, encola el recálculo de precios (ProcessSetFinalPrices); destroy() soft delete. Los descuentos (provider_discounts) son otro recurso y update() no los toca.',
        ],
        'category' => [
            'etiqueta'           => 'categorías',
            'singular'           => 'categoría',
            'genero'             => 'f',
            'descripcion'        => 'Las categorías (rubros) de artículos, en ABM > Artículos. Cambiar su margen recalcula los precios de sus artículos.',
            'operaciones'        => null,
            'solo_lectura'       => ['provider_category_id', 'tiendanube_category_id', 'image_url'],
            'claves_de_pantalla' => ['price_types' => []],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'articulos', 'sub_view' => 'categorias'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => 'Se borra la categoría con sus subcategorías; los artículos que la tenían quedan sin categoría.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'CategoryController: store()/update() iteran price_types sin guarda (claves_de_pantalla); la sincronización a Tienda Nube está gateada por USA_TIENDA_NUBE y es la misma de la pantalla. destroy() desengancha los artículos y borra las subcategorías.',
        ],
        'sub_category' => [
            'etiqueta'           => 'subcategorías',
            'singular'           => 'subcategoría',
            'genero'             => 'f',
            'descripcion'        => 'Las subcategorías, cada una colgada de su categoría (ABM > Artículos).',
            'operaciones'        => null,
            'solo_lectura'       => ['provider_sub_category_id', 'tiendanube_category_id', 'image_url'],
            'claves_de_pantalla' => ['price_types' => []],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'articulos', 'sub_view' => 'sub-categorias'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => 'Se borra la subcategoría; los artículos que la tenían quedan sin subcategoría.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'SubCategoryController: igual que categorías (price_types sin guarda, Tienda Nube gateada). destroy() desengancha los artículos.',
        ],
        'brand' => [
            'etiqueta'           => 'marcas',
            'singular'           => 'marca',
            'genero'             => 'f',
            'descripcion'        => 'Las marcas de artículos (ABM > Artículos).',
            'operaciones'        => null,
            'solo_lectura'       => ['image_url'],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'articulos', 'sub_view' => 'marcas'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => 'Se borra la marca. Los artículos que la tenían quedan sin marca.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'BrandController: store()/update() leen solo name; destroy() borra y limpia imágenes.',
        ],
        'price_type' => [
            'etiqueta'           => 'listas de precio',
            'singular'           => 'lista de precio',
            'genero'             => 'f',
            'descripcion'        => 'Las listas de precio ("tipos de precio" en ABM > Precios): un porcentaje sobre el precio base. Crear una recalcula los precios de los artículos en segundo plano si la cuenta usa listas.',
            'operaciones'        => null,
            'solo_lectura'       => ['apply_percentage_on_existing_articles'],
            'claves_de_pantalla' => ['categories' => [], 'sub_categories' => [], 'childrens' => []],
            // El formulario nace con "incluir en la lista de precios de Excel" prendido (la columna no tiene default).
            'defaults_de_pantalla' => ['incluir_en_lista_de_precios_de_excel' => 1],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'precios', 'sub_view' => 'tipos-de-precio'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => 'Se borra la lista y los artículos dejan de tener precio en ella.',
            'aviso_de_alta'      => 'Si la cuenta usa listas de precio, los precios de los artículos se recalculan en segundo plano.',
            'extension'          => null,
            'revisado'           => 'PriceTypeController: store() fuerza apply_percentage_on_existing_articles = 1 y encola ProcessSetFinalPrices si el dueño usa listas; store()/update() iteran categories y sub_categories sin guarda (claves_de_pantalla; withAll() las trae para la edición). destroy() desengancha artículos.',
        ],
        'discount' => [
            'etiqueta'           => 'descuentos',
            'singular'           => 'descuento',
            'genero'             => 'm',
            'descripcion'        => 'Los descuentos que se aplican en una venta (ABM > Precios).',
            'operaciones'        => null,
            'solo_lectura'       => ['client_id'],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'precios', 'sub_view' => 'descuentos'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'DiscountController: name y percentage; soft delete.',
        ],
        'surchage' => [
            'etiqueta'           => 'recargos',
            'singular'           => 'recargo',
            'genero'             => 'm',
            'descripcion'        => 'Los recargos que se aplican en una venta (ABM > Precios).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'precios', 'sub_view' => 'recargos'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'SurchageController: name y percentage; soft delete.',
        ],
        'category_price_type_range' => [
            'etiqueta'           => 'rangos de precio por categoría',
            'singular'           => 'rango de precio por categoría',
            'genero'             => 'm',
            'descripcion'        => 'Rangos de cantidad por categoría que asignan una lista de precio (ABM > Precios).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'precios', 'sub_view' => 'rangos-de-precio'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'CategoryPriceTypeRangeController: campos escalares, sin efectos.',
        ],
        'expense_concept' => [
            'etiqueta'           => 'subcategorías de gasto',
            'singular'           => 'subcategoría de gasto',
            'genero'             => 'f',
            'descripcion'        => 'Las subcategorías de gasto (en la pantalla de Gastos se llaman "Sub categoría"), colgadas de una categoría de gasto (ABM > Gastos).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'gastos', 'sub_view' => 'sub-categorías-de-gasto'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => 'Los gastos que la tenían quedan sin subcategoría.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ExpenseConceptController: name y expense_category_id con num(); sin efectos.',
        ],
        'expense_category' => [
            'etiqueta'           => 'categorías de gasto',
            'singular'           => 'categoría de gasto',
            'genero'             => 'f',
            'descripcion'        => 'Las categorías de gasto (ABM > Gastos).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'gastos', 'sub_view' => 'categorias-de-gasto'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ExpenseCategoryController: solo name.',
        ],
        /*
         * Lo trajo `develop` el mismo 21/9 (misión cheques-endoso-y-bancos) y lo denunció el
         * invariante de este catálogo, que es para lo que está: una ruta de recurso nueva no puede
         * quedar sin clasificar. Entra con las tres operaciones porque es un catálogo por dueño con
         * `name` y nada más, igual que las categorías de gasto — y su controller es de los pocos
         * que scopea por dueño en `update()` y `destroy()` por su cuenta (`banco_del_dueno`).
         *
         * La baja es prolija y por eso no lleva aviso propio: `destroy()` deja los cheques que lo
         * tenían con `cheque_banco_id` en null y **el texto del banco intacto**, que es el dato
         * histórico de lo que decía el papel.
         */
        'cheque_banco' => [
            'etiqueta'           => 'bancos de cheques',
            'singular'           => 'banco de cheques',
            'genero'             => 'm',
            'descripcion'        => 'El catálogo de bancos para los cheques (ABM > Tesorería). El asistente además los unifica solo desde los textos ya cargados.',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'tesoreria', 'sub_view' => 'bancos-de-cheques'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ChequeBancoController: solo name. El destroy desasocia los cheques y les deja el texto del banco.',
        ],
        'address' => [
            'etiqueta'           => 'sucursales',
            'singular'           => 'sucursal',
            'genero'             => 'f',
            'descripcion'        => 'Las sucursales del comercio (ABM > Sucursales). El nombre de la sucursal es el campo street. El teléfono y el email se cargan editándola, no al crearla.',
            'operaciones'        => null,
            'solo_lectura'       => ['image_url', 'lat', 'lng', 'buyer_id', 'default_afip_information_id'],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'sucursales', 'sub_view' => 'sucursales'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => 'Se borra la sucursal y el stock que tenía se da de baja con un movimiento por artículo.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'AddressController: store() no lee phone ni email (update() sí); destroy() genera un movimiento de stock negativo por artículo con stock en la sucursal y después la borra.',
        ],
        'location' => [
            'etiqueta'           => 'localidades',
            'singular'           => 'localidad',
            'genero'             => 'f',
            'descripcion'        => 'Las localidades para clientes y proveedores (ABM > Sucursales).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'sucursales', 'sub_view' => 'localidades'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'LocationController: name, provincia_id, codigo_postal; soft delete.',
        ],
        'provincia' => [
            'etiqueta'           => 'provincias',
            'singular'           => 'provincia',
            'genero'             => 'f',
            'descripcion'        => 'Las provincias para clientes y proveedores (ABM > Sucursales).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'sucursales', 'sub_view' => 'provincias'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ProvinciaController: solo name.',
        ],
        'seller' => [
            'etiqueta'           => 'vendedores',
            'singular'           => 'vendedor',
            'genero'             => 'm',
            'descripcion'        => 'Los vendedores con comisión (Clientes > Vendedores).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => ['categories' => []],
            // El formulario nace con "comisión al cobrar la venta" apagada aunque la columna tenga default 1.
            'defaults_de_pantalla' => ['commission_after_pay_sale' => 0],
            'ruta'               => ['name' => 'client', 'params' => ['view' => 'vendedores'], 'texto' => 'Ver en Vendedores'],
            'aviso_de_baja'      => 'Los clientes y las ventas que lo tenían asignado quedan sin vendedor.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'SellerController: store()/update() iteran categories sin guarda (claves_de_pantalla; withAll() las trae para la edición). seller_id es el vendedor superior.',
        ],
        'client_reputation' => [
            'etiqueta'           => 'reputaciones de cliente',
            'singular'           => 'reputación de cliente',
            'genero'             => 'f',
            'descripcion'        => 'Las reputaciones que se le asignan a un cliente (ABM > Ventas).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'ventas', 'sub_view' => 'reputaciones'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ClientReputationController: solo name.',
        ],
        'sale_status' => [
            'etiqueta'           => 'estados de venta',
            'singular'           => 'estado de venta',
            'genero'             => 'm',
            'descripcion'        => 'Los estados por los que pasa una venta (ABM > Ventas).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'ventas', 'sub_view' => 'estados-de-venta'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'SaleStatusController: name, position, description.',
        ],
        'sale_type' => [
            'etiqueta'           => 'tipos de venta',
            'singular'           => 'tipo de venta',
            'genero'             => 'm',
            'descripcion'        => 'Los tipos de venta (ABM > Ventas).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'ventas', 'sub_view' => 'tipos-de-venta'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'SaleTypeController: solo name.',
        ],
        'sale_sender_info' => [
            'etiqueta'           => 'remitentes',
            'singular'           => 'remitente',
            'genero'             => 'm',
            'descripcion'        => 'Los remitentes que se imprimen en un comprobante (ABM > Ventas).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'ventas', 'sub_view' => 'remitentes'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'SaleSenderInfoController: lee con $request->input(); sin efectos.',
        ],
        'dealer' => [
            'etiqueta'           => 'repartidores',
            'singular'           => 'repartidor',
            'genero'             => 'm',
            'descripcion'        => 'Los repartidores (ABM > Ventas).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'ventas', 'sub_view' => 'repartidores'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'DealerController: solo name.',
        ],
        'tipo_envase' => [
            'etiqueta'           => 'tipos de envase',
            'singular'           => 'tipo de envase',
            'genero'             => 'm',
            'descripcion'        => 'Los tipos de envase de los artículos (ABM > Artículos).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'articulos', 'sub_view' => 'tipo-de-envases'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'TipoEnvaseController: solo name.',
        ],
        'article_property_type' => [
            'etiqueta'           => 'tipos de propiedad de artículo',
            'singular'           => 'tipo de propiedad de artículo',
            'genero'             => 'm',
            'descripcion'        => 'Los tipos de propiedad con los que se describen los artículos, por ejemplo "Color" (ABM > Artículos).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'articulos', 'sub_view' => 'tipos-de-propiedad-de-articulo'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ArticlePropertyTypeController: solo name, con num().',
        ],
        'article_property_value' => [
            'etiqueta'           => 'valores de propiedad de artículo',
            'singular'           => 'valor de propiedad de artículo',
            'genero'             => 'm',
            'descripcion'        => 'Los valores de un tipo de propiedad, por ejemplo "Rojo" del tipo "Color" (ABM > Artículos).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'articulos', 'sub_view' => 'valores-de-propiedad-de-articulo'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ArticlePropertyValueController: name y article_property_type_id (la columna value no la lee).',
        ],
        'delivery_zone' => [
            'etiqueta'           => 'zonas de envío',
            'singular'           => 'zona de envío',
            'genero'             => 'f',
            'descripcion'        => 'Las zonas de envío de la tienda online, con su precio (ABM > Tienda online).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'tienda-online', 'sub_view' => 'zonas-de-envio'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'DeliveryZoneController: name, description, price.',
        ],
        'delivery_day' => [
            'etiqueta'           => 'días de entrega',
            'singular'           => 'día de entrega',
            'genero'             => 'm',
            'descripcion'        => 'Los días de la semana en que la tienda online entrega (ABM > Tienda online). day_of_week va de 0 (domingo) a 6 (sábado).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'tienda-online', 'sub_view' => 'dia-de-entrega'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'DeliveryDayController: solo day_of_week.',
        ],
        'cupon' => [
            'etiqueta'           => 'cupones',
            'singular'           => 'cupón',
            'genero'             => 'm',
            'descripcion'        => 'Los cupones de descuento de la tienda online: un código con un monto o un porcentaje y un mínimo de compra (Tienda online > Cupones).',
            'operaciones'        => null,
            'solo_lectura'       => ['type', 'buyer_id', 'valid', 'read', 'expiration_date', 'expiration_days'],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'online', 'params' => ['view' => 'cupones'], 'texto' => 'Ver en Cupones'],
            'aviso_de_baja'      => 'El cupón deja de poder usarse en la tienda.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'CuponController: store() lee amount, percentage, min_amount y code y fuerza type = normal, con num(). Sin efectos hacia afuera.',
        ],
        'bodega' => [
            'etiqueta'           => 'bodegas',
            'singular'           => 'bodega',
            'genero'             => 'f',
            'descripcion'        => 'Las bodegas de los vinos (ABM > Vinoteca).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'vinoteca', 'sub_view' => 'bodegas'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => 'vinoteca',
            'revisado'           => 'BodegaController: solo name. La solapa del ABM existe solo con la extensión vinoteca.',
        ],
        'cepa' => [
            'etiqueta'           => 'cepas',
            'singular'           => 'cepa',
            'genero'             => 'f',
            'descripcion'        => 'Las cepas de los vinos (ABM > Vinoteca).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'vinoteca', 'sub_view' => 'cepas'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => 'vinoteca',
            'revisado'           => 'CepaController: solo name. Solapa con la extensión vinoteca.',
        ],
        'turno_caja' => [
            'etiqueta'           => 'turnos de caja',
            'singular'           => 'turno de caja',
            'genero'             => 'm',
            'descripcion'        => 'Los turnos de caja, con su hora de inicio y de fin en formato HH:MM (ABM > Tesorería).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'tesoreria', 'sub_view' => 'turno-caja'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'TurnoCajaController: name, hora_inicio, hora_fin.',
        ],
        'default_payment_method_caja' => [
            'etiqueta'           => 'cajas por defecto por método de pago',
            'singular'           => 'caja por defecto por método de pago',
            'genero'             => 'f',
            'descripcion'        => 'A qué caja va cada método de pago por defecto, por sucursal y empleado (ABM > Tesorería).',
            'operaciones'        => null,
            'solo_lectura'       => ['moneda_id'],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'tesoreria', 'sub_view' => 'cajas-por-defecto'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'DefaultPaymentMethodCajaController: caja_id, current_acount_payment_method_id, address_id, employee_id. Sin efectos.',
        ],
        'cuota' => [
            'etiqueta'           => 'planes de cuotas',
            'singular'           => 'plan de cuotas',
            'genero'             => 'm',
            'descripcion'        => 'Los planes de cuotas: cantidad, descuento y recargo, por método de pago de la tienda (ABM > Cuenta corriente).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'cuenta-corriente', 'sub_view' => 'cuotas'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'CuotaController: campos escalares. Sin efectos.',
        ],
        'order_production_status' => [
            'etiqueta'           => 'estados de producción',
            'singular'           => 'estado de producción',
            'genero'             => 'm',
            'descripcion'        => 'Los estados por los que pasa una orden de producción (ABM > Producción).',
            'operaciones'        => null,
            'solo_lectura'       => ['optional'],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'produccion', 'sub_view' => 'estados-de-produccion'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'OrderProductionStatusController: name, position y el grupo (0 se guarda como null).',
        ],
        'order_production_status_group' => [
            'etiqueta'           => 'grupos de estados de producción',
            'singular'           => 'grupo de estados de producción',
            'genero'             => 'm',
            'descripcion'        => 'Los grupos que ordenan los estados de producción (ABM > Producción).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'produccion', 'sub_view' => 'grupos-de-estados-de-produccion'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'OrderProductionStatusGroupController: name y position.',
        ],
        'recipe_route_type' => [
            'etiqueta'           => 'tipos de ruta de receta',
            'singular'           => 'tipo de ruta de receta',
            'genero'             => 'm',
            'descripcion'        => 'Los tipos de ruta de las recetas de producción (ABM > Producción).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'produccion', 'sub_view' => 'tipo-de-rutas'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'RecipeRouteTypeController: solo name.',
        ],
        'venta_terminada_commission' => [
            'etiqueta'           => 'comisiones por venta terminada',
            'singular'           => 'comisión por venta terminada',
            'genero'             => 'f',
            'descripcion'        => 'Un monto fijo de comisión por venta terminada para un vendedor (ABM > Comisiones).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'comisiones', 'sub_view' => 'comisiones-por-venta-terminada'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'VentaTerminadaCommissionController: monto_fijo y seller_id.',
        ],
        'promocion_vinoteca_commission' => [
            'etiqueta'           => 'comisiones por promoción',
            'singular'           => 'comisión por promoción',
            'genero'             => 'f',
            'descripcion'        => 'Un monto fijo de comisión por promoción vendida para un vendedor (ABM > Comisiones).',
            'operaciones'        => null,
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'abm', 'params' => ['view' => 'comisiones', 'sub_view' => 'comision-por-promocion'], 'texto' => 'Ver en ABM'],
            'aviso_de_baja'      => null,
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'PromocionVinotecaCommissionController: monto_fijo y seller_id.',
        ],
        /*
         * Las tres con tool dedicada para el alta: por el genérico entran solo con lo que la
         * pantalla deja hacer y ninguna herramienta cubre (ver "Exclusiones del genérico de
         * escritura" en plan.md).
         */
        'expense' => [
            'etiqueta'           => 'gastos',
            'singular'           => 'gasto',
            'genero'             => 'm',
            'descripcion'        => 'Los gastos ya cargados (pantalla Gastos). Para cargar uno nuevo está proponer_gasto. Se ubican por su número.',
            'operaciones'        => [self::OP_EDICION, self::OP_BAJA],
            'solo_lectura'       => ['caja_id', 'current_acount_payment_method_id', 'moneda_id', 'expense_category_id'],
            // La pantalla de Gastos edita la fecha del gasto, que es created_at.
            'de_sistema_editables' => ['created_at'],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'expense', 'params' => [], 'texto' => 'Ver en Gastos'],
            'aviso_de_baja'      => 'Se borra el gasto. La plata que salió de la caja NO se compensa: eso se hace desde la pantalla de Gastos.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'ExpenseController: update() reasigna subcategoría, importe, IVA, observaciones y fecha, fuerza caja_id = 0 y reparte el monto nuevo entre los métodos de pago ya adjuntos; destroy(Request, $id) compensa caja SOLO con compensar_caja = true, que el genérico no manda.',
        ],
        'pending' => [
            'etiqueta'           => 'tareas de la agenda',
            'singular'           => 'tarea de la agenda',
            'genero'             => 'f',
            'descripcion'        => 'Las tareas y vencimientos de la agenda. Crearlas, cambiarlas y marcarlas como hechas tiene sus propias herramientas; por acá solo se borran.',
            'operaciones'        => [self::OP_BAJA],
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'pending', 'params' => [], 'texto' => 'Ver en la Agenda'],
            'aviso_de_baja'      => 'Se borra la tarea con su repetición, si la tenía. Lo que ya se marcó como hecho queda en Realizadas.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'PendingController: destroy() resuelve la tarea por dueño (tarea_de_la_cuenta) y la borra. La edición queda en proponer_cambios_en_tarea, que entiende la repetición.',
        ],
        'sale' => [
            'etiqueta'           => 'ventas',
            'singular'           => 'venta',
            'genero'             => 'f',
            'descripcion'        => 'Las ventas (pantalla Ventas). Por acá solo se anulan; para vender está proponer_venta. Se ubican por su número.',
            'operaciones'        => [self::OP_BAJA],
            'solo_lectura'       => [],
            'claves_de_pantalla' => [],
            'ruta'               => ['name' => 'sale', 'params' => [], 'texto' => 'Ver en Ventas'],
            'aviso_de_baja'      => 'Se anula la venta como desde la pantalla de Ventas: va a la papelera, vuelve al stock lo que descontó, se borra su movimiento de cuenta corriente (salvo que tenga nota de crédito de AFIP) y las comisiones del vendedor. La plata que entró en caja NO se compensa.',
            'aviso_de_alta'      => null,
            'extension'          => null,
            'revisado'           => 'SaleController::destroy(Request, $id) → DeleteSaleHelper::eliminar_venta con candado: soft delete, regresar_stock() por el libro de movimientos, deleteCurrentAcountFromSale salvo nota de crédito AFIP, deleteSellerCommissionsFromSale, puntos revertidos. compensar_caja solo si el request lo manda en true (el genérico no lo manda).',
        ],
    ];

    /**
     * LAS EXCLUIDAS, cada una con su motivo. Las primeras son las del plan ("Exclusiones del
     * genérico de escritura"); las siguientes son técnicas o de flujo, encontradas revisando las
     * 141 rutas con store(). El nombre es el derivado de la ruta (str_replace('-', '_', slug)).
     *
     * @var array<string, string>
     */
    const EXCLUIDAS = [
        // Del plan: tienen tool dedicada.
        'combo'                        => 'tiene su herramienta (proponer_combo); su store() delega el request entero',
        'client_offer'                 => 'tiene su herramienta (proponer_oferta)',
        'provider_order'               => 'compra con factura: tiene su herramienta y su store() abre transacción y toca stock y cuenta corriente',
        'pdf_column_profiles'          => 'diseños de PDF: tienen su herramienta (proponer_cambio_en_diseno_pdf)',
        // Del plan: próximas o fuera del alcance.
        'budget'                       => 'presupuesto: tan complejo como la venta, con renglones',
        'movimiento_caja'              => 'plata de caja',
        'movimiento_entre_caja'        => 'plata de caja',
        'cheque'                       => 'cheques: se cargan desde la pantalla de cobros',
        'order'                        => 'pedidos de la tienda: su store() no lee campos y el pedido nace en la tienda',
        'afip_selected_payment_method' => 'facturación (afip_*)',
        'afip_information'             => 'facturación (afip_*)',
        'employee'                     => 'usuarios y empleados',
        'whatsapp_chats'               => 'whatsapp_*',
        'whatsapp_templates'           => 'whatsapp_*',
        'platform_connector'           => 'credenciales de plataforma',
        'payment_method'               => 'credenciales (public_key, access_token)',
        'deposit_movement'             => 'el stock se mueve por sus pantallas',
        'stock_movement'               => 'el stock se mueve por sus pantallas',
        // Técnicas, de flujo o sin formulario propio (revisadas el 21/9/2026).
        'ai_conversations'             => 'estado del propio asistente',
        'article_pdf'                  => 'impresión: configuración de PDF con su propia pantalla',
        'article_pdf_observation'      => 'impresión: leyenda con imagen para el PDF',
        'article_pre_import_range'     => 'staging de la importación de Excel',
        'article_price_type_group'     => 'se arma con una lista de artículos (relación que el genérico no maneja)',
        'article_ubication'            => 'su store() crea muchas ubicaciones de una lista, no una fila',
        'buyer'                        => 'cuentas de la tienda online con contraseña',
        'caja'                         => 'plata de caja: se crea desde Tesorería con sus usuarios y métodos de pago',
        'caja_liquidacion_config'      => 'tesorería: se configura desde la caja',
        'column_position'              => 'preferencia de importación de Excel',
        'commission'                   => 'se arma con listas de vendedores (relaciones que el genérico no maneja)',
        'condition'                    => 'sin solapa en el ABM actual',
        'color'                        => 'variantes: sin solapa en el ABM actual',
        'size'                         => 'variantes: sin solapa en el ABM actual',
        'deposit'                      => 'sin solapa en el ABM actual',
        'error'                        => 'técnica: registro de errores de la SPA (manda mail)',
        'etiqueta_medidas'             => 'impresión de etiquetas: su store() delega el request',
        'inventory_linkage'            => 'se arma con listas de categorías y toca el catálogo de otro cliente',
        'meli_order'                   => 'Mercado Libre',
        'message'                      => 'mensajes de la tienda online',
        'offer_suggestion'             => 'proceso de la IA (sugerencias de ofertas)',
        'purchase_suggestion'          => 'proceso de la IA (sugerencias de compra)',
        'stock_suggestion'             => 'proceso de la IA (sugerencias de stock)',
        'order_production'             => 'orden de producción: flujo con artículos',
        'payment_plan'                 => 'cobros en cuotas',
        'payment_plan_cuota'           => 'cobros en cuotas',
        'pending_completed'            => 'marcar una tarea como hecha tiene su herramienta',
        'production_batch'             => 'lote de producción: flujo',
        'production_batch_status'      => 'producción: sin solapa en el ABM actual',
        'production_batch_movement_type' => 'producción: sin solapa en el ABM actual',
        'production_movement'          => 'producción: flujo que mueve stock',
        'promocion_vinoteca'           => 'promoción con artículos (relación que el genérico no maneja)',
        'provider_order_afip_ticket'   => 'facturación de compras (afip)',
        'provider_order_scan'          => 'escaneo de factura: flujo',
        'recipe'                       => 'receta con artículos (relación que el genérico no maneja)',
        'resumen_caja'                 => 'tesorería: cierre de caja',
        'road_map'                     => 'hoja de ruta con ventas (relación que el genérico no maneja)',
        'sale_tax'                     => 'impuesto que se aplica a artículos en segundo plano (job)',
        'service'                      => 'servicios de una venta: sin pantalla propia',
        'support_ticket'               => 'soporte: flujo',
        'sync_from_meli_article'       => 'Mercado Libre',
        'sync_from_meli_order'         => 'Mercado Libre',
        'table_column_preferences'     => 'preferencia de pantalla',
        'tag'                          => 'tags: se cargan desde la ficha del artículo',
        'task'                         => 'mensajes internos entre usuarios',
        'tienda_nube_order'            => 'Tienda Nube',
        'title'                        => 'banner de la tienda: su contenido es una imagen',
    ];

    /**
     * Campos que un controller lee A TRAVÉS de otro método y la regex no ve. Curado a mano leyendo
     * ese método: `[entidad => [operacion => [campos]]]`.
     *
     *   - article.cost: ArticleController::store() y update() no asignan `cost` directo; lo hace
     *     set_costo_desde_request($model, $request), que lee `$request->cost` (y `cost_incluye_iva`,
     *     que no es columna) para descomponer el IVA según la condición fiscal.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    const LEIDOS_POR_HELPER = [
        'article' => [
            self::OP_ALTA    => ['cost'],
            self::OP_EDICION => ['cost'],
        ],
    ];

    /**
     * Columnas que son un sí/no aunque en la tabla sean `int` (la SPA las dibuja como checkbox):
     * `[entidad => [columna => 'checkbox']]`.
     *
     * @var array<string, array<string, string>>
     */
    const TIPOS_CURADOS = [
        'article' => [
            'default_in_vender'   => 'checkbox',
            'omitir_en_lista_pdf' => 'checkbox',
        ],
    ];

    /**
     * Obligatorios que la tabla no declara (la columna admite null) pero sin los cuales la fila no
     * sirve: `[entidad => [columnas]]`. Un cupón sin código no se puede usar en la tienda.
     *
     * @var array<string, array<int, string>>
     */
    const OBLIGATORIOS_CURADOS = [
        'cupon' => ['code'],
    ];

    /**
     * Lo que el modelo tiene que saber de un campo y la etiqueta sola no dice: `[entidad => [columna
     * => texto]]`. Viaja en que_puedo_cargar como `descripcion` del campo.
     *
     *   - article.cost_in_dollars (misión asistente-fotos-barras-y-compras, 24/9/2026): es una MARCA,
     *     no un monto. El costo en dólares se carga poniendo el número en `cost` y prendiendo esto;
     *     sin la explicación, el modelo buscaba dónde poner "10 dólares" y no lo encontraba.
     *
     * @var array<string, array<string, string>>
     */
    const DESCRIPCIONES_DE_CAMPOS = [
        'article' => [
            'cost_in_dollars' => 'Marca que dice que `cost` está en dólares (si/no). El número va en `cost`; el precio en pesos sale del dólar del proveedor o, si no tiene, del dólar global del negocio.',
        ],
    ];

    /**
     * Relaciones por convención que no se resuelven con Str::plural del prefijo.
     *
     * @var array<string, array<string, string>>
     */
    const RELACIONES_FIJAS = [
        'employee_id'                      => ['tabla' => 'users', 'campo' => 'name'],
        'owner_id'                         => ['tabla' => 'users', 'campo' => 'name'],
        'iva_id'                           => ['tabla' => 'ivas', 'campo' => 'percentage'],
        'address_id'                       => ['tabla' => 'addresses', 'campo' => 'street'],
        'current_acount_payment_method_id' => ['tabla' => 'current_acount_payment_methods', 'campo' => 'name'],
    ];

    /** @var array<string, mixed>|null Derivación cacheada por proceso. */
    protected static $catalogo = null;

    /** @var array<string, array<string, string>>|null Rutas de recurso: slug => [operacion => 'Clase@metodo']. */
    protected static $rutas = null;

    /** @var array<string, array<int, string>> Claves del request que lee cada método, por 'Clase@metodo'. */
    protected static $leidos = [];

    /** @var array<string, array<int, array>> Tablas que referencian a cada entidad, cacheadas por proceso. */
    protected static $referencias = [];

    /**
     * Tope de filas ESTIMADAS (information_schema.tables.table_rows) de una tabla para contarle las
     * referencias cuando la columna no está indexada.
     *
     * Existe porque casi ninguna de estas columnas tiene índice: de las quince tablas que
     * referencian a `price_type_id`, una sola lo tiene (medido el 21/9/2026 sobre el esquema). Un
     * COUNT sobre una columna sin índice es un scan de la tabla entera, y hay tablas de un cliente
     * grande que no se pueden escanear adentro del request que propone la tarjeta. Con este tope,
     * el peor caso de una tabla es escanear 200.000 filas; las que se pasan no se cuentan y el
     * aviso lo dice con "puede haber más".
     */
    const TOPE_DE_FILAS_SIN_INDICE = 200000;

    /** Tope de tablas a las que se les cuentan referencias, de la más chica a la más grande. */
    const TOPE_DE_TABLAS_A_CONTAR = 15;

    /** Cuántas referencias se nombran en el aviso antes de agrupar el resto. */
    const TOPE_DE_REFERENCIAS_EN_EL_AVISO = 3;

    /**
     * Cómo se nombran las tablas que no son una entidad del catálogo. Una fila de
     * `category_price_type` es una referencia colgada de verdad, pero el nombre de la tabla no le
     * dice nada a nadie: todas se suman en una sola entrada con esta etiqueta.
     */
    const ETIQUETA_INNOMBRABLE = 'vínculos internos';

    // -------------------------------------------------------------------------------------------
    // API pública
    // -------------------------------------------------------------------------------------------

    /**
     * Las entidades escribibles con sus operaciones: [entidad => [etiqueta, etiqueta_singular, operaciones]].
     *
     * @return array<string, array<string, mixed>>
     */
    public static function entidades(): array
    {
        $lista = [];

        foreach (self::catalogo() as $entidad => $declaracion) {

            $lista[$entidad] = [
                'etiqueta'          => $declaracion['etiqueta'],
                'etiqueta_singular' => $declaracion['singular'],
                'operaciones'       => array_keys($declaracion['operaciones']),
            ];
        }

        return $lista;
    }

    /**
     * true si la entidad entra al genérico.
     *
     * @param  string  $entidad
     * @return bool
     */
    public static function existe(string $entidad): bool
    {
        $catalogo = self::catalogo();

        return isset($catalogo[self::normalizar_entidad($entidad)]);
    }

    /**
     * La declaración completa de una entidad, o null si no entra.
     *
     * Trae: tabla, slug, etiqueta, singular, genero, descripcion, columna_nombre, columna_numero,
     * operaciones ([op => [clase, metodo]]), campos ([columna => [tipo, etiqueta, obligatorio,
     * relacion, operaciones, nullable, default]]), no_la_lee_la_pantalla ([columna => operaciones
     * que sí la leen... o vacío]), claves_de_pantalla, ruta, aviso_de_baja, aviso_de_alta, extension.
     *
     * @param  string  $entidad
     * @return array<string, mixed>|null
     */
    public static function declaracion(string $entidad)
    {
        $catalogo = self::catalogo();

        $entidad = self::normalizar_entidad($entidad);

        return isset($catalogo[$entidad]) ? $catalogo[$entidad] : null;
    }

    /**
     * true si la entidad admite la operación por el genérico.
     *
     * @param  string  $entidad
     * @param  string  $operacion
     * @return bool
     */
    public static function permite(string $entidad, string $operacion): bool
    {
        $declaracion = self::declaracion($entidad);

        return !is_null($declaracion) && isset($declaracion['operaciones'][$operacion]);
    }

    /**
     * La pantalla donde ver lo registrado ({name, params, texto}, lo que lee AccionCard.vue), o null.
     *
     * Devuelve un array y no un string a propósito: la SPA navega por NOMBRE de ruta con params
     * (`/abm/:view?/:sub_view?`), y AccionCard.vue ya lee `resultado.ruta.name` / `.params` /
     * `.texto`; un path como string la obligaría a cambiar.
     *
     * @param  string  $entidad
     * @return array<string, mixed>|null
     */
    public static function ruta_de_pantalla(string $entidad)
    {
        $declaracion = self::declaracion($entidad);

        if (is_null($declaracion) || is_null($declaracion['ruta'])) {

            return null;
        }

        $ruta = $declaracion['ruta'];

        return [
            'name'   => $ruta['name'],
            // Un array vacío viajaría como [] y AccionCard lo normaliza; se manda objeto igual.
            'params' => count($ruta['params']) ? $ruta['params'] : new \stdClass(),
            'texto'  => $ruta['texto'],
        ];
    }

    /**
     * El aviso de una baja: lo que dice la declaración, más si la baja es DEFINITIVA, más cuántas
     * filas del dueño van a quedar apuntando a un registro que ya no existe.
     *
     * 🔴 POR QUÉ ESTO NO ES DECORACIÓN (hallazgo 🔴 4 del chequeo adversarial, 21/9/2026). Borrar
     * una lista de precios es un DELETE de verdad —`PriceType` no usa SoftDeletes— y
     * `PriceTypeController::destroy()` sólo desengancha los artículos: los clientes que tenían esa
     * lista quedan con un `price_type_id` que no apunta a nada, sin vuelta atrás. Es la misma clase
     * de dato huérfano que ya tumbó el listado de artículos, y hay una migración
     * (`2026_09_18_120000_normalizar_price_type_id_cero_en_clients`) que existe justamente para
     * limpiar ese destrozo. La tarjeta lo presentaba como una baja cualquiera, con un aviso que
     * hablaba sólo de los artículos.
     *
     * La cuenta es genérica (sale del esquema, no de una lista por entidad) y sólo se paga cuando
     * la baja es definitiva: si la entidad usa SoftDeletes la fila sigue existiendo y nadie queda
     * colgado, así que no hay nada que contar.
     *
     * @param  array  $declaracion
     * @param  object|array  $fila  La fila que se va a borrar (se usa su id).
     * @param  int  $owner_id
     * @return string  El aviso completo, o '' si no hay nada que decir.
     */
    public static function aviso_de_baja(array $declaracion, $fila, $owner_id): string
    {
        $partes = [];

        if (!is_null($declaracion['aviso_de_baja'])) {

            $partes[] = $declaracion['aviso_de_baja'];
        }

        if (!$declaracion['baja_definitiva']) {

            return implode(' ', $partes);
        }

        $partes[] = 'Esta baja es DEFINITIVA: no va a la papelera y no se puede deshacer.';

        $fila = (object) $fila;

        $id = isset($fila->id) ? (int) $fila->id : 0;

        $colgadas = self::referencias_que_quedan_colgadas($declaracion, $id, (int) $owner_id);

        if (count($colgadas['referencias'])) {

            $femenino = $declaracion['genero'] === 'f';

            $partes[] = self::enumerar($colgadas['referencias'])
                . ($femenino ? ' la tienen asignada y van a quedar sin ella' : ' lo tienen asignado y van a quedar sin él')
                . ($colgadas['hay_sin_contar'] ? ' (puede haber más)' : '')
                . '.';
        }

        return implode(' ', $partes);
    }

    /**
     * Cuántas filas del dueño apuntan a este registro, tabla por tabla, leyendo el esquema: toda
     * tabla que tenga una columna `<entidad>_id`.
     *
     * Lo que se cuenta y lo que no:
     *   - Se saltean las filas ya borradas (`deleted_at`), que no quedan colgadas de nada.
     *   - Se scopea por `user_id` donde la tabla lo tenga. Donde no (las tablas pivote), se cuenta
     *     por el id solo: ese id ya se verificó que es del dueño, así que lo que lo referencia es
     *     suyo.
     *   - No se cuentan las tablas grandes sin índice en esa columna (ver TOPE_DE_FILAS_SIN_INDICE):
     *     serían un scan entero adentro del request que propone la tarjeta. Cuando se saltea alguna,
     *     `hay_sin_contar` queda en true y el aviso lo dice.
     *
     * @param  array  $declaracion
     * @param  int  $id
     * @param  int  $owner_id
     * @return array{referencias: array<int, array{tabla: string, etiqueta: string, cantidad: int}>, hay_sin_contar: bool}
     */
    public static function referencias_que_quedan_colgadas(array $declaracion, int $id, int $owner_id): array
    {
        $vacio = ['referencias' => [], 'hay_sin_contar' => false];

        if ($id <= 0) {

            return $vacio;
        }

        $columna = $declaracion['entidad'].'_id';

        $candidatas = self::tablas_que_referencian($columna);

        $referencias = [];
        $hay_sin_contar = false;
        $contadas = 0;

        foreach ($candidatas as $candidata) {

            if ($contadas >= self::TOPE_DE_TABLAS_A_CONTAR) {

                $hay_sin_contar = true;

                break;
            }

            if (!$candidata['indexada'] && $candidata['filas'] > self::TOPE_DE_FILAS_SIN_INDICE) {

                $hay_sin_contar = true;

                continue;
            }

            $contadas++;

            try {

                $consulta = DB::table($candidata['tabla'])->where($columna, $id);

                if ($candidata['tiene_user_id']) {

                    $consulta->where('user_id', $owner_id);
                }

                if ($candidata['tiene_deleted_at']) {

                    $consulta->whereNull('deleted_at');
                }

                $cantidad = (int) $consulta->count();

            } catch (\Throwable $e) {

                // Una tabla que no se puede contar no puede voltear la propuesta de una baja.
                Log::warning('CatalogoDeEscrituraIaHelper: no se pudieron contar las referencias', [
                    'tabla'   => $candidata['tabla'],
                    'columna' => $columna,
                    'error'   => $e->getMessage(),
                ]);

                $hay_sin_contar = true;

                continue;
            }

            if ($cantidad > 0) {

                $referencias[] = [
                    'tabla'    => $candidata['tabla'],
                    'etiqueta' => self::etiqueta_de_tabla($candidata['tabla']),
                    'cantidad' => $cantidad,
                ];
            }
        }

        usort($referencias, function ($a, $b) {

            return $b['cantidad'] - $a['cantidad'];
        });

        return ['referencias' => self::agrupar_las_innombrables($referencias), 'hay_sin_contar' => $hay_sin_contar];
    }

    /**
     * Clase y método del controller que atiende la operación, resueltos desde la ruta.
     *
     * @param  string  $entidad
     * @param  string  $operacion
     * @return array{clase: string, metodo: string, slug: string}
     *
     * @throws \InvalidArgumentException  si la entidad no admite la operación.
     */
    public static function controller_y_metodo(string $entidad, string $operacion): array
    {
        $declaracion = self::declaracion($entidad);

        if (is_null($declaracion) || !isset($declaracion['operaciones'][$operacion])) {

            throw new \InvalidArgumentException('La entidad '.$entidad.' no admite la operación '.$operacion.' por el genérico.');
        }

        return [
            'clase'  => $declaracion['operaciones'][$operacion]['clase'],
            'metodo' => $declaracion['operaciones'][$operacion]['metodo'],
            'slug'   => $declaracion['slug'],
        ];
    }

    /**
     * EL CATÁLOGO BAJO DEMANDA, para la herramienta que_puedo_cargar. Sin entidad, la lista corta
     * (entidad, etiqueta, operaciones, descripción); con una entidad, sus campos por operación.
     *
     * @param  string|null  $entidad
     * @return array<string, mixed>
     */
    public static function que_puedo_cargar($entidad = null): array
    {
        $entidad = is_null($entidad) ? '' : trim((string) $entidad);

        if ($entidad === '') {

            $lista = [];

            foreach (self::catalogo() as $nombre => $declaracion) {

                $lista[] = [
                    'entidad'     => $nombre,
                    'etiqueta'    => $declaracion['etiqueta'],
                    'operaciones' => array_keys($declaracion['operaciones']),
                    'descripcion' => $declaracion['descripcion'],
                ];
            }

            return [
                'entidades' => $lista,
                'como_sigo' => 'Volvé a llamar con una entidad para ver sus campos. Para gastos, pagos, tareas, combos, ofertas, compras con factura y ventas nuevas están sus propias herramientas.',
            ];
        }

        $declaracion = self::declaracion($entidad);

        if (is_null($declaracion)) {

            return self::no_existe_la_entidad($entidad);
        }

        $campos = [];

        foreach ($declaracion['campos'] as $columna => $campo) {

            $fila = [
                'campo'       => $columna,
                'etiqueta'    => $campo['etiqueta'],
                'tipo'        => $campo['tipo'],
                'obligatorio' => $campo['obligatorio'],
                'operaciones' => $campo['operaciones'],
            ];

            if (!is_null($campo['relacion'])) {

                $fila['se_acepta'] = 'el nombre de '.self::etiqueta_de_relacion($campo).' (o su id si otra herramienta lo devolvió)';
            }

            if ($campo['tipo'] === 'checkbox') {

                $fila['se_acepta'] = 'si / no';
            }

            if ($campo['tipo'] === 'date') {

                $fila['se_acepta'] = 'AAAA-MM-DD';
            }

            if ($columna === 'moneda_id') {

                $fila['se_acepta'] = 'pesos / dolares';
            }

            if (isset(self::DESCRIPCIONES_DE_CAMPOS[$declaracion['entidad']][$columna])) {

                $fila['descripcion'] = self::DESCRIPCIONES_DE_CAMPOS[$declaracion['entidad']][$columna];
            }

            $campos[] = $fila;
        }

        $respuesta = [
            'entidad'     => self::normalizar_entidad($entidad),
            'etiqueta'    => $declaracion['etiqueta'],
            'descripcion' => $declaracion['descripcion'],
            'operaciones' => array_keys($declaracion['operaciones']),
            'campos'      => $campos,
            'como_sigo'   => 'Las claves de `datos` y `cambios` son estos campos. Un campo que no está acá no existe: no lo inventes, preguntá.',
        ];

        if (count($declaracion['no_la_lee_la_pantalla'])) {

            $respuesta['no_se_cargan_desde_la_pantalla'] = array_keys($declaracion['no_la_lee_la_pantalla']);
        }

        if (isset($declaracion['operaciones'][self::OP_EDICION]) || isset($declaracion['operaciones'][self::OP_BAJA])) {

            $respuesta['como_se_ubica_un_registro'] = self::como_se_ubica($declaracion);
        }

        return $respuesta;
    }

    /**
     * Las entidades que la derivación por rutas encuentra (tabla con user_id) y que no están ni en
     * ENTIDADES ni en EXCLUIDAS. Tiene que ser una lista vacía; el test 34 lo exige.
     *
     * @return array<int, string>
     */
    public static function entidades_sin_revisar(): array
    {
        $sin_revisar = [];

        foreach (self::rutas() as $slug => $operaciones) {

            if (!isset($operaciones[self::OP_ALTA])) {

                continue;
            }

            $entidad = str_replace('-', '_', $slug);

            if (isset(self::ENTIDADES[$entidad]) || isset(self::EXCLUIDAS[$entidad])) {

                continue;
            }

            $tabla = Str::plural($entidad);

            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'user_id')) {

                continue;
            }

            $sin_revisar[] = $entidad;
        }

        sort($sin_revisar);

        return $sin_revisar;
    }

    /**
     * Las entidades curadas que la derivación NO pudo resolver (sin ruta, sin tabla o sin user_id),
     * con el motivo. Tiene que ser una lista vacía; el test 34 lo exige.
     *
     * @return array<string, string>
     */
    public static function entidades_que_no_resolvieron(): array
    {
        self::catalogo();

        return self::$no_resueltas;
    }

    /** @var array<string, string> */
    protected static $no_resueltas = [];

    /**
     * Olvida la derivación (para los tests).
     *
     * @return void
     */
    public static function olvidar(): void
    {
        self::$catalogo = null;
        self::$rutas = null;
        self::$leidos = [];
        self::$no_resueltas = [];
        self::$referencias = [];
    }

    // -------------------------------------------------------------------------------------------
    // Nombres, etiquetas y valores legibles
    // -------------------------------------------------------------------------------------------

    /**
     * El nombre con el que se muestra una fila: la columna de nombre de la entidad, o "Venta N° 12"
     * cuando la entidad se identifica por número, o "#id".
     *
     * @param  string  $entidad
     * @param  object|array  $fila
     * @return string
     */
    public static function nombre_de_fila(string $entidad, $fila): string
    {
        $declaracion = self::declaracion($entidad);

        $fila = is_object($fila) ? (array) $fila : $fila;

        if (!is_null($declaracion) && !is_null($declaracion['columna_nombre']) && isset($fila[$declaracion['columna_nombre']]) && trim((string) $fila[$declaracion['columna_nombre']]) !== '') {

            return trim((string) $fila[$declaracion['columna_nombre']]);
        }

        if (!is_null($declaracion) && !is_null($declaracion['columna_numero']) && isset($fila[$declaracion['columna_numero']])) {

            return Str::ucfirst($declaracion['singular']).' N° '.$fila[$declaracion['columna_numero']];
        }

        return '#'.(isset($fila['id']) ? $fila['id'] : '?');
    }

    /**
     * Título de una tarjeta: "Nuevo proveedor" / "Nueva categoría" / "Editar cliente: Juan" /
     * "Borrar venta: Venta N° 12".
     *
     * @param  string  $entidad
     * @param  string  $operacion
     * @param  string|null  $nombre
     * @return string
     */
    public static function titulo(string $entidad, string $operacion, $nombre = null): string
    {
        $declaracion = self::declaracion($entidad);

        $singular = is_null($declaracion) ? $entidad : $declaracion['singular'];

        $femenino = !is_null($declaracion) && $declaracion['genero'] === 'f';

        if ($operacion === self::OP_ALTA) {

            return ($femenino ? 'Nueva ' : 'Nuevo ').$singular;
        }

        $verbo = $operacion === self::OP_BAJA ? 'Borrar ' : 'Editar ';

        return $verbo.$singular.(is_null($nombre) || trim((string) $nombre) === '' ? '' : ': '.$nombre);
    }

    /**
     * El nombre de la fila relacionada por su id ("Tornillos" para category_id 5), o null.
     *
     * @param  array  $campo  La declaración del campo (con `relacion`).
     * @param  mixed  $id
     * @return string|null
     */
    public static function nombre_relacionado(array $campo, $id)
    {
        if (is_null($campo['relacion']) || is_null($id) || (int) $id <= 0) {

            return null;
        }

        if ($campo['relacion']['tabla'] === 'monedas') {

            return isset(self::MONEDAS[(int) $id]) ? self::MONEDAS[(int) $id] : null;
        }

        $fila = DB::table($campo['relacion']['tabla'])
                    ->where('id', (int) $id)
                    ->first();

        if (is_null($fila)) {

            return null;
        }

        $campo_nombre = $campo['relacion']['campo'];

        return isset($fila->{$campo_nombre}) ? (string) $fila->{$campo_nombre} : null;
    }

    /**
     * Cómo se escribe un valor en un renglón de tarjeta: relación → su nombre, checkbox → Sí/No,
     * moneda → pesos/dólares, monto → "$ 1.500", porcentaje → "10 %", fecha → dd/mm/aaaa, vacío →
     * "(vacío)".
     *
     * @param  array  $campo
     * @param  mixed  $valor
     * @return string
     */
    public static function valor_legible(array $campo, $valor): string
    {
        if (is_null($valor) || (is_string($valor) && trim($valor) === '')) {

            return '(vacío)';
        }

        if (!is_null($campo['relacion'])) {

            $nombre = self::nombre_relacionado($campo, $valor);

            return is_null($nombre) ? '(vacío)' : $nombre;
        }

        switch ($campo['tipo']) {

            case 'checkbox':
                return filter_var($valor, FILTER_VALIDATE_BOOLEAN) ? 'Sí' : 'No';

            case 'number':
                if (self::es_columna_de_plata($campo['columna'])) {

                    return FormatoIaHelper::monto($valor);
                }

                if (self::es_columna_de_porcentaje($campo['columna'])) {

                    return self::numero((float) $valor).' %';
                }

                return self::numero((float) $valor);

            case 'date':
                $texto = (string) $valor;

                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m)) {

                    return $m[3].'/'.$m[2].'/'.$m[1];
                }

                return $texto;
        }

        return (string) $valor;
    }

    /**
     * Cómo se nombra la relación de un campo en una pregunta ("la categoría", "el proveedor").
     *
     * @param  array  $campo
     * @return string
     */
    public static function etiqueta_de_relacion(array $campo): string
    {
        return $campo['etiqueta'];
    }

    // -------------------------------------------------------------------------------------------
    // Derivación
    // -------------------------------------------------------------------------------------------

    /**
     * El catálogo derivado, cacheado por proceso.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function catalogo(): array
    {
        if (!is_null(self::$catalogo)) {

            return self::$catalogo;
        }

        $catalogo = [];

        self::$no_resueltas = [];

        $rutas = self::rutas();

        $columnas_por_tabla = self::columnas_de_tablas(array_map(function ($entidad) {
            return Str::plural($entidad);
        }, array_keys(self::ENTIDADES)));

        foreach (self::ENTIDADES as $entidad => $curada) {

            $slug = str_replace('_', '-', $entidad);

            $tabla = Str::plural($entidad);

            if (!isset($rutas[$slug])) {

                self::$no_resueltas[$entidad] = 'no hay ruta de recurso api/'.$slug;

                continue;
            }

            if (!isset($columnas_por_tabla[$tabla])) {

                self::$no_resueltas[$entidad] = 'la tabla '.$tabla.' no existe';

                continue;
            }

            if (!isset($columnas_por_tabla[$tabla]['user_id'])) {

                self::$no_resueltas[$entidad] = 'la tabla '.$tabla.' no tiene user_id';

                continue;
            }

            $operaciones = [];

            $permitidas = is_null($curada['operaciones']) ? self::OPERACIONES : $curada['operaciones'];

            foreach ($permitidas as $operacion) {

                if (isset($rutas[$slug][$operacion])) {

                    list($clase, $metodo) = explode('@', $rutas[$slug][$operacion]);

                    $operaciones[$operacion] = ['clase' => $clase, 'metodo' => $metodo];
                }
            }

            if (!count($operaciones)) {

                self::$no_resueltas[$entidad] = 'ninguna de sus operaciones tiene ruta';

                continue;
            }

            $declaracion = $curada;
            $declaracion['entidad'] = $entidad;
            $declaracion['slug'] = $slug;
            $declaracion['tabla'] = $tabla;
            $declaracion['operaciones'] = $operaciones;
            $declaracion['columna_nombre'] = self::columna_de_nombre($columnas_por_tabla[$tabla]);
            $declaracion['columna_numero'] = isset($columnas_por_tabla[$tabla]['num']) ? 'num' : null;
            $declaracion['tiene_deleted_at'] = isset($columnas_por_tabla[$tabla]['deleted_at']);

            /*
             * 🔴 SI LA BAJA ES DEFINITIVA O VA A LA PAPELERA, Y NO SE DEDUCE DE LA TABLA. Lo decide
             * el MODELO: `destroy()` llama a `delete()`, y `delete()` sólo marca `deleted_at` si el
             * modelo usa el trait SoftDeletes. `PriceType` no lo usa (es el único de su familia), y
             * su `destroy()` hace un DELETE de verdad: los clientes que tenían esa lista quedan con
             * un `price_type_id` que no existe. Sin esta marca, la tarjeta de baja de una lista de
             * precios se presenta igual que la de un artículo, que sí es reversible.
             */
            $declaracion['baja_definitiva'] = !self::usa_soft_deletes($entidad, $declaracion['tiene_deleted_at']);

            list($campos, $no_la_lee) = self::campos_de($entidad, $curada, $columnas_por_tabla[$tabla], $operaciones);

            $declaracion['campos'] = $campos;
            $declaracion['no_la_lee_la_pantalla'] = $no_la_lee;

            $catalogo[$entidad] = $declaracion;
        }

        self::$catalogo = $catalogo;

        return $catalogo;
    }

    /**
     * Las rutas de recurso `api/{slug}`: [slug => [alta => 'Clase@store', edicion => 'Clase@update',
     * baja => 'Clase@destroy']], solo las que apuntan a esos tres métodos.
     *
     * @return array<string, array<string, string>>
     */
    protected static function rutas(): array
    {
        if (!is_null(self::$rutas)) {

            return self::$rutas;
        }

        $rutas = [];

        foreach (Route::getRoutes() as $ruta) {

            $uri = $ruta->uri();
            $accion = $ruta->getActionName();
            $metodos = $ruta->methods();

            if (preg_match('#^api/([a-z0-9\-]+)$#', $uri, $m)) {

                if (in_array('POST', $metodos, true) && Str::endsWith($accion, '@store')) {

                    $rutas[$m[1]][self::OP_ALTA] = $accion;
                }

                continue;
            }

            if (preg_match('#^api/([a-z0-9\-]+)/\{[a-zA-Z_]+\}$#', $uri, $m)) {

                if (in_array('PUT', $metodos, true) && Str::endsWith($accion, '@update')) {

                    $rutas[$m[1]][self::OP_EDICION] = $accion;
                }

                if (in_array('DELETE', $metodos, true) && Str::endsWith($accion, '@destroy')) {

                    $rutas[$m[1]][self::OP_BAJA] = $accion;
                }
            }
        }

        self::$rutas = $rutas;

        return $rutas;
    }

    /**
     * true si el modelo de la entidad usa SoftDeletes, o sea si su `destroy()` manda la fila a la
     * papelera en vez de borrarla.
     *
     * Se mira el TRAIT del modelo y no la columna `deleted_at`: una tabla puede tener la columna de
     * una migración vieja y el modelo no usar el trait, y ahí el `delete()` borra igual. Si la
     * clase no existe (entidad sin modelo Eloquent), se cae a la columna, que es lo único que hay.
     *
     * @param  string  $entidad
     * @param  bool  $tiene_deleted_at
     * @return bool
     */
    protected static function usa_soft_deletes(string $entidad, bool $tiene_deleted_at): bool
    {
        $clase = GeneralHelper::getModelName($entidad);

        if (!class_exists($clase)) {

            return $tiene_deleted_at;
        }

        return in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($clase), true);
    }

    /**
     * Las tablas que tienen una columna `<entidad>_id`, con lo que hace falta para decidir si se
     * les puede contar: si la columna está indexada (primera columna de algún índice), cuántas
     * filas tienen estimadas, y si tienen `user_id` y `deleted_at`. Ordenadas de la más chica a la
     * más grande, para que el tope de tablas corte por las caras.
     *
     * Cuatro consultas a information_schema, cacheadas por proceso: una tarjeta de baja las paga
     * una sola vez aunque se proponga varias veces en la misma conversación.
     *
     * @param  string  $columna
     * @return array<int, array{tabla: string, indexada: bool, filas: int, tiene_user_id: bool, tiene_deleted_at: bool}>
     */
    protected static function tablas_que_referencian(string $columna): array
    {
        if (isset(self::$referencias[$columna])) {

            return self::$referencias[$columna];
        }

        $tablas = [];

        /*
         * Sin pluck(): information_schema devuelve las claves en MAYÚSCULAS en MySQL 8 (medido el
         * 21/9/2026: `pluck('table_name')` tira "Undefined property: stdClass::$table_name"), así
         * que cada fila se normaliza a minúsculas antes de leerla. Es lo mismo que ya hacía
         * columnas_de_tablas().
         */
        foreach (DB::table('information_schema.columns')
                    ->select('table_name')
                    ->whereRaw('table_schema = DATABASE()')
                    ->where('column_name', $columna)
                    ->get() as $fila) {

            $fila = (object) array_change_key_case((array) $fila, CASE_LOWER);

            $tablas[] = (string) $fila->table_name;
        }

        $tablas = array_values(array_unique($tablas));

        if (!count($tablas)) {

            self::$referencias[$columna] = [];

            return [];
        }

        $indexadas = [];

        foreach (DB::table('information_schema.statistics')
                    ->whereRaw('table_schema = DATABASE()')
                    ->where('column_name', $columna)
                    ->where('seq_in_index', 1)
                    ->whereIn('table_name', $tablas)
                    ->get() as $fila) {

            $fila = (object) array_change_key_case((array) $fila, CASE_LOWER);

            $indexadas[$fila->table_name] = true;
        }

        $filas_por_tabla = [];

        foreach (DB::table('information_schema.tables')
                    ->whereRaw('table_schema = DATABASE()')
                    ->whereIn('table_name', $tablas)
                    ->get() as $fila) {

            $fila = (object) array_change_key_case((array) $fila, CASE_LOWER);

            $filas_por_tabla[$fila->table_name] = (int) $fila->table_rows;
        }

        $propias = [];

        foreach (DB::table('information_schema.columns')
                    ->whereRaw('table_schema = DATABASE()')
                    ->whereIn('table_name', $tablas)
                    ->whereIn('column_name', ['user_id', 'deleted_at'])
                    ->get() as $fila) {

            $fila = (object) array_change_key_case((array) $fila, CASE_LOWER);

            $propias[$fila->table_name][$fila->column_name] = true;
        }

        $candidatas = [];

        foreach ($tablas as $tabla) {

            $candidatas[] = [
                'tabla'            => $tabla,
                'indexada'         => isset($indexadas[$tabla]),
                'filas'            => isset($filas_por_tabla[$tabla]) ? $filas_por_tabla[$tabla] : 0,
                'tiene_user_id'    => isset($propias[$tabla]['user_id']),
                'tiene_deleted_at' => isset($propias[$tabla]['deleted_at']),
            ];
        }

        usort($candidatas, function ($a, $b) {

            return $a['filas'] - $b['filas'];
        });

        self::$referencias[$columna] = $candidatas;

        return $candidatas;
    }

    /**
     * Cómo se nombra una tabla en el aviso: la etiqueta de su entidad si es una del catálogo
     * ("clientes", "artículos"), y si no, "vínculos internos" — una fila de `category_price_type`
     * es una referencia colgada de verdad, pero el nombre de la tabla no le dice nada a nadie.
     *
     * @param  string  $tabla
     * @return string
     */
    protected static function etiqueta_de_tabla(string $tabla): string
    {
        foreach (self::catalogo() as $declaracion) {

            if ($declaracion['tabla'] === $tabla) {

                return $declaracion['etiqueta'];
            }
        }

        return self::ETIQUETA_INNOMBRABLE;
    }

    /**
     * Las tablas sin nombre propio se suman en UNA sola entrada al final. Sin esto el aviso dice
     * "25 vínculos internos, 10 vínculos internos y 10 vínculos internos", que fue lo que salió la
     * primera vez que se corrió esto sobre la base de testing.
     *
     * @param  array<int, array{tabla: string, etiqueta: string, cantidad: int}>  $referencias
     * @return array<int, array{tabla: string, etiqueta: string, cantidad: int}>
     */
    protected static function agrupar_las_innombrables(array $referencias): array
    {
        $nombradas = [];
        $sueltas = 0;

        foreach ($referencias as $referencia) {

            if ($referencia['etiqueta'] === self::ETIQUETA_INNOMBRABLE) {

                $sueltas += $referencia['cantidad'];

                continue;
            }

            $nombradas[] = $referencia;
        }

        if ($sueltas > 0) {

            $nombradas[] = ['tabla' => '', 'etiqueta' => self::ETIQUETA_INNOMBRABLE, 'cantidad' => $sueltas];
        }

        return $nombradas;
    }

    /**
     * "30 clientes", "30 clientes y 12 artículos", "30 clientes, 12 artículos y 4 vínculos
     * internos" — con el tope de TOPE_DE_REFERENCIAS_EN_EL_AVISO.
     *
     * @param  array<int, array{etiqueta: string, cantidad: int}>  $referencias
     * @return string
     */
    protected static function enumerar(array $referencias): string
    {
        $partes = [];

        foreach ($referencias as $referencia) {

            if (count($partes) >= self::TOPE_DE_REFERENCIAS_EN_EL_AVISO) {

                break;
            }

            $partes[] = $referencia['cantidad'].' '.$referencia['etiqueta'];
        }

        if (count($partes) === 1) {

            return $partes[0];
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes).' y '.$ultima;
    }

    /**
     * Las columnas de varias tablas en UNA consulta a information_schema:
     * [tabla => [columna => {data_type, column_type, is_nullable, column_default, extra}]].
     *
     * @param  array<int, string>  $tablas
     * @return array<string, array<string, object>>
     */
    protected static function columnas_de_tablas(array $tablas): array
    {
        $filas = DB::table('information_schema.columns')
                    ->select('table_name', 'column_name', 'data_type', 'column_type', 'is_nullable', 'column_default', 'extra', 'ordinal_position')
                    ->whereRaw('table_schema = DATABASE()')
                    ->whereIn('table_name', $tablas)
                    ->orderBy('table_name')
                    ->orderBy('ordinal_position')
                    ->get();

        $por_tabla = [];

        foreach ($filas as $fila) {

            // MySQL devuelve las claves en mayúsculas o minúsculas según la versión: se normaliza.
            $fila = (object) array_change_key_case((array) $fila, CASE_LOWER);

            $por_tabla[$fila->table_name][$fila->column_name] = $fila;
        }

        return $por_tabla;
    }

    /**
     * Los campos escribibles de una entidad y los que la pantalla no lee.
     *
     * @param  string  $entidad
     * @param  array  $curada
     * @param  array<string, object>  $columnas
     * @param  array<string, array>  $operaciones
     * @return array{0: array, 1: array}
     */
    protected static function campos_de(string $entidad, array $curada, array $columnas, array $operaciones): array
    {
        $leidos = [];

        foreach ([self::OP_ALTA, self::OP_EDICION] as $operacion) {

            if (isset($operaciones[$operacion])) {

                $leidos[$operacion] = self::claves_que_lee($operaciones[$operacion]['clase'], $operaciones[$operacion]['metodo']);

                if (isset(self::LEIDOS_POR_HELPER[$entidad][$operacion])) {

                    $leidos[$operacion] = array_values(array_unique(array_merge($leidos[$operacion], self::LEIDOS_POR_HELPER[$entidad][$operacion])));
                }
            }
        }

        // Una entidad que solo se borra no ofrece campos, y no tiene sentido listar como
        // "no leídas" todas las columnas de su tabla.
        if (!count($leidos)) {

            return [[], []];
        }

        $lectura = self::declaracion_de_lectura($entidad);

        $campos = [];
        $no_la_lee = [];

        $de_sistema_editables = isset($curada['de_sistema_editables']) ? $curada['de_sistema_editables'] : [];

        foreach ($columnas as $columna => $meta) {

            if (in_array($columna, self::COLUMNAS_DE_SISTEMA, true) && !in_array($columna, $de_sistema_editables, true)) {

                continue;
            }

            if (preg_match(self::REGEX_SENSIBLES, $columna)) {

                continue;
            }

            if (in_array($columna, $curada['solo_lectura'], true)) {

                continue;
            }

            $ops = [];

            foreach ($leidos as $operacion => $claves) {

                if (in_array($columna, $claves, true)) {

                    $ops[] = $operacion;
                }
            }

            if (!count($ops)) {

                $no_la_lee[$columna] = [];

                continue;
            }

            $campos[$columna] = self::campo($columna, $meta, $ops, $lectura);

            if (isset(self::TIPOS_CURADOS[$entidad][$columna])) {

                $campos[$columna]['tipo'] = self::TIPOS_CURADOS[$entidad][$columna];
            }

            if (isset(self::OBLIGATORIOS_CURADOS[$entidad]) && in_array($columna, self::OBLIGATORIOS_CURADOS[$entidad], true)) {

                $campos[$columna]['obligatorio'] = true;
            }
        }

        return [$campos, $no_la_lee];
    }

    /**
     * La declaración de un campo: tipo, etiqueta, obligatorio, relación, operaciones.
     *
     * @param  string  $columna
     * @param  object  $meta
     * @param  array<int, string>  $operaciones
     * @param  array|null  $lectura  La declaración del esquema de lectura (bloque A), si está.
     * @return array<string, mixed>
     */
    protected static function campo(string $columna, $meta, array $operaciones, $lectura): array
    {
        $tipo = self::tipo_de($meta);

        $relacion = self::relacion_de($columna, $lectura);

        if (!is_null($relacion)) {

            $tipo = 'search';
        }

        $etiqueta = self::etiqueta_de($columna, $lectura);

        $obligatorio = $columna === 'name'
            || (strtoupper((string) $meta->is_nullable) === 'NO'
                && is_null($meta->column_default)
                && stripos((string) $meta->extra, 'auto_increment') === false);

        return [
            'columna'     => $columna,
            'tipo'        => $tipo,
            'etiqueta'    => $etiqueta,
            'obligatorio' => $obligatorio,
            'relacion'    => $relacion,
            'operaciones' => $operaciones,
            'nullable'    => strtoupper((string) $meta->is_nullable) !== 'NO',
            'default'     => $meta->column_default,
        ];
    }

    /**
     * El tipo del vocabulario del asistente a partir del tipo de MySQL (mismas reglas que el esquema
     * de lectura).
     *
     * @param  object  $meta
     * @return string
     */
    protected static function tipo_de($meta): string
    {
        $data_type = strtolower((string) $meta->data_type);
        $column_type = strtolower((string) $meta->column_type);

        if ($column_type === 'tinyint(1)') {

            return 'checkbox';
        }

        if (in_array($data_type, ['int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'float', 'double'], true)) {

            return 'number';
        }

        if (in_array($data_type, ['date', 'datetime', 'timestamp'], true)) {

            return 'date';
        }

        if (in_array($data_type, ['text', 'longtext', 'mediumtext'], true)) {

            return 'textarea';
        }

        return 'text';
    }

    /**
     * La relación de una columna `x_id`: la tabla destino y la columna con su nombre, o null si no
     * es una relación resoluble por nombre. `moneda_id` se resuelve con la tabla virtual `monedas`
     * (pesos/dólares).
     *
     * @param  string  $columna
     * @param  array|null  $lectura
     * @return array{tabla: string, campo: string}|null
     */
    protected static function relacion_de(string $columna, $lectura)
    {
        if (!Str::endsWith($columna, '_id') || $columna === 'user_id') {

            return null;
        }

        if ($columna === 'moneda_id') {

            return ['tabla' => 'monedas', 'campo' => 'nombre'];
        }

        // Lo que declaró el esquema de lectura manda.
        if (is_array($lectura) && isset($lectura['relaciones']) && is_array($lectura['relaciones'])) {

            foreach ($lectura['relaciones'] as $relacion) {

                if (is_array($relacion) && isset($relacion['columna_id'], $relacion['tabla'], $relacion['campo']) && $relacion['columna_id'] === $columna) {

                    // Verificada contra el esquema: una relación declarada a una columna que no
                    // existe (addresses.name) rompería la búsqueda por nombre.
                    if (Schema::hasTable($relacion['tabla']) && Schema::hasColumn($relacion['tabla'], $relacion['campo'])) {

                        return ['tabla' => $relacion['tabla'], 'campo' => $relacion['campo']];
                    }

                    break;
                }
            }
        }

        if (isset(self::RELACIONES_FIJAS[$columna])) {

            $fija = self::RELACIONES_FIJAS[$columna];

            return Schema::hasTable($fija['tabla']) ? $fija : null;
        }

        $tabla = Str::plural(substr($columna, 0, -3));

        if (!Schema::hasTable($tabla)) {

            return null;
        }

        $campo = self::columna_de_nombre(array_flip(Schema::getColumnListing($tabla)));

        return is_null($campo) ? null : ['tabla' => $tabla, 'campo' => $campo];
    }

    /**
     * La primera columna de nombre presente (ver COLUMNAS_DE_NOMBRE), o null.
     *
     * @param  array<string, mixed>  $columnas  Indexado por nombre de columna.
     * @return string|null
     */
    protected static function columna_de_nombre(array $columnas)
    {
        foreach (self::COLUMNAS_DE_NOMBRE as $candidata) {

            if (isset($columnas[$candidata])) {

                return $candidata;
            }
        }

        return null;
    }

    /**
     * Etiqueta de una columna: la del esquema de lectura si la declara, la del diccionario si no,
     * y el nombre con espacios como último recurso.
     *
     * @param  string  $columna
     * @param  array|null  $lectura
     * @return string
     */
    protected static function etiqueta_de(string $columna, $lectura): string
    {
        $mecanica = str_replace('_', ' ', $columna);

        /*
         * La del esquema de lectura manda SOLO si es curada: su último recurso es el mismo nombre
         * con espacios ("percentage gain", "location id"), y eso no le gana al diccionario de acá.
         */
        if (is_array($lectura) && isset($lectura['campos'][$columna]['etiqueta']) && is_string($lectura['campos'][$columna]['etiqueta'])) {

            $de_lectura = trim($lectura['campos'][$columna]['etiqueta']);

            if ($de_lectura !== '' && $de_lectura !== $mecanica) {

                return $de_lectura;
            }
        }

        if (isset(self::ETIQUETAS[$columna])) {

            return self::ETIQUETAS[$columna];
        }

        $sin_id = Str::endsWith($columna, '_id') ? substr($columna, 0, -3) : $columna;

        return str_replace('_', ' ', $sin_id);
    }

    /**
     * La declaración del esquema de lectura (bloque A) para una entidad, o null si el esquema no
     * está cargado o no la conoce. Va protegido: el catálogo de escritura no puede caerse porque
     * el de lectura cambie de forma.
     *
     * @param  string  $entidad
     * @return array|null
     */
    protected static function declaracion_de_lectura(string $entidad)
    {
        $clase = __NAMESPACE__.'\\EsquemaDeDatosIaHelper';

        if (!class_exists($clase) || !method_exists($clase, 'existe') || !method_exists($clase, 'declaracion')) {

            return null;
        }

        try {

            if (!$clase::existe($entidad)) {

                return null;
            }

            $declaracion = $clase::declaracion($entidad);

            return is_array($declaracion) ? $declaracion : null;

        } catch (\Throwable $e) {

            Log::warning('CatalogoDeEscrituraIaHelper: el esquema de lectura no pudo dar la declaración de '.$entidad, ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Las claves del request que lee un método del controller, sacadas de su código fuente SIN
     * comentarios: `$request->x`, `$request->input('x')`, `$request->get('x')`, `$request->has('x')`,
     * `$request->filled('x')`, `$request->boolean('x')` y `$request['x']`.
     *
     * Es una regex sobre el cuerpo del método, no un análisis del PHP (ver el docblock de la
     * clase). Cacheado por 'Clase@metodo'.
     *
     * @param  string  $clase
     * @param  string  $metodo
     * @return array<int, string>
     */
    public static function claves_que_lee(string $clase, string $metodo): array
    {
        $id = $clase.'@'.$metodo;

        if (isset(self::$leidos[$id])) {

            return self::$leidos[$id];
        }

        $cuerpo = self::cuerpo_del_metodo($clase, $metodo);

        $claves = [];

        if (!is_null($cuerpo)) {

            preg_match_all('/\$request->([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/', $cuerpo, $m);

            foreach ($m[1] as $clave) {

                $claves[$clave] = true;
            }

            preg_match_all('/\$request->(?:input|get|has|filled|boolean|integer|string|date)\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/', $cuerpo, $m);

            foreach ($m[1] as $clave) {

                $claves[$clave] = true;
            }

            preg_match_all('/\$request\[\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*\]/', $cuerpo, $m);

            foreach ($m[1] as $clave) {

                $claves[$clave] = true;
            }
        }

        self::$leidos[$id] = array_keys($claves);

        return self::$leidos[$id];
    }

    /**
     * El código fuente de un método, sin comentarios (`//`, `#` y `/* ... *\/`), o null si no existe.
     *
     * @param  string  $clase
     * @param  string  $metodo
     * @return string|null
     */
    public static function cuerpo_del_metodo(string $clase, string $metodo)
    {
        if (!class_exists($clase) || !method_exists($clase, $metodo)) {

            return null;
        }

        $reflexion = new \ReflectionMethod($clase, $metodo);

        $archivo = $reflexion->getFileName();

        if ($archivo === false || !is_readable($archivo)) {

            return null;
        }

        $lineas = file($archivo);

        $desde = $reflexion->getStartLine() - 1;
        $cantidad = $reflexion->getEndLine() - $desde;

        $codigo = implode('', array_slice($lineas, $desde, $cantidad));

        // Se tokeniza como PHP para sacar los comentarios sin romper strings que contengan "//".
        $tokens = token_get_all('<?php '.$codigo);

        $limpio = '';

        foreach ($tokens as $token) {

            if (is_array($token)) {

                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {

                    continue;
                }

                $limpio .= $token[1];

            } else {

                $limpio .= $token;
            }
        }

        return $limpio;
    }

    /**
     * Cómo se identifica un registro de la entidad para editarlo o borrarlo.
     *
     * @param  array  $declaracion
     * @return string
     */
    protected static function como_se_ubica(array $declaracion): string
    {
        if (!is_null($declaracion['columna_nombre'])) {

            $columna = $declaracion['columna_nombre'];

            $etiqueta = isset(self::ETIQUETAS[$columna]) ? self::ETIQUETAS[$columna] : str_replace('_', ' ', $columna);

            return 'Por su '.$etiqueta.' (texto), o por su id si otra herramienta lo devolvió (número).';
        }

        if (!is_null($declaracion['columna_numero'])) {

            return 'Por su número ("'.Str::ucfirst($declaracion['singular']).' N° 12"), que es el que ve la persona en la pantalla.';
        }

        return 'Por su id, que tiene que haber devuelto otra herramienta.';
    }

    /**
     * @param  string  $entidad
     * @return array<string, mixed>
     */
    protected static function no_existe_la_entidad(string $entidad): array
    {
        return [
            'error'                 => 'La entidad "'.$entidad.'" no se puede cargar por acá.',
            'entidades_disponibles' => array_keys(self::catalogo()),
            'como_sigo'             => 'Elegí una de la lista, o usá la herramienta propia si es un gasto, un pago, una tarea, un combo, una oferta, una compra con factura o una venta.',
        ];
    }

    /**
     * Normaliza el nombre de entidad que manda el modelo: minúsculas, guiones a guiones bajos,
     * y el plural más común al singular ("clientes" → "client" no se intenta: se acepta lo que
     * está en el catálogo tal cual, más el alias en español de la etiqueta).
     *
     * @param  string  $entidad
     * @return string
     */
    public static function normalizar_entidad(string $entidad): string
    {
        $entidad = str_replace('-', '_', mb_strtolower(trim($entidad)));

        if (isset(self::ENTIDADES[$entidad])) {

            return $entidad;
        }

        // Alias por etiqueta, sin acentos: "proveedores", "proveedor", "categoría", "articulos"...
        $sin_acentos = self::sin_acentos($entidad);

        foreach (self::ENTIDADES as $nombre => $curada) {

            $etiquetas = [self::sin_acentos(mb_strtolower($curada['etiqueta'])), self::sin_acentos(mb_strtolower($curada['singular']))];

            if (in_array($sin_acentos, $etiquetas, true)) {

                return $nombre;
            }
        }

        return $entidad;
    }

    /**
     * @param  string  $texto
     * @return string
     */
    protected static function sin_acentos(string $texto): string
    {
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    }

    /**
     * true si la columna guarda plata (se escribe con "$").
     *
     * @param  string  $columna
     * @return bool
     */
    public static function es_columna_de_plata(string $columna): bool
    {
        return (bool) preg_match('/^(price|cost|amount|total|monto|monto_fijo|min_amount|importe_iva|saldo|precio_promocional|final_price|costo_mano_de_obra|cost_in_dollars|provider_cost_in_dollars|expense_amount|dolar)$/', $columna);
    }

    /**
     * true si la columna es un porcentaje.
     *
     * @param  string  $columna
     * @return bool
     */
    public static function es_columna_de_porcentaje(string $columna): bool
    {
        return (bool) preg_match('/percentage|porcentaje|^descuento$|^recargo$|_gain/', $columna);
    }

    /**
     * Un número como lo escribe el sistema: separador de miles con punto y decimales con coma, sin
     * decimales cuando son ,00.
     *
     * @param  float  $valor
     * @return string
     */
    public static function numero(float $valor): string
    {
        $redondeado = round($valor, 2);

        $decimales = floor($redondeado) == $redondeado ? 0 : 2;

        return number_format($redondeado, $decimales, ',', '.');
    }
}
