<?php

namespace App\Http\Controllers\Helpers\ResetDatosNegocio;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivos del disco que cuelgan de datos de negocio y que el reset de datos de negocio tiene que apartar
 * (misión reset-datos-cliente, 21/11/2026).
 *
 * Con los ids reiniciados desde 1, una carpeta por id de venta (`vender-items/1/`) o una imagen vieja se pisaría
 * o quedaría pegada al primer registro nuevo. Por eso se MUEVEN (nunca se borran) a
 * `storage/app/respaldos-reset-datos/<stamp>-archivos/`, junto al volcado SQL:
 *   - las imágenes de artículos que `images.hosting_url` referencia (están planas en `storage/app/public`,
 *     compartiendo carpeta con logos: sólo se toma el NOMBRE del archivo, nunca una ruta, y sólo si existe ahí);
 *   - las carpetas de `CatalogoDeTablasDelResetHelper::CARPETAS_DE_ARCHIVOS`, si existen.
 *
 * Un error al mover no revierte el vaciado: se informa en `errores` y el reset termina con advertencias.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class ArchivosDelResetHelper
{
    /**
     * Nombres de archivo de `storage/app/public` que las filas de `images` referencian y que existen de verdad.
     *
     * Lee `images` ANTES del TRUNCATE (después no queda de dónde sacarlos). De cada `hosting_url` se toma sólo el
     * `basename` de la parte de ruta: una URL con `..` (`.../storage/../../app/otro.txt` o `../otro.txt`) se reduce
     * a `otro.txt` y se busca DENTRO de `storage/app/public`, así que nunca saca un archivo de esa carpeta.
     *
     * @return array<int, string> Nombres de archivo (sin ruta), sin repetidos.
     */
    public static function imagenes_a_mover()
    {
        if (!Schema::hasTable('images') || !Schema::hasColumn('images', 'hosting_url')) {
            return [];
        }

        $carpeta_publica = self::carpeta_publica();
        $encontradas = [];

        // Se lee de a miles: `images` puede tener cientos de miles de filas en un cliente grande.
        DB::table('images')
            ->select('id', 'hosting_url')
            ->whereNotNull('hosting_url')
            ->orderBy('id')
            ->chunk(1000, function ($filas) use ($carpeta_publica, &$encontradas) {
                foreach ($filas as $fila) {
                    $nombre = self::nombre_de_archivo($fila->hosting_url);

                    if ($nombre === null || isset($encontradas[$nombre])) {
                        continue;
                    }

                    $ruta = $carpeta_publica . DIRECTORY_SEPARATOR . $nombre;

                    // Sólo archivos comunes que estén realmente dentro de la carpeta pública (sin symlinks que salgan).
                    if (is_file($ruta) && !is_link($ruta)) {
                        $encontradas[$nombre] = true;
                    }
                }
            });

        return array_keys($encontradas);
    }

    /**
     * Carpetas con ids de negocio que el reset mueve, y si existen hoy.
     *
     * @return array<string, bool> `storage/app/<carpeta>` => existe.
     */
    public static function carpetas_previstas()
    {
        $carpetas = [];

        foreach (CatalogoDeTablasDelResetHelper::CARPETAS_DE_ARCHIVOS as $nombre) {
            $carpetas['storage/app/' . $nombre] = is_dir(storage_path('app/' . $nombre));
        }

        return $carpetas;
    }

    /**
     * Mueve las imágenes y las carpetas al respaldo de archivos de esta corrida.
     *
     * La carpeta de destino sólo se crea si hay algo que mover. Cada movimiento que falla se anota en `errores`
     * y sigue con el siguiente: un archivo trabado no frena el resto ni deshace el vaciado.
     *
     * @param array<int, string> $imagenes Nombres de archivo de `storage/app/public` (de imagenes_a_mover()).
     * @param string $stamp Sello `AAAAmmdd-HHiiss` de la corrida.
     * @return array{movidos: int, carpetas: array<int, string>, errores: array<int, string>, destino: string|null}
     */
    public static function mover(array $imagenes, $stamp)
    {
        $resultado = ['movidos' => 0, 'carpetas' => [], 'errores' => [], 'destino' => null];

        // Qué carpetas existen hoy.
        $carpetas_existentes = [];
        foreach (CatalogoDeTablasDelResetHelper::CARPETAS_DE_ARCHIVOS as $nombre) {
            if (is_dir(storage_path('app/' . $nombre))) {
                $carpetas_existentes[] = $nombre;
            }
        }

        if (count($imagenes) === 0 && count($carpetas_existentes) === 0) {
            return $resultado;
        }

        $destino = RespaldoDelResetHelper::directorio() . DIRECTORY_SEPARATOR . $stamp . '-archivos';
        $resultado['destino'] = RespaldoDelResetHelper::RUTA_PUBLICA . '/' . $stamp . '-archivos';

        // La carpeta de respaldos ya la creó y protegió el respaldo SQL; se reafirma por si se llama sola.
        try {
            RespaldoDelResetHelper::proteger_la_carpeta_de_la_web();
        } catch (\Throwable $e) {
            $resultado['errores'][] = $e->getMessage() . ' No se movió ningún archivo.';

            return $resultado;
        }

        if (!is_dir($destino) && !@mkdir($destino, 0775, true) && !is_dir($destino)) {
            $resultado['errores'][] = 'No se pudo crear la carpeta de destino ' . $resultado['destino'] . ': no se movió ningún archivo.';

            return $resultado;
        }

        // Imágenes de artículos: storage/app/public/<archivo> => <destino>/public/<archivo>.
        if (count($imagenes) > 0) {
            $destino_imagenes = $destino . DIRECTORY_SEPARATOR . 'public';

            if (!is_dir($destino_imagenes) && !@mkdir($destino_imagenes, 0775, true) && !is_dir($destino_imagenes)) {
                $resultado['errores'][] = 'No se pudo crear ' . $resultado['destino'] . '/public: las imágenes quedaron en su lugar.';
            } else {
                foreach ($imagenes as $nombre) {
                    $origen = self::carpeta_publica() . DIRECTORY_SEPARATOR . $nombre;

                    if (@rename($origen, $destino_imagenes . DIRECTORY_SEPARATOR . $nombre)) {
                        $resultado['movidos']++;
                    } else {
                        $resultado['errores'][] = 'No se pudo mover la imagen ' . $nombre;
                    }
                }
            }
        }

        // Carpetas completas: storage/app/<carpeta> => <destino>/<carpeta>.
        foreach ($carpetas_existentes as $nombre) {
            if (@rename(storage_path('app/' . $nombre), $destino . DIRECTORY_SEPARATOR . $nombre)) {
                $resultado['movidos']++;
                $resultado['carpetas'][] = 'storage/app/' . $nombre;
            } else {
                $resultado['errores'][] = 'No se pudo mover la carpeta storage/app/' . $nombre;
            }
        }

        return $resultado;
    }

    /**
     * Carpeta donde viven las imágenes de artículos (y los logos).
     *
     * @return string Ruta absoluta de `storage/app/public`.
     */
    public static function carpeta_publica()
    {
        return storage_path('app/public');
    }

    /**
     * Saca un nombre de archivo seguro de un `hosting_url`.
     *
     * Sólo el último tramo de la parte de ruta de la URL (sin consulta ni fragmento). Un nombre vacío, `.`, `..`
     * o con separadores o bytes nulos no es un archivo de la carpeta pública: se descarta.
     *
     * @param mixed $hosting_url Valor de `images.hosting_url`.
     * @return string|null Nombre de archivo, o null si no hay uno usable.
     */
    public static function nombre_de_archivo($hosting_url)
    {
        if (!is_string($hosting_url) || trim($hosting_url) === '') {
            return null;
        }

        $ruta = parse_url($hosting_url, PHP_URL_PATH);
        if (!is_string($ruta) || $ruta === '') {
            // Una cadena sin esquema ni barra ("foto.webp") no la interpreta parse_url como ruta en algunos casos.
            $ruta = $hosting_url;
        }

        $nombre = basename(str_replace('\\', '/', rawurldecode($ruta)));

        if ($nombre === '' || $nombre === '.' || $nombre === '..' || strpos($nombre, '/') !== false || strpos($nombre, "\0") !== false) {
            return null;
        }

        return $nombre;
    }
}
