<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\asistente_ia\BusquedaPorCodigoDeBarrasIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\CategoriasDelNegocioIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\Image;
use App\Models\MasiveUpdate;
use App\Models\Provider;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\BusquedaPorCodigoDeBarrasService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-altas-y-conversacion (16/10/2026) — D5: las altas de artículos con categoría y
 * subcategoría, y el precio de venta en el resultado.
 *
 * De dónde sale: la prueba real por WhatsApp contra demo3 (`_video/agente-personal/registro.md`):
 *  - S10: la cinta 3M quedó dada de alta sin categoría ni subcategoría ("Electricidad" era obvia).
 *  - S11: el martillo Tramontina (por código de barras) quedó sin categoría, y al pedir "ponele la
 *    categoría que corresponda" quedó con la categoría y SIN la subcategoría que existía. Y con
 *    `final_price` 9.278,35 el asistente dijo "el precio de venta todavía no se calculó".
 *  - S07/S08: la masiva de costo +8 % se confirmó sin que nadie dijera cómo quedaban los precios de
 *    venta (la ducha saltó de $ 22.415 a $ 29.292).
 *
 * Lo que protege este archivo:
 *  - 🔴 Un alta de artículo sin `category_id` en un negocio con categorías NO arma tarjeta: error que
 *    empieza con "No se armó ninguna tarjeta" con el árbol, y nada creado. El vacío explícito sí se
 *    acepta (sin categoría, como antes).
 *  - La subcategoría se resuelve DENTRO de la categoría; si vino sola, deduce la categoría; si es de
 *    otra categoría, error con las de la elegida. La edición deja las dos coherentes.
 *  - El resultado del alta (también en "directo") trae `precio_de_venta` = el `final_price` guardado,
 *    o "quedó SIN precio de venta" si no tiene costo ni precio, y `campos_guardados`.
 *  - La búsqueda por código de barras y que_puedo_cargar de `article` traen el árbol.
 *  - La masiva trae hasta 3 ejemplos de precio de venta antes → después, y el "después" es el que
 *    deja la masiva de verdad (se corre el job a mano y se compara). Proponer no deja nada escrito.
 *
 * El dueño es el user 500 del TestingFerreteriaSeeder, que no tiene categorías: cada test crea las
 * suyas dentro de la transacción de EmpresaTestCase. `agente_confianza` es un interruptor global y
 * vuelve a mano en tearDown(), igual que en 51_Modo_directo_y_escalado_Test.
 *
 * 🔴 Ningún test sale a la red (Http::fake() y un servicio de código de barras de mentira).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Alta_con_categoria_y_precio_de_venta_Test extends EmpresaTestCase
{
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array Las columnas globales del dueño que este archivo mueve, como estaban. */
    protected $columnas_originales = [];

    /** @var array<int, ExtencionEmpresa> */
    protected $extensiones_enganchadas = [];

    /** @var Category */
    protected $herramientas;

    /** @var Category */
    protected $electricidad;

    /** @var array<int, string> Archivos de imágenes que el alta con foto dejó en storage/app/public. */
    protected $archivos_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba-p72']);

        Http::fake();

        Storage::fake('local');

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->columnas_originales = [
            'listas_de_precio' => $this->dueno->listas_de_precio,
            'agente_confianza' => $this->dueno->agente_confianza,
            'percentage_gain'  => $this->dueno->percentage_gain,
        ];

        $this->dar_extension('agentes_ia');

        $this->dueno_con(['agente_confianza' => 'cauteloso', 'listas_de_precio' => 0]);

        Catalogo::olvidar();
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

        $this->actuar_como_el_dueno();
    }

    /**
     * @return void
     */
    protected function actuar_como_el_dueno()
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->dueno, 'web');
    }

    /**
     * El árbol de la ferretería de prueba:
     *   Herramientas P72 › Manuales P72, Eléctricas P72, Accesorios P72
     *   Electricidad P72 › Cables P72, Accesorios P72
     *
     * @return void
     */
    protected function con_categorias()
    {
        $this->herramientas = Category::create(['name' => 'Herramientas P72', 'user_id' => $this->dueno->id, 'num' => 972]);
        $this->electricidad = Category::create(['name' => 'Electricidad P72', 'user_id' => $this->dueno->id, 'num' => 973]);

        $this->sub('Manuales P72', $this->herramientas);
        $this->sub('Eléctricas P72', $this->herramientas);
        $this->sub('Accesorios P72', $this->herramientas);
        $this->sub('Cables P72', $this->electricidad);
        $this->sub('Accesorios P72', $this->electricidad);
    }

    /**
     * @param  string  $nombre
     * @param  Category  $categoria
     * @return SubCategory
     */
    protected function sub($nombre, Category $categoria)
    {
        return SubCategory::create([
            'name'        => $nombre,
            'category_id' => $categoria->id,
            'user_id'     => $this->dueno->id,
            'num'         => 970,
        ]);
    }

    /**
     * @param  string  $nombre
     * @param  Category  $categoria
     * @return SubCategory
     */
    protected function sub_de($nombre, Category $categoria)
    {
        return SubCategory::where('user_id', $this->dueno->id)->where('category_id', $categoria->id)->where('name', $nombre)->firstOrFail();
    }

    /**
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'Dame de alta este producto')
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
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

    /**
     * Confirma la tarjeta por el endpoint real.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->actuar_como_el_dueno();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * Los renglones de la tarjeta como [etiqueta => valor].
     *
     * @param  int  $tarjeta_id
     * @return array<string, string>
     */
    protected function renglones($tarjeta_id)
    {
        return array_column(AiMessageAction::find($tarjeta_id)->presentacion['renglones'], 'valor', 'etiqueta');
    }

    /**
     * El resultado guardado de una tarjeta, como array.
     *
     * @param  int  $tarjeta_id
     * @return array
     */
    protected function resultado_de($tarjeta_id)
    {
        return json_decode(json_encode(AiMessageAction::find($tarjeta_id)->resultado), true);
    }

    /**
     * Los nombres de categoría del árbol que vino en una respuesta.
     *
     * @param  array  $arbol
     * @return array<string, array<int, string>>
     */
    protected function por_categoria(array $arbol)
    {
        $por_categoria = [];

        foreach ($arbol as $rama) {
            $por_categoria[$rama['categoria']] = $rama['subcategorias'];
        }

        return $por_categoria;
    }

    // =====================================================================
    // 1. Sin categoría no se arma; el vacío explícito sí
    // =====================================================================

    /**
     * 🔴 LO QUE PASÓ EN DEMO3 (S10). Sin `category_id`, en un negocio con categorías, no queda
     * tarjeta ni artículo: el error empieza con "No se armó ninguna tarjeta" (el prompt dice que ese
     * es para el modelo) y trae el árbol para elegir en la misma vuelta.
     *
     * @test
     */
    public function un_alta_sin_categoria_en_un_negocio_con_categorias_no_se_arma_y_trae_el_arbol()
    {
        $this->con_categorias();

        list($conversation, $assistant) = $this->conversacion('Dame de alta la cinta aisladora, la vendo a 3500');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Cinta aisladora P72', 'price' => 3500],
        ]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringStartsWith('No se armó ninguna tarjeta', (string) $respuesta['error']);
        $this->assertStringContainsString('categoría', (string) $respuesta['error']);

        $arbol = $this->por_categoria($respuesta['opciones']['categorias']);

        $this->assertSame(['Accesorios P72', 'Eléctricas P72', 'Manuales P72'], $arbol['Herramientas P72']);
        $this->assertSame(['Accesorios P72', 'Cables P72'], $arbol['Electricidad P72']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Con el error no queda tarjeta.');
        $this->assertNull(Article::where('user_id', $this->dueno->id)->where('name', 'Cinta aisladora P72')->first());

        // "Sin categoría" a propósito: se arma, sin categoría, como antes.
        foreach (['', 'sin categoría', 0] as $vacio) {

            list($conversation, $assistant) = $this->conversacion('Cargala sin categoría');

            $sin = $this->herramienta($conversation, $assistant, 'proponer_alta', [
                'entidad' => 'article',
                'datos'   => ['name' => 'Cinta aisladora P72 sin', 'price' => 3500, 'category_id' => $vacio],
            ]);

            $this->assertTrue(!empty($sin['ok']), json_encode($sin));

            $tarjeta = AiMessageAction::find($sin['tarjeta_id']);

            $this->assertNull($tarjeta->datos['payload']['category_id']);
        }

        /*
         * 🔴 Un null crudo NO es "sin categoría" (chequeo independiente): es lo que manda un modelo que
         * no eligió nada, y esquivaba la guarda.
         */
        list($conversation, $assistant) = $this->conversacion();

        $con_null = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Cinta aisladora P72 null', 'price' => 3500, 'category_id' => null, 'sub_category_id' => null],
        ]);

        $this->assertFalse($con_null['ok'], json_encode($con_null));
        $this->assertStringStartsWith('No se armó ninguna tarjeta: falta la categoría', (string) $con_null['error']);
    }

    /**
     * 🔴 Con una categoría que tiene subcategorías, sin sub_category_id no se arma: el error trae las
     * subcategorías DE ESA categoría. El vacío explícito ("") sí se acepta, y una categoría sin
     * subcategorías no pide nada.
     *
     * @test
     */
    public function una_categoria_con_subcategorias_exige_la_subcategoria_o_el_vacio_explicito()
    {
        $this->con_categorias();

        $pinturas = Category::create(['name' => 'Pinturas P72', 'user_id' => $this->dueno->id, 'num' => 974]);

        list($conversation, $assistant) = $this->conversacion('Cargame el martillo');

        $sin_sub = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Martillo sin sub P72', 'category_id' => 'Herramientas P72'],
        ]);

        $this->assertFalse($sin_sub['ok'], json_encode($sin_sub));
        $this->assertStringStartsWith('No se armó ninguna tarjeta: falta la subcategoría', (string) $sin_sub['error']);
        $this->assertSame(['Accesorios P72', 'Eléctricas P72', 'Manuales P72'], $sin_sub['opciones']['sub_category_id']);
        $this->assertSame('Martillo sin sub P72', $sin_sub['opciones']['reenvia_igual']['datos']['name']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $vacia = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Martillo sin sub P72', 'category_id' => 'Herramientas P72', 'sub_category_id' => ''],
        ]);

        $this->assertTrue(!empty($vacia['ok']), json_encode($vacia));

        $payload = AiMessageAction::find($vacia['tarjeta_id'])->datos['payload'];

        $this->assertSame((int) $this->herramientas->id, (int) $payload['category_id']);
        $this->assertNull($payload['sub_category_id']);

        $sin_subcategorias = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Látex P72', 'category_id' => 'Pinturas P72'],
        ]);

        $this->assertTrue(!empty($sin_subcategorias['ok']), json_encode($sin_subcategorias));
        $this->assertSame((int) $pinturas->id, (int) AiMessageAction::find($sin_subcategorias['tarjeta_id'])->datos['payload']['category_id']);
    }

    /**
     * 🔴 El reintento no pierde nada: el error de la categoría trae `opciones.reenvia_igual` con la
     * llamada que llegó (datos y extras), y reenviarla sumando la categoría y la subcategoría crea el
     * artículo con la foto y la descripción.
     *
     * @test
     */
    public function el_reintento_con_reenvia_igual_crea_el_articulo_con_la_foto()
    {
        $this->con_categorias();

        list($conversation, $assistant) = $this->conversacion('Me llegaron estos martillos, cargalo');

        // La foto de internet, colgada del assistant en curso (como la deja la búsqueda por código).
        $binario = (string) (new ImageManager())->canvas(12, 12, '#0B84F8')->encode('png');
        $path = 'asistente_imagenes/' . $this->dueno->id . '/' . $assistant->id . '/1.png';

        Storage::disk('local')->put($path, $binario);

        $foto = AiMessageImagen::create([
            'ai_message_id' => $assistant->id,
            'user_id'       => $this->dueno->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/png',
            'bytes'         => strlen($binario),
        ]);

        $error = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Martillo Tramontina P72', 'bar_code' => '7891114091069'],
            'imagen_id'   => $foto->id,
            'descripcion' => 'Martillo de uña con cabo de madera.',
        ]);

        $this->assertFalse($error['ok'], json_encode($error));
        $this->assertStringContainsString('EXACTAMENTE los mismos datos y extras', (string) $error['error']);
        $this->assertNull(AiMessageImagen::find($foto->id)->gestionada_at, 'El error no sella la foto.');

        $reenvia = $error['opciones']['reenvia_igual'];

        $this->assertSame('article', $reenvia['entidad']);
        $this->assertSame((int) $foto->id, (int) $reenvia['imagen_id']);
        $this->assertSame('Martillo de uña con cabo de madera.', $reenvia['descripcion']);
        $this->assertSame(['name' => 'Martillo Tramontina P72', 'bar_code' => '7891114091069'], $reenvia['datos']);

        // El modelo reenvía EXACTAMENTE eso, sumando la categoría y la subcategoría.
        $reenvia['datos']['category_id'] = 'Herramientas P72';
        $reenvia['datos']['sub_category_id'] = 'Manuales P72';

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', $reenvia);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Martillo Tramontina P72')->first();

        $this->assertNotNull($articulo);

        foreach (Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->get() as $image) {
            $this->archivos_a_limpiar[] = basename((string) $image->hosting_url);
        }

        $this->assertSame('7891114091069', $articulo->bar_code);
        $this->assertSame((int) $this->herramientas->id, (int) $articulo->category_id);
        $this->assertSame((int) $this->sub_de('Manuales P72', $this->herramientas)->id, (int) $articulo->sub_category_id);
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count(), 'El artículo nace con la foto.');
        $this->assertSame(1, DB::table('descriptions')->where('article_id', $articulo->id)->count(), 'Y con la descripción.');
    }

    /**
     * Un negocio sin categorías arma el alta como siempre, sin pedir nada.
     *
     * @test
     */
    public function un_negocio_sin_categorias_arma_el_alta_como_antes()
    {
        $this->assertFalse(CategoriasDelNegocioIaHelper::tiene_categorias($this->dueno->id), 'El fixture no tiene categorías.');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Martillo P72 sin categorías', 'cost' => 9000],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('category_id', AiMessageAction::find($respuesta['tarjeta_id'])->datos['payload']);
    }

    // =====================================================================
    // 2. Categoría y subcategoría
    // =====================================================================

    /**
     * Con las dos por nombre, la tarjeta las muestra y el artículo queda con las dos.
     *
     * @test
     */
    public function con_categoria_y_subcategoria_por_nombre_el_articulo_queda_con_las_dos()
    {
        $this->con_categorias();

        list($conversation, $assistant) = $this->conversacion('Cargame el martillo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => [
                'name'            => 'Martillo de uña P72',
                'cost'            => 9000,
                'category_id'     => 'herramientas p72',
                'sub_category_id' => 'Manuales P72',
            ],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $renglones = $this->renglones($respuesta['tarjeta_id']);

        $this->assertSame('Herramientas P72', $renglones['Rubro'], json_encode($renglones));
        $this->assertSame('Manuales P72', $renglones['Sub rubro'], json_encode($renglones));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Martillo de uña P72')->first();

        $this->assertNotNull($articulo);
        $this->assertSame((int) $this->herramientas->id, (int) $articulo->category_id);
        $this->assertSame((int) $this->sub_de('Manuales P72', $this->herramientas)->id, (int) $articulo->sub_category_id);

        // "Accesorios P72" está en las dos categorías: se resuelve DENTRO de la elegida.
        list($conversation, $assistant) = $this->conversacion('Cargame el cable');

        $accesorio = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Prolongador P72', 'category_id' => 'Electricidad P72', 'sub_category_id' => 'Accesorios P72'],
        ]);

        $this->assertTrue(!empty($accesorio['ok']), json_encode($accesorio));
        $this->assertSame(
            (int) $this->sub_de('Accesorios P72', $this->electricidad)->id,
            (int) AiMessageAction::find($accesorio['tarjeta_id'])->datos['payload']['sub_category_id']
        );
    }

    /**
     * Una subcategoría que no es de la categoría elegida corta con las de esa categoría.
     *
     * @test
     */
    public function una_subcategoria_de_otra_categoria_corta_con_las_de_la_elegida()
    {
        $this->con_categorias();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Cable P72', 'category_id' => 'Herramientas P72', 'sub_category_id' => 'Cables P72'],
        ]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringStartsWith('No se armó ninguna tarjeta', (string) $respuesta['error']);
        $this->assertStringContainsString('"Cables P72" no es de la categoría "Herramientas P72"', (string) $respuesta['error']);
        $this->assertSame(['Accesorios P72', 'Eléctricas P72', 'Manuales P72'], $respuesta['opciones']['sub_category_id']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Solo la subcategoría: se deduce la categoría. Si está en dos categorías, opciones.
     *
     * @test
     */
    public function solo_la_subcategoria_deduce_la_categoria_y_si_es_ambigua_da_opciones()
    {
        $this->con_categorias();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Cable bipolar P72', 'sub_category' => 'cables p72'],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $payload = AiMessageAction::find($respuesta['tarjeta_id'])->datos['payload'];

        $this->assertSame((int) $this->electricidad->id, (int) $payload['category_id']);
        $this->assertSame((int) $this->sub_de('Cables P72', $this->electricidad)->id, (int) $payload['sub_category_id']);
        $this->assertSame('Electricidad P72', $this->renglones($respuesta['tarjeta_id'])['Rubro']);

        $ambigua = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Gancho P72', 'sub_category_id' => 'Accesorios P72'],
        ]);

        $this->assertFalse($ambigua['ok'], json_encode($ambigua));
        $this->assertStringStartsWith('No se armó ninguna tarjeta', (string) $ambigua['error']);
        $this->assertEqualsCanonicalizing(
            ['Accesorios P72 (de Herramientas P72)', 'Accesorios P72 (de Electricidad P72)'],
            $ambigua['opciones']['sub_category_id']
        );
    }

    /**
     * La edición deja las dos coherentes: cambiar la categoría saca la subcategoría que no es de la
     * nueva (y lo dice), y nombrar solo una subcategoría de otra categoría mueve también la categoría.
     *
     * @test
     */
    public function la_edicion_deja_categoria_y_subcategoria_coherentes()
    {
        $this->con_categorias();

        $articulo = Article::create([
            'name'            => 'Pinza P72',
            'user_id'         => $this->dueno->id,
            'status'          => 'active',
            'cost'            => 1000,
            'category_id'     => $this->herramientas->id,
            'sub_category_id' => $this->sub_de('Manuales P72', $this->herramientas)->id,
        ]);

        list($conversation, $assistant) = $this->conversacion('Pasala a electricidad');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => $articulo->id,
            'cambios'  => ['category_id' => 'Electricidad P72'],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertArrayHasKey('sub_category_id', $tarjeta->datos['cambios']);
        $this->assertNull($tarjeta->datos['cambios']['sub_category_id']);
        $this->assertStringContainsString('"Manuales P72" no es de la categoría nueva', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('Manuales P72', (string) $respuesta['aviso']);

        $this->confirmar($conversation, $assistant, $tarjeta->id)->assertStatus(200);

        $articulo->refresh();

        $this->assertSame((int) $this->electricidad->id, (int) $articulo->category_id);
        $this->assertNull($articulo->sub_category_id);

        // Solo la subcategoría, y es de otra categoría: la categoría se mueve con ella.
        list($conversation, $assistant) = $this->conversacion('Ponele la subcategoría eléctricas');

        $solo_sub = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => $articulo->id,
            'cambios'  => ['sub_category_id' => 'Eléctricas P72'],
        ]);

        $this->assertTrue(!empty($solo_sub['ok']), json_encode($solo_sub));

        $cambios = AiMessageAction::find($solo_sub['tarjeta_id'])->datos['cambios'];

        $this->assertSame((int) $this->herramientas->id, (int) $cambios['category_id']);
        $this->assertSame((int) $this->sub_de('Eléctricas P72', $this->herramientas)->id, (int) $cambios['sub_category_id']);

        // Con la categoría que cambia, la subcategoría se busca SOLO en la nueva.
        $de_otra = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => $articulo->id,
            'cambios'  => ['category_id' => 'Herramientas P72', 'sub_category_id' => 'Cables P72'],
        ]);

        $this->assertFalse($de_otra['ok'], json_encode($de_otra));
        $this->assertStringStartsWith('No se armó ninguna tarjeta', (string) $de_otra['error']);
    }

    /**
     * Ubicar un registro por nombre para editarlo: la frase entera como siempre; lo que encaja SOLO
     * por palabras vuelve como `faltan` con las opciones, aunque sea uno (elegir mal es grave).
     *
     * @test
     */
    public function la_edicion_por_nombre_que_encaja_solo_por_palabras_pregunta()
    {
        $articulo = Article::create([
            'name'    => 'DUCHA DE MANO METAL GLOA P72',
            'user_id' => $this->dueno->id,
            'status'  => 'active',
            'cost'    => 1000,
        ]);

        list($conversation, $assistant) = $this->conversacion('Subile el costo a la ducha gloa');

        $por_palabras = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => 'ducha gloa p72',
            'cambios'  => ['cost' => 1100],
        ]);

        $this->assertFalse($por_palabras['ok'], json_encode($por_palabras));
        $this->assertCount(1, $por_palabras['faltan']);
        $this->assertSame([['id' => (int) $articulo->id, 'nombre' => 'DUCHA DE MANO METAL GLOA P72']], $por_palabras['opciones']['article']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $por_frase = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => 'metal gloa p72',
            'cambios'  => ['cost' => 1100],
        ]);

        $this->assertTrue(!empty($por_frase['ok']), json_encode($por_frase));
        $this->assertSame((int) $articulo->id, (int) AiMessageAction::find($por_frase['tarjeta_id'])->datos['id']);
    }

    // =====================================================================
    // 3. El precio de venta en el resultado
    // =====================================================================

    /**
     * 🔴 LO QUE PASÓ EN DEMO3 (S11): en "directo", el alta con costo y sin margen deja un precio de
     * venta (el sistema lo calcula), y el resultado lo trae releído: `precio_de_venta` es EXACTAMENTE
     * el `final_price` guardado. `texto` no cambia: el precio va en claves aparte.
     *
     * @test
     */
    public function en_directo_el_alta_con_costo_trae_el_precio_de_venta_guardado()
    {
        $this->dueno_con(['agente_confianza' => 'directo']);

        list($conversation, $assistant) = $this->conversacion('Cargame el martillo, me costó 9000');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Martillo directo P72', 'cost' => 9000],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('confirmada', $respuesta['estado'], 'En directo el alta se ejecuta sola: ' . json_encode($respuesta));

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Martillo directo P72')->first();

        $this->assertNotNull($articulo);

        $final = (float) DB::table('articles')->where('id', $articulo->id)->value('final_price');

        $this->assertGreaterThan(0, $final, 'Con costo, el sistema calcula un precio de venta.');

        $resultado = $this->resultado_de($respuesta['tarjeta_id']);

        $this->assertSame('Artículo Martillo directo P72 creado', $resultado['texto'], 'El texto no cambia.');
        $this->assertEqualsWithDelta($final, (float) $resultado['precio_de_venta'], self::DELTA);
        $this->assertStringContainsString('precio de venta ' . FormatoIaHelper::monto($final), $resultado['precio_de_venta_texto']);
        $this->assertStringContainsString('costo ' . FormatoIaHelper::monto(9000), $resultado['precio_de_venta_texto']);

        // 🔴 Sin margen: el texto lo dice (el martillo de demo3 se vendía al costo más el IIBB).
        $this->assertStringContainsString('SIN margen de ganancia: se vende al costo más impuestos', $resultado['precio_de_venta_texto']);

        // Y lo que quedó guardado en cada campo de la tarjeta, legible.
        $guardados = array_column($resultado['campos_guardados'], 'valor', 'etiqueta');

        $this->assertSame(FormatoIaHelper::monto(9000), $guardados['Costo'], json_encode($guardados));
    }

    /**
     * Revisor de merge (16/10/2026): con la ganancia general de la cuenta (users.percentage_gain) el
     * artículo SÍ tiene margen aunque no tenga uno propio: el texto no puede decir "SIN margen".
     *
     * @test
     */
    public function con_la_ganancia_general_de_la_cuenta_no_dice_sin_margen()
    {
        $this->dueno_con(['agente_confianza' => 'directo', 'percentage_gain' => 30]);

        list($conversation, $assistant) = $this->conversacion('Cargame la pinza, me costó 5000');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Pinza margen general P72', 'cost' => 5000],
        ]);

        $this->assertSame('confirmada', isset($respuesta['estado']) ? $respuesta['estado'] : null, json_encode($respuesta));

        $resultado = $this->resultado_de($respuesta['tarjeta_id']);

        $this->assertStringNotContainsString('SIN margen', (string) $resultado['precio_de_venta_texto']);
    }

    /**
     * Sin costo ni precio, el resultado lo dice: "quedó SIN precio de venta (no tiene costo ni precio)".
     *
     * @test
     */
    public function sin_costo_ni_precio_el_resultado_dice_que_quedo_sin_precio()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Martillo sin precio P72'],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $this->assertNull($confirmacion->json('model.resultado.precio_de_venta'));
        $this->assertSame('quedó SIN precio de venta (no tiene costo ni precio)', $confirmacion->json('model.resultado.precio_de_venta_texto'));
    }

    /**
     * La edición del costo también trae el precio de venta nuevo, releído.
     *
     * @test
     */
    public function la_edicion_del_costo_trae_el_precio_de_venta_nuevo()
    {
        $articulo = Article::create([
            'name'    => 'Llave P72',
            'user_id' => $this->dueno->id,
            'status'  => 'active',
            'cost'    => 1000,
            'iva_id'  => 2,
        ]);

        list($conversation, $assistant) = $this->conversacion('Subile el costo a 2000');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => $articulo->id,
            'cambios'  => ['cost' => 2000],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $final = (float) DB::table('articles')->where('id', $articulo->id)->value('final_price');

        $this->assertGreaterThan(0, $final);
        $this->assertEqualsWithDelta($final, (float) $confirmacion->json('model.resultado.precio_de_venta'), self::DELTA);
        $this->assertSame([['etiqueta' => 'Costo', 'valor' => FormatoIaHelper::monto(2000)]], $confirmacion->json('model.resultado.campos_guardados'));
    }

    // =====================================================================
    // 4. El árbol en la búsqueda por código de barras y en que_puedo_cargar
    // =====================================================================

    /**
     * La búsqueda por código de barras (con un servicio de mentira: no sale a la red) trae el árbol
     * para que el alta salga con categoría y subcategoría en la misma vuelta (S11).
     *
     * @test
     */
    public function la_busqueda_por_codigo_de_barras_trae_las_categorias_del_negocio()
    {
        $this->con_categorias();

        $this->app->bind(BusquedaPorCodigoDeBarrasService::class, function ($app, $parametros) {
            return new ServicioDeCodigoDeBarrasSinRedP72(isset($parametros['owner']) ? $parametros['owner'] : null);
        });

        $r = BusquedaPorCodigoDeBarrasIaHelper::buscar($this->dueno->id, '7891114091069');

        $this->assertSame('Martillo de uña Tramontina 18 mm', $r['nombre'], json_encode($r));
        $this->assertArrayHasKey('categorias_del_negocio', $r);

        $arbol = $this->por_categoria($r['categorias_del_negocio']['categorias']);

        $this->assertSame(['Accesorios P72', 'Eléctricas P72', 'Manuales P72'], $arbol['Herramientas P72']);
        $this->assertStringContainsString('sub_category_id', $r['categorias_del_negocio']['como_se_usa']);

        Http::assertNothingSent();
    }

    /**
     * que_puedo_cargar de `article` con el contexto trae el árbol; sin categorías, la clave no está.
     *
     * @test
     */
    public function que_puedo_cargar_de_article_trae_el_arbol_solo_si_hay_categorias()
    {
        list($conversation, $assistant) = $this->conversacion();

        $sin = $this->herramienta($conversation, $assistant, 'que_puedo_cargar', ['entidad' => 'article']);

        $this->assertArrayNotHasKey('categorias_del_negocio', $sin);

        $this->con_categorias();

        $con = $this->herramienta($conversation, $assistant, 'que_puedo_cargar', ['entidad' => 'article']);

        $arbol = $this->por_categoria($con['categorias_del_negocio']['categorias']);

        $this->assertSame(['Accesorios P72', 'Cables P72'], $arbol['Electricidad P72']);

        // Las demás entidades, como siempre.
        $this->assertArrayNotHasKey('categorias_del_negocio', $this->herramienta($conversation, $assistant, 'que_puedo_cargar', ['entidad' => 'provider']));
    }

    // =====================================================================
    // 5. La masiva con ejemplos de precio
    // =====================================================================

    /**
     * 🔴 LO QUE PASÓ EN DEMO3 (S07/S08): "subile el costo 8 % a lo de Ferretera del Sur". La
     * propuesta trae hasta 3 ejemplos antes → después; proponer NO deja nada escrito; al ejecutar el
     * resultado los repite; y el "después" es EXACTAMENTE lo que deja la masiva (se corre el job a
     * mano), aunque el precio guardado no coincida con la fórmula (como la ducha de demo3).
     *
     * @test
     */
    public function la_masiva_trae_ejemplos_de_precio_y_el_despues_es_el_que_deja_la_masiva()
    {
        $proveedor = Provider::create(['name' => 'Ferretera del Sur P72', 'user_id' => $this->dueno->id, 'num' => 972]);

        $articulos = [];

        foreach (['Ducha P72' => 22415.01, 'Llave P72' => 15000, 'Timbre P72' => 8000, 'Flexible P72' => 5000] as $nombre => $guardado) {
            $articulos[] = Article::create([
                'name'            => $nombre,
                'user_id'         => $this->dueno->id,
                'status'          => 'active',
                'provider_id'     => $proveedor->id,
                'cost'            => 10000,
                'percentage_gain' => 40,
                'iva_id'          => 2,
                // A propósito, un precio guardado que NO es el de la fórmula (como en demo3).
                'final_price'     => $guardado,
            ]);
        }

        list($conversation, $assistant) = $this->conversacion('Ferretera del sur me aumentó un 8 %, subile el costo a todo lo de ellos');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_actualizacion_masiva', [
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Ferretera del Sur P72']],
            'cambios' => [['campo' => 'costo', 'operacion' => 'subir_porcentaje', 'valor' => 8]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertCount(3, $respuesta['ejemplos_de_precio'], 'Hasta tres ejemplos.');
        $this->assertStringStartsWith('Ducha P72: ' . FormatoIaHelper::monto(22415.01) . ' → ', $respuesta['ejemplos_de_precio'][0]);

        // Proponer no dejó nada escrito.
        foreach ($articulos as $articulo) {
            $fila = DB::table('articles')->where('id', $articulo->id)->first();

            $this->assertEqualsWithDelta(10000, (float) $fila->cost, self::DELTA, 'Proponer no puede tocar el costo.');
            $this->assertEqualsWithDelta((float) $articulo->final_price, (float) $fila->final_price, self::DELTA, 'Proponer no puede tocar el precio.');
        }

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $ejemplos = $tarjeta->datos[PropuestaActualizacionMasivaIaHelper::CLAVE_EJEMPLOS];

        $this->assertCount(3, $ejemplos);
        $this->assertSame((int) $articulos[0]->id, (int) $ejemplos[0]['article_id']);
        $this->assertEqualsWithDelta(22415.01, (float) $ejemplos[0]['antes'], self::DELTA);
        $this->assertStringContainsString('Ejemplos de precio de venta: Ducha P72', (string) $tarjeta->presentacion['aviso']);

        // Confirmar: el resultado repite los ejemplos.
        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $confirmacion = $this->confirmar($conversation, $assistant, $tarjeta->id);

        $confirmacion->assertStatus(200);
        $this->assertSame($respuesta['ejemplos_de_precio'], $confirmacion->json('model.resultado.ejemplos_de_precio'));

        // El job, a mano: lo que haría el worker. El "después" de cada ejemplo es lo que quedó.
        Notification::fake();

        $masiva = MasiveUpdate::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        MasiveUpdateHelper::process_update($masiva->fresh());

        $this->assertSame('completed', $masiva->fresh()->status);

        foreach ($ejemplos as $ejemplo) {
            $quedo = (float) DB::table('articles')->where('id', $ejemplo['article_id'])->value('final_price');

            $this->assertEqualsWithDelta($quedo, (float) $ejemplo['despues'], self::DELTA, 'El ejemplo de ' . $ejemplo['nombre'] . ' no es lo que dejó la masiva.');
        }

        $this->assertEqualsWithDelta(10800, (float) DB::table('articles')->where('id', $articulos[0]->id)->value('cost'), self::DELTA);
    }

    /**
     * Con un cambio que no se puede estimar (categoría), no hay ejemplos y el aviso es el de siempre.
     *
     * @test
     */
    public function una_masiva_que_no_mueve_precios_no_trae_ejemplos()
    {
        $this->con_categorias();

        $proveedor = Provider::create(['name' => 'Proveedor sin ejemplos P72', 'user_id' => $this->dueno->id, 'num' => 973]);

        Article::create([
            'name'        => 'Tornillo P72',
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'provider_id' => $proveedor->id,
            'cost'        => 100,
            'final_price' => 150,
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_actualizacion_masiva', [
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Proveedor sin ejemplos P72']],
            'cambios' => [['campo' => 'categoria', 'operacion' => 'asignar', 'valor' => 'Herramientas P72']],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('ejemplos_de_precio', $respuesta);
        $this->assertSame(PropuestaActualizacionMasivaIaHelper::AVISO, AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['aviso']);
    }
}

/**
 * El servicio de código de barras sin red: el producto "está" en una base abierta y la redacción
 * vuelve armada. Todo lo demás (validar el código, armar el resultado) es el código de producción.
 */
class ServicioDeCodigoDeBarrasSinRedP72 extends BusquedaPorCodigoDeBarrasService
{
    public function buscar_en_bases_abiertas($ean)
    {
        return [
            'base'       => 'open_food_facts',
            'url'        => 'https://world.openfoodfacts.org/product/' . $ean,
            'imagen_url' => null,
            'producto'   => ['product_name' => 'Martillo'],
        ];
    }

    public function redactar_desde_base_abierta($ean, array $hallazgo)
    {
        return [
            'nombre'      => 'Martillo de uña Tramontina 18 mm',
            'marca'       => 'Tramontina',
            'descripcion' => 'Martillo de uña con cabo de madera.',
            'body'        => null,
            'modelo'      => null,
        ];
    }
}
