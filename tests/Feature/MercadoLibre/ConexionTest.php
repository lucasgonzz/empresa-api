<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\OauthState;
use App\Models\Platform;
use App\Models\PlatformConnector;
use Carbon\Carbon;

/**
 * Conexión de la cuenta de Mercado Libre (misión mercado-libre-integracion, 27/10/2026): connect
 * con y sin la URL central del admin, el 422 sin credenciales, el callback (redirect del canje
 * según el formato del state y `extra_config.meli`), el desconectar y la solapa "Cuenta".
 *
 * @group mercado-libre
 */
class ConexionTest extends MercadoLibreTestCase
{
    /**
     * Query de la URL de autorización devuelta por el connect.
     *
     * @param string $url
     * @return array<string, string>
     */
    protected function query_de($url)
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_connect_con_relay_manda_el_state_con_el_host_y_la_redirect_del_relay()
    {
        $respuesta = $this->getJson('/api/integraciones/mercadolibre/connect');

        $respuesta->assertStatus(200);

        $url = (string) $respuesta->json('url');
        $this->assertStringStartsWith('https://auth.mercadolibre.com.ar/authorization?', $url);

        $query = $this->query_de($url);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('APP-ML-DE-PRUEBA', $query['client_id']);
        $this->assertSame(self::RELAY, $query['redirect_uri']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}_[a-z0-9.-]+$/', $query['state']);

        // El aleatorio (sin el host) es el que quedó guardado, atado al conector de ML del dueño.
        $aleatorio = substr($query['state'], 0, 64);
        $fila = OauthState::where('state', $aleatorio)->first();
        $this->assertNotNull($fila, 'El state aleatorio no quedó en oauth_states.');

        $connector = PlatformConnector::find($fila->platform_connector_id);
        $this->assertSame((int) $this->owner->id, (int) $connector->user_id);
        $this->assertSame(Platform::SLUG_MERCADO_LIBRE, $connector->platform->slug);
        $this->assertSame(PlatformConnector::STATUS_SIN_CONECTAR, $connector->status);
    }

    public function test_connect_sin_relay_usa_la_redirect_propia_y_el_state_sin_host()
    {
        config(['services.mercadolibre.oauth_relay_url' => '']);

        $respuesta = $this->getJson('/api/integraciones/mercadolibre/connect');

        $respuesta->assertStatus(200);

        $query = $this->query_de($respuesta->json('url'));
        $this->assertSame(self::REDIRECT_LEGACY, $query['redirect_uri']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $query['state']);
    }

    public function test_connect_sin_credenciales_de_la_app_responde_422_con_el_mensaje()
    {
        config([
            'services.mercadolibre.client_id'     => null,
            'services.mercadolibre.client_secret' => null,
        ]);

        $respuesta = $this->getJson('/api/integraciones/mercadolibre/connect');

        $respuesta->assertStatus(422);
        $respuesta->assertExactJson([
            'message' => 'Mercado Libre no está configurado en este sistema (faltan las credenciales de la app). Avisale a ComercioCity.',
        ]);
    }

    public function test_connect_crea_la_fila_de_platforms_si_falta()
    {
        Platform::where('slug', Platform::SLUG_MERCADO_LIBRE)->delete();

        $this->getJson('/api/integraciones/mercadolibre/connect')->assertStatus(200);

        $this->assertTrue(Platform::where('slug', Platform::SLUG_MERCADO_LIBRE)->exists());
    }

    public function test_el_callback_canjea_con_la_redirect_del_relay_y_guarda_la_cuenta()
    {
        $this->fake_ml([
            'POST oauth/token' => [200, [
                'access_token'  => 'APP_USR-NUEVO',
                'refresh_token' => 'TG-NUEVO',
                'expires_in'    => 21600,
                'user_id'       => (int) self::SELLER_ID,
            ]],
            'GET users/me' => [200, $this->usuario_ml(['normal', 'user_product_seller'])],
        ]);

        $url = $this->getJson('/api/integraciones/mercadolibre/connect')->json('url');
        $state = $this->query_de($url)['state'];

        // El admin reenvía el state SIN el host; se prueba con el host igual (se acepta de las dos formas).
        $respuesta = $this->get('/platform-connector/mercadolibre/callback?code=TG-CODE-PRUEBA&state='.urlencode($state));

        $respuesta->assertStatus(200);

        $canjes = $this->pedidos_ml('POST', 'oauth/token');
        $this->assertCount(1, $canjes);
        $this->assertSame(self::RELAY, $canjes[0]['redirect_uri']);
        $this->assertSame('authorization_code', $canjes[0]['grant_type']);
        $this->assertSame('APP-ML-DE-PRUEBA', $canjes[0]['client_id']);

        $connector = PlatformConnector::where('user_id', $this->owner->id)
            ->whereHas('platform', function ($q) {
                $q->where('slug', Platform::SLUG_MERCADO_LIBRE);
            })->first();

        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $connector->status);
        $this->assertSame('APP_USR-NUEVO', $connector->access_token);
        $this->assertSame(self::SELLER_ID, $connector->platform_user_id);

