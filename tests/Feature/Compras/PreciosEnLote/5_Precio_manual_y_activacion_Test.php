<?php

namespace Tests\Feature\Compras\PreciosEnLote;

/**
 * El precio cargado a mano en el renglón de la compra (llamada #2, update_price) y el artículo
 * inactivo que la compra activa (misión compras-precios-en-lote, 29/9/2026).
 *
 * - Precio manual CON margen: setFinalPrice() borra el `price` (el margen manda) y el precio sale
 *   del costo. Hoy lo borra la #2; con el motor, update_price() lo graba y el motor lo borra,
 *   como hacía la #3. Tiene que quedar igual: `price` en null en el artículo y en el cambio.
 * - Precio manual SIN margen: el precio manual manda. Hoy: la #1 mueve A→B con el costo nuevo y la
 *   #2 B→P con el precio del renglón; con el motor, un solo A→P.
 * - Inactivo que se activa: check_article_status() lo pone activo y le prende
 *   apply_provider_percentage_gain, que entra al cálculo. Hoy la #1 calcula SIN el margen del
 *   proveedor (todavía inactivo) y la #3 CON él.
 *
 * Con las bonificaciones de Buenos Aires del fixture: la #3 también las materializa.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Precio_manual_y_activacion_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * @group compras
     * @test
     */
    public function precio_manual_en_el_renglon_de_un_articulo_con_margen()
    {
        $this->set_condicion_iva('RRII');

        $martillo = $this->articulo('Martillo acero');

        $this->assertGreaterThan(0, (float) $martillo->percentage_gain, 'Guarda: el artículo tenía que tener margen propio.');

        $r = $this->dos_caminos(function () use ($martillo) {

            $compra_id = $this->alta($this->payload_compra([
                'articles' => [
                    $this->renglon($martillo, 2230, 3, ['price' => 9999]),
                ],
            ]));

            return [
                'articulos' => [$martillo->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('precio manual con margen', $r);

        $this->assert_hubo_cambios_de_precio($r);
        $this->assert_hubo_caso_d1($r);

        $this->assertNull($r['motor']['articles'][$martillo->id]['price'], 'Guarda: con margen, el precio manual del renglón tenía que quedar borrado.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * @group compras
     * @test
     */
    public function precio_manual_en_el_renglon_de_un_articulo_sin_margen()
    {
        $this->set_condicion_iva('RRII');

        $sin_margen = $this->crear_articulo([
            'cost'                           => 400,
            'percentage_gain'                => null,
            'apply_provider_percentage_gain' => 0,
        ]);

        $r = $this->dos_caminos(function () use ($sin_margen) {

            $compra_id = $this->alta($this->payload_compra([
                'articles' => [
                    $this->renglon($sin_margen, 430, 12, ['price' => 1234.5]),
                ],
            ]));

            return [
                'articulos' => [$sin_margen->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('precio manual sin margen', $r);

        $this->assert_hubo_caso_d1($r);

        $this->assertSame('1234.50', $r['motor']['articles'][$sin_margen->id]['final_price'], 'Guarda: sin margen, el precio manual del renglón tenía que quedar como precio final.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * @group compras
     * @test
     */
    public function articulo_inactivo_que_se_activa_con_la_compra()
    {
        $this->set_condicion_iva('RRII');

        $inactivo = $this->crear_articulo([
            'cost'                           => 800,
            'status'                         => 'inactive',
            'percentage_gain'                => null,
            'apply_provider_percentage_gain' => 0,
        ]);

        $pinza = $this->articulo('Pinza');

        $r = $this->dos_caminos(function () use ($inactivo, $pinza) {

            $compra_id = $this->alta($this->payload_compra([
                'articles' => [
                    $this->renglon($inactivo, 850, 5),
                    $this->renglon($pinza, 1020, 2),
                ],
            ]));

            return [
                'articulos' => [$inactivo->id, $pinza->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('inactivo que se activa', $r);

        $this->assert_hubo_caso_d1($r, 2);

        $this->assertSame('active', $r['motor']['articles'][$inactivo->id]['status'], 'Guarda: la compra tenía que activar el artículo.');
        $this->assertSame('1', $r['motor']['articles'][$inactivo->id]['apply_provider_percentage_gain'], 'Guarda: al activarlo se prende el margen del proveedor.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }
}
