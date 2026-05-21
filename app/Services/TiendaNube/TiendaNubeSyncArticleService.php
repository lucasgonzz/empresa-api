<?php

namespace App\Services\TiendaNube;

use App\Http\Controllers\Helpers\ArticlePlatformSyncNotificationHelper;
use App\Models\Article;
use App\Models\SyncToTNArticle;
use App\Services\TiendaNube\TiendaNubeProductService;
use Illuminate\Support\Facades\Log;

class TiendaNubeSyncArticleService 
{

    // Si el articulo tiene la info necesaria, se marca para sync a meli
    static function add_article_to_sync($article) {


        if (
            env('USA_TIENDA_NUBE', false)
        ) {


            if (
                !$article->tiendanube_product_id
                && !$article->disponible_tienda_nube
            ) {
                return;
            }


            $already_exists = SyncToTNArticle::where('article_id', $article->id)
                                    ->where('user_id', $article->user_id)
                                    ->where('status', 'pendiente')
                                    ->exists();

            if (!$already_exists) {
                SyncToTNArticle::create([
                    'article_id'    => $article->id,
                    'user_id'       => $article->user_id,
                    'status'        => 'pendiente',
                ]);
            }
            
        }
        
    }

    public function sync_article(SyncToTNArticle $sync)
    {
        $sync->status = 'en_progreso';
        $sync->attempted_at = now();
        $sync->save();

        try {
            $article = $sync->article;
            
            $service = new TiendaNubeProductService(null, $sync->user_id);
            $service->crearOActualizarProducto($article);
            

            $sync->status = 'exitosa';
            $sync->synced_at = now();
            $sync->error_message = null;
            $sync->save();

        } catch (\Exception $e) {
            Log::error('Error al sincronizar artículo con Tienda Nube: ');
            Log::error($e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());

            $error_message = $e->getMessage();
            $article = $sync->article ?? null;
            $article_label = $article ? $article->name : ('sync #'.$sync->id);

            $sync->status = 'error';
            $sync->error_message = $error_message;
            $sync->error_message_crudo = $e->getMessage();
            Log::info('Se marco como fallido');
            $sync->save();

            ArticlePlatformSyncNotificationHelper::notify_tienda_nube_sync_failed(
                (int) $sync->user_id,
                $article_label,
                $error_message
            );
        }
    }
}
