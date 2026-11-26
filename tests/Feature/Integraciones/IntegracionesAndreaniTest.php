<?php

namespace Tests\Feature\Integraciones;

use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Database\Seeders\PlatformSeeder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * Misión envios-andreani (25/11/2026), CA11 — TESTS DE ACEPTACIÓN de la parte de `empresa-api` de la tarjeta
 * «Envíos (Andreani)» en ABM -> Integraciones -> Tienda online (PLAN §2.1, §2.5 y §10.4).
 *
 * Qué fija este archivo:
 *
 * 1. Conectar valida cliente + contrato contra Andreani ANTES de guardar (tarifa de prueba al CP 1425) y guarda el
 *    conector: `platform_user_id` = número de cliente, `access_token` cifrado con el JSON `{usuario,password}` en
 *    null (la etapa 2 lo llenará), `extra_config` con los contratos y las preferencias. Andreani rechaza (400) =
 *    422 `credenciales` y no queda nada en la base; Andreani caído = 502 `andreani`.
 * 2. `GET /api/integraciones` lista Andreani en `tienda_online`, con su `config` pública, y NADA de lo que viaja
 *    al navegador contiene `access_token`, `usuario` ni `password`.
 * 3. Guardar la config (omitir preserva, null limpia), desconectar (sin webhook que dar de baja) y la cotización de
 *    prueba por código postal.
 * 4. La fila de la plataforma `andreani` la deja una migración (`asegurar_plataforma_andreani`) y el `PlatformSeeder`.
 *
 * Todo con `Http::fake()`: nada sale a la red.
 *
 * 🔴 Hoy estos tests están en ROJO a propósito: las rutas `integraciones/andreani/*` no existen (404), el listado
 * no trae la entrada `andreani`, no hay migración ni constante `SLUG_ANDREANI`.
 *
 * Decisión de contrato que este test fija: el item de la tarjeta usa las MISMAS claves base que el de Zipnova
 * (`connected`, no `conectado`: el PLAN §10.4 dice «mismas claves base que el item de Zipnova» y el CA11 escribe
 * `conectado` en prosa). Ver Pendientes del informe de tests.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group integraciones
 */
class IntegracionesAndreaniTest extends EmpresaTestCase
{
    /** Listado que consume ABM -> Integraciones. */
    const RUTA_LISTADO = '/api/integraciones';

    /** Host de la API pública de Andreani, fijado por config para que los fakes matcheen siempre. */
    const BASE_URL = 'https://apis.andreani.com';

    /** Número de cliente y contratos del ejemplo público de la documentación de Andreani. */
    const CLIENTE = 'CL0003750';
    const CONTRATO_DOMICILIO = '400006709';

    /** Slug de la plataforma (literal a propósito: la constante `Platform::SLUG_ANDREANI` la crea la misión). */
    const SLUG = 'andreani';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.andreani.base_url' => self::BASE_URL]);
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
     * Fila `andreani` de `platforms`. La migración `asegurar_plataforma_andreani` la deja en una base migrada; se
     * crea igual por si la base de testing se armó antes de esa migración (el test de la migración, aparte, la
     * ejecuta de verdad).
     *
     * @return Platform
     */
    protected function plataforma_andreani()
    {
        return Platform::firstOrCreate(
            ['slug' => self::SLUG],
            ['name' => 'Andreani', 'client_id' => null, 'client_secret' => null, 'extra_config' => null]
        );
    }

    /**
     * `extra_config` de un conector ya conectado, con la forma de PLAN §2.1.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    protected function extra_config_base(array $cambios = [])
    {
        return array_merge([
            'contrato_domicilio' => self::CONTRATO_DOMICILIO,
            'contrato_sucursal'  => null,
            'sucursal_origen'    => null,
            'bulto_default'      => ['peso' => 0.5, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10],
            'declarar_valor'     => true,
            'envio_gratis_desde' => null,
            'ofrecer_en_tienda'  => true,
            'conectado_en'       => '2026-11-25T15:00:00-03:00',
        ], $cambios);
    }

    /**
     * Crea el conector de Andreani del comercio del fixture, ya conectado (como lo deja `conectar`).
     *
     * @param array<string, mixed> $atributos
     * @return PlatformConnector
     */
    protected function conector_andreani(array $atributos = [])
    {
        $base = [
            'user_id'          => $this->user_id(),
            'platform_id'      => $this->plataforma_andreani()->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => json_encode(['usuario' => null, 'password' => null]),
            'refresh_token'    => null,
            'platform_user_id' => self::CLIENTE,
            'expires_at'       => null,
            'extra_config'     => $this->extra_config_base(),
        ];

        return PlatformConnector::create(array_merge($base, $atributos));
    }

