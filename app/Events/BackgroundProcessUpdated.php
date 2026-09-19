<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un proceso en segundo plano cambió (arrancó, avanzó, terminó o falló).
 *
 * Lo emite `BackgroundProcessHelper` —con throttle— y lo escucha
 * `common-vue/mixins/broadcast.js` de empresa-spa, que lo guarda en el store
 * `background_processes` para la píldora de arriba a la derecha y el modal de procesos.
 *
 * `ShouldBroadcastNow` y no `ShouldBroadcast`: esto se emite desde adentro de un job, y un
 * evento encolado esperaría al próximo worker (hasta 75 minutos en el shared hosting, ver
 * `InstantBroadcastChannel`). Un aviso de progreso que llega después de que el proceso terminó
 * no es un aviso.
 *
 * Recibe el payload ya armado (un array de escalares) y no el modelo a propósito: sin
 * `SerializesModels` no hay re-consulta al broadcastear, y el tamaño queda acotado —el helper lo
 * recorta para no acercarse a los 10.240 bytes que permite Pusher (la lección del informe del
 * 25/8/2026 sobre `ArticleBatchImagesProcessed`).
 */
class BackgroundProcessUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @var array */
    public $proceso;

    /** @var int */
    public $owner_id;

    /**
     * @param array $proceso  Payload armado por BackgroundProcessHelper::payload().
     * @param int   $owner_id Dueño del comercio (canal).
     */
    public function __construct(array $proceso, $owner_id)
    {
        $this->proceso  = $proceso;
        $this->owner_id = (int) $owner_id;
    }

    /**
     * Canal público del comercio, mismo criterio que `import_status.{id}` y
     * `global_notification.{id}`: la SPA se suscribe con el owner_id apenas autentica.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn()
    {
        return new Channel('background_processes.' . $this->owner_id);
    }

    /**
     * Nombre exacto que escucha la SPA: `.listen('.BackgroundProcessUpdated', ...)`.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'BackgroundProcessUpdated';
    }

    /**
     * Contrato con empresa-spa: `{ proceso: {...} }` con las claves de
     * BackgroundProcessHelper::payload().
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'proceso' => $this->proceso,
        ];
    }
}
