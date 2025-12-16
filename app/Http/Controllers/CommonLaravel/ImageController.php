<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Jobs\ProcessSyncArticleImageToTiendaNube;
use App\Models\Article;
use App\Models\Image;
use App\Services\MercadoLibre\ProductService;
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
        
        if (env('APP_ENV') == 'local') {
            $name = 'http://empresa.local:8000/storage/'.$name;
        } else {
            $name = env('APP_URL').'/public/storage/'.$name;
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
                $this->deleteImageProp($request->model_name, $request->model_id, $prop_name);
                $model->{$prop_name} = $name;
                $model->save();
            } 
        }
        if (isset($request->image_url_to_delete)) {
            Self::deleteImage($request->image_url_to_delete);
        }

        
        return response()->json(['model' => $this->fullModel($request->model_name, $request->model_id), 'image_url' => $name, 'image_model' => $image], 200);
    }

    function deleteImageProp($_model_name, $id, $prop_name = 'image_url') {
        $model_name = GeneralHelper::getModelName($_model_name);
        $model = $model_name::find($id);
        if (!is_null($model->{$prop_name})) {
            Self::deleteImage($model->{$prop_name});
            $model->{$prop_name} = null;
            $model->save();
        }
        return response()->json(['model' => $this->fullModel($_model_name, $id)], 200);
    }

    function deleteImageModel($model_name, $model_id, $image_id) {
        $image = Image::find($image_id);
        $image_name = $image->{env('IMAGE_URL_PROP_NAME', 'image_url')};
        $array = explode('/', $image_name);
        $image_name = $array[count($array)-1];



        if ($model_name == 'article') {
            $model = Article::find($model_id);
            ProductService::add_article_to_sync($model);

            $tn = new TiendaNubeProductImageService();
            $tn->delete_image_from_article($model, $image);
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
}
