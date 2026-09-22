<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AdjuntosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use App\Http\Controllers\Helpers\MostradorHelper;
use App\Http\Controllers\Helpers\asistente_ia\MostradorAccesoHelper;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use App\Models\MostradorReporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El asistente del negocio, hablado desde WhatsApp (misión asistente-por-whatsapp, §3.2 del plan,
 * 16/9/2026).
 *
 * El dueño le escribe al número de ComercioCity, el admin recibe el webhook de Kapso y empuja el
 * mensaje acá. De este lado es EL MISMO asistente que el del botón flotante: la misma
 * AiConversation, los mismos AiMessage, el mismo ResponderMensajeChatIaJob. Lo único que cambia es
 * `ai_messages.canal`, que adentro del loop decide el prompt y qué herramientas se declaran.
 *
 * Por qué el admin hace polling y no le mandamos un callback: todos los servicios que ya existen
 * van en un solo sentido (admin → cliente) y el `.env` de los 40+ clientes no tiene garantizado ni
 * ADMIN_API_URL ni ADMIN_API_OUTBOUND_KEY. Un callback agregaría una configuración por cliente que
 * puede faltar en silencio — la clase "la URL que un sistema le entrega a otro, armada con
 * APP_URL" de APRENDER_NO_PARCHEAR.md (9/9/2026).
 *
 * 🔴 LA CLAVE SE EXIGE SIEMPRE, AUNQUE `services.admin_api.require_api_key` ESTÉ EN `false`.
 * El middleware AdminApiKey :26 saltea la validación con ese flag y en producción está apagado
 * (medido el 14/9 en el informe del mostrador). Un canal que CARGA COMPRAS, GASTOS Y PAGOS no
 * puede quedar abierto a cualquiera que sepa el dominio del cliente, así que el header se valida
 * acá adentro sin mirar el flag. Si el cliente no tiene ADMIN_API_INBOUND_KEY cargada, el canal no
 * se prende para él — y eso es lo correcto, no un bug a saltear.
 *
 * 🔴 Y el gate de la extensión también va a mano: admin-sync no pasa por `auth:sanctum`, así que
 * `CheckExtencionEmpresa` no tiene de dónde sacar el usuario. Mismo 403, resuelto contra el dueño.
 */
class AsistenteController extends Controller
{
    /** Techo del texto de un mensaje, igual que el del chat de la pantalla (AiConversationController). */
    const MAX_TEXTO = 4000;

    /** Los tres tipos de mensaje que manda el admin. `audio` llega ya transcripto por Kapso. */
    const TIPOS = ['texto', 'audio', 'imagen'];

    /**
     * Lo que el admin manda como texto cuando Kapso no pudo transcribir un audio
     * (`WhatsappWebhookController::extract_audio_body()`).
     *
     * 🔴 Ese caso se resuelve ACÁ y de forma determinista, sin gastar una llamada a la IA. Antes el
     * literal se guardaba como si el dueño lo hubiera escrito y quedaba en el historial para
     * siempre, y lo único que lo manejaba era un renglón del prompt: o sea que dependía de que el
     * modelo reconociera ese texto y se acordara de contestar bien. Un audio que no se entendió es
     * un hecho, no una interpretación.
     */
    const AUDIO_SIN_TRANSCRIPCION = '[Audio sin transcripción]';

    /** Lo que se le contesta al dueño cuando el audio llegó sin transcribir. */
    const RESPUESTA_AUDIO_SIN_TRANSCRIPCION = 'No me llegó lo que dijiste en el audio. ¿Me lo escribís?';

    /**
     * Cuántos días hacia atrás, contando hoy, se buscan informes sin avisar.
     *
     * 🔴 No es "los de hoy", y el motivo es que el caso de fallo NO es exótico: es el esperado al
     * arrancar. A las 8:30 la ventana de 24 h de WhatsApp está cerrada para casi todos los dueños,
     * así que sin la plantilla de Meta aprobada no sale nada (riesgo §12.1 del plan) — y con la
     * ventana en "hoy", ese informe ya es de ayer en la próxima corrida y no lo agarra nadie nunca.
     * Pasa lo mismo con la skill /mostrador, que se corre A MANO: un informe depositado a las 09:00
     * quedaba fuera de la corrida de esa mañana y de todas las siguientes.
     *
     * Tres días alcanzan para cubrir un fin de semana largo de plantilla trabada sin llegar a
     * mandarle al dueño un informe tan viejo que ya no le sirve. La única marca que saca un informe
     * de la lista sigue siendo `avisado_at`.
     */
    const DIAS_DE_INFORMES_PENDIENTES = 3;

