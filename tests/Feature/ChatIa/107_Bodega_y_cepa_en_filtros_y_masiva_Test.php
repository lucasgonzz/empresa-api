<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\FiltroDeArticulosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\ArticleController;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\MasiveUpdate;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión asistente-truvari-pedidos-fallidos (13/11/2026), B1 — F1 y F2 del inventario.
 *
 * EL CASO REAL. Fernando (Truvari, vinoteca) le pidió a su asistente «vinculá todos los Weinert al
 * proveedor Grupo Vid» y le contestó «el proveedor no se puede cambiar desde esta pantalla». La
 * masiva ya sabía asignar proveedor; lo que no sabía era FILTRAR por bodega: bodega y cepa estaban
 * afuera del filtro a propósito (se filtran por id y a la IA le llegan por nombre). Ahora son
 * relaciones por nombre, como proveedor o marca, y también se pueden asignar en la masiva, SOLO con
 * el módulo Vinoteca (sin él, la columna existe pero la pantalla no la muestra).
 *
 * Y F2: «deshabilitá el Coratina de la tienda» volvió con «el campo de publicación figura entre los
 * que no se cargan». Desde d6d89dbc el ABM genérico ya lee `online` (y `provider_id`) detrás del
 * wrapper del historial de precios: acá queda fijado, por artículo y en masiva.
 *
 * 🔴 Sin red.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class Bodega_y_cepa_en_filtros_y_masiva_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var Provider */
    protected $grupo_vid;

    /** @var int */
    protected $weinert;

    /** @var int */
    protected $carrascal;

    /** @var int */
    protected $malbec;

    /** @var Article[] */
    protected $de_weinert = [];

    /** @var Article */
    protected $de_carrascal;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Truvari P107',
            'company_name'     => 'Truvari Bebidas P107',
            'email'            => 'truvari-p107-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $this->grupo_vid = Provider::create(['name' => 'Grupo Vid P107', 'user_id' => $this->comercio->id]);
        $otro = Provider::create(['name' => 'Distribuidora vieja P107', 'user_id' => $this->comercio->id]);

        $this->weinert = DB::table('bodegas')->insertGetId(['name' => 'Bodega Weinert P107', 'user_id' => $this->comercio->id]);
        $this->carrascal = DB::table('bodegas')->insertGetId(['name' => 'Carrascal P107', 'user_id' => $this->comercio->id]);
        $this->malbec = DB::table('cepas')->insertGetId(['name' => 'Malbec P107', 'user_id' => $this->comercio->id]);

        // Una bodega homónima de OTRO negocio: nunca la puede alcanzar el filtro.
        $ajeno = User::create(['name' => 'Ajeno P107', 'email' => 'ajeno-p107-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        DB::table('bodegas')->insert(['name' => 'Bodega Weinert P107 ajena', 'user_id' => $ajeno->id]);

        $this->de_weinert[] = $this->articulo(['name' => 'Weinert Cavas Malbec P107', 'bodega_id' => $this->weinert, 'cepa_id' => $this->malbec, 'provider_id' => $otro->id]);
        $this->de_weinert[] = $this->articulo(['name' => 'Weinert Carrascal Tinto P107', 'bodega_id' => $this->weinert, 'provider_id' => $otro->id]);
        $this->de_carrascal = $this->articulo(['name' => 'Otro Carrascal P107', 'bodega_id' => $this->carrascal, 'cepa_id' => $this->malbec, 'provider_id' => $otro->id]);
    }

    protected function articulo(array $atributos)
    {
        return Article::create(array_merge([
            'user_id'         => $this->comercio->id,
            'status'          => 'active',
            'cost'            => 1000,
            'percentage_gain' => 30,
        ], $atributos));
    }

    protected function dar_extension($slug)
    {
        $extencion = (ExtencionEmpresa::where('slug', $slug)->first() ?: ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]));

        $this->comercio->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->comercio->load('extencions');
    }

    protected function conversacion()
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Vinculá todos los Weinert al proveedor Grupo Vid', 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    protected function input_weinert_a_grupo_vid()
    {
        return [
            'filtros' => [['campo' => 'bodega', 'operador' => 'igual', 'valor' => 'Weinert']],
            'cambios' => [['campo' => 'proveedor', 'operacion' => 'asignar', 'valor' => 'Grupo Vid']],
        ];
    }

    /**
     * Sin el módulo Vinoteca, bodega y cepa no existen para el asistente: ni para filtrar ni para
     * asignar, y el error lo dice (no "no conozco el campo").
     *
     * @test
     */
    public function sin_vinoteca_bodega_y_cepa_se_rechazan_con_el_motivo()
    {
        $filtro = FiltroDeArticulosIaHelper::traducir([['campo' => 'bodega', 'operador' => 'igual', 'valor' => 'Weinert']], $this->comercio->id);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($filtro));
        $this->assertStringContainsString('Vinoteca', $filtro['error']);

        $vacio = FiltroDeArticulosIaHelper::traducir([['campo' => 'cepa', 'operador' => 'en_blanco']], $this->comercio->id);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($vacio));
        $this->assertStringContainsString('Vinoteca', $vacio['error']);

        $cambio = PropuestaActualizacionMasivaIaHelper::traducir_cambios([['campo' => 'bodega', 'operacion' => 'asignar', 'valor' => 'Carrascal']], $this->comercio->id);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($cambio));
        $this->assertStringContainsString('Vinoteca', $cambio['error']);
    }

    /**
     * Con Vinoteca: «¿cuántos Weinert tengo?» cuenta por bodega por NOMBRE (y por palabras: «Weinert»
     * encuentra «Bodega Weinert»), sin mezclar la bodega homónima de otro negocio; por cepa también,
     * y "sin cepa" es en_blanco.
     *
     * @test
     */
    public function con_vinoteca_se_cuenta_por_bodega_y_por_cepa_por_nombre()
    {
        $this->dar_extension('vinoteca');

        $por_bodega = FiltroDeArticulosIaHelper::contar_para_la_ia($this->comercio->id, [
            'filtros' => [['campo' => 'bodega', 'operador' => 'igual', 'valor' => 'Weinert']],
        ]);

        $this->assertTrue($por_bodega['ok'], json_encode($por_bodega));
        $this->assertSame(2, $por_bodega['total']);
        $this->assertSame(['Bodega: Bodega Weinert P107'], $por_bodega['filtros_legibles']);

        $por_cepa = FiltroDeArticulosIaHelper::contar_para_la_ia($this->comercio->id, [
            'filtros' => [['campo' => 'cepa', 'operador' => 'igual', 'valor' => 'malbec p107']],
        ]);
        $this->assertSame(2, $por_cepa['total'], 'El Cavas Malbec y el de Carrascal tienen cepa Malbec.');

        $sin_cepa = FiltroDeArticulosIaHelper::contar_para_la_ia($this->comercio->id, [
            'filtros' => [
                ['campo' => 'bodega', 'operador' => 'igual', 'valor' => 'Weinert'],
                ['campo' => 'cepa', 'operador' => 'en_blanco'],
            ],
        ]);
        $this->assertSame(1, $sin_cepa['total']);

        $ninguna = FiltroDeArticulosIaHelper::traducir([['campo' => 'bodega', 'operador' => 'igual', 'valor' => 'Catena']], $this->comercio->id);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($ninguna));
        $this->assertStringContainsString('ninguna bodega', $ninguna['error']);
    }

    /**
     * 🔴 EL PEDIDO DE FERNANDO, DE PUNTA A PUNTA: la masiva filtra por bodega y asigna el proveedor;
     * confirmada y procesada, los dos Weinert quedan en Grupo Vid y el de Carrascal no se toca. Y la
     * bodega también se asigna en masiva.
     *
     * @test
     */
    public function la_masiva_vincula_los_de_una_bodega_a_un_proveedor_y_asigna_bodega()
    {
        $this->dar_extension('vinoteca');
        // Las rutas del chat piden el asistente.
        $this->dar_extension('agentes_ia');

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_weinert_a_grupo_vid());

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(2, $respuesta['articulos_alcanzados']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '2'],
            ['etiqueta' => 'Bodega', 'valor' => 'Bodega Weinert P107'],
            ['etiqueta' => 'Cambio', 'valor' => 'Proveedor → Grupo Vid P107'],
        ], $accion->presentacion['renglones']);
        $this->assertSame([['key' => 'bodega_id', 'type' => 'search', 'igual_que' => (int) $this->weinert]], $accion->datos['filter_form']);
        $this->assertSame([['type' => 'search', 'key' => 'provider_id', 'value' => (int) $this->grupo_vid->id]], $accion->datos['update_form']);

        $assistant->estado = 'listo';
        $assistant->save();

        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);
        Auth::forgetGuards();
        $this->actingAs($this->comercio, 'web');

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')->assertStatus(200);

        $masiva = MasiveUpdate::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();
        $this->assertNotNull($masiva);

        Notification::fake();
        MasiveUpdateHelper::process_update($masiva->fresh());

        $this->assertSame((int) $this->grupo_vid->id, (int) $this->de_weinert[0]->fresh()->provider_id);
        $this->assertSame((int) $this->grupo_vid->id, (int) $this->de_weinert[1]->fresh()->provider_id);
        $this->assertNotSame((int) $this->grupo_vid->id, (int) $this->de_carrascal->fresh()->provider_id, 'El de Carrascal no era Weinert.');

        // Asignar bodega y cepa en masiva: misma resolución por nombre.
        $cambios = PropuestaActualizacionMasivaIaHelper::traducir_cambios([
            ['campo' => 'bodega', 'operacion' => 'asignar', 'valor' => 'Carrascal'],
            ['campo' => 'cepa', 'operacion' => 'asignar', 'valor' => 'Malbec'],
        ], $this->comercio->id);

        $this->assertSame([
            ['type' => 'search', 'key' => 'bodega_id', 'value' => (int) $this->carrascal],
            ['type' => 'search', 'key' => 'cepa_id', 'value' => (int) $this->malbec],
        ], $cambios['update_form']);
        $this->assertSame(['Bodega → Carrascal P107', 'Cepa → Malbec P107'], $cambios['legibles']);
    }

    /**
     * F2: publicar o despublicar en la tienda. Por artículo lo escribe el ABM genérico (update lee
     * `online` detrás del wrapper del historial, d6d89dbc) y en masiva es en_tienda.
     *
     * @test
     */
    public function online_se_escribe_por_articulo_y_en_masiva()
    {
        $this->assertContains('online', Catalogo::claves_que_lee(ArticleController::class, 'update'));
        $this->assertArrayHasKey('online', Catalogo::declaracion('article')['campos']);

        $cambios = PropuestaActualizacionMasivaIaHelper::traducir_cambios([['campo' => 'en_tienda', 'operacion' => 'desactivar']], $this->comercio->id);
        $this->assertSame([['type' => 'checkbox', 'key' => 'online', 'value' => 0]], $cambios['update_form']);
    }
}
