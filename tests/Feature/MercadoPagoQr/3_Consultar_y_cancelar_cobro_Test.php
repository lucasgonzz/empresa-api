<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\MercadoPagoQrCobro;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Consultar y cancelar un cobro con QR (misión cobro-qr-mercado-pago, 26/10/2026). Es lo que el
 * modal "Esperando el pago" de Vender hace cada 3 segundos y su botón "Cancelar cobro".
 *
 * Lo que fija:
 * - Pendiente: se consulta la orden en MP. `processed` → `pagado` con `paid_at` y
 *   `mp_payment_id`; `expired` → `vencido`; `created` (incluido un rechazo de tarjeta) → sigue
 *   `pendiente`. Un cobro que ya no está pendiente no se consulta.
 * - Cancelar: ok → `cancelado`; 409 `instore_order_locked_error` → 409 `pagando: true` y el cobro
 *   sigue `pendiente`; 409 `order_already_canceled` → `cancelado`.
 * - Un cobro de otro comercio: 404.
 *
 * @group mercado_pago_qr
 */
class Consultar_y_cancelar_cobro_Test extends MercadoPagoQrTestCase
{
    /**
     * `processed` → pagado, con fecha de pago y el id del pago de MP.
     *
     * @test
     */
    public function una_orden_processed_deja_el_cobro_pagado()
    {
        $this->conectar_mp();

        $qr    = $this->qr_en_base();
        $cobro = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDA']);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDA$#', $this->orden_mp('ORDA', 'processed', 'processed', 'accredited')],
        ]);

        $response = $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id);

        $response->assertStatus(200);
        $this->assert_sin_token($response);
        $response->assertJsonPath('cobro.estado', 'pagado');
        $response->assertJsonPath('cobro.qr.name', $qr->name);
        $response->assertJsonPath('cobro.current_acount_payment_method_id', self::METODO_MERCADO_PAGO);
        $this->assertNotNull($response->json('cobro.paid_at'));

        $cobro->refresh();

        $this->assertSame('pagado', $cobro->estado);
        $this->assertNotNull($cobro->paid_at);
        $this->assertSame('PAY01JTEST', $cobro->mp_payment_id);
        $this->assertSame('processed', $cobro->mp_status);
    }

    /**
     * El pago acreditado con la orden todavía sin pasar a processed también cuenta como pagado.
     *
     * @test
     */
    public function un_pago_accredited_con_la_orden_atrasada_tambien_es_pagado()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDA']);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDA$#', $this->orden_mp('ORDA', 'action_required', 'processed', 'accredited')],
        ]);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'pagado');
    }

    /**
     * `expired` → vencido.
     *
     * @test
     */
    public function una_orden_expired_deja_el_cobro_vencido()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDB']);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDB$#', $this->orden_mp('ORDB', 'expired')],
        ]);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'vencido');

        $this->assertSame('vencido', $cobro->fresh()->estado);
        $this->assertNull($cobro->fresh()->paid_at);
    }

    /**
     * `created` (nadie pagó, o le rechazaron la tarjeta) → sigue pendiente.
     *
     * @test
     */
    public function una_orden_created_deja_el_cobro_pendiente()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDC']);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDC$#', $this->orden_mp('ORDC', 'created', 'rejected', 'cc_rejected_insufficient_amount')],
        ]);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'pendiente');

        $this->assertSame('pendiente', $cobro->fresh()->estado);
    }

    /**
     * MP caído al consultar: el cobro sigue pendiente (no se da por perdido) y responde 200.
     *
     * @test
     */
    public function si_mp_falla_al_consultar_el_cobro_sigue_pendiente()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDC']);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDC$#', [500, ['message' => 'internal_error']]],
        ]);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'pendiente');
    }

    /**
     * Un cobro que ya no está pendiente no se consulta a MP.
     *
     * @test
     */
    public function un_cobro_que_no_esta_pendiente_no_se_consulta()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['estado' => 'cancelado']);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'cancelado');

        Http::assertNothingSent();
    }

    /**
     * Cobro de otro comercio: 404 al consultar y al cancelar.
     *
     * @test
     */
    public function un_cobro_de_otro_comercio_da_404()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $cobro = $this->cobro_en_base($this->qr_en_base(['user_id' => 999999]), ['user_id' => 999999]);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)->assertStatus(404);
        $this->postJson('api/mercado-pago/qr/cobros/' . $cobro->id . '/cancelar')->assertStatus(404);

        Http::assertNothingSent();
    }

    /**
     * Cancelar ok → cancelado, con idempotency key y sin cuerpo.
     *
     * @test
     */
    public function cancelar_deja_el_cobro_cancelado()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDD']);

        $this->fake_mp([
            ['POST', '#^/v1/orders/ORDD/cancel$#', [200, ['id' => 'ORDD', 'status' => 'canceled']]],
        ]);

        $this->postJson('api/mercado-pago/qr/cobros/' . $cobro->id . '/cancelar')
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'cancelado');

        $this->assertSame('cancelado', $cobro->fresh()->estado);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDD/cancel'
                && $request->header('X-Idempotency-Key') !== []
                && $request->header('X-Idempotency-Key')[0] !== ''
                && $request->body() === '';
        });
    }

    /**
     * 409 locked: el cliente está pagando → 409 `pagando: true` y el cobro sigue pendiente.
     *
     * @test
     */
    public function cancelar_mientras_el_cliente_paga_da_409_y_sigue_pendiente()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDE']);

        $this->fake_mp([
            ['POST', '#^/v1/orders/ORDE/cancel$#', [409, ['errors' => [['code' => 'instore_order_locked_error', 'message' => 'The order is locked']]]]],
        ]);

        $response = $this->postJson('api/mercado-pago/qr/cobros/' . $cobro->id . '/cancelar');

        $response->assertStatus(409);
        $response->assertJsonPath('pagando', true);
        $this->assertStringStartsWith('El cliente está pagando en este momento', $response->json('message'));

        $this->assertSame('pendiente', $cobro->fresh()->estado);
    }

    /**
     * 409 `order_already_canceled` → cancelado.
     *
     * @test
     */
    public function cancelar_una_orden_ya_cancelada_deja_el_cobro_cancelado()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDF']);

        $this->fake_mp([
            ['POST', '#^/v1/orders/ORDF/cancel$#', [409, ['errors' => [['code' => 'order_already_canceled', 'message' => 'Order already canceled']]]]],
        ]);

        $this->postJson('api/mercado-pago/qr/cobros/' . $cobro->id . '/cancelar')
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'cancelado');
    }

    /**
     * Otro rechazo al cancelar, y la orden resulta pagada un instante antes: 200 con el cobro
     * pagado (el SPA guarda la venta), no un error.
     *
     * @test
     */
    public function cancelar_una_orden_que_se_pago_justo_antes_devuelve_el_cobro_pagado()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDG']);

        $this->fake_mp([
            ['POST', '#^/v1/orders/ORDG/cancel$#', [409, ['errors' => [['code' => 'order_already_processed', 'message' => 'Order already processed']]]]],
            ['GET', '#^/v1/orders/ORDG$#', $this->orden_mp('ORDG', 'processed', 'processed', 'accredited')],
        ]);

        $this->postJson('api/mercado-pago/qr/cobros/' . $cobro->id . '/cancelar')
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'pagado');
    }

    /**
     * Listado de los últimos cobros del comercio, sin los de otros comercios.
     *
     * @test
     */
    public function el_listado_de_cobros_trae_solo_los_del_comercio()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $qr = $this->qr_en_base();

        $propio = $this->cobro_en_base($qr, ['estado' => 'pagado']);
        $ajeno  = $this->cobro_en_base($this->qr_en_base(['user_id' => 999999]), ['user_id' => 999999]);

        $response = $this->getJson('api/mercado-pago/qr/cobros?limit=20');

        $response->assertStatus(200);

        $ids = collect($response->json('cobros'))->pluck('id')->all();

        $this->assertContains($propio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);

        Http::assertNothingSent();
    }
}
