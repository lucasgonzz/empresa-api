<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/** Los archivos de demostración viajan con el código; los uploads del cliente se preservan. */
class DemoIndustriaAssets
{
    public static function exigir($slug)
    {
        $perfil = DemoIndustriaService::resolver($slug);
        if (!$perfil) {
            throw new RuntimeException('El rubro de la demo no tiene un catálogo preparado.');
        }

        $manifiesto = json_decode((string) file_get_contents(database_path('seeders/data/demo_industrias_assets.json')), true);
        return self::verificar_perfil($slug, $perfil, public_path(), $manifiesto ?: []);
    }

    public static function rutas($slug, array $perfil)
    {
        if (!is_string($slug) || !preg_match('/^[a-z_]+$/D', $slug)) {
            throw new RuntimeException('El rubro de los archivos de demo no es válido.');
        }
        $rutas = [$perfil['logo_url'] ?? ''];
        foreach ($perfil['banners'] ?? [] as $banner) {
            $rutas[] = $banner['desktop_url'] ?? '';
            $rutas[] = $banner['mobile_url'] ?? '';
        }
        foreach ($perfil['productos'] ?? [] as $producto) {
            $rutas[] = $producto['image_url'] ?? '';
        }
        foreach ($rutas as $ruta) {
            if (!is_string($ruta) || !preg_match('#^demo-industrias/'.preg_quote($slug, '#').'/(productos/)?[a-zA-Z0-9_-]+\.webp$#D', $ruta)) {
                throw new RuntimeException('El rubro '.$slug.' contiene una ruta de imagen inválida.');
            }
        }
        return array_values(array_unique($rutas));
    }

    public static function verificar_perfil($slug, array $perfil, $raiz_publica, array $manifiesto)
    {
        $raiz = realpath($raiz_publica);
        $rutas = self::rutas($slug, $perfil);
        foreach ($rutas as $ruta) {
            $archivo = $raiz === false ? false : realpath($raiz.DIRECTORY_SEPARATOR.$ruta);
            $esperado = $manifiesto['archivos']['public/'.$ruta] ?? null;
            if (!$archivo || !is_file($archivo)
                || strpos($archivo, $raiz.DIRECTORY_SEPARATOR) !== 0) {
                throw new RuntimeException('Falta la imagen de demo '.$ruta.'. Distribuí los archivos del rubro antes de preparar la demo.');
            }
            if (!is_array($esperado) || filesize($archivo) !== ($esperado['bytes'] ?? null)
                || hash_file('sha256', $archivo) !== ($esperado['sha256'] ?? null)) {
                throw new RuntimeException('La integridad de la imagen de demo '.$ruta.' no coincide con el paquete.');
            }
        }
        return count($rutas);
    }

    public static function verificar_paquete($ruta_zip, array $manifiesto)
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta_zip) !== true) {
            throw new RuntimeException('No se pudo abrir el paquete de la API.');
        }
        try {
            foreach (['artisan', 'vendor/autoload.php'] as $archivo) {
                if ($zip->locateName($archivo) === false) {
                    throw new RuntimeException('Falta '.$archivo.' en el paquete de la API.');
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = $zip->getNameIndex($i);
                if (strpos($nombre, '\\') !== false || substr($nombre, 0, 1) === '/'
                    || preg_match('#(^|/)\.\.(/|$)#', $nombre)
                    || preg_match('#^(storage|\.git)(/|$)#', $nombre)
                    || preg_match('#^\.env($|\.)#', $nombre)
                    || ($nombre !== 'public/' && strpos($nombre, 'public/') === 0 && strpos($nombre, 'public/demo-industrias/') !== 0)) {
                    throw new RuntimeException('El paquete incluye una ruta que debe preservarse en el destino: '.$nombre.'.');
                }
                if (strpos($nombre, 'public/demo-industrias/') === 0 && substr($nombre, -1) !== '/'
                    && !isset($manifiesto['archivos'][$nombre])) {
                    throw new RuntimeException('El paquete incluye una imagen de demo ajena al manifiesto.');
                }
            }
            foreach ($manifiesto['archivos'] ?? [] as $ruta => $esperado) {
                $contenido = $zip->getFromName($ruta);
                if ($contenido === false || strlen($contenido) !== $esperado['bytes']
                    || hash('sha256', $contenido) !== $esperado['sha256']) {
                    throw new RuntimeException('El paquete no contiene la imagen de demo íntegra '.$ruta.'.');
                }
            }
            return count($manifiesto['archivos'] ?? []);
        } finally {
            $zip->close();
        }
    }
}
