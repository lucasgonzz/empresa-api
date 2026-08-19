<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión whatsapp-sidebar-multimedia — U14/11: el operador manda fotos y audios.
 *
 * Lo que protege este archivo:
 *
 * 🔴 QUE UN ENVÍO QUE NO SALIÓ NO SE DIBUJE COMO SI ESTUVIERA EN CAMINO. Es el caso
 * `un_upload_rechazado_deja_la_fila_fallida_y_no_borra_el_archivo()` y es el modo de falla que
 * ya costó un hallazgo en la misión anterior ("un envío fallido se marcaba como enviado"): el
 * endpoint devuelve 201 en los dos casos, así que un test que solo mire el status pasa igual
 * con el bug adentro. Lo que hay que mirar es la fila (`delivery_status = 'fallido'` +
 * `send_error`, escritos ANTES del `create()` para que el broadcast ya salga con la verdad) y
 * que el archivo local NO se borre, porque el operador lo va a querer reintentar.
 *
 * - Que la imagen viaje en DOS requests y en ese orden: primero `/media` (multipart, que es lo
 *   único que le da a Meta el mime del archivo) y después `/messages` con el `media_id`.
 * - Que `audio.voice = true` viaje solo con ogg: es lo que hace que llegue como nota de voz en
 *   vez de como archivo adjunto, y la falla no se ve en ningún log ni en ningún status.
 * - Que fuera de la ventana de 24 h no salga NADA a Kapso.
 * - Que los topes de tamaño y la lista blanca de mimes corten del lado del servidor.
 * - Que un chat en simulación no suba archivos de verdad (el freno de D18).
 * - Que mandar un archivo descarte la respuesta que el agente dejó esperando aprobación.
 *
 * 🔴 UN SOLO `Http::fake()` POR MÉTODO: en Laravel 8 los stubs se ACUMULAN y gana el primero
 * que matchee, así que un segundo llamado en el mismo test da falsos verdes. El `'*'` va
 * siempre último, o la request sale a la red de verdad.
 *
 * ⚠️ `UploadedFile::fake()->create()` deja el archivo VACÍO (solo miente el tamaño): sirve para
 * los casos que rebotan por tamaño o por mime, pero para todo lo que tiene que llegar a la red
 * hay que usar `createWithContent()`, porque `upload_media()` corta antes de salir si el
 * archivo no tiene bytes.
 */
