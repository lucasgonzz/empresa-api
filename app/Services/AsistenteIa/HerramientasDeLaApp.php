<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\asistente_ia\BloquesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Models\AiConversation;

/**
 * Las herramientas que el agente tiene SOLO en la app «ComercioCity IA» (misión app-ia-y-memoria,
 * 29/10/2026, §4 E1.6 del plan): mostrar_grafico, mostrar_tabla y pedir_imagen.
 *
 * Las tres dejan un BLOQUE en la respuesta (`ai_messages.bloques`), que la app dibuja debajo del texto.
 * En WhatsApp no hay dónde dibujar un gráfico y la pantalla del sistema todavía no los pinta, así que
 * ahí no se declaran ni se despachan (AsistenteIaService::build_tools() y execute_tool_calls() miran el
 * canal del mensaje).
 *
 * Mismo molde que HerramientasDeCarga: definición y despacho en un solo lugar, en orden fijo (las tools
 * son el prefijo del caché de prompt). El servicio junta los bloques que devuelve ejecutar() y el job
 * los guarda con el mensaje, igual que las menciones y los adjuntos.
 */
class HerramientasDeLaApp
{
    const MOSTRAR_GRAFICO = 'mostrar_grafico';

    const MOSTRAR_TABLA = 'mostrar_tabla';

    const PEDIR_IMAGEN = 'pedir_imagen';

    /**
     * Las definiciones para la API, en orden fijo.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definiciones(): array
    {
        return [
            [
                'name'         => self::MOSTRAR_GRAFICO,
                'description'  => 'Muestra un GRÁFICO debajo de tu respuesta en la app. Usalo cuando un gráfico se entiende mejor que los números sueltos: '
                    . 'una evolución en el tiempo (lineas o area), una comparación entre varios (barras, barras_horizontales si los nombres son largos, barras_apiladas para partes que suman), '
                    . 'la participación de cada parte en un total (torta o dona, una sola serie y hasta 8 porciones), o la relación entre dos medidas (dispersion, con puntos [x, y]). '
                    . 'Los números salen SIEMPRE de las herramientas de consulta: primero consultás, después graficás. Los datos van como NÚMEROS sin formato (1234.5, no "$ 1.234,50"); un porcentaje va en puntos (15 = 15 %, no 0.15) con eje_y.formato porcentaje, '
                    . 'un dato por categoría y en el mismo orden (null si falta uno). Hasta 8 series y 500 datos por serie. Si te contesta un error, corregí lo que dice y volvé a llamarla. '
                    . 'Después de mostrarlo, comentá en el texto lo importante en una o dos oraciones; no repitas todos los números.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tipo'       => [
                            'type' => 'string',
                            'enum' => BloquesIaHelper::TIPOS_DE_GRAFICO,
                        ],
                        'titulo'     => ['type' => 'string', 'description' => 'Título corto (hasta 120 caracteres).'],
                        'subtitulo'  => ['type' => 'string', 'description' => 'Opcional: el período o la aclaración (hasta 200).'],
                        'categorias' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'El eje X (días, meses, artículos) o las etiquetas de cada porción de la torta. No va en dispersion.',
                        ],
                        'series'     => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'nombre' => ['type' => 'string', 'description' => 'Qué mide la serie (hasta 60).'],
                                    'datos'  => [
                                        'type'        => 'array',
                                        'items'       => ['type' => 'number'],
                                        'description' => 'Un número por categoría, en el mismo orden (null donde no hay dato).',
                                    ],
                                    'puntos' => [
                                        'type'        => 'array',
                                        'items'       => ['type' => 'array', 'items' => ['type' => 'number']],
                                        'description' => 'Solo en dispersion: [[x, y], …].',
                                    ],
                                ],
                                'required'   => ['nombre'],
                            ],
                        ],
                        'eje_x'      => [
                            'type'       => 'object',
                            'properties' => ['titulo' => ['type' => 'string']],
                        ],
                        'eje_y'      => [
                            'type'       => 'object',
                            'properties' => [
                                'titulo'  => ['type' => 'string'],
                                'formato' => ['type' => 'string', 'enum' => BloquesIaHelper::FORMATOS_DE_EJE],
                            ],
                        ],
                        'moneda'     => ['type' => 'string', 'enum' => BloquesIaHelper::MONEDAS, 'description' => 'Con eje_y.formato = moneda: ARS (por defecto) o USD.'],
                    ],
                    'required'   => ['tipo', 'titulo', 'series'],
                ],
            ],
            [
                'name'         => self::MOSTRAR_TABLA,
                'description'  => 'Muestra una TABLA debajo de tu respuesta en la app, con scroll y un botón para bajarla a Excel. Usala cuando la persona va a querer ver fila por fila '
                    . '(un listado de clientes con su deuda, los artículos a reponer, las ventas de un día). Nunca armes una tabla en el texto con | o guiones: para eso está esta herramienta. '
                    . 'Cada columna tiene una clave corta (letras, números o _), su etiqueta y su formato (texto, numero, moneda, porcentaje o fecha). Cada fila es un objeto con esas claves; '
                    . 'en las columnas numéricas el valor va como NÚMERO sin formato; en una columna porcentaje, en puntos (15 = 15 %, no 0.15). Hasta 12 columnas y 300 filas. Los datos salen de las herramientas de consulta. '
                    . 'Después, en el texto, contá lo importante en una o dos oraciones.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'titulo'   => ['type' => 'string', 'description' => 'Título corto (hasta 120 caracteres).'],
                        'columnas' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'clave'    => ['type' => 'string'],
                                    'etiqueta' => ['type' => 'string'],
                                    'formato'  => ['type' => 'string', 'enum' => BloquesIaHelper::FORMATOS_DE_COLUMNA],
                                ],
                                'required'   => ['clave', 'etiqueta'],
                            ],
                        ],
                        'filas'    => [
                            'type'  => 'array',
                            'items' => ['type' => 'object'],
                        ],
                    ],
                    'required'   => ['titulo', 'columnas', 'filas'],
                ],
            ],
            [
                'name'         => self::PEDIR_IMAGEN,
                'description'  => 'Pide que se GENERE una imagen nueva (una placa para redes, un cartel para el local, una foto de producto ilustrativa, el fondo de una promo). '
                    . 'Usala solo cuando la persona pide una imagen. Escribí el prompt en español, detallado: qué se ve, el texto que tiene que llevar escrito (entre comillas y tal cual), '
                    . 'los colores, el estilo y para qué es. Si es para el negocio, usá su nombre. La imagen aparece sola en este mismo mensaje cuando esté lista: '
                    . 'después de llamarla decí en una línea «la pedí; aparece acá cuando esté lista», sin prometer tiempos ni decir que ya está; si la respuesta dice que no se pudo, contalo tal cual. '
                    . 'Pedir una imagen NO autoriza a preparar promociones, envíos ni mensajes a clientes: si te parece útil, ofrecelo en una línea y no armes la tarjeta hasta que la persona lo pida. '
                    . 'No sirve para editar una foto que mandó la persona ni para las fotos de los artículos o las categorías del catálogo.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'La descripción completa de la imagen (hasta 4000 caracteres).'],
                        'tamano' => [
                            'type'        => 'string',
                            'enum'        => BloquesIaHelper::TAMANOS,
                            'description' => 'cuadrada (posteo de Instagram), vertical (historia o estado de WhatsApp) u horizontal (portada, banner).',
                        ],
                        'estilo' => ['type' => 'string', 'description' => 'Opcional: fotografía, ilustración, minimalista, etc. (hasta 200).'],
                    ],
                    'required'   => ['prompt', 'tamano'],
                ],
            ],
        ];
    }

    /**
     * Los nombres de las tres herramientas.
     *
     * @return array<int, string>
     */
    public static function nombres(): array
    {
        return [self::MOSTRAR_GRAFICO, self::MOSTRAR_TABLA, self::PEDIR_IMAGEN];
    }

