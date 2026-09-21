<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Services\Mostrador\RecolectorBase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EL CATÁLOGO DE LO QUE EL ASISTENTE PUEDE LEER, DERIVADO DEL ESQUEMA DE LA BASE.
 *
 * Misión asistente-omnisciente (21/9/2026). Hasta esta misión `consultar_datos` sabía leer
 * diecisiete entidades escritas a mano en CatalogoDeDatosIaHelper::ENTIDADES, y todo lo demás
 * —los renglones de una compra, los movimientos de caja, las cuotas de un plan de pago, los
 * cheques endosados— era "no tengo acceso a eso". Lucas pidió lectura sin límites: esto arma la
 * declaración de CADA tabla de la base que tiene columna `user_id` leyendo `information_schema`,
 * y deja las diecisiete de siempre como OVERRIDES (descripción, etiquetas y relaciones curadas
 * mandan sobre lo derivado; lo derivado aporta los campos que nadie declaró).
 *
 * Tres listas negras explícitas, cada una con su motivo escrito al lado:
 *
 *   1. TABLAS_EXCLUIDAS: tablas con `user_id` que no describen nada del negocio que un dueño
 *      quiera leer (estado interno del asistente, tokens, credenciales, sesiones, colas,
 *      sincronizaciones, staging de importación, preferencias de pantalla, snapshots internos).
 *   2. COLUMNAS_SENSIBLES: un regex sobre el nombre de la columna. Se aplica a TODAS las tablas,
 *      curadas incluidas: `buyers.password`, `payment_methods.access_token` y
 *      `articles.embedding` no salen por acá aunque la tabla sí.
 *   3. `user_id` nunca es un campo: es el scope, no un dato.
 *
 * Las entidades HIJAS (renglón de venta, de compra, de presupuesto, de nota de crédito, de pedido,
 * movimiento de caja y empleado) no tienen `user_id` propio: se scopean por su padre
 * (`padre.user_id = dueño`) y exponen algunos campos del padre con nombre propio y columna SQL
 * calificada (`fecha_venta` = `sales.created_at`). Están declaradas a mano porque el join no se
 * deriva de nada.
 *
 * 🔴 SE CACHEA POR VERSIÓN DEL ESQUEMA. Derivar pega a `information_schema` (una consulta grande)
 * y la clave del caché lleva el nombre de la base y `count(*)` + `max(id)` de `migrations`: una
 * migración nueva invalida sola, y dos bases distintas (dos slots, dos clientes) no se pisan.
 * Además queda en una propiedad estática por proceso, para que el loop del asistente no vuelva al
 * caché en cada tool call. `olvidar()` limpia las dos capas (los tests lo necesitan).
 *
 * ⚠️ Los nombres de tabla y de columna que se interpolan en SQL más adelante (CatalogoDeDatosIaHelper
 * y ResumenDeDatosIaHelper) salen SIEMPRE de acá, o sea de `information_schema` o de una constante
 * de este archivo: nunca de lo que mandó el modelo. Lo que manda el modelo se busca en esta
 * declaración y, si no está, corta con error.
 */
class EsquemaDeDatosIaHelper
{
    /** Cuánto vive la derivación en el caché compartido (un día; una migración la invalida antes). */
    const TTL_SEGUNDOS = 86400;

    /**
     * Tope de columnas que viajan por defecto en una entidad derivada sin override. Las que
     * quedan afuera se informan en `campos_omitidos` y se piden con `campos`. Una tabla como
     * `envios` (34 columnas) o `buyers` mandaría filas de 2 KB cada una sin este tope.
     */
    const TOPE_COLUMNAS_POR_DEFECTO = 30;

    /**
     * Columnas que no salen nunca, por nombre. Se aplica a todas las tablas, curadas incluidas.
     *
     * `verification` no estaba en la lista original del plan: `buyers.verification_code` es el
     * código con el que un comprador valida su cuenta y no matcheaba con nada de lo demás.
     */
    const COLUMNAS_SENSIBLES = '/pass|token|secret|api_key|clave|embedding|cert|private|credential|_key$|^key$|hash|cookie|session|access_|refresh|bypass|verification/i';

