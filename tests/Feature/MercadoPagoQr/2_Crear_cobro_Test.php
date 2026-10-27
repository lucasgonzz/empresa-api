<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\Address;
use App\Models\MercadoPagoQrCobro;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Crear un cobro con QR desde Vender (misión cobro-qr-mercado-pago, 26/10/2026).
 *
 * Lo que fija:
 * - La resolución del QR: el de la caja → el de la sucursal sin caja → el General → el único →
 *   422 `sin_qr`.
 * - La orden de MP: `type: qr`, `mode: static`, `total_amount` y el monto del pago como STRING
 *   con dos decimales, el `external_pos_id` del QR elegido, `external_reference` con la forma
 *   `ccqr-{owner}-{cobro}-{4}` y `X-Idempotency-Key`.
 * - Se cancela en MP el cobro pendiente anterior del mismo QR; si el cliente lo está pagando, no
 *   se pisa (422 `pagando`).
 * - 🔴 Un QR de OTRA cuenta de MP (`mp_user_id` distinto del conectado) nunca se usa para cobrar.
 * - MP rechaza la orden: 422 con su texto y el cobro queda en `error`.
 *
 * @group mercado_pago_qr
 */
class Crear_cobro_Test extends MercadoPagoQrTestCase
{
    /**
     * Rutas de MP: la orden se crea bien y devuelve ORD01 / PAY01. El closure de cancelación
     * contesta ok.
     *
     * @return array
     */
    protected function rutas_orden_ok()
    {
        return [
            ['POST', '#^/v1/orders$#', [201, [
                'id'           => 'ORD01JTEST',
                'type'         => 'qr',
                'status'       => 'created',
                'transactions' => ['payments' => [['id' => 'PAY01JTEST', 'amount' => '1500.00', 'status' => 'created']]],
            ]]],
            ['POST', '#^/v1/orders/[^/]+/cancel$#', [200, ['id' => 'ORDVIEJA', 'status' => 'canceled']]],
        ];
    }

    /**
     * `external_pos_id` de la orden que se mandó a MP.
     *
     * @return string|null
     */
    protected function external_pos_id_enviado()
    {
        $pedidos = Http::recorded(function (Request $request) {
            return $request->method() === 'POST' && parse_url($request->url(), PHP_URL_PATH) === '/v1/orders';
        });

        $this->assertCount(1, $pedidos, 'Una sola orden por cobro.');

        return $pedidos->first()[0]['config']['qr']['external_pos_id'];
    }

