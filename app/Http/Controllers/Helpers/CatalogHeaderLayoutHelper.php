<?php

namespace App\Http\Controllers\Helpers;

/**
 * Toda la lógica del encabezado del PDF del catálogo de artículos que se puede probar sin FPDF:
 * el esquema de pdf_column_profiles.catalog_header_layout, su normalización, los datos del
 * negocio que se pueden imprimir, el default de un perfil nuevo y las reglas de render (en qué
 * hoja sale cada cosa, cómo se resuelve un renglón, cómo se encaja el logo).
 *
 * Vive acá y no adentro de ArticleTablePdf porque las clases de Pdf/ hacen `require` de fpdf.php
 * y terminan en Output(); exit; — no se pueden instanciar desde PHPUnit. Lo único que queda en
 * la clase FPDF es dibujar.
 *
 * Misión catalogo-pdf-encabezado (18/9/2026).
 */
class CatalogHeaderLayoutHelper
{
    /** El elemento sale en todas las hojas del catálogo. */
    const PAGES_ALL = 'all';

    /** El elemento sale solo en la primera hoja. */
    const PAGES_FIRST = 'first';

    /** Lado mayor del logo, en mm: mínimo, máximo y valor por defecto. */
    const LOGO_SIZE_MM_MIN = 10;
    const LOGO_SIZE_MM_MAX = 60;
    const LOGO_SIZE_MM_DEFAULT = 25;

    /** Tope de renglones por columna (izquierda / derecha). */
    const MAX_ROWS_PER_COLUMN = 15;

    /** Largo máximo del título y del valor de un renglón, en caracteres. */
    const MAX_TITLE_LENGTH = 60;
    const MAX_VALUE_LENGTH = 200;

    /**
     * Fuentes de datos del negocio: clave => label. ÚNICA fuente de verdad (la SPA la copia en
     * catalog_header_designer_catalog.js). El orden de este array es el orden en que se ofrecen.
     */
    const SOURCE_LABELS = [
        'telefono'            => 'Teléfono',            // users.phone
        'email'               => 'Email',               // users.email
        'direccion'           => 'Dirección',           // primera Address del dueño: "street street_number, city" (partes vacías se omiten)
        'cuit'                => 'CUIT',                // afip_information.cuit
        'razon_social'        => 'Razón Social',        // afip_information.razon_social
        'domicilio_comercial' => 'Domicilio Comercial', // afip_information.domicilio_comercial
        'condicion_iva'       => 'Condición IVA',       // afip_information.iva_condition.name
        'ingresos_brutos'     => 'Ingresos Brutos',     // afip_information.ingresos_brutos
    ];

    /** Claves de primer nivel que reconoce el esquema; un array sin ninguna de ellas no es un layout. */
    const LAYOUT_KEYS = ['logo', 'company_name', 'rows_pages', 'izquierda', 'derecha'];

    // ── Datos del negocio ─────────────────────────────────────────────────────

    /**
     * Datos del negocio disponibles para el encabezado, en el orden de SOURCE_LABELS.
     * Mismo criterio que AfipPdfHelper::build_emisor_field_values() para los PDF de venta:
     * teléfono y email salen de users, el resto de afip_information; lo que no está es ''.
     *
     * @param  \App\Models\User|null  $user  Dueño del negocio (UserHelper::getFullModel()).
     * @return array  [ ['key' => 'telefono', 'label' => 'Teléfono', 'value' => '11 5555-5555'], ... ]
     */
    public static function sources_for_user($user): array
    {
        $values = self::source_values_for_user($user);

        $sources = [];
        foreach (self::SOURCE_LABELS as $key => $label) {
            $sources[] = [
                'key'   => $key,
                'label' => $label,
                'value' => $values[$key],
            ];
        }

        return $sources;
    }

    /**
     * Mapa clave => valor actual del negocio (todas las claves de SOURCE_LABELS, '' si no hay dato).
     *
     * @param  \App\Models\User|null  $user
     * @return array<string, string>
     */
    protected static function source_values_for_user($user): array
    {
        $values = [];
        foreach (array_keys(self::SOURCE_LABELS) as $key) {
            $values[$key] = '';
        }

        if (is_null($user)) {
            return $values;
        }

        $values['telefono'] = self::clean_value($user->phone);
        $values['email'] = self::clean_value($user->email);
        $values['direccion'] = self::first_address_line($user);

        $afip_information = $user->afip_information;
        if (! is_null($afip_information)) {
            $values['cuit'] = self::clean_value($afip_information->cuit);
            $values['razon_social'] = self::clean_value($afip_information->razon_social);
            $values['domicilio_comercial'] = self::clean_value($afip_information->domicilio_comercial);
            $values['ingresos_brutos'] = self::clean_value($afip_information->ingresos_brutos);

            $iva_condition = $afip_information->iva_condition;
            if (! is_null($iva_condition)) {
                $values['condicion_iva'] = self::clean_value($iva_condition->name);
            }
        }

        return $values;
    }

