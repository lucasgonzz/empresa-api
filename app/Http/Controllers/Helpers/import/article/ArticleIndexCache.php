<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /** @var array Ver ultimos_identificadores_pendientes(). */
    protected static $ultimo_identificadores_pendientes = [];

    /**
     * Resultado del desempate por nombre de la ultima llamada a find_with_index(),
     * SOLO cuando el usuario lo pidio ($desempatar_por_nombre = true) y NO alcanzo
     * para quedarse con un unico articulo. Ver ultimo_desempate_sin_resolver().
     *
     * Vacio ([]) cuando el desempate no se pidio, no hizo falta, o resolvio bien.
     *
     * @var array
     */
    protected static $ultimo_desempate_sin_resolver = [];

    /**
     * Registro en RAM de modelos Article "fake" pendientes de persistir (por user_id y fake_id).
     * Permite que find_with_index devuelva el mismo artículo aún sin fila en BD (whereIn(id) vacío).
     *
     * Estructura: [user_id][fake_id] => Article
     */
    protected static $runtime_fake_articles = [];

    /**
     * Contexto de la importación en curso (misión importacion-excel-motor-rapido, 24/9/2026):
     * ruta del archivo de claves del CSV (<csv>.claves, lo escribe
     * InitExcelImport::escribir_claves_del_archivo()) y sufijo de la clave de cache
     * ("_imp<import_history_id>"). Lo pone ProcessArticleChunk::handle() antes de
     * reset_runtime() y lo saca limpiar_cache() al terminar o fallar la importación. Con
     * contexto puesto, build() construye el índice SÓLO con las claves del archivo (ver
     * poblar_indice_acotado()); sin contexto —o si el archivo de claves no existe— es el
     * índice completo de siempre.
     *
     * @var string|null
     */
    protected static $contexto_claves_path = null;

    /** @var string|null Ver $contexto_claves_path. */
    protected static $contexto_sufijo = null;

    /**
     * Modelos precargados por lote (misión importacion-excel-motor-rapido, 24/9/2026):
     * [user_id][article_id] => Article con las relaciones de relaciones_de_precarga(), o null
     * si el id se pidió y no existe (borrado entre el índice y el lote). Lo llena
     * precargar_modelos() antes del loop de filas y lo sirve collection_from_index_article_ids()
     * cuando TODOS los ids pedidos están acá; si falta alguno, consulta como siempre. Se
     * descarta en reset_runtime() (cada lote precarga los suyos).
     *
     * @var array
     */
    protected static $modelos_precargados = [];

    /**
     * Tamaño de cada whereIn del índice acotado. Mismo tope y mismo porqué que
     * ExcelDuplicateStats::DB_CHUNK_SIZE: por debajo de `eq_range_index_dive_limit` (200 en
     * MySQL 8) el optimizador estima con index dives y elige el índice por columna.
     */
    const CLAVES_POR_CONSULTA = 100;

    /** Tamaño de cada whereIn de precargar_modelos(). */
    const MODELOS_POR_CONSULTA = 500;

    /**
     * Uso EXCLUSIVO de los tests: con true, build() ignora el archivo de claves y construye el
     * índice completo aunque haya contexto de importación. Es lo que permite importar el mismo
     * fixture de las dos maneras y comparar el resultado (IndiceAcotadoAlArchivoTest).
     *
     * @var bool
     */
    protected static $forzar_indice_completo_de_tests = false;

    /**
     * Modo del último build() de este proceso ('completo' | 'acotado' | null): observabilidad
     * para los tests y para diagnosticar; el log lo dice también.
     *
     * @var string|null
     */
    protected static $ultimo_modo_de_build = null;

    /**
     * @param  bool $forzar
     * @return void
     */
    public static function forzar_indice_completo_de_tests($forzar)
    {
        self::$forzar_indice_completo_de_tests = (bool) $forzar;
    }

    /**
     * @return string|null
     */
    public static function ultimo_modo_de_build()
    {
        return self::$ultimo_modo_de_build;
    }


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
     * Precarga por lote los modelos de los artículos que las filas del lote pueden matchear
     * (misión importacion-excel-motor-rapido, 24/9/2026), con las relaciones que ProcessRow
     * lee por fila. Una consulta por cada MODELOS_POR_CONSULTA ids en vez de cuatro consultas
     * por fila con match (find_with_index() cargaba cada artículo con sus tres relaciones al
     * momento de matchear) más un load() por fila de descuentos y otro de recargos.
     *
     * Los ids que se piden y no existen (borrados entre el índice y el lote, o soft-deleted)
     * quedan anotados como null: "consultado y ausente", para no volver a consultarlos por
     * fila. Los ids fake_* se ignoran (viven en RAM, no en la base).
     *
     * @param  int   $user_id
     * @param  array $article_ids
     * @param  array $relations  normalmente relaciones_de_precarga()
     * @return int   cantidad de modelos precargados
     */
    public static function precargar_modelos(int $user_id, array $article_ids, array $relations)
    {
        if (!isset(self::$modelos_precargados[$user_id])) {
            self::$modelos_precargados[$user_id] = [];
        }

        $pendientes = [];

        foreach ($article_ids as $raw_id) {
            if (self::is_fake_id($raw_id) || !is_numeric($raw_id)) {
                continue;
            }

            $id = (int) $raw_id;

            if ($id <= 0 || array_key_exists($id, self::$modelos_precargados[$user_id])) {
                continue;
            }

            $pendientes[$id] = true;
        }

        $cargados = 0;

        foreach (array_chunk(array_keys($pendientes), self::MODELOS_POR_CONSULTA) as $lote) {
            $modelos = Article::with($relations)->whereIn('id', $lote)->get();

            foreach ($lote as $id) {
                self::$modelos_precargados[$user_id][$id] = null;
            }

            foreach ($modelos as $modelo) {
                self::$modelos_precargados[$user_id][(int) $modelo->id] = $modelo;
                $cargados++;
            }
        }

        return $cargados;
    }

    /**
     * Ids REALES del índice que las filas de un lote pueden matchear, con los mismos
     * normalizadores que usa find_with_index() (IdentifierNormalizer para numero / bar_code /
     * sku / provider_code, normalize_name_for_match() para el nombre). Es lo que
     * ArticleImport::collection() precarga antes del loop.
     *
     * Superconjunto a propósito: incluye todos los candidatos de todas las secciones aunque
     * la cascada corte antes (un ambiguo, un provider_code bloqueado). Un modelo precargado de
     * más no cambia ningún resultado; consultarlo por fila, sí cuesta.
     *
     * @param  iterable $rows     filas del lote (arrays del CSV)
     * @param  array    $columns  mapa columna => índice 0-based (el de ProcessRow)
     * @param  array    $index    el índice de get_index()
     * @return int[]
     */
    public static function ids_candidatos_de_filas($rows, array $columns, array $index)
    {
        $ids = [];

        $agregar = function ($entry) use (&$ids) {
            foreach (self::index_entry_to_ids($entry) as $id) {
                if (!self::is_fake_id($id)) {
                    $ids[(int) $id] = true;
                }
            }
        };

        $identificadores = [
            'numero'              => 'ids',
            'codigo_de_barras'    => 'bar_codes',
            'sku'                 => 'skus',
            'codigo_de_proveedor' => 'provider_codes',
        ];

        foreach ($rows as $row) {
            foreach ($identificadores as $columna => $seccion) {
                $valor = IdentifierNormalizer::normalize(ImportHelper::getColumnValue($row, $columna, $columns));

                if (is_null($valor)) {
                    continue;
                }

                if ($seccion === 'ids') {
                    if (isset($index['ids'][(string) $valor])) {
                        $agregar($index['ids'][(string) $valor]);
                    }

                    continue;
                }

                if ($seccion === 'provider_codes') {
                    /* Todos los proveedores: find_with_index() mira los ajenos para la regla de bloqueo. */
                    foreach ($index['provider_codes'] ?? [] as $codigos) {
                        if (is_array($codigos) && isset($codigos[$valor])) {
                            $agregar($codigos[$valor]);
                        }
                    }

                    continue;
                }

                if (isset($index[$seccion][$valor])) {
                    $agregar($index[$seccion][$valor]);
                }
            }

            $nombre = ImportHelper::getColumnValue($row, 'nombre', $columns);

            if (!is_null($nombre)) {
                $clave = self::normalize_name_for_match($nombre);

                if ($clave !== '' && isset($index['names'][$clave])) {
                    $agregar($index['names'][$clave]);
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Los modelos pedidos, desde el mapa precargado, o null si alguno no está en el mapa (y
     * entonces el llamador consulta como siempre). Los anotados como ausentes (null en el
     * mapa) se saltean: la consulta tampoco los devolvería.
     *
     * Ordenados por id ascendente, que es el orden en que MySQL devuelve un whereIn sobre la
     * clave primaria: el orden importa para las Collection que find_with_index() devuelve
     * con provider_code repetido y para los article_ids de los conflictos.
     *
     * @param  int   $user_id
     * @param  array $db_ids
     * @return \Illuminate\Support\Collection|null
     */
    protected static function modelos_precargados_para(int $user_id, array $db_ids)
    {
        if (empty(self::$modelos_precargados[$user_id])) {
            return null;
        }

        $mapa = self::$modelos_precargados[$user_id];
        $ids  = [];

        foreach ($db_ids as $raw_id) {
            $id = (int) $raw_id;

            if (!array_key_exists($id, $mapa)) {
                return null;
            }

            $ids[$id] = true;
        }

        $ids = array_keys($ids);
        sort($ids);

        $out = collect();

        foreach ($ids as $id) {
            if ($mapa[$id] instanceof Article) {
                $out->push($mapa[$id]);
            }
        }

        return $out;
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
            $desde_el_mapa = self::modelos_precargados_para($user_id, $db_ids);

            if (!is_null($desde_el_mapa)) {
                $out = $out->merge($desde_el_mapa);
            } else {
                $out = $out->merge(Article::with($relations)->whereIn('id', $db_ids)->get());
            }
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
        $clave = 'article_index_v2_user_' . (int) $user_id;

        /*
         * Con contexto de importación (ver $contexto_claves_path), la clave lleva el sufijo de
         * ESA importación: un índice acotado a las claves de un archivo no le sirve a otra
         * importación del mismo comercio, y la de siempre (60 minutos) las haría compartirlo.
         */
        if (!is_null(self::$contexto_sufijo) && self::$contexto_sufijo !== '') {
            $clave .= '_imp' . self::$contexto_sufijo;
        }

        return $clave;
    }

    /**
     * Clave donde queda anotado el sufijo vigente del usuario, para que limpiar_cache() pueda
     * borrar también la clave con sufijo desde un proceso que no tiene el contexto en memoria
     * (el failed() de un job corre en un proceso fresco).
     *
     * @param  int $user_id
     * @return string
     */
    protected static function clave_del_sufijo($user_id)
    {
        return 'article_index_v2_user_' . (int) $user_id . '_sufijo';
    }

    /**
     * Pone (o saca, con null) el contexto de la importación en curso: la ruta del archivo de
     * claves del CSV y el sufijo de la clave de cache. Lo llama ProcessArticleChunk::handle()
     * en cada lote, ANTES de reset_runtime().
     *
     * @param  string|null $claves_path  <csv>.claves (puede no existir: entonces build() es el completo)
     * @param  string|null $sufijo       import_history_id como string
     * @return void
     */
    public static function set_contexto_de_importacion($claves_path, $sufijo)
    {
        self::$contexto_claves_path = (is_string($claves_path) && $claves_path !== '') ? $claves_path : null;
        self::$contexto_sufijo      = (is_string($sufijo) && $sufijo !== '') ? $sufijo : null;
    }

    /**
     * @return array ['claves_path' => string|null, 'sufijo' => string|null]
     */
    public static function contexto_de_importacion()
    {
        return [
            'claves_path' => self::$contexto_claves_path,
            'sufijo'      => self::$contexto_sufijo,
        ];
    }

    /**
     * Relaciones con las que llegan los modelos que devuelve find_with_index(): las tres de
     * siempre (price_types, addresses con su pivot, providers sólo id) más descuentos y
     * recargos, que ProcessRow leía con un load() por fila. Es la lista que usa
     * precargar_modelos(); find_with_index() sigue pidiendo las tres de siempre y, cuando el
     * modelo viene del mapa precargado, trae también las otras dos.
     *
     * @return array
     */
    public static function relaciones_de_precarga()
    {
        return [
            'price_types',
            'addresses',
            'providers' => function ($q) {
                $q->select('providers.id');
            },
            'article_discounts',
            'article_surchages',
        ];
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


    /**
     * Construye el índice de identificación del comercio y lo deja en cache.
     *
     * Con contexto de importación (ver $contexto_claves_path) y el archivo de claves presente,
     * el índice se arma SÓLO con las claves del archivo (poblar_indice_acotado()): el costo pasa
     * a depender del tamaño del archivo y no del catálogo. Sin contexto, o si el archivo de
     * claves no existe, es el índice completo de siempre (poblar_indice_completo()). La forma
     * del índice es la misma en los dos casos.
     *
     * @param  int      $user_id
     * @param  int|null $provider_id
     * @param  mixed    $actualizar_articulos_de_otro_proveedor  (sin uso desde que el filtro por proveedor se descartó)
     * @return float    duración en segundos
     */
    public static function build($user_id, $provider_id, $actualizar_articulos_de_otro_proveedor)
    {
        $inicio = microtime(true);

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
            /*
             * article_id (real) => import_history_id que lo creo (incidente Servian,
             * grupo 294). Solo lo llenan articulos creados DURANTE una importacion (ver
             * update()); los que ya existian antes de que arrancara nunca entran aca, y
             * build() siempre parte de cero porque lee la tabla articles sin este dato.
             */
            'created_by_import' => [],
        ];

        /*
         * Bucket de codigos de proveedor SIN proveedor asignado (decision de Lucas,
         * 24/8/2026). Hasta hoy un articulo con provider_code pero provider_id NULL
         * era invisible al escalon provider_code, asi que la fila que tenia que
         * actualizarlo creaba un duplicado en CADA importacion. Ahora se indexa en un
         * bucket propio (clave '': PHP convierte la clave null a cadena vacia).
         *
         * Va acotado a las importaciones que NO eligieron proveedor, y eso no es un
         * detalle: con provider_id null find_with_index() ya mete todos los buckets en
         * la bolsa de "mismo proveedor", asi que el bucket entra solo por ese merge, sin
         * tocar nada mas. Si se indexara tambien con proveedor elegido caeria en "otros
         * proveedores" y, con actualizar_articulos_de_otro_proveedor = false y
         * permitir_provider_code_repetido_en_multi_providers = false, bloquearia filas
         * que hoy se crean.
         */
        $indexar_sin_proveedor = (is_null($provider_id) || (int) $provider_id === 0);

        if ($indexar_sin_proveedor) {
            /*
             * Se crea la clave aunque quede vacia: es la marca que update() mira para
             * saber que ESTA importacion no eligio proveedor (update() no recibe el
             * provider_id de la importacion, solo el articulo).
             */
            $index['provider_codes'][''] = [];
        }

        $claves = self::claves_del_archivo_del_contexto();

        if (!is_null($claves)) {
            self::poblar_indice_acotado($index, $user_id, $claves, $indexar_sin_proveedor);
        } else {
            self::poblar_indice_completo($index, $user_id, $indexar_sin_proveedor);
        }

        return self::guardar_indice_construido($key, $index, $user_id, $inicio, is_null($claves) ? 'completo' : 'acotado');
    }

    /**
     * Claves del archivo de la importación en curso, o null si no hay contexto, el archivo no
     * existe o no se puede leer (en los tres casos build() es el completo de siempre).
     *
     * @return array|null ['ids' => [...], 'bar_codes' => [...], 'skus' => [...], 'provider_codes' => [...], 'names' => [...]]
     */
    protected static function claves_del_archivo_del_contexto()
    {
        if (self::$forzar_indice_completo_de_tests) {
            return null;
        }

        if (is_null(self::$contexto_claves_path) || !is_file(self::$contexto_claves_path)) {
            return null;
        }

        $contenido = @file_get_contents(self::$contexto_claves_path);

        if ($contenido === false || $contenido === '') {
            return null;
        }

        $claves = @unserialize($contenido, ['allowed_classes' => false]);

        if (!is_array($claves)) {
            Log::warning('ArticleIndexCache: el archivo de claves no se pudo leer; se construye el índice completo', [
                'claves_path' => self::$contexto_claves_path,
            ]);

            return null;
        }

        foreach (['ids', 'bar_codes', 'skus', 'provider_codes', 'names'] as $seccion) {
            if (!isset($claves[$seccion]) || !is_array($claves[$seccion])) {
                $claves[$seccion] = [];
            }
        }

        return $claves;
    }

    /**
     * El índice de siempre: todo el catálogo del comercio, en tandas de 2.000 por id.
     *
     * @param  array $index                  por referencia
     * @param  int   $user_id
     * @param  bool  $indexar_sin_proveedor
     * @return void
     */
    protected static function poblar_indice_completo(array &$index, $user_id, $indexar_sin_proveedor)
    {
        // 1) Index liviano desde articles (SIN with(providers), SIN get() gigante)
        $article_query = Article::where('user_id', $user_id)
            ->select(['id', 'bar_code', 'sku', 'name', 'provider_code', 'provider_id'])
            ->orderBy('id');

        // if ($filtrar_por_proveedor) {
        //     $article_query->where('provider_id', $provider_id);
        // }

        $article_query->chunkById(2000, function ($articles) use (&$index, $indexar_sin_proveedor) {
            foreach ($articles as $article) {
                self::indexar_articulo($index, $article, $indexar_sin_proveedor);
            }
        });
    }

    /**
     * El índice acotado al archivo (misión importacion-excel-motor-rapido, 24/9/2026): sólo
     * los artículos del comercio que alguna fila del archivo puede matchear.
     *
     * Por cada sección de claves del archivo, un whereIn por lotes de CLAVES_POR_CONSULTA con
     * TODOS los valores como string (array_map('strval')): el 23/9/2026 un int suelto en un IN
     * sobre provider_code hizo que MySQL comparara numéricamente, no pudiera usar el índice y
     * examinara 686.000 filas (corte de Servian). provider_code se busca en TODOS los
     * proveedores: find_with_index() necesita los de otros proveedores para la regla de
     * bloqueo, y el bucket '' sigue la misma regla que en el índice completo. Los nombres van
     * aparte: una pasada por (id, name) del comercio en tandas de 5.000, filtrando en PHP por
     * el conjunto de nombres normalizados del archivo (slug/nombre no tienen índice utilizable
     * para esto, y la comparación es por normalize_name_for_match(), que no se puede expresar
     * en SQL); sólo si el archivo trae nombres.
     *
     * Cada artículo encontrado se indexa entero (todos sus identificadores), una sola vez, con
     * las MISMAS reglas que el índice completo: por eso la forma del índice y lo que
     * find_with_index() devuelve para las claves del archivo son idénticos en los dos modos.
     *
     * @param  array $index                  por referencia
     * @param  int   $user_id
     * @param  array $claves                 ver claves_del_archivo_del_contexto()
     * @param  bool  $indexar_sin_proveedor
     * @return void
     */
    protected static function poblar_indice_acotado(array &$index, $user_id, array $claves, $indexar_sin_proveedor)
    {
        $columnas = ['id', 'bar_code', 'sku', 'name', 'provider_code', 'provider_id'];

        /* Un artículo puede entrar por más de una clave: se indexa una sola vez. */
        $vistos = [];

        $indexar_lote = function ($articles) use (&$index, &$vistos, $indexar_sin_proveedor) {
            foreach ($articles as $article) {
                if (isset($vistos[(int) $article->id])) {
                    continue;
                }

                $vistos[(int) $article->id] = true;

                self::indexar_articulo($index, $article, $indexar_sin_proveedor);
            }
        };

        $consultas = 0;

        foreach ([['ids', 'id'], ['bar_codes', 'bar_code'], ['skus', 'sku'], ['provider_codes', 'provider_code']] as $par) {
            list($seccion, $columna) = $par;

            $valores = array_map('strval', array_keys($claves[$seccion]));

            if ($columna === 'id') {
                /* Un "numero" que no es un entero no puede ser un id: ni se consulta. */
                $valores = array_values(array_filter($valores, 'ctype_digit'));
            }

            foreach (array_chunk($valores, self::CLAVES_POR_CONSULTA) as $lote) {
                $consultas++;

                $indexar_lote(
                    Article::where('user_id', $user_id)
                        ->whereIn($columna, $lote)
                        ->select($columnas)
                        ->get()
                );
            }
        }

        $nombres = $claves['names'];

        if (count($nombres) > 0) {
            $ids_por_nombre = [];

            /*
             * Query builder y no Eloquent, a propósito: son TODAS las filas del comercio (500.000
             * en el catálogo de prueba) y acá sólo se compara un nombre. Hidratar 500.000 modelos
             * Article costaba lo mismo que el índice completo entero (medido: 123 s contra 114 s
             * del build completo); con stdClass la pasada son unos segundos. El whereNull de
             * deleted_at reemplaza el scope de SoftDeletes que Eloquent aplicaba solo.
             */
            DB::table('articles')
                ->select(['id', 'name'])
                ->where('user_id', $user_id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->chunkById(5000, function ($filas) use (&$ids_por_nombre, &$vistos, $nombres) {
                    foreach ($filas as $fila) {
                        /* Ya indexado por otra clave: su nombre entró con él. */
                        if (isset($vistos[(int) $fila->id])) {
                            continue;
                        }

                        if (!empty($fila->name) && isset($nombres[self::normalize_name_for_match($fila->name)])) {
                            $ids_por_nombre[] = (int) $fila->id;
                        }
                    }
                });

            foreach (array_chunk($ids_por_nombre, self::CLAVES_POR_CONSULTA) as $lote) {
                $consultas++;

                $indexar_lote(
                    Article::where('user_id', $user_id)
                        ->whereIn('id', array_map('strval', $lote))
                        ->select($columnas)
                        ->get()
                );
            }
        }

        Log::info('ArticleIndexCache::build acotado al archivo', [
            'user_id'   => (int) $user_id,
            'claves'    => [
                'ids'            => count($claves['ids']),
                'bar_codes'      => count($claves['bar_codes']),
                'skus'           => count($claves['skus']),
                'provider_codes' => count($claves['provider_codes']),
                'names'          => count($nombres),
            ],
            'articulos' => count($vistos),
            'consultas' => $consultas,
        ]);
    }

    /**
     * Indexa un artículo (fila liviana: id, bar_code, sku, name, provider_code, provider_id)
     * en todas las secciones del índice. Es el cuerpo de siempre del build(), compartido por
     * los dos modos.
     *
     * @param  array  $index                  por referencia
     * @param  object $article
     * @param  bool   $indexar_sin_proveedor
     * @return void
     */
    protected static function indexar_articulo(array &$index, $article, $indexar_sin_proveedor)
    {
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

        /*
            Solo se tienen en cuenta los articulos que tienen codigo de proveedor y que pertenecen a un proveedor
        */
        if (
            !is_null($article->provider_code)
            && !is_null($article->provider_id)
        ) {
            $prov_code  = $article->provider_code;
            $prov_id    = $article->provider_id;

            if (!isset($index['provider_codes'][$prov_id])) {
                $index['provider_codes'][$prov_id] = [];
            }
            if (!isset($index['provider_codes'][$prov_id][$prov_code])) {
                $index['provider_codes'][$prov_id][$prov_code] = [];
            }
            $index['provider_codes'][$prov_id][$prov_code][] = $article_id;

        } elseif (
            $indexar_sin_proveedor
            && !is_null($article->provider_code)
            && trim((string) $article->provider_code) !== ''
            && is_null($article->provider_id)
        ) {
            /* Codigo de proveedor sin proveedor asignado: bucket propio. */
            $prov_code = $article->provider_code;

            /* '' es la clave del bucket sin proveedor (ver comentario en build()). */
            $sin_prov = '';

            if (!isset($index['provider_codes'][$sin_prov])) {
                $index['provider_codes'][$sin_prov] = [];
            }
            if (!isset($index['provider_codes'][$sin_prov][$prov_code])) {
                $index['provider_codes'][$sin_prov][$prov_code] = [];
            }
            $index['provider_codes'][$sin_prov][$prov_code][] = $article_id;
        }

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

    /**
     * Deja el índice recién construido en cache, verifica que haya quedado guardado y loguea.
     * Es la cola de siempre del build(), compartida por los dos modos.
     *
     * @param  string $key
     * @param  array  $index
     * @param  int    $user_id
     * @param  float  $inicio  microtime(true) del arranque
     * @param  string $modo    'completo' | 'acotado'
     * @return float  duración en segundos
     */
    protected static function guardar_indice_construido($key, array $index, $user_id, $inicio, $modo)
    {
        Cache::put($key, $index, now()->addMinutes(60));

        /*
         * Grupo 291, prompt 07: verificamos que el Cache::put de arriba haya
         * guardado realmente el índice. Con memcached, un ítem de más de 1MB se
         * descarta AL ESCRIBIRLO, sin devolver error -- Cache::put() vuelve como
         * si hubiera funcionado, y el próximo Cache::get() da vacío. A partir de
         * ahí la importación degrada en silencio a una consulta por fila, sin
         * ninguna excepción ni log que lo delate: una importación de 10.000 filas
         * que tarda horas y nadie sabe por qué. Cache::has() es la verificación
         * correcta y barata acá -- a diferencia de Cache::get(), no vuelve a
         * traer decenas de MB a memoria solo para comprobarlos.
         */
        if (!Cache::has($key)) {
            $counts = [
                'ids'            => count($index['ids']),
                'provider_codes' => count($index['provider_codes']),
                'bar_codes'      => count($index['bar_codes']),
                'skus'           => count($index['skus']),
                'names'          => count($index['names']),
            ];

            Log::critical('ArticleIndexCache::build: el Cache::put no guardo el indice', [
                'user_id' => $user_id,
                'driver'  => config('cache.default'),
                'counts'  => $counts,
            ]);

            throw new \RuntimeException(
                'No se pudo guardar el índice de artículos en el cache (driver: ' . config('cache.default') . '). '
                . 'La importación se detiene porque sin ese índice cada fila haría una consulta a la base y '
                . 'tardaría horas. Revisar el driver de cache del cliente.'
            );
        }

        /* Con contexto, queda anotado el sufijo para que limpiar_cache() encuentre esta clave. */
        self::anotar_sufijo($user_id, 60);

        self::$ultimo_modo_de_build = $modo;

        $duracion = microtime(true) - $inicio;

        Log::info("ArticleIndexCache::build ($modo) -> ids: " . count($index['ids']) . " provider_codes: ". count($index['provider_codes']) . " bar_codes: " . count($index['bar_codes']) . " skus: " . count($index['skus']) . " names: " . count($index['names']));
        // Log::info('$index->provider_codes: ');
        // Log::info($index['provider_codes']);
        // Log::info('Duración total cachear los articulos ' . $duracion . ' seg');

        /*
         * Aviso temprano (grupo 291, prompt 07): si el catálogo del comercio es
         * grande, avisamos ANTES de que un cliente importe un archivo grande y
         * descubra el problema en medio de la importación. No truncamos el
         * índice: eso causaría matcheos incorrectos, mucho peor que fallar.
         */
        if (count($index['ids']) > 200000) {
            Log::warning('ArticleIndexCache::build: catalogo grande, el driver de cache tiene que soportarlo', [
                'user_id' => $user_id,
                'driver'  => config('cache.default'),
                'ids'     => count($index['ids']),
            ]);
        }

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
     * Identificadores (bar_code y/o sku) que la fila traia, que NO matchearon ningun
     * articulo en su propio escalon, y que por eso la cascada de find_with_index()
     * siguio de largo hasta encontrar match en un escalon mas abajo (sku, provider_code
     * o name). Regla de Lucas, 30/7/2026, prompt 08 grupo 265: esos identificadores
     * "pendientes" se le tienen que asignar al articulo que matcheo, EXCEPTO cuando el
     * match fue de mas de un articulo (ver ProcessRow::procesar_articulo_ya_creado()).
     *
     * ['bar_code' => '7799100', 'sku' => 'SKU-1'] , en el orden de la jerarquia (los que
     * la fila trajo y no matchearon). Vacio si no quedo ninguno pendiente.
     *
     * Solo tiene sentido leerlo INMEDIATAMENTE despues de find_with_index(), igual que
     * ultimo_escalon().
     *
     * @return array
     */
    public static function ultimos_identificadores_pendientes()
    {
        return self::$ultimo_identificadores_pendientes;
    }

    /**
     * Datos del desempate por nombre que el usuario pidio y que NO alcanzo para
     * quedarse con un unico articulo (mision `desempate-por-nombre-codigo-repetido`,
     * 9/9/2026).
     *
     * Devuelve [] cuando el desempate no se pidio, no hizo falta (habia un solo
     * candidato) o resolvio bien. Cuando NO resolvio, devuelve:
     *
     *   [
     *     'provider_code' => 'FA-NN',        // codigo por el que matchearon los candidatos
     *     'nombre_excel'  => 'SILICONA ...', // nombre tal cual venia en la fila
     *     'article_ids'   => [12, 34],       // los candidatos que quedaron en pie
     *     'motivo'        => 'ninguno_coincide' | 'varios_coinciden' | 'fila_sin_nombre',
     *   ]
     *
     * 🔴 Existe porque find_with_index() es ESTATICO y no conoce a ProcessRow, que es
     * el unico que sabe en que fila esta y el unico que puede dejar un `import_conflict`.
     * Mismo mecanismo (y misma regla de uso) que ultimo_escalon() y
     * ultimos_identificadores_pendientes(): solo tiene sentido leerlo INMEDIATAMENTE
     * despues de find_with_index().
     *
     * @return array
     */
    public static function ultimo_desempate_sin_resolver()
    {
        return self::$ultimo_desempate_sin_resolver;
    }

    /**
     * Filtra, por nombre normalizado, los candidatos que YA matchearon por provider_code.
     *
     * 🔴 Esto es un FILTRO sobre el resultado del escalon provider_code, NO un escalon
     * nuevo ni una caida al escalon `name`. La diferencia no es cosmetica y no se
     * "simplifica" reusando el escalon 5: ese escalon busca en $index['names'], que es
     * GLOBAL AL USUARIO, asi que un articulo de OTRO proveedor que se llame igual
     * matchearia. Lo que se quiere es "de estos dos que matchearon por FA-NN, quedate con
     * el que ademas coincide en nombre" — el universo de busqueda son los candidatos, y
     * nada mas que los candidatos.
     *
     * Se compara con normalize_name_for_match(), el MISMO normalizador con el que se
     * construye $index['names']: si acá se usara otro criterio (por ejemplo trim + ===),
     * el desempate diria que si en casos donde el matching real dice que no, y al reves.
     *
     * @param  \Illuminate\Support\Collection $candidatos artículos que matchearon por provider_code
     * @param  string                         $name       nombre de la fila del Excel
     * @return array ['articulo' => Article|null, 'coincidencias' => int]
     */
    protected static function filtrar_candidatos_por_nombre($candidatos, $name)
    {
        $key_name = self::normalize_name_for_match($name);

        $coinciden = $candidatos->filter(function ($articulo) use ($key_name) {
            return self::normalize_name_for_match($articulo->name) === $key_name;
        })->values();

        return [
            'articulo'      => $coinciden->count() === 1 ? $coinciden->first() : null,
            'coincidencias' => $coinciden->count(),
        ];
    }

    /**
     * Desempate por nombre sobre los candidatos que ya matchearon por `provider_code`.
     *
     * Devuelve el ÚNICO artículo cuyo nombre coincide con el de la fila, o null si el
     * nombre no alcanza para quedarse con uno solo. Cuando devuelve null y el desempate
     * de verdad no resolvió (había ambigüedad y no se pudo romper), deja el detalle en
     * self::$ultimo_desempate_sin_resolver para que ProcessRow registre el
     * `import_conflict` — ver ultimo_desempate_sin_resolver().
     *
     * 🔴 Se lo llama ANTES de la bifurcación por política de colisión. Que devuelva null
     * tiene que dejar la ejecución exactamente como estaba: no toca $article_ids ni
     * ninguna otra variable de find_with_index().
     *
     * @param  array  $article_ids_same_provider ids que matchearon dentro del proveedor de la importación
     * @param  array  $article_ids               los anteriores más los de otros proveedores, si están habilitados
     * @param  array  $data                      fila del Excel ya normalizada
     * @param  string $provider_code             código por el que matchearon
     * @param  array  $relations                 relaciones eager para la consulta Eloquent
     * @param  int    $user_id
     * @return \App\Models\Article|null
     */
    protected static function desempatar_candidatos_por_nombre(
        array $article_ids_same_provider,
        array $article_ids,
        array $data,
        $provider_code,
        array $relations,
        int $user_id
    ) {
        $es_id_real = function ($id) {
            return strncmp((string) $id, 'fake_', strlen('fake_')) !== 0;
        };

        /*
         * 🔴 SÓLO artículos REALES de la base. Un id `fake_*` es un artículo que ESTA MISMA
         * importación encoló hace unas filas y todavía no tiene INSERT. Si el desempate se
         * quedara con uno, dos filas del propio archivo se estarían fusionando — y esa
         * decisión ya la toma `filas_repetidas_del_archivo`, que es otra pregunta. Además es
         * lo que deja intacto el camino "todos los ids son fakes -> devolver null para crear
         * un artículo nuevo" de la rama de permitir_provider_code_repetido, unas líneas
         * más abajo.
         */
        $ids_reales = array_values(array_filter($article_ids, $es_id_real));

        /* Con menos de dos candidatos reales no hay ninguna ambigüedad que romper. */
        if (count($ids_reales) < 2) {
            return null;
        }

        $candidatos = self::collection_from_index_article_ids($ids_reales, $relations, $user_id);

        if ($candidatos->count() < 2) {
            return null;
        }

        /*
         * 🔴 PREFERENCIA POR EL PROVEEDOR DE LA IMPORTACIÓN. Cuando
         * $actualizar_articulos_de_otro_proveedor está prendido, más arriba se mergearon a
         * $article_ids los artículos de OTROS proveedores que usan ese mismo código. Sin
         * esta preferencia el desempate se puede quedar con el artículo de otro proveedor y
         * escribirle precio, costo e identificadores, dejando SIN ACTUALIZAR el del
         * proveedor que se está importando. Antes de esta misión se actualizaban los dos, o
         * sea que el correcto al menos quedaba bien: elegir el ajeno sería una regresión
         * silenciosa, y la peor clase de regresión — la que deja los números mal sin fallar.
         *
         * Los de otros proveedores se miran SÓLO si no hay ninguno del actual. Y si el
         * proveedor actual tiene candidatos pero ninguno coincide en nombre, esto NO cae a
         * los ajenos: devuelve null y se aplica la política de siempre.
         */
        $ids_del_proveedor_actual = [];

        foreach ($article_ids_same_provider as $id) {
            if ($es_id_real($id)) {
                $ids_del_proveedor_actual[(string) $id] = true;
            }
        }

        $del_proveedor_actual = $candidatos->filter(function ($articulo) use ($ids_del_proveedor_actual) {
            return isset($ids_del_proveedor_actual[(string) $articulo->id]);
        })->values();

        $universo = $del_proveedor_actual->count() > 0 ? $del_proveedor_actual : $candidatos;

        $nombre_de_la_fila = isset($data['name']) ? trim((string) $data['name']) : '';

        if ($nombre_de_la_fila === '') {

            /*
             * La fila no trae nombre: no hay con qué desempatar. Queda reportado, pero
             * ProcessRow decide si eso es un conflicto POR FILA o una sola marca por chunk:
             * cuando la columna de nombre ni siquiera está mapeada en el import, esto pasa
             * en TODAS las filas y no es un problema de datos, es una configuración.
             */
            self::$ultimo_desempate_sin_resolver = [
                'provider_code' => $provider_code,
                'nombre_excel'  => null,
                'article_ids'   => $candidatos->pluck('id')->values()->all(),
                'motivo'        => 'fila_sin_nombre',
            ];

            Self::log('Desempate por nombre pedido pero la fila no trae nombre: ' . $provider_code);

            return null;
        }

        $desempate = self::filtrar_candidatos_por_nombre($universo, $nombre_de_la_fila);

        if (!is_null($desempate['articulo'])) {

            Self::log('Desempate por nombre OK: ' . $provider_code . ' -> articulo ' . $desempate['articulo']->id);

            return $desempate['articulo'];
        }

        self::$ultimo_desempate_sin_resolver = [
            'provider_code' => $provider_code,
            'nombre_excel'  => $nombre_de_la_fila,
            'article_ids'   => $candidatos->pluck('id')->values()->all(),
            /*
             * 'ninguno_coincide' = el proveedor cambió la redacción entre listas.
             * 'varios_coinciden' = dos artículos con el mismo código Y el mismo nombre; ahí
             * el nombre no distingue nada y no hay desempate posible.
             */
            'motivo'        => $desempate['coincidencias'] === 0
                                ? 'ninguno_coincide'
                                : 'varios_coinciden',
        ];

        Self::log(
            'Desempate por nombre NO resolvio: ' . $provider_code
            . ' ("' . $nombre_de_la_fila . '" coincide con ' . $desempate['coincidencias']
            . ' de ' . $universo->count() . ' candidatos)'
        );

        return null;
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

    /**
     * Indica si el articulo que acaba de resolver un escalon de la cascada fue
     * creado por ESTA MISMA importacion, en un chunk anterior (incidente Servian,
     * grupo 294).
     *
     * Por que importa: un match unico contra un articulo que ya existia ANTES de
     * arrancar la importacion es confiable (es la razon de ser de este indice). Pero
     * un match unico contra un articulo que la propia importacion creo hace unos
     * chunks es la version entre-lotes del mismo problema que ya_estaba_en_el_excel()
     * resuelve DENTRO de un chunk: puede ser el mismo producto repetido en el Excel
     * (legitimo), o dos productos distintos que coinciden por error de carga en un
     * solo identificador (bar_code/sku/name) mientras difieren en los demas -- y para
     * ese segundo caso, fusionar en silencio corrompe el articulo original.
     *
     * @param  array      $index
     * @param  int|string $article_id
     * @param  int|null   $import_history_id_actual
     * @return bool
     */
    protected static function matched_article_created_by_this_import(array $index, $article_id, ?int $import_history_id_actual): bool
    {
        if (is_null($import_history_id_actual)) {
            return false;
        }

        if (is_string($article_id) && strncmp($article_id, 'fake_', strlen('fake_')) === 0) {
            // Un fake es siempre de ESTE mismo chunk/import, pero ese caso ya lo
            // resuelve ya_estaba_en_el_excel() antes de llegar a la cascada.
            return false;
        }

        if (!isset($index['created_by_import'][(int) $article_id])) {
            return false;
        }

        return (int) $index['created_by_import'][(int) $article_id] === $import_history_id_actual;
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
        bool $actualizar_proveedor = true,

        /*
         * 'filas_repetidas_del_archivo' === 'productos_distintos' (prompt 04, grupo 265):
         * los matches del escalon provider_code contra articulos FAKE (encolados para crear
         * en este mismo chunk, via ArticleIndexCache::add(), todavia sin INSERT) no cuentan
         * -- el usuario dijo explicitamente que las repeticiones del propio archivo son
         * productos distintos. Los matches contra articulos REALES de la base siguen
         * contando igual: el flag habla del archivo, no de la base (prompt 09, grupo 265).
         * Default false preserva el comportamiento de siempre para cualquier otro llamador.
         */
        bool $descartar_matches_fake_del_archivo = false,

        /*
         * import_history_id de la importacion QUE ESTA CORRIENDO ahora mismo (grupo
         * 294, incidente Servian entre lotes). Se usa solo para comparar contra
         * $index['created_by_import'] y detectar un match unico contra un articulo
         * que esta MISMA importacion creo en un chunk anterior -- ver
         * matched_article_created_by_this_import(). Default null preserva el
         * comportamiento de siempre para cualquier llamador que no lo pase.
         */
        ?int $import_history_id_actual = null,

        /*
         * Mision `desempate-por-nombre-codigo-repetido` (9/9/2026). El proveedor usa el
         * MISMO codigo para dos productos distintos (el suelto y su pack x15, caso real
         * de DobleP Herrajes con la lista de Bronzen), y los nombres SI son unicos dentro
         * del par. Con este flag, cuando el escalon provider_code deja mas de un candidato
         * se filtra por nombre para quedarse con el que corresponde a ESTA fila.
         *
         * Va ULTIMO y con default false a proposito: una SPA vieja que no lo manda, y
         * cualquier otro llamador, obtienen el comportamiento de siempre bit por bit.
         */
        bool $desempatar_por_nombre = false
    ) {
        if (!is_array($index) || empty($index)) {
            $index = self::get_index($user_id, $provider_id);
        }

        // Se resetea en cada llamada: si no se pisa mas abajo, ultimos_identificadores_pendientes()
        // tiene que devolver [] y no arrastrar el valor de la fila anterior.
        self::$ultimo_identificadores_pendientes = [];

        // Idem: sin este reset, la fila N reportaria el desempate fallido de la fila N-1.
        self::$ultimo_desempate_sin_resolver = [];

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

        /*
         * 2) bar_code y 3) sku -- cascada de escalones (regla de Lucas, 30/7/2026,
         * prompt 08 grupo 265; reemplaza la version del 29/7 que cortaba la busqueda
         * en el primer escalon con valor). Se evalua cada uno EN ORDEN, solo si la
         * fila lo trae: si uno no matchea nada (no esta en el indice, o resolve_unique
         * da no_match), NO corta la busqueda -- se guarda como "identificador
         * pendiente" (self::$ultimo_identificadores_pendientes) y se sigue con el
         * escalon siguiente que la fila traiga (no necesariamente el inmediato: si no
         * hay sku, de bar_code se pasa directo a provider_code). Si un escalon
         * matchea un UNICO articulo, se le asignan los identificadores pendientes de
         * mas arriba -- ver ProcessRow::procesar_articulo_ya_creado(). Ambiguo sigue
         * cortando la busqueda de inmediato, igual que siempre: no se toco esa parte.
         */
        if (is_null($article_id)) {

            $identificadores_pendientes = [];

            foreach ([['bar_code', 'bar_codes'], ['sku', 'skus']] as $par) {

                list($campo, $indice_key) = $par;

                if (empty($data[$campo])) {
                    continue;
                }

                $valor = (string) $data[$campo];

                if (isset($index[$indice_key][$valor])) {

                    Self::log('Buscando por '.$campo.' '.$valor);

                    $resuelto = self::resolve_unique($index[$indice_key][$valor], $relations, $user_id);

                    if ($resuelto['status'] === 'ambiguous') {
                        Self::log($campo.' ambiguo: '.$valor.' matchea con '.count($resuelto['ids']).' articulos');
                        return self::con_escalon(null, new AmbiguousMatch($campo, $valor, $resuelto['ids']));
                    }

                    if ($resuelto['status'] === 'match') {

                        /*
                         * Incidente Servian (grupo 294): el UNICO articulo que matchea
                         * lo creo esta misma importacion en un chunk anterior. No es un
                         * match confiable como el de un articulo pre-existente -- puede
                         * ser el mismo producto repetido (legitimo) o dos productos
                         * distintos que coinciden en este campo por error de carga. Se
                         * reporta ambiguo en vez de fusionar en silencio.
                         */
                        if (self::matched_article_created_by_this_import($index, $resuelto['article']->id, $import_history_id_actual)) {
                            Self::log($campo.' matchea un articulo creado por esta misma importacion en un chunk anterior: se reporta ambiguo, no se fusiona.');
                            return self::con_escalon(null, new AmbiguousMatch($campo, $valor, [$resuelto['article']->id]));
                        }

                        self::$ultimo_identificadores_pendientes = $identificadores_pendientes;
                        return self::con_escalon($campo, $resuelto['article']);
                    }

                    // no_match: mismo tratamiento que "no esta en el indice", sigue de largo.
                }

                $identificadores_pendientes[$campo] = $data[$campo];
            }

            self::$ultimo_identificadores_pendientes = $identificadores_pendientes;
        }

        // 4) provider_code -- semantica sin cambios (sus flags deciden todo, ver mas
        // abajo). Antes era "elseif" de bar_code/sku; ahora es "if" guardado con
        // is_null($article_id) porque el bloque de arriba ya no es mutuamente
        // excluyente con este por construccion de if/elseif -- el guard cumple el
        // mismo rol: solo se llega aca si nada matcheo (ni fue ambiguo) todavia.
        if (is_null($article_id) && !empty($data['provider_code'])) {


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

            /*
             * 'productos_distintos' (prompt 09, grupo 265): descartar los matches contra
             * articulos FAKE (de este mismo chunk, todavia sin INSERT) ANTES de la regla de
             * bloqueo y de decidir match/ambiguo -- para esta fila, esos articulos no
             * cuentan como si ya existieran. Los matches contra articulos reales de la base
             * (ids numericos) no se tocan.
             */
            if ($descartar_matches_fake_del_archivo) {
                $es_id_real = function ($id) {
                    return strncmp((string) $id, 'fake_', strlen('fake_')) !== 0;
                };
                $article_ids_same_provider   = array_values(array_filter($article_ids_same_provider, $es_id_real));
                $article_ids_other_providers = array_values(array_filter($article_ids_other_providers, $es_id_real));
            }

            /*
             * Incidente Servian (grupo 294): a diferencia de bar_code/sku/name,
             * provider_code SI puede repetirse legitimamente entre productos
             * distintos (es la razon de ser de permitir_provider_code_repetido), asi
             * que un match unico contra un articulo que ya creo ESTA MISMA
             * importacion en un chunk anterior no se reporta como ambiguo -- se
             * descarta, como si el escalon no hubiera encontrado nada, y la fila
             * sigue de largo (a name, o a "crear nuevo"). A diferencia del filtro de
             * $descartar_matches_fake_del_archivo (que depende de 'productos_distintos'),
             * este se aplica siempre.
             */
            $no_creado_por_esta_importacion = function ($id) use ($index, $import_history_id_actual) {
                return !self::matched_article_created_by_this_import($index, $id, $import_history_id_actual);
            };
            $article_ids_same_provider   = array_values(array_filter($article_ids_same_provider, $no_creado_por_esta_importacion));
            $article_ids_other_providers = array_values(array_filter($article_ids_other_providers, $no_creado_por_esta_importacion));

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

                /*
                 * DESEMPATE POR NOMBRE (misión `desempate-por-nombre-codigo-repetido`,
                 * 9/9/2026). El proveedor usa el MISMO código para dos productos distintos
                 * —el suelto y su pack x15, caso real de DobleP Herrajes con la lista de
                 * Bronzen— y los nombres SÍ son únicos dentro del par.
                 *
                 * 🔴 VA ACÁ, ANTES DE LA BIFURCACIÓN POR POLÍTICA DE COLISIÓN, y no adentro
                 * de una de sus dos ramas. El desempate contesta "de estos dos artículos que
                 * matchearon por FA-NN, ¿cuál es el de ESTA fila?", que es una pregunta
                 * ANTERIOR a la que contesta la política ("cuando no se puede saber cuál es,
                 * ¿qué hago?"). La primera versión de esta misión lo metió adentro del
                 * `if ($permitir_provider_code_repetido)` y el efecto fue que la opción no
                 * hacía nada salvo que el usuario ADEMÁS hubiera elegido "actualizar todos
                 * los que tengan ese código": con "saltear esas filas y avisarme" la
                 * ejecución caía al `else`, devolvía un AmbiguousMatch y el desempate no
                 * corría nunca — el usuario prendía la casilla, la importación terminaba sin
                 * ningún error y no pasaba nada.
                 *
                 * Si el nombre resuelve a UN solo candidato, se devuelve ese match y la
                 * política de colisión pasa a ser irrelevante: ya no hay colisión que
                 * resolver. Si no resuelve (0 o 2+ coincidencias), esto devuelve null, deja
                 * anotado el motivo para que ProcessRow registre el `import_conflict`, y la
                 * ejecución sigue por la lógica de siempre con la política que corresponda,
                 * bit por bit como antes.
                 */
                if ($desempatar_por_nombre) {

                    $desempatado = self::desempatar_candidatos_por_nombre(
                        $article_ids_same_provider,
                        $article_ids,
                        $data,
                        $provider_code,
                        $relations,
                        $user_id
                    );

                    if ($desempatado instanceof Article) {
                        return self::con_escalon('provider_code', $desempatado);
                    }
                }

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

                    /*
                     * INVARIANTE (grupo 285, prompt 01): a partir de acá, este es el ÚNICO punto de
                     * find_with_index() que puede devolver una Collection, y tiene que devolverla
                     * SOLO cuando hay dos o más artículos. ProcessRow::son_varios_articulos() (y
                     * sus seis llamadores) tratan cualquier Collection como "son varios artículos" --
                     * antes de este fix, un provider_code que matcheaba un ÚNICO artículo llegaba acá
                     * como Collection de un elemento, son_varios_articulos() la trataba igual que una
                     * ambigüedad real, y el SKU/bar_code de la fila se descartaba con un
                     * import_conflict FALSO de identificador_sin_asignar (coincidía uno, no varios).
                     * No "arreglar" esto devolviendo false desde son_varios_articulos(): sin
                     * normalizar acá el origen, esa fila se trataría como "sin match" y crearía un
                     * artículo duplicado (ver línea ~795, la anulación por !instanceof Article).
                     */
                    $resuelto_provider_code_repetido = self::collection_from_index_article_ids($real_ids, $relations, $user_id);

                    if ($resuelto_provider_code_repetido->count() === 1) {
                        return self::con_escalon('provider_code', $resuelto_provider_code_repetido->first());
                    }

                    if ($resuelto_provider_code_repetido->count() === 0) {
                        // No puede pasar hoy (real_ids ya se validó no vacío más arriba), pero si el
                        // índice quedara desalineado contra la BD, nunca devolver una Collection
                        // vacía: son_varios_articulos() la trataría como "no son varios" y caería en
                        // la misma anulación-a-duplicado que el caso de arriba.
                        return self::con_escalon('provider_code', null);
                    }

                    /*
                     * Nota: el desempate por nombre NO va acá adentro. Corre unas líneas más
                     * arriba, antes de la bifurcación por política, porque es desambiguación
                     * y no política de colisión — ver el comentario largo de allá.
                     */
                    return self::con_escalon('provider_code', $resuelto_provider_code_repetido);

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

        // 5) name -- semantica sin cambios, mismo guard que provider_code.
        if (is_null($article_id) && !empty($data['name'])) {

            $key_name = self::normalize_name_for_match($data['name']);

            if (isset($index['names'][$key_name])) {

                $name_resolved = self::resolve_unique($index['names'][$key_name], $relations, $user_id);

                if ($name_resolved['status'] === 'ambiguous') {
                    Self::log('name ambiguo: '.$data['name'].' matchea con '.count($name_resolved['ids']).' articulos');
                    return self::con_escalon(null, new AmbiguousMatch('name', (string) $data['name'], $name_resolved['ids']));
                }

                if ($name_resolved['status'] === 'match') {

                    // Incidente Servian (grupo 294): mismo criterio que bar_code/sku arriba.
                    if (self::matched_article_created_by_this_import($index, $name_resolved['article']->id, $import_history_id_actual)) {
                        Self::log('name matchea un articulo creado por esta misma importacion en un chunk anterior: se reporta ambiguo, no se fusiona.');
                        return self::con_escalon(null, new AmbiguousMatch('name', (string) $data['name'], [$name_resolved['article']->id]));
                    }

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
        // Va por collection_from_index_article_ids() para servirse del mapa precargado del
        // lote cuando lo hay (misma fila, mismas relaciones; sin mapa, consulta como siempre).
        $article_encontrado = self::collection_from_index_article_ids([$article_id], $relations, $user_id)->first();

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


    public static function update(Article $article, $codigos_proveedor_repetidos, ?int $import_history_id = null)
    {
        $key = self::cache_key($article->user_id);

        /*
         * BUG incidente Servian (grupo 294): leer Cache::get() directo, en vez del
         * indice memoizado en RAM que add() ya usa via get_index(), hacia que cada
         * llamada a update() DENTRO DEL MISMO foreach (ver
         * ActualizarBBDD::actualizar_cache_articulos_creados()/actualizar_cache())
         * arrancara de nuevo desde el cache compartido -- que recien se escribe una
         * vez, en persist(), despues de terminar el foreach. Resultado: cada llamada
         * pisaba en RAM lo que habia agregado la llamada anterior del mismo lote, y
         * solo el ULTIMO articulo de ese foreach sobrevivia hasta persist(). Un
         * articulo creado a mitad de un chunk (no el ultimo) quedaba indexado en la
         * practica como si nunca se hubiera agregado, y un chunk posterior no lo
         * encontraba -> duplicado. self::get_index() es la MISMA vista en RAM que
         * add() usa, asi que las llamadas de este foreach se acumulan en vez de
         * pisarse.
         */
        $index = self::get_index((int) $article->user_id);

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

        /*
         * Marca de "quien lo creo" (incidente Servian, grupo 294): solo se etiqueta
         * cuando el llamador pasa un import_history_id explicito -- hoy, unicamente
         * ActualizarBBDD::actualizar_cache_articulos_creados(), justo para los
         * articulos que esta MISMA importacion acaba de insertar. Los articulos que
         * ya existian antes (actualizar_cache(), sin este argumento) nunca se tocan
         * aca, asi que jamas quedan marcados como "creados por" una importacion.
         */
        if (!is_null($import_history_id)) {
            $index['created_by_import'][(int) $article->id] = $import_history_id;
        }

        if (!empty($article->bar_code)) {
            // Formato lista: se acumula en vez de pisar (ver index_entry_to_ids/resolve_unique).
            $bc = (string) $article->bar_code;
            $ids_bc = self::index_entry_to_ids($index['bar_codes'][$bc] ?? null);
            $ids_bc[] = $article->id;
            $index['bar_codes'][$bc] = array_values(array_unique($ids_bc));
        }

        if (!empty($article->sku)) {
            // Rama que faltaba (mismo patron que bar_code arriba, grupo 294): sin
            // ella el sku de un articulo recien creado nunca quedaba indexado por
            // update(), asi que un chunk posterior no podia encontrarlo por sku.
            $sku = (string) $article->sku;
            $ids_sku = self::index_entry_to_ids($index['skus'][$sku] ?? null);
            $ids_sku[] = $article->id;
            $index['skus'][$sku] = array_values(array_unique($ids_sku));
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

        } elseif (empty($article->provider_id) && !empty($article->provider_code)) {

            /*
             * Codigo de proveedor SIN proveedor asignado (decision de Lucas, 24/8/2026):
             * la contracara en update() del bucket que arma build(). Sin esto, un
             * articulo creado a mitad de una importacion sin proveedor no queda indexado
             * por su provider_code y un lote posterior con la misma fila lo duplica --
             * exactamente el agujero que este cambio viene a cerrar para los articulos
             * que ya estaban en la base.
             *
             * La guarda es la clave '' del indice: build() la crea SOLO cuando la
             * importacion no eligio proveedor. Si la importacion si eligio uno, este
             * bucket no existe y no se escribe nada -- porque ahi caeria en "otros
             * proveedores" de find_with_index() y bloquearia filas que hoy se crean.
             */
            $sin_prov = '';

            if (isset($index['provider_codes'][$sin_prov])) {

                $prov_code = $article->provider_code;

                if (!isset($index['provider_codes'][$sin_prov][$prov_code])) {
                    $index['provider_codes'][$sin_prov][$prov_code] = [];
                }

                $index['provider_codes'][$sin_prov][$prov_code][] = $article->id;
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

        /*
         * Grupo 291, prompt 07: misma verificación que build() -- ver el
         * comentario ahí para el porqué. Acá el índice fusionado puede ser
         * incluso más grande que el de un solo worker, así que el riesgo de
         * exceder el límite de memcached es igual o mayor.
         */
        if (!Cache::has($key)) {
            Log::critical('ArticleIndexCache::persist: el Cache::put no guardo el indice fusionado', [
                'user_id' => $user_id,
                'driver'  => config('cache.default'),
                'counts'  => [
                    'ids'            => count($merged['ids'] ?? []),
                    'provider_codes' => count($merged['provider_codes'] ?? []),
                    'bar_codes'      => count($merged['bar_codes'] ?? []),
                    'skus'           => count($merged['skus'] ?? []),
                    'names'          => count($merged['names'] ?? []),
                ],
            ]);

            throw new \RuntimeException(
                'No se pudo guardar el índice de artículos en el cache (driver: ' . config('cache.default') . '). '
                . 'La importación se detiene porque sin ese índice cada fila haría una consulta a la base y '
                . 'tardaría horas. Revisar el driver de cache del cliente.'
            );
        }

        // La RAM local pasa a reflejar el indice fusionado (incluye lo que agregaron otros workers).
        self::$runtime_index_by_key[$key] = $merged;

        // ya persistido
        self::$runtime_dirty_by_key[$key] = false;

        /* La anotación del sufijo vive lo que vive el índice: se refresca con él. */
        self::anotar_sufijo($user_id, $ttl_minutes);
    }

    /**
     * Deja anotado en cache el sufijo de la importación en curso (si hay contexto), para que
     * limpiar_cache() encuentre la clave con sufijo desde cualquier proceso.
     *
     * @param  int $user_id
     * @param  int $ttl_minutes
     * @return void
     */
    protected static function anotar_sufijo($user_id, $ttl_minutes)
    {
        if (is_null(self::$contexto_sufijo) || self::$contexto_sufijo === '') {
            return;
        }

        Cache::put(self::clave_del_sufijo($user_id), self::$contexto_sufijo, now()->addMinutes((int) $ttl_minutes));
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

        /*
         * created_by_import (grupo 294, incidente Servian): mapa escalar igual que
         * ids (article_id real => import_history_id), gana el nuevo. Las claves
         * siempre son ids reales (update() solo las escribe con $article->id, nunca
         * con un fake_id), asi que no hace falta filtrar fakes aca.
         */
        if (isset($nuevo['created_by_import'])) {
            if (!isset($out['created_by_import'])) {
                $out['created_by_import'] = [];
            }
            foreach ($nuevo['created_by_import'] as $k => $v) {
                $out['created_by_import'][$k] = $v;
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

        /* Los modelos precargados son del lote anterior: el que arranca precarga los suyos. */
        unset(self::$modelos_precargados[$user_id]);
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

        /* Idem: contexto de importación y modelos precargados son estáticos del proceso. */
        self::$contexto_claves_path = null;
        self::$contexto_sufijo      = null;
        self::$modelos_precargados  = [];

        self::$forzar_indice_completo_de_tests = false;
        self::$ultimo_modo_de_build            = null;

        /* Idem: es estado estatico del proceso y sobrevive de un test al siguiente. */
        self::$ultimo_identificadores_pendientes = [];
        self::$ultimo_desempate_sin_resolver     = [];
    }

    static function limpiar_cache($user_id) {

        /*
         * Se borran las dos claves posibles: la de siempre y la que lleva el sufijo de la
         * importación (misión importacion-excel-motor-rapido, 24/9/2026). El sufijo sale del
         * contexto en memoria si este proceso lo tiene, y si no de la anotación en cache
         * (clave_del_sufijo(), la deja build() y la refresca persist()): el failed() de un job
         * corre en un proceso fresco que nunca pasó por set_contexto_de_importacion().
         */
        $claves = [];

        $clave_base = 'article_index_v2_user_' . (int) $user_id;
        $claves[$clave_base] = true;

        if (!is_null(self::$contexto_sufijo) && self::$contexto_sufijo !== '') {
            $claves[$clave_base . '_imp' . self::$contexto_sufijo] = true;
        }

        $sufijo_anotado = Cache::get(self::clave_del_sufijo($user_id));

        if (is_string($sufijo_anotado) && $sufijo_anotado !== '') {
            $claves[$clave_base . '_imp' . $sufijo_anotado] = true;
        }

        foreach (array_keys($claves) as $cache_key) {
            Cache::forget($cache_key);

            unset(self::$runtime_index_by_key[$cache_key]);
            unset(self::$runtime_loaded_by_key[$cache_key]);
            unset(self::$runtime_dirty_by_key[$cache_key]);
        }

        Cache::forget(self::clave_del_sufijo($user_id));

        Log::info('Cache de importación de artículos limpiado: ' . implode(', ', array_keys($claves)));

        unset(self::$runtime_fake_articles[(int) $user_id]);
        unset(self::$modelos_precargados[(int) $user_id]);

        /* La importación terminó (bien o mal): el contexto no puede sobrevivirla en el worker. */
        self::set_contexto_de_importacion(null, null);
    }

}
