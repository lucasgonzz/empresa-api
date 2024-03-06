<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\StockMovementController;

class NotaCreditoHelper {
	
	static function resetUnidadesDevueltas($nota_credito) {
		if (!is_null($nota_credito->sale) && count($nota_credito->articles) >= 1) {
			$sale = $nota_credito->sale;
			foreach ($nota_credito->articles as $article_nota_credito) {
				foreach ($sale->articles as $article_sale) {
					if ($article_sale->id == $article_nota_credito->id) {

						$new_returned_amount = $article_sale->pivot->returned_amount - $article_nota_credito->pivot->amount;
						$sale->articles()->updateExistingPivot($article_sale->id, [
							'returned_amount'	=> $new_returned_amount,	
						]);

						
		                $ct = new StockMovementController();
		                $request = new \Illuminate\Http\Request();
		                
		                $request->model_id = $article_nota_credito->id;
		                $request->to_address_id = $sale->address_id;
		                $request->amount = -(float)$article_nota_credito->pivot->amount;
		                $request->nota_credito_id = $nota_credito->id;
		                $request->concepto = 'Eliminacion Nota C. N° '.$nota_credito->num_receipt.' - Venta N° '.$sale->num;
		                $ct->store($request);
					}
				}
			}
		}
	}

}