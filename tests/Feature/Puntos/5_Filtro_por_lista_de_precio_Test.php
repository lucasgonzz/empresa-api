<?php

namespace Tests\Feature\Puntos;

use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 5 — el filtro por LISTA DE PRECIO, que se decide POR RENGLÓN y no por venta.
 *
 * La lista efectiva de un renglón es `article_sale.price_type_personalizado_id` y, si está en null,
 * `sales.price_type_id`. Esa es la única forma de que una venta mixta —renglones vendidos con
 * listas distintas, que es lo que permiten las extensiones de precio personalizado— reparta bien
 * los puntos: decidirlo por el encabezado de la venta sería adivinar.
 *
 * Las cinco reglas de borde, textuales:
 *
 *   | El programa NO tiene ninguna lista marcada    | suma todo                        |
 *   | Tiene listas y la efectiva está en la lista   | suma                             |
 *   | Tiene listas y la efectiva NO está            | no suma                          |
 *   | La efectiva es NULL y el programa filtra      | no suma                          |
 *   | La efectiva es NULL y el programa NO filtra   | suma, con price_type_id = 0      |
 *
 * Y la consecuencia visible: se emite UN movimiento por cada (venta, lista), así que la decisión de
 * guardar con qué lista se otorgó cada punto se cumple literalmente aun en una venta mixta.
 */
class Filtro_por_lista_de_precio_Test extends PuntosTestCase
{
    /** Renglón vendido con la lista Mayorista: neto $100.000 -> 100 puntos. */
    const TOTAL_MAYORISTA = 121000;

    /** Renglón vendido con la lista Minorista: neto $50.000 -> 50 puntos. */
    const TOTAL_MINORISTA = 60500;

    /** Renglón que hereda la lista del encabezado de la venta: neto $20.000 -> 20 puntos. */
    const TOTAL_HEREDADO = 24200;

