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
        return $user ? $user->id : config('app.USER_ID');
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

    /**
     * Indica si la empresa (usuario dueño) trabaja con listas de precio / márgenes por lista.
     * Usa la columna users.listas_de_precio del owner, no la extensión.
     *
     * @param User|null $user Usuario autenticado, dueño o cualquier modelo User; se resuelve al owner si tiene owner_id.
     * @return bool
     */
    static function uses_listas_de_precio($user = null) {
        $candidate = $user ?? self::user(true);
        if (!$candidate) {
            return false;
        }

        if ($candidate->owner_id) {
            $owner = $candidate->owner ?? User::find($candidate->owner_id);
            if (!$owner) {
                return false;
            }
            return (bool) $owner->listas_de_precio;
        }

        return (bool) $candidate->listas_de_precio;
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
