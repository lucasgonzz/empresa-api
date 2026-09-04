<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticleDiscount;
use App\Models\Provider;
use App\Models\User;

/**
 * ArticleProviderDiscountHelper
 *
 * Prompt 306: materializa los descuentos vigentes de un proveedor (bonificaciones cargadas en una
 * orden de compra) como `article_discounts` explícitos y "tagueados" con el `provider_id` que los
 * originó (columna agregada en el prompt 305).
 *
 * Reemplaza el esquema viejo donde el descuento de la compra se prorrateaba y se horneaba directo
 * en `articles.cost` (ver método eliminado
 * NewProviderOrderHelper::aplicar_descuento_compra_a_costo_articulos, prompt 262/306). Ahora
 * `articles.cost` queda con el costo BRUTO oficial, y el descuento vive como `article_discounts`
 * que el pipeline de precios (ArticlePricesHelper::aplicar_descuentos, vía
 * ArticleHelper::aplicar_descuentos_e_iva) aplica una única vez para obtener el `costo_real`.
 *
 * Semántica "overwrite / último costo" (igual que el recargo de transporte del prompt 264): cada
 * vez que se materializan descuentos de un proveedor para un artículo, se pisan los
 * `article_discounts` tagueados de CUALQUIER proveedor anterior — no se acumulan compra tras
 * compra. Los descuentos manuales del usuario (`provider_id` null) nunca se tocan acá.
 *
 * Pensado para ser reutilizado también por el import de artículos (prompt 307), que necesita
 * materializar descuentos de proveedor con la misma semántica.
 *
 * Prompt 308: se extraen dos sub-funciones (barrer-por-proveedor / crear-tagueados) desde el
 * `sync_provider_discounts` original, para poder reutilizarlas con la granularidad que necesita
 * el cambio MANUAL de proveedor de un artículo (dos flags independientes: "eliminar descuentos
 * del proveedor anterior" y "crear descuentos del proveedor nuevo"). El barrido total ciego de
 * `sync_provider_discounts` (compra/import) sigue intacto para no romper esos flujos.
 */
class ArticleProviderDiscountHelper {

    /**
     * Sincroniza (overwrite) los article_discounts "tagueados" de un proveedor para un artículo.
     *
     * @param \App\Models\Article $article     Artículo a actualizar. Si es null, no hace nada.
     * @param int|null            $provider_id Proveedor que origina estos descuentos. Si es null,
     *                                         no hace nada (nunca se taguea un descuento sin
     *                                         proveedor conocido).
     * @param iterable            $discounts   Descuentos vigentes a materializar. Cada item puede
     *                                         ser un array o un objeto/modelo con `percentage`
     *                                         y/o `amount` (o `monto`, alias usado por
     *                                         App\Models\ProviderOrderDiscount).
     * @return void
     */
    static function sync_provider_discounts($article, $provider_id, $discounts) {

        if (is_null($article) || is_null($provider_id)) {
            return;
        }

        // Barrido overwrite: borra TODOS los descuentos tagueados (provider_id no nulo), sin
        // importar de qué proveedor eran antes (semántica de compra/import, prompt 306/307).
        // Los manuales (provider_id null) quedan intactos, nunca se tocan desde acá.
        self::delete_tagged_discounts($article, null);

        self::create_tagged_discounts($article, $provider_id, $discounts);
    }

    /**
     * Borra los `article_discounts` tagueados (con proveedor) de un artículo.
     *
     * @param \App\Models\Article $article     Artículo a limpiar. Si es null, no hace nada.
     * @param int|null            $provider_id Si viene informado, borra SOLO los descuentos
     *                                         tagueados a ESE proveedor puntual (uso del cambio
     *                                         manual de proveedor, prompt 308). Si es null, borra
     *                                         todos los tagueados sin importar el proveedor
     *                                         (barrido total, uso de compra/import).
     *                                         Los manuales (`provider_id` null en el descuento)
     *                                         nunca se tocan acá.
     * @return void
     */
    static function delete_tagged_discounts($article, $provider_id = null) {

        if (is_null($article)) {
            return;
        }

        $query = ArticleDiscount::where('article_id', $article->id)
                                    ->whereNotNull('provider_id');

        if (!is_null($provider_id)) {
            // Barrido acotado: solo los descuentos tagueados a este proveedor puntual.
            $query->where('provider_id', $provider_id);
        }

        $query->delete();
    }

