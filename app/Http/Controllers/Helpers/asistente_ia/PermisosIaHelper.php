<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Qué puede cargar cada persona desde el asistente de IA (misión asistente-ia-acciones, 15/9/2026).
 *
 * 🔴 ES UN ESPEJO LITERAL DE LA PANTALLA, NO UN CRITERIO NUEVO. Cada persona propone y confirma solo
 * lo que la SPA le deja hacer. Si acá hubiera otra regla, el asistente le cargaría un gasto a un
 * empleado que en Gastos no ve el botón "Nuevo", o le negaría un cobro que la pantalla le acepta:
 * es la clase "el mismo invariante con dos criterios en front y back" de APRENDER_NO_PARCHEAR.md.
 * Si la SPA cambia su criterio, este archivo cambia en el mismo diff.
 *
 * Espeja, en empresa-spa:
 *   - `can(permission_slug)` de src/common-vue/mixins/permissions.js: dueño → true; `user.admin_access`
 *     → true; si no, `hasPermissionTo()`, que recorre `user.permissions` comparando `slug`.
 *   - `is_owner` (`!user.owner_id`) e `is_admin` (`is_owner || user.admin_access`) de
 *     src/common-vue/mixins/generals/computed.js. Con `!owner_id` un owner_id 0 también es dueño,
 *     y así se copia (empty()).
 *
 * De dónde sale cada slug:
 *   - Gastos → `expense.store`: el botón "Nuevo" de la vista Gastos se dibuja con
 *     `can(model_name + '.store')` (src/common-vue/components/view/header/Index.vue:262).
 *   - Tareas → `pending.index`: la ruta /agenda pide `can: 'pending.index'` (src/router/routes.js).
 *   - Pagos de clientes → `client.index`: la cuenta corriente se abre con BtnCurrentAcounts desde el
 *     listado de clientes, cuya ruta /clientes pide `can: 'client.index'`; adentro del modal de
 *     cuenta corriente y del de Pago no hay ningún otro can().
 *   - Pagos a proveedores → `provider.index`: ídem desde el listado de proveedores (ruta
 *     /proveedores, `can: 'provider.index'`). El nav de proveedores también se muestra con
 *     `provider.create` (src/components/provider/components/Nav.vue:22), pero sin `provider.index`
 *     no se entra al listado desde donde se abre la cuenta corriente, así que ese "o" NO se copia.
 *   - Compras → `provider_order.store` (misión asistente-por-whatsapp, 16/9/2026). 🔴 El plan de
 *     esa misión proponía `provider_order.create` y pedía verificarlo antes de fijarlo: medido, ese
 *     slug NO EXISTE. Lo único que lo nombra es el "o" de la solapa de compras
 *     (src/components/provider/components/Nav.vue:25), y ningún seeder lo crea. El permiso real de
 *     compras es `provider_order.store` — "Hacer pedidos a los Proveedores"
 *     (database/seeders/PermissionsTableSeeder.php:221) —, y es exactamente el que pide el botón
 *     "Nuevo" de la vista de compras, que se dibuja con `can(model_name + '.store')`
 *     (src/common-vue/components/view/header/Index.vue:262). Mismo origen que el de Gastos.
 */
class PermisosIaHelper {

    const GASTOS = 'expense.store';

    const TAREAS = 'pending.index';

    const PAGOS_DE_CLIENTES = 'client.index';

    const PAGOS_A_PROVEEDORES = 'provider.index';

    const COMPRAS = 'provider_order.store';

    /**
     * true si la persona puede hacer lo que pide el slug. Mismo orden que `can()` de la SPA.
     *
     * @param  \App\Models\User|null  $persona  La persona que charla con el asistente (no el dueño).
     * @param  string  $slug
     * @return bool
     */
    static function puede($persona, $slug) {

        // can() empieza con `if (!this.authenticated) return false`: sin persona no hay permiso.
        if (is_null($persona)) {

            return false;
        }

        if (self::es_admin($persona)) {

            return true;
        }

        foreach ($persona->permissions as $permission) {

            if ($permission->slug == $slug) {

                return true;
            }
        }

        return false;
    }

    /**
     * `is_owner` de la SPA: `!user.owner_id`.
     *
     * @param  \App\Models\User|null  $persona
     * @return bool
     */
    static function es_dueno($persona) {

        return !is_null($persona) && empty($persona->owner_id);
    }

    /**
     * `is_admin` de la SPA: dueño o `admin_access`. Es lo que abre todas las cajas en
     * get_caja_options() y todos los permisos en can().
     *
     * @param  \App\Models\User|null  $persona
     * @return bool
     */
    static function es_admin($persona) {

        return self::es_dueno($persona) || (!is_null($persona) && !empty($persona->admin_access));
    }

    /**
     * Texto que devuelve una herramienta (y el 422 al confirmar) cuando la persona no puede.
     *
     * @param  string  $que  "gastos", "tareas de la agenda", "pagos de clientes", "pagos a proveedores".
     * @return string
     */
    static function mensaje_sin_permiso($que) {

        return 'No tenés permiso para cargar '.$que.' desde tu usuario.';
    }
}
