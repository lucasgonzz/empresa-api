<?php

namespace App\Services;

use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Models\Article;
use App\Models\User;
use App\Services\Traits\BusquedaDeImagenesEnGoogle;
use App\Services\Traits\GoogleSearchHelpers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Las llamadas a afuera de la búsqueda de un producto por su código de barras (misión
 * asistente-fotos-barras-y-compras, 24/9/2026): Open Food Facts / Open Beauty Facts, Claude con
 * búsqueda web, las páginas de las fuentes (para su foto `og:image`), la validación por visión de
 * cada foto candidata y, como último recurso, la búsqueda de imágenes de Google que ya usa el
 * sistema.
 *
 * La orquestación (tope, "ya existe", dónde se guarda la foto, qué se le devuelve al modelo) vive en
 * BusquedaPorCodigoDeBarrasIaHelper. Acá solo hay red y parseo, para que el helper se lea de arriba
 * abajo como el pedido de Lucas.
 *
 * 🔴 TODO VA CON LA CLAVE DE ANTHROPIC DE LA PLATAFORMA (`services.anthropic.api_key`), nunca con la
 * del proveedor que el dueño eligió para el chat. La búsqueda web (`web_search_20250305`) es una
 * herramienta del servidor de Anthropic: si el dueño usa DeepSeek, mandarla por su proveedor no
 * daría error, daría una respuesta inventada. Mismo criterio que EscaneoFacturaCompraService.
 *
 * Reusa sin copiar: la validación GS1 y los clientes HTTP (GoogleSearchHelpers), la búsqueda de
 * imágenes de Google con su cuota diaria (BusquedaDeImagenesEnGoogle, que pide `$user_id`,
 * `$google_api_key` y `$cx`) y la validación por visión (ArticleImageValidationService).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class BusquedaPorCodigoDeBarrasService
{
    use GoogleSearchHelpers;
    use BusquedaDeImagenesEnGoogle;

    /**
     * Las dos bases abiertas que se consultan antes de gastar una búsqueda web, en este orden. Son
     * gratis y sin clave; alimentos y bebidas están bastante cubiertos, perfumería mucho menos (el
     * 24/9/2026 la cera Nic 7798111212032 no estaba en ninguna de las dos).
     */
    const BASES_ABIERTAS = [
        'open_food_facts'   => 'https://world.openfoodfacts.org/api/v2/product/',
        'open_beauty_facts' => 'https://world.openbeautyfacts.org/api/v2/product/',
    ];

    /**
     * User-Agent propio: Open Food Facts pide que cada app se identifique (una app anónima puede
     * quedar bloqueada). Para las páginas de las fuentes se usa uno de navegador, porque muchas
     * tiendas le devuelven 403 a un UA que no parece un navegador.
     */
    const USER_AGENT_PROPIO = 'ComercioCity/1.0 (asistente de carga; https://comerciocity.com)';

    const USER_AGENT_NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /** Lado mínimo de una foto candidata, en px: más chica no sirve para una tienda online. */
    const LADO_MINIMO = 300;

    /** Peso máximo de una foto candidata o de una página, en bytes (no se trae a memoria algo enorme). */
    const MAX_BYTES_IMAGEN = 8388608;

    const MAX_BYTES_PAGINA = 1572864;

    /** Cuántas páginas de fuentes se abren para buscar su `og:image`. Cada una es un GET de hasta 6 s. */
    const MAX_PAGINAS = 4;

    /**
     * Cuántas fotos se validan por visión como máximo en una búsqueda (Haiku, ~2 s cada una). Todo
     * esto corre adentro del turno del chat: el techo es tiempo de espera del dueño, no solo plata.
     */
    const MAX_VALIDACIONES = 4;

    /** Reenvíos ante `pause_turn` (la API corta un turno largo de búsqueda y hay que pedirle que siga). */
    const MAX_REENVIOS_PAUSE_TURN = 2;

    /** @var int Dueño del negocio: imputación del consumo y cuota de Google. */
    protected $user_id;

    /** @var string Key de Google Custom Search (la del dueño o la de config). */
    protected $google_api_key = '';

    /** @var string cx del motor de búsqueda de ComercioCity. */
    protected $cx = '';

    /** @var \App\Models\User|null */
    protected $owner;

    /** @var ArticleImageValidationService */
    protected $validador;

    /** @var int Validaciones por visión hechas en esta búsqueda. */
    protected $validaciones = 0;

    /** @var array<int, string> Por qué se descartó cada candidata (para el log, no para el dueño). */
    protected $descartes = [];

    /**
     * @param  \App\Models\User|null  $owner
     */
    public function __construct($owner)
    {
        $this->owner   = $owner;
        $this->user_id = is_null($owner) ? 0 : (int) $owner->id;

        if (! is_null($owner)) {
            $credenciales         = ImagenesAutomaticasHelper::credenciales($owner);
            $this->google_api_key = $credenciales['api_key'];
            $this->cx             = $credenciales['cx'];
        }

        $this->validador = new ArticleImageValidationService();
    }

    // ------------------------------------------------------------------ código

    /**
     * El código tal como lo tipeó o lo leyó el modelo, sin espacios, guiones ni puntos: debajo de
     * las barras los dígitos vienen agrupados ("7 798111 212032") y el modelo los copia así.
     *
     * @param  string  $codigo
     * @return string
     */
    public function normalizar($codigo)
    {
        return (string) preg_replace('/[\s\-\.]+/', '', $this->normalize_bar_code((string) $codigo));
    }

    /**
     * true si es un GTIN (8, 12, 13 o 14 dígitos) con el dígito verificador correcto. Es la misma
     * validación de la búsqueda automática de imágenes: un dígito mal leído de la foto casi siempre
     * rompe el verificador, y es mejor pedirle el código a la persona que buscar otro producto.
     *
     * @param  string  $normalizado
     * @return bool
     */
    public function es_valido($normalizado)
    {
        return $this->is_valid_product_bar_code((string) $normalizado);
    }

    // ------------------------------------------------------------------ bases abiertas

    /**
     * El producto en Open Food Facts o, si no está, en Open Beauty Facts.
     *
     * Una caída o un timeout de estas bases no es un error para el dueño: se sigue con la búsqueda
     * web como si no estuviera.
     *
     * @param  string  $ean
     * @return array|null  ['base' => string, 'url' => string, 'producto' => array, 'imagen_url' => string|null]
     */
    public function buscar_en_bases_abiertas($ean)
    {
        foreach (self::BASES_ABIERTAS as $base => $url_base) {

            try {
                $respuesta = $this->google_http()
                    ->timeout(5)
                    ->withHeaders(['User-Agent' => self::USER_AGENT_PROPIO])
                    ->get($url_base . $ean . '.json', [
                        'fields' => 'code,product_name,product_name_es,generic_name,generic_name_es,brands,quantity,categories,image_front_url,image_url',
                    ]);
            } catch (\Throwable $e) {
                Log::info('BusquedaPorCodigoDeBarras: ' . $base . ' no respondió -- ' . $e->getMessage());

                continue;
            }

            if (! $respuesta->successful()) {
                continue;
            }

            $cuerpo = $respuesta->json();

            if (! is_array($cuerpo) || (int) ($cuerpo['status'] ?? 0) !== 1 || ! isset($cuerpo['product']) || ! is_array($cuerpo['product'])) {
                continue;
            }

            $producto = $cuerpo['product'];

            /*
             * Una ficha sin nombre ni marca no identifica nada (OFF tiene miles de fichas creadas
             * con solo el código escaneado): se trata como "no está" y se sigue buscando.
             */
            if ($this->texto($producto, ['product_name_es', 'product_name']) === '' && $this->texto($producto, ['brands']) === '') {
                continue;
            }

            $imagen = $this->texto($producto, ['image_front_url', 'image_url']);

            return [
                'base'       => $base,
                'url'        => str_replace('/api/v2/product/', '/product/', $url_base) . $ean,
                'producto'   => $producto,
                'imagen_url' => $imagen === '' ? null : $imagen,
            ];
        }

        return null;
    }

    /**
     * Claude (sin búsqueda web) redacta el nombre comercial y la descripción EN ESPAÑOL a partir de
     * la ficha de la base abierta. Las fichas están en cualquier idioma y con nombres a medio
     * cargar; lo que se le devuelve al dueño tiene que ser lo que pondría en su tienda.
     *
     * Si la IA no responde, se arma el nombre con los datos crudos y la descripción queda null:
     * la ficha ya identificó el producto, no hay por qué perderla.
     *
     * @param  string  $ean
     * @param  array  $hallazgo  Lo que devolvió buscar_en_bases_abiertas().
     * @return array  ['nombre', 'marca', 'descripcion', 'body' (para el consumo) , 'modelo']
     */
    public function redactar_desde_base_abierta($ean, array $hallazgo)
    {
        $producto = $hallazgo['producto'];

        $crudo_nombre = trim($this->texto($producto, ['product_name_es', 'product_name']) . ' ' . $this->texto($producto, ['quantity']));
        $crudo_marca  = $this->primera_marca($this->texto($producto, ['brands']));

        $sin_ia = [
            'nombre'      => $crudo_nombre !== '' ? $crudo_nombre : null,
            'marca'       => $crudo_marca !== '' ? $crudo_marca : null,
            'descripcion' => null,
            'body'        => null,
            'modelo'      => null,
        ];

        if ($this->api_key() === '') {
            return $sin_ia;
        }

        $ficha = [];

        foreach (['product_name_es', 'product_name', 'generic_name_es', 'generic_name', 'brands', 'quantity', 'categories'] as $campo) {
            $valor = $this->texto($producto, [$campo]);

            if ($valor !== '') {
                $ficha[] = $campo . ': ' . mb_substr($valor, 0, 400);
            }
        }

        $usuario = implode("\n", [
            'Código de barras: ' . $ean,
            'Ficha de ' . ($hallazgo['base'] === 'open_beauty_facts' ? 'Open Beauty Facts' : 'Open Food Facts') . ':',
            implode("\n", $ficha),
            '',
            'Armá el JSON pedido usando SOLO estos datos.',
        ]);

        $body = $this->llamar_a_claude([
            'max_tokens' => 600,
            'system'     => $this->prompt_de_redaccion(),
            'messages'   => [['role' => 'user', 'content' => $usuario]],
        ], 30);

        if (is_null($body)) {
            return $sin_ia;
        }

        $json = $this->ultimo_json($this->texto_de_la_respuesta([$body]));

        if (is_null($json)) {
            return array_merge($sin_ia, ['body' => $body, 'modelo' => $this->modelo_de($body)]);
        }

        $nombre      = $this->texto($json, ['nombre']);
        $marca       = $this->texto($json, ['marca']);
        $descripcion = $this->texto($json, ['descripcion']);

        return [
            'nombre'      => $nombre !== '' ? $nombre : $sin_ia['nombre'],
            'marca'       => $marca !== '' ? $marca : $sin_ia['marca'],
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'body'        => $body,
            'modelo'      => $this->modelo_de($body),
        ];
    }

    // ------------------------------------------------------------------ búsqueda web

    /**
     * Claude Haiku con la herramienta de búsqueda web de Anthropic busca el código en internet y
     * devuelve nombre, marca, descripción en español y las páginas donde lo vio.
     *
     * 🔴 SIN `user_location`, a propósito (decisión de Lucas, 24/9/2026): busca en todo el mundo.
     * Un producto importado o de marca chica puede no estar en ninguna tienda argentina y sí en una
     * de afuera; la descripción igual sale en español porque lo exige el prompt.
     *
     * 🔴 `pause_turn`: con búsquedas largas la API corta el turno y devuelve lo que lleva; se le
     * reenvía la respuesta tal cual como turno del assistant para que siga (hasta dos veces).
     * Tratar ese corte como respuesta final daría "no lo encontré" con el producto a medio buscar.
     *
     * @param  string  $ean
     * @return array|null  null si no se pudo consultar (sin clave, sin red o error de la API).
     *                     ['nombre','marca','descripcion','fuentes','resultados','usage','modelo','busquedas']
     */
    public function buscar_en_la_web($ean)
    {
        if ($this->api_key() === '') {
            return null;
        }

        $max_busquedas = (int) config('services.asistente_ia.busqueda_codigo_barras.max_busquedas', 3);
        $timeout       = (int) config('services.asistente_ia.busqueda_codigo_barras.timeout', 60);

        $mensajes = [[
            'role'    => 'user',
            'content' => 'Código de barras: ' . $ean . "\n\n" .
                         'Buscá qué producto es y respondé con el JSON pedido.',
        ]];

        $cuerpos = [];

        for ($intento = 0; $intento <= self::MAX_REENVIOS_PAUSE_TURN; $intento++) {

            $body = $this->llamar_a_claude([
                'max_tokens' => 1500,
                'system'     => $this->prompt_de_busqueda_web(),
                'messages'   => $mensajes,
                'tools'      => [[
                    'type'     => 'web_search_20250305',
                    'name'     => 'web_search',
                    'max_uses' => $max_busquedas > 0 ? $max_busquedas : 3,
                ]],
            ], $timeout > 0 ? $timeout : 60);

            if (is_null($body)) {
                break;
            }

            $cuerpos[] = $body;

            if ((string) ($body['stop_reason'] ?? '') !== 'pause_turn') {
                break;
            }

            /* Se le devuelve lo que llevaba como turno del assistant, y la API retoma desde ahí. */
            $mensajes[] = [
                'role'    => 'assistant',
                'content' => isset($body['content']) && is_array($body['content']) ? $body['content'] : [],
            ];
        }

        if (empty($cuerpos)) {
            return null;
        }

        $json = $this->ultimo_json($this->texto_de_la_respuesta($cuerpos));

        $nombre = is_null($json) ? '' : $this->texto($json, ['nombre']);

        $fuentes = [];

        if (! is_null($json) && isset($json['fuentes']) && is_array($json['fuentes'])) {
            foreach ($json['fuentes'] as $fuente) {
                if (is_string($fuente) && preg_match('#^https?://#i', $fuente)) {
                    $fuentes[] = $fuente;
                }
            }
        }

        return [
            'nombre'      => $nombre !== '' ? $nombre : null,
            'marca'       => is_null($json) || $this->texto($json, ['marca']) === '' ? null : $this->texto($json, ['marca']),
            'descripcion' => is_null($json) || $this->texto($json, ['descripcion']) === '' ? null : $this->texto($json, ['descripcion']),
            'fuentes'     => array_values(array_unique($fuentes)),
            'resultados'  => $this->urls_de_los_resultados($cuerpos),
            'usage'       => $this->usage_sumado($cuerpos),
            'modelo'      => $this->modelo_de($cuerpos[count($cuerpos) - 1]),
            'busquedas'   => $this->busquedas_hechas($cuerpos),
        ];
    }

    // ------------------------------------------------------------------ foto

    /**
     * La primera foto candidata que pasa los filtros: se descarga, es una imagen de verdad, tiene
     * al menos LADO_MINIMO de lado y la validación por visión dice que es ese producto.
     *
     * Las candidatas vienen en orden de confianza: la de la base abierta primero (es la foto del
     * paquete), después la `og:image` de las fuentes que citó el modelo, después la de los demás
     * resultados. Una página que no responde o no tiene `og:image` simplemente no aporta.
     *
     * @param  array<int, string>  $urls_de_imagen  Fotos directas (la de la base abierta).
     * @param  array<int, string>  $paginas  Páginas de donde sacar la `og:image`, en orden.
     * @param  string  $nombre
     * @param  string  $ean
     * @param  string  $origen_directo  Cómo se nombra el origen de las fotos directas.
     * @return array|null  ['binario' => string, 'url' => string, 'origen' => string]
     */
    public function elegir_foto(array $urls_de_imagen, array $paginas, $nombre, $ean, $origen_directo = 'base_abierta')
    {
        foreach ($urls_de_imagen as $url) {
            $elegida = $this->probar_candidata($url, $nombre, $ean, $origen_directo);

            if (! is_null($elegida)) {
                return $elegida;
            }
        }

        $abiertas = 0;

        foreach ($paginas as $pagina) {
            if ($abiertas >= self::MAX_PAGINAS || $this->validaciones >= self::MAX_VALIDACIONES) {
                break;
            }

            $abiertas++;

            $imagen = $this->og_image_de($pagina);

            if (is_null($imagen)) {
                continue;
            }

            $elegida = $this->probar_candidata($imagen, $nombre, $ean, 'pagina:' . $this->dominio($pagina));

            if (! is_null($elegida)) {
                return $elegida;
            }
        }

        return null;
    }

    /**
     * El último recurso: la búsqueda de imágenes de Google que ya usa el sistema, primero por el
     * código y después por el nombre. Consume la cuota diaria de Google del dueño (la misma de las
     * imágenes automáticas del listado), así que si no le queda, ni se intenta.
     *
     * @param  string  $ean
     * @param  string  $nombre
     * @return array|null  ['binario','url','origen']
     */
    public function foto_de_google($ean, $nombre)
    {
        if (is_null($this->owner) || trim($this->google_api_key) === '') {
            return null;
        }

        $consultas = [$ean];

        if (trim((string) $nombre) !== '') {
            $consultas[] = (string) $nombre;
        }

        foreach ($consultas as $consulta) {
            if ($this->validaciones >= self::MAX_VALIDACIONES) {
                break;
            }

            if (ImagenesAutomaticasHelper::cuota_de($this->owner)['disponibles'] <= 0) {
                break;
            }

            $resultado = $this->fetch_google_image_results($consulta, $this->get_or_create_counter());

            if (! is_null($resultado['api_error']) || empty($resultado['items'])) {
                continue;
            }

            $probadas = 0;

            foreach ($resultado['items'] as $item) {
                if ($probadas >= 2 || $this->validaciones >= self::MAX_VALIDACIONES) {
                    break;
                }

                if (! isset($item['link']) || $this->validador->prefilter($item)['rejected']) {
                    continue;
                }

                $probadas++;

                $elegida = $this->probar_candidata((string) $item['link'], $nombre, $ean, 'google');

                if (! is_null($elegida)) {
                    return $elegida;
                }
            }
        }

        return null;
    }

    /**
     * Por qué se descartó cada candidata en esta búsqueda (para el log).
     *
     * @return array<int, string>
     */
    public function descartes()
    {
        return $this->descartes;
    }

    /**
     * Descarga una foto candidata y la pasa por los tres filtros (es imagen, tamaño, visión).
     *
     * @param  string  $url
     * @param  string  $nombre
     * @param  string  $ean
     * @param  string  $origen
     * @return array|null
     */
    protected function probar_candidata($url, $nombre, $ean, $origen)
    {
        if ($this->validaciones >= self::MAX_VALIDACIONES) {
            return null;
        }

        $binario = $this->descargar($url, self::MAX_BYTES_IMAGEN);

        if (is_null($binario)) {
            $this->descartes[] = $url . ': no se pudo descargar';

            return null;
        }

        $info = @getimagesizefromstring($binario);

        if ($info === false || ! isset($info[0], $info[1])) {
            $this->descartes[] = $url . ': no es una imagen';

            return null;
        }

        if (min((int) $info[0], (int) $info[1]) < self::LADO_MINIMO) {
            $this->descartes[] = $url . ': muy chica (' . (int) $info[0] . 'x' . (int) $info[1] . ')';

            return null;
        }

        /*
         * Un Article SIN GUARDAR con lo que se sabe del producto: es lo único que el validador
         * necesita para contrastar la foto (nombre y código). No se crea nada en la base.
         */
        $articulo           = new Article();
        $articulo->name     = trim((string) $nombre) !== '' ? (string) $nombre : 'Producto con código ' . $ean;
        $articulo->bar_code = $ean;

        $this->validaciones++;

        $veredicto = $this->validador->validate($binario, $articulo, $this->user_id);

        if (! $veredicto['accepted']) {
            $this->descartes[] = $url . ': la visión la rechazó (' . $veredicto['reason'] . ')';

            return null;
        }

        return ['binario' => $binario, 'url' => $url, 'origen' => $origen];
    }

    /**
     * La `og:image` (o `twitter:image`) de una página de producto, como URL absoluta. Es la foto que
     * la propia tienda eligió para mostrar el producto cuando se comparte el link: casi siempre la
     * foto de catálogo, con fondo limpio.
     *
     * @param  string  $pagina
     * @return string|null
     */
    protected function og_image_de($pagina)
    {
        $html = $this->descargar($pagina, self::MAX_BYTES_PAGINA, 6);

        if (is_null($html)) {
            $this->descartes[] = $pagina . ': la página no respondió';

            return null;
        }

        /* Solo la cabecera: las meta están en el <head> y el resto puede pesar un mega. */
        $cabecera = substr($html, 0, 300000);

        foreach (['og:image:secure_url', 'og:image', 'twitter:image'] as $propiedad) {
            $patron_1 = '/<meta[^>]+(?:property|name)\s*=\s*["\']' . preg_quote($propiedad, '/') . '["\'][^>]*content\s*=\s*["\']([^"\']+)["\']/i';
            $patron_2 = '/<meta[^>]+content\s*=\s*["\']([^"\']+)["\'][^>]*(?:property|name)\s*=\s*["\']' . preg_quote($propiedad, '/') . '["\']/i';

            if (preg_match($patron_1, $cabecera, $m) || preg_match($patron_2, $cabecera, $m)) {
                $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return $this->url_absoluta($url, $pagina);
            }
        }

        $this->descartes[] = $pagina . ': sin og:image';

        return null;
    }

    /**
     * GET con timeout corto y tope de bytes. Null si falla o si se pasa del tope.
     *
     * Sin `Referer` a propósito (google_http() y no google_api_http()): ver el docblock de
     * GoogleSearchHelpers::google_api_http(), muchos sitios bloquean el hotlink con un Referer ajeno.
     *
     * @param  string  $url
     * @param  int  $max_bytes
     * @param  int  $timeout
     * @return string|null
     */
    protected function descargar($url, $max_bytes, $timeout = 8)
    {
        if (! preg_match('#^https?://#i', (string) $url)) {
            return null;
        }

        try {
            $respuesta = $this->google_http()
                ->timeout($timeout)
                ->withHeaders([
                    'User-Agent'      => self::USER_AGENT_NAVEGADOR,
                    'Accept-Language' => 'es-AR,es;q=0.9,en;q=0.5',
                ])
                ->get((string) $url);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $respuesta->successful()) {
            return null;
        }

        $largo = (int) $respuesta->header('Content-Length');

        if ($largo > $max_bytes) {
            return null;
        }

        $cuerpo = (string) $respuesta->body();

        if ($cuerpo === '' || strlen($cuerpo) > $max_bytes) {
            return null;
        }

        return $cuerpo;
    }

    // ------------------------------------------------------------------ Anthropic

    /**
     * POST a `/v1/messages` con la clave de la plataforma. Null ante cualquier falla (se loguea):
     * quien llama decide qué significa no tener respuesta.
     *
     * @param  array  $payload  Sin `model`: se agrega acá desde config.
     * @param  int  $timeout
     * @return array|null  El body decodificado.
     */
    protected function llamar_a_claude(array $payload, $timeout)
    {
        $payload = array_merge(['model' => $this->modelo()], $payload);

        try {
            $respuesta = $this->anthropic_http()
                ->timeout($timeout)
                ->post($this->url_de_messages(), $payload);
        } catch (\Throwable $e) {
            Log::warning('BusquedaPorCodigoDeBarras: error de conexión con Anthropic', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $respuesta->successful()) {
            $body = $respuesta->json();

            Log::warning('BusquedaPorCodigoDeBarras: Anthropic respondió con error', [
                'status' => $respuesta->status(),
                'error'  => isset($body['error']['message']) ? $body['error']['message'] : null,
            ]);

            return null;
        }

        $body = $respuesta->json();

        return is_array($body) ? $body : null;
    }

    /**
     * @return string
     */
    protected function modelo()
    {
        $modelo = (string) config('services.asistente_ia.busqueda_codigo_barras.model', '');

        return $modelo !== '' ? $modelo : 'claude-haiku-4-5-20251001';
    }

    /**
     * @return string
     */
    protected function api_key()
    {
        return trim((string) config('services.anthropic.api_key'));
    }

    /**
     * La URL de messages sobre la base de Anthropic de config (no la del proveedor del chat).
     *
     * @return string
     */
    protected function url_de_messages()
    {
        $base = trim((string) config('services.anthropic.base_url'));

        return rtrim($base !== '' ? $base : 'https://api.anthropic.com', '/') . '/v1/messages';
    }

    /**
     * Cliente hacia Anthropic con TLS del entorno. Mismo patrón que
     * EscaneoFacturaCompraService::build_anthropic_http_client().
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function anthropic_http()
    {
        $http = Http::withHeaders([
            'x-api-key'         => $this->api_key(),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ]);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * System prompt de la búsqueda web.
     *
     * 🔴 El párrafo de "nombre null" es el núcleo: un EAN mal leído o poco conocido devuelve
     * resultados de OTRO producto, y un modelo complaciente lo daría por bueno. Un alta con el
     * producto equivocado es peor que preguntarle el nombre a la persona.
     *
     * @return string
     */
    protected function prompt_de_busqueda_web()
    {
        return implode("\n", [
            'Identificás productos de comercio minorista por su código de barras (EAN/GTIN) para cargarlos en el catálogo de un negocio argentino.',
            'Buscá en internet el código EXACTO que te pasan (probá el número solo y, si hace falta, con palabras como "producto" o "ean"). Podés usar fuentes de cualquier país.',
            '',
            'Al terminar, respondé ÚNICAMENTE con un objeto JSON, sin texto antes ni después y sin backticks:',
            '{"nombre": "...", "marca": "...", "descripcion": "...", "fuentes": ["https://..."]}',
            '',
            '- "nombre": el nombre comercial con el que se vende, en español: tipo de producto + marca + variante + contenido neto (ejemplo: "Cera Modeladora Efecto Mate Nic Modeleitor 90 g"). Sin el código de barras y sin precio.',
            '- "marca": la marca, o null si no la sabés.',
            '- "descripcion": SIEMPRE EN ESPAÑOL, aunque las fuentes estén en otro idioma (traducila). De 2 a 4 oraciones para la ficha de una tienda online: qué es, para qué sirve y sus características. Solo con datos que viste en las fuentes: no inventes beneficios, ingredientes ni medidas. Sin precios, sin nombres de tiendas y sin links.',
            '- "fuentes": las URLs de las páginas de producto donde viste ESE código o ese producto (las mejores primero, hasta 5).',
            '',
            'Si ninguna fuente confirma qué producto es ese código, o las fuentes hablan de productos distintos, devolvé "nombre": null. No adivines: un producto equivocado es peor que ninguno.',
        ]);
    }

    /**
     * System prompt de la redacción a partir de la ficha de una base abierta.
     *
     * @return string
     */
    protected function prompt_de_redaccion()
    {
        return implode("\n", [
            'Te paso la ficha de un producto de una base de datos abierta (puede estar en cualquier idioma o incompleta). Armá los datos para cargarlo en el catálogo de un negocio argentino.',
            '',
            'Respondé ÚNICAMENTE con un objeto JSON, sin texto antes ni después y sin backticks:',
            '{"nombre": "...", "marca": "...", "descripcion": "..."}',
            '',
            '- "nombre": el nombre comercial en español: tipo de producto + marca + variante + contenido neto (ejemplo: "Aceite de Girasol Cocinero 1,5 L").',
            '- "marca": la marca, o null.',
            '- "descripcion": SIEMPRE EN ESPAÑOL, de 1 a 3 oraciones para la ficha de una tienda online, solo con lo que dice la ficha (no inventes). null si la ficha no alcanza para decir nada.',
        ]);
    }

    // ------------------------------------------------------------------ parseo

    /**
     * El texto de todos los bloques `text` de una o varias respuestas, en orden.
     *
     * @param  array<int, array>  $cuerpos
     * @return string
     */
    protected function texto_de_la_respuesta(array $cuerpos)
    {
        $texto = '';

        foreach ($cuerpos as $cuerpo) {
            if (! isset($cuerpo['content']) || ! is_array($cuerpo['content'])) {
                continue;
            }

            foreach ($cuerpo['content'] as $bloque) {
                if (is_array($bloque) && ($bloque['type'] ?? '') === 'text' && isset($bloque['text'])) {
                    $texto .= (string) $bloque['text'];
                }
            }
        }

        return $texto;
    }

    /**
     * El ÚLTIMO objeto JSON balanceado del texto que decodifica a un array.
     *
     * Con búsqueda web el modelo suele escribir algo ("Voy a buscar…") antes de buscar, y la
     * respuesta viene partida en varios bloques con citas: el JSON pedido es lo último, no lo
     * primero. Se recorre de atrás para adelante probando cada `{` como inicio.
     *
     * @param  string  $texto
     * @return array|null
     */
    protected function ultimo_json($texto)
    {
        $texto = (string) $texto;
        $fin   = strrpos($texto, '}');

        if ($fin === false) {
            return null;
        }

        $inicio = strrpos(substr($texto, 0, $fin), '{');

        while ($inicio !== false) {
            $candidato = substr($texto, $inicio, $fin - $inicio + 1);
            $decodificado = json_decode($candidato, true);

            if (is_array($decodificado) && array_key_exists('nombre', $decodificado)) {
                return $decodificado;
            }

            if ($inicio === 0) {
                break;
            }

            $inicio = strrpos(substr($texto, 0, $inicio), '{');
        }

        return null;
    }

    /**
     * Las URLs de todos los resultados de búsqueda que vio el modelo, sin repetir.
     *
     * @param  array<int, array>  $cuerpos
     * @return array<int, string>
     */
    protected function urls_de_los_resultados(array $cuerpos)
    {
        $urls = [];

        foreach ($cuerpos as $cuerpo) {
            foreach ((isset($cuerpo['content']) && is_array($cuerpo['content']) ? $cuerpo['content'] : []) as $bloque) {
                if (! is_array($bloque) || ($bloque['type'] ?? '') !== 'web_search_tool_result') {
                    continue;
                }

                if (! isset($bloque['content']) || ! is_array($bloque['content'])) {
                    continue;
                }

                foreach ($bloque['content'] as $resultado) {
                    if (is_array($resultado) && isset($resultado['url']) && is_string($resultado['url'])) {
                        $urls[] = $resultado['url'];
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * El `usage` de varias respuestas sumado en uno: el consumo de una búsqueda se registra como UNA
     * fila aunque haya habido reenvíos por `pause_turn`, porque el tope cuenta búsquedas, no llamadas.
     *
     * @param  array<int, array>  $cuerpos
     * @return array<string, int>
     */
    protected function usage_sumado(array $cuerpos)
    {
        $total = [
            'input_tokens'                => 0,
            'output_tokens'               => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens'     => 0,
        ];

        foreach ($cuerpos as $cuerpo) {
            foreach (array_keys($total) as $clave) {
                $total[$clave] += isset($cuerpo['usage'][$clave]) ? (int) $cuerpo['usage'][$clave] : 0;
            }
        }

        return $total;
    }

    /**
     * Cuántas búsquedas web hizo de verdad (lo informa la API en usage.server_tool_use).
     *
     * @param  array<int, array>  $cuerpos
     * @return int
     */
    protected function busquedas_hechas(array $cuerpos)
    {
        $total = 0;

        foreach ($cuerpos as $cuerpo) {
            $total += isset($cuerpo['usage']['server_tool_use']['web_search_requests'])
                ? (int) $cuerpo['usage']['server_tool_use']['web_search_requests']
                : 0;
        }

        return $total;
    }

    /**
     * @param  array  $body
     * @return string
     */
    protected function modelo_de(array $body)
    {
        return isset($body['model']) && (string) $body['model'] !== '' ? (string) $body['model'] : $this->modelo();
    }

    /**
     * El primer valor no vacío de esas claves, como string recortado.
     *
     * @param  array  $datos
     * @param  array<int, string>  $claves
     * @return string
     */
    protected function texto(array $datos, array $claves)
    {
        foreach ($claves as $clave) {
            if (isset($datos[$clave]) && is_scalar($datos[$clave]) && trim((string) $datos[$clave]) !== '') {
                return trim((string) $datos[$clave]);
            }
        }

        return '';
    }

    /**
     * OFF guarda las marcas separadas por coma ("Cocinero,Molinos"): la primera es la del producto.
     *
     * @param  string  $marcas
     * @return string
     */
    protected function primera_marca($marcas)
    {
        $partes = explode(',', (string) $marcas);

        return trim($partes[0]);
    }

    /**
     * @param  string  $url
     * @return string
     */
    protected function dominio($url)
    {
        $host = (string) parse_url((string) $url, PHP_URL_HOST);

        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * Una URL de `og:image` relativa ("/img/a.jpg", "//cdn/a.jpg") pasada a absoluta.
     *
     * @param  string  $url
     * @param  string  $pagina
     * @return string|null
     */
    protected function url_absoluta($url, $pagina)
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $esquema = (string) parse_url($pagina, PHP_URL_SCHEME);
        $host    = (string) parse_url($pagina, PHP_URL_HOST);

        if ($esquema === '' || $host === '') {
            return null;
        }

        if (substr($url, 0, 2) === '//') {
            return $esquema . ':' . $url;
        }

        if (substr($url, 0, 1) === '/') {
            return $esquema . '://' . $host . $url;
        }

        return null;
    }
}
