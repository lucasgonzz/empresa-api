<?php

namespace Tests\Feature\EdicionCompletaVenta;

use App\Models\PaymentPlanCuota;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;

class Guardado_equivalente_conserva_cobros_Test extends EdicionCompletaVentaTestCase
{
    /** @test */
    public function guardar_dos_veces_sin_cambios_conserva_plan_usd_cobro_dirigido_y_comision()
    {
        $cliente = $this->cliente_nuevo();
        $cuenta = $this->cuenta($cliente, self::DOLARES);
        $vendedor = Seller::create([
            'num' => (int) Seller::where('user_id', $this->usuario->id)->max('num') + 1,
            'name' => 'zz Vendedor conserva cobros',
            'user_id' => $this->usuario->id,
            'commission_after_pay_sale' => 0,
            'percentage_commission' => 10,
            'commission_with_iva' => 1,
        ]);
        $vieja = $this->vender($cliente, self::DOLARES, 20);
        $venta = $this->vender($cliente, self::DOLARES, 100, ['seller_id' => $vendedor->id]);
        $this->postJson('api/payment-plan', [
            'sale_id' => $venta->id,
            'cantidad_cuotas' => 2,
            'frequency' => 'monthly',
            'start_date' => date('Y-m-d'),
        ])->assertStatus(201);
        $cuota = PaymentPlanCuota::where('sale_id', $venta->id)->orderBy('numero_cuota')->first();
        $pago = $this->pagar($cliente, $cuenta, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 30000, $this->caja_pesos, 1200),
        ], ['payment_plan_cuota' => ['id' => $cuota->id]]);
        $this->assertMonto(25, $pago->haber);
        $this->assertEquals($this->debito_de($venta, $cuenta)->id, $pago->to_pay_id);
        $antes = $this->foto_cruda($venta, [$cliente]);
        $this->assertNotEmpty($antes['comisiones']);
        $this->assertNotEmpty($antes['pagado_por']);

        for ($i = 0; $i < 2; $i++) {
            $this->editar_ok($venta, $this->payload_de_edicion($venta));
            $despues = $this->foto_cruda($venta, [$cliente]);
            foreach (['movimientos', 'pagado_por', 'cuentas', 'clientes', 'comisiones', 'planes', 'cuotas'] as $clave) {
                $this->assertSame($antes[$clave], $despues[$clave], 'Guardar sin cambios alteró '.$clave);
            }
        }
        $this->assert_saldo_independiente($cliente, self::DOLARES, 95, 'No-op USD');
        $this->assertEquals('sin_pagar', $this->debito_de($vieja, $cuenta)->status);
        $this->assertEquals(2, (int) $cuota->fresh()->moneda_id);
    }

    /** @test */
    public function cambiar_observacion_o_cotizacion_general_guarda_el_cambio_sin_reimputar_el_cobro()
    {
        $cliente = $this->cliente_nuevo();
        $cuenta = $this->cuenta($cliente, self::DOLARES);
        $venta = $this->vender($cliente, self::DOLARES, 100);
        $debito = $this->debito_de($venta, $cuenta);
        $pago = $this->pagar($cliente, $cuenta, [
            $this->fila_de_pago(self::DOLARES, self::DOLARES, 20, $this->caja_dolares),
        ], ['to_pay' => ['id' => $debito->id]]);
        $antes = $this->foto_cruda($venta, [$cliente]);
        $actualizada = $this->editar_ok($venta, $this->payload_de_edicion($venta, [
            'observations' => 'Entrega acordada para el viernes',
            'valor_dolar' => 1400,
        ]));
        $this->assertEquals('Entrega acordada para el viernes', $actualizada->observations);
        $this->assertMonto(1400, $actualizada->valor_dolar);
        $despues = $this->foto_cruda($venta, [$cliente]);
        $this->assertSame($antes['movimientos'], $despues['movimientos']);
        $this->assertSame($antes['pagado_por'], $despues['pagado_por']);
        $this->assertEquals($debito->id, $pago->fresh()->to_pay_id);
    }

    /** @test */
    public function un_total_distinto_actualiza_el_debito_en_vez_de_confundirse_con_un_guardado_equivalente()
    {
        $cliente = $this->cliente_nuevo();
        $cuenta = $this->cuenta($cliente, self::DOLARES);
        $venta = $this->vender($cliente, self::DOLARES, 100);
        $items = $this->items_de($venta);
        $items[0]['price_vender'] = 125;
        $actualizada = $this->editar_ok($venta, $this->payload_de_edicion($venta, [
            'total' => 125, 'sub_total' => 125, 'items' => $items,
        ]));
        $this->assertMonto(125, $actualizada->total);
        $this->assertMonto(125, $this->debito_de($actualizada, $cuenta)->debe);
        $this->assert_saldo_independiente($cliente, self::DOLARES, 125, 'Total editado');
    }
}
