<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
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
     * @return JsonResponse  202 · 401 sin clave · 403 sin extensión · 404 sin dueño · 422 validación
     */
    public function mensajes(Request $request): JsonResponse
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $dueno = AsistenteCanalHelper::dueno();

        $texto = trim((string) $request->input('texto'));

        if ($texto === '') {

            return response()->json(['message' => 'El mensaje no puede venir vacío.'], 422);
        }

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

        $conversation = AsistenteCanalHelper::conversacion($dueno, $request->input('ai_conversation_id'));

        /*
         * Mismo criterio que AiConversationController::send_message(): el título se infiere solo
         * en el primer mensaje del dueño de una conversación sin título, y se decide ANTES de
         * crear los mensajes para no contarse a sí mismo.
         */
        $inferir_titulo = is_null($conversation->titulo)
            && !AiMessage::where('ai_conversation_id', $conversation->id)
                ->where('rol', 'user')
                ->exists();

        $whatsapp_message_id = trim((string) $request->input('whatsapp_message_id'));

        $user_message = AiMessage::create([
            'ai_conversation_id'  => $conversation->id,
            'rol'                 => 'user',
            'contenido'           => $texto,
            'estado'              => 'listo',
            'canal'               => AiMessage::CANAL_WHATSAPP,
            'whatsapp_message_id' => $whatsapp_message_id === '' ? null : mb_substr($whatsapp_message_id, 0, 128),
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

        if ($inferir_titulo) {

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

        return response()->json([
            'ai_conversation_id' => (int) $conversation->id,
            'ai_message_id'      => (int) $assistant_message->id,
            'estado'             => 'pendiente',
        ], 202);
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
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse  200 · 401 · 403 · 404
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
        ], 200);
    }

    /**
     * La clave y el gate, en un solo lugar: devuelve la respuesta de rechazo o null si puede pasar.
     *
     * El orden importa. Primero la CLAVE (401): sin ella no se le contesta nada a nadie, ni
     * siquiera si existe el dueño. Después el dueño (404) y por último la extensión (403), para
     * que el admin pueda distinguir "este cliente no tiene el módulo" de "no pude resolver a quién
     * le estás hablando" y avisar distinto.
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

            return response()->json(['message' => 'No se pudo resolver el dueño de esta instancia.'], 404);
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