    /**
     * Primera dirección del dueño como "calle número, ciudad", omitiendo las partes vacías.
     *
     * @param  \App\Models\User  $user
     * @return string  '' si el dueño no tiene direcciones cargadas.
     */
    protected static function first_address_line($user): string
    {
        $addresses = $user->addresses;
        if (is_null($addresses) || ! count($addresses)) {
            return '';
        }

        $address = $addresses->first();

        $street = trim(self::clean_value($address->street).' '.self::clean_value($address->street_number));
        $city = self::clean_value($address->city);

        $parts = [];
        if ($street !== '') {
            $parts[] = $street;
        }
        if ($city !== '') {
            $parts[] = $city;
        }

        return implode(', ', $parts);
    }

    /**
     * Valor escalar del negocio como string sin espacios de borde ('' si es null).
     *
     * @param  mixed  $value
     * @return string
     */
    protected static function clean_value($value): string
    {
        return trim((string) $value);
    }

    // ── Default de un perfil nuevo ────────────────────────────────────────────

    /**
     * Diseño con el que arranca un perfil de artículo que todavía no tiene encabezado: logo en
     * todas las hojas a 25 mm, nombre del negocio, y a la izquierda el teléfono y el email
     * solo si el negocio los tiene cargados. Ya viene normalizado.
     *
     * @param  \App\Models\User|null  $user
     * @return array
     */
    public static function default_for_user($user): array
    {
        $values = self::source_values_for_user($user);

        $izquierda = [];
        foreach (['telefono', 'email'] as $key) {
            if ($values[$key] === '') {
                continue;
            }
            $izquierda[] = [
                'title'  => self::SOURCE_LABELS[$key],
                'value'  => $values[$key],
                'source' => $key,
            ];
        }

        return [
            'logo' => [
                'show'    => true,
                'pages'   => self::PAGES_ALL,
                'size_mm' => self::LOGO_SIZE_MM_DEFAULT,
            ],
            'company_name' => [
                'show' => true,
            ],
            'rows_pages' => self::PAGES_ALL,
            'izquierda'  => $izquierda,
            'derecha'    => [],
        ];
    }

    // ── Normalización ─────────────────────────────────────────────────────────

