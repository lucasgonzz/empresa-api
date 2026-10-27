<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\MercadoPagoQrCobro;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * El comando `mercadopago:qr-conciliar` (misión cobro-qr-mercado-pago, 26/10/2026), la red de
 * abajo del polling del modal de Vender.
 *
 * Lo que fija:
 * - Un pendiente reciente cuya orden está `processed` pasa a `pagado`; uno `created` sigue
 *   pendiente.
 * - Un pendiente de más de 3 horas pasa a `vencido` (se lo consulta una última vez y, si MP no
 *   dice que se pagó, se vence), tenga o no orden en MP.
 * - Lo que no está pendiente no se consulta.
 * - Un comercio sin conexión no corta el loop: sus pendientes viejos se vencen igual.
 *
 * @group mercado_pago_qr
 */
class Conciliar_cobros_Test extends MercadoPagoQrTestCase
{
    /**
     * Pone el `created_at` de un cobro en el pasado.
     *
     * @param MercadoPagoQrCobro $cobro
     * @param int $horas
     * @return void
     */
    protected function envejecer(MercadoPagoQrCobro $cobro, $horas)
    {
        MercadoPagoQrCobro::where('id', $cobro->id)->update(['created_at' => Carbon::now()->subHours($horas)]);
    }

    /**
     * Actualiza los pendientes recientes y vence los de más de 3 horas.
     *
     * @test
     */
    public function actualiza_los_pendientes_y_vence_los_de_mas_de_tres_horas()
    {
        $this->conectar_mp();

        $qr = $this->qr_en_base();

        $pagado_en_mp   = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDPAGO']);
        $sigue_esperando = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDESPERA']);
        $viejo          = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDVIEJA']);
        $viejo_sin_orden = $this->cobro_en_base($qr, ['mp_order_id' => null]);
        $ya_cancelado   = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDCANCELADA', 'estado' => 'cancelado']);

        $this->envejecer($viejo, 4);
        $this->envejecer($viejo_sin_orden, 4);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDPAGO$#', $this->orden_mp('ORDPAGO', 'processed', 'processed', 'accredited')],
            ['GET', '#^/v1/orders/ORDESPERA$#', $this->orden_mp('ORDESPERA', 'created')],
            ['GET', '#^/v1/orders/ORDVIEJA$#', $this->orden_mp('ORDVIEJA', 'created')],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pagado', $pagado_en_mp->fresh()->estado);
        $this->assertNotNull($pagado_en_mp->fresh()->paid_at);
        $this->assertSame('pendiente', $sigue_esperando->fresh()->estado);
        $this->assertSame('vencido', $viejo->fresh()->estado);
        $this->assertSame('vencido', $viejo_sin_orden->fresh()->estado);
        $this->assertSame('cancelado', $ya_cancelado->fresh()->estado);

        Http::assertNotSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDCANCELADA';
        });
    }

    /**
     * Un pendiente viejo que en MP figura pagado no se pierde: queda pagado, no vencido.
     *
     * @test
     */
    public function un_pendiente_viejo_que_se_pago_queda_pagado()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDTARDE']);
        $this->envejecer($cobro, 5);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDTARDE$#', $this->orden_mp('ORDTARDE', 'processed', 'processed', 'accredited')],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pagado', $cobro->fresh()->estado);
    }

    /**
     * Sin conexión: no se consulta nada, el reciente sigue pendiente y el viejo se vence.
     *
     * @test
     */
    public function sin_conexion_no_consulta_y_vence_los_viejos()
    {
        $this->fake_mp([]);

        $qr = $this->qr_en_base();

        $reciente = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDR']);
        $viejo    = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDV']);
        $this->envejecer($viejo, 4);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pendiente', $reciente->fresh()->estado);
        $this->assertSame('vencido', $viejo->fresh()->estado);

        Http::assertNothingSent();
    }
}
