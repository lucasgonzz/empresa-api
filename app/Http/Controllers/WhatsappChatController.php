<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Http\Controllers\Helpers\WhatsappTemplateHelper;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTemplate;
use App\Services\WhatsappAiAutoSendScheduler;
use App\Services\WhatsappBotAiService;
use App\Services\WhatsappBotSendService;
use App\Services\WhatsappInboundMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Endpoints REST del módulo de chats de WhatsApp con clientes (grupo 137, Prompt 02),
 * consumidos por empresa-spa. Toda la lógica de negocio vive en `WhatsappChatHelper`;
 * este controller solo valida pertenencia al owner autenticado y orquesta.
 * Rutas gateadas por `auth:sanctum` + `check_extencion_empresa:whatsapp` (ver routes/api.php).
 */
class WhatsappChatController extends Controller
{
    /**
     * Tope de una imagen que manda el operador, en bytes (5 MB, D28).
     *
     * Es el límite real de la Cloud API de Meta, no un número elegido acá. Está duplicado a
     * propósito con el del composer de la SPA: el front evita el viaje inútil de subir algo que
     * va a rebotar, y el back es el que manda porque el front se puede saltear.
     *
     * @var int
     */
    private const MEDIA_MAX_IMAGE_BYTES = 5242880;

    /**
     * Tope de un audio que manda el operador, en bytes (16 MB, D28). Mismo criterio que el de
     * la imagen: es el límite de la Cloud API de Meta.
     *
     * @var int
     */
    private const MEDIA_MAX_AUDIO_BYTES = 16777216;

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
     * `display_name` es el nombre con el que el operador ve el chat en la bandeja. Importa
     * cuando el chat NO va vinculado a un cliente del ERP: desde Clientes el nombre sale del
     * `client_id`, pero un chat abierto desde un pedido de la tienda o desde Compradores no
     * tiene cliente que lo nombre, así que sin este campo la bandeja muestra un número de
     * teléfono pelado y el operador no sabe con quién está hablando.
     *
     * @param  Request  $request  Espera `phone` (obligatorio), `client_id` (opcional) y
     *                            `display_name` (opcional).
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        // El largo se valida y no se trunca: `whatsapp_chats.display_name` es un string(120) y
        // un nombre más largo lo cortaría MySQL por la mitad (o reventaría la query, según el
        // modo estricto de la base). Es `nullable` porque los tres botones que abren un chat
        // mandan lo que tienen a mano y un pedido de la tienda puede no tener comprador cargado.
        $request->validate([
            'display_name' => 'nullable|string|max:120',
        ]);

        $user_id = $this->userId();
        $phone = WhatsappPhoneHelper::normalize($request->phone);

        if ($phone === '') {
            return response()->json(['message' => 'Teléfono inválido.'], 422);
        }

        $display_name = trim((string) $request->display_name);

        $chat = WhatsappChat::where('user_id', $user_id)
            ->where('phone', $phone)
            ->first();

        if (is_null($chat)) {
            $config = WhatsappBotConfig::where('user_id', $user_id)->first();

            $chat = WhatsappChat::create([
                'user_id' => $user_id,
                'phone' => $phone,
                'client_id' => $request->client_id ?: null,
                'display_name' => $display_name !== '' ? $display_name : null,
                'ai_enabled' => ! is_null($config) ? (bool) $config->ai_enabled_default : true,
                'unread_count' => 0,
            ]);
        } elseif ($display_name !== '' && empty($chat->display_name)) {
            // 🔴 SOLO SI EL CHAT NO TENÍA NOMBRE. Este endpoint es idempotente por teléfono: lo
            // llaman los tres botones nuevos de la SPA y se lo come cada vez que el operador
            // abre la misma conversación. Pisar el nombre existente haría que el nombre del
            // chat dependa de por dónde entró la última persona, y se llevaría puesto tanto el
            // nombre que mandó Kapso del perfil de WhatsApp como el que ya venía de otro
            // origen. Rellenar uno vacío, en cambio, no rompe nada y arregla el caso real: un
            // chat que nació de un entrante sin nombre de contacto y que ahora se abre desde un
            // pedido, donde sí sabemos cómo se llama el comprador. Es el mismo criterio, y por
            // los mismos motivos, que `WhatsappChatHelper::store_inbound_message()`.
            $chat->display_name = $display_name;
            $chat->save();
        }

