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
     * Datos del formulario "Crear QR" (los mismos que usa `1_Configuracion_de_QR_Test`).
     *
     * @return array
     */
    protected function datos_qr()
    {
        return [
            'name'          => 'Mostrador',
            'street_name'   => 'Av. Pellegrini',
            'street_number' => '1234',
            'city_name'     => 'Rosario',
            'state_name'    => 'Santa Fe',
        ];
    }

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
     * La sucursal recién creada tarda en aparecer para `/v2/pos` (medido el 27/10/2026 contra MP
     * real: 404 `store_not_found` un segundo después del alta). Se reintenta y el QR se crea.
     *
     * @test
     */
    public function la_caja_se_reintenta_mientras_mp_no_reconoce_la_sucursal()
    {
        config(['services.mercadopago.qr_espera_sucursal_segundos' => 0]);
        $this->conectar_mp();

        $intentos = 0;
        $this->fake_mp([
            ['POST', '#^/users/\d+/stores$#', [201, ['id' => 555]]],
            ['POST', '#^/v2/pos$#', function () use (&$intentos) {
                $intentos++;

                if ($intentos < 3) {
                    return [404, ['errors' => [['code' => 'store_not_found', 'message' => 'Store with external_store_id does not exist']]]];
                }

                return [201, ['id' => 777, 'qr_response' => ['image' => 'https://mp/qr.png', 'template_document' => 'https://mp/qr.pdf', 'qr_code' => '000201']]];
            }],
        ]);

        $response = $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr());

        $response->assertStatus(201);
        $this->assertSame(3, $intentos);
        $this->assertSame('777', \App\Models\MercadoPagoQrCaja::where('user_id', self::USER_ID)->first()->mp_pos_id);
    }

    /**
     * Si la caja falla igual, el próximo intento reusa la sucursal que ya quedó creada en MP: no se
     * deja otra sucursal huérfana en la cuenta del comercio.
     *
     * @test
     */
    public function un_reintento_despues_de_una_caja_fallida_reusa_la_sucursal_de_mp()
    {
        config(['services.mercadopago.qr_espera_sucursal_segundos' => 0]);
        $this->conectar_mp();

        $cajas = 0;
        $this->fake_mp([
            ['POST', '#^/users/\d+/stores$#', [201, ['id' => 555]]],
            ['POST', '#^/v2/pos$#', function () use (&$cajas) {
                $cajas++;

                return $cajas === 1
                    ? [400, ['message' => 'rechazo puntual', 'status' => 400]]
                    : [201, ['id' => 778, 'qr_response' => ['image' => 'https://mp/qr.png', 'template_document' => 'https://mp/qr.pdf', 'qr_code' => '000201']]];
            }],
        ]);

        $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr())->assertStatus(422);
        $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr())->assertStatus(201);

        $posts_a_stores = Http::recorded(function ($request) {
            return $request->method() === 'POST' && preg_match('#/stores$#', parse_url($request->url(), PHP_URL_PATH));
        });

        $this->assertCount(1, $posts_a_stores, 'La sucursal de MP se crea una sola vez.');
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
