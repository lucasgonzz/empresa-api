<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Services\AsistenteIa\Mcp\McpError;
use App\Services\AsistenteIa\Mcp\McpServidor;
use App\Services\AsistenteIa\Mcp\McpSesionHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El transporte del servidor MCP (misión asistente-mcp, 22/9/2026): Streamable HTTP, en su forma
 * más simple. JSON-RPC 2.0 sobre POST api/mcp, respuesta SIEMPRE application/json (nunca SSE), un
 * mensaje o un batch por request, sesión por header Mcp-Session-Id.
 *
 * Las tres rutas van adentro del gate del asistente (auth:sanctum + check_extencion_empresa:
 * asistente_ia + solo_el_dueno_ia): un cliente MCP es la misma persona que abre el chat, con un
 * token personal de Sanctum en vez de la cookie. Encima de eso, este controller exige que el token
 * tenga la habilidad `mcp` (la que da McpConexionController), así un token de otro uso no entra;
 * la sesión por cookie del SPA pasa (Sanctum le pone un TransientToken, que puede todo).
 *
 * Lo que decide cada código HTTP:
 *   400  el cuerpo no es JSON (-32700), un mensaje solo sin method (-32600), versión de protocolo no soportada (-32600)
 *   403  el token no tiene la habilidad mcp
 *   404  vino Mcp-Session-Id y no es una sesión de esta persona (-32001): el cliente re-inicializa
 *   202  el request eran solo notificaciones (sin id): se procesan y no hay nada que responder
 *   200  todo lo demás, incluidos los errores JSON-RPC de un método (van adentro del cuerpo)
 *   405  GET: este servidor no abre streams
 *   204  DELETE: la sesión quedó cerrada (o no había)
 *
 * 🔴 GET NO ABRE UN STREAM SSE A PROPÓSITO. La spec lo deja como opcional (el servidor puede
 * contestar 405) y acá no hay nada que empujar: ni notificaciones ni cambios de lista. Un stream
 * abierto por cliente sería un proceso PHP colgado por cliente en un hosting compartido.
 */
class McpController extends Controller
{
    /** Header con el id de sesión (lo emite initialize; el cliente lo repite). */
    const HEADER_SESION = 'Mcp-Session-Id';

    /** Header con la versión del protocolo que el cliente negoció. */
    const HEADER_VERSION = 'MCP-Protocol-Version';

    /** La habilidad de Sanctum que tiene que traer el token (McpConexionController la da). */
    const HABILIDAD = 'mcp';

    /** El nombre del cliente cuando initialize no trae clientInfo.name. */
    const CLIENTE_SIN_NOMBRE = 'cliente MCP';

    /**
     * POST api/mcp → uno o varios mensajes JSON-RPC.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function post(Request $request)
    {
        $version = $request->header(self::HEADER_VERSION);

        if (!is_null($version) && trim((string) $version) !== '' && !McpServidor::version_soportada(trim((string) $version))) {

            return $this->respuesta_json($this->error_json_rpc(null, new McpError(-32600, 'Versión de protocolo no soportada: ' . trim((string) $version))), 400);
        }

        $sin_habilidad = $this->rechazo_por_habilidad($request);

        if (!is_null($sin_habilidad)) {

            return $sin_habilidad;
        }

        $persona = UserHelper::user(false);
        $dueno = UserHelper::user(true);

        if (is_null($persona) || is_null($dueno)) {

            return response()->json(['message' => 'No se pudo resolver la persona o el dueño de la cuenta.'], 409);
        }

        $cuerpo = json_decode((string) $request->getContent(), true);

        if (!is_array($cuerpo)) {

            return $this->respuesta_json($this->error_json_rpc(null, new McpError(-32700, 'Cuerpo inválido: no es JSON')), 400);
        }

        $es_batch = self::es_lista($cuerpo);

        $mensajes = $es_batch ? $cuerpo : [$cuerpo];

        if (count($mensajes) === 0) {

            return $this->respuesta_json($this->error_json_rpc(null, new McpError(-32600, 'Batch vacío')), 400);
        }

        /*
         * La sesión: con header, se resuelve UNA vez para todo el request y un header que no cierra
         * con esta persona es 404 (la spec pide eso para que el cliente re-inicialice). Sin header,
         * la conversación se resuelve recién cuando un mensaje la necesita (tools/call): un ping o
         * un tools/list de un cliente sin sesiones no tiene por qué abrir una conversación.
         */
        $sesion_pedida = trim((string) $request->header(self::HEADER_SESION, ''));

