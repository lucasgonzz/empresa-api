<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\MercadoPagoQrCobro;
use App\Services\MercadoPago\MercadoPagoQrService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Lo que pasa cuando MP no contesta al crear la orden, y el candado por QR (misión
 * cobro-qr-mercado-pago, 26/10/2026, arreglos de la revisión independiente).
 *
 * Lo que fija:
 * - 🔴 Una orden puede haberse creado en MP aunque la respuesta no llegue. Por eso el cobro guarda
 *   su `X-Idempotency-Key` y el cuerpo se arma siempre igual: el reintento inmediato y la
 *   recuperación posterior reenvían EXACTAMENTE el mismo pedido con la MISMA clave.
 * - Red caída o 5xx dos veces → 201 con el cobro `pendiente`, SIN `mp_order_id` y con "Mercado
 *   Pago no respondió, reintentando…" (no `error`: el cliente podría pagar esa orden).
 * - El polling (`GET cobros/{id}`) recupera la orden reenviando el pedido; un 4xx definitivo al
 *   reenviar deja el cobro en `error`.
 * - Cancelar un cobro sin orden primero la recupera y después la cancela; si MP sigue sin
 *   contestar, 422 `sin_respuesta` y el cobro sigue pendiente.
 * - El candado por QR: con el QR tomado por otro cobro en curso, 422 `qr_ocupado` sin tocar MP.
 * - Reemplazar un cobro sin orden con MP caído: 422 `sin_respuesta`, no se carga el nuevo.
 *
 * @group mercado_pago_qr
 */
