<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ArticleBatchImagesProcessed implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public $user_id;
    public $processed;
    public $skipped;
    public $skipped_names;
    public $needs_review;
    public $needs_review_items;

    /**
     * @param int   $user_id            ID del usuario dueño.
     * @param int   $processed          Cantidad de artículos con imagen asignada.
     * @param int   $skipped            Cantidad de artículos sin imagen asignada.
     * @param array $skipped_names      Nombres de los artículos sin imagen.
     * @param int   $needs_review       Cantidad de artículos con imagen de baja confianza.
     * @param array $needs_review_items Artículos con imagen para revisar (id, nombre, image_url).
     */
    public function __construct(
        int $user_id,
        int $processed,
        int $skipped,
        array $skipped_names,
        int $needs_review,
        array $needs_review_items
    ) {
        $this->user_id            = $user_id;
        $this->processed          = $processed;
        $this->skipped            = $skipped;
        $this->skipped_names      = $skipped_names;
        $this->needs_review       = $needs_review;
        $this->needs_review_items = $needs_review_items;
    }

    /**
     * Canal EXACTO al que se emite.
     */
    public function broadcastOn()
    {
        Log::info('Broadcast ArticleBatchImagesProcessed', [
            'channel' => 'article_batch_images.'.$this->user_id,
        ]);

        return new Channel('article_batch_images.'.$this->user_id);
    }

    /**
     * Nombre del evento (esto es CLAVE para Vue).
     */
    public function broadcastAs()
    {
        return 'ArticleBatchImagesProcessed';
    }

    /**
     * Payload que recibe el frontend.
     */
    public function broadcastWith()
    {
        return [
            'processed'          => $this->processed,
            'skipped'            => $this->skipped,
            'skipped_names'      => $this->skipped_names,
            'needs_review'       => $this->needs_review,
            'needs_review_items' => $this->needs_review_items,
        ];
    }
}
