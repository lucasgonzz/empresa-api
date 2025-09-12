<?php

namespace App\Services\MercadoLibre;

use App\Models\Article;

class CategoryService extends MercadoLibreService
{
    /**
     * Llama al predictor de Mercado Libre basado en el nombre del artículo.
     */
    public function fetch_meli_categories(string $query): array
    {
        $response = $this->make_request(
            'get',
            'https://api.mercadolibre.com/sites/MLA/category_predictor/predict',
            ['q' => $query]
        );

        return $response['categories'] ?? [];
    }

    /**
     * Asigna una categoría de Mercado Libre al artículo.
     */
    public function assign_to_article(Article $article, string $meli_category_id): void
    {
        $article->meli_category_id = $meli_category_id;
        $article->save();
    }

    /**
     * Determina la categoría ML del artículo.
     * Prioriza:
     * - meli_category_id del artículo
     * - predictor automático si no está seteado
     */
    public function resolve_meli_category_for_article(Article $article): string
    {
        if ($article->meli_category_id) {
            return $article->meli_category_id;
        }

        $suggestions = $this->fetch_meli_categories($article->name);

        if (count($suggestions) > 0) {
            $meli_id = $suggestions[0]['id'];
            $this->assign_to_article($article, $meli_id);
            return $meli_id;
        }

        throw new \Exception("No se pudo determinar la categoría de Mercado Libre para el artículo ID {$article->id}");
    }
}
