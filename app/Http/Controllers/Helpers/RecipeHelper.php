<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Models\Article;
use Carbon\Carbon;

class RecipeHelper {

	static function attachArticles($recipe, $articles) {
		$recipe->articles()->sync([]);
		foreach ($articles as $article) {
			if ($article['status'] == 'inactive') {
				$art = Article::find($article['id']);
				$art->bar_code = $article['bar_code'];
				$art->provider_code = $article['provider_code'];
				$art->name = $article['name'];
				$art->save();
			} 
			$recipe->articles()->attach($article['id'], [
											'amount' 	=> GeneralHelper::getPivotValue($article, 'amount'),
											'notes' 	=> GeneralHelper::getPivotValue($article, 'notes'),
											'address_id' 	=> GeneralHelper::getPivotValue($article, 'address_id'),
											'order_production_status_id' => GeneralHelper::getPivotValue($article, 'order_production_status_id'),
										]);
		}
	}

	static function checkCostFromRecipe($recipe, $instance) {
		if ($recipe->article_cost_from_recipe) {
			$cost = 0;
			foreach ($recipe->articles as $article) {
				$cost += $article->cost * $article->pivot->amount;
			}
			$article = $recipe->article;
			$article->cost = $cost;
			$article->save();
			ArticleHelper::setFinalPrice($article);
        	$instance->sendAddModelNotification('Article', $article->id, false);
		}
	}

}