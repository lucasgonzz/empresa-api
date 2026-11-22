<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\article\DestacadoDeArticuloHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261121 (M1): destacar un artículo en la tienda (o quitarle el destacado) por
 * una tarjeta de confirmación (proponer_destacado_articulo), con la misma cuenta que la estrella del Listado.
 *
 * @group chat-ia
 */
class U200_Destacado_de_articulo_por_asistente_Test extends TestCase
{
    use DatabaseTransactions;

    protected $dueno;
    protected $conversation;
    protected $mensaje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringStartsWith('empresa_testing', DB::connection()->getDatabaseName());
        config(['services.anthropic.api_key' => null]);
        $this->dueno = User::create(['name' => 'Destacados', 'email' => uniqid('destacados') . '@test.local', 'password' => 'sin-uso']);
        // La ruta de confirmar exige la extensión de Agentes de IA (igual que los demás tests del asistente).
        $extension = ExtencionEmpresa::where('slug', 'agentes_ia')->first() ?: ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        $this->dueno->extencions()->attach($extension->id);
        $this->actingAs($this->dueno, 'web');
        $this->nueva_conversacion();
    }

    protected function nueva_conversacion()
    {
        $this->conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        $this->mensaje = AiMessage::create(['ai_conversation_id' => $this->conversation->id, 'rol' => 'assistant', 'estado' => 'listo', 'contenido' => 'Listo.', 'acciones_habilitadas' => true]);
    }

    protected function articulo($nombre, array $extra = [])
    {
        return Article::create(array_merge(['name' => $nombre, 'user_id' => $this->dueno->id, 'status' => 'active', 'online' => 1], $extra));
    }

    protected function proponer(array $input)
    {
        $r = HerramientasDeCarga::ejecutar('proponer_destacado_articulo', $input, $this->conversation, $this->mensaje);
        $this->assertFalse($r['is_error'], $r['content']);
        return json_decode($r['content'], true);
    }

    protected function tarjeta()
    {
        return AiMessageAction::where('ai_message_id', $this->mensaje->id)->orderByDesc('id')->firstOrFail();
    }

    protected function confirmar($tarjeta = null)
    {
        $tarjeta = $tarjeta ?: $this->tarjeta();
        return $this->postJson('api/ai-conversations/' . $this->conversation->id . '/acciones/' . $tarjeta->id . '/confirmar');
    }

    public function test_destacar_arma_una_tarjeta_que_no_cambia_nada_hasta_confirmar_y_queda_al_final()
    {
        $otro = $this->articulo('Zz Destacado previo U200', ['featured' => 1]);
        $a = $this->articulo('Zz Cerveza Roja U200');

        $r = $this->proponer(['articulo' => 'Zz Cerveza Roja U200', 'destacado' => true]);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(AiMessageAction::TIPO_DESTACADO_ARTICULO, $r['tipo']);
        $this->assertNull($a->fresh()->featured, 'La tarjeta no cambia nada hasta que se confirma.');
        $this->assertSame('propuesta', $this->tarjeta()->estado);

        $this->confirmar()->assertStatus(200);
        $this->assertEquals(2, $a->fresh()->featured, 'Queda al final de los destacados (cantidad + 1).');
        $this->assertEquals(1, $otro->fresh()->featured);
    }

    public function test_quitar_el_destacado_lo_deja_en_null()
    {
        $a = $this->articulo('Zz Cerveza Negra U200', ['featured' => 3]);

        $this->proponer(['articulo_id' => $a->id, 'destacado' => false]);
        $this->assertEquals(3, $a->fresh()->featured);
        $this->confirmar()->assertStatus(200);
        $this->assertNull($a->fresh()->featured);
    }

    public function test_si_ya_esta_en_el_estado_pedido_lo_dice_y_no_arma_tarjeta()
    {
        $destacado = $this->articulo('Zz Ya Destacado U200', ['featured' => 1]);
        $comun = $this->articulo('Zz Nunca Destacado U200');

        $r = $this->proponer(['articulo_id' => $destacado->id, 'destacado' => true]);
        $this->assertTrue($r['ya_estaba']);
        $this->assertTrue($r['sin_tarjeta']);
        $this->assertStringContainsString('ya está destacado', $r['texto']);

        $r = $this->proponer(['articulo_id' => $comun->id, 'destacado' => false]);
        $this->assertTrue($r['ya_estaba']);
        $this->assertStringContainsString('ya no estaba', $r['texto']);

        $this->assertSame(0, AiMessageAction::where('ai_message_id', $this->mensaje->id)->count());
    }

    public function test_articulo_inexistente_ajeno_o_ambiguo_no_arma_tarjeta()
    {
        $r = $this->proponer(['articulo' => 'Zz No Existe U200', 'destacado' => true]);
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['error']);

        $ajeno_dueno = User::create(['name' => 'Otro', 'email' => uniqid('otro') . '@test.local', 'password' => 'sin-uso']);
        $ajeno = Article::create(['name' => 'Zz Del Otro U200', 'user_id' => $ajeno_dueno->id, 'status' => 'active']);
        $r = $this->proponer(['articulo_id' => $ajeno->id, 'destacado' => true]);
        $this->assertFalse($r['ok']);
        $this->assertNull($ajeno->fresh()->featured);

        $this->articulo('Zz Malbec Reserva U200');
        $this->articulo('Zz Malbec Clasico U200');
        $r = $this->proponer(['articulo' => 'Zz Malbec U200', 'destacado' => true]);
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['faltan'], 'Ambiguo: pregunta cuál.');
        $this->assertCount(2, (array) $r['opciones']['articulos']);

        $r = $this->proponer(['articulo' => 'Zz Malbec Reserva U200']);
        $this->assertFalse($r['ok'], 'Sin destacado true/false se pregunta.');
        $this->assertNotEmpty($r['faltan']);

        $this->assertSame(0, AiMessageAction::where('ai_message_id', $this->mensaje->id)->count());
    }

    public function test_un_empleado_sin_permiso_no_puede_proponer_ni_confirmar()
    {
        $a = $this->articulo('Zz Protegido U200');
        $empleado = User::create(['name' => 'Sin permiso', 'email' => uniqid('empleado') . '@test.local', 'password' => 'sin-uso', 'owner_id' => $this->dueno->id]);

        // Propone el dueño y confirma un empleado sin permiso: tampoco se ejecuta.
        $this->proponer(['articulo_id' => $a->id, 'destacado' => true]);
        $tarjeta = $this->tarjeta();
        $this->conversation->update(['auth_user_id' => $empleado->id]);
        $this->actingAs($empleado->fresh(), 'web');
        // Lo frena la ruta (403) o el ejecutor (422): en los dos casos no se ejecuta.
        $this->assertContains($this->confirmar($tarjeta)->getStatusCode(), [403, 422]);
        $this->assertNull($a->fresh()->featured);

        // Y proponer con el empleado sin permiso devuelve el motivo.
        $r = $this->proponer(['articulo_id' => $a->id, 'destacado' => true]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('permiso', $r['error']);
    }

    public function test_un_encargado_con_acceso_de_administrador_si_puede()
    {
        $a = $this->articulo('Zz Encargado U200');
        $encargado = User::create(['name' => 'Encargado', 'email' => uniqid('encargado') . '@test.local', 'password' => 'sin-uso', 'owner_id' => $this->dueno->id, 'admin_access' => 1]);
        $this->conversation->update(['auth_user_id' => $encargado->id]);
        $this->actingAs($encargado, 'web');

        $this->proponer(['articulo_id' => $a->id, 'destacado' => true]);
        $this->confirmar()->assertStatus(200);
        $this->assertNotNull($a->fresh()->featured);
    }

    public function test_si_cambio_el_estado_entre_la_propuesta_y_el_si_no_se_invierte_a_ciegas()
    {
        $a = $this->articulo('Zz Carrera U200');
        $this->proponer(['articulo_id' => $a->id, 'destacado' => true]);
        // Alguien lo destacó desde la pantalla mientras la tarjeta esperaba.
        $a->featured = 7;
        $a->save();

        $this->confirmar()->assertStatus(200);
        $this->assertEquals(7, $a->fresh()->featured, 'Sigue destacado con su orden: no se vuelve a calcular ni se quita.');
    }

    public function test_un_articulo_no_publicado_avisa_en_la_tarjeta()
    {
        $a = $this->articulo('Zz Sin Publicar U200', ['online' => 0]);
        $this->proponer(['articulo_id' => $a->id, 'destacado' => true]);
        $renglones = array_column($this->tarjeta()->presentacion['renglones'], 'valor', 'etiqueta');
        $this->assertArrayHasKey('Atención', $renglones);
        $this->assertStringContainsString('no está publicado', $renglones['Atención']);
    }

    public function test_nunca_se_auto_confirma_ni_en_modo_directo()
    {
        $this->assertContains(AiMessageAction::TIPO_DESTACADO_ARTICULO, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);
        $this->assertNotContains(AiMessageAction::TIPO_DESTACADO_ARTICULO, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);

        $this->dueno->agente_confianza = 'directo';
        $this->dueno->save();
        $a = $this->articulo('Zz Directo U200');
        $this->proponer(['articulo_id' => $a->id, 'destacado' => true]);
        $this->assertNull($a->fresh()->featured);
        $this->assertSame('propuesta', $this->tarjeta()->estado);
    }

    public function test_la_herramienta_esta_declarada_al_final_y_la_estrella_del_listado_usa_la_misma_cuenta()
    {
        $this->assertTrue(HerramientasDeCarga::maneja('proponer_destacado_articulo'));
        $this->assertSame('proponer_destacado_articulo', HerramientasDeCarga::nombres()[count(HerramientasDeCarga::nombres()) - 1]);

        // La pantalla: GET api/article/set-featured/{id} alterna con la misma cuenta del helper.
        $uno = $this->articulo('Zz Estrella Uno U200');
        $dos = $this->articulo('Zz Estrella Dos U200');
        $this->getJson('api/article/set-featured/' . $uno->id)->assertStatus(200);
        $this->getJson('api/article/set-featured/' . $dos->id)->assertStatus(200);
        $this->assertEquals(1, $uno->fresh()->featured);
        $this->assertEquals(2, $dos->fresh()->featured);
        $this->getJson('api/article/set-featured/' . $uno->id)->assertStatus(200);
        $this->assertNull($uno->fresh()->featured);

        $this->assertTrue(DestacadoDeArticuloHelper::fijar($uno->fresh(), true, $this->dueno->id));
        $this->assertFalse(DestacadoDeArticuloHelper::fijar($uno->fresh(), true, $this->dueno->id), 'Ya estaba: no cambia.');
    }

    public function test_el_ejecutor_revalida_el_permiso_de_quien_confirma()
    {
        $a = $this->articulo('Zz Permiso Ejecutor U200');
        $this->proponer(['articulo_id' => $a->id, 'destacado' => true]);
        $tarjeta = $this->tarjeta();
        $empleado = User::create(['name' => 'Sin permiso', 'email' => uniqid('empleado') . '@test.local', 'password' => 'sin-uso', 'owner_id' => $this->dueno->id]);
        $contexto = ContextoDeCargaIa::de_la_conversacion($this->conversation, $empleado);
        try {
            \App\Http\Controllers\Helpers\asistente_ia\PropuestaDestacadoArticuloIaHelper::ejecutar($contexto, $tarjeta);
            $this->fail('Sin permiso no se puede destacar.');
        } catch (\App\Http\Controllers\Helpers\asistente_ia\AccionIaException $e) {
            $this->assertStringContainsString('permiso', $e->getMessage());
        }
        $this->assertNull($a->fresh()->featured);
    }
}
