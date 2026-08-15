<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints REST del chat con el asistente de IA (misión chat-ia-y-modulo-ia).
 *
 * Rutas en grupo gateado por `check_extencion_empresa:asistente_ia` (ver
 * routes/api.php). El POST del mensaje guarda y contesta rápido: la respuesta
 * la genera ResponderMensajeChatIaJob en cola, que avisa por el canal privado
 * de la persona; show_message existe para que la SPA busque el texto por REST
 * cuando el evento avisa (y para el polling de respaldo).
 *
 * 🔴 Tenencia SIEMPRE doble (R5 del plan): `auth_user_id` = la PERSONA
 * (UserHelper::userId(false)) **y** `user_id` = la cuenta. Las conversaciones
 * pueden traer saldos de clientes: un empleado no lee las del dueño ni al
 * revés, y el gate de extensión solo no alcanza para eso.
 */
class AiConversationController extends Controller
{
    /**
     * Conversaciones de la persona autenticada, ordenadas por actividad
     * (last_message_at DESC con nulls al final, D44: la SPA abre
     * conversations[0] al abrir el panel).
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $models = AiConversation::where('auth_user_id', UserHelper::userId(false))
            ->where('user_id', UserHelper::userId(true))
            ->orderByRaw('last_message_at IS NULL')
            ->orderBy('last_message_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Abre una conversación nueva de la persona (origen 'usuario' por el
     * default de la columna). El título nace null: se infiere del primer
     * mensaje y mientras tanto la SPA muestra "Nueva conversación".
     *
     * @return JsonResponse
     */
    public function store(): JsonResponse
    {
        $model = AiConversation::create([
            'user_id'      => UserHelper::userId(true),
            'auth_user_id' => UserHelper::userId(false),
        ]);

        return response()->json(['model' => $model], 201);
    }

    /**
     * Borra una conversación de la persona CON sus mensajes. Borrar también
     * los mensajes no es prolijidad: es lo que hace concreta la carrera R3
     * (el job de respuesta recarga por id y vuelve sin hacer nada si el
     * mensaje ya no está).
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $conversation = $this->conversacion_de_la_persona($id);

        if (is_null($conversation)) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        AiMessage::where('ai_conversation_id', $conversation->id)->delete();
        $conversation->delete();

        return response()->json(['deleted' => true], 200);
    }

    /**
     * Mensajes de una conversación de la persona, paginados del más nuevo al
     * más viejo (molde WhatsappChatController@messages: page/per_page con
     * default 30 y techo 200, paginador entero en `models`). La SPA invierte
     * para render y hace scroll infinito hacia arriba.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse
     */
    public function messages(Request $request, $id): JsonResponse
    {
        $conversation = $this->conversacion_de_la_persona($id);

        if (is_null($conversation)) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        $page = max(1, (int) $request->query('page', 1));
        $per_page = (int) $request->query('per_page', 30);
        if ($per_page < 1) {
            $per_page = 30;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        $paginator = AiMessage::where('ai_conversation_id', $conversation->id)
            ->orderBy('id', 'DESC')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json(['models' => $paginator], 200);
    }

    /**
     * Guarda el mensaje del usuario, deja un assistant 'pendiente' y despacha
     * el job que genera la respuesta (D17: el POST guarda y contesta rápido;
     * la espera la cubre el canal privado + el polling de la SPA).
     *
     * Si la conversación ya tiene un assistant en 'pendiente' devuelve 409
     * `respuesta_en_curso` y no crea nada: es la defensa de SERVIDOR contra
     * dos mensajes seguidos (R2) — una pestaña vieja o un doble clic pasan
     * por encima del bloqueo del composer de la SPA.
     *
     * @param  Request  $request  Espera `contenido` (obligatorio, hasta 4000 caracteres).
     * @param  int  $id
     * @return JsonResponse
     */
    public function send_message(Request $request, $id): JsonResponse
    {
        $conversation = $this->conversacion_de_la_persona($id);

        if (is_null($conversation)) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        $request->validate([
            'contenido' => 'required|string|max:4000',
        ]);

        $hay_respuesta_en_curso = AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('rol', 'assistant')
            ->where('estado', 'pendiente')
            ->exists();

        if ($hay_respuesta_en_curso) {
            return response()->json([
                'code'    => 'respuesta_en_curso',
                'message' => 'Todavía se está generando la respuesta anterior. Esperá a que llegue para mandar otro mensaje.',
            ], 409);
        }

        /*
         * D19: el título se infiere SOLO en el primer mensaje de usuario de
         * una conversación sin título. Se decide antes de crear los mensajes
         * para no contarse a sí mismo.
         */
        $inferir_titulo = is_null($conversation->titulo)
            && !AiMessage::where('ai_conversation_id', $conversation->id)
                ->where('rol', 'user')
                ->exists();

        $user_message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $request->contenido,
            'estado'             => 'listo',
        ]);

        $assistant_message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        $conversation->last_message_at = now();
        $conversation->save();

        dispatch(new ResponderMensajeChatIaJob($assistant_message->id));

        if ($inferir_titulo) {
            dispatch(new InferirTituloConversacionIaJob($conversation->id, (string) $request->contenido));
        }

        return response()->json([
            'user_message'      => $user_message,
            'assistant_message' => $assistant_message,
        ], 201);
    }

    /**
     * Un mensaje puntual de una conversación de la persona. Es el endpoint
     * que la SPA pega cuando el evento del canal privado avisa (el payload
     * lleva ids, nunca el texto) y el que consulta el polling de respaldo.
     *
     * @param  int  $id
     * @param  int  $message_id
     * @return JsonResponse
     */
    public function show_message($id, $message_id): JsonResponse
    {
        $conversation = $this->conversacion_de_la_persona($id);

        if (is_null($conversation)) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        $message = AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('id', $message_id)
            ->first();

        if (is_null($message)) {
            return response()->json(['message' => 'Mensaje no encontrado.'], 404);
        }

        return response()->json(['model' => $message], 200);
    }

    /**
     * Resuelve una conversación de la PERSONA autenticada (null si no existe
     * o es de otra persona/cuenta). Todos los métodos por id pasan por acá:
     * es el único lugar donde vive el filtro de tenencia doble.
     *
     * @param  int  $id
     * @return AiConversation|null
     */
    protected function conversacion_de_la_persona($id)
    {
        return AiConversation::where('auth_user_id', UserHelper::userId(false))
            ->where('user_id', UserHelper::userId(true))
            ->where('id', $id)
            ->first();
    }
}
