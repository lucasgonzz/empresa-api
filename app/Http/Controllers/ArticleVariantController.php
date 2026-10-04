<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\article\ArticleVariantBarCodeHelper;
use App\Http\Controllers\Helpers\article\ArticleVariantGeneratorHelper;
use App\Http\Controllers\Helpers\UserHelper;
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
     * Código de barras propio de la variante (`bar_code`, contrato C1 de la misión
     * "codigo-de-barras-de-variantes"): es OPCIONAL. Si la clave no viene en el request no se toca
     * (la SPA vieja manda solo price, image_url y oculta). Si viene, pasa por
     * ArticleVariantBarCodeHelper (normalizado como el lector, vacío = '0'.id, máximo 20 caracteres,
     * sin los caracteres que rompen la ruta del escaneo, sin repetir con otra variante ni con un
     * artículo del mismo dueño).
     *
     * @param Request $request Espera price, image_url, oculta y, opcional, bar_code (stock ya no se usa acá).
     * @param int $id Id de la ArticleVariant a actualizar.
     * @return \Illuminate\Http\JsonResponse Variante actualizada; 404 con `message` si la variante no
     *         existe o no es de un artículo del dueño del usuario logueado; o 422 con `message` si el
     *         código de barras no es válido (en ninguno de los dos casos se guarda NADA: ni el
     *         código, ni el precio, ni oculta).
     */
    function update(Request $request, $id) {
        $model = ArticleVariant::find($id);

        // La variante tiene que existir y ser de un articulo del DUENIO del usuario logueado
        // (`UserHelper::userId()` resuelve al duenio tambien para los empleados). `article_variants`
        // no tiene `user_id`, y `find($id)` a secas dejaba editar la variante de otro comercio con solo
        // saber su id. Se corta ANTES de leer o escribir cualquier campo, con un mensaje fijo: si
        // siguiera, la validacion de repetidos del codigo de barras contestaria con el nombre del
        // articulo y la variante ajenos. Cubre de paso el id inexistente, que antes era un 500.
        if (is_null($model) || !ArticleVariantBarCodeHelper::belongs_to_owner($model, UserHelper::userId())) {
            return response()->json(['message' => 'No se encontró la variante.'], 404);
        }

        // Se valida el código ANTES de asignar o guardar cualquier campo: con un código inválido el
        // comerciante tiene que ver el aviso y que la variante quede exactamente como estaba. Si se
        // validara después de asignar price/oculta, un cambio de orden futuro podría guardar el
        // precio y rechazar solo el código, dejando la grilla a medio guardar.
        // `has` y no `filled`: un código vacío es un pedido válido (volver a '0'.id), no una omisión.
        $tiene_bar_code = $request->has('bar_code');
        $bar_code_final = null;

        if ($tiene_bar_code) {
            $resultado = ArticleVariantBarCodeHelper::validate_bar_code_for_update($model, $request->bar_code);

            if (isset($resultado['error'])) {
                return response()->json(['message' => $resultado['error']], 422);
            }

            $bar_code_final = $resultado['bar_code'];
        }

        $model->price = $request->price;
        $model->image_url = $request->image_url;
        $model->oculta = $request->oculta;

        // Solo se escribe el código si el request lo trajo: sin la clave queda el que tenía.
        if ($tiene_bar_code) {
            $model->bar_code = $bar_code_final;
        }

        $model->save();

        // Se devuelve con las mismas relaciones que el resto de los endpoints de variantes (withAll):
        // la SPA reemplaza la variante del store con esta respuesta, y sin `addresses` la fila de la
        // grilla revienta al renderizar apenas se habilita o se oculta una variante.
        $model->load('article_property_values', 'addresses');

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

        // Variantes que corresponden a las propiedades actuales. Las huérfanas (combinaciones que
        // dejaron de ser válidas) se ocultan pero no se borran, y la grilla del SPA no las muestra:
        // habilitarlas desde acá las dejaría vendiéndose en Vender y en la tienda sin que nadie las vea.
        $generator = new ArticleVariantGeneratorHelper($article_id);
        $valid_ids = $generator->valid_variant_ids();

        if ($accion == 'todas') {
            // Todas las variantes vigentes del artículo pasan a estar disponibles.
            ArticleVariant::where('article_id', $article_id)
                            ->whereIn('id', $valid_ids)
                            ->update(['oculta' => false]);

        } else if ($accion == 'ninguna') {
            // Ninguna variante del artículo queda disponible.
            ArticleVariant::where('article_id', $article_id)->update(['oculta' => true]);

        } else if ($accion == 'con_stock') {
            // Disponibles las vigentes que tienen stock > 0...
            ArticleVariant::where('article_id', $article_id)
                            ->whereIn('id', $valid_ids)
                            ->where('stock', '>', 0)
                            ->update(['oculta' => false]);

            // ...y ocultas el resto (sin stock cargado o stock <= 0).
            ArticleVariant::where('article_id', $article_id)
                            ->where(function ($query) use ($valid_ids) {
                                $query->whereNull('stock')
                                        ->orWhere('stock', '<=', 0)
                                        ->orWhereNotIn('id', $valid_ids);
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