        $conversation = null;

        if ($sesion_pedida !== '' && $this->hay_mensajes_que_no_son_initialize($mensajes)) {

            $conversation = McpSesionHelper::resolver($persona, $dueno, $sesion_pedida);

            if (is_null($conversation)) {

                return $this->respuesta_json($this->error_json_rpc(null, new McpError(-32001, 'Sesión inexistente: volvé a inicializar')), 404);
            }
        }

        $servidor = new McpServidor();

        $sesion_abierta = null;

        $respuestas = [];

        foreach ($mensajes as $mensaje) {

            $tiene_id = is_array($mensaje) && array_key_exists('id', $mensaje);

            $id = $tiene_id ? $mensaje['id'] : null;

            if (!is_array($mensaje) || !isset($mensaje['method']) || !is_string($mensaje['method'])) {

                // Un mensaje sin method no es ni request ni notificación: es inválido y se contesta.
                $respuestas[] = $this->error_json_rpc($id, new McpError(-32600, 'Mensaje inválido: falta method'));

                continue;
            }

            $metodo = $mensaje['method'];

            $params = isset($mensaje['params']) && is_array($mensaje['params']) ? $mensaje['params'] : [];

            // Definida ANTES del try: el catch la lee para el log, y si abrir() lanza en el primer
            // mensaje todavía no se asignó (hallazgo del verificador: notice adentro del catch → 500).
            $de_este_mensaje = null;

            try {

                if ($metodo === 'initialize') {

                    $sesion_abierta = McpSesionHelper::abrir($persona, $dueno, $this->nombre_del_cliente($params));

                    $de_este_mensaje = $sesion_abierta;

                } elseif ($metodo === 'tools/call') {

                    if (is_null($conversation)) {

                        // Sin header: la última conversación MCP de las 6 horas, o una nueva.
                        $conversation = McpSesionHelper::resolver($persona, $dueno, null);
                    }

                    $de_este_mensaje = $conversation;

                } else {

                    $de_este_mensaje = $conversation;
                }

                $resultado = $servidor->atender($metodo, $params, $persona, $dueno, $de_este_mensaje);

                if (!$tiene_id) {

                    // Notificación: se procesó y no produce respuesta.
                    continue;
                }

                $respuestas[] = [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => is_null($resultado) ? new \stdClass() : $resultado,
                ];

            } catch (McpError $e) {

                if ($tiene_id) {

                    $respuestas[] = $this->error_json_rpc($id, $e);
                }

            } catch (\Throwable $e) {

                Log::error('McpController: error inesperado atendiendo ' . $metodo, [
                    'ai_conversation_id' => $de_este_mensaje instanceof AiConversation ? (int) $de_este_mensaje->id : null,
                    'persona_id'         => (int) $persona->id,
                    'error'              => $e->getMessage(),
                ]);

                if ($tiene_id) {

                    // Sin stack en la respuesta: el mensaje alcanza para que el cliente lo muestre.
                    $respuestas[] = $this->error_json_rpc($id, new McpError(-32603, $e->getMessage()));
                }
            }
        }

        if (count($respuestas) === 0) {

            return $this->con_sesion(response('', 202), $sesion_abierta);
        }

        $status = 200;

        if (!$es_batch && isset($respuestas[0]['error']) && in_array($respuestas[0]['error']['code'], [-32600, -32700], true)) {

            $status = 400;
        }

