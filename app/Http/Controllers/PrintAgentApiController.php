<?php

namespace App\Http\Controllers;

use App\Models\PrintAgent;
use App\Models\PrintJob;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Lo que consume el AGENTE de impresion instalado en la PC del comercio.
 *
 * Todo lo de aca lo llama un programa, no una persona: no hay sesion de Sanctum sino el token que
 * el equipo recibio al vincularse, validado por el middleware print.agent.token. La unica
 * excepcion es vincular(), que justamente corre antes de que el equipo tenga token.
 *
 * El contrato es de sondeo: el agente pregunta cada dos segundos si hay algo para imprimir. No hay
 * conexion persistente ni websocket, a proposito -- un long-poll dejaria un worker de PHP tomado
 * durante toda la espera, y con varios puestos por cliente eso voltea el hosting compartido.
 */
class PrintAgentApiController extends Controller
{
    /**
     * Canjea el codigo de vinculacion por el token permanente del equipo.
     *
     * Es la unica ruta del agente sin token, porque es la que lo entrega. El codigo es de un solo
     * uso y vence: al canjearlo se borra de la fila, asi que reintentar con el mismo codigo da 404.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function vincular(Request $request) {
        $request->validate([
            'codigo'        => 'required|string|max:255',
            'nombre_equipo' => 'nullable|string|max:255',
            'impresoras'    => 'nullable|array',
            'impresoras.*'  => 'string|max:255',
        ]);

        $print_agent = PrintAgent::where('link_code_hash', hash('sha256', $request->codigo))
            ->whereNotNull('link_code_hash')
            ->first();

        if (is_null($print_agent)) {
            return response()->json(['error' => 'El codigo no es valido o ya se uso'], 404);
        }

        if (is_null($print_agent->link_code_expira_at) || $print_agent->link_code_expira_at->isPast()) {
            return response()->json(['error' => 'El codigo vencio. Generá uno nuevo desde el sistema'], 410);
        }

        /*
         * El token viaja UNA sola vez, en esta respuesta. La base guarda el hash, asi que si el
         * agente lo pierde no hay forma de recuperarlo: hay que volver a vincular el equipo.
         */
        $token = bin2hex(random_bytes(32));

        $print_agent->token_hash          = hash('sha256', $token);
        $print_agent->nombre_equipo       = $request->nombre_equipo;
        $print_agent->impresoras          = json_encode($request->impresoras ? $request->impresoras : []);
        $print_agent->link_code_hash      = null;
        $print_agent->link_code_expira_at = null;
        $print_agent->vinculado_at        = Carbon::now();
        $print_agent->last_seen_at        = Carbon::now();
        $print_agent->save();

        return response()->json([
            'token'         => $token,
            'nombre_equipo' => $print_agent->nombre_equipo,
        ], 200);
    }

    /**
     * Marca presencia y actualiza la lista de impresoras del equipo.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function heartbeat(Request $request) {
        $request->validate([
            'impresoras'   => 'nullable|array',
            'impresoras.*' => 'string|max:255',
        ]);

        $print_agent = $request->attributes->get('print_agent');

        $print_agent->last_seen_at = Carbon::now();

        /*
         * Solo se pisan las impresoras si el agente mando la lista. Un heartbeat sin `impresoras`
         * no deja al equipo sin ninguna: seria una forma silenciosa de romper el selector del SPA.
         */
        if ($request->has('impresoras')) {
            $print_agent->impresoras = json_encode($request->impresoras ? $request->impresoras : []);
        }

        $print_agent->save();

        return response()->json(['ok' => true], 200);
    }

    /**
     * Trabajos que este equipo tiene que imprimir.
     *
     * Los marca como "tomado" en el momento de entregarlos: si no, dos sondeos seguidos (o un
     * reintento despues de un timeout de red) se llevarian el mismo ticket y saldria dos veces.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function jobs(Request $request) {
        $print_agent = $request->attributes->get('print_agent');

        $print_agent->last_seen_at = Carbon::now();
        $print_agent->save();

        $jobs = PrintJob::where('print_agent_id', $print_agent->id)
            ->where('status', PrintJob::STATUS_PENDIENTE)
            ->orderBy('id')
            ->limit(10)
            ->get();

        $respuesta = [];

        foreach ($jobs as $job) {
            $job->status    = PrintJob::STATUS_TOMADO;
            $job->tomado_at = Carbon::now();
            $job->save();

            $respuesta[] = [
                'id'             => $job->id,
                'printer_name'   => $job->printer_name,
                'payload_base64' => $job->payload_base64,
            ];
        }

        return response()->json(['jobs' => $respuesta], 200);
    }

    /**
     * Como le fue al agente con un trabajo.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    function resultado(Request $request, $id) {
        $request->validate([
            'status' => 'required|string|in:impreso,error',
            'error'  => 'nullable|string|max:1000',
        ]);

        $print_agent = $request->attributes->get('print_agent');

        /*
         * Se filtra por print_agent_id ademas de por id: sin eso, un equipo podria cerrar los
         * trabajos de otro comercio con solo adivinar un numero.
         */
        $job = PrintJob::where('id', $id)
            ->where('print_agent_id', $print_agent->id)
            ->first();

        if (is_null($job)) {
            return response()->json(['error' => 'not found'], 404);
        }

        $job->status       = $request->status;
        $job->error        = $request->error;
        $job->terminado_at = Carbon::now();
        $job->save();

        return response()->json(['ok' => true], 200);
    }
}
