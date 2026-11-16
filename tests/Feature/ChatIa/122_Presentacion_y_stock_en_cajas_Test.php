<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), rama A, P2 — presentación y unidades (V4, V3).
 *
 * 🔴 LOS CASOS REALES (Truvari, módulo Vinoteca, precio por caja):
 *  - V4: «Decime el precio por botella del Luigi Bosca… fijate vos en el sistema la presentación» → «La
 *    consulta no trae la presentación»; inventó «6», y dijo «costo $5.060 la caja» de un costo por botella.
 *  - V3: «Agrégale cuatro unidades en stock» → «Son cuatro cajas» → stock 0 → 24.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Presentacion_y_stock_en_cajas_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    /** @var Article Caja de 6, costo 5.060 por botella, precio de la caja 37.200. */
    protected $killka;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-c114']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->confianza_original = $this->dueno->agente_confianza;

        foreach (['agentes_ia', 'vinoteca'] as $slug) {
            $extencion = (ExtencionEmpresa::where('slug', $slug)->first() ?: ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]));
            $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        }

        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'cauteloso']);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');

        Catalogo::olvidar();

        // Sin `price`: el precio sale del cálculo, que con Vinoteca lo multiplica por la presentación.
        $this->killka = Article::create([
            'name'         => 'zz-c114 KILLKA MALBEC',
            'user_id'      => $this->dueno->id,
            'status'       => 'active',
            'cost'         => 5060,
            'final_price'  => 37200,
            'presentacion' => 6,
            'stock'        => 0,
            'iva_id'       => 2,
        ]);
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
    }

    protected function conversacion(array $pedidos)
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        foreach ($pedidos as $pedido) {
            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);
        }

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    // ---------------------------------------------------------------------
    // V4 — la consulta trae la presentación, el precio por unidad y el costo con su unidad
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function la_consulta_de_stock_trae_presentacion_precio_por_unidad_y_costo_con_su_unidad()
    {
        $filas = ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zz-c114 KILLKA', null, true);

        $this->assertCount(1, $filas);
        $fila = $filas[0];

        $this->assertEquals(37200, $fila['precio']);
        $this->assertEquals(6, $fila['presentacion']);
        $this->assertEquals(6200, $fila['precio_por_unidad']);
        $this->assertSame('caja de 6', $fila['precio_es_por']);
        $this->assertEquals(5060, $fila['costo']);
        $this->assertStringContainsString('unidad suelta', $fila['costo_es_por']);

        // Las claves de siempre siguen primero y en su orden (el canal «sistema:» lee por nombre).
        $claves = array_keys($fila);
        $this->assertSame(['id', 'nombre', 'codigo', 'precio', 'stock', 'tiene_imagen', 'stock_sin_cargar', 'stock_en_depositos', 'publicado_en_tienda', 'link_tienda'], array_slice($claves, 0, 10));

        // Por id, lo mismo.
        $por_id = ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, '', $this->killka->id, true);
        $this->assertEquals(6200, $por_id[0]['precio_por_unidad']);

        // Sin pedir el costo (el canal del admin), no viaja.
        $sin_costo = ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zz-c114 KILLKA');
        $this->assertArrayNotHasKey('costo', $sin_costo[0]);
        $this->assertEquals(6200, $sin_costo[0]['precio_por_unidad']);
    }

    /**
     * Un artículo con precio manual (no multiplica por la presentación): el precio es de la unidad del
     * listado y no se inventa un precio por botella.
     *
     * @test
     */
    public function con_precio_manual_no_hay_precio_por_unidad()
    {
        $manual = Article::create([
            'name' => 'zz-c114 MANUAL CAJA', 'user_id' => $this->dueno->id, 'status' => 'active',
            'cost' => 1000, 'price' => 9000, 'final_price' => 9000, 'presentacion' => 6, 'stock' => 3, 'iva_id' => 2,
        ]);

        $fila = ConsultasSistemaIaHelper::stock_de_articulos($this->dueno->id, 'zz-c114 MANUAL', null, true)[0];

        $this->assertSame((int) $manual->id, $fila['id']);
        $this->assertEquals(6, $fila['presentacion']);
        $this->assertNull($fila['precio_por_unidad']);
        $this->assertSame('unidad del listado', $fila['precio_es_por']);
    }

    /**
     * La herramienta del asistente le pasa el costo al dueño.
     *
     * @test
     */
    public function la_herramienta_del_asistente_le_da_el_costo_al_dueno()
    {
        list($conversation, $assistant) = $this->conversacion(['¿A cuánto está la botella del Killka?']);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_c114',
            'name'  => 'consultar_stock_de_articulos',
            'input' => ['busqueda' => 'zz-c114 KILLKA'],
        ]], $conversation, $assistant);

        $filas = json_decode($resultados[0]['content'], true);

        $this->assertEquals(6200, $filas[0]['precio_por_unidad']);
        $this->assertEquals(5060, $filas[0]['costo']);
    }

    // ---------------------------------------------------------------------
    // V3 — «cuatro cajas» son 4
    // ---------------------------------------------------------------------

    /**
     * 🔴 El caso real: «Agrégale cuatro unidades» → «Son cuatro cajas» y el modelo manda 24. La tarjeta
     * queda en 4 (cajas de 6) con el aviso, y al confirmar el stock del artículo es 4.
     *
     * @test
     */
    public function cuatro_cajas_son_cuatro_y_no_veinticuatro()
    {
        $this->assertNotNull(Address::unica_sucursal_de($this->dueno->id), 'El fixture deja una sola sucursal.');

        list($conversation, $assistant) = $this->conversacion(['Agrégale cuatro unidades en stock', 'Son cuatro cajas. Perdón, no te especifiqué.']);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-c114 KILLKA MALBEC',
            'cantidad' => 24,
            'modo'     => 'sumar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $texto = json_encode($accion->presentacion, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('→ 4 cajas (de 6)', $texto);
        $this->assertStringContainsString('Tomé 4 cajas como 4', $texto);
        $this->assertEquals(4, (float) $accion->datos['esperado']['cantidad']);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(4, (float) DB::table('articles')->where('id', $this->killka->id)->value('stock'), 0.01);
    }

    /**
     * Si el modelo ya manda 4, queda en 4 y la tarjeta dice la unidad, sin aviso.
     *
     * @test
     */
    public function la_tarjeta_dice_la_unidad_aunque_la_cantidad_venga_bien()
    {
        list($conversation, $assistant) = $this->conversacion(['Sumale 2 cajas al Killka']);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-c114 KILLKA MALBEC',
            'cantidad' => 2,
            'modo'     => 'sumar',
        ]);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $texto = json_encode($accion->presentacion, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('→ 2 cajas (de 6)', $texto);
        $this->assertStringNotContainsString('Tomé', $texto);
    }

    /**
     * Un artículo que no se vende por caja no cambia: «4 cajas» de tornillos sueltos no se toca.
     *
     * @test
     */
    public function sin_precio_por_caja_no_se_toca_la_cantidad()
    {
        $tornillo = Article::create([
            'name' => 'zz-c114 TORNILLO', 'user_id' => $this->dueno->id, 'status' => 'active',
            'cost' => 10, 'price' => 20, 'final_price' => 20, 'presentacion' => 6, 'stock' => 0, 'iva_id' => 2,
        ]);

        list($conversation, $assistant) = $this->conversacion(['Cargale 4 cajas de tornillos']);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo_id' => $tornillo->id,
            'cantidad'    => 24,
            'modo'        => 'sumar',
        ]);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(24, (float) $accion->datos['esperado']['cantidad']);
        $this->assertStringNotContainsString('cajas (de', json_encode($accion->presentacion, JSON_UNESCAPED_UNICODE));
    }
}
