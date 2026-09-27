<?php

namespace App\Services\ImageSearch;

use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Models\GeocoderCounter;
use App\Models\User;
use App\Services\Traits\BusquedaDeImagenesEnGoogle;
use App\Services\Traits\GoogleSearchHelpers;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Búsqueda de imágenes con Google Custom Search, el proveedor de siempre, envuelto para las
 * asignaciones inteligentes (misión imagenes-catalogo-completo, 27/9/2026). Queda de respaldo:
 * se usa en las asignaciones por selección de los clientes que no tienen SERPER_API_KEY.
 *
 * No reimplementa nada: la llamada es `fetch_google_image_results()` del trait
 * BusquedaDeImagenesEnGoogle, con el Referer que la clave necesita (google_api_http()) y con las
 * credenciales del dueño (su clave propia o la de config, y el cx de ComercioCity) que decide
 * ImagenesAutomaticasHelper::credenciales(). Acá solo se normaliza `items[]` a la forma común.
 *
 * El trait necesita `$this->user_id`, `$this->google_api_key` y `$this->cx`: por eso están acá.
 */
class GoogleCustomSearchImageProvider implements ImageSearchProvider
{
    use GoogleSearchHelpers, BusquedaDeImagenesEnGoogle;

    /**
     * @var object|null El dispatcher de eventos donde ya está la escucha de respuestas (se anota UNA
     *                  vez por dispatcher: en un worker es uno solo; en los tests cambia con cada test).
     */
    protected static $dispatcher_con_escucha = null;

    /**
     * @var int|null Estado HTTP de la última respuesta de Custom Search, para el registro de
     *               consultas. El trait no lo devuelve: lo anota la escucha de ResponseReceived.
     */
    protected static $ultimo_estado_de_custom_search = null;

    /** @var int Dueño del comercio (lo pide el trait). */
    protected $user_id;

    /** @var string Clave de Custom Search del dueño o la de config (lo pide el trait). */
    protected $google_api_key;

    /** @var string Motor de búsqueda de ComercioCity (lo pide el trait). */
    protected $cx;

    /**
     * @param \App\Models\User $owner  Dueño del comercio.
     */
    public function __construct(User $owner)
    {
        $credenciales = ImagenesAutomaticasHelper::credenciales($owner);

        $this->user_id        = (int) $owner->id;
        $this->google_api_key = $credenciales['api_key'];
        $this->cx             = $credenciales['cx'];
    }

    /**
     * @return string
     */
    public function nombre()
    {
        return 'google';
    }

    /**
     * Busca imágenes en Custom Search. Ver el contrato en ImageSearchProvider::buscar().
     *
     * El contador que se le pasa al trait es un GeocoderCounter NUEVO y sin guardar: el trait lo
     * pide por firma, pero acá consumir_cuota() está anulado (ver abajo), así que nunca se toca ni
     * se guarda.
     *
     * @param  string $consulta
     * @return array
     */
    public function buscar($consulta)
    {
        // Para el registro de consultas (image_service_calls): el estado HTTP y cuánto tardó Google.
        self::escuchar_respuestas_de_custom_search();
        self::$ultimo_estado_de_custom_search = null;

        $inicio = microtime(true);

        $resultado = $this->fetch_google_image_results(trim((string) $consulta), new GeocoderCounter());

        $duracion_ms = (int) max(0, round((microtime(true) - $inicio) * 1000));
        $http_status = self::$ultimo_estado_de_custom_search;

        // items null = error HTTP o de red; api_error con items = Google respondió 200 con un
        // bloque `error` (el trait tampoco lo cuenta como búsqueda). Las dos cosas son un fallo.
        if (is_null($resultado['items']) || !is_null($resultado['api_error'])) {
            $error = $this->sin_credenciales((string) $resultado['api_error']);

            return [
                'ok'          => false,
                'error'       => 'Google respondió con error: '.($error !== '' ? $error : 'desconocido').'.',
                'resultados'  => [],
                'total'       => null,
                'http_status' => $http_status,
                'duracion_ms' => $duracion_ms,
            ];
        }

        $resultados = [];
        $posicion   = 0;

        foreach ($resultado['items'] as $item) {
            $posicion++;

            if (!is_array($item) || !isset($item['link']) || trim((string) $item['link']) === '') {
                continue;
            }

            $imagen = isset($item['image']) && is_array($item['image']) ? $item['image'] : [];

            $resultados[] = [
                'url'       => trim((string) $item['link']),
                'miniatura' => isset($imagen['thumbnailLink']) ? (string) $imagen['thumbnailLink'] : '',
                'ancho'     => isset($imagen['width']) && (int) $imagen['width'] > 0 ? (int) $imagen['width'] : null,
                'alto'      => isset($imagen['height']) && (int) $imagen['height'] > 0 ? (int) $imagen['height'] : null,
                'pagina'    => isset($imagen['contextLink']) ? (string) $imagen['contextLink'] : '',
                'dominio'   => isset($item['displayLink']) ? (string) $item['displayLink'] : '',
                'titulo'    => isset($item['title']) ? (string) $item['title'] : '',
                'posicion'  => $posicion,
            ];
        }

        return [
            'ok'          => true,
            'error'       => null,
            'resultados'  => $resultados,
            'total'       => $resultado['total_results'],
            'http_status' => $http_status,
            'duracion_ms' => $duracion_ms,
        ];
    }

