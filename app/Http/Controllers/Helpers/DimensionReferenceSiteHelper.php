<?php

namespace App\Http\Controllers\Helpers;

use App\Models\DimensionReferenceSite;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los sitios de referencia de la búsqueda masiva de peso y medidas (misión medidas-sitios-de-referencia,
 * 11/11/2026): las páginas donde el comercio sabe que están los pesos y medidas de sus productos.
 *
 * Por qué existen: en la corrida de Masquito (4.143 artículos) los cuatro sitios donde estaban los datos (obi.de,
 * verdefacile.eu, meteorpiston.com, carbydoctor.com.au) no aparecieron NI UNA vez en los resultados: la búsqueda
 * es en Argentina y en castellano. Con `"<código>" site:a OR site:b` sí aparecen. El motor
 * (ArticleDimensionSearchEngine) arma ese plan primero, con la FOTO de la lista que guarda cada corrida.
 *
 * Reglas de la lista:
 *   - Se acepta una URL o un dominio por entrada («https://www.obi.de/p/123», «obi.de», «site:obi.de»): se saca el
 *     esquema, el `site:`, el `www.`, la ruta, la query y el puerto, y se pasa a minúsculas.
 *   - Tiene que quedar un dominio con al menos un punto; si no, la lista entera se rechaza (422) diciendo cuál.
 *   - Los vacíos y los repetidos se descartan sin error.
 *   - Como mucho MAXIMO_SITIOS: Google acota el largo de la consulta, y la de sitios lleva además los códigos.
 *   - Guardar REEMPLAZA la lista entera, en una transacción.
 *
 * 🔴 Todo por dueño: cada consulta filtra por `user_id`.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionReferenceSiteHelper
{
    /** Sitios como mucho por comercio (límite de largo de la consulta de Google). */
    const MAXIMO_SITIOS = 8;

    /** Largo máximo de un dominio (la columna). */
    const LARGO_MAXIMO = 120;

    /**
     * Los dominios del comercio, en el orden en que se cargaron.
     *
     * @param  int $owner_id
     * @return array  Lista de strings.
     */
    public static function listar($owner_id)
    {
        return DimensionReferenceSite::where('user_id', (int) $owner_id)
            ->orderBy('id')
            ->pluck('dominio')
            ->map(function ($dominio) {
                return (string) $dominio;
            })
            ->values()
            ->all();
    }

    /**
     * Normaliza una entrada (URL o dominio) al dominio que se guarda. '' si la entrada está vacía; null si no es
     * un dominio válido.
     *
     * @param  mixed $entrada
     * @return string|null
     */
    public static function normalizar($entrada)
    {
        if (is_null($entrada)) {
            return '';
        }

        if (!is_string($entrada)) {
            return null;
        }

        $texto = mb_strtolower(trim($entrada), 'UTF-8');

        if ($texto === '') {
            return '';
        }

        // «site:obi.de» (lo que se escribiría en Google) vale como «obi.de».
        $texto = (string) preg_replace('/^site:\s*/', '', $texto);

        // Sin esquema, parse_url no reconoce el host: se le agrega uno para leerlo.
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#', $texto)) {
            $texto = 'http://'.ltrim($texto, '/');
        }

        $host = parse_url($texto, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim($host, '.');
        $host = (string) preg_replace('/^www\./', '', $host);

        // Un dominio con caracteres no ASCII («tienda-ñandú.com.ar») va en punycode, si el servidor sabe convertirlo.
        if (preg_match('/[^\x00-\x7F]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = defined('INTL_IDNA_VARIANT_UTS46') ? idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46) : idn_to_ascii($host);

            if (!is_string($ascii) || $ascii === '') {
                return null;
            }

            $host = strtolower($ascii);
        }

        if (strlen($host) > self::LARGO_MAXIMO || !self::es_dominio($host)) {
            return null;
        }

        return $host;
    }

    /**
     * ¿Tiene forma de dominio? Etiquetas de letras, dígitos y guiones (sin guion en las puntas), separadas por
     * puntos, con al menos un punto y una terminación de letras (o punycode).
     *
     * @param  string $host
     * @return bool
     */
    public static function es_dominio($host)
    {
        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9\-]{1,59})$/', (string) $host);
    }

    /**
     * Normaliza una lista entera sin guardarla.
     *
     * @param  mixed $entradas  Lo que mandó la SPA o el comando.
     * @return array  ['ok' => bool, 'sitios' => [dominios], 'message' => string|null, 'errors' => array]
     */
    public static function normalizar_lista($entradas)
    {
        if (!is_array($entradas)) {
            return self::invalida('Mandá los sitios como una lista.', ['sitios' => ['Mandá los sitios como una lista.']]);
        }

        $sitios   = [];
        $vistos   = [];
        $invalido = [];

        foreach (array_values($entradas) as $indice => $entrada) {
            $dominio = self::normalizar($entrada);

            if (is_null($dominio)) {
                $invalido['sitios.'.$indice] = ['«'.(is_scalar($entrada) ? mb_substr(trim((string) $entrada), 0, 80) : '?').'» no es una página web válida (por ejemplo: obi.de o https://www.obi.de).'];
                continue;
            }

            if ($dominio === '' || isset($vistos[$dominio])) {
                continue;
            }

            $vistos[$dominio] = true;
            $sitios[]         = $dominio;
        }

        if (!empty($invalido)) {
            $primero = reset($invalido);

            return self::invalida($primero[0], $invalido);
        }

        if (count($sitios) > self::MAXIMO_SITIOS) {
            $mensaje = 'Se pueden cargar hasta '.self::MAXIMO_SITIOS.' sitios de referencia (mandaste '.count($sitios).').';

            return self::invalida($mensaje, ['sitios' => [$mensaje]]);
        }

        return ['ok' => true, 'sitios' => $sitios, 'message' => null, 'errors' => []];
    }

    /**
     * Reemplaza la lista del comercio por la que llega (normalizada). Si alguna entrada no sirve, no se toca nada.
     *
     * @param  int   $owner_id
     * @param  mixed $entradas
     * @return array  ['status' => 200|422, 'sitios' => [dominios guardados], 'message' => string|null, 'errors' => array]
     */
    public static function guardar($owner_id, $entradas)
    {
        $lista = self::normalizar_lista($entradas);

        if (!$lista['ok']) {
            return ['status' => 422, 'sitios' => [], 'message' => $lista['message'], 'errors' => $lista['errors']];
        }

        $sitios = $lista['sitios'];

        DB::transaction(function () use ($owner_id, $sitios) {
            DimensionReferenceSite::where('user_id', (int) $owner_id)->delete();

            $ahora = Carbon::now();
            $filas = [];

            foreach ($sitios as $dominio) {
                $filas[] = ['user_id' => (int) $owner_id, 'dominio' => $dominio, 'created_at' => $ahora, 'updated_at' => $ahora];
            }

            if (!empty($filas)) {
                DB::table('dimension_reference_sites')->insert($filas);
            }
        });

        return ['status' => 200, 'sitios' => self::listar($owner_id), 'message' => null, 'errors' => []];
    }

    /**
     * @param  string $mensaje
     * @param  array  $errores
     * @return array
     */
    protected static function invalida($mensaje, array $errores)
    {
        return ['ok' => false, 'sitios' => [], 'message' => $mensaje, 'errors' => $errores];
    }
}
