<?php

namespace Tests\Feature\EdicionCompletaVenta;

use App\Http\Controllers\Helpers\sale\IvaEnArticulosSinIvaEsquemaHelper;

/**
 * Caso 11 de la misión edicion-completa-ventas-presupuestos (8/10/2026): "que se pueda cambiar el
 * hecho de aplicar o no el IVA en los precios". La API ya lo persistía (`SaleController::update()`
 * asigna `iva_aplicado` y `iva_en_articulos_sin_iva` si vienen en el PUT); lo que se fija acá es que
 * sigue andando junto con el resto de la edición completa (cambio de cliente y de moneda en el mismo
 * PUT), y el sentido que ningún test cubría: APAGAR `iva_aplicado` (1 → 0). El encendido (0 → 1) ya
 * lo fija `Sales/31_Update_preserva_campos_que_no_viajan_Test::un_put_con_las_claves_si_las_pisa`, y
 * los dos sentidos de `iva_en_articulos_sin_iva`,
 * `IvaEnArticulosSinIva/1_Venta_guarda_el_flag_Test::el_update_con_el_flag_lo_cambia_en_los_dos_sentidos`.
 *
 * @group edicion-completa-venta
 */
class Iva_en_la_edicion_completa_Test extends EdicionCompletaVentaTestCase
{
    /**
     * @test
     */
    public function el_iva_se_edita_junto_con_el_cliente_y_la_moneda()
    {
        if (!IvaEnArticulosSinIvaEsquemaHelper::hay_columna('sales')) {
            $this->markTestSkipped('La base de testing no tiene la columna sales.iva_en_articulos_sin_iva.');
        }

        $cliente_a = $this->cliente_nuevo('zz Iva A');
        $cliente_b = $this->cliente_nuevo('zz Iva B');

        $venta = $this->vender($cliente_a, self::PESOS, 120000);

        $this->assertEquals(1, (int) $venta->fresh()->iva_aplicado, 'Precondición: la venta nace con iva_aplicado = 1.');

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, [
            'client_id'                => $cliente_b->id,
            'moneda_id'                => self::DOLARES,
            'valor_dolar'              => 1200,
            'items'                    => $this->reexpresar($this->items_de($venta), 1 / 1200),
            'sub_total'                => 100,
            'total'                    => 100,
            'iva_aplicado'             => 0,
            'iva_en_articulos_sin_iva' => 1,
        ]));

        $this->assertSame(0, (int) $venta->iva_aplicado, 'iva_aplicado no se apagó.');
        $this->assertSame(1, (int) $venta->iva_en_articulos_sin_iva, 'iva_en_articulos_sin_iva no se prendió.');
        $this->assertEquals($cliente_b->id, (int) $venta->client_id);
        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id);

        $this->assert_saldo_independiente($cliente_b, self::DOLARES, 100, 'B en dólares');
        $this->assert_saldo_independiente($cliente_a, self::PESOS, 0, 'A en pesos');

        // Y se vuelven a prender/apagar en una edición sin cambio de cuenta.
        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, [
            'iva_aplicado'             => 1,
            'iva_en_articulos_sin_iva' => 0,
        ]));

        $this->assertSame(1, (int) $venta->iva_aplicado);
        $this->assertSame(0, (int) $venta->iva_en_articulos_sin_iva);
    }
}
