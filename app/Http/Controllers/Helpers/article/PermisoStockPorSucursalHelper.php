<?php

namespace App\Http\Controllers\Helpers\article;

/**
 * ¿Esta persona puede editar el stock de esta sucursal? (misión stock-sucursal-desde-listado,
 * 14/10/2026).
 *
 * Es la regla ÚNICA del back. La usan la edición de stock por sucursal del Listado
 * (`UpdateAddressesStockHelper`, `PUT api/article-update-addresses`) y el asistente IA
 * (`PropuestaStockIaHelper::puede_en_deposito()`), que hasta esta misión tenía su propia copia.
 *
 * Es el espejo exacto de `puede_editar_stock` de la SPA
 * (`listado/components/table-props/address-stock/Index.vue`):
 *
 *  - sin persona → no (el `can()` de la SPA arranca con `if (!this.authenticated) return false`);
 *  - dueño (`owner_id` vacío) o con `admin_access` → sí, a todas;
 *  - con `article.edit_stock_only_sucursal` → solo la sucursal que tiene asignada en su ficha
 *    (`users.address_id`), aunque además tenga `article.edit_stock`: la pantalla mira el acotado
 *    ANTES que el general, y un empleado de una sucursal que pueda tocar el stock de otra es justo
 *    lo que ese permiso existe para impedir;
 *  - si no, `article.edit_stock`.
 *
 * Los permisos se leen de `$persona->permissions` por `slug` (mismo criterio que
 * `PermisosIaHelper::puede`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PermisoStockPorSucursalHelper {

    /** El permiso general para editar el stock. */
    const PERMISO = 'article.edit_stock';

    /** El permiso acotado a la sucursal asignada en la ficha de la persona. */
    const PERMISO_SOLO_SU_SUCURSAL = 'article.edit_stock_only_sucursal';

    /**
     * @param  \App\Models\User|null  $persona
     * @param  int  $address_id
     * @return bool
     */
    static function puede_editar($persona, $address_id) {

        if (is_null($persona)) {

            return false;
        }

        if (self::es_admin($persona)) {

            return true;
        }

        if (self::tiene_permiso($persona, self::PERMISO_SOLO_SU_SUCURSAL)) {

            return (int) $persona->address_id === (int) $address_id;
        }

        return self::tiene_permiso($persona, self::PERMISO);
    }

    /**
     * `is_admin` de la SPA: dueño (`!user.owner_id`) o `admin_access`.
     *
     * @param  \App\Models\User  $persona
     * @return bool
     */
    static function es_admin($persona) {

        return empty($persona->owner_id) || !empty($persona->admin_access);
    }

    /**
     * ¿La persona tiene asignado el permiso con ese slug?
     *
     * @param  \App\Models\User  $persona
     * @param  string  $slug
     * @return bool
     */
    static function tiene_permiso($persona, $slug) {

        $permisos = $persona->permissions;

        if (is_null($permisos)) {

            return false;
        }

        foreach ($permisos as $permiso) {

            if ($permiso->slug == $slug) {

                return true;
            }
        }

        return false;
    }
}
