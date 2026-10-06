<?php

namespace Tests\Feature\SaneoStockSucursales;

/**
 * Lo que NO es una fila fantasma no se toca ni se cuenta (misión
 * sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * Una fila es fantasma cuando su `address_id` NO existe en `addresses`. Nada más. Lo que el comando
 * tiene que dejar en paz, aunque esté en la misma tabla y al lado de un fantasma:
 *
 *   1. una fila en un DOMICILIO DE COMPRADOR (`addresses.buyer_id` no nulo): los pedidos de la
 *      tienda con envío le abren filas desde siempre y "Poner stock en 0" las limpia por otro
 *      camino. Si el comando las borrara, rompería el stock de los pedidos de la tienda;
 *   2. una fila en una sucursal VIVA de otro dueño;
 *   3. las filas vivas propias.
 *
 * Y a la inversa: el `address_id` 0 no es ninguna sucursal, así que SÍ es un fantasma.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Lo_que_no_es_fantasma_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un artículo del dueño con filas en cuatro lugares: su sucursal, un domicilio de comprador, la
     * sucursal de OTRO dueño y una sucursal borrada.
     *
     *  - S1 (propia) 10 · H (domicilio de comprador) 2 · SC (sucursal de otro dueño) 3 · D (borrada) −1
     *  - stock crudo del motor: 10 + 2 + 3 − 1 = 14
     *
     * @return array
     */
    protected function escenario()
    {
        $dueno = $this->dueno('no-fantasma');
        $otro = $this->dueno('otro-comercio');

        $s1 = $this->sucursal($dueno);
        $sc = $this->sucursal($otro, 'zz Sucursal de otro comercio');
        $h = $this->domicilio_de_comprador();
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_con_fantasmas($dueno, 'No fantasma', [$s1->id => 10, $h->id => 2, $sc->id => 3], [[$muerta, -1]]);

        return array_merge($e, compact('dueno', 'otro', 's1', 'sc', 'h', 'muerta'));
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function solo_se_borra_el_fantasma_y_las_filas_de_comprador_y_de_otro_dueno_quedan_intactas()
    {
        $e = $this->escenario();

        $this->assertEquals(14.0, $this->stock($e['articulo']), 'El escenario no quedó armado: el stock crudo tenía que ser 14.');

        $vivas_antes = [];
        foreach ($this->pivot($e['articulo']) as $fila) {
            if ((int) $fila['address_id'] !== $e['muerta']) {
                $vivas_antes[(int) $fila['id']] = $fila;
            }
        }

        $this->assertCount(3, $vivas_antes);

        $this->assertSame(0, $this->aplicar($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($e['articulo'], $e['muerta']), 'El fantasma de la sucursal borrada se tiene que ir.');

        $despues = [];
        foreach ($this->pivot($e['articulo']) as $fila) {
            $despues[(int) $fila['id']] = $fila;
        }

        $this->assertSame(
            $vivas_antes,
            $despues,
            'Las filas del domicilio de comprador, de la sucursal de otro dueño y de la sucursal propia tienen que quedar IDÉNTICAS (mismo id, mismo amount, mismos timestamps).'
        );

        $this->assertSame(1, $this->filas_en($e['articulo'], $e['h']->id), 'La fila del domicilio de comprador no es un fantasma: no se borra.');
        $this->assertSame(1, $this->filas_en($e['articulo'], $e['sc']->id), 'La fila de la sucursal de otro dueño no es un fantasma: no se borra.');

        // El stock global las sigue sumando: 10 + 2 + 3. Son stock de verdad.
        $this->assertEquals(15.0, $this->stock($e['articulo']), 'articles.stock sigue sumando las filas de comprador y de otro dueño (no son fantasmas).');

        $movimientos = $this->movimientos($e['articulo']);

        $this->assertCount(1, $movimientos);
        $this->assertEquals(1.0, (float) $movimientos[0]->amount, 'El movimiento explica SOLO la baja del fantasma de −1.');
    }

    /**
     * El reporte no cuenta las filas que no son fantasma, pero las nombra en una línea aparte para
     * que quien lo lee vea que el comando las conoce y las deja en paz.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_reporte_no_cuenta_lo_que_no_es_fantasma_y_menciona_los_domicilios_de_comprador()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->ver($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $fila = $this->fila_del_reporte($e['dueno']->id);

        $this->assertSame(1, $fila['filas_articulo'], 'Solo la fila de la sucursal borrada es un fantasma: las de comprador y de otro dueño no se cuentan.');
        $this->assertEquals(-1.0, $fila['unidades_articulo'], 'Las unidades fantasma son las de la sucursal borrada (−1), no las de comprador ni las de otro dueño.');
        $this->assertSame(1, $fila['articulos']);

        $this->assertStringContainsString(
            'Filas en domicilios de comprador (EXISTEN, no son fantasma, no se tocan): address_article 1 filas (+2.00 u)',
            $this->salida,
            'El reporte tiene que informar las filas de domicilios de comprador (acotadas al dueño) como "no se tocan".'
        );
    }

    /**
     * Un artículo cuyas filas son TODAS de lugares que existen no tiene nada que sanear.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_sin_ninguna_fila_fantasma_no_aparece_ni_se_toca()
    {
        $dueno = $this->dueno('sin-fantasmas');
        $otro = $this->dueno('sin-fantasmas-otro');

        $s1 = $this->sucursal($dueno);
        $sc = $this->sucursal($otro);
        $h = $this->domicilio_de_comprador();

        $e = $this->articulo_con_fantasmas($dueno, 'Todo vivo', [$s1->id => 10, $h->id => 2, $sc->id => 3], []);

        $antes = $this->foto_de_tablas();

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('No hay artículos con filas fantasma', $this->salida, 'Un artículo con todas sus filas en lugares que existen no es un caso para el saneo.');
        $this->assertStringContainsString('No hay nada para sanear', $this->salida);
        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Un artículo sin fantasmas se tocó');
        $this->assertDirectoryDoesNotExist($this->carpeta_de_salida, 'Sin nada para sanear no hay por qué dejar archivos de respaldo.');
        $this->assertEquals(15.0, $this->stock($e['articulo']));
    }

    /**
     * El `address_id` 0 nunca fue una sucursal: una fila con ese id es un fantasma.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function una_fila_con_address_id_cero_es_un_fantasma()
    {
        $dueno = $this->dueno('address-cero');
        $s1 = $this->sucursal($dueno);

        $e = $this->articulo_con_fantasmas($dueno, 'Address cero', [$s1->id => 5], [[0, -1]]);

        $this->assertEquals(4.0, $this->stock($e['articulo']), 'El escenario no quedó armado: stock crudo 5 − 1.');

        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);
        $this->assertSame(1, $this->fila_del_reporte($dueno->id)['filas_articulo'], 'El address_id 0 cuenta como sucursal inexistente.');
        $this->assertStringContainsString('#0: 1 filas', $this->salida);

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($e['articulo'], 0), 'La fila del address_id 0 se tiene que borrar.');
        $this->assertEquals(5.0, $this->stock($e['articulo']));
    }
}
