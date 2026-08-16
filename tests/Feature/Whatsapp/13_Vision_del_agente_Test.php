<?php

namespace Tests\Feature\Whatsapp;

use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión whatsapp-sidebar-multimedia — U14/13: el agente mira (o no) las fotos del cliente.
 *
 * Todo este archivo mira UNA cosa: el payload que efectivamente viaja a Anthropic. El stub lo
 * captura y después se afirma sobre él, porque ningún otro lado deja rastro de si la foto viajó:
 * `generate_response()` se traga cualquier `\Throwable` y devuelve `''`, así que un error acá no
 * se ve como error sino como "el agente dejó de contestar".
 *
 * Lo que protege:
 *
 * 🔴 QUE UNA FOTO QUE NO SE PUEDE LEER DEL DISCO NO DEJE MUDO AL AGENTE
 * (`si_el_archivo_no_esta_en_disco_el_agente_contesta_igual_con_el_texto()`). Con la visión
 * prendida y el archivo borrado, el turno tiene que degradar a texto plano y la conversación
 * seguir. Es el caso crítico 13.3 del plan.
 *
 * 🔴 QUE LA FUSIÓN DE TURNOS SOBREVIVA A LOS BLOQUES. La agrupación de mensajes consecutivos del
 * mismo rol se hacía con un `.=` sobre strings; con un array de bloques de un lado eso es un
 * fatal, y el síntoma —otra vez— es "el agente dejó de contestar" en toda conversación con dos
 * entrantes seguidos. Lo cubre `dos_entrantes_seguidos_con_una_foto_no_rompen_la_fusion()`.
 *
 * - Que con el interruptor APAGADO (el default) no viaje ni un bloque de imagen: el camino por
 *   defecto no puede gastar tokens de visión, que se cobran aparte.
 * - Que con el interruptor prendido la imagen viaje en base64 (el archivo es privado, no hay URL
 *   que Anthropic pueda bajar) y con el `media_type` correcto.
 * - Que el tope de 3 imágenes se respete: sin él, una conversación larga con fotos manda todas
 *   en cada turno y el gasto crece sin que la respuesta mejore.
 * - Que guardar el interruptor no pise la personalidad ni las credenciales: las dos pantallas de
 *   configuración pegan al mismo endpoint y cada una manda solo sus campos.
 *
 * 🔴 UN SOLO `Http::fake()` POR MÉTODO (en Laravel 8 los stubs se acumulan y gana el primero que
 * matchee), con el `'*'` último. Y el stub de OpenAI no es decorativo: si el embedding del RAG
 * cayera en el `'*'` y volviera vacío, `generate_response()` devolvería `''` por una excepción
 * del RAG, con exactamente el mismo síntoma que un bug de visión.
 */
class Vision_del_agente_Test extends TestCase
{
    use DatabaseTransactions;

    /** Teléfono del cliente final. */
    const PHONE = '5493416013333';

    /** Bytes del archivo de imagen guardado en el disco privado. */
    const BINARIO = 'JPEG-DE-PRUEBA-0123456789-0123456789-0123456789-0123456789';

    /** Personalidad cargada por el dueño; ninguna edición del switch la puede pisar. */
    const PERSONALIDAD = 'Sos el vendedor de la ferretería del barrio, tuteás y sos breve.';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    /** Payload que viajó a Anthropic (lo captura el stub). */
    protected $payload_anthropic = null;

