<?php

namespace Tests\Feature\Integraciones;

use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * Misión zipnova-envios (14/9/2026), parte de `empresa-api`: la conexión del comercio con
 * Zipnova desde ABM -> Integraciones -> Tienda online.
 *
 * Lo que fija este archivo:
 *
 * 1. Conectar prueba las credenciales contra Zipnova ANTES de guardarlas, las persiste como
 *    `Basic base64(token:secret)` cifrado en `platform_connectors.access_token`, resuelve la
 *    cuenta y los depósitos, y registra el webhook de estado apuntando a esta instancia.
 * 2. Credenciales rechazadas no dejan nada en la base.
 * 3. `GET /api/integraciones` muestra a Zipnova conectado con su `config`, y NADA de lo que
 *    viaja al navegador contiene el token ni el secret: es el mismo motivo que sacó las
 *    credenciales de `online_configuration` el 3/9/2026.
 * 4. Guardar la config valida y persiste; desconectar limpia; la cotización de prueba
 *    normaliza una respuesta real de Zipnova y la ordena por precio.
 *
 * Todo con `Http::fake()`: nada sale a la red.
 *
 * @group integraciones
 */
class IntegracionesZipnovaTest extends EmpresaTestCase
{
    /** Listado que consume ABM -> Integraciones. */
    const RUTA_LISTADO = '/api/integraciones';

    /** Host de la API de Zipnova, fijado por config para que los fakes matcheen siempre. */
    const BASE_URL = 'https://api.zipnova.com.ar/v2';

    /** Credenciales que "pega" el comercio. */
    const TOKEN = 'tok-de-prueba-1234567890';
    const SECRET = 'sec-de-prueba-0987654321';

    /** Cuenta y depósito que devuelve el Zipnova fakeado. */
    const ACCOUNT_ID = 3355;
    const ORIGIN_ID = 9323;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zipnova.base_url' => self::BASE_URL]);
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
     * Fila `zipnova` de `platforms`. La migración `asegurar_plataforma_zipnova` la deja; se
     * crea igual por si la base de testing se armó sin ella.
     *
     * @return Platform
     */
    protected function plataforma_zipnova()
    {
        return Platform::firstOrCreate(
            ['slug' => Platform::SLUG_ZIPNOVA],
            ['name' => 'Zipnova', 'client_id' => null, 'client_secret' => null, 'extra_config' => null]
        );
    }

    /**
     * `extra_config` de un conector ya conectado, con la forma de §2.3 del plan.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    protected function extra_config_base(array $cambios = [])
    {
        return array_merge([
            'account_name'       => 'Mi negocio SRL',
            'accounts'           => [['id' => self::ACCOUNT_ID, 'name' => 'Mi negocio SRL']],
            'origin_id'          => self::ORIGIN_ID,
            'origin_label'       => 'Depósito Central · Av. Colón 123, Córdoba (5000)',
            'origins'            => [['id' => self::ORIGIN_ID, 'label' => 'Depósito Central · Av. Colón 123, Córdoba (5000)']],
            'bulto_default'      => ['peso' => 0.5, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10],
            'declarar_valor'     => true,
            'envio_gratis_desde' => null,
            'webhook_id'         => 777,
            'webhook_url'        => $this->url_del_webhook(),
            'conectado_en'       => '2026-09-14T15:00:00-03:00',
        ], $cambios);
    }

    /**
     * Crea el conector de Zipnova del comercio del fixture, ya conectado.
     *
     * @param array<string, mixed> $atributos
     * @return PlatformConnector
     */
    protected function conector_zipnova(array $atributos = [])
    {
        $base = [
            'user_id'          => $this->user_id(),
            'platform_id'      => $this->plataforma_zipnova()->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => base64_encode(self::TOKEN . ':' . self::SECRET),
            'refresh_token'    => null,
            'platform_user_id' => (string) self::ACCOUNT_ID,
            'expires_at'       => null,
            'extra_config'     => $this->extra_config_base(),
        ];

        return PlatformConnector::create(array_merge($base, $atributos));
    }