class Orden_sin_respuesta_y_candado_Test extends MercadoPagoQrTestCase
{
    /**
     * Primer intento sin respuesta (la red cae), el reintento crea la orden: se usó la MISMA clave
     * y el MISMO cuerpo, y el cobro queda con su orden.
     *
     * @test
     */
    public function el_reintento_inmediato_usa_la_misma_clave_y_el_mismo_cuerpo()
    {
        $this->conectar_mp();
        $this->qr_en_base();

        $pedidos = [];

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', function (Request $request) use (&$pedidos) {
                $pedidos[] = ['clave' => $request->header('X-Idempotency-Key')[0], 'cuerpo' => $request->body()];

                if (count($pedidos) === 1) {
                    throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
                }

                return $this->orden_creada_mp('ORDREINTENTO');
            }],
        ]);

        $response = $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500]);

        $response->assertStatus(201);
        $response->assertJsonPath('cobro.mp_order_id', 'ORDREINTENTO');
        $response->assertJsonPath('cobro.error_message', null);

        $this->assertCount(2, $pedidos);
        $this->assertSame($pedidos[0]['clave'], $pedidos[1]['clave'], 'Misma X-Idempotency-Key en el reintento.');
        $this->assertSame($pedidos[0]['cuerpo'], $pedidos[1]['cuerpo'], 'Mismo cuerpo en el reintento.');

        $cobro = MercadoPagoQrCobro::find($response->json('cobro.id'));

        $this->assertSame($pedidos[0]['clave'], $cobro->mp_idempotency_key, 'La clave queda guardada en el cobro.');
    }

    /**
     * 5xx dos veces → pendiente sin orden con el aviso; después el polling recupera la orden con el
     * mismo pedido y consulta su estado.
     *
     * @test
     */
    public function cinco_xx_dos_veces_deja_el_cobro_pendiente_sin_orden_y_el_polling_lo_recupera()
    {
        $this->conectar_mp();
        $this->qr_en_base();

        $intentos = 0;

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', function () use (&$intentos) {
                $intentos++;

                return $intentos <= 2 ? [503, ['message' => 'service unavailable']] : $this->orden_creada_mp('ORDRECUPERADA');
            }],
            ['GET', '#^/v1/orders/ORDRECUPERADA$#', $this->orden_mp('ORDRECUPERADA', 'created')],
        ]);

        $response = $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500]);

        $response->assertStatus(201);
        $response->assertJsonPath('cobro.estado', 'pendiente');
        $response->assertJsonPath('cobro.mp_order_id', null);
        $response->assertJsonPath('cobro.error_message', 'Mercado Pago no respondió, reintentando…');

        $id = $response->json('cobro.id');

        $this->assertSame('pendiente', MercadoPagoQrCobro::find($id)->estado, 'NO es error: la orden puede existir.');

        $polling = $this->getJson('api/mercado-pago/qr/cobros/' . $id);

        $polling->assertStatus(200);
        $polling->assertJsonPath('cobro.estado', 'pendiente');
        $polling->assertJsonPath('cobro.mp_order_id', 'ORDRECUPERADA');
        $polling->assertJsonPath('cobro.error_message', null);

        $posts = $this->posts_de_ordenes();

        $this->assertCount(3, $posts, 'Dos al crear (con su reintento) y uno al recuperar.');

        $clave  = $posts[0]->header('X-Idempotency-Key')[0];
        $cuerpo = $posts[0]->body();

        foreach ($posts as $post) {
            $this->assertSame($clave, $post->header('X-Idempotency-Key')[0], 'Siempre la misma clave.');
            $this->assertSame($cuerpo, $post->body(), 'Siempre el mismo cuerpo.');
        }

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDRECUPERADA';
        });
    }

    /**
     * La recuperación encuentra la orden ya pagada: el cobro queda pagado.
     *
     * @test
     */
    public function la_recuperacion_de_una_orden_ya_pagada_deja_el_cobro_pagado()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null, 'error_message' => 'Mercado Pago no respondió, reintentando…']);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', $this->orden_creada_mp('ORDYAPAGA')],
            ['GET', '#^/v1/orders/ORDYAPAGA$#', $this->orden_mp('ORDYAPAGA', 'processed', 'processed', 'accredited')],
        ]);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'pagado')
            ->assertJsonPath('cobro.mp_order_id', 'ORDYAPAGA');

        Http::assertSent(function (Request $request) use ($cobro) {
            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/v1/orders'
                && $request->header('X-Idempotency-Key')[0] === $cobro->mp_idempotency_key
                && $request['external_reference'] === $cobro->external_reference;
        });
    }

    /**
     * El reenvío recibe un 4xx definitivo: la orden nunca existió, el cobro pasa a `error`.
     *
     * @test
     */
    public function un_cuatro_xx_al_recuperar_deja_el_cobro_en_error()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', [400, ['errors' => [['code' => 'invalid_pos', 'message' => 'external_pos_id not found']]]]],
        ]);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'error');

        $this->assertStringContainsString('external_pos_id not found', $cobro->fresh()->error_message);
    }

    /**
     * Con MP caído el polling no cambia nada: sigue pendiente sin orden.
     *
     * @test
     */
    public function con_mp_caido_el_polling_deja_el_cobro_pendiente_sin_orden()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', [502, ['message' => 'bad gateway']]],
        ]);

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'pendiente')
            ->assertJsonPath('cobro.mp_order_id', null)
            ->assertJsonPath('cobro.error_message', 'Mercado Pago no respondió, reintentando…');
    }

    /**
     * Cancelar un cobro sin orden: primero se la recupera y después se la cancela.
     *
     * @test
     */
    public function cancelar_un_cobro_sin_orden_la_recupera_y_la_cancela()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', $this->orden_creada_mp('ORDACANCELAR')],
            ['POST', '#^/v1/orders/ORDACANCELAR/cancel$#', [200, ['id' => 'ORDACANCELAR', 'status' => 'canceled']]],
        ]);

        $this->postJson('api/mercado-pago/qr/cobros/' . $cobro->id . '/cancelar')
            ->assertStatus(200)
            ->assertJsonPath('cobro.estado', 'cancelado')
            ->assertJsonPath('cobro.mp_order_id', 'ORDACANCELAR');

        Http::assertSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDACANCELAR/cancel';
        });
    }

    /**
     * Cancelar un cobro sin orden con MP caído: 422 `sin_respuesta` y el cobro sigue pendiente
     * (no se puede asegurar que no haya una orden viva en el QR).
     *
     * @test
     */
    public function cancelar_un_cobro_sin_orden_con_mp_caido_da_422_y_sigue_pendiente()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', [500, ['message' => 'internal']]],
        ]);

        $this->postJson('api/mercado-pago/qr/cobros/' . $cobro->id . '/cancelar')
            ->assertStatus(422)
            ->assertJsonPath('sin_respuesta', true);

        $this->assertSame('pendiente', $cobro->fresh()->estado);
    }

    /**
     * Reemplazar en el QR un cobro sin orden con MP caído: 422 `sin_respuesta` y no se carga el
     * cobro nuevo (no se sabe si el QR está libre).
     *
     * @test
     */
    public function reemplazar_un_cobro_sin_orden_con_mp_caido_no_carga_el_nuevo()
    {
        $this->conectar_mp();

        $qr    = $this->qr_en_base();
        $viejo = $this->cobro_en_base($qr, ['mp_order_id' => null]);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', [500, ['message' => 'internal']]],
        ]);

        $this->postJson('api/mercado-pago/qr/cobros', ['total' => 900])
            ->assertStatus(422)
            ->assertJsonPath('sin_respuesta', true);

        $this->assertSame('pendiente', $viejo->fresh()->estado);
        $this->assertSame(1, MercadoPagoQrCobro::where('mercado_pago_qr_caja_id', $qr->id)->count(), 'No se creó el cobro nuevo.');

        foreach ($this->posts_de_ordenes() as $post) {
            $this->assertSame($viejo->mp_idempotency_key, $post->header('X-Idempotency-Key')[0], 'Solo se reenvió la orden del viejo.');
        }
    }

    /**
     * Con el QR tomado por otro cobro en curso (otra conexión de MySQL tiene su candado), el
     * cobro nuevo no entra: 422 `qr_ocupado` y ni un pedido a MP.
     *
     * @test
     */
    public function con_el_qr_tomado_por_otro_cobro_da_422_qr_ocupado()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $qr = $this->qr_en_base();

        config(['services.mercadopago.qr_candado_segundos' => 0]);
        config(['database.connections.mysql_otra_caja' => config('database.connections.' . config('database.default'))]);

        $otra_caja = DB::connection('mysql_otra_caja');
        $nombre    = MercadoPagoQrService::nombre_del_candado($qr->id);

        try {
            $tomado = $otra_caja->select('SELECT GET_LOCK(?, 0) AS tomado', [$nombre]);
            $this->assertSame(1, (int) $tomado[0]->tomado, 'La otra conexión tiene que poder tomar el candado.');

            $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500])
                ->assertStatus(422)
                ->assertJsonPath('qr_ocupado', true);

            Http::assertNothingSent();
            $this->assertSame(0, MercadoPagoQrCobro::where('mercado_pago_qr_caja_id', $qr->id)->count());
        } finally {
            $otra_caja->select('SELECT RELEASE_LOCK(?)', [$nombre]);
            DB::disconnect('mysql_otra_caja');
        }

        // Suelto el candado, el mismo cobro entra.
        $this->fake_mp([
            ['POST', '#^/v1/orders$#', $this->orden_creada_mp('ORDLIBRE')],
        ]);

        $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500])->assertStatus(201);
    }

    /**
     * El cobro congela el método de pago configurado al crearlo, y `Cobro` lo devuelve aunque la
     * configuración cambie después.
     *
     * @test
     */
    public function el_cobro_congela_el_metodo_de_pago_configurado()
    {
        $connector = $this->conectar_mp(['extra_config' => ['qr' => ['current_acount_payment_method_id' => 4]]]);
        $this->qr_en_base();

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', $this->orden_creada_mp('ORDMETODO')],
            ['GET', '#^/v1/orders/ORDMETODO$#', $this->orden_mp('ORDMETODO', 'created')],
        ]);

        $response = $this->postJson('api/mercado-pago/qr/cobros', ['total' => 1500]);

        $response->assertStatus(201)->assertJsonPath('cobro.current_acount_payment_method_id', 4);

        $cobro = MercadoPagoQrCobro::find($response->json('cobro.id'));
        $this->assertSame(4, (int) $cobro->current_acount_payment_method_id);

        $connector->extra_config = ['qr' => ['current_acount_payment_method_id' => self::METODO_MERCADO_PAGO]];
        $connector->save();

        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertJsonPath('cobro.current_acount_payment_method_id', 4);
    }
}
