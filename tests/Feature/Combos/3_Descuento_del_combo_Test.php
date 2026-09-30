<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;

/**
 * El descuento del combo calculado (misión combos-calculados, 30/9/2026).
 *
 * La regla de Lucas: el descuento (porcentaje o monto) se aplica SOLO al precio de venta y NUNCA al
 * costo, y con listas de precio es el mismo a cada lista (el % igual sobre cada precio; el monto
 * restado a cada lista).
 *
 * Lo que protegen:
 *
 *  - que el costo del combo no se mueva con el descuento: es lo que le cuesta al comercio, y
 *    si bajara, la ganancia de la venta quedaría inflada;
 *  - que el precio nunca quede negativo ni inflado por un descuento mal cargado: un monto mayor al
 *    precio da 0 (piso), y un porcentaje fuera de 0 < p < 100 se IGNORA en el cálculo (los combos
 *    cargados por otro camino pueden traer cualquier cosa);
 *  - que el ABM rechace con 422 lo que el cálculo ignoraría, en vez de guardarlo "arreglado".
 *
 * @group combos-calculados
 */
class Descuento_del_combo_Test extends ComboCalculadoTestCase
{
    /**
     * Un combo de costo 240 y precio sin descuento 590, con el descuento que se le pida.
     *
     * @param  string|null  $tipo
     * @param  mixed        $valor
     * @return \App\Models\Combo
     */
    protected function combo_con_descuento($tipo, $valor)
    {
        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250]);
        $b = $this->nuevo_articulo(['costo_real' => 40, 'final_price' => 90]);

        $combo = $this->combo_calculado([[$a, 2], [$b, 1]], [
            'descuento_tipo'  => $tipo,
            'descuento_valor' => $valor,
        ]);

        ComboCalculadoHelper::guardar($combo);

        return $combo;
    }

    /**
     * @test
     */
    public function un_porcentaje_baja_el_precio_y_no_toca_el_costo()
    {
        $this->con_listas(0);

        $combo = $this->combo_con_descuento('porcentaje', 10);

        $this->assertSame(531.0, $this->precio_en_base($combo), '590 menos el 10 %');
        $this->assertSame(240.0, $this->costo_en_base($combo), 'el descuento NUNCA baja el costo');
    }

    /**
     * @test
     */
    public function un_monto_baja_el_precio_y_no_toca_el_costo()
    {
        $this->con_listas(0);

        $combo = $this->combo_con_descuento('monto', 90);

        $this->assertSame(500.0, $this->precio_en_base($combo), '590 menos 90');
        $this->assertSame(240.0, $this->costo_en_base($combo), 'el descuento NUNCA baja el costo');
    }

    /**
     * Un monto mayor al precio no deja el precio negativo: piso en 0.
     *
     * @test
     */
    public function un_monto_mayor_al_precio_deja_el_precio_en_cero()
    {
        $this->con_listas(0);

        $combo = $this->combo_con_descuento('monto', 5000);

        $this->assertSame(0.0, $this->precio_en_base($combo));
        $this->assertSame(240.0, $this->costo_en_base($combo));
    }

    /**
     * El descuento se aplica antes de redondear a 2 decimales: 333,33 x 0,85 = 283,3305 -> 283,33.
     *
     * @test
     */
    public function el_precio_con_descuento_se_redondea_a_dos_decimales()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['final_price' => 333.33]);

        $combo = $this->combo_calculado([[$a, 1]], ['descuento_tipo' => 'porcentaje', 'descuento_valor' => 15]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(283.33, $this->precio_en_base($combo));
    }

    /**
     * 🔴 Un porcentaje fuera de 0 < p < 100 se IGNORA: no se aplica un 150 % (precio negativo), un
     * 100 % (combo regalado) ni un -20 % (precio inflado). Se escribe por consulta directa porque el
     * ABM ya los rechaza con 422: esto cubre los combos que llegaron por otro camino.
     *
     * @test
     */
    public function un_porcentaje_fuera_de_rango_se_ignora_en_el_calculo()
    {
        $this->con_listas(0);

        foreach ([100, 150, -20, 0] as $porcentaje) {

            $combo = $this->combo_con_descuento('porcentaje', $porcentaje);

            $this->assertSame(590.0, $this->precio_en_base($combo), 'porcentaje ' . $porcentaje . ': sin descuento');
        }
    }

    /**
     * Un tipo de descuento que no existe se ignora, y un monto negativo también.
     *
     * @test
     */
    public function un_tipo_desconocido_o_un_monto_negativo_no_se_aplican()
    {
        $this->con_listas(0);

        $this->assertSame(590.0, $this->precio_en_base($this->combo_con_descuento('regalo', 50)));
        $this->assertSame(590.0, $this->precio_en_base($this->combo_con_descuento('monto', -50)));
        $this->assertSame(590.0, $this->precio_en_base($this->combo_con_descuento(null, 50)));
    }

    /**
     * Con listas, el MISMO descuento a cada lista: el % igual sobre cada precio, y `combos.price` es
     * el de la lista por defecto ya con el descuento.
     *
     * @test
     */
    public function con_listas_el_porcentaje_se_aplica_a_cada_lista()
    {
        $this->con_listas(1);

        $baja = $this->lista('Desc baja', 90);
        $alta = $this->lista('Desc alta', 91);

        $a = $this->nuevo_articulo(['costo_real' => 100]);

        $this->precio_en_lista($a, $baja, 1000);
        $this->precio_en_lista($a, $alta, 800);

        $combo = $this->combo_calculado([[$a, 1]], ['descuento_tipo' => 'porcentaje', 'descuento_valor' => 10]);

        ComboCalculadoHelper::guardar($combo);

        $precios = $this->precios_por_lista_en_base($combo);

        $this->assertSame(900.0, $precios[$baja->id]);
        $this->assertSame(720.0, $precios[$alta->id]);
        $this->assertSame(720.0, $this->precio_en_base($combo), 'combos.price = la lista por defecto, con descuento');
        $this->assertSame(100.0, $this->costo_en_base($combo), 'el costo sigue siendo el del artículo');
    }

    /**
     * Con listas, el monto se resta a cada lista (no se reparte ni se resta una sola vez).
     *
     * @test
     */
    public function con_listas_el_monto_se_resta_a_cada_lista()
    {
        $this->con_listas(1);

        $baja = $this->lista('Monto baja', 90);
        $alta = $this->lista('Monto alta', 91);

        $a = $this->nuevo_articulo();

        $this->precio_en_lista($a, $baja, 1000);
        $this->precio_en_lista($a, $alta, 150);

        $combo = $this->combo_calculado([[$a, 1]], ['descuento_tipo' => 'monto', 'descuento_valor' => 200]);

        ComboCalculadoHelper::guardar($combo);

        $precios = $this->precios_por_lista_en_base($combo);

        $this->assertSame(800.0, $precios[$baja->id]);
        $this->assertSame(0.0, $precios[$alta->id], '150 - 200 no baja de 0 en esa lista');
        $this->assertSame(0.0, $this->precio_en_base($combo));
    }

    /**
     * Quitar el descuento devuelve el precio a la suma pura de los componentes.
     *
     * @test
     */
    public function sacar_el_descuento_devuelve_el_precio_sin_descuento()
    {
        $this->con_listas(0);

        $combo = $this->combo_con_descuento('porcentaje', 10);

        $this->assertSame(531.0, $this->precio_en_base($combo));

        $combo->descuento_tipo  = null;
        $combo->descuento_valor = 0;
        $combo->save();

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(590.0, $this->precio_en_base($combo));
    }

    /**
     * `validar_descuento()`: lo que el ABM rechaza con 422 y lo que deja pasar.
     *
     * @test
     */
    public function validar_descuento_rechaza_lo_que_el_calculo_ignoraria()
    {
        // Sin descuento, en cualquiera de sus formas: válido.
        $this->assertNull(ComboCalculadoHelper::validar_descuento(null, null));
        $this->assertNull(ComboCalculadoHelper::validar_descuento('', 50), 'sin tipo no hay descuento, el valor no importa');
        $this->assertNull(ComboCalculadoHelper::validar_descuento('porcentaje', ''));
        $this->assertNull(ComboCalculadoHelper::validar_descuento('porcentaje', 0));
        $this->assertNull(ComboCalculadoHelper::validar_descuento('monto', 0));

        // Con descuento válido.
        $this->assertNull(ComboCalculadoHelper::validar_descuento('porcentaje', 99.5));
        $this->assertNull(ComboCalculadoHelper::validar_descuento('monto', '1500.50'));

        // Inválidos.
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('regalo', 10), 'tipo desconocido');
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('porcentaje', 100), 'el 100 % regala el combo');
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('porcentaje', 150));
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('monto', -1), 'monto negativo');
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('porcentaje', 'abc'), 'no numérico');
    }

    /**
     * `normalizar_descuento()`: lo que se guarda en las columnas.
     *
     * @test
     */
    public function normalizar_descuento_deja_tipo_valido_o_null_y_valor_numerico()
    {
        $this->assertSame(
            ['descuento_tipo' => 'porcentaje', 'descuento_valor' => 12.5],
            ComboCalculadoHelper::normalizar_descuento('porcentaje', '12.5')
        );

        $this->assertSame(
            ['descuento_tipo' => null, 'descuento_valor' => 0.0],
            ComboCalculadoHelper::normalizar_descuento('regalo', 30),
            'un tipo desconocido se guarda como sin descuento'
        );

        $this->assertSame(
            ['descuento_tipo' => 'monto', 'descuento_valor' => 0.0],
            ComboCalculadoHelper::normalizar_descuento('monto', -5),
            'un valor negativo o vacío queda en 0'
        );

        $this->assertSame(
            ['descuento_tipo' => null, 'descuento_valor' => 0.0],
            ComboCalculadoHelper::normalizar_descuento(null, null)
        );
    }

    /**
     * F4 (a): se valida el valor YA REDONDEADO a 2 decimales. Un 99,996 % pasaba el "menor a 100",
     * se guardaba como 100,00 y el cálculo lo ignoraba; un 99,994 % se guarda como 99,99 y es válido.
     *
     * @test
     */
    public function un_porcentaje_que_se_redondea_a_cien_se_rechaza()
    {
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('porcentaje', 99.996));
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('porcentaje', '99.999'));
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('porcentaje', 99.995));

        $this->assertNull(ComboCalculadoHelper::validar_descuento('porcentaje', 99.994));
        $this->assertNull(ComboCalculadoHelper::validar_descuento('porcentaje', 99.99));

        // Y lo que se valida es lo que se guarda: 99,994 queda en 99,99, que el cálculo SÍ aplica.
        $normalizado = ComboCalculadoHelper::normalizar_descuento('porcentaje', 99.994);

        $this->assertSame(99.99, $normalizado['descuento_valor']);
        $this->assertEqualsWithDelta(0.1, ComboCalculadoHelper::aplicar_descuento(1000, 'porcentaje', $normalizado['descuento_valor']), 0.0001);
    }

    /**
     * F4 (b): el monto tiene tope en lo que entra en DECIMAL(12,2) (9999999999,99): más es un 422
     * legible y no un 500 de la base.
     *
     * @test
     */
    public function un_monto_que_no_entra_en_la_columna_se_rechaza()
    {
        $this->assertNull(ComboCalculadoHelper::validar_descuento('monto', 9999999999.99));
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('monto', 10000000000));
        $this->assertNotNull(ComboCalculadoHelper::validar_descuento('monto', '99999999999999'));

        // Defensivo: aunque alguien se saltee la validación, el INSERT no recibe un valor fuera de rango.
        $normalizado = ComboCalculadoHelper::normalizar_descuento('monto', 99999999999999);

        $this->assertSame(9999999999.99, $normalizado['descuento_valor']);
    }
}
