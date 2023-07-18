<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use Illuminate\Support\Facades\Log;

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

}