    /**
     * URL del webhook que `conectar` tiene que registrar: el root con el que el test pega
     * (`config('app.url')`, que es lo que `MakesHttpRequests` usa como base) más la ruta pública.
     *
     * @return string
     */
    protected function url_del_webhook()
    {
        return rtrim((string) config('app.url'), '/') . '/api/zipnova/webhook';
    }

    /**
     * Respuestas de Zipnova para un conectar exitoso: la cuenta, dos depósitos (uno no
     * habilitado para despachar) y el alta del webhook.
     *
     * @return void
     */
    protected function fakear_conectar_ok()
    {
        Http::fake([
            self::BASE_URL . '/accounts' => Http::response(['data' => [
                ['id' => self::ACCOUNT_ID, 'name' => 'Mi negocio SRL', 'company_name' => 'Mi negocio S.R.L.', 'email' => 'hola@minegocio.com'],
            ]], 200),
            self::BASE_URL . '/addresses*' => Http::response(['data' => [
                ['id' => self::ORIGIN_ID, 'name' => 'Depósito Central', 'street' => 'Av. Colón', 'street_number' => '123', 'city' => ['name' => 'Córdoba'], 'state' => ['name' => 'Córdoba'], 'zipcode' => '5000', 'use_for_shipping' => true],
                ['id' => 9324, 'name' => 'Oficina', 'street' => 'San Martín', 'street_number' => '50', 'city' => ['name' => 'Córdoba'], 'state' => ['name' => 'Córdoba'], 'zipcode' => '5000', 'use_for_shipping' => false],
            ]], 200),
            self::BASE_URL . '/accounts/' . self::ACCOUNT_ID . '/webhooks' => Http::response([
                'id' => 777, 'account_id' => self::ACCOUNT_ID, 'topic' => 'status', 'url' => $this->url_del_webhook(),
            ], 201),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);
    }

    /**
     * Fixture real de `POST /shipments/quote`: dos correos, uno de ellos con una opción
     * `selectable = false`, y un `pickup_point` con dos sucursales.
     *
     * @return array
     */
    protected function fixture_cotizacion()
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/zipnova_quote.json'), true);
    }

    /**
     * El item de Zipnova del listado.
     *
     * @return array<string, mixed>
     */
    protected function item_zipnova_del_listado()
    {
        $json = $this->getJson(self::RUTA_LISTADO)->assertStatus(200)->json();

        foreach ($json['integraciones'] as $integracion) {
            if ($integracion['slug'] === Platform::SLUG_ZIPNOVA) {
                return $integracion;
            }
        }

        $this->fail('El listado de integraciones no tiene la entrada "zipnova".');
    }

    /**
     * Claves (a cualquier nivel) que contienen alguna de las palabras prohibidas.
     *
     * @param mixed $valor
     * @param array<int, string> $prohibidas
     * @param string $camino
     * @return array<int, string>
     */
    protected function claves_prohibidas($valor, array $prohibidas, $camino = '')
    {
        $encontradas = [];

        if (!is_array($valor)) {
            return $encontradas;
        }

        foreach ($valor as $clave => $hijo) {
            $ruta = $camino === '' ? (string) $clave : $camino . '.' . $clave;
            foreach ($prohibidas as $prohibida) {
                if (is_string($clave) && stripos($clave, $prohibida) !== false) {
                    $encontradas[] = $ruta;
                }
            }
            $encontradas = array_merge($encontradas, $this->claves_prohibidas($hijo, $prohibidas, $ruta));
        }

        return $encontradas;
    }

