<?php

namespace Tests\Feature\DimensionSearch;

use App\Jobs\ProcessDimensionSearchRunJob;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Los endpoints de Alertas → Catálogo → Medidas (contrato §2.6 del plan de la misión medidas-masivas-catalogo,
 * 27/10/2026): listado, resumen, detalle, ítems por solapa, aprobar / rechazar / quitar (de a uno y en lote),
 * previa, lanzamiento, detener y reanudar; y la tenencia por dueño, el permiso y el acceso maestro.
 */
class Endpoints_Test extends DimensionSearchTestCase
{
    /**
     * Una corrida armada a mano con un artículo por estado pedido.
     *
     * @param  array $estados
     * @param  array $datos     Columnas de la corrida.
     * @param  \App\Models\User|null $owner
     * @return \App\Models\DimensionSearchRun
     */
    protected function corrida_armada(array $estados, array $datos = [], $owner = null)
    {
        $owner = is_null($owner) ? $this->owner : $owner;

        $run = DimensionSearchRun::create(array_merge([
            'user_id'     => $owner->id,
            'creada_por'  => $owner->id,
            'origen'      => DimensionSearchRun::ORIGEN_CATALOGO,
            'status'      => DimensionSearchRun::STATUS_TERMINADA,
            'total'       => count($estados),
            'finished_at' => Carbon::now(),
        ], $datos));

        foreach (array_values($estados) as $orden => $estado) {
            $articulo = \App\Models\Article::create([
                'name'          => 'Artículo '.$estado.' '.$orden.' '.uniqid(),
                'bar_code'      => '77912345678'.str_pad((string) $orden, 2, '0', STR_PAD_LEFT),
                'provider_code' => 'COD-'.$orden,
                'user_id'       => $owner->id,
                'status'        => 'active',
            ]);

            $this->item_en($run, $articulo, $estado, ['orden' => $orden + 1]);
        }

        return $run;
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_listado_trae_las_corridas_del_dueno_con_sus_conteos_y_no_las_de_otro_comercio()
    {
        $vieja = $this->corrida_armada(
            ['asignada', 'aprobada', 'a_revisar', 'sin_datos', 'rechazada', 'sin_procesar', 'error_interno', 'pendiente', 'procesando'],
            ['status' => DimensionSearchRun::STATUS_EN_PROCESO, 'procesados' => 7, 'busquedas' => 10, 'validaciones_ia' => 5, 'finished_at' => null, 'created_at' => Carbon::now()->subHour()]
        );
        $nueva = $this->corrida_armada(['pendiente'], ['status' => DimensionSearchRun::STATUS_PENDIENTE, 'finished_at' => null]);

        // De otro comercio: no aparece.
        $this->corrida_armada(['asignada'], [], $this->otro_comercio());

        $respuesta = $this->getJson('api/dimension-search-runs');

        $respuesta->assertStatus(200);
        $this->assertSame(2, (int) $respuesta->json('total'));
        $this->assertSame(2, (int) $respuesta->json('models.total'), 'Y también bajo `models`, como el de imágenes.');
        $this->assertSame(25, (int) $respuesta->json('per_page'));

        // Más nuevas primero.
        $this->assertSame((int) $nueva->id, (int) $respuesta->json('data.0.id'));
        $this->assertNull($respuesta->json('data.0.busquedas_por_articulo'));

        $fila = $respuesta->json('data.1');
        $this->assertSame((int) $vieja->id, (int) $fila['id']);
        $this->assertSame(['asignadas' => 2, 'a_revisar' => 1, 'sin_datos' => 4, 'pendientes' => 2, 'errores' => 1], $fila['conteos']);
        $this->assertSame(10, $fila['busquedas']);
        $this->assertEquals(1.43, $fila['busquedas_por_articulo']);
        $this->assertSame(5, $fila['validaciones_ia']);
        $this->assertSame('catalogo', $fila['origen']);
        $this->assertSame('ComercioCity', $fila['lanzada_por']);
        $this->assertTrue($fila['puede_detener']);
        $this->assertFalse($fila['puede_reanudar']);
        $this->assertFalse($fila['esta_trabada']);
        $this->assertFalse($fila['visto']);

        foreach (['id', 'origen', 'status', 'solo_con_stock', 'total', 'procesados', 'conteos', 'busquedas', 'validaciones_ia', 'started_at', 'finished_at', 'esta_trabada', 'puede_detener', 'puede_reanudar', 'visto'] as $clave) {
            $this->assertArrayHasKey($clave, $fila, 'El RunPayload siempre trae '.$clave);
        }

        $this->assertSame(['a_revisar' => 1, 'sin_ver' => 0, 'badge' => 1, 'en_proceso' => 2], $respuesta->json('resumen'));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_resumen_cuenta_lo_que_espera_una_decision_mas_lo_que_nadie_vio()
    {
        $this->corrida_armada(['a_revisar', 'a_revisar', 'asignada'], ['visto_at' => null]);
        $this->corrida_armada(['a_revisar'], ['visto_at' => Carbon::now()]);
        $this->corrida_armada(['pendiente'], ['status' => DimensionSearchRun::STATUS_EN_PROCESO, 'finished_at' => null]);

        $resumen = $this->getJson('api/dimension-search-runs/resumen');

        $resumen->assertStatus(200);
        $this->assertSame(['a_revisar' => 3, 'sin_ver' => 1, 'badge' => 4, 'en_proceso' => 1], $resumen->json());
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_detalle_marca_vista_una_corrida_terminada_y_no_una_en_curso()
    {
        $terminada = $this->corrida_armada(['asignada']);
        $en_curso  = $this->corrida_armada(['pendiente'], ['status' => DimensionSearchRun::STATUS_EN_PROCESO, 'finished_at' => null]);

        $r = $this->getJson('api/dimension-search-runs/'.$terminada->id);
        $r->assertStatus(200);
        $this->assertSame((int) $terminada->id, (int) $r->json('id'));
        $this->assertSame((int) $terminada->id, (int) $r->json('model.id'));
        $this->assertNotNull($terminada->fresh()->visto_at);

        $this->getJson('api/dimension-search-runs/'.$en_curso->id)->assertStatus(200);
        $this->assertNull($en_curso->fresh()->visto_at);
    }

    /**
     * La tenencia: un id de otro comercio es «no existe» en todo.
     *
     * @group dimension-search
     * @test
     */
    public function un_comercio_no_ve_ni_toca_lo_de_otro()
    {
        $ajeno_owner = $this->otro_comercio();
        $ajena       = $this->corrida_armada(['a_revisar', 'asignada'], [], $ajeno_owner);
        $item_ajeno  = $ajena->items()->where('status', 'a_revisar')->first();
        $asignado_ajeno = $ajena->items()->where('status', 'asignada')->first();

        $this->getJson('api/dimension-search-runs/'.$ajena->id)->assertStatus(404);
        $this->getJson('api/dimension-search-runs/'.$ajena->id.'/items?solapa=a_revisar')->assertStatus(404);
        $this->postJson('api/dimension-search-runs/'.$ajena->id.'/detener')->assertStatus(404);
        $this->postJson('api/dimension-search-runs/'.$ajena->id.'/reanudar')->assertStatus(404);
        $this->postJson('api/dimension-search-items/'.$item_ajeno->id.'/aprobar')->assertStatus(404);
        $this->postJson('api/dimension-search-items/'.$item_ajeno->id.'/rechazar')->assertStatus(404);
        $this->postJson('api/dimension-search-items/'.$asignado_ajeno->id.'/quitar')->assertStatus(404);

        // En lote: no falla el pedido, pero ninguno se toca y cada uno vuelve como fallido.
        $lote = $this->postJson('api/dimension-search-items/aprobar-varios', ['ids' => [$item_ajeno->id]]);
        $lote->assertStatus(200);
        $this->assertSame(0, $lote->json('aprobados'));
        $this->assertSame($item_ajeno->id, $lote->json('fallidos.0.id'));

        $this->assertSame('a_revisar', $item_ajeno->fresh()->status);
        $this->assertSame('asignada', $asignado_ajeno->fresh()->status);
    }

    /**
     * Sin la extensión Agentes de IA, 403 en todo.
     *
     * @group dimension-search
     * @test
     */
    public function sin_la_extension_agentes_de_ia_todo_responde_403()
    {
        $this->owner->extencions()->detach();
        $this->actuar_como($this->owner->fresh(), true);

        $this->getJson('api/dimension-search-runs')->assertStatus(403);
        $this->getJson('api/dimension-search-runs/resumen')->assertStatus(403);
        $this->getJson('api/dimension-search-runs/catalogo/previa')->assertStatus(403);
        $this->postJson('api/dimension-search-runs/catalogo')->assertStatus(403);
        $this->postJson('api/dimension-search-items/1/aprobar')->assertStatus(403);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function las_solapas_traen_sus_estados_paginados_en_el_servidor_con_los_conteos_de_las_tres()
    {
        $run = $this->corrida_armada(['asignada', 'aprobada', 'a_revisar', 'a_revisar', 'a_revisar', 'sin_datos', 'rechazada', 'quitada', 'sin_procesar', 'error_interno', 'pendiente']);

        $a = $this->getJson('api/dimension-search-runs/'.$run->id.'/items?solapa=asignadas');
        $a->assertStatus(200);
        $this->assertSame(2, (int) $a->json('total'));
        $this->assertSame(['asignada', 'aprobada'], array_column($a->json('data'), 'status'));
        $this->assertSame(['asignadas' => 2, 'a_revisar' => 3, 'sin_datos' => 5, 'pendientes' => 1, 'errores' => 1], $a->json('conteos'));

        $r = $this->getJson('api/dimension-search-runs/'.$run->id.'/items?solapa=a_revisar&per_page=10');
        $this->assertSame(3, (int) $r->json('total'));
        $this->assertSame(10, (int) $r->json('per_page'));
        $this->assertSame(3, (int) $r->json('models.total'));

        $s = $this->getJson('api/dimension-search-runs/'.$run->id.'/items?solapa=sin_datos');
        $this->assertSame(5, (int) $s->json('total'));

        // Por omisión, A revisar; una solapa que no existe es 422.
        $this->assertSame(3, (int) $this->getJson('api/dimension-search-runs/'.$run->id.'/items')->json('total'));
        $this->getJson('api/dimension-search-runs/'.$run->id.'/items?solapa=otra')->assertStatus(422);

        // Buscador por nombre o código (de barras o de proveedor).
        $buscar = $this->getJson('api/dimension-search-runs/'.$run->id.'/items?solapa=a_revisar&buscar=COD-3');
        $this->assertSame(1, (int) $buscar->json('total'));
    }

    /**
     * El ItemPayload del contrato.
     *
     * @group dimension-search
     * @test
     */
    public function el_item_trae_la_propuesta_los_campos_con_su_conversion_la_cita_y_las_fuentes()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', '7791234567893', ['provider_code' => 'VR-CU-0402']);
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();

        $this->item_en($run, $articulo, 'a_revisar', [
            'alto_cm'        => null,
            'nota_ia'        => 'Parece la versión de 35 cm.',
            'criterio_usado' => 'codigo_de_barras',
            'diagnostico'    => [['criterio' => 'nombre', 'usado' => true]],
            'fuentes'        => [['url' => 'https://www.tienda.test/p', 'titulo' => 'Producto']],
        ]);

        $r = $this->getJson('api/dimension-search-runs/'.$run->id.'/items?solapa=a_revisar');
        $item = $r->json('data.0');

        $this->assertSame('CUCHILLA ROTARY', $item['article_name']);
        $this->assertSame('7791234567893', $item['article_bar_code']);
        $this->assertSame('VR-CU-0402', $item['article_provider_code']);
        $this->assertSame('medidas_parciales', $item['motivo']);
        $this->assertSame('codigo_de_barras', $item['criterio_usado']);
        $this->assertSame('Parece la versión de 35 cm.', $item['nota_ia']);

        $this->assertEquals(['peso' => 0.82, 'alto' => null, 'ancho' => 7.0, 'profundidad' => 10.0], $item['propuesta']);
        $this->assertSame(['alto'], $item['faltan']);

        $campos = array_column($item['campos'], null, 'clave');
        $this->assertSame(['peso', 'ancho', 'profundidad'], array_keys($campos));
        $this->assertSame('Ancho', $campos['ancho']['etiqueta']);
        $this->assertSame('Largo', $campos['profundidad']['etiqueta']);
        $this->assertSame('cm', $campos['ancho']['unidad']);
        $this->assertSame('kg', $campos['peso']['unidad']);
        $this->assertTrue($campos['ancho']['convertido']);
        $this->assertEquals(70, $campos['ancho']['valor_original']);
        $this->assertSame('mm', $campos['ancho']['unidad_original']);
        $this->assertSame('70 x 11 x 100 mm', $campos['ancho']['cita']);
        $this->assertSame('producto', $campos['ancho']['alcance']);

        $this->assertSame('tienda.test', $item['fuentes'][0]['dominio']);
        $this->assertSame([], $item['diagnostico'], 'El diagnóstico solo viaja en Sin datos.');
    }

    /**
     * El diagnóstico («ver qué se probó») viaja en Sin datos.
     *
     * @group dimension-search
     * @test
     */
    public function el_diagnostico_viaja_solo_en_sin_datos()
    {
        $articulo = $this->nuevo_articulo('SIN DATOS');
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();

        $this->item_en($run, $articulo, 'sin_datos', [
            'motivo'      => 'no_coincide',
            'peso_kg'     => null, 'alto_cm' => null, 'ancho_cm' => null, 'profundidad_cm' => null, 'evidencia' => null,
            'diagnostico' => [['criterio' => 'nombre', 'usado' => true, 'consulta' => 'sin datos ficha técnica peso medidas', 'paginas' => []]],
        ]);

        $item = $this->getJson('api/dimension-search-runs/'.$run->id.'/items?solapa=sin_datos')->json('data.0');

        $this->assertSame('sin datos ficha técnica peso medidas', $item['diagnostico'][0]['consulta']);
        $this->assertSame(['peso', 'alto', 'ancho', 'profundidad'], $item['faltan']);
        $this->assertSame([], $item['campos']);
    }

    /**
     * Aprobar escribe lo propuesto.
     *
     * @group dimension-search
     * @test
     */
    public function aprobar_escribe_lo_propuesto_convertido_y_deja_el_item_aprobado()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY');
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();
        $item = $this->item_en($run, $articulo, 'a_revisar');

        $r = $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar');

        $r->assertStatus(200);
        $this->assertSame('aprobada', $r->json('status'));
        $this->assertSame('aprobada', $r->json('model.status'));
        $this->assertSame($this->owner->name, $r->json('revisado_por'));
        $this->assertEqualsCanonicalizing(['peso', 'alto', 'ancho', 'profundidad'], $r->json('llenados'));

        $articulo = $articulo->fresh();
        $this->assertEquals(0.82, (float) $articulo->peso);
        $this->assertEquals(1.1, (float) $articulo->alto);
        $this->assertEquals(7, (float) $articulo->ancho);
        $this->assertEquals(10, (float) $articulo->profundidad);
        $this->assertEquals(1, (int) $articulo->needs_sync_with_tn);

        $item->refresh();
        $this->assertEqualsCanonicalizing(['peso', 'alto', 'ancho', 'profundidad'], array_keys($item->aplicado));
        $this->assertNotNull($item->revisado_at);

        // Una segunda vez ya no está esperando revisión.
        $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar')->assertStatus(422);
    }

    /**
     * Aprobar con valores editados en el lugar.
     *
     * @group dimension-search
     * @test
     */
    public function aprobar_con_valores_editados_escribe_los_editados_y_marca_la_evidencia()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY');
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();
        $item = $this->item_en($run, $articulo, 'a_revisar', ['alto_cm' => null]);

        // Edita el peso, completa el alto que faltaba (con coma decimal) y deja el resto como estaba.
        $r = $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar', ['valores' => ['peso' => 0.9, 'alto' => '1,5', 'ancho' => null]]);

        $r->assertStatus(200);

        $articulo = $articulo->fresh();
        $this->assertEquals(0.9, (float) $articulo->peso);
        $this->assertEquals(1.5, (float) $articulo->alto);
        $this->assertEquals(7, (float) $articulo->ancho, 'Lo que no se editó va como se propuso.');
        $this->assertEquals(10, (float) $articulo->profundidad);

        $item->refresh();
        $this->assertEquals(0.9, (float) $item->peso_kg);
        $this->assertEquals(1.5, (float) $item->alto_cm);
        $this->assertTrue($item->evidencia['peso']['editado']);
        $this->assertTrue($item->evidencia['alto']['editado']);
        $this->assertArrayNotHasKey('editado', $item->evidencia['ancho']);
    }

    /**
     * Valores inválidos: 422 con mensaje en español y no se escribe nada.
     *
     * @group dimension-search
     * @test
     */
    public function aprobar_con_valores_invalidos_da_422_en_espanol_y_no_escribe_nada()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY');
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();
        $item = $this->item_en($run, $articulo, 'a_revisar');

        $casos = [
            [['peso' => -1], 'El peso tiene que ser un número mayor a 0 y hasta 2.000 kg.'],
            [['peso' => 'mucho'], 'El peso tiene que ser un número mayor a 0 y hasta 2.000 kg.'],
            [['peso' => 2500], 'El peso tiene que ser un número mayor a 0 y hasta 2.000 kg.'],
            [['ancho' => 0], 'El ancho tiene que ser un número mayor a 0 y hasta 1.000 cm.'],
            [['profundidad' => 1500], 'El largo tiene que ser un número mayor a 0 y hasta 1.000 cm.'],
        ];

        foreach ($casos as $caso) {
            $r = $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar', ['valores' => $caso[0]]);

            $r->assertStatus(422);
            $this->assertSame($caso[1], $r->json('message'));
        }

        $r = $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar', ['valores' => 'no soy un objeto']);
        $r->assertStatus(422);

        $this->assertSame('a_revisar', $item->fresh()->status);
        $this->assertTrue(is_null($articulo->fresh()->peso) || (float) $articulo->fresh()->peso === 0.0);
    }

