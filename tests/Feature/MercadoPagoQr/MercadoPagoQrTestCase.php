<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\Caja;
use App\Models\MercadoPagoQrCaja;
use App\Models\MercadoPagoQrCobro;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Base de las suites del cobro con QR de Mercado Pago (misión cobro-qr-mercado-pago,
 * 26/10/2026).
 *
 * - Usuario 500 del fixture (TestingFerreteriaSeeder), autenticado por `web`, y todo adentro de
 *   `DatabaseTransactions`: lo que se crea acá (conector, QR, cobros, ventas) se revierte solo.
 * - NADA de red: cada test declara con `fake_mp()` exactamente qué contesta Mercado Pago. Lo que
 *   no esté declarado cae en el freno de `Tests\TestCase` (`HttpFactorySinSalida`), que lo
 *   bloquea y hace fallar el test. Es el equivalente de `Http::preventStrayRequests()`, que
 *   Laravel 8 no tiene.
 * - Los QR y los cobros que no son lo que el test mide se crean directo en la base
 *   (`qr_en_base()`, `cobro_en_base()`); lo que se mide pasa por los endpoints reales.
 *
 * PHP 7.4: sin match, ?->, str_contains, argumentos nombrados ni union types.
 */
abstract class MercadoPagoQrTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var string Cuenta de Mercado Pago del conector de prueba. */
    const MP_USER_ID = '123456789';

    /** @var string Access token de prueba. Ninguna respuesta de la API puede contenerlo. */
    const TOKEN = 'APP_USR-TOKEN-QR-DE-PRUEBA';

    /** @var int "Mercado Pago" en el catálogo `current_acount_payment_methods` del fixture. */
    const METODO_MERCADO_PAGO = 6;

    /** @var \App\Models\User */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');
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
     * Conecta Mercado Pago para el usuario 500: conector OAuth conectado, con token y cuenta.
     *
     * @param array $atributos Para pisar lo que haga falta (`platform_user_id`, `extra_config`...).
     * @return PlatformConnector
     */
    protected function conectar_mp(array $atributos = [])
    {
        return PlatformConnector::create(array_merge([
            'user_id'          => self::USER_ID,
            'platform_id'      => $this->plataforma_mp()->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => self::TOKEN,
            'refresh_token'    => 'TG-REFRESH-DE-PRUEBA',
            'platform_user_id' => self::MP_USER_ID,
            'expires_at'       => Carbon::now()->addDays(90),
        ], $atributos));
    }

    /**
     * Un QR ya creado (como si hubiera pasado por `POST qr/cajas`), directo en la base.
     *
     * @param array $atributos
     * @return MercadoPagoQrCaja
     */
    protected function qr_en_base(array $atributos = [])
    {
        static $n = 0;
        $n++;

        return MercadoPagoQrCaja::create(array_merge([
            'user_id'              => self::USER_ID,
            'name'                 => 'zz QR ' . $n,
            'caja_id'              => null,
            'address_id'           => null,
            'mp_user_id'           => self::MP_USER_ID,
            'mp_store_id'          => '5550' . $n,
            'mp_store_external_id' => 'CC500S0TEST' . $n,
            'mp_pos_id'            => '7770' . $n,
            'mp_pos_external_id'   => 'CC500PTEST' . $n,
            'qr_image_url'         => 'https://mp.test/qr' . $n . '.png',
            'qr_template_url'      => 'https://mp.test/qr' . $n . '.pdf',
            'qr_code'              => '00020101021243650016COM.MERCADOLIBRE' . $n,
        ], $atributos));
    }

    /**
     * Un cobro directo en la base.
     *
     * @param MercadoPagoQrCaja $qr
     * @param array $atributos
     * @return MercadoPagoQrCobro
     */
    protected function cobro_en_base(MercadoPagoQrCaja $qr, array $atributos = [])
    {
        static $n = 0;
        $n++;

        return MercadoPagoQrCobro::create(array_merge([
            'user_id'                 => self::USER_ID,
            'mercado_pago_qr_caja_id' => $qr->id,
            'employee_id'             => self::USER_ID,
            'total'                   => 1500,
            'external_reference'      => 'ccqr-test-' . $n . '-' . uniqid(),
            'mp_idempotency_key'      => 'clave-test-' . $n . '-' . uniqid(),
            'mp_order_id'             => 'ORDTEST' . $n,
            'estado'                  => MercadoPagoQrCobro::ESTADO_PENDIENTE,
        ], $atributos));
    }

    /**
     * Las cajas del usuario 500 en el fixture, por orden de id.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function cajas_del_fixture()
    {
        return Caja::where('user_id', self::USER_ID)->orderBy('id')->get();
    }

    /**
     * Declara qué contesta Mercado Pago. Cada ruta es `[METODO, regex del path, respuesta]`, y la
     * respuesta es `[status, cuerpo]` o un closure `function ($request) { return [status, cuerpo]; }`.
     * Gana la primera que matchea. Lo que no matchea (o no es api.mercadopago.com) devuelve null y
     * cae en el freno de internet de `Tests\TestCase`, que hace fallar el test.
     *
     * @param array $rutas
     * @return void
     */
    protected function fake_mp(array $rutas)
    {
        Http::fake(function ($request) use ($rutas) {
            if (parse_url($request->url(), PHP_URL_HOST) !== 'api.mercadopago.com') {
                return null;
            }

            $path = parse_url($request->url(), PHP_URL_PATH);

            foreach ($rutas as $ruta) {
                if ($request->method() === $ruta[0] && preg_match($ruta[1], $path)) {
                    $respuesta = is_callable($ruta[2]) ? call_user_func($ruta[2], $request) : $ruta[2];

                    return Http::response($respuesta[1], $respuesta[0]);
                }
            }

            return null;
        });
    }

    /**
     * Respuesta de MP a `GET /v1/orders/{id}` con el estado indicado.
     *
     * @param string $order_id
     * @param string $status Estado de la orden.
     * @param string $pago_status Estado del pago.
     * @param string|null $pago_detail
     * @return array
     */
    protected function orden_mp($order_id, $status, $pago_status = 'created', $pago_detail = null)
    {
        return [200, [
            'id'            => $order_id,
            'type'          => 'qr',
            'status'        => $status,
            'status_detail' => $status === 'processed' ? 'accredited' : $status,
            'total_amount'  => '1500.00',
            'transactions'  => [
                'payments' => [
                    [
                        'id'            => 'PAY01JTEST',
                        'amount'        => '1500.00',
                        'status'        => $pago_status,
                        'status_detail' => $pago_detail,
                    ],
                ],
            ],
        ]];
    }

    /**
     * Respuesta de MP a `POST /v1/orders` (orden creada).
     *
     * @param string $order_id
     * @return array
     */
    protected function orden_creada_mp($order_id)
    {
        return [201, [
            'id'           => $order_id,
            'type'         => 'qr',
            'status'       => 'created',
            'transactions' => ['payments' => [['id' => 'PAY01JTEST', 'amount' => '1500.00', 'status' => 'created']]],
        ]];
    }

    /**
     * Pedidos `POST /v1/orders` registrados por `Http::fake`, en orden.
     *
     * @return \Illuminate\Support\Collection De `Illuminate\Http\Client\Request`.
     */
    protected function posts_de_ordenes()
    {
        return Http::recorded(function ($request) {
            return $request->method() === 'POST' && parse_url($request->url(), PHP_URL_PATH) === '/v1/orders';
        })->map(function ($par) {
            return $par[0];
        })->values();
    }

    /**
     * Afirma que el JSON de una respuesta no contiene el access token del comercio.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @return void
     */
    protected function assert_sin_token($response)
    {
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent(), 'El access token nunca viaja al SPA.');
    }
}
