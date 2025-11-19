<?php

namespace App\Services\TiendaNube;

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


            if (!$article->disponible_tienda_nube) {
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
            $article->load('meli_category');

            
            $service = new TiendaNubeProductService();
            $service->crearOActualizarProducto($article);
            

            $sync->status = 'exitosa';
            $sync->synced_at = now();
            $sync->error_message = null;
            $sync->save();

        } catch (\Exception $e) {


            \Log::error('Error al sincronizar artículo con MercadoLibre: ' . $e->getMessage());
            
            $error_message = $e->getMessage();

            // Si el mensaje tiene un JSON de respuesta de ML, intentamos extraerlo
            if (str_contains($error_message, 'Mercado Libre API error:')) {
                $json_part = trim(str_replace('Mercado Libre API error:', '', $error_message));

                $parsed_error = json_decode($json_part, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($parsed_error['cause'])) {

                    if (is_array($parsed_error['cause'])) {
                        $causes = array_map(function ($c) {
                            return "- {$c['message']} (Código: {$c['code']})";
                        }, $parsed_error['cause']);
                    }

                    $error_message = "Errores al sincronizar con MercadoLibre:\n" . implode("\n", $causes);
                }
            }

            $sync->status = 'error';
            $sync->error_message = $error_message;
            $sync->error_message_crudo = $e->getMessage();
            Log::info('Se marco como fallido');
            $sync->save();

        }
    }
}
