<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\MostradorHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\MostradorAccesoHelper;
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

        /*
         * El cuerpo vive en MostradorHelper::asegurar_conversacion() desde la misión
         * asistente-por-whatsapp (16/9/2026): el informe que sale por WhatsApp manda el
         * `ai_conversation_id` de ESTE mismo hilo, para que la pregunta del dueño caiga en la
         * conversación del informe. Dos copias de este alta serían dos `contexto` de fondo que se
         * separan solos.
         */
        $resultado = MostradorHelper::asegurar_conversacion(
            $reporte,
            UserHelper::userId(true),
            UserHelper::userId(false)
        );

        return response()->json(['model' => $resultado['model']], $resultado['creada'] ? 201 : 200);
    }

    /**
     * GET informe-compartido/{token}  — PÚBLICA, fuera de auth:sanctum.
     *
     * El informe que el dueño abre desde el link que le llegó por WhatsApp (misión
     * asistente-por-whatsapp, §3.7 del plan). Sin usuario ni contraseña: el dueño está en la calle
     * con el teléfono en la mano, y esa es justamente la decisión de Lucas.
     *
     * 🔴 SOLO LECTURA Y SOLO ESTE INFORME. Devuelve título, resumen, contenido y fecha — nada más.
     * Ni los hechos crudos, ni la conversación, ni el escritorio, ni ninguna otra pantalla: el
     * token abre UN informe, no una sesión. Quien tenga el link ve lo que ese informe dice y se
     * acabó.
     *
     * 410 si el link venció (el dueño entiende "pedí otro"), 404 si nunca existió o si el informe
     * dejó de estar 'listo'. Los dos se distinguen a propósito: un 404 sobre un link vencido
     * mandaría al dueño a pensar que el informe se borró.
     *
     * @param string $token
     * @return JsonResponse
     */
    public function compartido($token): JsonResponse
    {
        $acceso = MostradorAccesoHelper::resolver($token);

        if (is_null($acceso)) {

            if (MostradorAccesoHelper::vencido($token)) {

                return response()->json([
                    'message' => 'Este link venció. Pedile al asistente que te mande el informe de nuevo.',
                ], 410);
            }

            return response()->json(['message' => 'Informe no encontrado.'], 404);
        }

        $reporte = MostradorReporte::where('id', $acceso->mostrador_reporte_id)
            ->where('user_id', $acceso->user_id)
            ->listos()
            ->first();

        if (is_null($reporte)) {
            return response()->json(['message' => 'Informe no encontrado.'], 404);
        }

        return response()->json([
            'model' => [
                'titulo'    => $reporte->titulo,
                'resumen'   => $reporte->resumen,
                'contenido' => $reporte->contenido,
                'fecha'     => $reporte->fecha->format('Y-m-d'),
                'tipo'      => (string) $reporte->tipo,
            ],
        ], 200);
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
