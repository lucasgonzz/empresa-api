<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\Address;
use App\Models\MercadoPagoQrCaja;
use App\Models\PlatformConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Configuración del cobro con QR (misión cobro-qr-mercado-pago, 26/10/2026): crear y borrar los
 * QR del mostrador, el listado con `activo` y el método de pago del QR.
 *
 * Lo que fija:
 * - Crear un QR crea la sucursal en MP (`POST /users/{id}/stores`) y la caja (`POST /v2/pos`, con
 *   `X-Idempotency-Key`), con external_ids alfanuméricos, y guarda la imagen y el PDF.
 * - Sin Mercado Pago conectado: 422 `sin_conexion` y ni un pedido a MP.
 * - 409 `pos_already_exists` → se recupera la caja con `GET /v2/pos?external_id=`.
 * - Un segundo QR en la misma sucursal reusa la sucursal de MP; uno para la misma caja se rechaza.
 * - Borrar llama a `DELETE /v2/pos/{id}` y borra con soft delete aunque MP falle.
 * - `GET qr`: un QR de otra cuenta de MP figura `activo: false`.
 * - El token nunca viaja en una respuesta.
 *
 * @group mercado_pago_qr
 */
class Configuracion_de_QR_Test extends MercadoPagoQrTestCase
{
    /**
     * Rutas de MP de una creación feliz: sucursal 555, caja 777 con su QR.
     *
     * @return array
     */
    protected function rutas_de_creacion_feliz()
    {
        return [
            ['POST', '#^/users/' . self::MP_USER_ID . '/stores$#', [201, ['id' => 555, 'name' => 'Sucursal']]],
            ['POST', '#^/v2/pos$#', [201, [
                'id'          => 777,
                'qr_response' => [
                    'uuid'              => 'abc',
                    'image'             => 'https://mp.test/qr777.png',
                    'template_document' => 'https://mp.test/qr777.pdf',
                    'template_image'    => 'https://mp.test/qr777-plantilla.png',
                    'qr_code'           => '00020101021243650016COM.MERCADOLIBRE777',
                ],
            ]]],
        ];
    }

    /**
     * Cuerpo del formulario "Crear QR".
     *
     * @param array $overrides
     * @return array
     */
    protected function datos_qr(array $overrides = [])
    {
        return array_merge([
            'name'          => 'Mostrador',
            'caja_id'       => null,
            'address_id'    => null,
            'street_name'   => 'Av. Pellegrini',
            'street_number' => '1234',
            'city_name'     => 'Rosario',
            'state_name'    => 'Santa Fe',
            'latitude'      => -32.95,
            'longitude'     => -60.65,
            'reference'     => 'Frente a la plaza',
        ], $overrides);
    }

