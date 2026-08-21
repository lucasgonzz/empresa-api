<?php

namespace Tests\Feature\Alertas;

use App\Jobs\SendRecordatorioCobroJob;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionProperty;
use Tests\EmpresaTestCase;

/**
 * Mision recordatorio-cobro-whatsapp — Pieza 2: el envio del recordatorio de cobro.
 *
 * Lo que este archivo tiene que clavar:
 *
 * 5. Que el envio DEJE UNA FILA en el chat del cliente, con `source = 'recordatorio_cobro'`, en
 *    los DOS caminos (texto libre con la ventana de 24 h abierta, plantilla con la ventana
 *    cerrada). Ese `source` no es cosmetico: es el freno anti-duplicado. Si el camino de
 *    plantilla lo escribiera distinto, el masivo le reenviaria el mismo recordatorio al mismo
 *    cliente cada vez que alguien aprieta el boton.
 * 6. Que un cliente sin telefono NO CORTE el masivo, y que se loguee como condicion esperable
 *    (`Log::info`) y no como falla de infraestructura (`Log::error`). La particion importa: si
 *    todo fuera error, el canal se llena de ruido y el fallo real deja de verse.
 * 7. Que la unidad del masivo sea EL CLIENTE y no la venta: seis ventas vencidas de un mismo
 *    cliente son UN job, no seis. Y que los salteados se cuenten y se muestren.
 *
 * 🔴 Todo `Http::fake([...])` lleva stub `'*'` al final: en Laravel 8 una request que no matchea
 * ningun stub sale a la red de verdad.
 *
 * 🔴 Todas las aserciones filtran por los datos que crea este test. La base del slot arrastra
 * filas de otras suites y cualquier conteo global seria verdadero hoy y falso mañana.
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`).
 */
class Recordatorio_de_cobro_Test extends EmpresaTestCase
{
    /** Telefono del primer cliente deudor. */
    const PHONE = '5493416009988';

    /** @var User Dueño de la empresa del test. */
    protected $owner;

    /** @var WhatsappBotConfig Config activa del bot: sin ella no se manda nada. */
    protected $config;

    /** @var Client Cliente deudor principal. */
    protected $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $sufijo = uniqid();

        $this->owner = User::create([
            'name'         => 'Dueño recordatorio cobro',
            'company_name' => 'Ferreteria Norte',
            'email'        => 'recordatorio-cobro-owner-'.$sufijo.'@test.local',
            'password'     => Hash::make('secret'),
            'api_url'      => 'https://api-test.comerciocity.com',
            'dias_alertar_administradores_ventas_no_cobradas' => 5,
            'dias_alertar_empleados_ventas_no_cobradas'       => 5,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->owner->id,
            'kapso_api_key'      => 'kapso-recordatorio',
            'phone_number_id'    => '5491100000077',
            'webhook_secret'     => 'secreto-recordatorio',
            'is_active'          => true,
            'ai_enabled_default' => false,
        ]);

        $this->cliente = $this->crear_cliente('Juan Perez', self::PHONE);

