<?php

namespace App\Services;

use Throwable;

/**
 * Servicio de reporte automático de errores de producción a GitHub.
 * Sube archivos markdown estructurados al repo claude-comerciocity/errores/, con **un archivo por
 * firma de error** (no uno por ocurrencia): la misma firma actualiza siempre el mismo archivo,
 * sumando un contador de repeticiones en vez de crear un archivo nuevo cada vez (grupo 253).
 */
class GitHubErrorReporterService
{
    /** Repositorio destino de los reportes de error. */
    const REPO = 'lucasgonzz/claude-comerciocity';

    /** Archivo local de throttle en storage/app/. */
    const THROTTLE_FILE = 'error_throttle.json';

    /**
     * Segundos que se acumulan las ocurrencias de una misma firma antes de subir el reporte
     * (6 horas). Antes eran 300 segundos, exactamente lo mismo que el intervalo del cron que
     * dispara los comandos: la comparación `<` nunca daba true y el throttle no frenaba nada.
     */
    const THROTTLE_SECONDS = 21600;

    /**
     * Excepciones que no se reportan a GitHub: no son defectos del sistema sino datos
     * de origen externo que el sistema maneja bien. Se siguen logueando localmente.
     *
     * @var array
     */
    const DONT_UPLOAD = [
        'Intervention\Image\Exception\NotReadableException',
    ];

    /**
     * Reportar excepción de PHP (origen: api).
     *
     * @param Throwable $e Excepción capturada por el handler global.
     * @return void
     */
    public function report(Throwable $e)
    {
        try {
            // Token requerido; sin token no se intenta subir nada. Se lee siempre de config(),
            // nunca directo de la variable de entorno: con config:cache corrido en producción esa
            // lectura directa devuelve null y el reporter se apagaría entero sin avisar.
            $token = config('services.github_error_reporter.token');
            if (empty($token)) {
                return;
            }

            // Solo reportar en producción
            if (config('app.env') !== 'production') {
                return;
            }

            // Filtrar excepciones que no son defectos del sistema sino errores manejados correctamente.
            foreach (self::DONT_UPLOAD as $dont_upload_class) {
                if ($e instanceof $dont_upload_class) {
                    return;
                }
            }

            // Archivo relativo a la raíz del proyecto: se calcula ANTES del hash (a diferencia de
            // como estaba antes) para no dejar el path absoluto del hosting dentro de la firma,
            // que cambia por cliente y partiría en 40 firmas distintas lo que es un solo bug.
            $relative_file = str_replace(base_path() . '/', '', $e->getFile());

            // Firma estable de la firma del error: clase + archivo:línea + mensaje normalizado.
            // Incluir el mensaje normalizado es clave: antes el hash solo usaba clase+archivo+línea,
            // y casi todos los QueryException de Laravel salen de la misma línea de Connection.php,
            // así que errores completamente distintos (tabla inexistente vs columna inexistente)
            // colapsaban al mismo hash y el segundo se perdía como "repetido".
            $signature = get_class($e) . '|' . $relative_file . ':' . $e->getLine() . '|' . $this->normalizeMessage($e->getMessage());
            $hash = md5($signature);

            // Throttle acumulativo: si devuelve null, esta ocurrencia quedó sumada al contador
            // pendiente y todavía no corresponde subir nada.
            $throttle_result = $this->evaluateThrottle($hash);
            if ($throttle_result === null) {
                return;
            }

            $filename = $this->buildFilename(get_class($e), $hash);
            $path = 'errores/' . $filename;

            // Slug del cliente (comercio) que reporta, para la lista de "Clientes afectados".
            $client_slug = $this->getClientSlug();

            // Línea de request HTTP si hay contexto disponible
            $request_line = '';
            try {
                $req = app('request');
                if ($req) {
                    $request_line = strtoupper($req->method()) . ' ' . $req->path();
                }
            } catch (\Exception $ex) {
            }

            // Clase corta sin namespace
            $short_class = basename(str_replace('\\', '/', get_class($e)));

            $markdown = $this->buildMarkdown([
                'path' => $path,
                'origen' => 'api',
                'class' => $short_class,
                'file' => $relative_file . ':' . $e->getLine(),
                'message' => $e->getMessage(),
                'url' => $request_line,
                'stack' => $e->getTraceAsString(),
                // Bloque de ocurrencias inicial, usado solo si el archivo todavía no existe.
                'occurrences' => [
                    'repeticiones' => $throttle_result['pending'],
                    'primera' => date('Y-m-d', $throttle_result['first_seen']),
                    'ultima' => date('Y-m-d'),
                    'clientes' => [$client_slug],
                ],
            ]);

            $this->uploadToGitHub($token, $path, $filename, $markdown, $throttle_result['pending'], $client_slug);
        } catch (\Exception $ex) {
            // Nunca propagar errores del reporter
        }
    }

