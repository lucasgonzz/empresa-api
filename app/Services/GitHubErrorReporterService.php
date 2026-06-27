<?php

namespace App\Services;

use Throwable;

/**
 * Servicio de reporte automático de errores de producción a GitHub.
 * Sube archivos markdown estructurados al repo claude-comerciocity/errores/.
 */
class GitHubErrorReporterService
{
    /** Nombre de la variable de entorno con el token de GitHub. */
    const GITHUB_TOKEN_ENV = 'GITHUB_ERROR_REPORTER_TOKEN';

    /** Repositorio destino de los reportes de error. */
    const REPO = 'lucasgonzz/claude-comerciocity';

    /** Archivo local de throttle en storage/app/. */
    const THROTTLE_FILE = 'error_throttle.json';

    /** Segundos mínimos entre reportes del mismo hash de error. */
    const THROTTLE_SECONDS = 300;

    /**
     * Reportar excepción de PHP (origen: api).
     *
     * @param Throwable $e Excepción capturada por el handler global.
     * @return void
     */
    public function report(Throwable $e)
    {
        try {
            // Token requerido; sin token no se intenta subir nada
            $token = env(self::GITHUB_TOKEN_ENV);
            if (empty($token)) {
                return;
            }

            // Solo reportar en producción
            if (config('app.env') !== 'production') {
                return;
            }

            // Hash para evitar spam del mismo error en pocos minutos
            $hash = md5(get_class($e) . ':' . $e->getFile() . ':' . $e->getLine());
            if (!$this->shouldReport($hash)) {
                return;
            }

            $filename = $this->buildFilename(get_class($e));
            $path = 'errores/' . $filename;

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

            // Archivo relativo a la raíz del proyecto
            $relative_file = str_replace(base_path() . '/', '', $e->getFile());

            $markdown = $this->buildMarkdown([
                'path' => $path,
                'origen' => 'api',
                'class' => $short_class,
                'file' => $relative_file . ':' . $e->getLine(),
                'message' => $e->getMessage(),
                'url' => $request_line,
                'stack' => $e->getTraceAsString(),
            ]);

            $this->uploadToGitHub($token, $path, $filename, $markdown);
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
            $token = env(self::GITHUB_TOKEN_ENV);
            if (empty($token)) {
                return;
            }
            if (config('app.env') !== 'production') {
                return;
            }

            $file = isset($data['file']) ? $data['file'] : '';
            $line = isset($data['line']) ? (int) $data['line'] : 0;
            $message = isset($data['message']) ? $data['message'] : '';

            $hash = md5('spa:' . $message . ':' . $file . ':' . $line);
            if (!$this->shouldReport($hash)) {
                return;
            }

            $filename = $this->buildFilename('SpaError');
            $path = 'errores/' . $filename;

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
            ]);

            $this->uploadToGitHub($token, $path, $filename, $markdown);
        } catch (\Exception $ex) {
        }
    }

    /**
     * Evalúa throttle por hash y persiste timestamps recientes.
     *
     * @param string $hash Identificador único del error.
     * @return bool true si debe reportarse, false si está throttled.
     */
    private function shouldReport($hash)
    {
        $throttle_path = storage_path('app/' . self::THROTTLE_FILE);
        $now = time();
        $data = [];

        if (file_exists($throttle_path)) {
            $json = file_get_contents($throttle_path);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Si el mismo hash está dentro del throttle, no reportar
        if (isset($data[$hash]) && ($now - $data[$hash]) < self::THROTTLE_SECONDS) {
            return false;
        }

        // Guardar el hash y limpiar entradas viejas (más de 1 hora)
        $data[$hash] = $now;
        $cleaned = [];
        foreach ($data as $h => $ts) {
            if (($now - $ts) < 3600) {
                $cleaned[$h] = $ts;
            }
        }

        file_put_contents($throttle_path, json_encode($cleaned));

        return true;
    }

    /**
     * Genera nombre de archivo markdown con timestamp y clase corta.
     *
     * @param string $class_name Nombre completo o corto de la excepción.
     * @return string
     */
    private function buildFilename($class_name)
    {
        $short = basename(str_replace('\\', '/', $class_name));

        return date('Y-m-d_His') . '_' . $short . '.md';
    }

    /**
     * Arma el contenido markdown estructurado para Claude.
     *
     * @param array $data Campos: path, origen, class, file, message, url, stack.
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
     * Sube el markdown al repo GitHub vía Contents API.
     *
     * @param string $token Token de autenticación GitHub.
     * @param string $path Ruta dentro del repo (errores/...).
     * @param string $filename Nombre del archivo para el commit message.
     * @param string $markdown Contenido del reporte.
     * @return void
     */
    private function uploadToGitHub($token, $path, $filename, $markdown)
    {
        $url = 'https://api.github.com/repos/' . self::REPO . '/contents/' . $path;
        $payload = json_encode([
            'message' => 'error: ' . $filename,
            'content' => base64_encode($markdown),
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => implode("\n", [
                    'Authorization: token ' . $token,
                    'Content-Type: application/json',
                    'User-Agent: ComercioCity-ErrorReporter',
                    'Content-Length: ' . strlen($payload),
                ]),
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        file_get_contents($url, false, $context);
    }
}
