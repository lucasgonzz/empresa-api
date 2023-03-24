<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Http\Controllers\Helpers\GeneralHelper;
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
											'order_production_status_id' => GeneralHelper::getPivotValue($article, 'order_production_status_id'),
										]);
		}
	}

}