        $this->actuar_como($this->owner);
    }

    /**
     * Cambia el usuario autenticado para las requests que siguen.
     *
     * 🔴 El `Auth::forgetGuards()` no es decorativo: la ruta vive bajo `auth:sanctum`, y el
     * guard de sanctum es un `RequestGuard` que CACHEA el usuario que resolvio la primera vez y
     * vive en el mismo container durante todo el test. Sin olvidarlo, un segundo `actingAs()`
     * escribe en el guard `web` pero la request sigue resolviendo al usuario de la primera.
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Crea un cliente del owner del test, con su cuenta corriente en pesos.
     *
     * @param string      $nombre
     * @param string|null $phone
     * @return \App\Models\Client
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

        // La cuenta necesita AL MENOS un movimiento para que el link del PDF se arme: con la
        // cuenta vacia, `pdfFromModel()` hace `$models[0]->...` sobre una coleccion vacia y la
        // ruta publica tira 500. El service, por eso, no arma la URL en ese caso.
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
     * Crea una venta impaga del cliente, con la antiguedad pedida.
     *
     * @param \App\Models\Client $client
     * @param int                $dias_de_antiguedad
     * @param float              $monto
     * @param int                $moneda_id
     * @return \App\Models\Sale
     */
    protected function venta_sin_cobrar(Client $client, $dias_de_antiguedad, $monto = 1000, $moneda_id = 1)
    {
        $creada_at = now()->subDays($dias_de_antiguedad);

        $sale = Sale::create([
            'user_id'    => $this->owner->id,
            'client_id'  => $client->id,
            'moneda_id'  => $moneda_id,
            'total'      => $monto,
            'terminada'  => 1,
            'created_at' => $creada_at,
            'updated_at' => $creada_at,
        ]);

        CurrentAcount::create([
            'user_id'    => $this->owner->id,
            'client_id'  => $client->id,
            'sale_id'    => $sale->id,
            'moneda_id'  => $moneda_id,
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
     * Chat del cliente con la ventana de 24 h ABIERTA (escribio recien).
     *
     * @param \App\Models\Client $client
     * @return \App\Models\WhatsappChat
     */
    protected function chat_con_ventana_abierta(Client $client)
    {
        return WhatsappChat::create([
            'user_id'         => $this->owner->id,
            'client_id'       => $client->id,
            'phone'           => $client->phone,
            'ai_enabled'      => false,
            'unread_count'    => 0,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
    }

    /**
     * Chat del cliente con la ventana CERRADA: el ultimo entrante fue hace 30 horas.
     *
     * @param \App\Models\Client $client
     * @return \App\Models\WhatsappChat
     */
    protected function chat_fuera_de_ventana(Client $client)
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
     * La plantilla del recordatorio, ya aprobada en Meta.
     *
     * @return \App\Models\WhatsappTemplate
     */
    protected function plantilla_aprobada()
    {
        return WhatsappTemplate::create([
            'user_id'            => $this->owner->id,
            'name'               => 'Recordatorio de cobro',
            'meta_template_name' => RecordatorioCobroSenderService::TEMPLATE_META_NAME,
            'category'           => 'utility',
            'language'           => 'es_AR',
            'body_preview'       => 'Hola {{1}}, te escribimos de {{2}}. Tenés {{3}} ventas pendientes de pago por un total de {{4}}.',
            'variables'          => ['Nombre', 'Negocio', 'Cantidad de ventas', 'Montos pendientes'],
            'status'             => 'aprobada',
            'is_system'          => true,
        ]);
    }

    /**
     * Los mensajes salientes del chat de un cliente. Filtra por el chat que creo el test.
     *
     * @param int $chat_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function mensajes_salientes($chat_id)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat_id)
            ->where('direction', 'out')
            ->get();
    }

    /**
     * Los mensajes salientes de TODOS los chats de un cliente, sin importar cual sea el chat.
     * Se usa cuando el test no sabe si el chat existe (por ejemplo, un cliente sin telefono al
     * que nunca se le pudo abrir uno).
     *
     * @param int $client_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function mensajes_salientes_de($client_id)
    {
        $chat_ids = WhatsappChat::where('user_id', $this->owner->id)
            ->where('client_id', $client_id)
            ->pluck('id');

        return WhatsappChatMessage::whereIn('whatsapp_chat_id', $chat_ids)
            ->where('direction', 'out')
            ->get();
    }

    /**
     * Deja en el chat un recordatorio ya enviado hace N horas, para ejercitar el freno de 24 h.
     *
     * 🔴 La fila va con `source = 'recordatorio_cobro'` porque ES el freno: si se guardara con
     * cualquier otro `source`, `ya_recibio_recordatorio()` no la veria y el test pasaria por el
     * motivo equivocado.
     *
     * @param \App\Models\WhatsappChat $chat
     * @param int                      $hace_horas
     * @return \App\Models\WhatsappChatMessage
     */
    protected function recordatorio_ya_enviado(WhatsappChat $chat, $hace_horas)
    {
        $enviado_at = now()->subHours($hace_horas);

        return WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => RecordatorioCobroSenderService::SOURCE,
            'body'             => 'Recordatorio anterior',
            'wa_message_id'    => 'wamid.ANTERIOR.'.$chat->id,
            'delivery_status'  => 'entregado',
            'created_at'       => $enviado_at,
            'updated_at'       => $enviado_at,
        ]);
    }

    /**
     * Le da al owner del test la extension `whatsapp`, creando la fila del catalogo si la base
     * del slot todavia no la tiene sembrada. Las cuatro rutas del recordatorio viven adentro del
     * grupo `check_extencion_empresa:whatsapp`.
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
     * Lee el `client_id` de un job despachado.
     *
     * Va por reflexion a proposito: la propiedad es `protected` porque nadie de produccion tiene
     * por que leerla, y abrirla con un getter publico solo para que el test la mire seria
     * agrandar la superficie del job por comodidad del test.
     *
     * @param \App\Jobs\SendRecordatorioCobroJob $job
     * @return int
     */
    protected function client_id_del_job($job)
    {
        $propiedad = new ReflectionProperty(SendRecordatorioCobroJob::class, 'client_id');
        $propiedad->setAccessible(true);

        return (int) $propiedad->getValue($job);
    }

    /**
     * TEST 5 — el envio persiste un mensaje en el chat del cliente, en los DOS caminos.
     *
     * Las dos variantes van en el mismo test a proposito: lo que importa no es que cada camino
     * ande por separado, sino que los DOS dejen la fila con el MISMO `source`. Ese `source` es
     * el freno anti-duplicado, y un camino que lo escriba distinto lo deja ciego a la mitad de
     * los envios.
     *
     * @test
     * @return void
     */
    public function el_envio_persiste_un_mensaje_en_el_chat_del_cliente()
    {
        // 🔴 UN SOLO `Http::fake()` PARA LAS DOS MITADES. Llamarlo dos veces NO reemplaza los
        // stubs: Laravel los MERGEA, asi que el primer `api.kapso.ai/*` sigue ganando y la
        // segunda mitad recibiria el `wa_message_id` de la primera. El stub devuelve un id
        // derivado del `type` del payload, que ademas hace que el test distinga de cual de los
        // dos caminos vino cada fila.
        Http::fake([
            'api.kapso.ai/*' => function ($request) {
                $body = json_decode($request->body(), true);
                $tipo = isset($body['type']) ? $body['type'] : 'desconocido';

                return Http::response(['messages' => [['id' => 'wamid.RECORDATORIO.'.strtoupper($tipo)]]], 200);
            },
            '*' => Http::response([], 200),
        ]);

        // ---------- (a) VENTANA ABIERTA: sale como texto libre ----------

        $chat_abierto = $this->chat_con_ventana_abierta($this->cliente);

        $venta = $this->venta_sin_cobrar($this->cliente, 45, 4000);

        $mensaje = (new RecordatorioCobroSenderService())
            ->enviar($this->owner->id, $this->cliente, [$venta], null);

        // Salio a Kapso de verdad, y salio como texto libre.
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['type']) && $body['type'] === 'text';
        });

        $this->assertSame('wamid.RECORDATORIO.TEXT', $mensaje->wa_message_id);
        $this->assertSame(RecordatorioCobroSenderService::SOURCE, $mensaje->source);
        $this->assertSame('out', $mensaje->direction);
        $this->assertNull($mensaje->template_meta_name, 'El texto libre no puede quedar etiquetado como plantilla: la burbuja del chat muestra ese campo en pantalla.');

        // Una sola fila saliente en el chat.
        $this->assertCount(1, $this->mensajes_salientes($chat_abierto->id));

        // El cuerpo dice lo que tiene que decir: nombre, monto formateado y el link de la venta.
        $this->assertNotFalse(strpos($mensaje->body, 'Juan Perez'), 'El mensaje tiene que saludar al cliente por su nombre.');
        $this->assertNotFalse(strpos($mensaje->body, 'Ferreteria Norte'), 'El mensaje tiene que decir de que negocio es.');
        $this->assertNotFalse(strpos($mensaje->body, '$4.000'), 'El monto tiene que ir formateado como en la pantalla, no como float crudo.');
        $this->assertNotFalse(strpos($mensaje->body, '/sale/pdf/'.$venta->id), 'Cada venta listada tiene que traer el link a su comprobante.');

        // ---------- (b) VENTANA CERRADA: sale como plantilla ----------

        $otro_cliente = $this->crear_cliente('Ana Lopez', '5493416007766');
        $chat_cerrado = $this->chat_fuera_de_ventana($otro_cliente);
        $this->plantilla_aprobada();

        $venta_de_ana = $this->venta_sin_cobrar($otro_cliente, 60, 2500);

        $mensaje_plantilla = (new RecordatorioCobroSenderService())
            ->enviar($this->owner->id, $otro_cliente, [$venta_de_ana], null);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            if (! isset($body['type']) || $body['type'] !== 'template') {
                return false;
            }

            return isset($body['template']['name'])
                && $body['template']['name'] === RecordatorioCobroSenderService::TEMPLATE_META_NAME;
        });

        $this->assertSame('wamid.RECORDATORIO.TEMPLATE', $mensaje_plantilla->wa_message_id);
        // 🔴 EL MISMO `source` QUE EN EL CAMINO DE TEXTO LIBRE. Es todo el punto del test.
        $this->assertSame(RecordatorioCobroSenderService::SOURCE, $mensaje_plantilla->source);
        $this->assertSame(
            RecordatorioCobroSenderService::TEMPLATE_META_NAME,
            $mensaje_plantilla->template_meta_name,
            'La fila de plantilla si tiene que decir con que plantilla salio.'
        );

        $this->assertCount(1, $this->mensajes_salientes($chat_cerrado->id));

        // Y el cuerpo persistido es la plantilla RENDERIZADA, no los `{{1}}` crudos.
        $this->assertFalse(strpos($mensaje_plantilla->body, '{{1}}'), 'El body persistido no puede tener placeholders sin reemplazar.');
        $this->assertNotFalse(strpos($mensaje_plantilla->body, 'Ana Lopez'));
        $this->assertNotFalse(strpos($mensaje_plantilla->body, '$2.500'));
    }

    /**
     * TEST 6 — un cliente sin telefono NO CORTA el masivo.
     *
     * Dos mitades, y las dos hacen falta:
     *
     * (a) Por el endpoint: el del medio sale en `salteados` con `motivo = 'sin_telefono'` y los
     *     otros dos SI persisten su mensaje. Que el masivo siga despues de tropezar con uno es lo
     *     minimo, pero ademas el salteado tiene que VERSE: ocultarlo dejaria al operador creyendo
     *     que le mando a todos.
     *
     * (b) Corriendo el job directo sobre el cliente sin telefono: se loguea con `Log::info` y NO
     *     con `Log::error`. La particion no es cosmetica. Un cliente sin telefono cargado es una
     *     condicion esperable del negocio y pasa todos los dias; si eso fuera un error, el canal
     *     se llenaria de ruido y el fallo que si importa —Kapso no confirmo el envio— dejaria de
     *     verse. Es la misma particion que ya documenta `SendSaleWhatsappJob`.
     *
     * @test
     * @return void
     */
    public function un_cliente_sin_telefono_no_corta_el_masivo()
    {
        $this->dar_extension();

        $primero = $this->crear_cliente('Primero con telefono', '5493416001111');
        $del_medio = $this->crear_cliente('El del medio sin telefono', null);
        $ultimo = $this->crear_cliente('Ultimo con telefono', '5493416003333');

        // Ventana abierta para los dos que si tienen telefono: asi el envio va por texto libre y
        // no depende de que la plantilla este aprobada en Meta.
        $chat_primero = $this->chat_con_ventana_abierta($primero);
        $chat_ultimo = $this->chat_con_ventana_abierta($ultimo);

        $this->venta_sin_cobrar($primero, 40, 1000);
        $this->venta_sin_cobrar($del_medio, 40, 2000);
        $this->venta_sin_cobrar($ultimo, 40, 3000);

        Http::fake([
            'api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.MASIVO']]], 200),
            '*' => Http::response([], 200),
        ]);

        // ---------- (a) El endpoint ----------

        $respuesta = $this->postJson('api/recordatorio-cobro/enviar-masivo', ['dias' => 0]);

        $respuesta->assertStatus(202);

        $json = $respuesta->json();

        $this->assertSame(3, $json['total_clientes'], 'Los tres clientes con ventas sin cobrar tienen que estar contados.');
        $this->assertSame(2, $json['encolados'], 'El sin telefono se saltea; los otros dos se encolan.');

        $this->assertCount(1, $json['salteados']);
        $this->assertSame((int) $del_medio->id, (int) $json['salteados'][0]['client_id']);
        $this->assertSame(
            RecordatorioCobroSenderService::CODE_SIN_TELEFONO,
            $json['salteados'][0]['motivo'],
            'El salteado tiene que decir POR QUE se salteo: es lo unico que el operador puede accionar.'
        );

        // La cola corre en `sync` en los tests, asi que los dos jobs ya se ejecutaron.
        $mensajes_primero = $this->mensajes_salientes($chat_primero->id);
        $mensajes_ultimo = $this->mensajes_salientes($chat_ultimo->id);

        $this->assertCount(1, $mensajes_primero, 'El cliente ANTERIOR al que fallo tiene que haber recibido su recordatorio.');
        $this->assertCount(1, $mensajes_ultimo, 'El cliente POSTERIOR al que fallo tambien: el masivo no se corta.');

        $this->assertSame(RecordatorioCobroSenderService::SOURCE, $mensajes_primero->first()->source);
        $this->assertSame(RecordatorioCobroSenderService::SOURCE, $mensajes_ultimo->first()->source);

        // ---------- (b) El job del cliente sin telefono, con el log bajo la lupa ----------

        $log = Log::spy();
        // El service y `WhatsappBotSendService` loguean con `Log::channel('daily')->...`: que
        // devuelva el propio spy.
        $log->shouldReceive('channel')->andReturnSelf();

        // No propaga nada: si lanzara, en la cola real el job quedaria como fallido y el resto del
        // lote se vuelve ruido.
        $job = new SendRecordatorioCobroJob($this->owner->id, $del_medio->id, 0, $this->owner->id, null);
        $job->handle();

        $log->shouldHaveReceived('info')
            ->with('SendRecordatorioCobroJob: no se envió el recordatorio (condición controlada).', Mockery::type('array'))
            ->once();

        // 🔴 NI UN `Log::error`. Un cliente sin telefono cargado no es una falla de
        // infraestructura y no puede taparle el lugar a una que si lo sea.
        $log->shouldNotHaveReceived('error');

        // Y no quedo ninguna fila diciendo que se le mando algo.
        $this->assertSame(0, $this->mensajes_salientes_de($del_medio->id)->count());
    }

    /**
     * TEST 7 — un job por CLIENTE, ninguno duplicado.
     *
     * La unidad del masivo es el cliente y no la venta. Un cliente con seis ventas vencidas
     * recibe UN mensaje con las cinco mas viejas listadas y una linea "y 1 venta mas": seis
     * mensajes seguidos al mismo telefono serian spam, y Meta los trata como tal.
     *
     * @test
     * @return void
     */
    public function el_masivo_despacha_un_job_por_cliente_y_ninguno_duplicado()
    {
        $this->dar_extension();

        // Los dos limpios. Al primero se le cargan SEIS ventas vencidas a proposito: si la unidad
        // fuera la venta, este solo generaria seis jobs.
        $limpio_con_seis_ventas = $this->crear_cliente('Deudor con seis ventas', '5493416004444');
        $limpio_con_una_venta = $this->crear_cliente('Deudor con una venta', '5493416005555');

        // Los dos que se saltean, uno por cada motivo.
        $ya_recibio = $this->crear_cliente('Ya recibio hace dos horas', '5493416006666');
        $sin_telefono = $this->crear_cliente('Sin telefono cargado', null);

        for ($i = 0; $i < 6; $i++) {
            $this->venta_sin_cobrar($limpio_con_seis_ventas, 30 + $i, 1000);
        }

        $this->venta_sin_cobrar($limpio_con_una_venta, 40, 500);
        $this->venta_sin_cobrar($ya_recibio, 40, 700);
        $this->venta_sin_cobrar($sin_telefono, 40, 900);

        // El chat del que ya recibio, con el recordatorio de hace dos horas adentro.
        $chat_ya_recibio = $this->chat_con_ventana_abierta($ya_recibio);
        $this->recordatorio_ya_enviado($chat_ya_recibio, 2);

        Bus::fake();

        $respuesta = $this->postJson('api/recordatorio-cobro/enviar-masivo', ['dias' => 0]);

        $respuesta->assertStatus(202);

        // (a) DOS jobs. No seis por las seis ventas del primero, ni cuatro por los cuatro
        // clientes: dos, los dos que efectivamente pueden recibirlo.
        Bus::assertDispatchedTimes(SendRecordatorioCobroJob::class, 2);

        // (b) Y son exactamente esos dos, sin repetidos.
        $ids_despachados = [];

        Bus::assertDispatched(SendRecordatorioCobroJob::class, function ($job) use (&$ids_despachados) {
            $ids_despachados[] = $this->client_id_del_job($job);
            return true;
        });

        sort($ids_despachados);

        $esperados = [(int) $limpio_con_seis_ventas->id, (int) $limpio_con_una_venta->id];
        sort($esperados);

        $this->assertSame($esperados, $ids_despachados, 'Los jobs despachados tienen que ser los dos clientes limpios, uno por cliente.');
        $this->assertSame($ids_despachados, array_values(array_unique($ids_despachados)), 'Ningun cliente puede aparecer dos veces.');

        // (c) El 202 cuenta a los cuatro y explica los dos motivos de salteo.
        $json = $respuesta->json();

        $this->assertSame(4, $json['total_clientes']);
        $this->assertSame(2, $json['encolados']);
        $this->assertCount(2, $json['salteados']);

        $motivos = [];

        foreach ($json['salteados'] as $salteado) {
            $motivos[(int) $salteado['client_id']] = $salteado['motivo'];
        }

        $this->assertSame(
            RecordatorioCobroSenderService::CODE_YA_ENVIADO,
            $motivos[(int) $ya_recibio->id],
            'El freno de 24 h tiene que MOSTRARSE en el resultado, no esconder al cliente.'
        );

        $this->assertSame(
            RecordatorioCobroSenderService::CODE_SIN_TELEFONO,
            $motivos[(int) $sin_telefono->id]
        );
    }
}
