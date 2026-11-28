<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\asistente_ia\BusquedaHibridaDeArticulosHelper;
use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\ArticleEmbeddingService;
use App\Services\WhatsappAgentScheduler;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Misión ft-agente-fotos-alternativas-t172 (ticket 172, Ferretotal): el agente de WhatsApp tiene
 * que ofrecer las ALTERNATIVAS CON STOCK y tiene que poder MANDAR LA FOTO de lo que el cliente pide.
 *
 * Cubre los criterios CA1 a CA10 del plan (CA11 son las suites existentes, que no se tocan):
 *
 *  - CA1/CA2: ante «tenés amoladora?» el orden de la búsqueda pone primero lo que tiene stock y el
 *    modelo recibe esas opciones junto con la consigna de ofrecer hasta 4.
 *  - CA3/CA4: la consigna de la foto permite varias y avisa cuando no hay; cada línea del catálogo
 *    dice `| Foto: sí|no | Ref: art<ID>`.
 *  - CA5: la foto se manda aunque el artículo NO tenga código de barras (causa raíz del ticket).
 *  - CA6: el segundo mensaje («¿me pasás la foto?») conserva los productos de los que se venía hablando.
 *  - CA7: varias fotos (hasta 4, sin repetidas), en el chat de prueba y en un chat real por Kapso.
 *  - CA8: un artículo sin foto sale como texto, sin inventar y sin marca visible.
 *  - CA9: aislamiento entre dueños, compatibilidad con `[FOTO:<bar_code>]`, URL absoluta y `first`.
 *  - CA10: «pasás» no es palabra de producto.
 *
 * Todo sin red real: el proveedor de IA y los embeddings van con `Http::fake`, Kapso también.
 * Vectores fingidos: consulta [1,0,0], artículo `[s, sqrt(1 - s²), 0]` → coseno = `s`.
 *
 * @group whatsapp
 */
