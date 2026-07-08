<?php

namespace App\Http\Controllers\Helpers\article;

use App\Models\ArticleDiscount;

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

        // Barrido overwrite: borra únicamente los descuentos tagueados (provider_id no nulo),
        // sin importar de qué proveedor eran antes. Los manuales (provider_id null) quedan
        // intactos, nunca se tocan desde acá.
        ArticleDiscount::where('article_id', $article->id)
                        ->whereNotNull('provider_id')
                        ->delete();

        if (empty($discounts)) {
            return;
        }

        foreach ($discounts as $discount) {

            // Normalizo a objeto para leer percentage/amount sin importar si vino como array
            // (import) o como modelo Eloquent (ProviderOrderDiscount).
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
}
