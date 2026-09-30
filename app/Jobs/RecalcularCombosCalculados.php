<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula en segundo plano los combos calculados de un dueño (misión combos-calculados, F5).
 *
 * POR QUÉ EXISTE. Recalcular un combo cuesta ~8-10 consultas, y hacerlo dentro de un request
 * (guardar un artículo, editar o borrar una lista de precios) o al cerrar una corrida masiva
 * multiplica eso por la cantidad de combos. Con pocos combos es inocuo y se hace en línea; con
 * muchos, o al cerrar una corrida, se encola este job: así el request responde rápido y, sobre todo,
 * un timeout del recálculo de combos ya no puede dejar abierta la corrida de precios que lo disparó
 * (`FinalizeSetFinalPrices` cierra la corrida ANTES de encolar esto).
 *
 * Es liviano a propósito: no tiene lógica propia, llama al helper. Dos formas:
 *  - `$combo_ids = null`: TODOS los combos calculados del dueño (los cierres de corrida, donde ya no
 *    se sabe qué artículos cambiaron).
 *  - `$combo_ids = [..]`: solo esos combos (los disparadores por artículo que encontraron más de
 *    `ComboCalculadoHelper::MAXIMO_EN_LINEA`).
 *
 * Idempotente (recalcular dos veces lo mismo no escribe la segunda), no reintenta (`$tries = 1`: una
 * falla queda en el log por combo y la red de seguridad diaria `combos:recalcular` la corrige) y
 * nunca propaga la falla de un combo a los demás.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RecalcularCombosCalculados implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 1;

    /** @var int */
    protected $user_id;

    /** @var array|null */
    protected $combo_ids;

    /**
     * @param  int         $user_id    Dueño (o un empleado: el helper lo resuelve al dueño).
     * @param  array|null  $combo_ids  Ids de combos a recalcular, o null para todos los del dueño.
     */
    public function __construct($user_id, $combo_ids = null)
    {
        $this->user_id   = $user_id;
        $this->combo_ids = is_null($combo_ids) ? null : array_values($combo_ids);

        /*
         * Misma cola que el finalizador de precios que lo encola en la mayoría de los casos: en el
         * shared hosting 'excel' (su worker corre con más memoria), en el VPS la 'default', que es
         * la única que consume el supervisor de cada cliente.
         */
        $this->queue = config('app.VPS') ? null : 'excel';
    }

    public function handle()
    {
        if (is_null($this->combo_ids)) {
            ComboCalculadoHelper::recalcular_de_un_dueno($this->user_id);
            return;
        }

        ComboCalculadoHelper::recalcular_ids($this->combo_ids);
    }

    /**
     * @param  \Throwable  $e
     * @return void
     */
    public function failed($e)
    {
        Log::warning('RecalcularCombosCalculados: falló el recálculo de combos en segundo plano (lo corrige la red de seguridad diaria)', [
            'user_id' => $this->user_id,
            'motivo'  => $e->getMessage(),
        ]);
    }
}
