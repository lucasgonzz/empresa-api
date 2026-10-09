<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

/**
 * Esquema, límites y normalización del diseño de la hoja de un diseño de PDF
 * (`pdf_column_profiles.page_layout`, misión diseno-pdf-configurable, 1/10/2026).
 *
 * El JSON que se guarda:
 *
 * {
 *   "version": 1,
 *   "superior": [ ITEM, ... ],     // entre el encabezado y la tabla; sale en TODAS las hojas
 *   "pie":      [ ITEM, ... ]      // debajo de la tabla; en la última hoja, o en cada hoja si el
 *                                  // perfil tiene "Mostrar pie de página en cada hoja"
 * }
 *
 * ITEM es uno de:
 *   {"tipo": "caja", "id": "caja_1", "cols": 6, "titulo": "Cliente", "estilo": "borde", "campos": [CAMPO, ...]}
 *   {"tipo": "salto_de_fila", "id": "salto_1"}      // lo que sigue arranca en una fila nueva
 *   {"tipo": "fijo", "key": "afip_receptor", "cols": 12}   // solo factura de ARCA, solo en "superior"; cols 6..12
 *   {"tipo": "fijo", "key": "afip_pie", "importes": true}   // solo factura de ARCA, solo en "pie"
 *   {"tipo": "fijo", "key": "afip_emisor"}         // solo factura de ARCA en ticket de comandera, solo en "superior"
 *
 * CAMPO:
 *   {"key": "cliente_nombre", "etiqueta": null, "tamano": 9, "negrita": false, "cursiva": false,
 *    "alineacion": "izquierda"}
 *   - etiqueta: null = la del catálogo; "" = sin rótulo; otro texto = ese rótulo.
 *   - tamano / negrita / cursiva / alineacion: null = el estilo por defecto del catálogo.
 *   - el texto libre lleva además "id" (puede haber varios) y "texto".
 *
 * La grilla es la de Diseños de Vender: 12 columnas por fila; una caja de N columnas ocupa N/12 del
 * ancho útil de la hoja y, si no entra en lo que queda de la fila, baja a la siguiente.
 *
 * QUÉ NORMALIZA Y QUÉ NO. normalizar() deja el JSON dentro de los límites (columnas 1..12,
 * tamaños 6..24, textos cortados, topes de cantidad, ids válidos y únicos, un campo una sola vez)
 * y descarta lo que no tiene forma. NO filtra las `key` contra el catálogo: una key que el
 * catálogo no conoce (un campo que se retiró, o uno nuevo que mandó un SPA más nuevo) se guarda y
 * el PDF la saltea. Mismo criterio que vender_layouts.layout.
 */
class DisenoDePaginaPdf
{
    const VERSION = 1;

    const ZONAS = ['superior', 'pie'];

    const TIPO_CAJA = 'caja';
    const TIPO_SALTO_DE_FILA = 'salto_de_fila';
    const TIPO_FIJO = 'fijo';

    /** borde = recuadro con línea fina; gris = fondo gris claro con borde suave (el de los totales); ninguno = sin recuadro. */
    const ESTILOS_DE_CAJA = ['borde', 'gris', 'ninguno'];
    const ESTILO_DE_CAJA_POR_DEFECTO = 'borde';

    const ALINEACIONES = ['izquierda', 'centro', 'derecha'];

    /**
     * Ancho mínimo (en columnas) del bloque del cliente de la factura de ARCA. Más angosto, sus dos
     * columnas internas no se leen. El bloque de importes, QR y CAE va siempre a lo ancho (12).
     */
    const COLS_MIN_AFIP_RECEPTOR = 6;

    const MAX_ITEMS_POR_ZONA = 24;
    const MAX_CAMPOS_POR_CAJA = 30;
    const MAX_TITULO = 80;
    const MAX_ETIQUETA = 60;
    const MAX_TEXTO_LIBRE = 1000;

    const TAMANO_MIN = 6;
    const TAMANO_MAX = 24;

    /** Margen de la hoja (mm), el mismo para los cuatro lados. */
    const MARGEN_MIN = 0;
    const MARGEN_MAX = 20;

    /** Alto de hoja por defecto (A4) cuando paper_height_mm es null. */
    const ALTO_DE_HOJA_POR_DEFECTO = 297;
    /** Topes del alto de hoja que acepta la API (mm). */
    const ALTO_DE_HOJA_MIN = 100;
    const ALTO_DE_HOJA_MAX = 600;