    /**
     * Crea `article_discounts` tagueados con un proveedor, a partir de una lista de descuentos
     * vigentes (percentage y/o amount). No borra nada previo — quien llame decide si corresponde
     * barrer antes (ver `delete_tagged_discounts`).
     *
     * @param \App\Models\Article $article     Artículo a actualizar. Si es null, no hace nada.
     * @param int|null            $provider_id Proveedor que origina estos descuentos. Si es null,
     *                                         no hace nada (nunca se taguea un descuento sin
     *                                         proveedor conocido).
     * @param iterable            $discounts   Descuentos vigentes a materializar. Cada item puede
     *                                         ser un array o un objeto/modelo con `percentage`
     *                                         y/o `amount` (o `monto`, alias usado por
     *                                         App\Models\ProviderOrderDiscount).
     * @return void
     */
    static function create_tagged_discounts($article, $provider_id, $discounts) {

        if (is_null($article) || is_null($provider_id) || empty($discounts)) {
            return;
        }

        foreach ($discounts as $discount) {

            // Normalizo a objeto para leer percentage/amount sin importar si vino como array
            // (import) o como modelo Eloquent (ProviderOrderDiscount / ProviderDiscount).
            $discount = (object) $discount;

            $percentage = isset($discount->percentage) ? $discount->percentage : null;

            // `monto` es el nombre de columna que usa ProviderOrderDiscount; `amount` es el que
            // usa ArticleDiscount. Se acepta cualquiera de los dos como origen del dato.
            $amount = isset($discount->amount)
                ? $discount->amount
                : (isset($discount->monto) ? $discount->monto : null);

            // Sin percentage ni amount cargado, no hay nada que materializar de este item.
            if (
                (is_null($percentage) || $percentage === '')
                && (is_null($amount) || $amount === '')
            ) {
                continue;
            }

            ArticleDiscount::create([
                'article_id'  => $article->id,
                'provider_id' => $provider_id,
                'percentage'  => (!is_null($percentage) && $percentage !== '') ? $percentage : null,
                'amount'      => (!is_null($amount) && $amount !== '') ? $amount : null,
                // Tipo del descuento (Prompt 260): distingue la naturaleza contable, siempre
                // "bonificación de proveedor" para los que vienen de acá.
                'tipo'        => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
            ]);
        }
    }

    /**
     * Prompt 308: cambio MANUAL de proveedor de un artículo desde el listado, con dos flags
     * independientes que el usuario controla desde el modal (ver prompt 309):
     *   - $eliminar_descuentos_proveedor_anterior: borra SOLO los `article_discounts` tagueados
     *     con el proveedor ANTERIOR del artículo (no toca los de otros proveedores ni los
     *     manuales).
     *   - $crear_descuentos_proveedor_nuevo: materializa los `provider_discounts` (bonificaciones
     *     estándar) del proveedor NUEVO como `article_discounts` tagueados con ese proveedor, sin
     *     borrar nada previo.
     * Las dos acciones son independientes entre sí: las 4 combinaciones son válidas (ver criterio
     * de éxito del prompt 308).
     *
     * @param \App\Models\Article $article                                 Artículo a modificar.
     * @param int|null            $new_provider_id                        Proveedor nuevo a asignar.
     * @param bool                $eliminar_descuentos_proveedor_anterior  Default true.
     * @param bool                $crear_descuentos_proveedor_nuevo        Default true.
     * @return \App\Models\Article Artículo actualizado (con costo_real/final_price recalculados).
     */
    static function change_provider(
        $article,
        $new_provider_id,
        $eliminar_descuentos_proveedor_anterior = true,
        $crear_descuentos_proveedor_nuevo = true
    ) {

        if (is_null($article)) {
            return $article;
        }

        // Proveedor que tenía el artículo ANTES del cambio (para saber qué descuentos tagueados
        // corresponde eliminar, si el flag viene activado).
        $old_provider_id = $article->provider_id;

        if ($eliminar_descuentos_proveedor_anterior && !is_null($old_provider_id)) {
            self::delete_tagged_discounts($article, $old_provider_id);
        }

        if ($crear_descuentos_proveedor_nuevo && !is_null($new_provider_id)) {

            // Bonificaciones estándar del proveedor nuevo (dato maestro de la negociación,
            // App\Models\ProviderDiscount), no las de una compra puntual.
            $new_provider = Provider::find($new_provider_id);
            $discounts = $new_provider ? $new_provider->provider_discounts : [];

            self::create_tagged_discounts($article, $new_provider_id, $discounts);
        }

        // Se asigna el proveedor nuevo recién acá, para no afectar el filtro de "proveedor
        // anterior" usado arriba al eliminar.
        $article->provider_id = $new_provider_id;
        $article->save();

        // Recalcula costo_real/final_price con los descuentos que hayan quedado vigentes.
        return ArticleHelper::setFinalPrice($article);
    }

