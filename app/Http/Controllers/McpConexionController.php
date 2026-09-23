<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\LinkDePdfIaHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;

/**
 * La clave de conexión al servidor MCP (misión asistente-mcp, contrato 2 del plan, 22/9/2026):
 * lo que el modal de configuración del asistente usa para "Conectá tu asistente a Claude y a
 * otras apps".
 *
 *   GET    api/mcp/conexion  → el estado: si hay clave, desde cuándo, último uso, y la URL del servidor
 *   POST   api/mcp/conexion  → crea la clave (revocando la anterior) y la devuelve UNA sola vez
 *   DELETE api/mcp/conexion  → la revoca
 *
 * La clave es un token personal de Sanctum (`personal_access_tokens`) de la PERSONA, con nombre
 * `mcp` y la habilidad `mcp`, que es la que McpController exige. Es el primer createToken() del
 * repo: hasta hoy el SPA se autenticaba solo por cookie. La tabla existe desde 2019 en todas las
 * bases, así que no hay tarea manual de despliegue.
 *
 * 🔴 EL TOKEN VIAJA UNA SOLA VEZ, EN EL 201 DEL POST. Sanctum guarda el hash y el texto plano no
 * se puede recuperar: el GET nunca lo devuelve. Si el dueño lo perdió, genera otro (y el anterior
 * deja de servir en ese mismo POST: una persona tiene UNA clave viva, así "revocar" es una sola
 * cosa que hacer).
 *
 * 🔴 ESTAS TRES RUTAS SE USAN DESDE EL SISTEMA, NO DESDE UN CLIENTE MCP. Van en el mismo gate que
 * el chat (auth:sanctum + extensión + solo el dueño), y encima se exige que la sesión sea la del
 * SPA (cookie → TransientToken) y no un token: si un token `mcp` pudiera crear o revocar tokens,
 * una clave filtrada alcanzaría para fabricarse otra que sobreviva a la revocación. La clave se
 * administra sentado frente al sistema.
 *
 * `url` sale de LinkDePdfIaHelper::base_publica(): es la misma base pública (users.api_url
 * normalizada) que ya usan los links de PDF, y es lo único que un cliente de afuera puede
 * alcanzar.
 */
class McpConexionController extends Controller
{
    /** Nombre del token en personal_access_tokens. */
    const NOMBRE = 'mcp';

    /** La habilidad que McpController::HABILIDAD exige. */
    const HABILIDADES = ['mcp'];

    /** Nombre con el que los ejemplos registran el servidor en cada cliente. */
    const NOMBRE_DEL_SERVIDOR = 'comerciocity';

    /** El header beta que la API de Anthropic pide para usar un conector MCP. */
    const ANTHROPIC_BETA = 'mcp-client-2025-11-20';