    const PATRON_KEY = '/^[a-z0-9_]{1,60}$/';
    const PATRON_ID = '/^[a-z0-9_]{1,40}$/';

    /**
     * Un diseño vacío (sin cajas), con la forma completa.
     *
     * @return array
     */
    public static function vacio()
    {
        return [
            'version' => self::VERSION,
            'superior' => [],
            'pie' => [],
        ];
    }

    /**
     * ¿El perfil tiene un diseño de hoja armado en el diseñador? Es lo que decide si el PDF sale
     * con el dibujante de cajas o con el de siempre.
     *
     * @param \App\Models\PdfColumnProfile|null $profile
     * @return bool
     */
    public static function tiene_diseno($profile)
    {
        return ! is_null($profile)
            && is_array($profile->page_layout)
            && (isset($profile->page_layout['superior']) || isset($profile->page_layout['pie']));
    }

    /**
     * Normaliza lo que llega (del request o de la base) a un diseño válido, o null.
     *
     * @param mixed $valor array, string JSON o null.
     * @return array|null null si no hay diseño (null, '' o 'null').
     * @throws \InvalidArgumentException si no tiene la forma de un diseño (el controller responde 422).
     */
    public static function normalizar($valor)
    {
        if (is_null($valor) || $valor === '' || $valor === 'null') {
            return null;
        }

        if (is_string($valor)) {
            $decodificado = json_decode($valor, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('El diseño de la hoja no tiene un formato válido.');
            }
            $valor = $decodificado;
        }

        if (is_null($valor)) {
            return null;
        }

        if (! is_array($valor) || (! array_key_exists('superior', $valor) && ! array_key_exists('pie', $valor))) {
            throw new \InvalidArgumentException('El diseño de la hoja no tiene un formato válido.');
        }

        /**
         * Estado compartido entre zonas: ids ya usados, campos ya ubicados (cada uno una sola vez)
         * y los ids que trae el JSON. Los ids que se generan para lo que vino sin id (o con uno
         * inválido) esquivan esos reservados: si no, un ítem sin id de más arriba podía quedarse
         * con el id explícito de uno de más abajo y obligarlo a cambiar el suyo.
         */
        $estado = [
            'ids' => [],
            'keys' => [],
            'contador' => 0,
            'reservados' => self::ids_explicitos($valor),
        ];

        $diseno = self::vacio();

        foreach (self::ZONAS as $zona) {
            $items = isset($valor[$zona]) && is_array($valor[$zona]) ? array_values($valor[$zona]) : [];
            $diseno[$zona] = self::normalizar_zona($zona, $items, $estado);
        }

        return $diseno;
    }

    /**
     * Garantiza los bloques fijos de la factura de ARCA: en un perfil fiscal, el bloque del cliente
     * tiene que estar en "superior" y el de importes/QR/CAE en "pie" (si faltan, el primero va al
     * principio y el segundo al final); en un perfil no fiscal se sacan los dos.
     *
     * Se llama al guardar Y al dibujar: un perfil puede cambiar de "Es factura de ARCA" desde el
     * formulario sin pasar por el diseñador.
     *
     * TICKET DE COMANDERA (misión diseno-ticket-comandera, 9/10/2026, contrato §3.5): con
     * `$es_ticket` y fiscal se suma el bloque del emisor (`afip_emisor`, al principio de "superior"
     * si falta; el del cliente, si falta, va al final de "superior", pegado a la tabla), y los tres
     * van a lo ancho del rollo (el del cliente queda en 12 columnas). En la hoja el del emisor NO
     * existe (lo imprime el encabezado del PDF): si llega, se saca. La firma es compatible: sin el
     * tercer parámetro todo queda como antes.
     *
     * @param array $diseno     diseño ya normalizado.
     * @param bool  $es_fiscal  is_afip_ticket del perfil.
     * @param bool  $es_ticket  el perfil es un ticket de comandera (default: hoja).
     * @return array
     */
    public static function asegurar_fijos($diseno, $es_fiscal, $es_ticket = false)
    {
        foreach (self::ZONAS as $zona) {
            if (! isset($diseno[$zona]) || ! is_array($diseno[$zona])) {
                $diseno[$zona] = [];
            }
        }

        if (! $es_fiscal) {
            foreach (self::ZONAS as $zona) {
                $diseno[$zona] = array_values(array_filter($diseno[$zona], function ($item) {
                    return ! (isset($item['tipo']) && $item['tipo'] === self::TIPO_FIJO);
                }));
            }

            return $diseno;
        }

        if ($es_ticket) {
            return self::asegurar_fijos_de_ticket($diseno);
        }

        /** En la hoja el emisor lo imprime el encabezado del PDF: el bloque fijo del ticket no va. */
        $diseno['superior'] = array_values(array_filter($diseno['superior'], function ($item) {
            return ! (isset($item['tipo'], $item['key']) && $item['tipo'] === self::TIPO_FIJO && $item['key'] === CatalogoDeCamposPdf::FIJO_AFIP_EMISOR);
        }));

        if (! self::zona_tiene_fijo($diseno['superior'], CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR)) {
            array_unshift($diseno['superior'], [
                'tipo' => self::TIPO_FIJO,
                'key' => CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR,
                'cols' => 12,
            ]);
        }

        if (! self::zona_tiene_fijo($diseno['pie'], CatalogoDeCamposPdf::FIJO_AFIP_PIE)) {
            $diseno['pie'][] = [
                'tipo' => self::TIPO_FIJO,
                'key' => CatalogoDeCamposPdf::FIJO_AFIP_PIE,
                'importes' => true,
            ];
        }

        return $diseno;
    }

