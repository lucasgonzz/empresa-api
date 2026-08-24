<?php

namespace Tests\Feature\Whatsapp;

use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión whatsapp-sidebar-multimedia — U14/10: los medios que manda el cliente.
 *
 * Lo que protege este archivo, en orden de importancia:
 *
 * 🔴 QUE UNA IMAGEN ABRA LA VENTANA DE 24 H AUNQUE EL ARCHIVO NO SE PUEDA BAJAR. Es el bug de
 * fondo de toda la misión y el modo de falla más caro: hasta acá una foto se descartaba en
 * silencio —el webhook devolvía 200, el log no decía nada— y el chat quedaba sin
 * `last_inbound_at`, así que el operador no le podía contestar por texto libre al cliente que
 * le acababa de mandar una foto. El caso que lo cubre es
 * `una_imagen_que_no_se_pudo_bajar_igual_abre_la_ventana_de_24_horas()`, y está escrito con la
 * descarga rota a propósito: si alguien vuelve a atar la persistencia del mensaje al éxito de
 * una descarga de un tercero, ese test es lo único que lo denuncia.
 *
 * - Que la imagen se registre CON y SIN epígrafe: eran dos guards distintos (el `in_array` de
 *   `parse_inbound_message()` y el corte por cuerpo vacío de `handle_message_received()`), y
 *   tocar uno solo deja pasar la foto con texto y sigue perdiendo la foto sola, que es el caso
 *   normal.
 * - Que el audio siga guardando la transcripción Y ahora también el archivo.
 * - Que un reintento del webhook no duplique el archivo en disco (nombre determinista).
 * - Que `video` siga sin persistir nada (D15: el alcance son audios e imágenes).
 * - Que la URL del adjunto se encuentre por las distintas rutas del payload de Kapso, que es lo
 *   único que hay a modo de documentación de ese payload.
 * - Que un mime fuera de la lista blanca no llegue nunca a escribirse en disco (D16).
 *
 * 🔴 UN SOLO `Http::fake()` POR MÉTODO. En Laravel 8 `Http::fake()` ACUMULA stubs en vez de
 * reemplazarlos, así que un segundo llamado adentro del mismo test queda ignorado en silencio y
 * gana el primero que matchee: el test pasa, pero probando la respuesta equivocada. Y el stub
 * `'*'` va SIEMPRE último: una request que no matchea ningún stub sale a la red de verdad.
 */
class Medios_entrantes_Test extends TestCase
{
    use DatabaseTransactions;

    /** Teléfono del cliente final usado en todos los casos. */
    const PHONE = '5493416010101';

    /** Secreto del webhook con el que se firman los payloads. */
    const SECRET = 'secreto-webhook-u14-10';

    /** URL desde la que el CDN de Kapso entrega el adjunto en la mayoría de los casos. */
    const MEDIA_URL = 'https://cdn-de-prueba.kapso.test/adjunto.bin';

