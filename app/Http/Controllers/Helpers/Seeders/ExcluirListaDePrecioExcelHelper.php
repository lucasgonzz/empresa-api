<?php

namespace App\Http\Controllers\Helpers\Seeders;

use App\Models\Article;
use App\Models\PriceType;


class ExcluirListaDePrecioExcelHelper {
	   
    static function set_articles() {
        $articles = Article::where('user_id', config('app.USER_ID'))
                            ->get();

        $price_types = PriceType::where('user_id', config('app.USER_ID'))
                                ->get();

        foreach ($articles as $article) {
            
            foreach ($price_types as $price_type) {
                
                if ($price_type->incluir_en_lista_de_precios_de_excel) {

                    $article->price_types()->updateExistingPivot($price_type->id, [
                        'incluir_en_excel_para_clientes'   => 1,
                    ]);
                }
            }
        }
    }

}