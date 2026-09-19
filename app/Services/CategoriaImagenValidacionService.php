<?php

namespace App\Services;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use Illuminate\Support\Facades\Log;

/**
 * Valida por visión si una imagen encontrada en Google sirve como imagen de una CATEGORÍA del
 * comercio (misión asistente-masivas-imagenes-y-remito, 19/9/2026).
 *
 * Extiende ArticleImageValidationService para reusar lo que ya está probado: el prefiltro de
 * texto (prefilter()), el cliente HTTP hacia Anthropic con el TLS del entorno, el resize a webp
 * que abarata la llamada, el contador de llamadas por corrida y la config de modelo/timeout de
 * `services.article_image_validation`. Lo que cambia es la PREGUNTA: acá no hay un artículo con
 * marca y código contra el que contrastar, hay un rubro ("Ferretería", "Bazar") y tres
 * condiciones que pidió Lucas: que la imagen represente ese rubro, que tenga fondo blanco (estilo
 * e-commerce) y que sea de calidad (nítida, sin texto ni collage).
 *
 * Veredicto (ver decidir_veredicto()):
 *   - `usar`      → representa ∧ fondo_blanco ∧ calidad ≠ baja ∧ confianza high. Se asigna sola.
 *   - `descartar` → ¬representa ∨ confianza low ∨ calidad baja. Se borra.
 *   - `dudosa`    → todo lo demás. Se le muestra a la persona en una tarjeta para que decida.
 *
 * 🔴 NO EVALUADO = `dudosa`, NUNCA `usar`. Acá no hay fail-open, al revés que en validate() del
 * padre: sin clave, con timeout, con JSON roto o con la validación apagada, la candidata queda
 * `dudosa` con motivo "no pude verificarla". El padre asigna igual porque una revisión manual
 * posterior es barata para un artículo; para una categoría, asignar sin mirar es justo lo que
 * Lucas no quiere ("solo si no encuentra o no está seguro, le pregunta al usuario"). Si alguien
 * quiere "simplificar" esto devolviendo `usar`, está cambiando la regla de negocio.
 */
class CategoriaImagenValidacionService extends ArticleImageValidationService
{
    /** Valores permitidos para "calidad" en la respuesta del modelo. */
    const CALIDADES = ['alta', 'media', 'baja'];

    /** Proceso con el que se imputa el consumo de tokens de cada validación. */
    const PROCESO_TOKENS = 'validacion_imagen_categoria';

    /** Motivo uniforme de todo lo que no se pudo verificar. */
    const MOTIVO_NO_VERIFICADA = 'No pude verificarla con la IA.';

    /**
     * Valida por visión si el binario de una imagen sirve como imagen de la categoría dada.
     *
     * @param  string   $binario           Contenido binario de la imagen ya descargada.
     * @param  string   $nombre_categoria  Nombre de la categoría tal como está en el sistema.
     * @param  int|null $user_id           Dueño al que se le imputa el consumo de tokens.
     * @param  int|null $category_id       Id de la categoría (referencia_id del consumo). Opcional
     *                                     y al final para no cambiar la firma del contrato.
     * @return array {
     *     evaluated:    bool,     false si no se pudo verificar (sin clave, timeout, JSON roto...)
     *     veredicto:    string,   'usar' | 'dudosa' | 'descartar'
     *     representa:   bool,
     *     fondo_blanco: bool,
     *     calidad:      string,   'alta' | 'media' | 'baja'
     *     confianza:    string,   'high' | 'medium' | 'low'
     *     motivo:       string,   en castellano, listo para mostrar en la tarjeta
     * }
     */
    public function validar_para_categoria(string $binario, string $nombre_categoria, $user_id = null, $category_id = null): array
    {
        if (!config('services.article_image_validation.enabled')) {
            return $this->no_verificada('La validación por IA está deshabilitada.');
        }

        $max_calls_batch = (int) config('services.article_image_validation.max_calls_batch');

        if ($max_calls_batch > 0 && $this->calls_made >= $max_calls_batch) {
            return $this->no_verificada('Se alcanzó el límite de validaciones con IA de esta corrida.');
        }

        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            Log::info('[ValidacionImagenCategoria] ANTHROPIC_API_KEY no configurada; la candidata queda dudosa.', [
                'categoria' => $nombre_categoria,
            ]);

            return $this->no_verificada();
        }

        $resized_base64 = $this->resize_and_encode_base64($binario);

        if ($resized_base64 === null) {
            Log::info('[ValidacionImagenCategoria] No se pudo procesar la imagen con Intervention; la candidata queda dudosa.', [
                'categoria' => $nombre_categoria,
            ]);

            return $this->no_verificada();
        }

        $timeout = (int) config('services.article_image_validation.timeout');
        $model   = (string) config('services.article_image_validation.model');

