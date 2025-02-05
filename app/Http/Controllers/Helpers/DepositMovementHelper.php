<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Stock\StockMovementController;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DepositMovementHelper {

	public $deposit_movement;
	public $previus_articles;
	
	function __construct($deposit_movement) {

		$this->deposit_movement = $deposit_movement;

		$this->set_previus_articles();

	}

	function set_previus_articles() {

		$this->previus_articles = [];

		foreach ($this->deposit_movement->articles as $article) {

			$this->previus_articles[$article->id] = $article->pivot->amount;
		}
	}


	function attach_articles($articles) {

		$this->deposit_movement->articles()->sync([]);
		
		foreach ($articles as $article) {

			$this->deposit_movement->articles()->attach($article['id'], [
				'amount'				=> $article['pivot']['amount'],
				'article_variant_id'	=> $article['pivot']['article_variant_id'],
			]);
		}
	}

	function check_status() {

		if (!is_null($this->deposit_movement->deposit_movement_status) 
			&& $this->deposit_movement->deposit_movement_status->name == 'Recibido') {

			$this->set_fecha_recibido();

			$this->actualizar_stock();
		}
	}

	function set_fecha_recibido() {

		$this->deposit_movement->recibido_at = Carbon::now();
		$this->deposit_movement->save();
	}

	function actualizar_stock() {

		$this->deposit_movement->load('articles');

		foreach ($this->deposit_movement->articles as $article) {
			
			$this->crear_stock_movement($article);
		}
	}

	function crear_stock_movement($article) {

		$ct_stock_movement = new StockMovementController();

		$data = [];

		$data['model_id'] = $article->id;
		$data['amount'] = $article->pivot->amount;

		if (!is_null($article->pivot->article_variant_id)
			&& $article->pivot->article_variant_id != 0) {
		
			$data['article_variant_id'] = $article->pivot->article_variant_id;
		}

		$data['deposit_movement_id'] = $this->deposit_movement->id;
		
		$data['from_address_id'] = $this->deposit_movement->from_address_id;
		$data['to_address_id'] = $this->deposit_movement->to_address_id;

		$data['employee_id'] = $this->deposit_movement->employee_id;
		$data['concepto_stock_movement_name'] = 'Mov entre depositos';
		
		Log::info('Se va a mandar a guardar stock_movement');

        $ct_stock_movement->crear($data);
	}
	
}