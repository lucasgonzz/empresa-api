<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Models\Address;
use App\Models\User;
use App\Notifications\DeletedModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Elimina en segundo plano una sucursal con muchas filas de stock (misión
 * eliminar-sucursal-con-stock, 5/10/2026, decisión D14 del plan).
 *
 * Lo encola `EliminarSucursalHelper::eliminar()` cuando la sucursal tiene más de
 * `EliminarSucursalHelper::FILAS_EN_LINEA` filas con stock: moverlas por el motor de stock no entra
 * en el tiempo de un request. El trabajo es el MISMO que el camino en línea
 * (`EliminarSucursalHelper::ejecutar()`), con tres diferencias:
 *
 *  - No hay `Auth` en la cola: el dueño y el usuario que firma los movimientos viajan explícitos
 *    (`StockMovementController::crear($data, false, $owner, $auth_user_id)`).
 *  - Antes de empezar se vuelve a validar: entre el clic y el worker (en el shared hosting, hasta un
 *    minuto o más) pudo aparecer un traslado pendiente, borrarse el destino elegido, etc.
 *  - Deja el registro visible (`BackgroundProcessHelper`): nace `pendiente` en el request, pasa a
 *    `en_proceso` acá, y se cierra `completado` o `fallo` con el motivo.
 *
 * 🔴 El candado de la sucursal (D13) lo tomó el request y lo libera ESTE job, pase lo que pase:
 * en el `finally` de `handle()` y en `failed()` (que corre en un proceso fresco si el worker murió
 * por OOM o timeout). Si nadie lo liberara, la sucursal quedaría "ya se está eliminando" hasta que
 * venza el candado (2 horas).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class EliminarSucursalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Una sucursal con miles de artículos son miles de movimientos de stock.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Un solo intento: el trabajo es idempotente (volver a apretar Eliminar continúa donde quedó),
     * pero un reintento automático correría sin que el usuario sepa por qué la primera falló.
     *
     * @var int
     */
    public $tries = 1;

    /** @var int */
    protected $address_id;

    /** @var int Dueño del comercio. */
    protected $owner_id;

    /** @var int|null Usuario que pidió la eliminación (firma los movimientos). */
    protected $auth_user_id;

    /** @var array Decisión normalizada (stock_accion, stock_destino_id, usuarios_accion, ...). */
    protected $decision;

    /** @var int|null Registro visible abierto en `pendiente` por el request. */
    protected $background_process_id;

    /**
     * @param  int       $address_id
     * @param  int       $owner_id
     * @param  int|null  $auth_user_id
     * @param  array     $decision
     * @param  int|null  $background_process_id
     */
    public function __construct($address_id, $owner_id, $auth_user_id, $decision, $background_process_id = null)
    {
        $this->address_id            = (int) $address_id;
        $this->owner_id              = (int) $owner_id;
        $this->auth_user_id          = is_null($auth_user_id) ? null : (int) $auth_user_id;
        $this->decision              = EliminarSucursalHelper::normalizar_decision($decision);
        $this->background_process_id = is_null($background_process_id) ? null : (int) $background_process_id;
    }

    /**
     * @return void
     */
    public function handle()
    {
        try {

            $proceso = BackgroundProcessHelper::avanzar($this->background_process_id, 0, [
                'etapa'            => 'Moviendo el stock',
                'forzar_broadcast' => true,
            ]);

            $address = Address::find($this->address_id);

            // Ya no existe (otra corrida la terminó): el pedido está cumplido.
            if (is_null($address)) {
                BackgroundProcessHelper::completar($this->background_process_id, ['ya_estaba_eliminada' => true]);
                return;
            }

            $error = EliminarSucursalHelper::validar($address, $this->owner_id, $this->decision);

            if (!is_null($error)) {
                BackgroundProcessHelper::fallar($this->background_process_id, $error['body']['message']);
                return;
            }

            $resultado = EliminarSucursalHelper::ejecutar(
                $this->address_id,
                $this->owner_id,
                $this->auth_user_id,
                $this->decision,
                is_null($proceso) ? $this->background_process_id : $proceso
            );

            if (!$resultado['ok']) {
                BackgroundProcessHelper::fallar($this->background_process_id, $resultado['mensaje']);
                return;
            }

            BackgroundProcessHelper::completar($this->background_process_id, [
                'articulos'   => (int) (isset($resultado['resumen']['articulos']) ? $resultado['resumen']['articulos'] : 0),
                'movimientos' => (int) (isset($resultado['resumen']['movimientos']) ? $resultado['resumen']['movimientos'] : 0),
                'usuarios'    => (int) (isset($resultado['resumen']['usuarios']) ? $resultado['resumen']['usuarios'] : 0),
            ], 'Sucursal eliminada');

            $this->avisar_a_la_spa();

        } catch (\Throwable $e) {

            Log::error('EliminarSucursalJob: falló la eliminación de la sucursal '.$this->address_id.': '.$e->getMessage(), [
                'owner_id' => $this->owner_id,
                'archivo'  => $e->getFile().':'.$e->getLine(),
            ]);

            BackgroundProcessHelper::fallar(
                $this->background_process_id,
                'No se pudo terminar de eliminar la sucursal. Volvé a intentarlo: continúa desde donde quedó. ('.$e->getMessage().')'
            );

        } finally {
            EliminarSucursalHelper::liberar_candado($this->address_id);
        }
    }

    /**
     * Le avisa a la SPA (todas las pestañas del comercio) que la sucursal ya no existe, como hace
     * `sendDeleteModelNotification()` en el camino en línea. `check_added_by` en false: en la cola no
     * hay "quién la agregó", y la pestaña que pidió la eliminación también tiene que sacarla de la
     * lista (la dejó a propósito mientras se procesaba). Un aviso perdido no voltea el job.
     *
     * @return void
     */
    protected function avisar_a_la_spa()
    {
        try {

            $owner = User::find($this->owner_id);

            if (!is_null($owner)) {
                $owner->notify(new DeletedModel('Address', $this->address_id, false, $this->owner_id));
            }

        } catch (\Throwable $e) {
            Log::warning('EliminarSucursalJob: no se pudo avisar a la SPA que se eliminó la sucursal '.$this->address_id.': '.$e->getMessage());
        }
    }

    /**
     * Lo que el catch de `handle()` no ve (un worker muerto por OOM o timeout): cierra el registro y
     * libera el candado. Idempotente: si `handle()` ya lo cerró, `fallar()` no lo pisa.
     *
     * @param  \Throwable|null  $e
     * @return void
     */
    public function failed($e)
    {
        $motivo = !is_null($e) ? $e->getMessage() : 'El proceso se interrumpió sin dejar traza.';

        BackgroundProcessHelper::fallar($this->background_process_id, 'No se pudo terminar de eliminar la sucursal. Volvé a intentarlo: continúa desde donde quedó. ('.$motivo.')');

        EliminarSucursalHelper::liberar_candado($this->address_id);
    }
}
