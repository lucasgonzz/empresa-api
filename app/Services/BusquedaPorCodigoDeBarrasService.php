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
     * Eran 4 y el chequeo del 24/9/2026 lo bajó a 2 junto con el presupuesto de abajo.
     */
    const MAX_VALIDACIONES = 2;

    /** Reenvíos ante `pause_turn` (la API corta un turno largo de búsqueda y hay que pedirle que siga). */
    const MAX_REENVIOS_PAUSE_TURN = 2;

    /**
     * 🔴 PRESUPUESTO TOTAL DE LA HERRAMIENTA, en segundos. La herramienta corre ADENTRO del loop del
     * chat, que tiene su propia cadena de techos: el job de respuesta muere a los 300 s, el loop
     * corta por su PRESUPUESTO_SEGUNDOS y un request del MCP espera una respuesta sincrónica. Sin
     * techo propio, el peor caso de esta herramienta sola (tres búsquedas web de 60 s + cuatro
     * páginas + las validaciones por visión + Google) se comía el turno entero y el dueño se quedaba
     * sin respuesta. Con 60 s entra holgado: las dos corridas reales del 24/9/2026 tardaron 13,6 s
     * (búsqueda web + foto de una tienda) y 17,8 s (Open Food Facts + su foto, que es lenta).
     *
     * Cada llamada toma su timeout de lo que queda (timeout_para()); cuando no alcanza para una
     * llamada más, se devuelve lo que haya: los datos sin foto antes que nada.
     */
    const PRESUPUESTO_SEGUNDOS = 60;

    /** Menos de esto no alcanza para ninguna llamada útil: se deja de intentar. */
    const SEGUNDOS_MINIMOS_POR_LLAMADA = 3;

    /** Saltos de redirección que se siguen a mano, validando cada destino (ver descargar()). */
    const MAX_REDIRECCIONES = 3;

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

    /** @var float microtime en que se acaba el presupuesto de esta búsqueda. */
    protected $vence_en;

    /**
     * @param  \App\Models\User|null  $owner
     */
    public function __construct($owner)
    {
        /* El reloj arranca acá: el helper crea un servicio por búsqueda. */
        $this->vence_en = microtime(true) + self::PRESUPUESTO_SEGUNDOS;

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

            $timeout = $this->timeout_para(5);

            if (is_null($timeout)) {
                break;
            }

            try {
                $respuesta = $this->google_http()
                    ->timeout($timeout)
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
                'imagen_url' => $imagen === '' ? null : $this->foto_grande_de_base_abierta($imagen),
            ];
        }

        return null;
    }

    /**
     * La foto de una base abierta en su resolución original.
     *
     * La ficha trae la miniatura de 400 px de lado MAYOR (`front_es.3.400.jpg`), y en un producto
     * alto —una botella— el lado menor queda en ~170 px y no pasa el filtro de LADO_MINIMO: el
     * 24/9/2026 el aceite Cocinero (7790070012050) se quedaba sin foto por eso. La misma foto en
     * `.full.jpg` es la original (498x1200 en ese caso).
     *
     * @param  string  $url
     * @return string
     */
    protected function foto_grande_de_base_abierta($url)
    {
        return (string) preg_replace('#\.(\d+)\.(\d+)\.jpg$#i', '.$1.full.jpg', (string) $url);
    }

    /**
     * Timeout de la descarga de una foto: las de las bases abiertas tardan mucho más que cualquier
     * tienda. Medido el 24/9/2026 desde esta máquina: images.openfoodfacts.org tardó 15 a 25 s en
     * empezar a mandar, la misma foto, cinco veces seguidas; con los 8 s de siempre no llegaba nunca.
     *
     * @param  string  $url
     * @return int
     */
    protected function timeout_de_descarga($url)
    {
        $host = (string) parse_url((string) $url, PHP_URL_HOST);

        if (preg_match('/(openfoodfacts|openbeautyfacts)\.org$/i', $host)) {
            return 30;
        }

        return 8;
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
            if (! $this->queda_tiempo()) {
                return null;
            }

            $elegida = $this->probar_candidata($url, $nombre, $ean, $origen_directo);

            if (! is_null($elegida)) {
                return $elegida;
            }
        }

        $abiertas = 0;

        foreach ($paginas as $pagina) {
            if ($abiertas >= self::MAX_PAGINAS || $this->validaciones >= self::MAX_VALIDACIONES || ! $this->queda_tiempo()) {
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
            /*
             * La búsqueda de Google tiene su timeout fijo de 15 s adentro del trait (compartido con
             * el job de imágenes): solo se la lanza si queda para ella y para validar una foto.
             */
            if ($this->validaciones >= self::MAX_VALIDACIONES || $this->segundos_restantes() < 20) {
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

        $binario = $this->descargar($url, 'imagen', $this->timeout_de_descarga($url));

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

        $timeout_vision = $this->timeout_para((int) config('services.article_image_validation.timeout', 25));

        if (is_null($timeout_vision)) {
            $this->descartes[] = $url . ': sin presupuesto para validarla';

            return null;
        }

        $this->validaciones++;

        /*
         * ArticleImageValidationService lee su timeout de config y no lo recibe por parámetro (lo
         * comparte con el job de imágenes). Se lo recorta a lo que queda del presupuesto solo
         * durante esta llamada y se restaura siempre: el mismo proceso (un worker) sigue corriendo
         * después otros jobs que tienen que ver el valor de siempre.
         */
        $timeout_original = config('services.article_image_validation.timeout');
        config(['services.article_image_validation.timeout' => $timeout_vision]);

        try {
            $veredicto = $this->validador->validate($binario, $articulo, $this->user_id);
        } finally {
            config(['services.article_image_validation.timeout' => $timeout_original]);
        }

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
        $html = $this->descargar($pagina, 'pagina', 6);

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
     * GET de una URL que NO es fija (una página fuente o una foto), con todas las guardas. Null si
     * no se pudo o no se debía traer; el motivo queda en descartes().
     *
     * 🔴 SSRF. Estas URLs las eligió alguien de afuera: salen de los resultados de una búsqueda web
     * y de la `og:image` de una página, que la controla el dueño de esa página. Sin guardas, una
     * página con `og:image` apuntando a `http://169.254.169.254/...` (la metadata del VPS) o a un
     * servicio interno hacía que ESTE servidor le pegara a su propia red (chequeo adversarial del
     * 24/9/2026). Por eso, en cada salto:
     *   - solo http/https y puerto 80/443 (url_permitida());
     *   - el host se resuelve ACÁ y si alguna de sus IPs es privada, reservada, loopback o
     *     link-local, no se sale (ip_publica());
     *   - la conexión se fija a la IP ya validada con CURLOPT_RESOLVE, para que un DNS que cambia
     *     entre la validación y el GET (DNS rebinding) no la lleve a otro lado;
     *   - las redirecciones NO las sigue Guzzle: se siguen a mano, hasta MAX_REDIRECCIONES, y cada
     *     Location pasa por la misma validación.
     *
     * Y el cuerpo se lee en streaming hasta el tope de bytes, después de mirar el Content-Type: una
     * "foto" de 2 GB o un HTML que nunca termina no se traen a memoria enteros.
     *
     * Sin `Referer` a propósito (google_http() y no google_api_http()): ver el docblock de
     * GoogleSearchHelpers::google_api_http(), muchos sitios bloquean el hotlink con un Referer ajeno.
     *
     * @param  string  $url
     * @param  string  $tipo  'imagen' o 'pagina'.
     * @param  int  $timeout_maximo  Segundos, antes de recortarlo al presupuesto.
     * @return string|null
     */
    protected function descargar($url, $tipo, $timeout_maximo)
    {
        $actual = (string) $url;

        for ($salto = 0; $salto <= self::MAX_REDIRECCIONES; $salto++) {

            /* La URL que se pide lleva el MISMO host que se valida y se fija (sin punto final). */
            $actual = $this->sin_punto_final_en_el_host($actual);

            $ip = $this->url_permitida($actual);

            if (is_null($ip)) {
                $this->descartes[] = $actual . ': destino no permitido';

                return null;
            }

            $timeout = $this->timeout_para($timeout_maximo);

            if (is_null($timeout)) {
                $this->descartes[] = $actual . ': sin presupuesto de tiempo';

                return null;
            }

            try {
                $respuesta = $this->google_http()
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream'          => true,
                        'curl'            => [CURLOPT_RESOLVE => [$this->regla_de_resolucion($actual, $ip)]],
                    ])
                    ->timeout($timeout)
                    ->withHeaders([
                        'User-Agent'      => self::USER_AGENT_NAVEGADOR,
                        'Accept-Language' => 'es-AR,es;q=0.9,en;q=0.5',
                    ])
                    ->get($actual);
            } catch (\Throwable $e) {
                return null;
            }

            $estado = (int) $respuesta->status();

            if (in_array($estado, [301, 302, 303, 307, 308], true)) {
                $destino = trim((string) $respuesta->header('Location'));
                $this->cerrar($respuesta);

                $actual = $destino === '' ? null : $this->url_absoluta($destino, $actual);

                if (is_null($actual)) {
                    return null;
                }

                continue;
            }

            if (! $respuesta->successful()) {
                $this->cerrar($respuesta);

                return null;
            }

            $tipo_de_contenido = strtolower((string) $respuesta->header('Content-Type'));
            $es_del_tipo = $tipo === 'imagen'
                ? strpos($tipo_de_contenido, 'image/') === 0
                : (strpos($tipo_de_contenido, 'text/html') === 0 || strpos($tipo_de_contenido, 'application/xhtml+xml') === 0);

            if (! $es_del_tipo) {
                $this->descartes[] = $actual . ': Content-Type "' . $tipo_de_contenido . '" no es ' . $tipo;
                $this->cerrar($respuesta);

                return null;
            }

            return $this->leer_con_tope($respuesta, $this->tope_de_bytes($tipo), $actual);
        }

        $this->descartes[] = (string) $url . ': demasiadas redirecciones';

        return null;
    }

    /**
     * El cuerpo leído de a pedazos, cortando apenas pasa el tope. Null si lo pasa o viene vacío.
     *
     * @param  \Illuminate\Http\Client\Response  $respuesta
     * @param  int  $max_bytes
     * @param  string  $url  Para el descarte.
     * @return string|null
     */
    protected function leer_con_tope($respuesta, $max_bytes, $url)
    {
        if ((int) $respuesta->header('Content-Length') > $max_bytes) {
            $this->descartes[] = $url . ': pesa más de ' . $max_bytes . ' bytes';
            $this->cerrar($respuesta);

            return null;
        }

        try {
            $cuerpo = $respuesta->toPsrResponse()->getBody();
            $leido  = '';

            while (! $cuerpo->eof()) {
                $leido .= $cuerpo->read(65536);

                if (strlen($leido) > $max_bytes) {
                    $cuerpo->close();
                    $this->descartes[] = $url . ': pesa más de ' . $max_bytes . ' bytes';

                    return null;
                }
            }

            $cuerpo->close();
        } catch (\Throwable $e) {
            return null;
        }

        return $leido === '' ? null : $leido;
    }

    /**
     * Cierra el stream de una respuesta que no se va a leer (libera la conexión).
     *
     * @param  \Illuminate\Http\Client\Response  $respuesta
     * @return void
     */
    protected function cerrar($respuesta)
    {
        try {
            $respuesta->toPsrResponse()->getBody()->close();
        } catch (\Throwable $e) {
            // Nada que hacer: la conexión se libera sola al terminar el proceso.
        }
    }

    /**
     * Tope de bytes por tipo. Método y no constante suelta para que un test pueda achicarlo sin
     * fabricar una foto de 8 MB.
     *
     * @param  string  $tipo
     * @return int
     */
    protected function tope_de_bytes($tipo)
    {
        return $tipo === 'imagen' ? self::MAX_BYTES_IMAGEN : self::MAX_BYTES_PAGINA;
    }

    /**
     * Si se puede salir a esa URL, la IP (ya validada) a la que hay que conectarse; si no, null.
     *
     * Público porque es la regla de seguridad de toda la herramienta y se prueba directo.
     *
     * @param  string  $url
     * @return string|null
     */
    public function url_permitida($url)
    {
        $partes = @parse_url((string) $url);

        if (! is_array($partes) || ! isset($partes['scheme'], $partes['host'])) {
            return null;
        }

        if (! in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {
            return null;
        }

        /* Un usuario:clave en la URL solo sirve para confundir a quien la lee ("https://tienda@10.0.0.1"). */
        if (isset($partes['user']) || isset($partes['pass'])) {
            return null;
        }

        if (isset($partes['port']) && ! in_array((int) $partes['port'], [80, 443], true)) {
            return null;
        }

        $host = $this->host_normalizado((string) $partes['host']);

        if ($host === '' || $host === 'localhost' || substr($host, -10) === '.localhost') {
            return null;
        }

        /* Un host que ya es una IP (literal) no se resuelve: se valida tal cual. */
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->ip_publica($host) ? $host : null;
        }

        $ips = $this->resolver_host($host);

        if (empty($ips)) {
            return null;
        }

        /* ALGUNA IP interna alcanza para rechazar: no se elige "la buena" entre varias. */
        foreach ($ips as $ip) {
            if (! $this->ip_publica($ip)) {
                return null;
            }
        }

        return (string) $ips[0];
    }

    /**
     * Las IPs de un host (A y AAAA). Protegido para que los tests simulen la resolución sin DNS.
     *
     * @param  string  $host
     * @return array<int, string>
     */
    protected function resolver_host($host)
    {
        $ips = [];

        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $ips = $v4;
        }

        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);

            if (is_array($aaaa)) {
                foreach ($aaaa as $registro) {
                    if (isset($registro['ipv6'])) {
                        $ips[] = (string) $registro['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * true si la IP es pública: ni privada, ni reservada, ni loopback, ni link-local, ni CGNAT.
     *
     * 🔴 SE DECIDE SOBRE LOS 16 BYTES (inet_pton), NUNCA SOBRE EL TEXTO. Una misma IPv4 interna se
     * puede escribir como IPv6 de muchas formas: `::ffff:127.0.0.1`, `::ffff:7f00:1`,
     * `0:0:0:0:0:ffff:a9fe:a9fe` (169.254.169.254), `::7f00:1`, `64:ff9b::7f00:1` (NAT64) o
     * `2002:7f00:1::` (6to4). El segundo chequeo adversarial del 24/9/2026 pasó todas esas por la
     * versión anterior, que solo reconocía `::ffff:` seguido de la forma con puntos — y en PHP 7.4
     * los flags de filter_var no marcan ::ffff:0:0/96. Acá cada prefijo que envuelve una IPv4 se
     * desenvuelve y la IPv4 pasa por la MISMA regla (ipv4_publica()).
     *
     * @param  string  $ip
     * @return bool
     */
    public function ip_publica($ip)
    {
        $binario = @inet_pton((string) $ip);

        if ($binario === false) {
            return false;
        }

        if (strlen($binario) === 4) {
            return $this->ipv4_publica(inet_ntop($binario));
        }

        if (strlen($binario) !== 16) {
            return false;
        }

        $doce = substr($binario, 0, 12);

        /* ::/128 (sin especificar) y ::1 (loopback). */
        if ($binario === str_repeat("\0", 16) || $binario === str_repeat("\0", 15) . "\1") {
            return false;
        }

        /*
         * Los prefijos que llevan una IPv4 en los últimos 4 bytes: ::ffff:0:0/96 (mapeada),
         * ::/96 (compatible, obsoleta pero curl la acepta) y 64:ff9b::/96 (NAT64 bien conocido).
         */
        if ($doce === str_repeat("\0", 10) . "\xff\xff"
            || $doce === str_repeat("\0", 12)
            || $doce === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return $this->ipv4_publica(inet_ntop(substr($binario, 12, 4)));
        }

        /* 2002::/16 (6to4): la IPv4 va en los bytes 2 a 5. */
        if (substr($binario, 0, 2) === "\x20\x02") {
            return $this->ipv4_publica(inet_ntop(substr($binario, 2, 4)));
        }

        /*
         * Teredo (2001:0::/32) y el NAT64 de uso local (64:ff9b:1::/48) también envuelven una IPv4,
         * ofuscada o con prefijo propio: no hay tienda que publique su foto ahí, se rechazan enteros.
         */
        if (substr($binario, 0, 4) === "\x20\x01\x00\x00" || substr($binario, 0, 6) === "\x00\x64\xff\x9b\x00\x01") {
            return false;
        }

        $b0 = ord($binario[0]);
        $b1 = ord($binario[1]);

        /*
         * fc00::/7 (únicas locales), fe80::/10 (link-local), fec0::/10 (site-local, obsoleta pero
         * enrutable adentro de una red) y ff00::/8 (multicast).
         */
        if (($b0 & 0xfe) === 0xfc
            || ($b0 === 0xfe && ($b1 & 0xc0) === 0x80)
            || ($b0 === 0xfe && ($b1 & 0xc0) === 0xc0)
            || $b0 === 0xff) {
            return false;
        }

        /* Y lo que los flags sepan de más, sobre la forma canónica. */
        return filter_var(inet_ntop($binario), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * true si la IPv4 es pública. La lista va explícita aunque repita lo que cubren los flags de
     * filter_var, para que no dependa de la versión de PHP, y suma 100.64.0.0/10 (CGNAT), que los
     * flags no cubren.
     *
     * @param  string|false  $ip
     * @return bool
     */
    protected function ipv4_publica($ip)
    {
        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $redes = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
            '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4',
            '240.0.0.0/4',
        ];

        foreach ($redes as $red) {
            if ($this->ipv4_en_red($ip, $red)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string  $ip
     * @param  string  $cidr
     * @return bool
     */
    protected function ipv4_en_red($ip, $cidr)
    {
        list($red, $bits) = explode('/', $cidr);

        $mascara = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return (ip2long($ip) & $mascara) === (ip2long($red) & $mascara);
    }

    /**
     * El host como se valida y se fija: minúsculas, sin corchetes y SIN PUNTO FINAL.
     *
     * `tienda.example.` (con el punto del nombre absoluto) resuelve igual que sin él, pero para
     * curl es otro nombre: si la regla de CURLOPT_RESOLVE dijera uno y la URL el otro, el pin no
     * aplicaría y curl resolvería por su cuenta — justo el hueco que el pin tapa.
     *
     * @param  string  $host
     * @return string
     */
    protected function host_normalizado($host)
    {
        return rtrim(strtolower(trim((string) $host, '[]')), '.');
    }

    /**
     * La URL con el host sin punto final (ver host_normalizado()), para que lo que curl busca sea
     * exactamente lo que se fijó. Si no hay nada que sacar, la misma URL.
     *
     * @param  string  $url
     * @return string
     */
    protected function sin_punto_final_en_el_host($url)
    {
        $host = (string) parse_url((string) $url, PHP_URL_HOST);

        if ($host === '' || substr($host, -1) !== '.') {
            return (string) $url;
        }

        $posicion = strpos((string) $url, '//' . $host);

        if ($posicion === false) {
            return (string) $url;
        }

        return substr((string) $url, 0, $posicion + 2) . rtrim($host, '.') . substr((string) $url, $posicion + 2 + strlen($host));
    }

    /**
     * La entrada de CURLOPT_RESOLVE ("host:puerto:ip") que ata la conexión a la IP validada.
     *
     * @param  string  $url
     * @param  string  $ip
     * @return string
     */
    protected function regla_de_resolucion($url, $ip)
    {
        $partes = parse_url($url);
        $host   = $this->host_normalizado((string) $partes['host']);
        $puerto = isset($partes['port'])
            ? (int) $partes['port']
            : (strtolower((string) $partes['scheme']) === 'https' ? 443 : 80);

        $ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;

        return $host . ':' . $puerto . ':' . $ip;
    }

    // ------------------------------------------------------------------ presupuesto

    /**
     * Segundos que le quedan al presupuesto de esta búsqueda.
     *
     * @return float
     */
    protected function segundos_restantes()
    {
        return $this->vence_en - microtime(true);
    }

    /**
     * true si todavía alcanza para una llamada más.
     *
     * @return bool
     */
    protected function queda_tiempo()
    {
        return ! is_null($this->timeout_para(self::SEGUNDOS_MINIMOS_POR_LLAMADA));
    }

    /**
     * El timeout de una llamada: el pedido, recortado a lo que queda (menos un segundo de margen
     * para lo que se hace después). Null si ya no alcanza para una llamada útil.
     *
     * @param  int  $maximo
     * @return int|null
     */
    protected function timeout_para($maximo)
    {
        $disponible = (int) floor($this->segundos_restantes()) - 1;

        if ($disponible < self::SEGUNDOS_MINIMOS_POR_LLAMADA) {
            return null;
        }

        return max(self::SEGUNDOS_MINIMOS_POR_LLAMADA, min((int) $maximo, $disponible));
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
        /* El timeout pedido, recortado a lo que queda del presupuesto de la herramienta. */
        $timeout = $this->timeout_para($timeout);

        if (is_null($timeout)) {
            Log::info('BusquedaPorCodigoDeBarras: sin presupuesto para otra llamada a Anthropic');

            return null;
        }

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
