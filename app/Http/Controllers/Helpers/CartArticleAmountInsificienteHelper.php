<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\MessageHelper;
use App\Models\Article;
use App\Models\ArticleCart;
use App\Models\Cart;
use App\Models\OnlineConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CartArticleAmountInsificienteHelper {
	
    static function checkCartsAmounts($article) {
        // $article = Article::find($article_id);

        // Si la tienda del comercio no pone tope de cantidad para este articulo, no hay nada que recortar ni avisar.
        if (!self::tienda_pone_tope_de_cantidad($article)) {
            return;
        }

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

    /**
     * Indica si la tienda del dueno del articulo pone tope de cantidad en el carrito.
     * Mismo criterio que tienda-api (ConsultaStockHelper::clasificar): con ignorar_stock prendido,
     * con stock null (y stock_null_equal_0 apagado) o en modo Consultar (permitir_pedidos_sin_stock
     * prendido e ignorar_stock apagado) con stock exactamente 0, no hay tope. Sin configuracion
     * online se conserva el comportamiento de siempre (hay tope).
     *
     * @param  \App\Models\Article $article Articulo cuyo stock se acaba de mover.
     * @return bool true si la tienda limita la cantidad al stock.
     */
    static function tienda_pone_tope_de_cantidad($article) {
        // Configuracion online del dueno del articulo.
        $config = OnlineConfiguration::where('user_id', $article->user_id)->first();

        if (is_null($config)) {
            return true;
        }

        // Las columnas nuevas pueden no existir en bases viejas.
        $ignorar_stock = Schema::hasColumn('online_configurations', 'ignorar_stock')
                            && (int) $config->ignorar_stock == 1;
        $permitir_sin_stock = Schema::hasColumn('online_configurations', 'permitir_pedidos_sin_stock')
                            && (int) $config->permitir_pedidos_sin_stock == 1;

        if ($ignorar_stock) {
            return false;
        }

        if (is_null($article->stock)) {
            return (int) $config->stock_null_equal_0 == 1;
        }

        if ($permitir_sin_stock && (float) $article->stock == 0) {
            return false;
        }

        return true;
    }

}