    /**
     * Contenido del archivo que devuelve el CDN falso. Es un binario cualquiera a propósito:
     * lo único que se afirma sobre él es que llega entero al disco y que pesa más de cero.
     */
    const BINARIO = 'BINARIO-DE-PRUEBA-0123456789-0123456789-0123456789-0123456789-0123456789-0123456789-0123456789-0123456789-0123456789';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        // Los adjuntos van al disco `local`, nunca al `public` (D2). Se fakea el mismo disco que
        // usa el servicio, así ningún test deja archivos en el storage del worktree.
        Storage::fake('local');

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp U14-10',
            'company_name' => 'Ferreteria U14-10',
            'email'        => 'whatsapp-u14-10-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'         => $this->comercio->id,
            'kapso_api_key'   => 'kapso-u14-10',
            'phone_number_id' => '5491100000010',
            'webhook_secret'  => self::SECRET,
            'is_active'       => true,
            // El agente queda apagado en todos los casos de este archivo: acá se mide la
            // persistencia del entrante, no la respuesta. Con la IA prendida cada posteo
            // arrastraría además el scheduler y el job, que tienen su propio archivo.
            'ai_enabled_default' => false,
        ]);
    }

    /**
     * Postea al webhook público firmando el cuerpo con HMAC-SHA256, igual que Kapso.
     * La firma se calcula sobre el MISMO json_encode que usa postJson internamente.
     *
     * @param array $message Nodo `message` del payload.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_webhook(array $message)
    {
        $payload = ['message' => $message];
        $firma   = hash_hmac('sha256', json_encode($payload), self::SECRET);

        return $this->postJson('api/whatsapp-bot/webhook', $payload, [
            'X-Kapso-Signature' => $firma,
        ]);
    }

    /**
     * Nodo `message` de una imagen entrante que llega por la ruta habitual
     * (`message.kapso.media_url`).
     *
     * @param string $wa_message_id Id del mensaje en WhatsApp (semilla del nombre del archivo).
     * @param string $caption       Epígrafe; vacío = imagen sin texto.
     * @param string $mime          Mime declarado en el nodo de la imagen.
     * @return array
     */
    protected function imagen($wa_message_id, $caption = '', $mime = 'image/jpeg')
    {
        $nodo = [
            'id'        => 'media-' . $wa_message_id,
            'mime_type' => $mime,
        ];

        if ($caption !== '') {
            $nodo['caption'] = $caption;
        }

        return [
            'from'  => self::PHONE,
            'id'    => $wa_message_id,
            'type'  => 'image',
            'image' => $nodo,
            'kapso' => ['media_url' => self::MEDIA_URL],
        ];
    }

    /**
     * Nodo `message` de una nota de voz entrante.
     *
     * @param string $wa_message_id
     * @param string $transcript Texto que ya transcribió Kapso; vacío = sin transcripción.
     * @return array
     */
    protected function audio($wa_message_id, $transcript = '')
    {
        $kapso = ['media_url' => self::MEDIA_URL];

        if ($transcript !== '') {
            $kapso['transcript'] = ['text' => $transcript];
        }

        return [
            'from'  => self::PHONE,
            'id'    => $wa_message_id,
            'type'  => 'audio',
            'audio' => [
                'id'        => 'media-' . $wa_message_id,
                'mime_type' => 'audio/ogg',
            ],
            'kapso' => $kapso,
        ];
    }

    /**
     * @return WhatsappChat|null
     */
    protected function chat()
    {
        return WhatsappChat::where('user_id', $this->comercio->id)
            ->where('phone', self::PHONE)
            ->first();
    }

    /**
     * Mensajes del chat del cliente, en orden de llegada.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function mensajes()
    {
        $chat = $this->chat();

        if (is_null($chat)) {
            return WhatsappChatMessage::whereRaw('1 = 0')->get();
        }

        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_imagen_con_epigrafe_guarda_el_texto_y_el_archivo()
    {
        Http::fake([
            'cdn-de-prueba.kapso.test/*' => Http::response(self::BINARIO, 200),
            '*'                          => Http::response([], 200),
        ]);

        $this->postear_webhook($this->imagen('wamid.CON-EPIGRAFE', '¿Tenés uno igual a este?'))
            ->assertStatus(200);

        $mensajes = $this->mensajes();
        $this->assertCount(1, $mensajes, 'La imagen con epígrafe tiene que dejar una fila en la conversación.');

        $mensaje = $mensajes[0];
        $this->assertEquals('¿Tenés uno igual a este?', $mensaje->body, 'El epígrafe es el cuerpo del mensaje.');
        $this->assertEquals('image', $mensaje->media_type);
        $this->assertNotNull($mensaje->media_path, 'La descarga anduvo, así que tiene que haber copia local.');
        $this->assertEquals('image/jpeg', $mensaje->media_mime);
        $this->assertEquals(strlen(self::BINARIO), (int) $mensaje->media_size);
        $this->assertGreaterThan(0, (int) $mensaje->media_size);

        Storage::disk('local')->assertExists($mensaje->media_path);
        $this->assertEquals(
            self::BINARIO,
            Storage::disk('local')->get($mensaje->media_path),
            'El archivo en disco tiene que ser exactamente lo que devolvió el CDN.'
        );
    }

    /**
     * El caso que moría en el guard de cuerpo vacío de `handle_message_received()`: una foto
     * sola, sin una palabra escrita, es lo que manda un cliente el 90% de las veces.
     *
     * @group whatsapp
     * @test
     */
    public function una_imagen_sin_epigrafe_igual_queda_registrada_en_la_conversacion()
    {
        Http::fake([
            'cdn-de-prueba.kapso.test/*' => Http::response(self::BINARIO, 200),
            '*'                          => Http::response([], 200),
        ]);

        $this->postear_webhook($this->imagen('wamid.SIN-EPIGRAFE'))->assertStatus(200);

        $mensajes = $this->mensajes();
        $this->assertCount(1, $mensajes, 'Una imagen sin texto NO se puede descartar: es el caso normal.');
        $this->assertEquals('[Imagen recibida]', $mensajes[0]->body);
        $this->assertEquals('image', $mensajes[0]->media_type);
        $this->assertNotNull($mensajes[0]->media_path);
    }

    /**
     * `last_inbound_at` es el único campo que abre la ventana de 24 h de Meta. Sin esto el
     * operador ve la foto en la conversación pero el composer le dice "fuera de ventana".
     *
     * @group whatsapp
     * @test
     */
    public function una_imagen_abre_la_ventana_de_24_horas_para_poder_contestarle_al_cliente()
    {
        Http::fake([
            'cdn-de-prueba.kapso.test/*' => Http::response(self::BINARIO, 200),
            '*'                          => Http::response([], 200),
        ]);

        $this->postear_webhook($this->imagen('wamid.VENTANA'))->assertStatus(200);

        $chat = $this->chat();
        $this->assertNotNull($chat, 'La imagen tiene que haber creado el chat.');
        $this->assertNotNull($chat->last_inbound_at, 'Una imagen abre la ventana de 24 h igual que un texto.');
        $this->assertTrue(
            $chat->is_within_service_window(),
            'Con la ventana abierta el operador puede contestarle con texto libre.'
        );
    }

    /**
     * @group whatsapp
     * @test
     */
    public function una_nota_de_voz_guarda_la_transcripcion_y_ademas_el_archivo()
    {
        Http::fake([
            'cdn-de-prueba.kapso.test/*' => Http::response(self::BINARIO, 200),
            '*'                          => Http::response([], 200),
        ]);

        $this->postear_webhook($this->audio('wamid.AUDIO-CON-TEXTO', 'Hola, quería saber el precio del taladro'))
            ->assertStatus(200);

        $mensajes = $this->mensajes();
        $this->assertCount(1, $mensajes);

        $mensaje = $mensajes[0];
        $this->assertEquals('Hola, quería saber el precio del taladro', $mensaje->body, 'La transcripción sigue siendo el cuerpo.');
        $this->assertEquals('audio', $mensaje->media_type);
        $this->assertEquals('audio/ogg', $mensaje->media_mime);
        $this->assertNotNull($mensaje->media_path, 'Ahora además del texto queda el audio para escuchar.');

        Storage::disk('local')->assertExists($mensaje->media_path);
    }

    /**
     * 🔴 El literal '[Audio sin transcripción]' SÍ se muestra en la burbuja (informa que no se
     * pudo transcribir), a diferencia de los literales de relleno de imagen.
     *
     * @group whatsapp
     * @test
     */
    public function una_nota_de_voz_sin_transcripcion_igual_deja_el_archivo_para_escuchar()
    {
        Http::fake([
            'cdn-de-prueba.kapso.test/*' => Http::response(self::BINARIO, 200),
            '*'                          => Http::response([], 200),
        ]);

        $this->postear_webhook($this->audio('wamid.AUDIO-SIN-TEXTO'))->assertStatus(200);

        $mensajes = $this->mensajes();
        $this->assertCount(1, $mensajes);
        $this->assertEquals('[Audio sin transcripción]', $mensajes[0]->body);
        $this->assertEquals('audio', $mensajes[0]->media_type);
        $this->assertNotNull($mensajes[0]->media_path);

        Storage::disk('local')->assertExists($mensajes[0]->media_path);
    }

    /**
     * 🔴 EL CASO CRÍTICO DE TODA LA MISIÓN (caso 10.6 del plan).
     *
     * El CDN de Kapso se cae, o la URL firmada venció, o el proxy de Meta contesta 500: los
     * TRES caminos de descarga fallan. Lo que NO puede pasar es que se pierda el mensaje.
     *
     * La fila tiene que existir igual, con `media_type` cargado (para que la conversación diga
     * "acá llegó una foto") y `media_path` en null, y sobre todo `last_inbound_at` escrito: la
     * ventana de 24 h no puede depender de que ande el servidor de archivos de un tercero. El
     * orden que lo garantiza está en `store_inbound_message()` — el `save()` del chat va ANTES
     * de salir a la red — y este test es lo único que lo denuncia si alguien lo da vuelta,
     * porque el modo de falla no tira ningún error: el webhook devuelve 200 y todo parece bien.
     *
     * @group whatsapp
     * @test
     */
    public function una_imagen_que_no_se_pudo_bajar_igual_abre_la_ventana_de_24_horas()
    {
        Http::fake([
            // El CDN devuelve 500 en los dos intentos (con api key y sin ella).
            'cdn-de-prueba.kapso.test/*' => Http::response('', 500),
            // Y el tercer camino, el proxy de Meta por media_id, también.
            'api.kapso.ai/*'             => Http::response('', 500),
            '*'                          => Http::response([], 200),
        ]);

        // El webhook siempre le contesta 200 a Kapso: un adjunto que no bajó no es un error
        // del webhook, y un 5xx haría que Kapso reintentara el mensaje entero.
        $this->postear_webhook($this->imagen('wamid.SIN-ARCHIVO'))->assertStatus(200);

        $mensajes = $this->mensajes();
        $this->assertCount(1, $mensajes, 'Que no se pueda bajar el archivo NO cancela el mensaje.');

        $mensaje = $mensajes[0];
        $this->assertEquals('[Imagen recibida]', $mensaje->body);
        $this->assertEquals('image', $mensaje->media_type, 'La conversación tiene que poder decir que llegó una foto.');
        $this->assertNull($mensaje->media_path, 'Sin descarga no hay copia local, y eso está bien.');
        $this->assertNull($mensaje->media_mime);
        $this->assertNull($mensaje->media_size);

        $chat = $this->chat();
        $this->assertNotNull($chat);
        $this->assertNotNull(
            $chat->last_inbound_at,
            'ESTE ES EL BUG DE FONDO: la ventana de 24 h se abre igual, aunque el archivo no se haya podido bajar.'
        );
        $this->assertTrue($chat->is_within_service_window());
    }

    /**
     * Kapso reintenta el webhook cuando no le llega el 200 a tiempo, y la descarga corre
     * sincrónicamente adentro del request: el reintento es un escenario real, no teórico. Con
     * el nombre determinista (md5 del id del mensaje) el segundo posteo sobreescribe el mismo
     * archivo en vez de dejar una copia más en disco.
     *
     * @group whatsapp
     * @test
     */
    public function un_reintento_del_webhook_no_deja_dos_copias_del_mismo_archivo()
    {
        Http::fake([
            'cdn-de-prueba.kapso.test/*' => Http::response(self::BINARIO, 200),
            '*'                          => Http::response([], 200),
        ]);

        $mensaje_identico = $this->imagen('wamid.REINTENTO', 'la misma foto');

        $this->postear_webhook($mensaje_identico)->assertStatus(200);
        $this->postear_webhook($mensaje_identico)->assertStatus(200);

        $chat = $this->chat();
        $this->assertNotNull($chat);

        $archivos = Storage::disk('local')->files('whatsapp/' . $chat->id);
        $this->assertCount(1, $archivos, 'El nombre determinista hace que el reintento sobreescriba, no que duplique.');

        $mensajes = $this->mensajes();
        $this->assertCount(2, $mensajes, 'Las filas sí se duplican: deduplicar mensajes no es alcance de esta misión.');
        $this->assertEquals(
            $mensajes[0]->media_path,
            $mensajes[1]->media_path,
            'Las dos filas apuntan al mismo archivo.'
        );
    }

    /**
     * D15: los tipos soportados son `text`, `audio`/`ptt`/`voice` e `image`. `video`,
     * `document` y `sticker` siguen devolviendo null, porque cada tipo nuevo necesita además
     * una forma de dibujarse en la burbuja.
     *
     * @group whatsapp
     * @test
     */
    public function un_video_sigue_sin_persistirse_porque_esta_fuera_del_alcance()
    {
        Http::fake([
            'cdn-de-prueba.kapso.test/*' => Http::response(self::BINARIO, 200),
            '*'                          => Http::response([], 200),
        ]);

        $this->postear_webhook([
            'from'  => self::PHONE,
            'id'    => 'wamid.VIDEO',
            'type'  => 'video',
            'video' => ['id' => 'media-video', 'mime_type' => 'video/mp4'],
            'kapso' => ['media_url' => self::MEDIA_URL],
        ])->assertStatus(200);

        $this->assertNull($this->chat(), 'Un tipo no soportado no crea ni el chat.');
        $this->assertCount(0, $this->mensajes());
        // Ni siquiera se intenta bajar el archivo de un tipo que no se soporta.
        Http::assertNothingSent();
    }

    /**
     * No hay documentación oficial del payload de medios de Kapso: las rutas de
     * `resolve_media_url()` salen de leer `admin-api`, que es la única implementación que
     * funciona en producción. Este test fija las tres formas verificables en las que la URL
     * puede venir, para que nadie borre una "porque no se usa".
     *
     * @group whatsapp
     * @test
     */
    public function la_url_del_adjunto_se_encuentra_por_las_distintas_rutas_del_payload()
    {
        Http::fake([
            'ruta-kapso-media-url.test/*' => Http::response(self::BINARIO, 200),
            'ruta-image-link.test/*'      => Http::response(self::BINARIO, 200),
            'ruta-kapso-content.test/*'   => Http::response(self::BINARIO, 200),
            '*'                           => Http::response([], 200),
        ]);

        // Ruta 1: `message.kapso.media_url` (snake_case, la que Kapso usa realmente hoy).
        $this->postear_webhook([
            'from'  => self::PHONE,
            'id'    => 'wamid.RUTA-1',
            'type'  => 'image',
            'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg'],
            'kapso' => ['media_url' => 'https://ruta-kapso-media-url.test/uno.jpg'],
        ])->assertStatus(200);

        // Ruta 3: `message.image.link`, el campo estándar de Meta. Sin nodo `kapso`.
        $this->postear_webhook([
            'from'  => self::PHONE,
            'id'    => 'wamid.RUTA-3',
            'type'  => 'image',
            'image' => [
                'id'        => 'media-3',
                'mime_type' => 'image/png',
                'link'      => 'https://ruta-image-link.test/tres.png',
            ],
        ])->assertStatus(200);

        // Ruta 4: el formato legado, donde el adjunto viajaba embebido como texto en
        // `kapso.content`. Acá el mime también sale de ahí, porque el nodo no lo declara.
        $this->postear_webhook([
            'from'  => self::PHONE,
            'id'    => 'wamid.RUTA-4',
            'type'  => 'image',
            'image' => ['id' => 'media-4'],
            'kapso' => [
                'content' => '[Image attached (cuatro.jpg)] [Size: 12 KB | Type: image/jpeg] URL: https://ruta-kapso-content.test/cuatro.jpg',
            ],
        ])->assertStatus(200);

        $mensajes = $this->mensajes();
        $this->assertCount(3, $mensajes);

        foreach ($mensajes as $mensaje) {
            $this->assertNotNull(
                $mensaje->media_path,
                'Las tres rutas del payload tienen que terminar en un archivo bajado.'
            );
            Storage::disk('local')->assertExists($mensaje->media_path);
        }

        $this->assertEquals('image/jpeg', $mensajes[0]->media_mime);
        $this->assertEquals('image/png', $mensajes[1]->media_mime);
        $this->assertEquals('image/jpeg', $mensajes[2]->media_mime, 'Sin mime en el nodo, sale del "Type:" del texto legado.');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://ruta-kapso-media-url.test/uno.jpg';
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://ruta-image-link.test/tres.png';
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://ruta-kapso-content.test/cuatro.jpg';
        });
    }

    /**
     * D16: la extensión y el `Content-Type` con el que después PHP sirve el archivo salen SOLO
     * de la lista blanca de mimes. Un mime que no está en ella no se guarda: el archivo no
     * existe, así que no hay forma de que algo elegido por quien mandó el mensaje termine
     * escrito en disco con una extensión ejecutable.
     *
     * @group whatsapp
     * @test
     */
    public function un_mime_fuera_de_la_lista_blanca_deja_la_fila_pero_no_escribe_el_archivo()
    {
        Http::fake([
            'cdn-de-prueba.kapso.test/*' => Http::response(self::BINARIO, 200),
            '*'                          => Http::response([], 200),
        ]);

        $this->postear_webhook($this->imagen('wamid.MIME-RARO', '', 'application/x-php'))
            ->assertStatus(200);

        $mensajes = $this->mensajes();
        $this->assertCount(1, $mensajes, 'El mensaje se registra igual: lo que se descarta es el archivo.');
        $this->assertEquals('image', $mensajes[0]->media_type);
        $this->assertNull($mensajes[0]->media_path, 'Un mime fuera de la lista blanca NO se guarda en disco.');

        $chat = $this->chat();
        $this->assertCount(
            0,
            Storage::disk('local')->files('whatsapp/' . $chat->id),
            'No puede quedar ni un archivo escrito para un mime que no está permitido.'
        );
    }
}