    /**
     * POST admin-sync/asistente/mensajes  (multipart/form-data)
     *
     * Campos: `texto` (obligatorio, hasta MAX_TEXTO), `tipo` (texto|audio|imagen),
     * `ai_conversation_id` (opcional: solo cuando el admin lo dedujo de una cita),
     * `whatsapp_message_id` (opcional), `imagenes[]` (0 a AsistenteImagenHelper::MAX_IMAGENES).
     *
     * Guarda el mensaje del dueño, deja el assistant 'pendiente' y despacha el job de siempre.
     * Contesta 202 con los dos ids: el admin hace polling con la ruta 2 y manda el texto por
     * WhatsApp cuando esté.
     *
     * 🔴 A diferencia del chat de la pantalla, acá NO hay 409 `respuesta_en_curso`. En la SPA ese
     * 409 le dice a la persona "esperá a que llegue la anterior" y la persona lo ve; en WhatsApp
     * el mensaje ya se mandó y rebotarlo lo perdería sin que el dueño se entere. Dos mensajes
     * seguidos generan dos respuestas, que es exactamente lo que pasa en cualquier chat.
     *
     * @param  Request  $request
     * @return JsonResponse  202 · 401 sin clave · 403 sin extensión · 409 sin dueño resuelto · 422 validación
     */
    public function mensajes(Request $request): JsonResponse
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $dueno = AsistenteCanalHelper::dueno();

        $texto = trim((string) $request->input('texto'));

        if (mb_strlen($texto) > self::MAX_TEXTO) {

            return response()->json(['message' => 'El mensaje supera los ' . self::MAX_TEXTO . ' caracteres.'], 422);
        }

        $tipo = (string) $request->input('tipo', 'texto');

        if (!in_array($tipo, self::TIPOS, true)) {

            return response()->json(['message' => 'El tipo tiene que ser uno de: ' . implode(', ', self::TIPOS) . '.'], 422);
        }

        $imagenes = $this->imagenes_del_request($request);

        $motivo = AsistenteImagenHelper::motivo_de_rechazo($imagenes);

        if (!is_null($motivo)) {

            return response()->json(['message' => $motivo], 422);
        }

        $sin_transcribir = $this->es_audio_sin_transcribir($tipo, $texto, $imagenes);

        /*
         * 🔴 UNA FOTO SIN EPÍGRAFE ES UN MENSAJE VÁLIDO, y de hecho es el caso normal: el dueño saca
         * la foto de la factura, la manda sin escribir nada, y recién en el mensaje siguiente dice
         * de qué proveedor es. Rechazarlo con 422 le devolvía el texto de disculpa por un mensaje
         * perfecto. Sin fotos y sin texto sí es un 422: no hay nada que contestar.
         */
        if ($texto === '' && !count($imagenes) && !$sin_transcribir) {

            return response()->json(['message' => 'El mensaje no puede venir vacío.'], 422);
        }

        $whatsapp_message_id = $this->wamid_del_request($request);

        /*
         * 🔴 IDEMPOTENCIA POR wamid. El admin reintenta el mismo POST ante un timeout o un 5xx, con
         * el mismo wamid de Kapso. Sin esto, ese reintento creaba un segundo AiMessage del dueño, un
         * segundo job y un SEGUNDO WhatsApp con la misma respuesta. Se devuelven los ids de la
         * primera vez, que es lo que el admin necesita para seguir con su polling.
         */
        $ya_estaba = $this->mensaje_ya_recibido($dueno, $whatsapp_message_id);

        if (!is_null($ya_estaba)) {

            return $this->respuesta_del_turno($ya_estaba);
        }

        /*
         * 🔴 EL TOPE DEL PLAN SE MIDE ACÁ ARRIBA, ANTES DE ELEGIR LA CONVERSACIÓN. Un negocio que
         * se pasó del tope se contesta con el texto de límite SIN gastar una llamada a la API — y
         * la decisión de hilo (HiloPorTemaIaHelper) ES una llamada a la API. Se calcula una sola
         * vez y se reusa más abajo, así que no cuesta una consulta de más.
         */
        $supero_el_tope = TopeDeTokensHelper::estado($dueno)['supero'];

