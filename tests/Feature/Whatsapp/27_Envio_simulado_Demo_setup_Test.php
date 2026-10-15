<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\WhatsappDemoHelper;
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
use App\Services\WhatsappBotSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Misión whatsapp-envio-simulado-demos (15/10/2026): lo que el demo setup (y el comando
 * `whatsapp:demo-simulado`, para demo, demo2 y demo3) deja armado en el módulo de WhatsApp.
 *
 * Lo que este archivo tiene que clavar:
 *
 * 1. LA DEMO QUEDA CONECTADA EN MODO SIMULADO Y SIN NINGUNA CREDENCIAL REAL: fila activa con
 *    `envio_simulado = 1`, clave y `phone_number_id` ficticios (aunque la base trajera una real,
 *    que es el caso de demo3, copia de la base de un cliente), y un `webhook_secret` que nadie
 *    conoce.
 * 2. LAS 6 PLANTILLAS ESTÁNDAR APROBADAS, y solo ésas: las del dueño no se tocan.
 * 3. LOS CHATS DE EJEMPLO CUENTAN LAS SITUACIONES QUE SE FILMAN, y se ven como chats REALES:
 *    entrantes con `is_simulated = 0` y `last_inbound_simulated = 0` (con 1 la SPA les pone la
 *    etiqueta «Simulado» y «SIM» en la bandeja), un mensaje de la IA «a confirmar» que no sale
 *    solo, un deudor con la ventana abierta y otro con la cerrada.
 * 4. NO GASTA PLATA NI SALE A INTERNET: ni una request HTTP, ni un job despachado (el camino de
 *    un entrante real agenda al agente, con Anthropic y OpenAI de por medio).
 * 5. LOS TELÉFONOS: sin repetidos entre clientes (el índice único de `whatsapp_chats` haría
 *    reventar el recordatorio al segundo cliente con el mismo número) y, con la opción, sin ningún
 *    número real.
 * 6. ES IDEMPOTENTE Y SE NIEGA FUERA DE UNA DEMO.
 *
 * 🔴 Todo `Http::fake([...])` lleva stub `'*'` al final.
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`).
 */
class Envio_simulado_Demo_setup_Test extends TestCase
{
    use DatabaseTransactions;

    /** El teléfono de Lucas: el único real que puede quedar en una demo. */
    const LUCAS = '3444622139';

    /** @var User */
    protected $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->owner = User::create([
            'name'         => 'Dueño demo whatsapp',
            'company_name' => 'Demo Whatsapp SA',
            'email'        => 'demo-whatsapp-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'api_url'      => 'https://api-test.comerciocity.com',
            'dias_alertar_administradores_ventas_no_cobradas' => 5,
            'dias_alertar_empleados_ventas_no_cobradas'       => 5,
        ]);
    }

    // ------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------

    /**
     * Cliente del dueño con su cuenta corriente y un movimiento (para el PDF de la cuenta).
     *
     * @param string      $nombre
     * @param string|null $phone
     * @param bool        $con_deuda  Si debe una venta de $5.000.
     * @return Client
     */
    protected function cliente($nombre, $phone, $con_deuda = false)
    {
        $client = Client::create([
            'name'    => $nombre,
            'user_id' => $this->owner->id,
            'phone'   => $phone,
        ]);

        $cuenta = CreditAccount::create([
            'user_id'    => $this->owner->id,
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'saldo'      => 0,
        ]);

        CurrentAcount::create([
            'user_id'           => $this->owner->id,
            'client_id'         => $client->id,
            'credit_account_id' => $cuenta->id,
            'moneda_id'         => 1,
            'debe'              => 0,
            'haber'             => 0,
            'saldo'             => 0,
            'status'            => 'pagado',
        ]);

        if ($con_deuda) {
            $creada = now()->subDays(40);

            $venta = Sale::create([
                'user_id'    => $this->owner->id,
                'client_id'  => $client->id,
                'moneda_id'  => 1,
                'total'      => 5000,
                'terminada'  => 1,
                'created_at' => $creada,
                'updated_at' => $creada,
            ]);

            CurrentAcount::create([
                'user_id'           => $this->owner->id,
                'client_id'         => $client->id,
                'credit_account_id' => $cuenta->id,
                'sale_id'           => $venta->id,
                'moneda_id'         => 1,
                'debe'              => 5000,
                'haber'             => 0,
                'saldo'             => 5000,
                'status'            => 'sin_pagar',
                'created_at'        => $creada,
                'updated_at'        => $creada,
            ]);
        }

        return $client;
    }

    /**
     * Una demo típica: cuatro clientes con el teléfono de Lucas (como los de `ClientSeeder`), dos
     * deudores, y clientes con teléfono propio.
     *
     * @return void
     */
    protected function clientes_de_una_demo()
    {
        $this->cliente('Cliente Cuenta Corriente', self::LUCAS, true);
        $this->cliente('Cliente Dos', self::LUCAS, true);
        $this->cliente('Cliente Tres', self::LUCAS);
        $this->cliente('Cliente Cuatro', self::LUCAS);
        $this->cliente('Lucia Fernandez', '3814500005', true);
        $this->cliente('Marcos Gimenez', '3425500006', true);
        $this->cliente('Sofia Ramirez', '1145500007');
        $this->cliente('Tomas Aguirre', '2235500008');
        $this->cliente('Valeria Ortiz', '3814500009');
        $this->cliente('Sin Telefono', null);
    }

    /**
     * Le da al dueño la extensión `whatsapp` y lo autentica.
     *
     * @return void
     */
    protected function entrar_como_dueno()
    {
        $extencion = ExtencionEmpresa::where('slug', 'whatsapp')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'whatsapp', 'name' => 'WhatsApp']);
        }

        $this->owner->extencions()->attach($extencion->id);
        $this->owner->load('extencions');

        Auth::forgetGuards();
        $this->actingAs($this->owner, 'web');
    }

    /**
     * Los chats del dueño, indexados por el id del cliente.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function chats()
    {
        return WhatsappChat::where('user_id', $this->owner->id)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------------------------------
    // 1 y 2. Config y plantillas
    // ------------------------------------------------------------------------------------------

    /**
     * La config nace activa, simulada y SIN credenciales reales; las 6 plantillas, aprobadas.
     *
     * @group whatsapp
     * @test
     */
    public function la_demo_queda_conectada_en_modo_simulado_con_credenciales_ficticias_y_plantillas_aprobadas()
    {
        $this->clientes_de_una_demo();

        $resumen = WhatsappDemoHelper::preparar($this->owner);

        $this->assertSame('creada', $resumen['config']);

        $config = WhatsappBotConfig::where('user_id', $this->owner->id)->first();
        $this->assertNotNull($config);
        $this->assertTrue((bool) $config->is_active);
        $this->assertTrue((bool) $config->envio_simulado);
        $this->assertTrue((bool) $config->chat_simulation_enabled, 'El botón de simular mensaje del cliente es parte de lo que se filma.');
        $this->assertTrue($config->esta_en_modo_demostracion());
        $this->assertSame(WhatsappDemoHelper::CLAVE_FALSA, $config->kapso_api_key);
        $this->assertSame(WhatsappDemoHelper::PHONE_NUMBER_ID_FALSO, $config->phone_number_id);
        $this->assertSame(48, strlen($config->webhook_secret), 'El secreto del webhook es aleatorio: nadie lo conoce, ningún webhook lo puede firmar.');
        $this->assertNotSame('', trim($config->webhook_secret));

        $plantillas = WhatsappTemplate::where('user_id', $this->owner->id)->get();
        $this->assertCount(6, $plantillas);

        foreach ($plantillas as $plantilla) {
            $this->assertSame('aprobada', $plantilla->status, $plantilla->meta_template_name);
            $this->assertTrue((bool) $plantilla->is_system);
        }

        $this->assertSame(6, $resumen['plantillas_aprobadas']);
    }

    /**
     * Una base copiada de un cliente real (demo3) puede traer SU clave de Kapso: se pisa.
     *
     * @group whatsapp
     * @test
     */
    public function pisa_las_credenciales_reales_que_traiga_la_base_y_no_toca_plantillas_ajenas()
    {
        WhatsappBotConfig::create([
            'user_id'         => $this->owner->id,
            'kapso_api_key'   => 'kapso_live_CLAVE_REAL_DE_OTRO_NEGOCIO',
            'phone_number_id' => '1164186643445476',
            'webhook_secret'  => 'secreto-real',
            'is_active'       => false,
            'envio_simulado'  => false,
        ]);

        $propia = WhatsappTemplate::create([
            'user_id'            => $this->owner->id,
            'name'               => 'Mi plantilla',
            'meta_template_name' => 'mi_plantilla_propia',
            'category'           => 'utility',
            'language'           => 'es_AR',
            'body_preview'       => 'Hola {{1}}',
            'variables'          => ['Nombre'],
            'status'             => 'borrador',
            'is_system'          => false,
        ]);

        $resumen = WhatsappDemoHelper::preparar($this->owner, ['chats' => false]);

        $this->assertSame('actualizada', $resumen['config']);

        $config = WhatsappBotConfig::where('user_id', $this->owner->id)->first();
        $this->assertSame(WhatsappDemoHelper::CLAVE_FALSA, $config->kapso_api_key, 'La clave real no puede quedar viva en una demo.');
        $this->assertSame(WhatsappDemoHelper::PHONE_NUMBER_ID_FALSO, $config->phone_number_id);
        $this->assertNotSame('secreto-real', $config->webhook_secret);
        $this->assertTrue((bool) $config->is_active);
        $this->assertTrue((bool) $config->envio_simulado);

        $this->assertSame('borrador', $propia->fresh()->status, 'Las plantillas que armó el dueño no se aprueban.');
        $this->assertSame(1, WhatsappBotConfig::where('user_id', $this->owner->id)->count());
    }

    // ------------------------------------------------------------------------------------------
    // 3. Los chats de ejemplo
    // ------------------------------------------------------------------------------------------

    /**
     * Los cinco chats, cada uno con la situación que se filma.
     *
     * @group whatsapp
     * @test
     */
    public function siembra_cinco_chats_que_cuentan_cada_situacion_y_se_ven_como_chats_reales()
    {
        $this->clientes_de_una_demo();

        $resumen = WhatsappDemoHelper::preparar($this->owner);

        $this->assertSame(5, $resumen['chats_sembrados'], 'Omitidos: ' . implode(' | ', $resumen['chats_omitidos']));

        $chats = $this->chats();
        $this->assertCount(5, $chats);

        // Un chat por teléfono, y ninguno en simulación: son chats "reales".
        $this->assertCount(5, $chats->pluck('phone')->unique());

        foreach ($chats as $chat) {
            $this->assertSame(0, (int) $chat->last_inbound_simulated, 'Con 1 la bandeja le pone «SIM» al chat.');
            $this->assertNotNull($chat->client_id);
        }

        foreach (WhatsappChatMessage::whereIn('whatsapp_chat_id', $chats->pluck('id'))->get() as $mensaje) {
            $this->assertFalse((bool) $mensaje->is_simulated, 'Con 1 la SPA le pone la etiqueta «Simulado» al mensaje.');
        }

        // Ventana: tres chats abiertos (deudor, comprobante, IA, atendido = cuatro con entrante
        // reciente) y uno cerrado (el deudor de hace 3 días).
        $cerrados = $chats->filter(function ($chat) {
            return ! $chat->is_within_service_window();
        });
        $this->assertCount(1, $cerrados, 'Un solo chat con la ventana cerrada: el deudor que escribió hace 3 días.');

        // Los dos deudores son los de las ventas sin cobrar.
        $deudores = Client::where('user_id', $this->owner->id)->whereIn('name', ['Cliente Cuenta Corriente', 'Lucia Fernandez', 'Marcos Gimenez', 'Cliente Dos'])->pluck('id')->all();
        $this->assertCount(2, $chats->filter(function ($chat) use ($deudores) {
            return in_array($chat->client_id, $deudores);
        }), 'Dos de los chats son de clientes con deuda.');

        // La respuesta de la IA pendiente: a confirmar, sin id de WhatsApp y SIN auto-envío.
        $pendiente = WhatsappChatMessage::whereIn('whatsapp_chat_id', $chats->pluck('id'))->where('ai_status', 'a_confirmar')->get();
        $this->assertCount(1, $pendiente);
        $this->assertNull($pendiente->first()->wa_message_id);
        $this->assertNull($pendiente->first()->ai_auto_send_at, 'No puede salir sola: no hay job que la mande.');
        $this->assertSame('pendiente', $pendiente->first()->delivery_status);
        $this->assertSame('esperando_aprobacion', WhatsappChat::find($pendiente->first()->whatsapp_chat_id)->estado_pendiente());

        // Hay chats sin responder (tarjeta roja) y uno atendido (sin estado pendiente).
        $estados = $chats->map(function ($chat) {
            return $chat->estado_pendiente();
        })->all();
        $this->assertContains('sin_responder', $estados);
        $this->assertContains(null, $estados);

        // Los salientes ya enviados llevan un id del modo demostración y quedan entregados o leídos.
        $enviados = WhatsappChatMessage::whereIn('whatsapp_chat_id', $chats->pluck('id'))
            ->where('direction', 'out')
            ->whereNotNull('wa_message_id')
            ->get();
        $this->assertGreaterThan(0, $enviados->count());
        foreach ($enviados as $mensaje) {
            $this->assertTrue(WhatsappBotSendService::es_id_simulado($mensaje->wa_message_id));
            $this->assertContains($mensaje->delivery_status, ['entregado', 'leido']);
        }

        // Los no leídos coinciden con los entrantes sin contestar.
        foreach ($chats as $chat) {
            $this->assertSame($chat->is_sin_responder() || $chat->is_esperando_aprobacion() ? true : false, (int) $chat->unread_count > 0, 'unread_count de ' . $chat->phone);
        }
    }

    /**
     * Sembrar los chats NO gasta plata ni sale a internet: ni una request HTTP ni un job (el camino
     * de un entrante real agenda al agente, con Anthropic y embeddings de OpenAI).
     *
     * @group whatsapp
     * @test
     */
    public function preparar_no_sale_a_internet_ni_despacha_jobs()
    {
        Http::fake(['*' => Http::response([], 200)]);
        Bus::fake();

        $this->clientes_de_una_demo();

        WhatsappDemoHelper::preparar($this->owner);

        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    /**
     * Idempotente: dos corridas dejan lo mismo, y el secreto del webhook no cambia.
     *
     * @group whatsapp
     * @test
     */
    public function correrlo_dos_veces_deja_lo_mismo()
    {
        $this->clientes_de_una_demo();

        WhatsappDemoHelper::preparar($this->owner);

        $secreto = WhatsappBotConfig::where('user_id', $this->owner->id)->first()->webhook_secret;
        $chats = WhatsappChat::where('user_id', $this->owner->id)->count();
        $mensajes = WhatsappChatMessage::whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->owner->id)->pluck('id'))->count();

        $segunda = WhatsappDemoHelper::preparar($this->owner);

        $this->assertSame('actualizada', $segunda['config']);
        $this->assertSame(0, $segunda['chats_sembrados'], 'Los clientes ya tienen su chat: no se vuelve a sembrar.');
        $this->assertSame(1, WhatsappBotConfig::where('user_id', $this->owner->id)->count());
        $this->assertSame(6, WhatsappTemplate::where('user_id', $this->owner->id)->count());
        $this->assertSame($chats, WhatsappChat::where('user_id', $this->owner->id)->count());
        $this->assertSame($mensajes, WhatsappChatMessage::whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->owner->id)->pluck('id'))->count());
        $this->assertSame($secreto, WhatsappBotConfig::where('user_id', $this->owner->id)->first()->webhook_secret);
    }

    /**
     * Con pocos clientes siembra los que puede y dice cuáles omitió: nunca inventa un cliente.
     *
     * @group whatsapp
     * @test
     */
    public function sin_clientes_suficientes_siembra_los_que_puede_y_lo_informa()
    {
        $this->cliente('Unico Cliente', '3814500005', true);

        $resumen = WhatsappDemoHelper::preparar($this->owner);

        $this->assertSame(1, $resumen['chats_sembrados']);
        $this->assertCount(4, $resumen['chats_omitidos']);
        $this->assertSame(1, Client::where('user_id', $this->owner->id)->count(), 'No se inventan clientes.');
    }

    // ------------------------------------------------------------------------------------------
    // 5. Teléfonos
    // ------------------------------------------------------------------------------------------

    /**
     * Teléfonos repetidos entre clientes: se queda el primero, los demás pasan a ficción. Sin eso
     * el recordatorio al segundo cliente con el mismo número revienta contra el índice único
     * (user_id, phone) de `whatsapp_chats`.
     *
     * @group whatsapp
     * @test
     */
    public function los_telefonos_repetidos_quedan_unicos_y_el_primero_conserva_el_de_lucas()
    {
        $this->clientes_de_una_demo();

        $resumen = WhatsappDemoHelper::preparar($this->owner, ['chats' => false]);

        $this->assertSame(3, $resumen['telefonos_reemplazados'], 'Cuatro clientes con el número de Lucas: uno lo conserva y tres cambian.');

        $telefonos = Client::where('user_id', $this->owner->id)->whereNotNull('phone')->pluck('phone')->all();
        $this->assertCount(count(array_unique($telefonos)), $telefonos, 'Ningún teléfono repetido.');

        $primero = Client::where('user_id', $this->owner->id)->orderBy('id')->first();
        $this->assertSame(self::LUCAS, $primero->phone, 'El primer cliente conserva el número de Lucas.');

        // Los que ya eran de la siembra (3814500005…) no se tocan sin la opción.
        $this->assertSame('3814500005', Client::where('user_id', $this->owner->id)->where('name', 'Lucia Fernandez')->first()->phone);
    }

    /**
     * La medición distingue los números que dejan las siembras (`3814500005`…) de los posibles
     * reales: en una demo recién armada tiene que dar 0 reales, que es lo que se midió en
     * demo, demo2 y demo3 el 15/10/2026.
     *
     * @group whatsapp
     * @test
     */
    public function la_medicion_distingue_la_siembra_de_los_numeros_reales()
    {
        $this->clientes_de_una_demo();

        $medicion = WhatsappDemoHelper::preparar($this->owner, ['solo_medir' => true]);

        $this->assertSame(9, $medicion['clientes_con_telefono']);
        $this->assertSame(5, $medicion['telefonos_de_siembra']);
        $this->assertSame(0, $medicion['telefonos_reales'], 'Lucas y la siembra no cuentan como reales.');
        $this->assertSame(3, $medicion['telefonos_repetidos'], 'Cuatro clientes con el número de Lucas: tres repetidos.');
    }

    /**
     * Con la opción (demo3: la base de un cliente real), ningún teléfono real sobrevive.
     *
     * @group whatsapp
     * @test
     */
    public function con_telefonos_de_ficcion_no_queda_ningun_numero_real()
    {
        // Una base copiada de un cliente real: números reales (inventados acá), uno repetido y uno
        // formateado con símbolos.
        $this->cliente('Cliente Real 1', '+54 9 11 5555-1234', true);
        $this->cliente('Cliente Real 2', '3415551234', true);
        $this->cliente('Cliente Real 3', '3415551234');
        $this->cliente('Cliente Real 4', '(0341) 555-9876');
        $this->cliente('Lucas', self::LUCAS);

        $medicion = WhatsappDemoHelper::preparar($this->owner, ['solo_medir' => true]);

        $this->assertTrue($medicion['solo_medir']);
        $this->assertSame(5, $medicion['clientes_con_telefono']);
        $this->assertSame(4, $medicion['telefonos_reales']);
        $this->assertSame(0, $medicion['telefonos_de_siembra']);
        $this->assertSame(1, $medicion['telefonos_repetidos']);
        $this->assertNull($medicion['config'], 'Solo medir no escribe nada.');
        $this->assertNull(WhatsappBotConfig::where('user_id', $this->owner->id)->first());

        $resumen = WhatsappDemoHelper::preparar($this->owner, ['telefonos_de_ficcion' => true]);

        $this->assertSame(4, $resumen['telefonos_reemplazados']);
        $this->assertSame(0, WhatsappDemoHelper::preparar($this->owner, ['solo_medir' => true])['telefonos_reales'], 'Después de correrlo no queda ningún número real.');

        $telefonos = Client::where('user_id', $this->owner->id)->pluck('phone')->all();
        $this->assertCount(count(array_unique($telefonos)), $telefonos);

        foreach ($telefonos as $telefono) {
            $es_lucas = $telefono === self::LUCAS;
            $es_ficcion = strpos($telefono, WhatsappDemoHelper::PREFIJO_TELEFONO_DE_FICCION) === 0 && strlen($telefono) === 10;
            $this->assertTrue($es_lucas || $es_ficcion, 'Teléfono que no es de ficción: ' . $telefono);
        }
    }

    /**
     * Los chats «abiertos» se cierran a las 24 h y un recordatorio ya mandado frena el siguiente.
     * `renovar_chats` los borra (con lo que se mandó filmando) y los vuelve a sembrar con fechas de
     * «hace un rato», sin tocar chats que armó otra cosa.
     *
     * @group whatsapp
     * @test
     */
    public function renovar_los_chats_repite_la_toma_sin_tocar_los_que_no_son_de_ejemplo()
    {
        $this->clientes_de_una_demo();

        WhatsappDemoHelper::preparar($this->owner);

        $abierto = $this->chats()->first(function ($chat) {
            return $chat->is_within_service_window() && $chat->is_sin_responder();
        });
        $this->assertNotNull($abierto);

        // Pasó el tiempo: la ventana se cerró.
        WhatsappChat::where('id', $abierto->id)->update(['last_inbound_at' => now()->subDays(3)]);
        $this->assertFalse($abierto->fresh()->is_within_service_window());

        // Y se mandó un recordatorio filmando.
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $abierto->id,
            'direction'        => 'out',
            'source'           => RecordatorioCobroSenderService::SOURCE,
            'body'             => 'Recordatorio filmado',
            'wa_message_id'    => WhatsappBotSendService::PREFIJO_ID_SIMULADO . 'x',
            'delivery_status'  => 'entregado',
        ]);

        // Un chat que armó otra cosa (otro primer mensaje) no es de ejemplo.
        $ajeno = $this->cliente('Cliente Ajeno Al Ejemplo', '3425500099');
        $chat_ajeno = WhatsappChat::create([
            'user_id'         => $this->owner->id,
            'client_id'       => $ajeno->id,
            'phone'           => $ajeno->phone,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        WhatsappChatMessage::create(['whatsapp_chat_id' => $chat_ajeno->id, 'direction' => 'in', 'source' => 'cliente', 'body' => 'Un mensaje cualquiera']);

        $sin_renovar = WhatsappDemoHelper::preparar($this->owner);
        $this->assertSame(0, $sin_renovar['chats_sembrados'], 'Sin la opción no se vuelve a sembrar.');
        $this->assertFalse($abierto->fresh()->is_within_service_window());

        $resumen = WhatsappDemoHelper::preparar($this->owner, ['renovar_chats' => true]);

        $this->assertSame(5, $resumen['chats_eliminados']);
        $this->assertSame(5, $resumen['chats_sembrados']);
        $this->assertNotNull(WhatsappChat::find($chat_ajeno->id), 'El chat que no es de ejemplo no se toca.');
        $this->assertSame(6, WhatsappChat::where('user_id', $this->owner->id)->count());
        $this->assertSame(
            0,
            WhatsappChatMessage::whereIn('whatsapp_chat_id', $this->chats()->pluck('id'))->where('source', RecordatorioCobroSenderService::SOURCE)->count(),
            'El recordatorio que se mandó filmando desapareció: se puede repetir la toma.'
        );

        $abiertos = $this->chats()->filter(function ($chat) use ($chat_ajeno) {
            return $chat->id !== $chat_ajeno->id && $chat->is_within_service_window();
        });
        $this->assertGreaterThanOrEqual(4, $abiertos->count(), 'Cuatro de los cinco chats vuelven a tener la ventana abierta.');
    }

    /**
     * Una base copiada de un cliente real trae SUS conversaciones, con los teléfonos reales
     * grabados en `whatsapp_chats.phone` (y los envíos salen a ese teléfono). Con la opción de
     * ficción se miden primero y se borran después, con sus mensajes.
     *
     * @group whatsapp
     * @test
     */
    public function con_telefonos_de_ficcion_se_borran_los_chats_de_numeros_reales()
    {
        $real = $this->cliente('Cliente Real', '3415551234', true);

        $chat_real = WhatsappChat::create([
            'user_id'         => $this->owner->id,
            'client_id'       => $real->id,
            'phone'           => '5493415551234',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        WhatsappChatMessage::create(['whatsapp_chat_id' => $chat_real->id, 'direction' => 'in', 'source' => 'cliente', 'body' => 'Conversación real de otro negocio']);

        $medicion = WhatsappDemoHelper::preparar($this->owner, ['solo_medir' => true]);
        $this->assertSame(1, $medicion['chats_con_telefono_real']);
        $this->assertNotNull(WhatsappChat::find($chat_real->id), 'Medir no borra nada.');

        $sin_opcion = WhatsappDemoHelper::preparar($this->owner, ['chats' => false]);
        $this->assertSame(0, $sin_opcion['chats_eliminados']);
        $this->assertNotNull(WhatsappChat::find($chat_real->id), 'Sin la opción de ficción el chat queda como estaba.');

        $resumen = WhatsappDemoHelper::preparar($this->owner, ['telefonos_de_ficcion' => true, 'chats' => false]);

        $this->assertSame(1, $resumen['chats_eliminados']);
        $this->assertNull(WhatsappChat::find($chat_real->id));
        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat_real->id)->count());
    }

    // ------------------------------------------------------------------------------------------
    // 6. Seguridad
    // ------------------------------------------------------------------------------------------

    /**
     * Fuera de una instancia de demo se niega y no toca nada: pisa la clave de Kapso, aprueba
     * plantillas y reescribe teléfonos.
     *
     * @group whatsapp
     * @test
     */
    public function fuera_de_una_instancia_de_demo_se_niega_y_no_toca_nada()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => null]);

        $this->clientes_de_una_demo();

        try {
            WhatsappDemoHelper::preparar($this->owner);
            $this->fail('Tendría que haberse negado: la instalación no es una demo.');
        } catch (\RuntimeException $e) {
            $this->assertNotFalse(strpos($e->getMessage(), 'instancia de demo'));
        }

        $this->assertNull(WhatsappBotConfig::where('user_id', $this->owner->id)->first());
        $this->assertSame(0, WhatsappTemplate::where('user_id', $this->owner->id)->count());
        $this->assertSame(0, WhatsappChat::where('user_id', $this->owner->id)->count());
        $this->assertSame(4, Client::where('user_id', $this->owner->id)->where('phone', self::LUCAS)->count(), 'Los teléfonos no se tocaron.');
    }

    /**
     * El comando: sale con código 1 fuera de una demo, y con `--solo-medir` no escribe nada.
     *
     * @group whatsapp
     * @test
     */
    public function el_comando_se_niega_fuera_de_una_demo_y_solo_medir_no_escribe()
    {
        $this->clientes_de_una_demo();
        config(['app.USER_ID' => $this->owner->id]);

        // Solo medir en una demo: código 0 y nada escrito.
        $this->assertSame(0, Artisan::call('whatsapp:demo-simulado', ['--solo-medir' => true]));
        $this->assertNull(WhatsappBotConfig::where('user_id', $this->owner->id)->first());
        $this->assertNotFalse(strpos(Artisan::output(), 'telefonos_reales'));

        // Fuera de una demo: código 1 y nada escrito.
        config(['app.env' => 'production', 'app.FOR_USER' => null]);

        $this->assertSame(1, Artisan::call('whatsapp:demo-simulado'));
        $this->assertNull(WhatsappBotConfig::where('user_id', $this->owner->id)->first());
        $this->assertSame(0, WhatsappTemplate::where('user_id', $this->owner->id)->count());
    }

    /**
     * El comando de verdad, sobre una instancia de demo.
     *
     * @group whatsapp
     * @test
     */
    public function el_comando_deja_la_demo_en_modo_simulado()
    {
        $this->clientes_de_una_demo();
        config(['app.USER_ID' => $this->owner->id]);

        $this->assertSame(0, Artisan::call('whatsapp:demo-simulado'));

        $config = WhatsappBotConfig::where('user_id', $this->owner->id)->first();
        $this->assertNotNull($config);
        $this->assertTrue($config->esta_en_modo_demostracion());
        $this->assertCount(5, $this->chats());
    }

    /**
     * El demo setup llama al helper: `run()` lo invoca después de `semilla:datos` y el método
     * puente solo actúa en una demo y nunca frena el setup. `run()` entero tarda ~9 minutos
     * (migrate:fresh + un año de operaciones), así que acá se verifica el puente y el orden
     * en el código; la corrida real se midió contra una base aparte (ver el informe).
     *
     * @group whatsapp
     * @test
     */
    public function el_demo_setup_llama_al_helper_despues_de_la_siembra_y_sin_frenar_el_setup()
    {
        $codigo = file_get_contents(base_path('app/Http/Controllers/Helpers/DemoSetupHelper.php'));

        $posicion_semilla = strpos($codigo, "Artisan::call('semilla:datos'");
        $posicion_whatsapp = strpos($codigo, 'self::preparar_whatsapp_de_la_demo($user);');

        $this->assertNotFalse($posicion_semilla);
        $this->assertNotFalse($posicion_whatsapp);
        $this->assertGreaterThan($posicion_semilla, $posicion_whatsapp, 'Necesita los clientes y las ventas sin cobrar que siembra semilla:datos.');

        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'preparar_whatsapp_de_la_demo');
        $metodo->setAccessible(true);

        $this->clientes_de_una_demo();

        // En una demo: deja la config en modo simulado.
        $metodo->invoke(null, $this->owner);
        $this->assertTrue(WhatsappBotConfig::where('user_id', $this->owner->id)->first()->esta_en_modo_demostracion());

        // Fuera de una demo: no hace nada (la instalación de un cliente real no recibe esto).
        WhatsappBotConfig::where('user_id', $this->owner->id)->delete();
        config(['app.env' => 'production', 'app.FOR_USER' => null]);
        $metodo->invoke(null, $this->owner);
        $this->assertNull(WhatsappBotConfig::where('user_id', $this->owner->id)->first());
    }

    // ------------------------------------------------------------------------------------------
    // De punta a punta: la demo recién armada manda un recordatorio
    // ------------------------------------------------------------------------------------------

    /**
     * Lo que Lucas va a filmar: con la demo recién armada, el recordatorio a los deudores sale por
     * el masivo y queda enviado en el módulo — el que escribió hace un rato por texto libre, el de
     * la ventana cerrada por la plantilla con el PDF de la cuenta —, sin una request a Kapso.
     *
     * @group whatsapp
     * @test
     */
    public function con_la_demo_recien_armada_el_recordatorio_masivo_queda_enviado_en_los_dos_caminos()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->clientes_de_una_demo();
        WhatsappDemoHelper::preparar($this->owner);
        $this->entrar_como_dueno();

        $respuesta = $this->postJson('api/recordatorio-cobro/enviar-masivo', ['dias' => 0]);
        $respuesta->assertStatus(202);

        $this->assertGreaterThanOrEqual(2, $respuesta->json('encolados'), 'Salteados: ' . json_encode($respuesta->json('salteados')));

        Http::assertNothingSent();

        $enviados = WhatsappChatMessage::whereIn('whatsapp_chat_id', $this->chats()->pluck('id'))
            ->where('source', RecordatorioCobroSenderService::SOURCE)
            ->get();

        $this->assertGreaterThanOrEqual(2, $enviados->count());

        foreach ($enviados as $mensaje) {
            $this->assertTrue(WhatsappBotSendService::es_id_simulado($mensaje->wa_message_id));
            $this->assertSame('entregado', $mensaje->delivery_status);
        }

        // Uno salió por texto libre (el deudor con la ventana abierta) y otro por la plantilla.
        $this->assertTrue($enviados->contains(function ($m) {
            return is_null($m->template_meta_name);
        }), 'Falta el recordatorio por texto libre.');
        $this->assertTrue($enviados->contains(function ($m) {
            return $m->template_meta_name === RecordatorioCobroSenderService::TEMPLATE_META_NAME;
        }), 'Falta el recordatorio por plantilla.');
    }
}
