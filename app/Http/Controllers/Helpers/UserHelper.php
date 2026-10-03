<?php

namespace App\Http\Controllers\Helpers;

use App\Events\CompanyOwnerContextUpdated;
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
        if (is_null($user)) {
            return false;
        }
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

    /**
     * Cómo lee VENDER los tickets de balanza en este comercio (misión balanzas-configurables,
     * 3/10/2026): 'plu', 'balanzas' o null (no lee ninguno).
     *
     * Es preferencia del COMERCIO y vive en el usuario dueño (`users.tickets_de_balanza`), mismo
     * patrón que `uses_listas_de_precio()`: para un empleado se lee la fila del dueño. Reemplaza a
     * las extensiones `plu_balanza_bar_code` / `balanza_bar_code`, que el código ya no mira.
     *
     * NULL (nunca se configuró), 'ninguno' y cualquier valor desconocido devuelven null: un valor
     * raro en la columna no puede prender una lectura que nadie eligió.
     *
     * Sin `$user` usa `self::user(true)`, o sea la foto del dueño que guarda la sesión: se refresca
     * en cada arranque de la SPA (`auth/me`) y cuando el dueño guarda la configuración, igual que
     * las extensiones que reemplaza. Si esa foto es anterior a la columna, se relee de la base (ver
     * el comentario de adentro).
     *
     * @param User|null $user Usuario autenticado, dueño o cualquier modelo User; se resuelve al dueño si tiene owner_id.
     * @return string|null 'plu' | 'balanzas' | null
     */
    static function modo_tickets_de_balanza($user = null) {
        $candidate = $user ?? self::user(true);
        if (!$candidate) {
            return null;
        }

        $owner = $candidate;

        if ($candidate->owner_id) {
            $owner = $candidate->owner ?? User::find($candidate->owner_id);
            if (!$owner) {
                return null;
            }
        }

        /*
         * 🔴 La foto del dueño que guarda la sesión puede ser ANTERIOR a la columna: una sesión
         * abierta antes del despliegue, en un frente que recibe el código nuevo con gente adentro.
         * Ahí el atributo directamente no existe, leerlo da null y VENDER dejaría de leer los
         * tickets hasta que la SPA se recargue (el comando ya migró al comercio, pero la sesión no
         * se entera). Solo en ese caso se relee de la base; con la columna en la foto, que es el
         * caso normal, no cuesta ninguna consulta.
         */
        if (array_key_exists('tickets_de_balanza', $owner->getAttributes())) {
            $modo = $owner->tickets_de_balanza;
        } else {
            $modo = self::tickets_de_balanza_de_la_base($owner->id);
        }

        if ($modo === BalanzaHelper::MODO_PLU || $modo === BalanzaHelper::MODO_BALANZAS) {
            return $modo;
        }

        return null;
    }

    /**
     * `users.tickets_de_balanza` del dueño, leído de la base. Si la columna todavía no existe (el
     * código llegó antes que la migración) no hay modo: devuelve null en vez de tumbar el escaneo.
     *
     * @param int $owner_id
     * @return string|null
     */
    private static function tickets_de_balanza_de_la_base($owner_id) {
        try {
            return User::where('id', $owner_id)->value('tickets_de_balanza');
        } catch (\Throwable $e) {
            Log::warning('modo_tickets_de_balanza: no se pudo leer users.tickets_de_balanza del dueño '.$owner_id.': '.$e->getMessage());
            return null;
        }
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

    /**
     * Programa el broadcast inmediatamente después de enviar la respuesta HTTP (sin cola de jobs),
     * para no demorar el PUT y aun así notificar a Pusher en el mismo request.
     *
     * @param int $company_owner_id Id del dueño (canal `global_notification.{id}`).
     * @param int $updated_by_user_id Usuario que guardó el perfil.
     * @param array<int, string> $change_descriptions Textos para el modal en otras sesiones; vacío no emite.
     * @return void
     */
    static function schedule_company_owner_context_updated_broadcast($company_owner_id, $updated_by_user_id, array $change_descriptions)
    {
        if ($change_descriptions === []) {
            return;
        }

        app()->terminating(function () use ($company_owner_id, $updated_by_user_id, $change_descriptions) {
            try {
                broadcast(new CompanyOwnerContextUpdated($company_owner_id, $updated_by_user_id, $change_descriptions));
            } catch (\Throwable $e) {
                Log::warning('CompanyOwnerContextUpdated broadcast falló: '.$e->getMessage());
            }
        });
    }
}

