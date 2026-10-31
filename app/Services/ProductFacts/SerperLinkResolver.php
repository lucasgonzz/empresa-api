<?php

namespace App\Services\ProductFacts;

use App\Services\Traits\GoogleSearchHelpers;

/** Resuelve los enlaces opacos de Google sin volver a pagar la búsqueda. PHP 7.4. */
class SerperLinkResolver
{
    use GoogleSearchHelpers;

    const URL = 'https://google.serper.dev/resolve';
    const PROCESO = 'serper_resolver_enlaces';
    const PRESUPUESTO_SEGUNDOS = 40;
    const TIMEOUT_SEGUNDOS = 35;
    const MAX_INTENTOS = 3;

    public static function pendiente($url)
    {
        if (!is_string($url)) {
            return false;
        }
        $parts = parse_url(trim($url));
        return is_array($parts) && isset($parts['host'], $parts['path'])
            && in_array(rtrim(strtolower($parts['host']), '.'), ['google.com', 'www.google.com'], true)
            && rtrim($parts['path'], '/') === '/goto';
    }

    /** Devuelve únicamente fuentes directas; la descarga conserva sus propias guardas SSRF. */
    public static function directos(array $results)
    {
        return array_values(array_filter($results, function ($result) {
            return is_array($result) && isset($result['link']) && is_string($result['link'])
                && trim($result['link']) !== '' && !self::pendiente($result['link']);
        }));
    }

    /**
     * La fecha límite incluye HTTP y retryAfter. Si falla, conserva lo ya resuelto y nunca inventa URLs.
     * El presupuesto lo comparte el llamador entre todas las búsquedas del artículo.
     */
    public function resolver(array $body, $key, array $context, $deadline)
    {
        if (trim((string) $key) === '') {
            return $body;
        }
        for ($attempt = 0; $attempt < self::MAX_INTENTOS; $attempt++) {
            $remaining = $deadline - $this->ahora();
            if ($remaining < 1 || !$this->hay_pendientes($body)) {
                break;
            }
            $started = microtime(true);
            $response = null;
            try {
                $response = $this->google_http()->withOptions(['connect_timeout' => min(3, $remaining)])
                    ->timeout(min(self::TIMEOUT_SEGUNDOS, $remaining))
                    ->withHeaders(['X-API-KEY' => $key])->post(self::URL, $body);
                $resolved = $response->json();
                if (!$response->successful() || !is_array($resolved)
                    || !isset($resolved['organic']) || !is_array($resolved['organic'])) {
                    break;
                }
                // Solo se reemplazan las URLs de los resultados originales, preservando título y evidencia.
                foreach ($body['organic'] as $index => $result) {
                    if (is_array($result) && self::pendiente($result['link'] ?? null)
                        && isset($resolved['organic'][$index]['link']) && $this->destino_valido($resolved['organic'][$index]['link'])) {
                        $body['organic'][$index]['link'] = $resolved['organic'][$index]['link'];
                    }
                }
                if (!$this->hay_pendientes($body)) {
                    break;
                }
                $wait = $resolved['retryAfter'] ?? null;
                if (!is_numeric($wait) || !is_finite((float) $wait) || $wait < 0
                    || $wait + 1 >= $deadline - $this->ahora()) {
                    break;
                }
                $this->esperar((float) $wait);
            } catch (\Throwable $e) {
                // No se publica el error crudo: podría contener información del proveedor.
                break;
            } finally {
                $context['process'] = self::PROCESO;
                SerperUsageService::record($context, 'search', '', $response, $started);
            }
        }
        return $body;
    }

    public function hay_pendientes(array $body)
    {
        foreach (isset($body['organic']) && is_array($body['organic']) ? $body['organic'] : [] as $result) {
            if (is_array($result) && self::pendiente($result['link'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /** Una respuesta malformada conserva el goto y su condición reintentable. */
    protected function destino_valido($url)
    {
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        return isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    protected function ahora()
    {
        return microtime(true);
    }

    protected function esperar($seconds)
    {
        if ($seconds > 0) {
            usleep((int) ceil($seconds * 1000000));
        }
    }
}
