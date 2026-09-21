<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Category;
use App\Services\TiendaNube\TiendaNubeCategoryImageService;
use Illuminate\Support\Facades\Log;

/**
 * La imagen de una categoría, fuera del request de la pantalla (misión
 * asistente-masivas-imagenes-y-remito, 19/9/2026).
 *
 * ImageController::setImage() asigna `categories.image_url` y sincroniza con Tienda Nube, pero
 * vive en un controller con la persona autenticada. El job de imágenes de categorías y la
 * tarjeta "Usar esta imagen" del asistente necesitan exactamente lo mismo desde el worker (sin
 * Auth) y desde el ejecutor de tarjetas: acá está, con la MISMA guarda de Tienda Nube.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class CategoriaImagenHelper
{
    /** Prefijo de los archivos candidatos que el job deja en storage/app/public. */
    const PREFIJO_CANDIDATA = 'catcand_';

    /** Días que una candidata que nadie usó sobrevive en el disco. */
    const DIAS_DE_VIDA_DE_UNA_CANDIDATA = 3;

    /**
     * Deja la URL pública como imagen de la categoría y la sincroniza con Tienda Nube.
     *
     * 🔴 La guarda de Tienda Nube es la MISMA que ImageController::sync_category_image_to_tienda_nube:
     * `env('USA_TIENDA_NUBE', false)` (env() y no config(), tal cual está allá) y el servicio con
     * el user_id de la categoría. Y va en try/catch con log: la imagen ya quedó asignada en el
     * sistema, y un error de Tienda Nube no puede voltear ni el job ni la confirmación de la
     * tarjeta (clase "el aviso que voltea la operación que ya terminó").
     *
     * @param  \App\Models\Category $categoria
     * @param  string               $url_publica  Lo que devuelve ApiUrlHelper::storage($nombre).
     * @return void
     */
    public static function asignar(Category $categoria, $url_publica)
    {
        $anterior = trim((string) $categoria->image_url);

        $categoria->image_url = (string) $url_publica;
        $categoria->save();

        /*
         * La imagen que había NO se borra del disco: reemplazar solo pasa con la confirmación de la
         * persona (alcance "todas"), y si se arrepiente, el archivo anterior sigue ahí para volver a
         * cargarlo desde el ABM. Queda el rastro de cuál era, que es lo que hace posible volver.
         */
        if ($anterior !== '' && $anterior !== (string) $url_publica) {
            Log::warning('CategoriaImagenHelper: imagen de categoría reemplazada desde el asistente.', [
                'category_id' => (int) $categoria->id,
                'anterior'    => $anterior,
                'nueva'       => (string) $url_publica,
            ]);
        }

        if (!env('USA_TIENDA_NUBE', false)) {
            return;
        }

        try {
            $tn = new TiendaNubeCategoryImageService((int) $categoria->user_id);
            $tn->upload_category_image($categoria);
        } catch (\Throwable $e) {
            Log::error('CategoriaImagenHelper: error al subir la imagen de la categoría a Tienda Nube: '.$e->getMessage(), [
                'category_id' => (int) $categoria->id,
            ]);
        }
    }

    /**
     * Las categorías del dueño que no tienen imagen, por nombre.
     *
     * @param  int $owner_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function sin_imagen($owner_id)
    {
        return self::del_dueno($owner_id)
            ->where(function ($q) {
                $q->whereNull('image_url')->orWhere('image_url', '');
            })
            ->get();
    }

    /**
     * Todas las categorías del dueño, por nombre.
     *
     * @param  int $owner_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function todas($owner_id)
    {
        return self::del_dueno($owner_id)->get();
    }

    /**
     * @param  int $owner_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function del_dueno($owner_id)
    {
        return Category::where('user_id', (int) $owner_id)
            ->withCount('articles')
            ->orderBy('name');
    }

    /**
     * Borra las candidatas viejas de storage/app/public (`catcand_*.webp` con más de
     * DIAS_DE_VIDA_DE_UNA_CANDIDATA días). Una candidata dudosa que la persona nunca miró no
     * tiene otro camino de borrado: la tarjeta vence a las 24 h y nadie más la conoce.
     *
     * Best-effort a propósito: corre al arrancar el job y un disco que no se deja listar o un
     * archivo que no se deja borrar no tienen por qué frenar la búsqueda.
     *
     * @return int  Cuántas se borraron.
     */
    public static function purgar_candidatas_viejas()
    {
        $borradas = 0;

        try {
            $archivos = glob(storage_path('app/public/'.self::PREFIJO_CANDIDATA.'*.webp'));

            if (!is_array($archivos)) {
                return 0;
            }

            $limite = time() - (self::DIAS_DE_VIDA_DE_UNA_CANDIDATA * 86400);

            foreach ($archivos as $archivo) {
                $modificado = @filemtime($archivo);

                if ($modificado !== false && $modificado < $limite && @unlink($archivo)) {
                    $borradas++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CategoriaImagenHelper: no se pudieron purgar las candidatas viejas: '.$e->getMessage());
        }

        return $borradas;
    }

    /**
     * Ruta absoluta en disco de una candidata, o null si el nombre no tiene la forma que deja el
     * job. Se valida la forma a propósito: el nombre viaja en `datos` de una tarjeta y vuelve
     * horas después; nunca se arma una ruta con un nombre que no sea `catcand_<uuid>.webp`.
     *
     * @param  mixed $archivo
     * @return string|null
     */
    public static function ruta_de_candidata($archivo)
    {
        $nombre = basename(trim((string) $archivo));

        if (!preg_match('/^'.self::PREFIJO_CANDIDATA.'[a-f0-9-]{36}\.webp$/', $nombre)) {
            return null;
        }

        return storage_path('app/public/'.$nombre);
    }

    /**
     * Copia (o mueve) una candidata a un nombre definitivo `<time><rand>.webp`, el mismo formato
     * que deja ImageController::setImage(), y devuelve su nombre y su URL pública.
     *
     * 🔴 Es lo que hay que hacer ANTES de asignar una candidata como imagen de la categoría. Si
     * `categories.image_url` apuntara al `catcand_*.webp`, purgar_candidatas_viejas() se lo
     * llevaría a los tres días y la categoría quedaría con una imagen rota. El prefijo existe
     * para poder borrar lo que nadie usó; lo que sí se usó tiene que dejar de tener el prefijo.
     *
     * @param  mixed $archivo             Nombre de la candidata.
     * @param  bool  $conservar_candidata true = copiar (la tarjeta sigue mostrando la miniatura
     *                                    hasta la purga); false = mover.
     * @return array|null  ['archivo' => string, 'url' => string], o null si no se pudo.
     */
    public static function promover_candidata($archivo, $conservar_candidata = false)
    {
        $ruta = self::ruta_de_candidata($archivo);

        if (is_null($ruta) || !is_file($ruta)) {
            return null;
        }

        $definitivo = time().rand(1, 100000).'.webp';
        $destino    = storage_path('app/public/'.$definitivo);

        $ok = $conservar_candidata ? @copy($ruta, $destino) : @rename($ruta, $destino);

        if (!$ok) {
            Log::warning('CategoriaImagenHelper: no se pudo promover la candidata a imagen definitiva.', [
                'candidata' => $ruta,
            ]);

            return null;
        }

        return ['archivo' => $definitivo, 'url' => ApiUrlHelper::storage($definitivo)];
    }
}
