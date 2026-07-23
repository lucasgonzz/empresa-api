<?php

namespace App\Services;

use App\Http\Controllers\Helpers\ColorContrastHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera hasta 3 propuestas de paleta de colores para la tienda online a partir del logo del
 * comercio, usando Claude con vision.
 *
 * Arquitectura elegida a proposito: "la IA propone, el codigo valida". Claude es bueno leyendo
 * la identidad visual de una marca, pero no es confiable garantizando contraste legible entre
 * texto y fondo. Por eso la salida cruda de Claude SIEMPRE pasa por ColorContrastHelper antes de
 * llegar al frontend: sin esa capa, tarde o temprano un comercio termina con texto blanco sobre
 * fondo amarillo.
 *
 * Mismo patron de cliente HTTP e integracion con Anthropic que ArticleDescriptionAiService /
 * ArticleImageValidationService / AiExcelAnalyzer. Este es el primer servicio del repo que manda
 * el logo completo (sin redimensionar/re-encodear) a un modelo de vision.
 */
class LogoPaletteAiService
{
    /** @var string Modelo de Claude a utilizar (mismo que el resto de los servicios de IA). */
    const CLAUDE_MODEL = 'claude-sonnet-4-5';

    /** @var int Tokens maximos de la respuesta: 3 paletas completas entran comodas. */
    const MAX_TOKENS = 1500;

    /** @var int Timeout de la llamada HTTP a Anthropic, en segundos. */
    const TIMEOUT = 60;

    /** @var int Peso maximo aceptado del archivo de logo, en bytes (3.5 MB). */
    const MAX_LOGO_BYTES = 3670016; // 3.5 * 1024 * 1024

