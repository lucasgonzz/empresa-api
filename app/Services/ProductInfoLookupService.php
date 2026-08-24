<?php

namespace App\Services;

use App\Models\Article;
use App\Models\GeocoderCounter;
use App\Services\Traits\GoogleSearchHelpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recolecta evidencia verificable sobre un producto para el flujo de descripciones
 * inteligentes. NO genera texto: junta hechos y devuelve el techo de confianza que
 * esas fuentes permiten.
 *
 * Orden de resolucion:
 *   1. GTIN valido + cupo de UPCitemdb  -> lookup por codigo de barras   (techo: high)
 *   2. GTIN valido                      -> Google Custom Search web      (techo: medium)
 *   3. Nombre del articulo              -> Google Custom Search web      (techo: low)
 *
 * Si ninguna devuelve evidencia utilizable, `found` es false y NO se genera nada.
 */
class ProductInfoLookupService
{
    use GoogleSearchHelpers;

    /** @var int Cantidad maxima de resultados de Google que se pasan como evidencia. */
    const MAX_GOOGLE_RESULTS = 5;

    /** @var int ID del usuario dueño (para la cuota diaria de Google). */
    protected $user_id;

    /** @var string Clave de Google Custom Search API. */
    protected $google_api_key;

    /** @var string ID del motor de busqueda personalizado (cx). */
    protected $cx;

    /** @var int Cuota diaria maxima de busquedas de Google del owner. */
    protected $google_cuota;

    public function __construct(int $user_id, string $google_api_key, string $cx, int $google_cuota)
    {
        $this->user_id        = $user_id;
        $this->google_api_key = $google_api_key;
        $this->cx             = $cx;
        $this->google_cuota   = $google_cuota;
    }

    /**
     * Recolecta la evidencia disponible sobre un articulo.
     *
     * @param  Article         $article
     * @param  GeocoderCounter $counter Contador diario de busquedas de Google.
     * @return array {
     *     found:            bool,
     *     source:           string|null  'barcode_api' | 'google_bar_code' | 'google_name'
     *     max_confidence:   string|null  'high' | 'medium' | 'low'
     *     quota_exceeded:   bool         true si se corto por cuota de Google agotada
     *     query:            string|null  lo que efectivamente se busco
     *     evidence:         array        [{title, snippet, url, source}]
     *     structured:       array        datos duros del barcode api (brand, title, category, ...)
     * }
     */
    public function lookup(Article $article, GeocoderCounter $counter): array
    {
        $bar_code = '';
        if ($article->bar_code !== null && $article->bar_code !== '') {
            $bar_code = $this->normalize_bar_code((string) $article->bar_code);
        }

        $has_valid_gtin = ($bar_code !== '' && $this->is_valid_product_bar_code($bar_code));

        /* 1. UPCitemdb (solo con GTIN valido y cupo disponible). */
        if ($has_valid_gtin) {
            $barcode_result = $this->lookup_upcitemdb($bar_code);

            if ($barcode_result !== null) {
                return [
                    'found'          => true,
                    'source'         => 'barcode_api',
                    'max_confidence' => 'high',
                    'quota_exceeded' => false,
                    'query'          => $bar_code,
                    'evidence'       => $barcode_result['evidence'],
                    'structured'     => $barcode_result['structured'],
                ];
            }
        }

        /* 2 y 3. Google Custom Search web: primero por codigo de barras, despues por nombre. */
        $google_queries = array();

        if ($has_valid_gtin) {
            $google_queries[] = array(
                'query'      => $bar_code,
                'source'     => 'google_bar_code',
                'confidence' => 'medium',
            );
        }

        $article_name = trim((string) $article->name);
        if ($article_name !== '') {
            $google_queries[] = array(
                'query'      => $article_name,
                'source'     => 'google_name',
                'confidence' => 'low',
            );
        }

        foreach ($google_queries as $google_query) {
            if ($counter->counter >= $this->google_cuota) {
                return $this->empty_result(true);
            }

            $results = $this->fetch_google_web_results($google_query['query'], $counter);

            if (empty($results)) {
                continue;
            }

            /*
             * Cuando la busqueda fue por codigo de barras, el techo baja a 'low' si los
             * resultados no coinciden entre si (ver check_evidence_coherence). Un GTIN que
             * devuelve 5 productos distintos no es evidencia: es ruido.
             */
            $max_confidence = $google_query['confidence'];
            if ($max_confidence === 'medium' && !$this->check_evidence_coherence($results)) {
                $max_confidence = 'low';
                Log::info('[DescripcionesIA] Resultados de Google incoherentes entre si, techo de confianza baja a low.', [
                    'article_id' => $article->id,
                    'query'      => $google_query['query'],
                ]);
            }

            return [
                'found'          => true,
                'source'         => $google_query['source'],
                'max_confidence' => $max_confidence,
                'quota_exceeded' => false,
                'query'          => $google_query['query'],
                'evidence'       => $results,
                'structured'     => [],
            ];
        }

        return $this->empty_result(false);
    }

