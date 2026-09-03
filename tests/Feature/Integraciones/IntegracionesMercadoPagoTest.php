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
     * las credenciales viejas de `online_configuration` y TAMBIÉN el espejo de
     * `payment_methods`.
     *
     * Lo último es lo que hace que el disconnect no mienta: antes limpiaba solo el conector, la
     * tarjeta pasaba a "Desconectado" y el comercio seguía cobrando por el fallback del helper.
     *
     * @return void
     */
    public function test_el_disconnect_limpia_el_conector_y_el_espejo_de_payment_methods()
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

        $this->assertNull(
            $payment_method->access_token,
            'El disconnect dejó la credencial en payment_methods: la tarjeta diría "Desconectado" '.
            'y el comercio seguiría cobrando igual por el fallback del helper.'
        );

        $this->assertNotNull(
            $payment_method->name,
            'El disconnect borró la fila entera de payment_methods; tiene que limpiar la credencial '.
            'y dejar la configuración comercial (nombre, descripción, descuento, cuotas).'
        );
    }

    /**
     * El comercio que cargó las claves A MANO y nunca conectó por OAuth no pierde nada cuando
     * alguien toca "Desconectar": no hay nada conectado que desconectar, y borrarle la credencial
     * lo dejaría sin poder cobrar por un botón que en su tarjeta ya decía "Desconectado".
     *
     * @return void
     */
    public function test_el_disconnect_no_toca_payment_methods_si_nunca_hubo_conector_conectado()
    {
        $payment_method = $this->payment_method_mp();

        $this->postJson('/api/integraciones/mercadopago/disconnect')->assertStatus(200);

        $payment_method->refresh();

        $this->assertSame(
            'TOKEN-DE-PAYMENT-METHOD',
            $payment_method->access_token,
            'El disconnect borró una credencial cargada a mano de un comercio que nunca conectó.'
        );
    }

    /**
     * 🔴 El callback ESPEJA la credencial en `payment_methods`.
     *
     * Sin esto, un comercio nuevo queda sin ninguna forma de cobrar durante la ventana de
     * despliegue: la pantalla nueva le sacó los campos para cargar las claves a mano, el OAuth
     * deja el token en el conector, y la `tienda-api` que todavía está arriba solo sabe leer
     * `payment_methods`.
     *
     * @return void
     */
    public function test_el_callback_espeja_la_credencial_en_payment_methods()
    {
        $this->fakear_canje_de_token();

        $conector = $this->conector_mp([
            'status'       => PlatformConnector::STATUS_SIN_CONECTAR,
            'access_token' => null,
        ]);

        // Comercio NUEVO: no tiene fila de payment_methods todavía.
        $type = PaymentMethodType::where('name', 'MercadoPago')->first();
        if ($type) {
            PaymentMethod::where('user_id', $this->user_id())
                ->where('payment_method_type_id', $type->id)
                ->delete();
        }

        $state = OauthState::create_for_connector($conector);

        $this->get(self::RUTA_CALLBACK.'?code=CODIGO&state='.$state)
             ->assertRedirect(self::SPA_REDIRECT.'?mp=ok');

        $credenciales = MercadoPagoCredentialsHelper::credentials($this->user_id());

        $this->assertSame('APP_USR-token-flamante', $credenciales['access_token']);

        $type = PaymentMethodType::where('name', 'MercadoPago')->firstOrFail();

        $payment_method = PaymentMethod::where('user_id', $this->user_id())
            ->where('payment_method_type_id', $type->id)
            ->first();

        $this->assertNotNull(
            $payment_method,
            'El OAuth no dejó la fila de payment_methods: un comercio nuevo no tendría con qué cobrar '.
            'hasta que se despliegue tienda-api.'
        );
        $this->assertSame('APP_USR-token-flamante', $payment_method->access_token);
        $this->assertSame('APP_USR-public-key-flamante', $payment_method->public_key);
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
     * 🔴 EL TEST QUE EVITA QUE VUELVA LA REGRESIÓN MÁS CARA DE ESTA MISIÓN.
     *
     * Editar un método de pago SIN mandar `access_token` no puede vaciar el token guardado.
     *
     * El par que hace esto necesario: `PaymentMethod::$hidden` sacó el token de la respuesta, así
     * que el formulario de la SPA —que arma el payload con `{...this.model}` sobre lo que devolvió
     * la API— ya no lo tiene para devolverlo en el PUT. Con la asignación incondicional que había
     * en `PaymentMethodController@update`, cambiarle el NOMBRE a un método de pago le borraba la
     * credencial de cobro al comercio, en producción, sin que nadie tocara nada de Mercado Pago.
     *
     * @return void
     */
    public function test_editar_un_metodo_de_pago_sin_mandar_el_token_no_lo_borra()
    {
        $payment_method = $this->payment_method_mp();

        // Exactamente lo que manda la SPA con el token ya oculto: los campos comerciales y nada
        // de credenciales.
        $respuesta = $this->putJson('/api/payment-method/'.$payment_method->id, [
            'name'                   => 'MercadoPago renombrado',
            'description'            => 'Otra descripción',
            'discount'               => 5,
            'payment_method_type_id' => $payment_method->payment_method_type_id,
        ]);

        $respuesta->assertStatus(200);

        $payment_method->refresh();

        $this->assertSame('MercadoPago renombrado', $payment_method->name, 'La edición no guardó el nombre.');
        $this->assertSame(
            'TOKEN-DE-PAYMENT-METHOD',
            $payment_method->access_token,
            'Editar el método de pago BORRÓ el access_token. Con el token oculto en las respuestas, '.
            'el request ya no lo trae: la asignación de PaymentMethodController@update tiene que ser '.
            'condicional o el comercio deja de cobrar al primer cambio de nombre.'
        );
        $this->assertSame(
            'PUBLIC-KEY-DE-PAYMENT-METHOD',
            $payment_method->public_key,
            'Editar el método de pago borró la public_key.'
        );
    }

    /**
     * Mandar el campo explícitamente vacío SÍ tiene que poder borrar la credencial: lo que no
     * puede es borrarse por omisión. (Por eso el controller usa `has()` y no `filled()`.)
     *
     * @return void
     */
    public function test_mandar_el_token_vacio_a_proposito_si_lo_borra()
    {
        $payment_method = $this->payment_method_mp();

        $this->putJson('/api/payment-method/'.$payment_method->id, [
            'name'                   => $payment_method->name,
            'payment_method_type_id' => $payment_method->payment_method_type_id,
            'access_token'           => null,
        ])->assertStatus(200);

        $payment_method->refresh();

        $this->assertNull($payment_method->access_token);
    }

    /**
     * 🔴 `GET /api/payment-method` no puede devolver el token de cobro del comercio.
     *
     * `tienda-api` ya ocultaba esta misma columna en su propio modelo; acá faltaba, y es el mismo
     * agujero que esta misión vino a cerrar, en el ABM que esta misión toca.
     *
     * @return void
     */
    public function test_el_listado_de_metodos_de_pago_no_serializa_el_token()
    {
        $this->payment_method_mp('SECRETO-DEL-METODO-DE-PAGO');

        $respuesta = $this->getJson('/api/payment-method');

        $respuesta->assertStatus(200);

        $cuerpo = $respuesta->getContent();

        $this->assertStringNotContainsString('SECRETO-DEL-METODO-DE-PAGO', $cuerpo, 'El listado de métodos de pago devolvió el access_token en claro.');
        $this->assertStringNotContainsString('access_token', $cuerpo, 'El listado de métodos de pago sigue serializando la clave access_token.');

        // La public key NO es secreta: la tienda la necesita para tokenizar la tarjeta.
        $this->assertStringContainsString('public_key', $cuerpo, 'Se ocultó también la public_key, que no es secreta y la tienda necesita.');
    }

    /**
     * Mercado Pago no se ofrece como plataforma del ABM de conectores: se conecta por su propia
     * pantalla. Si figurara, se podrían crear conectores con `auth_url` vacío — registros muertos
     * que el operador no entiende por qué no conectan.
     *
     * @return void
     */
    public function test_el_abm_de_conectores_no_ofrece_mercado_pago()
    {
        $this->plataforma_mp();

        $json = $this->getJson('/api/platform')->assertStatus(200)->json();

        $slugs = [];
        foreach ($json['models'] as $model) {
            $slugs[] = $model['slug'];
        }

        $this->assertNotContains(Platform::SLUG_MERCADO_PAGO, $slugs, 'El ABM de conectores ofrece Mercado Pago.');
        $this->assertContains(Platform::SLUG_MERCADO_LIBRE, $slugs, 'El filtro se llevó puesto a Mercado Libre.');
        $this->assertContains(Platform::SLUG_TIENDA_NUBE, $slugs, 'El filtro se llevó puesto a Tienda Nube.');
    }

    /**
     * Y tampoco se puede forzar por POST directo, que es lo que hace que el filtro sea una
     * validación y no solo una cosmética del listado.
     *
     * @return void
     */
    public function test_no_se_puede_crear_un_conector_de_mercado_pago_por_el_abm()
    {
        $this->postJson('/api/platform-connector', ['platform_id' => $this->plataforma_mp()->id])
             ->assertStatus(422);
    }

    /**
     * `GET /api/platform-connector` no serializa el `auth_code`. Es un code ya canjeado y de un
     * solo uso, pero es material de OAuth y no tiene por qué llegar al navegador.
     *
     * @return void
     */
    public function test_el_listado_de_conectores_no_serializa_el_auth_code()
    {
        $this->conector_mp(['auth_code' => 'CODE-DE-OAUTH-SECRETO']);

        $cuerpo = $this->getJson('/api/platform-connector')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('CODE-DE-OAUTH-SECRETO', $cuerpo);
        $this->assertStringNotContainsString('auth_code', $cuerpo);
    }

    /**
     * 🔴 Un `state` aleatorio YA CONSUMIDO no se puede resolver por el camino del formato viejo.
     *
     * `Str::random()` devuelve alfanumérico, así que un state que arranca con dígitos castea a
     * ese número: medido con el binario 7.4 sobre 10.000 states, 1429 (14%) dan un entero
     * distinto de cero, y 3, 4, 5 y 9 son ids reales de `platform_connectors`. Sin la guarda de
     * `ctype_digit()`, un replay caía en `PlatformConnector::find((int) $state)` y devolvía el
     * conector de OTRO comercio.
     *
     * @return void
     */
    public function test_un_state_aleatorio_consumido_no_resuelve_un_conector_por_id()
    {
        $conector = $this->conector_mp();

        $metodo = new ReflectionMethod(PlatformConnectorOAuthService::class, 'resolver_conector_del_state');
        $metodo->setAccessible(true);
        $service = new PlatformConnectorOAuthService();

        // Un state con la forma real del problema: arranca con el id de un conector existente y
        // sigue con letras, tal cual sale de Str::random() una de cada siete veces.
        $state_alfanumerico = $conector->id.'aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789aBcDeFgHiJkLmNoPqRsTuVwXy';

        $this->assertSame(
            $conector->id,
            (int) $state_alfanumerico,
            'El escenario del test dejó de reproducir el problema: este state ya no castea al id.'
        );

        $resuelto = $metodo->invoke($service, $state_alfanumerico);

        $this->assertNull(
            $resuelto,
            'Un state alfanumérico que no está en oauth_states resolvió un conector por el cast a '.
            'entero. Eso es exactamente el replay que la guarda de ctype_digit tiene que frenar.'
        );
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
