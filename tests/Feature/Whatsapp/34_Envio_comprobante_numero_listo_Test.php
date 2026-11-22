<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\SendSaleWhatsappJob;
use App\Models\Article;
use App\Models\Client;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;
use Tests\TestCase;

/**
 * Misión whatsapp-venta-numero-migrado: el envío automático del comprobante de venta por WhatsApp
 * sólo se encola cuando el número del dueño está "configurado y migrado".
 *
 * REGLA ÚNICA (no hay una columna "migrado"; es lo que queda en `whatsapp_bot_configs` cuando
 * ComercioCity carga la conexión dedicada por `admin-sync/whatsapp-bot/conexion`):
 *   fila ACTIVA del dueño (`WhatsappBotConfig::getForUser`) Y `conexion_configurada`
 *   (phone_number_id, kapso_api_key y webhook_secret no vacíos).
 *
 * Antes de la misión `SaleController` encolaba `SendSaleWhatsappJob` siempre y el job sólo
 * revalidaba "fila activa + auto_send_sale_pdf": una fila activa sin credenciales pasaba.
 *
 * Los tests pegan en el endpoint REAL de la venta (POST api/sale y PUT api/sale/{id}) con
 * `Queue::fake()` y miden el estado resultante (qué jobs quedaron encolados). Las credenciales se
 * cargan con `create()` directo porque `$hidden` no afecta la escritura.
 *
 * 🔴 Fuera de alcance, a propósito: el despacho en `SaleController` NO revisa la extensión
 * `agentes_ia` (la revalida el job). Acá el dueño la tiene para que el caso "sí encola" sea realista.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Envio_comprobante_numero_listo_Test extends TestCase
{
    use DatabaseTransactions;
    use OtorgaAgentesIa;

    /** @var int Usuario dueño del fixture de testing. */
    const USER_ID = 500;

    /** @var int Efectivo: método de pago sembrado fijo. */
    const EFECTIVO = 3;

    /** @var int Precio del renglón. */
    const PRECIO = 100;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 2;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\PriceType */
    protected $lista;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales ni la red: ningún test de esta suite sale afuera.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        // El módulo de WhatsApp es de `agentes_ia`: el dueño de la venta la paga.
        $this->otorgar_agentes_ia($this->user);

        // Parte sin ninguna fila de config del dueño: cada test arma la suya.
        WhatsappBotConfig::where('user_id', self::USER_ID)->delete();

        $this->actingAs($this->user, 'web');

        $this->lista = PriceType::create([
            'name'     => 'zz Lista (comprobante numero listo)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        $this->article = Article::create([
            'name'        => 'zz Articulo comprobante numero listo',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'status'      => 'active',
        ]);
    }

    /**
     * Crea la fila de config del dueño con las credenciales que se le indiquen.
     *
     * @param  array  $overrides  Columnas a pisar (is_active, phone_number_id, etc.).
     * @return \App\Models\WhatsappBotConfig
     */
    protected function crear_config($overrides = [])
    {
        return WhatsappBotConfig::create(array_merge([
            'user_id'            => self::USER_ID,
            'is_active'          => true,
            'phone_number_id'    => '5491100000034',
            'kapso_api_key'      => 'kapso-34',
            'webhook_secret'     => 'secreto-34',
            'auto_send_sale_pdf' => true,
        ], $overrides));
    }

    /**
     * Payload de POST api/sale de una venta de mostrador cobrada en efectivo (molde de Sales/33).
     *
     * @return array
     */
    protected function payload_venta()
    {
        $total = self::PRECIO * self::CANTIDAD;

        return [
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => self::EFECTIVO,
            'selected_payment_methods'         => [],
            'price_type_id'                    => $this->lista->id,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'moneda_id'                        => 1,
            'discount_stock'                   => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => $this->items(),
        ];
    }

    /**
     * Payload mínimo de PUT api/sale/{id} (molde de Sales/33).
     *
     * @return array
     */
    protected function payload_update()
    {
        $total = self::PRECIO * self::CANTIDAD;

        return [
            'client_id'                  => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'discount_stock'             => 0,
            'sub_total'                  => $total,
            'total'                      => $total,
            'moneda_id'                  => 1,
            'items'                      => $this->items(),
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ];
    }

    /**
     * El renglón único de la venta.
     *
     * @return array
     */
    protected function items()
    {
        return [
            [
                'is_article'   => true,
                'id'           => $this->article->id,
                'name'         => $this->article->name,
                'price_vender' => self::PRECIO,
                'amount'       => self::CANTIDAD,
            ],
        ];
    }

    /**
     * Venta de mostrador ya guardada, para los tests del PUT (directo a la base: se mide el
     * despacho, no el alta).
     *
     * @return \App\Models\Sale
     */
    protected function venta_guardada()
    {
        return Sale::create([
            'user_id'                    => self::USER_ID,
            'client_id'                  => null,
            'price_type_id'              => $this->lista->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => self::PRECIO * self::CANTIDAD,
            'total'                      => self::PRECIO * self::CANTIDAD,
        ]);
    }

    /**
     * Guarda una venta por el endpoint real (store) y devuelve su id.
     *
     * @return int
     */
    protected function guardar_venta_por_store()
    {
        $response = $this->postJson('api/sale', $this->payload_venta());
        $response->assertStatus(201);

        return (int) $response->json('model.id');
    }

    /**
     * Edita una venta por el endpoint real (update).
     *
     * @return void
     */
    protected function editar_venta_por_update()
    {
        $venta = $this->venta_guardada();

        $this->putJson('api/sale/' . $venta->id, $this->payload_update())->assertStatus(200);
    }

    /**
     * Cantidad de SendSaleWhatsappJob encolados.
     *
     * @return int
     */
    protected function jobs_encolados()
    {
        return Queue::pushed(SendSaleWhatsappJob::class)->count();
    }

    /**
     * (a) Sin fila de config: ni store ni update encolan el comprobante.
     *
     * @group whatsapp
     * @test
     */
    public function sin_fila_de_config_no_se_encola_en_store_ni_en_update()
    {
        Queue::fake();

        $this->guardar_venta_por_store();
        $this->editar_venta_por_update();

        Queue::assertNotPushed(SendSaleWhatsappJob::class);
    }

    /**
     * (b) Fila inactiva (aunque tenga las tres credenciales y el opt-in): no se encola.
     *
     * @group whatsapp
     * @test
     */
    public function con_fila_inactiva_no_se_encola_en_store_ni_en_update()
    {
        $this->crear_config(['is_active' => false]);
        Queue::fake();

        $this->guardar_venta_por_store();
        $this->editar_venta_por_update();

        Queue::assertNotPushed(SendSaleWhatsappJob::class);
    }

    /**
     * Las tres credenciales de la conexión: cada caso del test (c) deja afuera UNA. Va por data
     * provider (una transacción por caso) porque el store rechaza como duplicada una segunda venta
     * idéntica dentro del mismo test y respondería 200 en vez de 201.
     *
     * @return array
     */
    public function credenciales_de_la_conexion()
    {
        return [
            'sin phone_number_id' => ['phone_number_id'],
            'sin kapso_api_key'   => ['kapso_api_key'],
            'sin webhook_secret'  => ['webhook_secret'],
        ];
    }

    /**
     * (c) Fila activa pero con la conexión incompleta: falta cualquiera de las tres credenciales.
     *
     * @group whatsapp
     * @dataProvider credenciales_de_la_conexion
     * @test
     *
     * @param  string  $columna  Credencial que queda vacía en este caso.
     */
    public function con_fila_activa_y_conexion_incompleta_no_se_encola_en_store_ni_en_update($columna)
    {
        $this->crear_config([$columna => '']);
        Queue::fake();

        $this->guardar_venta_por_store();
        $this->editar_venta_por_update();

        $this->assertEquals(
            0,
            $this->jobs_encolados(),
            'Sin ' . $columna . ' el número no está migrado y no se tiene que encolar el comprobante.'
        );
    }

    /**
     * (d) Fila activa con la conexión completa: SÍ se encola, en store y en update.
     *
     * @group whatsapp
     * @test
     */
    public function con_fila_activa_y_conexion_completa_se_encola_en_store_y_en_update()
    {
        $this->crear_config();
        Queue::fake();

        $sale_id = $this->guardar_venta_por_store();

        $this->assertEquals(1, $this->jobs_encolados(), 'El store tiene que encolar el comprobante.');

        $this->editar_venta_por_update();

        $this->assertEquals(2, $this->jobs_encolados(), 'El update también tiene que encolar el comprobante.');
        $this->assertNotNull(Sale::find($sale_id));
    }

    /**
     * (e) GET whatsapp-bot/config trae `numero_listo_para_envios` en `model`: true con la conexión
     * completa y la fila activa, false si está inactiva o incompleta. Sólo lectura (no sale de la
     * base como columna).
     *
     * @group whatsapp
     * @test
     */
    public function el_get_de_config_trae_numero_listo_para_envios()
    {
        $this->crear_config();
        $listo = $this->getJson('api/whatsapp-bot/config');
        $listo->assertStatus(200);
        $this->assertTrue($listo->json('model.numero_listo_para_envios') === true);

        WhatsappBotConfig::where('user_id', self::USER_ID)->delete();
        $this->crear_config(['is_active' => false]);
        $inactivo = $this->getJson('api/whatsapp-bot/config');
        $this->assertTrue($inactivo->json('model.numero_listo_para_envios') === false);

        WhatsappBotConfig::where('user_id', self::USER_ID)->delete();
        $this->crear_config(['webhook_secret' => '']);
        $incompleto = $this->getJson('api/whatsapp-bot/config');
        $this->assertTrue($incompleto->json('model.numero_listo_para_envios') === false);
    }

    /**
     * (f) El job revalida con la misma regla: con la conexión incompleta no llama al sender (no
     * sale ninguna request a Kapso) aunque la fila esté activa y tenga el opt-in.
     *
     * @group whatsapp
     * @test
     */
    public function el_job_con_conexion_incompleta_no_llama_al_sender()
    {
        $this->crear_config(['webhook_secret' => '']);

        $cliente = Client::create([
            'name'    => 'zz Cliente comprobante numero listo',
            'user_id' => self::USER_ID,
            'phone'   => '5493416001234',
        ]);

        $venta = $this->venta_guardada();
        $venta->client_id = $cliente->id;
        $venta->save();

        // Chat con la ventana de 24 h abierta: con la regla vieja el comprobante SÍ saldría a Kapso.
        WhatsappChat::create([
            'user_id'         => self::USER_ID,
            'client_id'       => $cliente->id,
            'phone'           => '5493416001234',
            'ai_enabled'      => true,
            'unread_count'    => 0,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        Http::fake([
            'api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.34']]], 200),
            '*' => Http::response([], 200),
        ]);

        (new SendSaleWhatsappJob($venta->id))->handle();

        Http::assertNothingSent();
    }
}
