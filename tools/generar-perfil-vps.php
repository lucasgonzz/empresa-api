<?php

/**
 * Prepara el manifiesto optativo desde la base vigente, sin tocar ningún lock.
 * Uso: php tools/generar-perfil-vps.php
 * Después hay que resolver y validar el lock con Composer en un staging PHP 8.4.
 * Este preparador no hace red ni ejecuta Composer o artisan.
 */
function perfil_vps_leer($ruta)
{
    $datos = json_decode(file_get_contents($ruta), true);
    if (!is_array($datos)) {
        throw new RuntimeException('No se pudo leer el JSON: ' . $ruta);
    }
    return $datos;
}

function perfil_vps_canonico($valor)
{
    if (is_object($valor)) {
        $propiedades = get_object_vars($valor);
        ksort($propiedades, SORT_STRING);
        foreach ($propiedades as &$propiedad) {
            $propiedad = perfil_vps_canonico($propiedad);
        }
        return (object) $propiedades;
    }
    if (is_array($valor)) {
        return array_map('perfil_vps_canonico', $valor);
    }
    return $valor;
}

function perfil_vps_huella($ruta)
{
    $datos = json_decode(file_get_contents($ruta));
    if ($datos === null) {
        throw new RuntimeException('No se pudo calcular la huella de ' . $ruta);
    }
    return hash('sha256', json_encode(perfil_vps_canonico($datos), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

try {
    $raiz = dirname(__DIR__);
    $normal = perfil_vps_leer($raiz . '/composer.json');

    $perfil = $normal;
    $perfil['require']['php'] = '>=8.4.27 <8.5';
    $perfil['require']['openspout/openspout'] = '4.32.0';
    $perfil['config']['platform']['php'] = '8.4.27';
    $mapa_linux = [
        'app/Http/Controllers/Pdf/reportes/InventarioPdf.php',
        'app/Http/Controllers/Pdf/reportes/ClientesPdf.php',
        'app/Http/Controllers/Pdf/reportes/ReportePdf.php',
        'app/Models/MeLiBuyer.php',
    ];
    $perfil['autoload']['classmap'] = array_values(array_unique(array_merge(
        isset($normal['autoload']['classmap']) ? $normal['autoload']['classmap'] : [],
        $mapa_linux
    )));
    $perfil['extra']['comerciocity'] = array_merge(
        isset($normal['extra']['comerciocity']) ? $normal['extra']['comerciocity'] : [],
        [
            'runtime-profile' => 'vps-php84-v1',
            'base-manifest-sha256' => perfil_vps_huella($raiz . '/composer.json'),
            'base-lock-sha256' => perfil_vps_huella($raiz . '/composer.lock'),
        ]
    );

    $opciones = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $json = json_encode($perfil, $opciones) . "\n";
    if (file_put_contents($raiz . '/composer.vps.json', $json) !== strlen($json)) {
        throw new RuntimeException('No se pudo escribir el manifiesto VPS completo.');
    }
    echo "Manifiesto VPS preparado. No se modificó ningún lock ni composer.json.\n";
    echo "Antes de publicarlo, resolvé los tres paquetes fijados con Composer y verificá el contrato, validate y la instalación sin scripts en PHP 8.4.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'No se regeneró el perfil: ' . $e->getMessage() . "\n");
    exit(1);
}
