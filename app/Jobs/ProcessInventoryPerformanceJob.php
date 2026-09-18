<?php

namespace App\Jobs;

use App\Events\InventoryPerformanceGenerated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessInventoryPerformanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout amplio: las cuentas de 400k artículos tardan minutos en procesarse.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * No reintentar: un reintento es otra pasada completa por el catálogo.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Id del usuario owner para el que se genera el reporte de inventario.
     *
     * @var int
     */
    protected $user_id;

    /**
     * Quién pidió el reporte (misión procesos-en-segundo-plano, 18/9/2026). Con valor, el
     * reporte se registra como proceso visible en la píldora de ese usuario; en null lo
     * disparó el scheduler (`inventario:generar`) y no se registra nada, porque un reporte
     * que corre solo a las 04:00 no es algo que el dueño esté esperando ver.
     *
     * Con default null: un job encolado ANTES de este deploy se deserializa sin la property y
     * PHP la deja en null, o sea "no registrar", que es el comportamiento anterior.
     *
     * @var int|null
     */
    protected $auth_user_id = null;

    /**
     * Crea el job de generación del reporte de inventario en segundo plano.
     *
     * @param int      $user_id
     * @param int|null $auth_user_id  Usuario que apretó el botón; null = scheduler.
     */
    public function __construct($user_id, $auth_user_id = null)
    {
        $this->user_id = (int) $user_id;
        $this->auth_user_id = is_null($auth_user_id) ? null : (int) $auth_user_id;
    }

    /**
     * Genera el reporte de inventario y avisa al front por broadcast cuando termina.
     * Libera siempre el candado de generación, haya salido bien o mal.
     *
     * @return void
     */
    public function handle()
    {
        /*
         * Registro visible sólo cuando lo pidió alguien. Sin total: el helper recorre el
         * catálogo por chunks sin exponer avance, así que la barra es indeterminada. El proceso
         * queda en esta variable para el catch; failed() lo busca por tipo y dueño.
         */
        $proceso = null;

        if (!is_null($this->auth_user_id)) {
            $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'reporte_inventario', 'Reporte de inventario', [
                'auth_user_id' => $this->auth_user_id,
                'etapa'        => 'Recorriendo el catálogo',
            ]);
        }

        try {

            // El helper recibe el user_id explícito porque acá no hay sesión HTTP ni Auth.
            $helper = new InventoryPerformanceHelper($this->user_id);

            $inventory_performance = $helper->create();

            // Si se generó un reporte, se avisa al front con la cantidad de artículos bajo el mínimo.
            if (!is_null($inventory_performance)) {

                event(new InventoryPerformanceGenerated($this->user_id, (int) $inventory_performance->stock_minimo));
            }

            BackgroundProcessHelper::completar($proceso, [
                'stock_minimo' => is_null($inventory_performance) ? null : (int) $inventory_performance->stock_minimo,
            ], is_null($inventory_performance) ? 'Sin artículos para reportar' : 'Terminado');
        } catch (Exception $e) {

            Log::error('ProcessInventoryPerformanceJob: error al generar reporte de inventario', [
                'user_id' => $this->user_id,
                'message' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine(),
            ]);

            BackgroundProcessHelper::fallar($proceso, $e->getMessage());
        } finally {

            // Se libera el candado siempre, para que un futuro request pueda volver a encolar.
            Cache::forget('inventory_performance_generating_'.$this->user_id);
        }
    }

    /**
     * Cubre el caso de timeout o kill del worker, donde el finally del handle() puede no correr:
     * libera igual el candado de generación, y cierra en fallo el registro visible si lo había.
     *
     * @param Exception $e
     * @return void
     */
    /*
     * \Throwable y no Exception: el worker de Laravel llama a failed() con lo que haya
     * volteado al job, y un TypeError o un Error de memoria no es una Exception. Con el tipo
     * viejo, justo en esos casos PHP tiraba un TypeError ANTES de entrar acá, y el candado
     * de cache y el registro visible quedaban colgados.
     */
    public function failed(\Throwable $e)
    {
        Cache::forget('inventory_performance_generating_'.$this->user_id);

        /*
         * Sólo si alguien lo pidió: el scheduler no registra nada, así que no hay fila que
         * cerrar. Esta instancia es la deserializada del payload, sin lo que handle() tenía en
         * memoria: se busca el reporte abierto más nuevo del comercio. Idempotente: si el catch
         * de handle() ya lo cerró, activos() no lo devuelve.
         */
        if (is_null($this->auth_user_id)) {
            return;
        }

        $proceso = BackgroundProcessHelper::ultimo_activo($this->user_id, 'reporte_inventario');

        BackgroundProcessHelper::fallar(
            $proceso,
            $e->getMessage() !== '' ? $e->getMessage() : 'El proceso se interrumpió sin dejar traza (probable timeout o worker reiniciado).'
        );
    }
}
