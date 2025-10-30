<?php

namespace App\Services\StockSuggestion;

use App\Models\Article;
use Illuminate\Support\Collection;

class StockSuggestionService
{
    protected string $modo;
    protected string $origen;
    protected string $limite_origen;

    public function __construct($suggestion)
    {
        $this->suggestion = $suggestion;
    }

    public function getSuggestions(): Collection
    {
        return $this->getSuggestionsForArticles([]);
    }

    public function getSuggestionsForArticles(array $article_ids = []): Collection
    {
        $suggestions = collect();

        $query = Article::with(['addresses']);
        if (!empty($article_ids)) {
            $query->whereIn('id', $article_ids);
        }

        $query->chunk(50, function ($articles) use (&$suggestions) {
            foreach ($articles as $article) {
                $stock_data = [];

                foreach ($article->addresses as $address) {
                    $pivot = $address->pivot;

                    if (!isset($pivot->amount, $pivot->stock_min, $pivot->stock_max)) {
                        continue;
                    }

                    $ideal = ($pivot->stock_min + $pivot->stock_max) / 2;

                    $stock_data[] = [
                        'address_id' => $address->id,
                        'amount' => $pivot->amount,
                        'stock_min' => $pivot->stock_min,
                        'stock_max' => $pivot->stock_max,
                        'ideal' => $ideal,
                        'is_central' => $address->is_central ?? false,
                    ];
                }

                if (empty($stock_data)) continue;

                // Calcular depósitos con déficit
                $deficits = [];
                foreach ($stock_data as $data) {
                    $objetivo = $this->suggestion->modo === 'ideal' ? $data['ideal'] : $data['stock_min'];
                    if ($data['amount'] < $objetivo) {
                        $deficits[] = [
                            'to_address_id' => $data['address_id'],
                            'needed' => round($objetivo - $data['amount']),
                        ];
                    }
                }

                if (empty($deficits)) continue;

                // Obtener depósito origen
                $origin = $this->obtenerOrigen($stock_data, $deficits);
                if (!$origin) continue;

                // Calcular cuánto puede mover desde el origen
                $limite = 0;
                if ($this->suggestion->limite_origen === 'ideal') {
                    $limite = $origin['ideal'];
                } elseif ($this->suggestion->limite_origen === 'minimo') {
                    $limite = $origin['stock_min'];
                } elseif ($this->suggestion->limite_origen === 'sin_limite') {
                    $limite = 0;
                } else {
                    $limite = $origin['stock_min'];
                }

                $disponible = max(0, $origin['amount'] - $limite);

                foreach ($deficits as $deficit) {
                    if ($disponible <= 0) break;

                    $mover = min($deficit['needed'], $disponible);
                    if ($mover > 0) {
                        $suggestions->push([
                            'article_id' => $article->id,
                            'from_address_id' => $origin['address_id'],
                            'to_address_id' => $deficit['to_address_id'],
                            'suggested_amount' => $mover,
                        ]);
                        $disponible -= $mover;
                    }
                }
            }
        });

        return $suggestions;
    }

    protected function obtenerOrigen(array $stock_data, array $deficits): ?array
    {
        // Si hay un depósito central, lo usamos como origen
        $central = collect($stock_data)->firstWhere('is_central', true);
        if ($central) return $central;

        // Si no, elegir entre los que no están en déficit
        $candidatos = collect($stock_data)->filter(function ($d) use ($deficits) {
            return !in_array($d['address_id'], array_column($deficits, 'to_address_id'));
        });

        if ($candidatos->isEmpty()) return null;

        return $this->suggestion->origen === 'relativo'
            ? $candidatos->sortByDesc(fn($d) => $d['amount'] / max($d['stock_max'], 1))->first()
            : $candidatos->sortByDesc('amount')->first();
    }
}
