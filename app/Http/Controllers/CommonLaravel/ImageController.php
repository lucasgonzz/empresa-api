<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Jobs\ProcessSyncArticleImageToTiendaNube;
use App\Models\Article;
use App\Models\Image;
use App\Services\MercadoLibre\ProductService;
use App\Services\TiendaNube\TiendaNubeCategoryImageService;
use App\Services\TiendaNube\TiendaNubeProductImageService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use App\Services\Traits\GoogleSearchHelpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class ImageController extends Controller
{
    use GoogleSearchHelpers;

    /**
     * Obtiene el contenido de la imagen de origen para pasarselo a Intervention.
     *
     * Intervention, cuando recibe una URL, la baja con file_get_contents: sin User-Agent, sin
     * Referer y sin timeout. Muchos sitios le responden 403 a un pedido asi aunque la imagen se vea
     * perfecto en el navegador del usuario. Por eso la bajamos nosotros, con el mismo criterio que
     * ProcessArticleBatchImagesJob::download_crop_and_save(), y a Intervention le pasamos los bytes.
     *
     * Los data URI (subida de archivo desde el navegador) y los paths locales no se descargan: van
     * derecho a Intervention como hasta ahora.
     *
     * @param string $image_url URL http(s), data URI base64, o path local.
     * @return array Con claves 'data' (string|null), 'failure' ('http'|null) y 'http_status' (int|null).
     */
    private function get_image_source($image_url)
    {
        $value = (string) $image_url;

        /* Todo lo que no sea http(s) va directo: data URI, path local, contenido binario. */
        if (strpos($value, 'http://') !== 0 && strpos($value, 'https://') !== 0) {
            return ['data' => $value, 'failure' => null, 'http_status' => null];
        }

        $http_status = null;

        try {
            $response = $this->google_http()
                ->timeout(12)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept'     => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->get($value);

            $http_status = $response->status();

            if ($response->successful()) {
                $body = $response->body();
                if (!is_null($body) && $body !== '') {
                    return ['data' => $body, 'failure' => null, 'http_status' => $http_status];
                }
            }
        } catch (\Exception $e) {
            Log::info('setImage: fallo la descarga de '.$value.' -- '.$e->getMessage());
        }

        return ['data' => null, 'failure' => 'http', 'http_status' => $http_status];
    }

    /**
     * Respuesta de error cuando la imagen de origen no se pudo obtener o no se pudo procesar.
     *
     * Se devuelve 422 y no 500 a proposito: no es un bug del sistema, es una condicion normal del
     * mundo real (un sitio ajeno que no nos deja bajar su imagen). El 'message' esta escrito para
     * mostrarse tal cual al usuario, sin que el SPA tenga que interpretarlo.
     *
     * Sin la clave 'errors' a proposito: el interceptor de empresa-spa trata cualquier 422 CON
     * 'errors' como error de validacion de Laravel y le arma otro toast por su cuenta.
     *
     * @param string   $failure     'http' si fallo la descarga, cualquier otra cosa si fallo el formato.
     * @param int|null $http_status Status HTTP de la descarga, si se llego a tener respuesta.
     * @return \Illuminate\Http\JsonResponse
     */
    private function image_source_error_response($failure, $http_status)
    {
        if ($failure === 'http') {
            $code    = 'download_failed';
            $message = 'No se pudo descargar la imagen desde el sitio de origen. Ese sitio no permite que la bajemos, aunque en pantalla se vea bien. Elegí otra imagen de los resultados.';
        } else {
            $code    = 'invalid_image';
            $message = 'El archivo que devolvió el sitio de origen no es una imagen que podamos procesar. Elegí otra imagen de los resultados.';
        }

        return response()->json([
            'message'     => $message,
            'image_error' => $code,
            'http_status' => $http_status,
        ], 422);
    }

    function setImage(Request $request, $prop_name) {
        if (is_null($request->image_url) || $request->image_url === '') {
            return response()->json([
                'message'     => 'No se recibió ninguna imagen para guardar.',
                'image_error' => 'empty_source',
            ], 422);
        }

        $source = $this->get_image_source($request->image_url);

        if (!is_null($source['failure'])) {
            Log::info('setImage: no se pudo descargar la imagen. status: '.var_export($source['http_status'], true).' url: '.$request->image_url);

            return $this->image_source_error_response($source['failure'], $source['http_status']);
        }

        try {
            $manager      = new ImageManager();
            $croppedImage = $manager->make($source['data']);
        } catch (\Exception $e) {
            Log::info('setImage: Intervention no pudo procesar la imagen -- '.$e->getMessage());

            return $this->image_source_error_response('format', $source['http_status']);
        }
        if (isset($request->top)) {
            $croppedImage->crop($request->width, $request->height, $request->left, $request->top);
        }           
        /**
         * webp para todo, MENOS para las imagenes que terminan impresas en un PDF.
         *
         * La copia de FPDF de este repo solo sabe parsear jpg, png y gif
         * (CommonLaravel/fpdf/fpdf.php: _parsejpg, _parsepng, _parsegif): con cualquier
         * otra extension llama a Error() y tira una excepcion que corta la generacion del
         * comprobante entero. Por eso el logo del negocio (user) siempre fue png.
         *
         * El logo de la sucursal (address) va al mismo lugar de los mismos comprobantes
         * desde la tarea 17, asi que tiene que guardarse igual. Sacar 'address' de esta
         * condicion no rompe la subida --se ve bien en el ABM-- y rompe la impresion de
         * los tickets y las facturas de esa sucursal, que es el peor lugar para enterarse.
         */
        if ($request->model_name == 'user' || $request->model_name == 'address') {
            $name = time().rand(1, 100000).'.png';
        } else {
            $name = time().rand(1, 100000).'.webp';
        }
        try {
            $croppedImage->save(storage_path().'/app/public/'.$name);
        } catch (\Exception $e) {
            Log::info('setImage: no se pudo guardar la imagen en disco -- '.$e->getMessage());

            return response()->json([
                'message'     => 'La imagen se descargó bien pero no se pudo guardar en el servidor. Volvé a intentar en un momento.',
                'image_error' => 'storage_failed',
            ], 422);
        }

        $model_name = GeneralHelper::getModelName($request->model_name);
        
        // URL publica del archivo, centralizada en ApiUrlHelper (unico lugar que sabe si
        // corresponde agregar "/public" segun VPS/APP_ENV; ver grupo 230, prompt 01).
        $name = ApiUrlHelper::storage($name);

        $model = $model_name::find($request->model_id);

        $image = null;
        if ($prop_name == 'has_many') {
            $image = Image::create([
                'hosting_url'                                => $name,
                'imageable_id'                                => !is_null($model) ? $request->model_id : null,
                'imageable_type'                              => $request->model_name,
                'temporal_id'                                 => $this->getTemporalId($request),
            ]);

            if ($request->model_name == 'article' && !is_null($model)) {
                $helper = new InventoryLinkageHelper();
                $helper->check_created_image($model, $image);

                $model->needs_sync_with_tn = true;
                /* No desactivar timestamps: el save() debe bumpear updated_at para que el sync incremental del front (sync_articles.js) vuelva a bajar el articulo con su imagen nueva. */
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

                /**
                 * Cabecera de plantilla PDF (pdf_column_profiles.header_image_url).
                 */
                if ($request->model_name == 'pdf_column_profile' && ! is_null($model) && $prop_name === 'header_image_url') {
                    // Sin sync externo; solo persistencia en el perfil.
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
        $image_name = $image->hosting_url;
        $array = explode('/', $image_name);
        $image_name = $array[count($array)-1];


        Log::info('model_name: '.$model_name);
        if ($model_name == 'article') {
            $article = Article::find($model_id);

            if ($article) {
                Log::info('Llamando a TiendaNubeProductImageService');

                ProductService::add_article_to_sync($article);

                $tn = new TiendaNubeProductImageService($article->user_id);
                $tn->delete_image_from_article($article, $image);

                /* Encolar sync de respaldo: si el DELETE directo falló o el artículo tiene más cambios */
                TiendaNubeSyncArticleService::add_article_to_sync($article);

                /* Bumpear updated_at del articulo para que la baja de imagen tambien se propague al sync incremental del front (sync_articles.js) */
                $article->save();
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
            $user_id = isset($category->user_id) ? (int) $category->user_id : (int) $this->userId();
            $tn = new TiendaNubeCategoryImageService($user_id);
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
            $user_id = isset($category->user_id) ? (int) $category->user_id : (int) $this->userId();
            $tn = new TiendaNubeCategoryImageService($user_id);
            $tn->delete_category_image($category);
        } catch (\Exception $e) {
            Log::error('Error al eliminar imagen de categoría en Tienda Nube: ' . $e->getMessage());
        }
    }
}