    /**
     * LA LISTA NEGRA DE TABLAS: tabla => por qué no es un dato del negocio.
     *
     * Revisadas una por una contra `SELECT DISTINCT table_name FROM information_schema.columns
     * WHERE column_name = 'user_id'` (182 tablas el 21/9/2026). Las dudosas se decidieron mirando
     * qué tienen y qué pantalla las muestra; la decisión está en el motivo.
     *
     * @var array<string, string>
     */
    const TABLAS_EXCLUIDAS = [
        // Estado interno del asistente y del mostrador.
        'ai_conversations'                   => 'conversaciones del propio asistente: leerlas desde el chat es un bucle sin valor',
        'ai_message_actions'                 => 'tarjetas del asistente (estado interno)',
        'ai_message_imagenes'                => 'rutas de archivo de las fotos que mando la persona (interno)',
        'ai_token_usages'                    => 'consumo de tokens (interno)',
        'mostrador_accesos'                  => 'tokens de acceso a los informes del mostrador',
        'mostrador_memorias'                 => 'memoria interna del mostrador',
        'mostrador_reportes'                 => 'informes generados (el asistente ya los tiene como contexto cuando corresponde)',
        'embedding_runs'                     => 'corridas de embeddings (interno)',
        // Tokens, credenciales, sesiones y estados de OAuth.
        'demo_ingreso_tokens'                => 'tokens de ingreso a la demo',
        'mercado_libre_tokens'               => 'credenciales de Mercado Libre',
        'oauth_states'                       => 'estados de OAuth',
        'zippin_oauth_states'                => 'estados de OAuth de Zippin',
        'platform_connectors'                => 'credenciales de plataformas conectadas',
        'version_session_transfers'          => 'tokens de traspaso de sesion entre versiones',
        'print_agents'                       => 'tokens de vinculacion de las impresoras',
        'print_jobs'                         => 'cola de impresion (payload en base64)',
        'whatsapp_bot_configs'               => 'credenciales de Kapso y configuracion del bot',
        // Colas, corridas, sincronizaciones y staging de importacion.
        'background_processes'               => 'registro de procesos en segundo plano (misma familia que las corridas de abajo; el sistema avisa cuando terminan)',
        'excel_analysis_runs'                => 'corridas del analisis de Excel con IA',
        'price_update_runs'                  => 'corridas de actualizacion de precios',
        'import_histories'                   => 'historial tecnico de importaciones (trazas de error, chunks)',
        'import_statuses'                    => 'estado de una importacion en curso',
        'export_histories'                   => 'historial tecnico de exportaciones',
        'articles_pre_imports'               => 'staging de importacion de articulos',
        'article_pre_import_ranges'          => 'staging de importacion (rangos de color)',
        'article_image_search_attempts'      => 'intentos de busqueda de imagenes (interno)',
        'sync_from_meli_articles'            => 'sincronizacion con Mercado Libre',
        'sync_from_meli_orders'              => 'sincronizacion con Mercado Libre',
        'sync_to_meli_articles'              => 'sincronizacion con Mercado Libre',
        'sync_to_t_n_articles'               => 'sincronizacion con Tienda Nube',
        'synced_version_notification_reads'  => 'lecturas de avisos de version (interno)',
        'provider_order_scans'               => 'procesamiento de fotos de facturas (interno, resultado en JSON)',
        'provider_order_scan_images'         => 'rutas de archivo de las fotos de facturas (interno)',
        'errors'                             => 'errores de la aplicacion',
        'geocoder_counters'                  => 'contador interno de geocodificacion',
        'mantenimientos'                     => 'bitacora de los comandos de mantenimiento del cron (chequeo de saldos), no la carga nadie',
        'user_payments'                      => 'pagos del abono a ComercioCity: es la facturacion de la plataforma, no un dato del negocio',
        // Preferencias de pantalla y permisos.
        'column_positions'                   => 'posiciones de columnas para importar (preferencia de pantalla)',
        'table_column_preferences'           => 'columnas visibles de cada listado (preferencia de pantalla)',
        'vender_keyboard_shortcuts'          => 'atajos de teclado de Vender (preferencia de pantalla)',
        'user_configurations'                => 'preferencias de la cuenta (anchos de panel, flags de pantalla)',
        'filter_histories'                   => 'historial de filtros usados (preferencia de pantalla)',
        'etiqueta_medidas'                   => 'medidas de impresion de etiquetas: se siembran solas al crear la cuenta, no las carga nadie',
        'online_configurations'              => 'configuracion de la tienda: credenciales SMTP y de plataformas mezcladas con sesenta flags de pantalla',
        'permission_beta_user'               => 'permisos (pivot sin contenido)',
        'permission_empresa_user'            => 'permisos (pivot sin contenido)',
        'permission_user'                    => 'permisos (pivot sin contenido)',
        'extencion_empresa_user'             => 'extensiones habilitadas (pivot sin contenido)',
        'extencion_user'                     => 'extensiones habilitadas (pivot sin contenido)',
        'task_user'                          => 'asignacion de tareas internas (pivot sin contenido)',
        'user_workday'                       => 'dias de trabajo de empleados (pivot sin contenido)',
        'caja_user'                          => 'que cajas ve cada empleado (pivot sin contenido)',
        'caja_treasury_user'                 => 'que cajas de tesoreria ve cada empleado (pivot sin contenido)',
        'afip_selected_payment_methods'      => 'metodos de pago marcados para facturar (pivot sin contenido)',
        // Snapshots internos: son fotos de un momento, no el dato vivo. El numero vivo sale de
        // consultar_resumen_de_ventas y consultar_reporte_contable.
        'company_performances'               => 'snapshot del reporte de Rendimiento (existe solo si alguien lo abrio ese dia y es de ese momento)',
        'company_performance_user'           => 'snapshot de Rendimiento por vendedor',
        'company_performance_user_payment_method' => 'snapshot de Rendimiento por metodo de pago',
        'article_performances'               => 'snapshot de Rendimiento por articulo',
        'inventory_performances'             => 'snapshot de la valuacion del inventario',
        'credit_account_snapshots'           => 'snapshot diario de saldos de cuentas (para graficos)',
        'debt_snapshots'                     => 'snapshot diario de deuda (para graficos)',
        'buyer_tracking_daily'               => 'agregado diario del tracking de la tienda (lo lee consultar_actividad_de_un_cliente)',
        'buyer_tracking_events'              => 'eventos crudos del tracking de la tienda (lo lee consultar_actividad_de_un_cliente)',
        'sale_modifications'                 => 'auditoria de ediciones de venta: dos fotos JSON de la venta entera por fila',
    ];

    /**
     * Etiquetas para los nombres de columna más comunes. Lo que no está acá sale con los guiones
     * bajos pasados a espacios.
     *
     * @var array<string, string>
     */
    const ETIQUETAS = [
        'name'            => 'nombre',
        'nombre'          => 'nombre',
        'amount'          => 'cantidad',
        'price'           => 'precio',
        'final_price'     => 'precio final',
        'cost'            => 'costo',
        'total'           => 'total',
        'sub_total'       => 'subtotal',
        'created_at'      => 'fecha de alta',
        'updated_at'      => 'fecha de ultima modificacion',
        'deleted_at'      => 'fecha de borrado',
        'observations'    => 'observaciones',
        'observaciones'   => 'observaciones',
        'description'     => 'descripcion',
        'descripcion'     => 'descripcion',
        'notes'           => 'notas',
        'notas'           => 'notas',
        'phone'           => 'telefono',
        'email'           => 'email',
        'address'         => 'domicilio',
        'street'          => 'calle',
        'num'             => 'numero',
        'numero'          => 'numero',
        'status'          => 'estado',
        'estado'          => 'estado',
        'stock'           => 'stock',
        'stock_min'       => 'stock minimo',
        'percentage'      => 'porcentaje',
        'discount'        => 'descuento',
        'surchage'        => 'recargo',
        'saldo'           => 'saldo',
        'debe'            => 'debe',
        'haber'           => 'haber',
        'detalle'         => 'detalle',
        'cuit'            => 'CUIT',
        'razon_social'    => 'razon social',
        'bar_code'        => 'codigo de barras',
        'provider_code'   => 'codigo del proveedor',
        'moneda_id'       => 'moneda',
        'iva_id'          => 'alicuota de IVA',
        'client_id'       => 'cliente',
        'provider_id'     => 'proveedor',
        'article_id'      => 'articulo',
        'category_id'     => 'rubro',
        'sub_category_id' => 'sub rubro',
        'brand_id'        => 'marca',
        'employee_id'     => 'empleado',
        'seller_id'       => 'vendedor',
        'address_id'      => 'sucursal',
        'caja_id'         => 'caja',
        'buyer_id'        => 'comprador de la tienda',
        'sale_id'         => 'venta',
        'budget_id'       => 'presupuesto',
        'order_id'        => 'pedido de la tienda',
        'provider_order_id' => 'compra a proveedor',
        'expense_concept_id' => 'concepto de gasto',
        'price_type_id'   => 'lista de precios',
        'current_acount_payment_method_id' => 'metodo de pago',
        'fecha_entrega'   => 'fecha de entrega',
        'fecha_pago'      => 'fecha de pago',
        'fecha_emision'   => 'fecha de emision',
        'terminada'       => 'terminada',
        'online'          => 'publicado en la tienda',
    ];

