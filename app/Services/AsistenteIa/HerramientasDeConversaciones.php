<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\asistente_ia\ConversacionesAnterioresIaHelper;
use App\Models\AiConversation;

/**
 * Las dos herramientas de la memoria de CONVERSACIONES ANTERIORES (misión agente-memoria-y-sin-techo,
 * 30/10/2026): `buscar_conversaciones_anteriores` y `leer_conversacion_anterior`. Definición y despacho
 * juntos, igual que HerramientasDeContexto: una tool declarada en un solo lado es una tool que la IA
 * llama y recibe «Tool desconocida». La lógica (la tenencia, la búsqueda, la lectura) vive en
 * ConversacionesAnterioresIaHelper.
 *
 * 🔴 EL CASO REAL (Ferre Total, WhatsApp, Pablo): el 29/10 el agente registró en la conversación 23 el
 * Pago N° 1706 a Pinturas Sudamericanas con 5 e-cheq, y al otro día, en la 28 («¿Podemos seguir con lo
 * último de ayer? ¿Lo del pago de Pinturería Sudamericana?»), contestó «No tengo el detalle de lo que
 * hablamos ayer: esta conversación no trae el historial anterior». Las charlas de WhatsApp se parten
 * en varias conversaciones (por tema y por el corte de horas sin hablar) y el historial que viaja es
 * solo el de la actual; no había ninguna herramienta para mirar otra.
 *
 * 🔴 POR QUÉ UN REGISTRO APARTE. Son de LECTURA, pero no van en AsistenteIaService::
 * registro_de_lectura(): aquel registro es el prefijo cacheado de las tools y lo comparte el MCP
 * (McpHerramientasHelper lo publica entero). Estas dos van AL FINAL de build_tools() —después de las de
 * la app—, así el prefijo de todo lo de antes queda idéntico, y el MCP NO las tiene: un cliente MCP no
 * tiene una conversación propia que valga (la suya es el registro técnico de sus tools).
 *
 * 🔴 VAN SIEMPRE, en sistema, WhatsApp y app, con o sin acciones y SIN depender de `agentes_ia`: es leer
 * la propia charla de la persona, no un módulo. La conversación del turno define a quién se le puede
 * leer (mismo dueño Y misma persona); el dueño y la persona salen SIEMPRE de ella, nunca de Auth (esto
 * corre adentro del job, sin sesión).
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class HerramientasDeConversaciones
{
    const BUSCAR = 'buscar_conversaciones_anteriores';

    const LEER = 'leer_conversacion_anterior';

    /**
     * Las definiciones para la API (forma de Anthropic: name / description / input_schema).
     *
     * Las descripciones le dicen al modelo CUÁNDO usarlas (la persona nombra algo de otra charla) y qué
     * hacer con lo que lee (es contexto: los datos del negocio se vuelven a consultar), porque el error
     * que tapan es justamente que el modelo no sabía que podía mirar.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definiciones()
    {
        return [
            [
                'name'         => self::BUSCAR,
                'description'  => 'Busca entre TUS conversaciones anteriores con esta persona (las de otros días, y también las de hoy que quedaron en otra conversación: en WhatsApp una misma charla se parte en varias conversaciones por tiempo o por tema). Usala cuando la persona habla de algo que no está en esta conversación ("lo de ayer", "lo último que hablamos", "seguimos con lo del pago", "cuando me cargaste…") y no sabés en cuál de tus últimas conversaciones está (la lista de las últimas ya la tenés en las instrucciones). Con `texto` busca palabras clave: cada palabra de 3 letras o más tiene que aparecer en el título, en un mensaje o en el resultado de una carga confirmada; mandá de una a tres palabras distintivas (el nombre del proveedor o del cliente, el número del pago o de la venta), no la frase entera. Con `desde` / `hasta` acota por fecha. Sin nada, devuelve las más recientes. Esta conversación no aparece. Devuelve cada conversación con su conversacion_id, título, fechas, canal, cuántos mensajes tiene, hasta 3 cargas confirmadas y hasta 2 extractos donde aparece lo buscado; después abrí la que corresponda con leer_conversacion_anterior.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'texto'  => [
                            'type'        => 'string',
                            'description' => 'Palabras clave a buscar (todas tienen que aparecer). Ejemplo: "Sudamericana cheques".',
                        ],
                        'desde'  => [
                            'type'        => 'string',
                            'description' => 'Opcional: solo conversaciones con mensajes desde este día, AAAA-MM-DD. Para "ayer", el día de ayer en desde y en hasta.',
                        ],
                        'hasta'  => [
                            'type'        => 'string',
                            'description' => 'Opcional: solo conversaciones con mensajes hasta este día (incluido), AAAA-MM-DD.',
                        ],
                        'limite' => [
                            'type'        => 'integer',
                            'description' => 'Cuántas conversaciones como mucho (de 1 a ' . ConversacionesAnterioresIaHelper::LIMITE_MAXIMO . '; si no lo mandás, ' . ConversacionesAnterioresIaHelper::LIMITE_POR_DEFECTO . ').',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'         => self::LEER,
                'description'  => 'Abre una de TUS conversaciones con esta persona y te devuelve lo que se habló: los mensajes más nuevos que entran, en orden, con quién lo dijo, la fecha, las cargas de cada mensaje (con su estado y su resultado, por ejemplo "estado: confirmada (Pago N° 1706 registrado)") y las fotos que mandó la persona (con su [imagen_id …] solo si es ESTA conversación: las de otra no se pueden usar desde acá). Usala cuando la persona se refiere a algo de otra charla: el conversacion_id es el número que va después de # en la lista de tus últimas conversaciones de las instrucciones, o el que te devolvió buscar_conversaciones_anteriores. Podés abrir varias. Sin conversacion_id abre ESTA conversación: sirve para releer su principio cuando es larga y ya no lo tenés a la vista. Si la respuesta trae hay_anteriores en true, pedí los mensajes de antes con antes_de_mensaje = antes_de_mensaje_para_seguir. Lo que leas es contexto: los datos del negocio (saldos, stock, cheques, precios) volvé a consultarlos con las herramientas antes de afirmarlos, y lo que ahí ya quedó cargado ya está hecho (no lo vuelvas a proponer salvo que te lo pidan); una carga que ahí quedó esperando el sí no se confirma desde acá: si la persona la quiere, armala de nuevo. Si la respuesta trae "error", esa conversación no es de esta persona o no existe: no la nombres.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'conversacion_id'  => [
                            'type'        => 'integer',
                            'description' => 'El número de la conversación (el de después de # en la lista, o el conversacion_id de la búsqueda). Si no lo mandás, abre esta misma conversación.',
                        ],
                        'antes_de_mensaje' => [
                            'type'        => 'integer',
                            'description' => 'Opcional: para seguir leyendo hacia atrás, el antes_de_mensaje_para_seguir que te devolvió la lectura anterior.',
                        ],
                    ],
                    'required'   => [],
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
        return [self::BUSCAR, self::LEER];
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
     * Ejecuta una de las dos herramientas sobre las conversaciones de la persona de `$conversation`.
     *
     * Una conversación ajena o que no existe, o una fecha mal escrita, NO es un error técnico: viaja
     * como dato con `error`, para que el modelo lo cuente, igual que las demás herramientas. `is_error`
     * queda para lo que de verdad no corrió (y eso lo atrapa execute_tool_calls()).
     *
     * Devuelve además (clave aditiva, no viaja al modelo) `cargas_vistas`: el texto del resultado de cada
     * carga CONFIRMADA de OTRA conversación que la herramienta le mostró al modelo —las de la página que
     * devolvió leer_, y las (hasta 3 por conversación) que mostró buscar_—. Las usa la guarda de la carga
     * prometida (rondas de arreglos R2 y F1): ver AsistenteIaService::sin_lo_dicho_de_otras_conversaciones().
     *
     * @param  string  $tool_name
     * @param  array  $input
     * @param  \App\Models\AiConversation  $conversation  La conversación del turno: define la tenencia.
     * @return array{content: string, is_error: bool, cargas_vistas: array<int, string>}
     */
    public static function ejecutar($tool_name, array $input, AiConversation $conversation)
    {
        if ((string) $tool_name === self::BUSCAR) {

            return self::resultado(ConversacionesAnterioresIaHelper::buscar($conversation, $input), false);
        }

        return self::resultado(ConversacionesAnterioresIaHelper::leer($conversation, $input), false);
    }

    /**
     * El tool_result: JSON con el mismo fallback que el resto del asistente, y las cargas confirmadas de
     * OTRAS conversaciones que muestra (sin la actual: lo de esta conversación ya viaja en su historial).
     *
     * 🔴 SOLO LO QUE EL MODELO VIO (segunda ronda de arreglos, F1, 30/10/2026). La primera versión juntaba
     * los ids de TODAS las conversaciones que mostraba buscar_ (hasta 20) y la guarda tomaba TODAS sus
     * cargas confirmadas: con tantos registros, «Cargué el gasto de nafta por $7 mil» se escondía porque
     * en alguna había un «Gasto N° 7». Ahora son solo los textos que el modelo tuvo delante:
     *   - leer_: las cargas en «estado: confirmada (…)» de los mensajes de ESA página (el texto entre
     *     paréntesis es el de su resultado, ver AccionesIaHelper::linea_de_historial());
     *   - buscar_: las `cargas` de cada conversación encontrada (hasta 3, ya son solo confirmadas).
     *
     * @param  array  $datos
     * @param  bool  $is_error
     * @return array{content: string, is_error: bool, cargas_vistas: array<int, string>}
     */
    protected static function resultado(array $datos, $is_error)
    {
        $vistas = [];

        if (isset($datos['mensajes']) && is_array($datos['mensajes']) && empty($datos['es_esta_conversacion'])) {
            foreach ($datos['mensajes'] as $mensaje) {
                if (!is_array($mensaje) || !isset($mensaje['cargas']) || !is_array($mensaje['cargas'])) {
                    continue;
                }

                foreach ($mensaje['cargas'] as $linea) {
                    if (preg_match('/· estado: confirmada \((.+)\)\]\s*$/su', (string) $linea, $coincidencia)) {
                        $vistas[] = $coincidencia[1];
                    }
                }
            }
        }

        if (isset($datos['conversaciones']) && is_array($datos['conversaciones'])) {
            foreach ($datos['conversaciones'] as $encontrada) {
                if (is_array($encontrada) && isset($encontrada['cargas']) && is_array($encontrada['cargas'])) {
                    foreach ($encontrada['cargas'] as $carga) {
                        $vistas[] = (string) $carga;
                    }
                }
            }
        }

        return [
            'content'       => json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_error'      => (bool) $is_error,
            'cargas_vistas' => array_values(array_unique(array_filter($vistas, 'strlen'))),
        ];
    }
}
