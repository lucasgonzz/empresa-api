<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Article;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArticleIndexCache_viejo
{
    
    /**
     * Construye un índice liviano para búsquedas rápidas por bar_code,
     * provider_code y name (normalizado).
     *
     * Si $solo_de_ese_proveedor = true y se pasa $provider_id, indexa solo
     * artículos de ese proveedor (reduce memoria).
     */
    public static function build(
        int $user_id,
        ?int $provider_id = 0,
        bool $solo_de_ese_proveedor = false
    ): void {
        $query = Article::where('user_id', $user_id)->select([
            'id', 'bar_code', 'provider_code', 'name', 'provider_id',
        ]);

        if ($solo_de_ese_proveedor && $provider_id != 0) {
            $query->where('provider_id', $provider_id);
            $key = "article_index_user_{$user_id}_provider_{$provider_id}";
        } else {
            $key = "article_index_user_{$user_id}";
        }

        $articles = $query->get();

        $index = [
            'ids'            => [],   // bar_code => article_id
            'bar_codes'      => [],   // bar_code => article_id
            'provider_codes' => [],   // provider_code => article_id|[ids]
            'names'          => [],   // normalized name => article_id
        ];

        foreach ($articles as $article) {
            
            $index['ids'][$article->id] = $article->id;

            if (!empty($article->bar_code)) {
                $index['bar_codes'][$article->bar_code] = $article->id;
            }

            if (!empty($article->provider_code)) {
                if (env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false)) {
                    if (!isset($index['provider_codes'][$article->provider_code])) {
                        $index['provider_codes'][$article->provider_code] = [];
                    }
                    $index['provider_codes'][$article->provider_code][] = $article->id;
                } else {
                    if (!isset($index['provider_codes'][$article->provider_code])) {
                        $index['provider_codes'][$article->provider_code] = $article->id;
                    }
                }
            }

            if (!empty($article->name)) {
                $index['names'][strtolower(trim($article->name))] = $article->id;
            }
        }

        Cache::put($key, $index, now()->addMinutes(30));
        Log::info("ArticleIndexCache::build -> key: {$key}, total: " . count($articles));
    }

    /**
     * Devuelve el índice cacheado (vacío si no existe).
     */
    public static function get(int $user_id, ?int $provider_id = null, bool $solo_de_ese_proveedor = false): array
    {
        $key = $solo_de_ese_proveedor && !is_null($provider_id)
            ? "article_index_user_{$user_id}_provider_{$provider_id}"
            : "article_index_user_{$user_id}";

        return Cache::get($key, []);
    }

    /**
     * Busca un artículo en el índice y retorna la instancia de Eloquent
     * con relaciones críticas precargadas (para evitar N+1 en comparaciones).
     */
    public static function find(array $data, int $user_id, ?int $provider_id = null, bool $solo_de_ese_proveedor = false)
    {

        $index = self::get($user_id, $provider_id, $solo_de_ese_proveedor);

        $article_id = null;

        // 0) id
        if (!empty($data['id'])) {

            Log::info('Buscando por id: '.$data['id']);
            if (isset($index['ids'][$data['id']])) {
                $article_id = $index['ids'][$data['id']];
            } else {
                $article_id = null;
            }
        }

        // 1) bar_code
        if (!empty($data['bar_code'])) {
            Log::info('Buscando por bar_code: '.$data['bar_code']);
            
            if (isset($index['bar_codes'][$data['bar_code']])) {
                $article_id = $index['bar_codes'][$data['bar_code']];
            } else {
                $article_id = null;
            }

        } else if (!empty($data['provider_code'])) {

            Log::info('Buscando por provider_code: '.$data['provider_code']);
            if (isset($index['provider_codes'][$data['provider_code']])) {

                $id_or_ids = $index['provider_codes'][$data['provider_code']];
                $article_id = is_array($id_or_ids) ? reset($id_or_ids) : $id_or_ids;
            } else {
                $article_id = null;
            }

        } else if (!empty($data['name'])) {

            Log::info('Buscando por name: '.$data['name']);
            $lookup = strtolower(trim($data['name']));
            if (isset($index['names'][$lookup])) {
                $article_id = $index['names'][$lookup];
            } else {
                $article_id = null;
            }
        }
        
        Log::info('article_id: '.$article_id);

        if ($article_id) {
            Log::info('Filtrando con article_id: '.$article_id);
            // 🔎 Rel. mínimas para comparación en ProcessRow (ajustá si tenés otros nombres)
            return Article::with(['price_types', 'addresses'])->find($article_id);
        }

        return null;
    }

    /**
     * Devuelve TODOS los artículos que matchean un provider_code (maneja repetidos).
     */
    public static function find_all_by_provider_code(string $provider_code, int $user_id, ?int $provider_id = null, bool $solo_de_ese_proveedor = false)
    {

        $index = self::get($user_id, $provider_id, $solo_de_ese_proveedor);

        $ids = [];
        if (isset($index['provider_codes'][$provider_code])) {
            $matched_ids = $index['provider_codes'][$provider_code];
            if (is_array($matched_ids)) {
                foreach ($matched_ids as $id) $ids[] = $id;
            } else {
                $ids[] = $matched_ids;
            }
        }

        return Article::whereIn('id', $ids)->get();
    }

    public static function add(Article $article)
    {
        $key = "article_index_user_{$article->user_id}";
        $index = Cache::get($key);

        if (!$index) {
            self::build($article->user_id);
            $index = Cache::get($key);
        }

        if ($article->id) {
            $index['ids'][$article->id] = $article->id;
        }
        if ($article->bar_code) {
            $index['bar_codes'][$article->bar_code] = $article->id;
        }

        // if ($article->provider_code) {
        //     $index['provider_codes'][$article->provider_code] = $article->id;
        // }
        if ($article->provider_code) {
            if (env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false)) {
                if (!isset($index['provider_codes'][$article->provider_code])) {
                    $index['provider_codes'][$article->provider_code] = [];
                }
                $index['provider_codes'][$article->provider_code][] = $article->id;
            } else {
                $index['provider_codes'][$article->provider_code] = $article->id;
            }
        }
        
        if ($article->name) {
            $index['names'][strtolower(trim($article->name))] = $article->id;
        }

        Cache::put($key, $index, now()->addMinutes(30));
    }
}
