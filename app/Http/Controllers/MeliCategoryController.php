<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\MeliCategory;
use App\Services\MercadoLibre\CategoryService;
use App\Services\MercadoLibre\MercadoLibreCategoriaInvalidaException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Categorías de Mercado Libre para el formulario del artículo.
 *
 * Misión mercado-libre-integracion (27/10/2026): el predictor, la navegación del árbol y la
 * resolución de una categoría andan SIN cuenta conectada (catálogo público con token de app; ver
 * `CatalogoPublicoService`). La lógica vive en `CategoryService`.
 */
class MeliCategoryController extends Controller
{
    function show($id) {
        $model = MeliCategory::where('id', $id)
                                ->withAll()
                                ->first();

        return response()->json(['model'    => $model], 200);
    }

    /**
     * LEGACY: predictor por nombre en la ruta. Misma forma que `predictor()`.
     *
     * @param string $article_name
     * @return \Illuminate\Http\JsonResponse
     */
    function category_predictor($article_name) {
        return $this->respuesta_del_predictor((string) $article_name);
    }

    /**
     * Predictor de categorías: `GET meli-category-predictor?q=`.
     *
     * Respuesta: `{categories: [{category_id, category_name, domain_name, path}]}` (hasta 5).
     * Si ML no responde, 422 `{message}`.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function predictor(Request $request) {
        return $this->respuesta_del_predictor((string) $request->query('q', ''));
    }

    /**
     * Respuesta común de las dos rutas del predictor.
     *
     * @param string $q
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_del_predictor($q) {
        $service = new CategoryService($this->userId());

        try {
            $categories = $service->fetch_meli_categories($q, 5);
        } catch (\Throwable $e) {
            Log::warning('MeliCategoryController predictor: '.$e->getMessage());

            return response()->json(['message' => 'No se pudo consultar el predictor de Mercado Libre. Probá de nuevo en unos minutos.'], 422);
        }

        return response()->json(['categories' => $categories], 200);
    }

    /**
     * Hijas de una categoría para navegar el árbol: `GET meli-category-children/{id?}` (sin id, las
     * raíz del sitio).
     *
     * Respuesta: `{categories: [{id, name}], path: [{id, name}], hoja: bool}`.
     *
     * @param string|null $meli_category_id
     * @return \Illuminate\Http\JsonResponse
     */
    function children($meli_category_id = null) {
        $service = new CategoryService($this->userId());

        try {
            $hijas = $service->catalogo()->hijas($meli_category_id);
        } catch (\Throwable $e) {
            Log::warning('MeliCategoryController children: '.$e->getMessage());

            return response()->json(['message' => 'No se pudo consultar el árbol de categorías de Mercado Libre. Probá de nuevo en unos minutos.'], 422);
        }

        if (is_null($hijas)) {
            return response()->json(['message' => 'Mercado Libre no tiene esa categoría.'], 422);
        }

        return response()->json($hijas, 200);
    }

    /**
     * Resuelve la categoría elegida: `POST meli-category/resolve` con `{meli_category_id: 'MLA5232'}`.
     *
     * Respuesta: `{model: {id, meli_category_id, meli_category_name, path, listing_allowed,
     * max_title_length, atributos: [...]}}`. Categoría que no es hoja (o inexistente): 422
     * `{message}`.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function resolve(Request $request) {
        $service = new CategoryService($this->userId());

        try {
            $model = $service->resolver_para_formulario((string) $request->input('meli_category_id', ''));
        } catch (MercadoLibreCategoriaInvalidaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('MeliCategoryController resolve: '.$e->getMessage());

            return response()->json(['message' => 'No se pudo consultar la categoría en Mercado Libre. Probá de nuevo en unos minutos.'], 422);
        }

        return response()->json(['model' => $model], 200);
    }

    /**
     * LEGACY: asigna la categoría al artículo desde el modal del predictor.
     *
     * Devuelve el artículo en `model` (lo que lee la SPA) Y en `article` (lo que devolvía hasta la
     * misión mercado-libre-integracion), para que anden la SPA nueva y la vieja.
     *
     * @param int $article_id
     * @param string $mercado_libre_category_id
     * @return \Illuminate\Http\JsonResponse
     */
    function asignar_meli_category($article_id, $mercado_libre_category_id) {
        $article = Article::where('id', $article_id)
                            ->where('user_id', $this->userId())
                            ->first();

        if (!$article) {
            return response()->json(['message' => 'El artículo no existe o no es de tu cuenta.'], 404);
        }

        $service = new CategoryService($this->userId());

        try {
            $service->assign_to_article($article, $mercado_libre_category_id);
        } catch (\Throwable $e) {
            Log::warning('MeliCategoryController asignar_meli_category: '.$e->getMessage());

            return response()->json(['message' => 'No se pudo asignar la categoría de Mercado Libre: '.$e->getMessage()], 422);
        }

        $full = $this->fullModel('article', $article_id);

        return response()->json(['model' => $full, 'article' => $full], 200);
    }
}
