<?php

namespace App\Http\Controllers\Helpers;

use App\Article;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeneralHelper {

    /*
    |--------------------------------------------------------------------------
    | PreviusNext
    |--------------------------------------------------------------------------
    |
    |   * El parametro index indica el numero de dias a retroceder
    |   * Direction indica si se esta subiendo o bajando, se usa en el caso
    |   de que no haya ventas en tal fecha, si se esta bajando continua bajando
    |   y viceversa
    |   * only_one_date indica si se esta retrocediendo desde una fecha en especifico
    |   Si es nulo es porque se esta retrocediendo desde el principio
    |   Si no es nulo se empieza a retroceder desde la fecha que llega en esa variable
    |   
    */

    static function previusDays($model_name, $index) {
        if ($index == 0) {
            $start = Carbon::now()->startOfWeek();
            $end = Carbon::now()->endOfWeek();
        } else {
            $start = Carbon::now()->subWeeks($index)->startOfWeek();
            $end = Carbon::now()->subWeeks($index)->endOfWeek();
        }
        $result = [];
        $index = 0;
        while ($start < $end) {
            $start_date = $start->format('Y-m-d H:i:s');
            $end_date = $start->addDay()->format('Y-m-d H:i:s');
            $models = $model_name::where('user_id', UserHelper::userId())
                            ->whereBetween('created_at', [$start_date, $end_date])
                            ->get();
            $result[$index]['date'] = $start_date;
            $result[$index]['models'] = $models;
            $index++;
        }
        return $result;
    }

    static function checkNewValuesForArticlesPrices($current_value, $new_value, $from_model_id = null, $model_id = null) {
        if ($current_value != $new_value) {
            if (!is_null($from_model_id)) {
                $articles = Article::where($from_model_id, $model_id)
                                    ->get();
                foreach ($articles as $article) {
                    ArticleHelper::setFinalPrice($article);
                }
            } else {
                ArticleHelper::setArticlesFinalPrice();
            }
        }
    }

    static function getPivotValue($model, $prop, $ignore_0 = false) {
        if ($ignore_0) {
            if (isset($model['pivot'][$prop]) && $model['pivot'][$prop] != 0) {
                Log::info('retornando '.$model['pivot'][$prop].' para '.$prop);
                return $model['pivot'][$prop];
            }
            Log::info('no se retorno valor para '.$prop);
        } else if (isset($model['pivot'][$prop])) {
            return $model['pivot'][$prop];
        }
        return null;
    }

    static function getModelName($model_name) {
        $model_name = 'App\Models\/'.ucfirst($model_name);
        $model_name = str_replace('/', '', $model_name);
        if (strpos($model_name, '_') !== false) {
            $pos = strpos($model_name, '_');
            $sub_str = substr($model_name, $pos+1);
            $model_name = substr($model_name, 0, $pos).ucfirst($sub_str);
        }
        if (strpos($model_name, '-') !== false) {
            $pos = strpos($model_name, '-');
            $sub_str = substr($model_name, $pos+1);
            $model_name = substr($model_name, 0, $pos).ucfirst($sub_str);
        }
        return $model_name;
    }

    static function getImportColumns($request) {
        $props = [];
        foreach ($request->all() as $key => $value) {
            if (strpos($key, 'prop_') !== false) {
                if ($value != '' && $value != -1) {
                    $props[strtolower(substr($key, strpos($key, '_')+1))] = (int)$value-1;
                } 
            }
        }
        return $props;
    }

    static function getModelsFromId($model_name, $ids) {
        $models = [];
        foreach ($ids as $id) {
            $models[] = Self::getModelName($model_name)::where('id', $id)
                                                        ->withTrashed()
                                                        ->first();
        }
        return $models;
    }


    /**
     * Nombre a mostrar de un artículo en ventas, PDF y comprobantes.
     * Prioriza pivot.name si el operador personalizó el texto en vender.
     *
     * @param  \App\Models\Article|object  $article
     * @return string
     */
    static function article_name($article) {
        if (
            !is_null($article->pivot)
            && !is_null($article->pivot->name)
            && trim((string) $article->pivot->name) !== ''
        ) {
            return trim((string) $article->pivot->name);
        }

        $name = $article->name;
        if (!is_null($article->pivot) && !is_null($article->pivot->variant_description)) {
            $name .= ' '.$article->pivot->variant_description;
        }
        return $name;
    }

    /**
     * Ruta en disco de un archivo servido por /storage/ o /public/storage/ (sin HTTP).
     *
     * @param  string|null  $image_url
     * @return string|null
     */
    public static function storage_public_path_from_image_url($image_url)
    {
        if (empty($image_url)) {
            return null;
        }

        // La captura tiene que ser el RESTO del path ([^?#]+), no un solo segmento
        // ([^/?#]+): hay URLs de storage con subcarpetas -- el comando set_article_images
        // arma .../storage/articles_images/zip/<desc>/<desc>.jpg. Con la version vieja esto
        // devolvia storage/app/public/articles_images, o sea un DIRECTORIO, que pasaba el
        // file_exists() de abajo y llegaba hasta FPDF, que reventaba sobre una carpeta y
        // dejaba la celda en blanco sin log ni excepcion. Por eso tambien is_file() y no
        // file_exists(): un directorio no es una imagen.
        if (preg_match('~[/\\\\](?:public/)?storage/([^?#]+)~i', $image_url, $matches)) {
            if (strpos($matches[1], '..') === false) {
                $local_path = storage_path('app/public/' . $matches[1]);
                if (is_file($local_path)) {
                    return $local_path;
                }
            }
        }

        if (is_file($image_url)) {
            return $image_url;
        }

        return null;
    }

    /**
     * Ruta a un archivo LOCAL que FPDF puede dibujar, o null. Nunca una URL.
     *
     * El contrato es lo unico que importa aca: quien la consume chequea con is_file()
     * -- que es lo correcto para una ruta local -- y file_exists()/is_file() devuelven
     * SIEMPRE false para un http:// porque el wrapper HTTP de PHP no implementa stat().
     * Cuando este metodo devolvia la URL en el camino de fallback, la celda del PDF
     * quedaba vacia sin excepcion, sin log y sin nada que investigar (bug de los JPG
     * invisibles en ArticleTablePdf, 7/8/2026).
     *
     * @param  string|null  $image_url
     * @return string|null
     */
    public static function pdf_image_path($image_url)
    {
        if (empty($image_url)) {
            return null;
        }

        $local_source = self::storage_public_path_from_image_url($image_url);
        $source_for_read = $local_source ?: $image_url;
        $basename = basename(parse_url($image_url, PHP_URL_PATH) ?: $image_url);
        $base_name = pathinfo($basename, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        $jpg_file_url = storage_path('app/public/' . $base_name . '.jpg');

        // La rama webp va primero a proposito: aprovecha los .jpg ya convertidos que hay
        // en produccion y evita volver a bajar nada. Si la conversion falla ya no se
        // devuelve la URL: se cae a materialize_image_for_pdf() como cualquier otro caso.
        if ($extension === 'webp') {
            if (! is_file($jpg_file_url)) {
                try {
                    $image = @imagecreatefromwebp($source_for_read);
                    if ($image !== false) {
                        imagejpeg($image, $jpg_file_url, 100);
                        imagedestroy($image);
                    }
                } catch (\Exception $e) {
                    Log::info('pdf_image_path: fallo la conversion de webp de '.$image_url.' ('.$e->getMessage().'), intento materializar');
                }
            }

            if (is_file($jpg_file_url)) {
                return $jpg_file_url;
            }
        }

        // Un jpg local es exactamente lo que FPDF quiere: se devuelve tal cual.
        if (! is_null($local_source)) {
            $local_extension = strtolower(pathinfo($local_source, PATHINFO_EXTENSION));
            if ($local_extension === 'jpg' || $local_extension === 'jpeg') {
                return $local_source;
            }
        }

        return self::materialize_image_for_pdf($image_url, $local_source);
    }

    /**
     * Baja (o lee del disco) una imagen, la normaliza a JPEG y la deja cacheada.
     * Devuelve la ruta del archivo cacheado, o null si no se pudo resolver.
     *
     * El cache NO expira. Si alguna vez hay que invalidarlo, alcanza con borrar
     * storage/app/public/pdf_cache/ entero: se regenera solo en el proximo PDF.
     *
     * @param  string       $image_url
     * @param  string|null  $local_source
     * @return string|null
     */
    private static function materialize_image_for_pdf($image_url, $local_source)
    {
        $image = null;
        $canvas = null;

        try {
            // La clave del cache es la URL ENTERA, no el basename: dos articulos de
            // proveedores distintos pueden tener los dos una foto.jpg y con basename uno
            // pisaria al otro (es la debilidad que ya tiene la rama webp de arriba).
            $cache_path = storage_path('app/public/pdf_cache/' . md5($image_url) . '.jpg');
            if (is_file($cache_path)) {
                return $cache_path;
            }

            if (! is_null($local_source)) {
                $bytes = @file_get_contents($local_source);
            } else if (preg_match('~^https?://~i', $image_url)) {
                $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                                ->timeout(10)
                                ->get($image_url);
                // Sin Referer a proposito: mandarlo dispara las protecciones
                // anti-hotlinking de los sitios de origen (grupo 356).
                if (! $response->successful()) {
                    Log::info('pdf_image_path: no se pudo resolver la imagen '.$image_url.' (la descarga devolvio '.$response->status().')');
                    return null;
                }
                $bytes = $response->body();
            } else {
                Log::info('pdf_image_path: no se pudo resolver la imagen '.$image_url.' (no es http(s) y no existe local)');
                return null;
            }

            if ($bytes === false || $bytes === '') {
                Log::info('pdf_image_path: no se pudo resolver la imagen '.$image_url.' (no se pudieron leer los bytes)');
                return null;
            }

            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                Log::info('pdf_image_path: no se pudo resolver la imagen '.$image_url.' (formato ilegible)');
                return null;
            }

            $cache_dir = dirname($cache_path);
            if (! is_dir($cache_dir)) {
                // El segundo is_dir() cubre la carrera: otro proceso pudo haber creado la
                // carpeta entre el chequeo y el mkdir, y ahi mkdir devuelve false sin que
                // haya pasado nada malo.
                if (! @mkdir($cache_dir, 0775, true) && ! is_dir($cache_dir)) {
                    Log::info('pdf_image_path: no se pudo resolver la imagen '.$image_url.' (no se pudo crear '.$cache_dir.')');
                    imagedestroy($image);
                    return null;
                }
            }

            // Aplanar sobre blanco no es adorno: un PNG con canal alfa pasado a JPEG sin
            // fondo sale con fondo NEGRO. Y todo termina en JPEG baseline porque
            // ArticleTablePdf extiende fpdf (no AlphaPDF) y _parsejpg es lo unico que
            // dibuja con garantia.
            $width = imagesx($image);
            $height = imagesy($image);
            $canvas = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
            imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

            $saved = imagejpeg($canvas, $cache_path, 90);

            imagedestroy($canvas);
            imagedestroy($image);

            if (! $saved || ! is_file($cache_path)) {
                Log::info('pdf_image_path: no se pudo resolver la imagen '.$image_url.' (no se pudo guardar el cache)');
                return null;
            }

            return $cache_path;
        } catch (\Exception $e) {
            if (! is_null($canvas) && $canvas !== false) {
                imagedestroy($canvas);
            }
            if (! is_null($image) && $image !== false) {
                imagedestroy($image);
            }
            Log::info('pdf_image_path: no se pudo resolver la imagen '.$image_url.' ('.$e->getMessage().')');
            return null;
        }
    }

    /**
     * OJO: devuelve una ruta local O la URL original tal cual. No sirve para quien vaya a
     * chequear el resultado con is_file()/file_exists() -- para eso esta pdf_image_path().
     * Sus consumidores (CatalogClassic, TCPDCCatalog, BudgetPdf, ArticleListImagePdf) se lo
     * pasan directo a Image(), que baja la URL solo, y por eso toleran que no sea local.
     */
    static function getJpgImage($image_url) {


        if (!is_null($image_url)) {
            $array = explode('/', $image_url);
            $array_name = end($array);
            $name = explode('.', $array_name)[0];
            $extension = strtolower(pathinfo($array_name, PATHINFO_EXTENSION));

            if (config('app.APP_ENV') == 'local') {
                // $jpg_file_url = storage_path('app/' . $name . '.jpg');
                $jpg_file_url = storage_path('app/public/' . $name . '.jpg');
            } else {
                $jpg_file_url = storage_path('app/public/' . $name . '.jpg');
            }

            $local_source = self::storage_public_path_from_image_url($image_url);
            $source_for_read = $local_source ?: $image_url;

            // Convertir a JPG si la imagen es WEBP
            if ($extension === 'webp') {
                if (!file_exists($jpg_file_url)) {
                    try {
                        $image = @imagecreatefromwebp($source_for_read);
                        if ($image !== false) {
                            imagejpeg($image, $jpg_file_url, 100);
                            imagedestroy($image);
                        } else {
                            throw new \Exception("No se pudo crear la imagen desde WEBP.");
                        }
                    } catch (\Exception $e) {
                        return $local_source ?: $image_url;
                    }
                }
                return $jpg_file_url;
            }

            if ($local_source) {
                return $local_source;
            }

            // Si la imagen ya es JPG en disco, devolver ruta local
            if (self::isJpg($image_url)) {
                return $image_url;
            }
        }
        return $image_url;
    }

    public static function isJpg($file) {
        // Verifica que el archivo exista
        if (!file_exists($file)) {
            return false;
        }
        
        // Obtiene información sobre la imagen
        $imageInfo = getimagesize($file);
        
        // Verifica si el tipo MIME es 'image/jpeg'
        if ($imageInfo && $imageInfo['mime'] == 'image/jpeg') {
            return true;
        }
        
        return false;
    }

}