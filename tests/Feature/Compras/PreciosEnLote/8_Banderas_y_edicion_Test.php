<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Models\ProviderOrderExtraCost;

/**
 * Las banderas de la compra y la edición de una compra ya confirmada (misión
 * compras-precios-en-lote, 29/9/2026).
 *
 * - update_stock apagado: sin movimiento de stock no hay SetProvider, así que la regla D2 no se
 *   aplica en ningún renglón; los precios se recalculan igual (con el motor).
 * - update_prices apagado: ninguna de las cuatro llamadas corre, el motor no se usa y la compra
 *   tiene que quedar IDÉNTICA a la de hoy en todo.
 * - Edición (PUT): la compra se reconfirma entera (attach_articles(true) + procesar_pedido()), con
 *   el stock por diferencia contra lo recibido antes. Sube la cantidad de un renglón (movimiento
 *   con proveedor), baja la de otro (movimiento negativo, sin proveedor: SetProvider no escribe) y
 *   un tercero queda igual; se cambian costos y se agrega un flete.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Banderas_y_edicion_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * @group compras
     * @test
     */
    public function update_stock_apagado()
    {
        $this->set_condicion_iva('RRII');

        $pinza    = $this->articulo('Pinza');
        $cuchilla = $this->articulo('Cuchilla');

        $r = $this->dos_caminos(function () use ($pinza, $cuchilla) {

            $compra_id = $this->alta($this->payload_compra([
                'update_stock' => 0,
                'articles'     => [
                    $this->renglon($pinza, 1045, 10),
                    $this->renglon($cuchilla, 520, 3),
                ],
            ]));

            return [
                'articulos' => [$pinza->id, $cuchilla->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('update_stock apagado', $r);

        $this->assert_hubo_caso_d1($r, 2);

        $this->assertSame([], $r['motor']['stock_movements'], 'Guarda: sin update_stock no hay movimientos de stock.');
        $this->assertSame([], $r['motor']['movimientos_con_proveedor'], 'Guarda: sin movimientos, la regla D2 no se aplica a ningún renglón.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * @group compras
     * @test
     */
    public function update_prices_apagado()
    {
        $this->set_condicion_iva('RRII');

        $pinza    = $this->articulo('Pinza');
        $cuchilla = $this->articulo('Cuchilla');

        $r = $this->dos_caminos(function () use ($pinza, $cuchilla) {

            $compra_id = $this->alta($this->payload_compra([
                'update_prices' => 0,
                'articles'      => [
                    $this->renglon($pinza, 1045, 10),
                    $this->renglon($cuchilla, 520, 3),
                ],
            ]));

            return [
                'articulos' => [$pinza->id, $cuchilla->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('update_prices apagado', $r);

        $this->assertSame(0, $r['resumen']['hoy'], 'Referencia: sin update_prices, hoy la compra no cambia ningún precio.');
        $this->assertSame(0, $r['resumen']['motor'], 'Sin update_prices, el camino nuevo tampoco.');

        $this->assertNotSame([], $r['motor']['stock_movements'], 'Guarda: la compra tenía que mover stock (si no, no hay nada que comparar).');

        $this->assertFalse($this->usa_el_recalculo_diferido($r['motor']), 'Guarda: sin update_prices la comparación tiene que ser la de "idéntico".');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);

        $this->assert_identico($r['hoy'], $r['motor'], 'Sin update_prices, la compra tiene que quedar idéntica a la de hoy.');
    }

    /**
     * @group compras
     * @test
     */
    public function edicion_de_una_compra_confirmada()
    {
        $this->set_condicion_iva('RRII');

        $pinza    = $this->articulo('Pinza');
        $alicate  = $this->articulo('Alicate');
        $cuchilla = $this->articulo('Cuchilla');

        /*
         * La compra a editar se confirma UNA vez, antes de las corridas (con el camino por defecto):
         * las dos ediciones parten de la misma compra, con el mismo id.
         */
        $compra_id = $this->alta($this->payload_compra([
            'articles' => [
                $this->renglon($pinza, 1000, 10),
                $this->renglon($alicate, 300, 10),
                $this->renglon($cuchilla, 500, 5),
            ],
        ]));

        $costo_cuchilla = (float) $this->articulo('Cuchilla')->cost;

        $r = $this->dos_caminos(function () use ($compra_id, $pinza, $alicate, $cuchilla, $costo_cuchilla) {

            /* El flete se agrega a la compra ya confirmada, como en 4_Costos_Extra_Test. */
            ProviderOrderExtraCost::create([
                'provider_order_id' => $compra_id,
                'description'       => 'Flete',
                'value'             => 1100,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            $this->edicion($compra_id, $this->payload_compra([
                'articles' => [
                    $this->renglon($pinza, 1120, 12),
                    $this->renglon($alicate, 280, 6),
                    $this->renglon($cuchilla, $costo_cuchilla, 5),
                ],
            ]));

            return [
                'articulos' => [$pinza->id, $alicate->id, $cuchilla->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('edición de una compra confirmada', $r);

        $this->assert_hubo_caso_d1($r, 2);

        $this->assertSame(
            [$pinza->id . '|' . $this->payload_compra()['provider_id']],
            $r['motor']['movimientos_con_proveedor'],
            'Guarda: en la edición, solo el renglón que sube de cantidad mueve stock con proveedor (el que baja mueve sin proveedor y el que queda igual no mueve).'
        );

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }
}