    /**
     * Cobro para la caja con QR propio: usa ese QR, con el cuerpo de la orden completo.
     *
     * @test
     */
    public function el_cobro_de_una_caja_con_qr_usa_el_qr_de_la_caja_y_manda_la_orden_correcta()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_orden_ok());

        $cajas = $this->cajas_del_fixture();

        $qr_caja = $this->qr_en_base(['caja_id' => $cajas[0]->id]);
        $this->qr_en_base(); // General: no tiene que ganarle al de la caja.

        $response = $this->postJson('api/mercado-pago/qr/cobros', [
            'total'   => 1500,
            'caja_id' => $cajas[0]->id,
        ]);

        $response->assertStatus(201);
        $this->assert_sin_token($response);

        $response->assertJsonPath('cobro.estado', 'pendiente');
        $response->assertJsonPath('cobro.mp_order_id', 'ORD01JTEST');
        $response->assertJsonPath('cobro.qr.id', $qr_caja->id);
        $response->assertJsonPath('cobro.qr.qr_image_url', $qr_caja->qr_image_url);
        $response->assertJsonPath('cobro.current_acount_payment_method_id', self::METODO_MERCADO_PAGO);
        $this->assertSame(1500.0, (float) $response->json('cobro.total'));

        $this->assertSame(
            ['id', 'total', 'estado', 'mp_order_id', 'error_message', 'paid_at', 'sale_id', 'sale_num', 'qr', 'created_at', 'current_acount_payment_method_id'],
            array_keys($response->json('cobro')),
            'La forma Cobro del contrato, ni más ni menos.'
        );

        $cobro = MercadoPagoQrCobro::find($response->json('cobro.id'));

        $this->assertMatchesRegularExpression('/^ccqr-500-' . $cobro->id . '-[A-Za-z0-9]{4}$/', $cobro->external_reference);
        $this->assertSame(self::USER_ID, (int) $cobro->employee_id);

        Http::assertSent(function (Request $request) use ($qr_caja, $cobro) {
            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/v1/orders'
                && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
                && $request->header('X-Idempotency-Key') !== []
                && $request->header('X-Idempotency-Key')[0] !== ''
                && $request['type'] === 'qr'
                && $request['total_amount'] === '1500.00'
                && $request['transactions']['payments'][0]['amount'] === '1500.00'
                && $request['config']['qr']['external_pos_id'] === $qr_caja->mp_pos_external_id
                && $request['config']['qr']['mode'] === 'static'
                && $request['external_reference'] === $cobro->external_reference
                && $request['expiration_time'] === 'PT10M'
                && is_string($request['description']) && $request['description'] !== '';
        });
    }

    /**
     * Montos con centavos: el string lleva los dos decimales con punto.
     *
     * @test
     */
    public function el_total_viaja_como_string_con_dos_decimales()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_orden_ok());
        $this->qr_en_base();

        $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1234.5])->assertStatus(201);

        Http::assertSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders'
                && $request['total_amount'] === '1234.50';
        });
    }

    /**
     * Sin QR para la caja: el de su sucursal (sin caja) le gana al General.
     *
     * @test
     */
    public function sin_qr_de_la_caja_usa_el_de_la_sucursal()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_orden_ok());

        $cajas   = $this->cajas_del_fixture();
        $address = Address::where('user_id', self::USER_ID)->orderBy('id')->first();

        $this->qr_en_base(['caja_id' => $cajas[0]->id]);
        $qr_sucursal = $this->qr_en_base(['address_id' => $address->id]);
        $this->qr_en_base();

        $this->postJson('api/mercado-pago/qr/cobros', [
            'total'      => 1500,
            'caja_id'    => $cajas[1]->id,
            'address_id' => $address->id,
        ])->assertStatus(201)->assertJsonPath('cobro.qr.id', $qr_sucursal->id);

        $this->assertSame($qr_sucursal->mp_pos_external_id, $this->external_pos_id_enviado());
    }

    /**
     * Sin QR de la caja ni de la sucursal: el General.
     *
     * @test
     */
    public function sin_qr_de_la_caja_ni_de_la_sucursal_usa_el_general()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_orden_ok());

        $cajas = $this->cajas_del_fixture();

        $this->qr_en_base(['caja_id' => $cajas[0]->id]);
        $general = $this->qr_en_base();

        $this->postJson('api/mercado-pago/qr/cobros', [
            'total'   => 1500,
            'caja_id' => $cajas[1]->id,
        ])->assertStatus(201)->assertJsonPath('cobro.qr.id', $general->id);

        $this->assertSame($general->mp_pos_external_id, $this->external_pos_id_enviado());
    }

    /**
     * Sin QR de la caja, de la sucursal ni General, pero con un único QR: ese.
     *
     * @test
     */
    public function con_un_unico_qr_de_otra_caja_usa_ese()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_orden_ok());

        $cajas = $this->cajas_del_fixture();

        $unico = $this->qr_en_base(['caja_id' => $cajas[0]->id]);

        $this->postJson('api/mercado-pago/qr/cobros', [
            'total'   => 1500,
            'caja_id' => $cajas[1]->id,
        ])->assertStatus(201)->assertJsonPath('cobro.qr.id', $unico->id);
    }

    /**
     * Dos QR de otras cajas y ninguno que aplique: 422 `sin_qr`, sin llamar a MP.
     *
     * @test
     */
    public function sin_ningun_qr_que_aplique_da_422_sin_qr()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $cajas = $this->cajas_del_fixture();

        $this->qr_en_base(['caja_id' => $cajas[0]->id]);
        $this->qr_en_base(['caja_id' => $cajas[1]->id]);

        $response = $this->postJson('api/mercado-pago/qr/cobros', [
            'total'   => 1500,
            'caja_id' => $cajas[2]->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('sin_qr', true);
        $response->assertJsonPath('message', 'No hay un QR de Mercado Pago configurado para esta caja.');

        Http::assertNothingSent();
        $this->assertSame(0, MercadoPagoQrCobro::where('user_id', self::USER_ID)->count());
    }

    /**
     * 🔴 Un QR de OTRA cuenta de MP no se usa para cobrar, ni siquiera siendo el de la caja o el
     * único: 422 `sin_qr` y ningún pedido a MP.
     *
     * @test
     */
    public function un_qr_de_otra_cuenta_de_mp_no_se_usa_para_cobrar()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $cajas = $this->cajas_del_fixture();

        $this->qr_en_base(['caja_id' => $cajas[0]->id, 'mp_user_id' => '999']);

        $this->postJson('api/mercado-pago/qr/cobros', [
            'total'   => 1500,
            'caja_id' => $cajas[0]->id,
        ])->assertStatus(422)->assertJsonPath('sin_qr', true);

        Http::assertNothingSent();
    }

    /**
     * Con un QR de la cuenta conectada y otro de una cuenta vieja para la misma caja, se usa el
     * de la cuenta conectada.
     *
     * @test
     */
    public function entre_un_qr_de_la_cuenta_vieja_y_uno_de_la_nueva_gana_el_de_la_conectada()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_orden_ok());

        $cajas = $this->cajas_del_fixture();

        $propio = $this->qr_en_base(['caja_id' => $cajas[0]->id]);
        $this->qr_en_base(['caja_id' => $cajas[0]->id, 'mp_user_id' => '999']);

        $this->postJson('api/mercado-pago/qr/cobros', [
            'total'   => 1500,
            'caja_id' => $cajas[0]->id,
        ])->assertStatus(201)->assertJsonPath('cobro.qr.id', $propio->id);
    }

    /**
     * El cobro pendiente anterior del mismo QR se cancela en MP antes de cargar el nuevo.
     *
     * @test
     */
    public function cancela_el_cobro_pendiente_anterior_del_mismo_qr()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_orden_ok());

        $qr    = $this->qr_en_base();
        $viejo = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDVIEJA']);

        $response = $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500]);

        $response->assertStatus(201);

        $this->assertSame('cancelado', $viejo->fresh()->estado);
        $this->assertSame('pendiente', MercadoPagoQrCobro::find($response->json('cobro.id'))->estado);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDVIEJA/cancel'
                && $request->header('X-Idempotency-Key') !== [];
        });
    }

    /**
     * Si el cliente está pagando el cobro anterior, no se pisa: 422 `pagando` y no se crea orden.
     *
     * @test
     */
    public function si_el_cliente_esta_pagando_el_anterior_no_se_crea_el_nuevo()
    {
        $this->conectar_mp();
        $this->fake_mp([
            ['POST', '#^/v1/orders/ORDVIEJA/cancel$#', [409, ['errors' => [['code' => 'instore_order_locked_error', 'message' => 'Order is locked']]]]],
        ]);

        $qr    = $this->qr_en_base();
        $viejo = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDVIEJA']);

        $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500])
            ->assertStatus(422)
            ->assertJsonPath('pagando', true);

        $this->assertSame('pendiente', $viejo->fresh()->estado);

        Http::assertNotSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders';
        });
    }

    /**
     * MP rechaza la orden: 422 con su texto y el cobro queda en `error` con ese mensaje.
     *
     * @test
     */
    public function si_mp_rechaza_la_orden_el_cobro_queda_en_error()
    {
        $this->conectar_mp();
        $this->fake_mp([
            ['POST', '#^/v1/orders$#', [400, ['errors' => [['code' => 'invalid_total_amount', 'message' => 'total_amount must be greater than 0']]]]],
        ]);

        $this->qr_en_base();

        $response = $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500]);

        $response->assertStatus(422);
        $this->assertStringContainsString('total_amount must be greater than 0', $response->json('message'));

        $cobro = MercadoPagoQrCobro::where('user_id', self::USER_ID)->first();

        $this->assertSame('error', $cobro->estado);
        $this->assertStringContainsString('total_amount must be greater than 0', $cobro->error_message);
    }

    /**
     * Sin conexión: 422 `sin_conexion`. Total cero: 422.
     *
     * @test
     */
    public function sin_conexion_o_con_total_cero_no_se_cobra()
    {
        $this->fake_mp([]);
        $this->qr_en_base();

        $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500])
            ->assertStatus(422)
            ->assertJsonPath('sin_conexion', true);

        $this->conectar_mp();

        $this->postJson('api/mercado-pago/qr/cobros', ['total' => 0])->assertStatus(422);

        Http::assertNothingSent();
    }
}
