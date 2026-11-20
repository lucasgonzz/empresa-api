<?php

namespace App\Http\Controllers\Helpers;

/**
 * Disco público local primero, sin red. Remotos conservan IPv4 públicas pinneadas.
 * Sin bytes válidos el correo conserva el nombre escapado.
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
            $local = $this->localBytes($source, $allowedHosts);
            if ($local !== false) {
                return $this->convert($local);
            }
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

    /** false: fuente remota; null: local rechazado; string: bytes locales acotados. */
    private function localBytes($source, array $allowedHosts)
    {
        if (!is_string($source) || $source === '' || strlen($source) > 4096
            || preg_match('/[\x00-\x20\x7f\\\\]/', $source) || strpos($source, '//') === 0) {
            return null;
        }
        $absolute = preg_match('~^[a-z][a-z0-9+.-]*:~i', $source);
        if ($absolute && self::publicUrl($source) === null) {
            return null;
        }
        $disk = config('filesystems.disks.public');
        if (!is_array($disk) || !isset($disk['url']) || self::publicUrl($disk['url']) === null) {
            return $absolute ? false : null;
        }
        $base = parse_url($disk['url']);
        $input = parse_url($source);
        $appUrl = self::publicUrl(config('app.APP_URL'));
        $app = $appUrl === null ? null : parse_url($appUrl);
        // Nunca dejar que el normalizador histórico convierta un origen ajeno en propio.
        $own = $absolute && self::sameOrigin($input, $base);
        $historical = $absolute && $app && self::sameOrigin($input, $app);
        if ($absolute && !$own && !$historical) {
            // Otro esquema/puerto del mismo host no es equivalente al disco público.
            return strtolower($input['host']) === strtolower($base['host']) ? null : false;
        }
        if (isset($disk['driver']) && $disk['driver'] !== 'local') {
            return false;
        }
        if (!isset($disk['driver'], $disk['root'])
            || !in_array(strtolower($base['host']), $allowedHosts, true)
            || isset($base['query']) || isset($base['fragment'])
            || isset($input['query']) || isset($input['fragment'])) {
            return null;
        }
        $basePath = isset($base['path']) ? $base['path'] : '';
        if (substr($basePath, -1) === '/') {
            $basePath = substr($basePath, 0, -1);
        }
        if ($basePath === '' || self::relativePath(substr($basePath, 1)) === null) {
            return null;
        }
        $path = isset($input['path']) ? $input['path'] : '';
        // Validar antes de llamar ApiUrlHelper: nunca reparar traversal o escapes ambiguos.
        if (self::relativePath(ltrim($path, '/')) === null) {
            return null;
        }
        $prefix = $basePath.'/';
        $url = $source;
        if (!$own || strpos($path, $prefix) !== 0) {
            $appPath = $app && isset($app['path']) ? rtrim($app['path'], '/') : '';
            if ($absolute) {
                $rest = substr($path, strlen($appPath));
                if (!$historical || ($appPath !== '' && strpos($path, $appPath.'/') !== 0)
                    || !preg_match('~^/(?:public/)*storage/[^/].*$~D', $rest)) {
                    return strpos($path, $basePath) === 0 ? null : false;
                }
            } elseif (!preg_match('~^(?:/?storage/|public/storage/)[^/].*$~D', $source)
                && (strpos($source, '/') !== false || strpos($source, ':') !== false)) {
                return null;
            }
            if ($app === null) {
                return null;
            }
            $url = ApiUrlHelper::url_publica_de_imagen($source);
        }
        if (self::publicUrl($url) === null) {
            return null;
        }
        $normalized = parse_url($url);
        $path = isset($normalized['path']) ? $normalized['path'] : '';
        if (!self::sameOrigin($normalized, $base) || strpos($path, $prefix) !== 0) {
            return null;
        }
        $relative = self::relativePath(substr($path, strlen($prefix)));
        $root = $disk['root'];
        if ($relative === null || !is_string($root) || $root === '' || $root[0] !== '/'
            || strpos($root, "\0") !== false || strpos($root, '\\') !== false) {
            return null;
        }
        $root = realpath($root);
        if ($root === false || $root === '/' || !is_dir($root)) {
            return null;
        }
        $before = self::inspectLocal($root, $relative);
        if ($before === null || $before['size'] > self::MAX_BYTES) {
            return null;
        }
        $handle = @fopen($root.'/'.$relative, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $opened = fstat($handle);
            $after = self::inspectLocal($root, $relative);
            if (!self::sameFile($before, $opened) || !self::sameFile($after, $opened)
                || $opened['size'] > self::MAX_BYTES) {
                return null;
            }
            $bytes = '';
            while (!feof($handle) && strlen($bytes) <= self::MAX_BYTES) {
                $chunk = fread($handle, min(8192, self::MAX_BYTES + 1 - strlen($bytes)));
                if ($chunk === false || ($chunk === '' && !feof($handle))) {
                    return null;
                }
                $bytes .= $chunk;
            }
            $end = self::inspectLocal($root, $relative);
            $stat = fstat($handle);
            return strlen($bytes) <= self::MAX_BYTES && self::sameFile($end, $stat)
                && $stat['size'] === $opened['size'] && strlen($bytes) === $stat['size'] ? $bytes : null;
        } finally {
            fclose($handle);
        }
    }

    private static function sameOrigin(array $a, array $b)
    {
        return strtolower($a['scheme']) === strtolower($b['scheme'])
            && strtolower($a['host']) === strtolower($b['host'])
            && (isset($a['port']) ? $a['port'] : (strtolower($a['scheme']) === 'https' ? 443 : 80))
                === (isset($b['port']) ? $b['port'] : (strtolower($b['scheme']) === 'https' ? 443 : 80));
    }

    private static function relativePath($path)
    {
        if ($path === '' || strlen($path) > 4096 || preg_match('/%(?![0-9a-f]{2})|%2f|%5c/i', $path)) {
            return null;
        }
        $decoded = rawurldecode($path);
        if (preg_match('/[%\x00-\x1f\x7f\\\\:]/', $decoded)) {
            return null;
        }
        $segments = explode('/', $decoded);
        if (count($segments) > 64) {
            return null;
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        return $decoded;
    }

    private static function inspectLocal($root, $relative)
    {
        clearstatcache(true);
        $path = $root;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/'.$segment;
            $stat = @lstat($path);
            if (!$stat || ($stat['mode'] & 0170000) === 0120000) {
                return null;
            }
        }
        $checked = StoragePathHelper::inspeccionar($root, $relative);
        if ($checked['path'] !== $path || !is_file($path) || !is_readable($path)) {
            return null;
        }
        return @stat($path) ?: null;
    }

    private static function sameFile($a, $b)
    {
        return is_array($a) && is_array($b) && ($b['mode'] & 0170000) === 0100000
            && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
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
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        foreach (['getimagesizefromstring', 'imagecreatefromstring', 'imagepng', 'imagealphablending',
            'imagesavealpha', 'imagedestroy'] as $function) {
            if (!function_exists($function)) {
                return null;
            }
        }
        $info = @getimagesizefromstring($bytes);
        $decoders = ['image/png' => 'imagecreatefrompng', 'image/jpeg' => 'imagecreatefromjpeg', 'image/webp' => 'imagecreatefromwebp'];
        if (!$info || !isset($decoders[$info['mime']]) || !function_exists($decoders[$info['mime']])
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 4000000) {
            return null;
        }
        $im = @imagecreatefromstring($bytes);
        if (!$im) {
            return null;
        }
        $level = ob_get_level();
        try {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            ob_start();
            $ok = @imagepng($im);
            $png = ob_get_contents();
            return $ok && is_string($png) && $png !== '' && strlen($png) <= self::MAX_BYTES
                ? ['bytes' => $png, 'mime' => 'image/png', 'name' => 'logo.png'] : null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            imagedestroy($im);
        }
    }
}
