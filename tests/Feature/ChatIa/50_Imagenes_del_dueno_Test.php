<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\FotosDelMensajeIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión asistente-capacidades-y-hilos — P5: las fotos del dueño, visibles en el chat del sistema.
 *
 * Lucas lo reportó así: "Las fotos que le mando por whatsapp no las veo en el chat del sistema".
 * Y no era que no llegaran: en demo3 las cuatro están en disco con su fila en
 * `ai_message_imagenes`. El hueco estaba en que el API no las exponía.
 *
 * Lo que protege este archivo son las dos mitades del contrato:
 *
 *   1. `imagenes` viaja en CADA mensaje y SIEMPRE como lista —nunca null, nunca ausente—, en el
 *      índice paginado, en show_message y en el POST. Es lo que la SPA necesita para no tener que
 *      preguntarse si la clave está.
 *   2. 🔴 La `url` es un endpoint AUTENTICADO, no una ruta pública ni un link firmado eterno: son
 *      fotos del negocio, con facturas de proveedores adentro. La foto de una persona no se le
 *      sirve a otra, aunque compartan la cuenta.
 */
class Imagenes_del_dueno_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var User Un encargado con admin_access: pasa el gate del chat, pero es OTRA persona. */
    protected $encargado;

    /** @var array<int, string> Carpetas del disco local a borrar al terminar. */
    protected $carpetas_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio fotos P5',
            'company_name' => 'Ferretería de las fotos',
            'email'        => 'fotos-p5-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->encargado = User::create([
            'name'         => 'Encargado fotos P5',
            'email'        => 'fotos-p5-encargado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->comercio->id,
            'admin_access' => 1,
        ]);

        $this->dar_extension();
    }

    protected function tearDown(): void
    {
        /*
         * Las fotos se escriben en el disco `local` REAL y no en un Storage::fake(), porque el
         * endpoint lee con storage_path('app/...') —el mismo patrón que el escaneo de facturas— y
         * un fake vive en otra carpeta. Lo que se escribe, se borra.
         */
        foreach ($this->carpetas_a_limpiar as $carpeta) {

            Storage::disk('local')->deleteDirectory($carpeta);
        }

        $this->carpetas_a_limpiar = [];

        parent::tearDown();
    }

    /**
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Asistente IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Una conversación de WhatsApp de una persona.
     *
     * @param  \App\Models\User  $persona
     * @return \App\Models\AiConversation
     */
    protected function conversacion($persona = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        return AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $persona->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'titulo'          => 'La compra de Distribuidora Sur',
            'last_message_at' => now(),
        ]);
    }

    /**
     * Un mensaje de la conversación.
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @param  string  $rol
     * @return \App\Models\AiMessage
     */
    protected function mensaje($conversacion, $rol = 'user')
    {
        return AiMessage::create([
            'ai_conversation_id' => $conversacion->id,
            'rol'                => $rol,
            'contenido'          => $rol === 'user' ? 'Esto es la compra de Distribuidora Sur' : 'Ya la cargué.',
            'estado'             => 'listo',
            'canal'              => AiMessage::CANAL_WHATSAPP,
        ]);
    }

    /**
     * Una foto colgada de un mensaje, con su binario en el disco privado.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @param  int  $orden
     * @param  int|null  $owner_id
     * @return \App\Models\AiMessageImagen
     */
    protected function foto($mensaje, $orden = 1, $owner_id = null)
    {
        $owner_id = is_null($owner_id) ? $this->comercio->id : $owner_id;

        $carpeta = 'asistente_imagenes/' . $owner_id . '/' . $mensaje->id;
        $path    = $carpeta . '/' . $orden . '.webp';

        Storage::disk('local')->put($path, 'binario-de-la-foto-' . $orden);

        $this->carpetas_a_limpiar[] = $carpeta;

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $owner_id,
            'orden'         => $orden,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => 20,
        ]);
    }

    /**
     * 🔴 El contrato entero en un test: `imagenes` es una lista de `{id, orden, url}`, ordenada,
     * en el índice de mensajes. Es contra esto que está programando la SPA.
     *
     * @group asistente-fotos
     * @test
     */
    public function un_mensaje_con_fotos_las_trae_con_id_orden_y_url()
    {
        $this->actingAs($this->comercio, 'web');

        $conversacion = $this->conversacion();
        $mensaje      = $this->mensaje($conversacion);

        $primera = $this->foto($mensaje, 1);
        $segunda = $this->foto($mensaje, 2);

        $respuesta = $this->getJson('api/ai-conversations/' . $conversacion->id . '/messages');

        $respuesta->assertStatus(200);

        $mensajes = $respuesta->json('models.data');

        $this->assertCount(1, $mensajes);

        $imagenes = $mensajes[0]['imagenes'];

        $this->assertCount(2, $imagenes);

        $this->assertEquals(['id', 'orden', 'url'], array_keys($imagenes[0]), 'El recorte del contrato, sin una clave de más.');

        $this->assertEquals((int) $primera->id, $imagenes[0]['id']);
        $this->assertEquals(1, $imagenes[0]['orden']);

        $this->assertEquals((int) $segunda->id, $imagenes[1]['id']);
        $this->assertEquals(2, $imagenes[1]['orden'], 'En el orden en que llegaron.');

        $this->assertStringEndsWith(
            'api/ai-mensajes/' . $mensaje->id . '/imagen/1',
            $imagenes[0]['url'],
            'La url es el endpoint autenticado que sirve el binario.'
        );
    }

    /**
     * 🔴 SIEMPRE LISTA, NUNCA NULL Y NUNCA AUSENTE. Es la mitad del contrato que hace que la SPA
     * no tenga que preguntarse si la clave está: un mensaje del asistente, uno del dueño sin
     * fotos y uno con fotos se tratan todos igual.
     *
     * @group asistente-fotos
     * @test
     */
    public function imagenes_viaja_siempre_como_lista_aunque_no_haya_ninguna()
    {
        $this->actingAs($this->comercio, 'web');

        $conversacion = $this->conversacion();

        $sin_fotos = $this->mensaje($conversacion, 'user');
        $assistant = $this->mensaje($conversacion, 'assistant');
        $con_fotos = $this->mensaje($conversacion, 'user');

        $this->foto($con_fotos, 1);

        $respuesta = $this->getJson('api/ai-conversations/' . $conversacion->id . '/messages');

        $respuesta->assertStatus(200);

        foreach ($respuesta->json('models.data') as $mensaje) {

            $this->assertArrayHasKey('imagenes', $mensaje, 'La clave va en TODOS los mensajes.');
            $this->assertIsArray($mensaje['imagenes'], 'Nunca null: siempre una lista.');
        }

        $por_id = [];

        foreach ($respuesta->json('models.data') as $mensaje) {
            $por_id[(int) $mensaje['id']] = $mensaje['imagenes'];
        }

        $this->assertCount(0, $por_id[$sin_fotos->id]);
        $this->assertCount(0, $por_id[$assistant->id]);
        $this->assertCount(1, $por_id[$con_fotos->id]);
    }

    /**
     * La segunda punta por la que la SPA lee un mensaje: el GET puntual que pega cuando el canal
     * privado avisa. El contrato es el mismo.
     *
     * @group asistente-fotos
     * @test
     */
    public function show_message_tambien_trae_las_imagenes()
    {
        $this->actingAs($this->comercio, 'web');

        $conversacion = $this->conversacion();
        $mensaje      = $this->mensaje($conversacion);

        $this->foto($mensaje, 1);

        $respuesta = $this->getJson('api/ai-conversations/' . $conversacion->id . '/messages/' . $mensaje->id);

        $respuesta->assertStatus(200);

        $this->assertCount(1, $respuesta->json('model.imagenes'));
        $this->assertEquals(1, $respuesta->json('model.imagenes.0.orden'));
    }

    /**
     * La tercera punta: los dos globos que devuelve el POST del mensaje, que es lo que la SPA
     * pinta de forma optimista. Sin fotos, pero con la clave puesta.
     *
     * @group asistente-fotos
     * @test
     */
    public function el_post_del_mensaje_tambien_devuelve_la_clave()
    {
        $this->actingAs($this->comercio, 'web');

        $conversacion = $this->conversacion();

        $respuesta = $this->postJson(
            'api/ai-conversations/' . $conversacion->id . '/messages',
            ['contenido' => '¿Cuánto vendí hoy?']
        );

        $respuesta->assertStatus(201);

        $this->assertIsArray($respuesta->json('user_message.imagenes'));
        $this->assertIsArray($respuesta->json('assistant_message.imagenes'));
        $this->assertCount(0, $respuesta->json('user_message.imagenes'));
    }

    /**
     * 🔴 LA RUTA DEL DISCO NO SALE. `path` dice cómo está organizado el storage del cliente y no
     * le sirve a la SPA para nada: la foto se pide por el endpoint, no por su ruta.
     *
     * @group asistente-fotos
     * @test
     */
    public function la_ruta_del_disco_nunca_viaja_al_navegador()
    {
        $this->actingAs($this->comercio, 'web');

        $conversacion = $this->conversacion();
        $mensaje      = $this->mensaje($conversacion);

        $this->foto($mensaje, 1);

        $respuesta = $this->getJson('api/ai-conversations/' . $conversacion->id . '/messages');

        $respuesta->assertStatus(200);

        $crudo = $respuesta->getContent();

        $this->assertStringNotContainsString('asistente_imagenes/', $crudo, 'La ruta del disco privado no se cuenta.');
        $this->assertStringNotContainsString('.webp', $crudo, 'Ni el nombre del archivo.');

        /*
         * `path` se busca adentro de la foto y no en el JSON entero: el paginador de Laravel trae
         * su propio `path` (la URL del índice), que no tiene nada que ver con esto.
         */
        $foto = $respuesta->json('models.data.0.imagenes.0');

        $this->assertEquals(['id', 'orden', 'url'], array_keys($foto));
    }

    /**
     * La url que se publica es la del endpoint autenticado, no una ruta pública ni un link
     * firmado. Si alguien la cambia por `/storage/...`, esto lo denuncia.
     *
     * @group asistente-fotos
     * @test
     */
    public function la_url_apunta_al_endpoint_autenticado_y_no_al_storage_publico()
    {
        $url = FotosDelMensajeIaHelper::url(97, 1);

        $this->assertStringEndsWith('api/ai-mensajes/97/imagen/1', $url);
        $this->assertStringStartsWith('http', $url, 'Absoluta: la SPA la pone derecho en un <img src>.');
        $this->assertStringNotContainsString('/storage/', $url);
        $this->assertStringNotContainsString('signature=', $url, 'Nunca un link firmado: se autentica con la sesión.');
    }

    /**
     * El camino feliz del endpoint: el dueño ve su foto.
     *
     * @group asistente-fotos
     * @test
     */
    public function el_dueno_ve_el_binario_de_su_foto()
    {
        $this->actingAs($this->comercio, 'web');

        $conversacion = $this->conversacion();
        $mensaje      = $this->mensaje($conversacion);

        $this->foto($mensaje, 1);

        $respuesta = $this->get('api/ai-mensajes/' . $mensaje->id . '/imagen/1');

        $respuesta->assertStatus(200);

        $this->assertStringContainsString(
            'private',
            (string) $respuesta->headers->get('Cache-Control'),
            'Una factura de proveedor no la puede cachear un proxy compartido.'
        );
    }

    /**
     * 🔴 LA FOTO DE UNA PERSONA NO SE LE SIRVE A OTRA, aunque compartan la cuenta y aunque la otra
     * pase el gate del chat. Va en su propio método porque el guard de Sanctum cachea a la persona
     * resuelta durante todo el test: un segundo actingAs no llegaría al controller.
     *
     * @group asistente-fotos
     * @test
     */
    public function la_foto_del_dueno_no_se_le_sirve_al_encargado()
    {
        $conversacion = $this->conversacion($this->comercio);
        $mensaje      = $this->mensaje($conversacion);

        $this->foto($mensaje, 1);

        $this->actingAs($this->encargado, 'web');

        $this->get('api/ai-mensajes/' . $mensaje->id . '/imagen/1')->assertStatus(404);
    }

    /**
     * Y al revés: la del encargado tampoco es del dueño. La tenencia corta en las dos direcciones.
     *
     * @group asistente-fotos
     * @test
     */
    public function la_foto_del_encargado_no_se_le_sirve_al_dueno()
    {
        $conversacion = $this->conversacion($this->encargado);
        $mensaje      = $this->mensaje($conversacion);

        $this->foto($mensaje, 1);

        $this->actingAs($this->comercio, 'web');

        $this->get('api/ai-mensajes/' . $mensaje->id . '/imagen/1')->assertStatus(404);
    }

    /**
     * Un orden que no existe es un 404, no un 500 ni un archivo de otro mensaje.
     *
     * @group asistente-fotos
     * @test
     */
    public function un_orden_que_no_existe_da_404()
    {
        $this->actingAs($this->comercio, 'web');

        $conversacion = $this->conversacion();
        $mensaje      = $this->mensaje($conversacion);

        $this->foto($mensaje, 1);

        $this->getJson('api/ai-mensajes/' . $mensaje->id . '/imagen/7')->assertStatus(404);
    }

    /**
     * La fila está pero el archivo se borró del disco: 404 con mensaje, nunca una excepción.
     *
     * @group asistente-fotos
     * @test
     */
    public function una_fila_sin_archivo_da_404()
    {
        $this->actingAs($this->comercio, 'web');

        $conversacion = $this->conversacion();
        $mensaje      = $this->mensaje($conversacion);

        $imagen = $this->foto($mensaje, 1);

        Storage::disk('local')->delete($imagen->path);

        $this->getJson('api/ai-mensajes/' . $mensaje->id . '/imagen/1')->assertStatus(404);
    }

    /**
     * Un mensaje que no existe tampoco filtra nada.
     *
     * @group asistente-fotos
     * @test
     */
    public function un_mensaje_que_no_existe_da_404()
    {
        $this->actingAs($this->comercio, 'web');

        $this->getJson('api/ai-mensajes/99999999/imagen/1')->assertStatus(404);
    }

    /**
     * Sin la extensión, el endpoint de la foto se come el mismo 403 que el resto del chat: es
     * parte del módulo IA y no una ruta suelta.
     *
     * @group asistente-fotos
     * @test
     */
    public function sin_la_extension_el_endpoint_de_la_foto_devuelve_403()
    {
        $sin_extension = User::create([
            'name'         => 'Comercio sin módulo IA',
            'company_name' => 'Sin módulo',
            'email'        => 'fotos-p5-sin-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->actingAs($sin_extension, 'web');

        $this->getJson('api/ai-mensajes/1/imagen/1')->assertStatus(403);
    }
}
