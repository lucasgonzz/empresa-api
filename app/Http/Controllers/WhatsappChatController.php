<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints REST del módulo de chats de WhatsApp con clientes (grupo 137, Prompt 02),
 * consumidos por empresa-spa. Toda la lógica de negocio vive en `WhatsappChatHelper`;
 * este controller solo valida pertenencia al owner autenticado y orquesta.
 * Rutas gateadas por `auth:sanctum` + `check_extencion_empresa:whatsapp` (ver routes/api.php).
 */
class WhatsappChatController extends Controller
{
    /**
     * Lista los chats del owner autenticado, con el cliente vinculado, ordenados por
     * último mensaje. Búsqueda opcional por `?q=` (nombre del chat, teléfono o nombre
     * del cliente vinculado).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user_id = $this->userId();
        $search = trim((string) $request->query('q', ''));

        $query = WhatsappChat::where('user_id', $user_id)
            ->withAll()
            ->orderBy('last_message_at', 'DESC');

        if ($search !== '') {
            $query->where(function ($sub_query) use ($search) {
                $sub_query->where('display_name', 'LIKE', '%'.$search.'%')
                    ->orWhere('phone', 'LIKE', '%'.$search.'%')
                    ->orWhereHas('client', function ($client_query) use ($search) {
                        $client_query->where('name', 'LIKE', '%'.$search.'%');
                    });
            });
        }

        return response()->json(['models' => $query->get()], 200);
    }

    /**
     * Mensajes de un chat del owner autenticado, paginados y ordenados del más nuevo
     * al más viejo (paginación estándar del proyecto: page/per_page).
     *
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse
     */
    public function messages(Request $request, $id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        $page = max(1, (int) $request->query('page', 1));
        $per_page = (int) $request->query('per_page', 30);
        if ($per_page < 1) {
            $per_page = 30;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        $paginator = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->orderBy('created_at', 'DESC')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json(['models' => $paginator], 200);
    }

    /**
     * Crea un chat a mano (ej: el operador quiere iniciar conversación con un cliente
     * que todavía no le escribió). Si ya existe un chat para ese teléfono, lo devuelve
     * en vez de duplicarlo.
     *
     * @param  Request  $request  Espera `phone` (obligatorio) y `client_id` (opcional).
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $user_id = $this->userId();
        $phone = WhatsappPhoneHelper::normalize($request->phone);

        if ($phone === '') {
            return response()->json(['message' => 'Teléfono inválido.'], 422);
        }

        $chat = WhatsappChat::where('user_id', $user_id)
            ->where('phone', $phone)
            ->first();

        if (is_null($chat)) {
            $config = WhatsappBotConfig::where('user_id', $user_id)->first();

            $chat = WhatsappChat::create([
                'user_id' => $user_id,
                'phone' => $phone,
                'client_id' => $request->client_id ?: null,
                'ai_enabled' => ! is_null($config) ? (bool) $config->ai_enabled_default : true,
                'unread_count' => 0,
            ]);
        }

        return response()->json(['model' => $this->fullModel('WhatsappChat', $chat->id)], 201);
    }

    /**
     * Manda un mensaje de texto libre a un chat. Solo funciona dentro de la ventana de
     * 24 h de Meta (`is_within_service_window()`); si está cerrada devuelve 422 con
     * `code: 'fuera_de_ventana'` y NO llega a llamar a Kapso (el front ofrece plantillas).
     *
     * @param  Request  $request  Espera `body` (texto a enviar).
     * @param  int  $id
     * @return JsonResponse
     */
    public function send_message(Request $request, $id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        if (! $chat->is_within_service_window()) {
            return response()->json(['code' => 'fuera_de_ventana'], 422);
        }

        $body = trim((string) $request->body);
        if ($body === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        $config = WhatsappBotConfig::where('user_id', $chat->user_id)->first();
        if (is_null($config)) {
            return response()->json(['message' => 'No hay una configuración de WhatsApp activa para esta empresa.'], 422);
        }

        $send_service = new WhatsappBotSendService();
        $wa_message_id = $send_service->send_text($chat->phone, $body, $config);

        // Empleado autenticado (sin resolver al owner) que efectivamente mandó el mensaje.
        $sent_by_user_id = $this->userId(false);
        $message = WhatsappChatHelper::store_outbound_manual_message($chat, $body, $wa_message_id, $sent_by_user_id);

        return response()->json(['model' => $this->fullModel('WhatsappChatMessage', $message->id)], 201);
    }

    /**
     * Prende/apaga la respuesta automática de IA para un chat puntual.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function toggle_ai($id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        $chat->ai_enabled = ! $chat->ai_enabled;
        $chat->save();

        return response()->json(['model' => $this->fullModel('WhatsappChat', $chat->id)], 200);
    }

    /**
     * Vincula (o desvincula, con `client_id` null) el chat a un cliente del negocio a mano.
     *
     * @param  Request  $request  Espera `client_id` (o null para desvincular).
     * @param  int  $id
     * @return JsonResponse
     */
    public function link_client(Request $request, $id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        $chat->client_id = $request->client_id ?: null;
        $chat->save();

        return response()->json(['model' => $this->fullModel('WhatsappChat', $chat->id)], 200);
    }

    /**
     * Marca el chat como leído (`unread_count` a 0), lo llama el front al abrir la conversación.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function mark_read($id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        $chat->unread_count = 0;
        $chat->save();

        return response()->json(['model' => $this->fullModel('WhatsappChat', $chat->id)], 200);
    }

    /**
     * Busca un chat por id validando que pertenezca al owner del usuario autenticado
     * (patrón multi-empleado del proyecto: los empleados operan sobre los datos del dueño).
     *
     * @param  int  $id
     * @return WhatsappChat|null
     */
    private function find_owned_chat($id)
    {
        return WhatsappChat::where('id', $id)
            ->where('user_id', $this->userId())
            ->first();
    }
}