    /** Texto que devuelve el fake de Anthropic. */
    protected $texto_de_la_ia = 'Sí, tenemos ese repuesto.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        Storage::fake('local');

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp U14-13',
            'company_name' => 'Ferreteria U14-13',
            'email'        => 'whatsapp-u14-13-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-u14-13',
            'phone_number_id'    => '5491100000013',
            'webhook_secret'     => 'secreto-u14-13',
            'is_active'          => true,
            'ai_enabled_default' => true,
            'agent_personality'  => self::PERSONALIDAD,
            // Apagado de fábrica: el camino por defecto NO gasta tokens de visión.
            'ai_vision_enabled'  => false,
        ]);

        $this->chat = WhatsappChat::create([
            'user_id'                => $this->comercio->id,
            'phone'                  => self::PHONE,
            'ai_enabled'             => true,
            'unread_count'           => 0,
            'last_message_at'        => now(),
            'last_inbound_at'        => now(),
            'last_inbound_simulated' => 0,
        ]);
    }

    /**
     * Stubs de las tres APIs externas, capturando lo que viaja a Anthropic.
     *
     * @return void
     */
    protected function fakes_de_red()
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => function ($request) {
                $this->payload_anthropic = $request->data();

                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => $this->texto_de_la_ia]],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            'api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.VISION']]], 200),
            '*'              => Http::response([], 200),
        ]);
    }

    /**
     * Prende el interruptor de visión del dueño.
     *
     * @return void
     */
    protected function prender_vision()
    {
        $this->config->ai_vision_enabled = true;
        $this->config->save();
    }

    /**
     * Mensaje entrante con foto: fila con las columnas del medio y, si corresponde, el archivo
     * escrito en el disco privado.
     *
     * @param string $body           Epígrafe, o el literal de relleno.
     * @param string $sufijo         Distingue el archivo de cada mensaje.
     * @param bool   $con_archivo    false para simular la foto que no se pudo bajar o se borró.
     * @param string $mime           Mime guardado en la fila.
     * @return WhatsappChatMessage
     */
    protected function entrante_con_foto($body, $sufijo, $con_archivo = true, $mime = 'image/jpeg')
    {
        $path = 'whatsapp/' . $this->chat->id . '/wa_' . $sufijo . '.jpg';

        if ($con_archivo) {
            Storage::disk('local')->put($path, self::BINARIO);
        }

        return WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => $body,
            'media_type'       => 'image',
            'media_path'       => $path,
            'media_mime'       => $mime,
            'media_size'       => strlen(self::BINARIO),
        ]);
    }

    /**
     * Mensaje entrante de texto plano.
     *
     * @param string $body
     * @return WhatsappChatMessage
     */
    protected function entrante_de_texto($body)
    {
        return WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => $body,
        ]);
    }

    /**
     * Dispara la generación de la respuesta con la config vigente.
     *
     * @return string
     */
    protected function generar()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        return (new WhatsappBotAiService())->generate_response($this->chat, $this->config->fresh());
    }

    /**
     * Todos los bloques de tipo `image` que viajaron, sin importar en qué turno quedaron: la
     * fusión de turnos consecutivos hace que varias fotos terminen en un mismo mensaje.
     *
     * @return array<int, array>
     */
    protected function bloques_de_imagen()
    {
        $bloques  = [];
        $mensajes = isset($this->payload_anthropic['messages']) ? $this->payload_anthropic['messages'] : [];

        foreach ($mensajes as $mensaje) {
            if (! isset($mensaje['content']) || ! is_array($mensaje['content'])) {
                continue;
            }

            foreach ($mensaje['content'] as $bloque) {
                if (isset($bloque['type']) && $bloque['type'] === 'image') {
                    $bloques[] = $bloque;
                }
            }
        }

        return $bloques;
    }

    /**
     * El payload entero serializado, para afirmar que un texto llegó al modelo sin importar si
     * viajó como string o adentro de un bloque `text`.
     *
     * @return string
     */
    protected function payload_serializado()
    {
        return (string) json_encode($this->payload_anthropic, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Con el interruptor apagado el modelo se entera igual de que llegó una foto (por el
     * epígrafe, o por el literal de relleno), pero no se paga un solo token de visión.
     *
     * @group whatsapp
     * @test
     */
    public function con_la_vision_apagada_la_foto_viaja_como_texto_y_no_como_imagen()
    {
        $this->fakes_de_red();

        $this->entrante_con_foto('¿Tenés este repuesto?', 'apagada');

        $texto = $this->generar();

        $this->assertNotEquals('', $texto, 'El agente tiene que contestar igual.');
        $this->assertNotNull($this->payload_anthropic);
        $this->assertCount(
            0,
            $this->bloques_de_imagen(),
            'Con el interruptor apagado NO puede viajar ni un bloque de imagen: cada uno se cobra aparte.'
        );
        $this->assertStringContainsString(
            '¿Tenés este repuesto?',
            $this->payload_serializado(),
            'El texto que acompañaba la foto sí tiene que llegar al modelo.'
        );
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_la_vision_prendida_la_foto_viaja_en_base64_con_su_tipo()
    {
        $this->fakes_de_red();
        $this->prender_vision();

        $this->entrante_con_foto('¿Tenés este repuesto?', 'prendida');

        $texto = $this->generar();

        $this->assertNotEquals('', $texto);

        $bloques = $this->bloques_de_imagen();
        $this->assertCount(1, $bloques, 'Con el interruptor prendido la foto viaja como bloque de imagen.');

        // Base64 y no URL: el archivo vive en el disco privado, fuera del docroot, justamente
        // para que una conversación privada no quede abierta a quien adivine una URL.
        $this->assertEquals('base64', $bloques[0]['source']['type']);
        $this->assertEquals('image/jpeg', $bloques[0]['source']['media_type']);
        $this->assertEquals(
            self::BINARIO,
            base64_decode($bloques[0]['source']['data']),
            'Lo que viaja tiene que ser el archivo que está en disco.'
        );

        $this->assertStringContainsString('¿Tenés este repuesto?', $this->payload_serializado());
    }

    /**
     * 🔴 CASO CRÍTICO (13.3 del plan). El interruptor está prendido y la fila dice que hay foto,
     * pero el archivo no está en el disco: se limpió el storage, se restauró la base en otro
     * servidor, o la descarga del webhook nunca llegó a escribirlo.
     *
     * Ese turno tiene que degradar a texto plano y la conversación seguir. Si en vez de eso se
     * lanzara una excepción, `generate_response()` la atraparía y devolvería `''`: el negocio no
     * vería un error, vería que el agente dejó de contestarle a los clientes que mandan fotos.
     *
     * @group whatsapp
     * @test
     */
    public function si_el_archivo_no_esta_en_disco_el_agente_contesta_igual_con_el_texto()
    {
        $this->fakes_de_red();
        $this->prender_vision();

        // La fila tiene `media_path` cargado, pero el archivo nunca se escribió.
        $mensaje = $this->entrante_con_foto('¿Cuánto sale esto?', 'sin-archivo', false);
        Storage::disk('local')->assertMissing($mensaje->media_path);

        $texto = $this->generar();

        $this->assertEquals(
            $this->texto_de_la_ia,
            $texto,
            'El agente tiene que contestar igual: perder la vista de la foto no puede costar la respuesta.'
        );
        $this->assertNotNull($this->payload_anthropic, 'La llamada a Anthropic tiene que haber salido.');
        $this->assertCount(
            0,
            $this->bloques_de_imagen(),
            'Sin archivo no hay nada que mandar: el turno degrada a texto, no explota.'
        );
        $this->assertStringContainsString(
            '¿Cuánto sale esto?',
            $this->payload_serializado(),
            'El texto del cliente tiene que llegar igual.'
        );
    }

    /**
     * Sin tope, una conversación larga con fotos manda TODAS en cada turno: el gasto crece con
     * cada mensaje nuevo y la respuesta no mejora, porque lo que el cliente pregunta ahora casi
     * siempre es sobre la última foto.
     *
     * @group whatsapp
     * @test
     */
    public function solo_viajan_las_tres_fotos_mas_recientes()
    {
        $this->fakes_de_red();
        $this->prender_vision();

        $this->entrante_con_foto('foto uno', 'uno');
        $this->entrante_con_foto('foto dos', 'dos');
        $this->entrante_con_foto('foto tres', 'tres');
        $this->entrante_con_foto('foto cuatro', 'cuatro');

        $this->generar();

        $this->assertCount(
            3,
            $this->bloques_de_imagen(),
            'El tope duro son las 3 imágenes más recientes: la cuarta degrada a texto.'
        );

        // Las cuatro siguen estando en el historial como texto, así que el modelo no pierde el
        // hilo: pierde la vista de las fotos viejas.
        $serializado = $this->payload_serializado();
        $this->assertStringContainsString('foto uno', $serializado);
        $this->assertStringContainsString('foto cuatro', $serializado);
    }

    /**
     * 🔴 EL CASO DE LA FUSIÓN DE TURNOS (D23). Dos mensajes seguidos del cliente se funden en un
     * solo turno `user`. Hasta esta misión eso se hacía con un `.=` sobre strings; con la visión
     * prendida uno de los dos lados es un array de bloques y ese `.=` es un fatal.
     *
     * Y como `generate_response()` se traga la excepción y devuelve `''`, el modo de falla es
     * silencioso: el agente deja de contestar en toda conversación donde el cliente mande una
     * foto y después escriba algo, que es el caso más común de todos.
     *
     * @group whatsapp
     * @test
     */
    public function dos_entrantes_seguidos_con_una_foto_no_rompen_la_fusion()
    {
        $this->fakes_de_red();
        $this->prender_vision();

        $this->entrante_con_foto('te mando la foto', 'fusion');
        $this->entrante_de_texto('¿tenés uno igual?');

        $texto = $this->generar();

        $this->assertEquals($this->texto_de_la_ia, $texto, 'La fusión no puede dejar mudo al agente.');
        $this->assertNotNull($this->payload_anthropic);

        $mensajes = $this->payload_anthropic['messages'];
        $this->assertCount(1, $mensajes, 'Los dos entrantes seguidos se funden en un solo turno user.');
        $this->assertEquals('user', $mensajes[0]['role']);
        $this->assertTrue(
            is_array($mensajes[0]['content']),
            'Con una imagen adentro, el contenido del turno tiene que ser un array de bloques.'
        );

        $this->assertCount(1, $this->bloques_de_imagen());

        $serializado = $this->payload_serializado();
        $this->assertStringContainsString('te mando la foto', $serializado, 'El epígrafe de la foto sobrevive a la fusión.');
        $this->assertStringContainsString('¿tenés uno igual?', $serializado, 'Y el mensaje siguiente también.');
    }

    /**
     * Las dos pantallas de configuración (Conexión y Agente) pegan al MISMO endpoint y cada una
     * manda solo sus campos. Por eso cada campo se agrega con su propio `$request->has(...)`:
     * agrupar dos en un mismo `if` hace que la pantalla que manda uno le escriba `false` al
     * otro, y el switch se apagaría solo al guardar cualquier otra cosa, sin ningún error.
     *
     * @group whatsapp
     * @test
     */
    public function guardar_el_interruptor_de_vision_no_pisa_la_personalidad_ni_las_credenciales()
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($this->comercio, 'web');

        $this->putJson('api/whatsapp-bot/config', ['ai_vision_enabled' => true])->assertStatus(200);

        $guardada = $this->config->fresh();
        $this->assertTrue((bool) $guardada->ai_vision_enabled);
        $this->assertEquals(self::PERSONALIDAD, $guardada->agent_personality, 'La personalidad del agente no se toca.');
        $this->assertEquals('kapso-u14-13', $guardada->kapso_api_key);
        $this->assertEquals('5491100000013', $guardada->phone_number_id);
        $this->assertEquals('secreto-u14-13', $guardada->webhook_secret);
        $this->assertTrue((bool) $guardada->is_active);

        // Y al revés: guardar otra cosa desde la otra pantalla no puede apagar el switch.
        $this->putJson('api/whatsapp-bot/config', ['agent_personality' => 'Otra personalidad.'])->assertStatus(200);

        $guardada = $this->config->fresh();
        $this->assertEquals('Otra personalidad.', $guardada->agent_personality);
        $this->assertTrue(
            (bool) $guardada->ai_vision_enabled,
            'Guardar la personalidad NO puede apagar el interruptor de visión.'
        );
    }
}
