<?php

namespace Tests\Feature\Integraciones;

use App\Http\Controllers\Helpers\MercadoPagoCredentialsHelper;
use App\Models\OauthState;
use App\Models\OnlineConfiguration;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use App\Services\PlatformConnector\PlatformConnectorOAuthService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Misión "ABM -> Integraciones" (3/9/2026), parte de `empresa-api`.
 *
 * Lo que fija este archivo es el motivo de fondo de toda la misión y las dos compatibilidades
 * que no se pueden romper mientras `tienda-api` no se despliegue:
 *
 * 1. Las credenciales de cobro salen de `online_configuration` — la fila que `tienda-api`
 *    publica SIN AUTENTICACIÓN en `CommerceController@commerce` — y pasan a
 *    `platform_connectors`, cifradas.
 * 2. Nada se borra: la migración COPIA. `payment_methods.access_token`, que es lo único con lo
 *    que la tienda cobra HOY, queda intacto, y el helper lo sigue usando como fallback.
 * 3. El `state` del OAuth genérico se generaliza, pero el callback tiene que seguir aceptando
 *    el formato viejo (id del conector), que es el que Mercado Libre y Tienda Nube mandan en
 *    producción ahora mismo.
 *
 * @group integraciones
 */
class IntegracionesMercadoPagoTest extends EmpresaTestCase
{
    /** Ruta del listado de integraciones que consume ABM -> Integraciones. */
    const RUTA_LISTADO = '/api/integraciones';

    /** Callback público al que vuelve el navegador del comercio desde Mercado Pago. */
    const RUTA_CALLBACK = '/api/integraciones/mercadopago/callback';

    /** URL del SPA a la que redirige el callback, para poder afirmar sobre el redirect. */
    const SPA_REDIRECT = 'http://localhost:8080/abm/integraciones';

    /**
     * Deja configuradas las credenciales de la APP de ComercioCity (las del .env de producción)
     * para que el flujo OAuth pueda correr sin depender de qué tenga el `.env.testing` del slot.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.app_id'             => 'app-id-de-prueba',
            'services.mercadopago.app_secret'         => 'app-secret-de-prueba',
            'services.mercadopago.oauth_redirect_uri' => 'http://localhost/api/integraciones/mercadopago/callback',
            'services.mercadopago.spa_redirect_url'   => self::SPA_REDIRECT,
        ]);
    }

    /**
     * Id del comercio del fixture, que es el que queda autenticado en `EmpresaTestCase::setUp()`.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Fila `mercado_pago` de `platforms`, creándola si el fixture no la tiene.
     *
     * @return Platform
     */
    protected function plataforma_mp()
    {
        $platform = Platform::where('slug', Platform::SLUG_MERCADO_PAGO)->first();

        if ($platform) {
            return $platform;
        }

        return Platform::create([
            'slug'          => Platform::SLUG_MERCADO_PAGO,
            'name'          => 'Mercado Pago',
            'client_id'     => 'app-id-de-prueba',
            'client_secret' => 'app-secret-de-prueba',
            'extra_config'  => null,
        ]);
    }

    /**
     * Crea el conector de Mercado Pago del comercio del fixture con los atributos indicados.
     *
     * @param array<string, mixed> $atributos
     * @return PlatformConnector
     */
    protected function conector_mp(array $atributos = [])
    {
        $base = [
            'user_id'          => $this->user_id(),
            'platform_id'      => $this->plataforma_mp()->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => 'TOKEN-DEL-CONECTOR',
            'refresh_token'    => 'REFRESH-DEL-CONECTOR',
            'public_key'       => 'PUBLIC-KEY-DEL-CONECTOR',
            'platform_user_id' => '123456789',
            'expires_at'       => Carbon::now()->addDays(90),
        ];

        return PlatformConnector::create(array_merge($base, $atributos));
    }