    /**
     * Reportar error enviado desde el SPA (origen: spa).
     *
     * @param array $data Payload con message, file, line, url, stack.
     * @return void
     */
    public function reportFront(array $data)
    {
        try {
            $token = config('services.github_error_reporter.token');
            if (empty($token)) {
                return;
            }
            if (config('app.env') !== 'production') {
                return;
            }

            $file = isset($data['file']) ? $data['file'] : '';
            $line = isset($data['line']) ? (int) $data['line'] : 0;
            $message = isset($data['message']) ? $data['message'] : '';

            // Misma lógica de firma que report(): mensaje normalizado incluido.
            $signature = 'spa|' . $file . ':' . $line . '|' . $this->normalizeMessage($message);
            $hash = md5($signature);

            $throttle_result = $this->evaluateThrottle($hash);
            if ($throttle_result === null) {
                return;
            }

            $filename = $this->buildFilename('SpaError', $hash);
            $path = 'errores/' . $filename;
            $client_slug = $this->getClientSlug();

            $stack = isset($data['stack']) ? $data['stack'] : '';
            $url = isset($data['url']) ? $data['url'] : '';

            $markdown = $this->buildMarkdown([
                'path' => $path,
                'origen' => 'spa',
                'class' => 'SpaError',
                'file' => $file . ($line ? ':' . $line : ''),
                'message' => $message,
                'url' => $url,
                'stack' => $stack,
                'occurrences' => [
                    'repeticiones' => $throttle_result['pending'],
                    'primera' => date('Y-m-d', $throttle_result['first_seen']),
                    'ultima' => date('Y-m-d'),
                    'clientes' => [$client_slug],
                ],
            ]);

            $this->uploadToGitHub($token, $path, $filename, $markdown, $throttle_result['pending'], $client_slug);
        } catch (\Exception $ex) {
        }
    }

    /**
     * Normaliza el mensaje para que dos ocurrencias del mismo error den la misma firma.
     * Reemplaza numeros, ids y valores variables, que cambian entre ocurrencias sin cambiar el bug.
     *
     * @param string $message Mensaje crudo de la excepcion.
     * @return string
     */
    private function normalizeMessage($message)
    {
        $normalized = (string) $message;
        // Fechas completas primero, antes de que el reemplazo de numeros las rompa.
        $normalized = preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', '<FECHA>', $normalized);
        // Paths absolutos del hosting: cambian por cliente, el bug es el mismo.
        $normalized = preg_replace('#/home/[^\s\)]+#', '<PATH>', $normalized);
        // Cualquier secuencia de digitos: ids, cantidades, montos.
        $normalized = preg_replace('/\d+/', 'N', $normalized);
        // Espacios multiples y saltos de linea.
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return substr(trim($normalized), 0, 300);
    }

