<?php

namespace Tests\Feature\Alertas;

use App\Exceptions\RecordatorioCobroException;
use App\Jobs\SendRecordatorioCobroJob;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\PermissionEmpresa;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTemplate;
use App\Http\Controllers\Helpers\WhatsappTemplateStandardHelper;
use App\Services\RecordatorioCobroSenderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            // 🔴 El MISMO texto que declara `WhatsappTemplateStandardHelper`, que es el que se le
            // da de alta a Meta. Ojo con {{3}}: lleva el sustantivo adentro ("1 venta" / "7
            // ventas") y el body dice "Tenés {{3}} pendientes de pago", porque el singular no se
            // puede resolver desde el texto fijo de una plantilla aprobada.
            'body_preview'       => WhatsappTemplateStandardHelper::body_preview_de(RecordatorioCobroSenderService::TEMPLATE_META_NAME),
            'variables'          => ['Nombre', 'Negocio', 'Cantidad de ventas (ej: "7 ventas")', 'Montos pendientes'],
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

        // 🔴 Los dos clientes limpios NO tienen chat, asi que su ventana de 24 h esta CERRADA y
        // les toca el camino de plantilla. Sin la plantilla aprobada, el masivo los saltea a los
        // dos con `plantilla_no_aprobada` y no encola nada: es exactamente lo que tiene que pasar
        // (`cc_cli_recordatorio_cobro` nace `pendiente_meta`). Este test mide OTRA cosa —que la
        // unidad del masivo sea el cliente y no la venta—, asi que se le da el escenario en el
        // que el envio si puede salir.
        $this->plantilla_aprobada();

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

    /**
     * TEST 8 — 🔴 UN NULL DE KAPSO NO PERSISTE NINGUNA FILA, en los DOS caminos.
     *
     * Es la invariante mas cara del modulo y hasta ahora no tenia ni una red. `send_text()` y
     * `send_template()` se tragan sus errores y devuelven null: ese null significa que el mensaje
     * NO salio. Si igual se persistiera la fila, pasan tres cosas juntas y ninguna se ve: el
     * cliente se queda sin aviso, la pantalla muestra un mensaje enviado que nunca existio, y —lo
     * peor— la fila con `source = 'recordatorio_cobro'` ARMA EL FRENO DE 24 H, asi que el
     * siguiente intento tampoco sale. Es el bug que se cerro el 15/8/2026 en
     * `SaleWhatsappSenderService` y que el docblock de la clase promete que no se reabre.
     *
     * @test
     * @return void
     */
    public function un_null_de_kapso_no_persiste_ninguna_fila()
    {
        // Kapso caido: 500 en todo. `WhatsappBotSendService` loguea y devuelve null.
        Http::fake([
            'api.kapso.ai/*' => Http::response(['error' => 'algo se rompio en Kapso'], 500),
            '*' => Http::response([], 200),
        ]);

        // ---------- (a) TEXTO LIBRE ----------

        $chat_abierto = $this->chat_con_ventana_abierta($this->cliente);
        $venta = $this->venta_sin_cobrar($this->cliente, 45, 4000);

        $exploto = null;

        try {
            (new RecordatorioCobroSenderService())->enviar($this->owner->id, $this->cliente, [$venta], null);
        } catch (RecordatorioCobroException $e) {
            $exploto = $e;
        }

        $this->assertNotNull($exploto, 'Un envio que Kapso no confirmo tiene que lanzar, no devolver un mensaje como si hubiera salido.');
        $this->assertSame(RecordatorioCobroSenderService::CODE_ENVIO_NO_CONFIRMADO, $exploto->error_code());

        $this->assertSame(
            0,
            $this->mensajes_salientes($chat_abierto->id)->count(),
            '🔴 Kapso no confirmo el envio: no puede quedar NI UNA fila diciendo que el mensaje salio.'
        );

        // Y el freno de 24 h NO quedo armado: el proximo intento tiene que poder salir.
        $this->assertFalse(
            RecordatorioCobroSenderService::ya_recibio_recordatorio($chat_abierto->fresh()),
            'Una fila fantasma ademas activaria el freno de 24 h y el reintento tampoco saldria.'
        );

        // ---------- (b) PLANTILLA ----------

        $otro_cliente = $this->crear_cliente('Ana Lopez', '5493416007766');
        $chat_cerrado = $this->chat_fuera_de_ventana($otro_cliente);
        $this->plantilla_aprobada();

        $venta_de_ana = $this->venta_sin_cobrar($otro_cliente, 60, 2500);

        $exploto_plantilla = null;

        try {
            (new RecordatorioCobroSenderService())->enviar($this->owner->id, $otro_cliente, [$venta_de_ana], null);
        } catch (RecordatorioCobroException $e) {
            $exploto_plantilla = $e;
        }

        $this->assertNotNull($exploto_plantilla);
        $this->assertSame(RecordatorioCobroSenderService::CODE_ENVIO_NO_CONFIRMADO, $exploto_plantilla->error_code());

        $this->assertSame(
            0,
            $this->mensajes_salientes($chat_cerrado->id)->count(),
            '🔴 El camino de plantilla tiene exactamente la misma garantia: tapar uno solo deja el agujero abierto la mitad de las veces.'
        );

        $this->assertFalse(RecordatorioCobroSenderService::ya_recibio_recordatorio($chat_cerrado->fresh()));
    }

    /**
     * TEST 9 — el job revalida el freno de 24 h justo antes de mandar.
     *
     * El escenario es real y no de laboratorio: dos operadores mirando la misma pantalla de
     * cobros. El primero dispara el masivo, los jobs se encolan; el segundo, mientras la cola
     * todavia no llego a ese cliente, le manda el recordatorio de a uno. Cuando el job arranca, el
     * cliente YA recibio el mensaje. El chequeo del controller es informativo y quedo viejo: el
     * unico que puede frenar esto es el job, consultando el mismo `ya_recibio_recordatorio()`
     * estatico un instante antes de enviar.
     *
     * @test
     * @return void
     */
    public function el_job_revalida_el_freno_de_24_horas_antes_de_mandar()
    {
        Http::fake([
            'api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.NO_DEBERIA_SALIR']]], 200),
            '*' => Http::response([], 200),
        ]);

        $chat = $this->chat_con_ventana_abierta($this->cliente);
        $this->venta_sin_cobrar($this->cliente, 40, 1000);

        // El job queda "encolado": construido, todavia sin correr.
        $job = new SendRecordatorioCobroJob($this->owner->id, $this->cliente->id, 0, $this->owner->id, null);

        // Y recien AHORA otro operador le manda el recordatorio de a uno.
        $anterior = $this->recordatorio_ya_enviado($chat, 0);

        $job->handle();

        $salientes = $this->mensajes_salientes($chat->id);

        $this->assertCount(1, $salientes, 'El job tiene que frenarse solo: la unica fila saliente es la que dejo el otro operador.');
        $this->assertSame(
            $anterior->wa_message_id,
            $salientes->first()->wa_message_id,
            'La fila que quedo es la del envio anterior, no una nueva.'
        );

        // Y no salio nada a Kapso: el freno corta ANTES de gastar un mensaje de Meta.
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'api.kapso.ai') !== false;
        });
    }

    /**
     * TEST 10 — el 403 por permiso y el aislamiento entre empresas.
     *
     * Dos cosas distintas en el mismo test porque las dos responden a la misma pregunta: quien
     * puede tocar QUE. Mandar un WhatsApp a todos los deudores del negocio no es lo mismo que ver
     * la solapa de cobros, y el progreso de un lote no puede leerse desde otra empresa aunque se
     * adivine el uuid.
     *
     * 🔴 El 403 se afirma por el `code` del cuerpo y no solo por el status: el middleware
     * `check_extencion_empresa` tambien contesta 403, asi que un test que mirara nada mas el
     * numero pasaria igual con el permiso completamente roto.
     *
     * @test
     * @return void
     */
    public function sin_permiso_da_403_y_un_lote_no_se_lee_desde_otra_empresa()
    {
        $this->dar_extension();

        $this->venta_sin_cobrar($this->cliente, 40, 1000);

        $empleado = User::create([
            'name'         => 'Empleado sin permiso',
            'company_name' => 'Ferreteria Norte',
            'email'        => 'recordatorio-cobro-empleado-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->owner->id,
            'admin_access' => 0,
            'ver_alertas_de_todos_los_empleados' => 1,
        ]);

        $this->actuar_como($empleado);

        // Los cuatro endpoints, uno por uno: el permiso no puede estar puesto en tres de cuatro.
        $rutas = [
            $this->getJson('api/recordatorio-cobro/preview/'.$this->cliente->id.'?dias=0'),
            $this->postJson('api/recordatorio-cobro/enviar', ['client_id' => $this->cliente->id, 'dias' => 0]),
            $this->postJson('api/recordatorio-cobro/enviar-masivo', ['dias' => 0]),
            $this->getJson('api/recordatorio-cobro/lote/'.(string) Str::uuid()),
        ];

        foreach ($rutas as $respuesta) {
            $respuesta->assertStatus(403);
            $respuesta->assertJsonPath('code', 'sin_permiso');
        }

        // Con el permiso puesto, el MISMO empleado ya no recibe 403. Sin esta mitad, el test
        // pasaria igual si `check_permiso()` devolviera false siempre.
        // `forceCreate` y no `create`: `PermissionEmpresa` no declara `$fillable`, asi que fuera de
        // `db:seed` (que corre todo adentro de `Model::unguarded()`) un `create()` masivo tira
        // `MassAssignmentException`. Mismo tratamiento que `dar_extension()`.
        $permiso = PermissionEmpresa::where('slug', 'alerts.recordatorio_cobro')->first();

        if (is_null($permiso)) {
            $permiso = PermissionEmpresa::forceCreate([
                'slug'       => 'alerts.recordatorio_cobro',
                'name'       => 'Mandar recordatorios de cobro por WhatsApp',
                'model_name' => 'Alertas',
            ]);
        }

        $empleado->permissions()->attach($permiso->id);

        $this->actuar_como($empleado->fresh());

        $con_permiso = $this->getJson('api/recordatorio-cobro/preview/'.$this->cliente->id.'?dias=0');

        $this->assertNotSame(403, $con_permiso->getStatusCode(), 'Con el permiso puesto el empleado tiene que pasar el gate.');

        // ---------- Aislamiento entre empresas ----------

        $this->actuar_como($this->owner);

        // (a) Un cliente de OTRA empresa no se previsualiza: 404, no el mensaje de otro negocio.
        $otro_owner = User::create([
            'name'         => 'Dueño de otra empresa',
            'company_name' => 'Otro negocio',
            'email'        => 'recordatorio-cobro-otro-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $cliente_ajeno = Client::create([
            'name'    => 'Cliente de otra empresa',
            'user_id' => $otro_owner->id,
            'phone'   => '5493416000001',
        ]);

        $this->getJson('api/recordatorio-cobro/preview/'.$cliente_ajeno->id.'?dias=0')
            ->assertStatus(404)
            ->assertJsonPath('code', 'cliente_no_encontrado');

        // (b) El lote de otra empresa no se lee, aunque se sepa el uuid exacto. 🔴 El guard es el
        // `owner_id` adentro de la clave de Cache, y esto es lo que lo prueba: el MISMO uuid
        // devuelve 404 bajo la empresa ajena y 200 bajo la propia.
        $uuid = (string) Str::uuid();

        $lote = ['uuid' => $uuid, 'total' => 3, 'procesados' => 3, 'enviados' => 3, 'fallidos' => 0, 'terminado' => true];

        Cache::put(SendRecordatorioCobroJob::cache_key($otro_owner->id, $uuid), $lote, now()->addHour());

        $this->getJson('api/recordatorio-cobro/lote/'.$uuid)
            ->assertStatus(404)
            ->assertJsonPath('code', 'lote_no_encontrado');

        Cache::put(SendRecordatorioCobroJob::cache_key($this->owner->id, $uuid), $lote, now()->addHour());

        $this->getJson('api/recordatorio-cobro/lote/'.$uuid)
            ->assertStatus(200)
            ->assertJsonPath('lote.uuid', $uuid);
    }

    /**
     * TEST 11 — la previsualizacion dice POR CUAL DE LOS DOS CAMINOS va a salir, y a QUE numero.
     *
     * Es la decision 2 de Lucas y es lo unico que el operador lee antes de apretar "Enviar". Tres
     * mitades:
     *
     * (a) Ventana abierta -> `texto_libre`, sin plantilla: el mensaje lleva la lista de ventas.
     * (b) Ventana cerrada -> `plantilla`, con el nombre tecnico y el adjunto resueltos: ahi el
     *     detalle una-por-renglon no existe, y la pantalla tiene que decirlo.
     * (c) 🔴 El `phone` que muestra la pantalla es AL QUE VA A SALIR EL MENSAJE. El envio manda a
     *     `$chat->phone`, no al de la ficha del cliente: si la pantalla mostrara el de la ficha,
     *     estaria afirmando un numero al que el mensaje no llega. Y de paso clava que el chat se
     *     encuentra aunque la ficha tenga el telefono escrito a mano ("0341 600-9911") y el chat
     *     lo tenga como lo manda Meta ("5493416009911"): con comparacion exacta no matchean, se
     *     crearia un chat duplicado y el envio saldria por plantilla en vez de texto libre.
     *
     * @test
     * @return void
     */
    public function la_previsualizacion_dice_por_cual_de_los_dos_caminos_va_a_salir()
    {
        $this->dar_extension();
        $this->plantilla_aprobada();

        // ---------- (a) Ventana abierta ----------

        $this->chat_con_ventana_abierta($this->cliente);
        $venta = $this->venta_sin_cobrar($this->cliente, 45, 4000);

        $abierta = $this->getJson('api/recordatorio-cobro/preview/'.$this->cliente->id.'?dias=0');

        $abierta->assertStatus(200);
        $abierta->assertJsonPath('preview.canal', 'texto_libre');
        $abierta->assertJsonPath('preview.ventana_abierta', true);
        $abierta->assertJsonPath('preview.template_meta_name', null);
        $abierta->assertJsonPath('preview.puede_enviar', true);

        $this->assertNotFalse(
            strpos($abierta->json('preview.body'), '/sale/pdf/'.$venta->id),
            'El texto libre es el unico camino que lleva la lista de ventas una por renglon.'
        );

        // ---------- (b) Ventana cerrada ----------

        $cliente_cerrado = $this->crear_cliente('Ana Lopez', '5493416007766');
        $this->chat_fuera_de_ventana($cliente_cerrado);
        $this->venta_sin_cobrar($cliente_cerrado, 60, 2500);

        $cerrada = $this->getJson('api/recordatorio-cobro/preview/'.$cliente_cerrado->id.'?dias=0');

        $cerrada->assertStatus(200);
        $cerrada->assertJsonPath('preview.canal', 'plantilla');
        $cerrada->assertJsonPath('preview.ventana_abierta', false);
        $cerrada->assertJsonPath('preview.template_meta_name', RecordatorioCobroSenderService::TEMPLATE_META_NAME);
        $cerrada->assertJsonPath('preview.puede_enviar', true);

        $this->assertNotNull(
            $cerrada->json('preview.documento_url'),
            'Con la ventana cerrada la plantilla va con header DOCUMENT: sin adjunto, Meta la rechaza y el envio se saltea.'
        );

        // ---------- (c) El telefono que se muestra es el del chat ----------

        $con_telefono_a_mano = $this->crear_cliente('Carlos con telefono a mano', '0341 600-9911');

        // El chat lo abrio el webhook de Kapso: sin `client_id` vinculado y con el numero como lo
        // manda Meta.
        WhatsappChat::create([
            'user_id'         => $this->owner->id,
            'client_id'       => null,
            'phone'           => '5493416009911',
            'ai_enabled'      => false,
            'unread_count'    => 0,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $this->venta_sin_cobrar($con_telefono_a_mano, 50, 1500);

        $del_chat = $this->getJson('api/recordatorio-cobro/preview/'.$con_telefono_a_mano->id.'?dias=0');

        $del_chat->assertStatus(200);
        $del_chat->assertJsonPath('preview.phone', '5493416009911');
        $del_chat->assertJsonPath(
            'preview.canal',
            'texto_libre'
        );
    }

    /**
     * TEST 12 — multimoneda: UN mensaje con los dos montos SEPARADOS.
     *
     * Decision 3 de Lucas. Sumar $12.500 y USD 300 da 12.800 de nada: un numero que no existe en
     * ninguna moneda y que el cliente no puede pagar. Y tampoco son dos mensajes: dos WhatsApp
     * seguidos al mismo numero por la misma deuda son spam, y Meta los trata como tal.
     *
     * @test
     * @return void
     */
    public function multimoneda_un_solo_mensaje_con_los_montos_separados()
    {
        Http::fake([
            'api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.MULTIMONEDA']]], 200),
            '*' => Http::response([], 200),
        ]);

        $chat = $this->chat_con_ventana_abierta($this->cliente);

        $en_pesos = $this->venta_sin_cobrar($this->cliente, 45, 12500, 1);
        $en_dolares = $this->venta_sin_cobrar($this->cliente, 30, 300, 2);

        $mensaje = (new RecordatorioCobroSenderService())
            ->enviar($this->owner->id, $this->cliente, [$en_pesos, $en_dolares], null);

        // UN mensaje, no dos.
        $this->assertCount(1, $this->mensajes_salientes($chat->id));

        // Los dos montos, cada uno con su simbolo.
        $this->assertNotFalse(strpos($mensaje->body, '$12.500'), 'Tiene que decir el total en pesos.');
        $this->assertNotFalse(strpos($mensaje->body, 'USD 300'), 'Y el total en dolares, aparte.');

        // 🔴 Y NO la suma. 12500 + 300 = 12800 no es plata de ninguna moneda.
        $this->assertFalse(strpos($mensaje->body, '12.800'), 'Los montos de monedas distintas NO se suman.');

        // La pantalla recibe lo mismo: dos renglones, uno por moneda.
        $preview = (new RecordatorioCobroSenderService())
            ->previsualizar($this->owner->id, $this->cliente, [$en_pesos, $en_dolares], null);

        $this->assertCount(2, $preview['totales_por_moneda']);

        $etiquetas = [];

        foreach ($preview['totales_por_moneda'] as $total) {
            $etiquetas[(int) $total['moneda_id']] = $total['etiqueta'];
        }

        $this->assertSame('$12.500', $etiquetas[1]);
        $this->assertSame('USD 300', $etiquetas[2]);
    }

    /**
     * TEST 13 — el lote queda CONTADO aunque los jobs corran inline, y los fallos dicen por que.
     *
     * Dos defectos en el mismo escenario porque los dos se ven en la misma pantalla:
     *
     * (a) 🔴 EL ORDEN. `QUEUE_CONNECTION` es `sync` por default (`config/queue.php`) y es lo que
     *     corre en los tests: en sync el job se ejecuta INLINE adentro del `dispatch()`. Con el
     *     lote escrito en Cache DESPUES del `foreach` de despachos, cada job hacia su
     *     `Cache::get()`, no encontraba nada, se iba sin contar, y despues el `put` pisaba todo
     *     con ceros. El operador mandaba 20 recordatorios, los 20 salian, y el modal mostraba
     *     "0 de 20" cinco minutos para terminar diciendo que se seguian mandando solos.
     * (b) Los motivos de fallo. Cuatro casos se detectan recien adentro del job, o sea despues de
     *     que el 202 ya contesto. Si solo fueran a un `Log`, en pantalla salen como un numero
     *     pelado y el operador no tiene con que accionar.
     *
     * @test
     * @return void
     */
    public function el_lote_cuenta_los_jobs_inline_y_guarda_el_motivo_de_cada_fallo()
    {
        $this->dar_extension();

        $bien_1 = $this->crear_cliente('Recibe bien uno', '5493416001111');
        $bien_2 = $this->crear_cliente('Recibe bien dos', '5493416002222');
        $rechazado = $this->crear_cliente('Kapso lo rechaza', '5493416008888');

        // Ventana abierta para los tres: asi el camino es texto libre y lo unico que cambia entre
        // ellos es lo que contesta Kapso.
        $this->chat_con_ventana_abierta($bien_1);
        $this->chat_con_ventana_abierta($bien_2);
        $chat_rechazado = $this->chat_con_ventana_abierta($rechazado);

        $this->venta_sin_cobrar($bien_1, 40, 1000);
        $this->venta_sin_cobrar($bien_2, 40, 2000);
        $this->venta_sin_cobrar($rechazado, 40, 3000);

        // 🔴 UN SOLO `Http::fake()` con stub-closure: llamarlo dos veces los MERGEA y el primero
        // sigue ganando. El stub decide por el numero destino, que es lo unico que distingue a los
        // tres envios.
        Http::fake([
            'api.kapso.ai/*' => function ($request) {
                $body = json_decode($request->body(), true);

                $destino = isset($body['to']) ? (string) $body['to'] : '';

                if (strpos($destino, '8888') !== false) {
                    return Http::response(['error' => 'numero rechazado por Meta'], 500);
                }

                return Http::response(['messages' => [['id' => 'wamid.LOTE.'.$destino]]], 200);
            },
            '*' => Http::response([], 200),
        ]);

        $respuesta = $this->postJson('api/recordatorio-cobro/enviar-masivo', ['dias' => 0]);

        $respuesta->assertStatus(202);
        $this->assertSame(3, $respuesta->json('encolados'));

        // Con `sync` los tres jobs YA corrieron, adentro del dispatch.
        $estado = $this->getJson('api/recordatorio-cobro/lote/'.$respuesta->json('lote_uuid'));

        $estado->assertStatus(200);

        $lote = $estado->json('lote');

        $this->assertSame(3, $lote['total']);
        $this->assertSame(3, $lote['procesados'], '🔴 Los tres jobs corrieron inline: el lote tiene que haberlos contado a los tres, no quedar en cero.');
        $this->assertSame(2, $lote['enviados']);
        $this->assertSame(1, $lote['fallidos']);
        $this->assertTrue($lote['terminado']);

        // (b) Y el fallido dice POR QUE, agrupado por motivo y con nombre.
        $this->assertSame(
            1,
            $lote['motivos_fallo'][RecordatorioCobroSenderService::CODE_ENVIO_NO_CONFIRMADO],
            'Un fallido sin motivo es un numero que el operador no puede accionar.'
        );

        $this->assertCount(1, $lote['fallos']);
        $this->assertSame((int) $rechazado->id, (int) $lote['fallos'][0]['client_id']);
        $this->assertSame('Kapso lo rechaza', $lote['fallos'][0]['client_name']);

        // Y el que Kapso rechazo NO tiene fila: la garantia del test 8, ahora por el masivo.
        $this->assertSame(0, $this->mensajes_salientes($chat_rechazado->id)->count());
    }
}
