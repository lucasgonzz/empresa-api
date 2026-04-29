<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Jobs\ProcessSyncArticleImageToTiendaNube;
use App\Models\Article;
use App\Models\Image;
use App\Services\MercadoLibre\ProductService;
use App\Services\TiendaNube\TiendaNubeCategoryImageService;
use App\Services\TiendaNube\TiendaNubeProductImageService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class ImageController extends Controller
{

    function setImage(Request $request, $prop_name) {
        $manager = new ImageManager();
        $croppedImage = $manager->make($request->image_url); 
        // Log::info('height: '.$croppedImage->height());
        // Log::info('width: '.$croppedImage->width());
        if (isset($request->top)) {
            $croppedImage->crop($request->width, $request->height, $request->left, $request->top);
        }           
        if ($request->model_name == 'user') {
            $name = time().rand(1, 100000).'.png';
        } else {
            $name = time().rand(1, 100000).'.webp';
        }
        $croppedImage->save(storage_path().'/app/public/'.$name);

        $model_name = GeneralHelper::getModelName($request->model_name);
        
        if (config('app.APP_ENV') == 'local') {
            $name = 'http://empresa.local:8000/storage/'.$name;
        } else {

            if (config('app.VPS')) {
                $name = config('app.APP_URL').'/storage/'.$name;
            } else {
                $name = config('app.APP_URL').'/public/storage/'.$name;
            }
        }

        $model = $model_name::find($request->model_id);

        $image = null;
        if ($prop_name == 'has_many') {
            $image = Image::create([
                env('IMAGE_URL_PROP_NAME', 'image_url')     => $name,
                'imageable_id'                              => !is_null($model) ? $request->model_id : null,
                'imageable_type'                            => $request->model_name,
                'temporal_id'                               => $this->getTemporalId($request),
            ]);

            if ($request->model_name == 'article' && !is_null($model)) {
                $helper = new InventoryLinkageHelper();
                $helper->check_created_image($model, $image);

                $model->needs_sync_with_tn = true;
                $model->timestamps = false;
                $model->save();

                ProductService::add_article_to_sync($model);
                TiendaNubeSyncArticleService::add_article_to_sync($model);
            }

        } else {
            if (!is_null($request->model_id)) {
                /* Borrar imagen anterior sin encolar sync a TN (el sync lo hacemos debajo con la nueva) */
                $this->deleteImageProp($request->model_name, $request->model_id, $prop_name, false);
                $model->{$prop_name} = $name;
                $model->save();

                /* Sincronizar la nueva imagen de la categoría con Tienda Nube */
                if ($request->model_name == 'category' && !is_null($model)) {
                    $this->sync_category_image_to_tienda_nube($model);
                }
            } 
        }
        if (isset($request->image_url_to_delete)) {
            Self::deleteImage($request->image_url_to_delete);
        }

        
        return response()->json(['model' => $this->fullModel($request->model_name, $request->model_id), 'image_url' => $name, 'image_model' => $image], 200);
    }

    /**
     * Elimina la propiedad de imagen de un modelo y borra el archivo físico.
     * Si $sync_tn es true y el modelo es una categoría, también sincroniza la eliminación con TN.
     *
     * @param string $_model_name Nombre del modelo (ej. 'article', 'category').
     * @param int    $id          ID del modelo.
     * @param string $prop_name   Nombre de la propiedad de imagen (por defecto 'image_url').
     * @param bool   $sync_tn     Si se debe sincronizar la eliminación con Tienda Nube (default: true).
     * @return \Illuminate\Http\JsonResponse
     */
    function deleteImageProp($_model_name, $id, $prop_name = 'image_url', $sync_tn = true) {
        $model_name = GeneralHelper::getModelName($_model_name);
        $model = $model_name::find($id);
        if (!is_null($model->{$prop_name})) {
            Self::deleteImage($model->{$prop_name});
            $model->{$prop_name} = null;
            $model->save();

            /* Sincronizar la eliminación de imagen de categoría con TN si corresponde */
            if ($sync_tn && $_model_name == 'category') {
                $this->delete_category_image_from_tienda_nube($model);
            }
        }
        return response()->json(['model' => $this->fullModel($_model_name, $id)], 200);
    }

    function deleteImageModel($model_name, $model_id, $image_id) {
        $image = Image::find($image_id);
        $image_name = $image->{env('IMAGE_URL_PROP_NAME', 'image_url')};
        $array = explode('/', $image_name);
        $image_name = $array[count($array)-1];


        Log::info('model_name: '.$model_name);
        if ($model_name == 'article') {
            $article = Article::find($model_id);

            if ($article) {
                Log::info('Llamando a TiendaNubeProductImageService');

                ProductService::add_article_to_sync($article);

                $tn = new TiendaNubeProductImageService();
                $tn->delete_image_from_article($article, $image);

                /* Encolar sync de respaldo: si el DELETE directo falló o el artículo tiene más cambios */
                TiendaNubeSyncArticleService::add_article_to_sync($article);
            } else {
                Log::info('No se llamo a TiendaNubeProductImageService');
            }
        }

        
        $helper = new InventoryLinkageHelper();
        $helper->delete_image($image);

        Storage::disk('public')->delete($image_name);
        $image->delete();

        return response()->json(['model' => $this->fullModel($model_name, $model_id)], 200);
    }

    static function deleteModelImages($model) {
        if (!is_null($model)) {
            foreach ($model->getAttributes() as $prop => $_prop) {
                if (substr($prop, 0, 4) == 'foto' || substr($model->{$prop}, 0, 5) == 'image') {
                    Self::deleteImage($model->{$prop});
                }
            }
        }
    }

    static function deleteImage($prop_value) {
        $storage_name = explode('/', $prop_value);
        $storage_name = $storage_name[count($storage_name)-1];
        Storage::disk('public')->delete($storage_name);
    }

    /**
     * Sube o actualiza la imagen de una categoría en Tienda Nube.
     * Los errores se loguean sin interrumpir el flujo principal.
     *
     * @param mixed $category Instancia del modelo Category o SubCategory.
     * @return void
     */
    private function sync_category_image_to_tienda_nube($category): void
    {
        /* Solo sincronizar si la integración con TN está habilitada */
        if (!env('USA_TIENDA_NUBE', false)) {
            return;
        }

        try {
            $tn = new TiendaNubeCategoryImageService();
            $tn->upload_category_image($category);
        } catch (\Exception $e) {
            Log::error('Error al subir imagen de categoría a Tienda Nube: ' . $e->getMessage());
        }
    }

    /**
     * Elimina la imagen de una categoría en Tienda Nube.
     * Los errores se loguean sin interrumpir el flujo principal.
     *
     * @param mixed $category Instancia del modelo Category o SubCategory.
     * @return void
     */
    private function delete_category_image_from_tienda_nube($category): void
    {
        /* Solo sincronizar si la integración con TN está habilitada */
        if (!env('USA_TIENDA_NUBE', false)) {
            return;
        }

        try {
            $tn = new TiendaNubeCategoryImageService();
            $tn->delete_category_image($category);
        } catch (\Exception $e) {
            Log::error('Error al eliminar imagen de categoría en Tienda Nube: ' . $e->getMessage());
        }
    }
}
