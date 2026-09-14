<?php

namespace Tests\Feature\Envios;

use App\Models\Article;
use App\Models\Envio;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\Sale;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión zipnova-envios (14/9/2026), parte de `empresa-api`: el envío de un pedido de la tienda
 * generado en Zipnova y gestionado desde el ERP.
 *
 * Lo que fija este archivo:
 *
 * 1. `POST envio/generar/{order_id}` crea la fila de `envios` con lo que devuelve el 201 de
 *    Zipnova, y el payload que viaja tiene el `external_id`, el destino mapeado y los ítems en
 *    gramos y centímetros enteros.
 * 2. Sin conector es 422 sin fila; si Zipnova falla es 422 con la fila en `error`, y el
 *    reintento actualiza ESA fila en vez de crear otra.
 * 3. Confirmar el pedido (`PUT order/{id}`) genera el envío solo, y la confirmación NO falla si
 *    Zipnova falla: la venta nace igual. Un pedido sin `envio_opcion` no toca Zipnova.
 * 4. El webhook sincroniza un envío conocido y responde 200 a uno desconocido; cancelar llama
 *    al endpoint y sincroniza; la etiqueta vuelve como PDF; el comando toca solo los no finales.
 *
 * Todo con `Http::fake()`: nada sale a la red. El pedido se arma con el mismo fixture que las
 * suites de `tests/Feature/Pedidos` (`PedidosDePrueba`).
 *
 * @group envios
 */