    /**
     * Evalúa y actualiza el throttle acumulativo por hash (firma de error). A diferencia del
     * modelo anterior (que descartaba directamente las ocurrencias dentro de la ventana), acá se
     * van sumando en "pending" y viajan enteras en la próxima subida que sí corresponda.
     *
     * @param string $hash Hash de la firma del error.
     * @return array|null null si debe descartarse (todavía dentro de la ventana, ya se acumuló);
     *                     array ['pending' => int, 'first_seen' => int] si corresponde subir ahora.
     */
    private function evaluateThrottle($hash)
    {
        $throttle_path = storage_path('app/' . self::THROTTLE_FILE);
        // Timestamp actual, usado tanto para decidir la ventana como para limpiar entradas viejas.
        $now = time();
        $data = [];

        if (file_exists($throttle_path)) {
            $json = file_get_contents($throttle_path);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $entry = isset($data[$hash]) ? $data[$hash] : null;

        if ($entry !== null && isset($entry['last_upload']) && ($now - $entry['last_upload']) < self::THROTTLE_SECONDS) {
            // Todavía dentro de la ventana: sumar al contador pendiente y no subir nada ahora.
            $entry['pending'] = isset($entry['pending']) ? $entry['pending'] + 1 : 1;
            $data[$hash] = $entry;
            $this->persistThrottle($throttle_path, $data, $now);

            return null;
        }

        // Ventana vencida (o primera vez que se ve esta firma): el total a subir es lo que ya
        // estaba pendiente más esta ocurrencia. Se resetea el contador y se marca el momento de
        // esta subida como el nuevo "last_upload".
        $pending = ($entry !== null && isset($entry['pending'])) ? $entry['pending'] : 0;
        $first_seen = ($entry !== null && isset($entry['first_seen'])) ? $entry['first_seen'] : $now;

        $data[$hash] = [
            'last_upload' => $now,
            'pending' => 0,
            'first_seen' => $first_seen,
        ];

        $this->persistThrottle($throttle_path, $data, $now);

        return [
            'pending' => $pending + 1,
            'first_seen' => $first_seen,
        ];
    }

    /**
     * Persiste el archivo de throttle, descartando entradas cuya última subida fue hace más de
     * 7 días (antes era 1 hora: con eso un error que ocurre cada dos horas se reportaba siempre
     * como "nuevo" y perdía el contador acumulado).
     *
     * @param string $throttle_path Ruta absoluta al archivo JSON de throttle.
     * @param array $data Datos completos por hash, incluyendo la entrada recién actualizada.
     * @param int $now Timestamp actual.
     * @return void
     */
    private function persistThrottle($throttle_path, array $data, $now)
    {
        // 7 días en segundos.
        $max_age_seconds = 604800;

        $cleaned = [];
        foreach ($data as $h => $entry) {
            $last_upload = isset($entry['last_upload']) ? $entry['last_upload'] : 0;
            if (($now - $last_upload) < $max_age_seconds) {
                $cleaned[$h] = $entry;
            }
        }

        file_put_contents($throttle_path, json_encode($cleaned));
    }

    /**
     * Nombre de archivo estable por firma: la misma firma siempre apunta al mismo archivo.
     *
     * @param string $class_name Nombre completo o corto de la excepcion.
     * @param string $hash Hash de la firma.
     * @return string
     */
    private function buildFilename($class_name, $hash)
    {
        $short = basename(str_replace('\\', '/', $class_name));

        return $short . '_' . substr($hash, 0, 10) . '.md';
    }

    /**
     * Extrae el slug del cliente (comercio) a partir de base_path(), para poblar la lista de
     * "Clientes afectados" del reporte. En producción base_path() tiene la forma
     * ".../public_html/<slug>/api": se toma el penúltimo segmento. Si la estructura del path no
     * coincide con ese patrón (entorno local, VPS con otra estructura), se devuelve 'desconocido'
     * en vez de tirar una excepción — el reporter nunca puede romper producción.
     *
     * @return string
     */
    private function getClientSlug()
    {
        $path = str_replace('\\', '/', base_path());

        // Segmentos no vacíos del path, para poder ubicar el penúltimo sin depender de si
        // termina o no con una barra final.
        $segments = array_values(array_filter(explode('/', $path), function ($segment) {
            return $segment !== '';
        }));

        $total = count($segments);

        // Estructura esperada en producción: ".../public_html/<slug>/api". Si no coincide
        // exactamente (entorno local, otra estructura de VPS), se cae a 'desconocido'.
        if ($total >= 3 && $segments[$total - 1] === 'api' && $segments[$total - 3] === 'public_html') {
            return $segments[$total - 2];
        }

        return 'desconocido';
    }

    /**
     * Arma el contenido markdown estructurado para Claude. Se usa para el archivo COMPLETO cuando
     * la firma todavía no tiene archivo en GitHub; cuando ya existe, la actualización pasa por
     * mergeOccurrences() en vez de reconstruir todo el documento (así se preserva el header de
     * triage y el resto del cuerpo tal cual estaban).
     *
     * @param array $data Campos: path, origen, class, file, message, url, stack, occurrences.
     * @return string
     */
    private function buildMarkdown(array $data)
    {
        $lines = [];
        $lines[] = 'estado: sin_promptear';
        $lines[] = 'path: ' . $data['path'];
        $lines[] = 'origen: ' . $data['origen'];
        $lines[] = '';
        $lines[] = '> Este archivo contiene un error de producción de ComercioCity. Analizalo con el contexto del sistema y generá un prompt de Cursor numerado para corregirlo.';
        $lines[] = '';

        // Bloque de ocurrencias: contador de repeticiones, primera/última aparición y clientes
        // afectados. Va entre el blockquote y "## Clase de error".
        $occurrences = $data['occurrences'];
        $lines[] = '## Ocurrencias';
        $lines[] = '- **Repeticiones:** ' . $occurrences['repeticiones'];
        $lines[] = '- **Primera:** ' . $occurrences['primera'];
        $lines[] = '- **Ultima:** ' . $occurrences['ultima'];
        $lines[] = '- **Clientes afectados:** ' . implode(', ', $occurrences['clientes']);
        $lines[] = '';

        $lines[] = '## Clase de error';
        $lines[] = $data['class'];
        $lines[] = '';
        $lines[] = '## Archivo y línea';
        $lines[] = $data['file'];
        $lines[] = '';
        $lines[] = '## Mensaje';
        $lines[] = $data['message'];
        $lines[] = '';

        if (!empty($data['url'])) {
            $lines[] = '## URL / Origen';
            $lines[] = $data['url'];
            $lines[] = '';
        }

        if (!empty($data['stack'])) {
            $lines[] = '## Stack Trace';
            $lines[] = '```';
            $lines[] = $data['stack'];
            $lines[] = '```';
        }

        return implode("\n", $lines);
    }

    /**
     * Actualiza el bloque "## Ocurrencias" de un markdown ya existente: suma las ocurrencias
     * acumuladas, actualiza la fecha de última aparición y agrega el cliente a la lista si no
     * estaba. Preserva textualmente el resto del archivo (header de estado/triage con
     * estado/grupo/grupo_id, clase, mensaje, stack trace), para no perder el trabajo de triage
     * manual ya hecho sobre ese error.
     *
     * @param string $markdown Contenido markdown actual, tal como está en GitHub.
     * @param int $pending Ocurrencias nuevas a sumar al contador existente.
     * @param string $client_slug Slug del cliente que reporta esta ocurrencia.
     * @return string Markdown actualizado.
     */
    private function mergeOccurrences($markdown, $pending, $client_slug)
    {
        // Fecha de hoy, usada como nueva "Ultima aparición".
        $today = date('Y-m-d');

        // Repeticiones: sumar $pending al número actual (no reemplazar, acumular).
        $markdown = preg_replace_callback(
            '/(\*\*Repeticiones:\*\*\s*)(\d+)/',
            function ($m) use ($pending) {
                return $m[1] . ((int) $m[2] + $pending);
            },
            $markdown,
            1
        );

        // Ultima: reemplazar por la fecha de hoy.
        $markdown = preg_replace(
            '/(\*\*Ultima:\*\*\s*)\S+/',
            '${1}' . $today,
            $markdown,
            1
        );

        // Clientes afectados: agregar el slug del cliente actual si todavía no estaba en la lista.
        $markdown = preg_replace_callback(
            '/(\*\*Clientes afectados:\*\*\s*)(.*)/',
            function ($m) use ($client_slug) {
                $clients = array_filter(array_map('trim', explode(',', $m[2])));
                if (!in_array($client_slug, $clients)) {
                    $clients[] = $client_slug;
                }

                return $m[1] . implode(', ', $clients);
            },
            $markdown,
            1
        );

        return $markdown;
    }

    /**
     * Sube o actualiza el reporte en GitHub. Si el archivo de la firma todavía no existe, lo crea
     * completo; si ya existe, hace merge del bloque de ocurrencias preservando el resto. Ante un
     * 409/422 (SHA desactualizado porque otro cliente escribió el mismo archivo primero),
     * reintenta el ciclo completo una sola vez.
     *
     * @param string $token Token de autenticación GitHub.
     * @param string $path Ruta dentro del repo (errores/...).
     * @param string $filename Nombre del archivo para el commit message.
     * @param string $new_markdown Markdown completo a usar si el archivo todavía no existe.
     * @param int $pending Ocurrencias acumuladas a sumar si el archivo ya existe.
     * @param string $client_slug Slug del cliente que reporta, para la lista de afectados.
     * @return void
     */
    private function uploadToGitHub($token, $path, $filename, $new_markdown, $pending, $client_slug)
    {
        $this->attemptUpload($token, $path, $filename, $new_markdown, $pending, $client_slug, true);
    }

    /**
     * Un ciclo GET+PUT completo contra la Contents API de GitHub. Separado de uploadToGitHub()
     * para poder reintentarse a sí mismo una sola vez ante un conflicto de SHA.
     *
     * @param string $token Token de autenticación GitHub.
     * @param string $path Ruta dentro del repo (errores/...).
     * @param string $filename Nombre del archivo para el commit message.
     * @param string $new_markdown Markdown completo a usar si el archivo todavía no existe.
     * @param int $pending Ocurrencias acumuladas a sumar si el archivo ya existe.
     * @param string $client_slug Slug del cliente que reporta, para la lista de afectados.
     * @param bool $retry Si true, permite un reintento ante 409/422; el reintento se llama con false.
     * @return void
     */
    private function attemptUpload($token, $path, $filename, $new_markdown, $pending, $client_slug, $retry)
    {
        $get = $this->githubRequest($token, 'GET', $path);

        if ($get['status'] === 404) {
            // No existe todavía: crear con el markdown completo (incluye el bloque de ocurrencias inicial).
            $put = $this->githubRequest($token, 'PUT', $path, [
                'message' => 'error: ' . $filename,
                'content' => base64_encode($new_markdown),
            ]);

            if (($put['status'] === 409 || $put['status'] === 422) && $retry) {
                // Otro cliente lo creó justo entre nuestro GET y nuestro PUT: reintentar el ciclo
                // completo, que esta vez va a encontrar el archivo y hacer merge en vez de crear.
                $this->attemptUpload($token, $path, $filename, $new_markdown, $pending, $client_slug, false);
            }

            return;
        }

        if ($get['status'] !== 200 || empty($get['body']['content']) || empty($get['body']['sha'])) {
            // No se pudo leer el archivo existente (error de red, permisos, etc): abandonar en
            // silencio. El reporter nunca puede romper producción por no poder reportar.
            return;
        }

        // El contenido viene en base64 con saltos de línea cada 60 caracteres: hay que sacarlos
        // antes de decodificar.
        $current_markdown = base64_decode(str_replace("\n", '', $get['body']['content']));
        $merged_markdown = $this->mergeOccurrences($current_markdown, $pending, $client_slug);

        $put = $this->githubRequest($token, 'PUT', $path, [
            'message' => 'error: ' . $filename . ' (+' . $pending . ')',
            'content' => base64_encode($merged_markdown),
            'sha' => $get['body']['sha'],
        ]);

        if (($put['status'] === 409 || $put['status'] === 422) && $retry) {
            // Otro cliente escribió la misma firma entre nuestro GET y nuestro PUT: reintentar una
            // sola vez con el SHA fresco.
            $this->attemptUpload($token, $path, $filename, $new_markdown, $pending, $client_slug, false);
        }
    }

    /**
     * Ejecuta una request HTTP contra la Contents API de GitHub y devuelve el status HTTP junto
     * con el body decodificado (o null si no se pudo decodificar).
     *
     * @param string $token Token de autenticación GitHub.
     * @param string $method 'GET' o 'PUT'.
     * @param string $path Ruta dentro del repo (errores/...).
     * @param array|null $payload Body a enviar en PUT (null para GET).
     * @return array ['status' => int, 'body' => array|null]
     */
    private function githubRequest($token, $method, $path, array $payload = null)
    {
        $url = 'https://api.github.com/repos/' . self::REPO . '/contents/' . $path;

        $header = [
            'Authorization: token ' . $token,
            'User-Agent: ComercioCity-ErrorReporter',
        ];

        $options = [
            'method' => $method,
            'timeout' => 8,
            'ignore_errors' => true,
        ];

        if ($payload !== null) {
            $content = json_encode($payload);
            $header[] = 'Content-Type: application/json';
            $header[] = 'Content-Length: ' . strlen($content);
            $options['content'] = $content;
        }

        $options['header'] = implode("\n", $header);

        $context = stream_context_create(['http' => $options]);
        $response = file_get_contents($url, false, $context);

        // Status HTTP: primera línea de $http_response_header, formato "HTTP/1.1 200 OK".
        // El código de status es el segundo token separado por espacios.
        $status = 0;
        if (isset($http_response_header[0])) {
            $status_line_parts = explode(' ', trim($http_response_header[0]));
            if (isset($status_line_parts[1])) {
                $status = (int) $status_line_parts[1];
            }
        }

        $body = null;
        if ($response !== false) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return ['status' => $status, 'body' => $body];
    }
}
