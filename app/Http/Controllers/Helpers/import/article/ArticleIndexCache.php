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
     * Escalon de la cadena de identificacion que produjo el ultimo match de
     * find_with_index() ('id'|'bar_code'|'sku'|'provider_code'|'name'|null).
     * Se lee inmediatamente despues de find_with_index() (ver ultimo_escalon()).
     *
     * @var string|null
     */
    protected static $ultimo_escalon = null;

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
        if ($fake_id === '' || strncmp($fake_id, 'fake_', strlen('fake_')) !== 0) {
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

            if (strncmp($as_string, 'fake_', strlen('fake_')) === 0) {

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
     * Devuelve siempre un array de article ids, sirva el indice viejo (escalar)
     * o el nuevo (lista).
     *
     * Compatibilidad: hay índices ya cacheados en producción con el formato viejo
     * (bar_codes/skus como escalar => un solo article_id). Este helper centraliza
     * la lectura para que todo el archivo soporte ambos formatos sin duplicar lógica.
     *
     * Público (no solo protected) porque ProcessRow también lee directamente
     * $index['bar_codes']/$index['skus'] como snapshot en RAM (merge_bar_code_duplicate,
     * bar_code_or_provider_code_already_in_bd) y necesita el mismo soporte de formatos.
     *
     * @param  mixed $entry
     * @return array
     */
    public static function index_entry_to_ids($entry)
    {
        if (is_null($entry)) {
            return [];
        }

        if (is_array($entry)) {
            return array_values(array_unique($entry));
        }

        return [$entry];
    }

    /**
     * Normaliza un nombre para comparacion exacta en el escalon 5 de la cadena.
     *
     * Baja a minusculas con mb_strtolower (strtolower no baja acentos ni Ñ),
     * saca espacios de los bordes y colapsa espacios internos. NADA MAS:
     * no saca acentos, ni puntuacion, ni palabras.
     *
     * El colapso de espacios internos es necesario porque los Excel de proveedor
     * vienen con nombres alineados a mano, del estilo
     * "PARRILLA INFERIOR   IZQUIERDA PEUGEOT" vs "PARRILLA INFERIOR IZQUIERDA PEUGEOT".
     *
     * @param  string $name
     * @return string
     */
    public static function normalize_name_for_match($name)
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower((string) $name, 'UTF-8')
            : strtolower((string) $name);

        return trim(preg_replace('/\s+/u', ' ', $lower));
    }

    /**
     * Clave de cache del indice de importacion.
     *
     * La version se bumpea cuando cambia el FORMATO del indice o la forma de calcular
     * alguna de sus claves, para que los indices viejos que quedaron cacheados no se
     * lean con las reglas nuevas. v2: names pasa a lista y su clave se calcula con
     * normalize_name_for_match() (mb_strtolower + colapso de espacios internos).
     *
     * @param  int $user_id
     * @return string
     */
    protected static function cache_key($user_id)
    {
        return 'article_index_v2_user_' . (int) $user_id;
    }

    /**
     * Resuelve una entrada del indice a un unico articulo.
     *
     * Se usa para bar_code, sku y provider_code: en vez de "adivinar" con el primer
     * id de la lista cuando hay varios candidatos, se devuelve un status 'ambiguous'
     * explícito para que quien llama registre el conflicto y no cree/actualice nada.
     *
     * Devuelve un array asociativo:
     *   ['status' => 'no_match']                 -> no hay candidatos
     *   ['status' => 'match',    'article' => M] -> un unico candidato
     *   ['status' => 'ambiguous','ids' => [...]] -> varios candidatos
     *
     * @param  mixed $article_ids entrada cruda del indice (escalar o array)
     * @param  array $relations relaciones eager para la consulta Eloquent
     * @param  int   $user_id usuario dueño del índice
     * @return array
     */
    protected static function resolve_unique($article_ids, $relations, $user_id)
    {
        $ids = self::index_entry_to_ids($article_ids);

        if (count($ids) === 0) {
            return ['status' => 'no_match'];
        }

        if (count($ids) > 1) {
            return ['status' => 'ambiguous', 'ids' => $ids];
        }

        $resolved = self::collection_from_index_article_ids($ids, $relations, $user_id);

        if ($resolved->count() === 0) {
            return ['status' => 'no_match'];
        }

        if ($resolved->count() > 1) {
            return ['status' => 'ambiguous', 'ids' => $ids];
        }

        return ['status' => 'match', 'article' => $resolved->first()];
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
        $key = self::cache_key($user_id);

        if (empty(self::$runtime_loaded_by_key[$key]) || empty(self::$runtime_index_by_key[$key])) {
            return;
        }

        $index = self::$runtime_index_by_key[$key];

        unset($index['ids'][$fake_id]);

        // bar_codes: cada entrada puede ser escalar (formato viejo) o lista (formato nuevo).
        // Se filtra solo el fake_id puntual; si no quedan ids se borra la entrada entera.
        foreach ($index['bar_codes'] as $bc => $entry) {

            $ids_en_entry = self::index_entry_to_ids($entry);

            $filtrados = array_values(array_filter($ids_en_entry, function ($id_val) use ($fake_id) {
                return (string) $id_val !== $fake_id;
            }));

            if (count($filtrados) === 0) {
                unset($index['bar_codes'][$bc]);
            } else {
                $index['bar_codes'][$bc] = $filtrados;
            }
        }

        // skus: misma lógica que bar_codes.
        foreach ($index['skus'] as $sku => $entry) {

            $ids_en_entry = self::index_entry_to_ids($entry);

            $filtrados = array_values(array_filter($ids_en_entry, function ($id_val) use ($fake_id) {
                return (string) $id_val !== $fake_id;
            }));

            if (count($filtrados) === 0) {
                unset($index['skus'][$sku]);
            } else {
                $index['skus'][$sku] = $filtrados;
            }
        }

        // names: cada entrada puede ser escalar (formato viejo) o lista (formato nuevo,
        // ver index_entry_to_ids). Se filtra solo el fake_id puntual; si no quedan ids
        // se borra la entrada entera.
        foreach ($index['names'] as $name_key => $entry) {

            $ids_en_entry = self::index_entry_to_ids($entry);

            $filtrados = array_values(array_filter($ids_en_entry, function ($id_val) use ($fake_id) {
                return (string) $id_val !== $fake_id;
            }));

            if (count($filtrados) === 0) {
                unset($index['names'][$name_key]);
            } else {
                $index['names'][$name_key] = $filtrados;
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
        $key = self::cache_key($user_id);

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

                /*
                 * bar_codes/skus como lista de ids (no escalar): si dos artículos
                 * comparten bar_code o sku, ambos quedan registrados en vez de que
                 * el último pise al anterior. find_with_index() decide con esa
                 * lista si hay match único o ambigüedad.
                 */
                if (!empty($article->bar_code)) {
                    $bc = (string) $article->bar_code;
                    if (!isset($index['bar_codes'][$bc])) {
                        $index['bar_codes'][$bc] = [];
                    }
                    $index['bar_codes'][$bc][] = $article_id;
                }

                if (!empty($article->sku)) {
                    $sku = (string) $article->sku;
                    if (!isset($index['skus'][$sku])) {
                        $index['skus'][$sku] = [];
                    }
                    $index['skus'][$sku][] = $article_id;
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


                /*
                 * names como lista de ids (no escalar): igual que bar_codes/skus, si dos
                 * articulos comparten nombre normalizado ambos quedan registrados en vez
                 * de que el ultimo pise al anterior. find_with_index() decide con esa
                 * lista si hay match unico o ambiguedad (evita el incidente de Servian).
                 */
                if (!empty($article->name)) {
                    $name_key = self::normalize_name_for_match($article->name);
                    if (!isset($index['names'][$name_key])) {
                        $index['names'][$name_key] = [];
                    }
                    $index['names'][$name_key][] = $article_id;
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

        $key = self::cache_key($user_id);

        return Cache::get($key, []);
    }


    public static function get_index(int $user_id, ?int $provider_id = null, $actualizar_otro_proveedor = null): array
    {
        $key = self::cache_key($user_id);

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

    /**
     * Devuelve el escalon de la cadena que produjo el ultimo match de find_with_index().
     * Valores posibles: id | bar_code | sku | provider_code | name | null.
     *
     * Solo tiene sentido leerlo INMEDIATAMENTE despues de find_with_index() y solo
     * cuando esa llamada devolvio un Article o una Collection: si devolvio null o un
     * AmbiguousMatch, vale null.
     *
     * @return string|null
     */
    public static function ultimo_escalon()
    {
        return self::$ultimo_escalon;
    }

    /**
     * Deja registrado el escalon y devuelve el resultado, para que find_with_index()
     * no tenga ningun return que se olvide de setearlo.
     *
     * @param  string|null $escalon
     * @param  mixed       $resultado
     * @return mixed
     */
    protected static function con_escalon($escalon, $resultado)
    {
        self::$ultimo_escalon = $escalon;

        return $resultado;
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

        /*
         * Escalon que asigno $article_id y cayo al final del metodo (cierre), para que
         * ultimo_escalon() informe correctamente incluso en los returns de cierre.
         * En la practica solo el escalon 1 (id) asigna $article_id y cae al final: los
         * demas escalones (bar_code/sku/provider_code/name) retornan directo desde su
         * propia rama (match, ambiguo o bloqueo), asi que quedan cubiertos por con_escalon()
         * en el punto exacto de cada return.
         */
        $escalon = null;

        // if (!empty($data['provider_code']) && isset($index['provider_codes'][$provider_id][$data['provider_code']])) {
            
        //     Self::log('provider_code del provider_id: '.$provider_id);
        //     Self::log($index['provider_codes'][$provider_id][$data['provider_code']]);
        // }

        // 1) ID
        if (!empty($data['id']) && isset($index['ids'][(string)$data['id']])) {
            Self::log('Buscando por id '.$data['id']);
            $article_id = $index['ids'][(string)$data['id']];
            // Unico escalon que asigna $article_id y cae al cierre: se deja registrado aca.
            $escalon = 'id';
        }

        // 2) bar_code
        elseif (!empty($data['bar_code']) && isset($index['bar_codes'][(string)$data['bar_code']])) {

            Self::log('Buscando por bar_code '.$data['bar_code']);

            $bar_code_resolved = self::resolve_unique(
                $index['bar_codes'][(string)$data['bar_code']],
                $relations,
                $user_id
            );

            if ($bar_code_resolved['status'] === 'ambiguous') {
                Self::log('bar_code ambiguo: '.$data['bar_code'].' matchea con '.count($bar_code_resolved['ids']).' articulos');
                return self::con_escalon(null, new AmbiguousMatch('bar_code', (string) $data['bar_code'], $bar_code_resolved['ids']));
            }

            if ($bar_code_resolved['status'] === 'match') {
                return self::con_escalon('bar_code', $bar_code_resolved['article']);
            }

            // no_match: se deja $article_id sin asignar (queda null), igual que el comportamiento previo.
        }

        // 3) sku
        elseif (!empty($data['sku']) && isset($index['skus'][(string)$data['sku']])) {

            Self::log('Buscando por sku '.$data['sku']);

            $sku_resolved = self::resolve_unique(
                $index['skus'][(string)$data['sku']],
                $relations,
                $user_id
            );

            if ($sku_resolved['status'] === 'ambiguous') {
                Self::log('sku ambiguo: '.$data['sku'].' matchea con '.count($sku_resolved['ids']).' articulos');
                return self::con_escalon(null, new AmbiguousMatch('sku', (string) $data['sku'], $sku_resolved['ids']));
            }

            if ($sku_resolved['status'] === 'match') {
                return self::con_escalon('sku', $sku_resolved['article']);
            }

            // no_match: se deja $article_id sin asignar (queda null), igual que el comportamiento previo.
        }

        // 4) provider_code
        elseif (!empty($data['provider_code'])) {


            $provider_code = trim((string)$data['provider_code']);
            if ($provider_code === '') {
                return self::con_escalon(null, null);
            }

            Self::log('Buscando por provider_code '.$data['provider_code']);

            /*
                El usuario pidió explícitamente no identificar por código de proveedor.
                Antes esta guarda dependía de un && de tres condiciones que casi nunca se
                cumplían las tres a la vez, así que actualizar_por_provider_code = false
                no tenía efecto real. Ahora es una condición independiente: si está en
                false, el paso de provider_code queda deshabilitado, punto, sin importar
                el valor de las otras banderas.
            */
            if (!$actualizar_por_provider_code) {
                Self::log('Paso provider_code deshabilitado: actualizar_por_provider_code = false');
                return self::con_escalon(null, null);
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

            /*
             * Cuando no hay proveedor seleccionado (provider_id = 0 o null),
             * la distinción "mismo proveedor / otro proveedor" no aplica.
             * Todos los matches se tratan como "mismo proveedor" para que el
             * artículo se encuentre y se pueda actualizar normalmente.
             */
            if (is_null($provider_id) || (int)$provider_id === 0) {
                $article_ids_same_provider = array_values(array_unique(
                    array_merge($article_ids_same_provider, $article_ids_other_providers)
                ));
                $article_ids_other_providers = [];
            }

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

                return self::con_escalon(null, [
                    '__provider_code_blocked_by_other_provider' => true,
                    'provider_code' => $provider_code,
                    'provider_id' => $provider_id,
                    'matched_other_provider_ids' => $article_ids_other_providers,
                ]);
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

                    /*
                     * Separar IDs reales de BD (enteros) de IDs fake (pendientes de INSERT en este chunk).
                     * Los fakes existen solo en RAM del proceso actual y no tienen fila real en articles todavía.
                     *
                     * Si TODOS los IDs son fakes → estamos en primera importación sin artículos en BD.
                     * Devolver null para que ProcessRow cree un artículo nuevo por cada fila del Excel.
                     *
                     * Si hay al menos un ID real → estamos en reimportación.
                     * Devolver la colección para que ProcessRow actualice todos los artículos existentes.
                     */
                    $real_ids = array_values(array_filter($article_ids, function ($id) {
                        return strncmp((string) $id, 'fake_', strlen('fake_')) !== 0;
                    }));

                    if (empty($real_ids)) {
                        Self::log('Todos los IDs son fakes (primera importación) - retornando null para crear artículo nuevo');
                        return self::con_escalon(null, null);
                    }

                    Self::log('Hay IDs reales en BD - retornando colección para actualizar');
                    return self::con_escalon('provider_code', self::collection_from_index_article_ids($real_ids, $relations, $user_id));

                } else {

                    /*
                        No se permiten provider_codes repetidos: si hay más de un candidato,
                        antes se elegía el primero en silencio (->first()). Ahora se trata
                        como ambigüedad explícita: no se crea ni se actualiza nada, se
                        reporta el conflicto (ver ProcessRow::registrar_conflicto_ambiguo).
                    */
                    if (count($article_ids) > 1) {
                        Self::log('provider_code ambiguo (repetidos no permitidos): '.$provider_code.' matchea con '.count($article_ids).' articulos');
                        return self::con_escalon(null, new AmbiguousMatch('provider_code', $provider_code, $article_ids));
                    }

                    Self::log('Retornando un unico article porque no se permiten provider_codes repetidos');
                    // Un solo resultado: primero intentamos resolver ids mixtos (BD + fake en RAM)
                    $resolved = self::collection_from_index_article_ids($article_ids, $relations, $user_id);

                    if ($resolved->count() > 1) {
                        Self::log('provider_code ambiguo tras resolver en BD: '.$provider_code);
                        return self::con_escalon(null, new AmbiguousMatch('provider_code', $provider_code, $article_ids));
                    }

                    return self::con_escalon('provider_code', $resolved->first());
                }
            }

            return self::con_escalon(null, null);
        }

        // 5) name
        elseif (!empty($data['name'])) {

            $key_name = self::normalize_name_for_match($data['name']);

            if (isset($index['names'][$key_name])) {

                $name_resolved = self::resolve_unique($index['names'][$key_name], $relations, $user_id);

                if ($name_resolved['status'] === 'ambiguous') {
                    Self::log('name ambiguo: '.$data['name'].' matchea con '.count($name_resolved['ids']).' articulos');
                    return self::con_escalon(null, new AmbiguousMatch('name', (string) $data['name'], $name_resolved['ids']));
                }

                if ($name_resolved['status'] === 'match') {
                    return self::con_escalon('name', $name_resolved['article']);
                }

                /* no_match: se deja $article_id sin asignar, igual que bar_code y sku. */
            }
        }

        // Si no encontramos nada por ID/bar_code/sku/name => crear
        if (!$article_id) {
            return self::con_escalon(null, null);
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
        if (is_string($article_id) && strncmp((string) $article_id, 'fake_', strlen('fake_')) === 0) {

            $from_ram = self::get_runtime_fake_article($user_id, (string) $article_id);

            /*
             * Si el fake no aparece en RAM (caso raro, id inconsistente entre indice y
             * registro fake), no hubo match real: el escalon queda en null, no en el
             * escalon que asigno $article_id.
             */
            return self::con_escalon(is_null($from_ram) ? null : $escalon, $from_ram);
        }

        // $article_id apuntaba a un id de BD, pero el articulo pudo haber sido borrado
        // entre el build del indice y esta fila: en ese caso Eloquent devuelve null y
        // el escalon tiene que quedar en null, no en el escalon que asigno $article_id.
        $article_encontrado = Article::with($relations)->find($article_id);

        return self::con_escalon(is_null($article_encontrado) ? null : $escalon, $article_encontrado);
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
        $key = self::cache_key($article->user_id);

        // Usar índice en RAM (memoizado) para NO tocar cache en cada fila
        $index = self::get_index((int)$article->user_id);

        $article_id = $article->fake_id;

        if ($article_id) {

            // Referencia al modelo fake para find_with_index / whereIn no aplica en BD
            if (
                is_string($article_id)
                && strncmp($article_id, 'fake_', strlen('fake_')) === 0
            ) {

                $uid = (int) $article->user_id;

                if (!isset(self::$runtime_fake_articles[$uid])) {
                    self::$runtime_fake_articles[$uid] = [];
                }

                self::$runtime_fake_articles[$uid][(string) $article_id] = $article;
            }

            $index['ids'][(string)$article_id] = $article_id;
        }
        // bar_codes/skus en formato lista: se acumula en vez de pisar.
        if (!empty($article->bar_code)) {
            $bc = (string) $article->bar_code;
            $ids_bc = self::index_entry_to_ids($index['bar_codes'][$bc] ?? null);
            $ids_bc[] = $article_id;
            $index['bar_codes'][$bc] = array_values(array_unique($ids_bc));
        }
        if (!empty($article->sku)) {
            $sku = (string) $article->sku;
            $ids_sku = self::index_entry_to_ids($index['skus'][$sku] ?? null);
            $ids_sku[] = $article_id;
            $index['skus'][$sku] = array_values(array_unique($ids_sku));
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

        // names en formato lista: se acumula en vez de pisar (misma logica que bar_codes/skus).
        if (!empty($article->name)) {
            $name_key = self::normalize_name_for_match($article->name);
            $ids_name = self::index_entry_to_ids($index['names'][$name_key] ?? null);
            $ids_name[] = $article_id;
            $index['names'][$name_key] = array_values(array_unique($ids_name));
        }

        // Guardamos en RAM y marcamos como "dirty" SOLO si querés persistir.
        // OJO: para fake articles NO conviene persistir a cache compartido entre workers.
        self::$runtime_index_by_key[$key] = $index;
        self::$runtime_loaded_by_key[$key] = true;

        // NO Cache::put acá.
    }


    public static function update(Article $article, $codigos_proveedor_repetidos)
    {
        $key = self::cache_key($article->user_id);
        $index = Cache::get($key);

        // if (!$index) {
        //     self::build($article->user_id, null, false);
        //     $index = Cache::get($key);
        // }

        /** ------------------------------------------------------------------
         *  1) ELIMINAR SOLO EL fake QUE COINCIDE CON EL ARTÍCULO REAL
         * ------------------------------------------------------------------ */

        $fake_eliminado = false;

        // a) Si existe fake en bar_codes (entrada puede ser escalar viejo o lista nueva)
        if (!empty($article->bar_code)) {

            $bc = (string) $article->bar_code;

            if (isset($index['bar_codes'][$bc])) {

                $ids_en_entry = self::index_entry_to_ids($index['bar_codes'][$bc]);

                $fakes_en_entry = array_values(array_filter($ids_en_entry, function ($id_val) {
                    return strncmp((string) $id_val, 'fake_', strlen('fake_')) === 0;
                }));

                if (count($fakes_en_entry) > 0) {

                    foreach ($fakes_en_entry as $fid) {
                        self::forget_runtime_fake_article((int) $article->user_id, (string) $fid);
                        unset($index['ids'][$fid]);
                    }

                    $sin_fakes = array_values(array_filter($ids_en_entry, function ($id_val) {
                        return strncmp((string) $id_val, 'fake_', strlen('fake_')) !== 0;
                    }));

                    if (count($sin_fakes) === 0) {
                        unset($index['bar_codes'][$bc]);
                    } else {
                        $index['bar_codes'][$bc] = $sin_fakes;
                    }

                    $fake_eliminado = true;
                    // Log::info('Se elimino del cache bar_code: '.$bc);
                }
            }
        }


        if (!$fake_eliminado) {

            if (!empty($article->sku)) {

                $sku = (string) $article->sku;

                if (isset($index['skus'][$sku])) {

                    $ids_en_entry = self::index_entry_to_ids($index['skus'][$sku]);

                    $fakes_en_entry = array_values(array_filter($ids_en_entry, function ($id_val) {
                        return strncmp((string) $id_val, 'fake_', strlen('fake_')) === 0;
                    }));

                    if (count($fakes_en_entry) > 0) {

                        foreach ($fakes_en_entry as $fid) {
                            self::forget_runtime_fake_article((int) $article->user_id, (string) $fid);
                            unset($index['ids'][$fid]);
                        }

                        $sin_fakes = array_values(array_filter($ids_en_entry, function ($id_val) {
                            return strncmp((string) $id_val, 'fake_', strlen('fake_')) !== 0;
                        }));

                        if (count($sin_fakes) === 0) {
                            unset($index['skus'][$sku]);
                        } else {
                            $index['skus'][$sku] = $sin_fakes;
                        }

                        $fake_eliminado = true;
                        // Log::info('Se elimino del cache sku: '.$sku);
                    }
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

                            if (strncmp((string) $id_en_entry, 'fake_', strlen('fake_')) === 0) {
                                self::forget_runtime_fake_article((int) $article->user_id, (string) $id_en_entry);
                            }
                        }

                        $sin_fakes = array_values(array_filter($entry, function ($id) {
                            return strncmp((string) $id, 'fake_', strlen('fake_')) !== 0;
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
                        if (strncmp((string) $entry, 'fake_', strlen('fake_')) === 0) {

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

        // clave normalizada del nombre (mb_strtolower + colapso de espacios internos)
        $name_key = self::normalize_name_for_match($article->name);
        if (!$fake_eliminado) {

            // c) Si existe fake en names (entrada puede ser escalar viejo o lista nueva):
            // se filtran solo los ids fake_*, se conservan los reales.
            if (!empty($article->name) && isset($index['names'][$name_key])) {

                $ids_en_entry = self::index_entry_to_ids($index['names'][$name_key]);

                $fakes_en_entry = array_values(array_filter($ids_en_entry, function ($id_val) {
                    return strncmp((string) $id_val, 'fake_', strlen('fake_')) === 0;
                }));

                if (count($fakes_en_entry) > 0) {

                    foreach ($fakes_en_entry as $fid) {
                        self::forget_runtime_fake_article((int) $article->user_id, (string) $fid);
                        unset($index['ids'][$fid]);
                    }

                    $sin_fakes = array_values(array_filter($ids_en_entry, function ($id_val) {
                        return strncmp((string) $id_val, 'fake_', strlen('fake_')) !== 0;
                    }));

                    if (count($sin_fakes) === 0) {
                        unset($index['names'][$name_key]);
                    } else {
                        $index['names'][$name_key] = $sin_fakes;
                    }

                    $fake_eliminado = true;
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
            // Formato lista: se acumula en vez de pisar (ver index_entry_to_ids/resolve_unique).
            $bc = (string) $article->bar_code;
            $ids_bc = self::index_entry_to_ids($index['bar_codes'][$bc] ?? null);
            $ids_bc[] = $article->id;
            $index['bar_codes'][$bc] = array_values(array_unique($ids_bc));
        }

        if (!empty($article->name)) {
            // Formato lista: se acumula en vez de pisar (misma logica que bar_codes/skus).
            $ids_name = self::index_entry_to_ids($index['names'][$name_key] ?? null);
            $ids_name[] = $article->id;
            $index['names'][$name_key] = array_values(array_unique($ids_name));
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

    /**
     * Persiste el indice al cache compartido, fusionando con lo que ya haya.
     *
     * No se puede hacer un Cache::put del indice local a secas: con varios
     * workers, cada uno tiene una vista parcial y el ultimo en escribir
     * borraria lo que agregaron los demas (last-write-wins). Por eso se lee
     * el estado actual del cache compartido y se fusiona (merge_indexes) con
     * la vista local antes de escribir.
     *
     * @param  int $user_id dueño del indice
     * @param  int $ttl_minutes minutos de vida del cache tras persistir
     * @return void
     */
    public static function persist(int $user_id, int $ttl_minutes = 30): void
    {
        // clave del cache compartido para este usuario
        $key = self::cache_key($user_id);

        // Si no hay indice cargado en RAM o no tiene cambios sin persistir, no hay nada que hacer.
        if (empty(self::$runtime_loaded_by_key[$key]) || empty(self::$runtime_dirty_by_key[$key])) {
            return;
        }

        // vista local (RAM de este proceso/worker) con los cambios del lote actual
        $local = self::$runtime_index_by_key[$key];

        // estado actual del cache compartido, escrito potencialmente por otros workers
        $remoto = Cache::get($key, []);

        // fusion de ambos indices respetando la forma de cada sub-indice
        $merged = self::merge_indexes($remoto, $local);

        Cache::put($key, $merged, now()->addMinutes($ttl_minutes));

        // La RAM local pasa a reflejar el indice fusionado (incluye lo que agregaron otros workers).
        self::$runtime_index_by_key[$key] = $merged;

        // ya persistido
        self::$runtime_dirty_by_key[$key] = false;
    }

    /**
     * Fusiona dos indices de importacion.
     *
     * - ids: mapa escalar. Gana el nuevo.
     * - bar_codes, skus, names: listas de article ids. Se unen (names desde el prompt 01
     *   del grupo 232: antes era escalar y "ganaba el nuevo", ahora se acumula igual que
     *   bar_codes/skus para no perder candidatos con nombre repetido).
     * - provider_codes: mapa provider_id -> codigo -> lista de ids. Se unen.
     *
     * Los article ids "fake_*" NO se propagan: son locales al proceso que los creo
     * y no tienen sentido en un cache compartido entre workers.
     *
     * @param  array $base indice existente en el cache compartido (u otro origen)
     * @param  array $nuevo indice local a fusionar sobre el base
     * @return array indice fusionado
     */
    protected static function merge_indexes(array $base, array $nuevo): array
    {
        // resultado acumulado, arranca como copia del indice base (remoto)
        $out = $base;

        /* ids: escalar. */
        foreach (['ids'] as $seccion) {
            if (!isset($nuevo[$seccion])) {
                continue;
            }
            if (!isset($out[$seccion])) {
                $out[$seccion] = [];
            }
            foreach ($nuevo[$seccion] as $k => $v) {
                if (self::is_fake_id($v)) {
                    continue;
                }
                $out[$seccion][$k] = $v;
            }
        }

        /* bar_codes, skus y names: listas de ids. */
        foreach (['bar_codes', 'skus', 'names'] as $seccion) {
            if (!isset($nuevo[$seccion])) {
                continue;
            }
            if (!isset($out[$seccion])) {
                $out[$seccion] = [];
            }
            foreach ($nuevo[$seccion] as $codigo => $entry) {
                $ids_nuevos = self::index_entry_to_ids($entry);
                $ids_base   = isset($out[$seccion][$codigo])
                                ? self::index_entry_to_ids($out[$seccion][$codigo])
                                : [];

                $union = array_merge($ids_base, $ids_nuevos);
                $union = array_values(array_unique(array_filter($union, function ($id) {
                    return !self::is_fake_id($id);
                })));

                if (count($union) > 0) {
                    $out[$seccion][$codigo] = $union;
                }
            }
        }

        /* provider_codes: provider_id -> codigo -> lista. */
        if (isset($nuevo['provider_codes'])) {
            if (!isset($out['provider_codes'])) {
                $out['provider_codes'] = [];
            }
            foreach ($nuevo['provider_codes'] as $prov_id => $codigos) {
                if (!isset($out['provider_codes'][$prov_id])) {
                    $out['provider_codes'][$prov_id] = [];
                }
                foreach ($codigos as $codigo => $ids) {
                    $ids_nuevos = self::index_entry_to_ids($ids);
                    $ids_base   = isset($out['provider_codes'][$prov_id][$codigo])
                                    ? self::index_entry_to_ids($out['provider_codes'][$prov_id][$codigo])
                                    : [];

                    $union = array_merge($ids_base, $ids_nuevos);
                    $union = array_values(array_unique(array_filter($union, function ($id) {
                        return !self::is_fake_id($id);
                    })));

                    if (count($union) > 0) {
                        $out['provider_codes'][$prov_id][$codigo] = $union;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Indica si un id de articulo corresponde a un "fake" (pendiente de insert en el
     * proceso actual, sin fila real en la tabla articles todavia).
     *
     * @param  mixed $id
     * @return bool
     */
    protected static function is_fake_id($id)
    {
        return is_string($id) && strncmp($id, 'fake_', 5) === 0;
    }

    /*
     * NOTA DE INFRAESTRUCTURA
     *
     * Estos fixes hacen que el indice sea correcto con varios workers, pero la
     * importacion sigue siendo mas rapida y mas segura con UN SOLO worker por
     * subdominio: los lotes van en Bus::chain (secuenciales), asi que workers
     * extra no aceleran nada y solo agregan riesgo de indice desincronizado.
     *
     * En supervisor: numprocs = 1 por cada api_<subdominio>_queue.
     */

    /**
     * Descarta el indice memoizado en RAM, forzando que la proxima lectura
     * vaya al cache compartido.
     *
     * Se llama al inicio de cada lote: con varios workers, la RAM de un proceso
     * no refleja lo que hicieron los otros, y confiar en ella hace que se creen
     * articulos duplicados.
     *
     * NO borra el cache compartido. Solo la copia local.
     *
     * @param  int $user_id
     * @return void
     */
    public static function reset_runtime(int $user_id): void
    {
        // clave del cache compartido / RAM local para este usuario
        $key = self::cache_key($user_id);

        /* Si hay cambios sin persistir, persistirlos antes de descartar. */
        if (!empty(self::$runtime_dirty_by_key[$key])) {
            self::persist($user_id, 30);
        }

        unset(self::$runtime_index_by_key[$key]);
        unset(self::$runtime_loaded_by_key[$key]);
        unset(self::$runtime_dirty_by_key[$key]);
        unset(self::$runtime_fake_articles[$user_id]);
    }

    /**
     * Descarta TODO el estado de runtime de la clase: indice memoizado, banderas de carga,
     * banderas de cambios sin persistir, articulos fake pendientes y ultimo escalon.
     *
     * Uso EXCLUSIVO de los tests. En produccion no hace falta: cada job arranca con
     * un proceso limpio y la memoizacion dura lo que dura la importacion, que es
     * justamente lo que se busca para no releer el indice en cada fila.
     *
     * PHPUnit, en cambio, reutiliza el mismo proceso PHP para todos los tests, asi
     * que sin este reset el test N ve el indice que armo el test N-1. Un Cache::flush()
     * NO alcanza: get_index() corta al principio si $runtime_loaded_by_key ya esta
     * seteado y devuelve el indice memoizado sin volver a mirar la cache.
     *
     * NO confundir con reset_runtime(int $user_id), que es codigo de produccion y hace
     * otra cosa: descarta solo la clave de UN usuario, persiste antes de descartar si
     * hay cambios sin guardar, y no toca $ultimo_escalon. Para un test eso es al reves
     * de lo que se necesita: hay que tirar todo y no escribir nada.
     *
     * @return void
     */
    public static function reset_runtime_de_tests()
    {
        self::$runtime_index_by_key  = [];
        self::$runtime_loaded_by_key = [];
        self::$runtime_dirty_by_key  = [];
        self::$runtime_fake_articles = [];
        self::$ultimo_escalon        = null;
    }

    static function limpiar_cache($user_id) {

        $cache_key = self::cache_key($user_id);
        
        Cache::forget($cache_key);

        Log::info("Cache de importación de artículos limpiado: {$cache_key}");

        unset(self::$runtime_index_by_key[$cache_key]);
        unset(self::$runtime_loaded_by_key[$cache_key]);
        unset(self::$runtime_fake_articles[(int) $user_id]);
    }

}
