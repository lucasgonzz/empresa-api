<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Support\Str;

/**
 * Nombres DICTADOS o mal escritos que tienen que encontrar el nombre cargado (misión
 * asistente-mejoras-4-dias, 16/11/2026, rama A, P8 parte cliente y caja del cobro).
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026, por audio). El dueño dicta y el transcriptor escribe lo que
 * oye: «Justo Rubén» era RUBEN EDUARDO GIUSTO, «Nespeka Julio» era NESPECA JULIO HUMBERTO, «Kilka»
 * era KILLKA, «Vrobank First» era la caja BRUBANK FER. El LIKE de la base no encuentra ninguno, y la
 * venta se cortaba con «No encontré ningún cliente que se llame así».
 *
 * Esto compara palabra por palabra después de llevar las dos puntas a una forma FONÉTICA del
 * castellano rioplatense: sin tildes, la v es b, la z y la c suave son s, la c dura y la qu son k, la
 * g suave es j, la ll es y, la h no suena y las letras dobles cuentan una vez. Dos palabras se
 * parecen si quedan iguales o casi (similar_text ≥ UMBRAL), y un nombre se parece si TODAS las
 * palabras dictadas (las de 3 letras o más) tienen una parecida en el nombre cargado, en cualquier
 * orden: «Justo Rubén» encuentra «RUBEN EDUARDO GIUSTO».
 *
 * 🔴 NUNCA ELIGE. Lo que devuelve son CANDIDATOS: quien lo usa para cobrarle a alguien los pregunta
 * («¿es RUBEN EDUARDO GIUSTO?»), aunque haya uno solo. Un nombre parecido es una sospecha, no una
 * identificación.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class NombreParecidoIaHelper
{
    /**
     * Cuánto se tienen que parecer dos palabras (similar_text en porcentaje, sobre la forma fonética).
     * Medido con los casos reales: giusto/justo 89, nespeka/nespeca 100, vrobank/brubank 86;
     * martillo/pinza 15. Por debajo de 80 entran pares de palabras distintas de 5 letras.
     */
    const UMBRAL = 80;

    /** Palabras que no identifican a nadie y no se exigen («de», «la», «sa»). */
    const PALABRAS_VACIAS = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'sa', 'srl', 'sas', 'hnos'];

    /**
     * La forma fonética de un texto: palabras separadas por un espacio.
     *
     * @param  string  $texto
     * @return string
     */
    public static function fonetico($texto)
    {
        $texto = Str::lower(Str::ascii((string) $texto));
        $texto = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $texto);

        $palabras = preg_split('/\s+/', trim($texto), -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($palabras)) {
            return '';
        }

        $foneticas = [];

        foreach ($palabras as $palabra) {
            $foneticas[] = self::palabra_fonetica($palabra);
        }

        return implode(' ', $foneticas);
    }

    /**
     * Una palabra en su forma fonética (ver el docblock de la clase).
     *
     * @param  string  $palabra
     * @return string
     */
    public static function palabra_fonetica($palabra)
    {
        $p = (string) $palabra;

        if (ctype_digit($p)) {
            return $p;
        }

        $p = str_replace(['ll', 'qu', 'ch'], ['y', 'k', 'x'], $p);
        $p = (string) preg_replace('/g([ei])/', 'j$1', $p);
        $p = (string) preg_replace('/c([ei])/', 's$1', $p);
        $p = strtr($p, ['v' => 'b', 'z' => 's', 'c' => 'k', 'w' => 'u']);
        $p = str_replace('h', '', $p);

        // «gui» y «gue» suenan «gi» y «ge» duras: la u muda se va.
        $p = (string) preg_replace('/gu([ei])/', 'g$1', $p);

        // Las letras dobles cuentan una vez («Killka» y «Kilka»).
        $p = (string) preg_replace('/(.)\1+/', '$1', $p);

        return $p;
    }

    /**
     * true si dos palabras (ya fonéticas) se parecen.
     *
     * @param  string  $a
     * @param  string  $b
     * @return bool
     */
    public static function palabras_parecidas($a, $b)
    {
        $a = (string) $a;
        $b = (string) $b;

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        // Un número tiene que ser el mismo número.
        if (ctype_digit($a) || ctype_digit($b)) {
            return false;
        }

        // Las palabras cortas no admiten diferencias: «sol» no es «sal».
        if (mb_strlen($a) < 4 || mb_strlen($b) < 4) {
            return false;
        }

        $porcentaje = 0.0;
        similar_text($a, $b, $porcentaje);

        return $porcentaje >= self::UMBRAL;
    }

    /**
     * Las palabras de un texto que se exigen al comparar: fonéticas, de 3 letras o más (o números),
     * sin las vacías.
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    public static function palabras_que_cuentan($texto)
    {
        $palabras = [];

        foreach (explode(' ', self::fonetico($texto)) as $palabra) {

            if ($palabra === '' || in_array($palabra, self::PALABRAS_VACIAS, true)) {
                continue;
            }

            if (mb_strlen($palabra) < 3 && !ctype_digit($palabra)) {
                continue;
            }

            $palabras[] = $palabra;
        }

        return array_values(array_unique($palabras));
    }

    /**
     * true si el nombre cargado se parece al dictado: cada palabra que cuenta del dictado tiene una
     * palabra parecida en el nombre, en cualquier orden.
     *
     * @param  string  $dictado
     * @param  string  $nombre
     * @return bool
     */
    public static function se_parece($dictado, $nombre)
    {
        $buscadas = self::palabras_que_cuentan($dictado);

        if (!count($buscadas)) {
            return false;
        }

        $del_nombre = explode(' ', self::fonetico($nombre));

        foreach ($buscadas as $buscada) {

            $esta = false;

            foreach ($del_nombre as $palabra) {

                if (self::palabras_parecidas($buscada, $palabra)) {
                    $esta = true;
                    break;
                }
            }

            if (!$esta) {
                return false;
            }
        }

        return true;
    }

    /**
     * true si ALGUNA palabra que cuenta del nombre aparece (parecida) en el texto. Es la pregunta
     * «¿la persona nombró esta caja?»: alcanza con «Brubank» para BRUBANK FER.
     *
     * @param  string  $texto  Lo que dijo la persona.
     * @param  string  $nombre  El nombre cargado.
     * @param  array<int, string>  $sin_contar  Palabras del nombre que no alcanzan solas («caja»).
     * @return bool
     */
    public static function nombra_alguna_palabra($texto, $nombre, array $sin_contar = [])
    {
        $del_texto = explode(' ', self::fonetico($texto));

        $sin_contar = array_map(function ($palabra) {
            return self::palabra_fonetica(Str::lower(Str::ascii((string) $palabra)));
        }, $sin_contar);

        foreach (self::palabras_que_cuentan($nombre) as $buscada) {

            if (in_array($buscada, $sin_contar, true)) {
                continue;
            }

            foreach ($del_texto as $palabra) {

                if (self::palabras_parecidas($buscada, $palabra)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Los candidatos parecidos de una lista `[id => nombre]`, los que más palabras comparten primero,
     * hasta $tope.
     *
     * @param  string  $dictado
     * @param  iterable  $nombres  id => nombre
     * @param  int  $tope
     * @return array<int, array{id: int, nombre: string}>
     */
    public static function candidatos($dictado, $nombres, $tope = 5)
    {
        $encontrados = [];

        foreach ($nombres as $id => $nombre) {

            if (self::se_parece($dictado, (string) $nombre)) {

                $porcentaje = 0.0;
                similar_text(self::fonetico($dictado), self::fonetico((string) $nombre), $porcentaje);

                $encontrados[] = ['id' => (int) $id, 'nombre' => (string) $nombre, 'puntaje' => $porcentaje];
            }
        }

        usort($encontrados, function ($a, $b) {
            if ($a['puntaje'] == $b['puntaje']) {
                return strcmp($a['nombre'], $b['nombre']);
            }

            return $a['puntaje'] < $b['puntaje'] ? 1 : -1;
        });

        $salida = [];

        foreach (array_slice($encontrados, 0, (int) $tope) as $fila) {
            $salida[] = ['id' => $fila['id'], 'nombre' => $fila['nombre']];
        }

        return $salida;
    }
}
