<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le lleva en vivo a la tienda online la respuesta que el comercio le escribió a mano a un
 * comprador desde el submódulo "Mensajes" (misión mensajes-tienda-online, 28/9/2026, contrato C2').
 *
 * 🔴 POR QUÉ UN CANAL NUEVO Y NO `message.from_commerce.{buyer_id}` (el contrato C2 original). Ese
 * canal es público, la app de Pusher es UNA para toda la flota y `buyers.id` se repite entre bases
 * (cada base numera desde 1): la respuesta del comercio A a su comprador #7 le llegaba en vivo a
 * CUALQUIER comprador #7 logueado en cualquier tienda. Los `owner_id` sí son únicos en la flota (el
 * admin los asigna de a 100), así que `tienda-respuestas.{owner_id}.{buyer_id}` no se cruza entre
 * comercios.
 *
 * Sigue siendo un canal PÚBLICO: hacerlo privado exige auth de compradores para Pusher en
 * tienda-api, que está fuera de esta misión. Lo que viaja es solo el mensaje que el comercio le
 * escribió a ese comprador.
 *
 * Contrato, letra por letra (tienda-spa lo escucha con
 * `Echo.channel('tienda-respuestas.'+owner+'.'+buyer.id).listen('.RespuestaDelComercio', cb)`):
 *
 * - Canal público `tienda-respuestas.{owner_id}.{buyer_id}`, `owner_id` = `buyers.user_id`.
 * - `broadcastAs()` = `'RespuestaDelComercio'`.
 * - Payload `{ message: {...} }`, con `message` EXACTAMENTE igual al de `TiendaChatActualizado`
 *   (recorte a 500 y `text_truncado`): lo arma `TiendaChatHelper::payload_de_la_respuesta()`.
 * - `ShouldBroadcastNow`, emitido después de la respuesta y siempre en try/catch (lo hace
 *   `TiendaChatHelper::emitir_respuesta_a_la_tienda()`).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RespuestaDelComercio implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @var int Dueño del comercio (`buyers.user_id`). */
    public $owner_id;

    /** @var int Comprador al que el comercio le respondió. */
    public $buyer_id;

    /** @var array Payload del contrato C2', ya armado con sus tipos. */
    public $payload;

    /**
     * @param  int  $owner_id
     * @param  int  $buyer_id
     * @param  array  $payload  Lo que devuelve `TiendaChatHelper::payload_de_la_respuesta()`.
     */
    public function __construct($owner_id, $buyer_id, array $payload)
    {
        $this->owner_id = (int) $owner_id;
        $this->buyer_id = (int) $buyer_id;
        $this->payload = $payload;
    }

    /**
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn()
    {
        return new Channel('tienda-respuestas.'.$this->owner_id.'.'.$this->buyer_id);
    }

    /**
     * @return string
     */
    public function broadcastAs()
    {
        return 'RespuestaDelComercio';
    }

    /**
     * @return array
     */
    public function broadcastWith()
    {
        return $this->payload;
    }
}
