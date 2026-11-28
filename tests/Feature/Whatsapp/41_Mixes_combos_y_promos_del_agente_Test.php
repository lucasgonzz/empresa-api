<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\WhatsappAgentPromosHelper;
use App\Models\Article;
use App\Models\Combo;
use App\Models\ExtencionEmpresa;
use App\Models\PromocionVinoteca;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\ArticleEmbeddingService;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Misión truvari-agente-mix-promos-t190 (ticket 190, Truvari): cuando el cliente pide un «mix» o un
 * «combo», el agente de WhatsApp tiene que buscarlo en Promos (y Combos) por NOMBRE, preguntar cuál si
 * hay varios y NO ofrecer productos ajenos al consultar el stock de un mix.
 *
 * Caso real: «¿Tenés el mix Catena?» → el agente buscó entre artículos, devolvió vinos de la bodega
 * Catena Zapata y ofreció un estuche que nada que ver. En Promos existen «Mix Ernesto Catena» y
 * «Mix Catena Zapata».
 *
 * Qué se mide: lo que RECIBE el modelo (el último turno `user` del payload), no lo que contesta. Los
 * textos fijos del helper se declaran LITERALES acá (no se leen de la clase) para que un cambio de
 * texto se vea en este archivo, como hace el test 37 con `ENCABEZADO_NUEVO`.
 *
 * Cubre CA1 a CA13 del plan. Todo sin red: embeddings de consulta [1,0,0] y proveedor de IA fingidos
 * con `Http::fake`. Vectores de artículo `[s, sqrt(1 - s²), 0]` → coseno = `s`.
 *
 * @group whatsapp
 */
class Mixes_combos_y_promos_del_agente_Test extends TestCase
{
    use DatabaseTransactions;

    /** Encabezado del bloque de promos (plan §2 ítem 6, texto exacto). */
    const HEADER = '[Nota interna del sistema, el cliente no la ve] El cliente nombró un mix, un combo o una promo. Estas son las promos y combos del negocio que coinciden con lo que pidió. Cada uno se vende como una unidad, con su propio precio y su propio stock: no son los productos sueltos del catálogo.';

    /** Regla cuando coincide más de uno. */
    const RULE_SEVERAL = 'Hay más de uno que coincide: antes de confirmar precio o stock, preguntale cuál quiere, nombrándolos.';

    /** Regla cuando coincide uno solo. */
    const RULE_ONE = 'Coincide uno solo: contestá con su precio y su stock.';

    /** Regla cuando el cliente no dijo cuál (lista). */
    const RULE_LIST = 'El cliente no dijo cuál: si pregunta qué hay, podés nombrarlos; antes de confirmar precio o stock, preguntale cuál quiere.';

    /** Regla para los que no traen stock. */
    const RULE_NO_STOCK = 'Si uno de la lista no trae Stock, no confirmes que hay: decile que lo consultás y le confirmás.';

    /** Regla cuando la lista se cortó en el tope. */
    const RULE_MORE = 'El negocio tiene más promos y combos que los de esta lista: si busca uno que no está, pedile el nombre.';

    /** Regla del modo estricto: no ofrecer otros productos. */
    const RULE_STRICT = 'Si no podés confirmar lo que pidió, decilo y no le ofrezcas otros productos en su lugar.';

    /** Nota cuando el cliente nombró un mix y ninguna promo ni combo coincide. */
    const NONE = '[Nota interna del sistema, el cliente no la ve] El cliente nombró un mix, un combo o una promo, y ninguna promo ni combo del negocio coincide con lo que pidió. No digas que no existe ni que no hay: decile que lo consultás y le confirmás. No le ofrezcas otro producto en su lugar.';

    /** Artículos de vinoteca del caso real (nombres de los plan §6). */
    const MALBEC  = 'Catena Zapata Malbec 750';
    const ESTUCHE = 'Estuche Catena Zapata 2 copas';
    const RUTINI  = 'Rutini Cabernet 750';

    /** Teléfono de un cliente final real (chat persistido de CA10). */
    const PHONE = '5493416040190';

    /** @var User Comercio «Truvari test». */
    protected $comercio;

    /** @var User Otro comercio (aislamiento, CA9). */
    protected $otro_comercio;

