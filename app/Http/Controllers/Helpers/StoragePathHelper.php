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
 *    Lo que esta verificacion NO ve, y conviene tenerlo escrito para no creerle de mas: los HARD
 *    LINKS. Un hard link creado adentro del directorio permitido apuntando a un archivo de afuera
 *    es indistinguible del archivo original para realpath(), asi que se sirve. Es inherente a
 *    cualquier chequeo por path y requiere acceso de escritura local en el servidor, o sea que ya
 *    perdiste antes; no es algo que este helper pueda resolver. Verificado el 16/8/2026.
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
    const MOTIVO_BASE_INVALIDO    = 'base_invalido';
    const MOTIVO_INEXISTENTE      = 'inexistente';
    const MOTIVO_FUERA_DEL_BASE   = 'fuera_del_base';
    const MOTIVO_NO_ES_ARCHIVO    = 'no_es_archivo';
    const MOTIVO_NO_SE_PUEDE_LEER = 'no_se_puede_leer';

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
         *
         * Este guard no es redundante con el "=== false" de mas abajo, aunque lo parezca. Medido en
         * PHP 7.4.33: realpath() con un byte nulo adentro emite un Warning y devuelve NULL, no
         * false, asi que el chequeo de "=== false" NO lo atrapa. Terminaria cayendo igual en
         * empieza_con(), donde un NULL da largo 0 y devuelve false -- o sea que se rechazaria, pero
         * de rebote, con el motivo equivocado y dejando un Warning en el log de PHP. Sacar este
         * chequeo cambia un rechazo explicito por uno accidental.
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
         * Que cuenta como separador DEPENDE DE LA PLATAFORMA, y tratarlos igual en las dos rompe
         * Linux, que es donde corre produccion. En Linux la barra invertida es un caracter valido
         * de nombre de archivo, no un separador: recortarla de las puntas cambia a que archivo
         * apunta el path.
         *
         * Los dos casos concretos, medidos por el chequeo independiente en WSL el 16/8/2026:
         *   - rtrim del base: un directorio llamado "pub\" quedaba recortado a "pub", con lo cual
         *     el prefijo apuntaba a OTRO directorio. Servia archivos del hermano "pub/" con
         *     motivo=ok y rechazaba los propios. O sea, fuga.
         *   - ltrim del path pedido: "\foto.jpg" quedaba en "foto.jpg" y servia un archivo
         *     distinto del pedido, y "\../secreto.txt" se convertia en "../secreto.txt", creando
         *     un traversal que el input no tenia (lo frena realpath() despues, pero de rebote).
         */
        $separadores = (DIRECTORY_SEPARATOR === '\\') ? "/\\" : "/";

        $base_recortado = rtrim($base_real, $separadores);

        /*
         * Un base que es la raiz del filesystem no confina nada: el prefijo quedaria en "/" (o en
         * "C:\") y CUALQUIER path absoluto lo matchearia. Es el mismo agujero que el base
         * inexistente de arriba, entrando por otra puerta -- y es el caso que el comentario de
         * arriba nombra ("o en DIRECTORY_SEPARATOR a secas") y que hasta el 16/8/2026 no estaba
         * cubierto. Sacados los separadores del final, la raiz de Linux queda en "" y la de Windows
         * en "C:".
         */
        if ($base_recortado === '' || preg_match('~^[A-Za-z]:$~', $base_recortado) === 1) {
            return self::rechazo(self::MOTIVO_BASE_INVALIDO);
        }

        /*
         * El separador al final del prefijo no es cosmetico: es lo unico que impide que
         * "/storage/app/publico" matchee como si estuviera adentro de "/storage/app/public".
         */
        $prefijo = $base_recortado . DIRECTORY_SEPARATOR;

        $candidato = $prefijo . ltrim($relative_path, $separadores);

        $real = realpath($candidato);

        if ($real === false) {
            return self::rechazo(self::MOTIVO_INEXISTENTE);
        }

        /*
         * Pedir el directorio permitido en si ("/storage/." o "/storage/") no es un intento de
         * escape: es un pedido tonto. Se separa del caso de abajo para que no quede anotado en el
         * log como ataque, que era ruido puro.
         */
        if ($real === $base_recortado) {
            return self::rechazo(self::MOTIVO_NO_ES_ARCHIVO);
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

        /*
         * is_readable() no es paranoia: sin este chequeo, un archivo que existe adentro del
         * directorio pero que el usuario del servidor web no puede leer hacia que
         * BinaryFileResponse::setFile() tirara FileException('File must be readable') y la ruta
         * respondiera 500. Un archivo inexistente responde 404.
         *
         * O sea que la diferencia 500 contra 404 volvia a ser el oraculo de existencia que todo
         * este arreglo viene a sacar, solo que un escalon mas adentro. Medido el 16/8/2026 negando
         * el permiso de lectura con icacls sobre un archivo de storage/app/public.
         */
        if (!is_readable($real)) {
            return self::rechazo(self::MOTIVO_NO_SE_PUEDE_LEER);
        }

        return array('path' => $real, 'motivo' => self::MOTIVO_OK);
    }

    /**
     * Dice si el motivo de un inspeccionar() corresponde a un intento de escape que valga la pena
     * loguear.
     *
     * Deja AFUERA a proposito el caso mas comun de traversal: pedir "../algo" donde "algo" no
     * existe cae en MOTIVO_INEXISTENTE y no se loguea, igual que un 404 comun. La razon es que
     * cualquiera que escanee a ciegas genera miles de esos y taparia el log; lo que queda anotado es
     * el intento que efectivamente dio con un archivo real afuera del directorio, que es la señal
     * que importa. La contrapartida, y hay que saberla: un escaneo que no acierta ningun archivo NO
     * deja rastro acá.
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
