<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\BackgroundProcess;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Los procesos en segundo plano del comercio, para la píldora y el modal de la SPA
 * (misión procesos-en-segundo-plano, 18/9/2026).
 *
 * Todo scopeado por el DUEÑO (`$this->userId()`): un empleado ve los procesos de su comercio,
 * que es lo mismo que ve por el canal `background_processes.{owner_id}`.
 */
class BackgroundProcessController extends Controller
{
    /** Cuántos terminados recientes se devuelven como mucho. */
    const MAXIMO_DE_RECIENTES = 20;

    /** Horas hacia atrás que cuenta un terminado como "reciente". */
    const HORAS_DE_RECIENTES = 24;

    /**
     * Activos + terminados recientes (no vistos) del comercio.
     *
     * Es lo que la SPA pide al arrancar y cada vez que Echo reconecta: los eventos que se
     * emitieron con la pestaña desconectada no se reenvían, así que este endpoint es la fuente
     * que vuelve a poner en línea a la píldora.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $user_id = $this->userId();

        // Un job que murió sin pasar por failed() no puede quedar "en proceso" para siempre.
        BackgroundProcessHelper::cerrar_colgados($user_id);

        $activos = BackgroundProcess::where('user_id', $user_id)
            ->activos()
            ->orderBy('id', 'DESC')
            ->get();

        $recientes = BackgroundProcess::where('user_id', $user_id)
            ->terminados()
            ->whereNull('visto_at')
            ->where('finished_at', '>=', Carbon::now()->subHours(self::HORAS_DE_RECIENTES))
            ->orderBy('finished_at', 'DESC')
            ->limit(self::MAXIMO_DE_RECIENTES)
            ->get();

        return response()->json([
            'activos'   => $activos->map(function ($proceso) {
                return BackgroundProcessHelper::payload($proceso);
            })->values(),
            'recientes' => $recientes->map(function ($proceso) {
                return BackgroundProcessHelper::payload($proceso);
            })->values(),
            'broadcast' => $this->estado_del_broadcast(),
        ], 200);
    }

    /**
     * Si ESTE servidor puede avisar en tiempo real.
     *
     * El punto verde de la SPA mide el socket del navegador contra Pusher, y eso no alcanza:
     * una instancia con `BROADCAST_DRIVER=log` o sin `PUSHER_APP_KEY` tiene el socket
     * perfectamente conectado y no emite nunca nada. El usuario vería verde y no le llegaría un
     * solo aviso. Con esto la SPA puede poner el punto en rojo aunque el socket esté sano, con
     * el motivo de verdad.
     *
     * No revela credenciales: solo el nombre del driver y si la clave está cargada.
     *
     * @return array
     */
    protected function estado_del_broadcast()
    {
        $driver = (string) config('broadcasting.default');
        $clave = (string) config('broadcasting.connections.pusher.key');

        return [
            'driver'     => $driver,
            'habilitado' => $driver === 'pusher' && $clave !== '',
        ];
    }

    /**
     * Un proceso con el registro propio de su flujo cargado (para el detalle).
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $proceso = BackgroundProcess::where('user_id', $this->userId())->find($id);

        if (is_null($proceso)) {
            return response()->json(['message' => 'Proceso no encontrado'], 404);
        }

        return response()->json([
            'proceso'    => BackgroundProcessHelper::payload($proceso),
            'referencia' => BackgroundProcessHelper::referencia_para_detalle($proceso),
        ], 200);
    }

    /**
     * Marca un terminado como visto (el usuario lo cerró en el modal). Un proceso activo no se
     * puede cerrar: se lo va a seguir viendo hasta que termine.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function visto($id)
    {
        $proceso = BackgroundProcess::where('user_id', $this->userId())->find($id);

        if (is_null($proceso)) {
            return response()->json(['message' => 'Proceso no encontrado'], 404);
        }

        if ($proceso->esta_terminado() && is_null($proceso->visto_at)) {
            $proceso->visto_at = Carbon::now();
            $proceso->save();
        }

        return response()->json([
            'proceso' => BackgroundProcessHelper::payload($proceso),
        ], 200);
    }

    /**
     * Marca vistos todos los terminados del comercio (botón "Limpiar" del modal).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function vistos()
    {
        $cantidad = BackgroundProcess::where('user_id', $this->userId())
            ->terminados()
            ->whereNull('visto_at')
            ->update(['visto_at' => Carbon::now()]);

        return response()->json([
            'marcados' => (int) $cantidad,
        ], 200);
    }
}
