<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\MessageHelper;
use App\Models\Article;
use App\Models\ArticleCart;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;

class CartArticleAmountInsificienteHelper {
	
    static function checkCartsAmounts($article) {
        // $article = Article::find($article_id);
        $articles_cart = ArticleCart::where('article_id', $article->id)
                            		->get();

        foreach ($articles_cart as $article_cart) {
        	$buyer = Cart::find($article_cart->cart_id)->buyer;

        	if ($article_cart->amount_insuficiente > 0 
        		|| $article->stock < $article_cart->amount) {

        		$text = 'Hola '.$buyer->name.'. ';

        		$amount_requerida = $article_cart->amount + $article_cart->amount_insuficiente;
	        	
	        	if ($article->stock >= $amount_requerida) {
	        		$text .= 'Ingreso stock de "'.$article->name.'". Por lo que hemos restaurado tu carrito a '.$amount_requerida.' unidades.';

	        		$article_cart->amount_insuficiente = 0;
	        		$article_cart->amount = $amount_requerida;
	        	} else if ($article->stock >= $article_cart->amount) {
	        		$text .= 'Ingreso stock de "'.$article->name.'". Por lo que hemos actualizado tu carrito a '.$article->stock.' unidades.';

	        		$article_cart->amount_insuficiente = $amount_requerida - $article->stock;
	        		$article_cart->amount = $article->stock;
	        	} else if ($article->stock < $article_cart->amount) {
	        		$text .= 'Ya no tenemos el stock suficiente de "'.$article->name.'" para entregarte las '.$article_cart->amount.' unidades que solicitaste. Por lo que hemos actualizado tu carrito a '.$article->stock.' unidades.';

	        		$article_cart->amount = $article->stock;
	        		$article_cart->amount_insuficiente = $amount_requerida - $article->stock;
	        	}
	            
	        	$article_cart->save();

	            MessageHelper::sendCartArticleAmountUpdatedMessage($text, $buyer);

        	}
        }
    }

}