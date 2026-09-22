<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
 *
 * Misión asistente-ia-acciones (15/9/2026): el POST del mensaje acepta
 * `acciones` para que el asistente pueda proponer cargas, los mensajes viajan
 * con sus tarjetas en `acciones`, y confirmar_accion/cancelar_accion resuelven
 * una tarjeta. La lógica de las tarjetas vive en los helpers de asistente_ia.
 */
class AiConversationController extends Controller
{
    /**
     * Minutos tras los cuales un assistant 'pendiente' deja de bloquear el
     * POST de un mensaje nuevo. El techo real del job de respuesta es menor
     * (ResponderMensajeChatIaJob::$timeout = 300s = 5 minutos): un pendiente
     * más viejo que esto es un huérfano de un dispatch que falló o de un
     * worker caído ANTES de failed(), y sin este vencimiento la conversación
     * quedaba clavada en 409 `respuesta_en_curso` para siempre.
     */
    const MINUTOS_VENCIMIENTO_PENDIENTE = 10;

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

        /*
         * refresh(): un modelo recién creado solo carga los atributos que se le
         * pasaron — sin esto la respuesta viaja SIN `titulo`, `origen` ni
         * `last_message_at` (ni siquiera como null) y la SPA, que mete este
         * model tal cual en la sidebar, lo necesita con la misma forma que las
         * filas del index. De paso trae los defaults reales de la base
         * (origen 'usuario').
         */
        $model->refresh();

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

        // Las tarjetas de carga se van con la conversación (misión asistente-ia-acciones).
        AccionesIaHelper::borrar_de_conversacion($conversation->id);
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
     * Misión asistente-ia-acciones: cada mensaje suma `acciones` (sus tarjetas
     * en orden de id; vacío si no tiene o si no está 'listo').
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

        /*
         * `with('imagenes')`: las fotos que mandó el dueño viajan en cada mensaje (P5 de la misión
         * asistente-capacidades-y-hilos) y sin el eager load serían 30 consultas por página.
         */
        $paginator = AiMessage::where('ai_conversation_id', $conversation->id)
            ->with('imagenes')
            ->orderBy('id', 'DESC')
            ->paginate($per_page, ['*'], 'page', $page);

        AccionesIaHelper::cargar_en_mensajes($paginator->getCollection());

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
     * @param  Request  $request  Espera `contenido` (obligatorio, hasta 4000 caracteres) y
     *                            `acciones` (opcional, default false: con true el asistente
     *                            puede proponer cargas; misión asistente-ia-acciones).
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

        $limite_pendiente_vigente = now()->subMinutes(self::MINUTOS_VENCIMIENTO_PENDIENTE);

        /*
         * Los pendientes vencidos se cierran acá mismo con el error amigable
         * (decisión documentada: pasan a 'error', no quedan 'pendiente'):
         * si solo se los ignorara, el globo "pensando" de ese huérfano
         * quedaría eterno en la conversación y cada recarga de la SPA
         * re-armaría el polling sobre un mensaje que ya no tiene job.
         */
        $pendientes_vencidos = AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('rol', 'assistant')
            ->where('estado', 'pendiente')
            ->where('created_at', '<', $limite_pendiente_vigente)
            ->pluck('id');

        AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('rol', 'assistant')
            ->where('estado', 'pendiente')
            ->where('created_at', '<', $limite_pendiente_vigente)
            ->update([
                'contenido'     => ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE,
                'estado'        => 'error',
                'error_mensaje' => 'pendiente vencido: superó los ' . self::MINUTOS_VENCIMIENTO_PENDIENTE . ' minutos sin que el job lo resolviera (dispatch fallido o worker caído)',
            ]);

        /*
         * Misión asistente-ia-acciones: las tarjetas que un pendiente vencido
         * alcanzó a proponer quedan 'descartadas', igual que las de un mensaje
         * que el job dejó en error. Solo las de los que efectivamente quedaron
         * en error recién: si en el medio el job llegó a terminar uno, sus
         * tarjetas siguen vivas.
         */
        if ($pendientes_vencidos->isNotEmpty()) {
            $cerrados = AiMessage::whereIn('id', $pendientes_vencidos->all())
                ->where('estado', 'error')
                ->pluck('id');

            foreach ($cerrados as $cerrado_id) {
                AccionesIaHelper::descartar_de_mensaje($cerrado_id);
            }
        }

