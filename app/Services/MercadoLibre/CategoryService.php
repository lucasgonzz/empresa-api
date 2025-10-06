<?php

namespace App\Services\MercadoLibre;

use App\Models\Article;
use App\Models\MeliAttribute;
use App\Models\MeliAttributeTag;
use App\Models\MeliAttributeValue;
use App\Models\MeliCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class CategoryService extends MercadoLibreService
{

    function __construct($user_id = null) {
        parent::__construct($user_id);
    }

    /**
     * Llama al predictor de Mercado Libre basado en el nombre del artículo.
     */
    public function fetch_meli_categories(string $query)
    {

        $response = Http::get("https://api.mercadolibre.com/sites/MLA/domain_discovery/search", [
            'q' => $query,
        ]);

        Log::info('response categories:');
        Log::info($response);

        return json_decode($response, true);
    }

    /**
     * Asigna una categoría de Mercado Libre al artículo.
     */
    public function assign_to_article(Article $article, string $mercado_libre_category_id)
    {

        Log::info('assign_to_article para $mercado_libre_category_id: '.$mercado_libre_category_id);

        $meli_category = MeliCategory::where('meli_category_id', $mercado_libre_category_id)
                                        ->first();

        if (!$meli_category) {
            Log::info('La categoria no esta creada');
            $meli_category = $this->create_meli_category_y_asignar_attributes($mercado_libre_category_id);
        }

        $article->meli_category_id = $meli_category->id;
        $article->save();

        return $article;
    }

    /**
     * Determina la categoría ML del artículo.
     * Prioriza:
     * - meli_category_id del artículo
     * - predictor automático si no está seteado
     */
    public function resolve_meli_category_for_article(Article $article)
    {
        $mercado_libre_category_id = null;

        if ($article->meli_category) {
            $mercado_libre_category_id = $article->meli_category->meli_category_id; 
        }

        if (!$mercado_libre_category_id) {
            
            $suggestions = $this->fetch_meli_categories($article->name);

            Log::info('resolve_meli_category_for_article:');
            Log::info($suggestions);

            if (count($suggestions) > 0) {
                $mercado_libre_category_id = $suggestions[0]['category_id'];
            } else {
                throw new \Exception("No se pudo determinar la categoría de Mercado Libre para el artículo ID {$article->id}");
            }

        }

        if ($mercado_libre_category_id) {

            $article = $this->assign_to_article($article, $mercado_libre_category_id);
        }

        return $article;

    }

    function create_meli_category_y_asignar_attributes($meli_category_id) {

        $response = $this->make_request('get', "categories/{$meli_category_id}");

        Log::info('create_meli_category_y_asignar_attributes:');
        Log::info($response);

        $meli_category = MeliCategory::create([
            'meli_category_id'      => $response['id'],
            'meli_category_name'    => $response['name'],
        ]);

        $this->asignar_attributes($meli_category);

        return $meli_category;
    }

    function asignar_attributes($meli_category) {

        $attributes = $this->make_request('get', "categories/{$meli_category->meli_category_id}/attributes");

        foreach ($attributes as $attribute) {

            $meli_attribute = MeliAttribute::create([

                'meli_id'                   => $attribute['id'] ?? null,
                'name'                      => $attribute['name'] ?? null,
                'relevance'                 => $attribute['relevance'] ?? null,
                'value_type'                => $attribute['value_type'] ?? null,
                'value_max_length'          => $attribute['value_max_length'] ?? null,
                'default_unit'              => $attribute['default_unit'] ?? null,
                'tooltip'                   => $attribute['tooltip'] ?? null,
                'hint'                      => $attribute['hint'] ?? null,
                'hierarchy'                 => $attribute['hierarchy'] ?? null,
                'example'                   => $attribute['example'] ?? null,
                'attribute_group_id'        => $attribute['attribute_group_id'] ?? null,
                'attribute_group_name'      => $attribute['attribute_group_name'] ?? null,

                'meli_category_id'          => $meli_category->id,
            ]);

            Log::info('Se creo el meli_attribute: ');
            Log::info($meli_attribute->toArray());

            if (
                isset($attribute['values'])
                && is_array($attribute['values'])
            ) {

                foreach ($attribute['values'] as $value) {

                    $meli_attribute_value = MeliAttributeValue::create([
                        'meli_id'               => $value['id'],
                        'meli_name'             => $value['name'],
                        'meli_attribute_id'     => $meli_attribute->id,
                    ]);

                    Log::info('Se creo el meli_attribute_value: ');
                    Log::info($meli_attribute_value->toArray());
                }

            }

            if (
                isset($attribute['tags'])
                && is_array($attribute['tags'])
            ) {

                foreach ($attribute['tags'] as $value) {

                    MeliAttributeTag::create([
                        'slug'                  => $value,
                        'meli_attribute_id'     => $meli_attribute->id,
                    ]);
                }

            }
        }

    }
}
