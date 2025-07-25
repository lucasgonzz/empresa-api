<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class UserHelper {

	static function userId($from_owner = true) {

        $user = Self::user($from_owner);

        if ($user) {
            return $user->id;
        }

        return env('USER_ID');
    }

    static function user($from_owner = true) {
        if (session()->has('auth_user')) {
            
            if ($from_owner) {
                return session('owner');
            }
            return session('auth_user');
        } 

        $auth_user = Auth()->user();

        if ($auth_user) {
            $user_id = null;
            if ($from_owner) {
                if ($auth_user->owner_id) {
                    $user_id = $auth_user->owner_id;
                } else {
                    $user_id = $auth_user->id;
                }
            } else {
                $user_id = $auth_user->id;
            }
            if ($user_id) {
                return User::find($user_id);
            }
        }

        return null;
    }

    static function default_user() {

        return User::where('company_name', 'Autopartes Boxes')->first();
    }

    static function getFullModel($from_owner = true) {
        
        $id = Self::userId($from_owner);
        
        $user = User::where('id', $id)
                    ->withAll()
                    ->first();
        return $user;
    }

    static function checkUserTrial($user = null) {
        if (is_null($user)) {
            $user = Self::getFullModel();
        }
    	$expired_at = $user->expired_at;
    	if (!is_null($expired_at) && $expired_at->lte(Carbon::now())) {
    		$user->trial_expired = true;
    	} else {
    		$user->trial_expired = false;
    	}
    	return $user;
    }

    static function hasExtencion($extencion_slug, $user = null) {
        if (is_null($user)) {
            $user = Self::user();
        }
        $has_extencion = false;
        foreach ($user->extencions as $extencion) {
            if ($extencion->slug == $extencion_slug) {
                $has_extencion = true;
            }
        }
        return $has_extencion;
    }

    static function setEmployeeExtencionsAndConfigurations($employee) {
        $user_owner = Self::getFullModel(); 
        $employee->owner_extencions = $user_owner->extencions;
        $employee->owner_configuration = $user_owner->configuration;
        $employee->owner_addresses = $user_owner->addresses;
        $employee->from_cloudinary = $user_owner->from_cloudinary;
        $employee->default_article_image_url = $user_owner->default_article_image_url;
        return $employee;
    }

	static function isOscar() {
        $user = Auth()->user();
        if (is_null($user)) {
        	return false;
        } else {
        	if (env('APP_ENV') == 'local') {
	        	return $user->company_name == 'Oscar';
        	} else {
	        	return $user->id == 2;
        	}
        }
    }
}