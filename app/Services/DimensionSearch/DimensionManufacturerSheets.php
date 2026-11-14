<?php

namespace App\Services\DimensionSearch;

use App\Models\Article;
use App\Models\DimensionSearchRun;
use App\Services\ProductFacts\Gtin;
use Illuminate\Support\Str;

/**
 * «Fichas del fabricante» (misión medidas-masquito-fichas, 14/11/2026): el PESO de un artículo Stihl, Husqvarna u
 * Oregon leído de la ficha pública de un distribuidor, con la URL ARMADA desde el código del artículo. Sin Serper y
 * sin IA: todo lo lee este código con expresiones regulares y la identidad se comprueba con el EAN / UPC o con el
 * número de parte que trae la ficha.
 *
 * Adaptadores, en este orden por marca (se corta en el primer dato válido):
 *
 *   - STIHL → maxparts.hu: `https://maxparts.hu/spd/HU10_<código compacto>/` (solo con 11 caracteres alfanuméricos).
 *     Identidad: el EAN de la ficha es el código de barras del artículo, o el compacto está en el `<title>`. Dato:
 *     «Nettó tömeg (kg)» (producto). El «Bruttó tömeg» va solo al diagnóstico.
 *   - STIHL → lawnbarn.com (si maxparts no dio): la URL sale del índice del sitemap (DimensionLawnBarnIndex).
 *     Identidad: «UPC:» de la ficha = código de barras. Dato: `data-product-weight` o «Weight: 0.60 LBS» → kg.
 *     Producto, con el aviso de que puede ser el peso con embalaje. Un peso de 0 se descarta.
 *   - HUSQVARNA → kalohaz.hu: `https://kalohaz.hu/spd/<número>/`, con el número de 9 dígitos que empieza con 5 (del
 *     código de barras o del de proveedor). Identidad: «Cikkszám <número>». Dato: «Nettó tömeg» en g o kg (producto).
 *   - OREGON → oregonproducts.com: `https://www.oregonproducts.com/en/x/p/<parte>` (el código de proveedor sin el
 *     prefijo «55.»). Identidad: «Part# <parte>» o la URL del producto terminada en `/p/<parte>`. Dato: «Weight
 *     (with Packaging)» → kg, alcance ENVÍO (el motor lo deja «a revisar», nunca se escribe solo).
 *
 * Rango válido: 0 < peso ≤ DimensionValueValidator::MAXIMO_PESO_KG, redondeado a 4 decimales.
 *
 * Las fichas se bajan con DimensionPageReader::leer() (la guarda anti-SSRF de siempre). Cada fuente probada deja una
 * entrada en el diagnóstico con `criterio` = `ficha_<fuente>` (SOLO en el diagnóstico: el `criterio_usado` del item
 * sigue siendo codigo_de_barras / codigo_proveedor), la url, si se encontró, la identidad, el valor, la cita literal
 * y, si no bajó, el motivo del lector.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionManufacturerSheets
{
    /** Criterios del diagnóstico, uno por fuente (nunca van a `criterio_usado`). */
    const CRITERIO_MAXPARTS = 'ficha_maxparts';
    const CRITERIO_LAWNBARN = 'ficha_lawnbarn';
    const CRITERIO_KALOHAZ  = 'ficha_kalohaz';
    const CRITERIO_OREGON   = 'ficha_oregon';

    /** Los criterios en el orden en que se prueban (el motor ordena el diagnóstico con esto). */
    const CRITERIOS = [self::CRITERIO_MAXPARTS, self::CRITERIO_LAWNBARN, self::CRITERIO_KALOHAZ, self::CRITERIO_OREGON];

    /** Marcas soportadas (nombre de la marca en mayúsculas y sin acentos). */
    const MARCA_STIHL     = 'STIHL';
    const MARCA_HUSQVARNA = 'HUSQVARNA';
    const MARCA_OREGON    = 'OREGON';
    const MARCAS          = [self::MARCA_STIHL, self::MARCA_HUSQVARNA, self::MARCA_OREGON];

    /** Qué fuentes se prueban por marca, en orden. */
    const FUENTES_POR_MARCA = [
        self::MARCA_STIHL     => [self::CRITERIO_MAXPARTS, self::CRITERIO_LAWNBARN],
        self::MARCA_HUSQVARNA => [self::CRITERIO_KALOHAZ],
        self::MARCA_OREGON    => [self::CRITERIO_OREGON],
    ];

    /** El dominio de cada fuente (para los textos). */
    const DOMINIOS = [
        self::CRITERIO_MAXPARTS => 'maxparts.hu',
        self::CRITERIO_LAWNBARN => 'lawnbarn.com',
        self::CRITERIO_KALOHAZ  => 'kalohaz.hu',
        self::CRITERIO_OREGON   => 'oregonproducts.com',
    ];

    /** Alcance del dato. */
    const ALCANCE_PRODUCTO = 'producto';
    const ALCANCE_ENVIO    = 'envio';

    /** Con qué se confirmó que la ficha es del artículo. */
    const IDENTIDAD_EAN    = 'ean';
    const IDENTIDAD_CODIGO = 'codigo';

    /** Unidad de la ficha => kg. */
    const A_KG = [
        'kg' => 1.0,
        'g'  => 0.001,
        'lb' => 0.45359237,
        'oz' => 0.028349523125,
    ];

    /** Largo máximo de una cita. */
    const MAXIMO_CITA = 300;

    /** Nota del peso de Lawn Barn (a veces publica el neto y a veces el bruto). */
    const AVISO_LAWNBARN = 'Puede ser el peso con embalaje.';

    /** @var \App\Services\DimensionSearch\DimensionPageReader */
    protected $lector;

    /** @var \App\Models\DimensionSearchRun|null La corrida (para el índice de Lawn Barn). */
    protected $run;

    /** @var \App\Services\DimensionSearch\DimensionLawnBarnIndex|null */
    protected $indice_lawnbarn = null;

    /**
     * @param \App\Services\DimensionSearch\DimensionPageReader $lector
     * @param \App\Models\DimensionSearchRun|null               $run
     * @param \App\Services\DimensionSearch\DimensionLawnBarnIndex|null $indice_lawnbarn  null = uno nuevo al primer uso.
     */
    public function __construct(DimensionPageReader $lector, DimensionSearchRun $run = null, DimensionLawnBarnIndex $indice_lawnbarn = null)
    {
        $this->lector          = $lector;
        $this->run             = $run;
        $this->indice_lawnbarn = $indice_lawnbarn;
    }

    /**
     * El nombre de una marca como se compara: sin espacios de más, en mayúsculas y sin acentos.
     *
     * @param  string|null $nombre
     * @return string
     */
    public static function marca_normalizada($nombre)
    {
        return strtoupper(trim((string) preg_replace('/\s+/u', ' ', Str::ascii((string) $nombre))));
    }

    /**
     * ¿Hay fichas para esa marca (ya normalizada)?
     *
     * @param  string $marca
     * @return bool
     */
    public static function marca_soportada($marca)
    {
        return isset(self::FUENTES_POR_MARCA[(string) $marca]);
    }

    /**
     * Los dominios de las fuentes que se prueban para una marca, en orden.
     *
     * @param  string $marca  Normalizada.
     * @return array
     */
    public static function dominios_de($marca)
    {
        $dominios = [];

        if (self::marca_soportada($marca)) {
            foreach (self::FUENTES_POR_MARCA[$marca] as $criterio) {
                $dominios[] = self::DOMINIOS[$criterio];
            }
        }

        return $dominios;
    }

    /**
     * Busca el peso del artículo en las fichas de su marca.
     *
     * @param  \App\Models\Article $articulo
     * @param  string              $marca  Normalizada (ver marca_normalizada()).
     * @return array  ['dato' => array|null, 'diagnostico' => array]. El dato: valor (kg), valor_original,
     *                unidad_original, cita, url, titulo, dominio, alcance, identidad, aviso, criterio.
     */
    public function buscar(Article $articulo, $marca)
    {
        $diagnostico = [];

        if (!self::marca_soportada($marca)) {
            return ['dato' => null, 'diagnostico' => $diagnostico];
        }

        foreach (self::FUENTES_POR_MARCA[$marca] as $criterio) {
            if ($criterio === self::CRITERIO_MAXPARTS) {
                $entrada = $this->maxparts($articulo);
            } elseif ($criterio === self::CRITERIO_LAWNBARN) {
                $entrada = $this->lawnbarn($articulo);
            } elseif ($criterio === self::CRITERIO_KALOHAZ) {
                $entrada = $this->kalohaz($articulo);
            } else {
                $entrada = $this->oregon($articulo);
            }

            $dato = isset($entrada['_dato']) ? $entrada['_dato'] : null;
            unset($entrada['_dato']);

            $diagnostico[] = $entrada;

            if (!is_null($dato)) {
                return ['dato' => $dato, 'diagnostico' => $diagnostico];
            }
        }

        return ['dato' => null, 'diagnostico' => $diagnostico];
    }

    /* ----------------------------------------------------------------------------------------
     * Adaptadores
     * -------------------------------------------------------------------------------------- */

    /**
     * Stihl en maxparts.hu.
     *
     * @param  \App\Models\Article $articulo
     * @return array  La entrada del diagnóstico (con `_dato` si hubo un peso válido).
     */
    protected function maxparts(Article $articulo)
    {
        $compacto = DimensionLawnBarnIndex::compacto($articulo->provider_code);

        if (strlen($compacto) !== DimensionLawnBarnIndex::LARGO_CODIGO_STIHL) {
            return $this->no_usada(self::CRITERIO_MAXPARTS, 'El código de proveedor no tiene la forma de un código de Stihl (11 letras o números): no se puede armar la dirección de la ficha.');
        }

        $url  = 'https://maxparts.hu/spd/HU10_'.$compacto.'/';
        $html = $this->lector->leer($url);

        if (is_null($html) || $html === '') {
            return $this->no_bajo(self::CRITERIO_MAXPARTS, $url);
        }

        $texto  = self::texto($html);
        $titulo = self::titulo($html);

        // Identidad: el EAN de la ficha es el código de barras del artículo; si no, el código en el título.
        $identidad = null;

        if (preg_match_all('/\bEAN\b\s*:?\s*(\d{8,14})(?!\d)/u', $texto, $eans)) {
            foreach ($eans[1] as $ean) {
                if (self::mismo_codigo_de_barras($ean, $articulo->bar_code)) {
                    $identidad = self::IDENTIDAD_EAN;
                    break;
                }
            }
        }

        if (is_null($identidad) && strpos(strtoupper($titulo), $compacto) !== false) {
            $identidad = self::IDENTIDAD_CODIGO;
        }

        $bruto = null;

        if (preg_match('/Brutt[oó]\s+t[oö]meg\s*\(\s*kg\s*\)\s*:?\s*(\d+(?:[.,]\d+)?)/iu', $texto, $m)) {
            $bruto = $this->a_kg($m[1], 'kg');
        }

        $lectura = null;

        if (preg_match('/Nett[oó]\s+t[oö]meg\s*\(\s*kg\s*\)\s*:?\s*(\d+(?:[.,]\d+)?)/iu', $texto, $m)) {
            $lectura = ['numero' => $m[1], 'unidad' => 'kg', 'cita' => $m[0]];
        }

        $entrada = $this->entrada(self::CRITERIO_MAXPARTS, $url, $titulo, $identidad, $lectura, self::ALCANCE_PRODUCTO, null);

        if (!is_null($bruto)) {
            $entrada['bruto_kg'] = $bruto;
        }

        return $entrada;
    }

    /**
     * Stihl en lawnbarn.com (por el índice del sitemap).
     *
     * @param  \App\Models\Article $articulo
     * @return array
     */
    protected function lawnbarn(Article $articulo)
    {
        if (is_null(Gtin::canonical(trim((string) $articulo->bar_code)))) {
            return $this->no_usada(self::CRITERIO_LAWNBARN, 'El artículo no tiene un código de barras válido: en Lawn Barn la ficha se confirma con el UPC.');
        }

        $compacto = DimensionLawnBarnIndex::compacto($articulo->provider_code);

        if (strlen($compacto) !== DimensionLawnBarnIndex::LARGO_CODIGO_STIHL) {
            return $this->no_usada(self::CRITERIO_LAWNBARN, 'El código de proveedor no tiene la forma de un código de Stihl (11 letras o números): no se puede buscar en el índice de Lawn Barn.');
        }

        if (is_null($this->run)) {
            return $this->no_usada(self::CRITERIO_LAWNBARN, 'El índice de Lawn Barn se arma por corrida y no hay corrida.');
        }

        if (is_null($this->indice_lawnbarn)) {
            $this->indice_lawnbarn = new DimensionLawnBarnIndex();
        }

        $indice = $this->indice_lawnbarn->cargar($this->run);

        if (!$indice['ok']) {
            return $this->no_usada(self::CRITERIO_LAWNBARN, 'El índice de Lawn Barn no se pudo armar en esta corrida'.(empty($indice['error']) ? '.' : ': '.rtrim((string) $indice['error'], '. ').'.'));
        }

        if (!isset($indice['urls'][$compacto])) {
            return $this->no_usada(self::CRITERIO_LAWNBARN, 'El código no está en el índice de Lawn Barn'.(!empty($indice['incompleto']) ? ' (el índice quedó incompleto)' : '').'.');
        }

        $url  = (string) $indice['urls'][$compacto];
        $html = $this->lector->leer($url);

        if (is_null($html) || $html === '') {
            return $this->no_bajo(self::CRITERIO_LAWNBARN, $url);
        }

        $texto  = self::texto($html);
        $titulo = self::titulo($html);

        $identidad = null;

        if (preg_match_all('/\bUPC\b\s*:?\s*(\d{8,14})(?!\d)/iu', $texto, $upcs)) {
            foreach ($upcs[1] as $upc) {
                if (self::mismo_codigo_de_barras($upc, $articulo->bar_code)) {
                    $identidad = self::IDENTIDAD_EAN;
                    break;
                }
            }
        }

        $lectura = null;

        // El atributo de BigCommerce es el dato exacto; la etiqueta «Weight:» del texto es la cita.
        if (preg_match('/data-product-weight[^>]*>\s*(\d+(?:[.,]\d+)?)\s*(LBS|LB|KGS|KG|OZ|G)\b/i', $html, $m)) {
            $cita = preg_match('/Weight\s*:\s*'.preg_quote($m[1], '/').'\s*'.preg_quote($m[2], '/').'\b/i', $texto, $c) ? $c[0] : 'Weight: '.$m[1].' '.$m[2];
            $lectura = ['numero' => $m[1], 'unidad' => $m[2], 'cita' => $cita];
        } elseif (preg_match('/Weight\s*:\s*(\d+(?:[.,]\d+)?)\s*(LBS|LB|KGS|KG|OZ|G)\b/i', $texto, $m)) {
            $lectura = ['numero' => $m[1], 'unidad' => $m[2], 'cita' => $m[0]];
        }

        return $this->entrada(self::CRITERIO_LAWNBARN, $url, $titulo, $identidad, $lectura, self::ALCANCE_PRODUCTO, self::AVISO_LAWNBARN);
    }

    /**
     * Husqvarna en kalohaz.hu.
     *
     * @param  \App\Models\Article $articulo
     * @return array
     */
    protected function kalohaz(Article $articulo)
    {
        $numero = null;

        foreach ([$articulo->bar_code, $articulo->provider_code] as $codigo) {
            $compacto = DimensionLawnBarnIndex::compacto($codigo);

            if (preg_match('/^5\d{8}$/', $compacto)) {
                $numero = $compacto;
                break;
            }
        }

        if (is_null($numero)) {
            return $this->no_usada(self::CRITERIO_KALOHAZ, 'El artículo no tiene un número de Husqvarna de 9 dígitos que empiece con 5 (ni en el código de barras ni en el de proveedor): no se puede armar la dirección de la ficha.');
        }

        $url  = 'https://kalohaz.hu/spd/'.$numero.'/';
        $html = $this->lector->leer($url);

        if (is_null($html) || $html === '') {
            return $this->no_bajo(self::CRITERIO_KALOHAZ, $url);
        }

        $texto  = self::texto($html);
        $titulo = self::titulo($html);

        $identidad = preg_match('/Cikksz[aá]m\s*:?\s*'.$numero.'(?!\d)/iu', $texto) ? self::IDENTIDAD_CODIGO : null;

        $lectura = null;

        // Primero la fila de la tabla («Nettó tömeg 47 g», con mayúscula); si no está, la frase del texto.
        if (preg_match('/Nett[oó]\s+t[oö]meg\s*:?\s*(\d+(?:[.,]\d+)?)\s*(kg|g)\b/u', $texto, $m)
            || preg_match('/Nett[oó]\s+t[oö]meg\s*:?\s*(\d+(?:[.,]\d+)?)\s*(kg|g)\b/iu', $texto, $m)) {
            $lectura = ['numero' => $m[1], 'unidad' => $m[2], 'cita' => $m[0]];
        }

        return $this->entrada(self::CRITERIO_KALOHAZ, $url, $titulo, $identidad, $lectura, self::ALCANCE_PRODUCTO, null);
    }

    /**
     * Oregon en oregonproducts.com (peso con embalaje).
     *
     * @param  \App\Models\Article $articulo
     * @return array
     */
    protected function oregon(Article $articulo)
    {
        $parte = self::parte_de_oregon($articulo->provider_code);

        if (is_null($parte)) {
            return $this->no_usada(self::CRITERIO_OREGON, 'El código de proveedor no tiene la forma de un número de parte de Oregon: no se puede armar la dirección de la ficha.');
        }

        $url  = 'https://www.oregonproducts.com/en/x/p/'.rawurlencode($parte);
        $html = $this->lector->leer($url);

        if (is_null($html) || $html === '') {
            return $this->no_bajo(self::CRITERIO_OREGON, $url);
        }

        $texto  = self::texto($html);
        $titulo = self::titulo($html);

        $patron_parte = preg_quote($parte, '/');
        $identidad    = null;

        // «Part# 65-006» en la página, o la dirección del producto (canónica / JSON-LD) terminada en /p/<parte>.
        if (preg_match('/Part\s*#\s*:?\s*'.$patron_parte.'(?![A-Za-z0-9\-])/iu', $texto)
            || preg_match('#/p/'.preg_quote($parte, '#').'["\'?\#]#i', $html)) {
            $identidad = self::IDENTIDAD_CODIGO;
        }

        $lectura = null;

        if (preg_match('/Weight\s*\(\s*with\s+Packaging\s*\)\s*:?\s*(\d+(?:[.,]\d+)?)\s*(Lbs|Lb|Kgs|Kg|Oz|G)\b/iu', $texto, $m)) {
            $lectura = ['numero' => $m[1], 'unidad' => $m[2], 'cita' => $m[0]];
        }

        return $this->entrada(self::CRITERIO_OREGON, $url, $titulo, $identidad, $lectura, self::ALCANCE_ENVIO, null);
    }

    /* ----------------------------------------------------------------------------------------
     * Ayudas
     * -------------------------------------------------------------------------------------- */

    /**
     * La parte de Oregon a partir del código de proveedor: sin el prefijo «55.» que usa Masquito («55.75-299» →
     * «75-299»). Null si no tiene forma de número de parte.
     *
     * @param  string|null $codigo
     * @return string|null
     */
    public static function parte_de_oregon($codigo)
    {
        $parte = trim((string) $codigo);
        $parte = (string) preg_replace('/^55\./', '', $parte);
        $parte = strtoupper(trim($parte));

        if (!preg_match('/^[A-Z0-9][A-Z0-9\-]{1,30}$/', $parte) || !preg_match('/\d/', $parte)) {
            return null;
        }

        return $parte;
    }

    /**
     * ¿Dos códigos de barras son el mismo GTIN (con sus equivalentes EAN/UPC)? Si alguno no es un GTIN válido, se
     * comparan los dígitos sin ceros a la izquierda.
     *
     * @param  string      $de_la_ficha
     * @param  string|null $del_articulo
     * @return bool
     */
    public static function mismo_codigo_de_barras($de_la_ficha, $del_articulo)
    {
        $ficha    = trim((string) $de_la_ficha);
        $articulo = trim((string) $del_articulo);

        if ($ficha === '' || $articulo === '') {
            return false;
        }

        $a = Gtin::canonical($ficha);
        $b = Gtin::canonical($articulo);

        if (!is_null($a) && !is_null($b)) {
            return $a === $b;
        }

        $a = ltrim((string) preg_replace('/\D/', '', $ficha), '0');
        $b = ltrim((string) preg_replace('/\D/', '', $articulo), '0');

        return $a !== '' && $a === $b;
    }

    /**
     * El texto de una ficha para leerla con expresiones regulares: sin scripts ni estilos, cada etiqueta cambiada
     * por un espacio, las entidades decodificadas (el `&nbsp;` también es un espacio) y los espacios colapsados.
     *
     * 🔴 No es DimensionPageText::texto_plano(): ese saca los `<header>`, y la ficha de Oregon pone el «Part#» en un
     * `<header>` del producto.
     *
     * @param  string $html
     * @return string
     */
    public static function texto($html)
    {
        $sin_codigo = preg_replace('#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', (string) $html);
        $texto      = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', (string) $sin_codigo), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto      = str_replace("\xC2\xA0", ' ', $texto);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * El `<title>` de la página, limpio.
     *
     * @param  string $html
     * @return string
     */
    public static function titulo($html)
    {
        if (!preg_match('#<title[^>]*>(.*?)</title>#is', (string) $html, $m)) {
            return '';
        }

        $titulo = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $titulo))), 0, 200);
    }

    /**
     * Un número de la ficha («0.341», «0,62») en la unidad dada, llevado a kg y redondeado; null si no se entiende.
     *
     * @param  string $numero
     * @param  string $unidad
     * @return float|null
     */
    protected function a_kg($numero, $unidad)
    {
        $clave = self::unidad($unidad);

        if (is_null($clave)) {
            return null;
        }

        $valor = (float) str_replace(',', '.', (string) $numero);

        return round($valor * self::A_KG[$clave], DimensionApplier::DECIMALES);
    }

    /**
     * La unidad de la ficha en su forma corta (kg, g, lb, oz); null si no es de peso.
     *
     * @param  string $unidad
     * @return string|null
     */
    protected static function unidad($unidad)
    {
        $unidad = strtolower(trim((string) $unidad));

        if (in_array($unidad, ['kg', 'kgs'], true)) {
            return 'kg';
        }

        if (in_array($unidad, ['lb', 'lbs'], true)) {
            return 'lb';
        }

        if ($unidad === 'oz') {
            return 'oz';
        }

        if ($unidad === 'g') {
            return 'g';
        }

        return null;
    }

    /**
     * La entrada del diagnóstico de una ficha que se bajó, con el `_dato` si la identidad se confirmó y el peso es
     * válido.
     *
     * @param  string      $criterio
     * @param  string      $url
     * @param  string      $titulo
     * @param  string|null $identidad  ean | codigo | null.
     * @param  array|null  $lectura    numero, unidad, cita (null si la ficha no trae el peso).
     * @param  string      $alcance
     * @param  string|null $aviso
     * @return array
     */
    protected function entrada($criterio, $url, $titulo, $identidad, $lectura, $alcance, $aviso)
    {
        $dominio = self::DOMINIOS[$criterio];

        $valor = is_null($lectura) ? null : $this->a_kg($lectura['numero'], $lectura['unidad']);
        $cita  = is_null($lectura) ? null : mb_substr(trim($lectura['cita']), 0, self::MAXIMO_CITA);

        $entrada = [
            'criterio'   => $criterio,
            'usado'      => true,
            // Una ficha no es una consulta al buscador: cero (la SPA cuenta una por plan si no viene).
            'busquedas'  => 0,
            // La ficha leída (la SPA lo muestra como «1 resultado»).
            'resultados' => 1,
            'url'        => $url,
            'encontrada' => true,
            'identidad'  => $identidad,
            'valor_kg'   => $valor,
            'cita'       => $cita,
        ];

        $pagina = ['url' => $url, 'titulo' => $titulo, 'dominio' => $dominio, 'leida' => true, 'motivo' => null];

        if (is_null($identidad)) {
            $entrada['resultado'] = 'ficha_sin_el_codigo';
            $entrada['resumen']   = 'La ficha de '.$dominio.' no trae el código del artículo: no se usó.';
            $pagina['motivo']     = 'No traía el código del artículo';
            $entrada['paginas']   = [$pagina];

            return $entrada;
        }

        if (is_null($valor) || $valor <= 0 || $valor > DimensionValueValidator::MAXIMO_PESO_KG) {
            $entrada['resultado'] = 'sin_medidas';
            $entrada['resumen']   = is_null($lectura)
                ? 'La ficha de '.$dominio.' es del artículo pero no publica el peso.'
                : 'La ficha de '.$dominio.' es del artículo pero el peso que publica no sirve ('.$cita.').';
            $entrada['valor_kg']  = null;
            $entrada['paginas']   = [$pagina];

            return $entrada;
        }

        $entrada['resultado'] = 'datos';
        $entrada['resumen']   = 'Peso en la ficha de '.$dominio.': «'.$cita.'».';
        $entrada['paginas']   = [$pagina];

        $unidad_original = self::unidad($lectura['unidad']);

        $entrada['_dato'] = [
            'valor'           => $valor,
            'valor_original'  => (float) str_replace(',', '.', (string) $lectura['numero']),
            'unidad_original' => $unidad_original,
            'cita'            => $cita,
            'url'             => $url,
            'titulo'          => $titulo,
            'dominio'         => $dominio,
            'alcance'         => $alcance,
            'identidad'       => $identidad,
            'aviso'           => $aviso,
            'criterio'        => $criterio,
        ];

        return $entrada;
    }

    /**
     * Una fuente que no se pudo probar (no hay con qué armar la URL, el índice no la tiene…).
     *
     * @param  string $criterio
     * @param  string $motivo
     * @return array
     */
    protected function no_usada($criterio, $motivo)
    {
        return ['criterio' => $criterio, 'usado' => false, 'busquedas' => 0, 'url' => null, 'encontrada' => false, 'identidad' => null, 'valor_kg' => null, 'cita' => null, 'motivo' => $motivo];
    }

    /**
     * Una ficha que no bajó (404, bloqueo, sin respuesta): el motivo del lector.
     *
     * @param  string $criterio
     * @param  string $url
     * @return array
     */
    protected function no_bajo($criterio, $url)
    {
        $fallo   = $this->lector->motivo_del_fallo() ?: DimensionPageReader::FALLO_OTRO;
        $dominio = self::DOMINIOS[$criterio];

        if ($fallo === DimensionPageReader::FALLO_NO_EXISTE) {
            $motivo = 'No existe';
        } elseif ($fallo === DimensionPageReader::FALLO_BLOQUEADA) {
            $motivo = 'El sitio bloquea la descarga ('.(int) $this->lector->ultimo_estado().')';
        } elseif ($fallo === DimensionPageReader::FALLO_SIN_RESPUESTA) {
            $motivo = 'El sitio no respondió a tiempo.';
        } else {
            $motivo = 'No se pudo descargar la página.';
        }

        return [
            'criterio'   => $criterio,
            'usado'      => true,
            'busquedas'  => 0,
            'resultados' => 0,
            'url'        => $url,
            'encontrada' => false,
            'identidad'  => null,
            'valor_kg'   => null,
            'cita'       => null,
            'fallo'      => $fallo,
            'resultado'  => 'sin_ficha',
            'resumen'    => $fallo === DimensionPageReader::FALLO_NO_EXISTE ? 'No hay ficha de este artículo en '.$dominio.'.' : 'La ficha de '.$dominio.' no se pudo leer.',
            'paginas'    => [['url' => $url, 'titulo' => '', 'dominio' => $dominio, 'leida' => false, 'motivo' => $motivo, 'fallo' => $fallo]],
        ];
    }
}
