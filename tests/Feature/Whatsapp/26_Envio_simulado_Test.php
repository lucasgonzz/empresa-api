<?php

namespace Tests\Feature\Whatsapp;

use App\Exceptions\SaleWhatsappSendException;
use App\Jobs\SendSaleWhatsappJob;
use App\Http\Controllers\Helpers\WhatsappTemplateStandardHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTemplate;
use App\Services\RecordatorioCobroSenderService;
use App\Services\SaleWhatsappSenderService;
use App\Services\WhatsappBotSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión whatsapp-envio-simulado-demos (15/10/2026): el modo de ENVÍO SIMULADO de las instancias
 * de demo.
 *
 * Con `whatsapp_bot_configs.envio_simulado = 1` en una instancia de demo, `WhatsappBotSendService`
 * no le habla a Kapso y devuelve un `wa_message_id` ficticio (`SIMULADO.…`): el comprobante de
 * venta, el recordatorio de cobro (uno y masivo), los mensajes y plantillas del operador, los
 * archivos del composer y las respuestas del agente quedan en la conversación como ENVIADOS y
 * ENTREGADOS, sin una sola request a Kapso. Es lo que permite filmar el módulo sin mandarle nada
 * a un cliente real ni arriesgar la calificación del número de WhatsApp Business.
 *
 * Lo que este archivo tiene que clavar, y por qué cada punto importa:
 *
 * 1. NADA SALE A KAPSO, por los seis caminos de envío. `Http::assertNothingSent()` en cada uno:
 *    si un camino nuevo se saltea el service, el test lo ve.
 * 2. QUEDA COMO ENVIADO y NO como «Simulado»: `is_simulated = 0` (esa columna es de la
 *    simulación de entrantes y la SPA le pone la etiqueta y le esconde los tildes) y
 *    `delivery_status = 'entregado'`. El marcador del envío de demo es el `wa_message_id`.
 * 3. LAS GUARDAS SIGUEN IGUAL: ventana de 24 h, plantilla aprobada, «ya recibió el recordatorio
 *    hoy», número inválido, texto vacío. El modo simulado reemplaza el POST, no las reglas.
 * 4. 🔴 UN CLIENTE REAL NUNCA QUEDA CON ENVÍOS QUE PARECEN SALIR Y NO SALEN: la API de
 *    configuración no escribe `envio_simulado`, y aunque la columna quede en 1 en una instancia
 *    que no es de demo, el service sigue mandando por Kapso.
 *
 * 🔴 Todo `Http::fake([...])` lleva stub `'*'` al final: en Laravel 8 una request que no matchea
 * ningún stub sale a la red de verdad.
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`).
 */
class Envio_simulado_Test extends TestCase
{
    use DatabaseTransactions;

    /** Teléfono del cliente deudor principal. */
    const PHONE = '5493416007711';

    /** Ruta de la configuración del bot. */
    const RUTA_CONFIG = 'api/whatsapp-bot/config';

    /** @var User */
    protected $owner;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var Client */
    protected $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        Storage::fake('local');

        $this->owner = User::create([
            'name'         => 'Dueño envío simulado',
            'company_name' => 'Ferreteria Demo',
            'email'        => 'envio-simulado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'api_url'      => 'https://api-test.comerciocity.com',
            'dias_alertar_administradores_ventas_no_cobradas' => 5,
            'dias_alertar_empleados_ventas_no_cobradas'       => 5,
        ]);

        // La fila de configuración tal como la deja el demo setup: activa, con credenciales
        // ficticias y en envío simulado.
        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->owner->id,
            'kapso_api_key'      => 'DEMO-SIN-CLAVE-ENVIO-SIMULADO',
            'phone_number_id'    => 'DEMO-000000000000000',
            'webhook_secret'     => 'secreto-envio-simulado',
            'is_active'          => true,
            'ai_enabled_default' => false,
            'envio_simulado'     => true,
        ]);

        $this->cliente = $this->crear_cliente('Juan Perez', self::PHONE);

        $this->dar_extension();
        $this->actuar_como($this->owner);
    }

    // ------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------

    /**
     * Cambia el usuario autenticado. El `forgetGuards()` es el de los otros tests de WhatsApp: el
     * guard de sanctum cachea el usuario que resolvió primero.
     *
     * @param User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Le da al owner la extensión `whatsapp` (las rutas del módulo viven en ese grupo).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', 'whatsapp')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'whatsapp',
                'name' => 'WhatsApp',
            ]);
        }

        $this->owner->extencions()->attach($extencion->id);
        $this->owner->load('extencions');
    }

    /**
     * Cliente del owner con su cuenta corriente en pesos y un movimiento (el PDF de cuenta
     * corriente que se adjunta a la plantilla necesita al menos uno).
     *
     * @param string $nombre
     * @param string $phone
     * @return Client
     */
    protected function crear_cliente($nombre, $phone)
    {
        $client = Client::create([
            'name'    => $nombre,
            'user_id' => $this->owner->id,
            'phone'   => $phone,
        ]);

        $credit_account = CreditAccount::create([
            'user_id'    => $this->owner->id,
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'saldo'      => 0,
        ]);

        CurrentAcount::create([
            'user_id'           => $this->owner->id,
            'client_id'         => $client->id,
            'credit_account_id' => $credit_account->id,
            'moneda_id'         => 1,
            'debe'              => 0,
            'haber'             => 0,
            'saldo'             => 0,
            'status'            => 'pagado',
        ]);

        return $client;
    }

    /**
     * Venta impaga del cliente, con la antigüedad pedida.
     *
     * @param Client $client
     * @param int    $dias_de_antiguedad
     * @param float  $monto
     * @return Sale
     */
    protected function venta_sin_cobrar(Client $client, $dias_de_antiguedad, $monto = 1000)
    {
        $creada_at = now()->subDays($dias_de_antiguedad);

        $sale = Sale::create([
            'user_id'    => $this->owner->id,
            'client_id'  => $client->id,
            'moneda_id'  => 1,
            'total'      => $monto,
            'terminada'  => 1,
            'created_at' => $creada_at,
            'updated_at' => $creada_at,
        ]);

        CurrentAcount::create([
            'user_id'    => $this->owner->id,
            'client_id'  => $client->id,
            'sale_id'    => $sale->id,
            'moneda_id'  => 1,
            'debe'       => $monto,
            'haber'      => 0,
            'saldo'      => $monto,
            'status'     => 'sin_pagar',
            'created_at' => $creada_at,
            'updated_at' => $creada_at,
        ]);

        return $sale;
    }

    /**
     * Chat del cliente con la ventana de 24 h ABIERTA.
     *
     * @param Client $client
     * @param int    $simulado  `last_inbound_simulated` (1 = el último entrante lo inyectó «Simular mensaje»).
     * @return WhatsappChat
     */
    protected function chat_abierto(Client $client, $simulado = 0)
    {
        return WhatsappChat::create([
            'user_id'                => $this->owner->id,
            'client_id'              => $client->id,
            'phone'                  => $client->phone,
            'ai_enabled'             => false,
            'unread_count'           => 0,
            'last_message_at'        => now(),
            'last_inbound_at'        => now(),
            'last_inbound_simulated' => $simulado,
        ]);
    }

    /**
     * Chat del cliente con la ventana CERRADA: el último entrante fue hace 30 horas.
     *
     * @param Client $client
     * @return WhatsappChat
     */
    protected function chat_cerrado(Client $client)
    {
        return WhatsappChat::create([
            'user_id'         => $this->owner->id,
            'client_id'       => $client->id,
            'phone'           => $client->phone,
            'ai_enabled'      => false,
            'unread_count'    => 0,
            'last_message_at' => now()->subHours(30),
            'last_inbound_at' => now()->subHours(30),
        ]);
    }

    /**
     * Las seis plantillas estándar aprobadas, como las deja el demo setup.
     *
     * @return void
     */
    protected function plantillas_aprobadas()
    {
        WhatsappTemplateStandardHelper::aprobar_estandar_para($this->owner->id);
    }

    /**
     * Mensajes salientes de un chat.
     *
     * @param int $chat_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function salientes($chat_id)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat_id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->get();
    }

    /**
     * Afirma que un mensaje quedó como lo deja el modo demostración: id ficticio reconocible,
     * entregado, y SIN la marca de «simulado» que la SPA muestra como etiqueta.
     *
     * @param WhatsappChatMessage $mensaje
     * @return void
     */
    protected function assertQuedoComoEnviado(WhatsappChatMessage $mensaje)
    {
        $this->assertTrue(
            WhatsappBotSendService::es_id_simulado($mensaje->wa_message_id),
            'El id tiene que ser el ficticio del modo demostración, no uno de Kapso: ' . var_export($mensaje->wa_message_id, true)
        );
        $this->assertSame('entregado', $mensaje->delivery_status, 'Sin webhook de Meta que lo avance, nacería "pendiente" para siempre.');
        $this->assertFalse((bool) $mensaje->is_simulated, 'is_simulated es de la simulación de entrantes: con 1 la SPA le pone la etiqueta «Simulado» y le esconde los tildes.');
        $this->assertNull($mensaje->send_error);
    }

    // ------------------------------------------------------------------------------------------
    // 1. El comprobante de venta (T3.21)
    // ------------------------------------------------------------------------------------------

    /**
     * Ventana abierta: el comprobante sale como DOCUMENTO. Nada sale a Kapso.
     *
     * @group whatsapp
     * @test
     */
    public function el_comprobante_de_venta_con_la_ventana_abierta_queda_enviado_sin_salir_a_kapso()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $chat = $this->chat_abierto($this->cliente);
        $venta = $this->venta_sin_cobrar($this->cliente, 1, 2500);

        $mensaje = (new SaleWhatsappSenderService())->send_sale($venta, null);

        Http::assertNothingSent();

        $this->assertQuedoComoEnviado($mensaje);
        $this->assertSame('document', $mensaje->media_type);
        $this->assertSame('sistema', $mensaje->source);
        $this->assertNotFalse(strpos($mensaje->media_url, '/sale/pdf/' . $venta->id), 'El link al PDF de la venta tiene que quedar en el mensaje.');
        $this->assertCount(1, $this->salientes($chat->id));
    }

    /**
     * Ventana cerrada: el comprobante sale por la plantilla `cc_cli_comprobante` con el PDF de
     * header. Con las plantillas estándar aprobadas (como las deja el demo setup) no se frena.
     *
     * @group whatsapp
     * @test
     */
    public function el_comprobante_con_la_ventana_cerrada_sale_por_la_plantilla_aprobada()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->plantillas_aprobadas();
        $chat = $this->chat_cerrado($this->cliente);
        $venta = $this->venta_sin_cobrar($this->cliente, 1, 2500);

        $mensaje = (new SaleWhatsappSenderService())->send_sale($venta, null);

        Http::assertNothingSent();

        $this->assertQuedoComoEnviado($mensaje);
        $this->assertSame('plantilla', $mensaje->source);
        $this->assertSame(SaleWhatsappSenderService::TEMPLATE_META_NAME, $mensaje->template_meta_name);
        $this->assertNotFalse(strpos($mensaje->body, 'Juan Perez'), 'La plantilla renderizada tiene que saludar al cliente.');
        $this->assertCount(1, $this->salientes($chat->id));
    }

    /**
     * La guarda de «plantilla aprobada» NO se afloja en modo demostración: con la ventana
     * cerrada y la plantilla sin aprobar, sigue frenando con el mismo código. Lo que hace que la
     * demo ande es que el demo setup las deja aprobadas, no que el modo simulado se las saltee.
     *
     * @group whatsapp
     * @test
     */
    public function la_guarda_de_plantilla_aprobada_sigue_funcionando_en_modo_demostracion()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->chat_cerrado($this->cliente);
        $venta = $this->venta_sin_cobrar($this->cliente, 1);

        // Sin ninguna plantilla cargada.
        try {
            (new SaleWhatsappSenderService())->send_sale($venta, null);
            $this->fail('Tendría que haber frenado: la ventana está cerrada y no hay plantilla aprobada.');
        } catch (SaleWhatsappSendException $e) {
            $this->assertSame('plantilla_no_aprobada', $e->error_code());
        }

        Http::assertNothingSent();
    }

    /**
     * El botón manual del modal de Ventas, de punta a punta por el endpoint real
     * (`POST sales/{id}/send-whatsapp-agent`).
     *
     * @group whatsapp
     * @test
     */
    public function el_boton_de_comprobante_del_modal_de_ventas_devuelve_201_y_deja_el_mensaje_enviado()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $chat = $this->chat_abierto($this->cliente);
        $venta = $this->venta_sin_cobrar($this->cliente, 1, 900);

        $respuesta = $this->postJson('api/sales/' . $venta->id . '/send-whatsapp-agent');

        $respuesta->assertStatus(201);

        Http::assertNothingSent();

        $mensaje = $this->salientes($chat->id)->first();
        $this->assertNotNull($mensaje);
        $this->assertQuedoComoEnviado($mensaje);
        $this->assertSame('manual', $mensaje->source);
        $this->assertEquals($mensaje->id, $respuesta->json('model.id'));
    }

    /**
     * El envío AUTOMÁTICO del comprobante al guardar la venta (`auto_send_sale_pdf`, el job que
     * dispara el cobro): queda enviado en el chat y el job loguea su éxito de siempre, no el error
     * de «WhatsApp no confirmó el envío».
     *
     * @group whatsapp
     * @test
     */
    public function el_envio_automatico_del_comprobante_al_guardar_la_venta_queda_enviado()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->config->auto_send_sale_pdf = true;
        $this->config->save();

        $chat = $this->chat_abierto($this->cliente);
        $venta = $this->venta_sin_cobrar($this->cliente, 0, 1500);

        (new SendSaleWhatsappJob($venta->id))->handle();

        Http::assertNothingSent();

        $salientes = $this->salientes($chat->id);
        $this->assertCount(1, $salientes, 'El job automático tiene que haber dejado el comprobante en el chat.');
        $this->assertQuedoComoEnviado($salientes->first());
        $this->assertSame('sistema', $salientes->first()->source, 'Sin empleado que lo dispare, el origen es el sistema.');
    }

    // ------------------------------------------------------------------------------------------
    // 2. El recordatorio de cobro (T4.18 uno, T4.19 masivo)
    // ------------------------------------------------------------------------------------------

    /**
     * Recordatorio de a uno, ventana abierta: texto libre. Y el freno de 24 h SIGUE andando: el
     * segundo intento da `ya_recibio_hoy` en vez de mandar otro mensaje.
     *
     * @group whatsapp
     * @test
     */
    public function el_recordatorio_de_a_uno_con_la_ventana_abierta_sale_por_texto_libre_y_respeta_el_freno_de_24_horas()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $chat = $this->chat_abierto($this->cliente);
        $this->venta_sin_cobrar($this->cliente, 45, 4000);

        $primero = $this->postJson('api/recordatorio-cobro/enviar', ['client_id' => $this->cliente->id, 'dias' => 0]);
        $primero->assertStatus(202);
        $this->assertSame(1, $primero->json('encolados'));

        Http::assertNothingSent();

        $salientes = $this->salientes($chat->id);
        $this->assertCount(1, $salientes);
        $this->assertQuedoComoEnviado($salientes->first());
        $this->assertSame(RecordatorioCobroSenderService::SOURCE, $salientes->first()->source);
        $this->assertNull($salientes->first()->template_meta_name, 'Texto libre: no puede quedar etiquetado como plantilla.');
        $this->assertNotFalse(strpos($salientes->first()->body, '$4.000'));

        // Segundo intento: el freno de 24 h lo saltea (202 con el motivo escrito) y no se crea otra fila.
        $segundo = $this->postJson('api/recordatorio-cobro/enviar', ['client_id' => $this->cliente->id, 'dias' => 0]);

        $segundo->assertStatus(202);
        $this->assertSame(0, $segundo->json('encolados'));
        $this->assertSame(RecordatorioCobroSenderService::CODE_YA_ENVIADO, $segundo->json('salteados.0.motivo'));
        $this->assertCount(1, $this->salientes($chat->id), 'El segundo intento no puede mandar otro mensaje.');
    }

    /**
     * Recordatorio de a uno, ventana cerrada: plantilla `cc_cli_recordatorio_cobro` con el PDF de
     * la cuenta corriente como header, y queda el `source` que activa el freno de 24 h.
     *
     * @group whatsapp
     * @test
     */
    public function el_recordatorio_con_la_ventana_cerrada_sale_por_la_plantilla_con_el_pdf_de_la_cuenta()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->plantillas_aprobadas();
        $chat = $this->chat_cerrado($this->cliente);
        $this->venta_sin_cobrar($this->cliente, 45, 4000);

        $respuesta = $this->postJson('api/recordatorio-cobro/enviar', ['client_id' => $this->cliente->id, 'dias' => 0]);
        $respuesta->assertStatus(202);
        $this->assertSame(1, $respuesta->json('encolados'), 'Con la plantilla aprobada y el PDF de la cuenta, no se saltea: ' . json_encode($respuesta->json('salteados')));

        Http::assertNothingSent();

        $salientes = $this->salientes($chat->id);
        $this->assertCount(1, $salientes);

        $mensaje = $salientes->first();
        $this->assertQuedoComoEnviado($mensaje);
        $this->assertSame(RecordatorioCobroSenderService::SOURCE, $mensaje->source, 'El `source` es el freno anti-duplicado: tiene que ser el mismo en los dos caminos.');
        $this->assertSame(RecordatorioCobroSenderService::TEMPLATE_META_NAME, $mensaje->template_meta_name);
        $this->assertSame('document', $mensaje->media_type);
        $this->assertNotNull($mensaje->media_url, 'El PDF de la cuenta corriente va adjunto a la plantilla.');
    }

    /**
     * El masivo: un job por deudor, cada uno persiste su mensaje como enviado. La cola corre en
     * `sync` en los tests, así que los jobs ya se ejecutaron al volver el POST.
     *
     * @group whatsapp
     * @test
     */
    public function el_recordatorio_masivo_deja_un_mensaje_enviado_por_cada_deudor_sin_salir_a_kapso()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->plantillas_aprobadas();

        // Un deudor con la ventana abierta (texto libre), otro con la cerrada (plantilla) y otro
        // sin chat todavía (el envío le abre uno y sale por plantilla).
        $abierto = $this->cliente;
        $cerrado = $this->crear_cliente('Maria Cerrada', '5493416007722');
        $sin_chat = $this->crear_cliente('Pedro Sin Chat', '5493416007733');

        $chat_abierto = $this->chat_abierto($abierto);
        $chat_cerrado = $this->chat_cerrado($cerrado);

        $this->venta_sin_cobrar($abierto, 40, 1000);
        $this->venta_sin_cobrar($cerrado, 40, 2000);
        $this->venta_sin_cobrar($sin_chat, 40, 3000);

        $respuesta = $this->postJson('api/recordatorio-cobro/enviar-masivo', ['dias' => 0]);
        $respuesta->assertStatus(202);

        $this->assertSame(3, $respuesta->json('encolados'));

        Http::assertNothingSent();

        foreach ([$chat_abierto, $chat_cerrado] as $chat) {
            $salientes = $this->salientes($chat->id);
            $this->assertCount(1, $salientes);
            $this->assertQuedoComoEnviado($salientes->first());
            $this->assertSame(RecordatorioCobroSenderService::SOURCE, $salientes->first()->source);
        }

        $chat_nuevo = WhatsappChat::where('user_id', $this->owner->id)->where('client_id', $sin_chat->id)->first();
        $this->assertNotNull($chat_nuevo, 'El envío le tiene que haber abierto un chat al deudor que no tenía.');
        $this->assertCount(1, $this->salientes($chat_nuevo->id));
        $this->assertQuedoComoEnviado($this->salientes($chat_nuevo->id)->first());
    }

    // ------------------------------------------------------------------------------------------
    // 3. Lo que manda el operador desde la conversación (T12.x)
    // ------------------------------------------------------------------------------------------

    /**
     * Texto libre desde el composer («Responder»): 201, `enviado: true`, y la guarda de la
     * ventana de 24 h intacta (`fuera_de_ventana` sin tocar nada).
     *
     * @group whatsapp
     * @test
     */
    public function el_texto_libre_del_operador_queda_enviado_y_la_ventana_cerrada_sigue_dando_422()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $abierto = $this->chat_abierto($this->cliente);

        $respuesta = $this->postJson('api/whatsapp-chats/' . $abierto->id . '/messages', ['body' => 'Hola Juan, ya está tu pedido']);

        $respuesta->assertStatus(201);
        $this->assertTrue($respuesta->json('enviado'), 'Con el envío simulado el 201 viene con `enviado: true`.');

        $mensaje = $this->salientes($abierto->id)->first();
        $this->assertQuedoComoEnviado($mensaje);
        $this->assertSame('manual', $mensaje->source);

        // La ventana cerrada sigue frenando, exactamente igual que en producción.
        $otro = $this->crear_cliente('Ana Cerrada', '5493416007744');
        $cerrado = $this->chat_cerrado($otro);

        $cerrada = $this->postJson('api/whatsapp-chats/' . $cerrado->id . '/messages', ['body' => 'esto no puede salir']);

        $cerrada->assertStatus(422);
        $this->assertSame('fuera_de_ventana', $cerrada->json('code'));
        $this->assertCount(0, $this->salientes($cerrado->id));

        Http::assertNothingSent();
    }

    /**
     * Plantilla desde el chat (el camino con la ventana cerrada).
     *
     * @group whatsapp
     * @test
     */
    public function la_plantilla_del_operador_queda_enviada_con_las_variables_renderizadas()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->plantillas_aprobadas();
        $chat = $this->chat_cerrado($this->cliente);

        $plantilla = WhatsappTemplate::where('user_id', $this->owner->id)
            ->where('meta_template_name', 'cc_cli_recordatorio_pago')
            ->first();

        $respuesta = $this->postJson('api/whatsapp-chats/' . $chat->id . '/send-template', [
            'template_id' => $plantilla->id,
            'variables'   => ['Juan', 'Ferreteria Demo', '$15.000'],
        ]);

        $respuesta->assertStatus(201);
        $this->assertTrue($respuesta->json('enviado'));

        Http::assertNothingSent();

        $mensaje = $this->salientes($chat->id)->first();
        $this->assertQuedoComoEnviado($mensaje);
        $this->assertSame('plantilla', $mensaje->source);
        $this->assertSame('cc_cli_recordatorio_pago', $mensaje->template_meta_name);
        $this->assertNotFalse(strpos($mensaje->body, '$15.000'));
    }

    /**
     * Una foto desde el composer: la subida a Kapso y el envío son simulados los dos.
     *
     * @group whatsapp
     * @test
     */
    public function una_foto_del_composer_queda_enviada_sin_subirla_a_kapso()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $chat = $this->chat_abierto($this->cliente);

        $respuesta = $this->post('api/whatsapp-chats/' . $chat->id . '/media', [
            'file'    => UploadedFile::fake()->image('foto.jpg'),
            'caption' => 'Así quedó el pedido',
        ], ['Accept' => 'application/json']);

        $respuesta->assertStatus(201);
        $this->assertTrue($respuesta->json('enviado'));

        Http::assertNothingSent();

        $mensaje = $this->salientes($chat->id)->first();
        $this->assertQuedoComoEnviado($mensaje);
        $this->assertSame('image', $mensaje->media_type);
    }

    /**
     * La respuesta del agente que esperaba confirmación («Enviar ahora»): sale simulada y la fila
     * queda `enviado` + `entregado`.
     *
     * @group whatsapp
     * @test
     */
    public function la_respuesta_de_la_ia_pendiente_se_confirma_y_queda_enviada()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $chat = $this->chat_abierto($this->cliente);

        $pendiente = WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => 'ia',
            'body'             => 'Hola! Te confirmo el stock en un momento.',
            'delivery_status'  => 'pendiente',
            'ai_status'        => 'a_confirmar',
            'is_simulated'     => 0,
        ]);

        $respuesta = $this->putJson('api/whatsapp-chats/messages/' . $pendiente->id . '/confirm');

        $respuesta->assertStatus(200);

        Http::assertNothingSent();

        $fresco = $pendiente->fresh();
        $this->assertSame('enviado', $fresco->ai_status);
        $this->assertQuedoComoEnviado($fresco);
    }

    /**
     * Un audio del composer, por el controller real: nota de voz en ogg, la subida y el envío son
     * simulados los dos y nada sale a Kapso.
     *
     * @group whatsapp
     * @test
     */
    public function un_audio_del_composer_queda_enviado_sin_subirlo_a_kapso()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $chat = $this->chat_abierto($this->cliente);

        $respuesta = $this->post('api/whatsapp-chats/' . $chat->id . '/media', [
            // Con contenido: `create()` deja el archivo vacío y el servidor lo rechaza por mime.
            'file' => UploadedFile::fake()->createWithContent('nota.ogg', 'OggS-FALSO-0123456789-0123456789-0123456789-0123456789')->mimeType('audio/ogg'),
        ], ['Accept' => 'application/json']);

        $respuesta->assertStatus(201);
        $this->assertTrue($respuesta->json('enviado'));

        Http::assertNothingSent();

        $mensaje = $this->salientes($chat->id)->first();
        $this->assertQuedoComoEnviado($mensaje);
        $this->assertSame('audio', $mensaje->media_type);
    }

    /**
     * El botón «Simular mensaje del cliente» queda prendido en las demos. Después de usarlo el chat
     * tiene `last_inbound_simulated = 1`, y ahí las otras dos puertas de salida (el composer de
     * fotos y audios, y «Enviar ahora» de la respuesta de la IA) tienen su propio freno. En modo
     * demostración no tienen que frenar: no hay POST a Kapso que proteger, y frenar deja el
     * mensaje «sin enviar» justo después de usar el botón de simular.
     *
     * @group whatsapp
     * @test
     */
    public function despues_de_simular_un_mensaje_el_composer_y_la_confirmacion_de_la_ia_siguen_enviando()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $chat = $this->chat_abierto($this->cliente, 1);

        // El composer.
        $foto = $this->post('api/whatsapp-chats/' . $chat->id . '/media', [
            'file' => UploadedFile::fake()->image('foto.jpg'),
        ], ['Accept' => 'application/json']);

        $foto->assertStatus(201);
        $this->assertTrue($foto->json('enviado'), 'En modo demostración la foto sale aunque el chat esté en simulación.');

        // «Enviar ahora» de la respuesta pendiente de la IA (nace marcada como simulada: responde
        // a un entrante simulado).
        $pendiente = WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => 'ia',
            'body'             => 'Respuesta de la IA al mensaje simulado',
            'delivery_status'  => 'pendiente',
            'ai_status'        => 'a_confirmar',
            'is_simulated'     => 1,
        ]);

        $this->putJson('api/whatsapp-chats/messages/' . $pendiente->id . '/confirm')->assertStatus(200);

        Http::assertNothingSent();

        $fresco = $pendiente->fresh();
        $this->assertSame('enviado', $fresco->ai_status);
        $this->assertTrue(WhatsappBotSendService::es_id_simulado($fresco->wa_message_id), 'Sin id quedaba con el relojito de pendiente.');
        $this->assertSame('entregado', $fresco->delivery_status);
        $this->assertTrue((bool) $fresco->is_simulated, 'Sigue marcada «Simulado»: responde a un entrante que no escribió el cliente.');
    }

    // ------------------------------------------------------------------------------------------
    // 4. Las guardas locales del service no se aflojan
    // ------------------------------------------------------------------------------------------

    /**
     * Número inválido, cuerpo vacío, URL vacía y media sin id siguen dando null: el modo
     * simulado reemplaza el POST, no las validaciones locales.
     *
     * @group whatsapp
     * @test
     */
    public function las_validaciones_locales_del_service_siguen_dando_null()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $service = new WhatsappBotSendService();

        $this->assertNull($service->send_text('sin digitos', 'hola', $this->config));
        $this->assertNull($service->send_text(self::PHONE, '   ', $this->config));
        $this->assertNull($service->send_document(self::PHONE, '  ', 'x.pdf', '', $this->config));
        $this->assertNull($service->send_image(self::PHONE, [], '', $this->config));
        $this->assertNull($service->send_audio(self::PHONE, '  ', 'audio/ogg', $this->config));
        $this->assertNull($service->upload_media('C:/no/existe/archivo.jpg', 'image/jpeg', 'x.jpg', $this->config));

        // Y lo válido devuelve el id ficticio.
        $id = $service->send_text(self::PHONE, 'hola', $this->config);
        $this->assertTrue(WhatsappBotSendService::es_id_simulado($id));
        $this->assertSame(0, strpos($id, 'SIMULADO.'));
        $this->assertLessThan(80, strlen($id), 'La columna wa_message_id es string(80).');

        Http::assertNothingSent();
    }

    /**
     * Un chat con `last_inbound_simulated = 1` (el dueño probó «Simular mensaje» sobre el
     * teléfono de un cliente) NO bloquea el recordatorio en una demo: el freno de la simulación
     * existe para que no salga nada a Kapso, y acá no sale. Sin esto, un cliente con quien se
     * probó la simulación quedaba sin poder recibir su recordatorio en la demo.
     *
     * @group whatsapp
     * @test
     */
    public function en_modo_demostracion_un_chat_en_simulacion_no_frena_el_envio()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $chat = $this->chat_abierto($this->cliente, 1);

        $id = (new WhatsappBotSendService())->send_text($chat->phone, 'recordatorio', $this->config);

        $this->assertTrue(WhatsappBotSendService::es_id_simulado($id), 'En modo demostración el freno de la simulación no aplica: no hay POST que proteger.');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------------------------------
    // 5. Un cliente real nunca queda con envíos que parecen salir y no salen
    // ------------------------------------------------------------------------------------------

    /**
     * La API de configuración NO escribe `envio_simulado`, ni al crear la fila ni al editarla.
     *
     * @group whatsapp
     * @test
     */
    public function la_api_de_configuracion_no_puede_prender_ni_apagar_el_envio_simulado()
    {
        // Un negocio nuevo, sin fila de configuración, intenta crearla prendiendo el modo.
        $otro = User::create([
            'name'         => 'Comercio real',
            'company_name' => 'Comercio real SA',
            'email'        => 'comercio-real-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->actuar_como($otro);

        $alta = $this->putJson(self::RUTA_CONFIG, [
            'kapso_api_key'   => 'kapso-real',
            'phone_number_id' => '5491100000099',
            'webhook_secret'  => 'secreto-real',
            'is_active'       => true,
            'envio_simulado'  => true,
        ]);

        $alta->assertStatus(200);

        $creada = WhatsappBotConfig::where('user_id', $otro->id)->first();
        $this->assertNotNull($creada);
        $this->assertFalse((bool) $creada->envio_simulado, 'El alta por la API no puede prender el envío simulado.');
        $this->assertFalse($alta->json('model.modo_demostracion'));

        // Y la fila de la demo, que sí lo tiene prendido, no se apaga por editarla (ni se toca).
        $this->actuar_como($this->owner);

        $edicion = $this->putJson(self::RUTA_CONFIG, ['chat_simulation_enabled' => true, 'envio_simulado' => false]);
        $edicion->assertStatus(200);

        $this->assertTrue((bool) $this->config->fresh()->envio_simulado, 'Editar otros campos no toca el envío simulado, y mandarlo en falso tampoco lo apaga.');
    }

    /**
     * La puerta lateral: `PUT update/{model_name}` (la actualización masiva genérica) asigna
     * cualquier clave del update_form y resuelve los ids sin filtrar por dueño. Por ahí cualquier
     * usuario autenticado podía prender `envio_simulado` —o pisar la clave de Kapso— en la
     * configuración de OTRO negocio. La configuración del bot no se actualiza en forma masiva.
     *
     * @group whatsapp
     * @test
     */
    public function la_configuracion_de_whatsapp_no_se_actualiza_en_forma_masiva()
    {
        // La configuración de OTRO negocio, con el modo apagado.
        $ajeno = User::create([
            'name'         => 'Comercio ajeno',
            'company_name' => 'Comercio ajeno SA',
            'email'        => 'comercio-ajeno-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $config_ajena = WhatsappBotConfig::create([
            'user_id'         => $ajeno->id,
            'kapso_api_key'   => 'kapso-ajena',
            'phone_number_id' => '5491100000055',
            'webhook_secret'  => 'secreto-ajeno',
            'is_active'       => true,
        ]);

        $cuerpo = [
            'models_id'   => [$config_ajena->id],
            'from_filter' => false,
            'update_form' => [
                ['type' => 'checkbox', 'key' => 'envio_simulado', 'value' => 1],
                ['type' => 'text', 'key' => 'kapso_api_key', 'value' => 'pisada'],
            ],
        ];

        foreach (['whatsapp_bot_config', 'whatsappBotConfig', 'WhatsappBotConfig'] as $nombre) {
            $respuesta = $this->putJson('api/update/' . $nombre, $cuerpo);

            $this->assertSame(422, $respuesta->getStatusCode(), 'PUT update/' . $nombre . ': ' . $respuesta->getContent());
            $this->assertSame('La configuración de WhatsApp no se actualiza en forma masiva.', $respuesta->json('message'));
        }

        $intacta = $config_ajena->fresh();
        $this->assertFalse((bool) $intacta->envio_simulado);
        $this->assertSame('kapso-ajena', $intacta->kapso_api_key);
    }

    /**
     * 🔴 Aunque la columna quede en 1 en la base de un CLIENTE REAL (a mano, por una copia de
     * una demo, por un script mal apuntado), el sistema manda por Kapso de verdad: falla hacia
     * el lado seguro y no hacia «parece enviado y no salió».
     *
     * @group whatsapp
     * @test
     */
    public function fuera_de_una_instancia_de_demo_la_columna_se_ignora_y_el_mensaje_sale_por_kapso()
    {
        // Una instalación de cliente: APP_ENV de producción y sin FOR_USER=demo.
        config(['app.env' => 'production', 'app.FOR_USER' => null]);

        Http::fake([
            'api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.REAL.1']]], 200),
            '*'              => Http::response([], 200),
        ]);

        $this->assertFalse($this->config->fresh()->esta_en_modo_demostracion());

        $id = (new WhatsappBotSendService())->send_text(self::PHONE, 'hola de verdad', $this->config->fresh());

        $this->assertSame('wamid.REAL.1', $id, 'En una instalación de cliente el envío tiene que salir por Kapso.');

        Http::assertSent(function ($request) {
            return strpos($request->url(), 'api.kapso.ai') !== false;
        });

        // El cartel de la SPA tampoco miente: no hay modo demostración.
        $this->actuar_como($this->owner);
        $this->getJson(self::RUTA_CONFIG)->assertStatus(200)->assertJsonPath('model.modo_demostracion', false);
    }

    /**
     * `modo_demostracion` sale en la configuración (es lo que lee el cartel de la SPA) y refleja
     * el valor efectivo, no la columna cruda.
     *
     * @group whatsapp
     * @test
     */
    public function la_configuracion_informa_el_modo_demostracion()
    {
        $this->getJson(self::RUTA_CONFIG)
            ->assertStatus(200)
            ->assertJsonPath('model.modo_demostracion', true)
            ->assertJsonPath('model.envio_simulado', true);

        $this->config->envio_simulado = false;
        $this->config->save();

        $this->getJson(self::RUTA_CONFIG)
            ->assertStatus(200)
            ->assertJsonPath('model.modo_demostracion', false);
    }

    /**
     * Sin el modo prendido (la fila de un negocio normal) todo sigue yendo por Kapso como
     * siempre: el cambio no le toca nada a quien no lo usa.
     *
     * @group whatsapp
     * @test
     */
    public function sin_el_modo_prendido_el_envio_sigue_saliendo_por_kapso()
    {
        $this->config->envio_simulado = false;
        $this->config->save();

        Http::fake([
            'api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.NORMAL.1']]], 200),
            '*'              => Http::response([], 200),
        ]);

        $id = (new WhatsappBotSendService())->send_text(self::PHONE, 'hola', $this->config->fresh());

        $this->assertSame('wamid.NORMAL.1', $id);
        $this->assertFalse(WhatsappBotSendService::es_id_simulado($id));
    }
}
