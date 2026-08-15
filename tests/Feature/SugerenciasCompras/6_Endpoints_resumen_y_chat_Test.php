<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Jobs\GeneratePurchaseSuggestionChunksJob;
use App\Jobs\GenerarResumenSugerenciaCompraJob;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\Provider;
use App\Models\ProviderPriceOffer;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\PurchaseSuggestion\ResumenIaComprasService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 6: el endpoint paginado de
 * líneas, la tenencia, el resumen IA y la conversación del chat.
 *
 * 🔴 El test que más importa de este archivo es el reparto de la posta
 * (con_credenciales_el_chunk_job_no_notifica_y_el_aviso_sale_del_job_del_resumen
 * / sin_credenciales_notifica_el_chunk_job_y_no_se_despacha_el_resumen): el
 * bug que previene SOLO aparece en producción con la cola `database` — acá,
 * con la cola `sync` de los tests, el orden equivocado se vería bien igual.
 * Por eso el test verifica el DESPACHO y la bandera notificar_al_terminar,
 * no el resultado final.
 */
class Endpoints_resumen_y_chat_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /**
     * Texto que el fake de Anthropic responde en la PRÓXIMA llamada.
     * @var string
     */
    protected $texto_de_resumen_fake = '';

    /**
     * true cuando el stub de api.anthropic.com ya quedó registrado en este test.
     * @var bool
     */
    protected $fake_de_anthropic_registrado = false;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio endpoints P6',
            'email'    => 'sugerencias-compras-p6-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->actingAs($this->comercio, 'web');
    }

    /**
     * @param string $slug
     * @param string $nombre
     * @return void
     */
    protected function dar_extension($slug, $nombre)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $nombre]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * @param array $overrides
     * @return PurchaseSuggestion
     */
    protected function crear_suggestion(array $overrides = [])
    {
        return PurchaseSuggestion::create(array_merge([
            'user_id'           => $this->comercio->id,
            'status'            => 'pendiente',
            'origen_generacion' => 'manual',
        ], $overrides));
    }

    /**
     * Línea directa de la sugerencia (sin correr el pipeline: acá se prueba
     * el endpoint/el chat, no el cálculo).
     *
     * @param PurchaseSuggestion $suggestion
     * @param array $overrides
     * @return PurchaseSuggestionArticle
     */
    protected function crear_linea($suggestion, array $overrides = [])
    {
        $nombre_articulo = isset($overrides['article_name']) ? $overrides['article_name'] : 'Articulo linea P6';
        unset($overrides['article_name']);

        $article = Article::create(['name' => $nombre_articulo, 'user_id' => $this->comercio->id]);

        return PurchaseSuggestionArticle::create(array_merge([
            'purchase_suggestion_id' => $suggestion->id,
            'article_id'             => $article->id,
            'cantidad_sugerida'      => 5,
        ], $overrides));
    }

    /**
     * Corre GenerarResumenSugerenciaCompraJob contra un fake que responde el
     * texto dado. El stub se registra UNA sola vez por test y lee el texto
     * vigente de la propiedad (molde de ChatIa 9_Conversacion_de_sugerencia):
     * Http::fake ACUMULA stubs y el handler toma el PRIMERO que matchea, así
     * que un stub nuevo por corrida dejaría al primero respondiendo siempre.
     *
     * @param PurchaseSuggestion $suggestion
     * @param string $texto
     * @param bool $con_posta
     * @return void
     */
    protected function correr_el_job($suggestion, $texto, $con_posta = false)
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->texto_de_resumen_fake = $texto;

        if (!$this->fake_de_anthropic_registrado) {
            Http::fake([
                'api.anthropic.com/*' => function () {
                    return Http::response([
                        'model'   => 'claude-modelo-fake',
                        'content' => [
                            ['type' => 'text', 'text' => $this->texto_de_resumen_fake],
                        ],
                        'usage'   => ['input_tokens' => 400, 'output_tokens' => 90],
                    ], 200);
                },
            ]);

            $this->fake_de_anthropic_registrado = true;
        }

        (new GenerarResumenSugerenciaCompraJob($suggestion->id, $con_posta))->handle();
    }

    // ------------------------------------------------------------------
    // Paginado
    // ------------------------------------------------------------------

    /**
     * @group sugerencias-compras
     * @test
     */
    public function el_per_page_respeta_el_pedido_el_techo_y_el_default()
    {
        $this->dar_extension('sugerencias_compras', 'Sugerencias de compra a proveedores');
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);

        for ($i = 0; $i < 12; $i++) {
            $this->crear_linea($suggestion, ['prioridad' => $i + 1]);
        }

        $base = 'api/purchase-suggestion/' . $suggestion->id . '/articles';

        $response = $this->getJson($base . '?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 10);
        $this->assertCount(10, $response->json('models.data'));

        $response = $this->getJson($base . '?per_page=9999');
        $response->assertStatus(200);
        $response->assertJsonPath('models.per_page', 500);

        foreach (['0', '-5', 'no-es-un-numero'] as $valor_invalido) {
            $response = $this->getJson($base . '?per_page=' . $valor_invalido);
            $response->assertStatus(200);
            $response->assertJsonPath('models.per_page', 50);
        }
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function filtra_por_provider_id()
    {
        $this->dar_extension('sugerencias_compras', 'Sugerencias de compra a proveedores');
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);

        $provider_a = Provider::create(['name' => 'Proveedor A P6', 'user_id' => $this->comercio->id]);
        $provider_b = Provider::create(['name' => 'Proveedor B P6', 'user_id' => $this->comercio->id]);

        $this->crear_linea($suggestion, ['provider_id' => $provider_a->id]);
        $this->crear_linea($suggestion, ['provider_id' => $provider_b->id]);
        $this->crear_linea($suggestion, ['provider_id' => $provider_b->id]);

        $response = $this->getJson('api/purchase-suggestion/' . $suggestion->id . '/articles?provider_id=' . $provider_b->id);

        $response->assertStatus(200);
        $data = $response->json('models.data');
        $this->assertCount(2, $data);

        foreach ($data as $fila) {
            $this->assertEquals($provider_b->id, $fila['provider_id']);
        }
    }

    /**
     * Las tres órdenes soportadas: prioridad (default), cantidad y ahorro.
     *
     * @group sugerencias-compras
     * @test
     */
    public function ordena_por_prioridad_cantidad_o_ahorro_segun_el_parametro()
    {
        $this->dar_extension('sugerencias_compras', 'Sugerencias de compra a proveedores');
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);

        $linea1 = $this->crear_linea($suggestion, ['prioridad' => 2, 'cantidad_sugerida' => 5, 'ahorro_estimado' => 100]);
        $linea2 = $this->crear_linea($suggestion, ['prioridad' => 1, 'cantidad_sugerida' => 20, 'ahorro_estimado' => null]);
        $linea3 = $this->crear_linea($suggestion, ['prioridad' => 3, 'cantidad_sugerida' => 10, 'ahorro_estimado' => 50]);

        $base = 'api/purchase-suggestion/' . $suggestion->id . '/articles';

        // Default: prioridad ascendente.
        $response = $this->getJson($base);
        $ids = array_column($response->json('models.data'), 'purchase_suggestion_article_id');
        $this->assertEquals([$linea2->id, $linea1->id, $linea3->id], $ids);

        // cantidad: descendente.
        $response = $this->getJson($base . '?order=cantidad');
        $ids = array_column($response->json('models.data'), 'purchase_suggestion_article_id');
        $this->assertEquals([$linea2->id, $linea3->id, $linea1->id], $ids);

        // ahorro: descendente, con el null al final.
        $response = $this->getJson($base . '?order=ahorro');
        $ids = array_column($response->json('models.data'), 'purchase_suggestion_article_id');
        $this->assertEquals([$linea1->id, $linea3->id, $linea2->id], $ids);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function cada_linea_trae_el_nombre_del_articulo_en_la_clave_name()
    {
        $this->dar_extension('sugerencias_compras', 'Sugerencias de compra a proveedores');
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);
        $this->crear_linea($suggestion, ['article_name' => 'Martillo con nombre P6']);

        $response = $this->getJson('api/purchase-suggestion/' . $suggestion->id . '/articles');

        $response->assertStatus(200);
        $data = $response->json('models.data');

        $this->assertArrayHasKey('name', $data[0]);
        $this->assertEquals('Martillo con nombre P6', $data[0]['name']);
    }

    // ------------------------------------------------------------------
    // Tenencia
    // ------------------------------------------------------------------

    /**
     * @group sugerencias-compras
     * @test
     */
    public function un_comercio_no_puede_operar_la_sugerencia_de_otro_los_cinco_metodos_dan_404()
    {
        $this->dar_extension('sugerencias_compras', 'Sugerencias de compra a proveedores');

        $otro_comercio = User::create([
            'name'     => 'Otro comercio P6',
            'email'    => 'sugerencias-compras-p6-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $suggestion_ajena = PurchaseSuggestion::create([
            'user_id' => $otro_comercio->id,
            'status'  => 'terminado',
        ]);

        $articulo_ajeno = Article::create(['name' => 'Articulo ajeno P6', 'user_id' => $otro_comercio->id]);
        $linea_ajena = PurchaseSuggestionArticle::create([
            'purchase_suggestion_id' => $suggestion_ajena->id,
            'article_id'             => $articulo_ajeno->id,
            'cantidad_sugerida'      => 5,
        ]);

        $base = 'api/purchase-suggestion/' . $suggestion_ajena->id;

        $this->getJson($base)->assertStatus(404);
        $this->deleteJson($base)->assertStatus(404);
        $this->getJson($base . '/articles')->assertStatus(404);
        $this->postJson($base . '/resumen')->assertStatus(404);
        $this->postJson($base . '/create-provider-order', ['purchase_suggestion_article_ids' => [$linea_ajena->id]])->assertStatus(404);

        // Y la sugerencia ajena sigue intacta: ningún 404 tuvo efecto de borrado.
        $this->assertNotNull(PurchaseSuggestion::find($suggestion_ajena->id));
    }

    // ------------------------------------------------------------------
    // POST /resumen
    // ------------------------------------------------------------------

    /**
     * @group sugerencias-compras
     * @test
     */
    public function post_resumen_sobre_una_sugerencia_no_terminada_devuelve_422()
    {
        $this->dar_extension('sugerencias_compras', 'Sugerencias de compra a proveedores');
        $suggestion = $this->crear_suggestion(['status' => 'pendiente']);

        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/resumen');

        $response->assertStatus(422);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function post_resumen_sin_credenciales_devuelve_422()
    {
        $this->dar_extension('sugerencias_compras', 'Sugerencias de compra a proveedores');
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);
        // config ya quedó con api_key null desde el setUp: no hay credenciales.

        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/resumen');

        $response->assertStatus(422);
    }

    /**
     * El endpoint despacha el job SIN la posta: la corrida ya fue anunciada
     * cuando terminó, este es solo un reintento del resumen.
     *
     * @group sugerencias-compras
     * @test
     */
    public function post_resumen_con_credenciales_despacha_el_job_sin_la_posta()
    {
        $this->dar_extension('sugerencias_compras', 'Sugerencias de compra a proveedores');
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Queue::fake();

        $suggestion = $this->crear_suggestion(['status' => 'terminado']);

        $response = $this->postJson('api/purchase-suggestion/' . $suggestion->id . '/resumen');
        $response->assertStatus(200);

        Queue::assertPushed(GenerarResumenSugerenciaCompraJob::class, function ($job) {
            return $job->notificar_al_terminar === false;
        });

        $suggestion->refresh();
        $this->assertEquals('pendiente', $suggestion->resumen_ia_estado);
    }

    // ------------------------------------------------------------------
    // Conversación del chat
    // ------------------------------------------------------------------

    /**
     * @group sugerencias-compras
     * @test
     */
    public function con_la_extension_el_job_deja_una_conversacion_del_dueno_con_el_resumen()
    {
        $this->dar_extension('asistente_ia', 'Asistente IA');
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);
        $this->crear_linea($suggestion, ['prioridad' => 1]);

        $this->correr_el_job($suggestion, 'Convendria priorizar al proveedor mas barato esta semana.');

        $suggestion->refresh();
        $this->assertEquals('listo', $suggestion->resumen_ia_estado);

        $conversaciones = AiConversation::where('origen', 'sugerencia_compra')
            ->where('referencia_id', $suggestion->id)
            ->get();
        $this->assertCount(1, $conversaciones);

        $conversation = $conversaciones[0];
        $this->assertEquals($this->comercio->id, (int) $conversation->user_id);
        $this->assertEquals($this->comercio->id, (int) $conversation->auth_user_id);
        $this->assertEquals('Sugerencia de compra #' . $suggestion->id, $conversation->titulo);
        $this->assertNotNull($conversation->last_message_at);

        $mensajes = AiMessage::where('ai_conversation_id', $conversation->id)->get();
        $this->assertCount(1, $mensajes);
        $this->assertEquals('assistant', $mensajes[0]->rol);
        $this->assertEquals('listo', $mensajes[0]->estado);
        $this->assertEquals('Convendria priorizar al proveedor mas barato esta semana.', $mensajes[0]->contenido);
    }

    /**
     * 🔴 R13, blindaje: el contexto de la conversación es EXACTAMENTE
     * ResumenIaComprasService::armar_datos() — nunca la instrucción de
     * redacción de armar_prompt() (molde de
     * ChatIa/9_Conversacion_de_sugerencia_Test.php:302-322).
     *
     * @group sugerencias-compras
     * @test
     */
    public function el_contexto_guarda_exactamente_armar_datos_y_no_la_instruccion_de_redactar()
    {
        $this->dar_extension('asistente_ia', 'Asistente IA');
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);
        $this->crear_linea($suggestion, ['article_name' => 'Bulon contexto P6', 'prioridad' => 1]);

        $this->correr_el_job($suggestion, 'Resumen para el contexto.');

        $conversation = AiConversation::where('referencia_id', $suggestion->id)->first();
        $this->assertNotNull($conversation);

        // Igualdad EXACTA: el contexto es armar_datos(), ni un carácter más.
        $esperado = (new ResumenIaComprasService())->armar_datos($suggestion->fresh());
        $this->assertEquals($esperado, $conversation->contexto);

        $this->assertStringContainsString('Datos ya calculados:', $conversation->contexto);
        $this->assertStringContainsString('Bulon contexto P6', $conversation->contexto);

        // Y JAMÁS la instrucción de redacción de armar_prompt().
        $this->assertStringNotContainsString('Responde solo con el texto plano', $conversation->contexto);
        $this->assertStringNotContainsString('Maximo 6 oraciones', $conversation->contexto);
        $this->assertStringNotContainsString('encargado de compras', $conversation->contexto);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function correr_el_job_dos_veces_actualiza_la_conversacion_en_vez_de_duplicarla()
    {
        $this->dar_extension('asistente_ia', 'Asistente IA');
        $suggestion = $this->crear_suggestion(['status' => 'terminado']);
        $this->crear_linea($suggestion, ['prioridad' => 1]);

        $this->correr_el_job($suggestion, 'Primer resumen.');
        $this->correr_el_job($suggestion, 'Segundo resumen, corregido tras el reintento.');

        $conversaciones = AiConversation::where('origen', 'sugerencia_compra')
            ->where('referencia_id', $suggestion->id)
            ->get();
        $this->assertCount(1, $conversaciones, 'El reintento no puede duplicar la conversación.');

        $mensajes = AiMessage::where('ai_conversation_id', $conversaciones[0]->id)->get();
        $this->assertCount(1, $mensajes, 'El reintento tampoco puede apilar mensajes.');
        $this->assertEquals('Segundo resumen, corregido tras el reintento.', $mensajes[0]->contenido);
    }

    // ------------------------------------------------------------------
    // 🔴 R9 — el reparto de la posta de notificación
    // ------------------------------------------------------------------

    /**
     * Con credenciales, el chunk job NO notifica: le pasa la posta al job del
     * resumen (notificar_al_terminar === true), que es quien avisa una vez
     * que la conversación ya existe.
     *
     * @group sugerencias-compras
     * @test
     */
    public function con_credenciales_el_chunk_job_no_notifica_y_el_aviso_sale_del_job_del_resumen()
    {
        $this->dar_extension('asistente_ia', 'Asistente IA');
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Queue::fake();

        $articulo = Article::create(['name' => 'Taladro posta P6', 'user_id' => $this->comercio->id]);
        $deposito = Address::create(['street' => 'Deposito posta P6', 'user_id' => $this->comercio->id]);
        $articulo->addresses()->attach($deposito->id, ['amount' => 2, 'stock_min' => 10]);

        $suggestion = PurchaseSuggestion::create(['user_id' => $this->comercio->id, 'status' => 'pendiente']);
        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertEquals('terminado', $suggestion->status);
        $this->assertEquals('pendiente', $suggestion->resumen_ia_estado);

        // Mitad 1: el chunk job cerró la corrida SIN notificar...
        Notification::assertNothingSent();
        // ...porque le pasó la posta al job del resumen.
        Queue::assertPushed(GenerarResumenSugerenciaCompraJob::class, function ($job) {
            return $job->notificar_al_terminar === true;
        });

        // Mitad 2: corre el job del resumen (lo que en producción hace el
        // worker de la cola database) y el aviso sale con el botón del chat.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Habria que reponer el taladro pronto.']],
                'usage'   => ['input_tokens' => 300, 'output_tokens' => 60],
            ], 200),
        ]);

        (new GenerarResumenSugerenciaCompraJob($suggestion->id, true))->handle();

        $conversation = AiConversation::where('referencia_id', $suggestion->id)->first();
        $this->assertNotNull($conversation, 'La conversación existe ANTES del aviso: ese es el arreglo que este test protege.');

        Notification::assertSentTo(
            $this->comercio,
            GlobalNotification::class,
            function ($notification) use ($conversation) {
                $textos = array_column($notification->functions_to_execute, 'btn_text');

                return $textos === ['Ver sugerencia', 'Charlar con la IA', 'Entendido']
                    && (int) $notification->info_to_show[0]['ai_conversation_id'] === $conversation->id;
            }
        );
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }

    /**
     * Sin credenciales no hay job de resumen que pueda avisar: el chunk job
     * notifica inline, como siempre, con los dos botones de hoy.
     *
     * @group sugerencias-compras
     * @test
     */
    public function sin_credenciales_notifica_el_chunk_job_y_no_se_despacha_el_resumen()
    {
        config(['services.anthropic.api_key' => '']);
        Queue::fake();

        $articulo = Article::create(['name' => 'Taladro sin IA P6', 'user_id' => $this->comercio->id]);
        $deposito = Address::create(['street' => 'Deposito sin IA P6', 'user_id' => $this->comercio->id]);
        $articulo->addresses()->attach($deposito->id, ['amount' => 2, 'stock_min' => 10]);

        $suggestion = PurchaseSuggestion::create(['user_id' => $this->comercio->id, 'status' => 'pendiente']);
        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();

        $this->assertEquals('terminado', $suggestion->fresh()->status);
        Queue::assertNotPushed(GenerarResumenSugerenciaCompraJob::class);

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) {
            $funciones = array_column($notification->functions_to_execute, 'btn_text');

            return $notification->message_text === 'Sugerencia de compra terminada'
                && $funciones === ['Ver sugerencia', 'Entendido']
                && !array_key_exists('ai_conversation_id', $notification->info_to_show[0]);
        });
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }

    // ------------------------------------------------------------------
    // Tool de lectura del chat: consultar_precios_de_proveedores
    // ------------------------------------------------------------------

    /**
     * @group sugerencias-compras
     * @test
     */
    public function build_tools_incluye_consultar_precios_de_proveedores()
    {
        $tools = (new AsistenteIaService())->build_tools();
        $nombres = array_column($tools, 'name');

        $this->assertContains('consultar_precios_de_proveedores', $nombres);

        $tool = null;
        foreach ($tools as $t) {
            if ($t['name'] === 'consultar_precios_de_proveedores') {
                $tool = $t;
            }
        }

        $this->assertNotNull($tool);
        $this->assertEquals(['busqueda'], $tool['input_schema']['required']);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function execute_tool_calls_de_precios_usa_el_owner_id_de_la_conversacion_y_respeta_max_results()
    {
        // 25 pares (artículo, proveedor) distintos del comercio del test:
        // más que MAX_RESULTS (20), para probar el techo.
        $proveedor = Provider::create(['name' => 'Proveedor tool P6', 'user_id' => $this->comercio->id]);

        for ($i = 0; $i < 25; $i++) {
            $articulo = Article::create(['name' => 'Articulo tool P6 ' . $i, 'user_id' => $this->comercio->id]);

            ProviderPriceOffer::create([
                'user_id'     => $this->comercio->id,
                'article_id'  => $articulo->id,
                'provider_id' => $proveedor->id,
                'cost'        => 10 + $i,
                'moneda_id'   => 1,
                'origen'      => 'importacion',
                'fecha'       => now()->toDateString(),
            ]);
        }

        // Oferta de OTRO comercio: no puede filtrarse en el resultado.
        $otro_comercio = User::create([
            'name'     => 'Otro dueno tool P6',
            'email'    => 'sugerencias-compras-p6-tool-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $articulo_ajeno = Article::create(['name' => 'Articulo de otro dueno P6', 'user_id' => $otro_comercio->id]);
        $proveedor_ajeno = Provider::create(['name' => 'Proveedor de otro dueno P6', 'user_id' => $otro_comercio->id]);
        ProviderPriceOffer::create([
            'user_id'     => $otro_comercio->id,
            'article_id'  => $articulo_ajeno->id,
            'provider_id' => $proveedor_ajeno->id,
            'cost'        => 1,
            'moneda_id'   => 1,
            'origen'      => 'importacion',
            'fecha'       => now()->toDateString(),
        ]);

        $conversation = AiConversation::create([
            'user_id' => $this->comercio->id,
            // auth_user_id BIEN distinto del dueño: si execute_tool_calls
            // usara esta clave en vez de user_id, el resultado saldría vacío.
            'auth_user_id'  => 999999,
            'origen'        => 'sugerencia_compra',
            'referencia_id' => 1,
            'titulo'        => 'Conversacion tool P6',
        ]);

        $content_blocks = [[
            'type'  => 'tool_use',
            'id'    => 'toolu_1',
            'name'  => 'consultar_precios_de_proveedores',
            'input' => ['busqueda' => ''],
        ]];

        $resultados = (new AsistenteIaService())->execute_tool_calls($content_blocks, $conversation);

        $this->assertCount(1, $resultados);
        $this->assertArrayNotHasKey('is_error', $resultados[0]);

        $data = json_decode($resultados[0]['content'], true);
        $this->assertCount(20, $data, 'MAX_RESULTS = 20 tiene que capar el resultado.');
        $this->assertStringNotContainsString(
            'Articulo de otro dueno P6',
            $resultados[0]['content'],
            'No puede filtrarse data de otro comercio: tiene que usar el owner_id de la conversación.'
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function una_tool_desconocida_devuelve_is_error()
    {
        $conversation = AiConversation::create([
            'user_id'       => $this->comercio->id,
            'auth_user_id'  => $this->comercio->id,
            'origen'        => 'sugerencia_compra',
            'referencia_id' => 1,
            'titulo'        => 'Conversacion tool desconocida P6',
        ]);

        $content_blocks = [[
            'type'  => 'tool_use',
            'id'    => 'toolu_2',
            'name'  => 'tool_que_no_existe',
            'input' => [],
        ]];

        $resultados = (new AsistenteIaService())->execute_tool_calls($content_blocks, $conversation);

        $this->assertCount(1, $resultados);
        $this->assertTrue($resultados[0]['is_error']);
        $this->assertStringContainsString('Tool desconocida', $resultados[0]['content']);
    }
}
