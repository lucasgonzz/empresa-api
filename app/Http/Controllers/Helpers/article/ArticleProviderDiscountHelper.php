<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\Provider;
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
    const COLUMNAS_PARA_CLASIFICAR = [
        'id',
        'article_id',
        'percentage',
        'amount',
        'show_in_online',
        'editado_a_mano',
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
    static function create_tagged_discounts($article, $provider_id, $discounts, $show_in_online = 0) {

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
                // Visibilidad en el ecommerce. Default 0 (el de siempre para compra e import); la
                // propagacion lo pasa en 1 cuando el descuento que reemplaza ya lo tenia activado,
                // para no apagarle al comercio el precio tachado de la tienda sin avisarle.
                'show_in_online' => $show_in_online ? 1 : 0,
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

        foreach ($tagueados as $descuento) {

            // 🔴 Los descuentos de MONTO FIJO no los gobierna la ficha del proveedor y esta funcion
            // no opina sobre ellos: `provider_discounts` solo tiene `percentage`, asi que un
            // `article_discount` tagueado con `amount` solo pudo dejarlo una COMPRA, con la
            // bonificacion negociada de esa compra (NewProviderOrderHelper via ProviderOrderDiscount,
            // que la guarda en `monto`). Ver `descuentos_gobernados_por_la_ficha()`.
            if (!self::gobernado_por_la_ficha($descuento)) {
                continue;
            }

            $porcentaje = self::normalizar_porcentaje($descuento->percentage);

            /*
             * 🔴 La marca la puso ArticleDiscountController::update() en el momento en que una
             * persona cambio el porcentaje. No se deduce comparando numeros: una version anterior lo
             * intentaba asi y un test la puso en rojo, porque al borrar un descuento del proveedor
             * su porcentaje desaparece de toda referencia y los articulos que lo tenian copiado
             * pasaban por editados a mano.
             *
             * Pero la marca sola no alcanza para seguir contandolo como editado: si el valor que
             * tiene HOY coincide con uno de los del proveedor, no hay nada que decidir ni nada que
             * perder. Sin esta salida, un articulo que alguien edito una vez y despues dejo igual al
             * del proveedor quedaba marcado para siempre, y la ventana aparecia en todos los
             * guardados de ese proveedor aunque no hubiera nada para actualizar — hasta volverse
             * ruido que el usuario aprende a confirmar sin leer.
             */
            if ($descuento->editado_a_mano && !in_array($porcentaje, $percentages_actuales, true)) {
                return 'editado_a_mano';
            }

            $del_articulo[] = $porcentaje;
        }

        sort($del_articulo);
        $actuales_ordenados = $percentages_actuales;
        sort($actuales_ordenados);

        // Compara los dos conjuntos completos: asi tambien cae como desactualizado el articulo al
        // que le falta un descuento que el proveedor agrego, o al que le sobra uno que el proveedor
        // borro — no solo el que tiene un porcentaje viejo.
        if ($del_articulo === $actuales_ordenados) {
            return 'al_dia';
        }

        return 'desactualizado';
    }

    /**
     * Indica si un `article_discount` tagueado esta gobernado por la FICHA del proveedor, o sea si
     * una propagacion puede rehacerlo.
     *
     * 🔴 La distincion existe porque `article_discounts` no tiene columna de origen: la compra, el
     * import y la ficha del proveedor escriben todos con `provider_id` seteado y
     * `tipo = TIPO_BONIFICACION_PROVEEDOR`. Lo unico que los separa es la forma del descuento:
     * `provider_discounts` SOLO tiene `percentage`, asi que un tagueado con `amount` cargado solo
     * pudo dejarlo una compra, con la bonificacion de monto fijo que se negocio en esa compra
     * (ProviderOrderDiscount.monto).
     *
     * Rehacerlo seria destruirlo: la ficha del proveedor no tiene de donde reponer ese monto, asi
     * que el descuento se iria para siempre y el costo del articulo subiria solo. Por eso la
     * propagacion no los toca ni los cuenta.
     *
     * @param  \App\Models\ArticleDiscount|object $descuento
     * @return bool
     */
    static function gobernado_por_la_ficha($descuento) {

        $amount = isset($descuento->amount) ? $descuento->amount : null;

        return is_null($amount) || $amount === '' || (float) $amount == 0;
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

        $resultado = $vacio;

        /*
         * Solo los articulos que TIENEN descuentos tagueados de este proveedor: a los que no tienen
         * ninguno no se les toca nada, ni se los cuenta. Asignarles descuentos por primera vez es
         * el trabajo de aplicar_al_asignar_proveedor(), no de una propagacion.
         *
         * Se lee por chunks y no con un `get()` entero: un proveedor grande puede tener miles de
         * filas tagueadas, y esto es un preview que corre en cada guardado de la ficha.
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

        /*
         * 🔴 Sin descuentos cargados en la ficha, propagar es DESTRUIR y nada mas: se borrarian los
         * descuentos tagueados que dejaron las compras y el import, y no habria con que reponerlos.
         * Un catalogo entero pasaria a costo bruto de golpe, con la ventana presentandolo como una
         * actualizacion de rutina.
         *
         * Se corta explicitamente y con `count()`, no con `empty()`: sobre una Collection de Laravel
         * `empty()` es SIEMPRE false (verificado con el binario 7.4), asi que una guarda escrita con
         * `empty()` no corta nada.
         */
        if (count($provider->provider_discounts) === 0) {
            return $resultado;
        }

        $percentages_actuales = [];

        foreach ($provider->provider_discounts as $provider_discount) {

            $actual = self::normalizar_porcentaje($provider_discount->percentage);
            if (!is_null($actual)) {
                $percentages_actuales[] = $actual;
            }
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

                if (count($ids_a_barrer)) {
                    ArticleDiscount::whereIn('id', $ids_a_barrer)->delete();
                }

                self::create_tagged_discounts(
                    $article,
                    $provider->id,
                    $provider->provider_discounts,
                    $mostrar_en_online
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
}
