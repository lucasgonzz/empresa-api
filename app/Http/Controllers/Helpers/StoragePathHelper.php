<?php

namespace App\Http\Controllers\Helpers;

/**
 * StoragePathHelper
 *
 * Confina un path que vino del usuario adentro de un directorio permitido.
 *
 * Existe porque las rutas publicas /storage/, /imported-files/ y /exported-files/ de routes/web.php
 * concatenaban el parametro crudo a storage_path() y servian el resultado. Con "../" se leia
 * cualquier archivo del servidor -- incluido el .env, o sea las credenciales de la base, la API key
 * de Anthropic y las claves fiscales -- sin necesidad de estar autenticado.
 *
 * Dos decisiones de este archivo que alguien va a querer "simplificar", y no hay que:
 *
 * 1) La verificacion se hace SIEMPRE sobre realpath(), nunca sobre el string. Un chequeo textual de
 *    ".." parece equivalente y no lo es: no ve los symlinks, y un symlink adentro del directorio
 *    permitido que apunte afuera es exactamente el caso que un chequeo textual deja pasar. realpath()
 *    resuelve "..", ".", separadores repetidos Y symlinks en una sola pasada, contra el filesystem
 *    real.
 *
 * 2) Hacia afuera todos los motivos de rechazo son indistinguibles: el llamador responde 404 y nunca
 *    403. Un 403 le confirma al atacante que el archivo existe, y esa señal es la mitad del trabajo
 *    de enumerar un servidor. Por eso inspeccionar() devuelve el motivo -- para el log, del lado de
 *    adentro -- y el motivo no cruza nunca el borde HTTP.
 */
class StoragePathHelper
{
    const MOTIVO_OK               = 'ok';
    const MOTIVO_VACIO            = 'path_vacio';
    const MOTIVO_BYTE_NULO        = 'byte_nulo';
    const MOTIVO_BASE_INEXISTENTE = 'base_inexistente';
    const MOTIVO_INEXISTENTE      = 'inexistente';
    const MOTIVO_FUERA_DEL_BASE   = 'fuera_del_base';
    const MOTIVO_NO_ES_ARCHIVO    = 'no_es_archivo';

    /**
     * Resuelve un path relativo adentro de un directorio permitido y dice por que fallo si fallo.
     *
     * El motivo es para uso interno (loguear un intento de escape). La respuesta HTTP tiene que ser
     * la misma para todos los rechazos.
     *
     * @param  string  $base_dir       Directorio permitido, absoluto. Ej: storage_path('app/public').
     * @param  string  $relative_path  Path relativo tal cual lo mando el usuario.
     * @return array   ['path' => string|null, 'motivo' => string]
     */
    public static function inspeccionar($base_dir, $relative_path)
    {
        if (!is_string($base_dir) || $base_dir === '') {
            return self::rechazo(self::MOTIVO_BASE_INEXISTENTE);
        }

        if (!is_string($relative_path) || trim($relative_path) === '') {
            return self::rechazo(self::MOTIVO_VACIO);
        }

        /*
         * El byte nulo trunca el string en las llamadas al filesystem, que son de C: un
         * "foto.jpg\0../../.env" pasaria cualquier validacion de extension y despues abriria otro
         * archivo. Se rechaza antes de tocar el disco. No se sanitiza sacandolo: si vino un byte
         * nulo, el pedido no es honesto.
         */
        if (strpos($relative_path, "\0") !== false || strpos($base_dir, "\0") !== false) {
            return self::rechazo(self::MOTIVO_BYTE_NULO);
        }

        /*
         * realpath() del BASE tambien, no solo del archivo pedido: en hosting compartido storage/
         * puede ser un symlink, y si comparamos el destino ya resuelto contra un base sin resolver,
         * ningun archivo legitimo matchea el prefijo y la ruta deja de servir todo.
         */
        $base_real = realpath($base_dir);

        /*
         * CRITICO, y es el caso que convierte este arreglo en un no-arreglo si se omite: realpath()
         * devuelve false cuando el directorio todavia no existe, y hoy mismo storage/app/exported-files
         * no existe en un checkout limpio. Si se dejara pasar, el prefijo de abajo quedaria en ""
         * (o en DIRECTORY_SEPARATOR a secas) y la comparacion aceptaria CUALQUIER path del disco.
         * Falla cerrado a proposito.
         */
        if ($base_real === false) {
            return self::rechazo(self::MOTIVO_BASE_INEXISTENTE);
        }

        /*
         * El separador al final del prefijo no es cosmetico: es lo unico que impide que
         * "/storage/app/publico" matchee como si estuviera adentro de "/storage/app/public".
         */
        $prefijo = rtrim($base_real, '/\\') . DIRECTORY_SEPARATOR;

        $candidato = $prefijo . ltrim($relative_path, '/\\');

        $real = realpath($candidato);

        if ($real === false) {
            return self::rechazo(self::MOTIVO_INEXISTENTE);
        }

        if (!self::empieza_con($real, $prefijo)) {
            return self::rechazo(self::MOTIVO_FUERA_DEL_BASE);
        }

        /*
         * Un directorio pasa realpath() y pasa file_exists(). response()->file() sobre una carpeta
         * revienta con un 500, y ademas poder preguntar por carpetas es enumeracion gratis. Es la
         * misma razon por la que GeneralHelper::storage_public_path_from_image_url() usa is_file() y
         * no file_exists().
         */
        if (!is_file($real)) {
            return self::rechazo(self::MOTIVO_NO_ES_ARCHIVO);
        }

        return array('path' => $real, 'motivo' => self::MOTIVO_OK);
    }

    /**
     * Igual que inspeccionar(), pero devuelve solo el path resuelto o null.
     *
     * Para los llamadores que no necesitan loguear el motivo.
     *
     * @param  string  $base_dir
     * @param  string  $relative_path
     * @return string|null
     */
    public static function resolver_dentro_de($base_dir, $relative_path)
    {
        $resultado = self::inspeccionar($base_dir, $relative_path);

        return $resultado['path'];
    }

    /**
     * Dice si el motivo de un inspeccionar() corresponde a un intento de escape, y no a un pedido
     * legitimo por un archivo que no existe.
     *
     * Sirve para decidir que se loguea: un 404 comun no aporta nada al log, un intento de salir del
     * directorio si.
     *
     * @param  string  $motivo
     * @return bool
     */
    public static function es_intento_de_escape($motivo)
    {
        return $motivo === self::MOTIVO_FUERA_DEL_BASE || $motivo === self::MOTIVO_BYTE_NULO;
    }

    /**
     * Comparacion de prefijo sin str_starts_with, que es de PHP 8.0 y produccion corre 7.4.
     *
     * En Windows la comparacion va sin distinguir mayusculas porque el filesystem tampoco distingue:
     * con strncmp a secas, un base "C:\..." contra un realpath "c:\..." daria "esta afuera" y
     * romperia el servido legitimo en la maquina de desarrollo. En Linux, que es donde corre
     * produccion, la comparacion es sensible como corresponde.
     *
     * @param  string  $cadena
     * @param  string  $prefijo
     * @return bool
     */
    private static function empieza_con($cadena, $prefijo)
    {
        $largo = strlen($prefijo);

        if ($largo === 0 || strlen($cadena) < $largo) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return strncasecmp($cadena, $prefijo, $largo) === 0;
        }

        return strncmp($cadena, $prefijo, $largo) === 0;
    }

    /**
     * @param  string  $motivo
     * @return array
     */
    private static function rechazo($motivo)
    {
        return array('path' => null, 'motivo' => $motivo);
    }
}