    /**
     * Conectar guarda el conector con el Basic correcto (cifrado), la cuenta, los depósitos
     * habilitados y el webhook apuntando a esta instancia; y responde la tarjeta ya conectada.
     *
     * @return void
     */
    public function test_conectar_guarda_el_conector_y_registra_el_webhook()
    {
        $this->fakear_conectar_ok();

        $respuesta = $this->postJson('/api/integraciones/zipnova/conectar', [
            'api_token'  => self::TOKEN,
            'api_secret' => self::SECRET,
        ]);

        $respuesta->assertStatus(200);

        $integracion = $respuesta->json('integracion');

        $this->assertSame(Platform::SLUG_ZIPNOVA, $integracion['slug']);
        $this->assertTrue($integracion['connected'], 'Conectar no dejó la tarjeta como conectada.');
        $this->assertSame((string) self::ACCOUNT_ID, $integracion['platform_user_id']);
        $this->assertNull($integracion['expires_at'], 'Una credencial Basic no vence: expires_at tiene que ser null.');
        $this->assertSame('Mi negocio SRL', $integracion['config']['account_name']);
        $this->assertSame(self::ORIGIN_ID, $integracion['config']['origin_id'], 'No se eligió el primer depósito habilitado como origen.');
        $this->assertCount(1, $integracion['config']['origins'], 'El depósito con use_for_shipping=false no tiene que aparecer.');
        $this->assertStringContainsString('Depósito Central', $integracion['config']['origin_label']);
        $this->assertTrue($integracion['config']['webhook_registrado'], 'El webhook no quedó registrado.');
        $this->assertSame(0.5, (float) $integracion['config']['bulto_default']['peso']);
        $this->assertTrue($integracion['config']['declarar_valor']);

        $conector = PlatformConnector::find_for_user_and_slug($this->user_id(), Platform::SLUG_ZIPNOVA);

        $this->assertNotNull($conector, 'Conectar no creó el conector de Zipnova.');
        $this->assertSame(
            base64_encode(self::TOKEN . ':' . self::SECRET),
            $conector->access_token,
            'El access_token no es el Basic base64(token:secret) que va en el header.'
        );
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected());
        $this->assertNull($conector->refresh_token);
        $this->assertNull($conector->expires_at);
        $this->assertSame(777, $conector->extra_config['webhook_id']);
        $this->assertSame($this->url_del_webhook(), $conector->extra_config['webhook_url']);
        $this->assertSame([['id' => self::ORIGIN_ID, 'label' => 'Depósito Central · Av. Colón 123, Córdoba (5000)']], $conector->extra_config['origins']);
        $this->assertNotEmpty($conector->extra_config['conectado_en']);

        $crudo = DB::table('platform_connectors')->where('id', $conector->id)->value('access_token');

        $this->assertStringNotContainsString(self::TOKEN, (string) $crudo, 'El token quedó en claro en la base.');
        $this->assertStringNotContainsString(base64_encode(self::TOKEN . ':' . self::SECRET), (string) $crudo, 'El Basic quedó sin cifrar en la base.');

        $basic_esperado = 'Basic ' . base64_encode(self::TOKEN . ':' . self::SECRET);

