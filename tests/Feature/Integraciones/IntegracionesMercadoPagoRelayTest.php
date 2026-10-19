<?php

namespace Tests\Feature\Integraciones;

use App\Models\OauthState;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión "mp-redireccion-central-admin" (17/10/2026), parte de `empresa-api`.
 *
 * Mercado Pago pasa a usar UNA sola `redirect_uri` para todos los clientes: la del admin. Este
 * archivo fija el contrato del lado del ERP:
 *
 * 1. `connect` manda a Mercado Pago la URL central como `redirect_uri` y un `state` con forma
 *    `<aleatorio64>_<host>`; en `oauth_states` se guarda SOLO el aleatorio.
 * 2. El canje del `code` manda la MISMA `redirect_uri` que la autorización (Mercado Pago rechaza
 *    el canje si difieren).
 * 3. El callback acepta el `state` limpio (como lo reenvía el admin) y también con el sufijo.
 * 4. Con la URL central vacía (desarrollo local) todo se comporta como antes de la misión.
 *
 * Los tests del flujo viejo (conector, espejo, listado) siguen en `IntegracionesMercadoPagoTest`.
 *
 * @group integraciones
 */
class IntegracionesMercadoPagoRelayTest extends EmpresaTestCase
{
    /** URL central del admin: es lo que Lucas registra UNA vez en Mercado Pago. Contrato con admin-api. */
    const URL_CENTRAL = 'https://api-admin.comerciocity.com/api/mercadopago/callback';

    /** Callback propio de este backend (el flujo legacy). */
    const URL_LEGACY = 'http://localhost/api/integraciones/mercadopago/callback';

    /** Host de la API del cliente de prueba, el que tiene que viajar en el `state`. */
    const HOST = 'api-cliente-de-prueba.comerciocity.com';

    /** Ruta pública del callback del ERP. */
    const RUTA_CALLBACK = '/api/integraciones/mercadopago/callback';

    /** URL del SPA a la que redirige el callback. */
    const SPA_REDIRECT = 'http://localhost:8080/abm/integraciones';

    /**
     * Credenciales de la app y URLs. `oauth_relay_url` NO se pisa a propósito: los tests parten del
     * default de `config/services.php`, que es justamente lo que va a producción sin tocar ningún `.env`.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.app_id'             => 'app-id-de-prueba',
            'services.mercadopago.app_secret'         => 'app-secret-de-prueba',
            'services.mercadopago.oauth_redirect_uri' => self::URL_LEGACY,
            'services.mercadopago.spa_redirect_url'   => self::SPA_REDIRECT,
        ]);
    }

    /**
     * Id del comercio del fixture (el autenticado en `EmpresaTestCase::setUp()`).
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Conector de Mercado Pago del comercio, sin conectar, creando la plataforma si el fixture no la trae.
     *
     * @return PlatformConnector
     */
    protected function conector_sin_conectar()
    {
        $platform = Platform::where('slug', Platform::SLUG_MERCADO_PAGO)->first();

        if (!$platform) {
            $platform = Platform::create([
                'slug'          => Platform::SLUG_MERCADO_PAGO,
                'name'          => 'Mercado Pago',
                'client_id'     => 'app-id-de-prueba',
                'client_secret' => 'app-secret-de-prueba',
                'extra_config'  => null,
            ]);
        }

        return PlatformConnector::create([
            'user_id'     => $this->user_id(),
            'platform_id' => $platform->id,
            'status'      => PlatformConnector::STATUS_SIN_CONECTAR,
        ]);
    }

    /**
     * Fakea el canje del `code` por tokens en Mercado Pago.
     *
     * @return void
     */
    protected function fakear_canje_de_token()
    {
        Http::fake([
            'api.mercadopago.com/oauth/token' => Http::response([
                'access_token'  => 'APP_USR-token-flamante',
                'refresh_token' => 'TG-refresh-flamante',
                'user_id'       => 987654321,
                'public_key'    => 'APP_USR-public-key-flamante',
                'expires_in'    => 15552000,
            ], 200),
        ]);
    }

