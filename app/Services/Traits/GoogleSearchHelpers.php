<?php

namespace App\Services\Traits;

use Illuminate\Support\Facades\Http;

/**
 * Helpers compartidos para los flujos que usan Google Custom Search y validación GS1
 * de códigos de barras. Extraídos de ProcessArticleBatchImagesJob para que tanto el
 * job de asignación batch de imágenes como ProductInfoLookupService usen exactamente
 * la misma lógica de normalización de código de barras, validación de dígito verificador
 * GS1 y cliente HTTP con TLS del entorno.
 *
 * Los métodos son `protected` para poder invocarse desde la clase que use el trait.
 */
trait GoogleSearchHelpers
{
    /**
     * Normaliza el código de barras eliminando espacios (igual que getBarCode en el SPA).
     *
     * @param string $bar_code
     * @return string
     */
    protected function normalize_bar_code(string $bar_code): string
    {
        return preg_replace('/\s+/', '', $bar_code);
    }

    /**
     * Determina si el valor puede usarse como código de barras de producto en búsqueda automática.
     * Solo acepta GTIN numérico (EAN-8, UPC/EAN-12, EAN-13, GTIN-14) con dígito verificador válido.
     * Réplica de is_valid_product_bar_code en SearchImage.vue.
     *
     * @param string $normalized Valor ya normalizado sin espacios.
     * @return bool
     */
    protected function is_valid_product_bar_code(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        if (!preg_match('/^\d+$/', $normalized)) {
            return false;
        }

        return $this->validate_gs1_check_digit($normalized);
    }

    /**
     * Valida el dígito verificador GS1 (módulo 10) para códigos de 8, 12, 13 o 14 dígitos.
     * Réplica de validate_gs1_check_digit en SearchImage.vue.
     *
     * @param string $code Cadena numérica incluyendo el dígito de control al final.
     * @return bool
     */
    protected function validate_gs1_check_digit(string $code): bool
    {
        $len = strlen($code);
        if (!in_array($len, [8, 12, 13, 14], true)) {
            return false;
        }

        $digits      = array_map('intval', str_split($code));
        $check_digit = array_pop($digits);

        $sum = 0;
        for ($i = $len - 2; $i >= 0; $i--) {
            $position_from_right = $len - 1 - $i;
            $weight              = $position_from_right % 2 === 1 ? 3 : 1;
            $sum                += $digits[$i] * $weight;
        }

        $calculated = (10 - ($sum % 10)) % 10;

        return $calculated === $check_digit;
    }

    /**
     * Opciones Guzzle/cURL para peticiones a Google (ver config/services.php google_custom_search).
     * En WAMP/local sin CA bundle evita cURL error 60.
     *
     * @return array<string, mixed>
     */
    protected function google_http_options(): array
    {
        $ca_bundle = (string) config('services.google_custom_search.guzzle_ca_bundle', '');
        if ($ca_bundle !== '') {
            return ['verify' => $ca_bundle];
        }

        return [
            'verify' => (bool) config('services.google_custom_search.guzzle_verify', true),
        ];
    }

    /**
     * Cliente HTTP con opciones SSL del entorno para Google Custom Search y descarga de imágenes.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function google_http()
    {
        return Http::withOptions($this->google_http_options());
    }

    /**
     * Valor del header `Referer` para las llamadas a la API de Google Custom Search.
     *
     * La key tiene cargada una restriccion por referrer HTTP en Google Cloud Console. El navegador
     * la satisface solo (SearchImage.vue le pega directo a googleapis), pero una llamada
     * server-to-server no manda `Referer` y Google la rechaza con "Requests from referer <empty>
     * are blocked". El valor se deriva del host de `APP_URL`, que es exactamente el mismo host que
     * el navegador manda en el camino que hoy funciona, asi que no hay que cargar ninguna variable
     * nueva en los ~40 .env de produccion.
     *
     * Se lee `APP_URL` directo y NO `ApiUrlHelper::base()` a proposito: para el `Referer` alcanza
     * con el host (el `/public` y la barra final que ApiUrlHelper normaliza son path, y parse_url
     * los ignora solo), y `ApiUrlHelper` recien existe desde la v3.3.3 — este metodo tiene que
     * poder aplicarse tal cual como hotfix manual sobre una instalacion mas vieja. El 5/8/2026 una
     * version que si lo usaba murio en produccion con "Class ApiUrlHelper not found" sobre v3.3.2.
     *
     * La barra final no es decorativa: los patrones de referrer de Google se comparan contra una
     * URL, no contra un host pelado.
     *
     * @return string
     */
    protected function google_api_referer()
    {
        $app_url = (string) config('app.APP_URL');
        if ($app_url === '') {
            $app_url = (string) config('app.url');
        }

        $host = (string) parse_url($app_url, PHP_URL_HOST);
        if ($host === '') {
            // `APP_URL` cargada sin esquema (mi-cliente.comerciocity.com/api): parse_url no
            // reconoce host y mete todo en path. El host es el primer segmento.
            $path   = (string) parse_url($app_url, PHP_URL_PATH);
            $partes = explode('/', ltrim($path, '/'));
            $host   = isset($partes[0]) ? $partes[0] : '';
        }

        // PHP 7.4: `str_ends_with` es de PHP 8 y esta prohibido en este repo, de ahi el substr.
        // Y el caso `$es_raiz` no sobra: hay clientes servidos desde comerciocity.com/<cliente>/api,
        // cuyo host es el dominio raiz y no matchea el sufijo.
        $raiz          = 'comerciocity.com';
        $sufijo        = '.comerciocity.com';
        $es_raiz       = ($host === $raiz);
        $es_subdominio = (strlen($host) > strlen($sufijo) && substr($host, -strlen($sufijo)) === $sufijo);

        if ($host !== '' && ($es_raiz || $es_subdominio)) {
            return 'https://'.$host.'/';
        }

        $configurado = (string) config('services.google_custom_search.referer', '');
        if ($configurado !== '') {
            return rtrim($configurado, '/').'/';
        }

        return 'https://www.comerciocity.com/';
    }

    /**
     * Cliente HTTP para las llamadas a la API de Google (googleapis.com/customsearch/v1), con el
     * header `Referer` que la key necesita.
     *
     * 🔴 El header va SOLO aca y NO adentro de `google_http()`, aunque mover la linea una funcion
     * mas arriba parezca la simplificacion obvia: ese mismo cliente se usa para descargar la imagen
     * encontrada, que vive en un sitio de terceros cualquiera. Muchos de esos sitios tienen
     * proteccion anti-hotlinking y bloquean la descarga si el `Referer` es de otro dominio. Sin el
     * header, hoy la dejan pasar. Unificarlo arreglaria la busqueda y romperia la descarga: el
     * resultado neto seguiria siendo cero imagenes, por una causa mucho mas dificil de diagnosticar.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function google_api_http()
    {
        return $this->google_http()->withHeaders([
            'Referer' => $this->google_api_referer(),
        ]);
    }
}
