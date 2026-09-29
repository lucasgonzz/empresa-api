<?php

namespace App\Http\Controllers\Helpers\category;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\ProcessSetFinalPrices;

/**
 * Recalculo de precios por un cambio en una categoria o subcategoria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class PriceTypeHelper {

    /**
     * Despacha UN recalculo de precios en segundo plano para los articulos de la categoria (o de la
     * subcategoria) cuando guardarla puede haberles cambiado el precio (mision
     * recalculo-precios-motor-rapido, 28/9/2026).
     *
     * Cuando corresponde:
     *   - la cuenta tiene la extension `lista_de_precios_por_categoria` (golonorte): los porcentajes
     *     de las listas viven en la categoria/subcategoria (`price_types` con su `percentage`, que el
     *     controller acaba de sincronizar), asi que CADA guardado puede moverlos. Es lo que hacia
     *     este helper desde siempre.
     *   - cambio el margen de la categoria (`percentage_gain`, que lee
     *     ArticlePricesHelper::aplicar_category_percentage_gain() para cualquier cuenta). Una
     *     subcategoria no tiene margen propio: para ella solo cuenta la extension.
     *
     * 🔴 POR QUE EN SEGUNDO PLANO Y NUNCA SINCRONICO. Hasta hoy esto recalculaba en el REQUEST, con
     * un `setFinalPrice()` por articulo, y el ex CategoryController::check_percetange_gain() lo repetia una
     * SEGUNDA vez sobre los mismos articulos si ademas cambio el margen. En una categoria de miles de
     * articulos eso es un guardado que se cuelga (o que corta por tiempo a la mitad, con parte de la
     * categoria recalculada y parte no). Ahora es un ProcessSetFinalPrices acotado a la categoria,
     * visible en la pildora de procesos con el origen `categoria`, y UNO solo por guardado aunque
     * se cumplan las dos condiciones: el recalculo de la categoria ya incluye el margen nuevo.
     *
     * El `user_id` que se despacha es el del DUEÑO: el de la categoria (se crea siempre con el dueño,
     * ver CategoryController::store()) y, si no lo tuviera, el dueño de la sesion. Nunca el de un
     * empleado: el productor filtra los articulos por `user_id` y con el de un empleado no
     * encontraria ninguno.
     *
     * @param  \App\Models\Category|null    $category        Categoria guardada, o null si es una subcategoria.
     * @param  \App\Models\SubCategory|null $sub_category    Subcategoria guardada (solo si $category es null).
     * @param  bool                         $margen_cambiado Si cambio el `percentage_gain` de la categoria.
     * @return bool  true si se despacho el recalculo.
     */
    static function update_article_prices($category, $sub_category = null, $margen_cambiado = false) {

        $modelo = !is_null($category) ? $category : $sub_category;

        if (is_null($modelo)) {
            return false;
        }

        $usa_listas_por_categoria = UserHelper::hasExtencion('lista_de_precios_por_categoria');

        // El margen es solo de la categoria: en una subcategoria no hay nada que haya podido cambiar.
        $cambio_el_margen = !is_null($category) && (bool) $margen_cambiado;

        if (!$usa_listas_por_categoria && !$cambio_el_margen) {
            return false;
        }

        $columna = !is_null($category) ? 'category_id' : 'sub_category_id';

        $owner_id = !empty($modelo->user_id) ? (int) $modelo->user_id : UserHelper::userId();

        ProcessSetFinalPrices::dispatch(
            $owner_id,
            $columna,
            $modelo->id,
            false,
            'categoria',
            $modelo->name
        );

        return true;
    }
}
