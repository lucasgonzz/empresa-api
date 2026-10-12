<?php

namespace Tests\Feature\Seguridad\RutasPublicas;

use Tests\TestCase;

/**
 * La clave maestra de mantenimiento VIENE EN EL CÓDIGO (misión cerrar-rutas-web-publicas, 10/10/2026).
 *
 * El default de `config('app.mantenimiento_clave_sha256')` es el que vale en toda la flota: ningún .env de
 * cliente lo trae. Si ese default quedara vacío (o mal pegado), el middleware `RutaDeMantenimiento` falla
 * cerrado y, con el release, NADIE puede abrir una ruta de mantenimiento en producción: ni el configurador
 * de extensiones de un cliente nuevo ni `helpers/liberar_ventas`, la salida del bloqueo de ventas. Nada lo
 * avisaría: los demás tests cargan su propio hash con `config([...])`.
 *
 * Por eso este test lee el default SIN tocar la config, y por eso se pone rojo si alguien lo vacía "para
 * probar". Para rotar la clave: `generar-clave-maestra.ps1` (carpeta de la misión) y reemplazar el hash.
 */
class La_clave_maestra_viene_en_el_codigo_Test extends TestCase
{
    /**
     * @test
     */
    public function el_default_del_codigo_es_un_sha256_hexadecimal()
    {
        // Si el .env.testing de un slot la definiera, el test estaría mirando ese valor y no el del código.
        $this->assertEmpty(
            env('MANTENIMIENTO_CLAVE_SHA256'),
            'MANTENIMIENTO_CLAVE_SHA256 está definida en el entorno de tests: este test tiene que leer el default del código.'
        );

        $hash = config('app.mantenimiento_clave_sha256');

        $this->assertIsString($hash);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $hash,
            'El default de app.mantenimiento_clave_sha256 en config/app.php tiene que ser el sha256 (64 hex en minúsculas) de la clave maestra.'
        );
    }
}
