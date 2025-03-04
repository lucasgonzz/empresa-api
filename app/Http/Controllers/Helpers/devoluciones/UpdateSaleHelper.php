<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Models\Sale;

class UpdateSaleHelper {
	
	static function update_sale_returned_items($request) {

		$sale = Sale::find($request->sale_id);
		
		foreach ($request->items as $item) {
			
			if (isset($item['is_article'])) {

				if (isset($item['returned_amount'])) {

					$sale->articles()->updateExistingPivot($item['id'], [
						'returned_amount'	=> $item['returned_amount'],
					]);
				}

			}
		}
	}
}