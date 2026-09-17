<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\User;
use Carbon\Carbon;

/**
 * Lo que toda herramienta de carga y todo paso de confirmación del asistente necesita saber de
 * quién está cargando (misión asistente-ia-acciones, 15/9/2026).
 *
 * Se resuelve SIEMPRE desde la conversación y nunca desde Auth cuando se propone: las herramientas
 * corren adentro de ResponderMensajeChatIaJob, sin sesión, y `UserHelper::userId()` ahí devuelve el
 * USER_ID de config. Al confirmar, en cambio, la persona es la autenticada del request (que la
 * tenencia doble de AiConversationController ya garantizó que es la de la conversación).
 */
class ContextoDeCargaIa
{
    /** @var \App\Models\AiConversation */
    public $conversation;

    /** @var \App\Models\User|null Dueño de la cuenta (conversación.user_id). */
    public $owner;

    /** @var \App\Models\User|null La persona que charla con el asistente (conversación.auth_user_id). */
    public $persona;

    /** @var int */
    public $owner_id;

    /** @var bool La cuenta tiene la extensión ventas_en_dolares. */
    public $usa_dolares;

    /** @var \Carbon\Carbon Hoy en la zona de la app (America/Argentina/Buenos_Aires). */
    public $hoy;

    /**
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\User|null  $persona  La autenticada al confirmar; null para resolverla desde la conversación.
     * @return self
     */
    public static function de_la_conversacion(AiConversation $conversation, $persona = null)
    {
        $contexto = new self();

        $contexto->conversation = $conversation;
        $contexto->owner_id = (int) $conversation->user_id;
        $contexto->owner = User::find($conversation->user_id);
        $contexto->persona = is_null($persona) ? User::find($conversation->auth_user_id) : $persona;
        $contexto->usa_dolares = !is_null($contexto->owner) && UserHelper::hasExtencion('ventas_en_dolares', $contexto->owner);
        $contexto->hoy = Carbon::today();

        return $contexto;
    }
}
