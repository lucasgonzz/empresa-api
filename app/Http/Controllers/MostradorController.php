<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\MostradorHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AiConversation;
use App\Models\MostradorReporte;
use Illuminate\Http\JsonResponse;

/**
 * El mostrador del módulo IA para el dueño (misión modulo-ia-mostrador): el
 * escritorio con los informes listos, un informe abierto y la conversación sobre él.
 *
 * Rutas en grupo gateado por Sanctum + `check_extencion_empresa:asistente_ia` (ver
 * routes/api.php). Encima de ese gate, SOLO EL DUEÑO (o alguien con admin_access) ve
 * el mostrador: los informes traen cobranzas, deudas y compras, y un empleado no
 * tiene por qué leerlos. La conversación de cada informe es una AiConversation con
 * origen 'mostrador_reporte' de la PERSONA (auth_user_id), y de ahí en más la SPA
 * habla con AiConversationController como con cualquier otra conversación.
 */
class MostradorController extends Controller
{
    /**
     * GET mostrador/reportes
     *
     * {"ultimos": [...], "anteriores": [...]} — solo informes 'listo' del dueño, sin
     * contenido ni hechos.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        if (!MostradorHelper::puede_ver()) {
            return $this->solo_el_dueno();
        }

        return response()->json(
            MostradorHelper::escritorio(UserHelper::userId(true), UserHelper::userId(false)),
            200
        );
    }

    /**
     * GET mostrador/reportes/{id}
     *
     * El informe con su contenido. La primera apertura POR EL DUEÑO estampa leido_at:
     * si lo abre alguien con admin_access (o el acceso maestro), el informe sigue
     * "sin leer" para el dueño y la skill lo sigue mencionando como no leído. 404 si no
     * es del dueño o no está 'listo'.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        if (!MostradorHelper::puede_ver()) {
            return $this->solo_el_dueno();
        }

        $reporte = $this->reporte_del_dueno($id);

        if (is_null($reporte)) {
            return response()->json(['message' => 'Informe no encontrado.'], 404);
        }

        if (is_null($reporte->leido_at) && MostradorHelper::es_el_dueno()) {
            $reporte->leido_at = now();
            $reporte->save();
        }

        $conversaciones = MostradorHelper::conversaciones_por_reporte([$reporte->id], UserHelper::userId(false));

        return response()->json([
            'model' => MostradorHelper::serializar($reporte, $conversaciones, true),
        ], 200);
    }

    /**
     * POST mostrador/reportes/{id}/conversacion
     *
     * Idempotente por (informe, persona): devuelve la conversación existente (200) o
     * crea una (201) con el informe como contexto de fondo. Misma forma que
     * POST ai-conversations: {"model": conversation}.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function conversacion($id): JsonResponse
    {
        if (!MostradorHelper::puede_ver()) {
            return $this->solo_el_dueno();
        }

        $reporte = $this->reporte_del_dueno($id);

        if (is_null($reporte)) {
            return response()->json(['message' => 'Informe no encontrado.'], 404);
        }

        $auth_user_id = UserHelper::userId(false);

        $conversation = MostradorHelper::conversacion_de($reporte, $auth_user_id);

        if ($conversation) {
            return response()->json(['model' => $conversation], 200);
        }

        $conversation = AiConversation::create([
            'user_id'         => UserHelper::userId(true),
            'auth_user_id'    => $auth_user_id,
            // Título fijo y no null: null significa "se está infiriendo" (la SPA muestra
            // "Nueva conversación") y acá la conversación nace con nombre propio.
            'titulo'          => MostradorHelper::titulo_de_conversacion($reporte),
            'origen'          => MostradorReporte::ORIGEN_CONVERSACION,
            'referencia_id'   => $reporte->id,
            'contexto'        => MostradorHelper::contexto_de_conversacion($reporte),
            'last_message_at' => now(),
        ]);

        // Dos pestañas que preguntan a la vez crean dos conversaciones (no hay unique
        // sobre origen + referencia_id + auth_user_id): después de crear se relee la
        // más vieja y, si no es la recién creada, la nuestra sobra —nace sin mensajes—
        // y gana la anterior, que es la que el escritorio ya resuelve.
        $anterior = MostradorHelper::conversacion_de($reporte, $auth_user_id);

        if ($anterior && (int) $anterior->id !== (int) $conversation->id) {
            $conversation->messages()->delete();
            $conversation->delete();

            return response()->json(['model' => $anterior], 200);
        }

        // Mismo refresh que AiConversationController@store: la SPA necesita la fila
        // completa, con los defaults de la base.
        $conversation->refresh();

        return response()->json(['model' => $conversation], 201);
    }

    /**
     * Un informe 'listo' del dueño de la cuenta, o null.
     *
     * @param int $id
     * @return MostradorReporte|null
     */
    protected function reporte_del_dueno($id)
    {
        return MostradorReporte::where('user_id', UserHelper::userId(true))
            ->listos()
            ->where('id', $id)
            ->first();
    }

    /**
     * El 403 de quien no es el dueño.
     *
     * @return JsonResponse
     */
    protected function solo_el_dueno(): JsonResponse
    {
        return response()->json(['message' => 'Solo el dueño puede ver el mostrador.'], 403);
    }
}
