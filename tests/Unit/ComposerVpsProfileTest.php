<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Contrato de los dos manifiestos; no arranca Laravel, Composer ni una base. */
class ComposerVpsProfileTest extends TestCase
{
    private function raiz()
    {
        return dirname(__DIR__, 2);
    }

    private function leer($nombre)
    {
        $datos = json_decode(file_get_contents($this->raiz() . '/' . $nombre), true);
        $this->assertIsArray($datos, 'JSON inválido: ' . $nombre);
        return $datos;
    }

    private function canonico($valor)
    {
        if (is_object($valor)) {
            $propiedades = get_object_vars($valor);
            ksort($propiedades, SORT_STRING);
            foreach ($propiedades as &$propiedad) {
                $propiedad = $this->canonico($propiedad);
            }
            return (object) $propiedades;
        }
        if (is_array($valor)) {
            return array_map([$this, 'canonico'], $valor);
        }
        return $valor;
    }

    private function huella($nombre)
    {
        return hash('sha256', json_encode($this->canonico(json_decode(
            file_get_contents($this->raiz() . '/' . $nombre)
        )), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function test_el_perfil_solo_cambia_php_spout_platform_y_las_excepciones_linux_de_autoload()
    {
        $normal = $this->leer('composer.json');
        $perfil = $this->leer('composer.vps.json');
        $this->assertSame('>=8.4.27 <8.5', $perfil['require']['php']);
        $this->assertSame('4.32.0', $perfil['require']['openspout/openspout']);
        $this->assertSame('8.4.27', $perfil['config']['platform']['php']);
        $this->assertSame($normal['scripts'], $perfil['scripts']);
        $this->assertContains('@php artisan package:discover --ansi', $perfil['scripts']['post-autoload-dump']);

        $permitido = $normal;
        $permitido['require']['php'] = $perfil['require']['php'];
        $permitido['require']['openspout/openspout'] = $perfil['require']['openspout/openspout'];
        $permitido['config']['platform']['php'] = $perfil['config']['platform']['php'];
        $permitido['autoload']['classmap'] = array_values(array_unique(array_merge(
            isset($normal['autoload']['classmap']) ? $normal['autoload']['classmap'] : [],
            [
                'app/Http/Controllers/Pdf/reportes/InventarioPdf.php',
                'app/Http/Controllers/Pdf/reportes/ClientesPdf.php',
                'app/Http/Controllers/Pdf/reportes/ReportePdf.php',
                'app/Models/MeLiBuyer.php',
            ]
        )));
        $permitido['extra']['comerciocity'] = array_merge(
            isset($normal['extra']['comerciocity']) ? $normal['extra']['comerciocity'] : [],
            [
                'runtime-profile' => 'vps-php84-v1',
                'base-manifest-sha256' => $this->huella('composer.json'),
                'base-lock-sha256' => $this->huella('composer.lock'),
            ]
        );
        $this->assertSame($permitido, $perfil, 'El perfil se separó de la base en campos no autorizados.');
    }

    public function test_el_lock_conserva_los_paquetes_base_salvo_las_tres_actualizaciones_aprobadas()
    {
        $normal = $this->leer('composer.lock');
        $perfil = $this->leer('composer.vps.lock');
        $base = [];
        $vps = [];
        foreach ($normal['packages'] as $paquete) { $base[$paquete['name']] = $paquete; }
        foreach ($perfil['packages'] as $paquete) { $vps[$paquete['name']] = $paquete; }
        $this->assertSame(array_keys($base), array_keys($vps), 'Se agregaron o quitaron dependencias productivas.');
        $permitidos = ['nette/schema' => 'v1.4.0', 'nette/utils' => 'v4.1.6', 'openspout/openspout' => 'v4.32.0'];
        $cambiados = [];
        foreach ($base as $nombre => $paquete) {
            if (isset($permitidos[$nombre])) {
                $this->assertSame($permitidos[$nombre], $vps[$nombre]['version']);
                $cambiados[] = $nombre;
            } else {
                $this->assertSame($paquete, $vps[$nombre], 'Cambió un paquete fuera de los tres aprobados: ' . $nombre);
            }
        }
        $this->assertSame(array_keys($permitidos), $cambiados);
        $this->assertSame($normal['packages-dev'], $perfil['packages-dev'], 'La preparación del perfil actualizó dependencias de desarrollo.');
        $this->assertSame('>=8.4.27 <8.5', $perfil['platform']['php']);
        $this->assertSame('8.4.27', $perfil['platform-overrides']['php']);
    }

    public function test_las_huellas_identifican_la_base_entera_sin_depender_del_fin_de_linea()
    {
        $normal = $this->leer('composer.json');
        $perfil = $this->leer('composer.vps.json');
        $this->assertFalse(isset($normal['extra']['comerciocity']['runtime-profile']), 'El manifiesto normal no debe activar el perfil optativo.');
        $meta = $perfil['extra']['comerciocity'];
        $this->assertSame('vps-php84-v1', $meta['runtime-profile']);
        $this->assertSame($this->huella('composer.json'), $meta['base-manifest-sha256']);
        $this->assertSame($this->huella('composer.lock'), $meta['base-lock-sha256']);
        $ejemplo = json_decode('{"z":[],"a":{"b":2,"a":1}}');
        $this->assertSame('{"a":{"a":1,"b":2},"z":[]}', json_encode($this->canonico($ejemplo)));
    }

    public function test_los_archivos_del_classmap_tienen_la_capitalizacion_real_del_disco()
    {
        $perfil = $this->leer('composer.vps.json');
        foreach ($perfil['autoload']['classmap'] as $ruta) {
            $actual = $this->raiz();
            foreach (explode('/', $ruta) as $parte) {
                $this->assertContains($parte, scandir($actual), 'Capitalización incorrecta para Linux: ' . $ruta);
                $actual .= '/' . $parte;
            }
            $this->assertFileExists($actual);
        }
    }
}
