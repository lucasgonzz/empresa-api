<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
use Illuminate\Http\Request;

class VenderController extends Controller
{
    function search_bar_code($code) {

        $article = Article::where('user_id', $this->userId());
        
        $variant_id = null; 
        $variant = null; 

        if (UserHelper::hasExtencion('codigos_de_barra_basados_en_numero_interno')) {

            if (str_contains($code, '@')) {
                // El codigo es el id de una variante
                $variant = ArticleVariant::find(substr($code, 1));

                if (!is_null($variant)) {
                    
                    $variant_id = $variant->id; 
                    $variant = $variant; 

                    $article_id = $variant->article_id;
                }
                $article = $article->where('id', $article_id);
            } else {

                $article = $article->where('num', $code);
            }
        } else if (UserHelper::hasExtencion('codigo_proveedor_en_vender')) {
            $article = $article->where('provider_code', $code);
        } else {

            $article = $article->where('bar_code', $code);
        }

        $article = $article->withAll()
                        ->first();

        return response()->json(['article' => $article, 'variant_id' => $variant_id, 'variant' => $variant], 200);
    }
}