    /**
     * Crea (o completa) la fila de `payment_methods` del tipo MercadoPago del comercio, que es
     * la credencial vieja: la que `tienda-api` usa HOY para cobrar.
     *
     * @param string|null $access_token
     * @param string|null $public_key
     * @return PaymentMethod
     */
    protected function payment_method_mp($access_token = 'TOKEN-DE-PAYMENT-METHOD', $public_key = 'PUBLIC-KEY-DE-PAYMENT-METHOD')
    {
        $type = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$type) {
            $type = PaymentMethodType::create(['name' => 'MercadoPago']);
        }

        return PaymentMethod::create([
            'name'                   => 'MercadoPago',
            'description'            => 'Pago online',
            'payment_method_type_id' => $type->id,
            'public_key'             => $public_key,
            'access_token'           => $access_token,
            'user_id'                => $this->user_id(),
        ]);
    }

    /**
     * `online_configuration` del comercio del fixture (el seeder de testing deja una).
     *
     * @return OnlineConfiguration
     */
    protected function online_configuration()
    {
        return OnlineConfiguration::where('user_id', $this->user_id())
            ->orderBy('created_at', 'DESC')
            ->firstOrFail();
    }

    /**
     * Respuesta que Mercado Pago devuelve al canjear el `code`, ya fakeada.
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
     * `connect` devuelve la URL de autorización con un `state` ALEATORIO ya persistido y atado
     * al conector de Mercado Pago del comercio — no el id del conector, que es lo que manda el
     * OAuth genérico y es predecible, reusable y sin vencimiento.
     *
     * @return void
     */
    public function test_el_connect_arma_la_url_con_un_state_aleatorio_atado_al_conector()
    {
        $respuesta = $this->getJson('/api/integraciones/mercadopago/connect');

        $respuesta->assertStatus(200);

        $url = $respuesta->json('url');

        $this->assertStringStartsWith('https://auth.mercadopago.com.ar/authorization?', $url);
        $this->assertStringContainsString('client_id=app-id-de-prueba', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('state', $query);

        $guardado = OauthState::where('state', $query['state'])->first();

        $this->assertNotNull($guardado, 'El state de la URL no quedó persistido en oauth_states.');
        $this->assertNotNull(
            $guardado->platform_connector_id,
            'El state se generó sin atarlo al conector: el callback no va a poder resolverlo.'
        );
        $this->assertSame($this->user_id(), (int) $guardado->user_id);

        $conector = PlatformConnector::find($guardado->platform_connector_id);

        $this->assertNotNull($conector, 'El connect no dejó creado el conector de Mercado Pago.');
        $this->assertNotSame(
            (string) $conector->id,
            (string) $query['state'],
            'El state es el id del conector: eso es predecible y reusable, justo lo que esta misión sacó.'
        );
        $this->assertTrue($guardado->expires_at->isFuture(), 'El state nació vencido.');
        $this->assertNull($guardado->used_at);
    }

    /**
     * El callback del OAuth guarda los tokens en el conector y NO en el `online_configuration`.
     *
     * Es el cambio central de la misión: `online_configuration` viaja entera en una respuesta
     * pública sin autenticación de `tienda-api`, así que las credenciales de cobro no van más
     * ahí.
     *
     * @return void
     */
    public function test_el_callback_guarda_en_el_conector_y_no_en_el_online_configuration()
    {
        $this->fakear_canje_de_token();

        $conector = $this->conector_mp([
            'status'           => PlatformConnector::STATUS_SIN_CONECTAR,
            'access_token'     => null,
            'refresh_token'    => null,
            'public_key'       => null,
            'platform_user_id' => null,
            'expires_at'       => null,
        ]);

        $configuracion = $this->online_configuration();
        $configuracion->mp_access_token = null;
        $configuracion->mp_enabled = false;
        $configuracion->save();

        $state = OauthState::create_for_connector($conector);

        $respuesta = $this->get(self::RUTA_CALLBACK.'?code=CODIGO-DE-AUTORIZACION&state='.$state);

        $respuesta->assertRedirect(self::SPA_REDIRECT.'?mp=ok');

        $conector->refresh();

        $this->assertSame(
            'APP_USR-token-flamante',
            $conector->access_token,
            'El callback no guardó el access_token en el platform_connector.'
        );
        $this->assertSame('TG-refresh-flamante', $conector->refresh_token);
        $this->assertSame('987654321', $conector->platform_user_id);
        $this->assertSame('APP_USR-public-key-flamante', $conector->public_key);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected(), 'El conector quedó guardado pero no cuenta como conectado.');

        $configuracion->refresh();

        $this->assertNull(
            $configuracion->getAttributes()['mp_access_token'],
            'El callback siguió escribiendo el token en online_configuration, que es justamente la fila '.
            'que tienda-api publica sin autenticación.'
        );
    }

    /**
     * El token del conector queda CIFRADO en la base, no en texto plano.
     *
     * `platform_connectors` guardaba los tokens de ML y TN en plano; mudar Mercado Pago ahí sin
     * cifrarlos habría sido un retroceso frente a `online_configuration`, que sí los ciframa.
     *
     * @return void
     */
    public function test_el_token_del_conector_queda_cifrado_en_la_base()
    {
        $this->fakear_canje_de_token();

        $conector = $this->conector_mp([
            'status'       => PlatformConnector::STATUS_SIN_CONECTAR,
            'access_token' => null,
        ]);

        $state = OauthState::create_for_connector($conector);

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO-DE-AUTORIZACION&state='.$state);

        $crudo = DB::table('platform_connectors')->where('id', $conector->id)->value('access_token');

        $this->assertNotSame(
            'APP_USR-token-flamante',
            $crudo,
            'El access_token quedó en TEXTO PLANO en platform_connectors.'
        );
        $this->assertStringNotContainsString(
            'APP_USR-token-flamante',
            (string) $crudo,
            'El valor guardado contiene el token en claro.'
        );
    }

    /**
     * `disconnect` conserva el contrato (200 con la clave `model`), limpia el conector, limpia
     * las credenciales viejas que le quedaran en `online_configuration` y NO toca
     * `payment_methods`, que es con lo que la tienda cobra hoy.
     *
     * @return void
     */
    public function test_el_disconnect_limpia_el_conector_y_no_toca_payment_methods()
    {
        $conector = $this->conector_mp();
        $payment_method = $this->payment_method_mp();

        $configuracion = $this->online_configuration();
        $configuracion->mp_access_token = 'TOKEN-VIEJO';
        $configuracion->mp_enabled = true;
        $configuracion->save();

        $respuesta = $this->postJson('/api/integraciones/mercadopago/disconnect');

        $respuesta->assertStatus(200);
        $this->assertArrayHasKey('model', $respuesta->json(), 'disconnect perdió la clave "model" del contrato.');

        $conector->refresh();

        $this->assertNull($conector->access_token, 'El disconnect no limpió el token del conector.');
        $this->assertSame(PlatformConnector::STATUS_SIN_CONECTAR, $conector->status);
        $this->assertFalse($conector->is_connected());

        $configuracion->refresh();

        $this->assertNull($configuracion->getAttributes()['mp_access_token']);
        $this->assertFalse((bool) $configuracion->mp_enabled);

        $payment_method->refresh();

        $this->assertSame(
            'TOKEN-DE-PAYMENT-METHOD',
            $payment_method->access_token,
            'El disconnect borró payment_methods.access_token: eso deja al comercio sin poder cobrar '.
            'mientras tienda-api no esté desplegada con el orden nuevo.'
        );
    }

    /**
     * Un `state` aleatorio se consume UNA sola vez: el segundo intento con el mismo valor no
     * conecta nada (replay).
     *
     * @return void
     */
    public function test_el_state_no_se_puede_usar_dos_veces()
    {
        $this->fakear_canje_de_token();

        $conector = $this->conector_mp([
            'status'       => PlatformConnector::STATUS_SIN_CONECTAR,
            'access_token' => null,
        ]);

        $state = OauthState::create_for_connector($conector);

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$state)
             ->assertRedirect(self::SPA_REDIRECT.'?mp=ok');

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$state)
             ->assertRedirect(self::SPA_REDIRECT.'?mp=error');
    }

    /**
     * El callback del OAuth GENÉRICO (Mercado Libre / Tienda Nube) sigue resolviendo el conector
     * cuando el `state` es el id del conector, que es el formato que esos dos mandan HOY en
     * producción — y también resuelve el formato nuevo, aleatorio.
     *
     * Si esto se rompiera, cualquier ventana de autorización de ML abierta en el momento del
     * deploy fallaría sin que nada lo avise.
     *
     * @return void
     */
    public function test_el_callback_generico_acepta_los_dos_formatos_de_state()
    {
        $conector = $this->conector_mp();

        $metodo = new ReflectionMethod(PlatformConnectorOAuthService::class, 'resolver_conector_del_state');
        $metodo->setAccessible(true);

        $service = new PlatformConnectorOAuthService();

        $por_id = $metodo->invoke($service, (string) $conector->id);

        $this->assertNotNull($por_id, 'El callback dejó de aceptar el id del conector como state.');
        $this->assertSame($conector->id, $por_id->id);

        $state = OauthState::create_for_connector($conector);
        $por_state = $metodo->invoke($service, $state);

        $this->assertNotNull($por_state, 'El callback no resolvió el conector desde el state aleatorio.');
        $this->assertSame($conector->id, $por_state->id);
    }

    /**
     * El helper prefiere el conector conectado por sobre `payment_methods`.
     *
     * @return void
     */
    public function test_el_helper_prefiere_el_conector_conectado()
    {
        $this->payment_method_mp();
        $this->conector_mp();

        $credenciales = MercadoPagoCredentialsHelper::credentials($this->user_id());

        $this->assertSame('TOKEN-DEL-CONECTOR', $credenciales['access_token']);
        $this->assertSame('PUBLIC-KEY-DEL-CONECTOR', $credenciales['public_key']);
        $this->assertSame('platform_connector', $credenciales['origen']);
    }

    /**
     * Sin conector, el helper cae a `payment_methods`: es exactamente lo que hace que un
     * comercio que nunca conectó por OAuth siga cobrando igual que antes de esta misión.
     *
     * @return void
     */
    public function test_el_helper_cae_a_payment_methods_cuando_no_hay_conector()
    {
        $this->payment_method_mp();

        $credenciales = MercadoPagoCredentialsHelper::credentials($this->user_id());

        $this->assertSame('TOKEN-DE-PAYMENT-METHOD', $credenciales['access_token']);
        $this->assertSame('PUBLIC-KEY-DE-PAYMENT-METHOD', $credenciales['public_key']);
        $this->assertSame('payment_method', $credenciales['origen']);
    }

    /**
     * Un conector con el token vencido no vale: el helper cae a `payment_methods` igual que si
     * no existiera. Mismo criterio que `getMpConnectedAttribute()`.
     *
     * @return void
     */
    public function test_el_helper_ignora_un_conector_vencido()
    {
        $this->payment_method_mp();
        $this->conector_mp(['expires_at' => Carbon::now()->subDay()]);

        $credenciales = MercadoPagoCredentialsHelper::credentials($this->user_id());

        $this->assertSame('TOKEN-DE-PAYMENT-METHOD', $credenciales['access_token']);
        $this->assertSame('payment_method', $credenciales['origen']);
    }

    /**
     * Sin conector y sin `payment_methods`, el helper devuelve nulls y no explota: no poder
     * cobrar es un estado posible del sistema, no un error del programa.
     *
     * @return void
     */
    public function test_el_helper_devuelve_nulls_cuando_no_hay_con_que_cobrar()
    {
        $credenciales = MercadoPagoCredentialsHelper::credentials($this->user_id());

        $this->assertNull($credenciales['access_token']);
        $this->assertNull($credenciales['public_key']);
        $this->assertNull($credenciales['origen']);
    }

    /**
     * 🔴 El endpoint del listado NO serializa ningún token. Es el chequeo que da sentido a toda
     * la misión: se afirma sobre el CUERPO CRUDO de la respuesta, no sobre el array parseado,
     * para que un token colado en cualquier nivel de anidamiento también lo haga fallar.
     *
     * @return void
     */
    public function test_el_listado_no_serializa_ningun_token()
    {
        $this->conector_mp([
            'access_token'  => 'SECRETO-ACCESS-TOKEN',
            'refresh_token' => 'SECRETO-REFRESH-TOKEN',
        ]);
        $this->payment_method_mp('SECRETO-DE-PAYMENT-METHOD');

        $respuesta = $this->getJson(self::RUTA_LISTADO);

        $respuesta->assertStatus(200);

        $cuerpo = $respuesta->getContent();

        foreach (['SECRETO-ACCESS-TOKEN', 'SECRETO-REFRESH-TOKEN', 'SECRETO-DE-PAYMENT-METHOD', 'access_token', 'refresh_token', 'client_secret'] as $prohibido) {
            $this->assertStringNotContainsString(
                $prohibido,
                $cuerpo,
                'La respuesta de '.self::RUTA_LISTADO.' contiene "'.$prohibido.'". Ese endpoint no puede '.
                'exponer credenciales: sacarlas de una respuesta serializada es el motivo de esta misión.'
            );
        }
    }

    /**
     * El listado declara las cuatro integraciones con su grupo y su estado, y solo las seis
     * claves del contrato que la SPA consume.
     *
     * @return void
     */
    public function test_el_listado_declara_grupos_y_estado()
    {
        // `startOfSecond()` porque la columna es un `timestamp` de MySQL y no guarda
        // microsegundos: sin esto el test compararía contra una precisión que la base descarta.
        $vence = Carbon::now()->addDays(90)->startOfSecond();
        $this->conector_mp(['expires_at' => $vence, 'platform_user_id' => '123456789']);

        $respuesta = $this->getJson(self::RUTA_LISTADO);

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertArrayHasKey('integraciones', $json, 'La respuesta perdió la clave "integraciones".');

        $por_slug = [];
        foreach ($json['integraciones'] as $integracion) {
            $this->assertSame(
                ['slug', 'name', 'grupo', 'connected', 'expires_at', 'platform_user_id'],
                array_keys($integracion),
                'Cambió la forma de una integración del listado; el SPA consume estas seis claves.'
            );
            $por_slug[$integracion['slug']] = $integracion;
        }

        foreach (['mercado_libre', 'tienda_nube', 'mercado_pago', 'zippin'] as $slug) {
            $this->assertArrayHasKey($slug, $por_slug, 'Falta la integración "'.$slug.'" en el listado.');
        }

        $this->assertSame('sistema', $por_slug['mercado_libre']['grupo']);
        $this->assertSame('sistema', $por_slug['tienda_nube']['grupo']);
        $this->assertSame('tienda_online', $por_slug['mercado_pago']['grupo']);
        $this->assertSame('tienda_online', $por_slug['zippin']['grupo']);

        $this->assertTrue($por_slug['mercado_pago']['connected'], 'El conector conectado no se reflejó en el listado.');
        $this->assertSame($vence->toJSON(), $por_slug['mercado_pago']['expires_at']);
        $this->assertSame('123456789', $por_slug['mercado_pago']['platform_user_id']);

        $this->assertFalse($por_slug['mercado_libre']['connected'], 'Una integración sin conector no puede figurar conectada.');
        $this->assertNull($por_slug['mercado_libre']['expires_at']);
    }

    /**
     * Un conector cuyo token venció figura DESCONECTADO en el listado, aunque tenga token.
     *
     * @return void
     */
    public function test_el_listado_no_da_por_conectado_un_token_vencido()
    {
        $this->conector_mp(['expires_at' => Carbon::now()->subDay()]);

        $json = $this->getJson(self::RUTA_LISTADO)->json();

        $por_slug = [];
        foreach ($json['integraciones'] as $integracion) {
            $por_slug[$integracion['slug']] = $integracion;
        }

        $this->assertFalse($por_slug['mercado_pago']['connected']);
    }
}