    /**
     * Relaciones que la convención `x_id → xs.name` no resuelve sola, por nombre de columna.
     *
     *   - `employee_id`, `owner_id` y los "por quién" apuntan a `users.name`.
     *   - `address_id` y sus variantes a `addresses.street`: las sucursales no tienen `name`.
     *   - `iva_id` a `ivas.percentage`: la tabla no tiene `name` (el plan pedía `ivas.name` si existe).
     *   - `moneda_id` NO va contra la tabla `monedas` (puede estar vacía en una base vieja): son
     *     etiquetas fijas, ver ETIQUETAS_DE_MONEDA.
     *
     * @var array<string, array<string, string>>
     */
    const RELACIONES_POR_CONVENCION = [
        'employee_id'              => ['tabla' => 'users',              'campo' => 'name'],
        'owner_id'                 => ['tabla' => 'users',              'campo' => 'name'],
        'auth_user_id'             => ['tabla' => 'users',              'campo' => 'name'],
        'from_user_id'             => ['tabla' => 'users',              'campo' => 'name'],
        'cobrado_por_id'           => ['tabla' => 'users',              'campo' => 'name'],
        'rechazado_por_id'         => ['tabla' => 'users',              'campo' => 'name'],
        'address_id'               => ['tabla' => 'addresses',          'campo' => 'street'],
        'from_address_id'          => ['tabla' => 'addresses',          'campo' => 'street'],
        'to_address_id'            => ['tabla' => 'addresses',          'campo' => 'street'],
        'from_caja_id'             => ['tabla' => 'cajas',              'campo' => 'name'],
        'to_caja_id'               => ['tabla' => 'cajas',              'campo' => 'name'],
        'iva_id'                   => ['tabla' => 'ivas',               'campo' => 'percentage'],
        'endosado_a_provider_id'   => ['tabla' => 'providers',          'campo' => 'name'],
        'endosado_desde_client_id' => ['tabla' => 'clients',            'campo' => 'name'],
        'comercio_city_client_id'  => ['tabla' => 'clients',            'campo' => 'name'],
        'sistema_de_puntos_id'     => ['tabla' => 'sistemas_de_puntos', 'campo' => 'nombre'],
        'price_type_personalizado_id' => ['tabla' => 'price_types',     'campo' => 'name'],
    ];

    /**
     * Etiquetas fijas de `moneda_id`. El criterio es el de RecolectorBase::MONEDAS_PESOS: 0, 1 y
     * null son pesos; solo 2 es dólares.
     *
     * @var array<int, string>
     */
    const ETIQUETAS_DE_MONEDA = [0 => 'pesos', 1 => 'pesos', 2 => 'dolares'];

    /**
     * Módulo de cada tabla, para agrupar la lista que ve el modelo. Lo que no está acá cae en
     * `otros`. El orden de las claves es el orden en que se lista.
     *
     * @var array<string, array<int, string>>
     */
    const MODULOS = [
        'articulos' => [
            'articles', 'categories', 'sub_categories', 'brands', 'combos', 'price_types', 'prices_lists',
            'article_prices', 'article_price_type_groups', 'article_property_types', 'article_property_values',
            'article_ubications', 'tags', 'colors', 'sizes', 'conditions', 'tipo_envases', 'bodegas', 'cepas',
            'platelets', 'article_pdfs', 'article_pdf_observations', 'promocion_vinotecas',
            'promocion_vinoteca_commissions', 'category_price_type_ranges', 'services', 'discounts', 'surchages',
            'masive_updates', 'dealers',
        ],
        'ventas' => [
            'sales', 'article_sale', 'budgets', 'article_budget', 'sale_types', 'sale_statuses', 'sale_channels',
            'sale_taxes', 'sale_sender_infos', 'sellers', 'seller_commissions', 'commissions', 'commissioners',
            'venta_terminada_commissions', 'seller_commission_current_acount_payment_method', 'road_maps',
            'payment_plans', 'payment_plan_cuotas', 'cuotas', 'credit_cards', 'current_acount_payment_method_discounts',
        ],
        'clientes' => [
            'clients', 'credit_accounts', 'current_acounts', 'article_current_acount', 'client_offers',
            'client_reputations', 'movimiento_puntos', 'sistemas_de_puntos', 'whatsapp_chats', 'offer_suggestions',
        ],
        'proveedores y compras' => [
            'providers', 'provider_orders', 'article_provider_order', 'provider_price_offers',
            'provider_order_afip_tickets', 'purchase_suggestions',
        ],
        'caja y tesoreria' => [
            'cajas', 'movimiento_cajas', 'movimiento_entre_cajas', 'turno_cajas', 'resumen_cajas', 'cheques',
            'caja_liquidacion_configs', 'default_payment_method_cajas', 'current_acount_current_acount_payment_method',
            'dolar_cotizacion_registros',
        ],
        'gastos' => ['expenses', 'expense_concepts', 'expense_categories'],
        'agenda' => ['pendings', 'pending_completeds', 'tasks'],
        'tienda online' => [
            'orders', 'article_order', 'buyers', 'carts', 'cupons', 'delivery_zones', 'delivery_days',
            'payment_methods', 'messages', 'questions', 'calls', 'titles', 'schedules', 'envios', 'meli_orders',
            'meli_buyers', 'tienda_nube_orders', 'business_hours_configs',
        ],
        'facturacion' => ['afip_information', 'retenciones_sufridas'],
        'produccion' => [
            'order_productions', 'order_production_statuses', 'order_production_status_groups', 'production_batches',
            'production_batch_statuses', 'production_batch_movement_types', 'production_movements', 'recipes',
            'recipe_route_types',
        ],
        'stock' => ['stock_movements', 'deposit_movements', 'deposits', 'addresses', 'stock_suggestions', 'inventory_linkages'],
        'configuracion' => ['users', 'pdf_column_profiles', 'whatsapp_templates', 'localidads', 'locations', 'provincias', 'support_tickets'],
    ];

    /**
     * Curadas que NO están en CatalogoDeDatosIaHelper::ENTIDADES porque esa constante es el
     * contrato del test viejo (cada entrada tiene modelo con `scopeWithAll`), y `current_acount`
     * quedaba afuera justamente por no tenerlo. Con el motor nuevo ya no hace falta el scope, así
     * que entra por acá, con su descripción.
     *
     * @var array<string, array<string, mixed>>
     */
    const CURADAS_ADICIONALES = [
        'current_acount' => [
            'etiqueta'    => 'movimientos de cuenta corriente',
            'descripcion' => 'Los movimientos de cuenta corriente de clientes y proveedores: ventas a cuenta, pagos, notas de credito. `debe`, `haber` y `saldo` son de LA CUENTA de ese movimiento (un cliente puede tener una en pesos y otra en dolares), no de la persona. Para "que me debe Fulano" va consultar_ventas_impagas_de_un_cliente o consultar_movimientos_de_cuenta_corriente, que ya resuelven la cuenta.',
            'campos'      => [
                'detalle'      => ['tipo' => 'text',   'etiqueta' => 'detalle'],
                'debe'         => ['tipo' => 'number', 'etiqueta' => 'debe'],
                'haber'        => ['tipo' => 'number', 'etiqueta' => 'haber'],
                'saldo'        => ['tipo' => 'number', 'etiqueta' => 'saldo de la cuenta despues del movimiento'],
                'status'       => ['tipo' => 'text',   'etiqueta' => 'estado'],
                'client_id'    => ['tipo' => 'search', 'etiqueta' => 'cliente'],
                'provider_id'  => ['tipo' => 'search', 'etiqueta' => 'proveedor'],
                'sale_id'      => ['tipo' => 'number', 'etiqueta' => 'venta asociada'],
                'moneda_id'    => ['tipo' => 'search', 'etiqueta' => 'moneda'],
                'created_at'   => ['tipo' => 'date',   'etiqueta' => 'fecha'],
            ],
            'relaciones'  => [
                'cliente'   => ['tabla' => 'clients',   'columna_id' => 'client_id',   'campo' => 'name'],
                'proveedor' => ['tabla' => 'providers', 'columna_id' => 'provider_id', 'campo' => 'name'],
            ],
        ],
    ];