    /**
     * Cuerpos de los requests que viajaron al proveedor de IA, en orden.
     *
     * @var array<int,array>
     */
    protected $payloads_ia = [];

    /**
     * Prepara los dos comercios y la configuración de IA de prueba (igual que el test 37).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => null,
            'services.openai.api_key'          => 'clave-openai-de-prueba',
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba',
            'services.deepseek.model_agil'     => 'deepseek-flash-test',
            'services.deepseek.model_profundo' => 'deepseek-pro-test',
            'broadcasting.default'             => 'null',
        ]);

        $this->comercio = User::create([
            'name'         => 'Truvari test',
            'company_name' => 'Truvari test',
            'email'        => 'mixes-t190-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'         => 'Otra vinoteca t190',
            'company_name' => 'Otra vinoteca t190',
            'email'        => 'mixes-t190-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────────────────────────

    /**
     * Le da al usuario una extensión (la crea si la base de testing no la trae).
     *
     * @param User   $usuario Dueño del negocio.
     * @param string $slug    `vinoteca` o `combos`.
     * @return void
     */
    protected function dar_extension(User $usuario, $slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $usuario->extencions()->attach($extencion->id);
        $usuario->load('extencions');
    }

    /**
     * Fake de OpenAI (embedding de consulta [1,0,0]) y de DeepSeek (respuesta fija), guardando cada
     * payload de chat que sale hacia el proveedor en `$payloads_ia`.
     *
     * @param string $texto Respuesta fija del modelo.
     * @return void
     */
    protected function fingir_deepseek_y_openai($texto = 'Respuesta del agente.')
    {
        $this->payloads_ia = [];

        Http::fake([
            'api.openai.com/*'   => Http::response([
                'model' => 'text-embedding-3-small',
                'data'  => [['embedding' => [1.0, 0.0, 0.0]]],
                'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
            ], 200),
            'api.deepseek.com/*' => function ($request) use ($texto) {
                $this->payloads_ia[] = $request->data();

                return Http::response([
                    'model'   => 'deepseek-flash-test',
                    'content' => [['type' => 'text', 'text' => $texto]],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            '*'                  => Http::response([], 200),
        ]);
    }

    /**
     * Artículo del catálogo con su vector guardado por SQL crudo.
     *
     * @param string     $nombre
     * @param float|null $s      Similitud con la consulta; null = sin embedding.
     * @param array      $extra  Columnas extra (stock, final_price...).
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
                'embedding' => json_encode([$s, sqrt(max(0.0, 1 - $s * $s)), 0.0]),
            ]);
        }

        return $article;
    }

    /**
     * Siembra los tres artículos del caso real (similitud alta con la consulta, así entran siempre
     * por el lado vectorial además del léxico).
     *
     * @return void
     */
    protected function sembrar_articulos_de_vinoteca()
    {
        $this->articulo(self::MALBEC, 0.90, ['final_price' => 38000, 'stock' => 12]);
        $this->articulo(self::ESTUCHE, 0.85, ['final_price' => 25000, 'stock' => 4]);
        $this->articulo(self::RUTINI, 0.80, ['final_price' => 30000, 'stock' => 6]);
    }

    /**
     * Crea una promo de vinoteca.
     *
     * @param string         $nombre
     * @param float|null     $precio
     * @param int|null       $stock
     * @param User|null      $dueno   Dueño (por defecto el comercio de test).
     * @return PromocionVinoteca
     */
    protected function promo($nombre, $precio, $stock, $dueno = null)
    {
        return PromocionVinoteca::create([
            'name'        => $nombre,
            'slug'        => Str::slug($nombre) . '-' . uniqid(),
            'final_price' => $precio,
            'stock'       => $stock,
            'online'      => 0,
            'user_id'     => ($dueno ? $dueno : $this->comercio)->id,
        ]);
    }

    /**
     * Las dos promos del caso real.
     *
     * @return void
     */
    protected function sembrar_promos_catena()
    {
        $this->promo('Mix Ernesto Catena', 45000, 3);
        $this->promo('Mix Catena Zapata', 52000, 0);
    }

    /**
     * Crea un combo con sus artículos componentes.
     *
     * @param string $nombre
     * @param float  $precio
     * @param array  $componentes Lista de [stock del artículo, cantidad por combo].
     * @return Combo
     */
    protected function combo($nombre, $precio, array $componentes)
    {
        $combo = Combo::create(['name' => $nombre, 'num' => random_int(900000, 999999), 'price' => $precio, 'user_id' => $this->comercio->id]);

        foreach ($componentes as $indice => $componente) {
            $articulo = $this->articulo('Componente ' . $nombre . ' ' . $indice, null, ['stock' => $componente[0]]);
            $combo->articles()->attach($articulo->id, ['amount' => $componente[1]]);
        }

        return $combo;
    }

    /**
     * Simula un mensaje del cliente con el simulador del agente y devuelve el último turno `user` que
     * recibió la IA (mensaje + catálogo + bloque de promos).
     *
     * @param string $mensaje Texto del cliente.
     * @return string
     */
    protected function turno_simulado($mensaje)
    {
        $this->fingir_deepseek_y_openai();

        (new WhatsappBotAiService())->simular_respuesta($this->comercio->id, $mensaje);

        return $this->ultimo_turno();
    }

    /**
     * Último turno `user` del último payload que salió hacia el proveedor de IA.
     *
     * @return string
     */
    protected function ultimo_turno()
    {
        $this->assertNotEmpty($this->payloads_ia, 'Tendría que haber salido un request a DeepSeek.');

        $payload = $this->payloads_ia[count($this->payloads_ia) - 1];
        $turnos  = $payload['messages'];
        $ultimo  = $turnos[count($turnos) - 1];

        $this->assertSame('user', $ultimo['role']);
        $this->assertIsString($ultimo['content']);

        return $ultimo['content'];
    }

    /**
     * Líneas del turno que arrancan con `- Promo:`.
     *
     * @param string $turno
     * @return array<int,string>
     */
    protected function lineas_de_promo($turno)
    {
        return array_values(array_filter(explode("\n", $turno), function ($linea) {
            return strpos($linea, '- Promo:') === 0;
        }));
    }

    /**
     * Lee una constante privada del service (encabezados del catálogo de artículos, que no se tocan).
     *
     * @param string $nombre `CATALOG_HEADER`, `CATALOG_EMPTY` o `RAG_LIMIT`.
     * @return mixed
     */
    protected function constante_del_service($nombre)
    {
        $constante = new ReflectionClassConstant(WhatsappBotAiService::class, $nombre);

        return $constante->getValue();
    }

    /**
     * El turno de HOY (sin la capa nueva): mensaje + "\n\n" + el bloque de artículos que el service
     * arma con la misma búsqueda híbrida, invocando `build_catalog_block` por reflexión.
     *
     * @param string $mensaje Texto del cliente.
     * @return string
     */
    protected function turno_de_hoy($mensaje)
    {
        $articulos = (new ArticleEmbeddingService())
            ->search_hybrid_articles($mensaje, (int) $this->comercio->id, $this->constante_del_service('RAG_LIMIT'))
            ->all();

        $metodo = new ReflectionMethod(WhatsappBotAiService::class, 'build_catalog_block');
        $metodo->setAccessible(true);
        $bloque = $metodo->invoke(new WhatsappBotAiService(), $articulos, $this->comercio->fresh(), [], []);

        return $mensaje . "\n\n" . $bloque;
    }

    /**
     * Chat persistido (con su config de bot) y un historial de mensajes [direction, texto].
     *
     * @param array $mensajes Lista de [direction, texto], en orden cronológico.
     * @return array{0:WhatsappChat,1:WhatsappBotConfig}
     */
    protected function chat_con_historial(array $mensajes)
    {
        $config = WhatsappBotConfig::create([
            'user_id'                  => $this->comercio->id,
            'kapso_api_key'            => 'kapso-t190',
            'phone_number_id'          => '5491100000190',
            'webhook_secret'           => 'secreto-t190',
            'is_active'                => false,
            'ai_enabled_default'       => true,
            'ai_confirm_delay_seconds' => 120,
        ]);

        $chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => self::PHONE,
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        foreach ($mensajes as $indice => $mensaje) {
            WhatsappChatMessage::create([
                'whatsapp_chat_id' => $chat->id,
                'direction'        => $mensaje[0],
                'source'           => $mensaje[0] === 'in' ? 'cliente' : 'ia',
                'body'             => $mensaje[1],
                'created_at'       => now()->subMinutes(10 - $indice),
            ]);
        }

        return [$chat, $config];
    }

    // ── CA1 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA1 — Caso real: «¿Tenés el mix Catena?» trae las DOS promos, en orden por nombre, con la
     * regla de preguntar cuál, y ningún vino ni el estuche. Hoy «mix» se descarta (3 letras) y el
     * payload trae el estuche y ninguna promo.
     *
     * @group whatsapp
     * @test
     */
    public function ca1_el_mix_catena_trae_las_dos_promos_y_ningun_vino_suelto()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $turno = $this->turno_simulado('¿Tenés el mix Catena?');

        $this->assertStringContainsString(self::HEADER, $turno);

        $zapata   = strpos($turno, '- Promo: Mix Catena Zapata | Precio: $52.000,00 | Stock: 0');
        $ernesto  = strpos($turno, '- Promo: Mix Ernesto Catena | Precio: $45.000,00 | Stock: 3');
        $this->assertNotFalse($zapata, 'Falta la línea de Mix Catena Zapata.');
        $this->assertNotFalse($ernesto, 'Falta la línea de Mix Ernesto Catena.');
        $this->assertLessThan($ernesto, $zapata, 'Las promos van ordenadas por nombre.');

        $this->assertStringContainsString(self::RULE_SEVERAL, $turno);
        $this->assertStringContainsString(self::RULE_NO_STOCK, $turno);
        $this->assertStringContainsString(self::RULE_STRICT, $turno);

        $this->assertStringNotContainsString(self::MALBEC, $turno);
        $this->assertStringNotContainsString('Estuche Catena Zapata', $turno);
        $this->assertStringNotContainsString($this->constante_del_service('CATALOG_HEADER'), $turno);
        $this->assertStringNotContainsString($this->constante_del_service('CATALOG_EMPTY'), $turno);

        foreach ($this->lineas_de_promo($turno) as $linea) {
            $this->assertStringNotContainsString('Código:', $linea);
            $this->assertStringNotContainsString('Link:', $linea);
        }
    }

    // ── CA2 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA2 — Una sola coincidencia: sólo la línea de Mix Catena Zapata y la regla de «uno solo».
     *
     * @group whatsapp
     * @test
     */
    public function ca2_con_una_sola_coincidencia_contesta_con_esa()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $turno = $this->turno_simulado('¿Tenés stock del mix Catena Zapata?');

        $this->assertStringContainsString('- Promo: Mix Catena Zapata | Precio: $52.000,00 | Stock: 0', $turno);
        $this->assertStringNotContainsString('Mix Ernesto Catena', $turno);
        $this->assertStringContainsString(self::RULE_ONE, $turno);
        $this->assertStringNotContainsString(self::RULE_SEVERAL, $turno);
    }

    // ── CA3 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA3 — Sin coincidencia: el turno termina en la nota NONE (lo consulta, sin ofrecer otro
     * producto) y no trae ni promos ni el Rutini ni el catálogo vacío.
     *
     * @group whatsapp
     * @test
     */
    public function ca3_sin_coincidencia_dice_que_lo_consulta_y_no_ofrece_otro()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $turno = $this->turno_simulado('¿Tenés el mix Rutini?');

