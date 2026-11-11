<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Models\Article;
use App\Models\PriceChange;
use App\Services\HistorialPreciosService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\ListasSiempreTestCase;

class HistorialCompletoPorListaTest extends ListasSiempreTestCase
{
    private function escenario()
    {
        list($dueno, $final) = $this->con_precio_final($this->dueno_nuevo('historial'));
        $lista = $this->otra_lista($dueno, 'Negocio', 100);
        $articulo = $this->articulo($dueno, 'precio por lista', ['cost' => 100, 'percentage_gain' => 50]);
        $this->actingAs($dueno, 'web');
        $this->recalcular($articulo, $dueno);
        return [$dueno, $articulo->fresh(), $lista, $final];
    }

    private function payload($articulo, array $cambios = [], array $atributos = [])
    {
        $articulo = $articulo->fresh();
        $listas = [];
        foreach ($articulo->price_types as $lista) {
            $pivot = $lista->pivot->toArray();
            $listas[] = ['id' => $lista->id, 'pivot' => array_merge($pivot, $cambios[$lista->id] ?? [])];
        }
        return array_merge($articulo->getAttributes(), [
            'id' => $articulo->id, 'price_types' => $listas, 'price_type_monedas' => [],
            'cost_incluye_iva' => 0, 'tags' => [],
        ], $atributos);
    }