        return $this->con_sesion($this->respuesta_json($es_batch ? $respuestas : $respuestas[0], $status), $sesion_abierta);
    }

    /**
     * GET api/mcp → 405. Ver el 🔴 del docblock de la clase.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function get()
    {
        return response()->json(
            ['message' => 'El servidor MCP no abre streams: mandá los mensajes por POST.'],
            405,
            ['Allow' => 'POST, DELETE'],
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * DELETE api/mcp → cierra la sesión del header, si es de esta persona. 204 siempre: cerrar
     * una sesión que ya no existe no es un error para nadie.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function delete(Request $request)
    {
        $sin_habilidad = $this->rechazo_por_habilidad($request);

        if (!is_null($sin_habilidad)) {

            return $sin_habilidad;
        }

        $persona = UserHelper::user(false);
        $dueno = UserHelper::user(true);

        $sesion_pedida = trim((string) $request->header(self::HEADER_SESION, ''));

        if (!is_null($persona) && !is_null($dueno) && $sesion_pedida !== '') {

            $conversation = McpSesionHelper::resolver($persona, $dueno, $sesion_pedida);

            if (!is_null($conversation)) {

                McpSesionHelper::cerrar($conversation);
            }
        }

        return response('', 204);
    }

    /**
     * 403 si el token con el que entró no tiene la habilidad `mcp`; null si puede seguir.
     *
     * 🔴 Se pregunta sobre $request->user() y NO sobre UserHelper::user(false): éste hace un
     * User::find() nuevo, y ese modelo no tiene el access token colgado, así que tokenCan() daría
     * false hasta para el token correcto.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function rechazo_por_habilidad(Request $request)
    {
        $autenticado = $request->user();

        if (is_null($autenticado) || !method_exists($autenticado, 'tokenCan') || !$autenticado->tokenCan(self::HABILIDAD)) {

            return response()->json(
                ['message' => 'Esta clave no sirve para el servidor MCP: generá una desde la configuración del asistente.'],
                403,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }

        return null;
    }

    /**
     * true si el cuerpo es una lista (un batch) y no un objeto (un mensaje).
     *
     * @param  array  $cuerpo
     * @return bool
     */
    protected static function es_lista(array $cuerpo): bool
    {
        if (count($cuerpo) === 0) {

            return true;
        }

        return array_keys($cuerpo) === range(0, count($cuerpo) - 1);
    }

    /**
     * true si algún mensaje del request necesita la sesión del header (todo salvo initialize).
     *
     * @param  array  $mensajes
     * @return bool
     */
    protected function hay_mensajes_que_no_son_initialize(array $mensajes): bool
    {
        foreach ($mensajes as $mensaje) {

            if (!is_array($mensaje) || !isset($mensaje['method']) || $mensaje['method'] !== 'initialize') {

                return true;
            }
        }

        return false;
    }

    /**
     * `params.clientInfo.name` del initialize, o el nombre genérico.
     *
     * @param  array  $params
     * @return string
     */
    protected function nombre_del_cliente(array $params): string
    {
        $nombre = isset($params['clientInfo']) && is_array($params['clientInfo']) && isset($params['clientInfo']['name'])
            ? trim((string) $params['clientInfo']['name'])
            : '';

        return $nombre !== '' ? $nombre : self::CLIENTE_SIN_NOMBRE;
    }

    /**
     * Una respuesta JSON-RPC de error.
     *
     * @param  mixed  $id
     * @param  McpError  $error
     * @return array<string, mixed>
     */
    protected function error_json_rpc($id, McpError $error): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $error->a_json_rpc(),
        ];
    }

    /**
     * La respuesta JSON, con los acentos sin escapar (es lo que un cliente le muestra a una
     * persona).
     *
     * @param  mixed  $payload
     * @param  int  $status
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_json($payload, $status)
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Le suma el header Mcp-Session-Id a la respuesta cuando este request abrió una sesión.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $respuesta
     * @param  \App\Models\AiConversation|null  $sesion_abierta
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function con_sesion($respuesta, $sesion_abierta)
    {
        if ($sesion_abierta instanceof AiConversation && !is_null($sesion_abierta->mcp_sesion)) {

            $respuesta->headers->set(self::HEADER_SESION, (string) $sesion_abierta->mcp_sesion);
        }

        return $respuesta;
    }
}
