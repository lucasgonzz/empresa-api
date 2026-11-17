<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaAccionDePantallaIaHelper as Propuesta;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Address;
use App\Models\ExtencionEmpresa;
use App\Models\PromocionVinoteca;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/** Regresión del ticket 121: costo unitario, delta y revisión de la propuesta real. */
class T176_Edicion_parcial_de_promos_Test extends EmpresaTestCase
{
    private $promo;
    private $articulo;
    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = auth()->user();
        $extension = ExtencionEmpresa::where('slug', 'agentes_ia')->first();
        if (!$extension) {
            $extension = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        }
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $this->articulo = Article::create(['name' => 'Botella prueba ticket 121', 'user_id' => $this->dueno->id,
            'cost' => 100, 'final_price' => 200, 'stock' => 60, 'presentacion' => 6]);
        $this->promo = PromocionVinoteca::create(['name' => 'MIX CLASICO Nº1', 'slug' => 'mix-clasico-n1',
            'stock' => 6, 'cost' => 54262, 'final_price' => 73000, 'online' => 1, 'address_id' => 0,
            'description' => 'Descripción original', 'user_id' => $this->dueno->id]);
        $this->promo->articles()->attach($this->articulo->id, ['amount' => 12, 'unidades_por_promo' => 2]);
        Catalogo::olvidar();
    }

    private function uri($id = null)
    {
        return 'api/promocion-vinoteca/'.($id === null ? $this->promo->id : $id);
    }

    private function otro_dueno()
    {
        return User::create(['name' => 'Otro dueño T121', 'email' => 'otro-t121@example.test', 'password' => 'hash-prueba']);
    }

    private function foto()
    {
        return [
            DB::table('promocion_vinotecas')->where('id', $this->promo->id)->first(),
            DB::table('article_promocion_vinoteca')->where('promocion_vinoteca_id', $this->promo->id)->get()->toArray(),
            $this->articulo->fresh()->getAttributes(),
            DB::table('stock_movements')->count(), DB::table('sales')->get()->toArray(),
            DB::table('promocion_vinoteca_sale')->get()->toArray(),
        ];
    }

    public function test_put_de_solo_costo_conserva_todo_y_persiste_despues_del_guardado()
    {
        $antes = $this->foto();
        $this->putJson($this->uri(), ['cost' => 53181])->assertOk()->assertJsonPath('model.online', 1);
        $despues = $this->foto();
        $this->assertEquals(53181, $despues[0]->cost);
        unset($antes[0]->cost, $antes[0]->updated_at, $despues[0]->cost, $despues[0]->updated_at);
        $this->assertEquals($antes, $despues);
    }

    public function test_formulario_completo_es_compatible_y_no_recalcula_el_costo()
    {
        $this->promo->load('articles', 'images');
        $datos = $this->promo->toArray();
        $datos['name'] = 'Mix con nombre corregido';
        $datos['final_price'] = 75000;
        $datos['childrens'] = [];
        $this->putJson($this->uri(), $datos)->assertOk();
        $actual = $this->promo->fresh();
        $this->assertEquals(54262, $actual->cost);
        $this->assertEquals(75000, $actual->final_price);
        $this->assertEquals(6, $actual->stock);
        $this->assertNotEquals($this->promo->slug, $actual->slug);
    }

    public function test_online_omitido_se_preserva_y_false_explicito_se_guarda()
    {
        $this->putJson($this->uri(), ['description' => null])->assertOk();
        $this->assertEquals(1, $this->promo->fresh()->online);
        $this->putJson($this->uri(), ['online' => false])->assertOk();
        $this->assertEquals(0, $this->promo->fresh()->online);
        $this->assertNull($this->promo->fresh()->description);
    }

    public function test_costo_cero_es_valido_y_no_modifica_stock_ni_precio()
    {
        $this->putJson($this->uri(), ['cost' => 0])->assertOk();
        $this->assertEquals(0, $this->promo->fresh()->cost);
        $this->assertEquals(73000, $this->promo->fresh()->final_price);
        $this->assertEquals(6, $this->promo->fresh()->stock);
    }

    public function test_stock_decreciente_no_se_convierte_en_cantidad_del_lote()
    {
        $this->putJson($this->uri(), ['stock' => 1])->assertOk();
        $this->assertEquals(54262, $this->promo->fresh()->cost);
        $this->putJson($this->uri(), ['cost' => 53181])->assertOk();
        $this->assertEquals(53181, $this->promo->fresh()->cost);
        $this->assertEquals(1, $this->promo->fresh()->stock);
        $this->assertEquals(12, $this->promo->articles()->first()->pivot->amount);
    }

    /** @dataProvider entradas_invalidas */
    public function test_rechazo_util_sin_efectos_en_put_y_propuesta($datos, $texto)
    {
        $antes = $this->foto();
        $respuesta = $this->putJson($this->uri(), $datos)->assertStatus(422);
        $this->assertStringContainsString($texto, json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('SQLSTATE', $respuesta->getContent());
        $this->assertEquals($antes, $this->foto());
        list($conversacion, $assistant) = $this->conversacion();
        $resultado = $this->proponer($conversacion, $assistant, $datos);
        $this->assertFalse($resultado['ok']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversacion->id)->count());
        $this->assertEquals($antes, $this->foto());
        // También se comprueba al confirmar: una tarjeta vieja o alterada no saltea la validación.
        $valida = $this->proponer($conversacion, $assistant, ['cost' => 53181]);
        $accion = AiMessageAction::findOrFail($valida['tarjeta_id']);
        $guardados = $accion->datos;
        $guardados['cuerpo'] = $datos;
        $accion->update(['datos' => $guardados]);
        $confirmacion = $this->confirmar($conversacion, $accion->id)->assertStatus(422);
        $this->assertStringContainsString($texto, json_encode($confirmacion->json(), JSON_UNESCAPED_UNICODE));
        $this->assertNotSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->fresh()->estado);
        $this->assertEquals($antes, $this->foto());
    }

    public function entradas_invalidas()
    {
        return [
            [['cost' => -1], 'mayor o igual'], [['cost' => null], 'Complet'],
            [['cost' => 'cualquier cosa'], 'número'], [['cost' => 1.234], 'decimales'],
            [['cost' => true], 'número'], [['cost' => []], 'número'],
            [['online' => null], 'Complet'], [['online' => 'quizás'], 'tienda'],
            [['stock' => -1], 'negativo'], [['stock' => 1.5], 'entero'],
            [['stock' => true], 'entero'], [['final_price' => -1], 'mayor o igual'],
            [['name' => '   '], 'Complet'], [['campos_inventados' => 5], 'dato no'],
            [[], 'Indic'], [['address_id' => 2147483647], 'depósito'],
            [['description' => str_repeat('🍷', 17000)], 'demasiado larga'],
            [['cost' => '99999999999999999999'], 'máximo'],
            [['final_price' => '99999999999999999999'], 'máximo'],
        ];
    }

    public function test_promo_ajena_e_inexistente_se_rechazan_sin_escrituras()
    {
        $otro = User::create(['name' => 'Otro dueño prueba', 'email' => 'otro-ticket121@example.test', 'password' => 'hash-prueba']);
        $ajena = $this->promo->replicate();
        $ajena->user_id = $otro->id;
        $ajena->save();
        foreach ([$ajena->id, 2147483647] as $id) {
            $this->putJson($this->uri($id), ['cost' => 53181])->assertStatus(422);
            list($c, $a) = $this->conversacion();
            $this->assertFalse($this->proponer($c, $a, ['cost' => 53181], $id)['ok']);
        }
        $this->assertEquals(54262, $ajena->fresh()->cost);
    }

    public function test_no_se_puede_cambiar_dueño_ni_composicion_desde_el_formulario()
    {
        $antes = $this->foto();
        foreach ([['user_id' => $this->dueno->id + 1], ['articles' => []],
            ['articles' => [['id' => $this->articulo->id, 'pivot' => ['amount' => 1, 'unidades_por_promo' => 2]]]],
            ['images' => [['id' => 2147483647]]], ['childrens' => [['id' => 10]]]] as $extra) {
            $this->putJson($this->uri(), array_merge(['cost' => 53181], $extra))->assertStatus(422);
            $this->assertEquals($antes, $this->foto());
        }
    }

    private function conversacion($whatsapp = false)
    {
        $c = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'user', 'contenido' => 'Cambiar el costo de la promo', 'estado' => 'listo']);
        $a = AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'assistant', 'estado' => 'listo',
            'contenido' => 'Revisá el cambio de costo antes de confirmar.', 'acciones_habilitadas' => true,
            'canal' => $whatsapp ? AiMessage::CANAL_WHATSAPP : AiMessage::CANAL_SISTEMA]);
        return [$c, $a];
    }

    private function proponer($c, $a, $datos, $id = null, $reemplaza = null)
    {
        return Propuesta::proponer(ContextoDeCargaIa::de_la_conversacion($c, $this->dueno), $a,
            'PUT', $this->uri($id), [], $datos, 'Editar costo', $reemplaza);
    }

    private function confirmar($c, $tarjeta)
    {
        return $this->postJson('api/ai-conversations/'.$c->id.'/acciones/'.$tarjeta.'/confirmar');
    }

    public function test_tarjeta_identifica_promo_y_cambio_real_sin_completar_otros_campos()
    {
        list($c, $a) = $this->conversacion();
        $respuesta = $this->proponer($c, $a, ['cost' => 53181]);
        $this->assertTrue($respuesta['ok']);
        $accion = AiMessageAction::findOrFail($respuesta['tarjeta_id']);
        $this->assertSame(['cost' => 53181], $accion->datos['cuerpo']);
        $this->assertStringContainsString('MIX CLASICO Nº1', $respuesta['resumen']);
        $this->assertStringContainsString('53181', $respuesta['resumen']);
        $this->assertStringContainsString('54262', $respuesta['resumen']);
        $this->confirmar($c, $accion->id)->assertOk();
        $this->assertEquals(53181, $this->promo->fresh()->cost);
        $this->confirmar($c, $accion->id)->assertStatus(409);
    }

    public function test_cambio_concurrente_de_precio_no_se_pisa_al_confirmar_el_costo()
    {
        list($c, $a) = $this->conversacion();
        $r = $this->proponer($c, $a, ['cost' => 53181]);
        $this->promo->update(['final_price' => 79000, 'stock' => 2, 'online' => 0]);
        $this->confirmar($c, $r['tarjeta_id'])->assertOk();
        $this->assertEquals(53181, $this->promo->fresh()->cost);
        $this->assertEquals(79000, $this->promo->fresh()->final_price);
        $this->assertEquals(2, $this->promo->fresh()->stock);
        $this->assertEquals(0, $this->promo->fresh()->online);
    }

    public function test_cambio_concurrente_de_costo_rechaza_tarjeta_con_texto_util()
    {
        list($c, $a) = $this->conversacion();
        $r = $this->proponer($c, $a, ['cost' => 53181]);
        $this->promo->update(['cost' => 55000]);
        $this->confirmar($c, $r['tarjeta_id'])->assertStatus(422)->assertJsonFragment(['error_mensaje' => 'La promo cambió después de preparar la tarjeta. Pedí una propuesta nueva y revisá el costo por promo y los demás cambios antes de confirmar.']);
        $this->assertEquals(55000, $this->promo->fresh()->cost);
        $this->assertNotSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($r['tarjeta_id'])->estado);
    }

    public function test_reemplazo_de_tarjeta_inhabilita_la_anterior()
    {
        list($c, $a) = $this->conversacion();
        $primera = $this->proponer($c, $a, ['cost' => 53000]);
        $segunda = $this->proponer($c, $a, ['cost' => 53181], null, $primera['tarjeta_id']);
        $this->confirmar($c, $primera['tarjeta_id'])->assertStatus(409);
        $this->confirmar($c, $segunda['tarjeta_id'])->assertOk();
        $this->assertEquals(53181, $this->promo->fresh()->cost);
    }

    public function test_confirmacion_whatsapp_usa_la_misma_edicion_parcial_y_no_duplica()
    {
        list($c, $a) = $this->conversacion(true);
        $r = $this->proponer($c, $a, ['cost' => 53181]);
        AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'user', 'contenido' => 'Sí, confirmo', 'estado' => 'listo', 'canal' => AiMessage::CANAL_WHATSAPP]);
        $nuevo = AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'assistant', 'estado' => 'pendiente',
            'acciones_habilitadas' => true, 'canal' => AiMessage::CANAL_WHATSAPP]);
        $resultado = ConfirmacionPorTextoIaHelper::confirmar($c, $nuevo, $r['tarjeta_id']);
        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertEquals(53181, $this->promo->fresh()->cost);
        $repetida = ConfirmacionPorTextoIaHelper::confirmar($c, $nuevo, $r['tarjeta_id']);
        $this->assertFalse($repetida['ok']);
    }

    public function test_cambio_de_dueño_antes_de_confirmar_no_escribe()
    {
        list($c, $a) = $this->conversacion();
        $r = $this->proponer($c, $a, ['cost' => 53181]);
        $this->promo->update(['user_id' => $this->otro_dueno()->id]);
        $this->confirmar($c, $r['tarjeta_id'])->assertStatus(422);
        $this->assertEquals(54262, $this->promo->fresh()->cost);
    }

    public function test_fallo_al_guardar_no_deja_cambios_parciales_ni_anuncia_exito()
    {
        list($c, $a) = $this->conversacion();
        $r = $this->proponer($c, $a, ['cost' => 53181, 'online' => false]);
        $antes = $this->foto();
        $dispatcher = PromocionVinoteca::getEventDispatcher();
        PromocionVinoteca::setEventDispatcher(clone $dispatcher);
        PromocionVinoteca::saved(function () { throw new \RuntimeException('Falla transaccional de prueba'); });
        try {
            $this->confirmar($c, $r['tarjeta_id'])->assertStatus(500);
            $this->assertEquals($antes, $this->foto());
            $this->assertNotSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($r['tarjeta_id'])->estado);
        } finally {
            PromocionVinoteca::setEventDispatcher($dispatcher);
        }
    }

    public function test_catalogo_conserva_campos_editables_aunque_el_controller_delegue()
    {
        $declaracion = Catalogo::declaracion('PUT', 'api/promocion-vinoteca/{promocion_vinoteca}');
        $this->assertNotNull($declaracion);
        $this->assertContains('cost', $declaracion['claves']);
        $this->assertContains('online', $declaracion['claves']);
        $this->assertNotContains('user_id', $declaracion['claves']);
        $this->assertStringContainsString('POR PROMO', $declaracion['indicaciones']);
        $this->assertStringContainsString('solamente los campos', $declaracion['indicaciones']);
        list($c, $a) = $this->conversacion();
        $herramienta = (new \App\Services\AsistenteIa\AsistenteIaService())->execute_tool_calls([[
            'type' => 'tool_use', 'id' => 'catalogo-promos-t121', 'name' => 'que_acciones_de_pantalla_hay',
            'input' => ['buscar' => 'promocion-vinoteca', 'metodo' => 'PUT'],
        ]], $c, $a);
        $this->assertArrayNotHasKey('is_error', $herramienta[0]);
        $contenido = json_decode($herramienta[0]['content'], true);
        $editar = array_values(array_filter($contenido['acciones'], function ($accion) {
            return $accion['accion'] === 'PromocionVinotecaController@update';
        }));
        $this->assertCount(1, $editar);
        $this->assertStringContainsString('POR PROMO', $editar[0]['indicaciones']);
    }

    public function test_alta_calcula_por_cantidad_original_y_la_edicion_no_repite_movimientos()
    {
        $stock = $this->articulo->fresh()->stock;
        $r = $this->postJson('api/promocion-vinoteca', ['name' => 'Armado nuevo ticket 121', 'cost' => 0,
            'final_price' => 5000, 'stock' => 6, 'online' => 1, 'address_id' => 0, 'description' => null,
            'articles' => [['id' => $this->articulo->id, 'pivot' => ['amount' => 12, 'unidades_por_promo' => 2]]],
            'childrens' => []])->assertCreated();
        $nueva = PromocionVinoteca::findOrFail($r->json('model.id'));
        $this->assertEquals(1200, $nueva->cost); // 12 * 100 * presentación 6 / 6 promos armadas.
        $this->assertEquals($stock - 12, $this->articulo->fresh()->stock);
        $movimientos = DB::table('stock_movements')->count();
        $this->putJson($this->uri($nueva->id), ['stock' => 1])->assertOk();
        $this->putJson($this->uri($nueva->id), ['cost' => 900])->assertOk();
        $this->assertEquals(900, $nueva->fresh()->cost);
        $this->assertEquals(5000, $nueva->fresh()->final_price);
        $this->assertSame($movimientos, DB::table('stock_movements')->count());
    }

    public function test_deposito_propio_se_admite_y_ajeno_no_se_ofrece_ni_se_guarda()
    {
        $propio = Address::create(['street' => 'Depósito propio T121', 'user_id' => $this->dueno->id]);
        $ajeno = Address::create(['street' => 'Depósito ajeno T121', 'user_id' => $this->otro_dueno()->id]);
        $this->putJson($this->uri(), ['address_id' => $propio->id])->assertOk();
        $antes = $this->foto();
        $this->putJson($this->uri(), ['address_id' => $ajeno->id, 'cost' => 53181])->assertStatus(422);
        list($c, $a) = $this->conversacion();
        $this->assertFalse($this->proponer($c, $a, ['address_id' => $ajeno->id])['ok']);
        $this->assertEquals($antes, $this->foto());
    }

    public function test_articulo_ajeno_y_metadatos_invalidos_no_vuelven_error_generico()
    {
        $ajeno = Article::create(['name' => 'Artículo ajeno T121', 'user_id' => $this->otro_dueno()->id]);
        $antes = $this->foto();
        foreach ([['articles' => [['id' => $ajeno->id, 'pivot' => ['amount' => 12, 'unidades_por_promo' => 2]]]],
            ['user_id' => []], ['id' => true]] as $extra) {
            $r = $this->putJson($this->uri(), array_merge(['cost' => 53181], $extra))->assertStatus(422);
            $this->assertStringNotContainsString('SQLSTATE', $r->getContent());
            $this->assertEquals($antes, $this->foto());
        }
    }

    public function test_empleado_sin_admin_no_propone_y_admin_revocado_no_confirma()
    {
        $empleado = User::create(['name' => 'Empleado T121', 'email' => 'empleado-t121@example.test',
            'password' => 'hash-prueba', 'owner_id' => $this->dueno->id, 'admin_access' => 0]);
        list($c, $a) = $this->conversacion();
        $c->update(['auth_user_id' => $empleado->id]);
        $contexto = ContextoDeCargaIa::de_la_conversacion($c, $empleado);
        $negada = Propuesta::proponer($contexto, $a, 'PUT', $this->uri(), [], ['cost' => 53181], 'Editar costo');
        $this->assertFalse($negada['ok']);
        $empleado->update(['admin_access' => 1]);
        $contexto = ContextoDeCargaIa::de_la_conversacion($c, $empleado->fresh());
        $permitida = Propuesta::proponer($contexto, $a, 'PUT', $this->uri(), [], ['cost' => 53181], 'Editar costo');
        $this->assertTrue($permitida['ok']);
        $empleado->update(['admin_access' => 0]);
        $this->actingAs($empleado->fresh(), 'web');
        $resultado = EjecutorAccionesIaHelper::confirmar($c, $permitida['tarjeta_id'], $empleado->fresh(), function () { return 1; });
        $this->assertSame(422, $resultado['status']);
        $this->assertStringContainsString('permiso', json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertEquals(54262, $this->promo->fresh()->cost);
    }

    public function test_mix_con_nombre_parecido_no_recibe_el_costo_de_otra_promo()
    {
        $otra = $this->promo->replicate();
        $otra->name = 'MIX CLASICO';
        $otra->cost = 319086;
        $otra->stock = 1;
        $otra->final_price = 70000;
        $otra->save();
        list($c, $a) = $this->conversacion();
        $r = $this->proponer($c, $a, ['cost' => 53181]);
        $this->confirmar($c, $r['tarjeta_id'])->assertOk();
        $this->assertEquals(53181, $this->promo->fresh()->cost);
        $this->assertEquals(319086, $otra->fresh()->cost);
        $this->assertEquals(70000, $otra->fresh()->final_price);
    }

    public function test_tarjeta_anterior_sin_referencia_pide_nueva_propuesta_sin_escribir()
    {
        list($c, $a) = $this->conversacion();
        $r = $this->proponer($c, $a, ['cost' => 53181]);
        $accion = AiMessageAction::findOrFail($r['tarjeta_id']);
        $datos = $accion->datos;
        unset($datos['referencia_edicion_promo']);
        $accion->update(['datos' => $datos]);
        $respuesta = $this->confirmar($c, $accion->id)->assertStatus(422);
        $this->assertStringContainsString('propuesta nueva', json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE));
        $this->assertEquals(54262, $this->promo->fresh()->cost);
    }

    public function test_whatsapp_informa_conflicto_recuperable_sin_anunciar_guardado()
    {
        list($c, $a) = $this->conversacion(true);
        $r = $this->proponer($c, $a, ['cost' => 53181]);
        $this->promo->update(['cost' => 55000]);
        AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'user', 'contenido' => 'Sí, confirmo',
            'estado' => 'listo', 'canal' => AiMessage::CANAL_WHATSAPP]);
        $nuevo = AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'assistant', 'estado' => 'pendiente',
            'acciones_habilitadas' => true, 'canal' => AiMessage::CANAL_WHATSAPP]);
        $resultado = ConfirmacionPorTextoIaHelper::confirmar($c, $nuevo, $r['tarjeta_id']);
        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('propuesta nueva', json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertEquals(55000, $this->promo->fresh()->cost);
    }
}
