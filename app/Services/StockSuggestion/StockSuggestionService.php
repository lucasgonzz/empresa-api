<?php

namespace App\Services\StockSuggestion;

use App\Models\Article;
use Illuminate\Support\Collection;

/**
 * Calcula traslados sugeridos entre depósitos según stock min/max por artículo.
 *
 * v2: respeta los depósitos designados como origen preferente
 * (addresses.es_deposito_origen), suma el objetivo 'maximo' y scopea el
 * catálogo al comercio dueño de la sugerencia (user_id), no al de la instancia.
 */
class StockSuggestionService
{
    /** @var \App\Models\StockSuggestion Configuración de la sugerencia (modo, origen, límite, user_id) */
    protected $suggestion;

    /**
     * @param \App\Models\StockSuggestion $suggestion Registro con modo, origen y limite_origen
     */
    public function __construct($suggestion)
    {
        $this->suggestion = $suggestion;
    }

    /**
     * Sugerencias para todo el catálogo.
     *
     * @return Collection
     */
    public function getSuggestions(): Collection
    {
        return $this->getSuggestionsForArticles([]);
    }

    /**
     * Sugerencias para un subconjunto de artículos (lote de procesamiento).
     *
     * @param array $article_ids IDs de artículos; vacío = todos
     * @return Collection
     */
    public function getSuggestionsForArticles(array $article_ids = []): Collection
    {
        $suggestions = collect();

        // Se filtra por el dueño de la sugerencia (no por config('app.USER_ID')):
        // en producción es lo mismo, pero en la base de testing conviven varios
        // user_id y sin este where el cálculo mezclaba catálogos ajenos.
        $query = Article::with(['addresses'])
            ->where('user_id', $this->suggestion->user_id);
        if (!empty($article_ids)) {
            $query->whereIn('id', $article_ids);
        }

        $query->chunk(50, function ($articles) use (&$suggestions) {
            foreach ($articles as $article) {
                $article_suggestions = $this->build_suggestions_for_article($article);
                foreach ($article_suggestions as $item) {
                    $suggestions->push($item);
                }
            }
        });

        return $suggestions;
    }

    /**
     * Arma sugerencias de traslado para un solo artículo.
     *
     * @param Article $article
     * @return array
     */
    protected function build_suggestions_for_article(Article $article): array
    {
        $suggestions = [];
        $stock_data = [];

        foreach ($article->addresses as $address) {
            $pivot = $address->pivot;

            // amount es obligatorio; min/max pueden faltar en depósitos que solo tienen stock
            if (!isset($pivot->amount) || $pivot->amount === '' || $pivot->amount === null) {
                continue;
            }

            $stock_min = $pivot->stock_min;
            $stock_max = $pivot->stock_max;

            $ideal = null;
            if ($stock_min !== null && $stock_max !== null) {
                $ideal = ($stock_min + $stock_max) / 2;
            }

            $stock_data[] = [
                'address_id' => $address->id,
                'amount' => (float) $pivot->amount,
                'stock_min' => $stock_min !== null ? (float) $stock_min : null,
                'stock_max' => $stock_max !== null ? (float) $stock_max : null,
                'ideal' => $ideal,
                // Designación de depósito de origen preferente (v2). Reemplaza
                // al viejo is_central, que leía una columna inexistente.
                'es_deposito_origen' => (bool) ($address->es_deposito_origen ?? false),
            ];
        }

        if (empty($stock_data)) {
            return $suggestions;
        }

        $deficits = [];
        foreach ($stock_data as $data) {
            $objetivo = $this->resolve_objetivo($data);
            if ($objetivo === null) {
                continue;
            }
            if ($data['amount'] < $objetivo) {
                $deficits[] = [
                    'to_address_id' => $data['address_id'],
                    'needed' => (int) round($objetivo - $data['amount']),
                ];
            }
        }

        if (empty($deficits)) {
            return $suggestions;
        }

        $origin = $this->obtenerOrigen($stock_data, $deficits);
        if (!$origin) {
            return $suggestions;
        }

        $limite = $this->resolve_limite_origen($origin);
        $disponible = max(0, $origin['amount'] - $limite);

        foreach ($deficits as $deficit) {
            if ($disponible <= 0) {
                break;
            }

            // No sugerir traslado al mismo depósito
            if ($deficit['to_address_id'] === $origin['address_id']) {
                continue;
            }

            $mover = min($deficit['needed'], $disponible);
            if ($mover > 0) {
                $suggestions[] = [
                    'article_id' => $article->id,
                    'from_address_id' => $origin['address_id'],
                    'to_address_id' => $deficit['to_address_id'],
                    'suggested_amount' => $mover,
                ];
                $disponible -= $mover;
            }
        }

        return $suggestions;
    }

