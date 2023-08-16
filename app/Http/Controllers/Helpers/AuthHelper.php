<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AuthHelper {
	
	function setEmployeeProps($user) {
		$owner = User::find($user->owner_id);
		$user->owner_extencions = $owner->extencions;
		$user->owner_configuration = $owner->configuration;
		$user->iva_included = $owner->iva_included;
		$user->ask_amount_in_vender = $owner->ask_amount_in_vender;
		// $user->owner = $owner;
		return $user;
	}

	function checkUserLastActivity($user) {
		Log::info('last_activity: '.$user->last_activity.' carbon::now: '.Carbon::now().', carbon menos '.env('USER_ACTIVITY_MINUTES').'min: '.Carbon::now()->subMinutes(env('USER_ACTIVITY_MINUTES')));
		if (is_null($user->last_activity) || Carbon::now()->subMinutes(env('USER_ACTIVITY_MINUTES'))->gte($user->last_activity)) {
			$user->last_activity = Carbon::now();
			$user->save();
			return true;

		}
		return false;
	}

	function removeUserLastActivity($user) {
		$user->last_activity = Carbon::now()->subMinutes(env('USER_ACTIVITY_MINUTES'));
		Log::info('se puso last_activity en '.Carbon::now()->subMinutes(env('USER_ACTIVITY_MINUTES')));
		$user->save();
	}

}