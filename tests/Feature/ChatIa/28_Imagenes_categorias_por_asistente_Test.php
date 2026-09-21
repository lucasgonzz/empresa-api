<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\CategoriaImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\AccionIaException;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaImagenCategoriaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaImagenesCategoriasIaHelper;
use App\Jobs\ProcessCategoryImagesJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\Category;
use App\Models\GeocoderCounter;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Misión asistente-masivas-imagenes-y-remito (19/9/2026) — imágenes para las categorías desde
 * el asistente: la consulta, la tarjeta, el encolado con el registro visible `pendiente`, el
 * job de punta a punta (Google, descarga y visión falseados con Http::fake), el mensaje de
 * cierre con su tarjeta por dudosa, y la confirmación de esa tarjeta.
 *
 * Sin red: Google, los sitios de las imágenes y Anthropic se falsean. El PNG que "descarga" el
 * job lo genera Intervention. Los archivos que el job deja en storage/app/public se borran en
 * tearDown.
 */
class Imagenes_categorias_por_asistente_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var int Instante de arranque, para limpiar solo las candidatas de este test. */
    protected $inicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inicio = time() - 1;

        // Con clave (falsa): las validaciones salen a Http, que está falseado en cada test.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        config(['services.article_image_validation.enabled' => true]);
        config(['services.article_image_validation.max_calls_batch' => 300]);

        $this->comercio = User::create([
            'name'             => 'Comercio imagenes P28',
            'company_name'     => 'Ferreteria P28',
            'email'            => 'imgcat-p28-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
            'google_cuota'     => 10,
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado imagenes P28',
            'email'    => 'imgcat-p28-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    protected function tearDown(): void
    {
        // Lo que el job asignó (nombre definitivo) y las candidatas de esta corrida.
        foreach (Category::where('user_id', $this->comercio->id)->get() as $categoria) {
            $this->borrar_archivo_de_url($categoria->image_url);
        }

        foreach (glob(storage_path('app/public/catcand_*.webp')) ?: [] as $archivo) {
            if (@filemtime($archivo) >= $this->inicio) {
                @unlink($archivo);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  string|null $url
     * @return void
     */
    protected function borrar_archivo_de_url($url)
    {
        $url = (string) $url;

        if ($url === '') {
            return;
        }

        $nombre = basename((string) parse_url($url, PHP_URL_PATH));
        $ruta   = storage_path('app/public/' . $nombre);

        if ($nombre !== '' && strpos($nombre, 'pdf_cache') === false && is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /**
     * Tres categorías del comercio: dos sin imagen y una con.
     *
     * @return array{0: Category, 1: Category, 2: Category}
     */
    protected function categorias()
    {
        $bazar      = Category::create(['name' => 'Bazar', 'user_id' => $this->comercio->id]);
        $ferreteria = Category::create(['name' => 'Ferretería', 'user_id' => $this->comercio->id]);
        $pinturas   = Category::create(['name' => 'Pinturas', 'user_id' => $this->comercio->id, 'image_url' => 'https://ejemplo.test/storage/pinturas.webp']);

        return [$bazar, $ferreteria, $pinturas];
    }

    /**
     * Conversación de la persona (default: el dueño) con un mensaje del usuario y el assistant
     * pendiente que propone.
     *
     * @param  \App\Models\User|null $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Buscale imágenes a las categorías que no tienen',
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Un PNG real (no cuadrado, para que el recorte trabaje).
     *
     * @return string
     */
    protected function png()
    {
        // 400x300: por encima de ProcessCategoryImagesJob::MIN_LADO_PX, para que la candidata llegue a la visión.
        return (string) (new ImageManager())->canvas(400, 300, '#c0392b')->encode('png');
    }

    /**
     * Respuesta de Anthropic con el JSON del veredicto.
     *
     * @param  array $json
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function respuesta_de_vision(array $json)
    {
        return Http::response([
            'model'   => 'claude-haiku-4-5-20251001',
            'content' => [['type' => 'text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)]],
            'usage'   => ['input_tokens' => 120, 'output_tokens' => 40],
        ], 200);
    }

    /**
     * El nombre de categoría que viajó en el user prompt de una request a Anthropic.
     *
     * @param  \Illuminate\Http\Client\Request $request
     * @return string
     */
    protected function categoria_preguntada($request)
    {
        $cuerpo = json_decode($request->body(), true);

        foreach ((array) ($cuerpo['messages'][0]['content'] ?? []) as $bloque) {
            // El nombre viaja entre comillas (delimitado a propósito); se lee sin ellas.
            if (($bloque['type'] ?? '') === 'text' && preg_match('/CATEGORÍA DEL COMERCIO: "?([^"\n]+)"?/u', (string) $bloque['text'], $m)) {
                return trim($m[1]);
            }
        }

        return '';
    }

    /**
     * Falsea Google (dos items por búsqueda), la descarga (un PNG real) y Anthropic (el veredicto
     * que decide $veredicto_por_categoria por nombre; 'caido' responde 500).
     *
     * @param  array $veredicto_por_categoria  nombre => 'usar' | 'dudosa' | 'descartar' | 'caido'
     * @return void
     */
    protected function falsear_red(array $veredicto_por_categoria, $png = null)
    {
        $png = is_null($png) ? $this->png() : $png;

        Http::fake(function ($request) use ($png, $veredicto_por_categoria) {
            $url = $request->url();

            if (strpos($url, 'googleapis.com/customsearch') !== false) {
                return Http::response([
                    'items' => [
                        [
                            'link'  => 'https://sitio-de-terceros.test/a.jpg',
                            'title' => 'Set de bazar',
                            'image' => ['width' => 800, 'height' => 800, 'thumbnailLink' => 'https://sitio-de-terceros.test/thumb-a.jpg'],
                        ],
                        [
                            'link'  => 'https://sitio-de-terceros.test/b.jpg',
                            'title' => 'Otro producto',
                            'image' => ['width' => 640, 'height' => 480, 'thumbnailLink' => 'https://sitio-de-terceros.test/thumb-b.jpg'],
                        ],
                    ],
                    'searchInformation' => ['totalResults' => '2'],
                ], 200);
            }

            if (strpos($url, 'api.anthropic.com') !== false) {
                $veredicto = $veredicto_por_categoria[$this->categoria_preguntada($request)] ?? 'descartar';

                if ($veredicto === 'caido') {
                    return Http::response(['error' => ['message' => 'overloaded']], 500);
                }

                if ($veredicto === 'usar') {
                    return $this->respuesta_de_vision(['representa' => true, 'fondo_blanco' => true, 'calidad' => 'alta', 'confianza' => 'high', 'motivo' => 'Es un set de bazar sobre fondo blanco.']);
                }

                if ($veredicto === 'dudosa') {
                    return $this->respuesta_de_vision(['representa' => true, 'fondo_blanco' => false, 'calidad' => 'media', 'confianza' => 'medium', 'motivo' => 'El fondo no es blanco del todo.']);
                }

                return $this->respuesta_de_vision(['representa' => false, 'fondo_blanco' => true, 'calidad' => 'alta', 'confianza' => 'high', 'motivo' => 'Es una lista de precios.']);
            }

            return Http::response($png, 200, ['Content-Type' => 'image/png']);
        });
    }

    /**
     * @param  Category $bazar
     * @param  Category $ferreteria
     * @param  AiConversation $conversation
     * @return ProcessCategoryImagesJob
     */
    protected function job(Category $bazar, Category $ferreteria, AiConversation $conversation)
    {
        return new ProcessCategoryImagesJob(
            (int) $this->comercio->id,
            (int) $this->comercio->id,
            (int) $conversation->id,
            [['id' => $bazar->id, 'buscar_como' => null], ['id' => $ferreteria->id, 'buscar_como' => null]],
            'KEY-DE-PRUEBA',
            'CX-DE-PRUEBA',
            10
        );
    }

    /** @test */
    public function consultar_cuenta_las_categorias_sin_imagen_y_la_cuota()
    {
        list($bazar) = $this->categorias();

        Article::create(['name' => 'zz Plato p28', 'user_id' => $this->comercio->id, 'cost' => 10, 'status' => 'active', 'category_id' => $bazar->id]);

        GeocoderCounter::create(['user_id' => $this->comercio->id, 'counter' => 3]);

        list($conversation) = $this->conversacion();

        $respuesta = PropuestaImagenesCategoriasIaHelper::consultar(ContextoDeCargaIa::de_la_conversacion($conversation));

        $this->assertTrue($respuesta['ok']);
        $this->assertSame(3, $respuesta['total_categorias']);
        $this->assertSame(2, $respuesta['sin_imagen']);
        $this->assertSame(['Bazar', 'Ferretería'], array_column($respuesta['categorias'], 'nombre'));
        $this->assertSame(1, $respuesta['categorias'][0]['articulos']);
        $this->assertSame(10, $respuesta['cuota_diaria']);
        $this->assertSame(7, $respuesta['busquedas_disponibles_hoy']);
    }

    /** @test */
    public function un_empleado_sin_admin_no_puede_proponer()
    {
        $this->categorias();
        list($conversation, $assistant) = $this->conversacion($this->empleado);

        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, []);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(PropuestaImagenesCategoriasIaHelper::MENSAJE_SIN_PERMISO, $respuesta['error']);
    }

    /** @test */
    public function en_modo_cauteloso_la_propuesta_queda_como_tarjeta_con_las_dos_sin_imagen()
    {
        list($bazar, $ferreteria) = $this->categorias();
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, []);

        $this->assertTrue($respuesta['ok']);
        $this->assertSame('imagenes_categorias', $respuesta['tipo']);
        $this->assertSame(10, $respuesta['busquedas_disponibles_hoy']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado());
        $this->assertSame('sin_imagen', $accion->datos['alcance']);
        $this->assertSame([(int) $bazar->id, (int) $ferreteria->id], array_column($accion->datos['categorias'], 'id'));
        $this->assertSame('Imágenes para categorías', $accion->presentacion['titulo']);
        $this->assertSame('2 (las que no tienen imagen)', $accion->presentacion['renglones'][0]['valor']);
        $this->assertSame('Bazar, Ferretería', $accion->presentacion['renglones'][1]['valor']);
        $this->assertSame('10 de 10', $accion->presentacion['renglones'][2]['valor']);
    }

    /** @test */
    public function con_alcance_todas_entran_las_tres()
    {
        $this->categorias();
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, ['alcance' => 'todas']);

        $this->assertTrue($respuesta['ok']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame('todas', $accion->datos['alcance']);
        $this->assertCount(3, $accion->datos['categorias']);
    }

    /** @test */
    public function si_todas_tienen_imagen_es_un_error_de_negocio()
    {
        Category::create(['name' => 'Pinturas', 'user_id' => $this->comercio->id, 'image_url' => 'https://ejemplo.test/storage/p.webp']);
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, []);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('ya tienen imagen', $respuesta['error']);
    }

    /** @test */
    public function las_categorias_por_nombre_se_resuelven_y_la_ambigua_pide_desambiguar()
    {
        Category::create(['name' => 'Pinturas látex', 'user_id' => $this->comercio->id]);
        Category::create(['name' => 'Pinturas esmalte', 'user_id' => $this->comercio->id]);
        $bazar = Category::create(['name' => 'Bazar', 'user_id' => $this->comercio->id, 'image_url' => 'https://ejemplo.test/storage/b.webp']);
        Category::create(['name' => 'Bazar y regalos', 'user_id' => $this->comercio->id]);

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        /* Ambigua: dos que contienen "Pintura" y ninguna se llama exactamente así. */
        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer($contexto, $assistant, ['categorias' => [['nombre' => 'Pintura']]]);

        $this->assertFalse($respuesta['ok']);
        $this->assertCount(1, $respuesta['faltan']);
        $this->assertSame(['Pinturas esmalte', 'Pinturas látex'], array_column($respuesta['opciones']['categorias'], 'nombre'));

        /* Exacta gana aunque haya otra que la contenga; y nombrarla explícitamente la incluye aunque ya tenga imagen. */
        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer($contexto, $assistant, ['categorias' => [['nombre' => 'bazar', 'buscar_como' => 'artículos de bazar']]]);

        $this->assertTrue($respuesta['ok']);
        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame([['id' => (int) $bazar->id, 'nombre' => 'Bazar', 'buscar_como' => 'artículos de bazar']], $accion->datos['categorias']);

        /* Inexistente: error con el nombre. */
        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer($contexto, $assistant, ['categorias' => [['nombre' => 'Juguetería']]]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('Juguetería', $respuesta['error']);
    }

    /** @test */
    public function una_categoria_nombrada_que_ya_tiene_imagen_se_reemplaza_de_punta_a_punta()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Pinturas' => 'usar']);

        list(, , $pinturas) = $this->categorias();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        /* La persona la nombra: la tarjeta promete el reemplazo y exige confirmación. */
        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer($contexto, $assistant, ['categorias' => [['nombre' => 'Pinturas']]]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue($respuesta['requiere_confirmacion']);
        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame('todas', $accion->datos['alcance'], 'Nombrada = se procesa aunque tenga imagen: el alcance que viaja es todas.');

        /* Al confirmar, el job sale con ese alcance... */
        Queue::fake();
        PropuestaImagenesCategoriasIaHelper::ejecutar($contexto, $accion);

        $despachado = null;
        Queue::assertPushed(ProcessCategoryImagesJob::class, function ($job) use (&$despachado) {
            $despachado = $job;

            return true;
        });

        $alcance = new \ReflectionProperty($despachado, 'alcance');
        $alcance->setAccessible(true);
        $this->assertSame('todas', $alcance->getValue($despachado));

        /* ...y corrido, reemplaza la imagen en vez de saltearla ("ya tenía imagen"). */
        $despachado->handle();

        $pinturas->refresh();
        $this->assertNotSame('https://ejemplo.test/storage/pinturas.webp', $pinturas->image_url, 'La imagen tenía que reemplazarse.');
        $this->assertStringEndsWith('.webp', basename((string) parse_url($pinturas->image_url, PHP_URL_PATH)));

        $mensaje = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->orderBy('id', 'DESC')->first();
        $this->assertStringContainsString('Asigné imagen a 1: Pinturas.', $mensaje->contenido);
        $this->assertStringNotContainsString('ya tenía imagen', $mensaje->contenido);
    }

    /** @test */
    public function ejecutar_encola_el_job_con_las_categorias_y_deja_el_registro_visible_pendiente()
    {
        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer($contexto, $assistant, []);
        $accion    = AiMessageAction::find($respuesta['tarjeta_id']);

        $resultado = PropuestaImagenesCategoriasIaHelper::ejecutar($contexto, $accion);

        $this->assertSame('Mandé a buscar imágenes para 2 categorías. Cuando termine te escribo acá con el resultado, y en el sistema te aparece el proceso.', $resultado['texto']);
        $this->assertSame(['name' => 'abm', 'params' => ['view' => 'articulos', 'sub_view' => 'categorias'], 'texto' => 'Ver categorías'], $resultado['ruta']);

        Queue::assertPushed(ProcessCategoryImagesJob::class, function ($job) use ($bazar, $ferreteria, $conversation) {
            $leer = function ($nombre) use ($job) {
                $p = new \ReflectionProperty($job, $nombre);
                $p->setAccessible(true);

                return $p->getValue($job);
            };

            return (int) $leer('owner_id') === (int) $this->comercio->id
                && (int) $leer('ai_conversation_id') === (int) $conversation->id
                && array_column($leer('categorias'), 'id') === [(int) $bazar->id, (int) $ferreteria->id]
                && $leer('cx') === 'c442e5f346f314951'
                && (int) $leer('google_cuota') === 10;
        });

        $proceso = BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_categorias')->orderBy('id', 'DESC')->first();
        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $proceso->status);
        $this->assertSame('Imágenes de categorías', $proceso->titulo);
        $this->assertSame('En espera del procesador', $proceso->etapa);
        $this->assertSame('categorías', $proceso->unidad);
        $this->assertSame('2 categorías', $proceso->detalle);
        $this->assertSame(2, (int) $proceso->total);
        $this->assertSame((int) $this->comercio->id, (int) $proceso->auth_user_id);
    }

    /**
     * La auto-confirmación en "resuelto" pasa por HerramientasDeCarga (el `case` y
     * AUTO_CONFIRMABLES los escribe el constructor A de la misión): hasta que ese wiring esté,
     * este test se saltea y lo dice. Cuando esté, corre entero.
     *
     * @test
     */
    public function en_modo_resuelto_la_herramienta_encola_sola_y_en_cauteloso_deja_la_tarjeta()
    {
        if (!in_array('imagenes_categorias', HerramientasDeCarga::AUTO_CONFIRMABLES, true)) {
            $this->markTestSkipped('HerramientasDeCarga todavía no wireó proponer_imagenes_para_categorias (constructor A).');
        }

        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->comercio->agente_confianza = 'resuelto';
        $this->comercio->save();

        $this->categorias();
        list($conversation, $assistant) = $this->conversacion();

        HerramientasDeCarga::ejecutar('proponer_imagenes_para_categorias', [], $conversation, $assistant);

        $accion = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('tipo', 'imagenes_categorias')->first();
        $this->assertNotNull($accion);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado());

        Queue::assertPushed(ProcessCategoryImagesJob::class);
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_categorias')->orderBy('id', 'DESC')->value('status'));

        /* Y en cauteloso, la misma herramienta deja la tarjeta propuesta sin encolar nada. */
        $this->comercio->agente_confianza = 'cauteloso';
        $this->comercio->save();

        list($otra_conversation, $otro_assistant) = $this->conversacion();

        HerramientasDeCarga::ejecutar('proponer_imagenes_para_categorias', [], $otra_conversation, $otro_assistant);

        $accion = AiMessageAction::where('ai_conversation_id', $otra_conversation->id)->where('tipo', 'imagenes_categorias')->first();
        $this->assertNotNull($accion);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado());
    }

    /** @test */
    public function el_job_asigna_la_segura_deja_la_dudosa_en_una_tarjeta_y_cierra_el_registro()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'usar', 'Ferretería' => 'dudosa']);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation) = $this->conversacion();

        $this->job($bazar, $ferreteria, $conversation)->handle();

        /* Bazar quedó con imagen, en un archivo real y con nombre definitivo (no catcand_). */
        $bazar->refresh();
        $this->assertNotNull($bazar->image_url);
        $nombre_bazar = basename((string) parse_url($bazar->image_url, PHP_URL_PATH));
        $this->assertStringEndsWith('.webp', $nombre_bazar);
        $this->assertStringStartsNotWith('catcand_', $nombre_bazar, 'La imagen asignada no puede tener el prefijo de candidata: la purga se la llevaría.');
        $this->assertFileExists(storage_path('app/public/' . $nombre_bazar));

        /* Ferretería NO. */
        $this->assertNull($ferreteria->fresh()->image_url);

        /* El mensaje de cierre, en la conversación, listo y con acciones. */
        $mensaje = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->orderBy('id', 'DESC')->first();
        $this->assertSame('listo', $mensaje->estado);
        $this->assertTrue((bool) $mensaje->acciones_habilitadas);
        $this->assertSame('sistema', $mensaje->canal);
        $this->assertSame(
            "Terminé de buscar imágenes para las categorías.\n"
            . "Asigné imagen a 1: Bazar.\n"
            . "Para Ferretería encontré una imagen pero no estoy seguro de que corresponda: mirá la tarjeta de abajo y, si te sirve, tocá Usar esta imagen.",
            $mensaje->contenido
        );
        $this->assertNotNull($conversation->fresh()->last_message_at);

        /* UNA tarjeta imagen_categoria, con la miniatura y los textos de los botones. */
        $tarjetas = AiMessageAction::where('ai_message_id', $mensaje->id)->get();
        $this->assertCount(1, $tarjetas);

        $tarjeta = $tarjetas[0];
        $this->assertSame('imagen_categoria', $tarjeta->tipo);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame((int) $ferreteria->id, (int) $tarjeta->datos['category_id']);
        $this->assertStringStartsWith('catcand_', $tarjeta->datos['archivo']);
        $this->assertFileExists(storage_path('app/public/' . $tarjeta->datos['archivo']));
        $this->assertSame('Imagen para la categoría Ferretería', $tarjeta->presentacion['titulo']);
        $this->assertSame('El fondo no es blanco del todo.', $tarjeta->presentacion['renglones'][1]['valor']);
        $this->assertStringEndsWith('/storage/' . $tarjeta->datos['archivo'], $tarjeta->presentacion['imagen_url']);
        $this->assertSame('Usar esta imagen', $tarjeta->presentacion['texto_confirmar']);
        $this->assertSame('No usarla', $tarjeta->presentacion['texto_cancelar']);
        $this->assertSame('No usaste esta imagen.', $tarjeta->presentacion['texto_cancelada']);

        /* El aviso salió con el id del mensaje nuevo. */
        Event::assertDispatched(ChatIaMensajeActualizado::class, function ($evento) use ($conversation, $mensaje) {
            return (int) $evento->ai_conversation_id === (int) $conversation->id
                && (int) $evento->ai_message_id === (int) $mensaje->id
                && $evento->estado === 'listo';
        });

        /* El registro visible terminó con los cinco contadores. */
        $proceso = BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_categorias')->orderBy('id', 'DESC')->first();
        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame('Terminado', $proceso->etapa);
        $this->assertSame(['procesadas' => 2, 'asignadas' => 1, 'dudosas' => 1, 'sin_resultado' => 0, 'sin_cuota' => 0], $proceso->resultado());

        /* Solo quedó en disco la candidata de la tarjeta: las descartadas y la asignada (promovida) no. */
        $candidatas = array_filter(glob(storage_path('app/public/catcand_*.webp')) ?: [], function ($archivo) {
            return @filemtime($archivo) >= $this->inicio;
        });
        $this->assertCount(1, $candidatas);
    }

    /** @test */
    public function con_alcance_sin_imagen_el_job_saltea_la_que_ya_tiene_imagen_cuando_llega()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'usar', 'Ferretería' => 'usar']);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation) = $this->conversacion();

        // Entre la tarjeta y el worker, alguien le cargó una imagen a mano a Ferretería.
        $ferreteria->image_url = 'https://ejemplo.test/storage/a-mano.webp';
        $ferreteria->save();

        $this->job($bazar, $ferreteria, $conversation)->handle();

        $this->assertSame('https://ejemplo.test/storage/a-mano.webp', $ferreteria->fresh()->image_url, 'Con alcance sin_imagen no se pisa la que ya tiene.');
        $this->assertNotNull($bazar->fresh()->image_url);

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'googleapis.com/customsearch') !== false
                && strpos(urldecode((string) parse_url($request->url(), PHP_URL_QUERY)), 'Ferreter') !== false;
        });

        $mensaje = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->orderBy('id', 'DESC')->first();
        $this->assertStringContainsString('Ferretería ya tenía imagen cuando llegué, así que la dejé como estaba.', $mensaje->contenido);
    }

    /** @test */
    public function una_tanda_que_reemplaza_imagenes_cargadas_deja_tarjeta_aunque_el_dueno_este_en_resuelto()
    {
        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->comercio->agente_confianza = 'resuelto';
        $this->comercio->save();

        $this->categorias();
        list($conversation, $assistant) = $this->conversacion();

        // Pinturas ya tiene imagen: 'todas' la reemplazaría.
        $resultado = HerramientasDeCarga::ejecutar('proponer_imagenes_para_categorias', ['alcance' => 'todas'], $conversation, $assistant);
        $respuesta = json_decode($resultado['content'], true);

        $this->assertTrue($respuesta['ok'], $resultado['content']);
        $this->assertTrue($respuesta['requiere_confirmacion']);
        $this->assertSame(['Pinturas'], $respuesta['reemplaza_la_imagen_de']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado(), 'Reemplazar no es inocuo: no se auto-confirma ni en resuelto.');
        $this->assertTrue($accion->datos['reemplaza_existentes']);
        $this->assertSame('Se reemplaza la imagen de', $accion->presentacion['renglones'][2]['etiqueta']);
        $this->assertSame('Pinturas', $accion->presentacion['renglones'][2]['valor']);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function sin_cuota_disponible_no_se_propone_ni_se_encola()
    {
        Queue::fake();

        $this->categorias();
        list($conversation, $assistant) = $this->conversacion();

        $this->comercio->google_cuota = 5;
        $this->comercio->save();
        GeocoderCounter::create(['user_id' => $this->comercio->id, 'counter' => 5]);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer($contexto, $assistant, []);
        $this->assertFalse($respuesta['ok']);
        $this->assertSame(PropuestaImagenesCategoriasIaHelper::MENSAJE_SIN_CUOTA, $respuesta['error']);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function una_candidata_mas_chica_que_el_piso_se_descarta_sin_llamar_a_la_vision()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'usar', 'Ferretería' => 'usar'], (string) (new ImageManager())->canvas(120, 120, '#c0392b')->encode('png'));

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation) = $this->conversacion();

        $this->job($bazar, $ferreteria, $conversation)->handle();

        $this->assertNull($bazar->fresh()->image_url, 'Un thumbnail de 120 px no puede terminar como imagen de la categoría.');
        $this->assertNull($ferreteria->fresh()->image_url);
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'api.anthropic.com') !== false;
        });
    }

    /** @test */
    public function no_usarla_borra_la_candidata_del_disco()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'dudosa', 'Ferretería' => 'descartar']);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation) = $this->conversacion();

        $this->job($bazar, $ferreteria, $conversation)->handle();

        $tarjeta = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('tipo', 'imagen_categoria')->first();
        $this->assertNotNull($tarjeta);
        $ruta = storage_path('app/public/' . $tarjeta->datos['archivo']);
        $this->assertFileExists($ruta);

        $resultado = EjecutorAccionesIaHelper::cancelar($conversation, $tarjeta->id);

        $this->assertSame(200, $resultado['status']);
        $this->assertSame(AiMessageAction::ESTADO_CANCELADA, $tarjeta->fresh()->estado_guardado());
        $this->assertFileDoesNotExist($ruta);
    }

    /** @test */
    public function el_job_retoma_el_registro_que_le_pasaron_por_id_y_no_el_ultimo_activo()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'usar', 'Ferretería' => 'usar']);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation) = $this->conversacion();

        // Dos tandas encoladas seguidas: la del job es la primera; la segunda es más nueva.
        $propio = BackgroundProcessHelper::iniciar($this->comercio->id, 'imagenes_categorias', 'Imágenes de categorías', ['status' => 'pendiente', 'etapa' => 'En espera del procesador', 'total' => 2]);
        $otro   = BackgroundProcessHelper::iniciar($this->comercio->id, 'imagenes_categorias', 'Imágenes de categorías', ['status' => 'pendiente', 'etapa' => 'En espera del procesador', 'total' => 7]);

        $job = new ProcessCategoryImagesJob(
            (int) $this->comercio->id,
            (int) $this->comercio->id,
            (int) $conversation->id,
            [['id' => $bazar->id, 'buscar_como' => null], ['id' => $ferreteria->id, 'buscar_como' => null]],
            'KEY-DE-PRUEBA',
            'CX-DE-PRUEBA',
            10,
            'sin_imagen',
            (int) $propio->id
        );
        $job->handle();

        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $propio->fresh()->status, 'Retomó el suyo.');
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $otro->fresh()->status, 'El de la otra tanda sigue esperando a su worker.');
    }

    /** @test */
    public function la_primera_busqueda_pide_fondo_blanco_y_la_segunda_no()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'usar', 'Ferretería' => 'dudosa']);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation) = $this->conversacion();

        $this->job($bazar, $ferreteria, $conversation)->handle();

        $parametros = function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $p);

            return $p;
        };

        /* q1 de Ferretería: "productos de <nombre> fondo blanco" con imgDominantColor=white. */
        Http::assertSent(function ($request) use ($parametros) {
            $p = $parametros($request);

            return strpos($request->url(), 'googleapis.com/customsearch') !== false
                && ($p['q'] ?? '') === 'productos de Ferretería fondo blanco'
                && ($p['imgDominantColor'] ?? '') === 'white';
        });

        /* q2 de Ferretería (q1 no dio `usar`): "<nombre> productos", sin el parámetro de color. */
        Http::assertSent(function ($request) use ($parametros) {
            $p = $parametros($request);

            return strpos($request->url(), 'googleapis.com/customsearch') !== false
                && ($p['q'] ?? '') === 'Ferretería productos'
                && !array_key_exists('imgDominantColor', $p);
        });

        /* Bazar resolvió en q1: nunca hubo q2 para Bazar. */
        Http::assertNotSent(function ($request) use ($parametros) {
            return strpos($request->url(), 'googleapis.com/customsearch') !== false
                && ($parametros($request)['q'] ?? '') === 'Bazar productos';
        });

        /* Y la cuota se movió una vez por búsqueda hecha: 1 de Bazar + 2 de Ferretería. */
        $this->assertSame(3, (int) GeocoderCounter::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->value('counter'));
    }

    /** @test */
    public function sin_cuota_no_se_llama_a_google_y_las_categorias_quedan_sin_cuota()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'usar', 'Ferretería' => 'usar']);

        GeocoderCounter::create(['user_id' => $this->comercio->id, 'counter' => 10]);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation) = $this->conversacion();

        $this->job($bazar, $ferreteria, $conversation)->handle();

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'googleapis') !== false;
        });

        $this->assertNull($bazar->fresh()->image_url);
        $this->assertNull($ferreteria->fresh()->image_url);

        $proceso = BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_categorias')->orderBy('id', 'DESC')->first();
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame('Se agotó la cuota diaria de búsquedas', $proceso->etapa);
        $this->assertSame(['procesadas' => 0, 'asignadas' => 0, 'dudosas' => 0, 'sin_resultado' => 0, 'sin_cuota' => 2], $proceso->resultado());

        $mensaje = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->orderBy('id', 'DESC')->first();
        $this->assertSame(
            "Terminé de buscar imágenes para las categorías.\n"
            . "Me quedé sin búsquedas por hoy para 2 categorías (Bazar, Ferretería): pedímelo de nuevo mañana.",
            $mensaje->contenido
        );
        $this->assertCount(0, AiMessageAction::where('ai_message_id', $mensaje->id)->get());
    }

    /** @test */
    public function con_anthropic_caido_la_candidata_queda_dudosa_y_no_se_asigna()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'caido', 'Ferretería' => 'caido']);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation) = $this->conversacion();

        $this->job($bazar, $ferreteria, $conversation)->handle();

        $this->assertNull($bazar->fresh()->image_url, 'No evaluado tiene que ser dudosa, nunca asignada.');
        $this->assertNull($ferreteria->fresh()->image_url);

        $mensaje  = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->orderBy('id', 'DESC')->first();
        $tarjetas = AiMessageAction::where('ai_message_id', $mensaje->id)->orderBy('id')->get();

        $this->assertCount(2, $tarjetas);
        $this->assertSame('No pude verificarla con la IA.', $tarjetas[0]->presentacion['renglones'][1]['valor']);
        $this->assertStringContainsString('Para Bazar y Ferretería encontré una imagen pero no estoy seguro', $mensaje->contenido);

        $proceso = BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_categorias')->orderBy('id', 'DESC')->first();
        $this->assertSame(['procesadas' => 2, 'asignadas' => 0, 'dudosas' => 2, 'sin_resultado' => 0, 'sin_cuota' => 0], $proceso->resultado());
    }

    /** @test */
    public function el_job_retoma_el_registro_pendiente_del_encolado_y_failed_lo_cierra()
    {
        Event::fake([ChatIaMensajeActualizado::class, BackgroundProcessUpdated::class]);
        $this->falsear_red(['Bazar' => 'usar', 'Ferretería' => 'usar']);

        list($bazar, $ferreteria) = $this->categorias();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        Queue::fake();
        $respuesta = PropuestaImagenesCategoriasIaHelper::proponer($contexto, $assistant, []);
        PropuestaImagenesCategoriasIaHelper::ejecutar($contexto, AiMessageAction::find($respuesta['tarjeta_id']));

        $pendiente = BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_categorias')->orderBy('id', 'DESC')->first();
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $pendiente->status);

        $this->job($bazar, $ferreteria, $conversation)->handle();

        $this->assertSame(1, (int) BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_categorias')->count(), 'El job abrió otra fila en vez de retomar la pendiente.');
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, BackgroundProcess::find($pendiente->id)->status);

        /* failed() sobre una corrida nueva cierra su fila en fallo. */
        $otra = BackgroundProcess::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->comercio->id, 'tipo' => 'imagenes_categorias',
            'titulo' => 'Imágenes de categorías', 'status' => BackgroundProcess::STATUS_PENDIENTE, 'procesados' => 0,
        ]);

        $this->job($bazar, $ferreteria, $conversation)->failed(new \Exception('se murió el worker'));

        $otra->refresh();
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $otra->status);
        $this->assertSame('se murió el worker', $otra->error_message);
    }

    /** @test */
    public function usar_esta_imagen_asigna_y_con_el_archivo_borrado_da_422()
    {
        list($bazar) = $this->categorias();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        /* La candidata en disco, como la deja el job. */
        $archivo = 'catcand_' . (string) Str::uuid() . '.webp';
        (new ImageManager())->canvas(32, 32, '#2980b9')->save(storage_path('app/public/' . $archivo));

        $accion = PropuestaImagenCategoriaIaHelper::crear_desde_job($contexto, $assistant, $bazar, $archivo, 'https://ejemplo.test/storage/' . $archivo, 'El fondo no es blanco del todo.', null);

        $this->assertSame('imagen_categoria', $accion->tipo);
        $this->assertSame('imagen_categoria:' . $bazar->id, $accion->clave);

        $resultado = PropuestaImagenCategoriaIaHelper::ejecutar($contexto, $accion);

        $this->assertSame('Imagen asignada a la categoría Bazar', $resultado['texto']);
        $this->assertSame(['view' => 'articulos', 'sub_view' => 'categorias'], $resultado['ruta']['params']);

        $bazar->refresh();
        $nombre = basename((string) parse_url($bazar->image_url, PHP_URL_PATH));
        $this->assertStringStartsNotWith('catcand_', $nombre);
        $this->assertFileExists(storage_path('app/public/' . $nombre));
        /* La candidata sigue: la tarjeta confirmada la muestra hasta la purga. */
        $this->assertFileExists(storage_path('app/public/' . $archivo));

        /* Sin permiso: 422. */
        $contexto_empleado = ContextoDeCargaIa::de_la_conversacion($conversation, $this->empleado);

        try {
            PropuestaImagenCategoriaIaHelper::ejecutar($contexto_empleado, $accion);
            $this->fail('Un empleado sin admin no puede asignar la imagen.');
        } catch (AccionIaException $e) {
            $this->assertSame(422, $e->status);
        }

        /* Con el archivo borrado (la purga, o alguien que limpió storage): 422 legible. */
        @unlink(storage_path('app/public/' . $archivo));

        try {
            PropuestaImagenCategoriaIaHelper::ejecutar($contexto, $accion);
            $this->fail('Con el archivo borrado tiene que dar 422.');
        } catch (AccionIaException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(PropuestaImagenCategoriaIaHelper::MENSAJE_IMAGEN_INEXISTENTE, $e->getMessage());
        }
    }

    /** @test */
    public function la_purga_borra_solo_las_candidatas_viejas()
    {
        $vieja = storage_path('app/public/catcand_' . (string) Str::uuid() . '.webp');
        $nueva = storage_path('app/public/catcand_' . (string) Str::uuid() . '.webp');
        $real  = storage_path('app/public/' . time() . '99999.webp');

        foreach ([$vieja, $nueva, $real] as $ruta) {
            file_put_contents($ruta, 'x');
        }

        touch($vieja, time() - 4 * 86400);
        touch($real, time() - 30 * 86400);

        try {
            $this->assertSame(1, CategoriaImagenHelper::purgar_candidatas_viejas());
            $this->assertFileDoesNotExist($vieja);
            $this->assertFileExists($nueva);
            $this->assertFileExists($real, 'Una imagen real (sin el prefijo) nunca se purga, por vieja que sea.');
        } finally {
            @unlink($nueva);
            @unlink($real);
        }
    }
}
