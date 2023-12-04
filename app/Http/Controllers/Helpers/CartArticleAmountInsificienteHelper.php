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

        	if (!is_null($buyer) &&
        		($article_cart->amount_insuficiente > 0 
        		|| $article->stock < $article_cart->amount)) {

        		$enviar_mensaje = false;

        		$text = 'Hola '.$buyer->name.'. ';

        		$amount_requerida = $article_cart->amount + $article_cart->amount_insuficiente;
	        	
	        	if ($article->stock >= $amount_requerida) {
	        		$text .= 'Ingreso stock de "'.$article->name.'". Por lo que hemos restaurado tu carrito a '.$amount_requerida.' unidades.';

	        		$article_cart->amount_insuficiente = 0;
	        		$article_cart->amount = $amount_requerida;
        			$enviar_mensaje = true;
	        	} else if ($article->stock >= $article_cart->amount) {
	        		if ($article->stock > 0) {
        				$enviar_mensaje = true;
	        		}
	        		$text .= 'Ingreso stock de "'.$article->name.'". Por lo que hemos actualizado tu carrito a '.$article->stock.' unidades.';

	        		$article_cart->amount_insuficiente = $amount_requerida - $article->stock;
	        		$article_cart->amount = $article->stock;
	        	} else if ($article->stock < $article_cart->amount && $article_cart->amount > 0) {
	        		$new_cart_amount = $article->stock;
	        		if ($new_cart_amount < 0) {
	        			$new_cart_amount = 0;
	        		}
	        		$text .= 'Ya no tenemos el stock suficiente de "'.$article->name.'" para entregarte las '.$article_cart->amount.' unidades que solicitaste. Por lo que hemos actualizado tu carrito a '.$new_cart_amount.' unidades.';

	        		$article_cart->amount = $new_cart_amount;
	        		$article_cart->amount_insuficiente = $amount_requerida - $new_cart_amount;
	        		// $article_cart->amount_insuficiente = $amount_requerida - $article->stock;
        			$enviar_mensaje = true;
	        	}
	            
	        	$article_cart->save();

	        	if ($enviar_mensaje) {
	            	MessageHelper::sendCartArticleAmountUpdatedMessage($text, $buyer);
	        	}


        	}
        }
    }

}