    /**
     * Convierte lo que llega del request (array, string JSON o nada) en un layout limpio con el
     * esquema exacto, o null si no hay layout. Es idempotente: normalize(normalize($x)) === normalize($x).
     *
     * - null / '' => null. String => json_decode; si no es array => null.
     * - Array sin ninguna clave conocida => null.
     * - logo.show y company_name.show: bool, default true. logo.pages y rows_pages: solo 'all' o
     *   'first', cualquier otra cosa => 'all'. logo.size_mm: entero acotado a [10, 60], default 25.
     * - Renglones: title y value a string, sin espacios de borde, recortados a 60 / 200; source solo
     *   si está en SOURCE_LABELS, si no null. Un renglón sin source y con título y valor vacíos se
     *   descarta. Máximo 15 por columna. Sin uid ni ninguna clave extra.
     *
     * @param  mixed  $value
     * @return array|null
     */
    public static function normalize($value): ?array
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return null;
        }

        $has_known_key = false;
        foreach (self::LAYOUT_KEYS as $key) {
            if (array_key_exists($key, $value)) {
                $has_known_key = true;
                break;
            }
        }
        if (! $has_known_key) {
            return null;
        }

        $logo = (isset($value['logo']) && is_array($value['logo'])) ? $value['logo'] : [];
        $company_name = (isset($value['company_name']) && is_array($value['company_name'])) ? $value['company_name'] : [];

        return [
            'logo' => [
                'show'    => self::to_bool($logo['show'] ?? null, true),
                'pages'   => self::normalize_pages($logo['pages'] ?? null),
                'size_mm' => self::normalize_logo_size($logo['size_mm'] ?? null),
            ],
            'company_name' => [
                'show' => self::to_bool($company_name['show'] ?? null, true),
            ],
            'rows_pages' => self::normalize_pages($value['rows_pages'] ?? null),
            'izquierda'  => self::normalize_rows($value['izquierda'] ?? null),
            'derecha'    => self::normalize_rows($value['derecha'] ?? null),
        ];
    }

    /**
     * 'all' | 'first'; cualquier otra cosa cae a 'all'.
     *
     * @param  mixed  $pages
     * @return string
     */
    protected static function normalize_pages($pages): string
    {
        return $pages === self::PAGES_FIRST ? self::PAGES_FIRST : self::PAGES_ALL;
    }

    /**
     * Entero entre LOGO_SIZE_MM_MIN y LOGO_SIZE_MM_MAX; sin valor (o no numérico) => default.
     *
     * @param  mixed  $size_mm
     * @return int
     */
    protected static function normalize_logo_size($size_mm): int
    {
        if (is_null($size_mm) || $size_mm === '' || ! is_numeric($size_mm)) {
            return self::LOGO_SIZE_MM_DEFAULT;
        }

        return max(self::LOGO_SIZE_MM_MIN, min(self::LOGO_SIZE_MM_MAX, (int) $size_mm));
    }

    /**
     * Bool tolerante: acepta bool, 0/1 y los strings "true"/"false"/"0"/"1" que pueden llegar
     * por query string; sin valor (o irreconocible) => default.
     *
     * @param  mixed  $value
     * @param  bool   $default
     * @return bool
     */
    protected static function to_bool($value, bool $default): bool
    {
        if (is_null($value) || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return is_null($parsed) ? $default : $parsed;
        }

        return (bool) $value;
    }

    /**
     * Renglones de una columna con el esquema exacto (title, value, source) y nada más.
     *
     * @param  mixed  $rows
     * @return array
     */
    protected static function normalize_rows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = self::truncate_text($row['title'] ?? '', self::MAX_TITLE_LENGTH);
            $value = self::truncate_text($row['value'] ?? '', self::MAX_VALUE_LENGTH);
            $source = (isset($row['source']) && is_string($row['source']) && array_key_exists($row['source'], self::SOURCE_LABELS))
                ? $row['source']
                : null;

            if (is_null($source) && $title === '' && $value === '') {
                continue;
            }

            $normalized[] = [
                'title'  => $title,
                'value'  => $value,
                'source' => $source,
            ];

            if (count($normalized) >= self::MAX_ROWS_PER_COLUMN) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * String recortado a $max_length caracteres (UTF-8) y sin espacios de borde. Se recorta ANTES
     * del último trim para que una segunda normalización no cambie nada (idempotencia).
     *
     * @param  mixed  $text
     * @param  int    $max_length
     * @return string
     */
    protected static function truncate_text($text, int $max_length): string
    {
        if (is_array($text) || is_object($text)) {
            return '';
        }

        $text = trim((string) $text);

        return trim(mb_substr($text, 0, $max_length, 'UTF-8'));
    }

    // ── Reglas de render ──────────────────────────────────────────────────────

    /**
     * Renglones listos para imprimir. Un renglón con source imprime el valor ACTUAL del negocio
     * (no el que quedó guardado) con el título guardado, y se saltea si ese dato hoy está vacío
     * (no se imprime "Teléfono:" en blanco). Un renglón libre se imprime tal cual; si el título
     * está vacío, el render imprime solo el valor.
     *
     * @param  array                 $rows     Renglones normalizados de una columna.
     * @param  \App\Models\User|null $user     Dueño del negocio; se ignora si viene $sources.
     * @param  array|null            $sources  Salida de sources_for_user(), para no resolverla por columna.
     * @return array  [ ['title' => ..., 'value' => ..., 'source' => ...], ... ]
     */
    public static function resolve_rows(array $rows, $user, ?array $sources = null): array
    {
        if (is_null($sources)) {
            $sources = self::sources_for_user($user);
        }

        $current_values = [];
        foreach ($sources as $source) {
            if (isset($source['key'])) {
                $current_values[$source['key']] = trim((string) ($source['value'] ?? ''));
            }
        }

        $resolved = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            $source = $row['source'] ?? null;

            if (! is_null($source)) {
                if (! array_key_exists($source, $current_values) || $current_values[$source] === '') {
                    continue;
                }
                $value = $current_values[$source];
            }

            if ($title === '' && $value === '') {
                continue;
            }

            $resolved[] = [
                'title'  => $title,
                'value'  => $value,
                'source' => $source,
            ];
        }

        return $resolved;
    }

    /**
     * ¿Un elemento configurado para $pages se ve en la hoja $page_no?
     * 'first' => solo en la 1; cualquier otra cosa (incluida basura) => en todas.
     *
     * @param  mixed  $pages
     * @param  mixed  $page_no
     * @return bool
     */
    public static function element_visible_on_page($pages, $page_no): bool
    {
        if ($pages === self::PAGES_FIRST) {
            return (int) $page_no === 1;
        }

        return true;
    }

    /**
     * Ancho y alto en mm para dibujar la imagen encajada en un cuadrado de $size_mm de lado,
     * respetando su proporción: el lado mayor mide $size_mm y el otro lo que corresponda.
     *
     * @param  string|null  $image_path  Ruta local de la imagen (ya resuelta para FPDF).
     * @param  mixed        $size_mm     Lado del cuadrado, en mm.
     * @return array|null   ['width' => float, 'height' => float]; null si la imagen no se puede leer.
     */
    public static function logo_box_dimensions_mm($image_path, $size_mm): ?array
    {
        if (empty($image_path) || ! is_file($image_path)) {
            return null;
        }

        $size_mm = (float) $size_mm;
        if ($size_mm <= 0) {
            return null;
        }

        try {
            $info = @getimagesize($image_path);
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_array($info) || empty($info[0]) || empty($info[1])) {
            return null;
        }

        $width_px = (float) $info[0];
        $height_px = (float) $info[1];

        if ($width_px >= $height_px) {
            $width_mm = $size_mm;
            $height_mm = $size_mm * $height_px / $width_px;
        } else {
            $height_mm = $size_mm;
            $width_mm = $size_mm * $width_px / $height_px;
        }

        return [
            'width'  => round($width_mm, 2),
            'height' => round($height_mm, 2),
        ];
    }
}
