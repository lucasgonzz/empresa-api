<?php

namespace App\Http\Controllers\Helpers;

/**
 * Sin DNS implícito: la descarga exige hosts E IPv4 públicas fijadas por configuración.
 * Sin mapa no hay sockets ni imagen: el correo conserva el nombre escapado.
 */
class ImagenParaMailHelper
{
    const MAX_BYTES = 1048576;

    public static function publicUrl($value, $origin = null)
    {
        if (!is_string($value) || $value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return null;
        }
        if (strpos($value, '//') === 0) {
            return null;
        }
        if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) {
            $base = self::publicUrl($origin);
            if ($base === null) {
                return null;
            }
            $value = rtrim($base, '/').'/'.ltrim($value, '/');
        }
        $p = parse_url($value);
        if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)
            || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && $p['port'] !== (strtolower($p['scheme']) === 'https' ? 443 : 80))) {
            return null;
        }
        $host = strtolower($p['host']);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::publicIpv4($host)) {
                return null;
            }
        } elseif (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
            || preg_match('/\.(local|localhost|internal|test|invalid|example)$/', $host)) {
            return null;
        }
        return $value;
    }

    private static function publicIpv4($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && !preg_match('/^(0\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|192\.0\.0\.|198\.(18|19)\.|(?:22[4-9]|23[0-9]|24[0-9]|25[0-5])\.)/', $ip);
    }

    public function prepare($source, $origin, array $allowedHosts)
    {
        try {
            $url = self::publicUrl($source, $origin);
            if ($url === null || !in_array(strtolower(parse_url($url, PHP_URL_HOST)), $allowedHosts, true)) {
                return null;
            }
            $host = strtolower(parse_url($url, PHP_URL_HOST));
            $map = config('mail.image_public_addresses', []);
            $addresses = isset($map[$host]) ? $map[$host] : [];
            if (!is_array($addresses) || !$addresses) {
                return null;
            }
            foreach ($addresses as $ip) {
                if (!is_string($ip) || !self::publicIpv4($ip)) {
                    return null;
                }
            }
            $bytes = $this->download($url, $host, $addresses[0]);
            return $this->convert($bytes);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function download($url, $host, $ip)
    {
        if (app()->environment('testing') || !function_exists('curl_init')) {
            return null;
        }
        $bytes = '';
        $c = curl_init($url);
        $port = strtolower(parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
        curl_setopt_array($c, [
            CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip],
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$bytes) {
                if (strlen($bytes) + strlen($chunk) > self::MAX_BYTES) {
                    return 0;
                }
                $bytes .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return $ok && $status === 200 ? $bytes : null;
    }

    public function convert($bytes)
    {
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES
            || !function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 4000000) {
            return null;
        }
        $im = @imagecreatefromstring($bytes);
        if (!$im) {
            return null;
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        ob_start();
        $ok = imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);
        return $ok && strlen($png) <= self::MAX_BYTES ? ['bytes' => $png, 'mime' => 'image/png', 'name' => 'logo.png'] : null;
    }
}