        $this->assertStringEndsWith(self::NONE, $turno);
        $this->assertStringNotContainsString('- Promo:', $turno);
        $this->assertStringNotContainsString(self::RUTINI, $turno);
        $this->assertStringNotContainsString($this->constante_del_service('CATALOG_EMPTY'), $turno);
    }

    // ── CA4 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA4a — Combos: comercio con la extensión `combos` (sin `vinoteca`). El stock del combo es el
     * mínimo de floor(stock/cantidad): A 10 × 2 = 5, B 3 × 1 = 3 → 3.
     *
     * @group whatsapp
     * @test
     */
    public function ca4_el_combo_trae_su_stock_calculado()
    {
        $this->dar_extension($this->comercio, 'combos');
        $this->combo('Combo Asado Malbec', 30000, [[10, 2], [3, 1]]);

        $turno = $this->turno_simulado('¿Tenés el combo asado?');

        $this->assertStringContainsString('- Combo: Combo Asado Malbec | Precio: $30.000,00 | Stock: 3', $turno);
        $this->assertStringContainsString(self::RULE_ONE, $turno);
    }

    /**
     * CA4b — Con las dos extensiones, «combo» también busca en Promos: «¿Tenés el combo Catena?»
     * trae las dos promos Catena (lo que pidió Fernando).
     *
     * @group whatsapp
     * @test
     */
    public function ca4_combo_tambien_busca_en_promos()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->dar_extension($this->comercio, 'combos');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $turno = $this->turno_simulado('¿Tenés el combo Catena?');

        $this->assertStringContainsString('- Promo: Mix Catena Zapata | Precio: $52.000,00 | Stock: 0', $turno);
        $this->assertStringContainsString('- Promo: Mix Ernesto Catena | Precio: $45.000,00 | Stock: 3', $turno);
        $this->assertStringContainsString(self::RULE_SEVERAL, $turno);
    }

    // ── CA5 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA5 — Un artículo que SÍ se llama mix se conserva en el catálogo de artículos; como ninguna
     * promo coincide, el turno suma NONE, y no queda ningún artículo sin la palabra mix.
     *
     * @group whatsapp
     * @test
     */
    public function ca5_un_articulo_que_se_llama_mix_se_conserva()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();
        $this->articulo('MIX FRUTOS SECOS 200G', 0.70, ['final_price' => 4500, 'stock' => 20]);

        $turno = $this->turno_simulado('¿Tenés el mix de frutos secos?');

        $this->assertStringContainsString('- MIX FRUTOS SECOS 200G', $turno);
        $this->assertStringContainsString(self::NONE, $turno);
        $this->assertStringNotContainsString('- Promo:', $turno);
        $this->assertStringNotContainsString(self::MALBEC, $turno);
        $this->assertStringNotContainsString('Estuche Catena Zapata', $turno);
        $this->assertStringNotContainsString(self::RUTINI, $turno);
    }

    // ── CA6 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA6a — Modo suave («promo»): los artículos de hoy se conservan y DESPUÉS va el bloque de promos
     * con las dos, la regla de varias y sin la regla estricta (puede estar preguntando por un descuento).
     *
     * @group whatsapp
     * @test
     */
    public function ca6_promo_suma_el_bloque_sin_sacar_articulos()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $turno = $this->turno_simulado('¿Hay alguna promo de Catena?');

        $this->assertStringContainsString($this->constante_del_service('CATALOG_HEADER'), $turno);
        $this->assertStringContainsString('- ' . self::MALBEC, $turno);

        $this->assertStringContainsString(self::HEADER, $turno);
        $this->assertGreaterThan(
            strpos($turno, $this->constante_del_service('CATALOG_HEADER')),
            strpos($turno, self::HEADER),
            'El bloque de promos va después del de artículos.'
        );

        $this->assertCount(2, $this->lineas_de_promo($turno));
        $this->assertStringContainsString(self::RULE_SEVERAL, $turno);
        $this->assertStringNotContainsString(self::RULE_STRICT, $turno);
    }

    /**
     * CA6b — Modo suave sin coincidencia: el turno es idéntico al de hoy (sin HEADER ni NONE). Fija
     * "no cambia": pasa también antes de implementar.
     *
     * @group whatsapp
     * @test
     */
    public function ca6_promo_sin_coincidencia_no_cambia_nada()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $mensaje = '¿Hay promo en el Rutini?';
        $turno   = $this->turno_simulado($mensaje);

        $this->assertSame($this->turno_de_hoy($mensaje), $turno);
        $this->assertStringNotContainsString(self::HEADER, $turno);
        $this->assertStringNotContainsString(self::NONE, $turno);
    }

    // ── CA7 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA7 — Sin palabra disparadora nada cambia: con las promos cargadas, «¿Tenés Catena Zapata
     * Malbec?» llega exactamente como hoy. Fija "no cambia": pasa también antes de implementar.
     *
     * @group whatsapp
     * @test
     */
    public function ca7_sin_palabra_disparadora_el_turno_es_el_de_hoy()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $mensaje = '¿Tenés Catena Zapata Malbec?';
        $turno   = $this->turno_simulado($mensaje);

        $this->assertSame($this->turno_de_hoy($mensaje), $turno);
        $this->assertStringNotContainsString(self::HEADER, $turno);
    }

    // ── CA8 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA8 — Sin el módulo nada cambia: comercio SIN `vinoteca` ni `combos`, con las mismas promos en
     * la tabla. Fija "no cambia": pasa también antes de implementar.
     *
     * @group whatsapp
     * @test
     */
    public function ca8_sin_modulo_el_turno_es_el_de_hoy()
    {
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $mensaje = '¿Tenés el mix Catena?';
        $turno   = $this->turno_simulado($mensaje);

        $this->assertStringContainsString('Estuche Catena Zapata 2 copas', $turno);
        $this->assertStringNotContainsString(self::HEADER, $turno);
        $this->assertStringNotContainsString(self::NONE, $turno);
        $this->assertSame($this->turno_de_hoy($mensaje), $turno);
    }

    // ── CA9 ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA9 — Aislamiento: la promo de otro negocio y una propia borrada (soft delete) no aparecen; en
     * CA1 siguen siendo exactamente dos líneas `- Promo:`.
     *
     * @group whatsapp
     * @test
     */
    public function ca9_no_aparecen_promos_de_otro_negocio_ni_borradas()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();
        $this->promo('Mix Catena Reserva', 60000, 5, $this->otro_comercio);
        $this->promo('Mix Catena Viejo', 10000, 9)->delete();

        $turno = $this->turno_simulado('¿Tenés el mix Catena?');

        $this->assertCount(2, $this->lineas_de_promo($turno));
        $this->assertStringNotContainsString('Mix Catena Reserva', $turno);
        $this->assertStringNotContainsString('Mix Catena Viejo', $turno);
    }

    // ── CA10 ────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA10a — Desambiguación en dos turnos (chat real persistido): tras «Tengo dos... ¿Cuál querés?»,
     * «El de Ernesto» sigue en modo estricto y trae sólo esa promo.
     *
     * @group whatsapp
     * @test
     */
    public function ca10_la_respuesta_a_cual_sigue_en_el_mix()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();
        $this->fingir_deepseek_y_openai();

        list($chat, $config) = $this->chat_con_historial([
            ['in', '¿Tenés el mix Catena?'],
            ['out', 'Tengo dos: Mix Ernesto Catena y Mix Catena Zapata. ¿Cuál querés?'],
            ['in', 'El de Ernesto'],
        ]);

        (new WhatsappBotAiService())->generate_response_with_photo($chat, $config);

        $turno = $this->ultimo_turno();

        $lineas = $this->lineas_de_promo($turno);
        $this->assertSame(['- Promo: Mix Ernesto Catena | Precio: $45.000,00 | Stock: 3'], $lineas);
        $this->assertStringContainsString(self::RULE_ONE, $turno);
        $this->assertStringContainsString(self::RULE_STRICT, $turno);
        $this->assertStringNotContainsString(self::MALBEC, $turno);
    }

    /**
     * CA10b — «El primero» no nombra ninguna: vuelven las dos promos y la regla de varias, para que el
     * modelo resuelva con el historial.
     *
     * @group whatsapp
     * @test
     */
    public function ca10_el_primero_vuelve_a_traer_las_dos()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();
        $this->fingir_deepseek_y_openai();

        list($chat, $config) = $this->chat_con_historial([
            ['in', '¿Tenés el mix Catena?'],
            ['out', 'Tengo dos: Mix Ernesto Catena y Mix Catena Zapata. ¿Cuál querés?'],
            ['in', 'El primero'],
        ]);

        (new WhatsappBotAiService())->generate_response_with_photo($chat, $config);

        $turno = $this->ultimo_turno();

        $this->assertCount(2, $this->lineas_de_promo($turno));
        $this->assertStringContainsString(self::RULE_SEVERAL, $turno);
        $this->assertStringNotContainsString(self::MALBEC, $turno);
    }

    // ── CA11 ────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA11 — Stock sin dato: una promo con stock null y un combo cuyos artículos no controlan stock
     * salen sin `Stock:` (no se inventa), y va la regla de «lo consultás y le confirmás».
     *
     * @group whatsapp
     * @test
     */
    public function ca11_sin_stock_cargado_no_se_inventa()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->dar_extension($this->comercio, 'combos');
        $this->promo('Mix Sorpresa', 20000, null);
        $this->combo('Combo Delicia', 15000, [[null, 1], [null, 2]]);

        $turno_promo = $this->turno_simulado('¿Tenés el mix sorpresa?');

        $this->assertContains('- Promo: Mix Sorpresa | Precio: $20.000,00', explode("\n", $turno_promo));
        $this->assertStringContainsString(self::RULE_NO_STOCK, $turno_promo);
        $this->assertNotSame('', $this->solo_lineas_del_bloque($turno_promo), 'Tendría que haber una línea de promo.');
        $this->assertStringNotContainsString('Stock:', $this->solo_lineas_del_bloque($turno_promo));

        $turno_combo = $this->turno_simulado('¿Tenés el combo delicia?');

        $this->assertContains('- Combo: Combo Delicia | Precio: $15.000,00', explode("\n", $turno_combo));
        $this->assertStringContainsString(self::RULE_NO_STOCK, $turno_combo);
    }

    /**
     * Las líneas `- Promo:` y `- Combo:` de un turno, unidas (para afirmar que no traen `Stock:`).
     *
     * @param string $turno
     * @return string
     */
    protected function solo_lineas_del_bloque($turno)
    {
        return implode("\n", array_filter(explode("\n", $turno), function ($linea) {
            return strpos($linea, '- Promo:') === 0 || strpos($linea, '- Combo:') === 0;
        }));
    }

    // ── CA12 ────────────────────────────────────────────────────────────────────────────────────

    /**
     * CA12 — Lista y tope: «¿Qué mixes tenés?» sin nombre trae todas por nombre (con 3: las 3 y la
     * regla de lista, sin «hay más»); con 12 trae exactamente las 10 primeras y la frase de «hay más».
     *
     * @group whatsapp
     * @test
     */
    public function ca12_que_mixes_tenes_lista_y_corta_en_diez()
    {
        $this->dar_extension($this->comercio, 'vinoteca');

        foreach (['01', '02', '03'] as $numero) {
            $this->promo('Mix Delta ' . $numero, 10000, 5);
        }

        $turno = $this->turno_simulado('¿Qué mixes tenés?');

        $this->assertCount(3, $this->lineas_de_promo($turno));
        $this->assertStringContainsString(self::RULE_LIST, $turno);
        $this->assertStringNotContainsString(self::RULE_MORE, $turno);

        foreach (['04', '05', '06', '07', '08', '09', '10', '11', '12'] as $numero) {
            $this->promo('Mix Delta ' . $numero, 10000, 5);
        }

        $turno = $this->turno_simulado('¿Qué mixes tenés?');

        $lineas = $this->lineas_de_promo($turno);
        $this->assertCount(10, $lineas);
        $this->assertStringContainsString('- Promo: Mix Delta 10 |', $turno);
        $this->assertStringNotContainsString('Mix Delta 11', $turno);
        $this->assertStringNotContainsString('Mix Delta 12', $turno);
        $this->assertStringContainsString(self::RULE_MORE, $turno);
    }

    // ── CA13 ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Palabra del cliente y modo que tiene que disparar: '' = no dispara, 'strict' = mix/combo,
     * 'soft' = promo/promoción.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public function palabras_y_modo()
    {
        return [
            'MIX en mayúsculas'     => ['MIX', 'strict'],
            'Mixes en plural'       => ['Mixes', 'strict'],
            'COMBOS en plural'      => ['COMBOS', 'strict'],
            'Promoción con tilde'   => ['Promoción', 'soft'],
            'mixto no dispara'      => ['mixto', ''],
            'combinado no dispara'  => ['combinado', ''],
            'promotor no dispara'   => ['promotor', ''],
        ];
    }

    /**
     * CA13 — Palabras disparadoras: prueba directa sobre el helper (`for_turn`), con un dueño que
     * tiene los dos módulos. Falla hoy porque la clase del helper no existe.
     *
     * @dataProvider palabras_y_modo
     * @group whatsapp
     * @test
     */
    public function ca13_palabras_que_disparan_y_que_no($palabra, $modo_esperado)
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->dar_extension($this->comercio, 'combos');

        $texto     = '¿Tenés ' . $palabra . ' Catena?';
        $historial = collect([new WhatsappChatMessage(['direction' => 'in', 'body' => $texto])]);

        $capa = WhatsappAgentPromosHelper::for_turn($historial, $texto, (int) $this->comercio->id, $this->comercio);

        $this->assertSame($modo_esperado, $capa['mode'], 'Modo para la palabra «' . $palabra . '».');
    }
}
