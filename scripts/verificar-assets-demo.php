<?php

// No necesita Laravel ni .env: también valida el artefacto en el runner del release.
require_once __DIR__.'/../app/Services/DemoIndustriaAssets.php';

try {
    $raiz = dirname(__DIR__);
    $perfiles = json_decode(file_get_contents($raiz.'/database/seeders/data/demo_industrias.json'), true);
    $manifiesto = json_decode(file_get_contents($raiz.'/database/seeders/data/demo_industrias_assets.json'), true);
    if (!is_array($perfiles) || !is_array($manifiesto) || ($manifiesto['version'] ?? null) !== 1
        || empty($manifiesto['archivos'])) {
        throw new RuntimeException('No se pudo leer el catálogo o manifiesto de archivos de demo.');
    }
    $cantidad = 0;
    $rutas = [];
    foreach ($perfiles as $slug => $perfil) {
        $cantidad += App\Services\DemoIndustriaAssets::verificar_perfil($slug, $perfil, $raiz.'/public', $manifiesto);
        foreach (App\Services\DemoIndustriaAssets::rutas($slug, $perfil) as $ruta) {
            $rutas['public/'.$ruta] = true;
        }
    }
    if (array_diff_key($rutas, $manifiesto['archivos']) || array_diff_key($manifiesto['archivos'], $rutas)) {
        throw new RuntimeException('El manifiesto no coincide con todos los archivos referenciados por los rubros.');
    }
    $paquete = null;
    foreach (array_slice($argv, 1) as $opcion) {
        if (strpos($opcion, '--zip=') !== 0) {
            throw new RuntimeException('Opción inválida; usá --zip=ruta para verificar también el paquete.');
        }
        $paquete = App\Services\DemoIndustriaAssets::verificar_paquete(substr($opcion, 6), $manifiesto);
    }
    echo json_encode(['ok' => true, 'rubros' => count($perfiles), 'archivos' => $cantidad, 'archivos_en_paquete' => $paquete], JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'No se pudieron verificar los archivos de demo: '.$error->getMessage().PHP_EOL);
    exit(1);
}