    /**
     * Pide el `connect` como si lo hiciera el SPA contra la API de un cliente (con su host) y
     * devuelve los parámetros de la URL de autorización ya parseados.
     *
     * @param string $host
     * @return array
     */
    protected function connect_y_parsear($host = self::HOST)
    {
        $respuesta = $this->getJson('http://'.$host.'/api/integraciones/mercadopago/connect');

        $respuesta->assertStatus(200);

        $url = $respuesta->json('url');

        $this->assertStringStartsWith('https://auth.mercadopago.com.ar/authorization?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * El default de la config es EXACTAMENTE la URL del contrato con el admin. Se lee el archivo
     * `config/services.php` aparte de `config()` para no depender de qué `.env` tenga el slot.
     *
     * @return void
     */
    public function test_el_default_de_la_config_es_la_url_central_del_contrato()
    {
        $this->assertSame(self::URL_CENTRAL, config('services.mercadopago.oauth_relay_url'));

        $archivo = require config_path('services.php');

        $this->assertArrayHasKey('oauth_relay_url', $archivo['mercadopago']);
        $this->assertSame(
            'https://api-admin.comerciocity.com/api/mercadopago/callback',
            $archivo['mercadopago']['oauth_relay_url'],
            'El default de oauth_relay_url cambió: es el string que se registra en Mercado Pago y el que '.
            'espera la ruta del admin.'
        );
    }

    /**
     * 🔴 `connect` manda la URL central como `redirect_uri` y un `state` `<aleatorio64>_<host>`;
     * en la base queda solo el aleatorio, y el host no se persiste.
     *
     * @return void
     */
    public function test_el_connect_con_relay_manda_la_url_central_y_un_state_con_el_host()
    {
        $query = $this->connect_y_parsear();

        $this->assertSame(self::URL_CENTRAL, $query['redirect_uri']);
        $this->assertSame('app-id-de-prueba', $query['client_id']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}_'.preg_quote(self::HOST, '/').'$/', $query['state']);

        list($aleatorio, $host) = explode('_', $query['state'], 2);

        $this->assertSame(self::HOST, $host);

        $guardado = OauthState::where('state', $aleatorio)->first();

        $this->assertNotNull($guardado, 'El aleatorio del state no quedó persistido en oauth_states.');
        $this->assertSame($this->user_id(), (int) $guardado->user_id);
        $this->assertNotNull($guardado->platform_connector_id);

        $this->assertNull(
            OauthState::where('state', $query['state'])->first(),
            'Se persistió el state completo con el host: tiene que guardarse SOLO el aleatorio.'
        );
    }

    /**
     * Un host con mayúsculas o con puerto no rompe el formato que el admin acepta: el sufijo sale
     * en minúsculas y sin puerto (`getHost()` no trae el puerto).
     *
     * @return void
     */
    public function test_el_sufijo_del_state_sale_en_minusculas_y_sin_puerto()
    {
        $respuesta = $this->getJson('http://API-Cliente-De-Prueba.ComercioCity.com:8080/api/integraciones/mercadopago/connect');

        $respuesta->assertStatus(200);

        parse_str((string) parse_url($respuesta->json('url'), PHP_URL_QUERY), $query);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}_api-cliente-de-prueba\.comerciocity\.com$/', $query['state']);
    }

    /**
     * 🔴 El canje manda la MISMA `redirect_uri` que la autorización. Mercado Pago rechaza el canje
     * si difieren, así que se compara lo que salió en `connect` con lo que viaja en el POST.
     *
     * @return void
     */
    public function test_el_canje_manda_la_misma_redirect_uri_que_la_autorizacion()
    {
        $this->fakear_canje_de_token();

        $query = $this->connect_y_parsear();

        // Así vuelve del admin: el state ya sin el host.
        list($aleatorio) = explode('_', $query['state'], 2);

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$aleatorio)
             ->assertRedirect(self::SPA_REDIRECT.'?mp=ok');

        Http::assertSent(function (ClientRequest $request) use ($query) {
            return $request->url() === 'https://api.mercadopago.com/oauth/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['redirect_uri'] === $query['redirect_uri']
                && $request['redirect_uri'] === self::URL_CENTRAL;
        });
    }

