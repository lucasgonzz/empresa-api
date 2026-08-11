<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;

/**
 * Arma textos en español para notificar cambios en el perfil/configuración de `users`
 * tras un `UserController@update` (auth user y, si aplica, listas de precio del owner).
 */
class UserProfileChangeDescriptionHelper
{
    /**
     * Etiqueta del renglon unico que describe un cambio de redondeo. Es el mismo texto que el
     * cliente ve arriba del select en la configuracion de la cuenta.
     *
     * @var string
     */
    const LABEL_MODO_REDONDEO = 'Opciones de redondeo';

    /**
     * Columnas que el `update` del controlador puede modificar y que queremos reflejar en el aviso.
     *
     * @var array<int, string>
     */
    const TRACKED_FIELD_KEYS = [
        'name',
        'doc_number',
        'dollar',
        'company_name',
        'phone',
        'email',
        'download_articles',
        'iva_included',
        'ask_amount_in_vender',
        'sale_ticket_width',
        'default_current_acount_payment_method_id',
        'discount_stock_from_recipe_after_advance_to_next_status',
        'article_ticket_info_id',
        'dias_alertar_empleados_ventas_no_cobradas',
        'aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia',
        'dias_alertar_administradores_ventas_no_cobradas',
        'str_limint_en_vender',
        'sale_ticket_description',
        'siempre_omitir_en_cuenta_corriente',
        // Tarea 4: las cinco columnas de redondeo se siguen rastreando (el snapshot las necesita
        // para poder derivar el modo de antes y el de despues), pero NO producen una linea cada
        // una: build_change_descriptions() las saltea y emite un solo renglon "Opciones de
        // redondeo". Antes salian hasta cuatro renglones de tildes que el usuario nunca vio, y
        // desde el select directamente no existen como controles separados.
        // redondear_miles_en_vender se agrego acá: faltaba en las tres listas de este helper,
        // aunque ArticleHelper::redondear() la lee igual que a las otras cuatro.
        'redondear_miles_en_vender',
        'redondear_centenas_en_vender',
        'redondear_precios_en_decenas',
        'redondear_de_a_50',
        'redondear_precios_en_centavos',
        'header_articulos_pdf',
        'default_version',
        'estable_version',
        'api_url',
        'text_omitir_cc',
        'percentage_gain',
        'scroll_en_tablas',
        'cotizar_precios_en_dolares',
        'cc_ultimas_arriba',
        'show_stock_min_al_iniciar',
        'show_afip_errors_al_iniciar',
        'duracion_reporte_inventario',
        'usar_articles_cache',
        'sync_offline_articles',
        'clave_eliminar_article',
        'img_auto_timeout',
        'address_company',
        'all_addresses_in_sale_pdf',
        'mostrar_vendedor_en_venta_pdf',
        'pdf_image_size',
        'inputs_size_id',
        'aplicar_iva_al_costo',
        'aplicar_descuentos_de_venta_a_costos',
        'usa_provider_codes_repetidos',
    ];

    /**
     * Etiquetas legibles por campo (español).
     *
     * @var array<string, string>
     */
    const FIELD_LABELS = [
        'name' => 'Nombre',
        'doc_number' => 'Documento',
        'dollar' => 'Cotización del dólar',
        'company_name' => 'Nombre de la empresa',
        'phone' => 'Teléfono',
        'email' => 'Correo electrónico',
        'download_articles' => 'Descarga de artículos',
        'iva_included' => 'IVA incluido en precios',
        'ask_amount_in_vender' => 'Pedir importe al vender',
        'sale_ticket_width' => 'Ancho del ticket de venta',
        'default_current_acount_payment_method_id' => 'Medio de pago por defecto en cuenta corriente',
        'discount_stock_from_recipe_after_advance_to_next_status' => 'Descontar stock de recetas al avanzar estado',
        'article_ticket_info_id' => 'Información en ticket de artículo',
        'dias_alertar_empleados_ventas_no_cobradas' => 'Días para alertar empleados (ventas no cobradas)',
        'aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia' => 'Aplicar descuentos en artículos antes del margen',
        'dias_alertar_administradores_ventas_no_cobradas' => 'Días para alertar administradores (ventas no cobradas)',
        'str_limint_en_vender' => 'Texto límite en vender',
        'sale_ticket_description' => 'Descripción en ticket de venta',
        'siempre_omitir_en_cuenta_corriente' => 'Siempre omitir en cuenta corriente',
        // Tarea 4: se conservan por si alguna de estas columnas se muestra en otro contexto, pero
        // el cambio de redondeo se describe con la etiqueta unica de mas abajo (LABEL_MODO_REDONDEO).
        'redondear_miles_en_vender' => 'Redondear de a 1000',
        'redondear_centenas_en_vender' => 'Redondear centenas al vender',
        'redondear_precios_en_decenas' => 'Redondear precios en decenas',
        'redondear_de_a_50' => 'Redondear de a 50',
        'redondear_precios_en_centavos' => 'Redondear precios en centavos',
        'header_articulos_pdf' => 'Encabezado PDF de artículos',
        'default_version' => 'Versión por defecto',
        'estable_version' => 'Versión estable',
        'api_url' => 'URL de la API',
        'text_omitir_cc' => 'Texto omitir cuenta corriente',
        'percentage_gain' => 'Margen de ganancia global (%)',
        'scroll_en_tablas' => 'Scroll en tablas',
        'cotizar_precios_en_dolares' => 'Cotizar precios en dólares',
        'cc_ultimas_arriba' => 'Cuenta corriente: últimas arriba',
        'show_stock_min_al_iniciar' => 'Mostrar stock mínimo al iniciar',
        'show_afip_errors_al_iniciar' => 'Mostrar errores AFIP al iniciar',
        'duracion_reporte_inventario' => 'Minutos de duracion del reporte de inventario',
        'usar_articles_cache' => 'Usar caché de artículos',
        'sync_offline_articles' => 'Sincronizar artículos offline',
        'clave_eliminar_article' => 'Clave para eliminar artículo',
        'img_auto_timeout' => 'Timeout automático de imágenes',
        'address_company' => 'Dirección de la empresa',
        'all_addresses_in_sale_pdf' => 'Todas las direcciones en PDF de venta',
        'mostrar_vendedor_en_venta_pdf' => 'Mostrar vendedor en PDF de venta',
        'pdf_image_size' => 'Tamaño de imagen en PDF',
        'inputs_size_id' => 'Tamaño de inputs',
        'aplicar_iva_al_costo' => 'Aplicar IVA al costo',
        'aplicar_descuentos_de_venta_a_costos' => 'Aplicar descuentos de venta a costos',
        'usa_provider_codes_repetidos' => 'Permitir códigos de proveedor repetidos',
    ];