        try {
            $response = $this->build_anthropic_http_client($api_key)
                ->timeout($timeout > 0 ? $timeout : 25)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => 300,
                    'system'     => $this->build_system_prompt_categoria(),
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
                                    'text' => $this->build_user_prompt_categoria($nombre_categoria),
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (\Exception $e) {
            Log::info('[ValidacionImagenCategoria] Error de conexión con Anthropic.', [
                'categoria' => $nombre_categoria,
                'error'     => $e->getMessage(),
            ]);

            return $this->no_verificada();
        }

        // Igual que en el padre: cada intento cuenta para el límite de la corrida, salga bien o mal.
        $this->calls_made++;

        if (!$response->successful()) {
            $body      = $response->json();
            $api_error = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP '.$response->status();

            Log::info('[ValidacionImagenCategoria] Anthropic respondió con error.', [
                'categoria' => $nombre_categoria,
                'error'     => $api_error,
            ]);

            return $this->no_verificada();
        }

        $body = $response->json();

        // Consumo de tokens (misión tokens-por-cliente), después del guard de `!successful()`:
        // lo que Anthropic rechazó no se paga. Mismo criterio que validate() del padre.
        AiTokenUsageHelper::registrar([
            'user_id'       => is_null($user_id) ? null : (int) $user_id,
            'proceso'       => self::PROCESO_TOKENS,
            'body'          => is_array($body) ? $body : [],
            'modelo'        => isset($body['model']) && (string) $body['model'] !== '' ? (string) $body['model'] : $model,
            'referencia_id' => is_null($category_id) ? null : (int) $category_id,
        ]);

        $parsed = $this->parse_respuesta_categoria(is_array($body) ? $body : []);

        if ($parsed === null) {
            Log::info('[ValidacionImagenCategoria] No se pudo parsear la respuesta de Claude; la candidata queda dudosa.', [
                'categoria' => $nombre_categoria,
            ]);

            return $this->no_verificada();
        }

        return $this->decidir_veredicto($parsed);
    }

    /**
     * Aplica las reglas de veredicto sobre la respuesta ya parseada (ver el docblock de la clase).
     *
     * @param  array $parsed { representa: bool, fondo_blanco: bool, calidad: string, confianza: string, motivo: string }
     * @return array Contrato de respuesta de validar_para_categoria().
     */
    protected function decidir_veredicto(array $parsed): array
    {
        $representa   = (bool) $parsed['representa'];
        $fondo_blanco = (bool) $parsed['fondo_blanco'];
        $calidad      = (string) $parsed['calidad'];
        $confianza    = (string) $parsed['confianza'];

        if (!$representa || $confianza === 'low' || $calidad === 'baja') {
            $veredicto = 'descartar';
        } elseif ($fondo_blanco && $confianza === 'high') {
            // calidad ≠ baja ya está garantizada por la rama anterior.
            $veredicto = 'usar';
        } else {
            $veredicto = 'dudosa';
        }

        return [
            'evaluated'    => true,
            'veredicto'    => $veredicto,
            'representa'   => $representa,
            'fondo_blanco' => $fondo_blanco,
            'calidad'      => $calidad,
            'confianza'    => $confianza,
            'motivo'       => (string) $parsed['motivo'],
        ];
    }

    /**
     * Resultado uniforme de "no se pudo verificar": SIEMPRE `dudosa` (ver el docblock de la clase).
     *
     * @param  string|null $detalle  Motivo interno; para la persona el texto es siempre el mismo.
     * @return array Contrato de respuesta de validar_para_categoria().
     */
    protected function no_verificada($detalle = null): array
    {
        return [
            'evaluated'    => false,
            'veredicto'    => 'dudosa',
            'representa'   => false,
            'fondo_blanco' => false,
            'calidad'      => 'media',
            'confianza'    => 'low',
            'motivo'       => is_null($detalle) ? self::MOTIVO_NO_VERIFICADA : self::MOTIVO_NO_VERIFICADA.' '.$detalle,
        ];
    }

    /**
     * Parseo tolerante de la respuesta del modelo, con el mismo criterio que parse_vision_response()
     * del padre (texto de los bloques, backticks afuera, primer objeto JSON balanceado, valores
     * fuera del catálogo caen al peor caso). No se reusa el del padre porque está atado a la
     * clave `es_el_producto` y a su catálogo de tipos.
     *
     * @param  array $body  Respuesta completa de la API de Anthropic.
     * @return array|null { representa: bool, fondo_blanco: bool, calidad: string, confianza: string, motivo: string }
     */
    protected function parse_respuesta_categoria(array $body)
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

        $text = preg_replace('/^```(?:json)?/i', '', trim($text));
        $text = preg_replace('/```$/', '', trim($text));
        $text = trim($text);

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (!is_array($decoded) || !array_key_exists('representa', $decoded)) {
            return null;
        }

        $calidad = isset($decoded['calidad']) ? mb_strtolower(trim((string) $decoded['calidad'])) : '';

        if (!in_array($calidad, self::CALIDADES, true)) {
            // Valor desconocido o ausente: se trata como 'baja', nunca se asume lo mejor.
            $calidad = 'baja';
        }

        $confianza = isset($decoded['confianza']) ? mb_strtolower(trim((string) $decoded['confianza'])) : '';

        if (!in_array($confianza, self::ALLOWED_CONFIDENCES, true)) {
            $confianza = 'low';
        }