    /**
     * D5 en la revisión: no pisa lo que se cargó mientras tanto.
     *
     * @group dimension-search
     * @test
     */
    public function aprobar_no_pisa_lo_cargado_a_mano_y_si_no_queda_nada_por_llenar_da_409_con_el_detalle()
    {
        // Parcial: el peso y el ancho ya estaban; se llenan el alto y el largo.
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', null, ['peso' => 3, 'ancho' => 9]);
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();
        $item = $this->item_en($run, $articulo, 'a_revisar');

        $r = $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar');

        $r->assertStatus(200);
        $this->assertEqualsCanonicalizing(['alto', 'profundidad'], $r->json('llenados'));
        $this->assertEqualsCanonicalizing(['peso', 'ancho'], $r->json('salteados'));

        $articulo = $articulo->fresh();
        $this->assertEquals(3, (float) $articulo->peso);
        $this->assertEquals(9, (float) $articulo->ancho);
        $this->assertEquals(1.1, (float) $articulo->alto);
        $this->assertEquals(10, (float) $articulo->profundidad);
        $this->assertEqualsCanonicalizing(['alto', 'profundidad'], array_keys($item->fresh()->aplicado));

        // Total: todo estaba cargado → 409, el item sigue a revisar.
        $lleno = $this->nuevo_articulo('YA CARGADA', null, ['peso' => 1, 'alto' => 2, 'ancho' => 3, 'profundidad' => 4]);
        $item2 = $this->item_en($run, $lleno, 'a_revisar');

        $r2 = $this->postJson('api/dimension-search-items/'.$item2->id.'/aprobar');

        $r2->assertStatus(409);
        $this->assertStringContainsString('ya tiene cargado', $r2->json('message'));
        $this->assertSame([], $r2->json('llenados'));
        $this->assertEqualsCanonicalizing(['peso', 'alto', 'ancho', 'profundidad'], $r2->json('salteados'));
        $this->assertSame('a_revisar', $item2->fresh()->status);
        $this->assertEquals(1, (float) $lleno->fresh()->peso);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function rechazar_descarta_la_propuesta_sin_escribir_nada()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY');
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();
        $item = $this->item_en($run, $articulo, 'a_revisar');

        $r = $this->postJson('api/dimension-search-items/'.$item->id.'/rechazar');

        $r->assertStatus(200);
        $this->assertSame('rechazada', $r->json('status'));
        $this->assertSame([], $r->json('campos'));
        $this->assertTrue(is_null($articulo->fresh()->peso) || (float) $articulo->fresh()->peso === 0.0);

        $this->postJson('api/dimension-search-items/'.$item->id.'/rechazar')->assertStatus(422);
    }