    /**
     * Prompt 308 (tarea 4): datos para el modal de cambio de proveedor (prompt 309) — dado un
     * artículo y el proveedor DESTINO, expone qué descuentos tiene tagueados el proveedor
     * anterior (los que se borrarían con el flag "eliminar") y qué `provider_discounts`
     * estándar tiene el proveedor nuevo (los que se crearían con el flag "crear"). No modifica
     * nada, es solo consulta.
     *
     * @param \App\Models\Article $article         Artículo a consultar.
     * @param int|null            $new_provider_id Proveedor destino (aún no aplicado).
     * @return array{
     *     descuentos_proveedor_anterior: \Illuminate\Support\Collection,
     *     descuentos_estandar_proveedor_nuevo: \Illuminate\Support\Collection
     * }
     */
    static function get_change_provider_preview($article, $new_provider_id) {

        $old_provider_id = $article ? $article->provider_id : null;

        // Descuentos actualmente tagueados al proveedor anterior del artículo.
        $descuentos_proveedor_anterior = is_null($article) || is_null($old_provider_id)
            ? collect()
            : ArticleDiscount::where('article_id', $article->id)
                                ->where('provider_id', $old_provider_id)
                                ->get();

        // Bonificaciones estándar (provider_discounts) del proveedor destino.
        $new_provider = is_null($new_provider_id) ? null : Provider::find($new_provider_id);
        $descuentos_estandar_proveedor_nuevo = $new_provider ? $new_provider->provider_discounts : collect();

        return [
            'descuentos_proveedor_anterior'         => $descuentos_proveedor_anterior,
            'descuentos_estandar_proveedor_nuevo'    => $descuentos_estandar_proveedor_nuevo,
        ];
    }

    /**
     * Indica si el comercio quiere que asignarle un proveedor a un articulo le aplique
     * automaticamente los descuentos de ese proveedor (`users.aplicar_descuentos_proveedor_al_asignar`).
     *
     * Es la dinamica anterior al merge de `refractor`: poner el proveedor y que el articulo quede
     * con los descuentos de ese proveedor, sin esperar a la compra. Viene APAGADA por defecto.
     *
     * La preferencia es del COMERCIO, no de cada empleado: siempre se resuelve al usuario dueño,
     * igual que `UserHelper::uses_listas_de_precio()` y `Sale::fechaDeReportePorPedido()`. Un
     * empleado que crea un articulo tiene que obtener el comportamiento del comercio y no el de su
     * propia fila, que nadie escribe nunca.
     *
     * Devuelve `false` —el camino de siempre— cuando no hay usuario resoluble o cuando la columna
     * todavia no existe en esa base (Eloquent devuelve null para un atributo que no vino del SELECT).
     *
     * @param  \App\Models\User|int|null $user Usuario, id de usuario, o null para el de la sesion.
     * @return bool
     */
    static function debe_aplicar_al_asignar($user = null) {

        if (is_null($user)) {
            $user = UserHelper::user(true);
        } else if (is_numeric($user)) {
            $user = User::find($user);
        }

        if (is_null($user)) {
            return false;
        }

        if ($user->owner_id) {
            $user = User::find($user->owner_id);

            if (is_null($user)) {
                return false;
            }
        }

        return (bool) $user->aplicar_descuentos_proveedor_al_asignar;
    }

