<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Http\Controllers\Helpers\CodigoDeBarrasRealHelper;

/**
 * "¿El código de barras es REAL?" (CodigoDeBarrasRealHelper, plan §6.3): una regla por test.
 *
 * Lo que protege: que la asignación inteligente no gaste una búsqueda paga (ni traiga la foto de
 * OTRO producto) buscando un código inventado — y que un código de fábrica bien cargado sí se
 * busque. Cada rejection tiene que traer un motivo legible: va tal cual al diagnóstico.
 */
class Codigo_de_barras_real_Test extends ImagenesInteligentesTestCase
{
    /**
     * @param  string $codigo
     * @return array
     */
    protected function evaluar($codigo)
    {
        $articulo = $this->nuevo_articulo('Artículo de prueba '.uniqid(), $codigo);

        return CodigoDeBarrasRealHelper::evaluar($codigo, $articulo);
    }

    /**
     * @param  array  $resultado
     * @param  string $fragmento  Lo que el motivo tiene que decir.
     * @return void
     */
    protected function assertNoReal(array $resultado, $fragmento)
    {
        $this->assertFalse($resultado['real'], 'El código no debería ser real: '.json_encode($resultado));
        $this->assertNotEmpty($resultado['motivo'], 'Un código que no es real tiene que decir por qué.');
        $this->assertStringContainsString($fragmento, $resultado['motivo']);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function un_ean13_de_fabrica_es_real_y_se_normaliza()
    {
        $resultado = $this->evaluar('7791234567898');

        $this->assertTrue($resultado['real'], json_encode($resultado));
        $this->assertSame('7791234567898', $resultado['normalizado']);
        $this->assertNull($resultado['motivo']);

        // Con espacios o guiones (como viene debajo de las barras) también, normalizado.
        $agrupado = $this->evaluar('7 791234 567898');
        $this->assertTrue($agrupado['real']);
        $this->assertSame('7791234567898', $agrupado['normalizado']);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function vacio_o_con_letras_no_es_real()
    {
        $this->assertNoReal($this->evaluar(''), 'no tiene código de barras');
        $this->assertNoReal($this->evaluar('ABC-123'), 'letras o símbolos');
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_digito_verificador_malo_no_es_real()
    {
        $this->assertNoReal($this->evaluar('7791234567890'), 'no cierra la cuenta de control');
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function un_largo_que_no_es_de_gtin_no_es_real()
    {
        $this->assertNoReal($this->evaluar('77912345'.'67'), 'tiene 10 dígitos');
    }

    /**
     * El id del artículo, '0'.id y el id con ceros adelante (BarCodeAutomaticoHelper y
     * ArticleVariantGeneratorHelper) son el número interno, no un código de fábrica.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_id_del_articulo_como_codigo_no_es_real()
    {
        foreach (['', '0', '00000'] as $ceros) {
            $articulo = $this->nuevo_articulo('Artículo con su id de código '.uniqid());
            $codigo   = $ceros.$articulo->id;

            $resultado = CodigoDeBarrasRealHelper::evaluar($codigo, $articulo);

            $this->assertFalse($resultado['real'], 'El código '.$codigo.' es el id del artículo.');
            $this->assertSame('El código '.$codigo.' es el número interno del artículo, no un código de barras real.', $resultado['motivo']);
        }
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function los_prefijos_de_circulacion_restringida_no_son_reales()
    {
        // Balanza / uso interno: EAN-13 que empiezan con 2 (20, 27...) y con 02.
        $this->assertNoReal($this->evaluar($this->con_verificador('200000012345')), 'circulación interna');
        $this->assertNoReal($this->evaluar($this->con_verificador('271234500000')), 'circulación interna');
        $this->assertNoReal($this->evaluar($this->con_verificador('020000012345')), 'circulación interna');

        // Un UPC-A (12 dígitos) que empieza con 2 es el 02 llevado a 13 dígitos: peso variable.
        $this->assertNoReal($this->evaluar($this->con_verificador('21000054321')), 'circulación interna');

        // Uso interno (04), cupones y recibos (05, 98, 99).
        $this->assertNoReal($this->evaluar($this->con_verificador('040000012345')), 'uso interno');
        $this->assertNoReal($this->evaluar($this->con_verificador('050000012345')), 'cupón o un recibo');
        $this->assertNoReal($this->evaluar($this->con_verificador('980000012345')), 'cupón o un recibo');
        $this->assertNoReal($this->evaluar($this->con_verificador('990000012345')), 'cupón o un recibo');

        // Siete ceros adelante: un número interno completado con ceros.
        $this->assertNoReal($this->evaluar($this->con_verificador('000000071234')), 'siete ceros');

        // Un UPC-A (12) que no es de circulación restringida sí es real.
        $this->assertTrue($this->evaluar($this->con_verificador('07890123456'))['real']);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function un_ean8_que_empieza_con_0_o_2_no_es_real()
    {
        $this->assertNoReal($this->evaluar($this->con_verificador('0712894')), 'código corto de uso interno');
        $this->assertNoReal($this->evaluar($this->con_verificador('2712894')), 'código corto de uso interno');

        // Un EAN-8 con otro prefijo sí.
        $this->assertTrue($this->evaluar($this->con_verificador('7712894'))['real']);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function un_gtin14_con_indicador_9_no_es_real()
    {
        $this->assertNoReal($this->evaluar($this->con_verificador('9779123456789')), 'peso o medida variable');

        // Con otro indicador (una caja de unidades) es real.
        $this->assertTrue($this->evaluar($this->con_verificador('1779123456789'))['real']);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function una_escalera_no_es_real_aunque_cierre_el_verificador()
    {
        // Las dos pasan el dígito verificador (por eso existe esta regla).
        $this->assertSame('1234567890128', $this->con_verificador('123456789012'));
        $this->assertSame('0123456789012', $this->con_verificador('012345678901'));

        $this->assertNoReal($this->evaluar('1234567890128'), 'escalera');
        $this->assertNoReal($this->evaluar('0123456789012'), 'escalera');
        $this->assertNoReal($this->evaluar($this->con_verificador('987654321098')), 'escalera');
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function todos_los_digitos_iguales_no_es_real()
    {
        // 4444444444444 cierra el verificador y no tiene un prefijo restringido: lo agarra esta regla.
        $this->assertSame('4444444444444', $this->con_verificador('444444444444'));

        $this->assertNoReal($this->evaluar('4444444444444'), 'mismo número');
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_mismo_codigo_en_tres_articulos_activos_es_un_relleno_copiado()
    {
        $codigo = $this->con_verificador('779555000123');

        $primero = $this->nuevo_articulo('Relleno A', $codigo);
        $this->nuevo_articulo('Relleno B', $codigo);

        // Con dos todavía se busca (puede ser un duplicado o una variante mal cargada).
        $this->assertTrue(CodigoDeBarrasRealHelper::evaluar($codigo, $primero)['real']);

        // Uno inactivo no cuenta.
        $this->nuevo_articulo('Relleno inactivo', $codigo, ['status' => 'inactive']);
        $this->assertTrue(CodigoDeBarrasRealHelper::evaluar($codigo, $primero)['real']);

        $this->nuevo_articulo('Relleno C', $codigo);

        $resultado = CodigoDeBarrasRealHelper::evaluar($codigo, $primero);

        $this->assertNoReal($resultado, 'lo tienen 3 artículos');
    }
}
