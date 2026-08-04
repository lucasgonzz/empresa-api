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

        // La limpieza del sufijo "/public" (incluidas repeticiones tipo "/public/public") ahora
        // vive centralizada en strip_public_suffix(). Antes este bloque hacia solo una pasada con
        // un "if", lo que dejaba pasar valores ya duplicados (ver grupo 237, prompt 01).
        $url = self::strip_public_suffix($url);

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
     * Saca todas las repeticiones finales del sufijo "/public" de una URL, dejandola sin barra
     * final.
     *
     * Usa un "while" (no un "if") a proposito: es exactamente el caso que dejaba pasar el guard de
     * una sola pasada del grupo 230. Un valor como "https://api-x.com/public/public" (o con barras
     * intermedias como "/public//public") queda persistido y nadie lo vuelve a normalizar, porque
     * el chequeo de una sola pasada solo mira el ultimo segmento y ya lo encuentra en "/public".
     * Este fue el bug real detectado en produccion el 27/7/2026 (link de WhatsApp del comprobante
     * de venta roto para un cliente). Con el "while", cada vuelta saca un sufijo y vuelve a hacer
     * rtrim de "/" antes de revisar de nuevo, hasta que no quede ningun "/public" al final.
     *
     * @param  mixed  $url  Valor crudo a normalizar (se castea a string).
     * @return string  URL sin sufijos "/public" finales y sin barra final.
     */
    public static function strip_public_suffix($url)
    {
        // Casteamos a string por si llega null u otro tipo, y limpiamos espacios y barra final
        // antes de empezar a buscar el sufijo.
        $url = (string) $url;
        $url = trim($url);
        $url = rtrim($url, '/');

        // Mientras el final de la URL sea exactamente "/public", lo sacamos y volvemos a limpiar
        // la barra final (para tolerar "/public/public/" o "/public//public"). Usamos substr en
        // vez de str_ends_with porque el repo corre en PHP 7.4 (str_ends_with es de PHP 8).
        while ($url !== '' && substr($url, -7) === '/public') {
            $url = substr($url, 0, -7);
            $url = rtrim($url, '/');
        }

        return $url;
    }

    /**
     * Devuelve la forma canonica de una URL para persistir en "users.api_url": normaliza
     * cualquier sufijo "/public" duplicado o corrupto y vuelve a agregar el segmento correcto
     * segun needs_public_segment().
     *
     * Es idempotente a proposito: aplicar canonical_public_url() sobre su propio resultado tiene
     * que devolver el mismo valor. Esto es lo que permite que, ademas de usarse al guardar un
     * valor nuevo, se pueda usar como comando de limpieza sobre datos ya existentes sin riesgo de
     * ir agregando "/public" de mas en cada corrida (ver prompt 02 del grupo 237).
     *
     * @param  mixed  $url  Valor crudo (posiblemente corrupto) a normalizar.
     * @return string  URL canonica, o cadena vacia si no quedo nada configurado.
     */
    public static function canonical_public_url($url)
    {
        // Primero sacamos cualquier sufijo "/public" repetido, sin importar cuantas veces
        // aparezca al final del valor de entrada.
        $url = self::strip_public_suffix($url);

        // Si no quedo nada (URL vacia o solo compuesta por "/public" repetidos), devolvemos vacio:
        // nunca hay que devolver "/public" suelto, seria una URL sin dominio.
        if ($url === '') {
            return '';
        }

        // Volvemos a agregar el segmento "/public" solo si esta instalacion lo necesita (hosting
        // compartido fuera de local). En VPS o local, la URL normalizada ya es la forma final.
        if (self::needs_public_segment()) {
            return $url.'/public';
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