        /*
         * 🔴 Y un audio que Kapso no pudo transcribir NO dice nada del tema: viaja como texto
         * vacío para que HiloPorTemaIaHelper no lo tome por un pedido nuevo (el literal
         * AUDIO_SIN_TRANSCRIPCION tiene 23 caracteres y pasaría el umbral de largo). El mensaje
         * igual se guarda con su texto real unas líneas más abajo: esto es solo con qué se decide
         * el hilo.
         */
        $texto_del_tema = ($sin_transcribir || $supero_el_tope) ? '' : $texto;

        $conversation = AsistenteCanalHelper::conversacion($dueno, $request->input('ai_conversation_id'), $texto_del_tema);

        /*
         * Mismo criterio que AiConversationController::send_message(): el título se infiere solo
         * en el primer mensaje del dueño de una conversación sin título, y se decide ANTES de
         * crear los mensajes para no contarse a sí mismo.
         */
        $inferir_titulo = is_null($conversation->titulo)
            && !AiMessage::where('ai_conversation_id', $conversation->id)
                ->where('rol', 'user')
                ->exists();

        $user_message = AiMessage::create([
            'ai_conversation_id'  => $conversation->id,
            'rol'                 => 'user',
            /*
             * Una foto sin epígrafe deja el contenido vacío, no un texto inventado. Lo que hace que
             * ese mensaje igual llegue al modelo es que AsistenteIaService::build_messages_payload()
             * incluye los mensajes que tienen fotos aunque no tengan texto.
             */
            'contenido'           => $texto,
            'estado'              => 'listo',
            'canal'               => AiMessage::CANAL_WHATSAPP,
            'tipo'                => $tipo,
            'whatsapp_message_id' => $whatsapp_message_id,
        ]);

        if (count($imagenes)) {

            /* Después de crear el mensaje: la ruta de la foto lleva su id. */
            AsistenteImagenHelper::guardar($user_message, $imagenes, (int) $dueno->id);
        }

