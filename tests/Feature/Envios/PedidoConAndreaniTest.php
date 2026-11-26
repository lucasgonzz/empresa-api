<?php

namespace Tests\Feature\Envios;

use App\Http\Controllers\EnvioController;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelDuenoIaHelper;
use App\Models\Envio;
use App\Models\Order;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\Sale;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión envios-andreani (25/11/2026), CA12 — TESTS DE ACEPTACIÓN de la parte de `empresa-api` del PEDIDO que
 * nace con envío por Andreani desde la tienda (PLAN §2.4).
 *
 * Decisión de Lucas (25/11): esta misión NO genera órdenes ni etiquetas por la API de Andreani. El pedido queda
 * «A despachar en Andreani» y el comercio lo carga a mano en el portal de Andreani.
 *
 * Qué fija este archivo:
 *
 * 1. `Order::envio_va_por_andreani()` es true con `envio_opcion` no vacío y `envio_proveedor = 'andreani'`;
 *    `envio_va_por_zipnova()` sigue siendo false para ese pedido (y `va_por_andreani` es false para los de Zipnova
 *    y para los de forma propia).
 * 2. Confirmar un pedido Andreani (`PUT order/{id}`) NO llama a Zipnova ni a Andreani y no crea fila en `envios`;
 *    la venta nace igual. Esto vale aunque el comercio TENGA Zipnova conectado.
 * 3. `POST envio/generar/{id}` sobre un pedido Andreani es 422 con un mensaje propio que manda al portal de
 *    Andreani (no el «no tiene una forma de envío de Zipnova elegida»).
 * 4. El aviso de pedido nuevo al dueño dice «Envío por Andreani» (hoy diría «Envío a domicilio»).
 *
 * 🔴 Hoy estos tests están en ROJO a propósito: `Order::envio_va_por_andreani()` no existe, `generar` responde el
 * mensaje de Zipnova y el aviso dice «Envío a domicilio». (La parte de «no llama a Zipnova al confirmar» ya se
 * cumple hoy por construcción: `envio_va_por_zipnova()` excluye cualquier proveedor que no sea Zipnova; el test
 * la deja fijada como regresión y es rojo por la llamada a `envio_va_por_andreani()`.)
 *
 * Todo con `Http::fake()`: nada sale a la red. PHP 7.4: sin match, sin ?->, sin str_contains.
 *
 * @group envios
 */