    /**
     * Toma solo los atributos relevantes para comparar antes/después del guardado.
     *
     * @param User $user Usuario autenticado (modelo en memoria).
     * @return array<string, mixed>
     */
    public static function snapshot_tracked_attributes(User $user)
    {
        return $user->only(self::TRACKED_FIELD_KEYS);
    }

    /**
     * Genera líneas de texto describiendo diferencias entre snapshots y el flag de listas del owner.
     *
     * @param array<string, mixed> $before_auth Valores antes del update (solo claves rastreadas).
     * @param array<string, mixed> $after_auth Valores después del update.
     * @param int|null $listas_before Valor previo de `listas_de_precio` del owner (0/1) o null si no hay owner aparte.
     * @param int|null $listas_after Valor nuevo del owner o null.
     * @return array<int, string> Líneas listas para mostrar en el modal.
     */
    public static function build_change_descriptions(array $before_auth, array $after_auth, $listas_before, $listas_after)
    {
        /** @var array<int, string> $lines Mensajes acumulados. */
        $lines = [];

        foreach (self::TRACKED_FIELD_KEYS as $field_key) {
            // Tarea 4: las cinco columnas de redondeo no describen una linea cada una. Se resuelven
            // todas juntas despues del loop, en un solo renglon.
            if (array_key_exists($field_key, User::COLUMNAS_MODO_REDONDEO)) {
                continue;
            }

            $old_val = array_key_exists($field_key, $before_auth) ? $before_auth[$field_key] : null;
            $new_val = array_key_exists($field_key, $after_auth) ? $after_auth[$field_key] : null;

            if (self::values_are_equivalent($field_key, $old_val, $new_val)) {
                continue;
            }

            $label = self::FIELD_LABELS[$field_key] ?? $field_key;
            $lines[] = $label.': de '.self::format_value_for_display($field_key, $old_val).' a '.self::format_value_for_display($field_key, $new_val);
        }

        // El renglon unico de redondeo, si efectivamente cambio el modo. Se compara el MODO y no
        // las columnas: pasar de "de a 100" a "de a 50" toca dos columnas y es un solo cambio para
        // el usuario, que es lo unico que vio en la pantalla.
        $modo_antes = self::derivar_modo_redondeo($before_auth);
        $modo_despues = self::derivar_modo_redondeo($after_auth);

        if ($modo_antes !== $modo_despues) {
            // 🔴 Este renglon usa la flecha y NO el ': de X a Y' de los demas campos, y no es una
            // inconsistencia: los textos de los modos YA empiezan con "de a" ("de a 100", "de a
            // 50"), asi que el molde comun producia "Opciones de redondeo: de de a 100 a de a 50".
            // Con la flecha queda "Opciones de redondeo: de a 100 → de a 50", que es exactamente el
            // formato que pedia la tarea 4. Si algun dia se unifica el molde de todos los renglones,
            // hay que cambiar antes los textos de texto_modo_redondeo(), no este join.
            $lines[] = self::LABEL_MODO_REDONDEO.': '.self::texto_modo_redondeo($modo_antes).' → '.self::texto_modo_redondeo($modo_despues);
        }

        if ($listas_before !== null && $listas_after !== null && (int) $listas_before !== (int) $listas_after) {
            $lines[] = 'Listas de precio: de '.self::format_bool_spanish((int) $listas_before).' a '.self::format_bool_spanish((int) $listas_after);
        }

        return $lines;
    }