    /**
     * Los tres bloques fijos de una factura de ARCA en un ticket de comandera (decisión D8): el del
     * emisor y el del cliente en "superior", el de IVA, CAE y QR en "pie". Los que faltan se ponen
     * (el emisor al principio, el cliente al final de "superior", el pie al final) y el del cliente
     * queda a lo ancho (12 columnas): en el rollo los tres van siempre a lo ancho.
     *
     * @param array $diseno diseño ya normalizado, con las dos zonas como arreglo.
     * @return array
     */
    private static function asegurar_fijos_de_ticket($diseno)
    {
        if (! self::zona_tiene_fijo($diseno['superior'], CatalogoDeCamposPdf::FIJO_AFIP_EMISOR)) {
            array_unshift($diseno['superior'], [
                'tipo' => self::TIPO_FIJO,
                'key' => CatalogoDeCamposPdf::FIJO_AFIP_EMISOR,
            ]);
        }

        if (! self::zona_tiene_fijo($diseno['superior'], CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR)) {
            $diseno['superior'][] = [
                'tipo' => self::TIPO_FIJO,
                'key' => CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR,
                'cols' => 12,
            ];
        }

        /** El del cliente, a lo ancho del rollo aunque el diseño traiga otro ancho. */
        foreach ($diseno['superior'] as $i => $item) {
            if (isset($item['tipo'], $item['key']) && $item['tipo'] === self::TIPO_FIJO && $item['key'] === CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR) {
                $diseno['superior'][$i]['cols'] = 12;
            }
        }

        if (! self::zona_tiene_fijo($diseno['pie'], CatalogoDeCamposPdf::FIJO_AFIP_PIE)) {
            $diseno['pie'][] = [
                'tipo' => self::TIPO_FIJO,
                'key' => CatalogoDeCamposPdf::FIJO_AFIP_PIE,
                'importes' => true,
            ];
        }

        return $diseno;
    }

