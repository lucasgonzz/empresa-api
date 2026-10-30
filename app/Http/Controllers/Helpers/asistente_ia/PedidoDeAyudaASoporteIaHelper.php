<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Events\SupportMessageReceived;
use App\Http\Controllers\Helpers\SupportSyncHelper;
use App\Http\Controllers\Helpers\SupportTicketHelper;
use App\Models\AiMessage;
use App\Models\SupportMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Herramienta pedir_ayuda_a_soporte del agente personal (misión agente-mejoras-uso-real, 29/10/2026,
 * §4 E3.3 del plan): el agente le deja el pedido al equipo de ComercioCity en el chat de soporte que ya
 * existe (el de Ayuda del sistema: SupportTicket / SupportMessage, sincronizado al admin).
 *
 * EL CASO REAL (CONV 24 de Ferre Total, 29/10/2026): después de diez vueltas con la promo, el dueño
 * escribió *"Le pedis a lucas que vea este tema"* y el agente contestó *"No puedo escribirle a Lucas:
 * solo puedo mandar mensajes a clientes del negocio"* y le ofreció una tarea en la agenda. El canal para
 * eso existía: el chat de soporte del propio negocio con ComercioCity.
 *
 * CÓMO QUEDA EL PEDIDO. Un SupportMessage del usuario (sender_type 'user', la PERSONA que habla con el
 * agente, igual que cuando escribe a mano en el chat de soporte) en su ticket abierto
 * (SupportTicketHelper::get_or_create_open_ticket), con el cuerpo:
 *
 *     [Pedido desde el agente personal por WhatsApp] <el detalle que redactó el modelo>
 *
 *     Últimas vueltas de la conversación con el agente:
 *     - Pablo (18:12): Si lo tiene ya lo cargue en el sistema
 *     - Agente (18:12): Puede que esté cargado, pero...
 *
 * y se sincroniza al admin con SupportSyncHelper::sync_message_to_admin(). Si la sincronización falla,
 * el mensaje queda guardado con `remote_delivery_status = not_received` (lo mismo que hace el chat de
 * soporte) y el comando `support:retry-pending-syncs` lo reintenta cada 5 minutos: el pedido no se pierde.
 *
 * 🔴 LAS GUARDAS:
 *   - SIN TARJETA, pero solo cuando la persona lo pide o acepta (lo dice la descripción de la herramienta):
 *     es un mensaje a ComercioCity en nombre de la persona, no a un tercero.
 *   - TOPE_POR_DIA pedidos por persona y por día (se cuentan los mensajes del agente en su ticket): un
 *     modelo que se trabe en un loop no le llena el chat de soporte a nadie.
 *   - EXCLUIDA DEL MCP (McpHerramientasHelper::EXCLUIDAS): un agente externo no le escribe a soporte en
 *     nombre del dueño.
 *   - Sin las tablas de soporte (un cliente que todavía no las tiene) contesta el motivo exacto.
 *
 * PHP 7.4: sin match, sin nullsafe, sin argumentos nombrados.
 */
class PedidoDeAyudaASoporteIaHelper
{
    /** Cuántos pedidos al día puede dejar el agente por persona. */
    const TOPE_POR_DIA = 3;

    /** Cuántas vueltas de la conversación (mensaje de la persona + respuesta) viajan con el pedido. */
    const VUELTAS_DE_CONTEXTO = 6;

    /** Largo máximo del detalle que redacta el modelo. */
    const MAX_DETALLE = 2000;

    /** Largo máximo de cada mensaje citado en el contexto. */
    const MAX_POR_MENSAJE = 600;

    /** Cómo empieza el cuerpo de todo pedido del agente: con esto se cuentan para el tope. */
    const PREFIJO = '[Pedido desde el agente personal';

    /** Códigos de los motivos. */
    const CODIGO_SIN_DETALLE = 'sin_detalle';

    const CODIGO_TOPE = 'tope_diario';

    const CODIGO_SIN_SOPORTE = 'soporte_no_disponible';