    /**
     * Crear un QR para una caja y una sucursal: llama a stores y a /v2/pos con el cuerpo y los
     * headers correctos, guarda la fila y responde la forma QrCaja.
     *
     * @test
     */
    public function crear_qr_crea_la_sucursal_y_la_caja_en_mp_y_guarda_el_qr()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_de_creacion_feliz());

        $caja    = $this->cajas_del_fixture()->first();
        $address = Address::where('user_id', self::USER_ID)->orderBy('id')->first();

        $response = $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr([
            'caja_id'    => $caja->id,
            'address_id' => $address->id,
        ]));

        $response->assertStatus(201);
        $this->assert_sin_token($response);

        $response->assertJsonPath('qr.name', 'Mostrador');
        $response->assertJsonPath('qr.caja_id', $caja->id);
        $response->assertJsonPath('qr.caja_name', $caja->name);
        $response->assertJsonPath('qr.address_id', $address->id);
        $response->assertJsonPath('qr.qr_image_url', 'https://mp.test/qr777.png');
        $response->assertJsonPath('qr.qr_template_url', 'https://mp.test/qr777.pdf');
        $response->assertJsonPath('qr.qr_code', '00020101021243650016COM.MERCADOLIBRE777');
        $response->assertJsonPath('qr.activo', true);

        $qr = MercadoPagoQrCaja::find($response->json('qr.id'));

        $this->assertNotNull($qr);
        $this->assertSame('777', (string) $qr->mp_pos_id);
        $this->assertSame('555', (string) $qr->mp_store_id);
        $this->assertSame(self::MP_USER_ID, (string) $qr->mp_user_id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,60}$/', $qr->mp_store_external_id, 'El external_id de la sucursal es alfanumérico, sin guiones.');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,40}$/', $qr->mp_pos_external_id, 'El external_id de la caja es alfanumérico y de hasta 40.');
        $this->assertStringStartsWith('CC500P' . $qr->id, $qr->mp_pos_external_id);

        Http::assertSent(function (Request $request) use ($qr) {
            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/users/' . self::MP_USER_ID . '/stores'
                && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
                && $request['external_id'] === $qr->mp_store_external_id
                && $request['location']['street_name'] === 'Av. Pellegrini'
                && $request['location']['street_number'] === '1234'
                && $request['location']['city_name'] === 'Rosario'
                && $request['location']['state_name'] === 'Santa Fe'
                && abs($request['location']['latitude'] - (-32.95)) < 0.0001;
        });

        Http::assertSent(function (Request $request) use ($qr) {
            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/v2/pos'
                && $request->header('X-Idempotency-Key') !== []
                && $request->header('X-Idempotency-Key')[0] !== ''
                && $request['name'] === 'Mostrador'
                && $request['external_store_id'] === $qr->mp_store_external_id
                && $request['external_id'] === $qr->mp_pos_external_id
                && $request['config']['qr']['operating_mode'] === 'pdv';
        });
    }

    /**
     * Sin Mercado Pago conectado no se crea nada ni se llama a MP.
     *
     * @test
     */
    public function crear_qr_sin_conexion_da_422_sin_conexion()
    {
        $this->fake_mp([]);

        $response = $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr());

        $response->assertStatus(422);
        $response->assertJsonPath('sin_conexion', true);

        Http::assertNothingSent();
        $this->assertSame(0, MercadoPagoQrCaja::where('user_id', self::USER_ID)->count());
    }

    /**
     * Un conector desconectado (sin token) cuenta como sin conexión.
     *
     * @test
     */
    public function crear_qr_con_el_conector_desconectado_da_422_sin_conexion()
    {
        $this->conectar_mp(['status' => PlatformConnector::STATUS_SIN_CONECTAR, 'access_token' => null]);
        $this->fake_mp([]);

        $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr())
            ->assertStatus(422)
            ->assertJsonPath('sin_conexion', true);

        Http::assertNothingSent();
    }

    /**
     * 409 `pos_already_exists`: la caja se busca por external_id y se usa esa.
     *
     * @test
     */
    public function crear_qr_con_pos_already_exists_recupera_la_caja_por_busqueda()
    {
        $this->conectar_mp();

        $this->fake_mp([
            ['POST', '#^/users/\d+/stores$#', [201, ['id' => 555]]],
            ['POST', '#^/v2/pos$#', [409, ['message' => 'The pos already exists', 'error' => 'pos_already_exists', 'status' => 409]]],
            ['GET', '#^/v2/pos$#', function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return [200, ['data' => [[
                    'id'          => 888,
                    'external_id' => isset($query['external_id']) ? $query['external_id'] : '',
                    'qr_response' => [
                        'image'             => 'https://mp.test/qr888.png',
                        'template_document' => 'https://mp.test/qr888.pdf',
                        'qr_code'           => 'QR888',
                    ],
                ]]]];
            }],
        ]);

        $response = $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr());

        $response->assertStatus(201);
        $response->assertJsonPath('qr.qr_image_url', 'https://mp.test/qr888.png');

        $qr = MercadoPagoQrCaja::find($response->json('qr.id'));

        $this->assertSame('888', (string) $qr->mp_pos_id);

        Http::assertSent(function (Request $request) use ($qr) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/v2/pos'
                && isset($query['external_id'])
                && $query['external_id'] === $qr->mp_pos_external_id;
        });
    }

    /**
     * MP rechaza la sucursal: 422 con el texto de MP y no queda ninguna fila.
     *
     * @test
     */
    public function crear_qr_con_rechazo_de_mp_devuelve_el_mensaje_y_no_deja_fila()
    {
        $this->conectar_mp();

        $this->fake_mp([
            ['POST', '#^/users/\d+/stores$#', [400, [
                'message' => 'invalid location',
                'error'   => 'bad_request',
                'status'  => 400,
                'cause'   => [['code' => 'invalid_location', 'description' => 'La latitud es obligatoria']],
            ]]],
            ['GET', '#^/users/\d+/stores/search$#', [200, ['results' => []]]],
        ]);

        $response = $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr());

        $response->assertStatus(422);
        $this->assertStringContainsString('La latitud es obligatoria', $response->json('message'));

        $this->assertSame(0, MercadoPagoQrCaja::withTrashed()->where('user_id', self::USER_ID)->count());
    }

    /**
     * MP rechaza la caja: la fila provisoria se borra del todo.
     *
     * @test
     */
    public function crear_qr_con_rechazo_de_la_caja_borra_la_fila_provisoria()
    {
        $this->conectar_mp();

        $this->fake_mp([
            ['POST', '#^/users/\d+/stores$#', [201, ['id' => 555]]],
            ['POST', '#^/v2/pos$#', [400, ['message' => 'external_store_id invalido', 'status' => 400]]],
        ]);

        $response = $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr());

        $response->assertStatus(422);
        $this->assertStringContainsString('external_store_id invalido', $response->json('message'));

        $this->assertSame(0, MercadoPagoQrCaja::withTrashed()->where('user_id', self::USER_ID)->count());
    }

    /**
     * Un segundo QR para la misma sucursal (sin caja el primero, con caja el segundo) reusa la
     * sucursal de MP: un solo POST a stores.
     *
     * @test
     */
    public function un_segundo_qr_en_la_misma_sucursal_reusa_la_sucursal_de_mp()
    {
        $this->conectar_mp();
        $this->fake_mp($this->rutas_de_creacion_feliz());

        $address = Address::where('user_id', self::USER_ID)->orderBy('id')->first();
        $caja    = $this->cajas_del_fixture()->first();

        $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr(['address_id' => $address->id]))->assertStatus(201);
        $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr(['name' => 'Caja 1', 'address_id' => $address->id, 'caja_id' => $caja->id]))->assertStatus(201);

        $qrs = MercadoPagoQrCaja::where('user_id', self::USER_ID)->orderBy('id')->get();

        $this->assertCount(2, $qrs);
        $this->assertSame($qrs[0]->mp_store_external_id, $qrs[1]->mp_store_external_id);

        $posts_a_stores = Http::recorded(function (Request $request) {
            return $request->method() === 'POST' && preg_match('#/stores$#', parse_url($request->url(), PHP_URL_PATH));
        });

        $this->assertCount(1, $posts_a_stores, 'La sucursal de MP se crea una sola vez.');
    }

    /**
     * Ya hay un QR para esa caja (de la cuenta conectada): 422 sin llamar a MP.
     *
     * @test
     */
    public function un_qr_repetido_para_la_misma_caja_se_rechaza()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $caja = $this->cajas_del_fixture()->first();
        $this->qr_en_base(['caja_id' => $caja->id]);

        $this->postJson('api/mercado-pago/qr/cajas', $this->datos_qr(['caja_id' => $caja->id]))
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * Borrar: `DELETE /v2/pos/{id}` con idempotency key y soft delete.
     *
     * @test
     */
    public function borrar_qr_borra_la_caja_en_mp_y_hace_soft_delete()
    {
        $this->conectar_mp();

        $qr = $this->qr_en_base(['mp_pos_id' => '777']);

        $this->fake_mp([
            ['DELETE', '#^/v2/pos/777$#', [204, '']],
        ]);

        $this->deleteJson('api/mercado-pago/qr/cajas/' . $qr->id)
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertNull(MercadoPagoQrCaja::find($qr->id));
        $this->assertNotNull(MercadoPagoQrCaja::withTrashed()->find($qr->id));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'DELETE'
                && parse_url($request->url(), PHP_URL_PATH) === '/v2/pos/777'
                && $request->header('X-Idempotency-Key') !== []
                && $request->header('X-Idempotency-Key')[0] !== '';
        });
    }

    /**
     * Si MP falla al borrar, se borra igual acá.
     *
     * @test
     */
    public function borrar_qr_con_mp_caido_borra_igual()
    {
        $this->conectar_mp();

        $qr = $this->qr_en_base(['mp_pos_id' => '777']);

        $this->fake_mp([
            ['DELETE', '#^/v2/pos/777$#', [500, ['message' => 'internal error']]],
        ]);

        $this->deleteJson('api/mercado-pago/qr/cajas/' . $qr->id)->assertStatus(200);

        $this->assertNull(MercadoPagoQrCaja::find($qr->id));
    }

    /**
     * Borrar un QR de otro comercio: 404.
     *
     * @test
     */
    public function borrar_qr_de_otro_comercio_da_404()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $qr = $this->qr_en_base(['user_id' => 999999]);

        $this->deleteJson('api/mercado-pago/qr/cajas/' . $qr->id)->assertStatus(404);

        $this->assertNotNull(MercadoPagoQrCaja::find($qr->id));
        Http::assertNothingSent();
    }

    /**
     * `GET qr`: conectado, método de pago por defecto ("Mercado Pago" del catálogo) y los QR con
     * `activo` según la cuenta: uno de otra cuenta figura inactivo.
     *
     * @test
     */
    public function el_listado_marca_inactivo_el_qr_de_otra_cuenta()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $propio = $this->qr_en_base();
        $ajeno  = $this->qr_en_base(['mp_user_id' => '999']);

        $response = $this->getJson('api/mercado-pago/qr');

        $response->assertStatus(200);
        $this->assert_sin_token($response);
        $response->assertJsonPath('conectado', true);
        $response->assertJsonPath('current_acount_payment_method_id', self::METODO_MERCADO_PAGO);

        $qrs = collect($response->json('qrs'))->keyBy('id');

        $this->assertTrue($qrs[$propio->id]['activo']);
        $this->assertFalse($qrs[$ajeno->id]['activo']);

        $this->assertSame(
            ['id', 'name', 'caja_id', 'caja_name', 'address_id', 'address_name', 'qr_image_url', 'qr_template_url', 'qr_code', 'activo'],
            array_keys($qrs[$propio->id]),
            'La forma QrCaja del contrato, ni más ni menos.'
        );

        Http::assertNothingSent();
    }

    /**
     * `GET qr` sin conexión: 200 con `conectado: false` (el SPA esconde todo, no es un error).
     *
     * @test
     */
    public function el_listado_sin_conexion_responde_conectado_false()
    {
        $this->fake_mp([]);

        $this->getJson('api/mercado-pago/qr')
            ->assertStatus(200)
            ->assertJsonPath('conectado', false);
    }

    /**
     * `PUT config` guarda el método en `extra_config['qr']` sin pisar otras claves; un id
     * inexistente es 422.
     *
     * @test
     */
    public function la_config_guarda_el_metodo_sin_pisar_extra_config_y_valida_el_id()
    {
        $connector = $this->conectar_mp(['extra_config' => ['otra_clave' => 'se queda']]);
        $this->fake_mp([]);

        $this->putJson('api/mercado-pago/qr/config', ['current_acount_payment_method_id' => 4])
            ->assertStatus(200)
            ->assertJsonPath('current_acount_payment_method_id', 4);

        $connector->refresh();

        $this->assertSame('se queda', $connector->extra_config['otra_clave']);
        $this->assertSame(4, $connector->extra_config['qr']['current_acount_payment_method_id']);

        $this->getJson('api/mercado-pago/qr')->assertJsonPath('current_acount_payment_method_id', 4);

        $this->putJson('api/mercado-pago/qr/config', ['current_acount_payment_method_id' => 999999])
            ->assertStatus(422);

        $connector->refresh();
        $this->assertSame(4, $connector->extra_config['qr']['current_acount_payment_method_id'], 'Un id inválido no pisa lo guardado.');
    }
}
