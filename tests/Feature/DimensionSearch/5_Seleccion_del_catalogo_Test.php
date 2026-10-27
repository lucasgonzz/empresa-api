<?php

namespace Tests\Feature\DimensionSearch;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use Carbon\Carbon;

/**
 * Qué artículos entran a la búsqueda masiva de peso y medidas y en qué orden (decisiones D5 y D7 del plan
 * de la misión medidas-masivas-catalogo, 27/10/2026), y la previa de costo.
 *
 * «Activo» es `articles.status = 'active'` y «con stock» es `articles.stock > 0`: las mismas definiciones
 * que usa la selección de imágenes.
 */
class Seleccion_del_catalogo_Test extends DimensionSearchTestCase
{
    /**
     * Los ids que elegiría el catálogo, sin tope práctico.
     *
     * @param  bool $solo_con_stock
     * @param  int  $tope
     * @return array
     */
    protected function elegidos($solo_con_stock = false, $tope = 5000)
    {
        return DimensionSearchRunHelper::ids_del_catalogo($this->owner->id, $solo_con_stock, $tope);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function entran_los_activos_a_los_que_les_falta_al_menos_una_medida_y_no_los_completos()
    {
        $sin_nada    = $this->nuevo_articulo('SIN NADA');
        $en_cero     = $this->nuevo_articulo('TODO EN CERO', null, ['peso' => 0, 'alto' => 0, 'ancho' => 0, 'profundidad' => 0]);
        $solo_peso   = $this->nuevo_articulo('SOLO PESO', null, ['peso' => 1]);
        $falta_largo = $this->nuevo_articulo('FALTA EL LARGO', null, ['peso' => 1, 'alto' => 2, 'ancho' => 3, 'profundidad' => 0]);
        $completo    = $this->nuevo_articulo('COMPLETO', null, ['peso' => 1, 'alto' => 2, 'ancho' => 3, 'profundidad' => 4]);
        $inactivo    = $this->nuevo_articulo('INACTIVO', null, ['status' => 'inactive']);
        $borrado     = $this->nuevo_articulo('BORRADO');
        $borrado->delete();

        // De otro comercio: nunca.
        $otro = $this->otro_comercio();
        \App\Models\Article::create(['name' => 'AJENO', 'user_id' => $otro->id, 'status' => 'active']);

        $elegidos = $this->elegidos();

        $this->assertEqualsCanonicalizing(
            [$sin_nada->id, $en_cero->id, $solo_peso->id, $falta_largo->id],
            $elegidos
        );
        $this->assertNotContains($completo->id, $elegidos, 'Un artículo con las cuatro medidas no entra (D5).');
        $this->assertNotContains($inactivo->id, $elegidos);
        $this->assertNotContains($borrado->id, $elegidos);

        $conteos = DimensionSearchRunHelper::conteos_del_catalogo($this->owner->id);
        $this->assertSame(5, $conteos['activos']);
        $this->assertSame(4, $conteos['sin_medidas']);
        $this->assertSame(1, $conteos['con_medidas_completas']);
        $this->assertSame(4, $conteos['candidatos']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function con_solo_con_stock_entran_solo_los_de_stock_mayor_a_cero()
    {
        $con_stock = $this->nuevo_articulo('CON STOCK', null, ['stock' => 3]);
        $en_cero   = $this->nuevo_articulo('STOCK CERO', null, ['stock' => 0]);
        $negativo  = $this->nuevo_articulo('STOCK NEGATIVO', null, ['stock' => -2]);
        $sin_dato  = $this->nuevo_articulo('STOCK NULO', null, ['stock' => null]);

        $this->assertEqualsCanonicalizing([$con_stock->id, $en_cero->id, $negativo->id, $sin_dato->id], $this->elegidos(false));
        $this->assertSame([$con_stock->id], $this->elegidos(true));

        $conteos = DimensionSearchRunHelper::conteos_del_catalogo($this->owner->id, true);
        $this->assertSame(3, $conteos['excluidos_sin_stock']);
        $this->assertSame(1, $conteos['candidatos']);
    }

    /**
     * Primero lo publicado en la tienda, después lo que tiene stock, después el resto por id.
     *
     * @group dimension-search
     * @test
     */
    public function el_orden_es_publicados_en_la_tienda_despues_con_stock_despues_el_resto()
    {
        // `articles.online` es 1 por defecto: lo que no está publicado se pone en 0 a propósito.
        $resto_1   = $this->nuevo_articulo('RESTO 1', null, ['online' => 0]);
        $con_stock = $this->nuevo_articulo('CON STOCK', null, ['online' => 0, 'stock' => 4]);
        $resto_2   = $this->nuevo_articulo('RESTO 2', null, ['online' => 0]);
        $online    = $this->nuevo_articulo('ONLINE', null, ['online' => 1]);
        $online_stock = $this->nuevo_articulo('ONLINE CON STOCK', null, ['online' => 1, 'stock' => 2]);

        $this->assertSame(
            [$online_stock->id, $online->id, $con_stock->id, $resto_1->id, $resto_2->id],
            $this->elegidos()
        );

        // El tope corta el final de la lista.
        $this->assertSame([$online_stock->id, $online->id], $this->elegidos(false, 2));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function no_entran_los_que_esperan_revision_estan_en_otra_corrida_o_se_buscaron_hace_poco_sin_exito()
    {
        $a_revisar   = $this->nuevo_articulo('A REVISAR');
        $en_corrida  = $this->nuevo_articulo('EN OTRA CORRIDA');
        $sin_datos_reciente = $this->nuevo_articulo('SIN DATOS RECIENTE');
        $sin_datos_viejo    = $this->nuevo_articulo('SIN DATOS VIEJO');
        $error_reciente     = $this->nuevo_articulo('ERROR RECIENTE');
        $rechazado_reciente = $this->nuevo_articulo('RECHAZADO RECIENTE');
        $rechazado_viejo    = $this->nuevo_articulo('RECHAZADO VIEJO');
        $quitado_reciente   = $this->nuevo_articulo('QUITADO RECIENTE');
        $libre              = $this->nuevo_articulo('LIBRE');
        $aprobado           = $this->nuevo_articulo('YA APROBADO (se cargó)', null, ['peso' => 1, 'alto' => 1, 'ancho' => 1, 'profundidad' => 1]);

        $vieja = $this->corrida([$libre]);
        $vieja->update(['status' => DimensionSearchRun::STATUS_TERMINADA, 'finished_at' => Carbon::now()]);
        DimensionSearchItem::where('run_id', $vieja->id)->delete();

        $this->item_en($vieja, $a_revisar, 'a_revisar');
        $this->item_en($vieja, $sin_datos_reciente, 'sin_datos', ['motivo' => 'no_coincide', 'procesado_at' => Carbon::now()->subDays(10)]);
        $this->item_en($vieja, $sin_datos_viejo, 'sin_datos', ['motivo' => 'sin_resultados', 'procesado_at' => Carbon::now()->subDays(91)]);
        // Un error no dice nada del producto: se vuelve a intentar.
        $this->item_en($vieja, $error_reciente, 'sin_procesar', ['motivo' => 'error_de_busqueda', 'procesado_at' => Carbon::now()->subDays(1)]);
        $this->item_en($vieja, $rechazado_reciente, 'rechazada', ['revisado_at' => Carbon::now()->subDays(5), 'procesado_at' => Carbon::now()->subDays(200)]);
        $this->item_en($vieja, $rechazado_viejo, 'rechazada', ['revisado_at' => Carbon::now()->subDays(120), 'procesado_at' => Carbon::now()->subDays(130)]);
        $this->item_en($vieja, $quitado_reciente, 'quitada', ['revisado_at' => Carbon::now()->subDays(2), 'procesado_at' => Carbon::now()->subDays(3)]);

        // Otra corrida EN CURSO con un pendiente.
        $activa = $this->corrida([$en_corrida]);
        $activa->update(['status' => DimensionSearchRun::STATUS_EN_PROCESO]);

        $elegidos = $this->elegidos();

        $this->assertEqualsCanonicalizing(
            [$sin_datos_viejo->id, $error_reciente->id, $rechazado_viejo->id, $libre->id],
            $elegidos
        );

        $conteos = DimensionSearchRunHelper::conteos_del_catalogo($this->owner->id);
        $this->assertSame(1, $conteos['excluidos_pendientes_de_revision']);
        $this->assertSame(3, $conteos['excluidos_ya_buscados']);
        $this->assertSame(1, $conteos['excluidos_en_otra_corrida']);
        $this->assertSame(4, $conteos['candidatos']);
    }

    /**
     * Crear la corrida arma los items en el orden de prioridad con el snapshot de nombre y códigos.
     *
     * @group dimension-search
     * @test
     */
    public function crear_la_corrida_guarda_el_orden_y_el_snapshot_de_nombre_y_codigos()
    {
        \Illuminate\Support\Facades\Queue::fake();

        $resto  = $this->nuevo_articulo('RESTO', null, ['provider_code' => 'PROV-1', 'online' => 0]);
        $online = $this->nuevo_articulo('ONLINE', '7791234567893', ['online' => 1]);

        $r = DimensionSearchRunHelper::crear_del_catalogo($this->owner, $this->owner->id, true === false);

        $this->assertSame(201, $r['status']);

        $items = DimensionSearchItem::where('run_id', $r['run']->id)->orderBy('orden')->get();

        $this->assertSame([$online->id, $resto->id], $items->pluck('article_id')->all());
        $this->assertSame([1, 2], $items->pluck('orden')->all());
        $this->assertSame('ONLINE', $items[0]->article_name);
        $this->assertSame('7791234567893', $items[0]->article_bar_code);
        $this->assertSame('PROV-1', $items[1]->article_provider_code);
        $this->assertSame('pendiente', $items[0]->status);
        $this->assertSame(2, (int) $r['run']->total);
        $this->assertFalse((bool) $r['run']->solo_con_stock);
        $this->assertSame($this->owner->id, (int) $r['run']->creada_por);
    }

    /**
     * El tope por corrida de la configuración.
     *
     * @group dimension-search
     * @test
     */
    public function el_tope_de_la_configuracion_acota_la_corrida_y_la_previa_dice_cuantos_quedan_para_otra()
    {
        \Illuminate\Support\Facades\Queue::fake();

        config(['services.medidas_masivas.tope_catalogo' => 3]);

        for ($i = 0; $i < 5; $i++) {
            $this->nuevo_articulo('ARTICULO '.$i);
        }

        $previa = DimensionSearchRunHelper::previa_del_catalogo($this->owner);

        $this->assertSame(3, $previa['tope']);
        $this->assertSame(3, $previa['a_buscar']);
        $this->assertSame(2, $previa['quedan_para_otra_corrida']);

        $r = DimensionSearchRunHelper::crear_del_catalogo($this->owner, $this->owner->id);
        $this->assertSame(3, (int) $r['run']->total);
    }

    /**
     * La estimación de la previa: 2,2 búsquedas por artículo; Serper USD 1 cada mil; IA USD 0,0007 por artículo.
     *
     * @group dimension-search
     * @test
     */
    public function la_previa_estima_busquedas_tiempo_y_costo()
    {
        for ($i = 0; $i < 100; $i++) {
            $this->nuevo_articulo('ARTICULO '.$i);
        }

        $previa = DimensionSearchRunHelper::previa_del_catalogo($this->owner);

        $this->assertSame(100, $previa['a_buscar']);
        $this->assertSame(220, $previa['estimacion']['busquedas']);
        $this->assertSame(20, $previa['estimacion']['minutos']);
        $this->assertEquals(0.22, $previa['estimacion']['usd_busquedas']);
        $this->assertEquals(0.07, $previa['estimacion']['usd_ia']);
        $this->assertTrue($previa['proveedor_configurado']);
        $this->assertSame('serper', $previa['proveedor']);
        $this->assertTrue($previa['ia_configurada']);
        $this->assertArrayNotHasKey('tope_demo', $previa, 'Fuera de una demo no hay tope.');
    }

    /**
     * Las purgas: una corrida vieja sin nada a revisar se borra al crear una nueva, una con pendientes de revisión no.
     *
     * @group dimension-search
     * @test
     */
    public function al_crear_una_corrida_se_purgan_las_viejas_sin_nada_a_revisar()
    {
        \Illuminate\Support\Facades\Queue::fake();

        $a = $this->nuevo_articulo('VIEJO UNO');
        $b = $this->nuevo_articulo('VIEJO DOS');

        $sin_pendientes = $this->corrida([$a]);
        $sin_pendientes->update(['status' => DimensionSearchRun::STATUS_TERMINADA, 'finished_at' => Carbon::now()->subDays(200)]);
        DimensionSearchItem::where('run_id', $sin_pendientes->id)->update(['status' => 'sin_datos', 'motivo' => 'sin_resultados', 'procesado_at' => Carbon::now()->subDays(200)]);

        $con_pendientes = $this->corrida([$b]);
        $con_pendientes->update(['status' => DimensionSearchRun::STATUS_TERMINADA, 'finished_at' => Carbon::now()->subDays(200)]);
        DimensionSearchItem::where('run_id', $con_pendientes->id)->update(['status' => 'a_revisar']);

        $this->nuevo_articulo('NUEVO');

        $r = DimensionSearchRunHelper::crear_del_catalogo($this->owner, $this->owner->id);
        $this->assertSame(201, $r['status']);

        $this->assertNull(DimensionSearchRun::find($sin_pendientes->id));
        $this->assertSame(0, DimensionSearchItem::where('run_id', $sin_pendientes->id)->count());
        $this->assertNotNull(DimensionSearchRun::find($con_pendientes->id));
    }
}
