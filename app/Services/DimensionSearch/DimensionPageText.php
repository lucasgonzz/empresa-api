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

    /** Máximo de caracteres del texto plano de un respaldo (scrape) que se procesa. */
    const MAXIMO_TEXTO_DE_RESPALDO = 400000;

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
        $contenido = html_entity_decode(self::sin_ruido_de_codigo((string) $contenido), ENT_QUOTES, 'UTF-8');

        foreach (self::patrones_del_codigo($codigo, $tipo) as $patron) {
            if (preg_match($patron, $contenido)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El HTML sin lo que no es contenido de la página: comentarios, scripts y estilos. Un script de
     * analítica o un comentario con el código de otro producto no prueba que la página sea de este. Se
     * conserva el JSON-LD (`application/ld+json`), que sí describe el producto.
     *
     * @param  string $html
     * @return string
     */
    public static function sin_ruido_de_codigo($html)
    {
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);
        $html = (string) preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);

        return (string) preg_replace_callback('#<script\b([^>]*)>.*?</script>#is', function ($m) {
            return stripos($m[1], 'ld+json') !== false ? $m[0] : ' ';
        }, $html);
    }

    /**
     * El HTML en UTF-8. Una página vieja viene en ISO-8859-1 / Windows-1252: con `/u` un solo byte inválido
     * hace que preg_* devuelva null y el extracto quede vacío. Se decide el juego de caracteres por la
     * cabecera `Content-Type`, después por la meta (`<meta charset>` o `http-equiv`), y si lo declarado no
     * coincide con los bytes (o no hay nada declarado) pero no es UTF-8 válido, se asume Windows-1252.
     *
     * @param  string      $html
     * @param  string|null $content_type  La cabecera `Content-Type` de la respuesta, si se conoce.
     * @return string  UTF-8 válido.
     */
    public static function a_utf8($html, $content_type = null)
    {
        $html = (string) $html;

        $declarado = '';

        if (preg_match('/charset\s*=\s*["\']?([A-Za-z0-9_\-:.]+)/i', (string) $content_type, $m)) {
            $declarado = $m[1];
        } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9_\-:.]+)/i', substr($html, 0, 4096), $m)) {
            $declarado = $m[1];
        }

        $declarado = strtoupper($declarado);
        $es_utf8   = $declarado === '' || $declarado === 'UTF-8' || $declarado === 'UTF8';

        if ($es_utf8 && mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        // Declaró otro juego (o declaró UTF-8 y los bytes no lo son): se convierte desde ese, o desde Windows-1252.
        $origen = $es_utf8 || $declarado === 'ISO-8859-1' || $declarado === 'LATIN1' || $declarado === 'LATIN-1' ? 'Windows-1252' : $declarado;

        try {
            $convertido = mb_convert_encoding($html, 'UTF-8', $origen);
        } catch (\Throwable $e) {
            // Un juego de caracteres que mbstring no conoce.
            $convertido = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }

        return mb_check_encoding((string) $convertido, 'UTF-8') ? (string) $convertido : (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }

    /**
     * El extracto de una página para la IA.
     *
     * @param  string $html
     * @param  array  $codigos  Lista de ['codigo' => string, 'tipo' => CODIGO_*] que se buscan en el texto
     *                          (las ventanas de texto se centran en ellos). Puede ir vacía.
     * @param  bool   $es_texto true = `$html` NO es HTML sino texto plano (lo que devuelve el respaldo por el
     *                          scrape de Serper, también de un PDF). No se "limpian" tags y no hay metas ni
     *                          JSON-LD: solo se acomodan los espacios y se recorta igual que el HTML
     *                          (ventanas alrededor del código y de las palabras de ficha técnica).
     *                          (misión medidas-sin-datos, 28/10/2026)
     * @param  string $titulo   Solo con `$es_texto`: el título que informó el scrape (metadata), si lo hay.
     * @return string  Texto plano; '' si la página no tiene nada útil.
     */
    public static function recortar($html, array $codigos = [], $es_texto = false, $titulo = '')
    {
        // Siempre UTF-8 válido: con bytes inválidos las regex /u devuelven null y el extracto sale vacío.
        $html   = self::a_utf8((string) $html);
        $partes = [];

        if ($es_texto) {
            // Texto plano: el título (si lo hay) va como siempre y el cuerpo se usa tal cual, sin tocar tags.
            $titulo = trim((string) preg_replace('/\s+/u', ' ', (string) $titulo));

            if ($titulo !== '') {
                $partes[] = 'TÍTULO: '.mb_substr($titulo, 0, 300);
            }

            $texto = self::texto_de_respaldo($html);
        } else {
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
        }

        // Ventanas [desde, hasta] (en bytes: son offsets de preg). Las del CÓDIGO van primero: el tope del
        // extracto no puede dejar afuera justo la ventana que prueba que la página es del artículo.
        $ventanas_de_codigo = [];

        foreach ($codigos as $c) {
            foreach (self::patrones_del_codigo($c['codigo'], $c['tipo']) as $patron) {
                if (preg_match_all($patron, $texto, $encontrados, PREG_OFFSET_CAPTURE)) {
                    foreach (array_slice($encontrados[0], 0, 3) as $x) {
                        $ventanas_de_codigo[] = [$x[1] - 700, $x[1] + 700];
                    }
                }
            }
        }

        $ventanas = [];

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

        $extracto = '';

        // Se unen TODAS las ventanas (las que se pisan, una sola vez) y salen primero las que tocan un código.
        $con_codigo = [];
        $sin_codigo = [];

        foreach (self::unir_ventanas(array_merge($ventanas_de_codigo, $ventanas)) as $v) {
            $toca_un_codigo = false;

            foreach ($ventanas_de_codigo as $vc) {
                if ($vc[0] <= $v[1] && $vc[1] >= $v[0]) {
                    $toca_un_codigo = true;
                    break;
                }
            }

            if ($toca_un_codigo) {
                $con_codigo[] = $v;
            } else {
                $sin_codigo[] = $v;
            }
        }

        foreach (array_merge($con_codigo, $sin_codigo) as $v) {
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
     * Ordena las ventanas y une las que se pisan.
     *
     * @param  array $ventanas  Lista de [desde, hasta].
     * @return array
     */
    protected static function unir_ventanas(array $ventanas)
    {
        usort($ventanas, function ($a, $b) {
            return $a[0] - $b[0];
        });

        $unidas = [];

        foreach ($ventanas as $v) {
            $v[0]   = max(0, $v[0]);
            $ultima = count($unidas) - 1;

            if ($ultima >= 0 && $v[0] <= $unidas[$ultima][1]) {
                $unidas[$ultima][1] = max($unidas[$ultima][1], $v[1]);
            } else {
                $unidas[] = $v;
            }
        }

        return $unidas;
    }

    /**
     * El texto que devolvió el respaldo (scrape de Serper, también de un PDF) listo para recortar: sin
     * caracteres de control, con los espacios y saltos de línea colapsados, y acotado a un tamaño razonable
     * (un PDF largo puede traer cientos de miles de caracteres y las regex de las ventanas no lo necesitan
     * entero). NO toca `<` ni `>`: es texto, no HTML.
     *
     * @param  string $texto
     * @return string
     */
    public static function texto_de_respaldo($texto)
    {
        $texto = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', (string) $texto);
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);

        return mb_substr($texto, 0, self::MAXIMO_TEXTO_DE_RESPALDO);
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
        // Cada etiqueta se cambia por un espacio (strip_tags las borra y pega «820 g</td><td>Ancho» en
        // «820 gAncho», que el validador de unidades ya no reconoce).
        $texto     = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', (string) $sin_ruido), ENT_QUOTES, 'UTF-8');

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