class Fotos_y_alternativas_del_agente_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'agentes_ia';

    /** Teléfono de un cliente final real. */
    const PHONE = '5493416040172';

    /** Código de barras de un artículo que sí lo tiene (compatibilidad). */
    const BAR_CODE = '7791000000011';

    /** Foto de la primera caja Wadfow. */
    const URL_WADFOW_13 = 'https://img.test/wadfow13.webp';

    /** Consigna de alternativas, texto exacto del plan (D2). */
    const CONSIGNA_ALTERNATIVAS = 'Si el cliente pide un tipo de producto sin elegir uno en particular (por ejemplo "una amoladora"), ofrecele las opciones del catálogo de abajo que sirven para lo que pidió y tienen stock, hasta 4, cada una con su precio, y preguntale cuál prefiere: no elijas una sola por él. No ofrezcas como disponible un producto con Stock: 0.';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /**
     * Respuestas que va devolviendo el proveedor de IA, una por llamada (la última se repite).
     *
     * @var array<int,string>
     */
    protected $respuestas_de_la_ia = ['Respuesta del agente.'];

    /**
     * Cuerpos de los requests que viajaron al proveedor de IA, en orden.
     *
     * @var array<int,array>
     */
    protected $payloads_ia = [];

    /**
     * Cuerpos de los requests que viajaron a Kapso, en orden.
     *
     * @var array<int,array>
     */
    protected $payloads_kapso = [];

    /**
     * Prepara el dueño, el otro dueño (para aislamiento), la config del bot y la extensión.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => null,
            'services.openai.api_key'    => 'clave-openai-de-prueba',
            'broadcasting.default'       => 'null',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio fotos t172',
            'company_name' => 'Ferreteria fotos t172',
            'email'        => 'fotos-t172-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'         => 'Otro comercio fotos t172',
            'company_name' => 'Otra ferreteria',
            'email'        => 'fotos-t172-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // Agente APAGADO (is_active=false): el chat de prueba igual puede generar, el real no.
        $this->config = WhatsappBotConfig::create([
            'user_id'                  => $this->comercio->id,
            'kapso_api_key'            => 'kapso-t172',
            'phone_number_id'          => '5491100000172',
            'webhook_secret'           => 'secreto-t172',
            'is_active'                => false,
            'ai_enabled_default'       => true,
            // Con espera la respuesta queda `a_confirmar` y se puede mirar antes de que salga.
            'ai_confirm_delay_seconds' => 120,
        ]);

        $this->dar_extension();
    }

    /**
     * Le da al comercio la extensión de WhatsApp.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    // ── Utilidades ──────────────────────────────────────────────────────────────────────────────

    /**
     * Fake de TODAS las APIs externas, guardando los payloads de IA y de Kapso. El '*' final evita
     * salir a la red. Los embeddings de consulta son siempre [1,0,0].
     *
     * @return void
     */
    protected function fakes_de_red()
    {
        $this->payloads_ia    = [];
        $this->payloads_kapso = [];

        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data'  => [['embedding' => [1.0, 0.0, 0.0]]],
                'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
            ], 200),
            'api.anthropic.com/*' => function ($request) {
                $indice = count($this->payloads_ia);
                $this->payloads_ia[] = $request->data();

                $texto = $this->respuestas_de_la_ia[min($indice, count($this->respuestas_de_la_ia) - 1)];

                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => $texto]],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            'api.kapso.ai/*' => function ($request) {
                $this->payloads_kapso[] = $request->data();

                return Http::response(['messages' => [['id' => 'wamid.T172-' . count($this->payloads_kapso)]]], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Vector de un artículo con coseno `$s` contra la consulta [1,0,0].
     *
     * @param float $s
     * @return array<int,float>
     */
    protected function vector_con_similitud($s)
    {
        return [$s, sqrt(max(0.0, 1 - $s * $s)), 0.0];
    }

    /**
     * Crea un artículo del catálogo con su vector guardado por SQL crudo.
     *
     * @param string     $nombre
     * @param float|null $s      Similitud con la consulta; null = sin embedding.
     * @param array      $extra  Columnas extra (user_id, stock, final_price, bar_code...).
     * @return Article
     */
    protected function articulo($nombre, $s, array $extra = [])
    {
        $article = Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'final_price' => 1000,
            'stock'       => 10,
            'slug'        => Str::slug($nombre) . '-' . uniqid(),
        ], $extra));

        if (! is_null($s)) {
            DB::table('articles')->where('id', $article->id)->update([
                'embedding' => json_encode($this->vector_con_similitud($s)),
            ]);
        }

        return $article;
    }

    /**
     * Le carga una foto al artículo.
     *
     * @param Article $article
     * @param string  $hosting_url
     * @param int     $first       1 = la imagen marcada como principal.
     * @return void
     */
    protected function con_foto(Article $article, $hosting_url, $first = 0)
    {
        $article->images()->create(['hosting_url' => $hosting_url, 'first' => $first]);
    }

    /**
     * Las cuatro cajas Wadfow SIN código de barras y CON imagen (el caso del ticket).
     *
     * @return array<int,Article> Indexado 0..3 (13", 16", 19", 24").
     */
    protected function cajas_wadfow()
    {
        $cajas = [];
        foreach ([13, 16, 19, 24] as $pulgadas) {
            $caja = $this->articulo('CAJA DE HERRAMIENTAS WADFOW ' . $pulgadas . '"', 0.05, [
                'stock'       => 5,
                'final_price' => 20000 + $pulgadas * 100,
                'bar_code'    => null,
            ]);
            $this->con_foto($caja, 'https://img.test/wadfow' . $pulgadas . '.webp');
            $cajas[] = $caja;
        }

        return $cajas;
    }

    /**
     * Nueve artículos de ruido con similitud alta y nombres sin «caja», «herramienta» ni «pasa».
     *
     * @return void
     */
    protected function ruido_de_la_charla()
    {
        $nombres = ['MARTILLO', 'DESTORNILLADOR', 'PINZA', 'ALICATE', 'SERRUCHO', 'CINTA METRICA', 'NIVEL', 'ESCUADRA', 'TORNILLO'];
        foreach ($nombres as $i => $nombre) {
            $this->articulo($nombre . ' DE PRUEBA', 0.90 - $i * 0.03);
        }
    }

    /**
     * Chat con un entrante sin responder. `test-` en el teléfono lo vuelve chat de prueba.
     *
     * @param bool   $de_prueba Chat de prueba (simulador) o chat real.
     * @param string $mensaje   Texto del entrante.
     * @return WhatsappChat
     */
    protected function chat_con_entrante($de_prueba, $mensaje)
    {
        $chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => $de_prueba ? 'test-' . uniqid() : self::PHONE,
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $this->entrante($chat, $mensaje);

        return $chat;
    }

    /**
     * Agrega un entrante del cliente y deja vigente el token del debounce.
     *
     * @param WhatsappChat $chat
     * @param string       $mensaje
     * @return void
     */
    protected function entrante(WhatsappChat $chat, $mensaje)
    {
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => $mensaje,
        ]);

        $chat->update(['last_inbound_at' => now()]);

        // El scheduler bumpea el token sin encolar nada; el job de la prueba lleva el vigente.
        (new WhatsappAgentScheduler())->cancel((int) $chat->id);
    }

    /**
     * Corre el job que genera la respuesta, igual que lo haría la cola.
     *
     * @param WhatsappChat $chat
     * @return void
     */
    protected function correr_job(WhatsappChat $chat)
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $token = (int) WhatsappChat::find($chat->id)->ai_schedule_token;

        (new GenerateWhatsappAiReplyJob((int) $chat->id, $token))->handle(app(WhatsappAgentScheduler::class));
    }

    /**
     * La última respuesta saliente del agente.
     *
     * @param WhatsappChat $chat
     * @return WhatsappChatMessage|null
     */
    protected function ultima_saliente(WhatsappChat $chat)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Manda la respuesta pendiente por el camino de producción.
     *
     * @param WhatsappChat $chat
     * @return string Resultado de `send_pending_ai_message`.
     */
    protected function enviar_pendiente(WhatsappChat $chat)
    {
        $mensaje = $this->ultima_saliente($chat);
        $this->assertNotNull($mensaje, 'Tendría que haber una respuesta pendiente para enviar.');

        return WhatsappChatHelper::send_pending_ai_message($mensaje, WhatsappChat::find($chat->id), $this->config->fresh());
    }

    /**
     * Aplana un payload de IA a texto plano (system + todos los strings de los mensajes), así las
     * comparaciones no dependen del escapado JSON de las comillas de pulgadas (13").
     *
     * @param mixed $valor
     * @return string
     */
    protected function aplanar($valor)
    {
        if (is_array($valor)) {
            $partes = [];
            foreach ($valor as $hijo) {
                $partes[] = $this->aplanar($hijo);
            }

            return implode("\n", $partes);
        }

        return is_scalar($valor) ? (string) $valor : '';
    }

    /**
     * Texto completo del payload número `$indice` enviado al proveedor de IA.
     *
     * @param int $indice 0 = primero.
     * @return string
     */
    protected function texto_del_payload($indice = 0)
    {
        $this->assertArrayHasKey($indice, $this->payloads_ia, 'Tendría que haber un request a la IA número ' . $indice);

        return $this->aplanar($this->payloads_ia[$indice]);
    }

    /**
     * Texto del `system` del payload número `$indice`.
     *
     * @param int $indice
     * @return string
     */
    protected function system_del_payload($indice = 0)
    {
        $this->assertArrayHasKey($indice, $this->payloads_ia);

        return $this->aplanar(isset($this->payloads_ia[$indice]['system']) ? $this->payloads_ia[$indice]['system'] : '');
    }

    /**
     * Requests de imagen que salieron a Kapso, en orden.
     *
     * @return array<int,array>
     */
    protected function imagenes_a_kapso()
    {
        $imagenes = [];
        foreach ($this->payloads_kapso as $payload) {
            if (isset($payload['type']) && $payload['type'] === 'image') {
                $imagenes[] = $payload;
            }
        }

        return $imagenes;
    }

    /**
     * Charla de dos turnos con las cajas Wadfow: el cliente pregunta por cajas y después pide la
     * foto. Deja el turno 1 YA ENVIADO y el turno 2 generado (pendiente o por enviar).
     *
     * @param bool   $de_prueba      Chat de prueba o chat real.
     * @param string $respuesta_foto Respuesta del modelo en el segundo turno.
     * @return array{0:WhatsappChat,1:array<int,Article>}
     */
    protected function charla_de_las_cajas($de_prueba, $respuesta_foto)
    {
        Queue::fake();
        $this->respuestas_de_la_ia = ['Tengo cajas de herramientas Wadfow de 13, 16, 19 y 24 pulgadas.', $respuesta_foto];
        $this->fakes_de_red();

        $cajas = $this->cajas_wadfow();
        $this->ruido_de_la_charla();

        if (! $de_prueba) {
            $this->config->update(['is_active' => true]);
        }

        $chat = $this->chat_con_entrante($de_prueba, 'Hola, tenés cajas de herramientas?');
        $this->correr_job($chat);
        $this->enviar_pendiente($chat);

        $this->entrante($chat, '¿me pasás la foto?');
        $this->correr_job($chat);

        return [$chat, $cajas];
    }

    // ── CA1 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA1 — Stock primero en la búsqueda. Hoy los 6 lugares léxicos los ocupan las amoladoras sin
     * stock de mayor similitud y Konan y Dogo (con stock) quedan afuera del top 8.
     *
     * @group whatsapp
     * @test
     */
    public function la_busqueda_pone_primero_las_amoladoras_con_stock()
    {
        $this->fakes_de_red();

        $black = $this->articulo('AMOLADORA ANGULAR BLACK&DECKER 115MM 650W', 0.95, ['stock' => 4]);
        $this->articulo('AMOLADORA ANGULAR BOSCH 115MM 720W', 0.94, ['stock' => 0]);
        $this->articulo('AMOLADORA ANGULAR MAKITA 115MM 720W', 0.93, ['stock' => 0]);
        $this->articulo('AMOLADORA ANGULAR DEWALT 115MM 750W', 0.92, ['stock' => 0]);
        $this->articulo('AMOLADORA ANGULAR GAMMA 115MM 710W', 0.91, ['stock' => 0]);
        $this->articulo('AMOLADORA ANGULAR LUSQTOFF 115MM 710W', 0.90, ['stock' => 0]);
        $niwa  = $this->articulo('AMOLADORA ANGULAR NIWA 115MM 750W', 0.89, ['stock' => null]);
        $konan = $this->articulo('AMOLADORA ANGULAR KONAN 115MM 850W', 0.50, ['stock' => 3]);
        $dogo  = $this->articulo('AMOLADORA ANGULAR DOGO 180MM 2200W', 0.49, ['stock' => 2]);
        $this->articulo('SIERRA CIRCULAR 7 1/4 1400W', 0.88);
        $this->articulo('TALADRO PERCUTOR 13MM 750W', 0.87);
        $this->articulo('LIJADORA ORBITAL 200W', 0.86);
        $this->articulo('DISCO CORTE P/AMOLADORA 115', 0.85, ['stock' => 50]);

        $resultado = (new ArticleEmbeddingService())->search_hybrid_articles('Hola, tenés amoladora?', $this->comercio->id, 8);
        $ids       = $resultado->pluck('id')->map(function ($id) {
            return (int) $id;
        })->values()->all();

        $this->assertSame(
            [(int) $black->id, (int) $konan->id, (int) $dogo->id, (int) $niwa->id],
            array_slice($ids, 0, 4),
            'Primero lo que tiene stock (por similitud), después el stock sin dato, y recién al final lo agotado.'
        );
        $this->assertContains((int) $konan->id, $ids, 'Konan tiene stock: tiene que estar entre las opciones.');
        $this->assertContains((int) $dogo->id, $ids, 'Dogo tiene stock: tiene que estar entre las opciones.');
    }

    // ── CA2 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA2 — Las alternativas llegan al modelo, con la consigna exacta antes de las marcas internas.
     * Chat de prueba con el agente apagado, como lo reprodujo Pablo.
     *
     * @group whatsapp
     * @test
     */
    public function en_el_chat_de_prueba_las_alternativas_con_stock_llegan_al_modelo()
    {
        Queue::fake();
        $this->fakes_de_red();

        $this->articulo('AMOLADORA ANGULAR BLACK&DECKER 115MM 650W', 0.95, ['stock' => 4, 'final_price' => 61000]);
        foreach (['BOSCH' => 0.94, 'MAKITA' => 0.93, 'DEWALT' => 0.92, 'GAMMA' => 0.91, 'LUSQTOFF' => 0.90] as $marca => $s) {
            $this->articulo('AMOLADORA ANGULAR ' . $marca . ' 115MM 720W', $s, ['stock' => 0]);
        }
        $this->articulo('AMOLADORA ANGULAR NIWA 115MM 750W', 0.89, ['stock' => null]);
        $this->articulo('AMOLADORA ANGULAR KONAN 115MM 850W', 0.50, ['stock' => 3, 'final_price' => 52000]);
        $this->articulo('AMOLADORA ANGULAR DOGO 180MM 2200W', 0.49, ['stock' => 2, 'final_price' => 98000]);
        $this->articulo('SIERRA CIRCULAR 7 1/4 1400W', 0.88);
        $this->articulo('TALADRO PERCUTOR 13MM 750W', 0.87);
        $this->articulo('LIJADORA ORBITAL 200W', 0.86);

        $chat = $this->chat_con_entrante(true, 'Hola, tenés amoladora?');
        $this->assertTrue((bool) $chat->is_test, 'El chat de la prueba tiene que ser un chat de prueba.');
        $this->assertFalse((bool) $this->config->is_active, 'El agente está apagado, como en el ticket.');

        $this->correr_job($chat);

        $payload = $this->texto_del_payload(0);
        $this->assertStringContainsString('AMOLADORA ANGULAR KONAN 115MM 850W | Precio: $52.000,00 | Stock: 3', $payload);
        $this->assertStringContainsString('AMOLADORA ANGULAR DOGO 180MM 2200W | Precio: $98.000,00 | Stock: 2', $payload);

        $system = $this->system_del_payload(0);
        $posicion_consigna = strpos($system, self::CONSIGNA_ALTERNATIVAS);
        $posicion_marcas   = strpos($system, 'Marcas internas para que el sistema');
        $this->assertNotFalse($posicion_consigna, 'El system tiene que traer la consigna de alternativas con stock.');
        $this->assertNotFalse($posicion_marcas);
        $this->assertLessThan($posicion_marcas, $posicion_consigna, 'La consigna de alternativas va antes de las marcas internas.');
    }

    // ── CA3 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA3 — La consigna de foto nueva: cuatro oraciones exactas, sin el texto viejo de «una por
     * respuesta». Con objetivo cargado, el prompt sin objetivo sigue siendo su prefijo.
     *
     * @group whatsapp
     * @test
     */
    public function la_consigna_de_foto_permite_varias_y_avisa_sin_foto()
    {
        Queue::fake();
        $this->fakes_de_red();

        $this->articulo('CAJA DE HERRAMIENTAS WADFOW 13"', 0.9);
        $chat = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas?');
        $this->correr_job($chat);

        $system = $this->system_del_payload(0);

        $oraciones = [
            'Cada producto del catálogo de abajo trae su Ref y dice si tiene Foto cargada (Foto: sí o Foto: no).',
            'Si el cliente te pide la foto de uno o varios productos, o si le estás recomendando UN producto puntual que tiene Foto: sí, terminá tu respuesta con una línea por producto que diga exactamente [FOTO:<Ref del producto>], sin ningún otro texto en esa línea. Como mucho 4 líneas así por respuesta.',
            'Esa línea es una marca interna del sistema: se saca del mensaje antes de mandárselo al cliente y sirve para adjuntarle la foto del producto. No la menciones ni la expliques en el texto.',
            'Si el cliente pide la foto de un producto que dice Foto: no, decile que de ese producto no tenés una foto cargada, sin prometer que se la mandás. Nunca pongas [FOTO:...] de un producto con Foto: no.',
        ];
        foreach ($oraciones as $oracion) {
            $this->assertStringContainsString($oracion, $system);
        }

        $this->assertStringNotContainsString(
            'Poné como mucho una por respuesta, y solo si estás recomendando un producto puntual',
            $system,
            'La consigna vieja prohibía mandar más de una foto.'
        );

        // El prompt sin objetivo tiene que seguir siendo prefijo del prompt con objetivo.
        $servicio = new WhatsappBotAiService();
        $metodo   = new ReflectionMethod($servicio, 'build_system_prompt');
        $metodo->setAccessible(true);

        $sin_objetivo = $metodo->invoke($servicio, $this->config->fresh());
        // Objetivo «pedido por WhatsApp»: no depende de `users.online` (el de tienda online sin tienda
        // cargada no agrega capa, regla del test 28), así que la capa del objetivo siempre se suma.
        $this->config->agent_objective        = 'order_by_whatsapp';
        $this->config->agent_objective_detail = 'Recordale que paga con Mercado Pago.';
        $this->config->save();
        $con_objetivo = $metodo->invoke($servicio, $this->config->fresh());

        $this->assertStringStartsWith($sin_objetivo, $con_objetivo);
        $this->assertGreaterThan(strlen($sin_objetivo), strlen($con_objetivo));
        $this->assertStringContainsString('[FOTO:<Ref del producto>]', $sin_objetivo);
    }

    // ── CA4 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA4 — Cada línea del catálogo dice si tiene foto y su Ref interna.
     *
     * @group whatsapp
     * @test
     */
    public function cada_linea_del_catalogo_dice_foto_y_ref()
    {
        Queue::fake();
        $this->fakes_de_red();

        // Sin código de barras y con imagen.
        $con_foto = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 13"', 0.9, ['bar_code' => null]);
        $this->con_foto($con_foto, self::URL_WADFOW_13);
        // Con código de barras y sin imagen.
        $con_codigo = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 16"', 0.8, ['bar_code' => self::BAR_CODE]);
        // Imagen cargada pero con la URL vacía: no cuenta como foto.
        $imagen_vacia = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 19"', 0.7, ['bar_code' => null]);
        $this->con_foto($imagen_vacia, '');

        $chat = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas?');
        $this->correr_job($chat);

        $payload = $this->texto_del_payload(0);

        $this->assertStringContainsString('| Foto: sí | Ref: art' . $con_foto->id, $payload);
        $this->assertStringContainsString('| Código: ' . self::BAR_CODE . ' | Foto: no | Ref: art' . $con_codigo->id, $payload);
        $this->assertStringContainsString('| Foto: no | Ref: art' . $imagen_vacia->id, $payload);
    }

    // ── CA5 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA5 — La foto de un artículo SIN código de barras se adjunta con `[FOTO:art<ID>]`. Es la causa
     * raíz del ticket: hoy el job sólo sabe resolver por `bar_code`.
     *
     * @group whatsapp
     * @test
     */
    public function la_foto_de_un_articulo_sin_codigo_de_barras_se_adjunta()
    {
        Queue::fake();
        $this->fakes_de_red();

        $caja = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 13"', 0.9, ['bar_code' => null]);
        $this->con_foto($caja, self::URL_WADFOW_13);
        $this->respuestas_de_la_ia = ["Te paso la foto.\n[FOTO:art" . $caja->id . ']'];

        $chat = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas? pasame la foto');
        $this->correr_job($chat);

        $mensaje = $this->ultima_saliente($chat);
        $this->assertNotNull($mensaje, 'La respuesta tiene que haberse guardado.');
        $this->assertSame('Te paso la foto.', $mensaje->body);
        $this->assertSame('image', $mensaje->media_type);
        $this->assertSame(self::URL_WADFOW_13, $mensaje->media_url);
    }

    // ── CA6 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA6 — El segundo mensaje («¿me pasás la foto?») no trae el producto por sí solo: el turno
     * tiene que recordar el catálogo del turno anterior.
     *
     * @group whatsapp
     * @test
     */
    public function el_segundo_mensaje_conserva_los_productos_de_la_charla()
    {
        // Turno 2 sin marcas: lo que se mira es el payload, no la respuesta.
        list($chat, $cajas) = $this->charla_de_las_cajas(true, 'Claro, ¿de qué medida la querés?');

        // Turno 1: la respuesta del agente recuerda los ids del catálogo que vio el modelo.
        $primera = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->first();
        $recordados = array_map('intval', (array) $primera->ai_catalog_article_ids);
        foreach ($cajas as $caja) {
            $this->assertContains((int) $caja->id, $recordados, 'La respuesta tiene que guardar los ids del catálogo del turno.');
        }

        // Turno 2: el payload trae las 4 cajas con Foto y Ref, y la nota de arrastre.
        $payload = $this->texto_del_payload(1);
        foreach ($cajas as $caja) {
            $this->assertStringContainsString('| Foto: sí | Ref: art' . $caja->id, $payload, 'Falta la caja ' . $caja->name . ' en el segundo turno.');
        }
        $this->assertStringContainsString('Productos de los que ya se venía hablando', $payload);
    }

    // ── CA7 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * Respuesta del modelo que pide las fotos de las cuatro cajas.
     *
     * @param array<int,Article> $cajas
     * @return string
     */
    protected function respuesta_con_cuatro_fotos(array $cajas)
    {
        $lineas = ['Te paso las fotos.'];
        foreach ($cajas as $caja) {
            $lineas[] = '[FOTO:art' . $caja->id . ']';
        }

        return implode("\n", $lineas);
    }

    /**
     * CA7a — Chat de prueba: al enviarse quedan 4 salientes con imagen, en orden, la primera con el
     * texto y las demás sin cuerpo, todas enviadas y sin ningún request a Kapso.
     *
     * @group whatsapp
     * @test
     */
    public function pedir_las_fotos_de_las_cuatro_cajas_manda_cuatro_fotos_en_el_chat_de_prueba()
    {
        $cajas = [];
        // Las cajas se crean dentro de `charla_de_las_cajas`: se arma el texto con un mensaje que
        // se resuelve después con los ids reales.
        Queue::fake();
        $this->fakes_de_red();
        $cajas = $this->cajas_wadfow();
        $this->ruido_de_la_charla();
        $this->respuestas_de_la_ia = [
            'Tengo cajas de herramientas Wadfow de 13, 16, 19 y 24 pulgadas.',
            $this->respuesta_con_cuatro_fotos($cajas),
        ];

        $chat = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas?');
        $this->correr_job($chat);
        $this->enviar_pendiente($chat);

        $this->entrante($chat, '¿me pasás la foto?');
        $this->correr_job($chat);
        $this->enviar_pendiente($chat);

        $imagenes = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('direction', 'out')
            ->where('media_type', 'image')
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $imagenes, 'Las cuatro fotos tienen que quedar como mensajes salientes con imagen.');
        $this->assertSame(
            ['https://img.test/wadfow13.webp', 'https://img.test/wadfow16.webp', 'https://img.test/wadfow19.webp', 'https://img.test/wadfow24.webp'],
            $imagenes->pluck('media_url')->all(),
            'Las fotos salen en el orden en que el modelo las marcó.'
        );
        $this->assertSame('Te paso las fotos.', $imagenes[0]->body);
        foreach ([1, 2, 3] as $i) {
            $this->assertSame('', (string) $imagenes[$i]->body, 'Las fotos extra no llevan texto.');
        }
        foreach ($imagenes as $imagen) {
            $this->assertSame('ia', $imagen->source);
            $this->assertSame('enviado', $imagen->ai_status, 'Todas las fotos quedan enviadas.');
            $this->assertStringNotContainsString('[FOTO:', (string) $imagen->body);
        }

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'api.kapso.ai') !== false;
        });
    }

    /**
     * CA7b — Chat real: la pendiente guarda la primera foto y las otras tres en `ai_extra_media_urls`;
     * al enviarla salen 4 imágenes a Kapso por `link`, en orden, con caption sólo en la primera.
     *
     * @group whatsapp
     * @test
     */
    public function en_un_chat_real_salen_cuatro_imagenes_a_kapso_en_orden()
    {
        Queue::fake();
        $this->fakes_de_red();
        $cajas = $this->cajas_wadfow();
        $this->ruido_de_la_charla();
        $this->respuestas_de_la_ia = [
            'Tengo cajas de herramientas Wadfow de 13, 16, 19 y 24 pulgadas.',
            $this->respuesta_con_cuatro_fotos($cajas),
        ];
        $this->config->update(['is_active' => true]);

        $chat = $this->chat_con_entrante(false, 'Hola, tenés cajas de herramientas?');
        $this->correr_job($chat);
        $this->enviar_pendiente($chat);

        $this->entrante($chat, '¿me pasás la foto?');
        $this->correr_job($chat);

        $pendiente = $this->ultima_saliente($chat);
        $this->assertSame('a_confirmar', $pendiente->ai_status);
        $this->assertSame(self::URL_WADFOW_13, $pendiente->media_url);
        $this->assertSame(
            ['https://img.test/wadfow16.webp', 'https://img.test/wadfow19.webp', 'https://img.test/wadfow24.webp'],
            array_values((array) $pendiente->ai_extra_media_urls),
            'Las otras tres fotos viajan con el borrador.'
        );
        $imagenes_antes = count($this->imagenes_a_kapso());
        $this->assertSame(0, $imagenes_antes, 'Todavía no se confirmó: no salió ninguna imagen.');

        $this->enviar_pendiente($chat);

        $imagenes = $this->imagenes_a_kapso();
        $this->assertCount(4, $imagenes, 'Salen cuatro imágenes a Kapso.');
        $this->assertSame(
            ['https://img.test/wadfow13.webp', 'https://img.test/wadfow16.webp', 'https://img.test/wadfow19.webp', 'https://img.test/wadfow24.webp'],
            array_map(function ($payload) {
                return $payload['image']['link'];
            }, $imagenes),
            'Van por link y en el orden marcado.'
        );
        $this->assertSame('Te paso las fotos.', $imagenes[0]['image']['caption']);
        foreach ([1, 2, 3] as $i) {
            $this->assertEmpty(isset($imagenes[$i]['image']['caption']) ? $imagenes[$i]['image']['caption'] : '', 'Sólo la primera lleva caption.');
        }
    }

    /**
     * CA7c — Seis marcas con una repetida y cinco cajas distintas: como mucho 4 fotos, sin repetidas,
     * y ninguna marca visible.
     *
     * @group whatsapp
     * @test
     */
    public function nunca_mas_de_cuatro_ni_repetidas()
    {
        Queue::fake();
        $this->fakes_de_red();

        $cajas = $this->cajas_wadfow();
        $quinta = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 30"', 0.05, ['stock' => 5, 'bar_code' => null]);
        $this->con_foto($quinta, 'https://img.test/wadfow30.webp');

        $this->respuestas_de_la_ia = [
            "Te paso las fotos.\n[FOTO:art" . $cajas[0]->id . "]\n[FOTO:art" . $cajas[1]->id . "]\n[FOTO:art" . $cajas[0]->id
            . "]\n[FOTO:art" . $cajas[2]->id . "]\n[FOTO:art" . $cajas[3]->id . "]\n[FOTO:art" . $quinta->id . ']',
        ];

        $chat = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas? pasame las fotos');
        $this->correr_job($chat);

        $mensaje = $this->ultima_saliente($chat);
        $this->assertNotNull($mensaje);
        $this->assertSame('Te paso las fotos.', $mensaje->body);
        $this->assertStringNotContainsString('[FOTO:', (string) $mensaje->body);

        $urls = array_merge([$mensaje->media_url], array_values((array) $mensaje->ai_extra_media_urls));
        $this->assertSame(
            ['https://img.test/wadfow13.webp', 'https://img.test/wadfow16.webp', 'https://img.test/wadfow19.webp', 'https://img.test/wadfow24.webp'],
            $urls,
            'Máximo 4 fotos, sin repetir, en orden de aparición.'
        );
    }

    // ── CA8 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA8 — Sin foto no se inventa: la marca de un artículo sin foto sale como texto, y en una
     * respuesta con dos marcas sólo sale la del artículo que tiene foto.
     *
     * @group whatsapp
     * @test
     */
    public function una_marca_de_un_articulo_sin_foto_sale_como_texto()
    {
        Queue::fake();
        $this->fakes_de_red();

        $sin_foto = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 16"', 0.9, ['bar_code' => null]);
        $con_foto = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 13"', 0.8, ['bar_code' => null]);
        $this->con_foto($con_foto, self::URL_WADFOW_13);

        // Sólo la marca del artículo sin foto.
        $this->respuestas_de_la_ia = ["De esa caja no tengo foto cargada.\n[FOTO:art" . $sin_foto->id . ']'];
        $chat = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas? pasame la foto');
        $this->correr_job($chat);

        $mensaje = $this->ultima_saliente($chat);
        $this->assertNotNull($mensaje);
        $this->assertSame('De esa caja no tengo foto cargada.', $mensaje->body);
        $this->assertNull($mensaje->media_type);
        $this->assertNull($mensaje->media_url);

        // Dos marcas, una con foto y otra sin: sale sólo la que tiene.
        $this->respuestas_de_la_ia = ["Te paso las fotos.\n[FOTO:art" . $sin_foto->id . "]\n[FOTO:art" . $con_foto->id . ']'];
        $otro = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas? pasame las fotos');
        $this->correr_job($otro);

        $mensaje = $this->ultima_saliente($otro);
        $this->assertNotNull($mensaje);
        $this->assertSame('Te paso las fotos.', $mensaje->body);
        $this->assertSame('image', $mensaje->media_type);
        $this->assertSame(self::URL_WADFOW_13, $mensaje->media_url);
        $this->assertEmpty((array) $mensaje->ai_extra_media_urls, 'No hay más fotos que la de la caja que sí tiene.');
    }

    // ── CA9 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA9 — Aislamiento: la Ref de un artículo de OTRO dueño nunca adjunta nada. Se pide junto con
     * la Ref de un artículo propio para que el test pruebe el arreglo y no sólo la ausencia.
     *
     * @group whatsapp
     * @test
     */
    public function la_ref_de_otro_dueno_no_adjunta()
    {
        Queue::fake();
        $this->fakes_de_red();

        $propio = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 13"', 0.9, ['bar_code' => null]);
        $this->con_foto($propio, self::URL_WADFOW_13);

        $ajeno = $this->articulo('CAJA AJENA SECRETA', 0.9, ['user_id' => $this->otro_comercio->id, 'bar_code' => null]);
        $this->con_foto($ajeno, 'https://img.test/ajena.webp');

        $this->respuestas_de_la_ia = ["Te paso las fotos.\n[FOTO:art" . $propio->id . "]\n[FOTO:art" . $ajeno->id . ']'];

        $chat = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas? pasame las fotos');
        $this->correr_job($chat);

        $mensaje = $this->ultima_saliente($chat);
        $this->assertNotNull($mensaje);
        $this->assertSame(self::URL_WADFOW_13, $mensaje->media_url, 'La del dueño sí sale.');
        $this->assertEmpty((array) $mensaje->ai_extra_media_urls, 'La Ref de otro dueño no puede adjuntar nada.');
        $this->assertStringNotContainsString('ajena', $this->aplanar([$mensaje->media_url, $mensaje->ai_extra_media_urls]));
    }

    /**
     * CA9 — Compatibilidad: `[FOTO:<bar_code>]` sigue adjuntando (camino de hoy del test 14). Esta
     * parte PASA hoy a propósito: queda como guarda de que el arreglo no rompe el código de barras.
     *
     * @group whatsapp
     * @test
     */
    public function el_codigo_de_barras_sigue_adjuntando_la_foto()
    {
        Queue::fake();
        $this->fakes_de_red();

        $articulo = $this->articulo('TALADRO PERCUTOR 750W', 0.9, ['bar_code' => self::BAR_CODE]);
        $this->con_foto($articulo, self::URL_WADFOW_13);

        $this->respuestas_de_la_ia = ["Te paso la foto.\n[FOTO:" . self::BAR_CODE . ']'];
        $chat = $this->chat_con_entrante(true, 'Hola, tenés taladros?');
        $this->correr_job($chat);

        $mensaje = $this->ultima_saliente($chat);
        $this->assertNotNull($mensaje);
        $this->assertSame('Te paso la foto.', $mensaje->body);
        $this->assertSame('image', $mensaje->media_type);
        $this->assertSame(self::URL_WADFOW_13, $mensaje->media_url);
    }

    /**
     * CA9 — Una `hosting_url` relativa sale como URL absoluta armada por `ApiUrlHelper`, y con dos
     * imágenes sale la marcada `first`.
     *
     * @group whatsapp
     * @test
     */
    public function la_url_relativa_sale_absoluta_y_respeta_first()
    {
        Queue::fake();
        $this->fakes_de_red();
        config(['app.APP_URL' => 'https://api-t172.comerciocity.test']);

        $esperada = ApiUrlHelper::url_publica_de_imagen('storage/caja.webp');
        $this->assertNotEmpty($esperada, 'El fixture tiene que poder armar la URL absoluta.');
        $this->assertStringStartsWith('https://', $esperada);

        // Relativa: tiene que salir absoluta, no la cruda.
        $relativa = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 13"', 0.9, ['bar_code' => null]);
        $this->con_foto($relativa, 'storage/caja.webp');

        $this->respuestas_de_la_ia = ["Te paso la foto.\n[FOTO:art" . $relativa->id . ']'];
        $chat = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas? pasame la foto');
        $this->correr_job($chat);

        $mensaje = $this->ultima_saliente($chat);
        $this->assertNotNull($mensaje);
        $this->assertSame($esperada, $mensaje->media_url, 'La URL relativa se normaliza con ApiUrlHelper.');

        // Dos imágenes: sale la marcada `first`, aunque no sea la primera cargada.
        $doble = $this->articulo('CAJA DE HERRAMIENTAS WADFOW 16"', 0.8, ['bar_code' => null]);
        $this->con_foto($doble, 'https://img.test/primera-cargada.webp', 0);
        $this->con_foto($doble, 'https://img.test/la-principal.webp', 1);

        $this->respuestas_de_la_ia = ["Te paso la foto.\n[FOTO:art" . $doble->id . ']'];
        $otro = $this->chat_con_entrante(true, 'Hola, tenés cajas de herramientas? pasame la foto');
        $this->correr_job($otro);

        $mensaje = $this->ultima_saliente($otro);
        $this->assertNotNull($mensaje);
        $this->assertSame('https://img.test/la-principal.webp', $mensaje->media_url, 'Se respeta la imagen marcada como principal.');
    }

    // ── CA10 ────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA10 — «pasás» (y las otras formas de pedir un envío) no es palabra de producto: hoy genera un
     * `LIKE '%pasa%'` de basura. Unitario puro del helper, sin base.
     *
     * @group whatsapp
     * @test
     */
    public function pasas_no_es_palabra_de_producto()
    {
        $this->assertSame([], BusquedaHibridaDeArticulosHelper::palabras_de_producto('¿me pasás la foto?'));
        $this->assertSame(['cajas'], BusquedaHibridaDeArticulosHelper::palabras_de_producto('¿Me pasás la foto de las cajas?'));
    }
}