        return response()->json(['model' => $this->fullModel('WhatsappChat', $chat->id)], 201);
    }

    /**
     * Manda un mensaje de texto libre a un chat. Solo funciona dentro de la ventana de
     * 24 h de Meta (`is_within_service_window()`); si está cerrada devuelve 422 con
     * `code: 'fuera_de_ventana'` y NO llega a llamar a Kapso (el front ofrece plantillas).
     *
     * Responde 201 con `{ model, enviado }`: `model` es la fila del mensaje (que se guarda
     * siempre) y `enviado` dice si el texto efectivamente salió hacia WhatsApp. Puede ser false
     * con un 201 al lado: el chat está en simulación o Kapso rechazó el envío.
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

        // 🔴 INTERVENCIÓN HUMANA: si el operador está contestando a mano, la respuesta del
        // agente que quedó esperando confirmación ya no sirve y se descarta. Sin esto el
        // cliente recibe DOS respuestas descoordinadas de la misma empresa: la del operador
        // ahora, y la de la IA cuando vence el plazo y el job la manda igual.
        // Va acá abajo, después de todos los guards: una operación que devuelve error no puede
        // dejar efectos (mismo criterio que está escrito en confirm_ai_message()). Y va ANTES
        // del envío, no después, para achicar todo lo posible la ventana en la que el job de
        // auto-envío puede arrancar mientras este request está esperando a Kapso.
        WhatsappChatHelper::discard_pending_ai_messages($chat);

        $send_service = new WhatsappBotSendService();
        $wa_message_id = $send_service->send_text($chat->phone, $body, $config);

        // Empleado autenticado (sin resolver al owner) que efectivamente mandó el mensaje.
        $sent_by_user_id = $this->userId(false);
        $message = WhatsappChatHelper::store_outbound_manual_message($chat, $body, $wa_message_id, $sent_by_user_id);

        // `enviado` dice si el texto SALIÓ hacia WhatsApp; el 201 dice solamente que la fila del
        // mensaje se creó. Son dos cosas distintas y hasta el 15/8/2026 la respuesta solo
        // informaba la segunda: un 201 pelado sobre un envío frenado (chat en simulación) o
        // fallado (Kapso caído) daba a entender que el cliente lo había recibido.
        //
        // Por qué el status sigue siendo 201 y no un 4xx/5xx: el mensaje YA se persistió y tiene
        // que aparecer en la conversación, marcado como no enviado. La SPA solo lo agrega en el
        // `.then` de `sendMessage()` (store/whatsapp_chat.js) y en el `.catch` no limpia el
        // textarea, así que un status de error dejaría la fila guardada pero invisible hasta
        // recargar, con el texto todavía en el input listo para que el operador lo mande de
        // nuevo y duplique la fila. Cambiar el status obliga a tocar la SPA, y las dos puntas no
        // llegan juntas a producción: el campo se agrega como opcional (compatible hacia atrás)
        // y el front lo puede empezar a leer cuando le toque.
        return response()->json([
            'model'   => $this->fullModel('WhatsappChatMessage', $message->id),
            'enviado' => ! is_null($wa_message_id),
        ], 201);
    }

    /**
     * Manda una foto o un audio que subió el operador desde el composer (misión
     * whatsapp-sidebar-multimedia). Multipart: `file` (obligatorio) y `caption` (opcional, solo
     * para imágenes). Responde `201 { model, enviado }` con el mismo contrato que
     * `send_message()`: el 201 dice que la fila se creó, `enviado` dice si el archivo SALIÓ
     * hacia WhatsApp.
     *
     * 🔴 EL FRENO DE SIMULACIÓN VA ACÁ, ANTES DE `upload_media()`, Y NO ADENTRO DEL SERVICE.
     * `upload_media()` no recibe el teléfono, así que no tiene con qué resolver de qué chat se
     * trata: es el único de los tres métodos nuevos que NO puede llevar el guard adentro. Si el
     * corte no estuviera acá, un chat en simulación subiría el archivo a Kapso de verdad —
     * gastando el viaje y dejando un media_id colgado en Meta— aunque después `send_image()` /
     * `send_audio()` no lo manden. Se mira `$chat->last_inbound_simulated`, que ya viene cargado
     * por `find_owned_chat()`, porque `chat_en_simulacion()` del service es privado.
     *
     * 🔴 EL ESTADO DE ERROR SE ESCRIBE ANTES DEL `create()`, NO DESPUÉS. Cuando el envío falla,
     * `delivery_status = 'fallido'` y `send_error` viajan adentro del `$extra`, así que la fila
     * NACE fallada y el broadcast que sale en vivo a la pantalla del operador ya lleva la
     * verdad. Guardar primero y corregir después con un `save()` deja una ventana en la que la
     * conversación dibuja como "en camino" algo que nunca salió: es el bug #4 de la misión
     * whatsapp-agente ("un envío fallido se marcaba como enviado"), que ya costó un hallazgo.
     *
     * 🔴 EL ARCHIVO LOCAL NO SE BORRA CUANDO EL ENVÍO FALLA, a diferencia del rollback de
     * `admin-api` (que borra mensaje, adjunto y archivo). Acá la fila se conserva marcada como
     * fallida —para que el operador vea qué quiso mandar y pueda reintentarlo—, y una fila con
     * `media_path` apuntando a un archivo borrado es una miniatura rota. El costo de dejarlo es
     * disco; el de borrarlo es un registro mentiroso.
     *
     * Orden de los guards, y no es intercambiable: primero el chat del owner (404), después la
     * ventana de 24 h (422 `fuera_de_ventana`, SIN haber tocado disco ni red), y recién ahí el
     * archivo. Una operación que devuelve error no puede dejar efectos, así que
     * `discard_pending_ai_messages()` va después de TODOS los guards — mismo criterio que
     * `send_message()`.
     *
     * La lista blanca de mimes es la de `WhatsappInboundMediaService::safe_mime()`, la misma con
     * la que se guardan los entrantes y con la que `media()` los sirve. No se escribe una acá:
     * dos listas que se desincronizan son una fuga esperando.
     *
     * @param  Request  $request  Espera `file` (multipart) y `caption` (opcional).
     * @param  int  $id
     * @return JsonResponse
     */
    public function send_media(Request $request, $id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        // Antes que nada y antes de tocar el disco: una foto o un audio suelto (o sea, no
        // plantilla) solo es legal dentro de la ventana de 24 h de Meta. El front ofrece
        // plantillas con este mismo `code`.
        if (! $chat->is_within_service_window()) {
            return response()->json(['code' => 'fuera_de_ventana'], 422);
        }

        $file = $request->file('file');
        if (is_null($file) || is_array($file) || ! $file->isValid()) {
            return response()->json(['message' => 'No llegó ningún archivo válido.'], 422);
        }

        // El mime sale de `getMimeType()` (lo detecta finfo mirando el contenido), NUNCA del
        // nombre ni del `Content-Type` que declaró el navegador: los dos los elige el cliente.
        $mime = WhatsappInboundMediaService::safe_mime($file->getMimeType());
        if (is_null($mime)) {
            return response()->json([
                'message' => 'Ese tipo de archivo no se puede mandar por WhatsApp. Solo imágenes y audios.',
            ], 422);
        }

        $extension = self::extension_de_mime($mime);
        if (is_null($extension)) {
            // No debería pasar nunca: la tabla de abajo cubre toda la lista blanca del servicio.
            // Si pasa, alguien agregó un mime allá y se olvidó acá, y es preferible rechazarlo
            // ruidosamente que subirle a Meta un archivo con extensión incoherente (que Meta
            // rechaza igual, pero recién después de pagar el viaje y con un error mucho más
            // difícil de leer).
            Log::channel('daily')->error('WhatsappChatController: mime permitido sin extensión conocida.', [
                'mime' => $mime,
            ]);

            return response()->json([
                'message' => 'Ese tipo de archivo no se puede mandar por WhatsApp. Solo imágenes y audios.',
            ], 422);
        }

        $es_imagen = strpos($mime, 'image/') === 0;
        $size = (int) $file->getSize();
        $tope = $es_imagen ? self::MEDIA_MAX_IMAGE_BYTES : self::MEDIA_MAX_AUDIO_BYTES;

        if ($size <= 0 || $size > $tope) {
            // El tope está duplicado a propósito con el del front (D28): el front evita el viaje
            // inútil, pero es el back el que manda porque el front se puede saltear.
            return response()->json([
                'message' => $es_imagen
                    ? 'La imagen no puede pesar más de 5 MB.'
                    : 'El audio no puede pesar más de 16 MB.',
            ], 422);
        }

        $config = WhatsappBotConfig::where('user_id', $chat->user_id)->first();
        if (is_null($config)) {
            return response()->json(['message' => 'No hay una configuración de WhatsApp activa para esta empresa.'], 422);
        }

        // Mandar un archivo es una intervención humana: la respuesta que el agente dejó
        // esperando confirmación ya no sirve y se descarta, para que el cliente no reciba dos
        // cosas descoordinadas. Mismo criterio y mismo lugar que en `send_message()`: después de
        // todos los guards (una operación que devuelve error no deja efectos) y antes del envío.
        WhatsappChatHelper::discard_pending_ai_messages($chat);

        // 🔴 El nombre lo arma el servidor entero: la extensión sale del mime ya validado y no
        // del nombre que mandó el cliente. Un `.php` del lado del cliente no puede llegar a
        // escribirse en disco por más que el archivo se llame así.
        $stored_name = 'op_'.time().'_'.mt_rand(1000, 9999).'.'.$extension;
        $carpeta = 'whatsapp/'.$chat->id;
        $stored_path = $carpeta.'/'.$stored_name;

        if (Storage::disk('local')->putFileAs($carpeta, $file, $stored_name) === false) {
            Log::channel('daily')->error('WhatsappChatController: no se pudo guardar el adjunto del operador.', [
                'chat_id' => $chat->id,
                'path'    => $stored_path,
            ]);

            return response()->json(['message' => 'No se pudo guardar el archivo en el servidor.'], 500);
        }

        $caption = trim((string) $request->input('caption', ''));

        // Ver el docblock: el corte por simulación es lo primero del envío, antes de subir nada.
        $frenado_por_simulacion = (bool) $chat->last_inbound_simulated;
        $wa_message_id = null;

        if (! $frenado_por_simulacion) {
            $send_service = new WhatsappBotSendService();
            $media_id = $send_service->upload_media(
                Storage::disk('local')->path($stored_path),
                $mime,
                $stored_name,
                $config
            );

            if (! is_null($media_id)) {
                if ($es_imagen) {
                    $wa_message_id = $send_service->send_image($chat->phone, ['id' => $media_id], $caption, $config);
                } else {
                    // El audio no lleva epígrafe: Meta no acepta `caption` en `audio`. Si el
                    // operador mandara uno, guardarlo en el body dibujaría en la conversación un
                    // texto que el cliente nunca recibió.
                    $wa_message_id = $send_service->send_audio($chat->phone, $media_id, $mime, $config);
                }
            }
        }

        // Los tres literales de relleno son los que la burbuja oculta cuando hay medio para
        // mostrar: sin ellos el body quedaría vacío y la conversación no tendría qué mostrar en
        // la bandeja ni en el historial que ve la IA.
        if ($es_imagen) {
            $body = $caption !== '' ? $caption : '[Imagen enviada]';
        } else {
            $body = '[Audio enviado]';
        }

        $extra = [
            'media_type' => $es_imagen ? 'image' : 'audio',
            'media_path' => $stored_path,
            'media_mime' => $mime,
            'media_size' => $size,
        ];

        // 🔴 El estado fallado va ADENTRO del `$extra`, o sea antes del `create()` y antes del
        // broadcast (ver el docblock). Y la simulación NO entra por acá: ahí no falló nada, el
        // envío se frenó a propósito, y marcar 'fallido' le mostraría al operador un "no se pudo
        // enviar" que es mentira. Es el mismo razonamiento que ya está escrito en
        // `WhatsappChatHelper::send_pending_ai_message()` para la respuesta del agente.
        if (is_null($wa_message_id) && ! $frenado_por_simulacion) {
            $extra['delivery_status'] = 'fallido';
            $extra['send_error'] = 'No se pudo enviar el archivo a WhatsApp.';
        }

        // Empleado autenticado (sin resolver al owner) que efectivamente mandó el archivo.
        $message = WhatsappChatHelper::store_outbound_media_message(
            $chat,
            $body,
            $wa_message_id,
            $this->userId(false),
            $extra
        );

        // `enviado` false con un 201 al lado significa una de dos: el chat está en simulación o
        // WhatsApp rechazó el archivo. En los dos casos la fila existe y tiene que aparecer en
        // la conversación — el mismo motivo por el que `send_message()` tampoco devuelve un 4xx.
        return response()->json([
            'model'   => $this->fullModel('WhatsappChatMessage', $message->id),
            'enviado' => ! is_null($wa_message_id),
        ], 201);
    }

    /**
     * Manda una plantilla de Meta a un chat (grupo 137, Prompt 04). Es el ÚNICO camino
     * para mandar mensajes cuando la ventana de 24 h está cerrada
     * (`!$chat->is_within_service_window()`), pero también funciona con la ventana
     * abierta. Valida que la plantilla sea del owner autenticado, esté `aprobada` y que
     * la cantidad de variables recibidas coincida con la que declara la plantilla.
     *
     * Responde 201 con `{ model, enviado }`, igual que `send_message()`: `model` es la fila del
     * mensaje (que se guarda siempre) y `enviado` dice si la plantilla efectivamente salió.
     *
     * @param  Request  $request  Espera `template_id` y `variables` (array, en el mismo orden que la plantilla).
     * @param  int  $id
     * @return JsonResponse
     */
    public function send_template(Request $request, $id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        $template = WhatsappTemplate::where('id', $request->template_id)
            ->where('user_id', $chat->user_id)
            ->first();
        if (is_null($template)) {
            return response()->json(['message' => 'Plantilla no encontrada.'], 404);
        }

        $variables = is_array($request->variables) ? $request->variables : [];
        $validation_error = WhatsappTemplateHelper::validate_for_send($template, $variables);
        if (! is_null($validation_error)) {
            return response()->json(['message' => $validation_error], 422);
        }

        $config = WhatsappBotConfig::where('user_id', $chat->user_id)->first();
        if (is_null($config)) {
            return response()->json(['message' => 'No hay una configuración de WhatsApp activa para esta empresa.'], 422);
        }

        // Misma intervención humana que en send_message(): el operador está contestando él,
        // así que la respuesta del agente que esperaba confirmación se descarta antes de
        // mandar nada. Si no, el cliente recibe la plantilla y después la respuesta de la IA.
        WhatsappChatHelper::discard_pending_ai_messages($chat);

        $send_service = new WhatsappBotSendService();
        $wa_message_id = $send_service->send_template($chat->phone, $template, $variables, $config);

        // Cuerpo final ya con las variables reemplazadas, para mostrarlo como cualquier otro mensaje del chat.
        $rendered_body = $template->render_body($variables);

        // Empleado autenticado (sin resolver al owner) que efectivamente mandó la plantilla.
        $sent_by_user_id = $this->userId(false);
        $message = WhatsappChatHelper::store_outbound_template_message(
            $chat,
            $rendered_body,
            $wa_message_id,
            $sent_by_user_id,
            $template->meta_template_name
        );

        // Mismo criterio que en `send_message()`: el 201 dice que la fila se creó, `enviado` dice
        // si la plantilla SALIÓ hacia WhatsApp. `send_template()` también devuelve null cuando
        // Kapso falla (clave vencida, caída, rate limit), y sin este campo la respuesta daba a
        // entender que el cliente la había recibido.
        //
        // Va acá aunque el revisor haya marcado solo `send_message()`: dos endpoints casi
        // iguales, uno que informa si salió y otro que no, dejan al que lea el controller sin
        // saber cuál de los dos es el correcto. El status sigue siendo 201 por el mismo motivo
        // que allá (la SPA solo agrega el mensaje en el `.then`), y el campo es opcional, así
        // que el front no se entera hasta que lo quiera leer.
        return response()->json([
            'model'   => $this->fullModel('WhatsappChatMessage', $message->id),
            'enviado' => ! is_null($wa_message_id),
        ], 201);
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

        if (! $chat->ai_enabled) {
            // 🔴 Apagar la IA del chat también es una intervención humana: el operador vio la
            // respuesta que el agente dejó esperando, no le gustó, y se hace cargo él de la
            // conversación. Si la pendiente quedara viva, el job de auto-envío la mandaría
            // igual al vencer el plazo y el cliente terminaría con dos respuestas.
            // Solo al apagar: al prender no hay nada del agente esperando que descartar.
            WhatsappChatHelper::discard_pending_ai_messages($chat);
        }

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
     * Genera una respuesta sugerida con IA para el chat (misma personalidad, mismas reglas
     * fijas y mismo historial que usaría la respuesta automática del webhook), pero NO la
     * envía ni la persiste: el front la carga en el input para que el operador la edite y
     * la mande a mano por `POST whatsapp-chats/{id}/messages` (Prompt 02).
     *
     * 🔴 LA SUGERENCIA SALE COMO TEXTO PELADO, SIN LA FOTO DEL PRODUCTO, Y ES A PROPÓSITO. El
     * agente automático puede adjuntar la foto porque él mismo persiste la fila del mensaje y la
     * manda; acá el texto cae en el input del operador y después viaja por
     * `POST whatsapp-chats/{id}/messages`, que envía `body` y nada más: un adjunto no tiene por
     * dónde pasar en ese camino. Hacer que la sugerencia lleve la foto no es agregar un campo:
     * es un segundo campo en esta respuesta, un adjunto pendiente dibujado en el composer y otro
     * en el endpoint de envío del operador — y el operador YA tiene el clip al lado del input
     * para mandar la foto que quiera. No entra en el arreglo de un bug.
     *
     * Lo que sí se garantiza es que el `[FOTO:...]` con el que el agente pide esa foto no le
     * llegue nunca al operador ni, a través suyo, al cliente: el marcador lo saca
     * `WhatsappBotAiService::generate_response()`, o sea el productor del texto, no este caller.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function suggest($id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        $config = WhatsappBotConfig::where('user_id', $chat->user_id)->first();
        if (is_null($config)) {
            return response()->json(['message' => 'No hay una configuración de WhatsApp activa para esta empresa.'], 422);
        }

        // El proceso se declara distinto del de la respuesta automática ('whatsapp_respuesta')
        // aunque sea la misma llamada HTTP: el gasto tiene otro dueño (lo pidió una persona a
        // mano) y hay que poder distinguirlos al mirar los números. `userId(false)` es el
        // empleado autenticado, sin resolver al dueño.
        $ai_service = new WhatsappBotAiService();
        $suggestion = $ai_service->generate_response($chat, $config, 'whatsapp_sugerencia', $this->userId(false));

        return response()->json(['suggestion' => $suggestion], 200);
    }

    /**
     * Genera un resumen de la conversación con IA (últimos 100 mensajes). Es una llamada
     * aparte de `suggest()`: no usa personalidad ni catálogo, solo una consigna fija de
     * resumen. No persiste nada ni crea filas en `whatsapp_chat_messages`.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function summary($id): JsonResponse
    {
        $chat = $this->find_owned_chat($id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        // Igual que en suggest(): el resumen lo pidió una persona, así que el gasto se
        // registra con su propio proceso y con el empleado autenticado.
        $ai_service = new WhatsappBotAiService();
        $summary = $ai_service->generate_summary($chat, 'whatsapp_resumen', $this->userId(false));

        return response()->json(['summary' => $summary], 200);
    }

    /**
     * Confirma y envía una respuesta del agente que estaba esperando aprobación humana
     * (misión whatsapp-agente). Es el "mandalo ahora" del operador: corta el auto-envío
     * programado, manda por Kapso y marca la fila como enviada.
     *
     * @param  int  $message_id
     * @return JsonResponse
     */
    public function confirm_ai_message($message_id): JsonResponse
    {
        $message = $this->find_owned_ai_message($message_id);
        if (is_null($message)) {
            return response()->json(['message' => 'Mensaje no encontrado.'], 404);
        }

        if ((string) $message->ai_status !== 'a_confirmar') {
            // 'enviando' = la otra punta está mandándolo en este mismo momento; cualquier otro
            // valor = ya salió hace rato. Se distinguen con `code` para que el front pueda
            // decir algo distinto en cada caso. Ojo: este chequeo es solo un atajo para no
            // hacer trabajo al pedo, NO es lo que garantiza que no se mande dos veces — lee el
            // estado sin bloquearlo. Eso lo hace la transición condicional del helper.
            $code = (string) $message->ai_status === 'enviando' ? 'ya_en_envio' : 'ya_no_esta_pendiente';

            return response()->json([
                'code'    => $code,
                'message' => 'El mensaje ya no está esperando confirmación.',
            ], 422);
        }

        $chat = WhatsappChat::find($message->whatsapp_chat_id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        // 🔴 Los dos guards que pueden rechazar el pedido van ANTES de cancelar el token, y el
        // orden importa: si se cancela primero, una confirmación rechazada deja el mensaje en
        // 'a_confirmar' pero ya sin temporizador, o sea que nunca más se enviaría solo. El
        // operador vería un error y, sin saberlo, habría desactivado el auto-envío de ese
        // mensaje. Una operación que devuelve error no puede dejar efectos.
        // Mismo contrato que send_message(): fuera de la ventana de 24 h de Meta no se puede
        // mandar texto libre, el front tiene que ofrecer una plantilla.
        if (! $chat->is_within_service_window()) {
            return response()->json(['code' => 'fuera_de_ventana'], 422);
        }

        $config = WhatsappBotConfig::where('user_id', $chat->user_id)->first();
        if (is_null($config)) {
            return response()->json(['message' => 'No hay una configuración de WhatsApp activa para esta empresa.'], 422);
        }

        // Recién acá, con el envío ya asegurado, se cancela el token del auto-envío.
        //
        // ⚠️ Ojo con lo que esto SÍ y lo que NO garantiza: cancelar el token solamente hace
        // que un job que TODAVÍA NO ARRANCÓ se descarte solo al despertarse. NO frena al job
        // que ya está corriendo: ese lee el token al principio y recién varios pasos después
        // manda el POST a Kapso, que tarda segundos. Si el operador confirma dentro de esa
        // ventana, el token que se cancela acá ya fue leído y saldrían los dos envíos.
        // Lo que de verdad impide el envío doble es la transición de estado condicional que
        // hay adentro de `send_pending_ai_message()`: gana uno solo.
        (new WhatsappAiAutoSendScheduler())->cancel_for_message((int) $message->id);

        $resultado = WhatsappChatHelper::send_pending_ai_message($message, $chat, $config);

        if ($resultado === WhatsappChatHelper::ENVIO_YA_TOMADO) {
            // El job de auto-envío ganó la transición y lo está mandando él: no se manda de
            // nuevo. Se le avisa al operador para que no se quede esperando un cambio que va
            // a llegar por el broadcast del otro proceso.
            return response()->json([
                'code'    => 'ya_en_envio',
                'message' => 'El mensaje ya se está enviando solo, no hizo falta confirmarlo.',
            ], 422);
        }

        if ($resultado === WhatsappChatHelper::ENVIO_FALLIDO) {
            // WhatsApp no lo aceptó. NO se puede devolver 200 con el modelo como si hubiera
            // salido: el cliente no recibió nada y el operador tiene que enterarse. El mensaje
            // quedó en 'a_confirmar' con el motivo en `send_error`, así que se puede reintentar.
            return response()->json([
                'code'    => 'envio_fallido',
                'message' => 'No se pudo enviar el mensaje por WhatsApp. Quedó pendiente y podés volver a intentarlo.',
                'model'   => $this->fullModel('WhatsappChatMessage', $message->id),
            ], 422);
        }

        return response()->json(['model' => $this->fullModel('WhatsappChatMessage', $message->id)], 200);
    }

    /**
     * Descarta una respuesta del agente que estaba esperando aprobación humana: cancela el
     * auto-envío y BORRA la fila (misma decisión que cuando el cliente sigue escribiendo: si
     * quedara, la IA la leería como algo que ya contestó y el operador vería en la
     * conversación un mensaje que el cliente nunca recibió).
     *
     * @param  int  $message_id
     * @return JsonResponse
     */
    public function discard_ai_message($message_id): JsonResponse
    {
        $message = $this->find_owned_ai_message($message_id);
        if (is_null($message)) {
            return response()->json(['message' => 'Mensaje no encontrado.'], 404);
        }

        if ((string) $message->ai_status !== 'a_confirmar') {
            // Mismo criterio que en confirm_ai_message(): atajo con el motivo distinguible por
            // `code`. La defensa de verdad es el borrado condicional de abajo.
            $code = (string) $message->ai_status === 'enviando' ? 'ya_en_envio' : 'ya_no_esta_pendiente';

            return response()->json([
                'code'    => $code,
                'message' => 'El mensaje ya no está esperando confirmación.',
            ], 422);
        }

        $chat = WhatsappChat::find($message->whatsapp_chat_id);

        // El borrado es CONDICIONAL adentro del helper (solo se va si sigue en 'a_confirmar').
        // El chequeo de arriba lee el estado y no lo bloquea: entre esa lectura y el borrado el
        // job de auto-envío puede haber arrancado el POST a Kapso, y borrar ahí dejaría al
        // cliente recibiendo un mensaje del que no queda ningún registro.
        $descartado = WhatsappChatHelper::discard_pending_ai_message($message);

        if (! $descartado) {
            return response()->json([
                'code'    => 'ya_en_envio',
                'message' => 'No se pudo descartar: el mensaje ya salió hacia el cliente.',
            ], 422);
        }

        if (! is_null($chat)) {
            // Sin mensaje adjunto: el front no puede actualizar una fila que ya no existe.
            WhatsappChatHelper::broadcast_update((int) $chat->user_id, $chat);
        }

        return response()->json(['message' => 'Mensaje descartado.'], 200);
    }

    /**
     * Devuelve el archivo adjunto de un mensaje de la conversación (misión
     * whatsapp-sidebar-multimedia). Es la ÚNICA forma de ver una foto o escuchar un audio de
     * un chat: la SPA usa esta URL directo en `<img src>` y en `<audio src>`, armada por el
     * accessor `media_src` del modelo.
     *
     * 🔴 POR QUÉ EXISTE ESTA RUTA EN VEZ DE GUARDAR EL ARCHIVO EN EL DISCO `public`:
     * `routes/web.php:199-207` sirve `/storage/{path}` de forma pública, sin auth y con
     * `->where('path', '.*')`. Una foto o un audio de una conversación privada guardados ahí
     * quedarían abiertos para siempre a cualquiera que tenga o adivine la URL — sin sesión,
     * sin pertenencia y sin forma de revocarlo. Los medios de conversación viven en el disco
     * `local`, fuera del docroot, y salen solo por acá: `auth:sanctum` +
     * `check_extencion_empresa:whatsapp` (los dos en `routes/api.php`) + el chequeo de
     * pertenencia de abajo. Si alguien "simplifica" esto mandando los archivos a
     * `Storage::disk('public')`, lo que se rompe es la privacidad de todas las conversaciones,
     * en silencio y sin ningún error.
     *
     * 🔴 EL `Content-Type` SE VUELVE A VALIDAR CONTRA LA LISTA BLANCA, aunque ya se haya
     * validado al guardarlo. Acá lo sirve PHP, no una ruta firmada como en `admin-api`: si se
     * confiara en la columna sin revisar, una fila cargada por otro camino podría hacer que
     * este endpoint devuelva un `text/html` con el cuerpo que eligió un tercero, servido desde
     * el dominio de la API y con la sesión del operador puesta. El `nosniff` está por lo mismo:
     * sin él el navegador puede ignorar el `Content-Type` y adivinar mirando el contenido.
     *
     * Todos los caminos de error devuelven 404 y no 403: quién tiene o no un adjunto es
     * información del chat, y un 403 confirmaría que el mensaje existe.
     *
     * @param  int  $chat_id
     * @param  int  $message_id
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function media($chat_id, $message_id)
    {
        $chat = $this->find_owned_chat($chat_id);
        if (is_null($chat)) {
            return response()->json(['message' => 'Chat no encontrado.'], 404);
        }

        // El mensaje se busca SIEMPRE acotado al chat ya validado: sin este `where` alcanzaría
        // con pasar un id de chat propio y un id de mensaje ajeno para leer el adjunto de otra
        // empresa.
        $message = WhatsappChatMessage::where('id', $message_id)
            ->where('whatsapp_chat_id', $chat->id)
            ->first();

        if (is_null($message)) {
            return response()->json(['message' => 'Mensaje no encontrado.'], 404);
        }

        $media_path = trim((string) $message->media_path);
        if ($media_path === '') {
            return response()->json(['message' => 'El mensaje no tiene un archivo adjunto.'], 404);
        }

        $disk = Storage::disk('local');

        // La fila puede tener path y el archivo no estar (se limpió el disco, se restauró una
        // base en otro servidor). Es un 404, no una excepción de "file not found" con 500.
        if (! $disk->exists($media_path)) {
            return response()->json(['message' => 'El archivo adjunto ya no está disponible.'], 404);
        }

        $mime = WhatsappInboundMediaService::safe_mime($message->media_mime);
        if (is_null($mime)) {
            // Fuera de la lista blanca se sirve como binario opaco: el navegador lo baja en vez
            // de interpretarlo. No se devuelve 404 porque el archivo existe y es del operador.
            $mime = 'application/octet-stream';
        }

        return response()->file($disk->path($media_path), [
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Extensión de archivo para un mime YA validado.
     *
     * 🔴 DELEGA EN `WhatsappInboundMediaService`, NO TIENE TABLA PROPIA. Acá vivía una copia
     * literal de las doce entradas, y duraba hasta que alguien sumara un mime de un solo lado:
     * el que se olvidara aceptaría un archivo que el otro no sabe nombrar. Quién entra y con qué
     * extensión sale son la misma decisión, así que viven en la misma constante.
     *
     * @param  string  $mime  Mime normalizado (minúsculas, sin el parámetro después del `;`).
     * @return string|null  null si el mime no está en la lista blanca.
     */
    private static function extension_de_mime($mime)
    {
        return WhatsappInboundMediaService::extension_for($mime);
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

    /**
     * Busca un mensaje por id validando, a través de su chat, que pertenezca al owner del
     * usuario autenticado. Sin este filtro un empleado de otra empresa podría confirmar o
     * borrar mensajes ajenos pasando un id cualquiera.
     *
     * @param  int  $message_id
     * @return WhatsappChatMessage|null
     */
    private function find_owned_ai_message($message_id)
    {
        $user_id = $this->userId();

        return WhatsappChatMessage::where('id', $message_id)
            ->whereHas('whatsapp_chat', function ($chat_query) use ($user_id) {
                $chat_query->where('user_id', $user_id);
            })
            ->first();
    }
}