    /**
     * D6: quitar devuelve lo que puso la búsqueda.
     *
     * @group dimension-search
     * @test
     */
    public function quitar_devuelve_los_campos_a_vacio_pero_conserva_el_que_se_edito_despues()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', null, ['peso' => 0.82, 'alto' => 1.1, 'ancho' => 7, 'profundidad' => 10]);
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();

        $item = $this->item_en($run, $articulo, 'asignada', [
            'aplicado' => [
                'peso'        => ['valor' => 0.82, 'anterior' => null],
                'alto'        => ['valor' => 1.1, 'anterior' => null],
                'ancho'       => ['valor' => 7, 'anterior' => 0],
                'profundidad' => ['valor' => 10, 'anterior' => null],
            ],
        ]);

        // Alguien cambió el alto a mano después de la asignación.
        $articulo->update(['alto' => 2.2]);

        $r = $this->postJson('api/dimension-search-items/'.$item->id.'/quitar');

        $r->assertStatus(200);
        $this->assertSame('quitada', $r->json('status'));
        $this->assertEqualsCanonicalizing(['peso', 'ancho', 'profundidad'], $r->json('revertidos'));
        $this->assertSame(['alto'], $r->json('conservados'));
        $this->assertStringContainsString('Se conservó el alto', $r->json('motivo_detalle'));