    /**
     * Objetivo de stock según modo de la sugerencia (minimo / ideal / maximo).
     *
     * @param array $data Datos de un depósito del artículo
     * @return float|null null si no hay datos para calcular objetivo
     */
    protected function resolve_objetivo(array $data): ?float
    {
        if ($this->suggestion->modo === 'maximo') {
            if ($data['stock_max'] !== null) {
                return $data['stock_max'];
            }
            // Sin máximo definido se degrada al ideal, y sin ideal al mínimo
            // (misma cadena de fallback que el resto de los modos).
            if ($data['ideal'] !== null) {
                return $data['ideal'];
            }
            return $data['stock_min'];
        }

        if ($this->suggestion->modo === 'ideal') {
            if ($data['ideal'] !== null) {
                return $data['ideal'];
            }
            // Sin máximo definido: ideal = mínimo si existe
            return $data['stock_min'];
        }

        return $data['stock_min'];
    }

    /**
     * Stock mínimo que debe quedar en el depósito origen según limite_origen.
     *
     * @param array $origin
     * @return float
     */
    protected function resolve_limite_origen(array $origin): float
    {
        if ($this->suggestion->limite_origen === 'ideal') {
            return $origin['ideal'] ?? ($origin['stock_min'] ?? 0);
        }
        if ($this->suggestion->limite_origen === 'sin_limite') {
            return 0;
        }
        if ($this->suggestion->limite_origen === 'minimo') {
            return $origin['stock_min'] ?? 0;
        }

        return $origin['stock_min'] ?? 0;
    }

    /**
     * Elige el depósito origen en cuatro escalones de preferencia:
     *
     *   (1) designados (es_deposito_origen) sin déficit
     *   (2) designados aunque estén en déficit — marcar un depósito no puede
     *       dejarlo afuera para siempre por estar bajo su propio mínimo
     *   (3) no designados sin déficit (el comportamiento histórico)
     *   (4) cualquiera — todos en déficit: usar el que pueda mover algo
     *
     * Un designado solo cuenta en (1)/(2) si tiene stock por encima de su
     * propio limite_origen: un depósito designado y vacío no puede paralizar
     * la reposición de toda la red, así que se cae a (3).
     *
     * Dentro del escalón elegido se aplica el criterio `origen` de siempre
     * (absoluto = mayor stock; relativo = mayor % sobre el máximo).
     *
     * @param array $stock_data
     * @param array $deficits
     * @return array|null
     */
    protected function obtenerOrigen(array $stock_data, array $deficits): ?array
    {
        $deficit_ids = array_column($deficits, 'to_address_id');

        $designados_con_stock = collect($stock_data)->filter(function ($d) {
            return $d['es_deposito_origen'] && $d['amount'] > $this->resolve_limite_origen($d);
        });

        // Escalón 1: designados sin déficit.
        $candidatos = $designados_con_stock->filter(function ($d) use ($deficit_ids) {
            return !in_array($d['address_id'], $deficit_ids);
        });

        // Escalón 2: designados aunque estén en déficit.
        if ($candidatos->isEmpty()) {
            $candidatos = $designados_con_stock;
        }

        // Escalón 3: sin designados utilizables, comportamiento histórico.
        if ($candidatos->isEmpty()) {
            $candidatos = collect($stock_data)->filter(function ($d) use ($deficit_ids) {
                return !$d['es_deposito_origen'] && !in_array($d['address_id'], $deficit_ids);
            });
        }

        // Escalón 4: todos en déficit — usar el que más stock tenga para poder mover algo.
        if ($candidatos->isEmpty()) {
            $candidatos = collect($stock_data);
        }

        if ($candidatos->isEmpty()) {
            return null;
        }

        if ($this->suggestion->origen === 'relativo') {
            return $candidatos->sortByDesc(function ($d) {
                $max = $d['stock_max'] ?? $d['stock_min'] ?? 1;
                return $d['amount'] / max($max, 1);
            })->first();
        }

        return $candidatos->sortByDesc('amount')->first();
    }
}