    /**
     * El callback con el `state` limpio (el que reenvía el admin) conecta el comercio.
     *
     * @return void
     */
    public function test_el_callback_con_el_state_limpio_conecta()
    {
        $this->fakear_canje_de_token();

        $conector = $this->conector_sin_conectar();
        $state = OauthState::create_for_connector($conector);

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$state)
             ->assertRedirect(self::SPA_REDIRECT.'?mp=ok');

        $conector->refresh();

        $this->assertSame('APP_USR-token-flamante', $conector->access_token);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
    }

    /**
     * El callback con el `state` tal cual salió de `connect` (`<aleatorio>_<host>`) también
     * conecta: el sufijo se recorta antes de consumir. Y el state se consume igual: no se reusa.
     *
     * @return void
     */
    public function test_el_callback_con_el_state_con_sufijo_conecta_y_lo_consume()
    {
        $this->fakear_canje_de_token();

        $query = $this->connect_y_parsear();

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$query['state'])
             ->assertRedirect(self::SPA_REDIRECT.'?mp=ok');

        $conector = PlatformConnector::where('user_id', $this->user_id())
            ->where('status', PlatformConnector::STATUS_CONECTADO)
            ->first();

        $this->assertNotNull($conector, 'El callback con el state con sufijo no conectó el comercio.');
        $this->assertSame('APP_USR-token-flamante', $conector->access_token);

        // Replay con el mismo state con sufijo: ya está consumido.
        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$query['state'])
             ->assertRedirect(self::SPA_REDIRECT.'?mp=error');
    }

    /**
     * Un `state` con sufijo cuyo aleatorio no existe no conecta nada: el recorte no abre una
     * puerta, solo saca el host.
     *
     * @return void
     */
    public function test_un_state_con_sufijo_y_aleatorio_desconocido_no_conecta()
    {
        Http::fake();

        $falso = str_repeat('a', 64).'_'.self::HOST;

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$falso)
             ->assertRedirect(self::SPA_REDIRECT.'?mp=error');

        Http::assertNothingSent();
    }

    /**
     * 🔴 Con la URL central vacía (`MP_OAUTH_RELAY_URL=` en desarrollo local) todo sigue como
     * antes de la misión: `redirect_uri` legacy, `state` sin sufijo y canje con la legacy.
     *
     * @return void
     */
    public function test_con_el_relay_vacio_la_conducta_legacy_queda_intacta()
    {
        config(['services.mercadopago.oauth_relay_url' => '']);

        $this->fakear_canje_de_token();

        $query = $this->connect_y_parsear();

        $this->assertSame(self::URL_LEGACY, $query['redirect_uri']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $query['state'], 'Sin relay el state no lleva sufijo.');
        $this->assertNotNull(OauthState::where('state', $query['state'])->first());

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$query['state'])
             ->assertRedirect(self::SPA_REDIRECT.'?mp=ok');

        Http::assertSent(function (ClientRequest $request) {
            return $request->url() === 'https://api.mercadopago.com/oauth/token'
                && $request['redirect_uri'] === self::URL_LEGACY;
        });
    }

    /**
     * Sin relay y sin legacy no hay a dónde volver: `connect` responde 422 con el motivo, como
     * siempre que falta configuración.
     *
     * @return void
     */
    public function test_sin_relay_ni_legacy_el_connect_responde_422()
    {
        config([
            'services.mercadopago.oauth_relay_url'    => '',
            'services.mercadopago.oauth_redirect_uri' => '',
        ]);

        $this->getJson('/api/integraciones/mercadopago/connect')->assertStatus(422);
    }
}