    /**
     * ¿La zona tiene ese bloque fijo?
     *
     * @param array  $items
     * @param string $key
     * @return bool
     */
    private static function zona_tiene_fijo($items, $key)
    {
        foreach ($items as $item) {
            if (isset($item['tipo'], $item['key']) && $item['tipo'] === self::TIPO_FIJO && $item['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza los ítems de una zona.
     *
     * @param string $zona
     * @param array  $items
     * @param array  $estado ids y keys ya usados (por referencia).
     * @return array
     */
    private static function normalizar_zona($zona, $items, &$estado)
    {
        $resultado = [];

        foreach ($items as $item) {
            if (count($resultado) >= self::MAX_ITEMS_POR_ZONA) {
                break;
            }

            if (! is_array($item) || ! isset($item['tipo']) || ! is_string($item['tipo'])) {
                continue;
            }

            if ($item['tipo'] === self::TIPO_CAJA) {
                $resultado[] = self::normalizar_caja($item, $estado);
                continue;
            }

            if ($item['tipo'] === self::TIPO_SALTO_DE_FILA) {
                $resultado[] = [
                    'tipo' => self::TIPO_SALTO_DE_FILA,
                    'id' => self::id_unico(isset($item['id']) ? $item['id'] : null, 'salto', $estado),
                ];
                continue;
            }

            if ($item['tipo'] === self::TIPO_FIJO) {
                $fijo = self::normalizar_fijo($zona, $item, $estado);
                if (! is_null($fijo)) {
                    $resultado[] = $fijo;
                }
            }
        }

        return $resultado;
    }

    /**
     * Normaliza una caja.
     *
     * @param array $item
     * @param array $estado
     * @return array
     */
    private static function normalizar_caja($item, &$estado)
    {
        $cols = isset($item['cols']) && is_numeric($item['cols']) ? (int) $item['cols'] : 12;
        $cols = max(1, min(12, $cols));

        $estilo = isset($item['estilo']) && in_array($item['estilo'], self::ESTILOS_DE_CAJA, true)
            ? $item['estilo']
            : self::ESTILO_DE_CAJA_POR_DEFECTO;

        $campos = [];
        $campos_entrantes = isset($item['campos']) && is_array($item['campos']) ? array_values($item['campos']) : [];

        foreach ($campos_entrantes as $campo) {
            if (count($campos) >= self::MAX_CAMPOS_POR_CAJA) {
                break;
            }

            $normalizado = self::normalizar_campo($campo, $estado);
            if (! is_null($normalizado)) {
                $campos[] = $normalizado;
            }
        }

        return [
            'tipo' => self::TIPO_CAJA,
            'id' => self::id_unico(isset($item['id']) ? $item['id'] : null, 'caja', $estado),
            'cols' => $cols,
            'titulo' => self::texto_acotado(isset($item['titulo']) ? $item['titulo'] : '', self::MAX_TITULO, true),
            'estilo' => $estilo,
            'campos' => $campos,
        ];
    }

    /**
     * Normaliza un campo de una caja, o null si no sirve (key inválida o repetida).
     *
     * @param mixed $campo
     * @param array $estado
     * @return array|null
     */
    private static function normalizar_campo($campo, &$estado)
    {
        if (! is_array($campo) || ! isset($campo['key']) || ! is_string($campo['key']) || ! preg_match(self::PATRON_KEY, $campo['key'])) {
            return null;
        }

        $key = $campo['key'];
        $es_texto_libre = $key === CatalogoDeCamposPdf::KEY_TEXTO_LIBRE;

        /** Cada campo una sola vez en todo el diseño, salvo el texto libre (se distingue por id). */
        if (! $es_texto_libre) {
            if (isset($estado['keys'][$key])) {
                return null;
            }
            $estado['keys'][$key] = true;
        }

        $normalizado = [
            'key' => $key,
            'etiqueta' => array_key_exists('etiqueta', $campo) && ! is_null($campo['etiqueta'])
                ? self::texto_acotado($campo['etiqueta'], self::MAX_ETIQUETA, true)
                : null,
            'tamano' => isset($campo['tamano']) && is_numeric($campo['tamano'])
                ? max(self::TAMANO_MIN, min(self::TAMANO_MAX, (int) $campo['tamano']))
                : null,
            'negrita' => array_key_exists('negrita', $campo) && ! is_null($campo['negrita']) ? (bool) $campo['negrita'] : null,
            'cursiva' => array_key_exists('cursiva', $campo) && ! is_null($campo['cursiva']) ? (bool) $campo['cursiva'] : null,
            'alineacion' => isset($campo['alineacion']) && in_array($campo['alineacion'], self::ALINEACIONES, true)
                ? $campo['alineacion']
                : null,
        ];

        if ($es_texto_libre) {
            $normalizado['id'] = self::id_unico(isset($campo['id']) ? $campo['id'] : null, 'texto', $estado);
            $normalizado['texto'] = self::texto_acotado(isset($campo['texto']) ? $campo['texto'] : '', self::MAX_TEXTO_LIBRE, false);
        }

        return $normalizado;
    }

    /**
     * Normaliza un bloque fijo, o null si no corresponde a esa zona o ya estaba.
     *
     * @param string $zona
     * @param array  $item
     * @param array  $estado
     * @return array|null
     */
    private static function normalizar_fijo($zona, $item, &$estado)
    {
        $key = isset($item['key']) ? $item['key'] : null;

        /**
         * El del emisor (misión diseno-ticket-comandera) solo existe en el ticket de comandera, pero
         * acá no se sabe la clase del perfil: se acepta en "superior" y asegurar_fijos() lo saca de
         * un diseño de hoja.
         */
        $zona_del_fijo = [
            CatalogoDeCamposPdf::FIJO_AFIP_EMISOR => 'superior',
            CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR => 'superior',
            CatalogoDeCamposPdf::FIJO_AFIP_PIE => 'pie',
        ];

        if (! is_string($key) || ! isset($zona_del_fijo[$key]) || $zona_del_fijo[$key] !== $zona) {
            return null;
        }

        if (isset($estado['keys']['fijo:'.$key])) {
            return null;
        }
        $estado['keys']['fijo:'.$key] = true;

        $fijo = [
            'tipo' => self::TIPO_FIJO,
            'key' => $key,
        ];

        if ($key === CatalogoDeCamposPdf::FIJO_AFIP_PIE) {
            $fijo['importes'] = array_key_exists('importes', $item) ? (bool) $item['importes'] : true;
        }

        /** El bloque del cliente de ARCA cambia de ancho (6..12, como los obligatorios de Vender). */
        if ($key === CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR) {
            $cols = isset($item['cols']) && is_numeric($item['cols']) ? (int) $item['cols'] : 12;
            $fijo['cols'] = max(self::COLS_MIN_AFIP_RECEPTOR, min(12, $cols));
        }

        return $fijo;
    }

    /**
     * Un id válido y único en todo el diseño: el que vino si sirve, o uno nuevo con el prefijo.
     *
     * @param mixed  $id
     * @param string $prefijo 'caja' | 'salto' | 'texto'
     * @param array  $estado
     * @return string
     */
    private static function id_unico($id, $prefijo, &$estado)
    {
        if (is_string($id) && preg_match(self::PATRON_ID, $id) && ! isset($estado['ids'][$id])) {
            $estado['ids'][$id] = true;

            return $id;
        }

        do {
            $estado['contador']++;
            $nuevo = $prefijo.'_'.$estado['contador'];
        } while (isset($estado['ids'][$nuevo]) || isset($estado['reservados'][$nuevo]));

        $estado['ids'][$nuevo] = true;

        return $nuevo;
    }

    /**
     * Los ids válidos que trae el JSON (cajas, saltos de fila y textos libres), para que los que se
     * generen no los pisen.
     *
     * @param array $valor diseño sin normalizar (ya decodificado).
     * @return array<string, bool>
     */
    private static function ids_explicitos($valor)
    {
        /**
         * Conjunto (id => true) y ventana ACOTADA: se miran como mucho 4 veces los topes de ítems y
         * de campos. La reserva solo sirve para que un id generado no le gane a uno explícito; la
         * unicidad la garantiza id_unico() igual. Sin el tope y con búsquedas lineales, un JSON con
         * miles de ids tardaba segundos en normalizarse (cuadrático): lo midió el revisor de la API
         * el 1/10/2026 (595 KB → 6,5 s).
         */
        $ids = [];
        $tope_de_items = self::MAX_ITEMS_POR_ZONA * 4;
        $tope_de_campos = self::MAX_CAMPOS_POR_CAJA * 4;

        foreach (self::ZONAS as $zona) {
            if (! isset($valor[$zona]) || ! is_array($valor[$zona])) {
                continue;
            }

            foreach (array_slice(array_values($valor[$zona]), 0, $tope_de_items) as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id']) && preg_match(self::PATRON_ID, $item['id'])) {
                    $ids[$item['id']] = true;
                }

                if (! is_array($item) || ! isset($item['campos']) || ! is_array($item['campos'])) {
                    continue;
                }

                foreach (array_slice(array_values($item['campos']), 0, $tope_de_campos) as $campo) {
                    if (is_array($campo) && isset($campo['id']) && is_string($campo['id']) && preg_match(self::PATRON_ID, $campo['id'])) {
                        $ids[$campo['id']] = true;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * Texto acotado a un largo (en caracteres, no bytes).
     *
     * @param mixed $texto
     * @param int   $maximo
     * @param bool  $una_linea true = sin saltos de línea y sin espacios a los costados.
     * @return string
     */
    private static function texto_acotado($texto, $maximo, $una_linea)
    {
        if (! is_scalar($texto)) {
            return '';
        }

        $texto = (string) $texto;

        /**
         * Se recortan los espacios ANTES y DESPUÉS de cortar el largo. Solo antes no alcanza: si el
         * corte cae justo detrás de un espacio, ese espacio queda colgando al final y una segunda
         * pasada de normalizar() lo saca, así que normalizar dos veces no daba lo mismo (un título
         * de 80 volvía con 79). Lo detectó el constructor API-A el 1/10/2026.
         */
        if ($una_linea) {
            $texto = trim(preg_replace('/\s+/u', ' ', $texto));

            return trim(mb_substr($texto, 0, $maximo));
        }

        $texto = rtrim(str_replace("\r\n", "\n", $texto));

        return rtrim(mb_substr($texto, 0, $maximo));
    }
}