    /**
     * Crea el pedido en el chat de soporte de la persona y lo manda al admin.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage|null  $assistant_message  El mensaje del agente en curso (para el canal y el contexto).
     * @param  array  $input  detalle*
     * @return array  {ok, ticket, llego_a_soporte, nota} o la respuesta negativa con `codigo`.
     */
    public static function pedir(ContextoDeCargaIa $contexto, $assistant_message, array $input): array
    {
        if (!self::hay_tablas()) {

            return RespuestaDeCargaIa::error_con_codigo(
                self::CODIGO_SIN_SOPORTE,
                'Este sistema todavía no tiene el chat de soporte (falta actualizarlo), así que no puedo dejarle el pedido a ComercioCity desde acá. '
                . 'Decile a la persona que lo pida por el WhatsApp de soporte de ComercioCity.'
            );
        }

        $detalle = trim(preg_replace('/[ \t]+/u', ' ', EntradaDeCargaIa::texto($input, 'detalle')));

        if ($detalle === '') {

            return RespuestaDeCargaIa::error_con_codigo(
                self::CODIGO_SIN_DETALLE,
                'Falta el detalle. Escribilo VOS, en una o dos oraciones, con lo que la persona necesita que vea soporte y lo que ya probaste '
                . '(no se lo pidas a ella), y volvé a llamar.'
            );
        }

        $detalle = mb_substr($detalle, 0, self::MAX_DETALLE);

        // La PERSONA que habla con el agente: el ticket es suyo (como cuando escribe a mano en soporte).
        $persona_id = is_null($contexto->persona) ? (int) $contexto->owner_id : (int) $contexto->persona->id;

        $de_hoy = self::pedidos_de_hoy($persona_id);

        if ($de_hoy >= self::TOPE_POR_DIA) {

            return RespuestaDeCargaIa::error_con_codigo(
                self::CODIGO_TOPE,
                'Hoy ya le dejaste ' . $de_hoy . ' pedidos a soporte desde el agente, que es el máximo por día. No mandé este. Decile a la '
                . 'persona que ya está pedido y que, si es otra cosa urgente, lo escriba ella en el chat de soporte del sistema.'
            );
        }

        // El ticket abierto de la persona, o uno nuevo: el mismo hilo que ve en el chat de soporte.
        $ticket = SupportTicketHelper::get_or_create_open_ticket($persona_id);

        $cuerpo = self::cuerpo($contexto, $assistant_message, $detalle);

        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'sender_user_id'    => $persona_id,
            'kind'              => 'text',
            'body'              => $cuerpo,
            'delivered_at'      => Carbon::now(),
        ]);

        /*
         * Al admin, como el chat de soporte (SupportMessageController::store). Si no llegó, queda marcado y el
         * comando support:retry-pending-syncs lo reintenta: el pedido quedó guardado igual.
         */
        $llego = SupportSyncHelper::sync_message_to_admin($mensaje);

        if (!$llego) {

            $mensaje->remote_delivery_status = 'not_received';
            $mensaje->save();
        }

        // El chat de soporte del sistema lo muestra en vivo (Pusher). Un broadcast caído no voltea el pedido.
        try {
            event(new SupportMessageReceived((int) $mensaje->id, $persona_id));
        } catch (\Throwable $e) {
            Log::warning('PedidoDeAyudaASoporteIaHelper: no se pudo avisar el mensaje nuevo por broadcast.', [
                'support_message_id' => (int) $mensaje->id,
                'error'              => $e->getMessage(),
            ]);
        }

        return [
            'ok'              => true,
            'ticket'          => [
                'id'     => (int) $ticket->id,
                'estado' => (string) $ticket->status,
            ],
            'llego_a_soporte' => (bool) $llego,
            'pedidos_de_hoy'  => $de_hoy + 1,
            'nota'            => 'Listo: el pedido quedó en el chat de soporte de la persona con ComercioCity (el mismo de Ayuda del sistema), con lo que '
                . 'charlaron. Decile eso en una oración: que soporte lo va a ver y le contesta por ese chat. No prometas tiempos ni digas que ya está resuelto.'
                . ($llego ? '' : ' Todavía no llegó a ComercioCity por una falla de conexión: queda guardado y se reenvía solo en unos minutos; decíselo así.'),
        ];
    }

    /**
     * Cuántos pedidos dejó hoy el agente en nombre de esta persona.
     *
     * @param  int  $persona_id
     * @return int
     */
    public static function pedidos_de_hoy($persona_id): int
    {
        return (int) SupportMessage::where('sender_type', 'user')
            ->where('sender_user_id', (int) $persona_id)
            ->where('body', 'like', self::PREFIJO . '%')
            ->where('created_at', '>=', Carbon::today())
            ->count();
    }

    /**
     * El cuerpo del mensaje: el encabezado con el canal, el detalle y las últimas vueltas de la
     * conversación (para que soporte no tenga que preguntar de qué se trata).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @param  string  $detalle
     * @return string
     */
    protected static function cuerpo(ContextoDeCargaIa $contexto, $assistant_message, $detalle): string
    {
        $cuerpo = self::PREFIJO . ' por ' . self::nombre_del_canal($assistant_message) . '] ' . $detalle;

        $vueltas = self::ultimas_vueltas($contexto, $assistant_message);

        if (count($vueltas)) {
            $cuerpo .= "\n\nÚltimas vueltas de la conversación con el agente:\n" . implode("\n", $vueltas);
        }

        return $cuerpo;
    }

    /**
     * El canal del mensaje en criollo.
     *
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @return string
     */
    protected static function nombre_del_canal($assistant_message): string
    {
        $canal = ($assistant_message instanceof AiMessage) ? (string) $assistant_message->canal : '';

        if ($canal === AiMessage::CANAL_WHATSAPP) {
            return 'WhatsApp';
        }

        if ($canal === AiMessage::CANAL_APP) {
            return 'la app ComercioCity IA';
        }

        if ($canal === AiMessage::CANAL_MCP) {
            return 'MCP';
        }

        return 'el chat del sistema';
    }

    /**
     * El pedido de la persona (su último mensaje) y las VUELTAS_DE_CONTEXTO vueltas anteriores (mensaje
     * suyo + respuesta del agente), de la más vieja a la más nueva, sin el mensaje que se está generando.
     * Cada mensaje en un renglón y recortado.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @return array<int, string>
     */
    protected static function ultimas_vueltas(ContextoDeCargaIa $contexto, $assistant_message): array
    {
        $query = AiMessage::where('ai_conversation_id', (int) $contexto->conversation->id)
            ->whereIn('rol', ['user', 'assistant'])
            ->where('estado', 'listo');

        if ($assistant_message instanceof AiMessage) {
            $query->where('id', '<', (int) $assistant_message->id);
        }

        // Dos mensajes por vuelta, más el pedido de la persona que todavía no tiene respuesta.
        $mensajes = $query->orderBy('id', 'DESC')
            ->limit(self::VUELTAS_DE_CONTEXTO * 2 + 1)
            ->get(['id', 'rol', 'contenido', 'created_at'])
            ->reverse();

        $nombre_de_la_persona = is_null($contexto->persona) ? 'Dueño' : trim((string) $contexto->persona->name);

        if ($nombre_de_la_persona === '') {
            $nombre_de_la_persona = 'Dueño';
        }

        $renglones = [];

        foreach ($mensajes as $mensaje) {

            $texto = trim(preg_replace('/\s+/u', ' ', (string) $mensaje->contenido));

            if ($texto === '') {
                continue;
            }

            if (mb_strlen($texto) > self::MAX_POR_MENSAJE) {
                $texto = mb_substr($texto, 0, self::MAX_POR_MENSAJE) . '…';
            }

            $quien = (string) $mensaje->rol === 'user' ? $nombre_de_la_persona : 'Agente';

            $hora = is_null($mensaje->created_at) ? '' : ' (' . $mensaje->created_at->format('d/m H:i') . ')';

            $renglones[] = '- ' . $quien . $hora . ': ' . $texto;
        }

        return $renglones;
    }

    /**
     * true si existen las dos tablas del chat de soporte. Protegido: si la base no contesta, false.
     *
     * @return bool
     */
    protected static function hay_tablas(): bool
    {
        try {
            return Schema::hasTable('support_tickets') && Schema::hasTable('support_messages');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
