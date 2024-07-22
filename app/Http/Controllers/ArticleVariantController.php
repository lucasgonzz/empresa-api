<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ArticleVariantHelper;
use App\Models\ArticleVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ArticleVariantController extends Controller
{
    function store(Request $request) {
        $article_id = $request->article_id;

        $helper = new ArticleVariantHelper($article_id);

        $helper->check_cambio_en_cantidad_propiedades($request->models);

        foreach ($request->models as $model) {

            // Chequear si hay mas propiedades que antes, si es asi eliminar todo lo que esta hasta el momento





            if (!$helper->variant_ya_esta_creada($model)) {

                $article_variant = ArticleVariant::create([
                    'article_id'                => $model['article_id'],
                    'price'                     => null,
                    'variant_description'       => $model['variant_description'],
                    // 'image_url'                 => $model['image_url'],
                ]);

                $this->attachArticlePropertyValues($article_variant, $model['article_property_values']);

                Log::info('se creo variante '.$article_variant['variant_description']);
            } 
        }
        $models = ArticleVariant::where('article_id', $article_id)
                                    ->withAll()
                                    ->get();
        return response()->json(['models' => $models], 201);
    }

    function update(Request $request, $id) {
        $model = ArticleVariant::find($id);
        $model->stock = $request->stock;
        $model->price = $request->price;
        $model->image_url = $request->image_url;
        $model->save();

        return response()->json(['model' => $model], 200);
    }

    function deleteVariants($article_id) {
        ArticleVariant::where('article_id', $article_id)->delete();
    }

    function attachArticlePropertyValues($article_variant, $article_properties) {
        foreach ($article_properties as $article_property) {
            $article_variant->article_property_values()->attach($article_property['id']);
        }
    }
}
