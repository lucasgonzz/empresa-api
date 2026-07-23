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
    public $quota_reached;
    public $skipped_by_quota;
    public $skipped_by_quota_names;

    /** @var string UUID de la corrida del job, agrupa las filas de ArticleImageSearchAttempt (grupo 201, prompt 03). */
    public $batch_uuid;

    /**
     * @var array Un elemento por artículo sin imagen asignada, con
     * `['article_id' => ..., 'name' => ..., 'summary' => ...]`, donde `summary` junta en una
     * frase los `outcome_detail` de los intentos de búsqueda de ese artículo (grupo 201, prompt 03).
     */
    public $skipped_items;

    /**
     * @param int    $user_id                ID del usuario dueño.
     * @param int    $processed              Cantidad de artículos con imagen asignada.
     * @param int    $skipped                Cantidad de artículos procesados sin imagen asignada.
     * @param array  $skipped_names          Nombres de los artículos sin imagen.
     * @param int    $needs_review           Cantidad de artículos con imagen de baja confianza.
     * @param array  $needs_review_items     Artículos con imagen para revisar (id, nombre, image_url).
     * @param bool   $quota_reached          Si el procesamiento se cortó por alcanzar la cuota diaria.
     * @param int    $skipped_by_quota       Cantidad de artículos sin procesar por cuota agotada.
     * @param array  $skipped_by_quota_names Nombres de los artículos sin procesar por cuota agotada.
     * @param string $batch_uuid             UUID de la corrida (agrupa las filas de ArticleImageSearchAttempt).
     * @param array  $skipped_items          Detalle por artículo sin imagen (id, nombre, resumen del motivo).
     */
    public function __construct(
        int $user_id,
        int $processed,
        int $skipped,
        array $skipped_names,
        int $needs_review,
        array $needs_review_items,
        bool $quota_reached,
        int $skipped_by_quota,
        array $skipped_by_quota_names,
        string $batch_uuid = '',
        array $skipped_items = []
    ) {
        $this->user_id                = $user_id;
        $this->processed              = $processed;
        $this->skipped                = $skipped;
        $this->skipped_names          = $skipped_names;
        $this->needs_review           = $needs_review;
        $this->needs_review_items     = $needs_review_items;
        $this->quota_reached          = $quota_reached;
        $this->skipped_by_quota       = $skipped_by_quota;
        $this->skipped_by_quota_names = $skipped_by_quota_names;
        $this->batch_uuid             = $batch_uuid;
        $this->skipped_items          = $skipped_items;
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
            'processed'              => $this->processed,
            'skipped'                => $this->skipped,
            'skipped_names'          => $this->skipped_names,
            'needs_review'           => $this->needs_review,
            'needs_review_items'     => $this->needs_review_items,
            'quota_reached'          => $this->quota_reached,
            'skipped_by_quota'       => $this->skipped_by_quota,
            'skipped_by_quota_names' => $this->skipped_by_quota_names,
            'batch_uuid'             => $this->batch_uuid,
            'skipped_items'          => $this->skipped_items,
        ];
    }
}
