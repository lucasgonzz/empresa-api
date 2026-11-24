<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Models\Caja;
use App\Models\User;

/**
 * Regla ÚNICA de «quién ve y quién usa una caja» (misión cajas-permisos-claros, ticket 167 de Trama).
 *
 * Hasta esta misión la regla vivía sólo en la SPA (`Caja.vue`) y el servidor entregaba todas las cajas
 * del dueño, con saldos, aperturas y movimientos, a cualquier empleado; además el buscador de Tesorería
 * la esquivaba. Ahora la regla se aplica acá, en el servidor, y la SPA la usa sólo para mostrarla.
 *
 * Hay dos listas por caja, con dos significados distintos:
 *  - `treasury_users` (tabla `caja_treasury_user`): quiénes VEN la caja en Tesorería.
 *  - `users` (tabla `caja_user`): quiénes la USAN en Vender.
 *
 * Regla de Tesorería (NO cambia de significado respecto de lo que ya hacía la SPA):
 *  1. Dueño o empleado con `admin_access`: ve todas.
 *  2. Si `treasury_users` tiene gente: sólo ellos.
 *  3. Si no, y `users` tiene gente: sólo ellos (herencia de la lista de Vender).
 *  4. Si las dos están vacías: la ven todos.
 *
 * Regla de Vender: administrador → opera todas; `users` con gente → sólo ellos; vacía → todos.
 */
class CajaVisibilidadHelper
{
    /** El origen de la regla cuando manda la lista propia de Tesorería. */
    const ORIGEN_LISTA_TESORERIA = 'lista_tesoreria';

    /** El origen de la regla cuando la lista de Tesorería está vacía y se hereda la de Vender. */
    const ORIGEN_LISTA_VENDER = 'lista_vender';

    /** El origen de la regla cuando las dos listas están vacías (la ven todos). */
    const ORIGEN_TODOS = 'todos';

    /**
     * ¿El usuario es administrador a estos efectos? El dueño (sin `owner_id`) o un empleado con
     * `admin_access`. Es la misma noción que `is_admin` en la SPA.
     *
     * @param \App\Models\User $user Usuario autenticado (no el dueño resuelto).
     * @return bool
     */
    public static function es_admin(User $user)
    {
        return is_null($user->owner_id) || (bool) $user->admin_access;
    }

    /**
     * ¿El usuario ve la caja en Tesorería (listado, saldo, aperturas y movimientos)?
     *
     * @param \App\Models\Caja $caja
     * @param \App\Models\User $user
     * @return bool
     */
    public static function ve_en_tesoreria(Caja $caja, User $user)
    {
        if (self::es_admin($user)) {
            return true;
        }

        $ids_tesoreria = self::ids_de_lista($caja, 'treasury_users');

        if (count($ids_tesoreria) > 0) {
            return in_array((int) $user->id, $ids_tesoreria, true);
        }

        $ids_vender = self::ids_de_lista($caja, 'users');

        if (count($ids_vender) > 0) {
            return in_array((int) $user->id, $ids_vender, true);
        }

        return true;
    }

    /**
     * ¿El usuario puede usar la caja en Vender?
     *
     * @param \App\Models\Caja $caja
     * @param \App\Models\User $user
     * @return bool
     */
    public static function opera_en_vender(Caja $caja, User $user)
    {
        if (self::es_admin($user)) {
            return true;
        }

        $ids_vender = self::ids_de_lista($caja, 'users');

        if (count($ids_vender) > 0) {
            return in_array((int) $user->id, $ids_vender, true);
        }

        return true;
    }

    /**
     * De dónde sale la visibilidad de la caja en Tesorería, para mostrarla explícita en la SPA.
     *
     * @param \App\Models\Caja $caja
     * @return string `lista_tesoreria` | `lista_vender` | `todos`.
     */
    public static function origen_tesoreria(Caja $caja)
    {
        if (count(self::ids_de_lista($caja, 'treasury_users')) > 0) {
            return self::ORIGEN_LISTA_TESORERIA;
        }

        if (count(self::ids_de_lista($caja, 'users')) > 0) {
            return self::ORIGEN_LISTA_VENDER;
        }

        return self::ORIGEN_TODOS;
    }

    /**
     * Suma a una caja (modelo ya cargado) los tres campos de visibilidad para el usuario dado y, si
     * no la ve en Tesorería y no es administrador, anula sus saldos (ni siquiera se calculan).
     *
     * Los campos son NUEVOS y opcionales: una SPA vieja los ignora. `saldo` sigue existiendo, en null
     * sólo para cajas ajenas de empleados no administradores.
     *
     * @param \App\Models\Caja $caja
     * @param \App\Models\User $user
     * @return bool true si la ve en Tesorería (hay que calcular los saldos), false si no.
     */
    public static function marcar(Caja $caja, User $user)
    {
        $ve = self::ve_en_tesoreria($caja, $user);

        $caja->ve_en_tesoreria = $ve;
        $caja->opera_en_vender = self::opera_en_vender($caja, $user);
        $caja->origen_visibilidad_tesoreria = self::origen_tesoreria($caja);

        if (!$ve) {
            $caja->saldo = null;
            $caja->saldo_contable = null;
            $caja->saldo_disponible = null;
            $caja->saldo_a_liquidar = null;
        }

        return $ve;
    }

    /**
     * Acota una query de cajas a las que el usuario ve en Tesorería (misma regla, en SQL). Al
     * administrador no se le toca la query. Es lo que cierra el buscador de Tesorería.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query sobre `Caja`.
     * @param \App\Models\User $user
     * @return \Illuminate\Database\Eloquent\Builder La misma query, acotada.
     */
    public static function acotar_a_tesoreria($query, User $user)
    {
        if (self::es_admin($user)) {
            return $query;
        }

        $user_id = (int) $user->id;

        return $query->where(function ($q) use ($user_id) {
            $q->whereHas('treasury_users', function ($sub) use ($user_id) {
                $sub->where('users.id', $user_id);
            })->orWhere(function ($sin_lista_propia) use ($user_id) {
                $sin_lista_propia->doesntHave('treasury_users')
                    ->where(function ($heredada) use ($user_id) {
                        $heredada->whereHas('users', function ($sub) use ($user_id) {
                            $sub->where('users.id', $user_id);
                        })->orDoesntHave('users');
                    });
            });
        });
    }

    /**
     * Ids (enteros) de una de las dos listas de empleados de la caja. Usa la relación ya cargada si
     * está (el `index` trae `withAll()`), si no la consulta.
     *
     * @param \App\Models\Caja $caja
     * @param string $relacion `treasury_users` o `users`.
     * @return int[]
     */
    protected static function ids_de_lista(Caja $caja, $relacion)
    {
        $ids = [];

        foreach ($caja->{$relacion} as $empleado) {
            $ids[] = (int) $empleado->id;
        }

        return $ids;
    }
}
