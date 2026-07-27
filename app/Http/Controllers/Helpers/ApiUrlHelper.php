<?php

namespace App\Http\Controllers\Helpers;

/**
 * ApiUrlHelper
 *
 * Contrato: `APP_URL` se carga siempre sin `/public` y sin barra final; el `/public` lo agrega
 * esta clase segun `VPS` y `APP_ENV`. Nadie mas en el repo debe concatenar `/public` a mano: si un
 * lugar nuevo necesita la URL base o publica de la API, tiene que pasar por aca.
 *
 * Unico lugar del repo que sabe cuando la API vive en `{dominio}/public` (hosting compartido) y
 * cuando vive en la raiz (VPS). Antes esta regla estaba duplicada en varios archivos con
 * variantes ligeramente distintas entre si (ver grupo 230, prompt 01).
 */
class ApiUrlHelper
{
    /**
     * URL base de la API, sin `/public` y sin barra final.
     *
     * Es defensiva a proposito: hay instalaciones existentes con `APP_URL` mal cargada en el
     * `.env` (con `/public` incluido, o con barra final), y no se puede confiar en el valor crudo
     * de la config. Con este guard, esas instalaciones quedan corregidas solas en el proximo
     * deploy, sin tocar ningun `.env` a mano.
     *
     * @return string
     */
    public static function base()
    {
        // Valor crudo de configuracion, casteado a string por las dudas de que venga null.
        $url = (string) config('app.APP_URL');
        $url = trim($url);
        $url = rtrim($url, '/');

        // Si la URL cargada en el .env todavia trae el sufijo "/public" (instalacion mal
        // configurada), se lo sacamos aca. Usamos substr en vez de str_ends_with porque el repo
        // corre en PHP 7.4 (str_ends_with es de PHP 8).
        if ($url !== '' && substr($url, -7) === '/public') {
            $url = substr($url, 0, -7);
        }

        // Vuelve a limpiar la barra final por si quedo "https://x.com/public/" (con barra despues
        // del /public que se acaba de sacar).
        $url = rtrim($url, '/');

        // Si no quedo nada configurado, solo en local usamos el valor historico hardcodeado en
        // ImageController/ProcessArticleBatchImagesJob. Fuera de local preferimos devolver vacio
        // (link roto y visible) antes que apuntar a localhost en produccion.
        if ($url === '') {
            if (config('app.APP_ENV') == 'local') {
                return 'http://empresa.local:8000';
            }
            return '';
        }

        return $url;
    }

    /**
     * Indica si esta instalacion necesita agregar el segmento "/public" a las URLs publicas.
     * Hosting compartido (VPS falsy) fuera de local: si. VPS o local: no.
     *
     * @return bool
     */
    public static function needs_public_segment()
    {
        return config('app.APP_ENV') != 'local' && !config('app.VPS');
    }

    /**
     * URL base de la API lista para armar links publicos: incluye "/public" cuando corresponde
     * segun needs_public_segment().
     *
     * @return string
     */
    public static function public_base()
    {
        $url = self::base();

        // Si base() esta vacia (instalacion sin APP_URL fuera de local), no concatenamos nada:
        // devolver "/public" a secas seria una URL rota sin dominio.
        if ($url === '') {
            return '';
        }

        if (self::needs_public_segment()) {
            return $url.'/public';
        }

        return $url;
    }

    /**
     * URL publica de un archivo del disco "public" (storage), con o sin nombre de archivo.
     *
     * No se aplica urlencode al nombre: los consumidores actuales pasan nombres ya generados por
     * el sistema (time().rand().'.webp'), y agregar encoding aca cambiaria URLs ya guardadas.
     *
     * @param  string  $name  Nombre del archivo dentro de storage. Vacio para la carpeta base.
     * @return string
     */
    public static function storage($name = '')
    {
        $base = self::public_base();

        if ($name === '' || is_null($name)) {
            return $base.'/storage';
        }

        return $base.'/storage/'.$name;
    }
}