    /**
     * GET api/mcp/conexion → el estado de la clave de la persona.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function estado(Request $request): JsonResponse
    {
        $rechazo = $this->rechazo_si_no_es_la_sesion_del_sistema($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $persona = UserHelper::user(false);
        $dueno = UserHelper::user(true);

        if (is_null($persona) || is_null($dueno)) {

            return response()->json(['message' => 'No se pudo resolver la persona o el dueño de la cuenta.'], 409);
        }

        $token = $this->token_vigente($persona);

        return response()->json([
            'activa'        => !is_null($token),
            'nombre'        => self::NOMBRE,
            'creada_at'     => is_null($token) || is_null($token->created_at) ? null : $token->created_at->toIso8601String(),
            'ultimo_uso_at' => is_null($token) || is_null($token->last_used_at) ? null : $token->last_used_at->toIso8601String(),
            'url'           => $this->url_del_servidor($dueno),
        ], 200);
    }

    /**
     * POST api/mcp/conexion → revoca la clave anterior de la persona y crea una nueva.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function crear(Request $request): JsonResponse
    {
        $rechazo = $this->rechazo_si_no_es_la_sesion_del_sistema($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $persona = UserHelper::user(false);
        $dueno = UserHelper::user(true);

        if (is_null($persona) || is_null($dueno)) {

            return response()->json(['message' => 'No se pudo resolver la persona o el dueño de la cuenta.'], 409);
        }

        // Una clave viva por persona: la anterior deja de autenticar en este mismo request.
        $persona->tokens()->where('name', self::NOMBRE)->delete();

        $nuevo = $persona->createToken(self::NOMBRE, self::HABILIDADES);

        $token = $nuevo->plainTextToken;
        $url = $this->url_del_servidor($dueno);

        $creada_at = is_null($nuevo->accessToken->created_at) ? null : $nuevo->accessToken->created_at->toIso8601String();

        return response()->json([
            'token'         => $token,
            'url'           => $url,
            'activa'        => true,
            'nombre'        => self::NOMBRE,
            'creada_at'     => $creada_at,
            'ultimo_uso_at' => null,
            'ejemplos'      => $this->ejemplos($url, $token),
        ], 201, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * DELETE api/mcp/conexion → revoca la clave de la persona.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function revocar(Request $request): JsonResponse
    {
        $rechazo = $this->rechazo_si_no_es_la_sesion_del_sistema($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $persona = UserHelper::user(false);
        $dueno = UserHelper::user(true);

        if (is_null($persona) || is_null($dueno)) {

            return response()->json(['message' => 'No se pudo resolver la persona o el dueño de la cuenta.'], 409);
        }

        $persona->tokens()->where('name', self::NOMBRE)->delete();

        return response()->json([
            'activa'        => false,
            'nombre'        => self::NOMBRE,
            'creada_at'     => null,
            'ultimo_uso_at' => null,
            'url'           => $this->url_del_servidor($dueno),
        ], 200);
    }

    /**
     * La config lista para pegar en cada cliente, como strings ya formateados.
     *
     * - Claude Code: el comando `claude mcp add` con transporte http y el header.
     * - Claude Desktop: el bloque de claude_desktop_config.json vía mcp-remote. 🔴 El header va por
     *   variable de entorno (`env.AUTH_HEADER`) y no inline en `args`: mcp-remote tiene un bug
     *   conocido con espacios dentro de los args en Claude Desktop, y "Bearer <token>" tiene uno.
     *   Por eso el arg es `Authorization:${AUTH_HEADER}` sin espacio y el espacio vive adentro del
     *   valor de la variable.
     * - API de Anthropic: el fragmento del body con las DOS mitades —`mcp_servers` y el
     *   `mcp_toolset` en `tools`—, porque sin la segunda la API rechaza el request con un error de
     *   validación; y arriba, como comentario, el header beta que el request tiene que llevar.
     *
     * @param  string  $url
     * @param  string  $token
     * @return array<string, string>
     */
    protected function ejemplos($url, $token): array
    {
        $opciones = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        $claude_code = 'claude mcp add --transport http ' . self::NOMBRE_DEL_SERVIDOR . ' ' . $url
            . ' --header "Authorization: Bearer ' . $token . '"';

        // Comillas simples a propósito: `${AUTH_HEADER}` es literal para mcp-remote, no para PHP.
        $claude_desktop = json_encode([
            'mcpServers' => [
                self::NOMBRE_DEL_SERVIDOR => [
                    'command' => 'npx',
                    'args'    => ['-y', 'mcp-remote', $url, '--header', 'Authorization:${AUTH_HEADER}'],
                    'env'     => ['AUTH_HEADER' => 'Bearer ' . $token],
                ],
            ],
        ], $opciones);

        $anthropic_api = '// El request lleva el header anthropic-beta: ' . self::ANTHROPIC_BETA . "\n"
            . json_encode([
                'mcp_servers' => [
                    [
                        'type'                => 'url',
                        'url'                 => $url,
                        'name'                => self::NOMBRE_DEL_SERVIDOR,
                        'authorization_token' => $token,
                    ],
                ],
                'tools'       => [
                    [
                        'type'            => 'mcp_toolset',
                        'mcp_server_name' => self::NOMBRE_DEL_SERVIDOR,
                    ],
                ],
            ], $opciones);

        return [
            'claude_code'    => $claude_code,
            'claude_desktop' => (string) $claude_desktop,
            'anthropic_api'  => $anthropic_api,
        ];
    }

    /**
     * El token `mcp` más reciente de la persona, o null.
     *
     * @param  \App\Models\User  $persona
     * @return \Laravel\Sanctum\PersonalAccessToken|null
     */
    protected function token_vigente($persona)
    {
        return $persona->tokens()->where('name', self::NOMBRE)->orderBy('id', 'DESC')->first();
    }

    /**
     * La URL del servidor MCP de este negocio: la base pública de su API más /api/mcp.
     *
     * @param  \App\Models\User  $dueno
     * @return string
     */
    protected function url_del_servidor($dueno): string
    {
        return LinkDePdfIaHelper::base_publica($dueno->id) . '/api/mcp';
    }

    /**
     * 403 si quien entró lo hizo con un token y no con la sesión del sistema; null si puede
     * seguir. Ver el 🔴 del docblock de la clase. Se pregunta sobre $request->user() porque es el
     * modelo que tiene el access token colgado (UserHelper::user() hace un find nuevo, sin él).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse|null
     */
    protected function rechazo_si_no_es_la_sesion_del_sistema(Request $request)
    {
        $autenticado = $request->user();

        $token_actual = is_null($autenticado) || !method_exists($autenticado, 'currentAccessToken')
            ? null
            : $autenticado->currentAccessToken();

        if (!($token_actual instanceof TransientToken)) {

            return response()->json(
                ['message' => 'La clave de conexión se administra desde el sistema, no desde un cliente MCP.'],
                403,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }

        return null;
    }
}