class GenerarEnvioZipnovaTest extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** Host de la API de Zipnova, fijado por config para que los fakes matcheen siempre. */
    const BASE_URL = 'https://api.zipnova.com.ar/v2';

    /** Credenciales del conector del comercio. */
    const TOKEN = 'tok-de-prueba-1234567890';
    const SECRET = 'sec-de-prueba-0987654321';

    /** Cuenta, depósito y envío del Zipnova fakeado (los de la fixture `zipnova_shipment.json`). */
    const ACCOUNT_ID = 3355;
    const ORIGIN_ID = 9323;
    const SHIPMENT_ID = '987654';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zipnova.base_url' => self::BASE_URL]);

        $this->sembrar_estados_de_pedido();
    }

    /**
     * Conecta Zipnova para el comercio del fixture, directo en la base.
     *
     * @param array<string, mixed> $config Cambios sobre el `extra_config` base.
     * @return PlatformConnector
     */
    protected function conectar_zipnova(array $config = [])
    {
        $platform = Platform::firstOrCreate(
            ['slug' => Platform::SLUG_ZIPNOVA],
            ['name' => 'Zipnova', 'client_id' => null, 'client_secret' => null, 'extra_config' => null]
        );

        return PlatformConnector::create([
            'user_id'          => $this->user_id(),
            'platform_id'      => $platform->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => base64_encode(self::TOKEN . ':' . self::SECRET),
            'platform_user_id' => (string) self::ACCOUNT_ID,
            'expires_at'       => null,
            'extra_config'     => array_merge([
                'account_name'       => 'Mi negocio SRL',
                'origin_id'          => self::ORIGIN_ID,
                'origin_label'       => 'Depósito Central',
                'origins'            => [['id' => self::ORIGIN_ID, 'label' => 'Depósito Central']],
                'bulto_default'      => ['peso' => 0.5, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10],
                'declarar_valor'     => true,
                'envio_gratis_desde' => null,
                'webhook_id'         => 777,
            ], $config),
        ]);
    }

    /**
     * La opción de Zipnova que "eligió" el comprador (Andreani a domicilio, §2.4 del plan).
     *
     * @return array<string, mixed>
     */
    protected function opcion_elegida()
    {
        return [
            'key'                => '56|standard_delivery|carrier_dropoff',
            'carrier_id'         => 56,
            'carrier_name'       => 'Andreani',
            'carrier_logo'       => 'https://cdn.zipnova.com/carriers/andreani.png',
            'service_type'       => 'standard_delivery',
            'service_name'       => 'Envío a domicilio',
            'logistic_type'      => 'carrier_dropoff',
            'es_punto_de_retiro' => false,
            'point_id'           => null,
            'precio'             => 6473.5,
            'precio_original'    => 6473.5,
            'envio_gratis'       => false,
            'estimated_delivery' => '2026-09-18T23:59:59+00:00',
            'dias_min'           => 3,
            'dias_max'           => 3,
            'tags'               => [],
            'puntos_de_retiro'   => [],
        ];
    }

    /**
     * El destinatario y su dirección, con la forma de §2.4 del plan.
     *
     * @return array<string, mixed>
     */
    protected function destino()
    {
        return [
            'nombre'        => 'Juan',
            'apellido'      => 'Pérez',
            'documento'     => '30111222',
            'email'         => 'juan@example.com',
            'telefono'      => '+5493511234567',
            'calle'         => 'Av. Colón',
            'numero'        => '123',
            'piso_depto'    => '4 B',
            'localidad'     => 'Córdoba',
            'provincia'     => 'Córdoba',
            'codigo_postal' => '5000',
            'referencia'    => 'portón negro',
            'lat'           => -31.41,
            'lng'           => -64.18,
            'point_id'      => null,
        ];
    }

    /**
     * Un pedido "Sin confirmar" del fixture, con envío por Zipnova elegido y destino completo,
     * tal como lo deja `tienda-api`.
     *
     * @param int|null $client_id Cliente del ERP asociado al comprador.
     * @return \App\Models\Order
     */
    protected function pedido_con_envio($client_id = null)
    {
        $pedido = $this->crear_pedido($client_id);

        $pedido->deliver = 1;
        $pedido->envio_proveedor = 'zipnova';
        $pedido->envio_opcion = $this->opcion_elegida();
        $pedido->envio_destino = $this->destino();
        $pedido->envio_precio = 6473.5;
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * Fixture real del 201 de `POST /shipments` (misma forma que `GET /shipments/{id}`), con
     * cambios encima.
     *
     * @param array<string, mixed> $cambios
     * @return array
     */
    protected function fixture_envio(array $cambios = [])
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../Integraciones/fixtures/zipnova_shipment.json'), true);

        return array_merge($fixture, $cambios);
    }

    /**
     * Una fila de `envios` ya generada en Zipnova para el pedido, directo en la base.
     *
     * @param \App\Models\Order $pedido
     * @param array<string, mixed> $atributos
     * @return Envio
     */
    protected function envio_generado($pedido, array $atributos = [])
    {
        return Envio::create(array_merge([
            'user_id'            => $this->user_id(),
            'order_id'           => $pedido->id,
            'proveedor'          => Envio::PROVEEDOR_ZIPNOVA,
            'proveedor_envio_id' => self::SHIPMENT_ID,
            'external_id'        => 'CC-' . $this->user_id() . '-' . $pedido->id,
            'account_id'         => (string) self::ACCOUNT_ID,
            'carrier_id'         => '56',
            'carrier_name'       => 'Andreani',
            'service_type'       => 'standard_delivery',
            'status'             => 'new',
            'status_name'        => 'Procesando',
            'destino'            => $this->destino(),
        ], $atributos));
    }

    /**
     * Fila de `envios` del pedido, la más nueva.
     *
     * @param \App\Models\Order $pedido
     * @return Envio|null
     */
    protected function envio_del_pedido($pedido)
    {
        return Envio::where('order_id', $pedido->id)->orderBy('id', 'DESC')->first();
    }

    /**
     * 1. Generar crea la fila con los campos del 201 y manda a Zipnova el payload correcto:
     *    external_id, cuenta, depósito, valor declarado, destino mapeado e ítems en unidades
     *    enteras (un ítem por unidad, con el bulto por defecto para el artículo sin medidas).
     *
     * @return void
     */
    public function test_generar_crea_el_envio_y_manda_el_payload_correcto()
    {
        $this->conectar_zipnova();

        // El martillo tiene peso y alto cargados (kg y cm, decimales); la pinza no tiene nada y
        // cae al bulto por defecto del comercio.
        $martillo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $martillo->peso = 1.234;
        $martillo->alto = 12.4;
        $martillo->ancho = null;
        $martillo->profundidad = null;
        $martillo->timestamps = false;
        $martillo->save();

        $pedido = $this->pedido_con_envio();

        // Zipnova devuelve en el 201 el mismo external_id que se le mandó: la fixture lo imita.
        Http::fake([
            self::BASE_URL . '/shipments' => Http::response($this->fixture_envio(['external_id' => 'CC-' . $this->user_id() . '-' . $pedido->id]), 201),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $respuesta = $this->postJson('api/envio/generar/' . $pedido->id);

        $respuesta->assertStatus(200);

        $model = $respuesta->json('model');

        $this->assertSame($pedido->id, $model['id'], 'generar tiene que responder el pedido completo.');
        $this->assertArrayHasKey('envio', $model, 'El pedido no trae "envio": falta en withAll.');

        $envio = $model['envio'];

        $this->assertSame(self::SHIPMENT_ID, $envio['proveedor_envio_id']);
        $this->assertSame('CC-' . $this->user_id() . '-' . $pedido->id, $envio['external_id']);
        $this->assertSame('new', $envio['status']);
        $this->assertSame('Procesando', $envio['status_name']);
        $this->assertSame('Andreani', $envio['carrier_name']);
        $this->assertSame('56', $envio['carrier_id']);
        $this->assertSame('standard_delivery', $envio['service_type']);
        $this->assertSame('carrier_dropoff', $envio['logistic_type']);
        $this->assertSame('https://tracking.zipnova.com/987654', $envio['tracking_url']);
        $this->assertSame('https://www.andreani.com/envio/TRK-9876543210', $envio['tracking_external_url']);
        $this->assertSame('TRK-9876543210', $envio['carrier_tracking_id']);
        $this->assertSame('REM-123456', $envio['delivery_id']);
        $this->assertSame(6473.5, (float) $envio['price_incl_tax']);
        $this->assertSame(5350.0, (float) $envio['price']);
        $this->assertNotNull($envio['estimated_delivery'], 'estimated_delivery no se guardó.');
        $this->assertNotNull($envio['ultima_sincronizacion']);
        $this->assertNull($envio['error_message']);
        $this->assertSame('Juan', $envio['destino']['nombre']);

        $this->assertSame(1, Envio::where('order_id', $pedido->id)->count());

        $fila = $this->envio_del_pedido($pedido);
        $this->assertSame($this->user_id(), (int) $fila->user_id);
        $this->assertSame((string) self::ACCOUNT_ID, $fila->account_id);
        $this->assertSame('new', $fila->respuesta['status'], 'El payload completo de Zipnova no quedó en "respuesta".');
        $this->assertSame('2026-09-18', $fila->estimated_delivery->setTimezone('UTC')->toDateString());

        $user_id = $this->user_id();
        $total = $this->total_esperado();

        Http::assertSent(function ($request) use ($pedido, $user_id, $total) {
            if ($request->method() !== 'POST' || $request->url() !== self::BASE_URL . '/shipments') {
                return false;
            }

            $body = $request->data();

            $ok = $body['external_id'] === 'CC-' . $user_id . '-' . $pedido->id
                && $body['account_id'] === self::ACCOUNT_ID
                && $body['source'] === 'comerciocity'
                && $body['service_type'] === 'standard_delivery'
                && $body['logistic_type'] === 'carrier_dropoff'
                && $body['carrier_id'] === 56
                && $body['origin_id'] === self::ORIGIN_ID
                && (float) $body['declared_value'] === (float) $total
                && $body['destination']['name'] === 'Juan Pérez'
                && $body['destination']['document'] === '30111222'
                && $body['destination']['email'] === 'juan@example.com'
                && $body['destination']['phone'] === '+5493511234567'
                && $body['destination']['street'] === 'Av. Colón'
                && $body['destination']['street_number'] === '123'
                && $body['destination']['street_extras'] === '4 B portón negro'
                && $body['destination']['city'] === 'Córdoba'
                && $body['destination']['state'] === 'Córdoba'
                && $body['destination']['zipcode'] === '5000'
                && !array_key_exists('sku', $body['items'][0]);

            if (!$ok) {
                return false;
            }

            // 2 martillos + 3 pinzas = 5 ítems, uno por unidad.
            if (count($body['items']) !== 5) {
                return false;
            }

            foreach ($body['items'] as $item) {
                foreach (['weight', 'height', 'width', 'length'] as $campo) {
                    if (!is_int($item[$campo])) {
                        return false;
                    }
                }
            }

            // El martillo: 1,234 kg -> 1234 g; 12,4 cm -> 13; sin ancho/largo -> 10 del bulto por defecto.
            $martillo = $body['items'][0];
            $pinza = $body['items'][2];

            return $martillo['weight'] === 1234
                && $martillo['height'] === 13
                && $martillo['width'] === 10
                && $martillo['length'] === 10
                && $martillo['description'] === TestingFerreteriaSeeder::ARTICULO_CENTINELA
                && $pinza['weight'] === 500
                && $pinza['height'] === 10
                && $pinza['description'] === 'Pinza';
        });
    }

    /**
     * 2a. Sin conector: 422 con el mensaje que dice qué hacer, sin fila y sin llamar a Zipnova.
     *
     * @return void
     */
    public function test_sin_conector_da_422_y_no_crea_fila()
    {
        Http::fake();

        $pedido = $this->pedido_con_envio();

        $respuesta = $this->postJson('api/envio/generar/' . $pedido->id);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no tiene Zipnova conectado', $respuesta->json('message'));

        $this->assertSame(0, Envio::where('order_id', $pedido->id)->count());

        Http::assertNothingSent();
    }

    /**
     * 2b. Un pedido sin opción de envío o con destino incompleto no se manda a Zipnova.
     *
     * @return void
     */
    public function test_sin_opcion_o_con_destino_incompleto_da_422()
    {
        Http::fake();

        $this->conectar_zipnova();

        $sin_opcion = $this->crear_pedido(null);

        $respuesta = $this->postJson('api/envio/generar/' . $sin_opcion->id);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('forma de envío', $respuesta->json('message'));

        $incompleto = $this->pedido_con_envio();
        $destino = $this->destino();
        $destino['documento'] = '12';
        $destino['numero'] = '';
        $incompleto->envio_destino = $destino;
        $incompleto->save();

        $respuesta = $this->postJson('api/envio/generar/' . $incompleto->id);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('documento', $respuesta->json('message'));
        $this->assertStringContainsString('numero', $respuesta->json('message'));

        $this->assertSame(0, Envio::whereIn('order_id', [$sin_opcion->id, $incompleto->id])->count());

        Http::assertNothingSent();
    }

    /**
     * 2c. Si Zipnova rechaza el envío, la fila queda en `error` con el motivo y el 422 lo
     *     devuelve; el reintento reutiliza ESA fila y la deja generada.
     *
     * @return void
     */
    public function test_fallo_de_zipnova_deja_la_fila_en_error_y_el_reintento_la_reutiliza()
    {
        Http::fake([
            self::BASE_URL . '/shipments' => Http::sequence()
                ->push(['message' => 'The declared value is invalid', 'errors' => ['declared_value' => ['must be greater than 0']]], 400)
                ->push($this->fixture_envio(), 201),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio();

        $respuesta = $this->postJson('api/envio/generar/' . $pedido->id);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('Zipnova rechazó la operación', $respuesta->json('message'));
        $this->assertStringContainsString('declared value', $respuesta->json('message'));

        $fila = $this->envio_del_pedido($pedido);

        $this->assertNotNull($fila, 'El fallo de Zipnova no dejó la fila en error para poder reintentar.');
        $this->assertSame(Envio::STATUS_ERROR, $fila->status);
        $this->assertSame('No se pudo generar', $fila->status_name);
        $this->assertNull($fila->proveedor_envio_id);
        $this->assertStringContainsString('declared value', $fila->error_message);
        $this->assertFalse($fila->esta_vivo());

        // Reintento desde el mismo botón.
        $this->postJson('api/envio/generar/' . $pedido->id)->assertStatus(200);

        $this->assertSame(1, Envio::where('order_id', $pedido->id)->count(), 'El reintento creó una segunda fila en vez de reutilizar la de error.');

        $fila->refresh();

        $this->assertSame(self::SHIPMENT_ID, $fila->proveedor_envio_id);
        $this->assertSame('new', $fila->status);
        $this->assertNull($fila->error_message);
        $this->assertTrue($fila->esta_vivo());

        // Con un envío vivo no se genera otro.
        $respuesta = $this->postJson('api/envio/generar/' . $pedido->id);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya tiene un envío generado', $respuesta->json('message'));
        $this->assertSame(1, Envio::where('order_id', $pedido->id)->count());

        Http::assertSentCount(2);
    }

    /**
     * 3a. Confirmar el pedido genera el envío solo: la venta nace, el pedido queda confirmado y
     *     la respuesta ya trae el envío generado.
     *
     * @return void
     */
    public function test_confirmar_el_pedido_genera_el_envio_solo()
    {
        Http::fake([
            self::BASE_URL . '/shipments' => Http::response($this->fixture_envio(), 201),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio($this->cliente_cc()->id);

        $respuesta = $this->putJson('api/order/' . $pedido->id, $this->payload_de_estado('Confirmado'));

        $respuesta->assertStatus(200);

        $this->assertNotNull(Sale::where('order_id', $pedido->id)->first(), 'Confirmar no creó la venta.');

        $fila = $this->envio_del_pedido($pedido);

        $this->assertNotNull($fila, 'Confirmar el pedido no generó el envío en Zipnova.');
        $this->assertSame(self::SHIPMENT_ID, $fila->proveedor_envio_id);
        $this->assertSame('new', $fila->status);
        $this->assertNotNull($fila->sale_id, 'El envío no quedó atado a la venta que nació de la confirmación.');

        $this->assertSame(self::SHIPMENT_ID, $respuesta->json('model.envio.proveedor_envio_id'), 'La respuesta del update no trae el envío recién generado.');

        // Avanzar de estado después no genera otro.
        $this->putJson('api/order/' . $pedido->id, $this->payload_de_estado('Terminado'))->assertStatus(200);

        $this->assertSame(1, Envio::where('order_id', $pedido->id)->count());
        Http::assertSentCount(1);
    }

    /**
     * 3b. 🔴 La confirmación NO falla si Zipnova falla: el pedido queda confirmado, la venta
     *     nace, y el envío queda en `error` con el motivo para reintentar desde el modal.
     *
     * @return void
     */
    public function test_confirmar_no_falla_si_zipnova_falla()
    {
        Http::fake([
            '*' => Http::response(['message' => 'Internal error'], 500),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio($this->cliente_cc()->id);

        $respuesta = $this->putJson('api/order/' . $pedido->id, $this->payload_de_estado('Confirmado'));

        $respuesta->assertStatus(200);

        $this->assertSame('Confirmado', $pedido->fresh()->order_status->name, 'Un fallo de Zipnova revirtió la confirmación del pedido.');
        $this->assertNotNull(Sale::where('order_id', $pedido->id)->first(), 'Un fallo de Zipnova dejó al pedido confirmado sin venta.');

        $fila = $this->envio_del_pedido($pedido);

        $this->assertNotNull($fila);
        $this->assertSame(Envio::STATUS_ERROR, $fila->status);
        $this->assertStringContainsString('no está respondiendo', $fila->error_message);

        $this->assertSame(Envio::STATUS_ERROR, $respuesta->json('model.envio.status'));
    }

    /**
     * 3c. Compatibilidad hacia atrás: un pedido sin `envio_opcion` (retiro en el local, zona
     *     propia, o llegado desde una tienda vieja) se confirma sin tocar Zipnova.
     *
     * @return void
     */
    public function test_confirmar_un_pedido_sin_opcion_de_envio_no_toca_zipnova()
    {
        Http::fake();

        $this->conectar_zipnova();

        $pedido = $this->crear_pedido($this->cliente_cc()->id);

        $this->putJson('api/order/' . $pedido->id, $this->payload_de_estado('Confirmado'))->assertStatus(200);

        $this->assertNotNull(Sale::where('order_id', $pedido->id)->first());
        $this->assertSame(0, Envio::where('order_id', $pedido->id)->count());

        Http::assertNothingSent();
    }

    /**
     * 3d. Cancelar el pedido cancela el envío vivo en Zipnova y sincroniza su estado.
     *
     * @return void
     */
    public function test_cancelar_el_pedido_cancela_el_envio()
    {
        Http::fake([
            self::BASE_URL . '/shipments/' . self::SHIPMENT_ID . '/cancel' => Http::response(['shipment_id' => 987654, 'success' => true, 'result' => 'canceled'], 200),
            self::BASE_URL . '/shipments/' . self::SHIPMENT_ID => Http::response($this->fixture_envio(['status' => 'cancelled', 'status_name' => 'Cancelado']), 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio();
        $envio = $this->envio_generado($pedido);

        $this->putJson('api/order/' . $pedido->id, $this->payload_de_estado('Cancelado'))->assertStatus(200);

        $envio->refresh();

        $this->assertSame('cancelled', $envio->status);
        $this->assertTrue($envio->esta_cerrado());

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/shipments/' . self::SHIPMENT_ID . '/cancel';
        });
    }

    /**
     * 4a. El webhook con un `shipment_id` conocido re-consulta el envío y guarda el estado
     *     nuevo (no confía en el que viene en el aviso).
     *
     * @return void
     */
    public function test_webhook_con_shipment_id_conocido_sincroniza()
    {
        Http::fake([
            self::BASE_URL . '/shipments/' . self::SHIPMENT_ID => Http::response($this->fixture_envio([
                'status'         => 'in_transit',
                'status_name'    => 'En camino',
                'substatus_code' => 'on_route',
                'substatus_name' => 'En ruta al destino',
            ]), 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio();
        $envio = $this->envio_generado($pedido);

        $respuesta = $this->postJson('api/zipnova/webhook', [
            'topic'     => 'status',
            'timestamp' => '2026-09-16T10:00:00+00:00',
            'data'      => [
                'account_id'  => self::ACCOUNT_ID,
                'shipment_id' => (int) self::SHIPMENT_ID,
                'external_id' => $envio->external_id,
                'status'      => 'delivered',
                'status_code' => 'delivered',
                'direction'   => 'outbound',
            ],
        ]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('ok'));

        $envio->refresh();

        $this->assertSame('in_transit', $envio->status, 'El webhook no sincronizó el estado (o confió en el del aviso, que decía delivered).');
        $this->assertSame('En camino', $envio->status_name);
        $this->assertSame('on_route', $envio->substatus_code);
        $this->assertSame('En ruta al destino', $envio->substatus_name);
        $this->assertNotNull($envio->ultima_sincronizacion);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === self::BASE_URL . '/shipments/' . self::SHIPMENT_ID
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode(self::TOKEN . ':' . self::SECRET));
        });
    }

    /**
     * 4b. Un aviso de un envío que no está en esta instancia (o sin `shipment_id`) responde 200
     *     igual, sin llamar a Zipnova: un 4xx haría que Zipnova reintente 12 horas.
     *
     * @return void
     */
    public function test_webhook_desconocido_responde_200_sin_llamar_a_zipnova()
    {
        Http::fake();

        $this->postJson('api/zipnova/webhook', ['topic' => 'status', 'data' => ['account_id' => 1, 'shipment_id' => 'no-existe']])
             ->assertStatus(200)
             ->assertJson(['ok' => true]);

        $this->postJson('api/zipnova/webhook', ['topic' => 'status'])
             ->assertStatus(200)
             ->assertJson(['ok' => true]);

        $this->postJson('api/zipnova/webhook', [])
             ->assertStatus(200);

        Http::assertNothingSent();
    }

    /**
     * 4c. `POST envio/{id}/sincronizar` trae el estado actual; `GET envio/{id}` lo muestra; un
     *     envío de otro comercio es 404.
     *
     * @return void
     */
    public function test_sincronizar_y_ver_scopean_por_comercio()
    {
        Http::fake([
            self::BASE_URL . '/shipments/' . self::SHIPMENT_ID => Http::response($this->fixture_envio(['status' => 'delivered', 'status_name' => 'Entregado']), 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio();
        $envio = $this->envio_generado($pedido);

        $respuesta = $this->postJson('api/envio/' . $envio->id . '/sincronizar');

        $respuesta->assertStatus(200);
        $this->assertSame('delivered', $respuesta->json('model.status'));
        $this->assertSame('Entregado', $respuesta->json('model.status_name'));
        $this->assertTrue($envio->fresh()->esta_cerrado());

        $this->getJson('api/envio/' . $envio->id)
             ->assertStatus(200)
             ->assertJsonPath('model.id', $envio->id)
             ->assertJsonPath('model.status', 'delivered');

        $ajeno = $this->envio_generado($pedido, ['user_id' => $this->user_id() + 1000000, 'proveedor_envio_id' => '111']);

        $this->getJson('api/envio/' . $ajeno->id)->assertStatus(404);
        $this->postJson('api/envio/' . $ajeno->id . '/sincronizar')->assertStatus(404);
        $this->postJson('api/envio/' . $ajeno->id . '/cancelar')->assertStatus(404);
        $this->getJson('api/envio/' . $ajeno->id . '/etiqueta')->assertStatus(404);

        Http::assertSentCount(1);
    }

    /**
     * 4d. `POST envio/{id}/cancelar` llama al endpoint de cancelación y sincroniza; un envío ya
     *     cerrado no se puede cancelar.
     *
     * @return void
     */
    public function test_cancelar_llama_al_endpoint_y_sincroniza()
    {
        Http::fake([
            self::BASE_URL . '/shipments/' . self::SHIPMENT_ID . '/cancel' => Http::response(['shipment_id' => 987654, 'success' => true, 'result' => 'canceled'], 200),
            self::BASE_URL . '/shipments/' . self::SHIPMENT_ID => Http::response($this->fixture_envio(['status' => 'cancelled', 'status_name' => 'Cancelado']), 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio();
        $envio = $this->envio_generado($pedido);

        $respuesta = $this->postJson('api/envio/' . $envio->id . '/cancelar');

        $respuesta->assertStatus(200);
        $this->assertSame('cancelled', $respuesta->json('model.status'));
        $this->assertSame('cancelled', $envio->fresh()->status);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/shipments/' . self::SHIPMENT_ID . '/cancel';
        });

        // Ya está cerrado: no se vuelve a pedir la cancelación.
        $respuesta = $this->postJson('api/envio/' . $envio->id . '/cancelar');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no se puede cancelar', $respuesta->json('message'));

        Http::assertSentCount(2);

        // Después de cancelar, el pedido puede generar otro envío.
        $this->assertFalse($envio->fresh()->esta_vivo());
    }

    /**
     * 4e. La etiqueta vuelve como `application/pdf` inline a partir del base64 que manda
     *     Zipnova; el 409 de "todavía no está lista" se traduce a un 422 que dice qué hacer.
     *
     * @return void
     */
    public function test_etiqueta_devuelve_el_pdf_desde_el_base64()
    {
        $pdf = '%PDF-1.4 etiqueta de prueba';

        Http::fake([
            self::BASE_URL . '/shipments/' . self::SHIPMENT_ID . '/label.pdf*' => Http::sequence()
                ->push(['message' => 'Label not ready yet'], 409)
                ->push(['format' => 'pdf', 'content' => base64_encode($pdf)], 200),
            '*' => Http::response(['message' => 'ruta no fakeada en el test'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio();
        $envio = $this->envio_generado($pedido);

        $respuesta = $this->getJson('api/envio/' . $envio->id . '/etiqueta');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('todavía no está lista', $respuesta->json('message'));

        $respuesta = $this->get('api/envio/' . $envio->id . '/etiqueta');

        $respuesta->assertStatus(200);
        $respuesta->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline; filename="etiqueta-envio-', $respuesta->headers->get('Content-Disposition'));
        $this->assertSame($pdf, $respuesta->getContent());
    }

    /**
     * 4f. El comando `zipnova:sincronizar-envios` consulta solo los envíos en curso: no los
     *     finales, no los que nunca se generaron, no los sincronizados hace un rato.
     *
     * @return void
     */
    public function test_el_comando_sincroniza_solo_los_no_finales()
    {
        Http::fake([
            self::BASE_URL . '/shipments/' . self::SHIPMENT_ID => Http::response($this->fixture_envio(['status' => 'shipped', 'status_name' => 'Despachado']), 200),
            self::BASE_URL . '/shipments/*' => Http::response($this->fixture_envio(['id' => 111, 'status' => 'in_transit', 'status_name' => 'En camino']), 200),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_con_envio();

        $en_curso = $this->envio_generado($pedido, ['status' => 'new', 'ultima_sincronizacion' => null]);
        $viejo = $this->envio_generado($pedido, ['proveedor_envio_id' => '111', 'status' => 'in_transit', 'ultima_sincronizacion' => Carbon::now()->subHours(2)]);
        $reciente = $this->envio_generado($pedido, ['proveedor_envio_id' => '222', 'status' => 'in_transit', 'ultima_sincronizacion' => Carbon::now()->subMinutes(5)]);
        $entregado = $this->envio_generado($pedido, ['proveedor_envio_id' => '333', 'status' => 'delivered', 'ultima_sincronizacion' => null]);
        $cancelado = $this->envio_generado($pedido, ['proveedor_envio_id' => '444', 'status' => 'cancelled', 'ultima_sincronizacion' => null]);
        $error = $this->envio_generado($pedido, ['proveedor_envio_id' => null, 'status' => Envio::STATUS_ERROR, 'ultima_sincronizacion' => null]);

        $this->artisan('zipnova:sincronizar-envios')->assertExitCode(0);

        Http::assertSentCount(2);

        $this->assertSame('shipped', $en_curso->fresh()->status, 'El envío en curso no se sincronizó.');
        $this->assertSame('in_transit', $viejo->fresh()->status);
        $this->assertTrue($viejo->fresh()->ultima_sincronizacion->gt(Carbon::now()->subMinute()), 'El envío sincronizado hace 2 horas no se volvió a consultar.');

        $this->assertSame('in_transit', $reciente->fresh()->status);
        $this->assertTrue($reciente->fresh()->ultima_sincronizacion->lt(Carbon::now()->subMinutes(4)), 'El envío sincronizado hace 5 minutos se consultó de nuevo.');
        $this->assertSame('delivered', $entregado->fresh()->status);
        $this->assertNull($entregado->fresh()->ultima_sincronizacion, 'Un envío entregado (final) se consultó.');
        $this->assertNull($cancelado->fresh()->ultima_sincronizacion, 'Un envío cancelado (final) se consultó.');
        $this->assertNull($error->fresh()->ultima_sincronizacion, 'Un envío que nunca se generó se consultó.');

        Http::assertSent(function ($request) {
            return $request->url() === self::BASE_URL . '/shipments/' . self::SHIPMENT_ID;
        });
        Http::assertSent(function ($request) {
            return $request->url() === self::BASE_URL . '/shipments/111';
        });
    }

    /**
     * 4g. Sin conector el comando no llama a Zipnova y no revienta: el comercio se desconectó
     *     con envíos en curso.
     *
     * @return void
     */
    public function test_el_comando_saltea_un_comercio_desconectado()
    {
        Http::fake();

        $pedido = $this->pedido_con_envio();
        $this->envio_generado($pedido, ['status' => 'new']);
        $this->envio_generado($pedido, ['proveedor_envio_id' => '111', 'status' => 'in_transit']);

        $this->artisan('zipnova:sincronizar-envios')->assertExitCode(0);

        Http::assertNothingSent();
    }

    /**
     * 5. El pedido trae `envio` (withAll, el mismo que usa el listado), con el más nuevo cuando
     *    hay más de uno, y los json del envío se serializan como objetos (casts).
     *
     * @return void
     */
    public function test_el_pedido_trae_el_envio_mas_nuevo()
    {
        $pedido = $this->pedido_con_envio();

        $this->envio_generado($pedido, ['proveedor_envio_id' => '100', 'status' => 'cancelled']);
        $nuevo = $this->envio_generado($pedido, ['proveedor_envio_id' => '200', 'status' => 'new']);

        $respuesta = $this->getJson('api/order/' . $pedido->id);

        $respuesta->assertStatus(200);

        $encontrado = $respuesta->json('model');

        $this->assertSame($pedido->id, $encontrado['id']);
        $this->assertSame($nuevo->id, $encontrado['envio']['id'], 'El pedido no trae el envío más nuevo.');
        $this->assertSame('200', $encontrado['envio']['proveedor_envio_id']);
        $this->assertSame('Andreani', $encontrado['envio_opcion']['carrier_name'], 'envio_opcion no se serializa como json (falta el cast).');
        $this->assertSame('Juan', $encontrado['envio_destino']['nombre']);
    }
}