        $hay_respuesta_en_curso = AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('rol', 'assistant')
            ->where('estado', 'pendiente')
            ->where('created_at', '>=', $limite_pendiente_vigente)
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
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            /*
             * Misión asistente-ia-acciones: el flag va en el assistant que se
             * va a generar, no en la conversación. Una pestaña vieja manda el
             * POST sin `acciones` y esa respuesta sale de solo lectura como
             * siempre, aunque la misma conversación tenga mensajes con tarjetas.
             */
            'acciones_habilitadas' => $request->boolean('acciones'),
        ]);

        $conversation->last_message_at = now();
        $conversation->save();

        /*
         * Corte por tope del plan (misión foto-sucursal-y-asistente-configurable): si el negocio ya
         * superó el tope de su plan este mes, se contesta con el texto de límite SIN gastar una
         * llamada a la API. El assistant nace 'listo' y no se despacha ni el job de respuesta ni el
         * de título. Sin tope configurado, estado()['supero'] es false y todo sigue como siempre.
         */
        if (TopeDeTokensHelper::estado(User::find($conversation->user_id))['supero']) {
            $assistant_message->contenido = TopeDeTokensHelper::MENSAJE_LIMITE;
            $assistant_message->estado = 'listo';
            $assistant_message->save();

            return response()->json([
                'user_message'      => $user_message,
                'assistant_message' => $assistant_message,
            ], 201);
        }

        /*
         * Red de seguridad del encolado (arreglo post-chequeo): si dispatch()
         * lanza (tabla jobs caída, driver mal configurado), el assistant NO
         * puede quedar 'pendiente' — ningún worker lo va a resolver y el
         * chequeo del 409 bloquearía la conversación hasta el vencimiento. Se
         * lo cierra con el error amigable y se relanza: el mensaje del usuario
         * queda guardado, el POST devuelve 500 y el globo optimista de la SPA
         * ofrece reintentar.
         */
        try {
            dispatch(new ResponderMensajeChatIaJob($assistant_message->id));
        } catch (\Throwable $e) {
            $assistant_message->contenido = ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE;
            $assistant_message->estado = 'error';
            // Mismo recorte defensivo que marcar_error() del job.
            $assistant_message->error_mensaje = mb_substr('no se pudo encolar el job de respuesta: ' . $e->getMessage(), 0, 5000);
            $assistant_message->save();

            throw $e;
        }

        if ($inferir_titulo) {
            // El título es cosmético (D19: si falla queda null y la SPA
            // muestra "Nueva conversación"): una falla al encolarlo no puede
            // voltear un POST cuyo job de respuesta ya quedó despachado.
            try {
                dispatch(new InferirTituloConversacionIaJob($conversation->id, (string) $request->contenido));
            } catch (\Throwable $e) {
                Log::warning('AiConversationController: no se pudo encolar la inferencia del título', [
                    'ai_conversation_id' => $conversation->id,
                    'message'            => $e->getMessage(),
                ]);
            }
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
     * Misión asistente-ia-acciones: el mensaje suma `acciones`, igual que en
     * messages().
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
            ->with('imagenes')
            ->where('id', $message_id)
            ->first();

        if (is_null($message)) {
            return response()->json(['message' => 'Mensaje no encontrado.'], 404);
        }

        AccionesIaHelper::cargar_en_mensajes([$message]);

        return response()->json(['model' => $message], 200);
    }

    /**
     * El binario de una foto que el dueño mandó por WhatsApp (misión asistente-capacidades-y-hilos,
     * P5): `GET api/ai-mensajes/{message_id}/imagen/{orden}`.
     *
     * Es la `url` que viaja en cada mensaje dentro de `imagenes` (FotosDelMensajeIaHelper). Lucas
     * las reportó así: "Las fotos que le mando por whatsapp no las veo en el chat del sistema" —
     * llegaban y se guardaban bien, pero no había forma de mirarlas.
     *
     * 🔴 NUNCA UNA RUTA PÚBLICA NI UN LINK FIRMADO ETERNO. El binario vive en el disco `local`
     * (privado) y son fotos del negocio: facturas de proveedores con CUIT, razón social y precios
     * de compra. Esta ruta está adentro del mismo grupo que el resto del chat (Sanctum + extensión
     * `asistente_ia` + solo el dueño) y encima pasa por la MISMA tenencia doble que todo lo demás:
     * el mensaje tiene que ser de una conversación de esta persona (`auth_user_id`) y de esta
     * cuenta (`user_id`). Lo que la sostiene del lado del navegador es la sesión de Sanctum, que es
     * una cookie: por eso un `<img src>` derecho alcanza, exactamente como ya lo hace el modal de
     * revisión del escaneo de facturas con `provider-order-scan/{uuid}/imagen/{orden}`. No hace
     * falta base64 ni una URL firmada; el patrón ya existe en el repo y este lo copia.
     *
     * 404 —y no 403— cuando el mensaje es de otra persona: distinguir "no es tuyo" de "no existe"
     * ya confirma que existe. Mismo criterio que FichaArticuloIaHelper.
     *
     * @param  int  $message_id
     * @param  int  $orden
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
     */
    public function imagen_de_mensaje($message_id, $orden)
    {
        $message = AiMessage::whereIn('ai_conversation_id', function ($query) {
            $query->select('id')
                ->from('ai_conversations')
                ->where('auth_user_id', UserHelper::userId(false))
                ->where('user_id', UserHelper::userId(true));
        })
            ->where('id', (int) $message_id)
            ->first();

        if (is_null($message)) {
            return response()->json(['message' => 'Mensaje no encontrado.'], 404);
        }

        $imagen = AiMessageImagen::where('ai_message_id', $message->id)
            ->where('orden', (int) $orden)
            ->first();

        if (is_null($imagen)) {
            return response()->json(['message' => 'Foto no encontrada.'], 404);
        }

        $ruta = storage_path('app/' . $imagen->path);

        if (!file_exists($ruta)) {
            return response()->json(['message' => 'Foto no encontrada.'], 404);
        }

        $respuesta = response()->file($ruta);

        /*
         * 🔴 setPrivate() Y NO UN HEADER `Cache-Control` EN EL ARRAY DE response()->file().
         * BinaryFileResponse nace con `$public = true` y su constructor llama a setPublic()
         * DESPUÉS de cargar los headers que se le pasaron: un `private` puesto ahí sale como
         * `max-age=300, public`, que es exactamente lo contrario. Medido con el test de este
         * endpoint. Y acá importa de verdad: un proxy o una CDN intermedia guardando la factura
         * de un proveedor y sirviéndosela a otro es el peor final posible para estas fotos.
         */
        $respuesta->setPrivate();
        $respuesta->setMaxAge(300);

        return $respuesta;
    }

    /**
     * Confirma una tarjeta de carga del asistente (misión asistente-ia-acciones, contrato §2.4):
     * ejecuta el gasto, el pago o la tarea por el mismo camino que la pantalla, en este request
     * autenticado como la persona y con candado contra el doble clic. La lógica vive en
     * EjecutorAccionesIaHelper; acá queda la tenencia, que sigue viviendo en un solo lugar
     * (conversacion_de_la_persona()), y la respuesta.
     *
     * @param  int  $id
     * @param  int  $accion_id
     * @return JsonResponse  200 {model} · 404 {message} · 409 {code, message, model} · 422 {message, model} · 500 {message}
     */
    public function confirmar_accion($id, $accion_id): JsonResponse
    {
        $conversation = $this->conversacion_de_la_persona($id);

        if (is_null($conversation)) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        /*
         * El correlativo del gasto va como closure para que Controller::num() corra ADENTRO de la
         * transacción del ejecutor y su lockForUpdate se sostenga hasta el commit: resuelto antes,
         * dos altas concurrentes podrían llevarse el mismo número (mismo criterio que
         * ExpenseController::store() y PendingCompletedController::store()).
         */
        $resultado = EjecutorAccionesIaHelper::confirmar($conversation, $accion_id, UserHelper::user(false), function () {
            return $this->num('expenses');
        });

        return response()->json($resultado['body'], $resultado['status']);
    }

    /**
     * Cancela una tarjeta de carga del asistente (contrato §2.5): la deja 'cancelada' sin escribir
     * nada más, con el mismo candado que confirmar.
     *
     * @param  int  $id
     * @param  int  $accion_id
     * @return JsonResponse  200 {model} · 404 {message} · 409 {code, message, model}
     */
    public function cancelar_accion($id, $accion_id): JsonResponse
    {
        $conversation = $this->conversacion_de_la_persona($id);

        if (is_null($conversation)) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        $resultado = EjecutorAccionesIaHelper::cancelar($conversation, $accion_id);

        return response()->json($resultado['body'], $resultado['status']);
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
