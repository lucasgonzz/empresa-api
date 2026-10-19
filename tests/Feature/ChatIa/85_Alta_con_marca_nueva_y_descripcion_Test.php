<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaGenericaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\Image;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-arreglos-segunda-prueba (19/10/2026), N6 — el alta por foto que quedó sin
 * descripción ni marca.
 *
 * Lo que pasó en la segunda prueba real contra demo3 (`_video/agente-personal/registro-19-10.md`, A6):
 * «dame de alta esta lampara de filamento led philips 4w e27, la vendo a 4200. ponele esta foto» →
 * artículo 49 con foto, precio y categoría, pero SIN descripción (el prompt la dejaba opcional, «y
 * descripcion si la hay») y SIN marca: «Philips» no existía y resolver_relacion() devolvía un `faltan`
 * pelado, sin decir que la marca se puede crear (con la 3M del 16/10 sí se había creado).
 *
 * Lo que fija este archivo:
 *   - 🔴 Una marca que no existe en el alta de un artículo trae `opciones.si_no_esta` (crearla con
 *     proponer_alta de brand y volver a llamar) y `opciones.reenvia_igual` con la llamada tal como
 *     llegó MENOS la marca: la foto, la descripción, la categoría, el precio y el stock no se pierden
 *     en la segunda llamada.
 *   - Reenviar eso con la marca nueva crea el artículo con todo (en "directo", en el mismo mensaje).
 *   - Con la marca propuesta en una tarjeta que todavía no se confirmó, el alta no vuelve a pedir
 *     crearla: dice que se confirme primero (sin esto el modelo daba vueltas proponiendo la marca).
 *   - La marca, nueva o existente, queda GUARDADA en `articles.brand_id` y no se duplica. Desde
 *     b48c81f0 (develop, 19/10/2026) el catálogo no ofrecía brand_id al crear un artículo y el alta
 *     rechazaba toda marca; lo arregla LEIDOS_POR_HELPER['article'][OP_ALTA] en
 *     CatalogoDeEscrituraIaHelper (autorizado por la raíz de la misión).
 *   - Las demás relaciones sin coincidencia siguen como antes (sin `si_no_esta`).
 *   - El prompt lleva las dos reglas: descripción siempre en el alta de un artículo (sin inventar
 *     datos técnicos) y la marca nombrada o legible en brand_id, creándola si no existe.
 *
 * El dueño es el user 500 del TestingFerreteriaSeeder; cada test crea sus categorías y marcas adentro
 * de la transacción de EmpresaTestCase. `agente_confianza` es un interruptor global y vuelve a mano en
 * tearDown(), igual que en 72_Alta_con_categoria_y_precio_de_venta_Test.
 *
 * 🔴 Ningún test sale a la red (Http::fake() y el disco local falso).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Alta_con_marca_nueva_y_descripcion_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var array Las columnas globales del dueño que este archivo mueve, como estaban. */
    protected $columnas_originales = [];

    /** @var array<int, ExtencionEmpresa> */
    protected $extensiones_enganchadas = [];

    /** @var Category */
    protected $electricidad;

    /** @var SubCategory */
    protected $iluminacion;

    /** @var array<int, string> Archivos de imágenes que el alta con foto dejó en storage/app/public. */
    protected $archivos_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba-p85']);

        Http::fake();

        Storage::fake('local');

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->columnas_originales = [
            'listas_de_precio' => $this->dueno->listas_de_precio,
            'agente_confianza' => $this->dueno->agente_confianza,
        ];

        $this->dar_extension('asistente_ia');

        $this->dueno_con(['agente_confianza' => 'cauteloso', 'listas_de_precio' => 0]);

        Catalogo::olvidar();

        $this->electricidad = Category::create(['name' => 'Electricidad P85', 'user_id' => $this->dueno->id, 'num' => 985]);

        $this->iluminacion = SubCategory::create([
            'name'        => 'Iluminación P85',
            'category_id' => $this->electricidad->id,
            'user_id'     => $this->dueno->id,
            'num'         => 985,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos_a_limpiar as $nombre) {
            $ruta = storage_path() . '/app/public/' . $nombre;

            if ($nombre !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }

        User::where('id', $this->dueno->id)->update($this->columnas_originales);

        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->extensiones_enganchadas[] = $extencion;
    }

    /**
     * @param  array  $columnas
     * @return void
     */
    protected function dueno_con(array $columnas)
    {
        User::where('id', $this->dueno->id)->update($columnas);

        $this->dueno = User::find($this->dueno->id);

        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->dueno, 'web');
    }

    /**
     * La conversación del caso A6: el dueño manda la foto con el pedido y el assistant está pendiente.
     *
     * @return array{0: AiConversation, 1: AiMessage, 2: AiMessageImagen}
     */
    protected function conversacion_con_foto()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        $del_dueno = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'dame de alta esta lampara de filamento led philips 4w e27, la vendo a 4200. ponele esta foto',
            'estado'             => 'listo',
        ]);

        $binario = (string) (new ImageManager())->canvas(12, 12, '#F8C30B')->encode('png');
        $path = 'asistente_imagenes/' . $this->dueno->id . '/' . $del_dueno->id . '/1.png';

        Storage::disk('local')->put($path, $binario);

        $foto = AiMessageImagen::create([
            'ai_message_id' => $del_dueno->id,
            'user_id'       => $this->dueno->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/png',
            'bytes'         => strlen($binario),
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant, $foto];
    }

    /**
     * La llamada que mandó el modelo en A6, con la marca que no existe.
     *
     * @return array
     */
    protected function alta_de_la_lampara()
    {
        return [
            'entidad'                     => 'article',
            'datos'                       => [
                'name'            => 'Lámpara filamento LED Philips 4W E27 P85',
                'price'           => 4200,
                'category_id'     => 'Electricidad P85',
                'sub_category_id' => 'Iluminación P85',
                'brand_id'        => 'Philips P85',
            ],
            'con_foto_de_la_conversacion' => true,
            'descripcion'                 => 'Lámpara LED de filamento Philips de 4 W con rosca E27.',
            'stock_inicial'               => 6,
        ];
    }

    /**
     * Ejecuta una herramienta de carga por el despacho real y devuelve lo que ve el modelo.
     *
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    // =====================================================================
    // 1. La marca que no existe
    // =====================================================================

    /**
     * 🔴 EL CASO A6. «Philips» no existe: no queda tarjeta, la respuesta dice que se crea
     * (`si_no_esta`) y trae `reenvia_igual` con TODO lo que había mandado el modelo menos la marca.
     *
     * @test
     */
    public function una_marca_que_no_existe_trae_si_no_esta_y_el_reenvio_con_todo()
    {
        list($conversation, $assistant, $foto) = $this->conversacion_con_foto();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_lampara());

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertNotEmpty($respuesta['faltan']);
        $this->assertStringContainsString('Philips P85', $respuesta['faltan'][0]);

        $this->assertSame(PropuestaGenericaIaHelper::SI_NO_ESTA_LA_MARCA, $respuesta['opciones']['si_no_esta']);
        $this->assertStringContainsString('proponer_alta', $respuesta['opciones']['si_no_esta']);
        $this->assertStringContainsString('brand', $respuesta['opciones']['si_no_esta']);
        $this->assertStringContainsString('reenvia_igual', $respuesta['opciones']['si_no_esta']);

        $reenvia = $respuesta['opciones']['reenvia_igual'];

        $this->assertSame('article', $reenvia['entidad']);
        $this->assertSame([
            'name'            => 'Lámpara filamento LED Philips 4W E27 P85',
            'price'           => 4200,
            'category_id'     => 'Electricidad P85',
            'sub_category_id' => 'Iluminación P85',
        ], $reenvia['datos'], 'La categoría, la subcategoría y el precio viajan; la marca no (es lo que falta).');
        $this->assertTrue($reenvia['con_foto_de_la_conversacion']);
        $this->assertSame('Lámpara LED de filamento Philips de 4 W con rosca E27.', $reenvia['descripcion']);
        $this->assertEquals(6, $reenvia['stock_inicial']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Sin la marca no queda tarjeta.');
        $this->assertNull(AiMessageImagen::find($foto->id)->gestionada_at, 'La pregunta no sella la foto.');
    }

    /**
     * El camino entero en "directo" (como demo3): la marca se crea en el acto y el reenvío con la
     * marca nueva crea el artículo con la foto, la descripción, la categoría, la subcategoría, el
     * precio y la marca, en el mismo mensaje.
     *
     * @test
     */
    public function en_directo_se_crea_la_marca_y_el_reenvio_crea_el_articulo_con_todo()
    {
        $this->dueno_con(['agente_confianza' => 'directo']);

        list($conversation, $assistant, $foto) = $this->conversacion_con_foto();

        $pedido = $this->alta_de_la_lampara();
        unset($pedido['stock_inicial']);

        $primera = $this->herramienta($conversation, $assistant, 'proponer_alta', $pedido);

        $this->assertFalse($primera['ok'], json_encode($primera));

        $marca = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'brand',
            'datos'   => ['name' => 'Philips P85'],
        ]);

        $this->assertSame('confirmada', $marca['estado'], json_encode($marca));
        $this->assertNotNull(Brand::where('user_id', $this->dueno->id)->where('name', 'Philips P85')->first());

        // El modelo reenvía EXACTAMENTE reenvia_igual, sumando la marca nueva.
        $reenvia = $primera['opciones']['reenvia_igual'];
        $reenvia['datos']['brand_id'] = 'Philips P85';

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', $reenvia);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('confirmada', $respuesta['estado'], json_encode($respuesta));

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Lámpara filamento LED Philips 4W E27 P85')->first();

        $this->assertNotNull($articulo);

        foreach (Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->get() as $image) {
            $this->archivos_a_limpiar[] = basename((string) $image->hosting_url);
        }

        // 🔴 La marca queda GUARDADA en la fila (ArticleController::store → ApplyProductFacts::brand
        // devuelve el brand_id del request tal cual) y no se crea una segunda marca con el mismo nombre.
        $this->assertSame(1, Brand::where('user_id', $this->dueno->id)->where('name', 'Philips P85')->count(), 'Una sola marca Philips P85.');
        $this->assertSame(
            (int) Brand::where('user_id', $this->dueno->id)->where('name', 'Philips P85')->value('id'),
            (int) DB::table('articles')->where('id', $articulo->id)->value('brand_id'),
            'El artículo creado tiene la marca nueva en articles.brand_id.'
        );
        $this->assertSame((int) $this->electricidad->id, (int) $articulo->category_id);
        $this->assertSame((int) $this->iluminacion->id, (int) $articulo->sub_category_id);
        $this->assertEquals(4200, (float) $articulo->price);
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count(), 'Nace con la foto.');
        $this->assertSame(1, DB::table('descriptions')->where('article_id', $articulo->id)->count(), 'Y con la descripción.');
        $this->assertNotNull(AiMessageImagen::find($foto->id)->gestionada_at);
    }

    /**
     * En "cauteloso" la marca queda en una tarjeta: el alta del artículo que vuelve a llamar en el
     * mismo mensaje NO pide crearla de nuevo (el modelo daría vueltas proponiendo la marca), dice que
     * la persona la confirme primero y no deja tarjeta del artículo.
     *
     * @test
     */
    public function con_la_marca_en_una_tarjeta_sin_confirmar_no_vuelve_a_pedir_crearla()
    {
        list($conversation, $assistant) = $this->conversacion_con_foto();

        $marca = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'brand',
            'datos'   => ['name' => 'Philips P85'],
        ]);

        $this->assertTrue(!empty($marca['ok']), json_encode($marca));
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_lampara());

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('Philips P85', (string) $respuesta['error']);
        $this->assertStringContainsString('confirme', (string) $respuesta['error']);
        $this->assertFalse(isset($respuesta['opciones']['si_no_esta']), 'No vuelve a pedir que la crees.');
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Solo queda la tarjeta de la marca.');
    }

    /**
     * Con la marca que existe, se resuelve por nombre (sin `si_no_esta`) y, al confirmar la tarjeta en
     * "cauteloso" por el endpoint de la pantalla, el artículo queda en la base con ESA marca y no se
     * crea otra. Es lo que estaba roto en develop desde b48c81f0: el catálogo no ofrecía brand_id al
     * crear un artículo y el alta rechazaba hasta la marca que ya existía.
     *
     * @test
     */
    public function una_marca_que_existe_se_resuelve_y_queda_guardada_en_el_articulo()
    {
        $philips = Brand::create(['name' => 'Philips P85', 'user_id' => $this->dueno->id, 'num' => 985]);

        list($conversation, $assistant) = $this->conversacion_con_foto();

        $pedido = $this->alta_de_la_lampara();
        unset($pedido['stock_inicial']);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', $pedido);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertFalse(isset($respuesta['opciones']['si_no_esta']));
        $this->assertSame((int) $philips->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['payload']['brand_id']);

        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Lámpara filamento LED Philips 4W E27 P85')->first();

        $this->assertNotNull($articulo);

        foreach (Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->get() as $image) {
            $this->archivos_a_limpiar[] = basename((string) $image->hosting_url);
        }

        $this->assertSame((int) $philips->id, (int) DB::table('articles')->where('id', $articulo->id)->value('brand_id'), 'articles.brand_id es la marca que ya existía.');
        $this->assertSame(1, Brand::where('user_id', $this->dueno->id)->where('name', 'Philips P85')->count(), 'No se creó otra marca.');
    }

    /**
     * Las demás relaciones sin coincidencia siguen igual: `faltan` con las opciones y sin `si_no_esta`
     * (el proveedor que no existe, por ejemplo, se pregunta como siempre).
     *
     * @test
     */
    public function otra_relacion_sin_coincidencia_no_trae_si_no_esta()
    {
        list($conversation, $assistant) = $this->conversacion_con_foto();

        $pedido = $this->alta_de_la_lampara();
        unset($pedido['datos']['brand_id']);
        $pedido['datos']['provider_id'] = 'Proveedor que no existe P85';

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', $pedido);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertNotEmpty($respuesta['faltan']);
        $this->assertFalse(isset($respuesta['opciones']['si_no_esta']));
        $this->assertFalse(isset($respuesta['opciones']['reenvia_igual']));
    }

    /**
     * La edición de un artículo con una marca que no existe también dice que se crea (no hay extras que
     * reenviar: se vuelve a llamar con los mismos cambios).
     *
     * @test
     */
    public function la_edicion_con_una_marca_que_no_existe_trae_si_no_esta()
    {
        $articulo = Article::create(['user_id' => $this->dueno->id, 'name' => 'Lámpara vieja P85', 'category_id' => $this->electricidad->id]);

        list($conversation, $assistant) = $this->conversacion_con_foto();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => $articulo->id,
            'cambios'  => ['brand_id' => 'Philips P85'],
        ]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(PropuestaGenericaIaHelper::SI_NO_ESTA_LA_MARCA, $respuesta['opciones']['si_no_esta']);
    }

    // =====================================================================
    // 2. El prompt y la herramienta
    // =====================================================================

    /**
     * Las dos reglas del prompt: la descripción SIEMPRE en el alta de un artículo (sin inventar datos
     * técnicos) y la marca nombrada o legible en brand_id, creándola si no existe. Y la herramienta
     * dice lo mismo de la descripción.
     *
     * @test
     */
    public function el_prompt_lleva_las_reglas_de_la_descripcion_y_la_marca()
    {
        list($conversation) = $this->conversacion_con_foto();

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('con_foto_de_la_conversacion y descripcion', $prompt);
        $this->assertStringNotContainsString('(y descripcion si la hay)', $prompt, 'La descripción ya no es opcional.');
        $this->assertStringContainsString('Todo alta de un artículo lleva descripcion', $prompt);
        $this->assertStringContainsString('No inventes medidas', $prompt);
        $this->assertStringContainsString('La MARCA de un artículo', $prompt);
        $this->assertStringContainsString('brand_id', $prompt);
        $this->assertStringContainsString('opciones.si_no_esta', $prompt);

        $alta = null;

        foreach (HerramientasDeCarga::definiciones(true) as $definicion) {
            if ($definicion['name'] === 'proponer_alta') {
                $alta = $definicion;
            }
        }

        $this->assertNotNull($alta);
        $this->assertStringContainsString('SIEMPRE', $alta['input_schema']['properties']['descripcion']['description']);
    }
}
