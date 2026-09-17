<?php

namespace App\Jobs;

use App\Models\MostradorReporte;
use App\Models\User;
use App\Services\Mostrador\RecolectorDeHechos;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Calcula en la cola los hechos de un informe del mostrador (misión modulo-ia-mostrador)
 * cuando el catálogo es demasiado grande para hacerlo adentro del request de
 * POST admin-sync/mostrador/hechos (config mostrador.umbral_async): compras y stock
 * recorren el catálogo con los motores de sugerencias, y en un comercio grande son
 * minutos —el cron viejo (sugerencias:generar, compras:generar) los corría en jobs por
 * eso mismo.
 *
 * El contrato con el controlador es la fila de mostrador_reportes:
 *   - el controlador la deja en estado 'calculando' y despacha este job con su id;
 *   - el job recarga la fila y SALE SIN HACER NADA si no está en 'calculando' (ya la
 *     cerró otra corrida, o la skill la depositó): es lo que lo vuelve idempotente
 *     ante un despacho repetido o un reintento tardío;
 *   - al terminar deja los hechos, hechos_at y el estado 'listo' si la fila ya tenía
 *     contenido (recálculo forzado sobre un informe visible) o 'hechos' si no;
 *   - si revienta (excepción o timeout del worker), la fila queda en 'error' con el
 *     motivo en error_mensaje, para que el polling de la skill no espere para siempre.
 *
 * Cola por defecto; timeout de config mostrador.timeout_job (1800 s); un solo intento:
 * un cálculo que tardó media hora no se repite solo, se vuelve a pedir.
 */
class CalcularHechosMostradorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Un solo intento: el fallo se informa en la fila, no se reintenta */
    public $tries = 1;

    /** @var int Segundos antes de que el worker mate el job (config mostrador.timeout_job) */
    public $timeout = 1800;

    /** @var int Id de la fila de mostrador_reportes */
    protected $reporte_id;

    /**
     * @param int $reporte_id
     */
    public function __construct($reporte_id)
    {
        $this->reporte_id = (int) $reporte_id;
        $this->timeout = (int) config('mostrador.timeout_job', 1800);
    }

    /**
     * Calcula los hechos y cierra la fila.
     *
     * @return void
     */
    public function handle()
    {
        $reporte = MostradorReporte::find($this->reporte_id);

        if (is_null($reporte) || !$reporte->esta_calculando()) {
            return;
        }

        try {
            $owner = User::whereNull('owner_id')->find($reporte->user_id);

            if (is_null($owner)) {
                throw new \RuntimeException('El dueño del informe (user ' . $reporte->user_id . ') ya no existe.');
            }

            $hechos = (new RecolectorDeHechos())->recolectar($owner, $reporte->tipo, $reporte->fecha->copy());
        } catch (\Throwable $e) {
            $this->marcar_error($reporte, $e);

            return;
        }

        // Se vuelve a leer por si la skill depositó el contenido mientras se calculaba: el
        // estado final respeta lo que haya en la fila ahora, no lo que había al arrancar.
        $reporte->refresh();

        $reporte->hechos = $hechos;
        $reporte->hechos_at = now();
        $reporte->error_mensaje = null;
        $reporte->estado = is_null($reporte->contenido)
            ? MostradorReporte::ESTADO_HECHOS
            : MostradorReporte::ESTADO_LISTO;
        $reporte->save();
    }

    /**
     * El worker agotó el intento (excepción no atrapada o timeout): la fila queda en
     * 'error' con el motivo.
     *
     * @param \Throwable $e
     * @return void
     */
    public function failed(\Throwable $e)
    {
        $reporte = MostradorReporte::find($this->reporte_id);

        if (is_null($reporte) || !$reporte->esta_calculando()) {
            return;
        }

        $this->marcar_error($reporte, $e);
    }

    /**
     * Deja la fila en 'error' con el mensaje (recortado al ancho de la columna) y lo loguea.
     *
     * @param MostradorReporte $reporte
     * @param \Throwable $e
     * @return void
     */
    protected function marcar_error(MostradorReporte $reporte, \Throwable $e)
    {
        Log::error('CalcularHechosMostradorJob: falló el cálculo de hechos', [
            'reporte_id' => $reporte->id,
            'user_id'    => $reporte->user_id,
            'tipo'       => $reporte->tipo,
            'fecha'      => $reporte->fecha->format('Y-m-d'),
            'message'    => $e->getMessage(),
        ]);

        $reporte->estado = MostradorReporte::ESTADO_ERROR;
        $reporte->error_mensaje = mb_substr((string) $e->getMessage(), 0, 300);
        $reporte->save();
    }
}