    /**
     * LAS ENTIDADES HIJAS: sin `user_id` propio, scopeadas por su padre.
     *
     * `campos_del_padre` expone columnas del padre con nombre propio; `condiciones_fijas` son SQL
     * ya calificado (los nombres de tabla son constantes de este archivo, nunca del modelo).
     *
     * @var array<string, array<string, mixed>>
     */
    const HIJAS = [
        'renglon_de_venta' => [
            'tabla'       => 'article_sale',
            'etiqueta'    => 'renglones de venta (un articulo dentro de una venta)',
            'descripcion' => 'Cada articulo vendido, con su cantidad, precio y costo al momento de la venta. Es la tabla para "que articulos le vendi a", "cuantas unidades de X vendi" y para agrupar con resumir_datos. Solo ventas reales (sin las contenedoras de consolidacion AFIP) y no borradas. `fecha_venta`, `numero_venta`, `client_id`, `employee_id`, `seller_id`, `address_id`, `terminada` y `moneda_id` vienen de la venta. Para el total vendido de un periodo va consultar_resumen_de_ventas, que es el mismo numero que Rendimiento.',
            'modulo'      => 'ventas',
            'padre'       => ['tabla' => 'sales', 'columna_local' => 'sale_id', 'columna_padre' => 'id', 'entidad' => 'sale'],
            'campos_del_padre' => [
                'fecha_venta'  => ['columna' => 'sales.created_at',  'tipo' => 'date',     'etiqueta' => 'fecha de la venta'],
                'numero_venta' => ['columna' => 'sales.num',         'tipo' => 'number',   'etiqueta' => 'numero de la venta'],
                'client_id'    => ['columna' => 'sales.client_id',   'tipo' => 'search',   'etiqueta' => 'cliente'],
                'employee_id'  => ['columna' => 'sales.employee_id', 'tipo' => 'search',   'etiqueta' => 'empleado que la cargo'],
                'seller_id'    => ['columna' => 'sales.seller_id',   'tipo' => 'search',   'etiqueta' => 'vendedor'],
                'address_id'   => ['columna' => 'sales.address_id',  'tipo' => 'search',   'etiqueta' => 'sucursal'],
                'terminada'    => ['columna' => 'sales.terminada',   'tipo' => 'checkbox', 'etiqueta' => 'venta terminada'],
                'moneda_id'    => ['columna' => 'sales.moneda_id',   'tipo' => 'search',   'etiqueta' => 'moneda de la venta'],
            ],
            'condiciones_fijas' => [
                ['sql' => '(sales.is_consolidacion_facturacion IS NULL OR sales.is_consolidacion_facturacion = 0)', 'texto' => 'sin las ventas contenedoras de consolidacion AFIP'],
                ['sql' => 'sales.deleted_at IS NULL', 'texto' => 'solo ventas no borradas'],
            ],
            'etiquetas' => [
                'name'   => 'nombre del articulo al momento de la venta',
                'amount' => 'unidades vendidas',
                'price'  => 'precio unitario cobrado',
                'cost'   => 'costo unitario al momento de la venta',
            ],
        ],
        'renglon_de_compra' => [
            'tabla'       => 'article_provider_order',
            'etiqueta'    => 'renglones de compra (un articulo dentro de una compra a proveedor)',
            'descripcion' => 'Cada articulo comprado a un proveedor, con su cantidad pedida y recibida y su costo. Es la tabla para "que le compro mas a este proveedor" (resumir_datos agrupando por article_id con filtro provider_id) y "cuanto pague por X". `fecha_compra`, `numero_compra`, `provider_id`, `provider_order_status_id` y `moneda_id` vienen de la compra. `cost_in_dollars` en 1 dice que el costo esta en dolares.',
            'modulo'      => 'proveedores y compras',
            'padre'       => ['tabla' => 'provider_orders', 'columna_local' => 'provider_order_id', 'columna_padre' => 'id', 'entidad' => 'provider_order'],
            'campos_del_padre' => [
                'fecha_compra'             => ['columna' => 'provider_orders.created_at',               'tipo' => 'date',   'etiqueta' => 'fecha de la compra'],
                'numero_compra'            => ['columna' => 'provider_orders.num',                      'tipo' => 'number', 'etiqueta' => 'numero de la compra'],
                'provider_id'              => ['columna' => 'provider_orders.provider_id',              'tipo' => 'search', 'etiqueta' => 'proveedor'],
                'provider_order_status_id' => ['columna' => 'provider_orders.provider_order_status_id', 'tipo' => 'search', 'etiqueta' => 'estado de la compra'],
                'moneda_id'                => ['columna' => 'provider_orders.moneda_id',                'tipo' => 'search', 'etiqueta' => 'moneda de la compra'],
            ],
            'condiciones_fijas' => [],
            'etiquetas' => [
                'amount'   => 'unidades',
                'received' => 'unidades recibidas',
                'cost'     => 'costo unitario',
            ],
        ],
        'renglon_de_presupuesto' => [
            'tabla'       => 'article_budget',
            'etiqueta'    => 'renglones de presupuesto (un articulo dentro de un presupuesto)',
            'descripcion' => 'Cada articulo presupuestado, con su cantidad y precio. `fecha_presupuesto`, `numero_presupuesto`, `client_id`, `budget_status_id` y `moneda_id` vienen del presupuesto.',
            'modulo'      => 'ventas',
            'padre'       => ['tabla' => 'budgets', 'columna_local' => 'budget_id', 'columna_padre' => 'id', 'entidad' => 'budget'],
            'campos_del_padre' => [
                'fecha_presupuesto'  => ['columna' => 'budgets.created_at',       'tipo' => 'date',   'etiqueta' => 'fecha del presupuesto'],
                'numero_presupuesto' => ['columna' => 'budgets.num',              'tipo' => 'number', 'etiqueta' => 'numero del presupuesto'],
                'client_id'          => ['columna' => 'budgets.client_id',        'tipo' => 'search', 'etiqueta' => 'cliente'],
                'budget_status_id'   => ['columna' => 'budgets.budget_status_id', 'tipo' => 'search', 'etiqueta' => 'estado del presupuesto'],
                'moneda_id'          => ['columna' => 'budgets.moneda_id',        'tipo' => 'search', 'etiqueta' => 'moneda del presupuesto'],
            ],
            'condiciones_fijas' => [],
            'etiquetas' => ['amount' => 'unidades', 'price' => 'precio unitario'],
        ],
        'renglon_de_nota_de_credito' => [
            'tabla'       => 'article_current_acount',
            'etiqueta'    => 'renglones de nota de credito (un articulo devuelto)',
            'descripcion' => 'Cada articulo devuelto en una nota de credito, con su cantidad y precio. Solo movimientos de cuenta corriente con estado nota_credito. `fecha_nota`, `client_id` y `moneda_id` vienen de la nota.',
            'modulo'      => 'clientes',
            'padre'       => ['tabla' => 'current_acounts', 'columna_local' => 'current_acount_id', 'columna_padre' => 'id', 'entidad' => 'current_acount'],
            'campos_del_padre' => [
                'fecha_nota' => ['columna' => 'current_acounts.created_at', 'tipo' => 'date',   'etiqueta' => 'fecha de la nota de credito'],
                'client_id'  => ['columna' => 'current_acounts.client_id',  'tipo' => 'search', 'etiqueta' => 'cliente'],
                'moneda_id'  => ['columna' => 'current_acounts.moneda_id',  'tipo' => 'search', 'etiqueta' => 'moneda de la nota'],
            ],
            'condiciones_fijas' => [
                ['sql' => "current_acounts.status = 'nota_credito'", 'texto' => 'solo notas de credito'],
            ],
            'etiquetas' => ['amount' => 'unidades devueltas', 'price' => 'precio unitario'],
        ],
        'renglon_de_pedido' => [
            'tabla'       => 'article_order',
            'etiqueta'    => 'renglones de pedido de la tienda online (un articulo dentro de un pedido)',
            'descripcion' => 'Cada articulo de un pedido que entro por el ecommerce, con su cantidad y precio. `fecha_pedido`, `numero_pedido`, `buyer_id` y `order_status_id` vienen del pedido.',
            'modulo'      => 'tienda online',
            'padre'       => ['tabla' => 'orders', 'columna_local' => 'order_id', 'columna_padre' => 'id', 'entidad' => 'order'],
            'campos_del_padre' => [
                'fecha_pedido'    => ['columna' => 'orders.created_at',      'tipo' => 'date',   'etiqueta' => 'fecha del pedido'],
                'numero_pedido'   => ['columna' => 'orders.num',             'tipo' => 'number', 'etiqueta' => 'numero del pedido'],
                'buyer_id'        => ['columna' => 'orders.buyer_id',        'tipo' => 'search', 'etiqueta' => 'comprador'],
                'order_status_id' => ['columna' => 'orders.order_status_id', 'tipo' => 'search', 'etiqueta' => 'estado del pedido'],
            ],
            'condiciones_fijas' => [],
            'etiquetas' => ['amount' => 'unidades', 'price' => 'precio unitario'],
        ],
        'movimiento_de_caja' => [
            'tabla'       => 'movimiento_cajas',
            'etiqueta'    => 'movimientos de caja',
            'descripcion' => 'Cada ingreso o egreso de una caja, con el saldo que dejo. `moneda_id` viene de la caja. Un movimiento con `fecha_liquidacion_estimada` es plata en transito (todavia no liquidada).',
            'modulo'      => 'caja y tesoreria',
            'padre'       => ['tabla' => 'cajas', 'columna_local' => 'caja_id', 'columna_padre' => 'id', 'entidad' => 'caja'],
            'campos_del_padre' => [
                'moneda_id' => ['columna' => 'cajas.moneda_id', 'tipo' => 'search', 'etiqueta' => 'moneda de la caja'],
            ],
            'condiciones_fijas' => [],
            'etiquetas' => ['ingreso' => 'ingreso', 'egreso' => 'egreso', 'saldo' => 'saldo de la caja despues del movimiento'],
        ],
        'empleado' => [
            'tabla'       => 'users',
            'etiqueta'    => 'empleados de la cuenta',
            'descripcion' => 'Los usuarios empleados del negocio (los que entran con el usuario del dueño). Solo nombre, email, telefono, fecha de alta y si tienen acceso de administrador.',
            'modulo'      => 'configuracion',
            'columna_dueno' => 'owner_id',
            'solo_campos' => ['name', 'email', 'phone', 'created_at', 'admin_access'],
            'condiciones_fijas' => [],
            'etiquetas' => ['admin_access' => 'tiene acceso de administrador'],
        ],
    ];