    /** @test */
    public function endpoint_registra_lista_exclusiva_al_costo_con_general_invariable()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $base = $articulo->final_price;
        $previo = $this->pivote($articulo, $lista)->final_price;
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, [
            $lista->id => ['setear_precio_final' => 1, 'final_price' => 100],
        ]))->assertOk();
        $this->assertSame($base, $articulo->fresh()->final_price);
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $detalle = collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id);
        $this->assertEquals($previo, $detalle['precio_anterior']);
        $this->assertSame('100.00', $detalle['precio_nuevo']);
        $this->assertSame('Negocio', $detalle['lista_nombre']);
        $this->assertSame('ficha', $evento->origen);
        $this->assertEquals($dueno->id, $evento->employee_id);
        $this->assertEquals(100, $this->pivote($articulo, $lista)->final_price);
    }

    /** @test */
    public function precio_final_y_espejo_generan_un_evento_y_repetir_no_agrega_ruido()
    {
        list($dueno, $articulo, $lista, $final) = $this->escenario();
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, [
            $final->id => ['setear_precio_final' => 1, 'final_price' => 350],
        ]))->assertOk();
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $this->assertCount(1, collect($evento->cambios_listas)->where('lista_id', $final->id));
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo))->assertOk();
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
    }

    /** @test */
    public function recalculo_en_lote_registra_lista_sin_cambiar_precio_general()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $base = $articulo->final_price;
        $articulo->price_types()->updateExistingPivot($lista->id, ['percentage' => 200, 'setear_precio_final' => 0]);
        RecalculoDePreciosEnLote::recalcular([$articulo->id], $dueno, $dueno->id);
        $this->assertSame($base, $articulo->fresh()->final_price);
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        RecalculoDePreciosEnLote::recalcular([$articulo->id], $dueno, $dueno->id);
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
    }

    /** @test */
    public function rollback_descarta_precio_evento_y_estado_del_scope()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $antes = $this->pivote($articulo, $lista)->final_price;
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        try {
            HistorialPreciosService::ejecutar([$articulo->id], $dueno->id, 'prueba', function () use ($articulo, $lista) {
                $articulo->price_types()->updateExistingPivot($lista->id, ['final_price' => 777]);
                throw new \RuntimeException('Fallo de la operación');
            });
            $this->fail('La operación debía fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo de la operación', $e->getMessage());
        }
        $this->assertFalse(HistorialPreciosService::activo());
        $this->assertEquals($antes, $this->pivote($articulo, $lista)->final_price);
        $this->assertSame($cantidad, PriceChange::where('article_id', $articulo->id)->count());
    }

    /** @test */
    public function borrar_lista_conserva_nombre_y_registra_baja()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $this->deleteJson('api/price-type/'.$lista->id)->assertOk();
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $detalle = collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id);
        $this->assertSame('baja', $detalle['operacion']);
        $this->assertSame('Negocio', $detalle['lista_nombre']);
        $this->assertNull($detalle['precio_nuevo']);
    }

    /** @test */
    public function la_lectura_del_historial_verifica_tenencia()
    {
        list($dueno, $articulo) = $this->escenario();
        $otro = $this->dueno_nuevo('ajeno');
        $this->actingAs($otro, 'web');
        $this->getJson('api/price-change/'.$articulo->id)->assertNotFound();
    }
    /** @test */
    public function filas_de_moneda_residuales_no_ocultan_cambios_del_pivote_activo()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $articulo->price_type_monedas()->create(['price_type_id' => $lista->id, 'moneda_id' => 1, 'final_price' => 300]);
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, [
            $lista->id => ['setear_precio_final' => 1, 'final_price' => 100],
        ]))->assertOk();
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $detalle = collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id);
        $this->assertSame('100.00', $detalle['precio_nuevo']);
        $this->assertNotSame('300.00', $detalle['precio_anterior']);
    }

    /** @test */
    public function una_lista_unica_registra_el_cambio_y_no_el_formato_decimal()
    {
        list($dueno, $final) = $this->con_precio_final($this->dueno_nuevo('unica'));
        $this->actingAs($dueno, 'web');
        $articulo = $this->articulo($dueno, 'unica', ['cost' => 100, 'price' => 200]);
        $this->recalcular($articulo, $dueno);
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, [], ['price' => 100]))->assertOk();
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        $this->assertCount(1, $evento->cambios_listas);
        $this->assertEquals($final->id, $evento->cambios_listas[0]['lista_id']);
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, [], ['price' => '100.000000']))->assertOk();
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
    }

    /** @test */
    public function editar_precio_en_dolares_registra_moneda_y_no_duplica_espejo_pesos()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $dueno = $this->con_extension($dueno, 'ventas_en_dolares');
        $this->actingAs($dueno, 'web');
        $articulo->price_type_monedas()->create(['price_type_id' => $lista->id, 'moneda_id' => 1, 'final_price' => 200, 'setear_precio_final' => 1]);
        $articulo->price_type_monedas()->create(['price_type_id' => $lista->id, 'moneda_id' => 2, 'final_price' => 2, 'setear_precio_final' => 1]);
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $payload = $this->payload($articulo, [], ['price_type_monedas' => [
            ['price_type_id' => $lista->id, 'moneda_id' => 1, 'percentage' => 0, 'final_price' => 100, 'setear_precio_final' => 1],
            ['price_type_id' => $lista->id, 'moneda_id' => 2, 'percentage' => 0, 'final_price' => 1, 'setear_precio_final' => 1],
        ]]);
        $this->putJson('api/article/'.$articulo->id, $payload)->assertOk();
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $detalles = collect($evento->cambios_listas)->where('lista_id', $lista->id);
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        $this->assertCount(2, $detalles);
        $this->assertSame('1.00', $detalles->firstWhere('moneda_id', 2)['precio_nuevo']);
        $this->assertSame('100.00', $detalles->firstWhere('moneda_id', 1)['precio_nuevo']);
    }

    /** @test */
    public function null_y_cero_no_se_confunden_y_la_simulacion_no_deja_eventos()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $lista->id)->update(['final_price' => null]);
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, [
            $lista->id => ['setear_precio_final' => 1, 'final_price' => 0],
        ]))->assertOk();
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $detalle = collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id);
        $this->assertNull($detalle['precio_anterior']);
        $this->assertSame('0.00', $detalle['precio_nuevo']);
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        DB::beginTransaction();
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, [
            $lista->id => ['setear_precio_final' => 1, 'final_price' => 77],
        ]))->assertOk();
        DB::rollBack();
        $this->assertFalse(HistorialPreciosService::activo());
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
    }

    /** @test */
    public function consultar_desglose_que_recalcula_no_saltea_historial_de_lista()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $base = $articulo->final_price;
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $articulo->price_types()->updateExistingPivot($lista->id, ['percentage' => 300, 'setear_precio_final' => 0]);
        $this->getJson('api/article/price-type-description/'.$articulo->id.'/'.$lista->id)->assertOk();
        $this->assertSame($base, $articulo->fresh()->final_price);
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $this->assertNotNull(collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id));
    }

    /** @test */
    public function historial_del_empleado_sin_permiso_no_expone_costos_ni_precio_general()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $empleado = \App\Models\User::create([
            'name' => 'zz empleado historial', 'email' => uniqid().'@test.local',
            'owner_id' => $dueno->id, 'admin_access' => 0, 'password' => \Illuminate\Support\Facades\Hash::make(uniqid()),
        ]);
        $this->actingAs($empleado, 'web');
        $respuesta = $this->getJson('api/price-change/'.$articulo->id)->assertOk()->json('models');
        $this->assertNotEmpty($respuesta);
        $this->assertArrayNotHasKey('cost', $respuesta[0]);
        $this->assertArrayNotHasKey('final_price', $respuesta[0]);
        $this->assertArrayNotHasKey('precio_final_anterior', $respuesta[0]);
        $this->assertNotEmpty($respuesta[0]['price_types']);
    }

    /** @test */
    public function borrar_lista_con_precio_solo_por_moneda_deja_baja_y_no_huerfanos()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $dueno = $this->con_extension($dueno, 'ventas_en_dolares');
        $this->actingAs($dueno, 'web');
        $articulo->price_types()->detach($lista->id);
        $articulo->price_type_monedas()->create(['price_type_id' => $lista->id, 'moneda_id' => 2, 'final_price' => 20]);
        $this->deleteJson('api/price-type/'.$lista->id)->assertOk();
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $detalle = collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id);
        $this->assertSame('baja', $detalle['operacion']);
        $this->assertSame('20.00', $detalle['precio_anterior']);
        $this->assertSame(0, DB::table('article_price_type_monedas')->where('price_type_id', $lista->id)->count());
    }

    /** @test */
    public function dos_listas_propias_cambian_juntas_con_un_evento_y_base_estable()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $tienda = \App\Models\PriceType::create(['name' => 'Tienda nube', 'user_id' => $dueno->id]);
        $articulo->price_types()->attach($tienda->id, ['setear_precio_final' => 1, 'final_price' => 250]);
        $base = $articulo->fresh()->final_price;
        $cantidad = PriceChange::where('article_id', $articulo->id)->count();
        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, [
            $lista->id => ['setear_precio_final' => 1, 'final_price' => 100],
            $tienda->id => ['setear_precio_final' => 1, 'final_price' => 110],
        ]))->assertOk();
        $this->assertSame($base, $articulo->fresh()->final_price);
        $this->assertSame($cantidad + 1, PriceChange::where('article_id', $articulo->id)->count());
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $this->assertCount(2, $evento->cambios_listas);
        $this->assertSame('100.00', collect($evento->cambios_listas)->firstWhere('lista_id', $lista->id)['precio_nuevo']);
        $this->assertSame('110.00', collect($evento->cambios_listas)->firstWhere('lista_id', $tienda->id)['precio_nuevo']);
    }

    /** @test */
    public function moneda_duplicada_heredada_no_tapa_el_cambio_de_la_fila_que_lee_el_catalogo()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $dueno = $this->con_extension($dueno, 'ventas_en_dolares');
        $this->actingAs($dueno, 'web');
        $primera = $articulo->price_type_monedas()->create(['price_type_id' => $lista->id, 'moneda_id' => 2, 'final_price' => 2]);
        $articulo->price_type_monedas()->create(['price_type_id' => $lista->id, 'moneda_id' => 2, 'final_price' => 7]);
        HistorialPreciosService::ejecutar([$articulo->id], $dueno->id, 'moneda_duplicada', function () use ($primera) {
            $primera->final_price = 1;
            $primera->save();
        });
        $detalle = collect(PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first()->cambios_listas)->firstWhere('moneda_id', 2);
        $this->assertSame('2.00', $detalle['precio_anterior']);
        $this->assertSame('1.00', $detalle['precio_nuevo']);
    }

    /** @test */
    public function foto_publica_de_precio_final_sigue_disponible_para_spa_anterior_sin_costos()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('lectura-publica'));
        $articulo = $this->articulo($dueno, 'lectura-publica', ['cost' => 100, 'price' => 200]);
        $this->recalcular($articulo, $dueno);
        $empleado = \App\Models\User::create(['name' => 'zz empleado', 'email' => uniqid().'@test.local',
            'owner_id' => $dueno->id, 'password' => \Illuminate\Support\Facades\Hash::make(uniqid())]);
        $this->actingAs($empleado, 'web');
        $modelo = $this->getJson('api/price-change/'.$articulo->id)->assertOk()->json('models.0');
        $this->assertArrayNotHasKey('cost', $modelo);
        $this->assertArrayNotHasKey('precio_final_anterior', $modelo);
        $this->assertEquals(200, $modelo['final_price']);
        $this->assertEquals($modelo['price_types'][0]['pivot']['final_price'], $modelo['final_price']);
    }

    /** @test */
    public function metadata_identifica_operacion_compartida_y_origen_real_del_motor()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        $otro = $this->articulo($dueno, 'metadata', ['cost' => 100, 'percentage_gain' => 50]);
        $this->recalcular($otro, $dueno);
        $ids = [$articulo->id, $otro->id];
        DB::table('article_price_type')->whereIn('article_id', $ids)->where('price_type_id', $lista->id)->update(['percentage' => 500, 'setear_precio_final' => 0]);
        $marca = PriceChange::max('id');
        RecalculoDePreciosEnLote::recalcular($ids, $dueno, $dueno->id);
        $eventos = PriceChange::whereIn('article_id', $ids)->where('id', '>', $marca)->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame($eventos[0]->operacion_uuid, $eventos[1]->operacion_uuid);
        $this->assertSame(1, preg_match('/^[a-f0-9-]{36}$/', $eventos[0]->operacion_uuid));
        $this->assertSame('recalculo_lote', $eventos[0]->origen);
        $this->assertSame('recalculo_lote', $eventos[1]->origen);
        $uuid = $eventos[0]->operacion_uuid;
        DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $lista->id)->update(['percentage' => 600]);
        ArticleHelper::setFinalPrice($articulo->fresh(), $dueno->id, $dueno, $dueno->id);
        $ultimo = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        $this->assertSame('recalculo', $ultimo->origen);
        $this->assertNotSame($uuid, $ultimo->operacion_uuid);
    }

    /** @test */
    public function la_foto_historica_conserva_la_precision_persistida_de_costo_y_precio()
    {
        list($dueno, $articulo, $lista) = $this->escenario();
        DB::table('articles')->where('id', $articulo->id)->update(['cost' => 100.123456, 'price' => 123.654321]);
        $antes = DB::table('articles')->where('id', $articulo->id)->first();
        HistorialPreciosService::ejecutar([$articulo->id], $dueno->id, 'precision', function () use ($articulo, $lista) {
            DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $lista->id)->update(['final_price' => 123]);
        });
        $evento = PriceChange::where('article_id', $articulo->id)->orderByDesc('id')->first();
        // El control usa la escritura legacy: MySQL aplica la escala real de
        // cada columna histórica (puede diferir de articles), sin ALTER alguno.
        $control = PriceChange::create([
            'article_id' => $articulo->id, 'employee_id' => $dueno->id,
            'cost' => $antes->cost, 'price' => $antes->price, 'final_price' => $antes->final_price,
        ])->fresh();
        $this->assertSame($control->cost, $evento->cost);
        $this->assertSame($control->price, $evento->price);
        $this->assertSame($control->final_price, $evento->final_price);
    }

}
