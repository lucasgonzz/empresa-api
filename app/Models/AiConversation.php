<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conversación del asistente de IA del negocio.
 *
 * Tenencia doble: `auth_user_id` es la PERSONA dueña de la conversación (las
 * respuestas pueden traer saldos de clientes: un empleado no lee las del dueño
 * ni al revés) y `user_id` es el dueño de la cuenta, que es por quien filtran
 * las tools de consulta cuando el job genera la respuesta sin sesión.
 *
 * origen:
 * - 'usuario': la abrió una persona desde el panel del chat.
 * - 'sugerencia_stock': la creó automáticamente el resumen de una sugerencia
 *   de stock terminada (referencia_id = stock_suggestions.id).
 * - 'sugerencia_compra': ídem con una sugerencia de compra a proveedores
 *   terminada (referencia_id = purchase_suggestions.id). La misión de compras
 *   sumó el origen y NO actualizó esta lista: la deuda se salda acá (15/8/2026).
 * - 'sugerencia_oferta': ídem con una corrida del motor de ofertas por cliente
 *   terminada (referencia_id = offer_suggestions.id). Toda misión que sume un
 *   origen actualiza esta lista en el MISMO commit: no hay enum ni constante.
 * - 'mostrador_reporte': la abrió el dueño desde un informe del mostrador del
 *   módulo IA (referencia_id = mostrador_reportes.id, misión modulo-ia-mostrador).
 *   Una por (informe, persona); la crea MostradorController@conversacion con el
 *   informe pasado a texto plano más sus hechos como `contexto`, y es la ÚNICA
 *   conversación que después lee alguien más que la persona: la skill /mostrador
 *   la recibe por admin-sync/mostrador/contexto para saber qué le importa al dueño
 *   (MostradorReporte::ORIGEN_CONVERSACION).
 * - 'whatsapp': la abrió el dueño escribiéndole al asistente desde WhatsApp (misión
 *   asistente-por-whatsapp, 16/9/2026). No hay tabla nueva ni columna de canal en la
 *   conversación: es un valor nuevo de esta columna, que ya existía. `referencia_id`
 *   queda null. La conversación se lee y se sigue desde el panel del chat como
 *   cualquier otra —es la MISMA conversación—; lo que la distingue es el ícono del
 *   listado y que sus mensajes tienen `ai_messages.canal = 'whatsapp'`.
 *   🔴 Quién la resuelve: el admin manda `ai_conversation_id` SOLO cuando la dedujo de
 *   una cita (responder citando un mensaje del asistente reabre esa conversación); si no
 *   lo manda, decide AsistenteCanalHelper con el corte de 6 h sin hablar.
 * - 'mcp': la abrió un cliente MCP externo (Claude Desktop, Claude Code, la API de
 *   Anthropic…) al inicializar una sesión contra el servidor MCP de este API (misión
 *   asistente-mcp, 22/9/2026). `mcp_sesion` guarda el Mcp-Session-Id que identifica esa
 *   sesión (null cuando el cliente la cerró o no manda sesión). `referencia_id` queda null.
 *   Sus mensajes tienen `ai_messages.canal = 'mcp'` y son el registro de cada tool de CARGA
 *   que pidió el cliente: la tarjeta cuelga de ahí, y el dueño puede confirmarla desde el
 *   panel con el botón o el cliente MCP por texto. Ver McpSesionHelper.
 *
 * `contexto` guarda el bloque de DATOS ya calculados de la sugerencia (no las
 * instrucciones de redacción): viaja como segundo bloque del system en cada
 * pedido a la IA, para que pueda charlar sobre esos números sin recalcular.
 *
 * `titulo` null significa "todavía se está infiriendo" y la SPA muestra
 * "Nueva conversación"; si la inferencia falla queda null para siempre y no
 * pasa nada (un título es cosmético).
 */
class AiConversation extends Model
{
    /** Origen de las conversaciones que nacen de un mensaje de WhatsApp (misión asistente-por-whatsapp). */
    const ORIGEN_WHATSAPP = 'whatsapp';

    /** Origen de las conversaciones que abre un cliente MCP externo al inicializar su sesión (misión asistente-mcp). */
    const ORIGEN_MCP = 'mcp';

    protected $guarded = [];

    /**
     * Titulo de una conversacion que nace de una sugerencia terminada: el nombre del tipo
     * mas la fecha en que la sugerencia se genero, en dia/mes/anio.
     *
     * Antes era "<tipo> #<id>" (pedido de Lucas del 19/8/2026: el id no le dice nada a
     * nadie; la fecha si). Vive ACA y no copiado en los tres jobs porque el formato es
     * uno solo para los tres tipos: con una copia por job, el dia que se le agregue la
     * hora entra en el que se estaba mirando y la bandeja queda con dos formatos.
     *
     * 🔴 La fecha sale de created_at de la SUGERENCIA, no de now(): el job corre despues
     * y una corrida que quedo encolada de un dia para el otro se titularia con la fecha
     * equivocada. now() es solo la red por si created_at viniera null.
     *
     * @param string $prefijo Nombre del tipo, ej. 'Sugerencia de stock'
     * @param mixed $momento created_at de la sugerencia (Carbon o null)
     * @return string
     */
    public static function titulo_con_fecha($prefijo, $momento)
    {
        $fecha = is_null($momento) ? now() : $momento;

        return $prefijo . ' ' . $fecha->format('d/m/Y');
    }

    /**
     * Mensajes del hilo en orden de llegada. El orden por id alcanza porque
     * los mensajes solo se agregan al final, nunca se reordenan.
     */
    public function messages()
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }
}
