<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\article\ArticleVariantGeneratorHelper;
use App\Models\ArticleVariant;
use Illuminate\Http\Request;

class ArticleVariantController extends Controller
{
    /**
     * Genera/actualiza las variantes de un artículo.
     *
     * El cálculo del cartesiano y el diff incremental no destructivo ahora los hace el back
     * (ver ArticleVariantGeneratorHelper) a partir de las article_properties ya guardadas en DB
     * para el artículo. El front solo manda la intención (article_id); ya no arma ni postea el
     * listado completo de variantes calculado en el mixin de JS.
     *
     * @param Request $request Espera `article_id`.
     * @return \Illuminate\Http\JsonResponse Set completo de variantes del artículo, con withAll().
     */
    function store(Request $request) {
        // Id del artículo cuyas variantes se van a generar/actualizar.
        $article_id = $request->article_id;

        // El helper lee las article_properties/article_property_values desde la DB (fuente de verdad),
        // calcula el cartesiano y aplica el diff: crea las combinaciones nuevas (ocultas), no toca las
        // que ya existían y oculta (sin borrar) las que dejaron de ser válidas.
        $generator = new ArticleVariantGeneratorHelper($article_id);
        $models = $generator->generate();

        return response()->json(['models' => $models], 201);
    }

    /**
     * Actualiza una variante puntual (price/image_url/oculta).
     *
     * Prompt 521 (fuente única de verdad del stock de variantes): el stock de una variante
     * ya NO se edita acá. `article_variants.stock` es un campo derivado (suma de sus depósitos
     * en `address_article_variant.amount`) y se mantiene exclusivamente a través del flujo de
     * movimientos (`article-update-varians-stock` -> UpdateVariantsStockHelper -> StockMovement
     * -> CheckVariants). Setearlo a mano acá bypaseaba ese flujo y dejaba los movimientos sin
     * registrar. Si en el futuro hace falta permitir cambiar stock desde este endpoint, debe
     * hacerse generando un StockMovement, no escribiendo la columna directo.
     *
     * @param Request $request Espera price, image_url, oculta (stock ya no se usa acá).
     * @param int $id Id de la ArticleVariant a actualizar.
     * @return \Illuminate\Http\JsonResponse Variante actualizada.
     */
    function update(Request $request, $id) {
        $model = ArticleVariant::find($id);
        $model->price = $request->price;
        $model->image_url = $request->image_url;
        $model->oculta = $request->oculta;
        $model->save();

        return response()->json(['model' => $model], 200);
    }

    /**
     * Actualiza en bloque la disponibilidad (`oculta`) de todas las variantes de un artículo.
     *
     * Acciones soportadas en `request->accion`:
     * - 'todas'     -> disponibles todas (oculta = false).
     * - 'ninguna'   -> ninguna disponible (oculta = true).
     * - 'con_stock' -> disponibles solo las que tienen stock > 0; el resto se oculta.
     *
     * @param Request $request Espera `accion`.
     * @param int $article_id Id del artículo.
     * @return \Illuminate\Http\JsonResponse Set completo de variantes del artículo, con withAll().
     */
    function set_disponibilidad_masiva(Request $request, $article_id) {
        // Acción elegida por el usuario para la disponibilidad masiva.
        $accion = $request->accion;

        if ($accion == 'todas') {
            // Todas las variantes del artículo pasan a estar disponibles.
            ArticleVariant::where('article_id', $article_id)->update(['oculta' => false]);

        } else if ($accion == 'ninguna') {
            // Ninguna variante del artículo queda disponible.
            ArticleVariant::where('article_id', $article_id)->update(['oculta' => true]);

        } else if ($accion == 'con_stock') {
            // Disponibles las que tienen stock > 0...
            ArticleVariant::where('article_id', $article_id)
                            ->where('stock', '>', 0)
                            ->update(['oculta' => false]);

            // ...y ocultas el resto (sin stock cargado o stock <= 0).
            ArticleVariant::where('article_id', $article_id)
                            ->where(function ($query) {
                                $query->whereNull('stock')
                                        ->orWhere('stock', '<=', 0);
                            })
                            ->update(['oculta' => true]);
        }

        $models = ArticleVariant::where('article_id', $article_id)
                                    ->withAll()
                                    ->get();
        return response()->json(['models' => $models], 200);
    }

    function deleteVariants($article_id) {
        ArticleVariant::where('article_id', $article_id)->delete();
    }

    function destroy($id) {
        $model = ArticleVariant::find($id);
        if ($model) {
            $model->delete();
        }
        return response(null);
    }
}
