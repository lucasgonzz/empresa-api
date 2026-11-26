<?php

namespace App\Http\Controllers\Helpers\ResetDatosNegocio;

use Illuminate\Support\Facades\DB;

/**
 * Catálogo cerrado de tablas del reset de datos de negocio (misión reset-datos-cliente, 21/11/2026).
 *
 * El reset deja el sistema "igual que cuando se ejecutó el user setup": vacía con TRUNCATE (ids otra vez desde 1)
 * lo que el cliente cargó, importó o generó al operar, y conserva la configuración. Para que eso no dependa de
 * adivinar, CADA tabla de la base está en exactamente UNA de estas tres listas (esta clase es la fuente única):
 *
 *   - TABLAS_A_VACIAR: negocio. Lo cargó, importó o generó el cliente al operar; o es derivado/volátil y apunta a
 *     ids de negocio (reportes, snapshots, jobs, conversaciones). Incluye los catálogos que llegan con la
 *     importación (categorías, subcategorías, marcas, bodegas, cepas, tipos de envase, etiquetas, servicios,
 *     localidades de clientes) porque son "datos importados".
 *   - TABLAS_DE_CONFIGURACION: se conservan. Las arma el user setup o el dueño al configurar (usuarios, permisos,
 *     extensiones, ARCA, listas de precio, cajas como entidad, sucursales, plantillas, métodos de pago, comisiones,
 *     vendedores, conectores) o son catálogos de referencia sembrados. Algunas llevan un AJUSTE (ver AJUSTES).
 *   - TABLAS_DE_SISTEMA: se conservan. Infraestructura, soporte con ComercioCity, consumo facturable de IA,
 *     prueba/demo, versiones y sesiones del ERP, y la tabla de registro del propio reset (que no se vacía nunca).
 *
 * 🔴 Una tabla nueva sin clasificar rompe `ClasificacionDeTablasDelResetTest` en la suite y, en producción, el
 * reset se NIEGA (`tablas_sin_clasificar`): no se adivina qué hacer con lo que nadie decidió. Al agregar una
 * migración con una tabla nueva hay que sumarla a una de las tres listas.
 *
 * Mover una tabla de lista es una decisión de producto: se hace con test que la fije y con el motivo en el commit.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class CatalogoDeTablasDelResetHelper
{
    /**
     * Tablas de negocio: se vacían con TRUNCATE (siempre todas, tengan o no filas, para reiniciar el AUTO_INCREMENT).
     *
     * @var array<int, string>
     */
    const TABLAS_A_VACIAR = [
        'acopio_article_deliveries', 'acopio_article_delivery_article', 'address_article', 'address_article_variant',
        'advises', 'afip_errors', 'afip_observations', 'afip_tickets', 'agente_consolidacion_estados',
        'agente_consolidacion_hallazgos', 'agente_consolidacion_memorias', 'agente_consolidaciones', 'ai_archivos',
        'ai_avisos_del_asistente', 'ai_conversations', 'ai_envios_fallidos', 'ai_message_actions', 'ai_message_imagenes',
        'ai_messages', 'ai_tareas_del_asistente', 'ai_tareas_del_asistente_ejecuciones', 'answers', 'apertura_cajas', 'article_actualizados_article_import_result', 'article_actualizados_import_history',
        'article_article_price_type_group', 'article_article_ubication', 'article_articles_pre_import',
        'article_budget', 'article_cart', 'article_color', 'article_combo', 'article_commerce_order',
        'article_compact_embeddings', 'article_creados_article_import_result', 'article_creados_import_history',
        'article_current_acount', 'article_deposit', 'article_deposit_movement', 'article_deposit_movement_modification_antes',
        'article_deposit_movement_modification_despues', 'article_description_proposals', 'article_discount_blancos',
        'article_discounts', 'article_image_search_attempts', 'article_import_result_observations', 'article_import_results',
        'article_inventory_performance', 'article_me_li_order', 'article_meli_attribute', 'article_order',
        'article_order_production', 'article_order_production_finished', 'article_performances', 'article_pre_import_ranges',
        'article_price_ranges', 'article_price_type', 'article_price_type_monedas', 'article_prices', 'article_prices_list',
        'article_promocion_vinoteca', 'article_properties', 'article_property_article_property_value', 'article_property_value_article_variant',
        'article_provider', 'article_provider_order', 'article_purchases', 'article_recipe', 'article_recipe_route',
        'article_sale', 'article_sale_modification_antes_de_actualizar', 'article_sale_modification_despues_de_actualizar',
        'article_sale_tax', 'article_size', 'article_surchage_blancos', 'article_surchages', 'article_tag',
        'article_tienda_nube_order', 'article_variants', 'articles', 'articles_pre_imports', 'audit_logs',
        'background_processes', 'bodegas', 'brands', 'budget_combo', 'budget_discount', 'budget_observations',
        'budget_order_production_status', 'budget_product_article_stocks', 'budget_product_deliveries',
        'budget_products', 'budget_promocion_vinoteca', 'budget_service', 'budget_surchage', 'budgets',
        'buyer_addresses', 'buyer_messages', 'buyer_tracking_daily', 'buyer_tracking_events', 'buyers',
        'caja_caja_resumen', 'calls', 'cart_combo', 'cart_cupon', 'cart_promocion_vinoteca', 'carts',
        'categories', 'category_inventory_linkage', 'category_price_type', 'category_price_type_ranges',
        'category_proposal_items', 'category_proposal_nodes', 'category_proposal_runs', 'category_proposals',
        'category_seller', 'cepas', 'cheques', 'client_discount', 'client_offer_ranges', 'client_offers',
        'client_reputations', 'client_surchage', 'clients', 'combo_price_type', 'combo_sale', 'combos',
        'commerce_orders', 'commissioner_sale', 'company_performance_address_payment_method', 'company_performance_expense_concept',
        'company_performance_expense_concept_usd', 'company_performance_gasto', 'company_performance_info_facturacions',
        'company_performance_ingresos_cuenta_corriente', 'company_performance_ingresos_mostrador', 'company_performance_ingresos_total',
        'company_performance_price_type', 'company_performance_user', 'company_performance_user_payment_method',
        'company_performances', 'credit_account_snapshots', 'credit_accounts', 'cuotas', 'cupon_order',
        'cupons', 'current_acount_current_acount_payment_method', 'current_acount_discount', 'current_acount_payment_method_sale',
        'current_acount_seller_commission', 'current_acount_service', 'current_acount_surchage', 'current_acounts',
        'debt_snapshots', 'deposit_movement_modifications', 'deposit_movements', 'descriptions', 'dimension_search_items',
        'dimension_search_runs', 'discount_order', 'discount_sale', 'embedding_runs', 'envios', 'excel_analysis_runs',
        'expense_current_acount_payment_method', 'expenses', 'export_histories', 'failed_jobs', 'filter_histories',
        'ia_imagen_pedidos', 'image_assignment_items', 'image_assignment_runs', 'images', 'import_conflicts',
        'import_equivalencias', 'import_histories', 'import_statuses', 'importacion_de_soporte_cambios',
        'importaciones_de_prueba', 'importaciones_de_soporte', 'impressions', 'inventory_linkages', 'inventory_performances',
        'job_batches', 'jobs', 'last_searches', 'locations', 'masive_update_article', 'masive_updates',
        'meli_buyers', 'meli_order_article', 'meli_order_cancel_details', 'meli_order_sync_from_meli_order',
        'meli_order_tags', 'meli_orders', 'mercado_pago_qr_cobros', 'messages', 'mostrador_accesos', 'mostrador_memorias',
        'mostrador_reportes', 'movimiento_cajas', 'movimiento_entre_cajas', 'movimiento_punto_consumos',
        'movimiento_puntos', 'nota_credito_descriptions', 'offer_suggestion_lines', 'offer_suggestions',
        'order_combo',
        // order_communication_deliveries (misión tienda-compra-comunicaciones, 19/11/2026): un registro por cada envío de
        // comunicación de un pedido de la tienda (order_id, canal, tipo y hash del destinatario) para no mandarlo dos
        // veces (idempotencia). Es historial operativo del cliente. Si sobreviviera al reset, el nuevo pedido N° 1 chocaría
        // con las filas viejas del mismo order_id y la idempotencia suprimiría sus correos; por eso se vacía junto con orders.
        'order_communication_deliveries',
        'order_productions', 'order_promocion_vinoteca', 'order_stock_reservations', 'order_surchage',
        'orders', 'pagado_por', 'payment_card_infos', 'payment_plan_cuotas', 'payment_plans', 'payments',
        'pdf_links', 'pending_completeds', 'pendings', 'platelets', 'price_change_price_type', 'price_changes',
        'price_type_sub_category', 'price_update_run_articles', 'price_update_runs', 'prices_lists', 'print_jobs',
        'product_enrichments', 'production_batch_movement_inputs', 'production_batch_movements', 'production_batches',
        'production_movements', 'promocion_vinoteca_sale', 'promocion_vinotecas', 'provider_discounts',
        'provider_import_mappings', 'provider_order_afip_ticket_ivas', 'provider_order_afip_tickets', 'provider_order_discounts',
        'provider_order_extra_costs', 'provider_order_scan_images', 'provider_order_scans', 'provider_orders',
        'provider_price_lists', 'provider_price_offers', 'providers', 'purchase_suggestion_articles', 'purchase_suggestions',
        'questions', 'recipe_routes', 'recipes', 'resumen_cajas', 'retenciones_sufridas', 'road_map_client_observations',
        'road_map_client_positions', 'road_map_sale', 'road_maps', 'sale_article_attachments', 'sale_delivery_infos',
        'sale_modifications', 'sale_pdf_access_tokens', 'sale_service', 'sale_surchage', 'sale_taxes',
        'sales', 'seller_commission_current_acount_payment_method', 'seller_commissions', 'semilla_registros',
        'services', 'stock_movements', 'stock_suggestion_articles', 'stock_suggestions', 'sub_categories',
        'super_budget_feature_items', 'super_budget_features', 'super_budget_titles', 'super_budgets',
        'sync_from_meli_article_article', 'sync_from_meli_articles', 'sync_from_meli_orders', 'sync_to_meli_articles',
        'sync_to_t_n_articles', 'tags', 'task_user', 'tasks', 'tienda_nube_orders', 'tipo_envases',
        // unmet_demands (misión demanda-sin-stock-vender, 21/11/2026): un registro por cada freno de Vender por falta
        // de stock. Es demanda operativa del cliente (guarda article_id, article_variant_id, combo_id, address_id y el
        // user_id dueño) y alimenta las sugerencias de compra, ya vaciadas. No es configuración: tras vaciar articles
        // quedaría con ids colgados, así que se vacía. Lo único que apunta a ella es el asistente de IA (lectura).
        // La columna demanda_no_satisfecha_* de purchase_suggestion_articles no necesita ajuste: la tabla ya se vacía.
        'unmet_demands',
        'views',
        'whatsapp_chat_messages', 'whatsapp_chats',
    ];

    /**
     * Tablas de configuración: se conservan. Las que tienen un enlace a negocio que deja de existir llevan un
     * ajuste de filas (ver AJUSTES): cajas, users, addresses, discounts, column_positions, balanzas.
     *
     * @var array<int, string>
     */
    const TABLAS_DE_CONFIGURACION = [
        'acceso_soporte_tokens', 'addresses', 'afip_information', 'afip_selected_payment_methods', 'afip_tipo_comprobantes',
        'agente_contexto_archivos', 'agente_contexto_revisiones', 'ai_conocimiento_del_negocio', 'article_pdf_observations', 'article_pdfs',
        'article_price_type_groups', 'article_property_types', 'article_property_values', 'article_ticket_designs',
        'article_ticket_infos', 'article_ubications', 'balanzas', 'budget_statuses', 'business_hours_configs',
        'buyer_message_default_responses', 'c_a_payment_method_types', 'caja_current_acount_payment_method',
        'caja_liquidacion_configs', 'caja_treasury_user', 'caja_user', 'cajas', 'cards', 'check_statuses',
        'cheque_bancos', 'client_homes', 'colors', 'column_positions', 'commission_except_seller', 'commission_for_all_seller',
        'commission_for_only_seller', 'commission_seller', 'commissioners', 'commissions', 'company_infos',
        'concepto_movimiento_cajas', 'concepto_stock_movements', 'conditions', 'credentials', 'credit_card_payment_plans',
        'credit_cards', 'current_acount_payment_method_discounts', 'current_acount_payment_methods', 'dealers',
        'default_payment_method_cajas', 'delivery_days', 'delivery_zones', 'deposit_movement_statuses',
        'deposits', 'dimension_reference_sites', 'discounts', 'dolar_cotizacion_registros', 'etiqueta_medidas',
        'expense_categories', 'expense_concepts', 'extencion_empresa_user', 'extencion_empresas', 'extencion_user',
        'extencions', 'feature_plan', 'features', 'inputs_sizes', 'inventory_linkage_scopes', 'iva_conditions',
        'ivas', 'localidads', 'medidas_envio_paquetes', 'meli_attribute_tags', 'meli_attribute_values',
        'meli_attributes', 'meli_buying_modes', 'meli_categories', 'meli_item_conditions', 'meli_listing_types',
        'meli_order_statuses', 'mercado_libre_tokens', 'mercado_pago_qr_cajas', 'monedas', 'online_configurations',
        'online_price_types', 'online_templates', 'order_production_status_groups', 'order_production_statuses',
        'order_statuses', 'pais_exportacions', 'payment_method_installments', 'payment_method_types', 'payment_methods',
        'pdf_column_option_profile', 'pdf_column_options', 'pdf_column_profiles', 'permission_beta_plan',
        'permission_beta_user', 'permission_betas', 'permission_empresa_user', 'permission_empresas', 'permission_plan',
        'permission_user', 'permissions', 'plan_features', 'plan_plan_feature', 'plans', 'platform_connectors',
        'platforms', 'price_type_sistema_de_puntos', 'price_type_surchages', 'price_types', 'print_agents',
        'production_batch_movement_types', 'production_batch_statuses', 'promocion_vinoteca_commissions',
        'provicias', 'provider_order_statuses', 'provincias', 'recipe_route_types', 'retencion_impuestos',
        'sale_channels', 'sale_sender_infos', 'sale_statuses', 'sale_types', 'schedule_workday', 'schedules',
        'sellers', 'sheet_types', 'sistemas_de_puntos', 'sizes', 'surchages', 'table_column_preferences',
        'tienda_nube_order_statuses', 'titles', 'turno_cajas', 'unidad_frecuencias', 'unidad_medidas',
        'user_configurations', 'user_workday', 'users', 'vender_keyboard_shortcuts', 'vender_layouts',
        'venta_terminada_commissions', 'whatsapp_bot_configs', 'whatsapp_templates', 'workdays',
    ];

    /**
     * Tablas de sistema: se conservan. `reset_datos_negocio_registros` es el registro de cada simulación y
     * ejecución de este mismo reset y no se vacía nunca; `personal_access_tokens` se conserva por los tokens de los
     * usuarios del ERP (los de compradores de la tienda se borran con un ajuste).
     *
     * @var array<int, string>
     */
    const TABLAS_DE_SISTEMA = [
        // Aviso de actualización del sistema (misión aviso-actualizacion-sistema, 25/11/2026): estado operativo del
        // pipeline de actualización y presencia de computadoras. No son datos del negocio y no apuntan a ids de
        // negocio; son del mismo orden que `mantenimientos`, `installed_versions` y `version_session_transfers`.
        // Vaciarlas en un reset borraría el estado `actualizando` de una actualización en curso.
        'actualizacion_dispositivos', 'actualizaciones_del_sistema',
        'ai_token_usages', 'cache', 'cache_locks', 'centro_recursos_pases', 'demo_eventos', 'demo_ingreso_tokens',
        'demo_tracking_config', 'errors', 'geocoder_counters', 'image_service_calls', 'installed_versions', 'local_actividad_eventos',
        'mantenimientos', 'migrations', 'oauth_states', 'password_resets', 'personal_access_tokens', 'prueba_config',
        'prueba_hitos', 'reset_datos_negocio_registros', 'serper_usage_events', 'support_message_attachments',
        'support_messages', 'support_tickets', 'support_tours', 'support_typing_states', 'synced_version_notification_reads',
        'synced_version_notifications', 'synced_versions', 'update_features', 'user_payments', 'version_session_transfers',
        'zippin_oauth_states',
    ];

    /**
     * Ajustes sobre filas de tablas que SE CONSERVAN pero apuntan a datos de negocio que dejan de existir.
     *
     * Cada ajuste: `tabla`, `accion` (`update` o `delete`), `set` (columna => valor; sólo en `update`) y `filtro`
     * (null = todas las filas; o `['columna' => ..., 'operador' => 'no_nulo'|'como', 'valor' => ...]`). Si la tabla o
     * alguna de las columnas no existe en la base (esquema viejo), el ajuste se saltea y se informa en
     * `ajustes_salteados`: nunca se inventa una columna.
     *
     * @var array<string, array>
     */
    const AJUSTES = [
        // La caja existe (es configuración) pero sin el saldo ni la apertura vigente que le dejó la operación.
        'cajas' => [
            'tabla' => 'cajas', 'accion' => 'update', 'filtro' => null,
            'set' => ['saldo' => 0, 'abierta' => 0, 'abierta_at' => null, 'cerrada_at' => null, 'current_apertura_caja_id' => null],
        ],
        // Fuerza a cada navegador a borrar su IndexedDB (artículos y clientes cacheados con los ids viejos).
        'users_eliminar_articulos_offline' => [
            'tabla' => 'users', 'accion' => 'update', 'filtro' => null,
            'set' => ['eliminar_articulos_offline' => 1],
        ],
        // Cursores del asesor de avisos sobre ids de `stock_movements` y `orders` (sólo donde no son NULL).
        'users_cursor_de_stock' => [
            'tabla' => 'users', 'accion' => 'update',
            'filtro' => ['columna' => 'agente_aviso_stock_cursor', 'operador' => 'no_nulo', 'valor' => null],
            'set' => ['agente_aviso_stock_cursor' => 0],
        ],
        'users_cursor_de_pedidos' => [
            'tabla' => 'users', 'accion' => 'update',
            'filtro' => ['columna' => 'agente_aviso_pedidos_cursor', 'operador' => 'no_nulo', 'valor' => null],
            'set' => ['agente_aviso_pedidos_cursor' => 0],
        ],
        // Marcas de "última generación" de sugerencias y ofertas: hablan de datos que ya no existen.
        'users_ultimas_generaciones' => [
            'tabla' => 'users', 'accion' => 'update', 'filtro' => null,
            'set' => ['sugerencias_ultima_generacion_at' => null, 'sugerencias_compras_ultima_generacion_at' => null, 'ofertas_ultima_generacion_at' => null],
        ],
        // Direcciones de compradores de la tienda (las sucursales del comercio tienen buyer_id NULL y se conservan).
        'addresses_de_compradores' => [
            'tabla' => 'addresses', 'accion' => 'delete',
            'filtro' => ['columna' => 'buyer_id', 'operador' => 'no_nulo', 'valor' => null],
            'set' => [],
        ],
        // Descuentos atados a un cliente que se borra (los generales, sin cliente, se conservan).
        'discounts_de_clientes' => [
            'tabla' => 'discounts', 'accion' => 'delete',
            'filtro' => ['columna' => 'client_id', 'operador' => 'no_nulo', 'valor' => null],
            'set' => [],
        ],
        // El mapeo de columnas de Excel se conserva (sirve para reimportar) pero sin el proveedor que ya no existe.
        'column_positions_proveedor' => [
            'tabla' => 'column_positions', 'accion' => 'update', 'filtro' => null,
            'set' => ['provider_id' => null],
        ],
        // La balanza se conserva sin el artículo.
        'balanzas_articulo' => [
            'tabla' => 'balanzas', 'accion' => 'update', 'filtro' => null,
            'set' => ['article_id' => null],
        ],
        // Tokens Sanctum de compradores de la tienda: con ids reiniciados, un token viejo autenticaría al comprador
        // NUEVO que reciba el mismo id (la tienda comparte la base). Los de usuarios del ERP se conservan.
        'tokens_de_compradores' => [
            'tabla' => 'personal_access_tokens', 'accion' => 'delete',
            'filtro' => ['columna' => 'tokenable_type', 'operador' => 'como', 'valor' => '%Buyer'],
            'set' => [],
        ],
    ];

    /**
     * Carpetas de `storage/app` cuyo contenido cuelga de ids de negocio (adjuntos de ventas, escaneos de
     * pedidos a proveedores, imágenes del asistente, chats de WhatsApp, archivos importados). Se MUEVEN al
     * respaldo de archivos, nunca se borran. Las imágenes de artículos van aparte: comparten carpeta
     * (`storage/app/public`) con logos y sólo se mueven las que `images` referencia.
     *
     * @var array<int, string>
     */
    const CARPETAS_DE_ARCHIVOS = [
        'vender-items', 'provider_order_scans', 'asistente_imagenes', 'whatsapp', 'imported_files',
    ];

    /**
     * Tablas reales (sin vistas) de la base de la conexión por defecto.
     *
     * @return array<int, string> Nombres de tabla.
     */
    public static function tablas_reales()
    {
        return DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->pluck('TABLE_NAME')
            ->all();
    }

    /**
     * Todas las tablas clasificadas (las tres listas juntas, sin repetidos).
     *
     * @return array<int, string>
     */
    public static function tablas_clasificadas()
    {
        return array_values(array_unique(array_merge(self::TABLAS_A_VACIAR, self::TABLAS_DE_CONFIGURACION, self::TABLAS_DE_SISTEMA)));
    }

    /**
     * Tablas que existen en la base pero no están en ninguna de las tres listas (una migración nueva que nadie
     * clasificó, o una base más nueva que el código). Con alguna, el reset se niega.
     *
     * @return array<int, string> Nombres de tabla, ordenados.
     */
    public static function tablas_sin_clasificar()
    {
        $sin_clasificar = array_values(array_diff(self::tablas_reales(), self::tablas_clasificadas()));
        sort($sin_clasificar);

        return $sin_clasificar;
    }

    /**
     * Tablas que figuran en más de una lista (o repetidas dentro de una): un error de tipeo del catálogo, porque
     * una tabla no puede vaciarse y conservarse a la vez.
     *
     * @return array<int, string> Nombres de tabla, ordenados.
     */
    public static function tablas_duplicadas()
    {
        $apariciones = array_count_values(array_merge(self::TABLAS_A_VACIAR, self::TABLAS_DE_CONFIGURACION, self::TABLAS_DE_SISTEMA));
        $duplicadas = [];

        foreach ($apariciones as $tabla => $veces) {
            if ($veces > 1) {
                $duplicadas[] = (string) $tabla;
            }
        }
        sort($duplicadas);

        return $duplicadas;
    }
}
