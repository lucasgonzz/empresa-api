<?php

namespace App\Services\AsistenteIa\Mcp;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Models\AiConversation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * La sesión MCP ↔ la conversación del asistente (misión asistente-mcp, contrato 3 del plan,
 * 22/9/2026).
 *
 * Un cliente MCP externo (Claude Desktop, Claude Code, la API de Anthropic, Cursor…) inicializa una
 * sesión contra POST api/mcp y recibe un `Mcp-Session-Id`, que repite en cada request. De este lado
 * esa sesión ES una AiConversation con origen 'mcp': cada tool de CARGA que pida el cliente deja su
 * par de mensajes (canal 'mcp') y su tarjeta colgados de ahí, y el dueño la ve en el panel del chat
 * como cualquier otra. Puede confirmar las tarjetas desde la pantalla con el botón, o el cliente MCP
 * por texto con confirmar_carga_pendiente en una llamada posterior.
 *
 * 🔴 LA TENENCIA VA POR LA PERSONA, NO SOLO POR EL ID DE SESIÓN. El id es aleatorio y largo, pero
 * un id que llegue con el token de OTRA persona del mismo negocio no puede abrirle la conversación
 * ajena: `resolver()` filtra por `auth_user_id` y por `user_id`, igual que la tenencia doble de
 * AiConversationController. Un id que no cierra con la persona es "sesión inexistente" y el
 * cliente vuelve a inicializar, como dice la spec.
 *
 * 🔴 SIN HEADER DE SESIÓN NO SE ABRE UNA CONVERSACIÓN POR REQUEST. Un cliente que no maneja
 * sesiones (la API de Anthropic con `mcp_servers`, por ejemplo, puede no repetir el header) haría
 * una conversación nueva por cada tool call, y el panel del dueño se llenaría de hilos de un solo
 * mensaje. Se reusa la última conversación MCP de la persona que habló hace menos de
 * AsistenteCanalHelper::HORAS_CORTE, que es el mismo corte por tiempo que ya usa WhatsApp para
 * decidir si "seguimos hablando de lo mismo".
 */
class McpSesionHelper
{
    /** Largo del título de la conversación (ai_conversations.titulo es string(150)). */
    const LARGO_TITULO = 150;

    /** Bytes aleatorios del id de sesión: 20 bytes → 40 caracteres en hex. */
    const BYTES_DE_SESION = 20;

    /** El nombre que va en el título cuando el cliente no manda sesión ni clientInfo. */
    const NOMBRE_SIN_SESION = 'sin sesión';

    /**
     * Abre la conversación de una sesión MCP nueva y la devuelve.
     *
     * @param  \App\Models\User  $persona  Quien se autenticó con el token (auth_user_id).
     * @param  \App\Models\User  $dueno  El dueño de la cuenta (user_id), por quien filtran las tools.
     * @param  string  $nombre_del_cliente  `clientInfo.name` del initialize, o lo que se sepa.
     * @return \App\Models\AiConversation
     */
    public static function abrir(User $persona, User $dueno, $nombre_del_cliente)
    {
        $nombre = trim((string) $nombre_del_cliente);

        if ($nombre === '') {

            $nombre = self::NOMBRE_SIN_SESION;
        }

        return AiConversation::create([
            'user_id'         => $dueno->id,
            'auth_user_id'    => $persona->id,
            'origen'          => AiConversation::ORIGEN_MCP,
            'titulo'          => self::titulo($nombre),
            'mcp_sesion'      => bin2hex(random_bytes(self::BYTES_DE_SESION)),
            'last_message_at' => Carbon::now(),
        ]);
    }

    /**
     * La conversación de un request MCP.
     *
     * Con id de sesión: la conversación con ese `mcp_sesion` que sea de esta persona, o null si no
     * existe (el controller contesta 404 y el cliente re-inicializa). Sin id: la última conversación
     * MCP de la persona que habló hace menos de HORAS_CORTE, o una nueva titulada 'sin sesión'.
     *
     * @param  \App\Models\User  $persona
     * @param  \App\Models\User  $dueno
     * @param  string|null  $sesion_id  El header Mcp-Session-Id, o null si no vino.
     * @return \App\Models\AiConversation|null
     */
    public static function resolver(User $persona, User $dueno, $sesion_id = null)
    {
        $sesion_id = is_null($sesion_id) ? '' : trim((string) $sesion_id);

        if ($sesion_id !== '') {

            return AiConversation::where('mcp_sesion', $sesion_id)
                                    ->where('auth_user_id', $persona->id)
                                    ->where('user_id', $dueno->id)
                                    ->first();
        }

        $reciente = AiConversation::where('auth_user_id', $persona->id)
                                    ->where('user_id', $dueno->id)
                                    ->where('origen', AiConversation::ORIGEN_MCP)
                                    ->whereNotNull('last_message_at')
                                    ->where('last_message_at', '>', Carbon::now()->subHours(AsistenteCanalHelper::HORAS_CORTE))
                                    ->orderBy('last_message_at', 'DESC')
                                    ->orderBy('id', 'DESC')
                                    ->first();

        if (!is_null($reciente)) {

            return $reciente;
        }

        return self::abrir($persona, $dueno, self::NOMBRE_SIN_SESION);
    }

    /**
     * Cierra la sesión: la conversación queda (con sus mensajes y sus tarjetas, que el dueño sigue
     * viendo en el panel) pero ese id ya no la abre.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @return void
     */
    public static function cerrar(AiConversation $conversation)
    {
        $conversation->mcp_sesion = null;
        $conversation->save();
    }

    /**
     * 'Conexión MCP · <cliente> · dd/mm HH:mm', recortado al largo de la columna.
     *
     * Lleva la hora porque el mismo cliente puede abrir varias sesiones en el día y el dueño tiene
     * que poder distinguirlas en la lista del panel.
     *
     * @param  string  $nombre_del_cliente
     * @return string
     */
    protected static function titulo($nombre_del_cliente)
    {
        $titulo = 'Conexión MCP · ' . $nombre_del_cliente . ' · ' . Carbon::now()->format('d/m H:i');

        return Str::limit($titulo, self::LARGO_TITULO, '');
    }
}
