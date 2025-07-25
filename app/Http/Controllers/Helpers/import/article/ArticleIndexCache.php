<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Article;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArticleIndexCache
{
    public static function build($user_id)
    {
        $articles = Article::where('user_id', $user_id)->select([
            'id', 'bar_code', 'provider_code', 'name'
        ])->get();

        $index = [
            'bar_codes' => [],
            'provider_codes' => [],
            'names' => [],
            'ids' => [],
        ];

        foreach ($articles as $article) {
            if ($article->id) {
                $index['ids'][$article->id] = $article->id;
            }
            if ($article->bar_code) {
                $index['bar_codes'][$article->bar_code] = $article->id;
            }
            if ($article->provider_code) {
                $index['provider_codes'][$article->provider_code] = $article->id;
            }
            if ($article->name) {
                $index['names'][strtolower(trim($article->name))] = $article->id;
            }
        }

        // Log::info('se puso este cache:');
        // Log::info($index);

        Cache::put("article_index_user_{$user_id}", $index, now()->addMinutes(30));
    }

    public static function get($user_id)
    {
        return Cache::get("article_index_user_{$user_id}", [
            'bar_codes' => [],
            'provider_codes' => [],
            'names' => [],
            'ids' => [],
        ]);
    }

    public static function find(array $data, $user_id): ?Article
    {
        $index = self::get($user_id);

        // Buscar primero por id si está definido
        if (!empty($data['id'])) {
            $lookup = $data['id'];
            if (isset($index['ids'][$lookup])) {
                return Article::find($index['ids'][$lookup]);
            }
            return null;
        }

        // Si no hay id, buscar por bar_code si está definido
        if (!empty($data['bar_code'])) {
            $lookup = $data['bar_code'];
            if (isset($index['bar_codes'][$lookup])) {
                return Article::find($index['bar_codes'][$lookup]);
            }
            return null;
        }

        // Si no hay bar_code, buscar por provider_code
        if (
            !empty($data['provider_code'])
            && !env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false)
        ) {
            $lookup = $data['provider_code'];
            if (isset($index['provider_codes'][$lookup])) {
                return Article::find($index['provider_codes'][$lookup]);
            }
            return null;
        }

        // Si no hay bar_code ni provider_code, buscar por name
        if (!empty($data['name'])) {
            $lookup = strtolower(trim($data['name']));
            if (isset($index['names'][$lookup])) {
                return Article::find($index['names'][$lookup]);
            }
        }

        return null;
    }


    // public static function find(array $data, $user_id): ?Article
    // {
    //     $index = self::get($user_id);

    //     foreach (['id', 'provider_code', 'bar_code', 'name'] as $key) {
    //         $lookup = $data[$key] ?? null;
    //         if ($key === 'name' && $lookup) {
    //             $lookup = strtolower(trim($lookup));
    //         }

    //         if ($lookup) {
    //             if (isset($index["{$key}s"][$lookup])) {
    //                 return Article::find($index["{$key}s"][$lookup]);
    //             }
    //         }
    //     }

    //     return null;
    // }

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
        if ($article->provider_code) {
            $index['provider_codes'][$article->provider_code] = $article->id;
        }
        if ($article->name) {
            $index['names'][strtolower(trim($article->name))] = $article->id;
        }

        Cache::put($key, $index, now()->addMinutes(30));
    }
}