    /**
     * El catálogo del proceso: entidad => declaración. null = todavía no se derivó ni se leyó del
     * caché en este proceso.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected static $catalogo = null;

    /**
     * Los nombres de entidad que se pueden consultar.
     *
     * @return array<int, string>
     */
    public static function entidades(): array
    {
        return array_keys(self::catalogo());
    }

    /**
     * La declaración completa de una entidad, o null si no existe.
     *
     * @param  string  $entidad
     * @return array<string, mixed>|null
     */
    public static function declaracion(string $entidad)
    {
        $catalogo = self::catalogo();

        $entidad = trim($entidad);

        return isset($catalogo[$entidad]) ? $catalogo[$entidad] : null;
    }

    /**
     * @param  string  $entidad
     * @return bool
     */
    public static function existe(string $entidad): bool
    {
        return ! is_null(self::declaracion($entidad));
    }

    /**
     * La lista corta que ve el modelo: entidad, etiqueta, módulo y —solo para las curadas— la
     * descripción. Ordenada por módulo (en el orden de MODULOS, `otros` al final) y filtrada por
     * `buscar` (substring sin acentos sobre entidad, etiqueta y módulo).
     *
     * @param  string|null  $buscar
     * @return array<int, array<string, mixed>>
     */
    public static function lista_para_el_modelo($buscar = null): array
    {
        $buscar = is_null($buscar) ? '' : ConsultasSistemaIaHelper::normalize_text((string) $buscar);

        $orden_de_modulos = array_merge(array_keys(self::MODULOS), ['otros']);

        $por_modulo = [];

        foreach (self::catalogo() as $nombre => $declaracion) {
            $fila = [
                'entidad'  => $nombre,
                'etiqueta' => $declaracion['etiqueta'],
                'modulo'   => $declaracion['modulo'],
            ];

            if ($declaracion['curada']) {
                $fila['descripcion'] = $declaracion['descripcion'];
            }

            if ($buscar !== '') {
                $pajar = ConsultasSistemaIaHelper::normalize_text($nombre . ' ' . $declaracion['etiqueta'] . ' ' . $declaracion['modulo']);

                if (strpos($pajar, $buscar) === false) {
                    continue;
                }
            }

            $por_modulo[$declaracion['modulo']][] = $fila;
        }

        $lista = [];

        foreach ($orden_de_modulos as $modulo) {
            if (! isset($por_modulo[$modulo])) {
                continue;
            }

            foreach ($por_modulo[$modulo] as $fila) {
                $lista[] = $fila;
            }
        }

        return $lista;
    }

    /**
     * Tira las dos capas del caché. Lo usan los tests (que migran o siembran entre medio) y sirve
     * para forzar una rederivación a mano.
     *
     * @return void
     */
    public static function olvidar()
    {
        static::$catalogo = null;

        Cache::forget(self::clave_de_cache());
    }

