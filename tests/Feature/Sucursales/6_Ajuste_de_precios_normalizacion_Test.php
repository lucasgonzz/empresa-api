<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\AjusteDePreciosDeSucursalHelper;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Archivo 6 — las REGLAS PURAS de `AjusteDePreciosDeSucursalHelper::normalizar()` y
 * `request_trae_ajuste()`, sin pasar por el endpoint ni por la base.
 *
 * Los archivos 1 a 5 prueban lo que hace la API; este fija el detalle de cada regla (que texto
 * numerico se acepta, a cuantos decimales se redondea, que equivale a "sin ajuste") para que un
 * cambio en el helper rompa un test con nombre propio y no solo un 422 distinto cuatro archivos mas
 * abajo.
 *
 * @group sucursales
 */
class Ajuste_de_precios_normalizacion_Test extends TestCase
{
    /**
     * Afirma que el par es valido y que normaliza a lo esperado.
     *
     * @param  mixed        $tipo
     * @param  mixed        $porcentaje
     * @param  string|null  $tipo_esperado
     * @param  float|null   $porcentaje_esperado
     * @return void
     */
    protected function assert_acepta($tipo, $porcentaje, $tipo_esperado, $porcentaje_esperado)
    {
        $r = AjusteDePreciosDeSucursalHelper::normalizar($tipo, $porcentaje);

        $que = '('.var_export($tipo, true).', '.var_export($porcentaje, true).')';

        $this->assertTrue($r['valido'], $que.' tendria que ser valido, y dio: '.$r['mensaje']);
        $this->assertNull($r['mensaje'], $que.': un par valido no lleva mensaje.');
        $this->assertSame($tipo_esperado, $r['valores']['ajuste_precio_tipo'], $que.': tipo normalizado.');

        if (is_null($porcentaje_esperado)) {
            $this->assertNull($r['valores']['ajuste_precio_porcentaje'], $que.': porcentaje normalizado.');
            return;
        }

        $this->assertEqualsWithDelta($porcentaje_esperado, $r['valores']['ajuste_precio_porcentaje'], 0.00001, $que.': porcentaje normalizado.');
    }

    /**
     * Afirma que el par se rechaza, con un mensaje que contiene `$fragmento`, y sin valores.
     *
     * @param  mixed   $tipo
     * @param  mixed   $porcentaje
     * @param  string  $fragmento
     * @return void
     */
    protected function assert_rechaza($tipo, $porcentaje, $fragmento)
    {
        $r = AjusteDePreciosDeSucursalHelper::normalizar($tipo, $porcentaje);

        $que = '('.var_export($tipo, true).', '.var_export($porcentaje, true).')';

        $this->assertFalse($r['valido'], $que.' tendria que rechazarse.');
        $this->assertSame([], $r['valores'], $que.': un par rechazado no devuelve valores para escribir.');
        $this->assertIsString($r['mensaje'], $que.': tiene que traer el mensaje para el 422.');
        $this->assertStringContainsString($fragmento, $r['mensaje'], $que.': el mensaje no es el esperado.');
    }

    /**
     * Las formas de decir "sin ajuste": las dos columnas a NULL.
     *
     * @test
     */
    public function las_formas_de_decir_sin_ajuste_dejan_las_dos_columnas_en_null()
    {
        $this->assert_acepta(null, null, null, null);
        $this->assert_acepta('', '', null, null);
        $this->assert_acepta('sin_ajuste', null, null, null);
        $this->assert_acepta('  ', '  ', null, null);
        $this->assert_acepta('Sin_Ajuste', '', null, null);

        /* Un 0 con el tipo vacio es lo mismo que nada: un campo numerico que arranca en 0. */
        $this->assert_acepta(null, 0, null, null);
        $this->assert_acepta(null, '0', null, null);
        $this->assert_acepta('', '0,00', null, null);

        /*
         * El 0 como TIPO es el "vacio" del ABM de la SPA (el motor de formularios le pone 0 a todo
         * select sin valor): sin ajuste, igual que '' o null. Numerico (JSON) o de texto (formulario).
         */
        $this->assert_acepta(0, null, null, null);
        $this->assert_acepta(0, '', null, null);
        $this->assert_acepta(0, 0, null, null);
        $this->assert_acepta('0', null, null, null);
        $this->assert_acepta(' 0 ', '0', null, null);
        $this->assert_acepta('0', '0,00', null, null);
    }

    /**
     * Un valor valido: tipo en cualquier combinacion de mayusculas y espacios, porcentaje como numero
     * o como texto con coma o punto decimal.
     *
     * @test
     */
    public function acepta_el_tipo_sin_mayusculas_y_el_porcentaje_con_coma_o_punto()
    {
        $this->assert_acepta('recargo', 10, 'recargo', 10);
        $this->assert_acepta('Recargo', '10', 'recargo', 10);
        $this->assert_acepta(' DESCUENTO ', '5,5', 'descuento', 5.5);
        $this->assert_acepta('descuento', '5.5', 'descuento', 5.5);
        $this->assert_acepta('recargo', ' 12,25 ', 'recargo', 12.25);
        $this->assert_acepta('recargo', 7.5, 'recargo', 7.5);
        $this->assert_acepta('recargo', '.5', 'recargo', 0.5);
    }