    /**
     * Fake de la API de Andreani: `GET /v1/tarifas` con la respuesta real medida (779.51 con IVA; 650.00 si el
     * contrato es `SUC-TEST`) o con el error 400 real; cualquier otra ruta responde 599 para que un llamado
     * inesperado se note.
     *
     * @param int $status 200 = tarifa; otro = el cuerpo de error de Andreani con ese estado.
     * @return void
     */
    protected function fakear_andreani($status = 200)
    {
        $tarifa = json_decode(file_get_contents(__DIR__ . '/fixtures/andreani_tarifa.json'), true);

        Http::fake(function ($request) use ($status, $tarifa) {

            if (strpos($request->url(), self::BASE_URL . '/v1/tarifas') !== 0) {
                return Http::response(['message' => 'ruta no fakeada en el test'], 599);
            }

            if ($status !== 200) {
                return Http::response(['title' => 'Error en la validacion de su pedido', 'detail' => 'No se pudo obtener la tarifa', 'status' => $status], $status);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $consulta);

            if (isset($consulta['contrato']) && $consulta['contrato'] === 'SUC-TEST') {
                $tarifa['tarifaConIva']['total'] = '650.00';
            }

            return Http::response($tarifa, 200);
        });
    }

    /**
     * Las consultas de tarifa que salieron hacia Andreani, ya parseadas (`cliente`, `contrato`, `cpDestino`,
     * `bultos[i][...]`).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function consultas_de_tarifa()
    {
        $consultas = [];

        foreach (Http::recorded() as $par) {
            $url = $par[0]->url();
            if (strpos($url, self::BASE_URL . '/v1/tarifas') === 0) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
                $consultas[] = $consulta;
            }
        }

        return $consultas;
    }

    /**
     * El item de Andreani del listado.
     *
     * @return array<string, mixed>
     */
    protected function item_andreani_del_listado()
    {
        $json = $this->getJson(self::RUTA_LISTADO)->assertStatus(200)->json();

        foreach ($json['integraciones'] as $integracion) {
            if ($integracion['slug'] === self::SLUG) {
                return $integracion;
            }
        }

        $this->fail('El listado de integraciones no tiene la entrada "andreani".');
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
     * CA11 — Conectar valida contra Andreani (tarifa de prueba al CP 1425, un bulto de 1 kg y 1000 cm3), guarda el
     * conector con el token cifrado y responde la tarjeta ya conectada.
     *
     * @return void
     */
    public function test_conectar_valida_contra_andreani_y_guarda_cifrado()
    {
        $this->plataforma_andreani();
        $this->fakear_andreani(200);

        $respuesta = $this->postJson('/api/integraciones/andreani/conectar', [
            'cliente'            => self::CLIENTE,
            'contrato_domicilio' => self::CONTRATO_DOMICILIO,
        ]);

        $respuesta->assertStatus(200);

        $integracion = $respuesta->json('integracion');

        $this->assertSame(self::SLUG, $integracion['slug']);
        $this->assertSame('tienda_online', $integracion['grupo']);
        $this->assertTrue($integracion['connected'], 'Conectar no dejó la tarjeta como conectada.');
        $this->assertSame(self::CLIENTE, $integracion['config']['cliente']);
        $this->assertSame(self::CONTRATO_DOMICILIO, $integracion['config']['contrato_domicilio']);

        $conector = PlatformConnector::find_for_user_and_slug($this->user_id(), self::SLUG);

        $this->assertNotNull($conector, 'Conectar no creó el conector de Andreani.');
        $this->assertSame(self::CLIENTE, $conector->platform_user_id);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected(), 'El token JSON no vacío tiene que bastar para is_connected().');
        $this->assertNull($conector->expires_at);
        $this->assertSame(self::CONTRATO_DOMICILIO, $conector->extra_config['contrato_domicilio']);
        $this->assertTrue($conector->extra_config['ofrecer_en_tienda'], 'Por defecto Andreani se ofrece en la tienda.');
        $this->assertNotEmpty($conector->extra_config['conectado_en']);

        // En la etapa 1 el token es el JSON de usuario y contraseña vacíos (la etapa 2 los llena ahí).
        $this->assertSame(['usuario' => null, 'password' => null], json_decode((string) $conector->access_token, true));

        $crudo = DB::table('platform_connectors')->where('id', $conector->id)->value('access_token');

        $this->assertNotEmpty($crudo);
        $this->assertStringNotContainsString('usuario', (string) $crudo, 'El access_token quedó sin cifrar en la base.');
        $this->assertStringNotContainsString('password', (string) $crudo, 'El access_token quedó sin cifrar en la base.');

        // La validación salió a Andreani con el cliente, el contrato, el CP seguro 1425 y un bulto mínimo.
        $consultas = $this->consultas_de_tarifa();

        $this->assertCount(1, $consultas, 'Conectar tenía que hacer UNA consulta de tarifa de prueba.');
        $this->assertSame(self::CLIENTE, $consultas[0]['cliente']);
        $this->assertSame(self::CONTRATO_DOMICILIO, $consultas[0]['contrato']);
        $this->assertSame('1425', (string) $consultas[0]['cpDestino']);
        $this->assertEquals(1, $consultas[0]['bultos'][0]['kilos']);
        $this->assertEquals(1000, $consultas[0]['bultos'][0]['volumen']);
    }