        $assistant_message = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'canal'                => AiMessage::CANAL_WHATSAPP,
            /* El mismo wamid en las dos filas del turno: es lo que hace idempotente el reintento. */
            'whatsapp_message_id'  => $whatsapp_message_id,
            /*
             * Siempre con las herramientas de carga. En la pantalla el flag existe porque una
             * pestaña vieja no sabe pintar tarjetas; acá el canal es nuevo entero y la
             * confirmación es por texto (confirmar_carga_pendiente), así que no hay ningún
             * consumidor viejo al que dejarle una tarjeta que no pueda resolver.
             */
            'acciones_habilitadas' => true,
        ]);

        $conversation->last_message_at = now();
        $conversation->save();

        /*
         * 🔴 Un audio que llegó sin transcribir no sale a la IA: se contesta acá, con un texto fijo.
         * Ver AUDIO_SIN_TRANSCRIPCION. El mensaje del dueño queda igual en el historial (con su
         * `tipo`), así que la conversación no tiene un hueco.
         */
        if ($sin_transcribir) {

            $assistant_message->contenido = self::RESPUESTA_AUDIO_SIN_TRANSCRIPCION;
            $assistant_message->estado = 'listo';
            $assistant_message->save();

            return $this->respuesta_del_turno($assistant_message);
        }

        /*
         * Corte por tope del plan (misión foto-sucursal-y-asistente-configurable): si el dueño ya
         * superó el tope de su plan este mes, se contesta con el texto de límite SIN despachar el
         * job ni gastar una llamada a la API. El assistant nace 'listo' con el wamid ya puesto, así
         * que un reintento del admin con el mismo wamid sigue siendo idempotente, y el admin lee el
         * texto por el polling de siempre. Sin tope configurado no corta.
         *
         * El estado se midió arriba, antes de elegir la conversación (ver el 🔴 de allá): una sola
         * consulta para las dos decisiones.
         */
        if ($supero_el_tope) {

            $assistant_message->contenido = TopeDeTokensHelper::MENSAJE_LIMITE;
            $assistant_message->estado = 'listo';
            $assistant_message->save();

            return $this->respuesta_del_turno($assistant_message);
        }

        /*
         * Misma red de seguridad que el chat de la pantalla: si dispatch() lanza, el assistant no
         * puede quedar 'pendiente' — ningún worker lo va a resolver y el admin estaría 180
         * segundos haciendo polling sobre un mensaje muerto.
         */
        try {

            dispatch(new ResponderMensajeChatIaJob($assistant_message->id));

        } catch (\Throwable $e) {

            $assistant_message->contenido = ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE;
            $assistant_message->estado = 'error';
            $assistant_message->error_mensaje = mb_substr('no se pudo encolar el job de respuesta: ' . $e->getMessage(), 0, 5000);
            $assistant_message->save();

            throw $e;
        }

        if ($inferir_titulo && $texto !== '') {

            /* El título es cosmético: si no se puede encolar, no voltea un mensaje ya despachado. */
            try {

                dispatch(new InferirTituloConversacionIaJob($conversation->id, $texto));

            } catch (\Throwable $e) {

                Log::warning('AdminSync\AsistenteController: no se pudo encolar la inferencia del título', [
                    'ai_conversation_id' => $conversation->id,
                    'message'            => $e->getMessage(),
                ]);
            }
        }

        return $this->respuesta_del_turno($assistant_message);
    }

    /**
     * El 202 del contrato para un turno.
     *
     * `estado` viaja con el valor REAL del mensaje y no con el literal 'pendiente' del §7: en el
     * camino normal es 'pendiente' igual, pero en los dos caminos que contestan de una —el
     * reintento con el mismo wamid y el audio sin transcribir— decir 'pendiente' sobre un mensaje
     * que ya está 'listo' sería lo único de los dos que puede ser falso. El admin hace polling en
     * todos los casos, así que informar de más nunca lo rompe.
     *
     * @param  \App\Models\AiMessage  $assistant
     * @return JsonResponse
     */
    protected function respuesta_del_turno(AiMessage $assistant): JsonResponse
    {
        return response()->json([
            'ai_conversation_id' => (int) $assistant->ai_conversation_id,
            'ai_message_id'      => (int) $assistant->id,
            'estado'             => (string) $assistant->estado,
        ], 202);
    }

    /**
     * El wamid del request, recortado, o null si no vino.
     *
     * @param  Request  $request
     * @return string|null
     */
    protected function wamid_del_request(Request $request)
    {
        $wamid = trim((string) $request->input('whatsapp_message_id'));

        return $wamid === '' ? null : mb_substr($wamid, 0, 128);
    }

    /**
     * El assistant del turno que ya atendió este wamid para este dueño, o null.
     *
     * Sin wamid no hay nada que deduplicar: el admin siempre lo manda, pero un llamador sin él
     * simplemente no tiene idempotencia (mejor eso que colapsar dos mensajes distintos en uno).
     *
     * @param  \App\Models\User  $dueno
     * @param  string|null  $whatsapp_message_id
     * @return \App\Models\AiMessage|null
     */
    protected function mensaje_ya_recibido($dueno, $whatsapp_message_id)
    {
        if (is_null($whatsapp_message_id)) {

            return null;
        }

        return AiMessage::where('ai_messages.whatsapp_message_id', $whatsapp_message_id)
                        ->where('ai_messages.rol', 'assistant')
                        ->whereIn('ai_messages.ai_conversation_id', function ($query) use ($dueno) {
                            $query->select('id')
                                    ->from('ai_conversations')
                                    ->where('user_id', $dueno->id)
                                    ->where('auth_user_id', $dueno->id);
                        })
                        ->orderBy('ai_messages.id')
                        ->first();
    }

    /**
     * true si esto es un audio que llegó sin transcripción y no trae nada más con qué contestar.
     *
     * @param  string  $tipo
     * @param  string  $texto
     * @param  array  $imagenes
     * @return bool
     */
    protected function es_audio_sin_transcribir($tipo, $texto, array $imagenes)
    {
        if ($tipo !== 'audio' || count($imagenes)) {

            return false;
        }

        return $texto === '' || $texto === self::AUDIO_SIN_TRANSCRIPCION;
    }

    /**
     * GET admin-sync/asistente/mensajes/{id}
     *
     * El polling del admin. Devuelve el estado del assistant y, cuando está, su texto.
     *
     * 404 si el mensaje no existe, no es del dueño de esta instancia o no es de canal WhatsApp: el
     * admin no tiene por qué poder leer las conversaciones que el dueño tiene abiertas en la
     * pantalla del sistema.
     *
     * `adjuntos` (misión asistente-omnisciente, contrato §2): las imágenes que la respuesta lleva
     * colgadas, como `[{tipo, url, texto}]` —sin `articulo_id`, que el admin no necesita—. El admin
     * manda primero el texto y después una imagen por adjunto con `texto` de epígrafe. Siempre
     * lista: un admin viejo ignora la clave, y un admin nuevo con `[]` no manda ninguna imagen.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse  200 · 401 · 403 · 404 · 409 sin dueño resuelto
     */
    public function mostrar_mensaje(Request $request, $id): JsonResponse
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $dueno = AsistenteCanalHelper::dueno();

        $mensaje = AiMessage::where('ai_messages.id', (int) $id)
                            ->where('ai_messages.canal', AiMessage::CANAL_WHATSAPP)
                            ->whereIn('ai_messages.ai_conversation_id', function ($query) use ($dueno) {
                                $query->select('id')
                                        ->from('ai_conversations')
                                        ->where('user_id', $dueno->id)
                                        ->where('auth_user_id', $dueno->id);
                            })
                            ->first();

        if (is_null($mensaje)) {

            return response()->json(['message' => 'Mensaje no encontrado.'], 404);
        }

        return response()->json([
            'estado'             => (string) $mensaje->estado,
            'contenido'          => is_null($mensaje->contenido) ? null : (string) $mensaje->contenido,
            'error_mensaje'      => is_null($mensaje->error_mensaje) ? null : (string) $mensaje->error_mensaje,
            'ai_conversation_id' => (int) $mensaje->ai_conversation_id,
            'adjuntos'           => AdjuntosIaHelper::para_el_admin($mensaje->adjuntos),
        ], 200);
    }

    /**
     * GET admin-sync/asistente/informes-pendientes
     *
     * Los informes del mostrador listos de los últimos DIAS_DE_INFORMES_PENDIENTES días que
     * todavía no se le avisaron al dueño, del más viejo al más nuevo. Cada uno viaja con su link ya
     * emitido (§3.7) y con el id de SU conversación: el admin arma un solo mensaje de WhatsApp con
     * el título, el resumen y el link de cada uno.
     *
     * 🔴 `ai_conversation_id` es lo que hace que preguntar sobre el informe funcione, que es un
     * pedido textual de Lucas ("que pueda abrirlos desde el celular y también les pueda hacer
     * preguntas acerca de esos informes"). El admin guarda ese id contra el wamid del mensaje que
     * manda; cuando el dueño contesta "¿por qué bajó la caja?", la cita lo resuelve y el mensaje
     * entra en la conversación DEL INFORME — la única que tiene el informe entero como `contexto`
     * de fondo. Sin esto, el asistente contestaba sin saber de qué informe le hablaban.
     *
     * 🔴 `url` puede venir en null, y eso es correcto, no un bug: significa que esta instancia no
     * tiene cargada la SPA_URL del cliente y el admin tiene que mandar el resumen SIN link. Un link
     * armado con `app.url` (que es la URL de la API) daría 404 en el teléfono del dueño.
     *
     * La ventana se mide por `generado_at` y no por `fecha`: `fecha` es el día del que HABLA el
     * informe (para 'dia' y 'tienda' es ayer), y lo que hay que avisar es lo que se escribió.
     *
     * @param  Request  $request
     * @return JsonResponse  200 {informes:[{id, tipo, titulo, resumen, url, token, ai_conversation_id}]} · 401 · 403 · 409
     */
    public function informes_pendientes(Request $request): JsonResponse
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $dueno = AsistenteCanalHelper::dueno();

        $desde = now()->startOfDay()->subDays(self::DIAS_DE_INFORMES_PENDIENTES - 1);

        $reportes = MostradorReporte::where('user_id', $dueno->id)
                                    ->listos()
                                    ->whereNull('avisado_at')
                                    ->where('generado_at', '>=', $desde)
                                    /* Del más viejo al más nuevo: el que más esperó sale primero. */
                                    ->orderBy('generado_at')
                                    ->orderBy('id')
                                    ->get();

        $informes = [];

        foreach ($reportes as $reporte) {

            $conversacion = MostradorHelper::asegurar_conversacion($reporte, (int) $dueno->id, (int) $dueno->id);

            /*
             * El acceso viaja con las DOS claves. `url` es el link ya armado, que solo existe si
             * esta instancia tiene `SPA_URL`; `token` va siempre, y es con lo que el admin arma el
             * link desde su lado con `client_apis.spa_url`. Hoy ningún cliente tiene `SPA_URL`
             * cargada (no la escribe ningún seeder ni instalador), así que el camino que de verdad
             * se usa es el del token: si acá se mandara solo la URL, el informe saldría sin link
             * para todos.
             */
            $acceso = MostradorAccesoHelper::emitir($reporte);

            $informes[] = [
                'id'                 => (int) $reporte->id,
                'tipo'               => (string) $reporte->tipo,
                'titulo'             => (string) $reporte->titulo,
                'resumen'            => (string) $reporte->resumen,
                'url'                => $acceso['url'],
                'token'              => $acceso['token'],
                'ai_conversation_id' => (int) $conversacion['model']->id,
            ];
        }

        return response()->json(['informes' => $informes], 200);
    }

    /**
     * POST admin-sync/asistente/informes/{id}/avisado
     *
     * Sella el informe como avisado.
     *
     * 🔴 SON DOS PASOS A PROPÓSITO. El admin lo llama SOLO después de que el WhatsApp salió: si el
     * envío falla (ventana de 24 h cerrada, plantilla no aprobada, Kapso caído), el informe queda
     * sin marcar y el aviso vuelve a salir mientras siga adentro de la ventana de
     * DIAS_DE_INFORMES_PENDIENTES. Marcar al pedir los informes dejaría al dueño sin su informe y
     * sin forma de recuperarlo.
     *
     * Idempotente: un segundo aviso sobre el mismo informe no pisa la marca original.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse  200 {ok:true} · 401 · 403 · 404 · 409 sin dueño resuelto
     */
    public function informe_avisado(Request $request, $id): JsonResponse
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $dueno = AsistenteCanalHelper::dueno();

        $reporte = MostradorReporte::where('user_id', $dueno->id)
                                    ->where('id', (int) $id)
                                    ->first();

        if (is_null($reporte)) {

            return response()->json(['message' => 'Informe no encontrado.'], 404);
        }

        if (is_null($reporte->avisado_at)) {

            $reporte->avisado_at = now();
            $reporte->save();
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * La clave y el gate, en un solo lugar: devuelve la respuesta de rechazo o null si puede pasar.
     *
     * El orden importa. Primero la CLAVE (401): sin ella no se le contesta nada a nadie, ni
     * siquiera si existe el dueño. Después el dueño y por último la extensión (403), para
     * que el admin pueda distinguir "este cliente no tiene el módulo" de "no pude resolver a quién
     * le estás hablando" y avisar distinto.
     *
     * 🔴 El dueño no resuelto da **409, no 404**, y la diferencia importa de verdad. Para el admin,
     * un 404 en estas rutas significa una sola cosa: "este cliente todavía no tiene el endpoint",
     * o sea que está en una versión vieja — y con eso le dice al dueño que su sistema no tiene la
     * función y deja de reintentar. Pero un cliente **ya actualizado** puede no poder decidir el
     * dueño si está en una base compartida sin `app.USER_ID` en su `.env`, que es una configuración
     * real de producción. Con 404 se le estaría diciendo a ese dueño que actualice un sistema que
     * ya está actualizado, y el problema verdadero —una variable sin cargar— no lo vería nadie.
     * Lo encontró el revisor de merge del 16/9/2026.
     *
     * @param  Request  $request
     * @return JsonResponse|null
     */
    protected function rechazo_de_acceso(Request $request)
    {
        if (!$this->clave_valida($request)) {

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return response()->json([
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        if (!AsistenteCanalHelper::tiene_extension($dueno)) {

            return response()->json([
                'message' => 'No tenés acceso a esta funcionalidad. Extensión requerida: ' . AsistenteCanalHelper::EXTENSION,
            ], 403);
        }

        return null;
    }

    /**
     * Compara el header `X-Admin-Api-Key` contra `services.admin_api.api_key`, SIN mirar
     * `require_api_key`. Ver el 🔴 del docblock de la clase: este canal escribe plata.
     *
     * Una clave no configurada de este lado es un 401, no un pase libre.
     *
     * @param  Request  $request
     * @return bool
     */
    protected function clave_valida(Request $request)
    {
        $recibida = (string) $request->header('X-Admin-Api-Key');
        $esperada = (string) config('services.admin_api.api_key');

        if ($esperada === '' || $recibida === '') {

            return false;
        }

        return hash_equals($esperada, $recibida);
    }

    /**
     * Las fotos del request, siempre como lista (el admin puede mandar una sola sin corchetes).
     *
     * @param  Request  $request
     * @return array  UploadedFile[]
     */
    protected function imagenes_del_request(Request $request)
    {
        $imagenes = $request->file('imagenes');

        if (is_null($imagenes)) {

            return [];
        }

        return is_array($imagenes) ? array_values($imagenes) : [$imagenes];
    }
}
