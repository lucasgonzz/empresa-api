<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\MarcaDeLaFotoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaGenericaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TranscripcionDeFotosIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-marca-de-foto-y-envio-sin-bloque (20/10/2026) — una marca que sale SOLO de leer una
 * foto no se crea sin el sí del dueño.
 *
 * El caso real (repetición de la segunda prueba contra demo3, 20/10/2026): foto de una pinza que dice
 * «KNIPEX 03-200 GERMANY» + «dame de alta esta pinza universal, la vendo a 18500. ponele esta foto». La
 * transcripción de la foto (Flash) leyó «KWB» y el asistente creó la marca nueva KWB (brands 28) y el
 * artículo 51 «Pinza universal KWB 200mm» sin preguntar, y lo contó como hecho.
 *
 * Lo que fija este archivo:
 *   - 🔴 Con una foto en la conversación, una marca que no existe y que el dueño NO escribió (KWB) no se
 *     crea: el alta del artículo le dice al modelo que PREGUNTE («En la foto leo la marca KWB y no la
 *     tenés cargada: ¿la creo?») o arme el artículo sin marca, con `reenvia_igual` para no perder nada.
 *   - 🔴 Si igual propone la marca, la tarjeta queda esperando el sí AUN EN «directo» (`requiere_confirmacion`),
 *     con el aviso, y la marca no existe hasta que la persona confirme.
 *   - Sigue como siempre cuando la marca la escribió el dueño (en el acto en «directo»), cuando la trajo
 *     la búsqueda por código de barras, cuando contestó «sí» a la pregunta que nombraba la marca, y
 *     cuando no hay ninguna foto en la conversación.
 *   - Un «sí» a otra pregunta no vale. El «sí» suelto a la tarjeta de la marca la confirma.
 *   - Una marca que ya existe no se toca (se asigna sola, como siempre).
 *   - El prompt de transcripción pide la marca letra por letra y marca la duda.
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Marca_leida_de_una_foto_se_pregunta_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var array */
    protected $columnas_originales = [];

    /** @var array<int, ExtencionEmpresa> */
    protected $extensiones_enganchadas = [];

    /** @var Category */
    protected $herramientas;

    /** @var SubCategory */
    protected $pinzas;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-p87']);

        Http::fake();

        Storage::fake('local');

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->columnas_originales = [
            'listas_de_precio' => $this->dueno->listas_de_precio,
            'agente_confianza' => $this->dueno->agente_confianza,
        ];

        $this->dar_extension('asistente_ia');

        $this->dueno_con(['agente_confianza' => 'directo', 'listas_de_precio' => 0]);

        Catalogo::olvidar();

        $this->herramientas = Category::create(['name' => 'Herramientas P87', 'user_id' => $this->dueno->id, 'num' => 987]);

        $this->pinzas = SubCategory::create([
            'name'        => 'Pinzas P87',
            'category_id' => $this->herramientas->id,
            'user_id'     => $this->dueno->id,
            'num'         => 987,
        ]);
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update($this->columnas_originales);

        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

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

    protected function dueno_con(array $columnas)
    {
        User::where('id', $this->dueno->id)->update($columnas);

        $this->dueno = User::find($this->dueno->id);

        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->dueno, 'web');
    }

    /**
     * La conversación del caso real: el dueño manda la foto de la pinza con el pedido y el assistant
     * está pendiente. `$con_foto = false` arma la misma charla sin foto.
     *
     * @param  string  $pedido
     * @param  bool  $con_foto
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'dame de alta esta pinza universal, la vendo a 18500. ponele esta foto', $con_foto = true)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        $del_dueno = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
        ]);

        if ($con_foto) {

            $binario = (string) (new ImageManager())->canvas(12, 12, '#999999')->encode('png');
            $path = 'asistente_imagenes/' . $this->dueno->id . '/' . $del_dueno->id . '/1.png';

            Storage::disk('local')->put($path, $binario);

            AiMessageImagen::create([
                'ai_message_id' => $del_dueno->id,
                'user_id'       => $this->dueno->id,
                'orden'         => 1,
                'path'          => $path,
                'mime'          => 'image/png',
                'bytes'         => strlen($binario),
            ]);
        }

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * El alta que armó el modelo en el caso real, con la marca que leyó de la foto.
     *
     * @param  string  $marca
     * @return array
     */
    protected function alta_de_la_pinza($marca = 'KWB')
    {
        return [
            'entidad'                     => 'article',
            'datos'                       => [
                'name'            => 'Pinza universal KWB 200mm',
                'price'           => 18500,
                'category_id'     => 'Herramientas P87',
                'sub_category_id' => 'Pinzas P87',
                'brand_id'        => $marca,
            ],
            'con_foto_de_la_conversacion' => true,
            'descripcion'                 => 'Pinza universal de 200 mm.',
        ];
    }

    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    protected function marcas_llamadas($nombre)
    {
        return Brand::where('user_id', $this->dueno->id)->where('name', $nombre)->count();
    }

    // ---------------------------------------------------------------------
    // El caso real
    // ---------------------------------------------------------------------

    /**
     * 🔴 KNIPEX leído como KWB: el alta del artículo no manda a crear la marca, manda a PREGUNTAR, con el
     * reenvío completo para no perder la foto, la descripción, la categoría y el precio.
     *
     * @test
     */
    public function una_marca_que_sale_solo_de_la_foto_se_pregunta_en_vez_de_crearse()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_pinza('KWB'));

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));

        $si_no_esta = $respuesta['opciones']['si_no_esta'];

        $this->assertTrue(MarcaDeLaFotoIaHelper::es_la_instruccion_de_preguntar($si_no_esta));
        $this->assertNotSame(PropuestaGenericaIaHelper::SI_NO_ESTA_LA_MARCA, $si_no_esta);
        $this->assertStringContainsString('En la foto leo la marca KWB y no la tenés cargada: ¿la creo?', $si_no_esta);
        $this->assertStringContainsString('NO la crees ahora', $si_no_esta);

        $reenvia = $respuesta['opciones']['reenvia_igual'];

        $this->assertSame('article', $reenvia['entidad']);
        $this->assertArrayNotHasKey('brand_id', $reenvia['datos'], 'La marca es lo que falta; lo demás viaja.');
        $this->assertSame(18500, $reenvia['datos']['price']);
        $this->assertSame('Pinza universal de 200 mm.', $reenvia['descripcion']);
        $this->assertTrue($reenvia['con_foto_de_la_conversacion']);

        $this->assertSame(0, $this->marcas_llamadas('KWB'), 'No se creó la marca.');
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Ni una tarjeta.');
    }

    /**
     * Si el modelo igual propone la marca (en «directo» se ejecutaría sola), queda una tarjeta esperando el
     * sí: la marca no existe, la respuesta lo dice y trae el aviso con qué preguntar.
     *
     * @test
     */
    public function proponer_la_marca_de_la_foto_en_directo_deja_una_tarjeta_esperando_el_si()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'KWB']]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue($respuesta['requiere_confirmacion']);
        $this->assertArrayNotHasKey('estado', $respuesta, 'No se ejecutó: ' . json_encode($respuesta));
        $this->assertStringContainsString('En la foto leo la marca KWB y no la tenés cargada: ¿la creo?', $respuesta['aviso']);
        $this->assertStringContainsString('NO armes el artículo', $respuesta['aviso']);
        $this->assertStringContainsString('cancelar_carga_pendiente', $respuesta['aviso']);

        $tarjeta = AiMessageAction::findOrFail($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame(0, $this->marcas_llamadas('KWB'), 'Hasta el sí no existe.');

        // Y con la tarjeta esperando, el alta del artículo no vuelve a pedir crear la marca ni deja otra tarjeta.
        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_pinza('KWB'));

        $this->assertFalse($alta['ok'], json_encode($alta));
        $this->assertStringContainsString('todavía no existe', (string) $alta['error']);
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * El «sí» suelto del dueño a la tarjeta de la marca (por WhatsApp) la confirma: la marca recién se crea ahí.
     *
     * @test
     */
    public function el_si_del_duenio_a_la_tarjeta_de_la_marca_la_crea()
    {
        list($conversation, $propuso) = $this->conversacion();

        $propuso->canal = AiMessage::CANAL_WHATSAPP;
        $propuso->save();

        $respuesta = $this->herramienta($conversation, $propuso, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'KWB']]);

        $propuso->estado = 'listo';
        $propuso->contenido = 'En la foto leo la marca KWB y no la tenés cargada: ¿la creo?';
        $propuso->save();

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'sí',
            'estado'             => 'listo',
            'canal'              => AiMessage::CANAL_WHATSAPP,
        ]);

        $siguiente = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
            'canal'                => AiMessage::CANAL_WHATSAPP,
        ]);

        $confirmacion = ConfirmacionDeterministaIaHelper::quizas_confirmar($conversation, $siguiente);

        $this->assertNotNull($confirmacion, 'El sí confirma la única tarjeta pendiente.');
        $this->assertSame((int) $respuesta['tarjeta_id'], $confirmacion['tarjeta_id']);
        $this->assertSame(1, $this->marcas_llamadas('KWB'));
    }

    // ---------------------------------------------------------------------
    // Lo que sigue como siempre
    // ---------------------------------------------------------------------

    /**
     * Si el dueño escribió la marca, se crea en el acto en «directo» (hasta con otro nombre completo).
     *
     * @test
     */
    public function una_marca_que_escribio_el_duenio_se_crea_en_el_acto()
    {
        list($conversation, $assistant) = $this->conversacion('dame de alta esta pinza knipex universal, la vendo a 18500. ponele esta foto');

        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_pinza('Knipex'));

        $this->assertFalse($alta['ok']);
        $this->assertSame(PropuestaGenericaIaHelper::SI_NO_ESTA_LA_MARCA, $alta['opciones']['si_no_esta'], 'La dijo el dueño: se crea sin preguntar.');

        $marca = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'Knipex']]);

        $this->assertSame('confirmada', $marca['estado'], json_encode($marca));
        $this->assertArrayNotHasKey('requiere_confirmacion', $marca);
        $this->assertSame(1, $this->marcas_llamadas('Knipex'));
    }

    /**
     * Sin ninguna foto en la conversación no hay nada que se haya podido leer mal: como hasta hoy.
     *
     * @test
     */
    public function sin_foto_en_la_conversacion_la_marca_se_crea_como_siempre()
    {
        list($conversation, $assistant) = $this->conversacion('dame de alta una pinza universal, la vendo a 18500', false);

        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_pinza('Wadfow'));

        $this->assertSame(PropuestaGenericaIaHelper::SI_NO_ESTA_LA_MARCA, $alta['opciones']['si_no_esta']);

        $marca = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'Wadfow']]);

        $this->assertSame('confirmada', $marca['estado'], json_encode($marca));
        $this->assertSame(1, $this->marcas_llamadas('Wadfow'));
    }

    /**
     * La marca que devolvió la búsqueda por código de barras (base abierta o web) no es una lectura de la
     * foto: se crea sin preguntar (el caso de la cinta Stanley, N7).
     *
     * @test
     */
    public function la_marca_que_trajo_el_codigo_de_barras_se_crea_sin_preguntar()
    {
        list($conversation, $assistant) = $this->conversacion('me llegaron estas cintas metricas, cargalas');

        $antes = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_pinza('Stanley'));

        $this->assertTrue(MarcaDeLaFotoIaHelper::es_la_instruccion_de_preguntar($antes['opciones']['si_no_esta']), 'Sin la búsqueda, es una lectura de la foto.');

        MarcaDeLaFotoIaHelper::anotar_de_internet($conversation, 'STANLEY');

        $despues = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_pinza('Stanley'));

        $this->assertSame(PropuestaGenericaIaHelper::SI_NO_ESTA_LA_MARCA, $despues['opciones']['si_no_esta']);

        $marca = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'Stanley']]);

        $this->assertSame('confirmada', $marca['estado'], json_encode($marca));
    }

    /**
     * El «sí» a la pregunta que nombraba la marca autoriza crearla; el «sí» a otra pregunta, no.
     *
     * @test
     */
    public function el_si_a_la_pregunta_de_la_marca_autoriza_y_el_si_a_otra_cosa_no()
    {
        list($conversation, $primero) = $this->conversacion();

        $primero->estado = 'listo';
        $primero->contenido = 'En la foto leo la marca KWB y no la tenés cargada: ¿la creo?';
        $primero->save();

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'sí', 'estado' => 'listo']);

        $segundo = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        $marca = $this->herramienta($conversation, $segundo, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'KWB']]);

        $this->assertSame('confirmada', $marca['estado'], json_encode($marca));
        $this->assertSame(1, $this->marcas_llamadas('KWB'));

        // Un sí a una pregunta que no nombra la marca no vale.
        list($otra, $uno) = $this->conversacion();

        $uno->estado = 'listo';
        $uno->contenido = '¿Querés que le ponga el precio de lista?';
        $uno->save();

        AiMessage::create(['ai_conversation_id' => $otra->id, 'rol' => 'user', 'contenido' => 'sí', 'estado' => 'listo']);

        $dos = AiMessage::create(['ai_conversation_id' => $otra->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        $sin_permiso = $this->herramienta($otra, $dos, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'Bremen']]);

        $this->assertTrue($sin_permiso['requiere_confirmacion'], json_encode($sin_permiso));
        $this->assertSame(0, $this->marcas_llamadas('Bremen'));
    }

    /**
     * Una marca que YA existe en el negocio se asigna sola, como siempre, aunque salga de la foto.
     *
     * @test
     */
    public function una_marca_que_ya_existe_se_asigna_sin_preguntar()
    {
        $existente = Brand::create(['name' => 'KNIPEX P87', 'user_id' => $this->dueno->id, 'num' => 987]);

        list($conversation, $assistant) = $this->conversacion();

        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', $this->alta_de_la_pinza('knipex p87'));

        $this->assertTrue($alta['ok'], json_encode($alta));
        $this->assertSame('confirmada', $alta['estado'], json_encode($alta));
        $this->assertSame((int) $existente->id, (int) \DB::table('articles')->where('user_id', $this->dueno->id)->where('name', 'Pinza universal KWB 200mm')->value('brand_id'));
    }

    /**
     * Revisión independiente (20/10/2026): una palabra genérica del pedido («universal») no prueba que el
     * dueño haya nombrado la marca «Universal Tools»; y «sí, creala» es un sí a la pregunta de la marca.
     *
     * @test
     */
    public function las_palabras_genericas_no_autorizan_y_un_si_creala_si()
    {
        list($conversation, $assistant) = $this->conversacion();

        $generica = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'Universal Tools']]);

        $this->assertTrue($generica['requiere_confirmacion'], 'Solo «universal» (genérica) en el pedido: pregunta. ' . json_encode($generica));

        list($otra, $primero) = $this->conversacion();

        $primero->estado = 'listo';
        $primero->contenido = 'En la foto leo la marca KWB y no la tenés cargada: ¿la creo?';
        $primero->save();

        AiMessage::create(['ai_conversation_id' => $otra->id, 'rol' => 'user', 'contenido' => 'si, creala', 'estado' => 'listo']);

        $segundo = AiMessage::create(['ai_conversation_id' => $otra->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        $marca = $this->herramienta($otra, $segundo, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'KWB']]);

        $this->assertSame('confirmada', $marca['estado'], json_encode($marca));
    }

    // ---------------------------------------------------------------------
    // La lectura de la foto y el prompt
    // ---------------------------------------------------------------------

    /**
     * El prompt de la transcripción pide la marca letra por letra y marca la duda; el del asistente dice
     * que una marca leída de una foto se pregunta.
     *
     * @test
     */
    public function los_prompts_piden_leer_la_marca_letra_por_letra_y_preguntar_la_que_sale_de_la_foto()
    {
        $this->assertStringContainsString('DELETREALA letra por letra', TranscripcionDeFotosIaHelper::PROMPT_DE_TRANSCRIPCION);
        $this->assertStringNotContainsString('KNIPEX', TranscripcionDeFotosIaHelper::PROMPT_DE_TRANSCRIPCION, 'El ejemplo del prompt no es la foto del caso.');
        $this->assertStringContainsString('Marca: [dudosa]', TranscripcionDeFotosIaHelper::PROMPT_DE_TRANSCRIPCION);

        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno, true, false);

        $this->assertStringContainsString('ya no se crea sola', $prompt);
        $this->assertStringContainsString('En la foto leo la marca ACMEX y no la tenés cargada: ¿la creo?', $prompt);
    }
}