    /**
     * El catálogo entero: propiedad estática → caché compartido → derivación.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function catalogo(): array
    {
        if (! is_null(static::$catalogo)) {
            return static::$catalogo;
        }

        static::$catalogo = Cache::remember(self::clave_de_cache(), self::TTL_SEGUNDOS, function () {
            return self::derivar();
        });

        return static::$catalogo;
    }

    /**
     * La clave del caché lleva el nombre de la base y la versión del esquema (cuántas migraciones
     * corrieron y cuál fue la última): una migración nueva invalida sola.
     *
     * @return string
     */
    protected static function clave_de_cache(): string
    {
        $version = DB::table('migrations')->selectRaw('COUNT(*) as cuantas, COALESCE(MAX(id), 0) as ultima')->first();

        return 'asistente_ia:esquema:' . DB::connection()->getDatabaseName()
            . ':' . (int) $version->cuantas . ':' . (int) $version->ultima;
    }

    /**
     * LA DERIVACIÓN. Una sola consulta a `information_schema.columns` para toda la base, y de ahí
     * sale todo: qué tablas tienen `user_id`, qué tablas existen (para las relaciones), qué tablas
     * tienen `name` y el tipo de cada columna.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function derivar(): array
    {
        $columnas = self::columnas_de_la_base();

        $curadas = array_merge(CatalogoDeDatosIaHelper::ENTIDADES, self::CURADAS_ADICIONALES);

        $catalogo = [];

        // Primero las curadas, en su orden: son las que el modelo ya conoce.
        foreach ($curadas as $entidad => $curada) {
            $tabla = self::tabla_de_la_curada($entidad, $columnas);

            if (is_null($tabla)) {
                continue;
            }

            $catalogo[$entidad] = self::aplicar_override(self::derivada_de($tabla, $columnas), $curada, $columnas);
        }

        // Después el resto de las tablas con user_id, por orden alfabético.
        $tablas = array_keys($columnas);
        sort($tablas);

        foreach ($tablas as $tabla) {
            if (! isset($columnas[$tabla]['user_id']) || isset(self::TABLAS_EXCLUIDAS[$tabla])) {
                continue;
            }

            $entidad = Str::singular($tabla);

            if (isset($catalogo[$entidad])) {
                continue;
            }

            $catalogo[$entidad] = self::derivada_de($tabla, $columnas);
        }

        // Y las hijas, que no tienen user_id propio.
        foreach (self::HIJAS as $entidad => $hija) {
            if (! isset($columnas[$hija['tabla']])) {
                continue;
            }

            $catalogo[$entidad] = self::declaracion_hija($entidad, $hija, $columnas);
        }

        return $catalogo;
    }

    /**
     * Todas las columnas de la base conectada, agrupadas por tabla, en UNA consulta.
     *
     * ⚠️ Con MySQL 8 las columnas de `information_schema` vuelven en MAYÚSCULAS por PDO
     * (`TABLE_NAME`, no `table_name`): por eso van con alias explícito.
     *
     * @return array<string, array<string, array<string, mixed>>>  tabla => columna => info
     */
    protected static function columnas_de_la_base(): array
    {
        $filas = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->orderBy('table_name')
            ->orderBy('ordinal_position')
            ->get([
                DB::raw('table_name as tabla'),
                DB::raw('column_name as columna'),
                DB::raw('data_type as tipo_dato'),
                DB::raw('column_type as tipo_columna'),
                DB::raw('ordinal_position as posicion'),
                DB::raw('is_nullable as admite_nulo'),
            ]);

        $columnas = [];

        foreach ($filas as $fila) {
            $columnas[(string) $fila->tabla][(string) $fila->columna] = [
                'tipo_dato'    => strtolower((string) $fila->tipo_dato),
                'tipo_columna' => strtolower((string) $fila->tipo_columna),
                'posicion'     => (int) $fila->posicion,
                'admite_nulo'  => strtoupper((string) $fila->admite_nulo) === 'YES',
            ];
        }

        return $columnas;
    }

    /**
     * La tabla de una entidad curada: el plural del nombre (la convención de Eloquent), verificado
     * contra el esquema.
     *
     * @param  string  $entidad
     * @param  array   $columnas
     * @return string|null
     */
    protected static function tabla_de_la_curada(string $entidad, array $columnas)
    {
        $tabla = Str::plural($entidad);

        return isset($columnas[$tabla]) ? $tabla : null;
    }

    /**
     * La declaración derivada de una tabla: todos sus campos menos los sensibles y `user_id`, con
     * tipo, etiqueta, relaciones por convención y condiciones fijas.
     *
     * @param  string  $tabla
     * @param  array   $columnas
     * @return array<string, mixed>
     */
    protected static function derivada_de(string $tabla, array $columnas): array
    {
        $campos = [];
        $relaciones = [];

        foreach ($columnas[$tabla] as $columna => $info) {
            if (! self::columna_visible($columna)) {
                continue;
            }

            $campo = self::campo_derivado($tabla, $columna, $info, $columnas);

            if (isset($campo['relacion'])) {
                $clave = self::clave_de_relacion($columna, $columnas[$tabla], $relaciones);
                $relaciones[$clave] = array_merge($campo['relacion'], ['columna_id' => $columna]);
                unset($campo['relacion']);
            }

            $campos[$columna] = $campo;
        }

        $condiciones = self::condiciones_fijas_de($tabla, $columnas);

        $declaracion = [
            'entidad'           => Str::singular($tabla),
            'tabla'             => $tabla,
            'etiqueta'          => str_replace('_', ' ', $tabla),
            'descripcion'       => 'Los registros de "' . str_replace('_', ' ', $tabla) . '". Entidad derivada del esquema: los nombres de los campos son los de la base, sin descripcion curada.',
            'curada'            => false,
            'modulo'            => self::modulo_de($tabla),
            'columna_dueno'     => 'user_id',
            'padre'             => null,
            'campos'            => $campos,
            'relaciones'        => $relaciones,
            'condiciones_fijas' => $condiciones,
        ];

        return self::cerrar_declaracion($declaracion);
    }

    /**
     * Un campo derivado de una columna: tipo por DATA_TYPE / COLUMN_TYPE, etiqueta por diccionario
     * y relación por convención cuando es un `*_id` entero.
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @param  array   $info
     * @param  array   $columnas
     * @return array<string, mixed>
     */
    protected static function campo_derivado(string $tabla, string $columna, array $info, array $columnas): array
    {
        $campo = [
            'tipo'     => self::tipo_de($info['tipo_dato'], $info['tipo_columna']),
            'etiqueta' => self::etiqueta_de($columna),
            'columna'  => $tabla . '.' . $columna,
        ];

        // Un enum le dice al modelo qué valores existen: sin esto filtra por "activo" donde la base
        // dice 'active'.
        if ($info['tipo_dato'] === 'enum') {
            $campo['valores'] = self::valores_del_enum($info['tipo_columna']);
        }

        if ($campo['tipo'] === 'number' && substr($columna, -3) === '_id') {
            $relacion = self::relacion_para($columna, $columnas);

            if (! is_null($relacion)) {
                $campo['tipo'] = 'search';
                $campo['relacion'] = $relacion;
            }
        }

        return $campo;
    }

