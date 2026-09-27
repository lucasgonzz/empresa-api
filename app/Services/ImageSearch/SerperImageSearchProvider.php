<?php

namespace App\Services\ImageSearch;

use App\Services\ImageAssignment\ImageServiceCallLogger;
use App\Services\Traits\GoogleSearchHelpers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Búsqueda de imágenes con Serper (https://serper.dev), Google Imágenes por API (misión
 * imagenes-catalogo-completo, 27/9/2026). Es el proveedor principal de las asignaciones
 * inteligentes: sin el techo de 100 búsquedas diarias de Custom Search y a USD 0,30-1 cada 1.000.
 *
 * Request: `POST https://google.serper.dev/images`, header `X-API-KEY`, body `{q, gl, hl, num}`.
 * Respuesta: `images[]` con `imageUrl`, `imageWidth`, `imageHeight`, `thumbnailUrl`, `link` (la
 * página), `domain`, `title`, `position`.
 *
 * El cliente HTTP es el de GoogleSearchHelpers::google_http(): mismas opciones de TLS que Custom
 * Search (en WAMP sin CA bundle evita el cURL error 60). SIN `Referer`: Serper no lo pide.
 *
 * 🔴 La clave va solo en el header y nunca en un log ni en un mensaje de error.
 */
class SerperImageSearchProvider implements ImageSearchProvider
{
    use GoogleSearchHelpers;

    /** Endpoint de búsqueda de imágenes de Serper. */
    const URL = 'https://google.serper.dev/images';

    /** Segundos de espera de la búsqueda (igual que la de Custom Search). */
    const TIMEOUT_SEGUNDOS = 15;

    /** Resultados que se piden por búsqueda: los mismos 10 que devuelve Custom Search. */
    const RESULTADOS_POR_BUSQUEDA = 10;

    /** @var string Clave de Serper (de config, salvo que el llamador pase otra). */
    protected $api_key;

    /**
     * @param string|null $api_key  Null = la de config('services.serper.api_key').
     */
    public function __construct($api_key = null)
    {
        $this->api_key = is_null($api_key)
            ? trim((string) config('services.serper.api_key'))
            : trim((string) $api_key);
    }

    /**
     * @return string
     */
    public function nombre()
    {
        return 'serper';
    }

    /**
     * Busca imágenes en Serper. Ver el contrato en ImageSearchProvider::buscar().
     *
     * @param  string $consulta
     * @return array
     */
    public function buscar($consulta)
    {
        $consulta = trim((string) $consulta);

        if ($this->api_key === '') {
            return $this->fallo('No está configurada la clave de Serper (SERPER_API_KEY) en el servidor.');
        }

        // Para el registro de consultas (image_service_calls): cuánto tardó Serper en responder.
        $inicio = microtime(true);

        try {
            $respuesta = $this->google_http()
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->withHeaders([
                    'X-API-KEY'    => $this->api_key,
                    'Content-Type' => 'application/json',
                ])
                ->post(self::URL, [
                    'q'   => $consulta,
                    'gl'  => (string) config('services.serper.gl', 'ar'),
                    'hl'  => (string) config('services.serper.hl', 'es'),
                    'num' => self::RESULTADOS_POR_BUSQUEDA,
                ]);
        } catch (\Exception $e) {
            /*
             * Plan §13, S2 (sexta pasada): el mensaje crudo de Guzzle ("cURL error 28: Operation
             * timed out after 15001 milliseconds…") llegaba al diagnóstico del artículo, a su
             * motivo_detalle y al motivo de la asignación, que ve cualquier usuario del comercio. Al
             * usuario, un texto legible; el detalle (sin claves) al log y, como `detalle`, al registro
             * de consultas que mira el admin.
             */
            $detalle = 'No se pudo conectar con Serper: '.Str::limit(ImageServiceCallLogger::sin_claves($e->getMessage()), 200, '…');

            Log::warning('[ImagenesInteligentes] Serper no respondió.', ['error' => $detalle]);

            $legible = ImageServiceCallLogger::es_timeout($e->getMessage())
                ? 'El buscador no respondió a tiempo.'
                : 'No se pudo conectar con el buscador.';

            return $this->fallo($legible, null, $this->milisegundos_desde($inicio), $detalle);
        }

        $duracion_ms = $this->milisegundos_desde($inicio);
        $estado      = (int) $respuesta->status();

        $body = $respuesta->json();

        if (!$respuesta->successful()) {
            // Serper devuelve el motivo en `message` ("Unauthorized.", "Not enough credits", ...).
            $mensaje = is_array($body) && isset($body['message']) && is_scalar($body['message'])
                ? trim((string) $body['message'])
                : '';

            return $this->fallo('Serper respondió con error (HTTP '.$estado.')'.($mensaje !== '' ? ': '.Str::limit($mensaje, 200, '…') : '.'), $estado, $duracion_ms);
        }

        if (!is_array($body)) {
            return $this->fallo('Serper devolvió una respuesta que no se pudo leer.', $estado, $duracion_ms);
        }

        $imagenes = isset($body['images']) && is_array($body['images']) ? $body['images'] : [];

        $resultados = [];

        foreach ($imagenes as $indice => $imagen) {
            if (!is_array($imagen)) {
                continue;
            }

            $url = $this->texto($imagen, 'imageUrl');

            // Sin URL de la imagen no hay nada que bajar: no es una candidata.
            if ($url === '') {
                continue;
            }

            $resultados[] = [
                'url'       => $url,
                'miniatura' => $this->texto($imagen, 'thumbnailUrl'),
                'ancho'     => $this->entero($imagen, 'imageWidth'),
                'alto'      => $this->entero($imagen, 'imageHeight'),
                'pagina'    => $this->texto($imagen, 'link'),
                'dominio'   => $this->texto($imagen, 'domain'),
                'titulo'    => $this->texto($imagen, 'title'),
                // La posición que informa Serper (1-based); si no viene, la del array.
                'posicion'  => $this->entero($imagen, 'position') ?: ($indice + 1),
            ];
        }

        return [
            'ok'          => true,
            'error'       => null,
            'resultados'  => $resultados,
            'total'       => count($resultados),
            'http_status' => $estado,
            'duracion_ms' => $duracion_ms,
        ];
    }

