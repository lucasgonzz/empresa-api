<?php

namespace App\Http\Controllers;

use App\Models\PrintAgent;
use App\Models\PrintJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lo que consume el SPA sobre los agentes de impresion.
 *
 * Los equipos se resuelven con Auth::user() y no con $this->userId(), por el mismo motivo que
 * set_impresora: la impresion es por PUESTO. Un empleado vincula la maquina de su caja sin
 * depender del dueño, y no le pisa nada al dueño.
 *
 * El dueño, ademas, ve todos los equipos de su comercio: es el que atiende el telefono cuando
 * alguien dice que no le imprime, y necesita ver si esa caja esta en linea.
 */
class PrintAgentController extends Controller
{
    /**
     * Minutos que vive un codigo de vinculacion sin usar.
     */
    const MINUTOS_DE_VIDA_DEL_CODIGO = 30;

    /**
     * Genera el codigo que el operador pega en el agente recien instalado.
     *
     * El codigo lleva adentro la URL de esta API, para que el cliente no tenga que tipear ninguna
     * direccion: copia, pega, y el agente ya sabe con quien hablar.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function codigo(Request $request) {
        $user = Auth::user();

        $codigo = bin2hex(random_bytes(16));

        /*
         * Se crea una fila por vinculacion pendiente, no una por usuario: alguien con tres cajas
         * genera tres codigos y termina con tres equipos. Las pendientes que nadie canjea quedan
         * con el codigo vencido y se limpian solas en la proxima generacion.
         */
        PrintAgent::where('user_id', $user->id)
            ->whereNull('token_hash')
            ->where('link_code_expira_at', '<', Carbon::now())
            ->delete();

        $print_agent = PrintAgent::create([
            'user_id'             => $user->id,
            'owner_id'            => $user->owner_id ? $user->owner_id : $user->id,
            'link_code_hash'      => hash('sha256', $codigo),
            'link_code_expira_at' => Carbon::now()->addMinutes(self::MINUTOS_DE_VIDA_DEL_CODIGO),
        ]);

        /*
         * Formato del codigo pegable: CC1.<base64url del json>. El prefijo esta para que el agente
         * pueda decir "esto no es un codigo de ComercioCity" en vez de fallar con un error de
         * parseo cuando alguien pega cualquier otra cosa.
         */
        $payload = json_encode([
            'u' => $request->getSchemeAndHttpHost(),
            'c' => $codigo,
        ]);

        $codigo_pegable = 'CC1.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return response()->json([
            'codigo'     => $codigo_pegable,
            'expira_at'  => $print_agent->link_code_expira_at->toDateTimeString(),
            'expira_en_minutos' => self::MINUTOS_DE_VIDA_DEL_CODIGO,
        ], 200);
    }

    /**
     * Equipos vinculados que el usuario puede usar para imprimir.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    function index() {
        $user = Auth::user();

        $owner_id = $user->owner_id ? $user->owner_id : $user->id;

        $models = PrintAgent::where('owner_id', $owner_id)
            ->whereNotNull('token_hash')
            ->orderBy('nombre_equipo')
            ->get();

        $respuesta = [];

        foreach ($models as $model) {
            $respuesta[] = $model->toSpaArray();
        }

        return response()->json(['models' => $respuesta], 200);
    }

    /**
     * Desvincula un equipo.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    function destroy($id) {
        $user = Auth::user();

        $owner_id = $user->owner_id ? $user->owner_id : $user->id;

        $print_agent = PrintAgent::where('id', $id)->where('owner_id', $owner_id)->first();

        if (is_null($print_agent)) {
            return response()->json(['error' => 'not found'], 404);
        }

        /*
         * Los trabajos se van con el equipo. No hay foreign key, asi que sin esto quedarian filas
         * apuntando a un print_agent_id inexistente: show_job joinea contra print_agents, o sea que
         * nadie las puede ver ni borrar nunca mas, y el payload de cada una se queda en la base.
         */
        PrintJob::where('print_agent_id', $print_agent->id)->delete();

        $print_agent->delete();

        return response()->json(['ok' => true], 200);
    }

    /**
     * Encola un ticket para que lo imprima un equipo.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function store_job(Request $request) {
        $request->validate([
            'print_agent_id' => 'required|integer',
            'printer_name'   => 'required|string|max:255',
            'payload_base64' => 'required|string',
        ]);

        $user = Auth::user();

        $owner_id = $user->owner_id ? $user->owner_id : $user->id;

        /*
         * El equipo tiene que ser del mismo comercio. Sin este filtro, mandar un numero cualquiera
         * imprimiria un ticket en la caja de otro cliente.
         */
        $print_agent = PrintAgent::where('id', $request->print_agent_id)
            ->where('owner_id', $owner_id)
            ->whereNotNull('token_hash')
            ->first();

        if (is_null($print_agent)) {
            return response()->json(['error' => 'El equipo no existe o no esta vinculado'], 404);
        }

        if (! $print_agent->en_linea) {
            return response()->json([
                'error' => 'El equipo "' . $print_agent->nombre_equipo . '" no esta conectado',
            ], 409);
        }

        /*
         * La impresora tiene que ser una de las que ESE equipo reporto. Es defensa en profundidad:
         * el nombre viaja hasta un programa que lo usa como nombre de impresora de Windows, y sin
         * este filtro un empleado autenticado puede mandar cualquier string.
         *
         * Si el equipo todavia no reporto ninguna (recien vinculado), no se bloquea: seria dejarlo
         * inutilizable hasta el primer heartbeat.
         */
        $impresoras = $print_agent->impresoras_array;

        if (count($impresoras) && ! in_array($request->printer_name, $impresoras)) {
            return response()->json([
                'error' => 'La impresora "' . $request->printer_name . '" ya no esta en ese equipo',
            ], 422);
        }

        $this->purgar_trabajos_viejos($print_agent);

        $job = PrintJob::create([
            'print_agent_id' => $print_agent->id,
            'user_id'        => $user->id,
            'printer_name'   => $request->printer_name,
            'payload_base64' => $request->payload_base64,
            'status'         => PrintJob::STATUS_PENDIENTE,
        ]);

        return response()->json(['model' => $job], 201);
    }

    /**
     * Estado de un trabajo, para que el SPA pueda avisar si la impresion fallo.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    function show_job($id) {
        $user = Auth::user();

        $owner_id = $user->owner_id ? $user->owner_id : $user->id;

        $job = PrintJob::where('print_jobs.id', $id)
            ->join('print_agents', 'print_agents.id', '=', 'print_jobs.print_agent_id')
            ->where('print_agents.owner_id', $owner_id)
            ->select('print_jobs.*')
            ->first();

        if (is_null($job)) {
            return response()->json(['error' => 'not found'], 404);
        }

        return response()->json(['model' => $job], 200);
    }

    /**
     * Borra los trabajos terminados con mas de una semana.
     *
     * Se hace al encolar y no con una tarea programada porque no hay scheduler garantizado en el
     * hosting de cada cliente. Sin esto la tabla crece para siempre: un comercio de 200 ventas por
     * dia deja cientos de miles de filas al año en una base compartida.
     *
     * @param  \App\Models\PrintAgent  $print_agent
     * @return void
     */
    private function purgar_trabajos_viejos($print_agent) {
        PrintJob::where('print_agent_id', $print_agent->id)
            ->whereIn('status', [PrintJob::STATUS_IMPRESO, PrintJob::STATUS_ERROR])
            ->where('created_at', '<', Carbon::now()->subDays(7))
            ->delete();
    }
}
