<?php

namespace App\Services;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\Article;
use App\Models\ImageServiceCall;
use App\Services\ImageAssignment\ImageServiceCallLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;

/**
 * Valida si una imagen encontrada por Google Custom Search es realmente una foto del
 * producto de un articulo, antes de que ProcessArticleBatchImagesJob la asigne.
 *
 * Hoy el job solo mira el aspect ratio de los metadatos de Google y NUNCA descarta nada: una
 * captura de una lista de precios en PDF, si es mas o menos cuadrada, entra igual como "high".
 * Este servicio agrega dos capas de validacion:
 *
 * 1. prefilter(): heuristica de texto gratuita (sin llamar a ninguna API) que descarta items
 *    cuyo titulo/snippet/link delatan que no son una foto de producto (listas de precios,
 *    catalogos en PDF, etc).
 * 2. validate(): validacion real por vision, mandandole la imagen (redimensionada) a Claude
 *    junto con los datos del articulo, para que confirme si corresponde.
 *
 * Limite honesto (decidido a proposito, no es un bug): esto NO confirma el SKU exacto. Si el
 * articulo es "TORNILLO 3X20", el modelo va a aceptar cualquier tornillo razonable; no lee
 * codigos de barra en la foto. Lo que resuelve es el caso real de Lucas: descartar capturas de
 * listas de precios, catalogos, logos o productos de otra categoria.
 *
 * IMPORTANTE: este servicio queda CREADO y SIN USAR. El enganche a ProcessArticleBatchImagesJob
 * lo hace el prompt 03 de este mismo grupo (201). No se modifica el job aca.
 */
class ArticleImageValidationService
{
    /**
     * Palabras clave (ya normalizadas a minusculas y sin acentos) que en el titulo, snippet o
     * dominio de un resultado de Google delatan que NO es una foto de producto, sino un
     * documento comercial (lista de precios, catalogo, tarifario, etc). Se usan en prefilter().
     *
     * @var array
     */
    const NON_PRODUCT_TEXT_HINTS = [
        'lista de precios',
        'listado de precios',
        'tarifario',
        'catalogo',
        'folleto',
        'pdf',
        'planilla',
        'presupuesto',
    ];

    /**
     * Valores permitidos para la clave "tipo" que devuelve el modelo de vision. Cualquier otro
     * valor que llegue en la respuesta se descarta (parseo estricto, ver parse_vision_response()).
     *
     * @var array
     */
    const ALLOWED_TYPES = [
        'producto',
        'catalogo_o_lista_de_precios',
        'logo_o_marca',
        'otro_producto',
        'ilustracion_generica',
        'no_identificable',
    ];

    /**
     * Valores permitidos para la clave "confianza" que devuelve el modelo de vision.
     *
     * @var array
     */
    const ALLOWED_CONFIDENCES = ['high', 'medium', 'low'];

    /**
     * Veredictos por candidata de evaluar_candidatas() (misión imagenes-catalogo-completo,
     * 27/9/2026). "dudoso" es una respuesta válida y esperada, no un fracaso: es la salida que la
     * regla anti-complacencia le pide al modelo cuando no puede confirmar.
     *
     * @var array
     */
    const VEREDICTOS_DE_CANDIDATA = ['si', 'no', 'dudoso'];

    /**
     * Problemas que el modelo puede marcar en una candidata. Cualquier otro valor se descarta.
     *
     * @var array
     */
    const PROBLEMAS_DE_CANDIDATA = [
        'marca_de_agua',
        'texto_superpuesto',
        'varias_unidades',
        'foto_de_ambiente',
        'collage',
        'borrosa',
        'otro_producto',
    ];

    /**
     * Candidatas por llamada en evaluar_candidatas(): con cuatro imágenes de 512 px la llamada sigue
     * siendo barata (Haiku) y el modelo puede COMPARARLAS entre sí, que es lo que mejora el criterio
     * respecto de validar de a una.
     *
     * @var int
     */
    const MAX_CANDIDATAS_POR_LLAMADA = 4;

    /**
     * Contador de llamadas reales hechas a Anthropic por ESTA instancia del servicio. El job
     * (prompt 03) crea una unica instancia por corrida de batch, asi que este contador funciona
     * como limite de gasto por corrida (ver max_calls_batch en config).
     *
     * @var int
     */
    protected $calls_made = 0;

    /**
     * Cuantas llamadas reales a Anthropic hizo esta instancia hasta el momento.
     * Lo usa el job (prompt 03) para reportar cuanto se gasto en una corrida.
     *
     * @return int
     */
    public function calls_made(): int
    {
        return $this->calls_made;
    }

    /**
     * Prefiltro de texto, gratuito: mira title/snippet/displayLink/link de un item de Google
     * Custom Search y descarta, sin llamar a ninguna API, los que claramente no son la foto de
     * un producto (listas de precios, catalogos en PDF, tarifarios, etc).
     *
     * Deliberadamente NO filtra por dominio: sitios de ecommerce (MercadoLibre, etc.) suelen
     * tener las mejores fotos de producto, y un filtro por dominio las descartaria tambien.
     *
     * @param  array $google_item  Item individual devuelto por Google Custom Search.
     * @return array { rejected: bool, reason: string|null }
     */
    public function prefilter(array $google_item): array
    {
        // Texto a inspeccionar: titulo + snippet + dominio, todos opcionales en la respuesta de Google.
        $haystack = '';
        $haystack .= isset($google_item['title'])       ? ' '.$google_item['title']       : '';
        $haystack .= isset($google_item['snippet'])     ? ' '.$google_item['snippet']     : '';
        $haystack .= isset($google_item['displayLink']) ? ' '.$google_item['displayLink'] : '';

        $normalized = $this->normalize_text($haystack);

        foreach (self::NON_PRODUCT_TEXT_HINTS as $hint) {
            // strpos (no str_contains): empresa-api corre PHP 7.4 en produccion.
            if (strpos($normalized, $hint) !== false) {
                return [
                    'rejected' => true,
                    'reason'   => 'Descartada sin analizar: el resultado es una lista de precios o un catalogo, no una foto del producto.',
                ];
            }
        }

        // Un link que termina en .pdf es, de por si, un documento y no una foto de producto.
        $link = isset($google_item['link']) ? (string) $google_item['link'] : '';

        if ($link !== '' && strtolower(substr($link, -4)) === '.pdf') {
            return [
                'rejected' => true,
                'reason'   => 'Descartada sin analizar: el resultado es un archivo PDF, no una foto del producto.',
            ];
        }

        return ['rejected' => false, 'reason' => null];
    }

