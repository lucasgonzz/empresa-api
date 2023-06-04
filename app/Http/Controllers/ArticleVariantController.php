<?php

namespace App\Http\Controllers;

use App\Models\ArticleVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ArticleVariantController extends Controller
{
    function store(Request $request) {
        $article_id = $request->models[0]['article_id'];
        $this->deleteVariants($article_id);

        foreach ($request->models as $model) {
            $article_variant = ArticleVariant::create([
                'article_id'                => $model['article_id'],
                'price'                     => $model['price'],
                'variant_description'       => $model['variant_description'],
                'image_url'                 => $model['image_url'],
            ]);
            $this->attachArticlePropertyValues($article_variant, $model['article_property_values']);
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
            // Log::info('relacionando variant '.$article_variant->id.' del article '.$article_variant->article_id.' con article_property '.$article_property['id']);
            $article_variant->article_property_values()->attach($article_property['id']);
        }
    }
}