    /**
     * Anota, una sola vez por dispatcher, una escucha del evento ResponseReceived del cliente HTTP
     * de Laravel que guarda el estado de cada respuesta de Custom Search.
     *
     * Por qué un evento y no un middleware de Guzzle sobre el cliente del trait: Laravel pone los
     * middlewares propios ADENTRO del que responde los Http::fake, así que en los tests nunca
     * correrían y lo que anotan quedaría sin probar. El evento sale igual con respuestas reales y
     * falsas. No toca el trait (lo usan el job viejo, el de categorías y la búsqueda por código).
     *
     * Nunca lanza: sin escucha, el registro queda sin estado HTTP y la búsqueda sigue igual.
     *
     * @return void
     */
    protected static function escuchar_respuestas_de_custom_search()
    {
        try {
            $dispatcher = app('events');

            if (self::$dispatcher_con_escucha === $dispatcher) {
                return;
            }

            self::$dispatcher_con_escucha = $dispatcher;

            $dispatcher->listen(ResponseReceived::class, function ($evento) {
                if (isset($evento->request, $evento->response)
                    && strpos((string) $evento->request->url(), 'googleapis.com/customsearch') !== false) {
                    self::$ultimo_estado_de_custom_search = (int) $evento->response->status();
                }
            });
        } catch (\Throwable $e) {
            self::$dispatcher_con_escucha = null;
        }
    }

    /**
     * El mensaje de error sin la clave ni el cx.
     *
     * 🔴 Un error de conexión o un timeout de Guzzle trae la URL COMPLETA del pedido ("cURL error
     * 28: ... for https://www.googleapis.com/customsearch/v1?key=AIza...&cx=..."), y el trait lo
     * devuelve tal cual. Ese texto termina en el diagnóstico del artículo, en el motivo de la
     * asignación y en el registro visible, que ve cualquier usuario del comercio: sin esto se le
     * mostraba la clave de Google, que es prácticamente una sola para toda la flota. El trait no se
     * toca (lo usan el job viejo y el de categorías), así que se limpia acá.
     *
     * @param  string $mensaje
     * @return string
     */
    protected function sin_credenciales($mensaje)
    {
        $mensaje = (string) preg_replace('/([?&](?:key|cx)=)[^&\s"\'<>]*/i', '$1***', (string) $mensaje);

        // Por si la clave aparece suelta en el texto (sin el ?key=).
        if ($this->google_api_key !== '') {
            $mensaje = str_replace($this->google_api_key, '***', $mensaje);
        }

        return $mensaje;
    }

    /**
     * 🔴 ANULADO A PROPÓSITO: en las asignaciones inteligentes el cupo diario lo descuenta el
     * MOTOR, una vez por búsqueda que respondió bien y solo si la asignación aplica el tope diario
     * (ArticleImageAssignmentEngine::descontar_del_cupo_diario()), igual para Serper que para
     * Google. Si el trait también descontara acá, cada búsqueda de Google contaría doble — y las
     * de "todo el catálogo", que no tienen que tocar el cupo del cliente, lo tocarían igual.
     *
     * El trait sigue descontando para quien lo usa directo (el job viejo de artículos, el de
     * categorías y la búsqueda por código de barras del asistente): esto solo pisa el método en
     * esta clase.
     *
     * @param  \App\Models\GeocoderCounter $counter
     * @return void
     */
    protected function consumir_cuota(GeocoderCounter $counter)
    {
    }
}
