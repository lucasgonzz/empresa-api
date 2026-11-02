<?php

namespace Tests\Unit\Php84;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\Helpers\import\ClientImportHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Misión php84-errores-fatales (1/11/2026): en PHP 8 llamar con `Clase::metodo()` a un método SIN
 * `static` es un Error fatal (en 7.4 era un deprecated, por eso el shared no lo mostraba y las APIs
 * del VPS sí). Estos métodos se llaman en algún lado como estáticos; si alguien les saca el `static`
 * el gasto con caja, la importación de clientes de Golonorte o los seeders vuelven a romper.
 *
 * El barrido completo de la clase está en `tools/php8-barrido.php`.
 */
class MetodosDeInstanciaLlamadosEstaticosTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public function metodos_que_se_llaman_como_estaticos()
    {
        return [
            'gasto con caja (ExpenseCajaHelper:214)' => [MovimientoCajaHelper::class, 'set_apertura_caja_ingresos_egresos'],
            'recalcular saldos de caja'              => [MovimientoCajaHelper::class, 'recalcular_saldos'],
            'importación de clientes Golonorte'      => [ClientImportHelper::class, 'separar_localidad_de_direccion'],
            'seeder: proveedor'                      => [ArticleSeederHelper::class, 'set_provider'],
            'seeder: imágenes'                       => [ArticleSeederHelper::class, 'set_images'],
        ];
    }

    /**
     * @test
     * @dataProvider metodos_que_se_llaman_como_estaticos
     */
    public function el_metodo_es_static($clase, $metodo)
    {
        $this->assertTrue((new ReflectionMethod($clase, $metodo))->isStatic(), "$clase::$metodo() tiene que ser static");
    }

    /** @test */
    public function separar_localidad_de_direccion_se_puede_llamar_como_estatico()
    {
        $res = ClientImportHelper::separar_localidad_de_direccion('Calle 1 - Wanda - Misiones');

        $this->assertSame('Wanda', $res['localidad']);
    }
}