        $motivo = isset($decoded['motivo']) ? trim((string) $decoded['motivo']) : '';

        if ($motivo === '') {
            $motivo = 'No pude determinar con certeza si la imagen representa la categoría.';
        }

        return [
            'representa'   => $this->booleano($decoded['representa']),
            'fondo_blanco' => $this->booleano(isset($decoded['fondo_blanco']) ? $decoded['fondo_blanco'] : false),
            'calidad'      => $calidad,
            'confianza'    => $confianza,
            'motivo'       => $motivo,
        ];
    }

    /**
     * true/false tolerante: el modelo a veces manda "true"/"si"/"sí" como texto.
     *
     * @param  mixed $valor
     * @return bool
     */
    protected function booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (is_string($valor)) {
            return in_array(mb_strtolower(trim($valor)), ['true', '1', 'si', 'sí', 'yes'], true);
        }

        return (bool) $valor;
    }

    /**
     * System prompt del modelo de visión para categorías. Mantiene el anclaje anti-complacencia
     * del padre: sin él, el modelo se autocalifica "high" con cualquier foto que "parezca" del
     * rubro y todo terminaría asignado sin pasar por la persona.
     *
     * @return string
     */
    protected function build_system_prompt_categoria(): string
    {
        return implode("\n", [
            'Tu tarea es decidir si una imagen sirve como imagen de una CATEGORÍA del catálogo de',
            'un comercio (ferretería, distribuidora, mayorista, tienda online). Una categoría agrupa',
            'artículos de un rubro: "Ferretería", "Bazar", "Pinturas", "Limpieza". La imagen tiene',
            'que representar ese rubro de forma reconocible, con estilo de tienda online: producto',
            'entero, fondo blanco o casi blanco, nítida, sin texto encima, sin marcas de agua, sin',
            'collage ni logos.',
            '',
            'Respondés ÚNICAMENTE con un objeto JSON válido, sin texto antes ni después, sin',
            'backticks, sin markdown. Estructura exacta:',
            '',
            '{',
            '  "representa": true,',
            '  "fondo_blanco": true,',
            '  "calidad": "alta",',
            '  "confianza": "high",',
            '  "motivo": "..."',
            '}',
            '',
            '"representa": si un cliente que ve esta imagen entiende que se trata de artículos de',
            'esa categoría.',
            '"fondo_blanco": si el fondo es blanco o casi blanco y liso (no una escena, no un local,',
            'no una mesa).',
            '"calidad" solo puede ser "alta", "media" o "baja". "baja" es borrosa, pixelada, con',
            'texto o marcas de agua encima, un collage, un logo, o un producto recortado.',
            '"confianza" solo puede ser "high", "medium" o "low".',
            '',
            '"motivo" va en español rioplatense, en una sola frase corta y clara, porque se le',
            'muestra tal cual a la persona en una tarjeta cuando no estás seguro. Ejemplo bueno:',
            '"El fondo no es blanco del todo, es una mesa de madera." Ejemplo malo (no hacer esto):',
            '"The background appears to be wooden."',
            '',
            'Los nombres son los rubros de un comercio argentino: "Bazar" es menaje de cocina, mesa y',
            'hogar (ollas, vasos, platos, fuentes), no ropa, no un mercado ni un local; "Librería" es',
            'artículos de papelería y escolares; "Limpieza" son productos de limpieza; "Jardín" son',
            'herramientas, macetas y muebles de jardín, no un jardín. Una imagen de un local, una',
            'fachada, una calle o una escena de uso no representa la categoría.',
            '',
            'REGLA ANTI-COMPLACENCIA (la más importante, no la relajes): "confianza" es "high" solo',
            'si estás seguro de las tres cosas a la vez (representa, fondo blanco, calidad). Si',
            'dudás de alguna, bajá a "medium": la persona va a decidir mirando la imagen, y eso es',
            'una respuesta correcta y esperada, no un fracaso. Una imagen que puede ser de otro rubro',
            'parecido no representa la categoría.',
        ]);
    }

    /**
     * User prompt: el nombre de la categoría tal como está cargado en el sistema.
     *
     * @param  string $nombre_categoria
     * @return string
     */
    protected function build_user_prompt_categoria(string $nombre_categoria): string
    {
        /*
         * El nombre lo escribió el comercio y viaja adentro del prompt: se aplana a una sola línea
         * y va entre comillas, para que un nombre con saltos de línea no pueda colar instrucciones
         * ni romper el formato. Es del propio dueño, pero es gratis y evita un JSON inválido.
         */
        $nombre = trim((string) preg_replace('/\s+/u', ' ', str_replace('"', "'", (string) $nombre_categoria)));
        $nombre = mb_substr($nombre, 0, 120);

        return implode("\n", [
            'CATEGORÍA DEL COMERCIO: "'.($nombre !== '' ? $nombre : '(sin nombre)').'"',
            '',
            '¿Esta imagen sirve como imagen de esa categoría? Respondé solo con el JSON indicado.',
        ]);
    }
}
