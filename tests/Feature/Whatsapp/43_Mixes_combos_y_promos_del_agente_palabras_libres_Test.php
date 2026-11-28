<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PromocionVinoteca;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
 * Cubre CA16 del plan (ronda 3): las palabras libres no incluyen las que describen a una promo/combo del negocio (el nombre del mix no rescata vinos). Todo sin red: embeddings de consulta [1,0,0] y proveedor de IA fingidos
 * con `Http::fake`. Vectores de artículo `[s, sqrt(1 - s²), 0]` → coseno = `s`.
 *
 * @group whatsapp
 */
class Mixes_combos_y_promos_del_agente_palabras_libres_Test extends TestCase
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

    // ── Utilidades propias de esta clase ─────────────────────────────────────────────────────────

    /**
     * Corre el agente sobre un chat persistido con ese historial y devuelve el último turno `user`.
     *
     * @param array $mensajes Lista de [direction, texto], en orden cronológico.
     * @return string
     */
    protected function turno_del_chat(array $mensajes)
    {
        $this->fingir_deepseek_y_openai();

        list($chat, $config) = $this->chat_con_historial($mensajes);

        (new WhatsappBotAiService())->generate_response_with_photo($chat, $config);

        return $this->ultimo_turno();
    }

    /**
     * Afirma que el turno es el de HOY para un mensaje común: sin capa de promos de ningún tipo.
     *
     * @param string $turno
     * @return void
     */
    protected function afirmar_sin_capa_de_promos($turno)
    {
        $this->assertStringNotContainsString(self::HEADER, $turno);
        $this->assertStringNotContainsString(self::NONE, $turno);
        $this->assertStringNotContainsString(self::RULE_STRICT, $turno);
        $this->assertSame([], $this->lineas_de_promo($turno));
    }

    // ── CA16 ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Afirma el turno típico de CA16: ningún vino ni estuche Catena, y el bloque con las dos promos.
     *
     * @param string $turno
     * @return void
     */
    protected function afirmar_sin_vinos_catena_y_con_las_dos_promos($turno)
    {
        $this->assertStringNotContainsString(self::MALBEC, $turno, 'El vino Catena no tiene que llegar.');
        $this->assertStringNotContainsString(self::ESTUCHE, $turno, 'El estuche Catena no tiene que llegar.');
        $this->assertCount(2, $this->lineas_de_promo($turno));
        $this->assertStringContainsString('- Promo: Mix Ernesto Catena', $turno);
        $this->assertStringContainsString('- Promo: Mix Catena Zapata', $turno);
        $this->assertStringContainsString(self::RULE_SEVERAL, $turno);
        $this->assertStringContainsString(self::RULE_STRICT, $turno);
    }

    /**
     * CA16a — «¿Tenés el mix?» + «El de Catena»: «Catena» es parte del nombre de las promos, no un
     * producto pedido aparte; no rescata los vinos Catena.
     *
     * @group whatsapp
     * @test
     */
    public function ca16_el_nombre_del_mix_en_un_mensaje_aparte_no_rescata_vinos()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $turno = $this->turno_del_chat([
            ['in', '¿Tenés el mix?'],
            ['in', 'El de Catena'],
        ]);

        $this->afirmar_sin_vinos_catena_y_con_las_dos_promos($turno);
    }

    /**
     * CA16b — «Hola, ¿tenés mix?» + «Zapata»: «Zapata» es palabra del nombre de «Mix Catena Zapata»; no
     * rescata el vino ni el estuche Catena Zapata. El bloque trae la promo que coincide.
     *
     * @group whatsapp
     * @test
     */
    public function ca16_una_palabra_del_nombre_de_la_promo_no_rescata_el_vino()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $turno = $this->turno_del_chat([
            ['in', 'Hola, ¿tenés mix?'],
            ['in', 'Zapata'],
        ]);

        $this->assertStringNotContainsString(self::MALBEC, $turno, 'El vino Catena Zapata no tiene que llegar.');
        $this->assertStringNotContainsString(self::ESTUCHE, $turno, 'El estuche Catena Zapata no tiene que llegar.');
        $this->assertSame(['- Promo: Mix Catena Zapata | Precio: $52.000,00 | Stock: 0'], $this->lineas_de_promo($turno));
        $this->assertStringContainsString(self::RULE_STRICT, $turno);
    }

    /**
     * CA16c — Control (pasa hoy y después): «¿Tenés el mix Catena?» + «También quiero 2 Rutini Cabernet»:
     * «Rutini» no es nombre de ninguna promo, el Rutini llega y los Catena no.
     *
     * @group whatsapp
     * @test
     */
    public function ca16_control_el_vino_pedido_aparte_sigue_llegando()
    {
        $this->dar_extension($this->comercio, 'vinoteca');
        $this->sembrar_articulos_de_vinoteca();
        $this->sembrar_promos_catena();

        $turno = $this->turno_del_chat([
            ['in', '¿Tenés el mix Catena?'],
            ['in', 'También quiero 2 Rutini Cabernet'],
        ]);

        $this->assertStringContainsString(self::RUTINI, $turno, 'El Rutini pedido aparte tiene que llegar.');
        $this->assertStringNotContainsString(self::MALBEC, $turno);
        $this->assertStringNotContainsString(self::ESTUCHE, $turno);
        $this->assertCount(2, $this->lineas_de_promo($turno));
    }
}
