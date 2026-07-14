<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Evento Pusher que notifica al frontend el resultado del batch de generacion de
 * descripciones inteligentes (ProcessArticleBatchDescriptionsJob). Misma estructura que
 * ArticleBatchImagesProcessed, con contadores propios de este flujo.
 */
class ArticleBatchDescriptionsProcessed implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public $user_id;
    public $processed;
    public $skipped;
    public $skipped_names;
    public $skipped_existing;
    public $skipped_existing_names;
    public $needs_review;
    public $needs_review_items;
    public $quota_reached;
    public $skipped_by_quota;
    public $skipped_by_quota_names;

    /**
     * @param int   $user_id                  ID del usuario dueño.
     * @param int   $processed                Artículos con descripción generada y ya publicable (confianza high/medium).
     * @param int   $skipped                  Artículos intentados sin poder generar nada (sin evidencia o found=false).
     * @param array $skipped_names            Nombres de los artículos sin descripción generada.
     * @param int   $skipped_existing         Artículos salteados por ya tener descripciones cargadas a mano.
     * @param array $skipped_existing_names   Nombres de los artículos salteados por descripciones humanas existentes.
     * @param int   $needs_review             Artículos con descripción de confianza low, guardada pero no publicada.
     * @param array $needs_review_items       Detalle de los artículos pendientes de revisión (id, nombre, ids de description, preview).
     * @param bool  $quota_reached            Si el procesamiento se cortó por alcanzar la cuota diaria de Google.
     * @param int   $skipped_by_quota         Artículos sin procesar por cuota agotada.
     * @param array $skipped_by_quota_names   Nombres de los artículos sin procesar por cuota agotada.
     */
    public function __construct(
        $user_id,
        $processed,
        $skipped,
        array $skipped_names,
        $skipped_existing,
        array $skipped_existing_names,
        $needs_review,
        array $needs_review_items,
        $quota_reached,
        $skipped_by_quota,
        array $skipped_by_quota_names
    ) {
        $this->user_id                = $user_id;
        $this->processed              = $processed;
        $this->skipped                = $skipped;
        $this->skipped_names          = $skipped_names;
        $this->skipped_existing       = $skipped_existing;
        $this->skipped_existing_names = $skipped_existing_names;
        $this->needs_review           = $needs_review;
        $this->needs_review_items     = $needs_review_items;
        $this->quota_reached          = $quota_reached;
        $this->skipped_by_quota       = $skipped_by_quota;
        $this->skipped_by_quota_names = $skipped_by_quota_names;
    }

    /**
     * Canal EXACTO al que se emite (uno por usuario, igual que el de imágenes).
     */
    public function broadcastOn()
    {
        Log::info('Broadcast ArticleBatchDescriptionsProcessed', [
            'channel' => 'article_batch_descriptions.'.$this->user_id,
        ]);

        return new Channel('article_batch_descriptions.'.$this->user_id);
    }

    /**
     * Nombre del evento (esto es CLAVE para Vue).
     */
    public function broadcastAs()
    {
        return 'ArticleBatchDescriptionsProcessed';
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
            'skipped_existing'       => $this->skipped_existing,
            'skipped_existing_names' => $this->skipped_existing_names,
            'needs_review'           => $this->needs_review,
            'needs_review_items'     => $this->needs_review_items,
            'quota_reached'          => $this->quota_reached,
            'skipped_by_quota'       => $this->skipped_by_quota,
            'skipped_by_quota_names' => $this->skipped_by_quota_names,
        ];
    }
}
