<?php

namespace App\Services\DimensionSearch;

use App\Services\ProductFacts\Gtin;

/**
 * Los datos estructurados (JSON-LD de schema.org) de una página de producto: peso y medidas tal como el sitio
 * los publica para los buscadores (misión medidas-sitios-de-referencia, 11/11/2026). Sin red y sin estado.
 *
 * POR QUÉ: una ficha como la de OBI trae `"weight":{"@type":"QuantitativeValue","unitCode":"KGM","value":2.7}`.
 * La IA lo cita tal cual y el validador no lo puede comprobar: la unidad va ANTES del número y es un código
 * UN/CEFACT (KGM, CMT), no «kg». Era el `fuente_desconocida` / `cita_no_coincide_con_el_valor` de muchas propuestas.
 * Acá se lee por código, no por lo que diga la IA.
 *
 * QUÉ DEVUELVE de_html(): una lista de nodos de producto (`Product`, `ProductModel`, `IndividualProduct`,
 * `ProductGroup`, o un nodo sin `@type` que trae `gtin*` / `sku`), cada uno con:
 *   - `codigos`: gtin8, gtin12, gtin13, gtin14, gtin, mpn, sku (los que tenga, como string; también los de sus
 *     `offers` directas, que a veces son las que traen el gtin);
 *   - `campos`: peso, alto, ancho, profundidad => {valor (kg | cm), unidad, valor_original, unidad_original,
 *     convertido, cita (el fragmento JSON tal cual está en la página), alcance: 'producto'}.
 * Se leen `weight`, `height`, `width` y `depth` (y `length` si no hay `depth`), como QuantitativeValue con
 * `unitCode` o `unitText`, o como texto («2,7 kg»). Un nodo sin campos ni códigos no se devuelve.
 *
 * 🔴 NADA DE ACÁ SE ESCRIBE SOLO: el motor solo usa estos campos para completar lo que la IA no pudo citar, de una
 * página que trae el código del artículo y que la IA NO descartó como `otro_producto`; y cada campo pasa por los
 * topes de cordura del validador (DimensionValueValidator::validar_estructurado()).
 *
 * Un bloque que no es JSON válido se saltea (no rompe la lectura de la página).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionStructuredData
{
    /** Propiedad de schema.org => campo del artículo (en orden: `length` solo si no hay `depth`). */
    const PROPIEDADES = [
        'weight' => 'peso',
        'height' => 'alto',
        'width'  => 'ancho',
        'depth'  => 'profundidad',
        'length' => 'profundidad',
    ];

    /** Códigos UN/CEFACT (Rec. 20) de las unidades => [tipo, factor a kg / cm, etiqueta]. */
    const CODIGOS_DE_UNIDAD = [
        'KGM' => [DimensionValueValidator::TIPO_PESO, 1, 'kg'],
        'GRM' => [DimensionValueValidator::TIPO_PESO, 0.001, 'g'],
        'MGM' => [DimensionValueValidator::TIPO_PESO, 0.000001, 'mg'],
        'LBR' => [DimensionValueValidator::TIPO_PESO, 0.45359237, 'lb'],
        'ONZ' => [DimensionValueValidator::TIPO_PESO, 0.028349523125, 'oz'],
        'CMT' => [DimensionValueValidator::TIPO_MEDIDA, 1, 'cm'],
        'MMT' => [DimensionValueValidator::TIPO_MEDIDA, 0.1, 'mm'],
        'MTR' => [DimensionValueValidator::TIPO_MEDIDA, 100, 'm'],
        'INH' => [DimensionValueValidator::TIPO_MEDIDA, 2.54, 'in'],
    ];

    /** Los `@type` que son un producto. */
    const TIPOS_DE_PRODUCTO = ['product', 'productmodel', 'individualproduct', 'productgroup'];

    /** Las claves de código que se devuelven. */
    const CLAVES_DE_CODIGO = ['gtin8', 'gtin12', 'gtin13', 'gtin14', 'gtin', 'mpn', 'sku'];

    /** Profundidad máxima al recorrer el JSON (un JSON-LD real tiene 4 o 5 niveles). */
    const PROFUNDIDAD_MAXIMA = 10;

    /** Bytes de JSON-LD como mucho que se procesan por página. */
    const MAXIMO_JSON_LD = 200000;

    /** Largo máximo de una cita. */
    const MAXIMO_CITA = 300;

    /**
     * Los nodos de producto de los JSON-LD de una página.
     *
     * @param  string $html
     * @return array  Lista de ['codigos' => array, 'campos' => array].
     */
    public static function de_html($html)
    {
        $html = (string) $html;

        if (stripos($html, 'ld+json') === false) {
            return [];
        }

        if (!preg_match_all('#<script\b[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is', $html, $bloques)) {
            return [];
        }

        $nodos = [];
        $total = 0;

        foreach ($bloques[1] as $crudo) {
            $total += strlen($crudo);

            if ($total > self::MAXIMO_JSON_LD) {
                break;
            }

            $datos = self::decodificar($crudo);

            if (is_null($datos)) {
                continue;
            }

            $encontrados = [];
            self::recorrer($datos, $encontrados, 0);

            foreach ($encontrados as $nodo) {
                $armado = self::armar_nodo($nodo, $crudo);

                if (!empty($armado['campos']) || !empty($armado['codigos'])) {
                    $nodos[] = $armado;
                }
            }
        }

        return $nodos;
    }

    /**
     * El nodo que corresponde al artículo: el primero con campos cuyo GTIN es el del artículo, o cuyo `mpn` / `sku`
     * es el código de proveedor (sin importar mayúsculas, espacios, guiones ni puntos). Si ninguno coincide por
     * código y la página trae el código del artículo en otro lado, el único nodo con campos (si hay más de uno, son
     * variantes y no se sabe cuál es: null).
     *
     * @param  array       $nodos      Los de de_html().
     * @param  string|null $gtin       El código de barras del artículo (solo si es un GTIN válido).
     * @param  string|null $proveedor  El código de proveedor del artículo (solo si parece un código).
     * @param  bool        $pagina_trae_el_codigo  La página (el extracto) trae algún código del artículo.
     * @return array|null  ['nodo' => array, 'por_codigo' => bool]
     */
    public static function del_articulo(array $nodos, $gtin, $proveedor, $pagina_trae_el_codigo)
    {
        $con_campos = [];

        foreach ($nodos as $nodo) {
            if (!empty($nodo['campos'])) {
                $con_campos[] = $nodo;
            }
        }

        if (empty($con_campos)) {
            return null;
        }

        $canonico  = is_null($gtin) || $gtin === '' ? null : Gtin::canonical((string) $gtin);
        $compacto  = is_null($proveedor) ? '' : self::compactar($proveedor);

        foreach ($con_campos as $nodo) {
            foreach ($nodo['codigos'] as $clave => $codigo) {
                if (!is_null($canonico) && strpos($clave, 'gtin') === 0 && Gtin::canonical($codigo) === $canonico) {
                    return ['nodo' => $nodo, 'por_codigo' => true];
                }

                if ($compacto !== '' && ($clave === 'mpn' || $clave === 'sku') && self::compactar($codigo) === $compacto) {
                    return ['nodo' => $nodo, 'por_codigo' => true];
                }
            }
        }

        if ($pagina_trae_el_codigo && count($con_campos) === 1) {
            return ['nodo' => $con_campos[0], 'por_codigo' => false];
        }

        return null;
    }

    /**
     * Un código sin espacios, guiones ni puntos y en minúsculas («32 853 63-S» → «3285363s»).
     *
     * @param  string $codigo
     * @return string
     */
    public static function compactar($codigo)
    {
        return mb_strtolower((string) preg_replace('/[\s\-.]+/u', '', (string) $codigo), 'UTF-8');
    }

    /**
     * El JSON de un bloque, o null si no se puede leer. Tolera los envoltorios que algunos sitios le ponen
     * (comentarios HTML, CDATA) y los saltos de línea crudos adentro de un string.
     *
     * @param  string $crudo
     * @return mixed|null
     */
    protected static function decodificar($crudo)
    {
        $texto = trim((string) $crudo);
        $texto = (string) preg_replace('#^(?:<!--|/\*\s*<!\[CDATA\[\s*\*/|<!\[CDATA\[)\s*#', '', $texto);
        $texto = (string) preg_replace('#\s*(?:-->|/\*\s*\]\]>\s*\*/|\]\]>)$#', '', $texto);

        if ($texto === '') {
            return null;
        }

        $datos = json_decode($texto, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Caracteres de control crudos (un salto de línea en una descripción) invalidan el JSON entero.
            $datos = json_decode((string) preg_replace('/[\x00-\x1F]+/', ' ', $texto), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
        }

        return is_array($datos) ? $datos : null;
    }

    /**
     * Junta los nodos de producto de un JSON (también adentro de `@graph`, listas, `mainEntity`, `hasVariant`…).
     *
     * @param  mixed $datos
     * @param  array $encontrados  (por referencia)
     * @param  int   $nivel
     * @return void
     */
    protected static function recorrer($datos, array &$encontrados, $nivel)
    {
        if (!is_array($datos) || $nivel > self::PROFUNDIDAD_MAXIMA) {
            return;
        }

        if (self::es_producto($datos)) {
            $encontrados[] = $datos;
        }

        foreach ($datos as $clave => $valor) {
            // Las ofertas se leen como parte de su producto (armar_nodo), no como nodos sueltos.
            if ($clave === 'offers') {
                continue;
            }

            if (is_array($valor)) {
                self::recorrer($valor, $encontrados, $nivel + 1);
            }
        }
    }

    /**
     * ¿Es un nodo de producto? Por su `@type`, o (sin `@type`) porque trae un gtin o un sku.
     *
     * @param  array $nodo
     * @return bool
     */
    protected static function es_producto(array $nodo)
    {
        if (isset($nodo['@type'])) {
            $tipos = is_array($nodo['@type']) ? $nodo['@type'] : [$nodo['@type']];

            foreach ($tipos as $tipo) {
                if (!is_string($tipo)) {
                    continue;
                }

                // «Product», «schema:Product», «http://schema.org/Product».
                $corto = strtolower((string) preg_replace('#^.*[/:]#', '', $tipo));

                if (in_array($corto, self::TIPOS_DE_PRODUCTO, true)) {
                    return true;
                }
            }

            return false;
        }

        foreach (self::CLAVES_DE_CODIGO as $clave) {
            if ($clave !== 'mpn' && isset($nodo[$clave]) && is_scalar($nodo[$clave]) && trim((string) $nodo[$clave]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Los códigos y los campos de un nodo de producto.
     *
     * @param  array  $nodo
     * @param  string $crudo  El bloque tal cual está en la página (de ahí sale la cita).
     * @return array  ['codigos' => array, 'campos' => array]
     */
    protected static function armar_nodo(array $nodo, $crudo)
    {
        $codigos = self::codigos_de($nodo);

        // Las ofertas directas del producto: a veces el gtin está ahí y no en el producto.
        if (isset($nodo['offers']) && is_array($nodo['offers'])) {
            $ofertas = isset($nodo['offers']['@type']) || !isset($nodo['offers'][0]) ? [$nodo['offers']] : $nodo['offers'];

            foreach ($ofertas as $oferta) {
                if (is_array($oferta)) {
                    $codigos = $codigos + self::codigos_de($oferta);
                }
            }
        }

        $campos = [];

        foreach (self::PROPIEDADES as $propiedad => $campo) {
            if (isset($campos[$campo]) || !array_key_exists($propiedad, $nodo)) {
                continue;
            }

            $dato = self::medida($nodo[$propiedad], $campo);

            if (is_null($dato)) {
                continue;
            }

            $dato['cita'] = self::cita($crudo, $propiedad, $nodo[$propiedad]);
            $campos[$campo] = $dato;
        }

        return ['codigos' => $codigos, 'campos' => $campos];
    }

    /**
     * Los códigos de un nodo (los que sean texto o número no vacío).
     *
     * @param  array $nodo
     * @return array  clave => string
     */
    protected static function codigos_de(array $nodo)
    {
        $codigos = [];

        foreach (self::CLAVES_DE_CODIGO as $clave) {
            if (isset($nodo[$clave]) && is_scalar($nodo[$clave]) && !is_bool($nodo[$clave])) {
                $valor = trim((string) $nodo[$clave]);

                if ($valor !== '') {
                    $codigos[$clave] = mb_substr($valor, 0, 100);
                }
            }
        }

        return $codigos;
    }

    /**
     * Lee UNA medida de schema.org y la lleva a kg / cm.
     *
     * @param  mixed  $valor  QuantitativeValue ({value, unitCode | unitText}), una lista de ellos (la primera que
     *                        se pueda leer) o un texto («2,7 kg»).
     * @param  string $campo
     * @return array|null  valor, unidad, valor_original, unidad_original, convertido, alcance.
     */
    protected static function medida($valor, $campo)
    {
        $tipo = DimensionValueValidator::CAMPOS[$campo];

        if (is_string($valor)) {
            return self::medida_de_texto($valor, $tipo);
        }

        if (!is_array($valor)) {
            return null;
        }

        // Una lista de QuantitativeValue: la primera que se pueda leer.
        if (isset($valor[0]) && !isset($valor['value'])) {
            foreach ($valor as $uno) {
                $dato = self::medida($uno, $campo);

                if (!is_null($dato)) {
                    return $dato;
                }
            }

            return null;
        }

        if (!isset($valor['value'])) {
            return null;
        }

        $unidad = null;

        if (isset($valor['unitCode']) && is_string($valor['unitCode'])) {
            $codigo = strtoupper(trim($valor['unitCode']));

            if (isset(self::CODIGOS_DE_UNIDAD[$codigo])) {
                $unidad = self::CODIGOS_DE_UNIDAD[$codigo];
            }
        }

        if (is_null($unidad) && isset($valor['unitText']) && is_string($valor['unitText'])) {
            $unidad = (new DimensionValueValidator())->reconocer_unidad($valor['unitText']);
        }

        // `value` como texto con la unidad adentro («2,7 kg») y sin unitCode.
        if (is_null($unidad)) {
            return is_string($valor['value']) ? self::medida_de_texto($valor['value'], $tipo) : null;
        }

        $numero = self::numero($valor['value']);

        if (is_null($numero) || $numero <= 0 || $unidad[0] !== $tipo) {
            return null;
        }

        return self::dato($numero, $unidad, $tipo);
    }

    /**
     * Una medida escrita como texto («2,7 kg», «38 cm»): el único par número + unidad del tipo del campo.
     *
     * @param  string $texto
     * @param  string $tipo
     * @return array|null
     */
    protected static function medida_de_texto($texto, $tipo)
    {
        $pares = [];

        foreach ((new DimensionValueValidator())->parsear_pares(mb_substr((string) $texto, 0, 100)) as $par) {
            // Un número ambiguo («1.200 g») o un rango no se toma: en un dato «estructurado» no debería haberlos.
            if ($par['tipo'] === $tipo && empty($par['ambiguo']) && empty($par['rango']) && empty($par['grupo'])) {
                $pares[] = $par;
            }
        }

        if (count($pares) !== 1 || $pares[0]['numero'] <= 0) {
            return null;
        }

        $unidad = (new DimensionValueValidator())->reconocer_unidad($pares[0]['etiqueta']);

        return is_null($unidad) ? null : self::dato($pares[0]['numero'], $unidad, $tipo);
    }

    /**
     * El número de `value`: un número de JSON, o un texto numérico con punto decimal («38.00»). Una coma sola se
     * toma como decimal («2,7»). Cualquier otra cosa no es un número.
     *
     * @param  mixed $valor
     * @return float|null
     */
    protected static function numero($valor)
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        if (!is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        if (preg_match('/^\d+(?:\.\d+)?$/', $valor)) {
            return (float) $valor;
        }

        if (preg_match('/^\d+,\d+$/', $valor)) {
            return (float) str_replace(',', '.', $valor);
        }

        return null;
    }

    /**
     * @param  float  $numero
     * @param  array  $unidad  [tipo, factor, etiqueta]
     * @param  string $tipo
     * @return array
     */
    protected static function dato($numero, array $unidad, $tipo)
    {
        $canonica = DimensionValueValidator::UNIDAD_CANONICA[$tipo];

        return [
            'valor'           => round($numero * $unidad[1], DimensionValueValidator::DECIMALES),
            'unidad'          => $canonica,
            'valor_original'  => $numero,
            'unidad_original' => $unidad[2],
            'convertido'      => $unidad[2] !== $canonica,
            'alcance'         => 'producto',
        ];
    }

    /**
     * El fragmento JSON de la propiedad tal cual está en la página («"weight":{"@type":"QuantitativeValue",…}»): el
     * primero cuyo valor es el del nodo. Si no se encuentra (anidado de otra forma), el valor re-codificado.
     *
     * @param  string $crudo
     * @param  string $propiedad
     * @param  mixed  $valor
     * @return string
     */
    protected static function cita($crudo, $propiedad, $valor)
    {
        $patron = '/"'.preg_quote($propiedad, '/').'"\s*:\s*(\{[^{}]*\}|\[[^\[\]{}]*\]|\[(?:\s*\{[^{}]*\}\s*,?)+\]|"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?)/s';

        if (preg_match_all($patron, (string) $crudo, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $c) {
                if (json_decode($c[1], true) == $valor) {
                    return self::recortar_cita($c[0]);
                }
            }
        }

        return self::recortar_cita('"'.$propiedad.'":'.json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  string $cita
     * @return string
     */
    protected static function recortar_cita($cita)
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $cita)), 0, self::MAXIMO_CITA);
    }
}
