<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

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
    protected static $runtime_dirty_by_key = [];
    protected static $log_activado = false;

    /**
     * Registro en RAM de modelos Article "fake" pendientes de persistir (por user_id y fake_id).
     * Permite que find_with_index devuelva el mismo artículo aún sin fila en BD (whereIn(id) vacío).
     *
     * Estructura: [user_id][fake_id] => Article
     */
    protected static $runtime_fake_articles = [];


    /**
     * Devuelve un Article fake registrado vía add() para este usuario, o null.
     *
     * @param int $user_id dueño del índice / artículo
     * @param string $fake_id identificador tipo fake_*
     * @return Article|null
     */
    public static function get_runtime_fake_article(int $user_id, string $fake_id): ?Article
    {
        if ($fake_id === '' || !str_starts_with($fake_id, 'fake_')) {
            return null;
        }

        if (empty(self::$runtime_fake_articles[$user_id][$fake_id])) {
            return null;
        }

        return self::$runtime_fake_articles[$user_id][$fake_id];
    }

    /**
     * Quita un fake_id del registro en RAM (tras merge o al reemplazar por artículo real).
     *
     * @param int $user_id
     * @param string $fake_id
     */
    public static function forget_runtime_fake_article(int $user_id, string $fake_id): void
    {
        unset(self::$runtime_fake_articles[$user_id][$fake_id]);
    }

    /**
     * Arma una colección mezclando artículos de BD (ids numéricos) y modelos fake registrados en RAM.
     *
     * @param array $article_ids ids del índice (enteros o strings fake_*)
     * @param array $relations relaciones eager para consulta Eloquent
     * @param int $user_id usuario del índice
     * @return Collection de Article
     */
    protected static function collection_from_index_article_ids(array $article_ids, array $relations, int $user_id): Collection
    {
        // ids que existen en tabla articles vs pendientes de crear en este proceso
        $db_ids = [];
        $fake_ids_ordered = [];

        foreach ($article_ids as $raw_id) {

            $as_string = (string) $raw_id;

            if (str_starts_with($as_string, 'fake_')) {

                $fake_ids_ordered[$as_string] = true;
            } else {

                $db_ids[] = $raw_id;
            }
        }

        $out = collect();

        if (count($db_ids) > 0) {
            $out = $out->merge(Article::with($relations)->whereIn('id', $db_ids)->get());
        }

        foreach (array_keys($fake_ids_ordered) as $fid) {

            $fake_model = self::get_runtime_fake_article($user_id, $fid);

            if ($fake_model instanceof Article) {
                $out->push($fake_model);
            }
        }

        return $out;
    }

    /**
     * Elimina del índice runtime todas las entradas que apuntan a un fake_id concreto
     * (bar_code, sku, name, provider_codes, ids). Sirve antes de re-add tras merge de fila.
     *
     * @param int $user_id
     * @param string $fake_id
     */
    public static function remove_fake_from_runtime_index(int $user_id, string $fake_id): void
    {
        $key = "article_index_user_{$user_id}";

        if (empty(self::$runtime_loaded_by_key[$key]) || empty(self::$runtime_index_by_key[$key])) {
            return;
        }

        $index = self::$runtime_index_by_key[$key];

        unset($index['ids'][$fake_id]);

        foreach ($index['bar_codes'] as $bc => $fid) {

            if ((string) $fid === $fake_id) {
                unset($index['bar_codes'][$bc]);
            }
        }

        foreach ($index['skus'] as $sku => $fid) {

            if ((string) $fid === $fake_id) {
                unset($index['skus'][$sku]);
            }
        }

        foreach ($index['names'] as $name_key => $fid) {

            if ((string) $fid === $fake_id) {
                unset($index['names'][$name_key]);
            }
        }

        foreach ($index['provider_codes'] as $p_id => $codes) {

            if (!is_array($codes)) {
                continue;
            }

            foreach ($codes as $pc => $entry) {

                if (is_array($entry)) {

                    $filtered = [];

                    foreach ($entry as $id_val) {

                        if ((string) $id_val !== $fake_id) {
                            $filtered[] = $id_val;
                        }
                    }

                    if (count($filtered) === 0) {
                        unset($index['provider_codes'][$p_id][$pc]);
                    } else {
                        $index['provider_codes'][$p_id][$pc] = $filtered;
                    }
                } else {

                    if ((string) $entry === $fake_id) {
                        unset($index['provider_codes'][$p_id][$pc]);
                    }
                }
            }

            if (isset($index['provider_codes'][$p_id]) && count($index['provider_codes'][$p_id]) === 0) {
                unset($index['provider_codes'][$p_id]);
            }
        }

        self::$runtime_index_by_key[$key] = $index;

        self::forget_runtime_fake_article($user_id, $fake_id);
    }


    public static function build($user_id, $provider_id, $actualizar_articulos_de_otro_proveedor)
    {
        $inicio = microtime(true);

        $user = User::find($user_id);
        $key = "article_index_user_{$user_id}";

        // $provider_codes_desde_pivot_table = false;

        // $guardar_precio_de_otros_proveedores = config('app.GUARDAR_PRECIO_DE_OTROS_PROVEEDORES');
        $guardar_precio_de_otros_proveedores = true;

        // $filtrar_por_proveedor = (
        //     $provider_id
        //     && !$actualizar_articulos_de_otro_proveedor
        // );

        // // Lo pongo siempre en false para que machee todos los productos y deje siempre registro de a que precio lo tiene este proveedor
        // $filtrar_por_proveedor = false;

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

        // if ($filtrar_por_proveedor) {
        //     $article_query->where('provider_id', $provider_id);
        // }

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

                // Log::info('provider_code: '.$article->provider_code);
                // Log::info('provider_id: '.$article->provider_id);


                /*
                    Solo se tienen en cuenta los articulos que tienen codigo de proveedor y que pertenecen a un proveedor
                */
                // if (!$provider_codes_desde_pivot_table) {
                    if (
                        !is_null($article->provider_code)
                        && !is_null($article->provider_id)
                    ) {
                        // Log::info('Entro en provider_codes para hacer index');
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

        Cache::put($key, $index, now()->addMinutes(60));

        $duracion = microtime(true) - $inicio;

        Log::info("ArticleIndexCache::build -> ids: " . count($index['ids']) . " provider_codes: ". count($index['provider_codes']) . " bar_codes: " . count($index['bar_codes']) . " skus: " . count($index['skus']) . " names: " . count($index['names']));
        // Log::info('$index->provider_codes: ');
        // Log::info($index['provider_codes']);
        // Log::info('Duración total cachear los articulos ' . $duracion . ' seg');

        return $duracion;
    }


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

        // if (!is_array($index) || empty($index)) {
        //     self::build($user_id, $provider_id, $actualizar_otro_proveedor);
        //     $index = Cache::get($key, []);
        // }

        if (!is_array($index) || empty($index)) {

            $lock_key = "lock_build_article_index_user_{$user_id}";

            // Si tenés Redis/Database cache, esto evita builds duplicados en paralelo
            try {
                $lock = Cache::lock($lock_key, 300); // 5 min

                if ($lock->get()) {
                    try {
                        // Re-check por si otro lo llenó justo antes
                        $index = Cache::get($key, []);
                        if (!is_array($index) || empty($index)) {
                            self::build($user_id, $provider_id, $actualizar_otro_proveedor);
                            $index = Cache::get($key, []);
                        }
                    } finally {
                        $lock->release();
                    }
                } else {
                    // No obtuve lock: espero a que el otro termine el build
                    $wait_until = microtime(true) + 30; // hasta 30s
                    while (microtime(true) < $wait_until) {
                        usleep(200000); // 200ms
                        $index = Cache::get($key, []);
                        if (is_array($index) && !empty($index)) {
                            break;
                        }
                    }

                    // Si aún está vacío, hago build igual (fallback)
                    if (!is_array($index) || empty($index)) {
                        self::build($user_id, $provider_id, $actualizar_otro_proveedor);
                        $index = Cache::get($key, []);
                    }
                }
            } catch (\Throwable $e) {
                // Si el store no soporta locks o algo falla, fallback al comportamiento actual
                self::build($user_id, $provider_id, $actualizar_otro_proveedor);
                $index = Cache::get($key, []);
            }
        }

        $index = is_array($index) ? $index : [];

        self::$runtime_index_by_key[$key] = $index;
        self::$runtime_loaded_by_key[$key] = true;

        return $index;
    }

    static function log($text) {
        if (config('app.APP_ENV') == 'local' || self::$log_activado) {
            Log::info($text);
        }
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

        Self::log('find_with_index');

        $relations = [
            'price_types',
            'addresses',
            'providers' => function ($q) {
                $q->select('providers.id');
            },
        ];

        $article_id = null;

        if (isset($index['provider_codes'][$provider_id][$data['provider_code']])) {
            
            Self::log('provider_code del provider_id: '.$provider_id);
            Self::log($index['provider_codes'][$provider_id][$data['provider_code']]);
        }

        // 1) ID
        if (!empty($data['id']) && isset($index['ids'][(string)$data['id']])) {
            Self::log('Buscando por id '.$data['id']);
            $article_id = $index['ids'][(string)$data['id']];
        }

        // 2) bar_code
        elseif (!empty($data['bar_code']) && isset($index['bar_codes'][(string)$data['bar_code']])) {
            Self::log('Buscando por bar_code '.$data['bar_code']);
            $article_id = $index['bar_codes'][(string)$data['bar_code']];
        }

        // 3) sku
        elseif (!empty($data['sku']) && isset($index['skus'][(string)$data['sku']])) {
            Self::log('Buscando por sku '.$data['sku']);
            $article_id = $index['skus'][(string)$data['sku']];
        }

        // 4) provider_code
        elseif (!empty($data['provider_code'])) {

            Self::log('Buscando por provider_code '.$data['provider_code']);

            $provider_code = trim((string)$data['provider_code']);
            if ($provider_code === '') {
                return null;
            }

            /* 
                Si se permiten repetidos, NO querés sincronizar por provider_code => modo crear, y permitir codigos repetidos en multi proveedores = true
                Entonces, siempre que se busque por provider_code, se retorna siempre null, para que si o si cree el articulo 
            */
            if (
                $permitir_provider_code_repetido 
                && !$actualizar_por_provider_code
                && $permitir_provider_code_repetido_en_multi_providers
            ) {
                Self::log('Retornando NULL porque: permitir_provider_code_repetido = true y actualizar_por_provider_code = false');
                return null;
            }

            /**
             * IDs encontrados dentro del provider seleccionado en la importación.
             * Se usa para decidir actualización normal.
             */
            $article_ids_same_provider = [];

            /**
             * IDs encontrados en otros providers (distintos al de la importación).
             * Se usa para bloquear creación cuando no se permite actualizar otros providers.
             */
            $article_ids_other_providers = [];

            // Si hay provider_id, primero miramos en ese provider
            if (!is_null($provider_id) && isset($index['provider_codes'][(int)$provider_id][$provider_code])) {
                Self::log('Buscando en los provider_codes del provider_id: '.$provider_id);
                $article_ids_same_provider = array_merge($article_ids_same_provider, Arr::wrap($index['provider_codes'][(int)$provider_id][$provider_code]));
            }

            // Siempre detectamos matches en otros proveedores para aplicar regla de bloqueo de creación.
            foreach ($index['provider_codes'] as $p_id => $codes) {

                // Salteamos provider actual; ese ya se evaluó arriba.
                if (!is_null($provider_id) && (int)$p_id === (int)$provider_id) {
                    continue;
                }

                if (isset($codes[$provider_code])) {
                    $article_ids_other_providers = array_merge($article_ids_other_providers, Arr::wrap($codes[$provider_code]));
                }
            }

            $article_ids_same_provider = array_values(array_unique($article_ids_same_provider));
            $article_ids_other_providers = array_values(array_unique($article_ids_other_providers));

            /**
             * Regla de bloqueo:
             * - Si no hay match en provider actual
             * - Pero sí existe el provider_code en otro provider
             * - Y NO se permite actualizar artículos de otro proveedor
             * - Y NO se permiten codigos de proveedor repetidos en distintos proveedores
             * => No se debe crear ni actualizar.
             *
             * Se devuelve un marcador explícito para que ProcessRow diferencie este caso de "no hubo match".
             */
            if (
                empty($article_ids_same_provider)
                && !empty($article_ids_other_providers)
                && !$actualizar_articulos_de_otro_proveedor
                && !$permitir_provider_code_repetido_en_multi_providers
            ) {
                Self::log('Bloqueado por provider_code existente en otro proveedor');

                return [
                    '__provider_code_blocked_by_other_provider' => true,
                    'provider_code' => $provider_code,
                    'provider_id' => $provider_id,
                    'matched_other_provider_ids' => $article_ids_other_providers,
                ];
            }

            $article_ids = $article_ids_same_provider;

            // Solo si está habilitado, incorporamos los matches de otros providers para actualizar.
            if ($actualizar_articulos_de_otro_proveedor) {
                $article_ids = array_merge($article_ids, $article_ids_other_providers);
            }
            $article_ids = array_values(array_unique($article_ids));

            if (!empty($article_ids)) {

                // Si se permiten codigos repetidos, se retorna un array
                if ($permitir_provider_code_repetido) {
                    Self::log('Retornando array de articles porque se permiten provider_codes repetidos');
                    // Repetidos + sync: mezcla BD + artículos fake pendientes (ids fake_* no existen en articles)
                    return self::collection_from_index_article_ids($article_ids, $relations, $user_id);
                } else {

                    Self::log('Retornando un unico article porque no se permtien provider_codes repetidos');
                    // Un solo resultado: primero intentamos resolver ids mixtos (BD + fake en RAM)
                    $resolved = self::collection_from_index_article_ids($article_ids, $relations, $user_id);

                    return $resolved->first();
                }
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

        // REGLA actualizar_proveedor:
        // si el artículo no pertenece al provider actual y NO querés actualizar proveedor => no lo uses
        // if (
        //     !$actualizar_proveedor
        //     && !is_null($article_provider_id)
        // ) {

        //     $prov_map = $index['article_providers'][$article_id] ?? [];

        //     if (!isset($prov_map[(int)$provider_id])) {
        //         return null;
        //     }
        // }

        // Puede ser id numérico (BD) o fake_* (pendiente de insert en el mismo import)
        if (is_string($article_id) && str_starts_with((string) $article_id, 'fake_')) {

            $from_ram = self::get_runtime_fake_article($user_id, (string) $article_id);

            return $from_ram;
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

        // Usar índice en RAM (memoizado) para NO tocar cache en cada fila
        $index = self::get_index((int)$article->user_id);

        $article_id = $article->fake_id;

        if ($article_id) {

            // Referencia al modelo fake para find_with_index / whereIn no aplica en BD
            if (
                is_string($article_id)
                && str_starts_with($article_id, 'fake_')
            ) {

                $uid = (int) $article->user_id;

                if (!isset(self::$runtime_fake_articles[$uid])) {
                    self::$runtime_fake_articles[$uid] = [];
                }

                self::$runtime_fake_articles[$uid][(string) $article_id] = $article;
            }

            $index['ids'][(string)$article_id] = $article_id;
        }
        if (!empty($article->bar_code)) {
            $index['bar_codes'][(string)$article->bar_code] = $article_id;
        }
        if (!empty($article->sku)) {
            $index['skus'][(string)$article->sku] = $article_id;
        }

        if (!is_null($article->provider_code) && !is_null($article->provider_id)) {

            // Esto podria causar un error
            $prov_id = (string)$article->provider_id;
            
            $prov_code = (string)$article->provider_code;

            if (!isset($index['provider_codes'][$prov_id])) {
                $index['provider_codes'][$prov_id] = [];
            }

            if (!isset($index['provider_codes'][$prov_id][$prov_code])) {
                $index['provider_codes'][$prov_id][$prov_code] = [];
            }
            
            $index['provider_codes'][$prov_id][$prov_code][] = $article_id;
        }

        if (!empty($article->name)) {
            $index['names'][strtolower(trim((string)$article->name))] = $article_id;
        }

        // Guardamos en RAM y marcamos como "dirty" SOLO si querés persistir.
        // OJO: para fake articles NO conviene persistir a cache compartido entre workers.
        self::$runtime_index_by_key[$key] = $index;
        self::$runtime_loaded_by_key[$key] = true;

        // NO Cache::put acá.
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
                str_starts_with((string) $index['bar_codes'][$article->bar_code], 'fake_')) {

                $fake_id_bar = (string) $index['bar_codes'][$article->bar_code];

                self::forget_runtime_fake_article((int) $article->user_id, $fake_id_bar);
                unset($index['ids'][$fake_id_bar]);
                unset($index['bar_codes'][$article->bar_code]);
                $fake_eliminado = true;
                // Log::info('Se elimino del cache bar_code: '.$article->bar_code);
            }
        } 


        if (!$fake_eliminado) {

            if (!empty($article->sku)) {

                if (isset($index['skus'][$article->sku]) &&
                    str_starts_with((string) $index['skus'][$article->sku], 'fake_')) {

                    $fake_id_sku = (string) $index['skus'][$article->sku];

                    self::forget_runtime_fake_article((int) $article->user_id, $fake_id_sku);
                    unset($index['ids'][$fake_id_sku]);
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
                        // múltiples ids → eliminar solo fakes; liberar registro en RAM por cada fake
                        foreach ($entry as $id_en_entry) {

                            if (str_starts_with((string) $id_en_entry, 'fake_')) {
                                self::forget_runtime_fake_article((int) $article->user_id, (string) $id_en_entry);
                            }
                        }

                        $sin_fakes = array_values(array_filter($entry, function ($id) {
                            return !str_starts_with((string) $id, 'fake_');
                        }));

                        if (count($sin_fakes) === 0) {
                            unset($index['provider_codes'][$prov_id][$prov_code]);
                        } else {
                            $index['provider_codes'][$prov_id][$prov_code] = $sin_fakes;
                        }
                        
                        // Log::info('Se eliminaron del cache los provider_code: '.$prov_code);
                        $fake_eliminado = true;

                    } else {
                        // single id
                        if (str_starts_with((string) $entry, 'fake_')) {

                            self::forget_runtime_fake_article((int) $article->user_id, (string) $entry);
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
                    str_starts_with((string) $index['names'][$name_key], 'fake_')) {

                    $fake_id_name = (string) $index['names'][$name_key];

                    self::forget_runtime_fake_article((int) $article->user_id, $fake_id_name);
                    unset($index['ids'][$fake_id_name]);
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

        // Cache::put($key, $index, now()->addMinutes(30));
        // self::$runtime_index_by_key[$key] = $index;
        // self::$runtime_loaded_by_key[$key] = true;

        // NO persistimos por cada artículo (carísimo).
        self::$runtime_index_by_key[$key] = $index;
        self::$runtime_loaded_by_key[$key] = true;
        self::$runtime_dirty_by_key[$key] = true;
    }

    public static function persist(int $user_id, int $ttl_minutes = 30): void
    {
        $key = "article_index_user_{$user_id}";

        if (empty(self::$runtime_loaded_by_key[$key]) || empty(self::$runtime_dirty_by_key[$key])) {
            return;
        }

        Cache::put($key, self::$runtime_index_by_key[$key], now()->addMinutes($ttl_minutes));

        // ya persistido
        self::$runtime_dirty_by_key[$key] = false;
    }

    static function limpiar_cache($user_id) {

        $cache_key = "article_index_user_{$user_id}";
        
        Cache::forget($cache_key);

        Log::info("Cache de importación de artículos limpiado: {$cache_key}");

        unset(self::$runtime_index_by_key[$cache_key]);
        unset(self::$runtime_loaded_by_key[$cache_key]);
        unset(self::$runtime_fake_articles[(int) $user_id]);
    }

}
