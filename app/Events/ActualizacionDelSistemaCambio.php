<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa a todas las computadoras conectadas de un dueño que cambió el estado de actualización del sistema
 * (misión aviso-actualizacion-sistema, 25/11/2026): se abrió un pedido de OK, alguien contestó, el pedido se
 * canceló o el sistema pasó a `actualizando` / `libre`.
 *
 * Contrato con empresa-spa (plan §4.3):
 *  - Canal PÚBLICO `actualizacion_del_sistema.{owner_id}` (el sufijo de espacio `@xxxx` lo agrega el
 *    broadcaster del provider, igual que a `global_notification.{owner_id}`; ningún `broadcastOn()` cambia).
 *  - Evento `.ActualizacionDelSistemaCambio`.
 *  - Payload `{estado, total, aceptaron, faltan, vence_respuesta_at, rechazado_por}`.
 *
 * `ShouldBroadcastNow`: sale en el mismo request, sin cola (el cliente no tiene workers garantizados). Quien
 * lo emite (`ActualizacionDelSistemaHelper::emitir()`) lo envuelve en try/catch: si Pusher falla, el sistema
 * sigue funcionando con el polling de 60 segundos del SPA.
 */
class ActualizacionDelSistemaCambio implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /** @var int Dueño del comercio (id de `users` con owner_id NULL). */
    public $owner_id;

    /**
     * Payload ya armado para el SPA.
     *
     * @var array<string, mixed>
     */
    public $payload;

    /**
     * @param int                  $owner_id Dueño del comercio.
     * @param array<string, mixed> $payload  Claves `estado`, `total`, `aceptaron`, `faltan`,
     *                                       `vence_respuesta_at` y `rechazado_por`.
     */
    public function __construct($owner_id, array $payload)
    {
        $this->owner_id = (int) $owner_id;
        $this->payload = $payload;
    }

    /**
     * Canal público por dueño: lo escuchan todas las pestañas del comercio, sin autorización (no viaja
     * nada sensible: sólo el estado y los contadores).
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn()
    {
        return [
            new Channel('actualizacion_del_sistema.' . $this->owner_id),
        ];
    }

    /**
     * Nombre del evento para `Echo.channel(...).listen('.ActualizacionDelSistemaCambio', ...)`: el de la clase.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'ActualizacionDelSistemaCambio';
    }

    /**
     * Payload para el cliente.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith()
    {
        return $this->payload;
    }
}
