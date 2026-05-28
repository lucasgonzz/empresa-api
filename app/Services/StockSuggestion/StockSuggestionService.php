<?php

namespace App\Services\StockSuggestion;

use App\Models\Article;
use Illuminate\Support\Collection;

/**
 * Calcula traslados sugeridos entre depósitos según stock min/max por artículo.
 */
class StockSuggestionService
{
    /** @var \App\Models\StockSuggestion Configuración de la sugerencia (modo, origen, límite) */
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

        $query = Article::with(['addresses']);
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
                'is_central' => $address->is_central ?? false,
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
     * Objetivo de stock según modo de la sugerencia.
     *
     * @param array $data Datos de un depósito del artículo
     * @return float|null null si no hay datos para calcular objetivo
     */
    protected function resolve_objetivo(array $data): ?float
    {
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
     * Elige depósito origen: central (si no está en déficit), luego sin déficit, luego el de mayor stock.
     *
     * @param array $stock_data
     * @param array $deficits
     * @return array|null
     */
    protected function obtenerOrigen(array $stock_data, array $deficits): ?array
    {
        $deficit_ids = array_column($deficits, 'to_address_id');

        $central = collect($stock_data)->firstWhere('is_central', true);
        if ($central && !in_array($central['address_id'], $deficit_ids)) {
            return $central;
        }

        $candidatos = collect($stock_data)->filter(function ($d) use ($deficit_ids) {
            return !in_array($d['address_id'], $deficit_ids);
        });

        if ($candidatos->isEmpty()) {
            // Todos en déficit: usar el que más stock tenga para poder mover algo
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
