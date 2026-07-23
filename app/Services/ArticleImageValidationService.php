<?php

namespace App\Services;

use App\Models\Article;
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
     * @param  string  $image_binary  Contenido binario de la imagen ya descargada.
     * @param  Article $article       Articulo contra el que se valida la imagen.
     * @return array {
     *     evaluated:  bool,          false si la validacion esta deshabilitada o la IA no respondio
     *     accepted:   bool,          si la imagen se puede usar
     *     type:       string|null,  uno de ALLOWED_TYPES
     *     confidence: string|null,  'high' | 'medium' | 'low' | null
     *     reason:     string,       en castellano, lista para mostrar al usuario
     * }
     */
    public function validate(string $image_binary, Article $article): array
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

            return $this->not_evaluated('No se pudo validar la imagen con IA; se asignó igual para revisar a mano.');
        }

        $parsed = $this->parse_vision_response($response->json());

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
}