    /**
     * Resultado vacio (no se encontro nada, o se corto por cuota).
     *
     * @param  bool $quota_exceeded
     * @return array
     */
    protected function empty_result(bool $quota_exceeded): array
    {
        return [
            'found'          => false,
            'source'         => null,
            'max_confidence' => null,
            'quota_exceeded' => $quota_exceeded,
            'query'          => null,
            'evidence'       => [],
            'structured'     => [],
        ];
    }

    /**
     * Lookup en UPCitemdb (plan FREE). Devuelve null ante cualquier problema (sin cupo,
     * 429, timeout, sin resultados): el flujo degrada a Google en silencio.
     *
     * @param  string $gtin Codigo de barras ya normalizado y validado.
     * @return array|null   ['evidence' => [...], 'structured' => [...]]
     */
    protected function lookup_upcitemdb(string $gtin)
    {
        if (!config('services.upcitemdb.enabled')) {
            return null;
        }

        if (!$this->consume_upcitemdb_quota()) {
            Log::info('[DescripcionesIA] Cupo diario de UPCitemdb agotado, se usa Google.', [
                'gtin' => $gtin,
            ]);
            return null;
        }

        try {
            $response = $this->google_http()
                ->timeout((int) config('services.upcitemdb.timeout', 8))
                ->get('https://api.upcitemdb.com/prod/trial/lookup', ['upc' => $gtin]);
        } catch (\Exception $e) {
            Log::info('[DescripcionesIA] UPCitemdb: error de conexion.', [
                'gtin'  => $gtin,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->status() === 429) {
            /* Se agoto el limite real por IP antes que nuestro cap: quemar el cap del dia. */
            $this->burn_upcitemdb_quota();
            Log::info('[DescripcionesIA] UPCitemdb devolvio 429 (limite por IP agotado).', ['gtin' => $gtin]);
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $body = $response->json();

        if (!isset($body['items']) || !is_array($body['items']) || empty($body['items'])) {
            return null;
        }

        $item = $body['items'][0];

        $title = isset($item['title']) ? trim((string) $item['title']) : '';
        if ($title === '') {
            return null;
        }

        $structured = [
            'title'       => $title,
            'brand'       => isset($item['brand'])       ? (string) $item['brand']       : null,
            'model'       => isset($item['model'])       ? (string) $item['model']       : null,
            'category'    => isset($item['category'])    ? (string) $item['category']    : null,
            'description' => isset($item['description']) ? (string) $item['description'] : null,
            'dimension'   => isset($item['dimension'])   ? (string) $item['dimension']   : null,
            'weight'      => isset($item['weight'])      ? (string) $item['weight']      : null,
        ];

        $evidence = [[
            'title'   => $title,
            'snippet' => isset($item['description']) ? (string) $item['description'] : '',
            'url'     => 'https://www.upcitemdb.com/upc/'.$gtin,
            'source'  => 'upcitemdb',
        ]];

        return [
            'evidence'   => $evidence,
            'structured' => $structured,
        ];
    }

    /**
     * Consume una unidad del cupo diario de UPCitemdb. Devuelve false si ya no queda.
     * El contador es global de la plataforma (el limite del plan FREE es por IP, no por cliente).
     *
     * @return bool
     */
    protected function consume_upcitemdb_quota(): bool
    {
        $cache_key = 'upcitemdb_lookups_'.Carbon::today()->format('Y-m-d');
        $cap       = (int) config('services.upcitemdb.daily_cap', 90);

        $used = (int) Cache::get($cache_key, 0);

        if ($used >= $cap) {
            return false;
        }

        Cache::put($cache_key, $used + 1, Carbon::tomorrow());

        return true;
    }

    /**
     * Da por agotado el cupo del dia (se llama al recibir un 429 real de UPCitemdb).
     *
     * @return void
     */
    protected function burn_upcitemdb_quota(): void
    {
        $cache_key = 'upcitemdb_lookups_'.Carbon::today()->format('Y-m-d');
        $cap       = (int) config('services.upcitemdb.daily_cap', 90);

        Cache::put($cache_key, $cap, Carbon::tomorrow());
    }

    /**
     * Busqueda WEB en Google Custom Search (mismo cx y misma cuota diaria que la busqueda de
     * imagenes; la diferencia es que NO se manda searchType=image).
     * Incrementa el GeocoderCounter una vez por llamada, igual que el flujo de imagenes.
     *
     * @param  string          $query
     * @param  GeocoderCounter $counter
     * @return array           [{title, snippet, url, source}]
     */
    protected function fetch_google_web_results(string $query, GeocoderCounter $counter): array
    {
        $counter->counter += 1;
        $counter->save();

        try {
            // google_api_http() y no google_http(): la key de Custom Search tiene restriccion por
            // referrer HTTP y sin el header Google responde "Requests from referer <empty> are
            // blocked". Ver GoogleSearchHelpers::google_api_http().
            $response = $this->google_api_http()
                ->timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get('https://www.googleapis.com/customsearch/v1', [
                    'key' => $this->google_api_key,
                    'cx'  => $this->cx,
                    'q'   => $query,
                    'num' => self::MAX_GOOGLE_RESULTS,
                ]);
        } catch (\Exception $e) {
            Log::info('[DescripcionesIA] Google Custom Search: error de conexion.', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (!$response->successful()) {
            Log::info('[DescripcionesIA] Google Custom Search: respuesta no exitosa.', [
                'query'  => $query,
                'status' => $response->status(),
            ]);
            return [];
        }

        $body = $response->json();

        if (!isset($body['items']) || !is_array($body['items'])) {
            return [];
        }

        $results = [];

        foreach ($body['items'] as $item) {
            $title   = isset($item['title'])   ? trim((string) $item['title'])   : '';
            $snippet = isset($item['snippet']) ? trim((string) $item['snippet']) : '';

            if ($title === '' && $snippet === '') {
                continue;
            }

            $results[] = [
                'title'   => $title,
                'snippet' => $snippet,
                'url'     => isset($item['link']) ? (string) $item['link'] : '',
                'source'  => 'google',
            ];

            if (count($results) >= self::MAX_GOOGLE_RESULTS) {
                break;
            }
        }

        return $results;
    }

    /**
     * Chequea si los resultados hablan del mismo producto o de productos distintos.
     * Heuristica deliberadamente simple: se toman las palabras significativas (>3 caracteres)
     * del titulo de cada resultado y se exige que al menos 2 resultados compartan 2 o mas.
     *
     * Un codigo de barras que devuelve 5 titulos sin nada en comun NO es evidencia.
     *
     * @param  array $results
     * @return bool
     */
    protected function check_evidence_coherence(array $results): bool
    {
        if (count($results) < 2) {
            /* Un solo resultado no se puede contrastar: no se penaliza, pero tampoco confirma. */
            return true;
        }

        $token_sets = [];

        foreach ($results as $result) {
            $normalized = mb_strtolower($result['title']);
            $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
            $words      = preg_split('/\s+/', trim($normalized));

            $tokens = [];
            foreach ($words as $word) {
                if (mb_strlen($word) > 3) {
                    $tokens[] = $word;
                }
            }

            if (!empty($tokens)) {
                $token_sets[] = array_unique($tokens);
            }
        }

        $total = count($token_sets);

        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $shared = array_intersect($token_sets[$i], $token_sets[$j]);
                if (count($shared) >= 2) {
                    return true;
                }
            }
        }

        return false;
    }
}
