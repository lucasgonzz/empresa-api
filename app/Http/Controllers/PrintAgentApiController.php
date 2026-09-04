<?php

namespace App\Http\Controllers;

use App\Models\PrintAgent;
use App\Models\PrintJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $print_agent->save();

        /*
         * Solo se pisan las impresoras si el agente mando la lista. Un heartbeat sin `impresoras`
         * no deja al equipo sin ninguna: seria una forma silenciosa de romper el selector del SPA.
         */
        if ($request->has('impresoras')) {
            $print_agent->impresoras = json_encode($request->impresoras ? $request->impresoras : []);
            $print_agent->save();
        }

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

        $this->marcar_presencia($print_agent);
        $this->rescatar_trabajos_colgados($print_agent);

        /*
         * 🔴 La lectura y el marcado van en UNA transaccion con lockForUpdate, no en un select
         * suelto seguido de updates.
         *
         * Sin el lock, dos sondeos solapados leen los mismos "pendiente" antes de que ninguno
         * alcance a marcarlos, y el ticket sale DOS VECES por la comandera. No es teorico: el
         * agente sondea cada 2 segundos y en hosting compartido un request puede tardar mas que
         * eso. Con el lock, el segundo sondeo espera al primero y despues ya los ve en "tomado".
         */
        $jobs = DB::transaction(function () use ($print_agent) {
            $models = PrintJob::where('print_agent_id', $print_agent->id)
                ->where('status', PrintJob::STATUS_PENDIENTE)
                ->orderBy('id')
                ->limit(10)
                ->lockForUpdate()
                ->get();

            foreach ($models as $job) {
                $job->status    = PrintJob::STATUS_TOMADO;
                $job->tomado_at = Carbon::now();
                $job->save();
            }

            return $models;
        });

        $respuesta = [];

        foreach ($jobs as $job) {
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

        /*
         * El ticket ya salio (o ya fallo): el payload no sirve mas y ocupa lugar. Vaciarlo al
         * cerrar el trabajo es lo que evita que la tabla se coma la base del cliente -- son varios
         * KB por cada venta de cada caja, todos los dias, en hosting compartido.
         */
        $job->payload_base64 = '';
        $job->save();

        return response()->json(['ok' => true], 200);
    }

    /**
     * Marca que el equipo sigue sondeando, sin escribir en cada request.
     *
     * El agente pregunta cada 2 segundos: guardar siempre serian ~40 UPDATE por segundo contra el
     * MySQL compartido con 40 comercios, todos para mover un timestamp. Con el umbral alcanza:
     * lo unico que se decide con este dato es si el equipo figura en linea, y el corte es de 30s.
     *
     * @param  \App\Models\PrintAgent  $print_agent
     * @return void
     */
    private function marcar_presencia($print_agent) {
        $ahora = Carbon::now();

        if (! is_null($print_agent->last_seen_at) && $print_agent->last_seen_at->diffInSeconds($ahora) < 10) {
            return;
        }

        $print_agent->last_seen_at = $ahora;
        $print_agent->save();
    }

    /**
     * Devuelve a la cola los trabajos que quedaron tomados y nunca se cerraron.
     *
     * 🔴 Sin esto se pierden en silencio, que es justo lo que este modulo vino a eliminar. Pasa de
     * dos formas: el agente levanta el ticket y se corta la luz antes de imprimirlo, o imprime bien
     * pero el aviso de vuelta no llega (timeout, 500). En los dos casos el trabajo queda en
     * "tomado" para siempre y nadie lo vuelve a entregar.
     *
     * El riesgo del rescate es reimprimir algo que ya salio, por eso la ventana es amplia: el
     * agente reintenta el aviso con backoff durante bastante menos que esto.
     *
     * @param  \App\Models\PrintAgent  $print_agent
     * @return void
     */
    private function rescatar_trabajos_colgados($print_agent) {
        PrintJob::where('print_agent_id', $print_agent->id)
            ->where('status', PrintJob::STATUS_TOMADO)
            ->where('tomado_at', '<', Carbon::now()->subMinutes(3))
            ->update([
                'status'    => PrintJob::STATUS_PENDIENTE,
                'tomado_at' => null,
            ]);
    }
}