    /**
     * Compara valores teniendo en cuenta tipos sueltos de la API (string "0", float, etc.).
     *
     * @param string $field_key Nombre de columna.
     * @param mixed $old_val Valor anterior.
     * @param mixed $new_val Valor nuevo.
     * @return bool true si se consideran iguales.
     */
    private static function values_are_equivalent($field_key, $old_val, $new_val)
    {
        if ($field_key === 'dollar' || $field_key === 'percentage_gain') {
            return abs((float) $old_val - (float) $new_val) < 0.000001;
        }

        return self::normalize_scalar($old_val) === self::normalize_scalar($new_val);
    }

    /**
     * Normaliza para comparación simple entre antes/después.
     *
     * @param mixed $v Valor crudo.
     * @return string Representación estable.
     */
    private static function normalize_scalar($v)
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return (string) $v;
    }

    /**
     * Formatea un valor para el texto del modal (español cuando aplica).
     *
     * @param string $field_key Columna.
     * @param mixed $v Valor.
     * @return string
     */
    private static function format_value_for_display($field_key, $v)
    {
        if ($v === null || $v === '') {
            return '(vacío)';
        }

        if (self::is_boolish_field($field_key)) {
            return self::format_bool_spanish((int) (bool) $v);
        }

        if ($field_key === 'dollar' || $field_key === 'percentage_gain') {
            return (string) $v;
        }

        if (is_string($v) && strlen($v) > 120) {
            return mb_substr($v, 0, 117).'…';
        }

        return (string) $v;
    }

    /**
     * Tarea 4 — deriva el modo de redondeo desde un SNAPSHOT (array de columnas), no desde el
     * modelo. Es la misma regla que `User::getModoRedondeoAttribute()`, aplicada sobre el array
     * que guarda `snapshot_tracked_attributes()`: una sola prendida devuelve ese modo, ninguna
     * devuelve 'sin_redondeo' y dos o mas devuelven 'personalizado'.
     *
     * @param array<string, mixed> $snapshot Valores de las columnas rastreadas.
     * @return string
     */
    private static function derivar_modo_redondeo(array $snapshot)
    {
        /** @var array<int, string> $prendidas Modos cuyas columnas estan en 1. */
        $prendidas = [];

        foreach (User::COLUMNAS_MODO_REDONDEO as $columna => $modo) {
            $valor = array_key_exists($columna, $snapshot) ? $snapshot[$columna] : null;

            if ((int) $valor === 1) {
                $prendidas[] = $modo;
            }
        }

        if (count($prendidas) === 0) {
            return User::MODO_SIN_REDONDEO;
        }

        if (count($prendidas) > 1) {
            return User::MODO_PERSONALIZADO;
        }

        return $prendidas[0];
    }

    /**
     * Texto legible de cada modo de redondeo, para el renglon del modal.
     *
     * @param string $modo Valor derivado por derivar_modo_redondeo().
     * @return string
     */
    private static function texto_modo_redondeo($modo)
    {
        /** @var array<string, string> $textos */
        $textos = [
            User::MODO_SIN_REDONDEO  => 'sin redondeo',
            'miles'                  => 'de a 1000',
            'centenas'               => 'de a 100',
            'decenas'                => 'de a 10',
            'cincuenta'              => 'de a 50',
            'centavos'               => 'sin centavos',
            User::MODO_PERSONALIZADO => 'combinacion personalizada',
        ];

        return array_key_exists($modo, $textos) ? $textos[$modo] : $modo;
    }

    /**
     * Indica si el campo se trata como booleano en UI (0/1 en base).
     *
     * @param string $field_key Nombre de columna.
     * @return bool
     */
    private static function is_boolish_field($field_key)
    {
        /** @var array<int, string> $bool_keys Campos típicamente 0/1 en `users`. */
        $bool_keys = [
            'download_articles',
            'iva_included',
            'ask_amount_in_vender',
            'siempre_omitir_en_cuenta_corriente',
            // Tarea 4: siguen siendo boolish (por si se muestran sueltas en otro contexto), y se
            // suma redondear_miles_en_vender, que faltaba.
            'redondear_miles_en_vender',
            'redondear_centenas_en_vender',
            'redondear_precios_en_decenas',
            'redondear_de_a_50',
            'redondear_precios_en_centavos',
            'scroll_en_tablas',
            'cotizar_precios_en_dolares',
            'cc_ultimas_arriba',
            'show_stock_min_al_iniciar',
            'show_afip_errors_al_iniciar',
            'usar_articles_cache',
            'sync_offline_articles',
            'all_addresses_in_sale_pdf',
            'mostrar_vendedor_en_venta_pdf',
            'aplicar_iva_al_costo',
            'aplicar_descuentos_de_venta_a_costos',
            'usa_provider_codes_repetidos',
            'aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia',
            'discount_stock_from_recipe_after_advance_to_next_status',
        ];

        return in_array($field_key, $bool_keys, true);
    }

    /**
     * @param int $v 0 o 1.
     * @return string
     */
    private static function format_bool_spanish($v)
    {
        return (int) $v ? 'sí' : 'no';
    }
}