    /**
     * Aplica la dinamica vieja cuando el comercio la tiene prendida: al asignarle un proveedor a un
     * articulo, se le materializan los `provider_discounts` (bonificaciones estandar) de ese
     * proveedor como `article_discounts` tagueados.
     *
     * Cubre los DOS huecos que dejaba `develop` (los otros caminos ya estaban resueltos y no se
     * tocan): crear un articulo con proveedor, y asignarle un proveedor a un articulo que no tenia.
     * El cambio de proveedor A -> B desde el listado sigue pasando por el modal de confirmacion y
     * su endpoint dedicado (`ArticleController::change_provider`), que no depende de esta
     * preferencia — decision de Lucas del 4/9/2026.
     *
     * 🔴 El barrido es ACOTADO al proveedor anterior (`delete_tagged_discounts` con provider_id),
     * nunca el barrido total ciego de `sync_provider_discounts()`: aca el usuario cambio un
     * proveedor, no hizo una compra, y los descuentos tagueados de otros proveedores no son suyos
     * para borrar. Los descuentos manuales (`provider_id` null) tampoco se tocan nunca.
     *
     * 🔴 Deja la relacion `article_discounts` descargada antes de devolver. Quien llama recalcula
     * el precio inmediatamente despues (`ArticleHelper::setFinalPrice`, que lee esa relacion en
     * `ArticlePricesHelper::aplicar_descuentos`), y Eloquent cachea las relaciones ya cargadas: sin
     * el `unsetRelation` el costo se recalcularia con los descuentos de ANTES, sin ninguna
     * excepcion de por medio, y se guardaria como si estuviera bien. Es la clase de error del
     * 31/8/2026 (relacion de Eloquent vieja en memoria) y el que desincroniza es el que refresca.
     *
     * @param  \App\Models\Article $article          Articulo con el `provider_id` NUEVO ya asignado.
     * @param  int|null            $old_provider_id  Proveedor que tenia antes (null al crear).
     * @param  \App\Models\User|int|null $user       Usuario/comercio del que leer la preferencia.
     *                                               🔴 OBLIGATORIO desde un job en cola: la
     *                                               actualizacion masiva corre en
     *                                               ProcessMasiveUpdateJob (ShouldQueue), donde no
     *                                               hay sesion ni Auth::user() — resolver por
     *                                               defecto ahi daria false SIEMPRE y la
     *                                               preferencia quedaria muerta sin ningun error.
     *                                               Desde un controller se puede omitir.
     * @return bool  true si se materializo algo (quien llama tiene que recalcular el precio).
     */
    static function aplicar_al_asignar_proveedor($article, $old_provider_id = null, $user = null) {

        if (is_null($article) || !self::debe_aplicar_al_asignar($user)) {
            return false;
        }

        /*
         * Normalizacion antes de comparar: `$article->provider_id` puede venir de un request como
         * string ('5') o como cadena vacia, y `$old_provider_id` sale de la base como int. En PHP
         * 7.4 `5 == ''` es FALSE, asi que un payload con `provider_id: ''` sobre un articulo con
         * proveedor entraria por la rama "cambio" y le barreria los descuentos. Se comparan los dos
         * como int-o-null, y con `===`.
         */
        $old_provider_id = (is_null($old_provider_id) || $old_provider_id === '')
            ? null
            : (int) $old_provider_id;

        $new_provider_id = (is_null($article->provider_id) || $article->provider_id === '')
            ? null
            : (int) $article->provider_id;

        /*
         * 🔴 SIN PROVEEDOR NUEVO NO SE TOCA NADA, y esta guarda no es defensiva: es la diferencia
         * entre esta preferencia y una que borra datos.
         *
         * Quitar el proveedor de un articulo (la X del campo en la ficha) llega hasta acá con
         * `$new_provider_id` en null. Barrer ahi le borraria al articulo los `article_discounts`
         * tagueados — que NO son solo los que pudo haber puesto esta preferencia: son tambien los
         * que materializo una COMPRA real, con las bonificaciones negociadas de esa compra
         * (NewProviderOrderHelper), y los del import de Excel. Se irian sin aviso, sin modal de
         * confirmacion y sin registro, y el costo del articulo subiria solo.
         *
         * Lucas pidio aplicar los descuentos AL ASIGNAR un proveedor. Quitarlo no es asignar, y en
         * develop ese guardado no tocaba un solo descuento: sigue sin tocarlo.
         */
        if (is_null($new_provider_id)) {
            return false;
        }

        // Sin cambio real de proveedor no hay nada que hacer: un guardado que no toco el proveedor
        // no puede rehacerle los descuentos al articulo (borraria las ediciones manuales que el
        // usuario le haya hecho a los descuentos tagueados desde que se asigno el proveedor).
        if ($old_provider_id === $new_provider_id) {
            return false;
        }

        if (!is_null($old_provider_id)) {
            self::delete_tagged_discounts($article, $old_provider_id);
        }

        $new_provider = Provider::find($new_provider_id);
        $discounts = $new_provider ? $new_provider->provider_discounts : [];

        self::create_tagged_discounts($article, $new_provider_id, $discounts);

        // Ver el docblock: sin esto, el setFinalPrice() del llamador calcula con los descuentos
        // viejos y guarda un costo_real que no se corresponde con las filas de la base.
        $article->unsetRelation('article_discounts');

        return true;
    }
}
