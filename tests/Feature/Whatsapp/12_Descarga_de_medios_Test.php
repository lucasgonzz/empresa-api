<?php

namespace Tests\Feature\Whatsapp;

use App\Models\ExtencionEmpresa;
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
 * Misión whatsapp-sidebar-multimedia — U14/12: la ruta autenticada que sirve los adjuntos.
 *
 * Lo que protege este archivo es la privacidad de todas las conversaciones. Los audios y las
 * fotos NO van a `storage/app/public` porque `routes/web.php` sirve `/storage/{path}` de forma
 * pública, sin auth y con `->where('path', '.*')`: una foto de una conversación privada quedaría
 * abierta para siempre a cualquiera que tenga o adivine la URL. Viven en el disco `local`, fuera
 * del docroot, y salen SOLO por esta ruta, que suma `auth:sanctum`,
 * `check_extencion_empresa:whatsapp` y el chequeo de pertenencia del chat.
 *
 * Los casos:
 *  - sin sesión: 401 (la etiqueta `<img>` de la SPA manda la cookie de Sanctum, no un Bearer);
 *  - con la sesión de OTRA empresa: 404 y no 403, porque un 403 confirmaría que el mensaje
 *    existe. La otra empresa tiene la extensión puesta a propósito: si no, el 404 podría venir
 *    del middleware y el test no probaría nada sobre la pertenencia;
 *  - con la del dueño: 200, el `Content-Type` guardado y el cuerpo byte por byte;
 *  - sin `media_path` y con el archivo borrado del disco: 404, nunca un 500.
 *
 * ⚠️ El `Http::fake()` del setUp es una red de contención: acá no debería salir ni una request.
 * Está en el setUp y en ningún test para no violar la regla de "un solo `Http::fake()` por
 * método" (en Laravel 8 los stubs se acumulan y gana el primero que matchee).
 */
class Descarga_de_medios_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Teléfono del cliente final. */
    const PHONE = '5493416012222';

    /** Bytes exactos del adjunto guardado. */
    const BINARIO = 'CONTENIDO-DEL-ADJUNTO-0123456789-0123456789-0123456789';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var User */
    protected $comercio_ajeno;

    /** @var WhatsappChat */
    protected $chat;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        Http::fake(['*' => Http::response([], 200)]);

        Storage::fake('local');

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp U14-12',
            'company_name' => 'Ferreteria U14-12',
            'email'        => 'whatsapp-u14-12-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp U14-12',
            'email'    => 'whatsapp-u14-12-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->comercio_ajeno = User::create([
            'name'         => 'Otro comercio U14-12',
            'company_name' => 'Otra ferreteria U14-12',
            'email'        => 'whatsapp-u14-12-ajeno-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-u14-12',
            'phone_number_id'    => '5491100000012',
            'webhook_secret'     => 'secreto-u14-12',
            'is_active'          => true,
            'ai_enabled_default' => false,
        ]);

        $this->dar_extension($this->comercio);
        // La empresa ajena TAMBIÉN tiene la extensión: así el 404 del caso de pertenencia no se
        // puede confundir con el corte del middleware.
        $this->dar_extension($this->comercio_ajeno);

        $this->chat = WhatsappChat::create([
            'user_id'                => $this->comercio->id,
            'phone'                  => self::PHONE,
            'ai_enabled'             => false,
            'unread_count'           => 0,
            'last_message_at'        => now(),
            'last_inbound_at'        => now(),
            'last_inbound_simulated' => 0,
        ]);
    }

    /**
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
     * Mensaje entrante con la foto ya bajada al disco privado.
     *
     * @param bool $escribir_archivo false para simular una fila cuyo archivo ya no está.
     * @return WhatsappChatMessage
     */
    protected function mensaje_con_adjunto($escribir_archivo = true)
    {
        $path = 'whatsapp/' . $this->chat->id . '/wa_de_prueba.jpg';

        if ($escribir_archivo) {
            Storage::disk('local')->put($path, self::BINARIO);
        }

        return WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => '[Imagen recibida]',
            'media_type'       => 'image',
            'media_path'       => $path,
            'media_mime'       => 'image/jpeg',
            'media_size'       => strlen(self::BINARIO),
        ]);
    }

    /**
     * @param WhatsappChatMessage $mensaje
     * @return string
     */
    protected function url(WhatsappChatMessage $mensaje)
    {
        return 'api/whatsapp-chats/' . $this->chat->id . '/media/' . $mensaje->id;
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_sesion_el_adjunto_no_se_puede_bajar()
    {
        $mensaje = $this->mensaje_con_adjunto();

        $this->getJson($this->url($mensaje))->assertStatus(401);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_la_sesion_de_otra_empresa_el_adjunto_no_existe()
    {
        $mensaje = $this->mensaje_con_adjunto();

        $this->actingAs($this->comercio_ajeno, 'web');

        // 404 y no 403: quién tiene o no un adjunto es información del chat, y un 403
        // confirmaría que el mensaje existe.
        $this->getJson($this->url($mensaje))->assertStatus(404);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_dueno_baja_el_archivo_con_el_tipo_de_contenido_guardado()
    {
        $mensaje = $this->mensaje_con_adjunto();

        $this->actingAs($this->empleado, 'web');

        $response = $this->get($this->url($mensaje));

        $response->assertStatus(200);
        $this->assertEquals('image/jpeg', $response->headers->get('Content-Type'));
        // Sin `nosniff` el navegador puede ignorar el Content-Type y adivinar mirando el
        // contenido, que es justo lo que la lista blanca de mimes viene a evitar.
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));

        ob_start();
        $response->sendContent();
        $cuerpo = ob_get_clean();

        $this->assertEquals(self::BINARIO, $cuerpo, 'El cuerpo tiene que ser el archivo, byte por byte.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_mensaje_sin_adjunto_devuelve_404()
    {
        $mensaje = WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'esto es un mensaje de texto',
        ]);

        $this->actingAs($this->empleado, 'web');

        $this->getJson($this->url($mensaje))->assertStatus(404);
    }

    /**
     * La fila puede tener path y el archivo no estar: se limpió el disco, o se restauró una
     * base en otro servidor. Es un 404, no una excepción de "file not found" con 500.
     *
     * @group whatsapp
     * @test
     */
    public function una_fila_cuyo_archivo_ya_no_esta_en_disco_devuelve_404_y_no_un_error()
    {
        $mensaje = $this->mensaje_con_adjunto(false);

        $this->actingAs($this->empleado, 'web');

        $this->getJson($this->url($mensaje))->assertStatus(404);
    }
}