    /**
     * Payload de una venta MIXTA de mostrador: tres renglones, dos con lista propia y uno que
     * hereda la del encabezado.
     *
     * @param  int       $client_id
     * @param  int|null  $price_type_id_de_la_venta
     * @param  int       $lista_a
     * @param  int       $lista_b
     * @return array
     */
    protected function payload_venta_mixta($client_id, $price_type_id_de_la_venta, $lista_a, $lista_b)
    {
        $total = self::TOTAL_MAYORISTA + self::TOTAL_MINORISTA + self::TOTAL_HEREDADO;

        return $this->payload_venta($client_id, $total, [
            'omitir_en_cuenta_corriente' => 1,
            'price_type_id'              => $price_type_id_de_la_venta,
            'items'                      => [
                [
                    'is_article'                  => true,
                    'id'                          => $this->articulo_de_puntos('Martillo acero')->id,
                    'price_vender'                => self::TOTAL_MAYORISTA,
                    'amount'                      => 1,
                    'price_type_personalizado_id' => $lista_a,
                ],
                [
                    'is_article'                  => true,
                    'id'                          => $this->articulo_de_puntos('Pinza')->id,
                    'price_vender'                => self::TOTAL_MINORISTA,
                    'amount'                      => 1,
                    'price_type_personalizado_id' => $lista_b,
                ],
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos('Alicate')->id,
                    'price_vender' => self::TOTAL_HEREDADO,
                    'amount'       => 1,
                ],
            ],
        ]);
    }

    /**
     * Con UNA sola lista habilitada, suman solo los renglones de esa lista. Los otros dos —el de
     * otra lista y el que hereda la del encabezado— no aportan un peso a la base.
     *
     * @group puntos
     * @test
     */
    public function con_una_lista_habilitada_suman_solo_los_renglones_de_esa_lista()
    {
        $this->dar_extencion();

        $mayorista    = $this->lista_de_precio('Mayorista');
        $minorista    = $this->lista_de_precio('Minorista');
        $distribuidor = $this->lista_de_precio('Distribuidor');

        $this->crear_programa([], [$mayorista->id]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta(
            $this->payload_venta_mixta($cliente->id, $distribuidor->id, $mayorista->id, $minorista->id)
        );

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(
            1,
            $ganados,
            'Con una sola lista habilitada tiene que haber UN movimiento: el de esa lista.'
        );

        $movimiento = $ganados->first();

        $this->assertEquals(
            $mayorista->id,
            (int) $movimiento->price_type_id,
            'El movimiento tiene que guardar CON QUÉ LISTA se otorgó.'
        );
        $this->assertEqualsWithDelta(
            100,
            (float) $movimiento->puntos,
            self::DELTA,
            'Solo el renglón de la lista habilitada tiene que aportar a la base (neto $100.000 -> 100 puntos).'
        );
        $this->assertEqualsWithDelta(100000, (float) $movimiento->monto_base, self::DELTA);
    }

    /**
     * 🔴 Con DOS listas habilitadas se emite UN movimiento POR LISTA, no uno solo por la suma.
     *
     * Es lo que hace que la ficha del cliente y el reporte puedan decir de qué lista salió cada
     * punto. Y trae una consecuencia visible que queda afirmada acá: cada grupo redondea por
     * separado.
     *
     * @group puntos
     * @test
     */
    public function con_dos_listas_habilitadas_se_emite_un_movimiento_por_lista()
    {
        $this->dar_extencion();

        $mayorista    = $this->lista_de_precio('Mayorista');
        $minorista    = $this->lista_de_precio('Minorista');
        $distribuidor = $this->lista_de_precio('Distribuidor');

        $this->crear_programa([], [$mayorista->id, $minorista->id]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta(
            $this->payload_venta_mixta($cliente->id, $distribuidor->id, $mayorista->id, $minorista->id)
        );

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(2, $ganados, 'Tienen que ser DOS movimientos, uno por lista habilitada.');

        $por_lista = [];

        foreach ($ganados as $movimiento) {
            $por_lista[(int) $movimiento->price_type_id] = (float) $movimiento->puntos;
        }

        $this->assertArrayHasKey($mayorista->id, $por_lista, 'Falta el movimiento de la lista Mayorista.');
        $this->assertArrayHasKey($minorista->id, $por_lista, 'Falta el movimiento de la lista Minorista.');
        $this->assertArrayNotHasKey(
            $distribuidor->id,
            $por_lista,
            'El renglón que hereda la lista del encabezado no está habilitado: no puede tener movimiento.'
        );

        $this->assertEqualsWithDelta(100, $por_lista[$mayorista->id], self::DELTA);
        $this->assertEqualsWithDelta(50, $por_lista[$minorista->id], self::DELTA);

        $this->assertEqualsWithDelta(
            150,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El saldo tiene que ser la suma de los dos grupos.'
        );
    }

    /**
     * El renglón que hereda la lista del ENCABEZADO también entra al filtro: si la lista de
     * `sales.price_type_id` está habilitada, ese renglón suma con esa lista.
     *
     * @group puntos
     * @test
     */
    public function el_renglon_sin_lista_propia_hereda_la_del_encabezado_de_la_venta()
    {
        $this->dar_extencion();

        $mayorista    = $this->lista_de_precio('Mayorista');
        $minorista    = $this->lista_de_precio('Minorista');
        $distribuidor = $this->lista_de_precio('Distribuidor');

        // Solo se habilita la lista del ENCABEZADO: los dos renglones con lista propia quedan afuera.
        $this->crear_programa([], [$distribuidor->id]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta(
            $this->payload_venta_mixta($cliente->id, $distribuidor->id, $mayorista->id, $minorista->id)
        );

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados);
        $this->assertEquals($distribuidor->id, (int) $ganados->first()->price_type_id);
        $this->assertEqualsWithDelta(
            20,
            (float) $ganados->first()->puntos,
            self::DELTA,
            'Solo el renglón que hereda la lista del encabezado tiene que sumar (neto $20.000 -> 20 puntos).'
        );
    }

    /**
     * Sin ninguna lista marcada, el programa aplica a TODO. Con una venta sin lista de precio en el
     * encabezado y renglones sin lista propia, sale un solo movimiento con el centinela 0.
     *
     * El centinela es 0 y no NULL porque esa columna participa del unique
     * (sale_id, tipo, price_type_id) y MySQL deja pasar N filas con NULL en un índice único.
     *
     * @group puntos
     * @test
     */
    public function sin_filtro_y_sin_listas_la_venta_suma_con_el_centinela_cero()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
            'price_type_id'              => null,
        ]));

        $this->assertNull($venta->price_type_id, 'La venta tiene que haber quedado sin lista de precio.');

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados);
        $this->assertEquals(
            0,
            (int) $ganados->first()->price_type_id,
            'Sin lista efectiva, el movimiento tiene que guardar el centinela 0.'
        );
        $this->assertEqualsWithDelta(100, (float) $ganados->first()->puntos, self::DELTA);
    }

    /**
     * 🔴 La regla de borde que decide plata: la lista efectiva es NULL y el programa SÍ filtra.
     * Ese renglón no suma. Interpretar el null como "cualquiera" le regalaría puntos a toda venta
     * cargada sin lista en un comercio que solo quiere premiar la lista minorista.
     *
     * @group puntos
     * @test
     */
    public function con_filtro_y_sin_lista_efectiva_el_renglon_no_suma()
    {
        $this->dar_extencion();

        $mayorista = $this->lista_de_precio('Mayorista');

        $this->crear_programa([], [$mayorista->id]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
            'price_type_id'              => null,
        ]));

        $this->assertNull($venta->price_type_id);

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'Con el programa filtrando por lista, un renglón sin lista efectiva no puede sumar.'
        );
    }

    /**
     * Una lista que NO está en el programa no suma, aunque sea la única de la venta.
     *
     * @group puntos
     * @test
     */
    public function una_lista_que_no_esta_en_el_programa_no_suma()
    {
        $this->dar_extencion();

        $mayorista    = $this->lista_de_precio('Mayorista');
        $distribuidor = $this->lista_de_precio('Distribuidor');

        $this->crear_programa([], [$mayorista->id]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
            'price_type_id'              => $distribuidor->id,
        ]));

        $this->assertEquals($distribuidor->id, (int) $venta->price_type_id);

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta),
            'La venta se hizo con una lista que el programa no habilita: no puede acumular.'
        );
    }

    /**
     * El multiplicador del pivot, que el MVP no expone en la interfaz pero el motor respeta desde
     * el día uno. Con 2.00 en la lista Mayorista, el mismo renglón otorga el doble.
     *
     * Está probado a propósito aunque la interfaz no lo muestre: la columna existe como hedge, y un
     * hedge sin test es una columna que el día que se exponga nadie sabe si funciona.
     *
     * @group puntos
     * @test
     */
    public function el_multiplicador_del_pivot_duplica_los_puntos_de_esa_lista()
    {
        $this->dar_extencion();

        $mayorista = $this->lista_de_precio('Mayorista');

        $sistema = $this->crear_programa([], [$mayorista->id]);

        $sistema->price_types()->updateExistingPivot($mayorista->id, ['multiplicador' => 2]);

        \App\Http\Controllers\Helpers\puntos\PuntosConfigHelper::limpiar_memo();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000, [
            'omitir_en_cuenta_corriente' => 1,
            'price_type_id'              => $mayorista->id,
        ]));

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados);
        $this->assertEqualsWithDelta(
            200,
            (float) $ganados->first()->puntos,
            self::DELTA,
            'Con multiplicador 2 en el pivot, los 100 puntos de la base tienen que salir 200.'
        );
    }
}