    /**
     * Valida por vision si una imagen (ya descargada, en binario) corresponde al producto del
     * articulo dado. Llama a Claude con la imagen redimensionada y los datos del articulo.
     *
     * Fail-open a proposito: si Anthropic no responde, tira timeout, devuelve un error HTTP o
     * un JSON que no se puede parsear, este metodo NO lanza excepcion. Devuelve un resultado
     * "no evaluado" con accepted=true, para que una caida del servicio de IA no le impida a
     * ningun comercio seguir asignando imagenes (el job, en el prompt 03, marca esos casos para
     * revision manual en vez de rechazarlos).
     *
     * @param  string   $image_binary  Contenido binario de la imagen ya descargada.
     * @param  Article  $article       Articulo contra el que se valida la imagen.
     * @param  int|null $user_id       Dueno al que se le imputa el consumo de tokens. Opcional
     *                                 y al final para no romper a ningun llamador; lo pasa
     *                                 ProcessArticleBatchImagesJob, que es el unico que hay.
     * @return array {
     *     evaluated:  bool,          false si la validacion esta deshabilitada o la IA no respondio
     *     accepted:   bool,          si la imagen se puede usar
     *     type:       string|null,  uno de ALLOWED_TYPES
     *     confidence: string|null,  'high' | 'medium' | 'low' | null
     *     reason:     string,       en castellano, lista para mostrar al usuario
     * }
     */
    public function validate(string $image_binary, Article $article, $user_id = null): array
    {
        // Interruptor general: si esta deshabilitado, no se llama a la IA y se asigna igual.
        if (!config('services.article_image_validation.enabled')) {
            return $this->not_evaluated('La validación por IA está deshabilitada; se asignó igual.');
        }

        // Techo de gasto por corrida de batch: pasado este limite, se deja de llamar a la API.
        $max_calls_batch = (int) config('services.article_image_validation.max_calls_batch');

        if ($max_calls_batch > 0 && $this->calls_made >= $max_calls_batch) {
            return $this->not_evaluated('Se alcanzó el límite de validaciones con IA de esta corrida.');
        }

        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            Log::info('[ValidacionImagenIA] ANTHROPIC_API_KEY no configurada; se asigna sin validar.', [
                'article_id' => $article->id,
            ]);
            return $this->not_evaluated('No se pudo validar la imagen con IA; se asignó igual para revisar a mano.');
        }

        // Redimensiona y re-encodea la imagen a webp para bajar el costo en tokens de la llamada.
        $resized_base64 = $this->resize_and_encode_base64($image_binary);

        if ($resized_base64 === null) {
            Log::info('[ValidacionImagenIA] No se pudo procesar la imagen con Intervention; se asigna sin validar.', [
                'article_id' => $article->id,
            ]);
            return $this->not_evaluated('No se pudo validar la imagen con IA; se asignó igual para revisar a mano.');
        }

        $timeout = (int) config('services.article_image_validation.timeout');
        $model   = (string) config('services.article_image_validation.model');

        // Registro de consultas (misión imagenes-catalogo-completo, §12.1): cuánto tarda la llamada.
        $inicio_del_registro = microtime(true);

