<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\SuperBudgetFeature;
use Carbon\Carbon;

class SuperBudgetHelper {

	static function attachFeatures($model, $features) {
		Self::deletecurrentFeatures($model);
		foreach ($features as $feature) {
			SuperBudgetFeature::create([
				'title' 			=> $feature['title'],
				'description' 		=> $feature['description'],
				'development_time' 	=> $feature['development_time'],
				'total' 			=> $feature['total'],
				'super_budget_id' 	=> $model->id,
			]);
		}
		return $model;
	}

	static function deletecurrentFeatures($model) {
		$models = SuperBudgetFeature::where('super_budget_id', $model->id)
									->pluck('id');
		SuperBudgetFeature::destroy($models);
	}
	
}