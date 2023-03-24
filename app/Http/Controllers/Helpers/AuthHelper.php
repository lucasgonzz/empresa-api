<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class AuthHelper {
	
	function setEmployeeProps($user) {
		$owner = User::find($user->owner_id);
		$user->owner_extencions = $owner->extencions;
		$user->owner = $owner;
		return $user;
	}

}