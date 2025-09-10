<?php

namespace App\Http\Controllers\Helpers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;

class UserHelper {

    static function user($from_owner = true) {
        if (session()->has('auth_user')) {
            return $from_owner ? session('owner') : session('auth_user');
        }

        $auth_user = Auth::user();

        if ($auth_user) {
            $user_id = $from_owner && $auth_user->owner_id ? $auth_user->owner_id : $auth_user->id;
            return User::find($user_id);
        }

        return null;
    }

    static function userId($from_owner = true) {
        $user = self::user($from_owner);
        return $user ? $user->id : env('USER_ID');
    }

    static function getFullModel($from_owner = true) {
        $id = self::userId($from_owner);
        return User::where('id', $id)->withAll()->first();
    }

    static function default_user() {
        return User::where('company_name', 'Autopartes Boxes')->first();
    }

    static function checkUserTrial($user = null) {
        $user = $user ?: self::getFullModel();
        $expired_at = $user->expired_at;
        $user->trial_expired = $expired_at && $expired_at->lte(Carbon::now());
        return $user;
    }

    static function hasExtencion($extencion_slug, $user = null) {
        $user = $user ?? self::user();
        return collect($user->extencions)->contains('slug', $extencion_slug);
    }

    static function set_sessions($auth_user) {
        $auth_user = User::where('id', $auth_user->id)->withAll()->first();
        $owner = $auth_user->owner_id
            ? User::where('id', $auth_user->owner_id)->withAll()->first()
            : $auth_user;

        session([
            'auth_user' => $auth_user,
            'owner'     => $owner,
        ]);
    }
}