        Http::assertSent(function ($request) use ($basic_esperado) {
            return $request->method() === 'GET'
                && $request->url() === self::BASE_URL . '/accounts'
                && $request->hasHeader('Authorization', $basic_esperado);
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/accounts/' . self::ACCOUNT_ID . '/webhooks'
                && $request['topic'] === 'status'
                && $request['url'] === $this->url_del_webhook();
        });
    }

    /**
     * Credenciales que Zipnova rechaza (401) dan 422 con el mensaje para el operador y NO
     * dejan conector: un token mal copiado nunca llega a la base.
     *
     * @return void
     */
    public function test_credenciales_rechazadas_dan_422_y_no_crean_conector()
    {
        Http::fake([
            self::BASE_URL . '/accounts' => Http::response(['message' => 'Unauthenticated.'], 401),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $respuesta = $this->postJson('/api/integraciones/zipnova/conectar', [
            'api_token'  => 'token-equivocado-123',
            'api_secret' => 'secret-equivocado-456',
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no reconoció el token o el secret', $respuesta->json('message'));

        $this->assertNull(
            PlatformConnector::find_for_user_and_slug($this->user_id(), Platform::SLUG_ZIPNOVA),
            'Un conectar rechazado dejó un conector en la base.'
        );

        $this->assertStringNotContainsString('token-equivocado-123', $respuesta->getContent());
    }

    /**
     * Sin token o con uno de dos letras no se llama a Zipnova: 422 de validación.
     *
     * @return void
     */
    public function test_conectar_sin_credenciales_no_llama_a_zipnova()
    {
        Http::fake();

        $this->postJson('/api/integraciones/zipnova/conectar', ['api_token' => 'ab', 'api_secret' => ''])
             ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * Zipnova responde pero sin ninguna cuenta: 422 y sin conector.
     *
     * @return void
     */
    public function test_credenciales_sin_cuenta_dan_422()
    {
        Http::fake([
            self::BASE_URL . '/accounts' => Http::response(['data' => []], 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $respuesta = $this->postJson('/api/integraciones/zipnova/conectar', [
            'api_token'  => self::TOKEN,
            'api_secret' => self::SECRET,
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ninguna cuenta', $respuesta->json('message'));
        $this->assertNull(PlatformConnector::find_for_user_and_slug($this->user_id(), Platform::SLUG_ZIPNOVA));
    }

    /**
     * El listado muestra a Zipnova conectado, en la solapa de la tienda, con `config`, y sin
     * ninguna clave ni valor que huela a credencial. `zippin` ya no está en el catálogo.
     *
     * @return void
     */
    public function test_el_listado_muestra_zipnova_conectado_con_config_y_sin_ningun_token()
    {
        $this->conector_zipnova();

        $respuesta = $this->getJson(self::RUTA_LISTADO);

        $respuesta->assertStatus(200);

        $cuerpo = $respuesta->getContent();

        foreach ([self::TOKEN, self::SECRET, base64_encode(self::TOKEN . ':' . self::SECRET), 'access_token', 'refresh_token', 'client_secret'] as $prohibido) {
            $this->assertStringNotContainsString(
                $prohibido,
                $cuerpo,
                'La respuesta de ' . self::RUTA_LISTADO . ' contiene "' . $prohibido . '": ese endpoint no puede exponer credenciales.'
            );
        }

        $por_slug = [];
        foreach ($respuesta->json('integraciones') as $integracion) {
            $por_slug[$integracion['slug']] = $integracion;
        }

        $this->assertArrayHasKey(Platform::SLUG_ZIPNOVA, $por_slug, 'Falta "zipnova" en el listado.');
        $this->assertArrayNotHasKey('zippin', $por_slug, '"zippin" sigue en el catálogo: la tarjeta vieja se reemplazó por la de Zipnova.');

        $zipnova = $por_slug[Platform::SLUG_ZIPNOVA];

        $this->assertSame('Zipnova', $zipnova['name']);
        $this->assertSame('tienda_online', $zipnova['grupo']);
        $this->assertTrue($zipnova['connected']);
        $this->assertSame((string) self::ACCOUNT_ID, $zipnova['platform_user_id']);
        $this->assertNull($zipnova['expires_at']);

        $this->assertArrayHasKey('config', $zipnova, 'El item de Zipnova conectado tiene que traer "config".');
        $this->assertSame(
            ['account_name', 'accounts', 'origin_id', 'origin_label', 'origins', 'bulto_default', 'declarar_valor', 'envio_gratis_desde', 'webhook_registrado', 'conectado_en'],
            array_keys($zipnova['config']),
            'Cambió la forma de "config"; la tarjeta consume estas diez claves.'
        );
        $this->assertTrue($zipnova['config']['webhook_registrado']);

        $this->assertSame(
            [],
            $this->claves_prohibidas($zipnova, ['token', 'secret']),
            'El item de Zipnova tiene claves que contienen "token" o "secret".'
        );

        // Los otros tres siguen con las seis claves del contrato original.
        foreach (['mercado_libre', 'tienda_nube', 'mercado_pago'] as $slug) {
            $this->assertSame(
                ['slug', 'name', 'grupo', 'connected', 'expires_at', 'platform_user_id'],
                array_keys($por_slug[$slug]),
                'Cambió la forma de "' . $slug . '" en el listado.'
            );
        }
    }

    /**
     * Un comercio sin conector ve a Zipnova desconectado, sin `config`.
     *
     * @return void
     */
    public function test_el_listado_muestra_zipnova_desconectado_sin_config()
    {
        $zipnova = $this->item_zipnova_del_listado();

        $this->assertFalse($zipnova['connected']);
        $this->assertNull($zipnova['platform_user_id']);
        $this->assertArrayNotHasKey('config', $zipnova, 'Un comercio desconectado no tiene config que mostrar.');
    }

    /**
     * `PUT config` valida los límites y persiste en `extra_config`, recalculando la etiqueta
     * del depósito y devolviendo la tarjeta actualizada.
     *
     * @return void
     */
    public function test_put_config_valida_y_persiste()
    {
        Http::fake();

        $conector = $this->conector_zipnova([
            'extra_config' => $this->extra_config_base([
                'origin_id'    => null,
                'origin_label' => null,
                'origins'      => [
                    ['id' => self::ORIGIN_ID, 'label' => 'Depósito Central · Av. Colón 123, Córdoba (5000)'],
                    ['id' => 9330, 'label' => 'Sucursal Norte · Ruta 9 km 5, Córdoba (5000)'],
                ],
            ]),
        ]);

        $respuesta = $this->putJson('/api/integraciones/zipnova/config', [
            'origin_id'          => 9330,
            'bulto_default'      => ['peso' => 1.25, 'alto' => 20, 'ancho' => 15, 'profundidad' => 30.4],
            'declarar_valor'     => false,
            'envio_gratis_desde' => 25000,
        ]);

        $respuesta->assertStatus(200);

        $config = $respuesta->json('integracion.config');

        $this->assertSame(9330, $config['origin_id']);
        $this->assertSame('Sucursal Norte · Ruta 9 km 5, Córdoba (5000)', $config['origin_label'], 'origin_label no se recalculó desde origins.');
        $this->assertSame(1.25, (float) $config['bulto_default']['peso']);
        $this->assertSame(30.4, (float) $config['bulto_default']['profundidad']);
        $this->assertFalse($config['declarar_valor']);
        $this->assertSame(25000.0, (float) $config['envio_gratis_desde']);

        $conector->refresh();

        $this->assertSame(9330, $conector->extra_config['origin_id']);
        $this->assertSame(20.0, (float) $conector->extra_config['bulto_default']['alto']);
        $this->assertFalse($conector->extra_config['declarar_valor']);
        $this->assertSame(25000.0, (float) $conector->extra_config['envio_gratis_desde']);
        // Lo que no se mandó queda como estaba.
        $this->assertSame(777, $conector->extra_config['webhook_id']);
        $this->assertSame('Mi negocio SRL', $conector->extra_config['account_name']);

        // Envío gratis en cero apaga la regla: queda null.
        $this->putJson('/api/integraciones/zipnova/config', ['envio_gratis_desde' => 0])->assertStatus(200);
        $this->assertNull($conector->fresh()->extra_config['envio_gratis_desde']);

        Http::assertNothingSent();
    }

    /**
     * Los valores fuera de los límites de Zipnova y un depósito que no está en la lista dan 422
     * y no tocan nada.
     *
     * @return void
     */
    public function test_put_config_rechaza_valores_invalidos()
    {
        Http::fake();

        $conector = $this->conector_zipnova();
        $antes = $conector->extra_config;

        $this->putJson('/api/integraciones/zipnova/config', ['bulto_default' => ['peso' => 0]])->assertStatus(422);
        $this->putJson('/api/integraciones/zipnova/config', ['bulto_default' => ['alto' => 6000]])->assertStatus(422);
        $this->putJson('/api/integraciones/zipnova/config', ['envio_gratis_desde' => -5])->assertStatus(422);

        $respuesta = $this->putJson('/api/integraciones/zipnova/config', ['origin_id' => 999999]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('Actualizar depósitos', $respuesta->json('message'));

        // assertEquals y no assertSame: MySQL reordena las claves de un json al guardarlo.
        $this->assertEquals($antes, $conector->fresh()->extra_config, 'Un PUT inválido modificó la config.');
    }

    /**
     * Sin conector, guardar la config es 422 con el mensaje que dice qué hacer.
     *
     * @return void
     */
    public function test_put_config_sin_conectar_da_422()
    {
        $respuesta = $this->putJson('/api/integraciones/zipnova/config', ['declarar_valor' => true]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no está conectado', $respuesta->json('message'));
    }

    /**
     * Desconectar da de baja el webhook en Zipnova y deja el conector sin credenciales, sin
     * cuenta y sin preferencias. La tarjeta vuelve a "desconectado" sin `config`.
     *
     * @return void
     */
    public function test_disconnect_limpia_el_conector_y_da_de_baja_el_webhook()
    {
        Http::fake([
            self::BASE_URL . '/accounts/' . self::ACCOUNT_ID . '/webhooks/777' => Http::response([], 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $conector = $this->conector_zipnova();

        $respuesta = $this->postJson('/api/integraciones/zipnova/disconnect');

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('integracion.connected'));
        $this->assertArrayNotHasKey('config', $respuesta->json('integracion'));

        $conector->refresh();

        $this->assertNull($conector->access_token, 'El disconnect no limpió el token.');
        $this->assertNull($conector->platform_user_id);
        $this->assertNull($conector->extra_config, 'El disconnect dejó las preferencias en extra_config.');
        $this->assertSame(PlatformConnector::STATUS_SIN_CONECTAR, $conector->status);
        $this->assertFalse($conector->is_connected());

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === self::BASE_URL . '/accounts/' . self::ACCOUNT_ID . '/webhooks/777';
        });
    }

    /**
     * Si Zipnova no deja borrar el webhook, el disconnect se completa igual: la baja es best
     * effort y un webhook huérfano solo genera POSTs que el backend descarta con 200.
     *
     * @return void
     */
    public function test_disconnect_se_completa_aunque_zipnova_no_borre_el_webhook()
    {
        Http::fake([
            '*' => Http::response(['message' => 'Zipnova caído'], 503),
        ]);

        $conector = $this->conector_zipnova();

        $this->postJson('/api/integraciones/zipnova/disconnect')->assertStatus(200);

        $this->assertFalse($conector->fresh()->is_connected());
    }

    /**
     * La cotización de prueba manda a Zipnova un solo ítem con el bulto por defecto, valor
     * declarado 1000 y el depósito elegido; y devuelve las opciones normalizadas, sin las no
     * seleccionables, ordenadas por precio, con las sucursales del retiro en punto.
     *
     * @return void
     */
    public function test_cotizar_prueba_normaliza_la_respuesta_y_ordena_por_precio()
    {
        Http::fake([
            self::BASE_URL . '/shipments/quote' => Http::response($this->fixture_cotizacion(), 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conector_zipnova([
            'extra_config' => $this->extra_config_base([
                'bulto_default' => ['peso' => 1.5, 'alto' => 20, 'ancho' => 15, 'profundidad' => 30],
            ]),
        ]);

        $respuesta = $this->postJson('/api/integraciones/zipnova/cotizar-prueba', ['zipcode' => '5000']);

        $respuesta->assertStatus(200);

        $cotizacion = $respuesta->json('cotizacion');

        $this->assertSame('5000', $cotizacion['zipcode']);
        $this->assertSame('Cordoba', $cotizacion['city']);
        $this->assertSame('Cordoba', $cotizacion['state']);

        $opciones = $cotizacion['opciones'];

        $this->assertCount(3, $opciones, 'La opción con selectable=false tenía que quedar afuera.');

        $precios = [];
        foreach ($opciones as $opcion) {
            $precios[] = (float) $opcion['precio'];
        }
        $this->assertSame([3993.0, 4840.0, 6473.5], $precios, 'Las opciones no vienen ordenadas por precio.');

        $this->assertSame('12|pickup_point|carrier_dropoff', $opciones[0]['key']);
        $this->assertTrue($opciones[0]['es_punto_de_retiro']);
        $this->assertCount(2, $opciones[0]['puntos_de_retiro']);
        $this->assertSame(501, $opciones[0]['puntos_de_retiro'][0]['point_id']);
        $this->assertSame('Sucursal Cordoba Centro', $opciones[0]['puntos_de_retiro'][0]['description']);

        $this->assertSame('12|standard_delivery|carrier_dropoff', $opciones[1]['key']);
        $this->assertSame('Correo Argentino', $opciones[1]['carrier_name']);
        $this->assertSame('Envio a domicilio', $opciones[1]['service_name']);
        $this->assertSame(3, $opciones[1]['dias_min']);
        $this->assertSame(5, $opciones[1]['dias_max']);
        $this->assertSame(['cheapest'], $opciones[1]['tags']);
        $this->assertFalse($opciones[1]['envio_gratis']);
        $this->assertSame(4840.0, (float) $opciones[1]['precio_original']);

        $this->assertSame('56|standard_delivery|carrier_dropoff', $opciones[2]['key']);
        $this->assertSame('Andreani', $opciones[2]['carrier_name']);

        foreach ($opciones as $opcion) {
            $this->assertSame(
                ['key', 'carrier_id', 'carrier_name', 'carrier_logo', 'service_type', 'service_name', 'logistic_type', 'es_punto_de_retiro', 'point_id', 'precio', 'precio_original', 'envio_gratis', 'estimated_delivery', 'dias_min', 'dias_max', 'tags', 'puntos_de_retiro'],
                array_keys($opcion),
                'Cambió la forma de una opción de envío: es el contrato con la tienda (§2.4).'
            );
        }

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/shipments/quote'
                && $request['account_id'] === self::ACCOUNT_ID
                && $request['source'] === 'comerciocity'
                && $request['origin_id'] === self::ORIGIN_ID
                && $request['declared_value'] === 1000
                && $request['destination']['zipcode'] === '5000'
                && count($request['items']) === 1
                && $request['items'][0]['weight'] === 1500
                && $request['items'][0]['height'] === 20
                && $request['items'][0]['width'] === 15
                && $request['items'][0]['length'] === 30;
        });
    }

    /**
     * Un código postal que Zipnova no reconoce vuelve como 422 `ubicacion` con `needs_location`
     * para que la tarjeta pida localidad y provincia; y con ellas, viajan en `destination`.
     *
     * @return void
     */
    public function test_cotizar_prueba_pide_localidad_cuando_zipnova_no_reconoce_el_cp()
    {
        Http::fake([
            self::BASE_URL . '/shipments/quote' => Http::sequence()
                ->push(['message' => 'Destination location not found', 'errors' => ['destination' => ['unknown zipcode']]], 400)
                ->push($this->fixture_cotizacion(), 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conector_zipnova();

        $respuesta = $this->postJson('/api/integraciones/zipnova/cotizar-prueba', ['zipcode' => '9999']);

        $respuesta->assertStatus(422);
        $this->assertSame('ubicacion', $respuesta->json('codigo'));
        $this->assertTrue($respuesta->json('needs_location'));

        $this->postJson('/api/integraciones/zipnova/cotizar-prueba', ['zipcode' => '9999', 'city' => 'Córdoba', 'state' => 'Córdoba'])
             ->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->url() === self::BASE_URL . '/shipments/quote'
                && isset($request['destination']['city'])
                && $request['destination']['city'] === 'Córdoba'
                && $request['destination']['state'] === 'Córdoba';
        });
    }

    /**
     * Sin conector la cotización de prueba es 422 `sin_zipnova`, y Zipnova caído es 502.
     *
     * @return void
     */
    public function test_cotizar_prueba_sin_conector_y_con_zipnova_caido()
    {
        Http::fake([
            '*' => Http::response(['message' => 'Service unavailable'], 503),
        ]);

        $respuesta = $this->postJson('/api/integraciones/zipnova/cotizar-prueba', ['zipcode' => '5000']);

        $respuesta->assertStatus(422);
        $this->assertSame('sin_zipnova', $respuesta->json('codigo'));
        Http::assertNothingSent();

        $this->conector_zipnova();

        $respuesta = $this->postJson('/api/integraciones/zipnova/cotizar-prueba', ['zipcode' => '5000']);

        $respuesta->assertStatus(502);
        $this->assertSame('zipnova', $respuesta->json('codigo'));
    }

    /**
     * Un token que no se puede descifrar (APP_KEY cambiada, fila escrita en plano) se trata como
     * NO conectado: la tarjeta lo muestra desconectado, y la prueba y los depósitos responden 422
     * con un mensaje que apunta a la causa, sin mandarle a Zipnova un `Basic` vacío que volvería
     * como "no reconoció el token o el secret" (culpando al comercio).
     *
     * @return void
     */
    public function test_un_token_indescifrable_se_trata_como_no_conectado()
    {
        Http::fake();

        $conector = $this->conector_zipnova();

        DB::table('platform_connectors')->where('id', $conector->id)->update(['access_token' => 'esto-no-es-un-cifrado-valido']);

        $zipnova = $this->item_zipnova_del_listado();

        $this->assertFalse($zipnova['connected'], 'Un conector cuyo token no se puede descifrar figuró conectado.');
        $this->assertArrayNotHasKey('config', $zipnova);

        $respuesta = $this->postJson('/api/integraciones/zipnova/cotizar-prueba', ['zipcode' => '5000']);

        $respuesta->assertStatus(422);
        $this->assertSame('sin_zipnova', $respuesta->json('codigo'));
        $this->assertStringContainsString('La credencial guardada no se puede leer', $respuesta->json('message'));
        $this->assertStringNotContainsString('no reconoció', $respuesta->json('message'));

        $respuesta = $this->postJson('/api/integraciones/zipnova/origenes');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('La credencial guardada no se puede leer', $respuesta->json('message'));

        Http::assertNothingSent();

        // Desconectar sigue andando (no necesita leer el token) y deja el conector limpio para
        // volver a conectar.
        $this->postJson('/api/integraciones/zipnova/disconnect')->assertStatus(200);

        $this->assertNull($conector->fresh()->getAttributes()['access_token']);
        $this->assertSame(PlatformConnector::STATUS_SIN_CONECTAR, $conector->fresh()->status);
    }

    /**
     * "Actualizar depósitos" vuelve a pedir `GET /addresses` y actualiza la lista; si el elegido
     * ya no existe en Zipnova pasa al primero de la lista nueva.
     *
     * @return void
     */
    public function test_origenes_refresca_la_lista_y_reubica_el_elegido()
    {
        Http::fake([
            self::BASE_URL . '/addresses*' => Http::response(['data' => [
                ['id' => 9340, 'name' => 'Depósito Nuevo', 'street' => 'Belgrano', 'street_number' => '800', 'city' => ['name' => 'Río Cuarto'], 'state' => ['name' => 'Córdoba'], 'zipcode' => '5800', 'use_for_shipping' => true],
            ]], 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $conector = $this->conector_zipnova();

        $respuesta = $this->postJson('/api/integraciones/zipnova/origenes');

        $respuesta->assertStatus(200);

        $config = $respuesta->json('integracion.config');

        $this->assertSame([['id' => 9340, 'label' => 'Depósito Nuevo · Belgrano 800, Río Cuarto (5800)']], $config['origins']);
        $this->assertSame(9340, $config['origin_id'], 'El depósito elegido ya no existía y no se reubicó al primero de la lista nueva.');
        $this->assertSame('Depósito Nuevo · Belgrano 800, Río Cuarto (5800)', $config['origin_label']);

        $this->assertSame(9340, $conector->fresh()->extra_config['origin_id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && Str::startsWith($request->url(), self::BASE_URL . '/addresses')
                && Str::contains($request->url(), 'account_id=' . self::ACCOUNT_ID);
        });
    }
}
