<?php

namespace App\Http\Controllers\Helpers;

/**
 * Candado de exclusión mutua para el setup de demo.
 *
 * Existe porque DemoSetupHelper::run() arranca con `migrate:fresh`: dos corridas
 * solapadas sobre la misma instancia no se molestan un poco, se destruyen. Medido el
 * 25/8/2026 en empresa_testing_s1: la corrida A murió con
 * `SQLSTATE[42S02] Base table or view not found` adentro de `semilla:datos`, justo
 * cuando la corrida B —disparada a los 130 s— corría su propio `migrate:fresh` y le
 * vació la base abajo de los pies. El endpoint no tenía ninguna exclusión mutua y el
 * admin re-dispara el setup cuando su timeout de 300 s da la corrida por muerta
 * (la corrida sigue viva igual: `ignore_user_abort(true)` + `set_time_limit(0)`).
 *
 * POR QUÉ UN ARCHIVO Y NO Cache::lock NI UNA FILA EN LA BASE
 * ---------------------------------------------------------
 * Si llegás acá con ganas de "simplificar" esto a `Cache::lock('demo-setup')`, leé
 * primero estas tres razones, que son las que descartaron esa opción:
 *
 * 1. `migrate:fresh` vacía la base ENTERA, y ahí adentro está `cache_locks` (el driver
 *    `database` de Laravel guarda los locks en una tabla). O sea: el candado que
 *    tendría que protegernos del wipe se lo lleva puesto el wipe. Lo mismo vale para
 *    cualquier fila de estado propia que inventemos en la base.
 * 2. `CACHE_DRIVER` es `array` en varias instancias (y en testing). El driver `array`
 *    vive en memoria del proceso: dos requests distintos son dos procesos distintos y
 *    cada uno ve su propio candado vacío. Serviría para nada exactamente en el caso
 *    que hay que cubrir.
 * 3. `storage/app/` no lo toca el `migrate:fresh`, y `flock` lo libera el sistema
 *    operativo cuando el proceso muere —salga bien, reviente, o lo mate el server—.
 *    No existe el lock huérfano que deje la instancia trabada para siempre, que es
 *    justo la clase de error que ya nos comió una vez (los tres leads que quedaron en
 *    `ejecutandose` con error NULL el 13/8/2026: todo estado intermedio necesita algo
 *    que lo destrabe que no sea el mismo que lo puso ahí).
 *
 * El archivo .lock NO se borra al soltar, a propósito: entre el `fopen` de un proceso
 * y el `unlink` de otro hay una ventana en la que los dos terminan con handles a inodos
 * distintos y los dos se llevan el candado. Un archivo vacío de 0 bytes que queda ahí
 * para siempre es más barato que esa carrera.
 */
class DemoSetupLockHelper
{
    /**
     * Ruta relativa dentro de storage/. Va en storage/app/ y no en storage/framework/
     * porque `artisan cache:clear` y compañía barren framework/, y el candado tiene que
     * sobrevivir a cualquier limpieza que corra el setup mismo.
     */
    const RUTA_RELATIVA = 'app/demo-setup.lock';

    /**
     * Ruta absoluta del archivo de candado.
     *
     * @return string
     */
    public static function ruta()
    {
        return storage_path(self::RUTA_RELATIVA);
    }

    /**
     * Intenta tomar el candado sin bloquear.
     *
     * No espera: si ya hay una corrida, el que llama tiene que rebotar al toque
     * (409 en el endpoint de admin-sync). Esperar sería peor que rebotar —el setup
     * tarda ~9 minutos y el que espera se lo come colgado del request.
     *
     * @return resource|false El handle abierto y lockeado, o false si ya está tomado.
     */
    public static function tomar()
    {
        $ruta = self::ruta();
        $directorio = dirname($ruta);

        // El directorio puede no existir en una instalación recién clonada:
        // storage/app/ está en el .gitignore con `*`, así que git no lo garantiza.
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0775, true);
        }

        // Modo 'c': crea si no existe, NO trunca. Truncar (modo 'w') pisaría el archivo
        // antes de saber si el candado estaba libre.
        $handle = @fopen($ruta, 'c');

        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    /**
     * Libera el candado tomado con tomar().
     *
     * Se llama siempre desde un `finally`: si el setup revienta a mitad de camino, el
     * candado igual tiene que quedar libre para el próximo intento. (Aun si alguien se
     * olvida del finally, el sistema operativo lo libera al morir el proceso — pero eso
     * es la red de seguridad, no el mecanismo.)
     *
     * @param resource|false $handle Lo que devolvió tomar().
     *
     * @return void
     */
    public static function soltar($handle)
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
