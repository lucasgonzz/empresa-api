<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\AudioDelMensajeIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use App\Http\Controllers\Helpers\asistente_ia\TranscripcionDeAudioIaHelper;
use App\Models\IaImagenPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Las rutas propias de la app «ComercioCity IA» (misión app-ia-y-memoria, 29/10/2026, contrato C5).
 *
 * Van en el mismo gate que el chat del sistema (auth:sanctum + extensión agentes_ia + solo el dueño):
 * la app es ese mismo chat visto desde otro lado. Entran con el token `ia-app` que emitió
 * admin-sync/ia-app/token, y SoloRutasDeLaApp no les deja usar nada que no sea de la app.
 *
 * 🔴 La app nunca llama a `GET api/user`: ese endpoint corre el candado de sesión única. Todo lo que
 * necesita saber de quién es y de qué negocio está en `ia-app/yo`.
 */
class IaAppController extends Controller
{
    /**
     * GET api/ia-app/yo → quién es, de qué negocio, qué sabe hacer este sistema y en qué quedó su
     * cuestionario de inicio.
     *
     * De paso deja armada la memoria del negocio si todavía no existe (es idempotente y barato): la
     * app la va a leer apenas abra la configuración.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function yo(): JsonResponse
    {
        $persona = UserHelper::user(false);
        $dueno   = UserHelper::user(true);

        if (is_null($persona) || is_null($dueno)) {

            return response()->json([
                'code'    => 'sin_persona',
                'message' => 'No se pudo resolver la persona o el dueño de la cuenta.',
            ], 409, [], JSON_UNESCAPED_UNICODE);
        }

        IaAppHelper::asegurar_la_memoria($dueno);

        return response()->json([
            'usuario'      => IaAppHelper::usuario($persona),
            'negocio'      => IaAppHelper::negocio($dueno),
            'capacidades'  => IaAppHelper::capacidades(),
            'cuestionario' => IaAppHelper::cuestionario($dueno),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * DELETE api/ia-app/token → revoca el token con el que entró la app (cerrar sesión). 204.
     *
     * Solo borra un token de la app: si el pedido vino con la cookie del sistema o con la clave MCP,
     * no hay nada de la app que revocar y contesta 204 igual (cerrar sesión dos veces no es un error).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function revocar_token(Request $request)
    {
        $token = IaAppHelper::token_de_la_app($request);

        if (!is_null($token)) {
            $token->delete();
        }

        return response()->noContent();
    }

    /**
     * POST api/ia-app/transcribir (multipart `{audio}`) → `{texto}`: el dictado del cuestionario de
     * inicio. Mismos formatos y tope que el audio de un mensaje (AudioDelMensajeIaHelper) y la misma
     * transcripción (TranscripcionDeAudioIaHelper). No guarda nada.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse  200 {texto} · 422 audio_invalido · 503 sin_transcripcion
     */
    public function transcribir(Request $request): JsonResponse
    {
        $audio = $request->file('audio');

        $motivo = AudioDelMensajeIaHelper::motivo_de_rechazo($audio);

        if (!is_null($motivo)) {

            return response()->json(['code' => 'audio_invalido', 'message' => $motivo], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $resultado = null;

        if (TranscripcionDeAudioIaHelper::disponible()) {

            $resultado = TranscripcionDeAudioIaHelper::transcribir(
                (string) file_get_contents($audio->getRealPath()),
                'audio.' . AudioDelMensajeIaHelper::extension_de($audio),
                ['user_id' => UserHelper::userId(true), 'auth_user_id' => UserHelper::userId(false)]
            );
        }

        if (is_null($resultado)) {

            return response()->json([
                'code'    => 'sin_transcripcion',
                'message' => 'No pude pasar el audio a texto. Probá de nuevo o escribilo.',
            ], 503, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json(['texto' => $resultado['texto']], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET api/ia-imagenes/{uuid} → el binario de una imagen que generó el agente (misión
     * app-ia-y-memoria, contrato C5). Es la `url` del bloque `imagen_generada` cuando está `lista`.
     *
     * Tenencia por DUEÑO (el pedido es del negocio, no de la persona) y 404 —no 403— si es de otro o
     * todavía no llegó. Caché `private`, como las fotos del chat.
     *
     * @param  string  $uuid
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function imagen_generada($uuid)
    {
        $pedido = IaImagenPedido::where('uuid', (string) $uuid)
            ->where('user_id', (int) UserHelper::userId(true))
            ->where('estado', IaImagenPedido::ESTADO_LISTA)
            ->first();

        if (is_null($pedido) || empty($pedido->path) || !Storage::disk('local')->exists($pedido->path)) {

            return response()->json(['code' => 'imagen_inexistente', 'message' => 'Imagen no encontrada.'], 404);
        }

        $respuesta = response()->file(Storage::disk('local')->path($pedido->path));

        // setPrivate() y no un header en el array: ver el 🔴 de AiConversationController::imagen_de_mensaje().
        $respuesta->setPrivate();
        $respuesta->setMaxAge(300);

        return $respuesta;
    }
}
