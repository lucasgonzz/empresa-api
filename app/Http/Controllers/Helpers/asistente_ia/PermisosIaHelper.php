<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;

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
 *   - Combos → `article.index`: los combos se crean desde un modal del Listado de artículos
 *     (src/components/listado/components/combos/), cuya ruta /listado-de-articulos pide
 *     `can: 'article.index'` (src/router/routes.js:59). ⚠️ Adentro del modal NO hay otro can(): el
 *     view-component se monta sin `check_permissions`, que por defecto es false
 *     (src/common-vue/components/view/header/Index.vue:153-156), así que el botón "Nuevo Combo" no
 *     chequea `combo.store` — y ese slug ni siquiera está sembrado. Se copia lo que la pantalla
 *     hace, no lo que uno esperaría que hiciera.
 *   - Ofertas por cliente → `buyer.index`: la pantalla de Promociones entra por el menú de Tienda
 *     Online con `can: 'buyer.index'` (src/router/routes.js:366).
 *
 * ⚠️ LOS PERMISOS NO SON EL ÚNICO GATE. Combos y ofertas viven detrás de una EXTENSIÓN
 * (`combos` y `motor_de_ofertas`), que es otra cosa: el permiso dice si esta persona puede, la
 * extensión si el comercio compró el módulo. Esa se chequea aparte, con UserHelper::hasExtencion()
 * sobre el dueño, en el helper de cada propuesta.
 */
class PermisosIaHelper {

    const GASTOS = 'expense.store';

    const TAREAS = 'pending.index';

    const PAGOS_DE_CLIENTES = 'client.index';

    const PAGOS_A_PROVEEDORES = 'provider.index';

    const COMBOS = 'article.index';

    const OFERTAS = 'buyer.index';

    /** Extensión que enciende el módulo de Combos (ExtencionSeeder). */
    const EXTENCION_COMBOS = 'combos';

    /** Extensión que enciende el motor de ofertas por cliente (ExtencionMotorDeOfertasSeeder). */
    const EXTENCION_OFERTAS = 'motor_de_ofertas';

    /**
     * true si el COMERCIO tiene el módulo comprado. Se mira sobre el DUEÑO y no sobre quien charla,
     * que es el mismo criterio del middleware `check_extencion_empresa` del API y del
     * `hasExtencion()` de la SPA.
     *
     * 🔴 Sin dueño devuelve false y NO cae en UserHelper::hasExtencion(slug, null), que ahí se
     * resolvería por Auth: estas herramientas corren adentro del job, sin sesión, y ahí Auth es el
     * USER_ID de config, o sea otro comercio.
     *
     * @param  \App\Models\User|null  $owner
     * @param  string  $slug
     * @return bool
     */
    static function tiene_extencion($owner, $slug) {

        return !is_null($owner) && UserHelper::hasExtencion($slug, $owner);
    }

    /**
     * Texto que devuelve una herramienta (y el 422 al confirmar) cuando el comercio no tiene el
     * módulo. Se distingue a propósito del de permisos: uno lo arregla el dueño dándole permiso a
     * la persona, el otro lo activa ComercioCity.
     *
     * @param  string  $que  "Combos", "Promociones".
     * @return string
     */
    static function mensaje_sin_extencion($que) {

        return 'Tu cuenta no tiene activado el módulo de '.$que.'.';
    }

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
