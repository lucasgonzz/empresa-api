<?php

namespace App\Http\Controllers\Helpers;

/**
 * Disco público local primero, sin red. Remotos conservan IPv4 públicas pinneadas.
 * Sin bytes válidos el correo conserva el nombre escapado.
 */
class ImagenParaMailHelper
{
    /** Tope de bytes de entrada (archivo local o descarga) y de salida (PNG). */
    const MAX_BYTES = 1048576;

    /** Tope de píxeles que se acepta decodificar (4096 x 4096): los logos y fotos reales de los comercios son grandes. */
    const MAX_PIXELS = 16777216;

    /** Lado mayor, en píxeles, al que se reduce una imagen antes de adjuntarla (logo; la foto del artículo pide menos). */
    const DEFAULT_MAX_SIDE = 440;

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

    /**
     * Arma la imagen lista para adjuntar al mail (PNG reducido) desde un archivo local o un host habilitado.
     *
     * @param mixed $source URL absoluta o ruta relativa de la imagen.
     * @param mixed $origin URL pública del sistema (para completar rutas relativas).
     * @param array $allowedHosts Hosts habilitados de forma explícita (en minúscula).
     * @param int $max_side Lado mayor máximo del PNG resultante.
     * @return array|null ['bytes', 'mime', 'name'] o null si no se pudo (el mail conserva el nombre en texto).
     */
    public function prepare($source, $origin, array $allowedHosts, $max_side = self::DEFAULT_MAX_SIDE)
    {
        try {
            $local = $this->localBytes($source, $allowedHosts);
            if ($local !== false) {
                return $this->convert($local, $max_side);
            }
            $url = self::publicUrl($source, $origin);
            if ($url === null) {
                return null;
            }
            $host = strtolower(parse_url($url, PHP_URL_HOST));
            // Habilitado: está en la lista explícita o es de un dominio propio (sufijo) por HTTPS y sin puerto.
            if (!in_array($host, $allowedHosts, true) && !self::matchesAllowedSuffix($url)) {
                return null;
            }
            $addresses = $this->addresses($host);
            if ($addresses === null) {
                return null;
            }
            $bytes = $this->download($url, $host, $addresses[0]);
            return $this->convert($bytes, $max_side);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Indica si la URL pertenece a un dominio propio habilitado por sufijo (mail.image_allowed_host_suffixes).
     * Sólo HTTPS y sin puerto explícito; el host debe ser el sufijo o un subdominio suyo (nunca una coincidencia parcial).
     *
     * @param string $url URL ya validada por publicUrl().
     * @return bool
     */
    private static function matchesAllowedSuffix($url)
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https'
            || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $suffixes = config('mail.image_allowed_host_suffixes', []);
        foreach (is_array($suffixes) ? $suffixes : [] as $suffix) {
            // El sufijo tiene que ser un nombre DNS completo (sin comodines, rutas ni espacios).
            if (!is_string($suffix) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $suffix)) {
                continue;
            }
            $tail = '.'.$suffix;
            if ($host === $suffix || (strlen($host) > strlen($tail) && substr($host, -strlen($tail)) === $tail)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Direcciones IPv4 con las que se descarga un host habilitado: primero el mapa fijo de configuración
     * (mail.image_public_addresses) y, si no hay entrada, la resolución DNS del host. TODAS tienen que ser
     * públicas: una sola privada o reservada descarta el host (la descarga queda fijada a la primera, sin rebinding).
     *
     * @param string $host Host habilitado, en minúscula.
     * @return array|null Lista no vacía de IPv4 públicas o null si no hay o alguna no es pública.
     */
    private function addresses($host)
    {
        $map = config('mail.image_public_addresses', []);
        $addresses = is_array($map) && isset($map[$host]) ? $map[$host] : [];
        if (!is_array($addresses) || !$addresses) {
            $addresses = $this->resolveHost($host);
        }
        if (!is_array($addresses) || !$addresses) {
            return null;
        }
        $addresses = array_values($addresses);
        foreach ($addresses as $ip) {
            if (!is_string($ip) || !self::publicIpv4($ip)) {
                return null;
            }
        }
        return $addresses;
    }

    /**
     * Resuelve un host a sus direcciones IPv4. En testing no sale a la red.
     *
     * @param string $host Host a resolver.
     * @return array Direcciones IPv4 (vacío si no resuelve).
     */
    protected function resolveHost($host)
    {
        if (app()->environment('testing') || !function_exists('gethostbynamel')) {
            return [];
        }
        $list = @gethostbynamel($host);
        return is_array($list) ? $list : [];
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
        // La base configurada debe ser canónica: no interpretar escapes como otro namespace.
        if ($basePath === '' || self::relativePath(substr($basePath, 1)) !== substr($basePath, 1)) {
            return null;
        }
        $path = isset($input['path']) ? $input['path'] : '';
        // Validar antes de llamar ApiUrlHelper: nunca reparar traversal o escapes ambiguos.
        // Quitar sólo la barra de raíz: ltrim ocultaría barras iniciales repetidas.
        $decodedPath = self::relativePath(isset($path[0]) && $path[0] === '/' ? substr($path, 1) : $path);
        if ($decodedPath === null) {
            return null;
        }
        $prefix = $basePath.'/';
        $decodedAbsolutePath = '/'.$decodedPath;
        if ($own && ($decodedAbsolutePath === $basePath || strpos($decodedAbsolutePath, $prefix) === 0)
            && $path !== $basePath && strpos($path, $prefix) !== 0) {
            // Se parece al namespace local sólo al decodificar: rechazar, nunca reparar ni descargar.
            return null;
        }
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

    /**
     * Decodifica una imagen (PNG, JPEG o WEBP por sus bytes), la reduce si hace falta y la devuelve como PNG con alpha.
     *
     * @param mixed $bytes Bytes de la imagen (hasta MAX_BYTES).
     * @param int $max_side Lado mayor máximo; si la imagen es más grande se reduce proporcionalmente.
     * @return array|null ['bytes', 'mime', 'name'] o null si no es válida, es demasiado grande o no hay memoria.
     */
    public function convert($bytes, $max_side = self::DEFAULT_MAX_SIDE)
    {
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        foreach (['getimagesizefromstring', 'imagecreatefromstring', 'imagepng', 'imagealphablending',
            'imagesavealpha', 'imagedestroy', 'imagecreatetruecolor', 'imagecopyresampled'] as $function) {
            if (!function_exists($function)) {
                return null;
            }
        }
        $info = @getimagesizefromstring($bytes);
        $decoders = ['image/png' => 'imagecreatefrompng', 'image/jpeg' => 'imagecreatefromjpeg', 'image/webp' => 'imagecreatefromwebp'];
        if (!$info || !isset($decoders[$info['mime']]) || !function_exists($decoders[$info['mime']])
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096
            || $info[0] * $info[1] > self::MAX_PIXELS || !$this->hasMemoryFor($info[0], $info[1])) {
            return null;
        }
        $im = @imagecreatefromstring($bytes);
        if (!$im) {
            return null;
        }
        $level = ob_get_level();
        $small = null;
        try {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $width = $info[0];
            $height = $info[1];
            $max_side = max(1, (int) $max_side);
            // Reducir ANTES de codificar: un logo de 3590 px o una foto de 1533 px no entran en 1 MB como PNG.
            if (max($width, $height) > $max_side) {
                $ratio = $max_side / max($width, $height);
                $new_width = max(1, (int) round($width * $ratio));
                $new_height = max(1, (int) round($height * $ratio));
                $small = imagecreatetruecolor($new_width, $new_height);
                if (!$small) {
                    return null;
                }
                imagealphablending($small, false);
                imagesavealpha($small, true);
                imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
                if (!imagecopyresampled($small, $im, 0, 0, 0, 0, $new_width, $new_height, $width, $height)) {
                    return null;
                }
            }
            ob_start();
            $ok = @imagepng($small !== null ? $small : $im);
            $png = ob_get_contents();
            return $ok && is_string($png) && $png !== '' && strlen($png) <= self::MAX_BYTES
                ? ['bytes' => $png, 'mime' => 'image/png', 'name' => 'logo.png'] : null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            imagedestroy($im);
            if ($small !== null) {
                imagedestroy($small);
            }
        }
    }

    /**
     * Estima si hay memoria para decodificar y reducir una imagen (ancho x alto x 5 bytes + 8 MB) antes de intentarlo.
     *
     * @param int $width Ancho en píxeles.
     * @param int $height Alto en píxeles.
     * @return bool false si superaría el memory_limit disponible (así una imagen grande degrada a texto en vez de abortar el envío).
     */
    protected function hasMemoryFor($width, $height)
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || (int) $limit === -1) {
            return true;
        }
        $bytes = (float) $limit;
        $unit = strtolower(substr(trim($limit), -1));
        if ($unit === 'g') {
            $bytes *= 1073741824;
        } elseif ($unit === 'm') {
            $bytes *= 1048576;
        } elseif ($unit === 'k') {
            $bytes *= 1024;
        }
        return $width * $height * 5 + 8388608 <= $bytes - memory_get_usage();
    }
}
