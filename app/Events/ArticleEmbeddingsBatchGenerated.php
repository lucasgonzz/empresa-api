<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Evento que avisa al frontend que terminó una tanda de generación de embeddings y que
 * al menos un artículo quedó con vector nuevo. Lo emite FinalizeEmbeddingRun, una sola vez
 * por tanda (el candado es `embedding_runs.notificado_at`).
 *
 * Calcado de ArticleBatchDescriptionsProcessed, que es el evento de batch que el repo ya
 * tenía andando; misma forma, contadores propios de este flujo.
 *
 * 🔴 POR QUÉ ShouldBroadcastNow Y NO ShouldBroadcast.
 * Quien emite este evento ya es un job corriendo en el worker (FinalizeEmbeddingRun). Con
 * ShouldBroadcast, Laravel encolaría ADEMÁS un BroadcastEvent, o sea otro job más en la misma
 * cola. Y como `queue:work --stop-when-empty` se agenda cada minuto (Kernel.php:29), ese job
 * de broadcast lo levantaría recién el worker del minuto siguiente: un minuto entero de
 * retraso extra encima del que el finalizador ya arrastra por sus propios re-despachos con
 * delay. Con Now el push a Pusher sale en el mismo proceso, sin sumar un salto de cola.
 */
class ArticleEmbeddingsBatchGenerated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @var int
     */
    public $user_id;

    /**
     * @var int
     */
    public $generados;

    /**
     * @var string
     */
    public $origen;

    /**
     * @param int    $user_id   ID del usuario dueño de la instancia (el del canal).
     * @param int    $generados Artículos que quedaron con un vector NUEVO escrito.
     * @param string $origen    De dónde salió la tanda: scheduler | importacion | manual.
     */
    public function __construct($user_id, $generados, $origen)
    {
        $this->user_id   = $user_id;
        $this->generados = $generados;
        $this->origen    = $origen;
    }

    /**
     * Canal EXACTO al que se emite, uno por usuario.
     *
     * Canal PÚBLICO a propósito, igual que `article_batch_descriptions.{id}` e
     * `import_status.{id}`: por eso no hay que agregar nada en routes/channels.php. Lo que
     * viaja es un número, no datos del catálogo.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn()
    {
        Log::info('Broadcast ArticleEmbeddingsBatchGenerated', [
            'channel'   => 'article_embeddings.'.$this->user_id,
            'generados' => $this->generados,
            'origen'    => $this->origen,
        ]);

        return new Channel('article_embeddings.'.$this->user_id);
    }

    /**
     * Nombre del evento. Es lo que la SPA escucha con `.listen('.ArticleEmbeddingsBatchGenerated')`
     * (el punto adelante es lo que le dice a Echo que use el nombre de broadcastAs y no el FQCN).
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'ArticleEmbeddingsBatchGenerated';
    }

    /**
     * Payload que recibe el frontend.
     *
     * Solo `generados` y `origen`, y nada más a propósito: `salteados` y `fallados` quedan en
     * `embedding_runs` para diagnosticar desde la base, pero no viajan. Al dueño de la
     * ferretería no le sirve saber que 300 artículos se saltearon porque no habían cambiado —
     * lo único que le dice algo es cuántos quedaron actualizados.
     *
     * 🔴 Estas dos claves, el nombre del canal y el de broadcastAs son un CONTRATO ya consumido
     * por empresa-spa/src/mixins/broadcast.js. Si se renombra cualquiera de los cuatro, el toast
     * no aparece nunca y no salta ningún error en ninguno de los dos lados: Echo se suscribe
     * feliz a un canal por el que no habla nadie.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'generados' => (int) $this->generados,
            'origen'    => (string) $this->origen,
        ];
    }
}
