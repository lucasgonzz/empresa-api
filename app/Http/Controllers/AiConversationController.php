<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ArchivosAdjuntosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\AudioDelMensajeIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ColumnasDeLaAppIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use App\Http\Controllers\Helpers\asistente_ia\ValoracionIaHelper;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Endpoints REST del chat con el asistente de IA (misión chat-ia-y-modulo-ia).
 *
 * Rutas en grupo gateado por `check_extencion_empresa:agentes_ia` (ver
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

    /** Techo del texto de búsqueda de la barra lateral de la app. */
    const MAX_BUSCAR = 100;

    /** Techo del título que pone el dueño a mano (contrato C5; la columna admite 150). */
    const MAX_TITULO = 120;

    /**
     * Conversaciones de la persona autenticada, ordenadas por actividad
     * (last_message_at DESC con nulls al final, D44: la SPA abre
     * conversations[0] al abrir el panel).
     *
     * Misión app-ia-y-memoria (29/10/2026, contrato C5): cada conversación trae `fijada`, y `buscar`
     * (opcional) filtra por el título y por lo que dicen los mensajes, con los comodines de LIKE escapados
     * (un "%" busca el carácter). Sin `buscar`, la lista de siempre.
     *
     * 🔴 Las FIJADAS van primero SOLO para la app (token `ia-app`): el panel del sistema abre
     * conversations[0] al abrirse (D44), y una conversación fijada en la app no puede cambiarle cuál abre.
     * Con la cookie, el orden de siempre (corrección de la revisión).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = AiConversation::where('auth_user_id', UserHelper::userId(false))
            ->where('user_id', UserHelper::userId(true));

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, self::MAX_BUSCAR);

        if ($buscar !== '') {
            $como = '%' . addcslashes($buscar, '%_\\') . '%';

            $query->where(function ($donde) use ($como) {
                $donde->where('titulo', 'like', $como)
                    ->orWhereExists(function ($en_los_mensajes) use ($como) {
                        $en_los_mensajes->selectRaw('1')
                            ->from('ai_messages')
                            ->whereColumn('ai_messages.ai_conversation_id', 'ai_conversations.id')
                            ->where('ai_messages.contenido', 'like', $como);
                    });
            });
        }

        if (IaAppHelper::es_pedido_de_la_app($request) && ColumnasDeLaAppIaHelper::hay_columna('ai_conversations', 'fijada')) {
            $query->orderBy('fijada', 'DESC');
        }

        $models = $query
            ->orderByRaw('last_message_at IS NULL')
            ->orderBy('last_message_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Renombra o fija una conversación de la persona (misión app-ia-y-memoria, contrato C5):
     * `PATCH api/ai-conversations/{id}` con `{titulo?: string ≤ 120, fijada?: bool}` → la conversación.
     * Un campo que no viene no se toca; un título vacío es un 422 (para "sin título" no hay botón).
     *
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse  200 {model} · 404 · 422 {code: validacion}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $conversation = $this->conversacion_de_la_persona($id);

        if (is_null($conversation)) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        $validador = Validator::make($request->all(), [
            'titulo' => 'sometimes|required|string|max:' . self::MAX_TITULO,
            'fijada' => 'sometimes|boolean',
        ]);

        if ($validador->fails()) {
            return response()->json([
                'code'    => 'validacion',
                'message' => $validador->errors()->first(),
                'errors'  => $validador->errors(),
            ], 422);
        }

        if ($request->has('titulo')) {
            $conversation->titulo = trim((string) $request->input('titulo'));
        }

        /* Sin la columna (migración todavía sin correr) no hay dónde guardarlo: se ignora. */
        if ($request->has('fijada') && ColumnasDeLaAppIaHelper::hay_columna('ai_conversations', 'fijada')) {
            $conversation->fijada = $request->boolean('fijada');
        }

        $conversation->save();

        return response()->json(['model' => $conversation->fresh()], 200);
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

        /* Misión app-ia-y-memoria: una imagen que lleva más de 8 h sin llegar se da por fallida (persistido). */
        PedidosDeImagenIaHelper::vencer_las_colgadas($paginator->getCollection());

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
     * Misión app-ia-y-memoria (29/10/2026, contrato C5): acepta JSON o multipart, y además del texto
     * `modelo` (rapido | pensamiento, solo para este turno), `imagenes[]` (≤ 3; por el MISMO camino que
     * las fotos de WhatsApp), `archivos[]` (≤ 3; PDF, Excel, CSV) y `audio` (1; se transcribe en el job).
     * `contenido` puede venir vacío si hay algo de eso, y se guarda '' (nunca null). Con un token
     * `ia-app` los dos mensajes del turno son del canal `app`. Las validaciones nuevas contestan 422
     * con `code` (validacion | mensaje_vacio | adjunto_invalido) y ANTES de crear nada.
     *
     * @param  Request  $request  Espera `contenido` (hasta 4000 caracteres; obligatorio salvo que vengan
     *                            adjuntos o audio) y `acciones` (opcional, default false: con true el
     *                            asistente puede proponer cargas; misión asistente-ia-acciones).
     * @param  int  $id
     * @return JsonResponse
     */
    public function send_message(Request $request, $id): JsonResponse
    {
        $conversation = $this->conversacion_de_la_persona($id);

        if (is_null($conversation)) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        $validador = Validator::make($request->all(), [
            'contenido'    => 'nullable|string|max:4000',
            'modelo'       => 'nullable|in:' . implode(',', ProveedorIaHelper::MODELOS_PEDIDOS),
            'duracion_seg' => 'nullable|numeric',
        ]);

        if ($validador->fails()) {
            return response()->json([
                'code'    => 'validacion',
                'message' => $validador->errors()->first(),
                'errors'  => $validador->errors(),
            ], 422);
        }

        $contenido = (string) $request->input('contenido', '');
        $imagenes  = AsistenteImagenHelper::del_request($request);
        $archivos  = ArchivosAdjuntosIaHelper::como_lista($request->file('archivos'));
        $audio     = $request->file('audio');

        if (is_array($audio)) {
            $audio = count($audio) ? array_values($audio)[0] : null;
        }

        /*
         * Sin texto y sin nada adjunto no hay nada que contestar. `errors.contenido` va igual que antes
         * de la misión (el 422 de `required`), para la SPA del sistema.
         */
        if (trim($contenido) === '' && !count($imagenes) && !count($archivos) && is_null($audio)) {
            return response()->json([
                'code'    => 'mensaje_vacio',
                'message' => 'El mensaje no puede venir vacío.',
                'errors'  => ['contenido' => ['El mensaje no puede venir vacío.']],
            ], 422);
        }

        /*
         * Los adjuntos se validan ANTES de crear el mensaje: un 422 con el mensaje ya creado dejaría en la
         * conversación algo del dueño que nadie va a contestar (mismo criterio que WhatsApp).
         */
        $motivo = AsistenteImagenHelper::motivo_de_rechazo($imagenes);

        if (is_null($motivo)) {
            $motivo = ArchivosAdjuntosIaHelper::motivo_de_rechazo($archivos);
        }

        if (is_null($motivo) && !is_null($audio)) {
            $motivo = AudioDelMensajeIaHelper::motivo_de_rechazo($audio);
        }

        if (!is_null($motivo)) {
            return response()->json(['code' => 'adjunto_invalido', 'message' => $motivo], 422);
        }

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

        $cierre_del_huerfano = [
            'contenido'     => ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE,
            'estado'        => 'error',
            'error_mensaje' => 'pendiente vencido: superó los ' . self::MINUTOS_VENCIMIENTO_PENDIENTE . ' minutos sin que el job lo resolviera (dispatch fallido o worker caído)',
        ];

        /*
         * Misión app-ia-y-memoria: la línea de progreso de un huérfano no queda colgada. Solo si la
         * columna ya existe: sin la migración, este UPDATE es el de siempre (el chat no se cae).
         */
        if (ColumnasDeLaAppIaHelper::hay_columna('ai_messages', 'progreso')) {
            $cierre_del_huerfano['progreso'] = null;
        }

        AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('rol', 'assistant')
            ->where('estado', 'pendiente')
            ->where('created_at', '<', $limite_pendiente_vigente)
            ->update($cierre_del_huerfano);

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

                /*
                 * Misión app-ia-y-memoria: si lo que había mandado el dueño era un audio que no llegó a
                 * transcribirse (el job nunca corrió), la transcripción queda `fallida`: si no, la app
                 * muestra "transcribiendo…" para siempre debajo del audio.
                 */
                AudioDelMensajeIaHelper::cerrar_si_quedo_pendiente(
                    AiMessage::where('ai_conversation_id', $conversation->id)
                        ->where('rol', 'user')
                        ->where('id', '<', (int) $cerrado_id)
                        ->orderBy('id', 'DESC')
                        ->first()
                );
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

        /*
         * Misión app-ia-y-memoria: el canal sale de CÓMO entró el pedido. Con un token `ia-app` es la app
         * (formato y herramientas de bloques); con la cookie del sistema, 'sistema' como siempre.
         */
        $canal = IaAppHelper::es_pedido_de_la_app($request) ? AiMessage::CANAL_APP : AiMessage::CANAL_SISTEMA;

        $tipo = AiMessage::TIPO_TEXTO;

        if (!is_null($audio)) {
            $tipo = AiMessage::TIPO_AUDIO;
        } elseif (count($imagenes)) {
            $tipo = AiMessage::TIPO_IMAGEN;
        }

        $user_message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            // '' y nunca null: una SPA vieja hace .length sobre el contenido.
            'contenido'          => $contenido,
            'estado'             => 'listo',
            'canal'              => $canal,
            'tipo'               => $tipo,
        ]);

        $this->guardar_adjuntos($user_message, (int) $conversation->user_id, $imagenes, $archivos, $audio, $request->input('duracion_seg'));

        $datos_del_assistant = [
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'canal'                => $canal,
            /*
             * Misión asistente-ia-acciones: el flag va en el assistant que se
             * va a generar, no en la conversación. Una pestaña vieja manda el
             * POST sin `acciones` y esa respuesta sale de solo lectura como
             * siempre, aunque la misma conversación tenga mensajes con tarjetas.
             */
            'acciones_habilitadas' => $request->boolean('acciones'),
        ];

        /* El modelo que eligió el dueño en la app, solo para este turno (ProveedorIaHelper::modelo_del_asistente()). */
        $modelo = trim((string) $request->input('modelo', ''));

        if ($modelo !== '') {
            $datos_del_assistant['modelo_pedido'] = $modelo;
        }

        $assistant_message = AiMessage::create($datos_del_assistant);

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

            // El audio no se va a transcribir: que la app no muestre "transcribiendo…" para siempre.
            AudioDelMensajeIaHelper::cerrar_si_quedo_pendiente($user_message);

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

        /*
         * Sin texto (una foto sola, un audio) no hay de dónde sacar el título acá: el de un audio lo
         * infiere el job después de transcribirlo.
         */
        if ($inferir_titulo && trim($contenido) !== '') {
            // El título es cosmético (D19: si falla queda null y la SPA
            // muestra "Nueva conversación"): una falla al encolarlo no puede
            // voltear un POST cuyo job de respuesta ya quedó despachado.
            try {
                dispatch(new InferirTituloConversacionIaJob($conversation->id, $contenido));
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

        PedidosDeImagenIaHelper::vencer_las_colgadas([$message]);

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
     * `agentes_ia` + solo el dueño) y encima pasa por la MISMA tenencia doble que todo lo demás:
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
        $message = $this->mensaje_de_la_persona($message_id);

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
     * El audio que el dueño mandó desde la app (misión app-ia-y-memoria, contrato C5):
     * `GET api/ai-mensajes/{message_id}/audio`. Es la `url` de `audio` del mensaje.
     *
     * Mismo criterio que imagen_de_mensaje(): disco `local`, la tenencia doble y 404 (no 403) si no es
     * de esta persona, y `private` para que ningún proxy lo guarde.
     *
     * @param  int  $message_id
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
     */
    public function audio_de_mensaje($message_id)
    {
        $message = $this->mensaje_de_la_persona($message_id);

        $audio = is_null($message) ? null : AudioDelMensajeIaHelper::datos($message);

        if (is_null($audio) || empty($audio['path']) || !Storage::disk('local')->exists($audio['path'])) {
            return response()->json(['message' => 'Audio no encontrado.'], 404);
        }

        /* Solo con un mime de audio validado; si no, como binario (nunca algo que el navegador ejecute). */
        $mime = trim((string) ($audio['mime'] ?? ''));

        return $this->archivo_privado(
            Storage::disk('local')->path($audio['path']),
            AudioDelMensajeIaHelper::es_mime_de_audio($mime) ? $mime : 'application/octet-stream',
            null
        );
    }

    /**
     * Un archivo (PDF, Excel, CSV) que el dueño mandó desde la app (misión app-ia-y-memoria, contrato
     * C5): `GET api/ai-mensajes/{message_id}/archivo/{orden}`, con su nombre en Content-Disposition para
     * que se baje como lo mandó. Misma tenencia que el audio y la foto.
     *
     * @param  int  $message_id
     * @param  int  $orden
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
     */
    public function archivo_de_mensaje($message_id, $orden)
    {
        $message = $this->mensaje_de_la_persona($message_id);

        $archivo = is_null($message) ? null : ArchivosAdjuntosIaHelper::archivo($message, $orden);

        if (is_null($archivo) || empty($archivo['path']) || !Storage::disk('local')->exists($archivo['path'])) {
            return response()->json(['message' => 'Archivo no encontrado.'], 404);
        }

        $mime = trim((string) ($archivo['mime'] ?? ''));

        return $this->archivo_privado(
            Storage::disk('local')->path($archivo['path']),
            $mime === '' ? 'application/octet-stream' : $mime,
            (string) ($archivo['nombre'] ?? 'archivo')
        );
    }

    /**
     * Un binario del disco privado, con caché `private` (ver el 🔴 de imagen_de_mensaje(): el
     * `Cache-Control` se pone con setPrivate(), nunca en el array de headers) y, si se pasa nombre, como
     * descarga con ese nombre (con un respaldo en ASCII: el header no admite tildes sin él). Siempre con
     * `X-Content-Type-Options: nosniff`: el navegador usa el mime que se manda y no adivina otro.
     *
     * @param  string  $ruta
     * @param  string  $mime
     * @param  string|null  $nombre
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    protected function archivo_privado($ruta, $mime, $nombre)
    {
        $respuesta = response()->file($ruta, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']);

        if (!is_null($nombre) && $nombre !== '') {
            $ascii = preg_replace('/[^A-Za-z0-9\.\-_ ]/', '_', Str::ascii($nombre));
            $respuesta->setContentDisposition('attachment', $nombre, $ascii === '' ? 'archivo' : $ascii);
        }

        $respuesta->setPrivate();
        $respuesta->setMaxAge(300);

        return $respuesta;
    }

    /**
     * Guarda lo que trajo el mensaje del dueño desde la app: fotos (el MISMO camino que WhatsApp:
     * AsistenteImagenHelper, webp en ai_message_imagenes), archivos y audio (cada uno en su columna).
     * Después de crear el mensaje: las rutas llevan su id.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  int  $owner_id
     * @param  array  $imagenes
     * @param  array  $archivos
     * @param  mixed  $audio
     * @param  mixed  $duracion_seg
     * @return void
     */
    protected function guardar_adjuntos(AiMessage $mensaje, $owner_id, array $imagenes, array $archivos, $audio, $duracion_seg)
    {
        if (count($imagenes)) {
            AsistenteImagenHelper::guardar($mensaje, $imagenes, $owner_id);
        }

        $cambio = false;

        if (count($archivos)) {
            $entradas = ArchivosAdjuntosIaHelper::guardar($mensaje, $archivos, $owner_id);

            if (count($entradas)) {
                $mensaje->archivos = $entradas;
                $cambio = true;
            }
        }

        if (!is_null($audio)) {
            $datos = AudioDelMensajeIaHelper::guardar($mensaje, $audio, $owner_id, $duracion_seg);

            if (!is_null($datos)) {
                $mensaje->audio = $datos;
                $cambio = true;
            }
        }

        if ($cambio) {
            $mensaje->save();
        }
    }

    /**
     * Me gusta / no me gusta de una respuesta del agente, con el comentario del no me gusta (misión
     * agente-mejoras-uso-real, 29/10/2026): `PUT api/ai-mensajes/{message_id}/valoracion`.
     *
     * Body: `{valoracion: 'me_gusta'|'no_me_gusta'|null, comentario?: string ≤ 1000}`. `null` saca la
     * valoración (volver a tocar el mismo botón). La usan el chat del sistema y la app ComercioCity IA, y
     * la lee el análisis diario de las 22 h. Misma tenencia doble que el resto del chat: 404 —no 403—
     * si el mensaje es de otra persona; 422 si el mensaje es del dueño y no del agente.
     *
     * @param  Request  $request
     * @param  int  $message_id
     * @return JsonResponse
     */
    public function valorar_mensaje(Request $request, $message_id): JsonResponse
    {
        $message = $this->mensaje_de_la_persona($message_id);

        if (is_null($message)) {
            return response()->json(['code' => 'mensaje_inexistente', 'message' => 'Mensaje no encontrado.'], 404);
        }

        if ($message->rol !== 'assistant') {
            return response()->json(['code' => 'no_es_del_agente', 'message' => 'Solo se valoran las respuestas del agente.'], 422);
        }

        $valoracion = $request->input('valoracion');
        $comentario = $request->input('comentario');
        $error = ValoracionIaHelper::error_de_validacion($valoracion, $comentario);

        if (!is_null($error)) {
            return response()->json(['code' => $error, 'message' => 'La valoración no es válida.'], 422);
        }

        ValoracionIaHelper::valorar($message, $valoracion, $comentario);

        return response()->json(['model' => ValoracionIaHelper::para_respuesta($message)], 200);
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

    /**
     * Un mensaje de una conversación de la PERSONA autenticada (null si no existe o es de otra
     * persona/cuenta): la misma tenencia doble, para los binarios que se piden por id de mensaje (la
     * foto, el audio, el archivo).
     *
     * @param  int  $message_id
     * @return AiMessage|null
     */
    protected function mensaje_de_la_persona($message_id)
    {
        return AiMessage::whereIn('ai_conversation_id', function ($query) {
            $query->select('id')
                ->from('ai_conversations')
                ->where('auth_user_id', UserHelper::userId(false))
                ->where('user_id', UserHelper::userId(true));
        })
            ->where('id', (int) $message_id)
            ->first();
    }
}