        $meli = $connector->extra_config['meli'];
        $this->assertSame('VENDEDOR_PRUEBA', $meli['nickname']);
        $this->assertTrue($meli['user_product_seller']);
        $this->assertFalse($meli['multiorigen']);
        $this->assertSame('MLA', $meli['site_id']);
        $this->assertNotEmpty($meli['conectado_en']);
    }

    public function test_el_callback_con_el_state_viejo_canjea_con_la_redirect_propia()
    {
        $this->fake_ml([
            'POST oauth/token' => [200, ['access_token' => 'APP_USR-VIEJO', 'refresh_token' => 'TG-VIEJO', 'expires_in' => 21600, 'user_id' => 1]],
            'GET users/me'     => [200, $this->usuario_ml()],
        ]);

        $connector = $this->conector_ml(['status' => PlatformConnector::STATUS_SIN_CONECTAR, 'access_token' => null, 'refresh_token' => null]);

        $this->get('/platform-connector/mercadolibre/callback?code=TG-CODE&state='.$connector->id)->assertStatus(200);

        $canjes = $this->pedidos_ml('POST', 'oauth/token');
        $this->assertCount(1, $canjes);
        $this->assertSame(self::REDIRECT_LEGACY, $canjes[0]['redirect_uri']);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $connector->fresh()->status);
    }

    public function test_el_callback_rechazado_deja_el_conector_en_error_con_mensaje_legible()
    {
        $this->fake_ml([
            'POST oauth/token' => [400, ['error' => 'invalid_grant', 'message' => 'code expired']],
        ]);

        $url = $this->getJson('/api/integraciones/mercadolibre/connect')->json('url');
        $state = substr($this->query_de($url)['state'], 0, 64);

        $this->get('/platform-connector/mercadolibre/callback?code=X&state='.$state)->assertStatus(500);

        $fila = OauthState::where('state', $state)->first();
        $connector = PlatformConnector::find($fila->platform_connector_id);

        $this->assertSame(PlatformConnector::STATUS_ERROR, $connector->status);
        $this->assertStringContainsString('Mercado Libre rechazó la conexión (invalid_grant)', $connector->error_message);
        $this->assertStringNotContainsString('code expired', $connector->error_message);
    }

    /**
     * Arreglo 6 (revisión, 27/10/2026): el callback es público. Un `code` basura con el state viejo
     * (el id del conector) no puede cortar una conexión que anda.
     */
    public function test_un_canje_fallido_sobre_un_conector_conectado_no_lo_toca()
    {
        $connector = $this->conector_ml();
        $this->fake_ml([
            'POST oauth/token' => [400, ['error' => 'invalid_grant', 'message' => 'invalid code']],
        ]);

        $respuesta = $this->get('/platform-connector/mercadolibre/callback?code=basura&state='.$connector->id);

        $respuesta->assertStatus(400);
        $this->assertStringContainsString('text/html', (string) $respuesta->headers->get('Content-Type'));

        $connector = $connector->fresh();
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $connector->status);
        $this->assertNull($connector->error_message);
        $this->assertSame(self::TOKEN, $connector->access_token);
        $this->assertSame(self::REFRESH, $connector->refresh_token);
    }

    /**
     * Arreglo 6: el comerciante canceló en ML (el relay reenvía `error`): página amable, sin canjear
     * nada y sin tocar el conector.
     */
    public function test_si_el_comerciante_cancela_en_ml_se_muestra_una_pagina_amable_sin_tocar_nada()
    {
        $connector = $this->conector_ml();
        $this->fake_ml([]);

        $url = $this->getJson('/api/integraciones/mercadolibre/connect')->json('url');
        $state = substr($this->query_de($url)['state'], 0, 64);

        $respuesta = $this->get('/platform-connector/mercadolibre/callback?error=access_denied&error_description=user+denied&state='.$state);

        $respuesta->assertStatus(200);
        $respuesta->assertSee('Cancelaste la conexión con Mercado Libre. Podés cerrar esta ventana y volver a ComercioCity.', false);
        $this->assertCount(0, $this->pedidos_ml('POST', 'oauth/token'));

        $connector = $connector->fresh();
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $connector->status);
        $this->assertSame(self::TOKEN, $connector->access_token);
    }

    public function test_desconectar_borra_los_tokens_y_la_cuenta()
    {
        $connector = $this->conector_ml();

        $this->postJson('/api/integraciones/mercadolibre/disconnect')
            ->assertStatus(200)
            ->assertExactJson(['ok' => true]);

        $connector = $connector->fresh();
        $this->assertSame(PlatformConnector::STATUS_SIN_CONECTAR, $connector->status);
        $this->assertNull($connector->getAttributes()['access_token']);
        $this->assertNull($connector->getAttributes()['refresh_token']);
        $this->assertNull($connector->platform_user_id);
        $this->assertTrue(empty($connector->extra_config['meli']));
    }

    public function test_cuenta_sin_conexion_responde_200_con_conectado_false()
    {
        $respuesta = $this->getJson('/api/mercado-libre/cuenta');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cuenta.conectado', false);
        $respuesta->assertJsonStructure(['cuenta' => [
            'conectado', 'nickname', 'meli_user_id', 'reputacion', 'puede_publicar', 'motivos_no_publica',
            'user_product_seller', 'multiorigen', 'cola' => ['pendientes', 'errores', 'exitosas_24h'],
            'ultimo_poll_pedidos', 'error_conexion',
        ]]);
    }

    public function test_cuenta_conectada_trae_la_reputacion_y_si_puede_publicar()
    {
        $this->conector_ml();
        $this->fake_ml(['GET users/me' => [200, $this->usuario_ml()]]);

        $respuesta = $this->getJson('/api/mercado-libre/cuenta');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cuenta.conectado', true);
        $respuesta->assertJsonPath('cuenta.nickname', 'VENDEDOR_PRUEBA');
        $respuesta->assertJsonPath('cuenta.meli_user_id', self::SELLER_ID);
        $respuesta->assertJsonPath('cuenta.reputacion.level_id', '4_light_green');
        $respuesta->assertJsonPath('cuenta.reputacion.transacciones_completadas', 40);
        $respuesta->assertJsonPath('cuenta.reputacion.periodo', '365 days');
        $respuesta->assertJsonPath('cuenta.puede_publicar', true);
        $respuesta->assertJsonPath('cuenta.motivos_no_publica', []);

        $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent());
    }

    public function test_cuenta_con_la_conexion_cortada_informa_el_error()
    {
        $this->conector_ml([
            'status'        => PlatformConnector::STATUS_ERROR,
            'error_message' => 'Mercado Libre cortó la conexión (cambio de contraseña, permiso quitado o mucho tiempo sin uso). Volvé a conectar la cuenta.',
        ]);

        $respuesta = $this->getJson('/api/mercado-libre/cuenta');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cuenta.conectado', false);
        $this->assertStringContainsString('Volvé a conectar', (string) $respuesta->json('cuenta.error_conexion'));
    }

    public function test_el_listado_de_integraciones_no_muestra_vencimiento_y_da_conectado_con_el_access_vencido()
    {
        $this->conector_ml(['expires_at' => Carbon::now()->subHour()]);

        $json = $this->getJson('/api/integraciones')->assertStatus(200)->json();

        $ml = null;
        foreach ($json['integraciones'] as $integracion) {
            if ($integracion['slug'] === Platform::SLUG_MERCADO_LIBRE) {
                $ml = $integracion;
            }
        }

        $this->assertTrue($ml['connected'], 'Con refresh_token, el access vencido se renueva solo: sigue conectado.');
        $this->assertNull($ml['expires_at']);
    }
}
