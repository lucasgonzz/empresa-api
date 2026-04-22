<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthHelper {
	
	function setEmployeeProps($user) {
		$owner = UserHelper::getFullModel();
		$user->owner_extencions = $owner->extencions;
		$user->owner_configuration = $owner->configuration;
		$user->iva_included = $owner->iva_included;
		$user->ask_amount_in_vender = $owner->ask_amount_in_vender;
		$user->owner = $owner;
		return $user;
	}

	function checkUserLastActivity() {
		Log::info('checkUserLastActivity');
		$user = Auth()->user();
		if (is_null($user->last_activity) || is_null($user->session_id) || $this->ya_paso_el_tiempo($user)) {
			session(['session_id' => time().rand(0,1000)]);
			$user->last_activity = Carbon::now();
			$user->session_id = session('session_id');
			$user->save();
			Log::info('se puso session_id: '.$user->session_id);
			return true;
		} else if ($user->session_id == session('session_id')) {
			$user->last_activity = Carbon::now();
			$user->save();
			Log::info('tiene el mismo session_id: '.$user->session_id);
			return true;
		}
		return false;
	}

	function get_activity_minutes($user) {
		$owner = $user->owner_id
			? User::find($user->owner_id)
			: $user;

		return $owner->activity_minutes ?? env('USER_ACTIVITY_MINUTES', 60);
	}

	function get_remaining_wait_minutes($user) {
		if (!$user) {
			return 0;
		}

		if (is_null($user->last_activity)) {
			return 0;
		}

		$minutes = $this->get_activity_minutes($user);
		$unlock_at = Carbon::parse($user->last_activity)->addMinutes($minutes);
		$remaining_seconds = Carbon::now()->diffInSeconds($unlock_at, false);

		if ($remaining_seconds <= 0) {
			return 0;
		}

		return (int) ceil($remaining_seconds / 60);
	}

	function ya_paso_el_tiempo($user) {
		$minutes = $this->get_activity_minutes($user);
		if (Carbon::now()->subMinutes($minutes)->gte($user->last_activity)) {
			Log::info('Ya paso el tiempo ('.$minutes.' min)');
			return true;
		} else {
			Log::info('No paso el tiempo ('.$minutes.' min)');
		}
		return false;
	}

	function removeUserLastActivity($user) {
		$minutes = $this->get_activity_minutes($user);
		$user->last_activity = Carbon::now()->subMinutes($minutes);
		Log::info('se puso last_activity en '.Carbon::now()->subMinutes($minutes));
		$user->save();
	}

}
