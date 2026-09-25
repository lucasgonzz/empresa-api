<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\TextoFinalIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Description;
use App\Models\ExtencionEmpresa;
use App\Models\Image;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-fotos-barras-y-compras (24/9/2026) — A3, A4 y A5.
 *
 * A3: el texto que le llega a la persona sin el razonamiento filtrado. El caso real es el msg 136 de
 * demo3, que llegó por WhatsApp con un párrafo en inglés que nombraba confirmar_carga_pendiente.
 *
 * A4: `cost_in_dollars` en el alta de un artículo. En demo3 (conv 11) el dueño pidió "costo en
 * dólares de diez dólares" y el asistente contestó que el alta no tenía ese campo: estaba en
 * `solo_lectura` del catálogo, aunque la pantalla lo carga.
 *
 * A5: el alta de un artículo con su foto y su descripción en UNA tarjeta, sea la foto que mandó el
 * dueño o la que encontró en internet la búsqueda por código de barras (colgada del mensaje del
 * asistente). Todo por el mismo camino de la pantalla: ArticleController::store para el alta, los
 * cuatro efectos de ImageController para la foto y una fila de `descriptions` para la descripción.
 *
 * Corre sobre el fixture de la ferretería, igual que 35_Cargas_genericas_de_punta_a_punta_Test.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Texto_final_costo_usd_y_alta_con_foto_Test extends EmpresaTestCase
{
    /** El texto REAL del msg 136 de demo3 (24/9/2026), tal cual llegó al WhatsApp del dueño. */
    const TEXTO_MSG_136 = "Volví a armar la asignación de la foto para \"Botella Stanley de aluminio\". Confirmala y queda publicada en la tienda.\n\nConfirmation needed; no report state until confirmar_carga_pendiente returns. Let me tell the user to confirm.\n\nDejé la carga preparada: le asigna a \"Botella Stanley de aluminio\" la foto que me mandaste. ¿La registro?";

    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    /** Archivos que la asignación de la foto dejó en storage/app/public. */
    protected $archivos_a_limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Storage::fake('local');

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        // En "cauteloso" el alta deja tarjeta y se confirma por el endpoint de la pantalla.
        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'cauteloso']);

        $this->service = new AsistenteIaService();

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

        parent::tearDown();
    }

    /**
     * Conversación del dueño con su pedido y el assistant pendiente con las acciones habilitadas.
     *
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage, 2: AiMessage}
     */
    protected function conversacion($pedido = 'Cargame este producto')
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

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant, $del_dueno];
    }

    /**
     * Una foto de verdad en el disco falso, colgada de un mensaje.
     *
     * @param  AiMessage  $mensaje
     * @return AiMessageImagen
     */
    protected function foto(AiMessage $mensaje)
    {
        $binario = (string) (new ImageManager())->canvas(12, 12, '#0B84F8')->encode('png');

        $path = 'asistente_imagenes/' . $this->dueno->id . '/' . $mensaje->id . '/1.png';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->dueno->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/png',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * Llama a una herramienta por el mismo camino que el loop del servicio.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param string $herramienta
     * @param array $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Confirma la tarjeta por el endpoint de la pantalla.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param int $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * @param  Article  $articulo
     * @return void
     */
    protected function anotar_archivos(Article $articulo)
    {
        foreach (Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->get() as $image) {
            $this->archivos_a_limpiar[] = basename((string) $image->hosting_url);
        }
    }

    // ------------------------------------------------------------------------------------ A3

    /**
     * 🔴 El texto real del msg 136: pierde SÓLO el párrafo en inglés que nombra la herramienta.
     *
     * @test
     */
    public function el_texto_del_msg_136_pierde_solo_el_parrafo_en_ingles()
    {
        $limpio = TextoFinalIaHelper::sanear(self::TEXTO_MSG_136, ['confirmar_carga_pendiente', 'proponer_foto_articulo']);

        $this->assertSame(
            "Volví a armar la asignación de la foto para \"Botella Stanley de aluminio\". Confirmala y queda publicada en la tienda.\n\n"
            . "Dejé la carga preparada: le asigna a \"Botella Stanley de aluminio\" la foto que me mandaste. ¿La registro?",
            $limpio
        );
    }

    /**
     * El mismo párrafo cae aunque no nombre ninguna herramienta, sólo por ser inglés; un nombre de
     * producto en inglés adentro de una oración en castellano se queda; y una línea de sistema cae.
     *
     * @test
     */
    public function el_ingles_cae_por_idioma_y_un_nombre_propio_en_ingles_se_queda()
    {
        $this->assertTrue(TextoFinalIaHelper::es_razonamiento_filtrado('Let me tell the user to confirm the card first.', []));

        $this->assertFalse(TextoFinalIaHelper::es_razonamiento_filtrado('Te cargué la botella The North Face de aluminio en la categoría Camping.', []));

        $this->assertTrue(TextoFinalIaHelper::es_razonamiento_filtrado('[Tarjeta #12 · Gasto · estado: confirmada]', []));
    }

    /**
     * 🔴 Los TRES textos que la primera versión (contar palabras inglesas) borraba en el chequeo
     * adversarial del 24/9/2026: una lista con modelos de notebooks, una lista numerada con nombres
     * en inglés y marcas en inglés sueltas. Son datos: ninguno se toca.
     *
     * @test
     */
    public function los_datos_en_ingles_y_las_listas_no_se_tocan()
    {
        $herramientas = ['confirmar_carga_pendiente', 'consultar_stock_de_articulos'];

        $notebooks = "Tenés 3 notebooks con stock:\n- Lenovo IdeaPad Core i5 (4 u.)\n- HP 15 Core i7 (2 u.)\n- Dell Inspiron 3520 Core i3 (1 u.)\n\n¿Querés que te pase los precios?";
        $this->assertSame($notebooks, TextoFinalIaHelper::sanear($notebooks, $herramientas));

        $numerada = "1. Cable USB to Lightning — 12 u.\n2. Funda for iPhone 15 — 3 u.\n3. Charger for the car — 5 u.";
        $this->assertSame($numerada, TextoFinalIaHelper::sanear($numerada, $herramientas));

        $marcas = 'Just For Men, Old Spice After Shave';
        $this->assertSame($marcas, TextoFinalIaHelper::sanear($marcas, $herramientas));

        // Un renglón de lista no cae ni aunque traiga un marcador: en la lista están los datos.
        $lista_con_marcador = "Te dejo lo que encontré:\n- Let Me Be Kids, remera talle 8 — 2 u.";
        $this->assertSame($lista_con_marcador, TextoFinalIaHelper::sanear($lista_con_marcador, $herramientas));
    }

    /**
     * ⚠️ Segundo chequeo adversarial (24/9/2026): datos con marcadores en inglés que la versión por
     * renglón todavía borraba (tienen UNA palabra española o una tilde), y razonamiento que dejaba
     * pasar (pegado a una oración en castellano, o en una viñeta con el nombre de una herramienta).
     *
     * @test
     */
    public function por_oracion_se_quedan_los_datos_y_se_va_el_razonamiento()
    {
        $herramientas = ['confirmar_carga_pendiente', 'proponer_alta'];

        foreach ([
            'Stock de Let\'s Go Naranja 1L: 12 u.',
            'Encontré: I Will Survive (DVD) — 3 u.',
            'Precio del Now I Know (libro): $8000',
        ] as $dato) {
            $this->assertSame($dato, TextoFinalIaHelper::sanear($dato, $herramientas), '"' . $dato . '" es un dato.');
        }

        $this->assertSame(
            'Te dejé la tarjeta de la compra.',
            TextoFinalIaHelper::sanear('Te dejé la tarjeta de la compra. Let me tell the user to confirm it.', $herramientas),
            'La oración en inglés pegada a una en castellano se va sola.'
        );

        $this->assertSame(
            "Te armé la compra:\n- Proveedor: Distribuidora Sur",
            TextoFinalIaHelper::sanear("Te armé la compra:\n- Need to call confirmar_carga_pendiente\n- Proveedor: Distribuidora Sur", $herramientas),
            'Una viñeta que nombra una herramienta se va; la lista de datos se queda.'
        );

        // Y los de antes siguen igual: el msg 136 pierde sólo su párrafo en inglés.
        $this->assertSame(
            "Volví a armar la asignación de la foto para \"Botella Stanley de aluminio\". Confirmala y queda publicada en la tienda.\n\n"
            . "Dejé la carga preparada: le asigna a \"Botella Stanley de aluminio\" la foto que me mandaste. ¿La registro?",
            TextoFinalIaHelper::sanear(self::TEXTO_MSG_136, $herramientas)
        );
    }

    /**
     * Si limpiar deja la respuesta VACÍA y el sistema ya confirmó una carga por el "sí" de la
     * persona, la respuesta es el resultado de esa carga (no el razonamiento en inglés original).
     *
     * @test
     */
    public function si_limpiar_deja_vacio_despues_de_una_confirmacion_va_el_resultado()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'end_turn',
                'content'     => [
                    ['type' => 'text', 'text' => 'Confirmation needed; let me tell the user it is done.'],
                ],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 20],
            ], 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $propone) = $this->conversacion('Cargá la yerba zz-r4');

        $respuesta = $this->herramienta($conversation, $propone, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Yerba zz-r4'],
        ]);

        $propone->contenido = 'Te dejé la tarjeta, ¿la registro?';
        $propone->estado = 'listo';
        $propone->save();

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Dale', 'estado' => 'listo']);

        $contestando = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        $texto = $this->service->responder($conversation, $contestando);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertStringStartsWith('Listo', $texto);
        $this->assertStringContainsString('Yerba zz-r4', $texto);
        $this->assertStringNotContainsString('Confirmation needed', $texto);
    }

    /**
     * Un marcador de razonamiento adentro de una oración en castellano (dos o más palabras
     * españolas) no alcanza para sacarla: es una cita, no un pensamiento en voz alta.
     *
     * @test
     */
    public function un_marcador_adentro_de_una_oracion_en_castellano_se_queda()
    {
        $this->assertFalse(TextoFinalIaHelper::es_razonamiento_filtrado('El libro se llama "Let me go" y queda uno en la tienda.', []));
        $this->assertTrue(TextoFinalIaHelper::es_razonamiento_filtrado('I need to check the stock first.', []));
    }

    /**
     * Si limpiar dejara la respuesta vacía, se manda la original: un mensaje vacío no se puede mandar.
     *
     * @test
     */
    public function si_no_queda_nada_se_devuelve_el_original()
    {
        $solo_ingles = 'Confirmation needed; let me tell the user to confirm.';

        $this->assertSame($solo_ingles, TextoFinalIaHelper::sanear($solo_ingles, []));
    }

    /**
     * De punta a punta por el loop: el proveedor devuelve el texto del msg 136 y responder()
     * devuelve el limpio.
     *
     * @test
     */
    public function el_loop_devuelve_el_texto_limpio()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'end_turn',
                'content'     => [
                    ['type' => 'text', 'text' => self::TEXTO_MSG_136],
                ],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 80],
            ], 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion('Ponele la foto a la botella');

        $texto = $this->service->responder($conversation, $assistant);

        $this->assertStringNotContainsString('Confirmation needed', $texto);
        $this->assertStringNotContainsString('confirmar_carga_pendiente', $texto);
        $this->assertStringContainsString('Volví a armar la asignación', $texto);
        $this->assertStringContainsString('¿La registro?', $texto);
    }

    /**
     * El razonamiento en su PROPIO bloque de texto, sin saltos de línea: como los bloques se unen
     * sin separador, sin el filtro por bloque de extract_response_text quedaría soldado al párrafo
     * en español y el saneo por párrafo no lo vería.
     *
     * @test
     */
    public function el_razonamiento_en_un_bloque_aparte_sin_saltos_se_descarta()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'end_turn',
                'content'     => [
                    ['type' => 'text', 'text' => 'Volví a armar la asignación de la foto.'],
                    ['type' => 'text', 'text' => 'Confirmation needed; no report state until confirmar_carga_pendiente returns. Let me tell the user to confirm.'],
                    ['type' => 'text', 'text' => ' ¿La registro?'],
                ],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 80],
            ], 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion('Ponele la foto a la botella');

        $texto = $this->service->responder($conversation, $assistant);

        $this->assertSame('Volví a armar la asignación de la foto. ¿La registro?', $texto);
    }

    // ------------------------------------------------------------------------------------ A4

    /**
     * `cost_in_dollars` es un campo del alta de artículos, con la explicación para el modelo.
     *
     * @test
     */
    public function el_costo_en_dolares_esta_en_que_puedo_cargar_con_su_explicacion()
    {
        $catalogo = Catalogo::que_puedo_cargar('article');

        $campo = null;

        foreach ($catalogo['campos'] as $fila) {
            if ($fila['campo'] === 'cost_in_dollars') {
                $campo = $fila;
            }
        }

        $this->assertNotNull($campo, 'cost_in_dollars tiene que ofrecerse en el alta de artículos.');
        $this->assertContains(Catalogo::OP_ALTA, $campo['operaciones']);
        $this->assertStringContainsString('dólar', $campo['descripcion']);

        $this->assertNotContains('provider_cost_in_dollars', array_column($catalogo['campos'], 'campo'), 'El del proveedor sigue siendo de solo lectura.');
    }

    /**
     * 🔴 El caso de conv 11: "costo en dólares de diez dólares". El artículo queda con la marca y el
     * precio sale cotizado al dólar del negocio, por ArticleController::store.
     *
     * @test
     */
    public function el_alta_con_costo_en_dolares_guarda_la_marca_y_cotiza_el_precio()
    {
        User::where('id', $this->dueno->id)->update(['dollar' => 1000, 'cotizar_precios_en_dolares' => 1]);

        list($conversation, $assistant) = $this->conversacion('Cargá la Botella zz-a4, costo en dólares de diez dólares');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => [
                'name'            => 'Botella zz-a4',
                'cost'            => 10,
                'cost_in_dollars' => 'si',
                'percentage_gain' => 50,
                'aplicar_iva'     => 'no',
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // La tarjeta dice el costo EN DÓLARES: "$ 10" haría confirmar diez pesos.
        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $valores = array_column($tarjeta->presentacion['renglones'], 'valor', 'etiqueta');
        $this->assertSame('US$ 10,00', $valores['Costo'], json_encode($tarjeta->presentacion['renglones']));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Botella zz-a4')->first();

        $this->assertNotNull($articulo);
        $this->assertEquals(1, (int) $articulo->cost_in_dollars, 'La marca de costo en dólares tiene que quedar guardada.');
        $this->assertGreaterThanOrEqual(10000, (float) $articulo->final_price, 'El precio sale cotizado: 10 dólares a 1000 son 10.000 pesos antes del margen.');
    }

    // ------------------------------------------------------------------------------------ A5

    /**
     * 🔴 El alta con la foto que mandó el dueño: UNA tarjeta, y al confirmar el artículo, la fila en
     * `images` con los efectos de la pantalla y la foto sellada.
     *
     * @test
     */
    public function el_alta_con_la_foto_de_la_conversacion_crea_el_articulo_con_su_imagen()
    {
        list($conversation, $assistant, $del_dueno) = $this->conversacion('Cargá esta botella zz-a5 con la foto');

        $foto = $this->foto($del_dueno);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'                     => 'article',
            'datos'                       => ['name' => 'Botella zz-a5'],
            'con_foto_de_la_conversacion' => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Una sola tarjeta: el alta lleva la foto adentro.');

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame((int) $foto->id, (int) $tarjeta->datos['extras']['imagen_id']);
        $this->assertArrayNotHasKey('con_foto_de_la_conversacion', $tarjeta->datos['payload'], 'Los extras no van al controller.');
        $this->assertContains('Foto', array_column($tarjeta->presentacion['renglones'], 'etiqueta'));

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Botella zz-a5')->first();

        $this->assertNotNull($articulo);

        $this->anotar_archivos($articulo);

        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());
        $this->assertNotNull(AiMessageImagen::find($foto->id)->gestionada_at, 'La foto queda sellada.');
        $this->assertStringContainsString('con la foto', $confirmacion->json('model.resultado.texto'));
    }

    /**
     * La foto que la búsqueda por código de barras dejó colgada del mensaje del ASISTENTE se acepta
     * por su imagen_id, y la tarjeta dice que es de internet.
     *
     * @test
     */
    public function el_alta_con_imagen_id_de_un_mensaje_del_asistente_la_asigna()
    {
        list($conversation, $assistant) = $this->conversacion('Cargá este producto por el código de barras');

        // La foto de internet, colgada del assistant en curso como la guarda el constructor B.
        $de_internet = $this->foto($assistant);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'   => 'article',
            'datos'     => ['name' => 'Yerba zz-a5 internet', 'bar_code' => '7790387000014'],
            'imagen_id' => $de_internet->id,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $valores = array_column(AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['renglones'], 'valor');

        $this->assertContains('Foto encontrada en internet', $valores);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Yerba zz-a5 internet')->first();

        $this->assertNotNull($articulo);

        $this->anotar_archivos($articulo);

        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count());
        $this->assertNotNull(AiMessageImagen::find($de_internet->id)->gestionada_at);
    }

    /**
     * Un imagen_id de OTRA conversación no se acepta: el id lo manda el modelo y no puede terminar
     * publicada una foto que no es de esta charla.
     *
     * @test
     */
    public function un_imagen_id_de_otra_conversacion_no_se_acepta()
    {
        list(, , $de_otra_charla) = $this->conversacion('Otra charla');

        $ajena = $this->foto($de_otra_charla);

        list($conversation, $assistant) = $this->conversacion('Cargá este producto');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'   => 'article',
            'datos'     => ['name' => 'Producto zz-a5 ajeno'],
            'imagen_id' => $ajena->id,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * El alta con descripción: una fila de `descriptions` colgada del artículo, como la de la
     * pantalla. La descripción puede venir como parámetro o adentro de `datos`.
     *
     * @test
     */
    public function el_alta_con_descripcion_crea_la_descripcion_del_articulo()
    {
        list($conversation, $assistant) = $this->conversacion('Cargá el termo con esta descripción');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => [
                'name'        => 'Termo zz-a5',
                'descripcion' => 'Termo de acero inoxidable de un litro, mantiene el agua caliente 24 horas.',
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Termo zz-a5')->first();

        $this->assertNotNull($articulo);

        $descripcion = Description::where('article_id', $articulo->id)->first();

        $this->assertNotNull($descripcion, 'La descripción tiene que quedar colgada del artículo.');
        $this->assertStringContainsString('acero inoxidable', $descripcion->content);
    }

    /**
     * La foto y la descripción son sólo del alta de un artículo: en otra entidad se corta, no se
     * ignoran en silencio.
     *
     * @test
     */
    public function los_extras_en_otra_entidad_cortan()
    {
        list($conversation, $assistant) = $this->conversacion('Cargá el proveedor');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'provider',
            'datos'       => ['name' => 'Proveedor zz-a5'],
            'descripcion' => 'Uno nuevo',
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('sólo se cargan en el alta de un artículo', $respuesta['error']);
    }

    // ------------------------------------------------ correcciones del 24/9/2026 (g, h, l)

    /**
     * La tarjeta del alta con foto trae la miniatura (`presentacion.imagen_url`, la clave que
     * AccionCard.vue ya pinta): el dueño ve QUÉ foto se publica antes de confirmar. Sirve para la
     * foto del dueño y para la de internet (el endpoint no filtra por rol).
     *
     * @test
     */
    public function la_tarjeta_del_alta_con_foto_trae_la_miniatura()
    {
        list($conversation, $assistant, $del_dueno) = $this->conversacion('Cargá esta taza zz-g con la foto');

        $this->foto($del_dueno);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'                     => 'article',
            'datos'                       => ['name' => 'Taza zz-g'],
            'con_foto_de_la_conversacion' => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $presentacion = AiMessageAction::find($respuesta['tarjeta_id'])->presentacion;

        $this->assertArrayHasKey('imagen_url', $presentacion);
        $this->assertStringContainsString('api/ai-mensajes/' . $del_dueno->id . '/imagen/1', $presentacion['imagen_url']);

        list($otra, $otro_assistant) = $this->conversacion('Cargá la yerba zz-g por el código');

        $de_internet = $this->foto($otro_assistant);

        $respuesta = $this->herramienta($otra, $otro_assistant, 'proponer_alta', [
            'entidad'   => 'article',
            'datos'     => ['name' => 'Yerba zz-g'],
            'imagen_id' => $de_internet->id,
        ]);

        $this->assertStringContainsString(
            'api/ai-mensajes/' . $otro_assistant->id . '/imagen/1',
            AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['imagen_url']
        );
    }

    /**
     * 🔴 La corrección de una tarjeta ("sí, pero cambiale el nombre") hereda la foto y la
     * descripción de la que reemplaza si el modelo no las vuelve a mandar. En la prueba real el
     * modelo inventó un imagen_id y reescribió la descripción.
     *
     * @test
     */
    public function la_correccion_de_un_alta_hereda_la_foto_y_la_descripcion()
    {
        list($conversation, $assistant) = $this->conversacion('Cargá este producto');

        $de_internet = $this->foto($assistant);

        $descripcion = 'Yerba mate con palo, elaborada con hojas estacionadas por 12 meses. Paquete de un kilo. ' . str_repeat('Sabor intenso y parejo. ', 20);

        $primera = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Yerba zz-h'],
            'imagen_id'   => $de_internet->id,
            'descripcion' => $descripcion,
        ]);

        $this->assertTrue($primera['ok'], json_encode($primera));

        $corregida = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Yerba zz-h Premium'],
            'reemplaza_a' => $primera['tarjeta_id'],
        ]);

        $this->assertTrue($corregida['ok'], json_encode($corregida));

        $extras = AiMessageAction::find($corregida['tarjeta_id'])->datos['extras'];

        $this->assertSame((int) $de_internet->id, (int) $extras['imagen_id'], 'La foto se hereda de la tarjeta reemplazada.');
        $this->assertSame(trim($descripcion), $extras['descripcion'], 'La descripción se hereda ENTERA, no recortada.');
        $this->assertSame('internet', $extras['imagen_origen']);
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::find($primera['tarjeta_id'])->estado_guardado());
    }

    /**
     * 🔴 Misión asistente-deepseek-pro-razona (24/9/2026): la corrección también hereda los CAMPOS.
     * En la prueba real con DeepSeek el modelo mandó sólo `{"name": "Cera Nic Mate"}` y la tarjeta
     * nueva perdió el código de barras de la anterior. Lo nuevo pisa; lo que no vino se conserva.
     *
     * @test
     */
    public function la_correccion_de_un_alta_hereda_los_campos_que_no_vuelve_a_mandar()
    {
        list($conversation, $assistant) = $this->conversacion('Cargá este producto');

        $primera = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Cera zz-campos', 'bar_code' => '7798111212032', 'cost' => 10],
        ]);

        $this->assertTrue($primera['ok'], json_encode($primera));

        $corregida = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Cera zz-campos Mate'],
            'reemplaza_a' => $primera['tarjeta_id'],
        ]);

        $this->assertTrue($corregida['ok'], json_encode($corregida));

        $pedidos = AiMessageAction::find($corregida['tarjeta_id'])->datos['pedidos'];

        $this->assertSame('Cera zz-campos Mate', $pedidos['name'], 'Lo que el modelo vuelve a mandar pisa.');
        $this->assertSame('7798111212032', (string) $pedidos['bar_code'], 'El código de barras se hereda.');
        $this->assertEquals(10, $pedidos['cost'], 'El costo se hereda.');
    }

    /**
     * Un imagen_id que no existe (inventado) corta con un error claro: no cae en otra foto.
     *
     * @test
     */
    public function un_imagen_id_inventado_corta_con_un_error_claro()
    {
        list($conversation, $assistant, $del_dueno) = $this->conversacion('Cargá este producto');

        // Hay una foto del dueño sin usar: no tiene que terminar en la tarjeta en lugar de la inventada.
        $this->foto($del_dueno);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'   => 'article',
            'datos'     => ['name' => 'Producto zz-h inventado'],
            'imagen_id' => 99999310,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('99999310', $respuesta['error']);
        $this->assertStringContainsString('No inventes ids', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Con la foto de INTERNET, la foto del código de barras que mandó el dueño ya cumplió: al
     * ejecutar el alta se sella, para que no quede 24 horas colándose en otra carga.
     *
     * @test
     */
    public function el_alta_con_foto_de_internet_sella_la_foto_del_codigo_de_barras()
    {
        list($conversation, $assistant, $del_dueno) = $this->conversacion('Cargá este producto por el código de barras');

        $del_codigo = $this->foto($del_dueno);
        $de_internet = $this->foto($assistant);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'   => 'article',
            'datos'     => ['name' => 'Galletitas zz-l'],
            'imagen_id' => $de_internet->id,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertNull(AiMessageImagen::find($del_codigo->id)->gestionada_at, 'Proponer no sella nada todavía.');

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Galletitas zz-l')->first();

        $this->anotar_archivos($articulo);

        $this->assertNotNull(AiMessageImagen::find($del_codigo->id)->gestionada_at, 'La foto del código de barras queda sellada.');
        $this->assertNotNull(AiMessageImagen::find($de_internet->id)->gestionada_at);
    }

    // ------------------------------------------------ segundo chequeo adversarial (6, 7)

    /**
     * ⚠️ "Sí, pero sin foto": un `con_foto_de_la_conversacion: false` o un `imagen_id: 0` explícitos
     * en la corrección QUITAN la foto heredada, y una `descripcion: ""` explícita quita la
     * descripción. Antes se descartaban como "no vino nada" y la foto se seguía publicando.
     *
     * @test
     */
    public function la_correccion_puede_quitar_la_foto_y_la_descripcion_heredadas()
    {
        list($conversation, $assistant) = $this->conversacion('Cargá este producto');

        $de_internet = $this->foto($assistant);

        $primera = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Yerba zz-q6'],
            'imagen_id'   => $de_internet->id,
            'descripcion' => 'Yerba mate con palo.',
        ]);

        $this->assertTrue($primera['ok'], json_encode($primera));

        $sin_foto = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'                     => 'article',
            'datos'                       => ['name' => 'Yerba zz-q6'],
            'con_foto_de_la_conversacion' => false,
            'reemplaza_a'                 => $primera['tarjeta_id'],
        ]);

        $this->assertTrue($sin_foto['ok'], json_encode($sin_foto));

        $tarjeta = AiMessageAction::find($sin_foto['tarjeta_id']);
        $extras = isset($tarjeta->datos['extras']) ? $tarjeta->datos['extras'] : [];

        $this->assertArrayNotHasKey('imagen_id', $extras, 'Con con_foto_de_la_conversacion:false la foto heredada se quita.');
        $this->assertSame('Yerba mate con palo.', $extras['descripcion'], 'Lo que no se tocó se sigue heredando.');
        $this->assertArrayNotHasKey('imagen_url', $tarjeta->presentacion);

        $sin_nada = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Yerba zz-q6'],
            'imagen_id'   => 0,
            'descripcion' => '',
            'reemplaza_a' => $primera['tarjeta_id'],
        ]);

        $this->assertTrue($sin_nada['ok'], json_encode($sin_nada));

        $extras = AiMessageAction::find($sin_nada['tarjeta_id'])->datos;
        $extras = isset($extras['extras']) ? $extras['extras'] : [];

        $this->assertArrayNotHasKey('imagen_id', $extras, 'imagen_id:0 explícito quita la foto.');
        $this->assertArrayNotHasKey('descripcion', $extras, 'descripcion:"" explícita quita la descripción.');
    }

    /**
     * ⚠️ La descripción que manda el modelo se sanea al proponer: sin HTML (la tienda la pinta con
     * v-html y va a Tienda Nube), con los espacios colapsados y con techo de 1200 caracteres.
     *
     * @test
     */
    public function la_descripcion_se_sanea_al_proponer()
    {
        list($conversation, $assistant) = $this->conversacion('Cargá el termo');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Termo zz-q7'],
            'descripcion' => "<p>Termo <b>de acero</b></p>\n\n\n<script>alert(1)</script>   de un   litro. " . str_repeat('Muy bueno. ', 200),
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $descripcion = AiMessageAction::find($respuesta['tarjeta_id'])->datos['extras']['descripcion'];

        $this->assertStringNotContainsString('<', $descripcion);
        $this->assertStringStartsWith('Termo de acero alert(1) de un litro.', $descripcion);
        $this->assertStringNotContainsString('  ', $descripcion, 'Los espacios quedan colapsados.');
        $this->assertLessThanOrEqual(1200, mb_strlen($descripcion));
    }
}
