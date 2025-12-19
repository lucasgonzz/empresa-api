<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArticleIndexCache
{
    
    /**
     * Construye un índice liviano para búsquedas rápidas por bar_code,
     * provider_code y name (normalizado).
     *
     * Si $solo_de_ese_proveedor = true y se pasa $provider_id, indexa solo
     * artículos de ese proveedor (reduce memoria).
     */


    // Tengq ue modificar para que si son muuuchos productos guard solo los del proveedor
    public static function build($user_id, $provider_id, $no_actualizar_otro_proveedor) 
    {

        $inicio = microtime(true);

        $user = User::find($user_id);

        $key = "article_index_user_{$user_id}";

        $articles = Article::where('user_id', $user_id)
            ->with(['providers' => function ($q) {
                $q->select('providers.id');
            }])
            ->select(['id', 'bar_code', 'sku', 'name']);

        if (
            !$user->comparar_precios_de_proveedores_en_excel
            && $provider_id
            && $no_actualizar_otro_proveedor
        ) {
            $articles->where('provider_id', $provider_id);
        }

        $articles = $articles->get();

        $index = [
            'ids' => [],
            'bar_codes' => [],
            'skus'      => [],
            'provider_codes' => [], // provider_id -> provider_code -> article_id
            'names' => [],
        ];

        foreach ($articles as $article) {
            $index['ids'][$article->id] = $article->id;

            if (!empty($article->bar_code)) {
                $index['bar_codes'][$article->bar_code] = $article->id;
            }

            if (!empty($article->sku)) {
                $index['skus'][$article->sku] = $article->id;
            }

            if (!empty($article->name)) {
                $index['names'][strtolower(trim($article->name))] = $article->id;
            }

            foreach ($article->providers as $provider) {

                if (!empty($provider->pivot->provider_code)) {
                
                    $prov_id = $provider->id;
                
                    $prov_code = $provider->pivot->provider_code;

                    if (!isset($index['provider_codes'][$prov_id][$prov_code])) {
                        $index['provider_codes'][$prov_id][$prov_code] = [];
                    }

                    $index['provider_codes'][$prov_id][$prov_code][] = $article->id;
                }
            }
        }

        Cache::put($key, $index, now()->addMinutes(60));
        Log::info("ArticleIndexCache::build -> total artículos: " . count($articles));


        $fin = microtime(true);

        $duracion = $fin - $inicio;

        Log::info('Duración total cachear los articulos ' . number_format($duracion, 3) . ' segundos');

        if (env('APP_ENV') == 'local') {
            Log::info('');
            Log::info('**********************');
            Log::info('cache en memoria:');
            Log::info(Self::get($user_id));
            Log::info('');
            Log::info('');
        } else {
            Log::info('cache en memoria:');
            Log::info(count(Self::get($user_id)['ids']).' articulos del provider_id '.$provider_id);
            Log::info(Self::get($user_id));
        }
    }

    /**
     * Devuelve el índice cacheado (vacío si no existe).
     */
    public static function get(int $user_id): array
    {

        $key = "article_index_user_{$user_id}";

        return Cache::get($key, []);
    }

    /**
     * Busca un artículo en el índice y retorna la instancia de Eloquent
     * con relaciones críticas precargadas (para evitar N+1 en comparaciones).
     */
    public static function find(array $data, int $user_id, ?int $provider_id = null, $no_actualizar_otro_proveedor)
    {

        $key = "article_index_user_{$user_id}";
        $index = Cache::get($key);

        // if (!$index) {
        //     self::build($user_id);
        //     $index = Cache::get($key);
        // }

        Log::info('find, data:');
        Log::info($data);

        $article_id = null;

        // 1) Buscar por ID
        if (!empty($data['id'])) {

            // Log::info('Buscando por id');

            if (isset($index['ids'][$data['id']])) {

                $article_id = $index['ids'][$data['id']];
            }
        }

        // 2) Buscar por bar_code
        elseif (!empty($data['bar_code'])) {

            // Log::info('Buscando por bar_code');
            if (isset($index['bar_codes'][$data['bar_code']])) {

                $article_id = $index['bar_codes'][$data['bar_code']];
            }
        }

        // 3) Buscar por sku
        elseif (!empty($data['sku'])) {

            if (isset($index['skus'][$data['sku']])) {

                $article_id = $index['skus'][$data['sku']];
            }
        }



        // 4) Buscar por provider_code 
        elseif (!empty($data['provider_code'])) {

            Log::info('Buscando por provider_code');
            $provider_code = $data['provider_code'];

            $article_ids = [];

            // Caso 1 → hay provider_id y no_actualizar_otro_proveedor = true
            if ($provider_id && $no_actualizar_otro_proveedor) {
                if (isset($index['provider_codes'][$provider_id][$provider_code])) {
                    $article_ids = $index['provider_codes'][$provider_id][$provider_code];
                }
            }

            // Caso 2 → hay provider_id pero se permite actualizar de otros providers
            elseif ($provider_id && !$no_actualizar_otro_proveedor) {


                // Buscar también en todos los demás providers
                foreach ($index['provider_codes'] as $prov_id => $codes) {
                    if (isset($codes[$provider_code])) {
                        $article_ids = array_merge($article_ids, $codes[$provider_code]);
                    }
                }
            }


            // Caso 3 → no hay provider_id
            else {
                // Buscar el provider_code en todos los providers
                foreach ($index['provider_codes'] as $prov_id => $codes) {
                    if (isset($codes[$provider_code])) {
                        $article_ids = array_merge($article_ids, $codes[$provider_code]);
                    }
                }
            }

            if (!empty($article_ids)) {
                
                if (env('CODIGOS_DE_PROVEEDOR_REPETIDOS')) {

                    Log::info('articulos id encontrados por el provider_code:');
                    Log::info($article_ids);

                    return Article::whereIn('id', $article_ids)->get();
                } else {

                    Log::info('Filtrando articles con ids: ');
                    Log::info($article_ids);
                    return Article::whereIn('id', $article_ids)->first();
                }
            }
        }

        
        // 5) Buscar por nombre (último recurso)
        elseif (!empty($data['name'])) {
            // Log::info('Buscando por name');
            $key_name = strtolower(trim($data['name']));
            if (isset($index['names'][$key_name])) {
                $article_id = $index['names'][$key_name];
            }
        }

        // Log::info('artice_id: '.$article_id);

        return $article_id ? Article::find($article_id) : null;
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

    public static function add($article)
    {
        $key = "article_index_user_{$article->user_id}";
        $index = Cache::get($key);


        Log::info('Llego a add: ');
        // Log::info($article->id);

        $article_id = $article->fake_id;

        // if (!$index) {
        //     self::build($article->user_id);
        //     $index = Cache::get($key);
        // }

        if ($article_id) {
            $index['ids'][$article_id] = $article_id;
        }
        if ($article->bar_code) {
            $index['bar_codes'][$article->bar_code] = $article_id;
        }
        if ($article->sku) {
            $index['skus'][$article->sku] = $article_id;
        }

        // if ($article->provider_code) {
        //     $index['provider_codes'][$article->provider_code] = $article_id;
        // }
        if ($article->provider_code) {

            if (env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false)) {

                if (!isset($index['provider_codes'][$article->provider_id][$article->provider_code])) {
                    $index['provider_codes'][$article->provider_id][$article->provider_code] = [];
                }
                
                $index['provider_codes'][$article->provider_id][$article->provider_code][] = $article_id;
            } else {
                $index['provider_codes'][$article->provider_id][$article->provider_code] = $article_id;
            }
        }
        
        if ($article->name) {
            $index['names'][strtolower(trim($article->name))] = $article_id;
        }

        Log::info('Se agrego al cache: ');
        Log::info($article->toArray());

        Cache::put($key, $index, now()->addMinutes(30));
    }

    public static function update(Article $article)
    {
        $key = "article_index_user_{$article->user_id}";
        $index = Cache::get($key);

        // if (!$index) {
        //     self::build($article->user_id, null, false);
        //     $index = Cache::get($key);
        // }

        /** ------------------------------------------------------------------
         *  1) ELIMINAR SOLO EL fake QUE COINCIDE CON EL ARTÍCULO REAL
         * ------------------------------------------------------------------ */

        $fake_eliminado = false;

        // a) Si existe fake en bar_codes
        if (!empty($article->bar_code)) {

            if (isset($index['bar_codes'][$article->bar_code]) &&
                str_starts_with($index['bar_codes'][$article->bar_code], 'fake_')) {

                unset($index['ids'][$index['bar_codes'][$article->bar_code]]);
                unset($index['bar_codes'][$article->bar_code]);
                $fake_eliminado = true;
                Log::info('Se elimino del cache bar_code: '.$article->bar_code);
            }
        } 


        if (!$fake_eliminado) {

            if (!empty($article->sku)) {

                if (isset($index['skus'][$article->sku]) &&
                    str_starts_with($index['skus'][$article->sku], 'fake_')) {

                    unset($index['ids'][$index['skus'][$article->sku]]);
                    unset($index['skus'][$article->sku]);
                    $fake_eliminado = true;
                    Log::info('Se elimino del cache sku: '.$article->sku);
                }
            } 
        }



        if (!$fake_eliminado) {

            // b) Si existe fake en provider_codes
            if (!empty($article->provider_id) && !empty($article->provider_code)) {

                $prov_id = $article->provider_id;
                $prov_code = $article->provider_code;

                if (isset($index['provider_codes'][$prov_id][$prov_code])) {

                    $entry = $index['provider_codes'][$prov_id][$prov_code];

                    if (is_array($entry)) {
                        // múltiples ids → eliminar solo fakes iguales al real keys
                        $index['provider_codes'][$prov_id][$prov_code] = array_filter($entry, function($id) {
                            return !str_starts_with($id, 'fake_');
                        });
                        
                        Log::info('Se eliminaron del cache los provider_code: '.$prov_code);
                        $fake_eliminado = true;

                    } else {
                        // single id
                        if (str_starts_with($entry, 'fake_')) {
                            unset($index['provider_codes'][$prov_id][$prov_code]);
                            unset($index['ids'][$entry]);

                            Log::info('Se elimino del cache provider_code: '.$prov_code);
                            $fake_eliminado = true;
                        }
                    }
                }
            }
        }

        $name_key = strtolower(trim($article->name));
        if (!$fake_eliminado) {

            // c) Si existe fake en names
            if (!empty($article->name)) {
                if (isset($index['names'][$name_key]) &&
                    str_starts_with($index['names'][$name_key], 'fake_')) {

                    unset($index['ids'][$index['names'][$name_key]]);
                    unset($index['names'][$name_key]);
                    Log::info('Se elimino del cache name: '.$name_key);
                }
            }
        }

        if (!$fake_eliminado) {

            Log::info('No se elimino ningun fake');
        }

        /** ------------------------------------------------------------------
         * 2) AGREGAR EL ARTÍCULO REAL
         * ------------------------------------------------------------------ */

        $index['ids'][$article->id] = $article->id;

        if (!empty($article->bar_code)) {
            $index['bar_codes'][$article->bar_code] = $article->id;
        }

        if (!empty($article->name)) {
            $index['names'][$name_key] = $article->id;
        }

        // provider_code
        if (!empty($article->provider_id) && !empty($article->provider_code)) {

            if (env('CODIGOS_DE_PROVEEDOR_REPETIDOS', false)) {

                if (!isset($index['provider_codes'][$article->provider_id][$article->provider_code])) {
                    $index['provider_codes'][$article->provider_id][$article->provider_code] = [];
                }

                $index['provider_codes'][$article->provider_id][$article->provider_code][] = $article->id;
            } else {

                if (!isset($index['provider_codes'][$article->provider_id][$article->provider_code])) {
                    $index['provider_codes'][$article->provider_id][$article->provider_code] = [];
                }

                $index['provider_codes'][$article->provider_id][$article->provider_code][] = $article->id;
            }
        }

        Cache::put($key, $index, now()->addMinutes(30));
    }

    static function limpiar_cache($user_id) {

        $cache_key = "article_index_user_{$user_id}";
        
        Cache::forget($cache_key);

        Log::info("Cache de importación de artículos limpiado: {$cache_key}");
    }

}
