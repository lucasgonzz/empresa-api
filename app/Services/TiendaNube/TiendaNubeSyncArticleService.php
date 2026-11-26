<?php

namespace App\Services\TiendaNube;

use App\Http\Controllers\Helpers\ArticlePlatformSyncNotificationHelper;
use App\Models\SyncToTNArticle;
use Illuminate\Support\Facades\Log;

class TiendaNubeSyncArticleService
{
    private const MAX_RATE_LIMIT_ATTEMPTS = 4;
    private const MAX_RETRY_DELAY_HOURS = 24;

    public static function add_article_to_sync($article)
    {
        if (!env('USA_TIENDA_NUBE', false)) {
            return;
        }

        if (!$article->tiendanube_product_id && !$article->disponible_tienda_nube) {
            return;
        }

        // Una edición durante una ejecución necesita su propio pendiente.
        SyncToTNArticle::firstOrCreate([
            'article_id' => $article->id,
            'user_id' => $article->user_id,
            'status' => 'pendiente',
        ]);
    }

    /** Devuelve false cuando se debe detener el lote del dueño por HTTP 429. */
    public function sync_article(SyncToTNArticle $sync): bool
    {
        $claimed = SyncToTNArticle::pendingForOwner((int) $sync->user_id)
            ->whereKey($sync->id)
            ->where(function ($query) {
                $query->whereNull('rate_limited_until')->orWhere('rate_limited_until', '<=', now());
            })
            ->update(['status' => 'en_progreso', 'attempted_at' => now()]);

        if (!$claimed) {
            return true;
        }

        $sync->refresh();

        try {
            $article = $sync->article;
            if (!$article) {
                throw new \RuntimeException('El artículo ya no existe.');
            }

            if ($sync->rate_limit_attempts > 0 && !$article->tiendanube_product_id) {
                throw new \RuntimeException('El artículo perdió su vínculo con Tienda Nube durante la espera; revisalo antes de volver a publicarlo.');
            }

            $service = new TiendaNubeProductService(null, $sync->user_id);
            $service->crearOActualizarProducto($article);

            $sync->status = 'exitosa';
            $sync->synced_at = now();
            $sync->error_message = null;
            $sync->error_message_crudo = null;
            $sync->rate_limited_until = null;
            $sync->rate_limit_attempts = 0;
            $sync->save();
        } catch (TiendaNubeImageRateLimitException $e) {
            $this->handle_rate_limit($sync, $e);
            return false;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $article = $sync->article;
            $articleLabel = $article ? $article->name : ('sync #'.$sync->id);

            $sync->status = 'error';
            $sync->error_message = $errorMessage;
            $sync->error_message_crudo = $errorMessage;
            $sync->rate_limited_until = null;
            $sync->rate_limit_attempts = 0;
            $sync->save();

            Log::error('Error al sincronizar artículo con Tienda Nube', ['exception' => $e]);
            ArticlePlatformSyncNotificationHelper::notify_tienda_nube_sync_failed(
                (int) $sync->user_id,
                $articleLabel,
                $errorMessage
            );
        }

        return true;
    }

    private function handle_rate_limit(SyncToTNArticle $sync, TiendaNubeImageRateLimitException $error): void
    {
        $attempts = (int) $sync->rate_limit_attempts + 1;
        $retryAt = $error->retry_at(60 * (2 ** ($attempts - 1)));
        $retry = $attempts < self::MAX_RATE_LIMIT_ATTEMPTS
            && $retryAt->lte(now()->addHours(self::MAX_RETRY_DELAY_HOURS));

        $sync->rate_limit_attempts = $attempts;
        $sync->rate_limited_until = $retryAt;
        $sync->status = $retry ? 'pendiente' : 'error';
        $sync->error_message = $retry
            ? 'Tienda Nube limitó temporalmente las consultas de imágenes. La sincronización se reintentará.'
            : 'Tienda Nube mantuvo el límite de consultas de imágenes. Revisá el historial de sincronización.';
        $sync->error_message_crudo = $error->getMessage();
        $sync->save();

        Log::log($retry ? 'warning' : 'error', $sync->error_message, [
            'sync_id' => $sync->id,
            'article_id' => $sync->article_id,
            'retry_at' => $retryAt->toDateTimeString(),
            'attempts' => $attempts,
        ]);

        if (!$retry && !SyncToTNArticle::where('user_id', $sync->user_id)
            ->where('id', '!=', $sync->id)
            ->where('status', 'error')
            ->where('rate_limit_attempts', '>', 0)
            ->where('attempted_at', '>=', now()->subHour())
            ->exists()) {
            ArticlePlatformSyncNotificationHelper::notify_tienda_nube_rate_limit((int) $sync->user_id);
        }
    }
}
