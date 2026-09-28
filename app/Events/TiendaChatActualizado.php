<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Avisa en vivo al submódulo "Mensajes" de Tienda Online (empresa-spa) que una conversación con
 * un comprador de la tienda cambió: entró un mensaje del comprador, el comercio respondió, o el
 * comercio la marcó como leída (misión mensajes-tienda-online, 28/9/2026). Es el espejo de
 * `WhatsappChatUpdated` para los mensajes de la tienda.
 *
 * 🔴 CONTRATO C1 DEL PLAN, LETRA POR LETRA. Del otro lado hay dos puntas programadas contra estos
 * nombres: `tienda-api` emite ESTE MISMO evento (misma clase, canal, `broadcastAs` y payload) cuando
 * el comprador escribe, y `empresa-spa` lo escucha con
 * `Echo.private('tienda-mensajes.'+owner_id).listen('.TiendaChatActualizado', cb)`. Un nombre mal
 * escrito no explota: el módulo queda mudo.
 *
 * - Canal privado `tienda-mensajes.{owner_id}` (en Pusher: `private-tienda-mensajes.{owner_id}`),
 *   autorizado en `routes/channels.php` al dueño y a sus empleados, igual que `whatsapp.{owner_id}`.
 * - `ShouldBroadcastNow`: sale en el momento, sin pasar por la cola (encolado llegaría tarde; ver el
 *   docblock de `InstantBroadcastChannel`).
 * - El payload NO se arma acá: lo arma `TiendaChatHelper::payload_del_evento()` a mano, con los
 *   tipos del contrato, y este evento lo transporta tal cual. Así la forma vive en un solo lugar y
 *   nunca depende de `$model->toArray()` (tras un `Message::create()` sin `from_buyer`, ese atributo
 *   ni siquiera existe en el modelo).
 * - Se emite SIEMPRE a través de `TiendaChatHelper::emitir()`, que lo envuelve en try/catch: una
 *   caída de Pusher nunca puede romper el guardado de un mensaje.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class TiendaChatActualizado implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @var int Id del DUEÑO del comercio (`buyers.user_id`); define el canal. */
    public $owner_id;

    /** @var array Payload del contrato C1, ya armado con sus tipos. */
    public $payload;

    /**
     * @param  int  $owner_id  Dueño del comercio (nunca el id de un empleado).
     * @param  array  $payload  Lo que devuelve `TiendaChatHelper::payload_del_evento()`.
     */
    public function __construct($owner_id, array $payload)
    {
        $this->owner_id = (int) $owner_id;
        $this->payload = $payload;
    }

    /**
     * Canal privado por dueño: solo el dueño y sus empleados pueden suscribirse.
     *
     * @return \Illuminate\Broadcasting\PrivateChannel
     */
    public function broadcastOn()
    {
        return new PrivateChannel('tienda-mensajes.'.$this->owner_id);
    }

    /**
     * Nombre corto del evento: la SPA lo escucha con `.listen('.TiendaChatActualizado', ...)`, con
     * el punto adelante.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'TiendaChatActualizado';
    }

    /**
     * El payload del contrato, tal cual se armó.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return $this->payload;
    }
}
