<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\asistente_ia\AdjuntosIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\Image;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión asistente-omnisciente — bloque C1: `adjuntos[]`, el canal por el que una respuesta lleva
 * las fotos de los artículos (contrato §1, §2 y §3).
 *
 * Lo que este archivo protege:
 *  - Que `adjuntos` sea SIEMPRE una lista con la forma exacta del contrato (accessor + $appends),
 *    también para los mensajes anteriores a la misión (columna en null) y para lo que no cumple la
 *    forma (el mutator lo descarta antes de guardar).
 *  - El tope de seis.
 *  - Que el job los guarde junto con el mensaje, y que el mensaje termine bien aunque el servicio
 *    todavía no los produzca.
 *  - Que viajen por el índice del chat, por show_message y por el POST (como lista vacía).
 *  - Que `GET admin-sync/asistente/mensajes/{id}` los devuelva recortados a `{tipo, url, texto}`, y
 *    `[]` cuando no hay.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 *
 * @group chat-ia
 */
class Adjuntos_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita el chat. */
    const SLUG = 'asistente_ia';

    /** Clave que el admin manda en el header X-Admin-Api-Key. */
    const CLAVE = 'clave-del-admin-para-los-tests';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.admin_api.api_key' => self::CLAVE]);
        config(['services.admin_api.require_api_key' => false]);

        $this->comercio = User::create([
            'name'         => 'Comercio adjuntos C1',
            'company_name' => 'Ferreteria adjuntos C1',
            'email'        => 'adjuntos-c1-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Asigna la extensión al comercio.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Asistente IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Conversación del dueño con un user 'listo' y un assistant 'pendiente'.
     *
     * @param  string  $canal
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_pendiente($canal = AiMessage::CANAL_SISTEMA)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Mostrame la foto del martillo',
            'canal'              => $canal,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
            'canal'              => $canal,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Un adjunto válido según el contrato.
     *
     * @param  int  $n
     * @return array
     */
    protected function adjunto($n = 1)
    {
        return [
            'tipo'        => 'imagen',
            'url'         => 'https://fotos.test/articulo-' . $n . '.jpg',
            'texto'       => 'Artículo ' . $n,
            'articulo_id' => 100 + $n,
        ];
    }

    /**
     * Un artículo del comercio con su foto en `images`.
     *
     * 🔴 `imageable_type` va con el ALIAS del morph map ('article'), no con la clase: hallazgo del
     * 16/9 (informe agente-ia-mano-derecha). Con la clase la fila se inserta igual y la relación
     * devuelve vacío en silencio.
     *
     * @param  string  $nombre
     * @param  string  $url
     * @return Article
     */
    protected function articulo_con_foto($nombre, $url)
    {
        $article = Article::create(['name' => $nombre, 'user_id' => $this->comercio->id, 'status' => 'active']);

        Image::create(['hosting_url' => $url, 'imageable_type' => 'article', 'imageable_id' => $article->id]);

        return $article;
    }

    /**
     * Fakea el loop: una vuelta que llama a una tool y otra que contesta el texto final.
     *
     * @param  string  $tool
     * @param  array   $input
     * @param  string  $texto
     * @return void
     */
    protected function fakear_loop($tool, array $input, $texto)
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'stop_reason' => 'tool_use',
                    'content'     => [
                        ['type' => 'tool_use', 'id' => 'toolu_adjuntos_01', 'name' => $tool, 'input' => $input],
                    ],
                    'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
                ], 200)
                ->push([
                    'stop_reason' => 'end_turn',
                    'content'     => [['type' => 'text', 'text' => $texto]],
                    'usage'       => ['input_tokens' => 120, 'output_tokens' => 12],
                ], 200),
        ]);
    }

    /**
     * true si el constructor A ya sumó al servicio la tool y el método del contrato §3.
     *
     * @return bool
     */
    protected function el_servicio_ya_recolecta_adjuntos()
    {
        if (!method_exists(AsistenteIaService::class, 'adjuntos')) {

            return false;
        }

        $tools = (new AsistenteIaService())->build_tools(false, false);

        foreach ($tools as $tool) {
            if (is_array($tool) && isset($tool['name']) && $tool['name'] === 'mostrar_imagenes_de_articulos') {

                return true;
            }
        }

        return false;
    }

    /**
     * Un mensaje anterior a la misión —columna en null— y uno con basura en la columna viajan con
     * `[]`, nunca con null ni ausentes: es lo que le permite a la SPA no preguntar si la clave
     * existe.
     *
     * @test
     */
    public function el_accessor_siempre_devuelve_lista()
    {
        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        $this->assertSame([], $assistant->adjuntos);
        $this->assertSame([], (new AiMessage())->adjuntos);

        // Basura en la columna (escrita por fuera del mutator) tampoco rompe nada.
        DB::table('ai_messages')->where('id', $assistant->id)->update(['adjuntos' => '"no es una lista"']);

        $this->assertSame([], AiMessage::find($assistant->id)->adjuntos);

        // Y la clave viaja en la serialización aunque el modelo sea recién creado (el $appends).
        $this->assertArrayHasKey('adjuntos', $assistant->toArray());
        $this->assertSame([], $assistant->toArray()['adjuntos']);
    }

    /**
     * El mutator normaliza: guarda solo lo que cumple el contrato, con las claves en orden, el
     * texto recortado a 200 y el articulo_id como entero o null.
     *
     * @test
     */
    public function el_mutator_normaliza_y_descarta_lo_invalido()
    {
        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        $largo = str_repeat('x', 250);

        $assistant->adjuntos = [
            ['tipo' => 'imagen', 'url' => 'https://fotos.test/a.jpg', 'texto' => 'Martillo', 'articulo_id' => 12],
            ['tipo' => 'video', 'url' => 'https://fotos.test/b.mp4', 'texto' => 'No es imagen', 'articulo_id' => 13],
            ['tipo' => 'imagen', 'url' => '/storage/relativa.jpg', 'texto' => 'URL relativa', 'articulo_id' => 14],
            ['tipo' => 'imagen', 'url' => 'data:image/png;base64,AAAA', 'texto' => 'data uri', 'articulo_id' => 15],
            ['tipo' => 'imagen', 'url' => 'http://fotos.test/c.jpg', 'texto' => $largo, 'articulo_id' => 'no-numerico'],
            ['tipo' => 'imagen', 'url' => 'https://fotos.test/d.jpg'],
            'esto no es un adjunto',
            ['url' => 'https://fotos.test/sin-tipo.jpg'],
        ];

        $assistant->save();

        $guardados = AiMessage::find($assistant->id)->adjuntos;

        $this->assertCount(3, $guardados, 'Solo entran las imágenes con URL absoluta http(s).');

        $this->assertSame(
            ['tipo' => 'imagen', 'url' => 'https://fotos.test/a.jpg', 'texto' => 'Martillo', 'articulo_id' => 12],
            $guardados[0]
        );

        $this->assertSame(['tipo', 'url', 'texto', 'articulo_id'], array_keys($guardados[1]), 'Las claves van en el orden del contrato.');
        $this->assertSame(200, mb_strlen($guardados[1]['texto']), 'El epígrafe se recorta a 200.');
        $this->assertNull($guardados[1]['articulo_id'], 'Un articulo_id no numérico queda null.');

        $this->assertNull($guardados[2]['texto'], 'Sin texto va null, no ausente.');
        $this->assertNull($guardados[2]['articulo_id']);
    }

    /**
     * Tope de seis (contrato §1): el API recorta, la SPA pinta lo que llega.
     *
     * @test
     */
    public function mas_de_seis_adjuntos_se_recortan_a_seis()
    {
        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        $ocho = [];

        for ($i = 1; $i <= 8; $i++) {
            $ocho[] = $this->adjunto($i);
        }

        $assistant->adjuntos = $ocho;
        $assistant->save();

        $guardados = AiMessage::find($assistant->id)->adjuntos;

        $this->assertCount(AdjuntosIaHelper::MAX_ADJUNTOS, $guardados);
        $this->assertSame('https://fotos.test/articulo-6.jpg', $guardados[5]['url'], 'Se quedan los primeros seis, en orden.');

        $this->assertSame(6, AdjuntosIaHelper::MAX_ADJUNTOS);
    }

    /**
     * Una lista vacía se guarda como null: la columna queda igual a la de todos los mensajes
     * anteriores a la misión.
     *
     * @test
     */
    public function una_lista_vacia_se_guarda_como_null()
    {
        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        $assistant->adjuntos = [$this->adjunto()];
        $assistant->save();

        $this->assertNotNull(DB::table('ai_messages')->where('id', $assistant->id)->value('adjuntos'));

        $assistant->adjuntos = [];
        $assistant->save();

        $this->assertNull(DB::table('ai_messages')->where('id', $assistant->id)->value('adjuntos'));
        $this->assertSame([], AiMessage::find($assistant->id)->adjuntos);
    }

    /**
     * El camino completo por el loop: el modelo llama a `mostrar_imagenes_de_articulos`, el
     * servicio recolecta `adjuntos_de_la_respuesta`, y el job los guarda con el mensaje. La foto
     * viaja aunque el texto final no la nombre (contrato §3: no se cruza contra el texto).
     *
     * Se saltea mientras el constructor A no haya sumado la tool y `adjuntos()` al servicio: hasta
     * ahí el job tiene su guarda temporal (ver el test siguiente).
     *
     * @test
     */
    public function el_job_guarda_los_adjuntos_que_recolecta_el_servicio()
    {
        if (!$this->el_servicio_ya_recolecta_adjuntos()) {
            $this->markTestSkipped('AsistenteIaService todavía no registra mostrar_imagenes_de_articulos ni adjuntos() (constructor A, contrato §3).');
        }

        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);

        $article = $this->articulo_con_foto('Martillo adjuntos C1', 'https://fotos.test/martillo-c1.jpg');

        $this->fakear_loop(
            'mostrar_imagenes_de_articulos',
            ['articulo_ids' => [$article->id]],
            'Acá va la foto.'
        );

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant->refresh();

        $this->assertEquals('listo', $assistant->estado);
        $this->assertSame(
            [['tipo' => 'imagen', 'url' => 'https://fotos.test/martillo-c1.jpg', 'texto' => 'Martillo adjuntos C1', 'articulo_id' => $article->id]],
            $assistant->adjuntos
        );
    }

    /**
     * Con el servicio de hoy (sin `adjuntos()`), el job sigue terminando 'listo' y el mensaje
     * viaja con `adjuntos: []`: la guarda temporal del job no rompe la respuesta.
     *
     * @test
     */
    public function el_job_termina_listo_con_adjuntos_vacios_cuando_no_hay_fotos()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);

        $this->fakear_loop('consultar_clientes', ['busqueda' => ''], 'No tenés clientes cargados todavía.');

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant->refresh();

        $this->assertEquals('listo', $assistant->estado);
        $this->assertSame([], $assistant->adjuntos);
        $this->assertNull(DB::table('ai_messages')->where('id', $assistant->id)->value('adjuntos'));
    }

    /**
     * 🔴 LOS TRES LUGARES POR DONDE VIAJA UN MENSAJE (contrato §1): el índice paginado, show_message
     * y el `assistant_message` del POST (como lista vacía, porque nace 'pendiente').
     *
     * @test
     */
    public function los_adjuntos_viajan_por_el_indice_show_message_y_el_post()
    {
        $this->dar_extension();

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        $assistant->contenido = 'Acá va la foto del martillo.';
        $assistant->estado = 'listo';
        $assistant->adjuntos = [$this->adjunto(1)];
        $assistant->save();

        $this->actingAs($this->comercio, 'web');

        // 1. El índice paginado.
        $indice = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages');
        $indice->assertStatus(200);

        $del_indice = null;

        foreach ($indice->json('models.data') as $fila) {
            if ((int) $fila['id'] === (int) $assistant->id) {
                $del_indice = $fila;
            }
        }

        $this->assertNotNull($del_indice, 'El assistant tiene que estar en el índice.');
        $this->assertSame([$this->adjunto(1)], $del_indice['adjuntos']);

        // 2. show_message.
        $uno = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $assistant->id);
        $uno->assertStatus(200);
        $this->assertSame([$this->adjunto(1)], $uno->json('model.adjuntos'));

        // 3. El POST: el assistant nace 'pendiente' y sin adjuntos, pero la clave viaja igual.
        Queue::fake();

        $nuevo = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => '¿Y la pinza?',
        ]);

        $nuevo->assertStatus(201);
        $this->assertSame([], $nuevo->json('assistant_message.adjuntos'), 'Siempre presente: si no hay adjuntos va [], nunca null y nunca ausente.');
        $this->assertSame([], $nuevo->json('user_message.adjuntos'));
    }

    /**
     * El polling del admin (contrato §2): `adjuntos` como `[{tipo, url, texto}]`, sin
     * `articulo_id`, y `[]` cuando el mensaje no tiene.
     *
     * @test
     */
    public function mostrar_mensaje_devuelve_los_adjuntos_recortados_para_el_admin()
    {
        $this->dar_extension();

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);

        $con_fotos = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'contenido'          => 'Acá van las fotos.',
            'estado'             => 'listo',
            'canal'              => AiMessage::CANAL_WHATSAPP,
        ]);

        $con_fotos->adjuntos = [$this->adjunto(1), $this->adjunto(2)];
        $con_fotos->save();

        $sin_fotos = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'contenido'          => 'Sin fotos.',
            'estado'             => 'listo',
            'canal'              => AiMessage::CANAL_WHATSAPP,
        ]);

        $headers = ['X-Admin-Api-Key' => self::CLAVE];

        $respuesta = $this->getJson('api/admin-sync/asistente/mensajes/' . $con_fotos->id, $headers);

        $respuesta->assertStatus(200);
        $this->assertSame('listo', $respuesta->json('estado'));
        $this->assertSame('Acá van las fotos.', $respuesta->json('contenido'));
        $this->assertSame(
            [
                ['tipo' => 'imagen', 'url' => 'https://fotos.test/articulo-1.jpg', 'texto' => 'Artículo 1'],
                ['tipo' => 'imagen', 'url' => 'https://fotos.test/articulo-2.jpg', 'texto' => 'Artículo 2'],
            ],
            $respuesta->json('adjuntos'),
            'Sin articulo_id: el admin no lo necesita.'
        );

        $vacio = $this->getJson('api/admin-sync/asistente/mensajes/' . $sin_fotos->id, $headers);

        $vacio->assertStatus(200);
        $this->assertSame([], $vacio->json('adjuntos'));
    }
}
