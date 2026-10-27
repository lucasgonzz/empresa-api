<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\PlatformConnector;
use Illuminate\Support\Facades\Http;

/**
 * Conector de Mercado Pago conectado pero SIN la cuenta registrada (`platform_user_id` vacío).
 *
 * Es el estado en el que quedan las demos: el demo-setup carga una credencial fija en el conector en
 * vez de pasar por el OAuth, que es el único camino que guarda el `user_id` de la cuenta. Medido el
 * 27/10/2026 en demo, demo2 y demo3: las tres con el conector `conectado` y `platform_user_id` NULL,
 * y por eso la tarjeta del QR no aparecía. El QR completa la cuenta una vez con `GET /users/me`.
 *
 * PHP 7.4: sin match, ?->, str_contains, argumentos nombrados ni union types.
 */
class Conector_sin_cuenta_registrada_Test extends MercadoPagoQrTestCase
{
    /**
     * La cuenta se pide a `/users/me`, se guarda en el conector y el QR queda disponible.
     *
     * @test
     */
    public function completa_la_cuenta_con_users_me_y_la_guarda()
    {
        $connector = $this->conectar_mp(['platform_user_id' => null, 'refresh_token' => null, 'expires_at' => null]);

        $this->fake_mp([
            ['GET', '#^/users/me$#', [200, ['id' => 163250661, 'nickname' => 'COMERCIO']]],
        ]);

        $response = $this->getJson('api/mercado-pago/qr');

        $response->assertStatus(200);
        $response->assertJsonPath('conectado', true);
        $this->assertSame('163250661', PlatformConnector::find($connector->id)->platform_user_id);

        // Ya guardada: la segunda consulta no vuelve a preguntarle a Mercado Pago.
        $this->fake_mp([]);
        $this->getJson('api/mercado-pago/qr')->assertJsonPath('conectado', true);
        Http::assertNothingSent();
    }

    /**
     * Si Mercado Pago no contesta, sigue sin conexión y no se guarda nada (se reintenta después).
     *
     * @test
     */
    public function si_users_me_falla_sigue_sin_conexion_y_no_guarda_nada()
    {
        $connector = $this->conectar_mp(['platform_user_id' => null]);

        $this->fake_mp([
            ['GET', '#^/users/me$#', [500, ['message' => 'internal_error']]],
        ]);

        $response = $this->getJson('api/mercado-pago/qr');

        $response->assertStatus(200);
        $response->assertJsonPath('conectado', false);
        $this->assertNull(PlatformConnector::find($connector->id)->platform_user_id);
    }
}
