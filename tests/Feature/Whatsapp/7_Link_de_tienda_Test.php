<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Article;
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
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/7: el link a la tienda online en el catálogo del agente.
 *
 * El agente ahora le puede pasar al cliente el link del producto en la tienda del negocio,
 * con el mismo formato que arma `App\Mail\Advise`: `{users.online}/articulos/{slug}/{user_id}`.
 *
 * 🔴 D7, y este archivo es la garantía explícita de la decisión: a propósito NO se replica
 * la condición `APP_ENV == 'production'` que tiene el mail. En el mail existe porque una
 * campaña disparada desde una máquina de desarrollo le llegaría igual a un cliente real con
 * un link roto; el agente, en cambio, solo contesta conversaciones que entraron por un
 * número de Kapso real. Y con la condición puesta, esta funcionalidad sería imposible de
 * probar fuera de producción — que es justo el agujero que la misión vino a tapar. El gate
 * real y suficiente es que el negocio tenga tienda cargada (`users.online`).
 *
 * También se protege que la sexta regla fija (la del link) no se haya llevado puesta a
 * ninguna de las cinco viejas: sin la nueva, el modelo duda en pasar una URL o se la
 * inventa; sin las viejas, inventa precios.
 */
class Link_de_tienda_Test extends TestCase
{
    use DatabaseTransactions;

    /** Las cinco reglas fijas que ya existían antes de la misión. No se tocan. */
    const REGLAS_VIEJAS = [
        'Respondé solo con información que esté en el catálogo de productos de abajo o en la conversación.',
        'Si no sabés algo, decilo con honestidad y ofrecé que una persona del negocio siga la consulta.',
        'Nunca inventes precios ni stock que no estén en el catálogo de productos.',
        'Nunca confirmes pagos ni acuerdes descuentos que no te hayan sido informados explícitamente acá.',
        'Texto plano, sin markdown, sin asteriscos ni listas con guiones.',
    ];

    /** La regla nueva, la que habilita pasar el link tal cual. */
    const REGLA_DEL_LINK = 'Si un producto del catálogo de abajo trae un Link, podés pasárselo al cliente tal cual, sin modificarlo.';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    /** Payload que viajó a Anthropic. */
    protected $payload_anthropic = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: cada test setea su fake si la necesita.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-7',
            'company_name' => 'Ferreteria F8-7',
            'email'        => 'whatsapp-f8-7-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'         => $this->comercio->id,
            'kapso_api_key'   => 'kapso-f8-7',
            'phone_number_id' => '5491100000007',
            'webhook_secret'  => 'secreto-f8-7',
            'is_active'       => true,
        ]);

        $this->chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => '5493416004455',
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => '¿Tenés tornillos de 3 pulgadas?',
        ]);
    }

    /**
     * Stubs de red. El `'*'` final evita que una request sin stub salga de verdad.
     *
     * @return void
     */
    protected function fakes_de_red()
    {
        Http::fake([
            'api.openai.com/*'    => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => function ($request) {
                $this->payload_anthropic = $request->data();

                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => 'Acá tenés el link.']],
                    'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Artículo activo con vector cargado, que es lo que necesita el RAG en MySQL
     * (`search_similar_articles_in_php` filtra por status activo y embedding no nulo).
     *
     * @param string      $nombre
     * @param string|null $slug
     * @return Article
     */
    protected function articulo_vectorizado($nombre, $slug)
    {
        $article = Article::create([
            'name'        => $nombre,
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'final_price' => 1500.50,
            'stock'       => 40,
            'slug'        => $slug,
        ]);

        DB::table('articles')->where('id', $article->id)->update([
            'embedding' => json_encode([1.0, 0.0, 0.0]),
        ]);

        return $article->fresh();
    }

    /**
     * Contenido completo del último turno de usuario que viajó a Anthropic: ahí adentro va
     * pegado el bloque de catálogo.
     *
     * @return string
     */
    protected function ultimo_turno_de_usuario()
    {
        $this->assertNotNull($this->payload_anthropic, 'No llegó a viajar ningún payload a Anthropic.');

        $mensajes = $this->payload_anthropic['messages'];

        return (string) $mensajes[count($mensajes) - 1]['content'];
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_busqueda_de_catalogo_devuelve_el_slug()
    {
        $this->fakes_de_red();
        $this->articulo_vectorizado('Tornillo de 3 pulgadas', 'tornillo-de-3-pulgadas');

        $resultados = (new ArticleEmbeddingService())
            ->search_similar_articles('tornillos', (int) $this->comercio->id, 5);

        $this->assertCount(1, $resultados);
        $this->assertTrue(
            property_exists($resultados[0], 'slug'),
            'Sin slug en el SELECT, el agente no puede armar el link a la tienda.'
        );
        $this->assertEquals('tornillo-de-3-pulgadas', $resultados[0]->slug);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_tienda_cargada_el_catalogo_le_pasa_el_link_al_modelo()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $this->comercio->online = 'https://tienda.test';
        $this->comercio->save();

        $this->articulo_vectorizado('Tornillo de 3 pulgadas', 'tornillo-de-3-pulgadas');

        (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $contenido = $this->ultimo_turno_de_usuario();

        $this->assertStringContainsString('Tornillo de 3 pulgadas', $contenido);
        $this->assertStringContainsString(
            'https://tienda.test/articulos/tornillo-de-3-pulgadas/' . $this->comercio->id,
            $contenido,
            'El link tiene que armarse con el mismo formato que App\Mail\Advise.'
        );

        // D7: esto corre con APP_ENV = testing, y el link se arma igual. Si alguien
        // "corrigiera" el service agregando la condición de producción, este test se cae.
        $this->assertEquals(
            'testing',
            config('app.env'),
            'La garantía de D7 solo vale si este test corre fuera de producción.'
        );
    }

    /**
     * @group whatsapp
     * @test
     */
    public function sin_tienda_cargada_no_aparece_ningun_link()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        // El comercio no tiene tienda online: `users.online` vacío es el gate real.
        $this->assertEmpty($this->comercio->online);

        $this->articulo_vectorizado('Tornillo de 3 pulgadas', 'tornillo-de-3-pulgadas');

        (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $contenido = $this->ultimo_turno_de_usuario();

        $this->assertStringContainsString('Tornillo de 3 pulgadas', $contenido, 'El artículo sigue estando en el catálogo...');
        $this->assertStringNotContainsString('| Link:', $contenido, '...pero sin tienda no hay link que pasar.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_articulo_sin_slug_no_trae_link()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        $this->comercio->online = 'https://tienda.test';
        $this->comercio->save();

        $this->articulo_vectorizado('Tornillo sin slug', null);

        (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $contenido = $this->ultimo_turno_de_usuario();

        $this->assertStringContainsString('Tornillo sin slug', $contenido);
        $this->assertStringNotContainsString('| Link:', $contenido, 'Sin slug no hay URL posible: no se inventa ninguna.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_prompt_de_sistema_conserva_las_cinco_reglas_viejas_y_suma_la_del_link()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red();

        (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertNotNull($this->payload_anthropic);
        $system = (string) $this->payload_anthropic['system'];

        foreach (self::REGLAS_VIEJAS as $regla) {
            $this->assertStringContainsString(
                $regla,
                $system,
                'La regla fija vieja tiene que seguir textual: es lo que impide que la IA invente precios.'
            );
        }

        $this->assertStringContainsString(
            self::REGLA_DEL_LINK,
            $system,
            'Sin esta regla, las de "solo lo que está en el catálogo" hacen que el modelo dude en pasar la URL.'
        );
    }
}