    /**
     * CA11 — Con contrato de sucursal, conectar valida también ese contrato y lo guarda.
     *
     * @return void
     */
    public function test_conectar_con_contrato_de_sucursal_lo_valida_y_lo_guarda()
    {
        $this->plataforma_andreani();
        $this->fakear_andreani(200);

        $this->postJson('/api/integraciones/andreani/conectar', [
            'cliente'            => self::CLIENTE,
            'contrato_domicilio' => self::CONTRATO_DOMICILIO,
            'contrato_sucursal'  => 'SUC-TEST',
            'sucursal_origen'    => 40476,
        ])->assertStatus(200);

        $contratos = array_map(function ($consulta) {
            return $consulta['contrato'];
        }, $this->consultas_de_tarifa());

        $this->assertContains(self::CONTRATO_DOMICILIO, $contratos);
        $this->assertContains('SUC-TEST', $contratos, 'El contrato de sucursal no se validó contra Andreani.');

        $conector = PlatformConnector::find_for_user_and_slug($this->user_id(), self::SLUG);

        $this->assertSame('SUC-TEST', $conector->extra_config['contrato_sucursal']);
        $this->assertSame(40476, (int) $conector->extra_config['sucursal_origen']);
    }

    /**
     * CA11 — Andreani rechaza el cliente o el contrato (400): 422 `credenciales` y NO queda conector.
     *
     * @return void
     */
    public function test_credenciales_rechazadas_no_guardan_nada()
    {
        $this->plataforma_andreani();
        $this->fakear_andreani(400);

        $respuesta = $this->postJson('/api/integraciones/andreani/conectar', [
            'cliente'            => 'CL9999999',
            'contrato_domicilio' => '111111111',
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame('credenciales', $respuesta->json('codigo'));
        $this->assertStringContainsString('Andreani no reconoció ese número de cliente o contrato', $respuesta->json('message'));

        $this->assertNull(
            PlatformConnector::find_for_user_and_slug($this->user_id(), self::SLUG),
            'Un conectar rechazado dejó un conector en la base.'
        );
    }

    /**
     * CA11 — Andreani caído (5xx): 502 `andreani`, y tampoco queda conector.
     *
     * @return void
     */
    public function test_andreani_caido_al_conectar_da_502_y_no_guarda_nada()
    {
        $this->plataforma_andreani();
        $this->fakear_andreani(503);

        $respuesta = $this->postJson('/api/integraciones/andreani/conectar', [
            'cliente'            => self::CLIENTE,
            'contrato_domicilio' => self::CONTRATO_DOMICILIO,
        ]);

        $respuesta->assertStatus(502);
        $this->assertSame('andreani', $respuesta->json('codigo'));
        $this->assertNull(PlatformConnector::find_for_user_and_slug($this->user_id(), self::SLUG));
    }

    /**
     * CA11 — Sin cliente o sin contrato de domicilio no se llama a Andreani: 422 `validacion`.
     *
     * @return void
     */
    public function test_conectar_sin_datos_da_validacion_y_no_llama_a_andreani()
    {
        Http::fake();

        $sin_cliente = $this->postJson('/api/integraciones/andreani/conectar', ['contrato_domicilio' => self::CONTRATO_DOMICILIO]);
        $sin_cliente->assertStatus(422);
        $this->assertSame('validacion', $sin_cliente->json('codigo'));

        $sin_contrato = $this->postJson('/api/integraciones/andreani/conectar', ['cliente' => self::CLIENTE]);
        $sin_contrato->assertStatus(422);
        $this->assertSame('validacion', $sin_contrato->json('codigo'));

        Http::assertNothingSent();
    }

    /**
     * CA11 — El listado muestra a Andreani conectado en la solapa de la tienda, con su `config` pública, y sin
     * ninguna clave ni valor que huela a credencial.
     *
     * @return void
     */
    public function test_listado_incluye_andreani_sin_secretos()
    {
        $this->conector_andreani();

        $respuesta = $this->getJson(self::RUTA_LISTADO);

        $respuesta->assertStatus(200);

        $cuerpo = $respuesta->getContent();

        foreach (['access_token', 'refresh_token', 'client_secret', '"usuario"', '"password"'] as $prohibido) {
            $this->assertStringNotContainsString(
                $prohibido,
                $cuerpo,
                'La respuesta de ' . self::RUTA_LISTADO . ' contiene ' . $prohibido . ': ese endpoint no puede exponer credenciales.'
            );
        }

        $por_slug = [];
        foreach ($respuesta->json('integraciones') as $integracion) {
            $por_slug[$integracion['slug']] = $integracion;
        }

        $this->assertArrayHasKey(self::SLUG, $por_slug, 'Falta "andreani" en el listado.');
        $this->assertArrayHasKey('zipnova', $por_slug, 'Sumar Andreani no puede sacar a Zipnova del listado.');

        $andreani = $por_slug[self::SLUG];

        $this->assertSame('Andreani', $andreani['name']);
        $this->assertSame('tienda_online', $andreani['grupo']);
        $this->assertTrue($andreani['connected']);
        $this->assertSame(self::CLIENTE, $andreani['platform_user_id']);
        $this->assertNull($andreani['expires_at']);

        $this->assertArrayHasKey('config', $andreani, 'El item de Andreani conectado tiene que traer "config".');

        foreach (['cliente', 'contrato_domicilio', 'contrato_sucursal', 'sucursal_origen', 'bulto_default', 'declarar_valor', 'envio_gratis_desde', 'ofrecer_en_tienda', 'api_cargada'] as $clave) {
            $this->assertArrayHasKey($clave, $andreani['config'], 'Falta "' . $clave . '" en la config pública de Andreani.');
        }

        $this->assertSame(self::CLIENTE, $andreani['config']['cliente']);
        $this->assertSame(self::CONTRATO_DOMICILIO, $andreani['config']['contrato_domicilio']);
        $this->assertFalse($andreani['config']['api_cargada'], 'En la etapa 1 no hay usuario ni contraseña de la API cargados.');

        $this->assertSame(
            [],
            $this->claves_prohibidas($andreani, ['token', 'secret', 'usuario', 'password']),
            'El item de Andreani tiene claves de credenciales.'
        );

        // Los otros ítems conservan las seis claves del contrato original.
        foreach (['mercado_libre', 'tienda_nube', 'mercado_pago'] as $slug) {
            $this->assertSame(
                ['slug', 'name', 'grupo', 'connected', 'expires_at', 'platform_user_id'],
                array_keys($por_slug[$slug]),
                'Cambió la forma de "' . $slug . '" en el listado.'
            );
        }
    }

    /**
     * CA11 — Un comercio sin conector ve a Andreani desconectado y sin `config`.
     *
     * @return void
     */
    public function test_listado_muestra_andreani_desconectado_sin_config()
    {
        $andreani = $this->item_andreani_del_listado();

        $this->assertFalse($andreani['connected']);
        $this->assertNull($andreani['platform_user_id']);
        $this->assertArrayNotHasKey('config', $andreani, 'Un comercio desconectado no tiene config que mostrar.');
    }

    /**
     * CA11 — `PUT config` guarda bulto, envío gratis, declarar valor y «ofrecer en la tienda»; lo que no se manda
     * se preserva y `null` limpia.
     *
     * @return void
     */
    public function test_guardar_config()
    {
        Http::fake();

        $conector = $this->conector_andreani();

        $respuesta = $this->putJson('/api/integraciones/andreani/config', [
            'bulto_default'      => ['peso' => 1.25, 'alto' => 20, 'ancho' => 15, 'profundidad' => 30.4],
            'declarar_valor'     => false,
            'envio_gratis_desde' => 25000,
            'ofrecer_en_tienda'  => false,
        ]);

        $respuesta->assertStatus(200);

        $config = $respuesta->json('integracion.config');

        $this->assertSame(1.25, (float) $config['bulto_default']['peso']);
        $this->assertSame(30.4, (float) $config['bulto_default']['profundidad']);
        $this->assertFalse($config['declarar_valor']);
        $this->assertSame(25000.0, (float) $config['envio_gratis_desde']);
        $this->assertFalse($config['ofrecer_en_tienda']);

        $conector->refresh();

        $this->assertSame(20.0, (float) $conector->extra_config['bulto_default']['alto']);
        $this->assertFalse($conector->extra_config['declarar_valor']);
        $this->assertSame(25000.0, (float) $conector->extra_config['envio_gratis_desde']);
        $this->assertFalse($conector->extra_config['ofrecer_en_tienda']);
        // Lo que no se mandó queda como estaba.
        $this->assertSame(self::CONTRATO_DOMICILIO, $conector->extra_config['contrato_domicilio']);
        $this->assertSame(self::CLIENTE, $conector->platform_user_id);

        // `null` limpia el envío gratis.
        $this->putJson('/api/integraciones/andreani/config', ['envio_gratis_desde' => null])->assertStatus(200);
        $this->assertNull($conector->fresh()->extra_config['envio_gratis_desde']);

        // Y un contrato de sucursal se agrega por la config.
        $this->putJson('/api/integraciones/andreani/config', ['contrato_sucursal' => 'SUC-TEST', 'sucursal_origen' => 40476])->assertStatus(200);
        $this->assertSame('SUC-TEST', $conector->fresh()->extra_config['contrato_sucursal']);

        Http::assertNothingSent();
    }

    /**
     * CA11 — Sin conector, guardar la config es 422.
     *
     * @return void
     */
    public function test_guardar_config_sin_conectar_da_422()
    {
        $this->putJson('/api/integraciones/andreani/config', ['declarar_valor' => true])->assertStatus(422);
    }

    /**
     * CA11 — Desconectar deja el conector sin credenciales ni cliente ni preferencias, y la tarjeta vuelve a
     * «desconectado» sin `config`. No hay webhook que dar de baja: no sale nada a la red.
     *
     * @return void
     */
    public function test_desconectar()
    {
        Http::fake();

        $conector = $this->conector_andreani();

        $respuesta = $this->postJson('/api/integraciones/andreani/disconnect');

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('integracion.connected'));
        $this->assertArrayNotHasKey('config', $respuesta->json('integracion'));

        $conector->refresh();

        $this->assertNull($conector->access_token, 'El disconnect no limpió el token.');
        $this->assertNull($conector->platform_user_id);
        $this->assertNull($conector->extra_config, 'El disconnect dejó las preferencias en extra_config.');
        $this->assertSame(PlatformConnector::STATUS_SIN_CONECTAR, $conector->status);
        $this->assertFalse($conector->is_connected());

        Http::assertNothingSent();
    }

    /**
     * CA11 — La cotización de prueba por código postal devuelve la opción `andreani|domicilio` con el precio de
     * Andreani (779.51) y consulta con el cliente, el contrato, el CP y el bulto por defecto del comercio.
     *
     * @return void
     */
    public function test_cotizar_prueba_por_codigo_postal()
    {
        $this->fakear_andreani(200);

        $this->conector_andreani([
            'extra_config' => $this->extra_config_base([
                'bulto_default' => ['peso' => 1.5, 'alto' => 20, 'ancho' => 15, 'profundidad' => 30],
            ]),
        ]);

        $respuesta = $this->postJson('/api/integraciones/andreani/cotizar-prueba', ['zipcode' => '5000']);

        $respuesta->assertStatus(200);

        $cotizacion = $respuesta->json('cotizacion');

        $this->assertSame('5000', (string) $cotizacion['zipcode']);
        $this->assertArrayHasKey('motivo', $cotizacion);
        $this->assertCount(1, $cotizacion['opciones']);

        $opcion = $cotizacion['opciones'][0];

        $this->assertSame('andreani|domicilio', $opcion['key']);
        $this->assertSame('andreani', $opcion['proveedor']);
        $this->assertSame('Andreani', $opcion['carrier_name']);
        $this->assertSame(779.51, (float) $opcion['precio']);
        $this->assertFalse($opcion['es_punto_de_retiro']);

        $consultas = $this->consultas_de_tarifa();

        $this->assertCount(1, $consultas);
        $this->assertSame(self::CLIENTE, $consultas[0]['cliente']);
        $this->assertSame(self::CONTRATO_DOMICILIO, $consultas[0]['contrato']);
        $this->assertSame('5000', (string) $consultas[0]['cpDestino']);
        $this->assertEquals(1.5, $consultas[0]['bultos'][0]['kilos']);
        $this->assertEquals(9000, $consultas[0]['bultos'][0]['volumen']);
    }

    /**
     * CA11 — Sin conector la cotización de prueba es 422 `sin_andreani`; sin `zipcode`, 422 `validacion`; con
     * Andreani caído, 502 `andreani`.
     *
     * @return void
     */
    public function test_cotizar_prueba_sin_conector_sin_codigo_postal_y_con_andreani_caido()
    {
        Http::fake();

        $sin_conector = $this->postJson('/api/integraciones/andreani/cotizar-prueba', ['zipcode' => '5000']);

        $sin_conector->assertStatus(422);
        $this->assertSame('sin_andreani', $sin_conector->json('codigo'));
        Http::assertNothingSent();

        $this->conector_andreani();

        $sin_cp = $this->postJson('/api/integraciones/andreani/cotizar-prueba', []);

        $sin_cp->assertStatus(422);
        $this->assertSame('validacion', $sin_cp->json('codigo'));

        $this->fakear_andreani(503);

        $caido = $this->postJson('/api/integraciones/andreani/cotizar-prueba', ['zipcode' => '5000']);

        $caido->assertStatus(502);
        $this->assertSame('andreani', $caido->json('codigo'));
    }

    /**
     * La migración `asegurar_plataforma_andreani` deja la fila `andreani` (nombre «Andreani», sin credenciales de
     * aplicación), no pisa una existente y se puede correr dos veces. Se ejecuta de verdad su `up()` sobre la base
     * de testing (dentro de la transacción del test), en vez de confiar en que la base ya la tenga.
     *
     * @return void
     */
    public function test_la_migracion_deja_la_fila_de_la_plataforma_andreani()
    {
        $archivos = glob(database_path('migrations/*_asegurar_plataforma_andreani.php'));

        $this->assertCount(1, $archivos, 'Falta la migración database/migrations/*_asegurar_plataforma_andreani.php.');

        require_once $archivos[0];

        $clase = Str::studly(preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($archivos[0], '.php')));

        $this->assertTrue(class_exists($clase), 'La clase de la migración tendría que llamarse ' . $clase . '.');

        DB::table('platforms')->where('slug', self::SLUG)->delete();

        (new $clase())->up();

        $fila = DB::table('platforms')->where('slug', self::SLUG)->first();

        $this->assertNotNull($fila, 'La migración no creó la fila de la plataforma andreani.');
        $this->assertSame('Andreani', $fila->name);
        $this->assertNull($fila->client_id);
        $this->assertNull($fila->client_secret);

        // Idempotente: correrla otra vez no duplica ni falla.
        (new $clase())->up();

        $this->assertSame(1, DB::table('platforms')->where('slug', self::SLUG)->count());
    }

    /**
     * El `PlatformSeeder` también siembra la fila `andreani` (instalaciones nuevas y demos), y la constante
     * `Platform::SLUG_ANDREANI` existe con el mismo valor.
     *
     * @return void
     */
    public function test_el_seeder_siembra_la_plataforma_andreani()
    {
        $this->assertTrue(defined(Platform::class . '::SLUG_ANDREANI'), 'Falta la constante Platform::SLUG_ANDREANI.');
        $this->assertSame(self::SLUG, constant(Platform::class . '::SLUG_ANDREANI'));

        DB::table('platforms')->where('slug', self::SLUG)->delete();

        (new PlatformSeeder())->run();

        $fila = DB::table('platforms')->where('slug', self::SLUG)->first();

        $this->assertNotNull($fila, 'El PlatformSeeder no sembró la plataforma andreani.');
        $this->assertSame('Andreani', $fila->name);
    }
}