    /**
     * @param  string  $columna
     * @return bool
     */
    protected static function columna_visible(string $columna): bool
    {
        if ($columna === 'user_id' || $columna === 'id') {
            return false;
        }

        return preg_match(self::COLUMNAS_SENSIBLES, $columna) !== 1;
    }

    /**
     * El tipo del vocabulario del asistente para un tipo de MySQL.
     *
     * @param  string  $tipo_dato     DATA_TYPE (int, varchar, ...)
     * @param  string  $tipo_columna  COLUMN_TYPE (tinyint(1), decimal(12,2), enum(...), ...)
     * @return string
     */
    public static function tipo_de(string $tipo_dato, string $tipo_columna): string
    {
        if ($tipo_columna === 'tinyint(1)') {
            return 'checkbox';
        }

        if (in_array($tipo_dato, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'], true)) {
            return 'number';
        }

        if (in_array($tipo_dato, ['date', 'datetime', 'timestamp'], true)) {
            return 'date';
        }

        if (in_array($tipo_dato, ['text', 'mediumtext', 'longtext', 'json'], true)) {
            return 'textarea';
        }

        return 'text';
    }

    /**
     * @param  string  $columna
     * @return string
     */
    public static function etiqueta_de(string $columna): string
    {
        if (isset(self::ETIQUETAS[$columna])) {
            return self::ETIQUETAS[$columna];
        }

        return str_replace('_', ' ', $columna);
    }

    /**
     * Los valores de un `enum('a','b')` de MySQL.
     *
     * @param  string  $tipo_columna
     * @return array<int, string>
     */
    protected static function valores_del_enum(string $tipo_columna): array
    {
        if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $tipo_columna, $coincidencias) === false) {
            return [];
        }

