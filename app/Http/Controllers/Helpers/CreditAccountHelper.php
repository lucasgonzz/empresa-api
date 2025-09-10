<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\CreditAccount;
use Illuminate\Support\Facades\Log;

class CreditAccountHelper {
	
	static function crear_credit_accounts($model_name, $model_id, $user_id = null) {
		
		if (is_null($user_id)) {
			$user_id = UserHelper::userId();
		}

		$monedas_id = [1,2];

		foreach ($monedas_id as $moneda_id) {

			CreditAccount::create([
				'moneda_id'		=> $moneda_id,
				'model_name'	=> $model_name,
				'model_id'		=> $model_id,
				'saldo'			=> 0,
				'user_id'		=> $user_id,
			]);

			Log::info('Se creo credit_account para '.$model_name.' id: '.$model_id);
		}
	}

}