<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Models\AiConversation;
use App\Models\User;

/**
 * Las dos herramientas de la memoria del agente (misión app-ia-y-memoria, 29/10/2026):
 * `leer_contexto` y `guardar_en_contexto`. Definición y despacho juntos, igual que
 * HerramientasDeCarga: una tool declarada en un solo lado es una tool que la IA llama y recibe
 * «Tool desconocida».
 *
 * 🔴 POR QUÉ UN REGISTRO APARTE Y NO DENTRO DE AsistenteIaService::registro_de_lectura(). Aquel
 * registro es de LECTURA PURA por definición («ninguna crea, modifica ni borra nada») y
 * guardar_en_contexto escribe; además su orden es el prefijo del caché de prompt. Estas dos van
 * AL FINAL de build_tools() (después de las de carga), así que el prefijo de todo lo de antes queda
 * idéntico; y el MCP las suma al final de su lista (McpHerramientasHelper).
 *
 * 🔴 VAN EN TODOS LOS CANALES (sistema, WhatsApp, app y MCP), pero solo para un dueño con la
 * extensión `agentes_ia`: la memoria es parte de ese módulo. El despacho lo vuelve a mirar por si
 * llegara un pedido sin ella.
 *
 * El dueño sale SIEMPRE de la conversación (user_id), nunca de Auth: esto corre adentro del job, sin
 * sesión, y en el MCP con el token de la persona.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class HerramientasDeContexto
{
    const LEER = 'leer_contexto';

    const GUARDAR = 'guardar_en_contexto';

    /**
     * Las definiciones para la API (forma de Anthropic: name / description / input_schema).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definiciones()
    {
        return [
            [
                'name'         => self::LEER,
                'description'  => 'Abre un archivo de TU MEMORIA de este negocio: lo que el dueño te contó y el sistema no sabe (sus objetivos, cómo trabaja, sus proveedores y clientes clave, lo que decidió, las correcciones que te hizo). El contexto maestro y el índice ya los tenés en las instrucciones; usala para abrir el archivo de un tema (por ejemplo "areas/compras_y_proveedores.md") antes de contestar algo que dependa de eso. Con ruta vacía o "contexto/INDICE.md" devuelve el índice con todos los archivos. Si no tenés el contexto maestro en tus instrucciones (por ejemplo, conectado por MCP), empezá por "contexto/contexto_maestro.md".',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'ruta' => [
                            'type'        => 'string',
                            'description' => 'La ruta del archivo, tal como aparece en el índice. Ejemplo: "areas/stock.md".',
                        ],
                    ],
                    'required'   => ['ruta'],
                ],
            ],
            [
                'name'         => self::GUARDAR,
                'description'  => 'Guarda en TU MEMORIA de este negocio algo valioso que te dijo el dueño y el sistema no sabe: un objetivo, una preferencia, cómo trabaja, un proveedor o un cliente clave y por qué, una decisión y su motivo, una corrección que te hizo, una fecha importante. 🔴 NUNCA guardes lo calculable (ventas, stock, saldos, precios, deudas, cantidades): eso se consulta con las herramientas y guardado queda viejo. Elegí el archivo del índice que corresponde (por ejemplo "areas/compras_y_proveedores.md" o "aprendizajes/correcciones.md"); si ninguno encaja podés crear uno nuevo dentro de dueno/, negocio/, areas/, decisiones/ o aprendizajes/. operacion "agregar" lo suma al final (lo normal); "reemplazar" pisa el archivo entero, o solo una sección si mandás seccion: usala para corregir algo que quedó viejo, después de leer el archivo. En el contexto maestro siempre decí la sección. contexto/INDICE.md no se escribe: lo arma el sistema. Si la respuesta trae "error", contá ese motivo. Cuando guardes, decíselo al dueño en una línea con "Me lo anoto.", sin pedir permiso (no "anotado", "guardado" ni "listo").',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'ruta'      => [
                            'type'        => 'string',
                            'description' => 'La ruta del archivo. Ejemplo: "areas/compras_y_proveedores.md".',
                        ],
                        'operacion' => [
                            'type'        => 'string',
                            'enum'        => [AgenteContextoHelper::AGREGAR, AgenteContextoHelper::REEMPLAZAR],
                            'description' => '"agregar" suma el texto al final (lo normal); "reemplazar" pisa el archivo (o la sección) con el texto.',
                        ],
                        'contenido' => [
                            'type'        => 'string',
                            'description' => 'El texto a guardar, en markdown simple y corto: una o dos líneas por dato, en tercera persona y con la fecha si importa. Ejemplo: "- Le compra la pintura a Pinturerías Rex porque le da 60 días (29/10/2026)." Con "reemplazar", el texto completo nuevo.',
                        ],
                        'resumen'   => [
                            'type'        => 'string',
                            'description' => 'Opcional: una línea (hasta 300 caracteres) de qué hay en el archivo, para el índice. Mandala cuando el archivo pasa a tratar de algo nuevo.',
                        ],
                        'motivo'    => [
                            'type'        => 'string',
                            'description' => 'Por qué lo guardás, en una línea (queda en el historial). Ejemplo: "El dueño me contó con quién trabaja la pintura".',
                        ],
                        'seccion'   => [
                            'type'        => 'string',
                            'description' => 'Opcional: el título de una sección «## …» del archivo (por ejemplo "Lo que le importa hoy" en el contexto maestro). La operación se aplica solo a esa sección; si no existe, se crea.',
                        ],
                    ],
                    'required'   => ['ruta', 'operacion', 'contenido', 'motivo'],
                ],
            ],
        ];
    }

    /**
     * Los nombres, en el mismo orden que definiciones().
     *
     * @return array<int, string>
     */
    public static function nombres()
    {
        return [self::LEER, self::GUARDAR];
    }

    /**
     * true si la tool es de este registro.
     *
     * @param  string  $tool_name
     * @return bool
     */
    public static function maneja($tool_name)
    {
        return in_array((string) $tool_name, self::nombres(), true);
    }

    /**
     * Ejecuta una de las dos herramientas.
     *
     * Lo que no se pudo hacer (una ruta que no existe, un tope) NO es un error técnico: viaja como
     * datos con `error`, para que el modelo lo cuente, igual que las demás herramientas. `is_error`
     * queda para lo que de verdad no corrió.
     *
     * @param  string  $tool_name
     * @param  array  $input
     * @param  \App\Models\AiConversation  $conversation
     * @return array{content: string, is_error: bool}
     */
    public static function ejecutar($tool_name, array $input, AiConversation $conversation)
    {
        $owner = User::find((int) $conversation->user_id);

        if (is_null($owner) || !AgentesIaHelper::tiene($owner)) {

            return self::resultado(['error' => 'La memoria del agente es parte de Agentes de IA, y este negocio no la tiene activa.'], false);
        }

        if ((string) $tool_name === self::LEER) {

            return self::resultado(self::leer($owner, $input), false);
        }

        return self::resultado(self::guardar($owner, $input, $conversation), false);
    }

    /**
     * leer_contexto: el archivo pedido, o el índice si no vino ruta.
     *
     * @param  \App\Models\User  $owner
     * @param  array  $input
     * @return array
     */
    protected static function leer(User $owner, array $input)
    {
        $ruta = AgenteContextoHelper::normalizar_ruta(isset($input['ruta']) ? (string) $input['ruta'] : '');

        if ($ruta === '' || mb_strtolower($ruta) === mb_strtolower(AgenteContextoHelper::INDICE)) {
            $ruta = AgenteContextoHelper::INDICE;
        }

        $archivo = AgenteContextoHelper::leer($owner, $ruta);

        if (is_null($archivo)) {

            return [
                'error'              => 'No existe "' . $ruta . '" en la memoria.',
                'rutas_disponibles'  => AgenteContextoHelper::rutas($owner),
            ];
        }

        return [
            'ruta'           => $archivo['ruta'],
            'titulo'         => $archivo['titulo'],
            'resumen'        => $archivo['resumen'],
            'contenido'      => $archivo['contenido'],
            'version'        => $archivo['version'],
            'vacio'          => AgenteContextoHelper::esta_vacio($archivo['contenido']),
            'actualizado_en' => $archivo['actualizado_en'],
        ];
    }

    /**
     * guardar_en_contexto: escribe por el único camino de escritura, con origen `agente` y la
     * conversación en la revisión.
     *
     * @param  \App\Models\User  $owner
     * @param  array  $input
     * @param  \App\Models\AiConversation  $conversation
     * @return array
     */
    protected static function guardar(User $owner, array $input, AiConversation $conversation)
    {
        $resultado = AgenteContextoHelper::guardar(
            $owner,
            isset($input['ruta']) ? (string) $input['ruta'] : '',
            isset($input['operacion']) ? (string) $input['operacion'] : '',
            isset($input['contenido']) ? (string) $input['contenido'] : '',
            isset($input['resumen']) ? (string) $input['resumen'] : null,
            isset($input['motivo']) ? (string) $input['motivo'] : null,
            AgenteContextoHelper::ORIGEN_AGENTE,
            $conversation->id,
            isset($input['seccion']) && trim((string) $input['seccion']) !== '' ? (string) $input['seccion'] : null
        );

        if (!$resultado['ok']) {

            return ['ok' => false, 'code' => $resultado['code'], 'error' => $resultado['error']];
        }

        return [
            'ok'          => true,
            'ruta'        => $resultado['archivo']['ruta'],
            'version'     => $resultado['archivo']['version'],
            'creado'      => $resultado['creado'],
            'sin_cambios' => $resultado['sin_cambios'],
            /*
             * 🔴 La nota le pide «Me lo anoto.» y no otra frase a propósito: «anotado», «guardado» o
             * «listo, lo guardé» las lee la guarda de cargas prometidas (TarjetaPrometidaIaHelper) como
             * una carga hecha en el negocio, y en un turno con acciones cambiaría el texto por uno de
             * «no pude cargarlo». «Me lo anoto.» pasa limpio (lo cubre la suite de la memoria).
             */
            'nota'        => $resultado['sin_cambios']
                ? 'Ya estaba en la memoria así: no cambió nada.'
                : 'Quedó en la memoria. Contáselo al dueño con un "Me lo anoto." y seguí con lo que te pidió.',
        ];
    }

    /**
     * El tool_result: JSON con el mismo fallback que el resto del asistente.
     *
     * @param  array  $datos
     * @param  bool  $is_error
     * @return array{content: string, is_error: bool}
     */
    protected static function resultado(array $datos, $is_error)
    {
        return [
            'content'  => json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_error' => (bool) $is_error,
        ];
    }
}
