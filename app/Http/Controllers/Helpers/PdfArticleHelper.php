<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Sale;
use App\Client;

class PdfArticleHelper {

	static function amount($article) {
		return $article->pivot->amount;
	}

	static function getSubTotalCost($article) {
		return $article->pivot->cost * $article->pivot->amount;
	}

	static function getSubTotalPrice($article) {
		$total = $article->pivot->price * $article->pivot->amount;
		if (!is_null($article->pivot->percentage)) {
			$total -= $total * $article->pivot->percentage / 100;
			dd('asd');
		}
		return $total;
	}

}