    /**
     * Resultado uniforme de una búsqueda que falló (no cuenta como búsqueda).
     *
     * @param  string      $error        Lo que ve el usuario (diagnóstico, motivos): legible.
     * @param  int|null    $http_status  El estado HTTP que devolvió Serper (null si no llegó a responder).
     * @param  int|null    $duracion_ms  Cuánto tardó (null si ni siquiera se llamó).
     * @param  string|null $detalle      El detalle técnico (sin claves) para el registro de consultas
     *                                   del admin, cuando el `error` es un texto genérico.
     * @return array
     */
    protected function fallo($error, $http_status = null, $duracion_ms = null, $detalle = null)
    {
        // Defensivo: la clave viaja en un header y Guzzle no pone headers en sus mensajes, pero este
        // texto termina a la vista de cualquier usuario del comercio (diagnóstico, registro visible).
        if ($this->api_key !== '') {
            $error = str_replace($this->api_key, '***', (string) $error);

            if (!is_null($detalle)) {
                $detalle = str_replace($this->api_key, '***', (string) $detalle);
            }
        }

        return [
            'ok'          => false,
            'error'       => $error,
            'detalle'     => $detalle,
            'resultados'  => [],
            'total'       => null,
            'http_status' => is_null($http_status) ? null : (int) $http_status,
            'duracion_ms' => is_null($duracion_ms) ? null : (int) $duracion_ms,
        ];
    }

    /**
     * Milisegundos desde un microtime(true).
     *
     * @param  float $inicio
     * @return int
     */
    protected function milisegundos_desde($inicio)
    {
        return (int) max(0, round((microtime(true) - (float) $inicio) * 1000));
    }

    /**
     * Un campo de texto de la respuesta, recortado; '' si no viene.
     *
     * @param  array  $datos
     * @param  string $clave
     * @return string
     */
    protected function texto(array $datos, $clave)
    {
        return isset($datos[$clave]) && is_scalar($datos[$clave]) ? trim((string) $datos[$clave]) : '';
    }

    /**
     * Un campo numérico de la respuesta como entero positivo; null si no viene o no es válido.
     *
     * @param  array  $datos
     * @param  string $clave
     * @return int|null
     */
    protected function entero(array $datos, $clave)
    {
        if (!isset($datos[$clave]) || !is_numeric($datos[$clave])) {
            return null;
        }

        $valor = (int) $datos[$clave];

        return $valor > 0 ? $valor : null;
    }
}
