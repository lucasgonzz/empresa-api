<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\BusquedaPorCodigoDeBarrasIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FichaWebArticuloIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\Description;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\SerperUsageEvent;
use App\Models\PermissionEmpresa;
use App\Models\Bodega;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\ProductFacts\ArticleWebFactsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** @group chat-ia */
class Fichas_web_de_articulos_Test extends TestCase
{
    use DatabaseTransactions;

    const URL = 'https://bodega.test/la-mascota-malbec';
    const TEXTO = 'La Mascota Malbec. Origen: Cruz de Piedra, Maipú, Mendoza. Crianza durante 15 meses en roble francés y americano. Color rojo intenso. Aromas de frutas negras y especias. Taninos suaves y final persistente.';

    protected $owner;
    protected $conversation;
    protected $message;
    protected $article;
    protected $extraction;
    protected $pages;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::create(['name' => 'Fichas vinos', 'email' => uniqid('fichas') . '@test.local', 'password' => 'sin-uso']);
        config(['app.USER_ID' => $this->owner->id, 'services.anthropic.api_key' => 'falsa',
            'services.anthropic.base_url' => 'https://api.anthropic.com', 'services.serper.api_key' => '',
            'services.asistente_ia.busqueda_codigo_barras.tope_diario_defecto' => 30]);
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'agentes_ia'], ['name' => 'Agentes de IA']);
        $this->owner->extencions()->attach($extension->id);
        $this->actingAs($this->owner, 'web');
        $this->conversation = AiConversation::create(['user_id' => $this->owner->id, 'auth_user_id' => $this->owner->id]);
        $this->message = AiMessage::create(['ai_conversation_id' => $this->conversation->id, 'rol' => 'assistant', 'estado' => 'listo', 'acciones_habilitadas' => true]);
        $this->article = Article::create(['user_id' => $this->owner->id, 'name' => 'La Mascota Malbec', 'bar_code' => '7791234567898']);
        $this->pages = [self::URL];
        $this->extraction = ['coincide' => true, 'sections' => [
            ['title' => 'Origen', 'content' => 'Cruz de Piedra, Maipú, Mendoza.', 'evidence' => 'Origen: Cruz de Piedra, Maipú, Mendoza.', 'source_url' => self::URL],
            ['title' => 'Elaboración', 'content' => 'Crianza durante 15 meses en roble francés y americano.', 'evidence' => 'Crianza durante 15 meses en roble francés y americano.', 'source_url' => self::URL],
            ['title' => 'Nariz', 'content' => 'Aromas de frutas negras y especias.', 'evidence' => 'Aromas de frutas negras y especias.', 'source_url' => self::URL],
        ], 'faltantes' => 'La fuente no informa la temperatura de fermentación.'];
        $this->app->bind(ArticleWebFactsService::class, function ($app, $parameters) {
            return new FichasWebConDnsDePrueba($parameters['owner'], $parameters['person'] ?? null);
        });
        $this->http();
    }

    protected function http()
    {
        Http::fake(function ($request) {
            if ($request->url() === 'https://api.anthropic.com/v1/messages') {
                if (isset($request['tools'])) {
                    $results = array_map(function ($url) { return ['type' => 'web_search_result', 'url' => $url]; }, $this->pages);
                    return Http::response(['model' => 'haiku-test', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
                        'content' => [['type' => 'web_search_tool_result', 'content' => $results],
                            ['type' => 'text', 'text' => 'https://inventada.test/fuente-no-buscada']]]);
                }
                $payload = json_decode($request['messages'][0]['content'], true);
                $this->assertSame('La Mascota Malbec', $payload['producto']);
                $this->assertStringContainsString('No uses conocimientos previos', $request['system']);
                return Http::response(['model' => 'haiku-test', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 30, 'output_tokens' => 40],
                    'content' => [['type' => 'text', 'text' => json_encode($this->extraction)]]]);
            }
            if ($request->url() === self::URL) {
                return Http::response('<html><p>' . self::TEXTO . '</p></html>', 200, ['Content-Type' => 'text/html']);
            }
            if ($request->url() === 'https://google.serper.dev/search') {
                return Http::response(['organic' => [['link' => self::URL, 'title' => 'Bodega oficial']], 'credits' => 1]);
            }
            throw new \RuntimeException('Solicitud inesperada: ' . $request->url());
        });
    }

    protected function buscar(array $input = [])
    {
        $results = app(AsistenteIaService::class)->execute_tool_calls([['type' => 'tool_use', 'id' => 'ficha-1',
            'name' => 'buscar_ficha_de_articulo', 'input' => array_merge(['articulo' => 'La Mascota Malbec', 'pedido' => 'Elaboración, origen y nota de cata'], $input)]], $this->conversation, $this->message);
        $this->assertEmpty($results[0]['is_error'] ?? false, $results[0]['content']);
        return json_decode($results[0]['content'], true);
    }

    protected function proponer($consulta)
    {
        $result = HerramientasDeCarga::ejecutar('proponer_descripcion_verificada', ['consulta_id' => $consulta], $this->conversation, $this->message);
        return json_decode($result['content'], true);
    }

    protected function confirmar($id)
    {
        return $this->postJson('/api/ai-conversations/' . $this->conversation->id . '/acciones/' . $id . '/confirmar');
    }

    public function test_busca_por_nombre_con_anthropic_sin_serper_y_asigna_la_ficha_con_fuentes_al_confirmar()
    {
        $previa = Description::create(['article_id' => $this->article->id, 'title' => 'Descripción del dueño', 'content' => 'Contenido anterior.', 'ai_generated' => false]);
        $r = $this->buscar();
        $this->assertTrue($r['found'], json_encode($r));
        $this->assertCount(3, $r['sections']);
        $this->assertSame(self::URL, $r['sources'][0]['url']);
        $this->assertSame('Resultado de búsqueda web', $r['sources'][0]['title']);
        $this->assertCount(3, $r['sources'][0]['evidence']);
        $this->assertSame(1, BusquedaPorCodigoDeBarrasIaHelper::busquedas_web_de_hoy($this->owner->id));
        $this->assertSame(1, $this->article->descriptions()->count());
        $propuesta = $this->proponer($r['consulta_id']);
        $this->assertTrue($propuesta['ok']);
        $this->assertTrue($propuesta['requiere_confirmacion']);
        $this->assertSame(AiMessageAction::TIPO_DESCRIPCION_VERIFICADA, $propuesta['tipo']);
        $this->assertContains($propuesta['tipo'], HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);
        $this->confirmar($propuesta['tarjeta_id'])->assertOk();
        $this->assertSame(4, $this->article->descriptions()->count());
        $this->assertSame('Contenido anterior.', $previa->fresh()->content);
        foreach ($this->article->descriptions()->where('ai_generated', true)->get() as $d) {
            $this->assertNotNull($d->ai_reviewed_at);
            $this->assertSame($r['sources'], $d->ai_sources);
        }
        $this->confirmar($propuesta['tarjeta_id'])->assertStatus(409);
        $this->assertSame(4, $this->article->descriptions()->count());
    }

    public function test_fuentes_y_citas_deben_provenir_de_paginas_descargadas_y_no_de_urls_inventadas()
    {
        $this->extraction['sections'][0]['source_url'] = 'https://inventada.test/fuente-no-buscada';
        $this->extraction['sections'][1]['evidence'] = 'Esta cita no está en ninguna página.';
        $r = $this->buscar();
        $this->assertTrue($r['found']);
        $this->assertSame(['Olfato'], array_column($r['sections'], 'title'));
        $this->extraction['sections'][2]['evidence'] = 'Otra cita inventada.';
        $r = $this->buscar();
        $this->assertFalse($r['found']);
        $this->assertArrayNotHasKey('consulta_id', $r);
        $this->assertSame(0, $this->article->descriptions()->count());
    }

    public function test_no_mezcla_productos_ni_añadas_cuando_la_extraccion_no_confirma_identidad()
    {
        $this->extraction['coincide'] = false;
        $r = $this->buscar();
        $this->assertFalse($r['found']);
        $this->assertArrayNotHasKey('consulta_id', $r);
        $this->assertFalse($this->proponer('id-inventado')['ok']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $this->conversation->id)->count());
    }

    public function test_sin_resultados_reales_o_con_destino_privado_no_inventa_una_ficha()
    {
        $this->pages = [];
        $this->assertFalse($this->buscar()['found']);
        $this->pages = ['https://interno.test/ficha'];
        $this->assertFalse($this->buscar()['found']);
        Http::assertNotSent(function ($request) { return strpos($request->url(), 'interno.test') !== false || strpos($request->url(), 'inventada.test') !== false; });
    }

    public function test_un_enlace_publico_se_lee_sin_busqueda_y_se_guarda_como_texto_seguro()
    {
        $this->extraction['sections'][0]['content'] = '<script>alert(1)</script>Origen &lt;img src=x onerror=alert(2)&gt;';
        $this->extraction['sections'][0]['title'] = '<b>Origen</b>';
        $r = $this->buscar(['url' => self::URL]);
        $this->assertTrue($r['found']);
        $this->assertSame(0, BusquedaPorCodigoDeBarrasIaHelper::busquedas_web_de_hoy($this->owner->id));
        $p = $this->proponer($r['consulta_id']);
        $this->confirmar($p['tarjeta_id'])->assertOk();
        $saved = $this->article->descriptions()->where('title', 'Origen')->firstOrFail();
        $this->assertStringNotContainsString('<script', $saved->content);
        $this->assertStringNotContainsString('<img', $saved->content);
        $this->assertStringNotContainsString('alert', $saved->content);
        $this->assertSame('Origen: Cruz de Piedra, Maipú, Mendoza.', $saved->content);
    }

    public function test_no_admite_articulo_ajeno_ni_evidencia_de_otra_conversacion()
    {
        $other = User::create(['name' => 'Otro', 'email' => uniqid() . '@test.local', 'password' => 'sin-uso']);
        $foreign = Article::create(['user_id' => $other->id, 'name' => 'Ajeno']);
        $this->assertArrayHasKey('error', $this->buscar(['articulo_id' => $foreign->id]));
        $r = $this->buscar();
        $this->conversation = AiConversation::create(['user_id' => $this->owner->id, 'auth_user_id' => $this->owner->id]);
        $this->message = AiMessage::create(['ai_conversation_id' => $this->conversation->id, 'rol' => 'assistant', 'estado' => 'listo']);
        $this->assertFalse($this->proponer($r['consulta_id'])['ok']);
    }

    public function test_revalida_tenencia_e_identidad_al_confirmar_y_conserva_cambios_previos()
    {
        $r = $this->buscar();
        $p = $this->proponer($r['consulta_id']);
        $this->article->name = 'Gran Mascota Malbec';
        $this->article->save();
        $this->confirmar($p['tarjeta_id'])->assertStatus(422);
        $this->assertSame(0, $this->article->descriptions()->count());
    }

    public function test_empleado_sin_permiso_no_busca_ni_propone_y_el_permiso_se_revalida_al_confirmar()
    {
        $empleado = User::create(['name' => 'Empleado', 'email' => uniqid() . '@test.local', 'password' => 'sin-uso', 'owner_id' => $this->owner->id]);
        $this->conversation->auth_user_id = $empleado->id;
        $this->conversation->save();
        $this->assertArrayHasKey('error', $this->buscar());
        $this->conversation->auth_user_id = $this->owner->id;
        $this->conversation->save();
        $r = $this->buscar();
        $this->owner->extencions()->detach();
        $this->owner->unsetRelation('extencions');
        $this->assertFalse($this->proponer($r['consulta_id'])['ok']);
    }

    public function test_el_prompt_y_las_herramientas_ofrecen_busqueda_y_descripcion_en_app_y_whatsapp()
    {
        $service = app(AsistenteIaService::class);
        foreach ([false, true] as $wsp) {
            $tools = array_column($service->build_tools(true, $wsp, $this->owner, !$wsp), 'name');
            $this->assertContains('buscar_ficha_de_articulo', $tools);
            $this->assertContains('proponer_descripcion_verificada', $tools);
            $prompt = $service->build_system_prompt($this->conversation, $this->owner, true, $wsp, !$wsp);
            $this->assertStringContainsString('buscar_ficha_de_articulo investiga', $prompt);
            $this->assertStringNotContainsString('Un campo que no existe en que_puedo_cargar no existe', $prompt);
        }
    }

    public function test_el_cupo_web_corta_antes_de_llamar_al_proveedor_y_explicita_el_motivo()
    {
        $this->owner->plan_ia_tope_busquedas_web_diarias = 1;
        $this->owner->save();
        $this->assertTrue($this->buscar()['found']);
        $antes = Http::recorded()->count();
        $r = $this->buscar();
        $this->assertStringContainsString('tope diario', $r['error']);
        $this->assertSame($antes, Http::recorded()->count());
    }

    public function test_si_el_articulo_cambia_de_dueno_o_se_retira_la_extension_no_escribe_al_confirmar()
    {
        $r = $this->buscar();
        $p = $this->proponer($r['consulta_id']);
        $other = User::create(['name' => 'Otro dueño', 'email' => uniqid() . '@test.local', 'password' => 'sin-uso']);
        $this->article->user_id = $other->id;
        $this->article->save();
        $this->confirmar($p['tarjeta_id'])->assertStatus(422);
        $this->assertSame(0, $this->article->descriptions()->count());
        $this->article->user_id = $this->owner->id;
        $this->article->save();
        $p = $this->proponer($r['consulta_id']);
        $this->owner->extencions()->detach();
        // El middleware del módulo corta con 403 antes de llegar al ejecutor.
        $this->confirmar($p['tarjeta_id'])->assertForbidden();
        $this->assertSame(0, $this->article->descriptions()->count());
    }

    public function test_muestra_alternativas_ante_nombre_ambiguo_sin_investigar_otro_vino()
    {
        Article::create(['user_id' => $this->owner->id, 'name' => 'La Mascota Cabernet Franc']);
        $r = $this->buscar(['articulo' => 'La Mascota']);
        $this->assertCount(2, $r['opciones']);
        Http::assertNothingSent();
    }

    public function test_una_cita_real_no_habilita_publicar_un_resumen_inventado_ni_un_titulo_con_hechos()
    {
        $this->extraction['sections'][0]['content'] = 'Viñedos del Valle de Uco y crianza de 24 meses.';
        $this->extraction['sections'][1]['title'] = 'Crianza durante 24 meses';
        $r = $this->buscar();
        $this->assertSame(['Origen', 'Olfato'], array_column($r['sections'], 'title'));
        $this->assertSame('Origen: Cruz de Piedra, Maipú, Mendoza.', $r['sections'][0]['content']);
        $p = $this->proponer($r['consulta_id']);
        $this->confirmar($p['tarjeta_id'])->assertOk();
        $published = $this->article->descriptions()->pluck('content')->implode(' ');
        $this->assertStringNotContainsString('Valle de Uco', $published);
        $this->assertStringNotContainsString('24 meses', $published);
        $sources = $this->article->descriptions()->first()->ai_sources;
        $this->assertSame('Origen: Cruz de Piedra, Maipú, Mendoza.', $sources[0]['evidence'][0]['quote']);
    }

    public function test_serper_cuenta_el_evento_real_del_cupo_sin_inventar_consumo_de_tokens()
    {
        config(['services.serper.api_key' => 'clave-falsa-serper']);
        $r = $this->buscar();
        $this->assertTrue($r['found']);
        $this->assertSame(1, BusquedaPorCodigoDeBarrasIaHelper::busquedas_web_de_hoy($this->owner->id));
        $this->assertSame(1, SerperUsageEvent::where('user_id', $this->owner->id)->where('process', 'ficha_articulo_web')->count());
        $this->assertSame(0, AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', BusquedaPorCodigoDeBarrasIaHelper::PROCESO_WEB)->count());
        $this->assertSame(1, AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', 'ficha_articulo_extraccion')->count());
    }

    public function test_el_permiso_de_edicion_del_empleado_se_revalida_si_se_retiro_despues_de_la_propuesta()
    {
        $employee = User::create(['name' => 'Empleado con permiso', 'email' => uniqid() . '@test.local', 'password' => 'sin-uso', 'owner_id' => $this->owner->id]);
        foreach (['article.index', 'article.update'] as $slug) {
            $permission = PermissionEmpresa::where('slug', $slug)->first();
            if (!$permission) {
                $permission = PermissionEmpresa::forceCreate(['name' => $slug, 'slug' => $slug]);
            }
            $employee->permissions()->attach($permission->id);
        }
        $this->conversation->auth_user_id = $employee->id;
        $this->conversation->save();
        $this->actingAs($employee, 'web');
        $r = $this->buscar();
        $this->assertTrue($r['found']);
        $p = $this->proponer($r['consulta_id']);
        $this->assertTrue($p['ok']);
        $employee->permissions()->detach(PermissionEmpresa::where('slug', 'article.update')->value('id'));
        $employee->unsetRelation('permissions');
        // El ejecutor revalida article.update aunque el canal ya haya autenticado a la persona.
        $result = EjecutorAccionesIaHelper::confirmar($this->conversation, $p['tarjeta_id'], $employee, function () { return 1; });
        $this->assertSame(422, $result['status']);
        // La ruta web añade su propia restricción de acceso al asistente para empleados.
        $this->confirmar($p['tarjeta_id'])->assertForbidden();
        $this->assertSame(0, $this->article->descriptions()->count());
    }

    public function test_revalida_la_bodega_separada_del_nombre_al_confirmar()
    {
        $bodega = Bodega::forceCreate(['name' => 'Mascota Vineyards', 'user_id' => $this->owner->id]);
        $this->article->bodega_id = $bodega->id;
        $this->article->save();
        $r = $this->buscar();
        $p = $this->proponer($r['consulta_id']);
        $this->article->bodega_id = null;
        $this->article->save();
        $this->confirmar($p['tarjeta_id'])->assertStatus(422);
        $this->assertSame(0, $this->article->descriptions()->count());
    }
}

class FichasWebConDnsDePrueba extends ArticleWebFactsService
{
    protected function resolver_host($host)
    {
        return $host === 'interno.test' ? ['10.0.0.5'] : ['93.184.216.34'];
    }
}