        try {
            // Mismo patron de cliente HTTP que ArticleDescriptionAiService/AiExcelAnalyzer.
            $response = $this->build_anthropic_http_client($api_key)
                ->timeout($timeout > 0 ? $timeout : 25)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => 300,
                    'system'     => $this->build_system_prompt(),
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => [
                                [
                                    'type'   => 'image',
                                    'source' => [
                                        'type'       => 'base64',
                                        'media_type' => 'image/webp',
                                        'data'       => $resized_base64,
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $this->build_user_prompt($article),
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (\Exception $e) {
            Log::info('[ValidacionImagenIA] Error de conexión con Anthropic.', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);
            $this->registrar_validacion_individual($article, $user_id, $model, $inicio_del_registro, [
                'ok'      => false,
                'cobrada' => false,
                'error'   => 'Error de conexión con Anthropic: '.$e->getMessage(),
            ]);
            return $this->not_evaluated('No se pudo validar la imagen con IA; se asignó igual para revisar a mano.');
        }

        // Cada intento de llamada cuenta para el limite de la corrida, haya tenido exito o no:
        // lo que se quiere limitar es el gasto de peticiones a Anthropic, no solo las exitosas.
        $this->calls_made++;

        if (!$response->successful()) {
            $body = $response->json();
            $api_error = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP '.$response->status();

            Log::info('[ValidacionImagenIA] Anthropic respondió con error.', [
                'article_id' => $article->id,
                'error'      => $api_error,
            ]);

            $this->registrar_validacion_individual($article, $user_id, $model, $inicio_del_registro, [
                'ok'          => false,
                'cobrada'     => false,
                'http_status' => $response->status(),
                'error'       => (string) $api_error,
            ]);

            return $this->not_evaluated('No se pudo validar la imagen con IA; se asignó igual para revisar a mano.');
        }

        /*
         * Consumo de tokens (misión tokens-por-cliente). Una tanda de imágenes son cientos de
         * llamadas de visión seguidas, que es de lo más caro que corre el sistema, y hasta acá
         * no se anotaba ninguna.
         *
         * Va después del guard de `!successful()`: los tres `return` de arriba son llamadas que
         * no salieron o que Anthropic rechazó, y eso no se paga. El `$this->calls_made++` sí
         * cuenta los rechazos, pero ese contador limita PETICIONES por corrida, que es otra cosa
         * que el gasto real.
         *
         * El dueño viene del job y no de `$article->user_id` a propósito: es el mismo owner que
         * la corrida usa para la cuota de Google y para todo el resto de sus escrituras, así que
         * el gasto queda imputado a quien apretó el botón aunque un artículo tuviera el user_id
         * mal cargado.
         */
        $body = $response->json();

        AiTokenUsageHelper::registrar([
            'user_id' => is_null($user_id) ? null : (int) $user_id,
            'proceso' => 'validacion_imagen_articulo',
            'body'    => is_array($body) ? $body : [],

            // El que devolvió Anthropic (alias ya resuelto con su fecha); el de config, solo
            // si no vino: el costo se calcula por modelo y el alias no alcanza.
            'modelo' => isset($body['model']) && (string) $body['model'] !== ''
                ? (string) $body['model']
                : $model,

            'referencia_id' => (int) $article->id,
        ]);

        $this->registrar_validacion_individual($article, $user_id, $model, $inicio_del_registro, [
            'ok'          => true,
            'cobrada'     => true,
            'http_status' => $response->status(),
            'modelo'      => is_array($body) && isset($body['model']) && (string) $body['model'] !== '' ? (string) $body['model'] : $model,
            'usage'       => is_array($body) && isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : [],
            'resumen'     => $this->resumen_de_validacion_individual($body),
        ]);

        $parsed = $this->parse_vision_response($body);

        if ($parsed === null) {
            Log::info('[ValidacionImagenIA] No se pudo parsear la respuesta de Claude.', [
                'article_id' => $article->id,
            ]);
            return $this->not_evaluated('No se pudo validar la imagen con IA; se asignó igual para revisar a mano.');
        }

        return $this->decide($parsed);
    }

    /**
     * Aplica la regla de decision sobre la respuesta ya parseada del modelo de vision.
     *
     * - es_el_producto = false                              -> accepted = false.
     * - es_el_producto = true  y confianza = low             -> accepted = false (no se pudo confirmar).
     * - es_el_producto = true  y confianza = high|medium     -> accepted = true.
     *
     * @param  array $parsed { es_el_producto: bool, tipo: string, confianza: string, motivo: string }
     * @return array Contrato de respuesta de validate().
     */
    protected function decide(array $parsed): array
    {
        $is_product = $parsed['es_el_producto'];
        $confidence = $parsed['confianza'];
        $reason     = $parsed['motivo'];

        if (!$is_product) {
            return [
                'evaluated'  => true,
                'accepted'   => false,
                'type'       => $parsed['tipo'],
                'confidence' => $confidence,
                'reason'     => $reason,
            ];
        }

        if ($confidence === 'low') {
            return [
                'evaluated'  => true,
                'accepted'   => false,
                'type'       => $parsed['tipo'],
                'confidence' => $confidence,
                'reason'     => $reason,
            ];
        }

        return [
            'evaluated'  => true,
            'accepted'   => true,
            'type'       => $parsed['tipo'],
            'confidence' => $confidence,
            'reason'     => $reason,
        ];
    }

    /**
     * Resultado uniforme de "no evaluado" (fail-open): la imagen se asigna igual porque no se
     * pudo validar (IA deshabilitada, sin clave, error de red, limite de corrida alcanzado,
     * etc). Nunca se interpreta como un rechazo.
     *
     * @param  string $reason  Motivo en castellano, listo para mostrar al usuario.
     * @return array Contrato de respuesta de validate().
     */
    protected function not_evaluated(string $reason): array
    {
        return [
            'evaluated'  => false,
            'accepted'   => true,
            'type'       => null,
            'confidence' => null,
            'reason'     => $reason,
        ];
    }

    /**
     * Redimensiona (sin agrandar) el binario de una imagen al lado mayor configurado y lo
     * re-encodea como webp, para minimizar el peso en tokens de la llamada a Anthropic.
     *
     * @param  string $image_binary
     * @return string|null  Base64 del resultado, o null si Intervention no pudo procesar la imagen.
     */
    protected function resize_and_encode_base64(string $image_binary): ?string
    {
        $max_side = (int) config('services.article_image_validation.max_side');
        $max_side = $max_side > 0 ? $max_side : 512;

        try {
            $manager = new ImageManager();
            $img     = $manager->make($image_binary);

            // Redimensiona manteniendo aspect ratio, y 'upsize' evita agrandar imagenes que ya
            // son mas chicas que $max_side (agrandar solo agregaria tokens sin sumar detalle).
            $img->resize($max_side, $max_side, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            $encoded = (string) $img->encode('webp');
        } catch (\Exception $e) {
            return null;
        }

        if ($encoded === '') {
            return null;
        }

        return base64_encode($encoded);
    }

    /**
     * Parsea la respuesta JSON estricta que devuelve el modelo de vision, tolerando backticks
     * o texto alrededor (igual que ArticleDescriptionAiService::parse_response). Valida que
     * "tipo" y "confianza" tengan uno de los valores permitidos; si no, descarta la respuesta.
     *
     * @param  array $body  Respuesta completa de la API de Anthropic.
     * @return array|null { es_el_producto: bool, tipo: string, confianza: string, motivo: string }
     */
    protected function parse_vision_response(array $body)
    {
        if (!isset($body['content']) || !is_array($body['content'])) {
            return null;
        }

        $text = '';
        foreach ($body['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                $text .= $block['text'];
            }
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        // Saca backticks/markdown por si el modelo los agrega igual, pese a la instruccion.
        $text = preg_replace('/^```(?:json)?/i', '', trim($text));
        $text = preg_replace('/```$/', '', trim($text));
        $text = trim($text);

        // Se queda con el primer objeto JSON balanceado del texto.
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json    = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded['es_el_producto'])) {
            return null;
        }

        $type = isset($decoded['tipo']) ? (string) $decoded['tipo'] : '';

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $type = 'no_identificable';
        }

        $confidence = isset($decoded['confianza']) ? (string) $decoded['confianza'] : '';

        if (!in_array($confidence, self::ALLOWED_CONFIDENCES, true)) {
            // Valor desconocido o ausente: se trata como 'low', nunca se asume lo mejor.
            $confidence = 'low';
        }

        $reason = isset($decoded['motivo']) ? trim((string) $decoded['motivo']) : '';

        if ($reason === '') {
            $reason = 'No se pudo determinar con certeza si la imagen corresponde al producto.';
        }

        return [
            'es_el_producto' => (bool) $decoded['es_el_producto'],
            'tipo'           => $type,
            'confianza'      => $confidence,
            'motivo'         => $reason,
        ];
    }

    /**
     * System prompt del modelo de vision. El anclaje anti-complacencia (parrafo final) es el
     * nucleo de la funcionalidad: los modelos de vision se autocalifican "alta" casi siempre,
     * y sin esa instruccion explicita terminarian aceptando cualquier producto generico
     * (tornillos, bolsas, cables) solo porque "parece" corresponder.
     *
     * @return string
     */
    protected function build_system_prompt(): string
    {
        return implode("\n", [
            'Tu tarea es decidir si una imagen sirve como foto de catálogo para un producto de',
            'un comercio (ferretería, distribuidora, mayorista, ecommerce). Te paso la imagen y',
            'los datos del artículo tal como están cargados en el sistema.',
            '',
            'Respondés ÚNICAMENTE con un objeto JSON válido, sin texto antes ni después, sin',
            'backticks, sin markdown. Estructura exacta:',
            '',
            '{',
            '  "es_el_producto": true,',
            '  "tipo": "producto",',
            '  "confianza": "high",',
            '  "motivo": "..."',
            '}',
            '',
            '"tipo" solo puede ser uno de estos valores: "producto", "catalogo_o_lista_de_precios",',
            '"logo_o_marca", "otro_producto", "ilustracion_generica", "no_identificable".',
            '',
            '"confianza" solo puede ser "high", "medium" o "low".',
            '',
            '"motivo" va en español rioplatense, en una sola frase corta y clara, porque se le',
            'muestra tal cual a un usuario real. Ejemplo bueno: "Es una captura de una lista de',
            'precios, no una foto del producto." Ejemplo malo (no hacer esto): "The image appears',
            'to be a PDF listing."',
            '',
            'LÍMITE IMPORTANTE: no podés confirmar el modelo o código exacto del producto, ni leer',
            'un código de barras en la foto. Solo evaluás si la imagen es consistente con lo que',
            'el artículo dice ser.',
            '',
            'REGLA ANTI-COMPLACENCIA (la más importante, no la relajes): si en la imagen NO se ve',
            'una marca o un texto que coincida con el artículo, y el producto es genérico (un',
            'tornillo, una bolsa, un cable, un caño), la confianza tiene que ser "low", no "medium"',
            'ni "high", aunque la imagen "parezca" del tipo de producto correcto. Decir que no se',
            'puede determinar con certeza es una respuesta correcta y esperada, no un fracaso.',
        ]);
    }

    /**
     * User prompt: datos del articulo tal como estan cargados en el sistema, para que el
     * modelo de vision tenga contra que contrastar la imagen.
     *
     * @param  Article $article
     * @return string
     */
    protected function build_user_prompt(Article $article): string
    {
        $lines   = [];
        $lines[] = 'DATOS DEL ARTÍCULO:';
        $lines[] = '- Nombre: '.($article->name ?: '(sin nombre)');

        if ($article->bar_code) {
            $lines[] = '- Código de barras: '.$article->bar_code;
        }

        if ($article->brand && $article->brand->name) {
            $lines[] = '- Marca: '.$article->brand->name;
        }

        if ($article->category && $article->category->name) {
            $lines[] = '- Categoría: '.$article->category->name;
        }

        $lines[] = '';
        $lines[] = '¿Esta imagen corresponde a este producto? Respondé solo con el JSON indicado.';

        return implode("\n", $lines);
    }

    /**
     * Normaliza texto para comparacion: minusculas y sin acentos/tildes.
     *
     * @param  string $text
     * @return string
     */
    protected function normalize_text(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        // Reemplazo manual de acentos (evita depender de la extension intl, que no siempre esta
        // disponible en todos los entornos de WAMP/produccion).
        $with_accents    = ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'];
        $without_accents = ['a', 'e', 'i', 'o', 'u', 'n', 'u'];

        return str_replace($with_accents, $without_accents, $text);
    }

    /**
     * Cliente HTTP hacia Anthropic con headers y TLS del entorno. Mismo patron que
     * ArticleDescriptionAiService::build_anthropic_http_client() / AiExcelAnalyzer.
     *
     * @param  string $api_key
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function build_anthropic_http_client(string $api_key)
    {
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ]);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /* ----------------------------------------------------------------------------------------
     * Evaluación de VARIAS candidatas en una sola llamada (misión imagenes-catalogo-completo,
     * 27/9/2026). Lo usa ArticleImageAssignmentEngine; validate() de arriba queda como estaba
     * para el job viejo y la búsqueda por código de barras del asistente.
     * -------------------------------------------------------------------------------------- */

    /**
     * Le muestra a la IA hasta MAX_CANDIDATAS_POR_LLAMADA imágenes candidatas del mismo artículo en
     * UNA llamada ("Candidata 1:", imagen, "Candidata 2:", imagen, ...) y devuelve un veredicto por
     * candidata: si es el producto, con qué confianza, si el fondo es blanco y qué problemas tiene.
     *
     * Mismo modelo, timeout, cliente HTTP y registro de tokens que validate() (proceso
     * `validacion_imagen_articulo`), y la misma REGLA ANTI-COMPLACENCIA.
     *
     * 🔴 FAIL-OPEN DISTINTO al de validate(): si la IA no se pudo consultar (apagada, sin clave, error
     * de red, respuesta ilegible), las candidatas vuelven `sin_evaluar` — NO aceptadas. El motor
     * manda la ganadora "a revisar": una imagen nunca se asigna sola sin que la IA la haya visto.
     *
     * @param  array    $candidatas  Hasta 4, cada una ['indice' => int, 'base64' => string, 'media_type' => string].
     *                               El índice es el número que el modelo ve ("Candidata N").
     * @param  Article  $article     Artículo contra el que se comparan.
     * @param  int|null $user_id     Dueño al que se le imputa el consumo de tokens.
     * @param  array    $registro    Contexto para el registro de consultas (image_service_calls,
     *                               plan §12.1): run_id, item_id, criterio y consulta (la búsqueda de
     *                               la que salieron las candidatas). Cada llamada que sale hacia
     *                               Anthropic deja una fila, haya salido bien o mal.
     * @return array {
     *     evaluada:      bool,         true si la IA devolvió veredictos que se pudieron leer.
     *     llamada_hecha: bool,         true si Anthropic respondió bien (la llamada se paga).
     *     motivo:        string|null,  por qué no se evaluó.
     *     resultados:    array,        indice => {es_el_producto: si|no|dudoso|sin_evaluar,
     *                                  confianza: high|medium|low|null, fondo_blanco: bool|null,
     *                                  problemas: string[], motivo: string}.
     * }
     */
    public function evaluar_candidatas(array $candidatas, Article $article, $user_id = null, array $registro = [])
    {
        // Solo las que traen imagen, y como mucho las que entran en una llamada.
        $validas = [];

        foreach ($candidatas as $candidata) {
            if (!is_array($candidata) || !isset($candidata['indice'], $candidata['base64']) || (string) $candidata['base64'] === '') {
                continue;
            }

            if (count($validas) >= self::MAX_CANDIDATAS_POR_LLAMADA) {
                break;
            }

            $validas[(int) $candidata['indice']] = $candidata;
        }

        $indices = array_keys($validas);

        if (empty($validas)) {
            return $this->candidatas_sin_evaluar($indices, 'No había imágenes para mostrarle a la IA.', false);
        }

        if (!config('services.article_image_validation.enabled')) {
            return $this->candidatas_sin_evaluar($indices, 'La validación con IA está apagada.', false);
        }

        // Mismo techo de llamadas por instancia que validate() (ARTICLE_IMAGE_VALIDATION_MAX_CALLS_BATCH).
        $max_calls_batch = (int) config('services.article_image_validation.max_calls_batch');

        if ($max_calls_batch > 0 && $this->calls_made >= $max_calls_batch) {
            return $this->candidatas_sin_evaluar($indices, 'Se alcanzó el límite de validaciones con IA de esta corrida.', false);
        }

        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            Log::info('[ValidacionImagenIA] ANTHROPIC_API_KEY no configurada; las candidatas quedan sin evaluar.', [
                'article_id' => $article->id,
            ]);

            return $this->candidatas_sin_evaluar($indices, 'No está configurada la IA en el servidor.', false);
        }

        // Contenido del mensaje: cada imagen precedida por su rótulo, y al final los datos del artículo.
        $contenido = [];

        foreach ($validas as $indice => $candidata) {
            $contenido[] = ['type' => 'text', 'text' => 'Candidata '.$indice.':'];
            $contenido[] = [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => isset($candidata['media_type']) && (string) $candidata['media_type'] !== '' ? (string) $candidata['media_type'] : 'image/webp',
                    'data'       => (string) $candidata['base64'],
                ],
            ];
        }

        $contenido[] = ['type' => 'text', 'text' => $this->build_user_prompt_candidatas($article, $indices)];

        $timeout = (int) config('services.article_image_validation.timeout');
        $model   = (string) config('services.article_image_validation.model');

        // Para el registro de consultas: cuánto tarda la llamada.
        $inicio = microtime(true);

        try {
            $response = $this->build_anthropic_http_client($api_key)
                ->timeout($timeout > 0 ? $timeout : 25)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    // Un veredicto corto por candidata: 4 motivos de una frase entran holgados.
                    'max_tokens' => 1000,
                    'system'     => $this->build_system_prompt_candidatas(),
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => $contenido,
                        ],
                    ],
                ]);
        } catch (\Exception $e) {
            Log::info('[ValidacionImagenIA] Error de conexión con Anthropic al evaluar candidatas.', [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);

            $this->registrar_consulta_de_candidatas($registro, $article, $user_id, $model, $inicio, [
                'ok'         => false,
                'cobrada'    => false,
                'candidatas' => count($validas),
                'error'      => 'Error de conexión con Anthropic: '.$e->getMessage(),
            ]);

            return $this->candidatas_sin_evaluar($indices, 'No se pudo consultar a la IA (error de conexión).', false);
        }

        // Cada intento cuenta para el techo de la instancia, como en validate().
        $this->calls_made++;

        if (!$response->successful()) {
            $body      = $response->json();
            $api_error = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP '.$response->status();

            Log::info('[ValidacionImagenIA] Anthropic respondió con error al evaluar candidatas.', [
                'article_id' => $article->id,
                'error'      => $api_error,
            ]);

            $this->registrar_consulta_de_candidatas($registro, $article, $user_id, $model, $inicio, [
                'ok'          => false,
                'cobrada'     => false,
                'http_status' => $response->status(),
                'candidatas'  => count($validas),
                'error'       => (string) $api_error,
            ]);

            return $this->candidatas_sin_evaluar($indices, 'La IA respondió con error y no se pudo validar.', false);
        }

        $body = $response->json();

        // Consumo de tokens: mismo registro y mismo proceso que validate() (misión tokens-por-cliente).
        AiTokenUsageHelper::registrar([
            'user_id'       => is_null($user_id) ? null : (int) $user_id,
            'proceso'       => 'validacion_imagen_articulo',
            'body'          => is_array($body) ? $body : [],
            'modelo'        => isset($body['model']) && (string) $body['model'] !== '' ? (string) $body['model'] : $model,
            'referencia_id' => (int) $article->id,
        ]);

        $resultados = is_array($body) ? $this->parse_candidatas_response($body, $indices) : null;

        // Anthropic respondió: la llamada se cobra, se haya podido leer o no.
        $this->registrar_consulta_de_candidatas($registro, $article, $user_id, $model, $inicio, [
            'ok'          => true,
            'cobrada'     => true,
            'http_status' => $response->status(),
            'modelo'      => is_array($body) && isset($body['model']) && (string) $body['model'] !== '' ? (string) $body['model'] : $model,
            'usage'       => is_array($body) && isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : [],
            'candidatas'  => count($validas),
            'resumen'     => is_null($resultados) ? 'La respuesta de la IA no se pudo leer' : $this->resumen_de_veredictos($resultados),
        ]);

        if (is_null($resultados)) {
            Log::info('[ValidacionImagenIA] No se pudo parsear la evaluación de candidatas.', [
                'article_id' => $article->id,
            ]);

            return $this->candidatas_sin_evaluar($indices, 'La respuesta de la IA no se pudo leer.', true);
        }

        return [
            'evaluada'      => true,
            'llamada_hecha' => true,
            'motivo'        => null,
            'resultados'    => $resultados,
        ];
    }

    /**
     * Resultado de "no se pudo evaluar": todas las candidatas `sin_evaluar` (nunca aceptadas).
     *
     * @param  array  $indices
     * @param  string $motivo
     * @param  bool   $llamada_hecha  true si Anthropic respondió (la llamada se pagó) pero no se entendió.
     * @return array
     */
    protected function candidatas_sin_evaluar(array $indices, $motivo, $llamada_hecha)
    {
        $resultados = [];

        foreach ($indices as $indice) {
            $resultados[$indice] = [
                'es_el_producto' => 'sin_evaluar',
                'confianza'      => null,
                'fondo_blanco'   => null,
                'problemas'      => [],
                'motivo'         => $motivo,
            ];
        }

        return [
            'evaluada'      => false,
            'llamada_hecha' => (bool) $llamada_hecha,
            'motivo'        => $motivo,
            'resultados'    => $resultados,
        ];
    }

    /**
     * Parsea la respuesta JSON de evaluar_candidatas(): `{"candidatas": [{indice, es_el_producto,
     * confianza, fondo_blanco, problemas, motivo}]}`, tolerando backticks o texto alrededor (mismo
     * criterio que parse_vision_response()). Valores desconocidos se llevan al lado prudente:
     * veredicto desconocido → "dudoso", confianza desconocida → "low". Una candidata que el modelo
     * no mencionó queda `sin_evaluar`.
     *
     * Y una coherencia que el modelo a veces rompe: si marcó "otro_producto" (un producto parecido
     * pero distinto) no puede decir "si" al mismo tiempo; se lo baja a "dudoso".
     *
     * @param  array $body     Respuesta completa de Anthropic.
     * @param  array $indices  Los índices que se mandaron.
     * @return array|null  indice => veredicto, o null si no hay un JSON legible.
     */
    protected function parse_candidatas_response(array $body, array $indices)
    {
        if (!isset($body['content']) || !is_array($body['content'])) {
            return null;
        }

        $texto = '';

        foreach ($body['content'] as $bloque) {
            if (isset($bloque['type']) && $bloque['type'] === 'text' && isset($bloque['text'])) {
                $texto .= $bloque['text'];
            }
        }

        $texto = trim($texto);
        $texto = preg_replace('/^```(?:json)?/i', '', $texto);
        $texto = trim(preg_replace('/```$/', '', trim($texto)));

        $inicio = strpos($texto, '{');
        $fin    = strrpos($texto, '}');

        if ($inicio === false || $fin === false || $fin <= $inicio) {
            return null;
        }

        $decodificado = json_decode(substr($texto, $inicio, $fin - $inicio + 1), true);

        if (!is_array($decodificado) || !isset($decodificado['candidatas']) || !is_array($decodificado['candidatas'])) {
            return null;
        }

        // Por defecto, todas sin evaluar: se completan con lo que el modelo sí contestó.
        $resultados = $this->candidatas_sin_evaluar($indices, 'La IA no dio un veredicto para esta imagen.', true)['resultados'];

        foreach ($decodificado['candidatas'] as $entrada) {
            if (!is_array($entrada) || !isset($entrada['indice'])) {
                continue;
            }

            $indice = (int) $entrada['indice'];

            if (!array_key_exists($indice, $resultados)) {
                continue;
            }

            $veredicto = isset($entrada['es_el_producto']) ? $entrada['es_el_producto'] : null;

            // Tolerancia al formato viejo de validate(), que era booleano.
            if ($veredicto === true) {
                $veredicto = 'si';
            } elseif ($veredicto === false) {
                $veredicto = 'no';
            }

            $veredicto = strtolower(trim((string) $veredicto));

            if ($veredicto === 'sí') {
                $veredicto = 'si';
            }

            if (!in_array($veredicto, self::VEREDICTOS_DE_CANDIDATA, true)) {
                $veredicto = 'dudoso';
            }

            $confianza = isset($entrada['confianza']) ? strtolower(trim((string) $entrada['confianza'])) : '';

            if (!in_array($confianza, self::ALLOWED_CONFIDENCES, true)) {
                $confianza = 'low';
            }

            $problemas = [];

            if (isset($entrada['problemas']) && is_array($entrada['problemas'])) {
                foreach ($entrada['problemas'] as $problema) {
                    $problema = strtolower(trim((string) $problema));

                    if (in_array($problema, self::PROBLEMAS_DE_CANDIDATA, true) && !in_array($problema, $problemas, true)) {
                        $problemas[] = $problema;
                    }
                }
            }

            if ($veredicto === 'si' && in_array('otro_producto', $problemas, true)) {
                $veredicto = 'dudoso';
            }

            $motivo = isset($entrada['motivo']) ? trim((string) $entrada['motivo']) : '';

            $resultados[$indice] = [
                'es_el_producto' => $veredicto,
                'confianza'      => $confianza,
                'fondo_blanco'   => isset($entrada['fondo_blanco']) ? (bool) $entrada['fondo_blanco'] : null,
                'problemas'      => $problemas,
                'motivo'         => $motivo !== '' ? $motivo : 'La IA no explicó el motivo.',
            ];
        }

        return $resultados;
    }

    /**
     * System prompt de evaluar_candidatas(). Conserva la REGLA ANTI-COMPLACENCIA de
     * build_system_prompt() —es el núcleo de la funcionalidad: los modelos de visión se
     * autocalifican "alta" casi siempre— y suma lo propio de comparar varias fotos: una entrada por
     * candidata, fondo blanco, problemas de una foto de catálogo y las acepciones argentinas (un
     * comercio argentino le dice "pava" a lo que en otro lado es un ave).
     *
     * @return string
     */
    protected function build_system_prompt_candidatas()
    {
        return implode("\n", [
            'Tu tarea es revisar fotos candidatas para el catálogo online de un comercio argentino',
            '(ferretería, almacén, distribuidora, mayorista, ecommerce) y decir, para CADA candidata,',
            'si muestra el producto del artículo. Te paso hasta 4 imágenes, cada una precedida por',
            '"Candidata N:", y después los datos del artículo tal como están cargados en el sistema.',
            '',
            'Respondés ÚNICAMENTE con un objeto JSON válido, sin texto antes ni después, sin',
            'backticks, sin markdown. Estructura exacta, con una entrada por candidata:',
            '',
            '{"candidatas": [{"indice": 1, "es_el_producto": "si", "confianza": "high", "fondo_blanco": true, "problemas": [], "motivo": "..."}]}',
            '',
            '- "indice": el número de la candidata.',
            '- "es_el_producto": "si", "no" o "dudoso".',
            '- "confianza": "high", "medium" o "low".',
            '- "fondo_blanco": true si el fondo es blanco liso, de foto de catálogo; false si no.',
            '- "problemas": lista, que puede estar vacía, con cualquiera de estos valores:',
            '  "marca_de_agua", "texto_superpuesto", "varias_unidades", "foto_de_ambiente", "collage",',
            '  "borrosa", "otro_producto".',
            '- "motivo": una sola frase corta y clara en español rioplatense, porque se le muestra tal',
            '  cual a un usuario real. Ejemplo bueno: "Es la botella de 1,5 L de esa marca, sobre fondo',
            '  blanco." Ejemplo malo (no hacer esto): "The image appears to show the product."',
            '',
            '"otro_producto" es un producto parecido pero distinto (otra marca, otra variante, otro',
            'tamaño). Si lo marcás, "es_el_producto" no puede ser "si".',
            '',
            'ACEPCIONES ARGENTINAS: leé el nombre con el vocabulario de un comercio argentino. "Pava" es',
            'la pava para calentar agua (no un ave), "canilla" es un grifo, "birome" es un bolígrafo,',
            '"manteca" es mantequilla, "frutilla" es fresa, "palta" es aguacate, "remera" es una camiseta,',
            '"pileta" es una bacha o una piscina, "garrafa" es un envase de gas, "bulón" es un perno,',
            '"tarugo" es un taco de fijación, "mecha" es una broca, "lavandina" es lejía y "repasador"',
            'es un paño de cocina.',
            '',
            'LÍMITE IMPORTANTE: no podés confirmar el modelo o código exacto del producto, ni leer un',
            'código de barras en la foto. Solo evaluás si la imagen es consistente con lo que el',
            'artículo dice ser (tipo de producto, marca, variante y tamaño cuando se ven).',
            '',
            'REGLA ANTI-COMPLACENCIA (la más importante, no la relajes): si en la imagen NO se ve una',
            'marca o un texto que coincida con el artículo, y el producto es genérico (un tornillo, una',
            'bolsa, un cable, un caño), la confianza tiene que ser "low", no "medium" ni "high", aunque',
            'la imagen "parezca" del tipo de producto correcto. Decir "dudoso" o que no se puede',
            'determinar con certeza es una respuesta correcta y esperada, no un fracaso. "high" es solo',
            'cuando el tipo de producto, la marca y la variante se ven y coinciden.',
        ]);
    }

    /**
     * Texto final del mensaje de evaluar_candidatas(): los datos del artículo (los mismos que usa
     * build_user_prompt()) y la pregunta por todas las candidatas.
     *
     * @param  Article $article
     * @param  array   $indices
     * @return string
     */
    protected function build_user_prompt_candidatas(Article $article, array $indices)
    {
        $lines   = [];
        $lines[] = 'DATOS DEL ARTÍCULO:';
        $lines[] = '- Nombre: '.($article->name ?: '(sin nombre)');

        if ($article->bar_code) {
            $lines[] = '- Código de barras: '.$article->bar_code;
        }

        if ($article->brand && $article->brand->name) {
            $lines[] = '- Marca: '.$article->brand->name;
        }

        if ($article->category && $article->category->name) {
            $lines[] = '- Categoría: '.$article->category->name;
        }

        $lines[] = '';
        $lines[] = 'Candidatas a evaluar: '.implode(', ', $indices).'. ¿Cuáles muestran este producto? Respondé solo con el JSON indicado, una entrada por candidata.';

        return implode("\n", $lines);
    }

    /* ----------------------------------------------------------------------------------------
     * Registro de consultas (misión imagenes-catalogo-completo, agregado del 27/9/2026, plan
     * §12.1): una fila de image_service_calls por cada llamada que sale hacia Anthropic. Lo mira el
     * admin por cliente. ImageServiceCallLogger nunca lanza y tapa las claves.
     * -------------------------------------------------------------------------------------- */

    /**
     * Registra una llamada de evaluar_candidatas() (origen `asignacion`: la hace el motor).
     *
     * @param  array    $registro  run_id, item_id, criterio, consulta (ver evaluar_candidatas()).
     * @param  Article  $article
     * @param  int|null $user_id
     * @param  string   $modelo    El de config (la respuesta buena lo pisa con el que devolvió Anthropic).
     * @param  float    $inicio    microtime(true) de antes de la llamada.
     * @param  array    $datos     ok, cobrada, http_status, error, usage, candidatas, resumen, modelo.
     * @return void
     */
    protected function registrar_consulta_de_candidatas(array $registro, Article $article, $user_id, $modelo, $inicio, array $datos)
    {
        ImageServiceCallLogger::registrar(array_merge([
            'user_id'      => $this->dueno_para_el_registro($user_id, $article),
            'run_id'       => isset($registro['run_id']) ? $registro['run_id'] : null,
            'item_id'      => isset($registro['item_id']) ? $registro['item_id'] : null,
            'article_id'   => $article->exists ? (int) $article->id : null,
            'article_name' => (string) $article->name,
            'origen'       => isset($registro['origen']) ? (string) $registro['origen'] : ImageServiceCall::ORIGEN_ASIGNACION,
            'tipo'         => ImageServiceCall::TIPO_VALIDACION_IA,
            'proveedor'    => ImageServiceCall::PROVEEDOR_ANTHROPIC,
            'modelo'       => (string) $modelo,
            'criterio'     => isset($registro['criterio']) ? $registro['criterio'] : null,
            'consulta'     => isset($registro['consulta']) ? $registro['consulta'] : null,
            'duracion_ms'  => ImageServiceCallLogger::milisegundos_desde($inicio),
        ], $datos));
    }

    /**
     * Registra una llamada de validate() (origen `validacion_individual`: la búsqueda por código de
     * barras del asistente y el lote viejo). Sin asignación ni item, y `article_id` solo si el
     * artículo existe en la base (el de la búsqueda por código es un Article sin guardar).
     *
     * @param  Article  $article
     * @param  int|null $user_id
     * @param  string   $modelo
     * @param  float    $inicio
     * @param  array    $datos  ok, cobrada, http_status, error, usage, resumen, modelo.
     * @return void
     */
    protected function registrar_validacion_individual(Article $article, $user_id, $modelo, $inicio, array $datos)
    {
        ImageServiceCallLogger::registrar(array_merge([
            'user_id'      => $this->dueno_para_el_registro($user_id, $article),
            'article_id'   => $article->exists ? (int) $article->id : null,
            'article_name' => (string) $article->name,
            'origen'       => ImageServiceCall::ORIGEN_VALIDACION_INDIVIDUAL,
            'tipo'         => ImageServiceCall::TIPO_VALIDACION_IA,
            'proveedor'    => ImageServiceCall::PROVEEDOR_ANTHROPIC,
            'modelo'       => (string) $modelo,
            'candidatas'   => 1,
            'duracion_ms'  => ImageServiceCallLogger::milisegundos_desde($inicio),
        ], $datos));
    }

    /**
     * El dueño al que se le imputa la consulta: el que pasó el llamador (el de la corrida o el del
     * asistente) y, si no vino, el del artículo.
     *
     * @param  int|null $user_id
     * @param  Article  $article
     * @return int
     */
    protected function dueno_para_el_registro($user_id, Article $article)
    {
        if (!is_null($user_id) && (int) $user_id > 0) {
            return (int) $user_id;
        }

        return (int) $article->user_id;
    }

    /**
     * El resumen legible de una evaluación de candidatas: "2 sí, 1 no, 1 dudosa".
     *
     * @param  array $resultados  indice => veredicto (ver evaluar_candidatas()).
     * @return string
     */
    protected function resumen_de_veredictos(array $resultados)
    {
        $cuentas = ['si' => 0, 'no' => 0, 'dudoso' => 0, 'sin_evaluar' => 0];

        foreach ($resultados as $resultado) {
            $veredicto = isset($resultado['es_el_producto']) ? (string) $resultado['es_el_producto'] : 'sin_evaluar';

            if (!array_key_exists($veredicto, $cuentas)) {
                $veredicto = 'sin_evaluar';
            }

            $cuentas[$veredicto]++;
        }

        $partes = [];

        if ($cuentas['si'] > 0) {
            $partes[] = $cuentas['si'].' sí';
        }

        if ($cuentas['no'] > 0) {
            $partes[] = $cuentas['no'].' no';
        }

        if ($cuentas['dudoso'] > 0) {
            $partes[] = $cuentas['dudoso'].($cuentas['dudoso'] === 1 ? ' dudosa' : ' dudosas');
        }

        if ($cuentas['sin_evaluar'] > 0) {
            $partes[] = $cuentas['sin_evaluar'].' sin veredicto';
        }

        return empty($partes) ? 'Sin veredictos' : implode(', ', $partes);
    }

    /**
     * El resumen legible de una llamada de validate(): "Aceptada · es el producto · confianza alta".
     * Relee la respuesta con los mismos métodos que validate() (los dos son puros), así el registro
     * no toca la lógica de la validación.
     *
     * @param  mixed $body  La respuesta de Anthropic.
     * @return string
     */
    protected function resumen_de_validacion_individual($body)
    {
        $parsed = is_array($body) ? $this->parse_vision_response($body) : null;

        if (is_null($parsed)) {
            return 'La respuesta de la IA no se pudo leer';
        }

        $veredicto = $this->decide($parsed);

        $confianzas = ['high' => 'alta', 'medium' => 'media', 'low' => 'baja'];
        $confianza  = isset($confianzas[$parsed['confianza']]) ? $confianzas[$parsed['confianza']] : (string) $parsed['confianza'];

        return ($veredicto['accepted'] ? 'Aceptada' : 'Rechazada')
            .' · '.($parsed['es_el_producto'] ? 'es el producto' : 'no es el producto')
            .' · confianza '.$confianza;
    }
}