class PedidoConAndreaniTest extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** Host de la API de Zipnova, fijado por config para que los fakes matcheen siempre. */
    const BASE_URL_ZIPNOVA = 'https://api.zipnova.com.ar/v2';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zipnova.base_url' => self::BASE_URL_ZIPNOVA]);
        config(['services.andreani.base_url' => 'https://apis.andreani.com']);

        $this->sembrar_estados_de_pedido();
    }

    /**
     * Conecta Zipnova para el comercio del fixture: sirve para que, si el código se equivocara y mandara el pedido
     * Andreani a Zipnova, el test lo note (con el conector ausente saldría una fila en error y no una llamada).
     *
     * @return PlatformConnector
     */
    protected function conectar_zipnova()
    {
        $platform = Platform::firstOrCreate(
            ['slug' => Platform::SLUG_ZIPNOVA],
            ['name' => 'Zipnova', 'client_id' => null, 'client_secret' => null, 'extra_config' => null]
        );

        return PlatformConnector::create([
            'user_id'          => $this->user_id(),
            'platform_id'      => $platform->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => base64_encode('tok-de-prueba-1234567890:sec-de-prueba-0987654321'),
            'platform_user_id' => '3355',
            'expires_at'       => null,
            'extra_config'     => [
                'account_name'   => 'Mi negocio SRL',
                'origin_id'      => 9323,
                'origin_label'   => 'Depósito Central',
                'origins'        => [['id' => 9323, 'label' => 'Depósito Central']],
                'bulto_default'  => ['peso' => 0.5, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10],
                'declarar_valor' => true,
                'webhook_id'     => 777,
            ],
        ]);
    }

    /**
     * La opción de Andreani que «eligió» el comprador, con la forma del PLAN §2.2 / §10.1.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    protected function opcion_andreani(array $cambios = [])
    {
        return array_merge([
            'key'                => 'andreani|domicilio',
            'proveedor'          => 'andreani',
            'carrier_id'         => null,
            'carrier_name'       => 'Andreani',
            'carrier_logo'       => null,
            'service_type'       => 'domicilio',
            'service_name'       => 'Envío a domicilio',
            'logistic_type'      => 'andreani',
            'es_punto_de_retiro' => false,
            'point_id'           => null,
            'precio'             => 779.51,
            'precio_original'    => 779.51,
            'envio_gratis'       => false,
            'estimated_delivery' => null,
            'dias_min'           => null,
            'dias_max'           => null,
            'tags'               => [],
            'puntos_de_retiro'   => [],
            'andreani'           => [
                'tipo'                  => 'domicilio',
                'valor_declarado_total' => 0,
                'bultos'                => [['kilos' => 10, 'alto_cm' => 100, 'ancho_cm' => 100, 'largo_cm' => 100, 'volumen_cm3' => 1000000, 'valor_declarado' => 0]],
            ],
        ], $cambios);
    }

    /**
     * Destino completo del comprador, como lo deja `tienda-api`.
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
     * Un pedido «Sin confirmar» con envío por Andreani, tal como lo deja `tienda-api`.
     *
     * @param int|null $client_id Cliente del ERP asociado al comprador.
     * @return Order
     */
    protected function pedido_andreani($client_id = null)
    {
        $pedido = $this->crear_pedido($client_id);

        $pedido->deliver = 1;
        $pedido->envio_proveedor = 'andreani';
        $pedido->envio_opcion = $this->opcion_andreani();
        $pedido->envio_destino = $this->destino();
        $pedido->envio_precio = 779.51;
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * Un pedido «Sin confirmar» con envío por Zipnova (Correo Argentino a domicilio), para comparar.
     *
     * @param int|null $client_id Cliente del ERP asociado al comprador.
     * @return Order
     */
    protected function pedido_zipnova($client_id = null)
    {
        $pedido = $this->crear_pedido($client_id);

        $pedido->deliver = 1;
        $pedido->envio_proveedor = 'zipnova';
        $pedido->envio_opcion = ['key' => '12|standard_delivery|carrier_dropoff', 'carrier_id' => 12, 'carrier_name' => 'Correo Argentino', 'precio' => 4840];
        $pedido->envio_destino = $this->destino();
        $pedido->envio_precio = 4840;
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * CA12 — `envio_va_por_andreani()` es true sólo para un pedido con opción y proveedor `andreani`; el de Zipnova,
     * el de proveedor vacío y el de forma propia (sin `envio_opcion`) dan false. Y `envio_va_por_zipnova()` no
     * cambia: el pedido Andreani sigue afuera.
     *
     * @return void
     */
    public function test_un_pedido_andreani_va_por_andreani_y_no_por_zipnova()
    {
        $andreani = $this->pedido_andreani();

        $this->assertTrue($andreani->envio_va_por_andreani(), 'Un pedido con proveedor andreani tiene que ir por Andreani.');
        $this->assertFalse($andreani->envio_va_por_zipnova(), 'Un pedido Andreani no puede ir por Zipnova.');

        $zipnova = $this->pedido_zipnova();

        $this->assertTrue($zipnova->envio_va_por_zipnova());
        $this->assertFalse($zipnova->envio_va_por_andreani(), 'Un pedido de Zipnova no va por Andreani.');

        // Compatibilidad del 14/8/2026: proveedor vacío = Zipnova, nunca Andreani.
        $vacio = $this->pedido_zipnova();
        $vacio->envio_proveedor = null;
        $vacio->save();

        $this->assertTrue($vacio->fresh()->envio_va_por_zipnova());
        $this->assertFalse($vacio->fresh()->envio_va_por_andreani());

        // Proveedor andreani pero sin opción elegida: no hay nada que despachar.
        $sin_opcion = $this->pedido_andreani();
        $sin_opcion->envio_opcion = null;
        $sin_opcion->save();

        $this->assertFalse($sin_opcion->fresh()->envio_va_por_andreani());

        // Forma de envío propia (sin envio_opcion ni proveedor).
        $propio = $this->crear_pedido(null);

        $this->assertFalse($propio->envio_va_por_andreani());
        $this->assertFalse($propio->envio_va_por_zipnova());
    }

    /**
     * CA12 — Confirmar el pedido Andreani (`PUT order/{id}` a «Confirmado») no llama a Zipnova ni a Andreani y no
     * crea fila en `envios`, aunque el comercio tenga Zipnova conectado; la venta nace igual.
     *
     * @return void
     */
    public function test_confirmar_pedido_andreani_no_genera_envio_en_zipnova()
    {
        Http::fake([
            '*' => Http::response(['message' => 'este test no admite ninguna llamada'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_andreani($this->cliente_cc()->id);

        $respuesta = $this->putJson('api/order/' . $pedido->id, $this->payload_de_estado('Confirmado'));

        $respuesta->assertStatus(200);

        $this->assertNotNull(Sale::where('order_id', $pedido->id)->first(), 'Confirmar no creó la venta.');
        $this->assertSame(0, Envio::where('order_id', $pedido->id)->count(), 'Un pedido Andreani no tiene que dejar fila en envios.');
        $this->assertTrue($pedido->fresh()->envio_va_por_andreani(), 'El pedido confirmado sigue siendo un pedido Andreani «a despachar».');

        Http::assertNothingSent();
    }

    /**
     * CA12 — `POST envio/generar/{id}` sobre un pedido Andreani (ya confirmado) es 422 con el mensaje propio que
     * manda al portal de Andreani, distinto del de «no tiene forma de envío de Zipnova», y no sale a ningún lado.
     *
     * @return void
     */
    public function test_generar_pedido_andreani_responde_422_con_mensaje_propio()
    {
        Http::fake([
            '*' => Http::response(['message' => 'este test no admite ninguna llamada'], 599),
        ]);

        $this->conectar_zipnova();

        $pedido = $this->pedido_andreani($this->cliente_cc()->id);
        $pedido->order_status_id = $this->estado('Confirmado')->id;
        $pedido->save();

        $respuesta = $this->postJson('api/envio/generar/' . $pedido->id);

        $respuesta->assertStatus(422);

        $mensaje = (string) $respuesta->json('message');

        $this->assertStringContainsString('Andreani', $mensaje, 'El mensaje tiene que nombrar a Andreani.');
        $this->assertStringContainsString('portal de Andreani', $mensaje, 'El mensaje tiene que mandar al portal de Andreani.');
        $this->assertNotSame(EnvioController::MENSAJE_SIN_ZIPNOVA, $mensaje, 'No corresponde el mensaje de Zipnova para un pedido Andreani.');

        $this->assertSame(0, Envio::where('order_id', $pedido->id)->count());

        Http::assertNothingSent();
    }

    /**
     * CA12 — El texto de entrega del aviso de pedido nuevo al dueño dice «Envío por Andreani»; el de Zipnova sigue
     * diciendo «Envío por correo» y el retiro por local no cambia. (`entrega_del_pedido` es protegido: se lo
     * invoca por reflexión, igual que lo usan los textos del aviso.)
     *
     * @return void
     */
    public function test_aviso_al_dueno_dice_envio_por_andreani()
    {
        $this->assertSame('Envío por Andreani', $this->entrega_del_aviso($this->pedido_andreani()));
        $this->assertSame('Envío por correo', $this->entrega_del_aviso($this->pedido_zipnova()), 'El aviso de un pedido de Zipnova no puede cambiar.');
        $this->assertSame('Retiro por local', $this->entrega_del_aviso($this->crear_pedido(null)));
    }

    /**
     * Texto «Entrega: …» que arma `AvisosDelDuenoIaHelper` para un pedido.
     *
     * @param Order $pedido
     * @return string
     */
    protected function entrega_del_aviso(Order $pedido)
    {
        $metodo = new ReflectionMethod(AvisosDelDuenoIaHelper::class, 'entrega_del_pedido');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $pedido);
    }
}
