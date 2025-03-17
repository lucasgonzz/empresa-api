<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateSaleHelper {
	
	static function update_sale_returned_items($request) {

		$sale = Sale::find($request->sale_id);
		
		foreach ($request->items as $item) {
			
			if (isset($item['is_article'])) {

				if (isset($item['returned_amount'])) {

					if (
						isset($item['article_variant_id'])
						&& $item['article_variant_id'] != 0
					) {

						DB::table('article_sale')
						    ->where('sale_id', $sale->id)
						    ->where('article_id', $item['id'])
						    ->where('article_variant_id', $item['article_variant_id'])
						    ->update(['returned_amount' => $item['returned_amount']]);

						Log::info('Actualizando la en base a article_variant_id: '.$item['article_variant_id']);

					} else {

						$sale->articles()->updateExistingPivot($item['id'], [
							'returned_amount'	=> $item['returned_amount'],
						]);
					}

				}

			}
		}
	}
}