    /**
     * true si la herramienta es de la app.
     *
     * @param  string  $tool_name
     * @return bool
     */
    public static function maneja($tool_name): bool
    {
        return in_array((string) $tool_name, self::nombres(), true);
    }

    /**
     * Ejecuta una herramienta de la app. Devuelve `['content' => string, 'is_error' => bool,
     * 'bloque' => array|null, 'pedido_id' => int|null]`: el servicio suma el bloque a la respuesta y el
     * job despacha el envío del pedido de imagen después de guardar el mensaje.
     *
     * @param  string  $tool_name
     * @param  array  $input
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @param  int  $bloques_ya_puestos  Cuántos bloques lleva ya esta respuesta (techo: MAX_BLOQUES).
     * @return array{content: string, is_error: bool, bloque: array|null, pedido_id: int|null}
     */
    public static function ejecutar($tool_name, array $input, AiConversation $conversation, $assistant_message, $bloques_ya_puestos): array
    {
        if ((int) $bloques_ya_puestos >= BloquesIaHelper::MAX_BLOQUES) {

            return self::resultado(
                'Ya pusiste ' . BloquesIaHelper::MAX_BLOQUES . ' gráficos, tablas o imágenes en esta respuesta, que es el máximo. Seguí con el texto.',
                true
            );
        }

        if ($tool_name === self::PEDIR_IMAGEN) {

            $pedido = PedidosDeImagenIaHelper::pedir((int) $conversation->user_id, $assistant_message, $input);

            return [
                'content'   => self::json(['resultado' => $pedido['texto']]),
                'is_error'  => (bool) $pedido['es_error'],
                'bloque'    => $pedido['bloque'],
                'pedido_id' => is_null($pedido['pedido']) ? null : (int) $pedido['pedido']->id,
            ];
        }

        $validado = $tool_name === self::MOSTRAR_TABLA
            ? BloquesIaHelper::tabla($input)
            : BloquesIaHelper::grafico($input);

        if (is_null($validado['bloque'])) {

            return self::resultado('No se pudo mostrar: ' . $validado['error'] . '. Corregilo y volvé a llamar.', true);
        }

        $que = $tool_name === self::MOSTRAR_TABLA ? 'La tabla' : 'El gráfico';

        return [
            'content'   => self::json(['resultado' => $que . ' se muestra debajo de tu texto. No repitas todos los números: comentá lo importante.']),
            'is_error'  => false,
            'bloque'    => $validado['bloque'],
            'pedido_id' => null,
        ];
    }

    /**
     * @param  string  $texto
     * @param  bool  $es_error
     * @return array{content: string, is_error: bool, bloque: null, pedido_id: null}
     */
    protected static function resultado($texto, $es_error): array
    {
        return [
            'content'   => self::json(['resultado' => $texto]),
            'is_error'  => (bool) $es_error,
            'bloque'    => null,
            'pedido_id' => null,
        ];
    }

    /**
     * @param  array  $datos
     * @return string
     */
    protected static function json(array $datos): string
    {
        return json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
