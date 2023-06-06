<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Notifications\ProviderOrderCreated;
use App\Notifications\UpdatedArticle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProviderOrderHelper {

	static function deleteCurrentAcount($provider_order) {
		$current_acount = CurrentAcount::where('provider_order_id', $provider_order->id)->first();
		if (!is_null($current_acount)) {
			Log::info('Eliminando current_acount');
            $current_acount->pagado_por()->detach();
            $current_acount->delete();
			$current_acount->delete(); 
			CurrentAcountHelper::checkSaldos('provider', $provider_order->provider_id, $current_acount);
		} else {
			Log::info('No se elimino current_acount');
		}
	}

	static function sendEmail($send_email, $provider_order) {
		if ($send_email && !is_null($provider_order->provider->email)) {
			$provider_order->provider->notify(new ProviderOrderCreated($provider_order));
		}
	}

	static function updateArticleStock($_article, $last_received, $provider_order) {
		if ($_article['pivot']['received'] > 0) {
			$data_changed = false;
			$article = Article::find($_article['id']);
			if (is_null($article->stock)) {
				$article->stock = 0;
			}
			if ($_article['pivot']['update_cost']) {
				$cost = null;
				if (isset($_article['pivot']['received_cost']) && $_article['pivot']['received_cost'] != '') {
					$cost = $_article['pivot']['received_cost'];
				} else if (isset($_article['pivot']['cost']) && $_article['pivot']['cost'] != '') {
					$cost = $_article['pivot']['cost'];
				}
				if (!is_null($cost) && $article->cost != $cost) {
					$article->cost = $cost;
					$data_changed = true;
				}
			}
			if (!is_null($_article['pivot']['iva_id']) && $_article['pivot']['iva_id'] != 0 && $article->iva_id != $_article['pivot']['iva_id']) {
				$article->iva_id = $_article['pivot']['iva_id'];
				Log::info('Nuevo iva');
				$data_changed = true;
			}
			if (!isset($last_received[$article->id]) || $_article['pivot']['received'] != $last_received[$article->id]) {
				Log::info('Nuevo stock');
				$data_changed = true;
			}
			if (isset($last_received[$article->id])) {
				$article->stock -= $last_received[$article->id];
			}
			$article->stock += $_article['pivot']['received'];
			if ($article->status == 'inactive') {
				$article->status = 'active';
				$article->apply_provider_percentage_gain = 1;
				$article->created_at = Carbon::now();
				$data_changed = true;
			}
			if ($article->provider_id != $provider_order->provider_id || (isset($last_received[$article->id]) && $last_received[$article->id] != $_article['pivot']['received'])) {
				$data_changed = true;
				$article->provider_id = $provider_order->provider_id;
				$amount = $_article['pivot']['received'];
				if (array_key_exists($article->id, $last_received)) {
					$amount -= $last_received[$article->id];
				}
				$article->providers()->attach($provider_order->provider_id, [
										'amount' => $amount,
										'cost' 	 => $_article['pivot']['cost'],
									]);
			}
			if ($data_changed) {
				$article->save();
				ArticleHelper::setFinalPrice($article);
				$ct = new Controller();
	        	$ct->sendAddModelNotification('article', $article->id, false);
			}
		}
	}

	static function attachArticles($articles, $provider_order) {
		$last_received = Self::getLastReceived($provider_order);
		// Self::deleteInactiveArticles($provider_order);
		$provider_order->articles()->sync([]);
		foreach ($articles as $article) {
			if ($article['status'] == 'inactive') {
				$art = Article::find($article['id']);
				if (!is_null($art)) {
					$art->bar_code = $article['bar_code'];
					$art->provider_code = $article['provider_code'];
					$art->name = $article['name'];
					$art->save();
				}
			} 
			$cost = null;
			if (isset($article['pivot']['cost'])) {
				$cost = $article['pivot']['cost'];
			}
			if ($article['status'] == 'active' && is_null($cost) && !is_null($article['cost'])) {
				$cost = $article['cost'];
			}
			$provider_order->articles()->attach($article['id'], [
											'amount' 			=> GeneralHelper::getPivotValue($article, 'amount'),
											'notes' 			=> GeneralHelper::getPivotValue($article, 'notes'),
											'received' 			=> GeneralHelper::getPivotValue($article, 'received'),
											'cost' 				=> $cost,
											'received_cost' 	=> GeneralHelper::getPivotValue($article, 'received_cost'),
											'update_cost' 		=> GeneralHelper::getPivotValue($article, 'update_cost'),
											'cost_in_dollars'	=> GeneralHelper::getPivotValue($article, 'cost_in_dollars'),
											'iva_id'    		=> Self::getIvaId($article),
										]);
			Self::updateArticleStock($article, $last_received, $provider_order);
		}
		Self::saveCurrentAcount($provider_order);
	}

	static function deleteInactiveArticles($provider_order) {
		foreach ($provider_order->articles as $article) {
			if ($article->status == 'inactive') {
				$article->delete();
			}
		}
	}

	static function getIvaId($article) {
		if ($article['status'] == 'active' && $article['pivot']['iva_id'] == 0) {
			return $article['iva_id'];
		}
		return $article['pivot']['iva_id'];
	}

	static function attachAfipTickets($afip_tickets, $model) {
		foreach ($afip_tickets as $afip_ticket) {
			$_afip_ticket = null;
			if (!isset($afip_ticket['id']) && ($afip_ticket['code'] != '' || $afip_ticket['issued_at'] != '' || $afip_ticket['total'] != '')) {
				$_afip_ticket = ProviderOrderAfipTicket::create([
					'provider_order_id' => $model->id,
				]);
			} else if (isset($afip_ticket['id'])) {
				$_afip_ticket = ProviderOrderAfipTicket::find($afip_ticket['id']);
			}
			if (!is_null($_afip_ticket)) {
				$_afip_ticket->issued_at 	= $afip_ticket['issued_at'];
				$_afip_ticket->code 		= $afip_ticket['code'];
				$_afip_ticket->total 		= $afip_ticket['total'];
				$_afip_ticket->save();
			}
		}
	}

	static function saveCurrentAcount($provider_order) {
		$total = Self::getTotal($provider_order->id);
		Log::info('Total del pedido '.$provider_order->num.': '.$total);
		if ($total > 0) {
			Log::info('entro a total > 0');
			$current_acount = CurrentAcount::where('provider_order_id', $provider_order->id)->first();
			// Log::info('current_acount_id: '.$current_acount->id);
			if (is_null($current_acount)) {
				$current_acount = CurrentAcount::create([
					'detalle' 			=> 'Pedido N°'.$provider_order->num,
					'debe'				=> $total,
					'status' 			=> 'sin_pagar',
					'user_id'			=> UserHelper::userId(),
					'provider_id'		=> $provider_order->provider_id,
					'provider_order_id'	=> $provider_order->id,
				]);
				$current_acount->saldo = CurrentAcountHelper::getSaldo('provider', $provider_order->provider_id, $current_acount) + $total;
				$current_acount->save();
				Log::info('Se creo current_acount con saldo de: '.$current_acount->saldo);
			} else if ($current_acount->debe != $total) {
				$current_acount->debe = $total;
				$current_acount->saldo = CurrentAcountHelper::getSaldo('provider', $provider_order->provider_id, $current_acount) + $total;
				$current_acount->save();
				CurrentAcountHelper::checkSaldos('provider', $provider_order->provider_id, $current_acount);

				Log::info('Se actualizo current_acount con saldo de: '.$current_acount->saldo);
			}
	        $provider_order->provider->pagos_checkeados = 0;
	        $provider_order->provider->save();
		}
	}

	static function getTotal($id) {
		$provider_order = ProviderOrder::find($id);
		$user = UserHelper::getFullModel();
		$total = 0;
		if ((boolean)$provider_order->total_from_provider_order_afip_tickets) {
			foreach ($provider_order->provider_order_afip_tickets as $afip_ticket) {
				$total += $afip_ticket->total;
			}
		} else {
			foreach ($provider_order->articles as $article) {
				if ($article->pivot->cost != '' && $article->pivot->received > 0) {
					$cost = $article->pivot->cost;
					if (!is_null($article->pivot->received_cost)) {
						$cost = $article->pivot->received_cost;
					}
					if ($article->pivot->cost_in_dollars) {
						if (!is_null($provider_order->provider->dolar)) {
							$cost *= $provider_order->provider->dolar;
						} else if (!is_null($user->dollar)) {
							Log::info('cost esta en '.$cost);
							Log::info('sumando dolar de usuario de '.$user->dollar);
							$cost *= $user->dollar;
							Log::info('cost quedo en '.$cost);
						}
					}
					$total_article = $cost * $article->pivot->received;
					if ($provider_order->total_with_iva && !is_null($article->pivot->iva_id) && $article->pivot->iva_id != 0) {
						$ct = new Controller();
						$iva = $ct->getModelBy('ivas', 'id', $article->pivot->iva_id);
						if ($iva->percentage != 'No Gravado' && $iva->percentage != 'Exento' && $iva->percentage != 0)
						$total_article += $total_article * $iva->percentage / 100;
					}
					$total += $total_article;
				}
			}
		}
		foreach ($provider_order->provider_order_extra_costs as $extra_cost) {
			$total += $extra_cost->value;
		}
		return $total;
	}

	static function getLastReceived($provider_order) {
		$last_received = [];
		foreach ($provider_order->articles as $article) {
			$last_received[$article->id] = $article->pivot->received;
		}
		return $last_received;
	}

	static function setArticles($provider_orders) {
		foreach ($provider_orders as $provider_order) {
			foreach ($provider_order->articles as $article) {
				$article->amount = $article->pivot->amount;
				$article->notes = $article->pivot->notes;
				$article->received = $article->pivot->received;
			}
		}
		return $provider_orders;
	}

}