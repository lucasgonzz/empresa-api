<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
     * Columnas que necesita `clasificar_articulo()`. Se seleccionan explicitamente en vez de traer
     * la fila entera: el preview corre en CADA guardado de la ficha de un proveedor, y uno grande
     * puede tener miles de `article_discounts` tagueados.
     */
    /**
     * Alcance de la sincronizacion manual desde el boton de la ficha del proveedor (17/9/2026).
     *
     *   - TODOS: alcanza a TODOS los articulos del proveedor, incluidos los que no tienen ningun
     *     `article_discount` tagueado. Es lo que no existia hasta hoy.
     *   - SOLO_CON_DESCUENTOS: solo los que ya tienen alguno tagueado a ese proveedor, que es el
     *     universo que recorre `propagar_a_articulos()`.
     */
    const ALCANCE_TODOS = 'todos';
    const ALCANCE_SOLO_CON_DESCUENTOS = 'solo_con_descuentos';

    /**
     * Que hacer con los articulos que tienen descuentos tagueados a ese proveedor que la ficha NO
     * puede reponer (`origen` = compra, import, o null = desconocido).
     *
     * 🔴 Existe porque los descuentos se aplican EN CASCADA, no sumados
     * (`ArticlePricesHelper::aplicar_descuentos`). Un articulo de costo 1000 con 10% de compra y
     * 10% de ficha da 810, no 900.
     *
     *   - SALTEAR (default): no se los toca. Es el lado seguro: una bonificacion negociada en una
     *     compra real NO se puede reconstruir si se pierde.
     *   - PISAR: se reemplaza TODO lo tagueado a ese proveedor por los descuentos de la ficha. Se
     *     pierde la bonificacion negociada.
     *   - AGREGAR: se rehace lo de la ficha y se DEJA lo de la compra. Es la opcion que duplica, y
     *     Lucas la pidio explicitamente — el modal se lo dice al usuario con el numero a la vista.
     */
    const ACCION_COMPRAS_SALTEAR = 'saltear';
    const ACCION_COMPRAS_PISAR = 'pisar';
    const ACCION_COMPRAS_AGREGAR = 'agregar';

    const COLUMNAS_PARA_CLASIFICAR = [
        'id',
        'article_id',
        'percentage',
        'amount',
        'show_in_online',
        'editado_a_mano',
        'origen',
    ];

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
    static function sync_provider_discounts($article, $provider_id, $discounts, $origen = null) {

        if (is_null($article) || is_null($provider_id)) {
            return;
        }

        // Barrido overwrite: borra TODOS los descuentos tagueados (provider_id no nulo), sin
        // importar de qué proveedor eran antes (semántica de compra/import, prompt 306/307).
        // Los manuales (provider_id null) quedan intactos, nunca se tocan desde acá.
        self::delete_tagged_discounts($article, null);

        self::create_tagged_discounts($article, $provider_id, $discounts, 0, $origen);
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
    static function create_tagged_discounts($article, $provider_id, $discounts, $show_in_online = 0, $origen = null) {

        // `count()` y no `empty()`: sobre una Collection de Laravel `empty()` es SIEMPRE false
        // (todo objeto es truthy), asi que la guarda historica no cortaba con una coleccion vacia.
        // No cambia ningun resultado —el foreach de abajo tampoco iteraba— pero la guarda ahora
        // dice la verdad.
        if (is_null($article) || is_null($provider_id) || is_null($discounts)) {
            return;
        }

        if (is_array($discounts) || $discounts instanceof \Countable) {

            if (count($discounts) === 0) {
                return;
            }
        }

        foreach ($discounts as $discount_original) {

            // Normalizo a objeto para leer percentage/amount sin importar si vino como array
            // (import) o como modelo Eloquent (ProviderOrderDiscount / ProviderDiscount).
            //
            // ⚠️ El item ORIGINAL se conserva aparte: `leer_provider_discount_id()` necesita saber
            // de QUE CLASE vino, y el cast de un array a stdClass borra esa informacion. Ver el
            // docblock de ese metodo, que es donde esta la trampa.
            $discount = (object) $discount_original;

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
                // Visibilidad en el ecommerce. Default 0 (el de siempre para compra e import); la
                // propagacion lo pasa en 1 cuando el descuento que reemplaza ya lo tenia activado,
                // para no apagarle al comercio el precio tachado de la tienda sin avisarle.
                'show_in_online' => $show_in_online ? 1 : 0,
                // 🔴 QUIEN lo creo. Es lo que despues decide si una propagacion puede rehacerlo;
                // sin esto hay que adivinarlo mirando la forma del descuento, que es de donde
                // salieron nueve defectos en cuatro rondas de verificacion. Ver ArticleDiscount.
                'origen' => $origen,
                // DE CUAL descuento del proveedor salio, y como se llama (mision
                // sincronizar-descuentos-proveedor, 17/9/2026). Los dos son opcionales y quedan en
                // null cuando la fuente no los trae: el import manda arrays de
                // `['percentage' => x]`, y una compra manda un ProviderOrderDiscount, que no
                // pertenece a la relacion. Eso esta bien y no rompe nada: `origen` sigue siendo la
                // unica columna con la que se decide algo.
                'provider_discount_id' => self::leer_provider_discount_id($discount_original, $discount),
                'nombre'               => self::leer_nombre_del_descuento($discount),
            ]);
        }
    }

    /**
     * De que fila de `provider_discounts` sale este item de origen, si es que sale de alguna.
     *
     * 🔴 NUNCA se lee `$item->id` a secas, y esa es la trampa entera de este metodo. Los items que
     * recibe `create_tagged_discounts()` vienen de cuatro fuentes distintas:
     *
     *   - `ProviderDiscount` (la ficha del proveedor): SU `id` es exactamente el dato que se busca.
     *   - `ProviderOrderDiscount` (la bonificacion negociada en una compra): TAMBIEN tiene `id`,
     *     pero es el id de OTRA tabla. Copiarlo dejaria `article_discounts.provider_discount_id`
     *     apuntando a un `provider_discounts.id` que no tiene nada que ver — y como los dos son
     *     enteros chicos y correlativos, la mayoria de las veces ese id existiria. Renombrar un
     *     descuento del proveedor le cambiaria el nombre a descuentos de compras ajenas, sin un
     *     solo error de por medio.
     *   - arrays del import (`['percentage' => 10]`): no traen nada, y esta bien que quede null.
     *   - lo que venga despues: si quiere declarar la relacion, la declara con todas las letras.
     *
     * Por eso se mira la CLASE del item original y, si no es de la ficha, se exige la clave
     * explicita `provider_discount_id`.
     *
     * @param  mixed  $item_original Item tal como lo recibio el foreach (array o modelo).
     * @param  object $item          El mismo item ya normalizado a objeto.
     * @return int|null
     */
    static function leer_provider_discount_id($item_original, $item) {

        if ($item_original instanceof ProviderDiscount) {
            return $item_original->id;
        }

        // La clave explicita: la unica otra forma de declarar la relacion. `''` cuenta como vacio
        // (clase de error del `??` del 27/8/2026: la SPA manda cadena vacia, no null).
        if (isset($item->provider_discount_id) && $item->provider_discount_id !== '') {
            return (int) $item->provider_discount_id;
        }

        return null;
    }

    /**
     * Nombre/descripcion del descuento, leido con el mismo cuidado defensivo que `percentage` y
     * `amount`/`monto`: cada fuente lo llama distinto y ninguna esta obligada a traerlo.
     *
     *   - `nombre`      -> el de `provider_discounts` (mision del 17/9/2026).
     *   - `description` -> el de `provider_order_discounts`, que ya existia desde el 26/2/2026. Se
     *                      acepta como alias por el mismo criterio por el que `monto` vale como
     *                      `amount`: es el mismo dato con otro nombre de columna, y sin esto la
     *                      bonificacion de una compra quedaria sin nombre teniendolo cargado.
     *
     * Vacio es null, nunca cadena vacia: la columna es nullable y "sin nombre" tiene que verse de
     * una sola forma en la base.
     *
     * Se corta a 191 caracteres, que es el largo de la columna: un nombre mas largo tiraria un
     * error de SQL a la mitad de una sincronizacion de miles de articulos, dejandola por la mitad.
     *
     * @param  object $item
     * @return string|null
     */
    static function leer_nombre_del_descuento($item) {

        $nombre = isset($item->nombre)
            ? $item->nombre
            : (isset($item->description) ? $item->description : null);

        if (is_null($nombre)) {
            return null;
        }

        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            return null;
        }

        return mb_substr($nombre, 0, 191);
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

            self::create_tagged_discounts($article, $new_provider_id, $discounts, 0, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);
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

        self::create_tagged_discounts($article, $new_provider_id, $discounts, 0, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR);

        // Ver el docblock: sin esto, el setFinalPrice() del llamador calcula con los descuentos
        // viejos y guarda un costo_real que no se corresponde con las filas de la base.
        $article->unsetRelation('article_discounts');

        return true;
    }

    /**
     * Saca los `article_discounts` tagueados a un proveedor cuando el articulo se queda SIN
     * proveedor. Es la contraparte de `aplicar_al_asignar_proveedor()` para el unico caso en que
     * quedarse sin proveedor sí tiene que barrer: la REVERSION de una actualizacion masiva que
     * habia asignado ese proveedor a un articulo que no tenia ninguno.
     *
     * 🔴 POR QUE ESTO NO CONTRADICE LA GUARDA DE `aplicar_al_asignar_proveedor()`, que existe
     * justamente para que quitar el proveedor NO borre descuentos:
     *
     * La diferencia es quien llama y que sabe. Cuando un usuario le saca el proveedor a un articulo
     * desde la ficha, nadie sabe de donde salieron esos descuentos: pueden ser de una compra real
     * con bonificaciones negociadas, y borrarlos pierde un dato irrecuperable. Al revertir una
     * masiva, en cambio, el llamador SI sabe: el articulo no tenia proveedor antes de esa masiva,
     * asi que tampoco tenia descuentos tagueados, y los que hay ahora los puso esa misma masiva
     * hace un rato. Revertir es devolver el articulo al estado previo, y ese estado no los incluia.
     *
     * Sin esto, revertir una masiva de "null -> proveedor B" dejaba el articulo sin proveedor pero
     * CON los descuentos de B, y el setFinalPrice() siguiente le recalculaba el costo con esos
     * descuentos huerfanos aplicados. Sin error y sin aviso.
     *
     * ⚠️ Un caso que este metodo pisa a proposito: si entre la masiva y su reversion alguien le
     * cargo una COMPRA a ese mismo proveedor, los descuentos tagueados ya no son los de la masiva
     * sino los de la compra, y se van igual. Es coherente con lo que la reversion ya hace con el
     * resto de las columnas (restaura el valor previo pisando lo que haya pasado en el medio), pero
     * vale tenerlo escrito.
     *
     * Gateado por la preferencia: con la preferencia apagada la masiva no materializo nada, asi que
     * no hay nada que barrer y cualquier descuento tagueado que el articulo tenga es de otro origen.
     *
     * @param  \App\Models\Article $article     Articulo ya revertido (sin proveedor).
     * @param  int|null            $provider_id Proveedor que la masiva le habia asignado.
     * @param  \App\Models\User|int|null $user  Usuario/comercio, explicito: esto corre en cola.
     * @return bool  true si se barrio algo (quien llama tiene que recalcular el precio).
     */
    static function revertir_materializacion_de_masiva($article, $provider_id, $user = null) {

        if (is_null($article) || is_null($provider_id) || !self::debe_aplicar_al_asignar($user)) {
            return false;
        }

        self::delete_tagged_discounts($article, $provider_id);

        // Mismo motivo que en aplicar_al_asignar_proveedor(): el setFinalPrice() del llamador lee
        // esta relacion inmediatamente despues.
        $article->unsetRelation('article_discounts');

        return true;
    }

    /**
     * Clasifica un articulo frente a los descuentos ACTUALES de su proveedor.
     *
     * Devuelve una de estas tres:
     *   'al_dia'          -> sus descuentos tagueados son exactamente los del proveedor hoy.
     *   'desactualizado'  -> difieren y nadie los edito: corresponde actualizarlos sin preguntar.
     *                        Cubre tambien al articulo al que le FALTA un descuento que el proveedor
     *                        agrego, y al que le SOBRA uno que el proveedor borro.
     *   'editado_a_mano'  -> alguno de sus descuentos tiene la marca `editado_a_mano`, o sea que una
     *                        persona le cambio el porcentaje a proposito para ESE articulo.
     *
     * 🔴 "Editado a mano" gana sobre todo lo demas: ante la duda se le pregunta al usuario en vez de
     * pisarle una decision comercial.
     *
     * @param  \Illuminate\Support\Collection $tagueados article_discounts del articulo tagueados a
     *                                                   ESE proveedor.
     * @param  array $percentages_actuales Porcentajes que el proveedor tiene hoy.
     * @return string
     */
    static function clasificar_articulo($tagueados, $percentages_actuales) {

        $del_articulo = [];

        /*
         * 🔴 Solo se mira lo que la FICHA creo. El origen lo dice la columna; ya no se deduce de la
         * forma del descuento.
         */
        $de_la_ficha = collect($tagueados)->filter(function ($descuento) {
            return self::gobernado_por_la_ficha($descuento);
        });

        /*
         * 🔴 SIN NINGUNA FILA DE LA FICHA NO HAY NADA QUE PROPAGAR, y devolver otra cosa aca duplica
         * descuentos.
         *
         * Un articulo cuyos descuentos vinieron todos de compras o del import nunca tuvo un
         * descuento puesto por la ficha: agregarle uno ahora no seria "actualizarlo", seria sumarle
         * un descuento que no tenia. Y como el barrido solo alcanza a las filas de la ficha —o sea,
         * a ninguna— la fila nueva quedaria ENCIMA de las que ya estaban: sobre un costo de 1000 con
         * un 10% de compra y un 10% de ficha, 810 en vez de 900. En la corrida siguiente el articulo
         * ya tendria su fila de ficha y saldria 'al_dia', con el doble descuento horneado para
         * siempre.
         *
         * Es el mismo daño de las versiones anteriores con el signo invertido: antes se destruia lo
         * que no se podia reponer, ahora se duplicaba lo que no habia que tocar.
         */
        if ($de_la_ficha->isEmpty()) {
            return 'al_dia';
        }

        $hay_marca = false;

        foreach ($de_la_ficha as $descuento) {

            if ($descuento->editado_a_mano) {
                $hay_marca = true;
            }

            $del_articulo[] = self::normalizar_porcentaje($descuento->percentage);
        }

        sort($del_articulo);
        $actuales_ordenados = $percentages_actuales;
        sort($actuales_ordenados);

        /*
         * Compara los dos conjuntos COMPLETOS: asi tambien cae como desactualizado el articulo al
         * que le falta un descuento que el proveedor agrego, o al que le sobra uno que el proveedor
         * borro — no solo el que tiene un porcentaje viejo.
         *
         * 🔴 Y la comparacion va ANTES de mirar la marca, no al reves. Una version anterior la
         * miraba valor por valor —"si este porcentaje esta entre los del proveedor, la marca no
         * cuenta"— y eso rompia la proteccion justo en el caso mas comun de un proveedor con dos
         * bonificaciones: ficha [10, 5], el usuario aplana el 5 a 10 para ese articulo, y como 10
         * figura entre los del proveedor la marca se ignoraba. El articulo salia 'desactualizado',
         * la ventana lo contaba como actualizable y NO lo contaba entre los editados, y la edicion
         * se destruia sin aviso. Comparando conjuntos primero, [10,10] no es [5,10] y la marca hace
         * su trabajo.
         */
        if ($del_articulo === $actuales_ordenados) {
            // Coincide con la ficha: no hay nada que actualizar ni nada que perder, aunque alguna
            // fila siga marcada de una edicion vieja que despues se realineo.
            return 'al_dia';
        }

        if ($hay_marca) {
            return 'editado_a_mano';
        }

        return 'desactualizado';
    }

    /**
     * Indica si un `article_discount` tagueado esta gobernado por la FICHA del proveedor, o sea si
     * una propagacion puede rehacerlo.
     *
     * 🔴 LO DICE LA COLUMNA `origen`, YA NO SE ADIVINA. Hasta la migracion de 4/9/2026 esto se
     * deducia mirando la FORMA del descuento (si traia porcentaje, si traia monto, si estaba
     * marcado), porque las cuatro vias escriben filas identicas. Esa inferencia produjo NUEVE
     * defectos en cuatro rondas de verificacion, todos de la misma familia: un descuento destruido,
     * duplicado o pisado sin preguntar, en silencio. Cada arreglo tapaba una combinacion y
     * destapaba otra.
     *
     * Ahora solo se rehace lo que la ficha creo, porque es lo unico que la ficha puede reponer. Un
     * descuento de una COMPRA trae la bonificacion negociada en esa compra; uno de un IMPORT, la de
     * esa planilla; y la ficha no tiene de donde sacar ninguna de las dos. Rehacerlos seria
     * destruirlos. `origen` null (filas anteriores a la columna) tampoco se toca: sin saber quien
     * la puso, no se pisa.
     *
     * @param  \App\Models\ArticleDiscount|object $descuento
     * @return bool
     */
    static function gobernado_por_la_ficha($descuento) {

        $origen = isset($descuento->origen) ? $descuento->origen : null;

        return $origen === ArticleDiscount::ORIGEN_FICHA_PROVEEDOR;
    }

    /**
     * Normaliza un porcentaje a string con dos decimales, para poder compararlos con `===` sin que
     * "10", "10.0", 10.00 y "10.00" cuenten como distintos. La columna es decimal(10,2) en las dos
     * tablas, asi que dos decimales es exactamente su precision.
     *
     * @param  mixed $valor
     * @return string|null
     */
    static function normalizar_porcentaje($valor) {

        if (is_null($valor) || $valor === '') {
            return null;
        }

        return number_format((float) $valor, 2, '.', '');
    }

    /**
     * Cuenta como quedaria una propagacion ANTES de hacerla, para la ventana de confirmacion.
     * No modifica nada.
     *
     * @param  \App\Models\Provider $provider
     * @param  \App\Models\User|int|null $user
     * @return array{al_dia:int,desactualizados:int,editados_a_mano:int,total:int,preferencia_activa:bool}
     */
    static function preview_propagacion($provider, $user = null) {

        $vacio = [
            'al_dia'             => 0,
            'desactualizados'    => 0,
            'editados_a_mano'    => 0,
            'total'              => 0,
            'preferencia_activa' => self::debe_aplicar_al_asignar($user),
        ];

        if (is_null($provider)) {
            return $vacio;
        }

        $percentages_actuales = [];

        foreach ($provider->provider_discounts as $provider_discount) {

            $actual = self::normalizar_porcentaje($provider_discount->percentage);
            if (!is_null($actual)) {
                $percentages_actuales[] = $actual;
            }
        }

        /*
         * 🔴 La MISMA guarda que corta `propagar_a_articulos()`, y por eso esta duplicada: sin ella
         * el preview y la accion usan criterios distintos, y la ventana promete lo que la accion no
         * va a hacer. Con un proveedor sin porcentajes utilizables, el preview contaba cada articulo
         * como "se va a actualizar", el usuario confirmaba, y la propagacion devolvia 0.
         */
        if (count($percentages_actuales) === 0) {
            return $vacio;
        }

        $resultado = $vacio;

        /*
         * Solo los articulos que TIENEN descuentos tagueados de este proveedor: a los que no tienen
         * ninguno no se les toca nada, ni se los cuenta. Asignarles descuentos por primera vez es
         * el trabajo de aplicar_al_asignar_proveedor(), no de una propagacion.
         *
         * Se seleccionan solo las columnas que la clasificacion necesita (COLUMNAS_PARA_CLASIFICAR)
         * en vez de traer la fila entera: esto corre en cada guardado de la ficha de un proveedor, y
         * uno grande puede tener miles de filas tagueadas.
         */
        $articulos = ArticleDiscount::where('provider_id', $provider->id)
                                        ->select(self::COLUMNAS_PARA_CLASIFICAR)
                                        ->get()
                                        ->groupBy('article_id');

        foreach ($articulos as $tagueados) {

            $clase = self::clasificar_articulo($tagueados, $percentages_actuales);

            if ($clase === 'al_dia') {
                $resultado['al_dia']++;
            } else if ($clase === 'desactualizado') {
                $resultado['desactualizados']++;
            } else {
                $resultado['editados_a_mano']++;
            }

            $resultado['total']++;
        }

        return $resultado;
    }

    /**
     * Propaga los descuentos ACTUALES del proveedor a sus articulos: re-materializa los
     * `article_discounts` tagueados con los porcentajes de hoy.
     *
     * 🔴 Re-materializar es el punto, y es lo que el pedido literal ("actualizar el precio") NO
     * hace. El recalculo de precios que ya existia (ProviderController -> ProcessSetFinalPrices)
     * lee los `article_discounts`, que son COPIAS con su propio porcentaje: recalcular sin tocarlas
     * da exactamente el mismo precio de antes. El sistema trabaja y nada se mueve.
     *
     * @param  \App\Models\Provider $provider
     * @param  bool  $pisar_editados Si es true, tambien se rehacen los articulos cuyo descuento
     *                               alguien edito a mano. Por defecto NO se tocan.
     * @param  \App\Models\User|int|null $user Usuario/comercio, explicito para poder correr sin sesion.
     * @return array{actualizados:int,respetados:int}
     */
    static function propagar_a_articulos($provider, $pisar_editados = false, $user = null) {

        $resultado = ['actualizados' => 0, 'respetados' => 0];

        // 🔴 Gateado por la preferencia del comercio: con la preferencia apagada este comercio nunca
        // quiso descuentos copiados en sus articulos, y propagarlos le moveria los costos sin
        // haberlo pedido.
        if (is_null($provider) || !self::debe_aplicar_al_asignar($user)) {
            return $resultado;
        }

        $percentages_actuales = [];

        foreach ($provider->provider_discounts as $provider_discount) {

            $actual = self::normalizar_porcentaje($provider_discount->percentage);
            if (!is_null($actual)) {
                $percentages_actuales[] = $actual;
            }
        }

        /*
         * 🔴 Sin porcentajes utilizables en la ficha, propagar es DESTRUIR y nada mas: se borrarian
         * los descuentos tagueados que dejaron las compras y el import, y no habria con que
         * reponerlos. Un catalogo entero pasaria a costo bruto de golpe, con la ventana
         * presentandolo como una actualizacion de rutina.
         *
         * 🔴 La guarda va sobre `$percentages_actuales` y NO sobre `provider_discounts`, y la
         * diferencia no es cosmetica: `provider_discounts.percentage` es nullable, y el has_many del
         * formulario deja agregar una fila sin completarla. Un proveedor con una fila vacia tiene
         * `count($provider->provider_discounts) === 1` —o sea que una guarda contando filas lo deja
         * pasar— pero cero porcentajes con que rehacer nada. Mismo destrozo, por otro camino.
         *
         * Y se cuenta con `count()`, no con `empty()`: sobre una Collection de Laravel `empty()` es
         * SIEMPRE false (verificado con el binario 7.4), asi que una guarda escrita asi no corta.
         */
        if (count($percentages_actuales) === 0) {
            return $resultado;
        }

        $articulos = ArticleDiscount::where('provider_id', $provider->id)
                                        ->select(self::COLUMNAS_PARA_CLASIFICAR)
                                        ->get()
                                        ->groupBy('article_id');

        foreach ($articulos as $article_id => $tagueados) {

            $clase = self::clasificar_articulo($tagueados, $percentages_actuales);

            if ($clase === 'al_dia') {
                continue;
            }

            if ($clase === 'editado_a_mano' && !$pisar_editados) {
                $resultado['respetados']++;
                continue;
            }

            $article = Article::find($article_id);

            if (is_null($article)) {
                continue;
            }

            /*
             * 🔴 Se rehace SOLO lo que gobierna la ficha del proveedor, y el delete+create va dentro
             * de una transaccion.
             *
             * Lo que se conserva y por que:
             *   - los descuentos de MONTO FIJO tagueados, que dejo una compra con su bonificacion
             *     negociada: la ficha del proveedor no tiene de donde reponerlos (solo tiene
             *     porcentajes), asi que borrarlos los perderia para siempre y le subiria el costo al
             *     articulo. Ver gobernado_por_la_ficha().
             *   - los tagueados de OTROS proveedores, que no son de esta operacion.
             *   - los manuales (`provider_id` null), que no se tocan nunca.
             *   - el "Mostrar en la tienda online": si el usuario lo habia activado en alguno de los
             *     descuentos que se rehacen, los nuevos nacen con el tilde puesto. Sin esto, cada
             *     propagacion le apagaba en silencio el precio tachado y el badge de oferta en el
             *     ecommerce, articulo por articulo y sin forma de saber cuales.
             *
             * La transaccion importa por el mecanismo viejo, que sigue vivo: ProviderController
             * despacha ProcessSetFinalPrices cuando algun descuento se toco hace menos de 2 minutos,
             * asi que puede haber un worker recalculando estos mismos articulos. Sin transaccion,
             * ese worker puede leer el articulo entre el DELETE y el INSERT y guardarle un
             * costo_real calculado con CERO descuentos.
             */
            /*
             * Que se rehace: EXACTAMENTE lo que la ficha creo, ni mas ni menos.
             *
             * Con `origen` en la tabla esto dejo de ser una inferencia. Antes habia que deducirlo de
             * la forma del descuento —si traia porcentaje, si traia monto, si estaba marcado— y esa
             * deduccion produjo nueve defectos en cuatro rondas de verificacion.
             *
             * Lo que NO entra, y por que:
             *   - compra / import: traen la bonificacion negociada en esa compra o el valor de esa
             *     planilla. La ficha no tiene con que reponerlos; borrarlos los pierde para siempre.
             *   - manual: lo cargo una persona aparte, no lo gobierna nadie mas.
             *   - origen desconocido (filas anteriores a la columna): sin saber quien las puso, no
             *     se pisan.
             *
             * La decision de si este articulo se toca ya se tomo en `clasificar_articulo`: un
             * 'editado_a_mano' sin tilde ni llega hasta aca.
             */
            $gobernados = collect($tagueados)->filter(function ($descuento) {
                return self::gobernado_por_la_ficha($descuento);
            });

            $mostrar_en_online = 0;

            foreach ($gobernados as $descuento) {
                if ($descuento->show_in_online) {
                    $mostrar_en_online = 1;
                }
            }

            $ids_a_barrer = $gobernados->pluck('id')->all();

            DB::transaction(function () use ($article, $provider, $ids_a_barrer, $mostrar_en_online) {

                if (!count($ids_a_barrer)) {
                    /*
                     * 🔴 Defensa en profundidad: si no hay nada que reemplazar, tampoco se crea.
                     * El DELETE ya era condicional y el CREATE no, asi que un articulo sin filas de
                     * la ficha recibia una fila NUEVA encima de las que ya tenia. Hoy
                     * `clasificar_articulo` no deja llegar ese caso hasta aca, pero la asimetria
                     * entre las dos operaciones fue exactamente el defecto, y no vuelve a existir.
                     */
                    return;
                }

                ArticleDiscount::whereIn('id', $ids_a_barrer)->delete();

                self::create_tagged_discounts(
                    $article,
                    $provider->id,
                    $provider->provider_discounts,
                    $mostrar_en_online,
                    ArticleDiscount::ORIGEN_FICHA_PROVEEDOR
                );
            });

            // Clase de error del 31/8/2026: setFinalPrice lee esta relacion justo abajo.
            $article->unsetRelation('article_discounts');

            // El usuario va explicito: sin el, setFinalPrice resuelve UserHelper::user() por
            // articulo, que con auth por token es un User::find() por cada uno.
            ArticleHelper::setFinalPrice($article, $article->user_id);

            $resultado['actualizados']++;
        }

        return $resultado;
    }

    /* ==================================================================================
     * SINCRONIZACION MANUAL DESDE LA FICHA DEL PROVEEDOR (17/9/2026)
     *
     * Todo lo que sigue es el camino NUEVO, el del boton "Sincronizar articulos". Es otro
     * camino que `propagar_a_articulos()` a proposito, y las dos diferencias importan:
     *
     * 🔴 1. NO consulta `users.aplicar_descuentos_proveedor_al_asignar`. Es una accion
     *    explicita, sobre un proveedor puntual, con un modal que dice cuantos articulos
     *    toca. Gatearla dejaria un boton mudo que devuelve 0 sin explicar que la causa es
     *    un tilde en otra pantalla (decision de Lucas, 17/9/2026). La preferencia sigue
     *    rigiendo TODO lo automatico —alta de articulo, cambio de proveedor, masiva,
     *    import— y por eso el gate de `propagar_a_articulos()` se queda donde esta: sus
     *    llamadores actuales no cambian de comportamiento ni un poco.
     *
     * 🔴 2. Alcanza a TODOS los articulos del proveedor, no solo a los que ya tienen un
     *    descuento tagueado. Ese universo puede ser de miles de articulos, y por eso esto
     *    corre en cola (ProcessSincronizarDescuentosProveedorJob) y no en el request.
     * ================================================================================== */

    /**
     * Porcentajes utilizables que el proveedor tiene HOY en su ficha, normalizados.
     *
     * ⚠️ Cuenta solo los que sirven para rehacer algo: `provider_discounts.percentage` es nullable
     * y el has_many del formulario deja agregar una fila sin completarla. Un proveedor con una fila
     * vacia tiene un `provider_discount` pero cero porcentajes con que rehacer nada.
     *
     * (La misma cuenta esta escrita a mano dentro de `preview_propagacion()` y
     * `propagar_a_articulos()`. No se las refactoriza para usar este metodo: son el camino viejo,
     * estan verificadas y funcionando, y tocarlas para ahorrar seis lineas no paga el riesgo.)
     *
     * @param  \App\Models\Provider $provider
     * @return array
     */
    static function percentages_de_la_ficha($provider) {

        $percentages_actuales = [];

        if (is_null($provider)) {
            return $percentages_actuales;
        }

        foreach ($provider->provider_discounts as $provider_discount) {

            $actual = self::normalizar_porcentaje($provider_discount->percentage);

            if (!is_null($actual)) {
                $percentages_actuales[] = $actual;
            }
        }

        return $percentages_actuales;
    }

    /**
     * ¿Este articulo tiene algun descuento tagueado que la ficha NO puede reponer?
     *
     * O sea: alguno con `origen` = compra, import, o null (desconocido). Es exactamente el
     * complemento de `gobernado_por_la_ficha()`, y es lo que define el grupo "con descuentos de
     * compra" del preview.
     *
     * ⚠️ Los descuentos MANUALES no entran nunca en esta cuenta, porque no estan tagueados
     * (`provider_id` null) y estas colecciones se arman filtrando por `provider_id`. Se quedan
     * afuera de toda la operacion, que es lo que corresponde.
     *
     * @param  iterable $tagueados
     * @return bool
     */
    static function tiene_descuentos_que_la_ficha_no_puede_reponer($tagueados) {

        foreach ($tagueados as $descuento) {

            if (!self::gobernado_por_la_ficha($descuento)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recorre los articulos del proveedor UNA sola vez y los reparte en grupos EXCLUYENTES entre
     * si. Es la base del preview, de la accion y del export de conflictos: los tres tienen que
     * estar de acuerdo sobre que articulo cae en que grupo, o la ventana promete lo que la accion
     * no hace (la clase de error que ya obligo a duplicar la guarda de los porcentajes entre
     * `preview_propagacion()` y `propagar_a_articulos()`).
     *
     * ## El universo
     *
     * Es la UNION de dos conjuntos: los articulos cuyo `provider_id` es este proveedor, y los
     * articulos que tienen algun `article_discount` tagueado a el. No son el mismo conjunto: a un
     * articulo se le puede haber cambiado el proveedor despues de una compra y quedarse con los
     * descuentos tagueados del anterior. La union deja que `solo_con_descuentos` alcance exactamente
     * lo mismo que `propagar_a_articulos()` (que recorre por descuento) y que `todos` sume ademas a
     * los articulos del proveedor que no tienen ninguno.
     *
     * ## Los grupos, en orden de precedencia
     *
     *   1. `sin_descuentos`           -> no tiene NINGUN tagueado a este proveedor.
     *   2. `con_descuentos_de_compra` -> tiene al menos uno que la ficha no puede reponer.
     *   3. `al_dia` / `desactualizados` / `editados_a_mano` -> todo lo tagueado es de la ficha, y
     *      lo clasifica `clasificar_articulo()`.
     *
     * 🔴 El grupo 2 gana sobre el 3, y eso es una diferencia deliberada con `propagar_a_articulos()`:
     * ahi, un articulo con un descuento de compra Y uno de ficha se clasifica mirando solo los de la
     * ficha, y se actualiza. Aca cae en "con descuentos de compra" y su destino lo decide
     * `accion_sobre_compras`, que por defecto es no tocarlo. Es mas conservador y, sobre todo, es lo
     * que hace que el numero del modal signifique algo: el usuario esta eligiendo sobre los
     * articulos que tienen datos que no se pueden reconstruir.
     *
     * Con los tres grupos excluyentes, `total_articulos` es la suma de los cinco contadores. (Solo
     * cuando `hay_descuentos_en_la_ficha` es true: sin porcentajes utilizables no se clasifica nada,
     * porque la accion no va a tocar un solo articulo y contarlos como "desactualizados" seria
     * prometer un trabajo que no se va a hacer.)
     *
     * @param  \App\Models\Provider $provider
     * @return array
     */
    static function escanear_articulos_del_proveedor($provider) {

        $resultado = [
            'hay_descuentos_en_la_ficha' => false,
            'percentages_actuales'       => [],
            'total_articulos'            => 0,
            'sin_descuentos'             => [],
            'con_descuentos_de_compra'   => [],
            'al_dia'                     => [],
            'desactualizados'            => [],
            'editados_a_mano'            => [],
            'tagueados_por_articulo'     => [],
        ];

        if (is_null($provider)) {
            return $resultado;
        }

        $percentages_actuales = self::percentages_de_la_ficha($provider);

        $resultado['percentages_actuales']       = $percentages_actuales;
        $resultado['hay_descuentos_en_la_ficha'] = count($percentages_actuales) > 0;

        /*
         * Se seleccionan solo las columnas que la clasificacion necesita, igual que el preview
         * viejo: un proveedor grande puede tener miles de filas tagueadas.
         *
         * El agrupado se hace a mano y no con `groupBy()` de Collection para que las claves sean
         * enteros con certeza: mas abajo se las usa como indices de array y se las compara contra
         * ids de `Article`.
         */
        $tagueados_por_articulo = [];

        /*
         * 🔴 ESTAS FILAS NO SE HIDRATAN COMO MODELOS ELOQUENT, Y NO ES UNA MICRO-OPTIMIZACION.
         * `toBase()` devuelve `stdClass` crudos del query builder.
         *
         * Este escaneo es el unico pedazo de la sincronizacion que corre SINCRONICO: la accion se
         * mando a la cola justamente porque un proveedor de un comercio grande tiene miles de
         * articulos, pero `sincronizar_descuentos_preview()` —el que abre el modal, o sea el que
         * dispara el usuario antes de confirmar nada— y
         * `sincronizar_descuentos_exportar_conflictos()` pasan por aca adentro de un request HTTP,
         * bajo el `memory_limit` y el `max_execution_time` de PHP-FPM del shared hosting, NO bajo
         * el `timeout = 3600` del worker. Con 8.000 articulos a 3 descuentos cada uno son ~24.000
         * filas: como modelos Eloquent (cada uno con sus atributos originales, su diccionario de
         * cambios y su relacion vacia) eso es varias veces mas memoria que como `stdClass`, y el
         * sintoma seria "el modal no abre" justo en el cliente grande, que es el que mas lo
         * necesita.
         *
         * ⚠️ Se puede hacer porque NADA de lo que consume estas filas necesita un modelo:
         * `gobernado_por_la_ficha()` lee `isset($descuento->origen)`, `clasificar_articulo()` lee
         * `->editado_a_mano` y `->percentage`, y `rehacer_lo_de_la_ficha()` lee `->id` y
         * `->show_in_online` — todo acceso por propiedad. `ArticleDiscount` no declara `$casts`, ni
         * accessors, ni SoftDeletes, ni global scopes, asi que el crudo trae exactamente los mismos
         * valores. Si algun dia alguno de esos consumidores necesita un metodo de Eloquent, se le
         * pasa el id y se busca el modelo ahi, no se vuelve a hidratar el catalogo entero.
         */
        $filas = ArticleDiscount::where('provider_id', $provider->id)
                                    ->select(self::COLUMNAS_PARA_CLASIFICAR)
                                    ->toBase()
                                    ->get();

        foreach ($filas as $fila) {

            $article_id = (int) $fila->article_id;

            if (!isset($tagueados_por_articulo[$article_id])) {
                $tagueados_por_articulo[$article_id] = [];
            }

            $tagueados_por_articulo[$article_id][] = $fila;
        }

        $resultado['tagueados_por_articulo'] = $tagueados_por_articulo;

        // El universo: articulos del proveedor (Article usa SoftDeletes, asi que los borrados ya
        // quedan afuera) mas los que arrastran descuentos tagueados a el.
        $universo = [];

        $ids_del_proveedor = Article::where('provider_id', $provider->id)
                                        ->where('user_id', $provider->user_id)
                                        ->pluck('id');

        foreach ($ids_del_proveedor as $article_id) {
            $universo[(int) $article_id] = true;
        }

        foreach (array_keys($tagueados_por_articulo) as $article_id) {
            $universo[(int) $article_id] = true;
        }

        $resultado['total_articulos'] = count($universo);

        foreach (array_keys($universo) as $article_id) {

            $tagueados = isset($tagueados_por_articulo[$article_id])
                ? $tagueados_por_articulo[$article_id]
                : [];

            if (count($tagueados) === 0) {
                $resultado['sin_descuentos'][] = $article_id;
                continue;
            }

            if (self::tiene_descuentos_que_la_ficha_no_puede_reponer($tagueados)) {
                $resultado['con_descuentos_de_compra'][] = $article_id;
                continue;
            }

            if (!$resultado['hay_descuentos_en_la_ficha']) {
                // Sin porcentajes en la ficha no hay con que comparar ni con que rehacer: no se
                // clasifica. Ver el docblock.
                continue;
            }

            $clase = self::clasificar_articulo($tagueados, $percentages_actuales);

            if ($clase === 'al_dia') {
                $resultado['al_dia'][] = $article_id;
            } else if ($clase === 'desactualizado') {
                $resultado['desactualizados'][] = $article_id;
            } else {
                $resultado['editados_a_mano'][] = $article_id;
            }
        }

        return $resultado;
    }

    /**
     * Cuenta como quedaria la sincronizacion ANTES de hacerla, para el modal del boton. No modifica
     * nada. Es el contrato exacto que consume `empresa-spa`.
     *
     * @param  \App\Models\Provider $provider
     * @return array
     */
    static function preview_sincronizacion($provider) {

        $escaneo = self::escanear_articulos_del_proveedor($provider);

        return [
            'nombre_proveedor'           => is_null($provider) ? null : $provider->name,
            'hay_descuentos_en_la_ficha' => $escaneo['hay_descuentos_en_la_ficha'],
            'total_articulos'            => $escaneo['total_articulos'],
            'sin_descuentos'             => count($escaneo['sin_descuentos']),
            'al_dia'                     => count($escaneo['al_dia']),
            'desactualizados'            => count($escaneo['desactualizados']),
            'editados_a_mano'            => count($escaneo['editados_a_mano']),
            'con_descuentos_de_compra'   => count($escaneo['con_descuentos_de_compra']),
        ];
    }

    /**
     * Ids de los articulos del proveedor que tienen descuentos tagueados que la ficha no puede
     * reponer. Es lo que exporta a excel el boton del modal, para que el comercio pueda mirar la
     * lista antes de elegir entre saltear, pisar y agregar.
     *
     * @param  \App\Models\Provider $provider
     * @return array
     */
    static function ids_articulos_con_descuentos_de_compra($provider) {

        $escaneo = self::escanear_articulos_del_proveedor($provider);

        return $escaneo['con_descuentos_de_compra'];
    }

    /**
     * ¿Es un alcance valido?
     *
     * @param  mixed $alcance
     * @return bool
     */
    static function alcance_valido($alcance) {

        return in_array($alcance, [self::ALCANCE_TODOS, self::ALCANCE_SOLO_CON_DESCUENTOS], true);
    }

    /**
     * ¿Es una accion sobre compras valida?
     *
     * @param  mixed $accion
     * @return bool
     */
    static function accion_sobre_compras_valida($accion) {

        return in_array(
            $accion,
            [self::ACCION_COMPRAS_SALTEAR, self::ACCION_COMPRAS_PISAR, self::ACCION_COMPRAS_AGREGAR],
            true
        );
    }

    /**
     * Sincroniza los descuentos ACTUALES de la ficha del proveedor a sus articulos.
     *
     * 🔴 CORRE EN COLA (ProcessSincronizarDescuentosProveedorJob). No hay sesion ni `Auth::user()`:
     * todo lo que necesita un usuario lo recibe explicito. Por eso `setFinalPrice()` va con
     * `$article->user_id` y por eso este metodo NO consulta ninguna preferencia (ver el bloque de
     * arriba). Es el pozo que ya esta documentado dos veces en `MasiveUpdateHelper` y en
     * `ProcessRow`: dejarlo resolver solo da `false` siempre, con la funcionalidad muerta y sin un
     * solo error que lo delate.
     *
     * @param  \App\Models\Provider $provider
     * @param  string $alcance               ALCANCE_TODOS | ALCANCE_SOLO_CON_DESCUENTOS.
     * @param  bool   $pisar_editados        Si tambien se rehacen los editados a mano.
     * @param  string $accion_sobre_compras  ACCION_COMPRAS_*.
     * @return array
     */
    static function sincronizar_a_articulos(
        $provider,
        $alcance = self::ALCANCE_SOLO_CON_DESCUENTOS,
        $pisar_editados = false,
        $accion_sobre_compras = self::ACCION_COMPRAS_SALTEAR
    ) {

        $resultado = [
            'total_articulos'      => 0,
            'creados'              => 0,
            'actualizados'         => 0,
            'respetados'           => 0,
            'al_dia'               => 0,
            'de_compra_salteados'  => 0,
            'de_compra_pisados'    => 0,
            'de_compra_agregados'  => 0,
        ];

        if (is_null($provider)) {
            return $resultado;
        }

        /*
         * Normalizacion defensiva: si llega cualquier otra cosa se cae al lado seguro en vez de
         * entrar por una rama que nadie eligio. El controller ya rechaza lo que no esta en la lista
         * blanca; esto es para los llamadores de codigo (tests, comandos) y para que el default
         * quede escrito en un solo lugar.
         */
        if (!self::alcance_valido($alcance)) {
            $alcance = self::ALCANCE_SOLO_CON_DESCUENTOS;
        }

        if (!self::accion_sobre_compras_valida($accion_sobre_compras)) {
            $accion_sobre_compras = self::ACCION_COMPRAS_SALTEAR;
        }

        $escaneo = self::escanear_articulos_del_proveedor($provider);

        $resultado['total_articulos'] = $escaneo['total_articulos'];
        $resultado['al_dia']          = count($escaneo['al_dia']);

        /*
         * 🔴 LA MISMA GUARDA QUE CORTA `propagar_a_articulos()`, y por el mismo motivo: sin
         * porcentajes utilizables en la ficha, sincronizar es DESTRUIR y nada mas. Se borrarian los
         * descuentos tagueados que dejaron las compras y el import, sin nada con que reponerlos, y
         * un catalogo entero pasaria a costo bruto de golpe.
         *
         * Y aca pesa mas que en el camino viejo: el modo "todos" alcanza articulos que nunca
         * tuvieron un descuento de la ficha, asi que el destrozo seria mas grande.
         */
        if (!$escaneo['hay_descuentos_en_la_ficha']) {
            return $resultado;
        }

        // 1) Articulos SIN ningun descuento tagueado a este proveedor. Es el alcance nuevo: hasta
        //    hoy eran invisibles para toda propagacion.
        if ($alcance === self::ALCANCE_TODOS) {

            foreach ($escaneo['sin_descuentos'] as $article_id) {

                if (self::aplicar_ficha_al_articulo($provider, $article_id, [], 0)) {
                    $resultado['creados']++;
                }
            }
        }

        // 2) Desactualizados: tienen la copia vieja de la ficha y nadie los edito.
        foreach ($escaneo['desactualizados'] as $article_id) {

            if (self::rehacer_lo_de_la_ficha($provider, $escaneo, $article_id, false)) {
                $resultado['actualizados']++;
            }
        }

        // 3) Editados a mano: se respetan salvo tilde explicito del usuario.
        foreach ($escaneo['editados_a_mano'] as $article_id) {

            if (!$pisar_editados) {
                $resultado['respetados']++;
                continue;
            }

            if (self::rehacer_lo_de_la_ficha($provider, $escaneo, $article_id, false)) {
                $resultado['actualizados']++;
            }
        }

        // 4) Los que tienen descuentos que la ficha no puede reponer. Su destino lo eligio el
        //    usuario en el modal.
        foreach ($escaneo['con_descuentos_de_compra'] as $article_id) {

            if ($accion_sobre_compras === self::ACCION_COMPRAS_SALTEAR) {
                $resultado['de_compra_salteados']++;
                continue;
            }

            if ($accion_sobre_compras === self::ACCION_COMPRAS_PISAR) {

                /*
                 * PISAR: se barre TODO lo tagueado a este proveedor —incluida la bonificacion
                 * negociada de una compra— y se deja solo lo de la ficha. El usuario lo eligio con
                 * el numero a la vista; el default es saltear justamente porque esto no se puede
                 * deshacer.
                 */
                if (self::rehacer_lo_de_la_ficha($provider, $escaneo, $article_id, true)) {
                    $resultado['de_compra_pisados']++;
                }

                continue;
            }

            /*
             * AGREGAR: se rehace lo de la ficha y se DEJA lo de la compra. El articulo queda con
             * los dos, en cascada — 1000 con 10% de compra y 10% de ficha da 810, no 900. Es la
             * opcion que duplica y Lucas la pidio explicitamente.
             *
             * ⚠️ "Agregar" rehace lo de la ficha en vez de sumar una copia mas: si el articulo ya
             * tenia filas de ficha, apilar otras dejaria la operacion NO idempotente y cada click
             * del boton bajaria el costo un escalon mas. Con esto, correrlo dos veces da el mismo
             * resultado que correrlo una.
             */
            if (self::rehacer_lo_de_la_ficha($provider, $escaneo, $article_id, false)) {
                $resultado['de_compra_agregados']++;
            }
        }

        return $resultado;
    }

    /**
     * Rehace en un articulo los descuentos de la ficha del proveedor.
     *
     * @param  \App\Models\Provider $provider
     * @param  array $escaneo     Salida de `escanear_articulos_del_proveedor()`.
     * @param  int   $article_id
     * @param  bool  $barrer_todo Si es true se borra TODO lo tagueado a este proveedor (opcion
     *                            "pisar"); si es false, solo lo que gobierna la ficha.
     * @return bool
     */
    static function rehacer_lo_de_la_ficha($provider, $escaneo, $article_id, $barrer_todo) {

        $tagueados = isset($escaneo['tagueados_por_articulo'][$article_id])
            ? $escaneo['tagueados_por_articulo'][$article_id]
            : [];

        $a_barrer = [];

        foreach ($tagueados as $descuento) {

            if ($barrer_todo || self::gobernado_por_la_ficha($descuento)) {
                $a_barrer[] = $descuento;
            }
        }

        /*
         * "Mostrar en la tienda online": si alguno de los descuentos que se reemplazan lo tenia
         * activado, los nuevos nacen con el tilde puesto. Sin esto, cada sincronizacion le apagaria
         * en silencio el precio tachado y el badge de oferta del ecommerce, articulo por articulo y
         * sin forma de saber cuales. Es exactamente lo que ya hace `propagar_a_articulos()`.
         */
        $mostrar_en_online = 0;
        $ids_a_barrer = [];

        foreach ($a_barrer as $descuento) {

            $ids_a_barrer[] = $descuento->id;

            if ($descuento->show_in_online) {
                $mostrar_en_online = 1;
            }
        }

        return self::aplicar_ficha_al_articulo($provider, $article_id, $ids_a_barrer, $mostrar_en_online);
    }

    /**
     * Borra los descuentos indicados y crea los de la ficha, en una transaccion, y recalcula el
     * precio del articulo.
     *
     * ⚠️ A DIFERENCIA de `propagar_a_articulos()`, aca un `$ids_a_barrer` VACIO es un caso legitimo
     * y se crea igual: es el articulo del proveedor que no tenia ningun descuento, que es
     * justamente lo que el modo "todos" viene a alcanzar. La defensa contra duplicar —que en el
     * camino viejo vive en este punto— aca vive mas arriba, en el reparto en grupos excluyentes de
     * `escanear_articulos_del_proveedor()`: un articulo que ya tiene filas de la ficha nunca llega
     * hasta aca con la lista de barrido vacia.
     *
     * 🔴 La transaccion no es decorativa: `ProviderController` despacha `ProcessSetFinalPrices`
     * cuando algun descuento se toco hace menos de 2 minutos, asi que puede haber un worker
     * recalculando estos mismos articulos. Sin transaccion, ese worker puede leer el articulo entre
     * el DELETE y el INSERT y guardarle un `costo_real` calculado con CERO descuentos.
     *
     * 🔴 `unsetRelation('article_discounts')` antes de recalcular: clase de error del 31/8/2026, ya
     * fijada dos veces en este helper. Eloquent cachea las relaciones ya cargadas, asi que sin esto
     * `setFinalPrice()` calcula con los descuentos de ANTES y guarda el resultado como si estuviera
     * bien, sin ninguna excepcion de por medio.
     *
     * 🔴 Y el usuario va EXPLICITO a `setFinalPrice()`: esto corre en un worker, donde no hay
     * sesion.
     *
     * @param  \App\Models\Provider $provider
     * @param  int   $article_id
     * @param  array $ids_a_barrer
     * @param  int   $mostrar_en_online
     * @return bool  true si el articulo se toco.
     */
    static function aplicar_ficha_al_articulo($provider, $article_id, $ids_a_barrer, $mostrar_en_online) {

        $article = Article::find($article_id);

        if (is_null($article)) {
            return false;
        }

        DB::transaction(function () use ($article, $provider, $ids_a_barrer, $mostrar_en_online) {

            if (count($ids_a_barrer)) {
                ArticleDiscount::whereIn('id', $ids_a_barrer)->delete();
            }

            self::create_tagged_discounts(
                $article,
                $provider->id,
                $provider->provider_discounts,
                $mostrar_en_online,
                ArticleDiscount::ORIGEN_FICHA_PROVEEDOR
            );
        });

        $article->unsetRelation('article_discounts');

        ArticleHelper::setFinalPrice($article, $article->user_id);

        return true;
    }
}