    /**
     * Se redondea a DOS decimales (los de la columna).
     *
     * @test
     */
    public function redondea_el_porcentaje_a_dos_decimales()
    {
        $this->assert_acepta('recargo', '5,123', 'recargo', 5.12);
        $this->assert_acepta('recargo', '5,126', 'recargo', 5.13);
        $this->assert_acepta('descuento', 33.333333, 'descuento', 33.33);
    }

    /**
     * Los limites se miden DESPUES de redondear: lo que se valida es lo que se guarda.
     *
     * @test
     */
    public function los_limites_se_miden_con_el_valor_ya_redondeado()
    {
        $this->assert_acepta('descuento', '99,99', 'descuento', 99.99);
        $this->assert_acepta('descuento', '99,994', 'descuento', 99.99);
        $this->assert_rechaza('descuento', '99,996', 'menor al 100%');

        $this->assert_acepta('recargo', '999,99', 'recargo', 999.99);
        $this->assert_acepta('recargo', '999,994', 'recargo', 999.99);
        $this->assert_rechaza('recargo', '999,996', '999,99%');

        $this->assert_acepta('recargo', '0,01', 'recargo', 0.01);
        $this->assert_rechaza('recargo', '0,004', 'mayor a 0');
    }

    /**
     * Los rechazos, uno por regla, con el mensaje de cada una.
     *
     * @test
     */
    public function rechaza_cada_regla_con_su_propio_mensaje()
    {
        $this->assert_rechaza('otro', 10, '"recargo" o "descuento"');
        $this->assert_rechaza(true, 10, '"recargo" o "descuento"');
        $this->assert_rechaza(['recargo'], 10, '"recargo" o "descuento"');

        /* El 0 es el unico numero que vale como tipo ("vacio"); el 1, un false o un '1' no. */
        $this->assert_rechaza(1, 10, '"recargo" o "descuento"');
        $this->assert_rechaza('1', 10, '"recargo" o "descuento"');
        $this->assert_rechaza(false, 10, '"recargo" o "descuento"');
        $this->assert_rechaza(0.5, 10, '"recargo" o "descuento"');

        $this->assert_rechaza('recargo', null, 'cargá también el porcentaje');
        $this->assert_rechaza('descuento', '', 'cargá también el porcentaje');

        $this->assert_rechaza(null, 10, 'no elegiste si es un recargo o un descuento');
        $this->assert_rechaza('', '5,5', 'no elegiste si es un recargo o un descuento');
        $this->assert_rechaza(0, 10, 'no elegiste si es un recargo o un descuento');
        $this->assert_rechaza('0', '5,5', 'no elegiste si es un recargo o un descuento');

        $this->assert_rechaza('recargo', 'abc', 'tiene que ser un número');
        $this->assert_rechaza('recargo', '1e1', 'tiene que ser un número');
        $this->assert_rechaza('recargo', '1.000,5', 'tiene que ser un número');
        $this->assert_rechaza('recargo', true, 'tiene que ser un número');
        $this->assert_rechaza('recargo', [10], 'tiene que ser un número');
        $this->assert_rechaza('recargo', NAN, 'tiene que ser un número');

        $this->assert_rechaza('recargo', 0, 'mayor a 0');
        $this->assert_rechaza('recargo', -5, 'mayor a 0');
        $this->assert_rechaza('descuento', '-0,5', 'mayor a 0');

        $this->assert_rechaza('descuento', 100, 'menor al 100%');
        $this->assert_rechaza('descuento', 150, 'menor al 100%');

        $this->assert_rechaza('recargo', 1000, '999,99%');
    }

    /**
     * `request_trae_ajuste()`: clave ausente = no se toca; clave presente, aunque este en null o
     * vacia, = se escribe (es la SPA nueva diciendo "quitale el ajuste").
     *
     * @test
     */
    public function el_request_trae_ajuste_si_trae_alguna_de_las_dos_claves_aunque_sea_vacia()
    {
        $sin_claves = Request::create('/api/address/1', 'PUT', ['street' => 'zz', 'city' => 'x']);
        $solo_tipo  = Request::create('/api/address/1', 'PUT', ['ajuste_precio_tipo' => 'recargo']);
        $solo_pct   = Request::create('/api/address/1', 'PUT', ['ajuste_precio_porcentaje' => 10]);
        $en_null    = Request::create('/api/address/1', 'PUT', ['ajuste_precio_tipo' => null]);
        $vacias     = Request::create('/api/address/1', 'PUT', ['ajuste_precio_tipo' => '', 'ajuste_precio_porcentaje' => '']);

        $this->assertFalse(AjusteDePreciosDeSucursalHelper::request_trae_ajuste($sin_claves), 'SPA vieja: sin claves no se toca el ajuste.');
        $this->assertTrue(AjusteDePreciosDeSucursalHelper::request_trae_ajuste($solo_tipo));
        $this->assertTrue(AjusteDePreciosDeSucursalHelper::request_trae_ajuste($solo_pct));
        $this->assertTrue(AjusteDePreciosDeSucursalHelper::request_trae_ajuste($en_null), 'Una clave en null es "quitar el ajuste": se escribe.');
        $this->assertTrue(AjusteDePreciosDeSucursalHelper::request_trae_ajuste($vacias));
    }
}
