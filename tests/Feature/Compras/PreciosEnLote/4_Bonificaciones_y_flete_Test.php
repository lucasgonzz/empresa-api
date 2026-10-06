<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Models\ProviderOrderExtraCost;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Bonificaciones, descuentos y costos extra: los casos en los que hoy la compra deja más de un
 * cambio de precio por artículo (misión compras-precios-en-lote, 29/9/2026).
 *
 * Hoy, la llamada #1 (update_cost) calcula con el costo nuevo y los descuentos y recargos VIEJOS;
 * la #3 (materializar descuentos) vuelve a calcular con las bonificaciones de la compra, y la #4
 * (costos extra) con el flete. Cada una que mueve el precio graba su price_change: A→B, B→C, C→D.
 * El motor calcula una vez con todo junto: un solo A→D (decisión D1), el mismo precio final.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Bonificaciones_y_flete_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * Las bonificaciones de Buenos Aires del fixture (10% y 5%) se precargan en la compra y se
     * materializan como descuentos tagueados de cada artículo. Un renglón trae el MISMO costo que
     * ya tenía el artículo: la #1 no corre, el precio lo mueve recién la #3, y hoy el historial de
     * proveedores queda con el precio de antes (SetProvider graba en el medio de la compra), que es
     * justo lo que cambia la decisión D2.
     *
     * @group compras
     * @test
     */
    public function bonificaciones_del_proveedor_que_se_materializan_en_la_compra()
    {
        $this->set_condicion_iva('RRII');

        $pinza   = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $nuevo   = $this->crear_articulo(['cost' => 640]);

        $costo_de_siempre = (float) $alicate->cost;

        $r = $this->dos_caminos(function () use ($pinza, $alicate, $nuevo, $costo_de_siempre) {

            $compra_id = $this->alta($this->payload_compra([
                'articles' => [
                    $this->renglon($pinza, 1150, 10),
                    $this->renglon($alicate, $costo_de_siempre, 8),
                    $this->renglon($nuevo, 700, 5),
                ],
            ]));

            return [
                'articulos' => [$pinza->id, $alicate->id, $nuevo->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('bonificaciones del proveedor', $r);

        $this->assert_hubo_cambios_de_precio($r);
        $this->assert_hubo_caso_d1($r, 2);

        $this->assertCount(1, $r['hoy']['cambios'][$alicate->id], 'Referencia: el renglón con el costo de siempre cambia de precio una sola vez hoy (la #3).');

        $this->assertNotSame(
            $r['hoy']['movimientos_con_proveedor'],
            [],
            'Guarda: la compra tenía que mover stock con proveedor (la regla D2 se aplica ahí).'
        );

        /*
         * D2, a la vista: en el renglón con el costo de siempre, hoy el historial de proveedores
         * queda con el precio de ANTES de la compra (SetProvider grabó antes de la #3) y con el
         * motor, con el precio con el que el artículo sale de la compra.
         */
        $bsas = (string) $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS)->id;

        $this->assertSame(
            $this->precio_entero($r['antes']['articles'][$alicate->id]),
            $this->precio_del_historial($r['hoy'], $alicate->id, $bsas),
            'Referencia: hoy el historial de proveedores del renglón sin cambio de costo queda con el precio de antes.'
        );

        $this->assertSame(
            $this->precio_entero($r['motor']['articles'][$alicate->id]['final_price']),
            $this->precio_del_historial($r['motor'], $alicate->id, $bsas),
            'D2: con el motor, el historial de proveedores queda con el precio final del artículo.'
        );

        $this->assertNotSame(
            $this->precio_del_historial($r['hoy'], $alicate->id, $bsas),
            $this->precio_del_historial($r['motor'], $alicate->id, $bsas),
            'Guarda: en este renglón el historial tenía que diferir entre los dos caminos (si no, D2 no se probó).'
        );

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * El "Precio Final" del historial de proveedores de un par (artículo, proveedor) en una foto.
     *
     * @param  array  $foto
     * @param  int    $article_id
     * @param  string $provider_id
     * @return string|null
     */
    protected function precio_del_historial(array $foto, $article_id, $provider_id)
    {
        foreach ($foto['article_provider'] as $fila) {
            if ($fila['article_id'] === (string) $article_id && $fila['provider_id'] === $provider_id) {
                return $fila['price'];
            }
        }

        $this->fail('El artículo ' . $article_id . ' no tiene fila en el historial de proveedores para el proveedor ' . $provider_id . '.');
    }

    /**
     * Descuentos propios de la compra (un porcentaje y un monto), cargados en el alta como los carga
     * la SPA: con descuentos propios, las bonificaciones del proveedor NO se precargan.
     *
     * @group compras
     * @test
     */
    public function descuentos_propios_de_la_compra()
    {
        $this->set_condicion_iva('RRII');

        $martillo = $this->articulo('Martillo acero');
        $cuchilla = $this->articulo('Cuchilla');

        $r = $this->dos_caminos(function () use ($martillo, $cuchilla) {

            $compra_id = $this->alta($this->payload_compra([
                'childrens' => [
                    $this->descuento_del_alta(['percentage' => 8]),
                    $this->descuento_del_alta(['monto' => 25]),
                ],
                'articles' => [
                    $this->renglon($martillo, 2210, 3),
                    $this->renglon($cuchilla, 515, 7),
                ],
            ]));

            return [
                'articulos' => [$martillo->id, $cuchilla->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('descuentos propios de la compra', $r);

        $this->assert_hubo_cambios_de_precio($r);
        $this->assert_hubo_caso_d1($r, 2);

        $this->assertCount(4, $r['motor']['article_discounts'], 'Guarda: los dos descuentos de la compra se tenían que materializar en los dos artículos.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * Dos costos extra tipados, sin bonificaciones: un flete (transporte) dentro de la factura de la
     * compra y un seguro de carga facturado aparte, con su IVA y otro emisor (genera su propio
     * comprobante, que referencia al costo extra). Los dos se prorratean como recargos y mueven el
     * precio en la llamada #4.
     *
     * @group compras
     * @test
     */
    public function flete_y_seguro_tipados_que_se_prorratean()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza    = $this->articulo('Pinza');
        $alicate  = $this->articulo('Alicate');
        $cuchilla = $this->articulo('Cuchilla');

        $iva_21 = $this->iva_id('21');

        $r = $this->dos_caminos(function () use ($pinza, $alicate, $cuchilla, $iva_21) {

            $flete = $this->costo_extra_del_alta([
                'description'       => 'Flete',
                'value'             => 1300,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => 0,
                'en_factura_compra' => 1,
            ]);

            $seguro = $this->costo_extra_del_alta([
                'description'         => 'Seguro de carga',
                'value'               => 605,
                'tipo'                => ProviderOrderExtraCost::TIPO_SEGURO,
                'facturado'           => 1,
                'iva_id'              => $iva_21,
                'en_factura_compra'   => 0,
                'emisor_cuit'         => '30712345678',
                'emisor_razon_social' => 'zz Aseguradora de prueba',
            ]);

            $compra_id = $this->alta($this->payload_compra([
                'childrens' => [$flete, $seguro],
                'articles'  => [
                    $this->renglon($pinza, 1040, 10),
                    $this->renglon($alicate, 310, 20),
                    $this->renglon($cuchilla, 505, 4),
                ],
            ]));

            return [
                'articulos' => [$pinza->id, $alicate->id, $cuchilla->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('flete y seguro tipados', $r);

        $this->assert_hubo_cambios_de_precio($r);
        $this->assert_hubo_caso_d1($r, 3);

        $this->assertCount(6, $r['motor']['article_surchages'], 'Guarda: flete y seguro se tenían que materializar como recargo en los tres artículos.');
        $this->assertCount(2, $r['motor']['provider_order_afip_tickets'], 'Guarda: la factura de la compra y la del seguro, aparte.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * Todo junto, y además un artículo que CAMBIA DE PROVEEDOR con la compra: es de Rosario (sin
     * margen de proveedor) y lo compra Buenos Aires (margen 100%), con apply_provider_percentage_gain
     * prendido. Hoy: la #1 calcula con el proveedor viejo, update_article_provider() le pone el nuevo,
     * la #3 recalcula con el margen y las bonificaciones del nuevo, y la #4 con el flete. Tres
     * cambios de precio hoy; uno solo con el motor.
     *
     * @group compras
     * @test
     */
    public function descuentos_flete_y_cambio_de_proveedor_del_articulo()
    {
        $this->set_condicion_iva('RRII');

        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $de_rosario = $this->crear_articulo([
            'cost'                           => 500,
            'provider_id'                    => $rosario->id,
            'apply_provider_percentage_gain' => 1,
            'percentage_gain'                => null,
        ]);

        $pinza = $this->articulo('Pinza');

        $r = $this->dos_caminos(function () use ($de_rosario, $pinza) {

            $flete = $this->costo_extra_del_alta(['value' => 900]);

            $compra_id = $this->alta($this->payload_compra([
                'childrens' => [$flete],
                'articles'  => [
                    $this->renglon($de_rosario, 520, 6),
                    $this->renglon($pinza, 1060, 10),
                ],
            ]));

            return [
                'articulos' => [$de_rosario->id, $pinza->id],
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('descuentos, flete y cambio de proveedor', $r);

        $this->assert_hubo_cambios_de_precio($r);
        $this->assert_hubo_caso_d1($r, 2);

        $this->assertGreaterThanOrEqual(3, count($r['hoy']['cambios'][$de_rosario->id]), 'Referencia: hoy el artículo que cambia de proveedor tenía que cambiar de precio en la #1, la #3 y la #4.');

        $bsas = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);

        $this->assertSame((string) $bsas->id, $r['motor']['articles'][$de_rosario->id]['provider_id'], 'Guarda: el artículo tenía que quedar con el proveedor de la compra.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }
}