class Envio_de_medios_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Teléfono del cliente final. */
    const PHONE = '5493416011111';

    /** Contenido con el que se arman los archivos que sí tienen que viajar. */
    const BYTES = 'OggS-FALSO-0123456789-0123456789-0123456789-0123456789-0123456789-0123456789';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        Storage::fake('local');

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp U14-11',
            'company_name' => 'Ferreteria U14-11',
            'email'        => 'whatsapp-u14-11-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp U14-11',
            'email'    => 'whatsapp-u14-11-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-u14-11',
            'phone_number_id'    => '5491100000011',
            'webhook_secret'     => 'secreto-u14-11',
            'is_active'          => true,
            'ai_enabled_default' => false,
        ]);

        $this->chat = $this->chat_con_ventana_abierta($this->comercio->id, self::PHONE);

        $this->dar_extension($this->comercio);
        $this->actingAs($this->empleado, 'web');
    }

    /**
     * Asigna la extensión al comercio (creando la fila del catálogo si la base del slot todavía
     * no la tiene sembrada). El middleware resuelve al owner, así que con dársela al dueño
     * alcanza también para sus empleados.
     *
     * @param User $comercio
     * @return void
     */
    protected function dar_extension(User $comercio)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp',
            ]);
        }

        $comercio->extencions()->attach($extencion->id);
        $comercio->load('extencions');
    }

    /**
     * Chat con la ventana de 24 h abierta: es la precondición de cualquier envío suelto.
     *
     * @param int    $user_id
     * @param string $phone
     * @return WhatsappChat
     */
    protected function chat_con_ventana_abierta($user_id, $phone)
    {
        return WhatsappChat::create([
            'user_id'                => $user_id,
            'phone'                  => $phone,
            'ai_enabled'             => false,
            'unread_count'           => 0,
            'last_message_at'        => now(),
            'last_inbound_at'        => now(),
            'last_inbound_simulated' => 0,
        ]);
    }

    /**
     * Postea el archivo al endpoint del operador.
     *
     * @param UploadedFile $file
     * @param string       $caption
     * @param int|null     $chat_id Por default, el chat del comercio.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function mandar(UploadedFile $file, $caption = '', $chat_id = null)
    {
        $id = is_null($chat_id) ? $this->chat->id : $chat_id;

        return $this->post('api/whatsapp-chats/' . $id . '/media', [
            'file'    => $file,
            'caption' => $caption,
        ]);
    }

    /**
     * Imagen con contenido real (la genera GD), así el upload tiene bytes para mandar.
     *
     * @return UploadedFile
     */
    protected function imagen_real()
    {
        return UploadedFile::fake()->image('foto.jpg');
    }

    /**
     * Salientes del chat, en orden de creación.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function salientes()
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $this->chat->id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->get();
    }

    /**
     * @group whatsapp
     * @test
     */
    public function mandar_una_foto_la_sube_primero_y_recien_despues_la_envia()
    {
        Http::fake([
            // El stub más específico va PRIMERO: gana el primero que matchea.
            'api.kapso.ai/*/media' => Http::response(['id' => 'MEDIA-ID-DE-PRUEBA'], 200),
            'api.kapso.ai/*'       => Http::response(['messages' => [['id' => 'wamid.FOTO']]], 200),
            '*'                    => Http::response([], 200),
        ]);

        $response = $this->mandar($this->imagen_real(), 'mirá esto');

        $response->assertStatus(201);
        $this->assertTrue($response->json('enviado'), 'La foto salió: `enviado` tiene que venir en true.');

        Http::assertSentCount(2);

        // 1) La subida, por multipart. Sin multipart Guzzle no puede poner el boundary y Meta
        //    nunca recibe el archivo: es el motivo del tercer parámetro de KapsoHttpClient.
        Http::assertSent(function ($request) {
            return substr($request->url(), -6) === '/media' && $request->isMultipart();
        });

        // 2) El envío, referenciando el media_id que devolvió la subida. Sin `filename`, que
        //    Meta acepta en document pero NO en image.
        Http::assertSent(function ($request) {
            if (substr($request->url(), -9) !== '/messages') {
                return false;
            }

            return $request['type'] === 'image'
                && isset($request['image']['id'])
                && $request['image']['id'] === 'MEDIA-ID-DE-PRUEBA'
                && $request['image']['caption'] === 'mirá esto'
                && ! isset($request['image']['filename']);
        });

        $salientes = $this->salientes();
        $this->assertCount(1, $salientes);

        $mensaje = $salientes[0];
        $this->assertEquals('mirá esto', $mensaje->body, 'El epígrafe queda como cuerpo del mensaje.');
        $this->assertEquals('image', $mensaje->media_type);
        $this->assertEquals('image/jpeg', $mensaje->media_mime);
        $this->assertNotNull($mensaje->media_path);
        $this->assertGreaterThan(0, (int) $mensaje->media_size);
        $this->assertEquals('wamid.FOTO', $mensaje->wa_message_id);
        $this->assertEquals('pendiente', $mensaje->delivery_status);
        $this->assertNull($mensaje->send_error);

        Storage::disk('local')->assertExists($mensaje->media_path);
    }

    /**
     * 🔴 `voice: true` es lo único que hace que el audio llegue como nota de voz (con la ondita)
     * y no como un archivo adjunto con ícono de clip. Sin la bandera el mensaje se manda igual
     * y devuelve 200, así que ningún log ni ningún status lo delata: solo se ve mirando cómo le
     * apareció al cliente. Meta la acepta únicamente para ogg/opus.
     *
     * @group whatsapp
     * @test
     */
    public function una_nota_de_voz_en_ogg_viaja_marcada_como_nota_de_voz()
    {
        Http::fake([
            'api.kapso.ai/*/media' => Http::response(['id' => 'MEDIA-AUDIO-OGG'], 200),
            'api.kapso.ai/*'       => Http::response(['messages' => [['id' => 'wamid.OGG']]], 200),
            '*'                    => Http::response([], 200),
        ]);

        $archivo = UploadedFile::fake()
            ->createWithContent('nota.ogg', self::BYTES)
            ->mimeType('audio/ogg');

        $response = $this->mandar($archivo);

        $response->assertStatus(201);
        $this->assertTrue($response->json('enviado'));

        Http::assertSent(function ($request) {
            if (substr($request->url(), -9) !== '/messages') {
                return false;
            }

            return $request['type'] === 'audio'
                && $request['audio']['id'] === 'MEDIA-AUDIO-OGG'
                && isset($request['audio']['voice'])
                && $request['audio']['voice'] === true;
        });

        $mensaje = $this->salientes()[0];
        $this->assertEquals('audio', $mensaje->media_type);
        $this->assertEquals('audio/ogg', $mensaje->media_mime);
        // El audio no lleva epígrafe: Meta no acepta `caption` en `audio`, así que el cuerpo es
        // el literal de relleno que la burbuja esconde cuando dibuja el reproductor.
        $this->assertEquals('[Audio enviado]', $mensaje->body);
    }

    /**
     * La otra mitad de la regla: con cualquier contenedor que no sea ogg, mandar `voice` hace
     * que Meta rechace el mensaje entero.
     *
     * @group whatsapp
     * @test
     */
    public function un_audio_que_no_es_ogg_viaja_sin_la_marca_de_nota_de_voz()
    {
        Http::fake([
            'api.kapso.ai/*/media' => Http::response(['id' => 'MEDIA-AUDIO-MP3'], 200),
            'api.kapso.ai/*'       => Http::response(['messages' => [['id' => 'wamid.MP3']]], 200),
            '*'                    => Http::response([], 200),
        ]);

        $archivo = UploadedFile::fake()
            ->createWithContent('grabacion.mp3', self::BYTES)
            ->mimeType('audio/mpeg');

        $this->mandar($archivo)->assertStatus(201);

        Http::assertSent(function ($request) {
            if (substr($request->url(), -9) !== '/messages') {
                return false;
            }

            return $request['type'] === 'audio'
                && $request['audio']['id'] === 'MEDIA-AUDIO-MP3'
                && ! isset($request['audio']['voice']);
        });

        $this->assertEquals('audio/mpeg', $this->salientes()[0]->media_mime);
    }

    /**
     * 🔴 CASO CRÍTICO (11.4 del plan). Meta rechaza la subida y no hay `media_id`, así que no
     * hay nada para enviar.
     *
     * Las tres cosas que se miden acá, y ninguna se ve en el status:
     *  - la respuesta es 201 con `enviado: false` (la fila existe y tiene que aparecer en la
     *    conversación; un 4xx la dejaría guardada e invisible);
     *  - la fila NACE fallada, con `delivery_status` y `send_error` puestos antes del `create()`,
     *    para que el broadcast que dibuja la burbuja ya lleve la verdad. Si eso se guardara en un
     *    `save()` posterior, entre las dos escrituras el operador vería el mensaje como pendiente;
     *  - 🔴 el archivo local NO se borra: es lo que le permite al operador reintentar, y borrarlo
     *    sería perder algo que el usuario ya subió.
     *
     * @group whatsapp
     * @test
     */
    public function un_upload_rechazado_deja_la_fila_fallida_y_no_borra_el_archivo()
    {
        Http::fake([
            'api.kapso.ai/*/media' => Http::response(['error' => ['message' => 'archivo rechazado']], 400),
            'api.kapso.ai/*'       => Http::response(['messages' => [['id' => 'wamid.QUE-NUNCA-SALE']]], 200),
            '*'                    => Http::response([], 200),
        ]);

        $response = $this->mandar($this->imagen_real(), 'con epígrafe');

        $response->assertStatus(201);
        $this->assertFalse($response->json('enviado'), 'Si Meta rechazó el archivo, `enviado` tiene que venir en false.');

        // Sin media_id no hay segundo viaje: nunca se llegó a `/messages`.
        Http::assertNotSent(function ($request) {
            return substr($request->url(), -9) === '/messages';
        });

        $salientes = $this->salientes();
        $this->assertCount(1, $salientes, 'El mensaje se guarda igual: lo que falló fue el envío, no la carga.');

        $mensaje = $salientes[0];
        $this->assertEquals('fallido', $mensaje->delivery_status, 'Un envío que no salió NO se puede ver como pendiente.');
        $this->assertNotEmpty($mensaje->send_error, 'Tiene que quedar escrito por qué no salió.');
        $this->assertNull($mensaje->wa_message_id);
        $this->assertEquals('image', $mensaje->media_type);
        $this->assertNotNull($mensaje->media_path);

        Storage::disk('local')->assertExists(
            $mensaje->media_path
        );
    }

    /**
     * Fuera de la ventana de 24 h de Meta solo se pueden mandar plantillas. El corte va antes
     * de tocar el disco y antes de cualquier request: si saliera igual, Meta lo rechazaría de su
     * lado y cada intento le baja la calificación al número de WhatsApp Business del negocio.
     *
     * @group whatsapp
     * @test
     */
    public function fuera_de_la_ventana_de_24_horas_no_se_manda_nada_a_kapso()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->chat->last_inbound_at = now()->subHours(25);
        $this->chat->save();

        $response = $this->mandar($this->imagen_real());

        $response->assertStatus(422);
        $this->assertEquals('fuera_de_ventana', $response->json('code'), 'El front usa este code para ofrecer plantillas.');

        Http::assertNothingSent();
        $this->assertCount(0, $this->salientes(), 'Una operación que devuelve error no puede dejar efectos.');
        $this->assertCount(0, Storage::disk('local')->files('whatsapp/' . $this->chat->id));
    }

    /**
     * Los topes están duplicados a propósito con los del composer: el front evita el viaje
     * inútil, pero el back es el que manda porque el front se puede saltear.
     *
     * @group whatsapp
     * @test
     */
    public function el_servidor_rechaza_los_archivos_demasiado_grandes_y_los_tipos_no_permitidos()
    {
        Http::fake(['*' => Http::response([], 200)]);

        // Imagen de 6 MB: el tope de la Cloud API son 5 MB.
        $this->mandar(UploadedFile::fake()->create('grande.jpg', 6144, 'image/jpeg'))->assertStatus(422);

        // Audio de 20 MB: el tope son 16 MB.
        $this->mandar(UploadedFile::fake()->create('larga.ogg', 20480, 'audio/ogg'))->assertStatus(422);

        // Un PDF no está en la lista blanca: por acá van fotos y audios, nada más.
        $this->mandar(UploadedFile::fake()->create('presupuesto.pdf', 10, 'application/pdf'))->assertStatus(422);

        Http::assertNothingSent();
        $this->assertCount(0, $this->salientes());
    }

    /**
     * Patrón multi-empleado del proyecto: los empleados operan sobre los datos del dueño, y un
     * chat de otra empresa no existe para ellos. 404 y no 403, para no confirmar que existe.
     *
     * @group whatsapp
     * @test
     */
    public function no_se_puede_mandar_un_archivo_al_chat_de_otra_empresa()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $otro_comercio = User::create([
            'name'         => 'Otro comercio U14-11',
            'company_name' => 'Otra ferreteria U14-11',
            'email'        => 'whatsapp-u14-11-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $chat_ajeno = $this->chat_con_ventana_abierta($otro_comercio->id, '5493416099999');

        $this->mandar($this->imagen_real(), '', $chat_ajeno->id)->assertStatus(404);

        Http::assertNothingSent();
        $this->assertCount(
            0,
            WhatsappChatMessage::where('whatsapp_chat_id', $chat_ajeno->id)->get(),
            'No se puede escribir ni una fila en la conversación de otra empresa.'
        );
    }

    /**
     * D18 y H2: la simulación falsea la ventana de 24 h (escribe `last_inbound_at` desde un
     * número que nunca escribió), así que el freno tiene que estar ANTES de `upload_media()` —
     * ese método no recibe el teléfono y no tiene con qué resolver el chat. Sin el corte acá,
     * un chat simulado le subiría archivos de verdad a Kapso.
     *
     * Y la fila NO queda 'fallido': acá no falló nada, el envío se frenó a propósito.
     *
     * @group whatsapp
     * @test
     */
    public function un_chat_en_simulacion_guarda_el_archivo_pero_no_sale_a_kapso()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->chat->last_inbound_simulated = 1;
        $this->chat->save();

        $response = $this->mandar($this->imagen_real(), 'foto simulada');

        $response->assertStatus(201);
        $this->assertFalse($response->json('enviado'), 'En simulación nunca sale nada: `enviado` en false.');

        Http::assertNothingSent();

        $salientes = $this->salientes();
        $this->assertCount(1, $salientes, 'La fila se guarda igual, que es todo el punto de simular.');

        $mensaje = $salientes[0];
        $this->assertEquals('image', $mensaje->media_type);
        $this->assertNotNull($mensaje->media_path);
        $this->assertEquals(
            'pendiente',
            $mensaje->delivery_status,
            'La simulación no es un fallo: marcarla "fallido" le mostraría al operador un error que es mentira.'
        );
        $this->assertNull($mensaje->send_error);
        $this->assertEquals(1, (int) $mensaje->is_simulated);
    }

    /**
     * Mandar un archivo es una intervención humana: la respuesta que el agente dejó esperando
     * confirmación ya no sirve, porque el operador tomó la conversación. Si quedara, el cliente
     * recibiría dos cosas descoordinadas. Mismo criterio que el envío de texto.
     *
     * @group whatsapp
     * @test
     */
    public function mandar_un_archivo_descarta_la_respuesta_que_el_agente_dejo_esperando()
    {
        Http::fake([
            'api.kapso.ai/*/media' => Http::response(['id' => 'MEDIA-ID-CON-PENDIENTE'], 200),
            'api.kapso.ai/*'       => Http::response(['messages' => [['id' => 'wamid.CON-PENDIENTE']]], 200),
            '*'                    => Http::response([], 200),
        ]);

        $pendiente = WhatsappChatHelper::store_pending_ai_message($this->chat, 'Respuesta que quedó esperando aprobación.');
        $this->assertEquals('a_confirmar', $pendiente->ai_status);

        $this->mandar($this->imagen_real(), 'te mando la foto yo')->assertStatus(201);

        $this->assertNull(
            WhatsappChatMessage::find($pendiente->id),
            'La respuesta pendiente del agente se borra: el operador ya contestó él.'
        );

        $salientes = $this->salientes();
        $this->assertCount(1, $salientes, 'El único saliente que queda es el archivo que mandó el operador.');
        $this->assertEquals('image', $salientes[0]->media_type);
    }
}