        return array_map(function ($valor) {
            return str_replace("\\'", "'", $valor);
        }, $coincidencias[1]);
    }

    /**
     * LA RELACIÓN DE UNA COLUMNA `*_id`, por convención.
     *
     *   1. `moneda_id` → etiquetas fijas (ver ETIQUETAS_DE_MONEDA).
     *   2. RELACIONES_POR_CONVENCION, por nombre exacto de columna.
     *   3. `x_id` → tabla `Str::plural('x')` si existe en el esquema y tiene columna `name`.
     *
     * El chequeo de existencia va contra el mapa de `information_schema` que ya se trajo, que es
     * exactamente lo que `Schema::hasTable` consultaría, sin una consulta por columna.
     *
     * `con_user_id` dice si la tabla destino está scopeada por dueño: el filtro por nombre lo usa
     * para no matchear un rubro de OTRO comercio que se llame igual.
     *
     * @param  string  $columna
     * @param  array   $columnas
     * @return array<string, mixed>|null
     */
    public static function relacion_para(string $columna, array $columnas)
    {
        if ($columna === 'moneda_id') {
            return ['tabla' => null, 'campo' => null, 'etiquetas_fijas' => self::ETIQUETAS_DE_MONEDA, 'con_user_id' => false];
        }

        if (isset(self::RELACIONES_POR_CONVENCION[$columna])) {
            $fija = self::RELACIONES_POR_CONVENCION[$columna];

            if (! isset($columnas[$fija['tabla']]) || ! isset($columnas[$fija['tabla']][$fija['campo']])) {
                return null;
            }

            return [
                'tabla'       => $fija['tabla'],
                'campo'       => $fija['campo'],
                'con_user_id' => isset($columnas[$fija['tabla']]['user_id']),
            ];
        }

        $destino = Str::plural(substr($columna, 0, -3));

        if (! isset($columnas[$destino]) || ! isset($columnas[$destino]['name'])) {
            return null;
        }

        return [
            'tabla'       => $destino,
            'campo'       => 'name',
            'con_user_id' => isset($columnas[$destino]['user_id']),
        ];
    }

    /**
     * Cómo se llama la etiqueta de una relación derivada en la respuesta: la columna sin `_id`
     * (`client_id` → `client`), y con el sufijo `_nombre` si ese nombre ya es una columna de la
     * tabla (`clients.address` existe al lado de `clients.address_id`) o ya es otra relación.
     *
     * @param  string  $columna
     * @param  array   $columnas_de_la_tabla
     * @param  array   $relaciones_ya_armadas
     * @return string
     */
    protected static function clave_de_relacion(string $columna, array $columnas_de_la_tabla, array $relaciones_ya_armadas): string
    {
        $clave = substr($columna, 0, -3);

        if (isset($columnas_de_la_tabla[$clave]) || isset($relaciones_ya_armadas[$clave])) {
            $clave .= '_nombre';
        }

        return $clave;
    }

    /**
     * Las condiciones fijas de una tabla: `deleted_at IS NULL` si tiene la columna, solo activos
     * para artículos, sin consolidaciones para ventas (la misma condición que
     * Sale::scopeSoloVentasReales, en SQL).
     *
     * @param  string  $tabla
     * @param  array   $columnas
     * @return array<int, array<string, string>>
     */
    protected static function condiciones_fijas_de(string $tabla, array $columnas): array
    {
        $condiciones = [];

        if (isset($columnas[$tabla]['deleted_at'])) {
            $condiciones[] = ['sql' => $tabla . '.deleted_at IS NULL', 'texto' => 'solo registros no borrados'];
        }

        if ($tabla === 'articles') {
            $condiciones[] = ['sql' => "articles.status = 'active'", 'texto' => 'solo articulos activos (los pausados y los borrados no aparecen)'];
        }

        if ($tabla === 'sales') {
            $condiciones[] = [
                'sql'   => '(sales.is_consolidacion_facturacion IS NULL OR sales.is_consolidacion_facturacion = 0)',
                'texto' => 'sin las ventas contenedoras de consolidacion AFIP (agrupan otras ventas ya contadas)',
            ];
        }

        return $condiciones;
    }

    /**
     * @param  string  $tabla
     * @return string
     */
    public static function modulo_de(string $tabla): string
    {
        foreach (self::MODULOS as $modulo => $tablas) {
            if (in_array($tabla, $tablas, true)) {
                return $modulo;
            }
        }

        return 'otros';
    }

    /**
     * LOS OVERRIDES: la curada manda en descripción, etiqueta, etiquetas de campos y relaciones;
     * la derivada aporta los campos que la curada no declaró.
     *
     * Los campos curados van primero y en su orden, y son los que viajan por defecto (el test
     * viejo de proyección fija exactamente esas claves). Los derivados que faltaban quedan
     * declarados —se filtra, se ordena y se agrupa por ellos, y se piden con `campos`— pero no
     * viajan si nadie los pide: una fila de `article` con sus ochenta columnas es justo lo que la
     * proyección declarada vino a evitar.
     *
     * @param  array  $derivada
     * @param  array  $curada
     * @param  array  $columnas
     * @return array<string, mixed>
     */
    protected static function aplicar_override(array $derivada, array $curada, array $columnas): array
    {
        $campos = [];

        foreach ($curada['campos'] as $nombre => $campo_curado) {
            $base = isset($derivada['campos'][$nombre]) ? $derivada['campos'][$nombre] : ['columna' => $derivada['tabla'] . '.' . $nombre];

            $campos[$nombre] = array_merge($base, [
                'tipo'        => $campo_curado['tipo'],
                'etiqueta'    => $campo_curado['etiqueta'],
                'por_defecto' => true,
            ]);
        }

        foreach ($derivada['campos'] as $nombre => $campo) {
            if (isset($campos[$nombre])) {
                continue;
            }

            $campo['por_defecto'] = false;
            $campos[$nombre] = $campo;
        }

        // Las relaciones curadas mandan; las derivadas entran solo sobre columnas que la curada no
        // relacionó (y sin pisar una clave curada).
        $relaciones = [];

        foreach ($curada['relaciones'] as $clave => $relacion) {
            $relaciones[$clave] = array_merge($relacion, [
                'con_user_id' => isset($columnas[$relacion['tabla']]['user_id']),
            ]);
        }

        $columnas_relacionadas = array_column($relaciones, 'columna_id');

        foreach ($derivada['relaciones'] as $clave => $relacion) {
            if (in_array($relacion['columna_id'], $columnas_relacionadas, true) || isset($relaciones[$clave])) {
                continue;
            }

            $relaciones[$clave] = $relacion;
        }

        $derivada['etiqueta']    = $curada['etiqueta'];
        $derivada['descripcion'] = $curada['descripcion'];
        $derivada['curada']      = true;
        $derivada['campos']      = $campos;
        $derivada['relaciones']  = $relaciones;

        return self::cerrar_declaracion($derivada);
    }

    /**
     * La declaración de una entidad hija: los campos propios derivados de su tabla más los del
     * padre con nombre propio, las relaciones de las dos mitades por convención, y el scope por
     * el padre (o por `columna_dueno` cuando no hay padre, como `empleado`).
     *
     * @param  string  $entidad
     * @param  array   $hija
     * @param  array   $columnas
     * @return array<string, mixed>
     */
    protected static function declaracion_hija(string $entidad, array $hija, array $columnas): array
    {
        $tabla = $hija['tabla'];
        $campos = [];
        $relaciones = [];

        $solo = isset($hija['solo_campos']) ? $hija['solo_campos'] : null;

        /*
         * Los campos del padre van PRIMERO: son los que dan contexto a la fila (de qué venta, de
         * qué fecha, de qué cliente) y, si la tabla propia tuviera más columnas que el tope por
         * defecto, son los que no pueden quedar afuera.
         */
        if (isset($hija['campos_del_padre'])) {
            foreach ($hija['campos_del_padre'] as $nombre => $campo) {
                if ($campo['tipo'] === 'search') {
                    $relacion = self::relacion_para($nombre, $columnas);

                    if (is_null($relacion)) {
                        $campo['tipo'] = 'number';
                    } else {
                        $clave = self::clave_de_relacion($nombre, $columnas[$tabla], $relaciones);
                        $relaciones[$clave] = array_merge($relacion, ['columna_id' => $nombre]);
                    }
                }

                $campos[$nombre] = $campo;
            }
        }

        foreach ($columnas[$tabla] as $columna => $info) {
            if (! self::columna_visible($columna) || isset($campos[$columna])) {
                continue;
            }

            if (! is_null($solo) && ! in_array($columna, $solo, true)) {
                continue;
            }

            if (isset($hija['padre']) && $columna === $hija['padre']['columna_local']) {
                // El id del padre viaja como número: es lo que permite encadenar con la entidad padre.
                $campos[$columna] = [
                    'tipo'     => 'number',
                    'etiqueta' => 'id de ' . str_replace('_', ' ', Str::singular($hija['padre']['tabla'])),
                    'columna'  => $tabla . '.' . $columna,
                ];
                continue;
            }

            $campo = self::campo_derivado($tabla, $columna, $info, $columnas);

            if (isset($hija['etiquetas'][$columna])) {
                $campo['etiqueta'] = $hija['etiquetas'][$columna];
            }

            if (isset($campo['relacion'])) {
                $clave = self::clave_de_relacion($columna, $columnas[$tabla], $relaciones);
                $relaciones[$clave] = array_merge($campo['relacion'], ['columna_id' => $columna]);
                unset($campo['relacion']);
            }

            $campos[$columna] = $campo;
        }

        $declaracion = [
            'entidad'           => $entidad,
            'tabla'             => $tabla,
            'etiqueta'          => $hija['etiqueta'],
            'descripcion'       => $hija['descripcion'],
            'curada'            => true,
            'modulo'            => $hija['modulo'],
            'columna_dueno'     => isset($hija['columna_dueno']) ? $hija['columna_dueno'] : 'user_id',
            'padre'             => isset($hija['padre']) ? $hija['padre'] : null,
            'campos'            => $campos,
            'relaciones'        => $relaciones,
            'condiciones_fijas' => $hija['condiciones_fijas'],
        ];

        return self::cerrar_declaracion($declaracion);
    }

    /**
     * Lo que toda declaración lleva al final: la lista de campos por defecto y los omitidos.
     *
     * Para una derivada sin override, los campos por defecto son los primeros
     * TOPE_COLUMNAS_POR_DEFECTO por orden ordinal; el resto va en `campos_omitidos`.
     *
     * @param  array  $declaracion
     * @return array<string, mixed>
     */
    protected static function cerrar_declaracion(array $declaracion): array
    {
        $por_defecto = [];
        $omitidos = [];

        $hay_marcas = false;

        foreach ($declaracion['campos'] as $nombre => $campo) {
            if (isset($campo['por_defecto'])) {
                $hay_marcas = true;
                break;
            }
        }

        $cuantos = 0;

        foreach ($declaracion['campos'] as $nombre => $campo) {
            if ($hay_marcas) {
                if (! empty($campo['por_defecto'])) {
                    $por_defecto[] = $nombre;
                } else {
                    $omitidos[] = $nombre;
                }

                continue;
            }

            if ($cuantos < self::TOPE_COLUMNAS_POR_DEFECTO) {
                $por_defecto[] = $nombre;
                $cuantos++;
            } else {
                $omitidos[] = $nombre;
            }
        }

        $declaracion['campos_por_defecto'] = $por_defecto;
        $declaracion['campos_omitidos'] = $omitidos;

        return $declaracion;
    }

    /**
     * Si una entidad tiene un campo `moneda_id` (propio o del padre): la agregación avisa cuando
     * hay registros en otra moneda.
     *
     * @param  array  $declaracion
     * @return bool
     */
    public static function tiene_moneda(array $declaracion): bool
    {
        return isset($declaracion['campos']['moneda_id']);
    }

    /**
     * Los `moneda_id` que son pesos, para el aviso de otra moneda (RecolectorBase::MONEDAS_PESOS
     * más null, que se trata aparte en SQL).
     *
     * @return array<int, int>
     */
    public static function monedas_pesos(): array
    {
        return RecolectorBase::MONEDAS_PESOS;
    }
}
