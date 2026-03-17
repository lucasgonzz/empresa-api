<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class ArticleIndexCache
{
    
    /**
     * Construye un índice liviano para búsquedas rápidas por bar_code,
     * provider_code y name (normalizado).
     *
     * Si $solo_de_ese_proveedor = true y se pasa $provider_id, indexa solo
     * artículos de ese proveedor (reduce memoria).
     */
    protected static $runtime_index_by_key = [];
    protected static $runtime_loaded_by_key = [];

    public static function build($user_id, $provider_id, $actualizar_articulos_de_otro_proveedor)
    {
        $inicio = microtime(true);

        $user = User::find($user_id);
        $key = "article_index_user_{$user_id}";

        $provider_codes_desde_pivot_table = false;

        // $guardar_precio_de_otros_proveedores = config('app.GUARDAR_PRECIO_DE_OTROS_PROVEEDORES');
        $guardar_precio_de_otros_proveedores = true;

        $filtrar_por_proveedor = (
            $provider_id
            && !$actualizar_articulos_de_otro_proveedor
        );

        // Lo pongo siempre en false para que machee todos los productos y deje siempre registro de a que precio lo tiene este proveedor
        $filtrar_por_proveedor = false;

        $index = [
            'ids' => [],
            'bar_codes' => [],
            'skus' => [],
            'provider_codes' => [], // provider_id -> provider_code -> article_id (o array si repetidos)
            'names' => [],
        ];

        // 1) Index liviano desde articles (SIN with(providers), SIN get() gigante)
        $article_query = Article::where('user_id', $user_id)
            ->select(['id', 'bar_code', 'sku', 'name', 'provider_code', 'provider_id'])
            ->orderBy('id');

        if ($filtrar_por_proveedor) {
            $article_query->where('provider_id', $provider_id);
        }

        $article_query->chunkById(2000, function ($articles) use (&$index) {

            foreach ($articles as $article) {
                $article_id = (int) $article->id;

                $index['ids'][$article_id] = $article_id;

                if (!empty($article->bar_code)) {
                    $index['bar_codes'][(string) $article->bar_code] = $article_id;
                }

                if (!empty($article->sku)) {
                    $index['skus'][(string) $article->sku] = $article_id;
                }

                Log::info('provider_code: '.$article->provider_code);
                Log::info('provider_id: '.$article->provider_id);

                // if (!$provider_codes_desde_pivot_table) {
                    if (
                        !is_null($article->provider_code)
                        && !is_null($article->provider_id)
                    ) {
                        Log::info('Entro en provider_codes para hacer index');
                        $prov_code  = $article->provider_code;
                        $prov_id    = $article->provider_id;

                        if (!isset($index['provider_codes'][$prov_id])) {
                            $index['provider_codes'][$prov_id] = [];
                        }
                        if (!isset($index['provider_codes'][$prov_id][$prov_code])) {
                            $index['provider_codes'][$prov_id][$prov_code] = [];
                        }
                        $index['provider_codes'][$prov_id][$prov_code][] = $article_id;
                    }
                // }


                if (!empty($article->name)) {
                    $index['names'][strtolower(trim((string) $article->name))] = $article_id;
                }
            }
        });

        if ($provider_codes_desde_pivot_table) {
            
            // 2) provider_codes desde pivot con JOIN (sin cargar relaciones en memoria)
            $pivot_query = DB::table('article_provider')
                ->join('articles', 'articles.id', '=', 'article_provider.article_id')
                ->where('articles.user_id', $user_id)
                ->whereNull('articles.deleted_at') // ✅ clave: excluye eliminados en JOIN
                ->whereNotNull('article_provider.provider_code')
                ->select([
                    'article_provider.provider_id',
                    'article_provider.provider_code',
                    'article_provider.article_id',
                ])
                ->orderBy('article_provider.article_id');

            if ($filtrar_por_proveedor) {
                $pivot_query->where('articles.provider_id', $provider_id);
            }

            $pivot_query->chunk(5000, function ($rows) use (&$index, $codigos_proveedor_repetidos) {
                foreach ($rows as $row) {
                    $prov_id = (int) $row->provider_id;
                    $prov_code = trim((string) $row->provider_code);
                    $article_id = (int) $row->article_id;

                    if ($prov_code === '') {
                        continue;
                    }
                    
                    if (!isset($index['provider_codes'][$prov_id][$prov_code])) {
                        $index['provider_codes'][$prov_id][$prov_code] = [];
                    }
                    $index['provider_codes'][$prov_id][$prov_code][] = $article_id;

                    // if ($codigos_proveedor_repetidos) {
                    //     if (!isset($index['provider_codes'][$prov_id][$prov_code])) {
                    //         $index['provider_codes'][$prov_id][$prov_code] = [];
                    //     }
                    //     $index['provider_codes'][$prov_id][$prov_code][] = $article_id;
                    // } else {
                    //     if (!isset($index['provider_codes'][$prov_id][$prov_code])) {
                    //         $index['provider_codes'][$prov_id][$prov_code] = [$article_id];
                    //     } else {
                    //         // por si aparecen duplicados inesperados, lo convertimos a array
                    //         if (!is_array($index['provider_codes'][$prov_id][$prov_code])) {
                    //             $index['provider_codes'][$prov_id][$prov_code] = [$index['provider_codes'][$prov_id][$prov_code]];
                    //         }
                    //         $index['provider_codes'][$prov_id][$prov_code][] = $article_id;
                    //     }
                    // }
                }
            });
        }

        Cache::put($key, $index, now()->addMinutes(60));

        $duracion = microtime(true) - $inicio;

        Log::info("ArticleIndexCache::build -> ids: " . count($index['ids']) . " provider_codes: ". count($index['provider_codes']) . " bar_codes: " . count($index['bar_codes']) . " skus: " . count($index['skus']) . " names: " . count($index['names']));
        Log::info('$index->provider_codes: ');
        Log::info($index['provider_codes']);
        // Log::info('Duración total cachear los articulos ' . $duracion . ' seg');

        return $duracion;
    }


    // Tengq ue modificar para que si son muuuchos productos guard solo los del proveedor
    // public static function build($user_id, $provider_id, $no_actualizar_otro_proveedor) 
    // {

    //     $inicio = microtime(true);

    //     $user = User::find($user_id);

    //     $key = "article_index_user_{$user_id}";

    //     $articles = Article::where('user_id', $user_id)
    //         ->with(['providers' => function ($q) {
    //             $q->select('providers.id');
    //         }])
    //         ->select(['id', 'bar_code', 'sku', 'name']);

    //     $guardar_precio_de_otros_proveedores = config('app.GUARDAR_PRECIO_DE_OTROS_PROVEEDORES');

    //     if (
    //         !$user->comparar_precios_de_proveedores_en_excel
    //         && $provider_id
    //         && $no_actualizar_otro_proveedor
    //         && !$guardar_precio_de_otros_proveedores
    //     ) {
    //         $articles->where('provider_id', $provider_id);
    //     }

    //     $articles = $articles->get();

    //     $index = [
    //         'ids' => [],
    //         'bar_codes' => [],
    //         'skus'      => [],
    //         'provider_codes' => [], // provider_id -> provider_code -> article_id
    //         'names' => [],
    //     ];

    //     foreach ($articles as $article) {
    //         $index['ids'][$article->id] = $article->id;

    //         if (!empty($article->bar_code)) {
    //             $index['bar_codes'][$article->bar_code] = $article->id;
    //         }

    //         if (!empty($article->sku)) {
    //             $index['skus'][$article->sku] = $article->id;
    //         }

    //         if (!empty($article->name)) {
    //             $index['names'][strtolower(trim($article->name))] = $article->id;
    //         }

    //         foreach ($article->providers as $provider) {

    //             if (!empty($provider->pivot->provider_code)) {
                
    //                 $prov_id = $provider->id;
                
    //                 $prov_code = $provider->pivot->provider_code;
    //                 $prov_code = trim($prov_code);

    //                 if (!isset($index['provider_codes'][$prov_id][$prov_code])) {
    //                     $index['provider_codes'][$prov_id][$prov_code] = [];
    //                 }

    //                 $index['provider_codes'][$prov_id][$prov_code][] = $article->id;
    //             }
    //         }
    //     }

    //     Cache::put($key, $index, now()->addMinutes(60));
    //     Log::info("ArticleIndexCache::build -> total artículos: " . count($articles));


    //     $fin = microtime(true);

    //     $duracion = $fin - $inicio;

    //     Log::info('Duración total cachear los articulos ' . $duracion . ' seg');

    //     if (config('app.APP_ENV' 'local') {
    //         // Log::info('');
    //         // Log::info('**********************');
    //         // Log::info('cache en memoria:');
    //         // Log::info(Self::get($user_id));
    //         // Log::info('');
    //         // Log::info('');
    //     } else {
    //         // Log::info('cache en memoria:');
    //         // Log::info(count(Self::get($user_id)['ids']).' articulos del provider_id '.$provider_id);
    //         // Log::info(Self::get($user_id));
    //     }

    //     return $duracion;
    // }

    /**
     * Devuelve el índice cacheado (vacío si no existe).
     */
    public static function get(int $user_id): array
    {

        $key = "article_index_user_{$user_id}";

        return Cache::get($key, []);
    }


    public static function get_index(int $user_id, ?int $provider_id = null, $actualizar_otro_proveedor = null): array
    {
        $key = "article_index_user_{$user_id}";

        // ✅ runtime memoization (RAM) para este proceso/job
        if (!empty(self::$runtime_loaded_by_key[$key])) {
            return self::$runtime_index_by_key[$key];
        }

        Log::info('GET INDEX DESDE REDIS '.$key);

        $index = Cache::get($key, []);

        if (!is_array($index) || empty($index)) {
            self::build($user_id, $provider_id, $actualizar_otro_proveedor);
            $index = Cache::get($key, []);
        }

        $index = is_array($index) ? $index : [];

        self::$runtime_index_by_key[$key] = $index;
        self::$runtime_loaded_by_key[$key] = true;

        return $index;
    }


    public static function find_with_index(
        array $data,
        array $index,
        int $user_id,
        ?int $provider_id = null,

        bool $permitir_provider_code_repetido = false,
        bool $permitir_provider_code_repetido_en_multi_providers = true,
        bool $actualizar_articulos_de_otro_proveedor = false,
        bool $actualizar_por_provider_code = true,
        bool $actualizar_proveedor = true
    ) {
        if (!is_array($index) || empty($index)) {
            $index = self::get_index($user_id, $provider_id);
        }

        Log::info('find_with_index');

        $relations = [
            'price_types',
            'addresses',
            'providers' => function ($q) {
                $q->select('providers.id');
            },
        ];

        $article_id = null;

        // 1) ID
        if (!empty($data['id']) && isset($index['ids'][(string)$data['id']])) {
            $article_id = $index['ids'][(string)$data['id']];
        }

        // 2) bar_code
        elseif (!empty($data['bar_code']) && isset($index['bar_codes'][(string)$data['bar_code']])) {
            $article_id = $index['bar_codes'][(string)$data['bar_code']];
        }

        // 3) sku
        elseif (!empty($data['sku']) && isset($index['skus'][(string)$data['sku']])) {
            $article_id = $index['skus'][(string)$data['sku']];
        }

        // 4) provider_code
        elseif (!empty($data['provider_code'])) {

            Log::info('Buscando por provider_code');

            $provider_code = trim((string)$data['provider_code']);
            if ($provider_code === '') {
                return null;
            }

            // Si se permiten repetidos pero NO querés sincronizar por provider_code => modo crear
            if ($permitir_provider_code_repetido && !$actualizar_por_provider_code) {
                Log::info(1);
                return null;
            }

            $article_ids = [];

            // Si hay provider_id, primero miramos en ese provider
            if (!is_null($provider_id) && isset($index['provider_codes'][(int)$provider_id][$provider_code])) {
                Log::info(2);
                $article_ids = array_merge($article_ids, Arr::wrap($index['provider_codes'][(int)$provider_id][$provider_code]));
            }

            // ¿Incluimos otros proveedores?
            if ($actualizar_articulos_de_otro_proveedor) {
                Log::info(3);
                foreach ($index['provider_codes'] as $p_id => $codes) {
                    if (!is_null($provider_id) && (int)$p_id === (int)$provider_id) {
                        continue;
                    }
                    if (isset($codes[$provider_code])) {
                        $article_ids = array_merge($article_ids, Arr::wrap($codes[$provider_code]));
                    }
                }
            }

            $article_ids = array_values(array_unique($article_ids));

            if (!empty($article_ids)) {
                if ($permitir_provider_code_repetido) {
                    Log::info(4);
                    // Repetidos + sync: actualizar TODOS
                    return Article::with($relations)->whereIn('id', $article_ids)->get();
                }
                // No repetidos: actualizar 1
                Log::info(6);
                return Article::with($relations)->whereIn('id', $article_ids)->first();
            }

            return null;
        }

        // 5) name
        elseif (!empty($data['name'])) {
            $key_name = mb_strtolower(trim((string)$data['name']));
            if (isset($index['names'][$key_name])) {
                $article_id = $index['names'][$key_name];
            }
        }

        // Si no encontramos nada por ID/bar_code/sku/name => crear
        if (!$article_id) {
            return null;
        }

        // REGRA actualizar_proveedor:
        // si el artículo no pertenece al provider actual y NO querés actualizar proveedor => no lo uses
        if (!is_null($provider_id) && !$actualizar_proveedor) {
            $prov_map = $index['article_providers'][$article_id] ?? [];
            if (!isset($prov_map[(int)$provider_id])) {
                return null;
            }
        }

        return Article::with($relations)->find($article_id);
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


        // Log::info('Llego a add: ');
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

            // if (config('app.CODIGOS_DE_PROVEEDOR_REPETIDOS')) {

            //     if (!isset($index['provider_codes'][$article->provider_id][$article->provider_code])) {
            //         $index['provider_codes'][$article->provider_id][$article->provider_code] = [];
            //     }
                
            //     $index['provider_codes'][$article->provider_id][$article->provider_code][] = $article_id;
            // } else {
            //     $index['provider_codes'][$article->provider_id][$article->provider_code][] = $article_id;
            // }


            if (!isset($index['provider_codes'][$article->provider_id][$article->provider_code])) {
                $index['provider_codes'][$article->provider_id][$article->provider_code] = [];
            }
            
            $index['provider_codes'][$article->provider_id][$article->provider_code][] = $article_id;
        }
        
        if ($article->name) {
            $index['names'][strtolower(trim($article->name))] = $article_id;
        }

        // Log::info('Se agrego al cache: ');
        // Log::info($article->toArray());


        Cache::put($key, $index, now()->addMinutes(30));

        self::$runtime_index_by_key[$key] = $index;
        self::$runtime_loaded_by_key[$key] = true;
    }

    public static function update(Article $article, $codigos_proveedor_repetidos)
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
                // Log::info('Se elimino del cache bar_code: '.$article->bar_code);
            }
        } 


        if (!$fake_eliminado) {

            if (!empty($article->sku)) {

                if (isset($index['skus'][$article->sku]) &&
                    str_starts_with($index['skus'][$article->sku], 'fake_')) {

                    unset($index['ids'][$index['skus'][$article->sku]]);
                    unset($index['skus'][$article->sku]);
                    $fake_eliminado = true;
                    // Log::info('Se elimino del cache sku: '.$article->sku);
                }
            } 
        }



        if (!$fake_eliminado) {

            // b) Si existe fake en provider_codes
            if (!empty($article->provider_id) && !empty($article->provider_code)) {

                $prov_id = $article->provider_id;
                $prov_code = $article->provider_code;

                if (
                    isset($index['provider_codes'][$prov_id])
                    && isset($index['provider_codes'][$prov_id][$prov_code])
                ) {

                    $entry = $index['provider_codes'][$prov_id][$prov_code];

                    if (is_array($entry)) {
                        // múltiples ids → eliminar solo fakes iguales al real keys
                        $index['provider_codes'][$prov_id][$prov_code] = array_filter($entry, function($id) {
                            return !str_starts_with($id, 'fake_');
                        });
                        
                        // Log::info('Se eliminaron del cache los provider_code: '.$prov_code);
                        $fake_eliminado = true;

                    } else {
                        // single id
                        if (str_starts_with($entry, 'fake_')) {
                            unset($index['provider_codes'][$prov_id][$prov_code]);
                            unset($index['ids'][$entry]);

                            // Log::info('Se elimino del cache provider_code: '.$prov_code);
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
                    // Log::info('Se elimino del cache name: '.$name_key);
                }
            }
        }

        if (!$fake_eliminado) {

            // Log::info('No se elimino ningun fake');
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

            if ($codigos_proveedor_repetidos) {

                if (!isset($index['provider_codes'][$article->provider_id][$article->provider_code])) {
                    $index['provider_codes'][$article->provider_id][$article->provider_code] = [];
                }

                $index['provider_codes'][$article->provider_id][$article->provider_code][] = $article->id;
            } else {

                // if (!isset($index['provider_codes'][$article->provider_id][$article->provider_code])) {
                //     $index['provider_codes'][$article->provider_id][$article->provider_code] = [];
                // }

                // Log::info('Agregando el articulo con provider_code '.$article->provider_code.' al cache:');
                // Log::info($index['provider_codes'][$article->provider_id][$article->provider_code]);
                $index['provider_codes'][$article->provider_id][$article->provider_code][] = $article->id;
            }
        }

        Cache::put($key, $index, now()->addMinutes(30));

        self::$runtime_index_by_key[$key] = $index;
        self::$runtime_loaded_by_key[$key] = true;
    }

    static function limpiar_cache($user_id) {

        $cache_key = "article_index_user_{$user_id}";
        
        Cache::forget($cache_key);

        Log::info("Cache de importación de artículos limpiado: {$cache_key}");

        unset(self::$runtime_index_by_key[$cache_key]);
        unset(self::$runtime_loaded_by_key[$cache_key]);
    }

}
