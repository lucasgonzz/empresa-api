<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\User;

class RecalculateCurrentAcountsHelper {

	static function recalculate($company_name) {
        $user = User::where('company_name', $company_name)->first();

        // Se ponen en sin pagar las cuentas de las ventas

		$clients = Client::where('user_id', $user->id)
						->get();

		foreach ($clients as $client) {
			$compras = CurrentAcount::where('client_id', $client->id)
									->whereNotNull('debe')
									->orderBy('created_at', 'ASC')
									->get();
			foreach ($compras as $compra) {
				if (!is_null($compra->sale)) {
					$compra->detalle = 'Venta N°'.$compra->sale->num;
					$compra->status = 'sin_pagar';
					$compra->pagandose = 0;
					$compra->save();
				} else {
					echo 'No estaba la venta de la cuenta corriente id: '.$compra->id.' del cliente '.$client->name.' </br>';
					$compra->delete();
				}
			}
			$pagos = CurrentAcount::where('client_id', $client->id)
									->whereNotNull('haber')
									->orderBy('created_at', 'ASC')
									->get();
			foreach ($pagos as $pago) {
		        $pago->saldo = CurrentAcountHelper::getSaldo('client', $client->id, $pago) - $pago->haber;
		        $pago->detalle = CurrentAcountHelper::procesarPago('client', $client->id, $pago->haber, $pago, $pago->to_pay_id);
		        $pago->save();
		        echo 'Se proceso pago del cliente '.$client->name.' </br>';
			}
			echo '------------------------------------------- </br>';
		}

		echo 'Terminado';

	}

}