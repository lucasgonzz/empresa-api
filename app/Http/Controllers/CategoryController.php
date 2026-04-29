<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\category\PriceTypeHelper;
use App\Http\Controllers\Helpers\category\SetPriceTypesHelper;
use App\Models\Article;
use App\Models\Category;
use App\Models\SubCategory;
use App\Services\TiendaNube\TiendaNubeCategoryImageService;
use App\Services\TiendaNube\TiendaNubeCategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{

    public function index() {
        $models = Category::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Category::create([
            'num'                   => $this->num('categories'),
            'name'                  => $request->name,
            'image_url'             => $request->image_url,
            'percentage_gain'       => $request->percentage_gain,
            'show_in_pdf_personalizado'       => $request->show_in_pdf_personalizado,
            'user_id'               => $this->userId(),
        ]);

        GeneralHelper::attachModels($model, 'price_types', $request->price_types, ['percentage']);
        
        SetPriceTypesHelper::set_price_types($model);

        SetPriceTypesHelper::set_rangos($model);

        /* Crear o actualizar la categoría en TN antes de subir la imagen */
        $this->sync_category_to_tienda_nube($model);
        $this->check_tienda_nube_image($model);

        return response()->json(['model' => $this->fullModel('Category', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Category', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Category::find($id);

        $previus_percentage_gain = $model->percentage_gain;

        $model->name                = $request->name;
        $model->image_url           = $request->image_url;
        $model->percentage_gain     = $request->percentage_gain;
        $model->show_in_pdf_personalizado     = $request->show_in_pdf_personalizado;
        
        $model->save();
        GeneralHelper::attachModels($model, 'price_types', $request->price_types, ['percentage']);

        PriceTypeHelper::update_article_prices($model);

        $this->check_percetange_gain($model, $previus_percentage_gain);

        /* Sincronizar el nombre actualizado de la categoría en TN antes de subir la imagen */
        $this->sync_category_to_tienda_nube($model);
        $this->check_tienda_nube_image($model);
        
        return response()->json(['model' => $this->fullModel('Category', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Category::find($id);
        $this->detachArticlesCategory($model);
        $this->deleteSubCategories($model);

        /* Intentar eliminar la categoría en TN antes de borrarla localmente */
        $this->delete_category_from_tienda_nube($model);

        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Category', $model->id);
        $this->sendUpdateModelsNotification('sub_category', false);
        return response(null);
    }

    public function detachArticlesCategory($category) {
        Article::where('user_id', $this->userId())
                ->where('category_id', $category->id)
                ->update([
                    'category_id'       => 0,
                    'sub_category_id'   => 0,
                ]);
    }

    public function deleteSubCategories($category) {
        SubCategory::where('category_id', $category->id)->delete();
    }

    function check_percetange_gain($category, $previus_percentage_gain) {

        if ($previus_percentage_gain != $category->percentage_gain) {

            Log::info('Hubo cambios en percentage_gain de la categoria');
            foreach ($category->articles as $article) {

                ArticleHelper::setFinalPrice($article);
            }
        }
    }

    function check_tienda_nube_image($category) {
        if (
            env('USA_TIENDA_NUBE', false)
            && !is_null($category->image_url)
        ) {
            Log::info('Category recien creada:');
            Log::info($category->toArray());
            $tn = new TiendaNubeCategoryImageService();
            $tn->upload_category_image($category);
        }
    }

    /**
     * Sincroniza la categoría (nombre) a Tienda Nube creándola o actualizándola.
     * Hace refresh del modelo después de la sincronización para obtener el tiendanube_category_id asignado.
     * Los errores se loguean y no interrumpen el flujo principal.
     *
     * @param Category $category Categoría a sincronizar.
     * @return void
     */
    function sync_category_to_tienda_nube($category) {
        /* Solo sincronizar si la integración con TN está habilitada */
        if (!env('USA_TIENDA_NUBE', false)) {
            return;
        }

        try {
            $tn = new TiendaNubeCategoryService();
            $tn->syncRootCategory($category);

            /* Recargar el modelo para que el rest del flujo vea el tiendanube_category_id actualizado */
            $category->refresh();
        } catch (\Exception $e) {
            Log::error('Error al sincronizar categoría con Tienda Nube: ' . $e->getMessage());
        }
    }

    /**
     * Elimina la categoría de Tienda Nube si tiene ID asignado.
     * Los errores se loguean y no interrumpen el flujo principal.
     *
     * @param Category $category Categoría a eliminar de TN.
     * @return void
     */
    function delete_category_from_tienda_nube($category) {
        /* Solo sincronizar si la integración con TN está habilitada */
        if (!env('USA_TIENDA_NUBE', false)) {
            return;
        }

        try {
            $tn = new TiendaNubeCategoryService();
            $tn->deleteRootCategory($category);
        } catch (\Exception $e) {
            Log::error('Error al eliminar categoría en Tienda Nube: ' . $e->getMessage());
        }
    }
}
