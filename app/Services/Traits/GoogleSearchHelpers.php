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
}