    /** @var array Extensiones de imagen que Anthropic acepta como bloque "image". */
    const ALLOWED_EXTENSIONS = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
    ];

    /** @var array Ratios minimos de contraste exigidos (validacion determinista, punto 2.d). */
    const MIN_CONTRAST_TEXT_ON_PRIMARY       = 4.5;
    const MIN_CONTRAST_HOVER_TEXT_ON_PRIMARY = 3.0;
    const MIN_CONTRAST_PRIMARY_VS_SECONDARY  = 1.3;
    const MIN_CONTRAST_PRIMARY_VS_BACKGROUND = 3.0;

    /**
     * Genera las paletas para el usuario indicado.
     *
     * @param  int $user_id  Id del owner (nunca se acepta por request: lo resuelve el controller).
     * @return array {
     *     ok:                 bool,
     *     message:            string|null,  motivo en español cuando ok=false, listo para el usuario
     *     logo_source:        string|null,  'tienda' | 'empresa'
     *     logo_is_monochrome: bool,
     *     palettes:           array,        0 a 3 paletas ya validadas
     * }
     */
    public function generate($user_id)
    {
        // Paso 1: resolver que logo se va a analizar (tienda tiene prioridad sobre empresa).
        $logo = $this->resolve_logo($user_id);

        if (!$logo['ok']) {
            return $this->fail($logo['message']);
        }

        // Paso 2: leer el archivo del disco y prepararlo para el bloque "image" de la API.
        $prepared = $this->prepare_image($logo['path']);

        if (!$prepared['ok']) {
            return $this->fail($prepared['message']);
        }

        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            Log::info('[PaletaLogo] ANTHROPIC_API_KEY no configurada.', ['user_id' => $user_id]);
            return $this->fail('No se pudo generar la paleta: el servicio de IA no esta disponible en este momento.');
        }

        // Paso 3: llamar a Claude con el logo + la consigna de diseño.
        try {
            $response = $this->build_anthropic_http_client($api_key)
                ->timeout(self::TIMEOUT)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => self::CLAUDE_MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'system'     => $this->build_system_prompt(),
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => [
                                [
                                    'type'   => 'image',
                                    'source' => [
                                        'type'       => 'base64',
                                        'media_type' => $prepared['media_type'],
                                        'data'       => $prepared['base64'],
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $this->build_user_prompt(),
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (\Exception $e) {
            Log::info('[PaletaLogo] Error de conexion con Anthropic.', [
                'user_id' => $user_id,
                'error'   => $e->getMessage(),
            ]);
            return $this->fail('No se pudo contactar al servicio de IA. Intenta de nuevo en unos minutos.');
        }

        if (!$response->successful()) {
            $body = $response->json();
            $api_error = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP '.$response->status();

            // Nunca se devuelve $api_error al frontend (podria filtrar detalle de la API/cuenta).
            Log::info('[PaletaLogo] Anthropic respondio con error.', [
                'user_id' => $user_id,
                'error'   => $api_error,
            ]);

            return $this->fail('El servicio de IA no pudo procesar el logo. Intenta de nuevo en unos minutos.');
        }

        $parsed = $this->parse_response($response->json());

        if ($parsed === null) {
            Log::info('[PaletaLogo] No se pudo parsear la respuesta de Claude.', ['user_id' => $user_id]);
            return $this->fail('No se pudo interpretar la respuesta de la IA. Intenta de nuevo.');
        }

        if (!$parsed['logo_readable']) {
            return $this->fail('No se pudo interpretar el logo. Probá con una imagen mas nitida o con mejor resolucion.');
        }

        // Paso 4: validacion determinista de cada paleta cruda (el punto critico del prompt).
        $validated_palettes = [];

        foreach ($parsed['palettes'] as $raw_palette) {
            $validated = $this->validate_palette($raw_palette);

            if ($validated === null) {
                continue;
            }

            // Descarta duplicados exactos (mismos 5 colores que una paleta ya aceptada).
            if ($this->is_duplicate($validated, $validated_palettes)) {
                continue;
            }

            $validated_palettes[] = $validated;
        }

        if (empty($validated_palettes)) {
            Log::info('[PaletaLogo] Ninguna paleta sobrevivio a la validacion de contraste.', ['user_id' => $user_id]);
            return $this->fail('No se pudo generar una paleta valida a partir de este logo. Probá con otra imagen.');
        }

        return [
            'ok'                 => true,
            'message'            => null,
            'logo_source'        => $logo['source'],
            'logo_is_monochrome' => $parsed['logo_is_monochrome'],
            'palettes'           => $validated_palettes,
        ];
    }

    /**
     * Resuelve la ruta local del logo a analizar, con prioridad tienda > empresa.
     *
     * @param  int $user_id
     * @return array { ok: bool, message: string|null, source: string|null, path: string|null }
     */
    protected function resolve_logo($user_id)
    {
        // 1. logo_url de la configuracion online (logo de la tienda), tiene prioridad.
        $online_configuration = OnlineConfiguration::where('user_id', $user_id)->first();

        if ($online_configuration && !empty($online_configuration->logo_url)) {
            $path = GeneralHelper::storage_public_path_from_image_url($online_configuration->logo_url);

            if ($path !== null) {
                return ['ok' => true, 'message' => null, 'source' => 'tienda', 'path' => $path];
            }
        }

        // 2. image_url del usuario (logo de la empresa, cargado en Configuracion general).
        $user = User::find($user_id);

        if ($user && !empty($user->image_url)) {
            $path = GeneralHelper::storage_public_path_from_image_url($user->image_url);

            if ($path !== null) {
                return ['ok' => true, 'message' => null, 'source' => 'empresa', 'path' => $path];
            }
        }

        // 3. No hay ningun logo utilizable (ni tienda ni empresa, o ninguno resuelve a un
        // archivo real en disco).
        return [
            'ok'      => false,
            'message' => 'Para generar la paleta primero cargá el logo de tu tienda o el de tu empresa.',
            'source'  => null,
            'path'    => null,
        ];
    }

    /**
     * Lee el archivo de logo del disco, valida formato/peso y lo codifica en base64.
     *
     * @param  string $path  Ruta local en disco (ya resuelta por GeneralHelper).
     * @return array { ok: bool, message: string|null, media_type: string|null, base64: string|null }
     */
    protected function prepare_image($path)
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED_EXTENSIONS[$extension])) {
            return [
                'ok'         => false,
                'message'    => 'Ese formato de logo no se puede analizar. Subí el logo en PNG o JPG e intentá de nuevo.',
                'media_type' => null,
                'base64'     => null,
            ];
        }

        $size = @filesize($path);

        if ($size === false || $size > self::MAX_LOGO_BYTES) {
            return [
                'ok'         => false,
                'message'    => 'El logo pesa demasiado para analizarlo. Subí una version mas liviana (menos de 3,5 MB) e intentá de nuevo.',
                'media_type' => null,
                'base64'     => null,
            ];
        }

        $binary = @file_get_contents($path);

        if ($binary === false) {
            return [
                'ok'         => false,
                'message'    => 'No se pudo leer el archivo del logo. Intenta de nuevo.',
                'media_type' => null,
                'base64'     => null,
            ];
        }

        return [
            'ok'         => true,
            'message'    => null,
            'media_type' => self::ALLOWED_EXTENSIONS[$extension],
            'base64'     => base64_encode($binary),
        ];
    }

    /**
     * Valida y corrige (o descarta) una paleta cruda devuelta por Claude, aplicando en orden
     * las reglas deterministas del punto 2.d del prompt.
     *
     * @param  array $raw  Paleta cruda tal como vino en el JSON de Claude.
     * @return array|null  Paleta lista para el frontend, o null si hay que descartarla entera.
     */
    protected function validate_palette($raw)
    {
        if (!is_array($raw) || !isset($raw['id'])) {
            return null;
        }

        // 1. Normalizar los 5 colores. Si alguno es invalido, se descarta la paleta entera: no
        // se intenta adivinar un color que la IA no supo devolver bien formado.
        $primary    = ColorContrastHelper::normalize_hex(isset($raw['primary_color'])    ? $raw['primary_color']    : null);
        $secondary  = ColorContrastHelper::normalize_hex(isset($raw['secondary_color'])  ? $raw['secondary_color']  : null);
        $text       = ColorContrastHelper::normalize_hex(isset($raw['text_color'])       ? $raw['text_color']       : null);
        $hover_text = ColorContrastHelper::normalize_hex(isset($raw['hover_text_color']) ? $raw['hover_text_color'] : null);
        $background = ColorContrastHelper::normalize_hex(isset($raw['background_color']) ? $raw['background_color'] : null);

        if ($primary === null || $secondary === null || $text === null || $hover_text === null || $background === null) {
            return null;
        }

        $warnings = [];

        // 2. text_color va SIEMPRE sobre primary_color (la nav), no sobre el fondo. Si no llega
        // al minimo de legibilidad WCAG AA para texto normal (4.5), se reemplaza por el color
        // (blanco o negro casi puro) que de mas contraste contra el primario.
        if (ColorContrastHelper::contrast_ratio($text, $primary) < self::MIN_CONTRAST_TEXT_ON_PRIMARY) {
            $text = ColorContrastHelper::best_text_on($primary);
        }

        // 3. Mismo criterio para hover_text_color, con un piso mas laxo (3.0) porque suele ser
        // un texto secundario/de menor tamaño relativo.
        if (ColorContrastHelper::contrast_ratio($hover_text, $primary) < self::MIN_CONTRAST_HOVER_TEXT_ON_PRIMARY) {
            $hover_text = ColorContrastHelper::best_text_on($primary);
        }

        // 4. Primario y secundario casi identicos: no se corrige (es una eleccion de diseño
        // valida en algunas paletas monocromas), pero se avisa.
        if (ColorContrastHelper::contrast_ratio($primary, $secondary) < self::MIN_CONTRAST_PRIMARY_VS_SECONDARY) {
            $warnings[] = 'El color primario y el de acento son muy parecidos entre sí.';
        }

        // 5. Primario poco distinguible del fondo: tampoco se corrige, solo se avisa (el fondo
        // es una eleccion de identidad de marca, no algo que el codigo deba pisar).
        if (ColorContrastHelper::contrast_ratio($primary, $background) < self::MIN_CONTRAST_PRIMARY_VS_BACKGROUND) {
            $warnings[] = 'El color primario se distingue poco del fondo elegido.';
        }

        // 6. Fondo oscuro: decision explicita de Lucas (23/7/2026) de PERMITIRLO, solo avisando
        // del riesgo (las tarjetas de producto y varios textos de la tienda estan pensados sobre
        // fondo claro). No se bloquea ni se corrige el fondo.
        $background_is_dark = ColorContrastHelper::is_dark($background);

        if ($background_is_dark) {
            $warnings[] = 'Es un fondo oscuro. Las tarjetas de producto y varios textos de la tienda están pensados sobre fondo claro, así que revisá cómo se ve antes de guardar.';
        }

        return [
            'id'                 => (string) $raw['id'],
            'nombre'             => isset($raw['nombre']) ? (string) $raw['nombre'] : '',
            'descripcion'        => isset($raw['descripcion']) ? (string) $raw['descripcion'] : '',
            'primary_color'      => $primary,
            'secondary_color'    => $secondary,
            'text_color'         => $text,
            'hover_text_color'   => $hover_text,
            'background_color'   => $background,
            'background_is_dark' => $background_is_dark,
            'warnings'           => $warnings,
        ];
    }

    /**
     * Verifica si una paleta ya validada es un duplicado exacto (mismos 5 colores) de alguna
     * paleta ya aceptada en la lista.
     *
     * @param  array $palette             Paleta ya validada a chequear.
     * @param  array $accepted_palettes   Paletas ya aceptadas hasta el momento.
     * @return bool
     */
    protected function is_duplicate($palette, $accepted_palettes)
    {
        foreach ($accepted_palettes as $accepted) {
            if (
                $palette['primary_color'] === $accepted['primary_color']
                && $palette['secondary_color'] === $accepted['secondary_color']
                && $palette['text_color'] === $accepted['text_color']
                && $palette['hover_text_color'] === $accepted['hover_text_color']
                && $palette['background_color'] === $accepted['background_color']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resultado negativo uniforme.
     *
     * @param  string $message  En español, apto para mostrar al usuario.
     * @return array
     */
    protected function fail($message)
    {
        return [
            'ok'                 => false,
            'message'            => $message,
            'logo_source'        => null,
            'logo_is_monochrome' => false,
            'palettes'           => [],
        ];
    }

    /**
     * System prompt: explica el rol semantico de cada color y pide las 3 variantes de paleta.
     *
     * @return string
     */
    protected function build_system_prompt()
    {
        return implode("\n", [
            'Sos un diseñador de identidad visual armando la paleta de colores del ecommerce de',
            'un comercio, a partir de su logo. Trabajás en español rioplatense.',
            '',
            'ROL SEMÁNTICO DE CADA COLOR (esto es lo mas importante para entender bien, se suele',
            'malinterpretar):',
            '',
            '- primary_color: color de la barra de navegación y de las superficies principales de',
            '  marca de la tienda.',
            '- secondary_color: color de acento, para botones, links y bordes.',
            '- text_color: el texto que va ENCIMA de primary_color (el texto de la barra de',
            '  navegación). Tiene que contrastar contra el primario, NO contra el fondo de la',
            '  página.',
            '- hover_text_color: ese mismo texto de la navegación, pero al pasar el mouse por',
            '  encima. Tambien contrasta contra primary_color, no contra el fondo.',
            '- background_color: el fondo general de la página, detrás de las tarjetas de',
            '  producto (que son siempre blancas, no cambian de color).',
            '',
            'Si no respetás esta jerarquía semántica, la barra de navegación termina con texto',
            'ilegible: es el error mas comun y hay que evitarlo.',
            '',
            'TAREA: proponé EXACTAMENTE 3 paletas, cada una con una intención distinta:',
            '',
            '1. id "fiel": lo mas pegada posible a los colores reales que aparecen en el logo.',
            '2. id "sobria": conservadora, con un fondo claro neutro; la marca aparece solo en',
            '   los acentos (secondary_color), no domina toda la pantalla.',
            '3. id "contraste": mas audaz, con mayor separación entre el color primario y el de',
            '   acento.',
            '',
            'Si el logo es monocromo (blanco y negro, escala de grises, o un solo color plano sin',
            'una identidad cromatica propia), marcá "logo_is_monochrome": true y proponé igual las',
            '3 paletas, pero neutras y elegantes (grises, negros, blancos con un acento sutil).',
            'NO inventes un color de marca que el logo no tiene.',
            '',
            'Si la imagen no se puede interpretar como un logo (esta corrupta, en blanco, o no se',
            'distingue nada útil), respondé "logo_readable": false.',
            '',
            'FORMATO DE SALIDA: respondés ÚNICAMENTE con un objeto JSON válido, sin texto antes ni',
            'después, sin backticks, sin markdown. Estructura exacta:',
            '',
            '{',
            '  "logo_readable": true,',
            '  "logo_is_monochrome": false,',
            '  "palettes": [',
            '    {',
            '      "id": "fiel",',
            '      "nombre": "...",',
            '      "descripcion": "...",',
            '      "primary_color": "#RRGGBB",',
            '      "secondary_color": "#RRGGBB",',
            '      "text_color": "#RRGGBB",',
            '      "hover_text_color": "#RRGGBB",',
            '      "background_color": "#RRGGBB"',
            '    }',
            '  ]',
            '}',
            '',
            '"nombre" es un titulo corto para mostrar en la interfaz (2 a 4 palabras). "descripcion"',
            'es una sola linea explicando la intencion de esa paleta. Ambos en español.',
        ]);
    }

    /**
     * User prompt: la consigna puntual para esta llamada (el logo va en el bloque "image").
     *
     * @return string
     */
    protected function build_user_prompt()
    {
        return 'Este es el logo del comercio. Generá las 3 paletas indicadas en las instrucciones, '
            .'respetando el rol semántico de cada color. Respondé solo con el JSON pedido.';
    }

    /**
     * Parsea el JSON que devuelve Claude. Tolera backticks o texto alrededor (mismo criterio que
     * ArticleDescriptionAiService::parse_response / ArticleImageValidationService).
     *
     * @param  array $body  Respuesta completa de la API de Anthropic.
     * @return array|null { logo_readable: bool, logo_is_monochrome: bool, palettes: array }
     */
    protected function parse_response($body)
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

        // Sacar fences de markdown por si el modelo los agrega igual (strpos, no str_contains:
        // este repo corre PHP 7.4 en produccion).
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);
        $text = trim($text);

        // Quedarse con el primer objeto JSON balanceado del texto.
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json    = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded['logo_readable'])) {
            return null;
        }

        if (!$decoded['logo_readable']) {
            return [
                'logo_readable'      => false,
                'logo_is_monochrome' => false,
                'palettes'           => [],
            ];
        }

        if (!isset($decoded['palettes']) || !is_array($decoded['palettes'])) {
            return null;
        }

        return [
            'logo_readable'      => true,
            'logo_is_monochrome' => isset($decoded['logo_is_monochrome']) ? (bool) $decoded['logo_is_monochrome'] : false,
            'palettes'           => $decoded['palettes'],
        ];
    }

    /**
     * Cliente HTTP hacia Anthropic con headers y TLS del entorno. Mismo patron que
     * ArticleDescriptionAiService::build_anthropic_http_client() / ArticleImageValidationService.
     *
     * @param  string $api_key
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function build_anthropic_http_client($api_key)
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