        $articulo = $articulo->fresh();
        $this->assertNull($articulo->peso);
        $this->assertEquals(0, (float) $articulo->ancho, 'Vuelve a lo que era (0, no null: es lo que tenía).');
        $this->assertNull($articulo->profundidad);
        $this->assertEquals(2.2, (float) $articulo->alto, 'El que se editó después no se toca.');

        // No se puede quitar algo que no está asignado.
        $this->postJson('api/dimension-search-items/'.$item->id.'/quitar')->assertStatus(422);
    }

    /**
     * Una aprobada también se puede quitar.
     *
     * @group dimension-search
     * @test
     */
    public function una_aprobada_tambien_se_puede_quitar_y_una_a_revisar_no()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY');
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();
        $item = $this->item_en($run, $articulo, 'a_revisar');

        $this->postJson('api/dimension-search-items/'.$item->id.'/quitar')->assertStatus(422);

        $this->postJson('api/dimension-search-items/'.$item->id.'/aprobar')->assertStatus(200);
        $this->assertEquals(0.82, (float) $articulo->fresh()->peso);

        $this->postJson('api/dimension-search-items/'.$item->id.'/quitar')->assertStatus(200);
        $this->assertNull($articulo->fresh()->peso);
        $this->assertSame('quitada', $item->fresh()->status);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function aprobar_y_rechazar_varios_resuelven_cada_uno_por_su_lado()
    {
        $run = $this->corrida([$this->nuevo_articulo('PLACEHOLDER')]);
        DimensionSearchItem::where('run_id', $run->id)->delete();

        $a = $this->item_en($run, $this->nuevo_articulo('A APROBAR 1'), 'a_revisar');
        $b = $this->item_en($run, $this->nuevo_articulo('A APROBAR 2'), 'a_revisar');
        $ya = $this->item_en($run, $this->nuevo_articulo('YA RESUELTO'), 'rechazada');

        $r = $this->postJson('api/dimension-search-items/aprobar-varios', ['ids' => [$a->id, $b->id, $ya->id, $a->id, 999999]]);

        $r->assertStatus(200);
        $this->assertSame(2, $r->json('aprobados'));
        $this->assertCount(2, $r->json('fallidos'));
        $this->assertSame([$ya->id, 999999], array_column($r->json('fallidos'), 'id'));
        $this->assertStringContainsString('ya no está esperando revisión', $r->json('fallidos.0.message'));
        $this->assertSame('aprobada', $a->fresh()->status);

        $c = $this->item_en($run, $this->nuevo_articulo('A RECHAZAR 1'), 'a_revisar');
        $d = $this->item_en($run, $this->nuevo_articulo('A RECHAZAR 2'), 'a_revisar');

        $r2 = $this->postJson('api/dimension-search-items/rechazar-varios', ['ids' => [$c->id, $d->id]]);

        $r2->assertStatus(200);
        $this->assertSame(2, $r2->json('rechazados'));
        $this->assertSame([], $r2->json('fallidos'));
        $this->assertSame('rechazada', $c->fresh()->status);

        // Sin ids o con demasiados: 422.
        $this->postJson('api/dimension-search-items/aprobar-varios', ['ids' => []])->assertStatus(422);
        $this->postJson('api/dimension-search-items/rechazar-varios', [])->assertStatus(422);
        $this->postJson('api/dimension-search-items/aprobar-varios', ['ids' => range(1, 201)])->assertStatus(422);
    }

    /**
     * Detener y reanudar: solo el acceso maestro.
     *
     * @group dimension-search
     * @test
     */
    public function detener_y_reanudar_son_solo_del_acceso_maestro()
    {
        Queue::fake();

        $run = $this->corrida_armada(['pendiente'], ['status' => DimensionSearchRun::STATUS_EN_PROCESO, 'finished_at' => null]);

        $this->postJson('api/dimension-search-runs/'.$run->id.'/detener')->assertStatus(403);
        $this->postJson('api/dimension-search-runs/'.$run->id.'/reanudar')->assertStatus(403);
        $this->assertSame('en_proceso', $run->fresh()->status);

        $this->actuar_como($this->owner, true);

        $r = $this->postJson('api/dimension-search-runs/'.$run->id.'/detener');
        $r->assertStatus(200);
        $this->assertSame('detenida', $r->json('status'));
        $this->assertTrue($r->json('puede_reanudar'));
        $this->assertFalse($r->json('puede_detener'));

        $this->postJson('api/dimension-search-runs/'.$run->id.'/detener')->assertStatus(422);

        $r2 = $this->postJson('api/dimension-search-runs/'.$run->id.'/reanudar');
        $r2->assertStatus(200);
        $this->assertSame('pendiente', $r2->json('status'));
        Queue::assertPushed(ProcessDimensionSearchRunJob::class);
    }

    /**
     * La previa y el lanzamiento de «todo el catálogo».
     *
     * @group dimension-search
     * @test
     */
    public function la_previa_y_el_lanzamiento_del_catalogo_son_solo_del_acceso_maestro()
    {
        Queue::fake();

        $this->getJson('api/dimension-search-runs/catalogo/previa')->assertStatus(403);
        $this->postJson('api/dimension-search-runs/catalogo', ['solo_con_stock' => true])->assertStatus(403);
        $this->assertSame(0, DimensionSearchRun::count());

        $this->actuar_como($this->owner, true);

        $this->nuevo_articulo('CON STOCK 1', null, ['stock' => 5]);
        $this->nuevo_articulo('CON STOCK 2', null, ['stock' => 1]);
        $this->nuevo_articulo('SIN STOCK 1', null, ['stock' => 0]);

        $previa = $this->getJson('api/dimension-search-runs/catalogo/previa?solo_con_stock=1');
        $previa->assertStatus(200);
        $this->assertTrue($previa->json('solo_con_stock'));
        $this->assertSame(2, $previa->json('a_buscar'));
        $this->assertSame(1, $previa->json('excluidos_sin_stock'));
        $this->assertTrue($previa->json('proveedor_configurado'));
        $this->assertTrue($previa->json('ia_configurada'));
        $this->assertTrue($previa->json('se_puede_lanzar'));
        $this->assertSame(4, $previa->json('estimacion.busquedas'));
        $this->assertNull($previa->json('corrida_activa'));

        $todo = $this->getJson('api/dimension-search-runs/catalogo/previa');
        $this->assertSame(3, $todo->json('a_buscar'));
        $this->assertFalse($todo->json('solo_con_stock'));

        $r = $this->postJson('api/dimension-search-runs/catalogo', ['solo_con_stock' => true]);

        $r->assertStatus(201);
        $this->assertSame('pendiente', $r->json('status'));
        $this->assertTrue($r->json('solo_con_stock'));
        $this->assertSame(2, $r->json('total'));
        $this->assertSame('catalogo', $r->json('origen'));
        $this->assertSame(2, (int) $r->json('model.total'));
        Queue::assertPushed(ProcessDimensionSearchRunJob::class, 1);

        // Una segunda mientras la primera está en curso: 422.
        $dos = $this->postJson('api/dimension-search-runs/catalogo', ['solo_con_stock' => true]);
        $dos->assertStatus(422);
        $this->assertStringContainsString('Ya hay una búsqueda de medidas de todo el catálogo en curso', $dos->json('message'));
        $this->assertSame(1, DimensionSearchRun::count());
    }

    /**
     * Sin Serper, sin IA o sin nada para buscar no se lanza.
     *
     * @group dimension-search
     * @test
     */
    public function no_se_lanza_sin_serper_sin_ia_o_sin_articulos()
    {
        Queue::fake();
        $this->actuar_como($this->owner, true);

        // Sin artículos.
        $vacio = $this->postJson('api/dimension-search-runs/catalogo');
        $vacio->assertStatus(422);
        $this->assertStringContainsString('No hay artículos para buscar', $vacio->json('message'));

        $this->nuevo_articulo('UN ARTICULO');

        config(['services.serper.api_key' => '']);
        $sin_serper = $this->postJson('api/dimension-search-runs/catalogo');
        $sin_serper->assertStatus(422);
        $this->assertStringContainsString('clave de Serper', $sin_serper->json('message'));
        $this->assertFalse($this->getJson('api/dimension-search-runs/catalogo/previa')->json('se_puede_lanzar'));

        config(['services.serper.api_key' => 'SERPER-DE-PRUEBA', 'services.deepseek.api_key' => '']);
        $sin_ia = $this->postJson('api/dimension-search-runs/catalogo');
        $sin_ia->assertStatus(422);
        $this->assertStringContainsString('sin la IA', $sin_ia->json('message'));
        $this->assertFalse($this->getJson('api/dimension-search-runs/catalogo/previa')->json('ia_configurada'));

        $this->assertSame(0, DimensionSearchRun::count());
        Queue::assertNothingPushed();
    }

    /**
     * El límite del POST.
     *
     * @group dimension-search
     * @test
     */
    public function el_limite_del_lanzamiento_acota_los_articulos_de_la_corrida()
    {
        Queue::fake();
        $this->actuar_como($this->owner, true);

        for ($i = 0; $i < 5; $i++) {
            $this->nuevo_articulo('ARTICULO '.$i);
        }

        $r = $this->postJson('api/dimension-search-runs/catalogo', ['limite' => 3]);

        $r->assertStatus(201);
        $this->assertSame(3, $r->json('total'));
    }
}
