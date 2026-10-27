<?php

namespace App\Services\DimensionSearch;

use App\Services\ProductFacts\Gtin;

/**
 * Lo que se le pasa a la IA de una página web, y la pregunta «¿esta página tiene el código?» (misión
 * medidas-masivas-catalogo, 27/10/2026). Portado del prototipo probado con 10 artículos de Masquito
 * (`recortar()` de prueba.php de la misión prueba-fichas-por-barras).
 *
 * Una página puede pesar mucho y casi todo es ruido (menús, cookies, productos relacionados). Para
 * acotar tokens, `recortar()` se queda con: el título, las metas útiles (og:title, description,
 * product:brand, product:weight), el JSON-LD de producto, y ventanas de texto alrededor del código, de
 * los encabezados de ficha técnica y de las palabras «peso / medidas / dimensiones / alto / ancho /
 * largo». Sin estado ni red.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionPageText
{
    /** Máximo de caracteres del extracto de texto de una página. */
    const MAXIMO_EXTRACTO = 6000;

    /** Máximo de caracteres de JSON-LD de una página (todos los bloques juntos). */
    const MAXIMO_JSON_LD = 8000;

    /** Tipos de código con los que se identifica un artículo en una página. */
    const CODIGO_DE_BARRAS = 'codigo_de_barras';
    const CODIGO_PROVEEDOR = 'codigo_proveedor';

    /**
     * ¿La página contiene el código? Barras: el GTIN o cualquiera de sus equivalentes (EAN-13, UPC-A,
     * EAN-8) sin dígitos pegados antes ni después. Proveedor: el texto literal, sin letras ni dígitos
     * pegados, sin importar mayúsculas y con cualquier espacio en blanco entre sus partes.
     *
     * @param  string $contenido  El HTML o el texto de la página.
     * @param  string $codigo     El código tal como está en el artículo (barras o proveedor).
     * @param  string $tipo       CODIGO_DE_BARRAS | CODIGO_PROVEEDOR.
     * @return bool
     */
    public static function contiene_codigo($contenido, $codigo, $tipo)
    {
        $contenido = html_entity_decode((string) $contenido, ENT_QUOTES, 'UTF-8');

        foreach (self::patrones_del_codigo($codigo, $tipo) as $patron) {
            if (preg_match($patron, $contenido)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El extracto de una página para la IA.
     *
     * @param  string $html
     * @param  array  $codigos  Lista de ['codigo' => string, 'tipo' => CODIGO_*] que se buscan en el texto
     *                          (las ventanas de texto se centran en ellos). Puede ir vacía.
     * @return string  Texto plano; '' si la página no tiene nada útil.
     */
    public static function recortar($html, array $codigos = [])
    {
        $html    = (string) $html;
        $partes  = [];

        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $partes[] = 'TÍTULO: '.trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }

        foreach (['og:title', 'description', 'og:description', 'product:brand', 'product:weight:value', 'product:weight:units'] as $nombre) {
            $valor = self::meta($html, $nombre);

            if ($valor !== '') {
                $partes[] = strtoupper($nombre).': '.mb_substr($valor, 0, 1500);
            }
        }

        // JSON-LD: puede tener el código y las unidades. Se conserva como DATO, jamás se ejecuta.
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $ld);

        $ld_total = 0;

        foreach ($ld[1] as $bloque) {
            if (!preg_match('/Product|gtin|sku/i', $bloque)) {
                continue;
            }

            $bloque = mb_substr(preg_replace('/\s+/u', ' ', trim($bloque)), 0, 6000);

            if ($ld_total + mb_strlen($bloque) > self::MAXIMO_JSON_LD) {
                break;
            }

            $ld_total += mb_strlen($bloque);
            $partes[] = 'JSON-LD: '.$bloque;
        }

        $texto = self::texto_plano($html);

        // Ventanas [desde, hasta] (en bytes: son offsets de preg) alrededor de lo que interesa.
        $ventanas = [];

        foreach ($codigos as $c) {
            foreach (self::patrones_del_codigo($c['codigo'], $c['tipo']) as $patron) {
                if (preg_match_all($patron, $texto, $encontrados, PREG_OFFSET_CAPTURE)) {
                    foreach (array_slice($encontrados[0], 0, 3) as $x) {
                        $ventanas[] = [$x[1] - 700, $x[1] + 700];
                    }
                }
            }
        }

        if (preg_match_all('/descripci[oó]n|caracter[ií]sticas|especificaciones|ficha t[eé]cnica|sobre este producto/iu', $texto, $encontrados, PREG_OFFSET_CAPTURE)) {
            foreach (array_slice($encontrados[0], 0, 4) as $x) {
                $ventanas[] = [$x[1] - 100, $x[1] + 1200];
            }
        }

        if (preg_match_all('/\bpeso\b|medidas|dimensi[oó]n|embalaje|\balto\b|\bancho\b|\blargo\b|profundidad|contenido neto|\bmarca\b/iu', $texto, $encontrados, PREG_OFFSET_CAPTURE)) {
            foreach (array_slice($encontrados[0], 0, 12) as $x) {
                $ventanas[] = [$x[1] - 250, $x[1] + 350];
            }
        }

        usort($ventanas, function ($a, $b) {
            return $a[0] - $b[0];
        });

        // Se unen las ventanas que se pisan.
        $unidas = [];

        foreach ($ventanas as $v) {
            $v[0] = max(0, $v[0]);
            $ultima = count($unidas) - 1;

            if ($ultima >= 0 && $v[0] <= $unidas[$ultima][1]) {
                $unidas[$ultima][1] = max($unidas[$ultima][1], $v[1]);
            } else {
                $unidas[] = $v;
            }
        }

        $extracto = '';

        foreach ($unidas as $v) {
            $extracto .= ' […] '.substr($texto, $v[0], $v[1] - $v[0]);

            if (strlen($extracto) > self::MAXIMO_EXTRACTO) {
                break;
            }
        }

        if ($extracto !== '') {
            // Una ventana puede cortar un carácter multibyte por la mitad: se limpia antes de recortar.
            $partes[] = 'TEXTO: '.mb_substr(mb_convert_encoding($extracto, 'UTF-8', 'UTF-8'), 0, self::MAXIMO_EXTRACTO);
        }

        return implode("\n", $partes);
    }

    /**
     * El texto visible de una página: sin scripts, estilos, menús ni pies, con las entidades decodificadas
     * y los espacios colapsados.
     *
     * @param  string $html
     * @return string
     */
    public static function texto_plano($html)
    {
        $sin_ruido = preg_replace('#<(script|style|noscript|svg|header|footer|nav)\b[^>]*>.*?</\1>#is', ' ', (string) $html);
        $texto     = html_entity_decode(strip_tags((string) $sin_ruido), ENT_QUOTES, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', $texto);
    }

    /**
     * Las expresiones regulares que reconocen un código en un texto.
     *
     * @param  string $codigo
     * @param  string $tipo
     * @return array  Lista de patrones preg.
     */
    protected static function patrones_del_codigo($codigo, $tipo)
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            return [];
        }

        if ($tipo === self::CODIGO_DE_BARRAS) {
            $canonico = Gtin::canonical($codigo);

            // Un código de barras que no es un GTIN válido no se busca por equivalentes.
            $codigos = is_null($canonico) ? [$codigo] : array_values(Gtin::equivalents($canonico));
            $patrones = [];

            foreach ($codigos as $c) {
                $patrones[] = '/(?<!\d)'.preg_quote($c, '/').'(?!\d)/';
            }

            return $patrones;
        }

        // Código de proveedor: literal, con cualquier espacio entre sus partes, sin letras/dígitos pegados.
        $literal = str_replace(' ', '\s+', preg_quote(preg_replace('/\s+/', ' ', $codigo), '/'));

        return ['/(?<![\p{L}\d])'.$literal.'(?![\p{L}\d])/iu'];
    }

    /**
     * El `content` de una meta (por `property` o `name`), decodificado; '' si no está.
     *
     * @param  string $html
     * @param  string $nombre
     * @return string
     */
    protected static function meta($html, $nombre)
    {
        $n = preg_quote($nombre, '#');

        if (preg_match('#<meta[^>]+(?:property|name)=["\']'.$n.'["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m)
            || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']'.$n.'["\']#i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        return '';
    }
}
