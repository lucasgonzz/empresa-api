<?php

namespace App\Services\MercadoLibre;

use App\Models\Article;
use App\Services\MercadoLibre\CategoryService;
use App\Services\MercadoLibre\MercadoLibreService;

class ProductService extends MercadoLibreService
{
    public function sync_article(Article $article)
    {

        $category_service = new CategoryService();
        $meli_category_id = $category_service->resolve_meli_category_for_article($article);

        $meli_payload = [
            'title' => $article->name,
            'price' => $article->price,
            'available_quantity' => $article->stock,
            'category_id' => $meli_category_id, // fallback
            'pictures' => array_map(function ($image) {
                return ['source' => $image->url];
            }, $article->images->toArray()),
        ];

        $meli_id = $article->meli_id;

        if ($meli_id) {
            $this->make_request('put', "https://api.mercadolibre.com/items/{$meli_id}", $meli_payload);
        } else {
            $response = $this->make_request('post', 'https://api.mercadolibre.com/items', $meli_payload);
            $article->meli_id = $response['id'];
            $article->save();
        }
    }
}
