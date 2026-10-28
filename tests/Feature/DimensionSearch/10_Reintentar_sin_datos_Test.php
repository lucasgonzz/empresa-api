<?php

namespace Tests\Feature\DimensionSearch;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Jobs\ProcessDimensionSearchRunJob;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Reintentar los «sin datos» en una corrida nueva (misión medidas-sin-datos, 28/10/2026): la opción
 * `reintentar_sin_datos` del helper, de la previa, del endpoint y del comando `medidas:buscar`.
 *
 * Con la opción entran los artículos cuyo resultado fue `sin_datos`; SIGUEN afuera los rechazados o quitados
 * hace poco (una persona dijo que no), los que esperan revisión (`a_revisar`), los que están en una corrida
 * activa y los que ya tienen todas las medidas.
 */
class Reintentar_sin_datos_Test extends DimensionSearchTestCase
{
    /** @var array nombre => Article */
    protected $arts = [];

    /** @var mixed */
    protected $user_id_anterior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id_anterior = config('app.USER_ID');

        config(['app.USER_ID' => $this->owner->id]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        config(['app.USER_ID' => $this->user_id_anterior]);

        parent::tearDown();
    }

    /**
     * Arma el escenario: un artículo por cada situación posible.
     *
     * @return void
     */
    protected function escenario()
    {
        foreach ([
            'sin_datos_no_coincide', 'sin_datos_bloqueadas', 'sin_datos_pdf', 'sin_datos_viejo', 'sin_datos_y_en_corrida',
            'a_revisar', 'rechazado', 'quitado', 'error_de_busqueda', 'libre',
        ] as $nombre) {
            $this->arts[$nombre] = $this->nuevo_articulo(strtoupper($nombre));
        }

        $this->arts['completo'] = $this->nuevo_articulo('COMPLETO', null, ['peso' => 1, 'alto' => 1, 'ancho' => 1, 'profundidad' => 1]);

        $vieja = $this->corrida([$this->arts['libre']]);
        $vieja->update(['status' => DimensionSearchRun::STATUS_TERMINADA, 'finished_at' => Carbon::now()]);
        DimensionSearchItem::where('run_id', $vieja->id)->delete();

        $hace = function ($dias) {
            return Carbon::now()->subDays($dias);
        };

        $this->item_en($vieja, $this->arts['sin_datos_no_coincide'], 'sin_datos', ['motivo' => 'no_coincide', 'procesado_at' => $hace(10)]);
        $this->item_en($vieja, $this->arts['sin_datos_bloqueadas'], 'sin_datos', ['motivo' => 'paginas_bloqueadas', 'procesado_at' => $hace(3)]);
        $this->item_en($vieja, $this->arts['sin_datos_pdf'], 'sin_datos', ['motivo' => 'pdf_no_leido', 'procesado_at' => $hace(3)]);
        $this->item_en($vieja, $this->arts['sin_datos_viejo'], 'sin_datos', ['motivo' => 'sin_resultados', 'procesado_at' => $hace(91)]);
        $this->item_en($vieja, $this->arts['sin_datos_y_en_corrida'], 'sin_datos', ['motivo' => 'sin_medidas_en_la_pagina', 'procesado_at' => $hace(2)]);
        $this->item_en($vieja, $this->arts['a_revisar'], 'a_revisar');
        $this->item_en($vieja, $this->arts['rechazado'], 'rechazada', ['revisado_at' => $hace(5), 'procesado_at' => $hace(6)]);
        $this->item_en($vieja, $this->arts['quitado'], 'quitada', ['revisado_at' => $hace(2), 'procesado_at' => $hace(3)]);
        $this->item_en($vieja, $this->arts['error_de_busqueda'], 'sin_procesar', ['motivo' => 'error_de_busqueda', 'procesado_at' => $hace(1)]);

        // Una corrida EN CURSO con el artículo `sin_datos_y_en_corrida` esperando su turno. Es de SELECCIÓN y no de
        // catálogo para no impedir que el test lance la corrida de catálogo.
        $activa = $this->corrida([$this->arts['sin_datos_y_en_corrida']], ['origen' => DimensionSearchRun::ORIGEN_SELECCION]);
        $activa->update(['status' => DimensionSearchRun::STATUS_EN_PROCESO]);
    }

    /**
     * Los ids que elegiría el catálogo.
     *
     * @param  bool $reintentar
     * @return array  nombres
     */
    protected function nombres_elegidos($reintentar)
    {
        $ids = DimensionSearchRunHelper::ids_del_catalogo($this->owner->id, false, 5000, $reintentar);

        $nombres = [];

        foreach ($this->arts as $nombre => $articulo) {
            if (in_array($articulo->id, $ids, true)) {
                $nombres[] = $nombre;
            }
        }

        sort($nombres);

        return $nombres;
    }

    /**
     * @group dimension-search
     * @test
     */
    public function sin_la_opcion_los_sin_datos_recientes_siguen_afuera()
    {
        $this->escenario();

        $this->assertSame(['error_de_busqueda', 'libre', 'sin_datos_viejo'], $this->nombres_elegidos(false));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function con_la_opcion_entran_los_sin_datos_pero_no_los_rechazados_ni_los_que_esperan_revision_ni_los_de_una_corrida_activa()
    {
        $this->escenario();

        $this->assertSame(
            ['error_de_busqueda', 'libre', 'sin_datos_bloqueadas', 'sin_datos_no_coincide', 'sin_datos_pdf', 'sin_datos_viejo'],
            $this->nombres_elegidos(true)
        );

        $elegidos = $this->nombres_elegidos(true);

        $this->assertNotContains('a_revisar', $elegidos);
        $this->assertNotContains('rechazado', $elegidos);
        $this->assertNotContains('quitado', $elegidos);
        $this->assertNotContains('sin_datos_y_en_corrida', $elegidos);
        $this->assertNotContains('completo', $elegidos);
    }

    /**
     * Los conteos y la previa dicen cuántos entran por la opción.
     *
     * @group dimension-search
     * @test
     */
    public function los_conteos_y_la_previa_cuentan_los_que_entran_por_la_opcion()
    {
        $this->escenario();

        $sin = DimensionSearchRunHelper::conteos_del_catalogo($this->owner->id, false, false);
        $con = DimensionSearchRunHelper::conteos_del_catalogo($this->owner->id, false, true);

        // No coincide, bloqueadas y pdf: los tres sin_datos recientes que no están en una corrida activa ni esperan revisión.
        $this->assertSame(3, $sin['reintento_sin_datos']);
        $this->assertSame(3, $con['reintento_sin_datos']);
        $this->assertSame(3, $sin['candidatos']);
        $this->assertSame(6, $con['candidatos']);
        $this->assertSame(6, $sin['excluidos_ya_buscados'], 'Cuatro sin_datos recientes + el rechazado y el quitado.');
        $this->assertSame(2, $con['excluidos_ya_buscados'], 'Con la opción solo quedan el rechazado y el quitado.');
        $this->assertSame(1, $con['excluidos_en_otra_corrida']);

        $previa_sin = DimensionSearchRunHelper::previa_del_catalogo($this->owner, false, false);
        $previa_con = DimensionSearchRunHelper::previa_del_catalogo($this->owner, false, true);

        $this->assertFalse($previa_sin['reintentar_sin_datos']);
        $this->assertTrue($previa_con['reintentar_sin_datos']);
        $this->assertSame(3, $previa_sin['a_buscar']);
        $this->assertSame(6, $previa_con['a_buscar']);
        $this->assertSame(3, $previa_con['reintento_sin_datos']);
    }

    /**
     * La previa estima el respaldo (scrape) y lo incluye en el costo; el texto aclara el rango de créditos.
     *
     * @group dimension-search
     * @test
     */
    public function la_previa_estima_el_costo_del_respaldo_y_lo_suma_al_de_las_busquedas()
    {
        for ($i = 0; $i < 10; $i++) {
            $this->nuevo_articulo('ARTICULO '.$i);
        }

        $previa = DimensionSearchRunHelper::previa_del_catalogo($this->owner);

        $est = $previa['estimacion'];

        $this->assertSame(22, $est['busquedas']);
        $this->assertSame(60, $est['creditos_respaldo'], '10 artículos x 6 créditos (1,5 scrapes x 4).');
        $this->assertEquals(0.06, $est['usd_respaldo']);
        $this->assertEquals(0.02, $est['usd_busquedas'], 'usd_busquedas sigue siendo solo el de las búsquedas.');
        $this->assertStringContainsString('2 a 10 créditos', $est['texto_respaldo']);
        $this->assertStringContainsString('como piso: al menos ~60 créditos', $est['texto_respaldo']);
        $this->assertStringContainsString('puede ser más', $est['texto_respaldo']);

        // Formato argentino: coma decimal y punto de miles (incluido el tope por corrida).
        $this->assertStringContainsString('(USD 0,06)', $est['texto_respaldo']);
        $this->assertStringContainsString('al llegar a 30.000 créditos', $est['texto_respaldo']);
        $this->assertStringNotContainsString('0.06', $est['texto_respaldo']);
        $this->assertStringNotContainsString('30000', $est['texto_respaldo']);

        config(['services.medidas_masivas.creditos_estimados_por_articulo' => 10, 'services.medidas_masivas.max_scrapes_por_articulo' => 2]);

        $previa = DimensionSearchRunHelper::previa_del_catalogo($this->owner);

        $this->assertSame(100, $previa['estimacion']['creditos_respaldo']);
        $this->assertStringContainsString('hasta 2 por artículo', $previa['estimacion']['texto_respaldo']);

        // Con miles: 5.000 créditos de piso y un tope de 12.500.
        config(['services.medidas_masivas.max_creditos_por_corrida' => 12500, 'services.medidas_masivas.creditos_estimados_por_articulo' => 500]);

        $previa = DimensionSearchRunHelper::previa_del_catalogo($this->owner);

        $this->assertStringContainsString('al menos ~5.000 créditos de Serper (USD 5,00)', $previa['estimacion']['texto_respaldo']);
        $this->assertStringContainsString('al llegar a 12.500 créditos', $previa['estimacion']['texto_respaldo']);
    }

    /**
     * El helper crea la corrida con los sin_datos cuando se pide.
     *
     * @group dimension-search
     * @test
     */
    public function crear_del_catalogo_con_la_opcion_arma_una_corrida_nueva_con_los_sin_datos()
    {
        $this->escenario();

        $resultado = DimensionSearchRunHelper::crear_del_catalogo($this->owner, null, false, null, true);

        $this->assertSame(201, $resultado['status']);
        $this->assertSame(6, (int) $resultado['run']->total);

        // Esa corrida termina sin datos para todos: sin la opción no queda nada que buscar (los sin_datos recientes
        // no se repiten solos); con la opción, vuelven a entrar.
        $resultado['run']->update(['status' => DimensionSearchRun::STATUS_TERMINADA, 'finished_at' => Carbon::now()]);
        DimensionSearchItem::where('run_id', $resultado['run']->id)->update(['status' => 'sin_datos', 'motivo' => 'no_coincide', 'procesado_at' => Carbon::now()]);

        $sin_opcion = DimensionSearchRunHelper::crear_del_catalogo($this->owner);

        $this->assertSame(422, $sin_opcion['status']);
        $this->assertStringContainsString('No hay artículos para buscar', $sin_opcion['message']);

        $con_opcion = DimensionSearchRunHelper::crear_del_catalogo($this->owner, null, false, null, true);

        $this->assertSame(201, $con_opcion['status']);
        $this->assertSame(6, (int) $con_opcion['run']->total);
    }

    /**
     * El endpoint: POST acepta `reintentar_sin_datos` y GET previa también.
     *
     * @group dimension-search
     * @test
     */
    public function el_endpoint_acepta_reintentar_sin_datos_en_el_post_y_en_la_previa()
    {
        $this->escenario();
        $this->actuar_como($this->owner, true);

        $sin = $this->getJson('api/dimension-search-runs/catalogo/previa');
        $sin->assertStatus(200);
        $this->assertSame(3, $sin->json('a_buscar'));
        $this->assertSame(3, $sin->json('reintento_sin_datos'));
        $this->assertFalse($sin->json('reintentar_sin_datos'));

        $con = $this->getJson('api/dimension-search-runs/catalogo/previa?reintentar_sin_datos=1');
        $con->assertStatus(200);
        $this->assertSame(6, $con->json('a_buscar'));
        $this->assertSame(3, $con->json('reintento_sin_datos'));
        $this->assertTrue($con->json('reintentar_sin_datos'));
        $this->assertTrue($con->json('se_puede_lanzar') === false || $con->json('se_puede_lanzar') === true);

        // El POST sin la opción: como siempre.
        $r = $this->postJson('api/dimension-search-runs/catalogo', ['limite' => 100]);
        $r->assertStatus(201);
        $this->assertSame(3, $r->json('total'));

        $run = DimensionSearchRun::find($r->json('id'));
        $run->update(['status' => DimensionSearchRun::STATUS_TERMINADA, 'finished_at' => Carbon::now()]);
        DimensionSearchItem::where('run_id', $run->id)->delete();

        // El POST con la opción: entran también los sin_datos.
        $r2 = $this->postJson('api/dimension-search-runs/catalogo', ['reintentar_sin_datos' => true]);
        $r2->assertStatus(201);
        $this->assertSame(6, $r2->json('total'));

        $ids = DimensionSearchItem::where('run_id', $r2->json('id'))->pluck('article_id')->all();
        $this->assertContains($this->arts['sin_datos_pdf']->id, $ids);
        $this->assertNotContains($this->arts['rechazado']->id, $ids);
        $this->assertNotContains($this->arts['a_revisar']->id, $ids);

        Queue::assertPushed(ProcessDimensionSearchRunJob::class);
    }

    /**
     * El valor de la opción se interpreta como booleano ('0' / 'false' no la prenden).
     *
     * @group dimension-search
     * @test
     */
    public function la_opcion_apagada_en_la_query_no_cambia_la_seleccion()
    {
        $this->escenario();
        $this->actuar_como($this->owner, true);

        $this->assertSame(3, $this->getJson('api/dimension-search-runs/catalogo/previa?reintentar_sin_datos=0')->json('a_buscar'));
        $this->assertSame(3, $this->getJson('api/dimension-search-runs/catalogo/previa?reintentar_sin_datos=false')->json('a_buscar'));
    }

    /**
     * El comando: `--reintentar-sin-datos` (con --dry-run, --solo-con-stock y --limite).
     *
     * @group dimension-search
     * @test
     */
    public function el_comando_con_reintentar_sin_datos_incluye_los_sin_datos()
    {
        $this->escenario();

        $this->artisan('medidas:buscar', ['--dry-run' => true, '--reintentar-sin-datos' => true])
            ->expectsOutput('  (--reintentar-sin-datos: vuelven a entrar 3 que habían quedado sin datos)')
            ->expectsOutput('A buscar en esta corrida:                   6 (tope 5000)')
            ->expectsOutput('Es un --dry-run: no se creó ni se despachó nada.')
            ->assertExitCode(0);

        // Sin la opción el dry-run avisa cuántos entrarían.
        $this->artisan('medidas:buscar', ['--dry-run' => true])
            ->expectsOutput('  (con --reintentar-sin-datos entrarían 3 más, que habían quedado sin datos)')
            ->expectsOutput('A buscar en esta corrida:                   3 (tope 5000)')
            ->assertExitCode(0);

        $this->assertSame(0, DimensionSearchRun::where('origen', 'catalogo')->where('status', DimensionSearchRun::STATUS_PENDIENTE)->count());

        $this->artisan('medidas:buscar', ['--reintentar-sin-datos' => true, '--limite' => '4'])->assertExitCode(0);

        $run = DimensionSearchRun::where('status', DimensionSearchRun::STATUS_PENDIENTE)->first();

        $this->assertNotNull($run);
        $this->assertSame(4, (int) $run->total);
        Queue::assertPushed(ProcessDimensionSearchRunJob::class);
    }

    /**
     * El comando con --solo-con-stock combina con la opción.
     *
     * @group dimension-search
     * @test
     */
    public function el_comando_combina_reintentar_sin_datos_con_solo_con_stock()
    {
        $con_stock = $this->nuevo_articulo('CON STOCK SIN DATOS', null, ['stock' => 5]);
        $sin_stock = $this->nuevo_articulo('SIN STOCK SIN DATOS', null, ['stock' => 0]);

        $vieja = $this->corrida([$con_stock]);
        $vieja->update(['status' => DimensionSearchRun::STATUS_TERMINADA, 'finished_at' => Carbon::now()]);
        DimensionSearchItem::where('run_id', $vieja->id)->delete();

        $this->item_en($vieja, $con_stock, 'sin_datos', ['motivo' => 'no_coincide', 'procesado_at' => Carbon::now()->subDays(2)]);
        $this->item_en($vieja, $sin_stock, 'sin_datos', ['motivo' => 'no_coincide', 'procesado_at' => Carbon::now()->subDays(2)]);

        $this->artisan('medidas:buscar', ['--reintentar-sin-datos' => true, '--solo-con-stock' => true])->assertExitCode(0);

        $run = DimensionSearchRun::where('status', DimensionSearchRun::STATUS_PENDIENTE)->first();

        $this->assertSame([$con_stock->id], DimensionSearchItem::where('run_id', $run->id)->pluck('article_id')->all());
    }

    /**
     * Si lo único que había era rechazado o en revisión, con la opción tampoco hay nada que buscar.
     *
     * @group dimension-search
     * @test
     */
    public function con_la_opcion_y_sin_candidatos_el_mensaje_aclara_que_los_rechazados_siguen_afuera()
    {
        $rechazado = $this->nuevo_articulo('RECHAZADO');
        $vieja     = $this->corrida([$rechazado]);
        $vieja->update(['status' => DimensionSearchRun::STATUS_TERMINADA, 'finished_at' => Carbon::now()]);
        DimensionSearchItem::where('run_id', $vieja->id)->delete();
        $this->item_en($vieja, $rechazado, 'rechazada', ['revisado_at' => Carbon::now()->subDays(1), 'procesado_at' => Carbon::now()->subDays(2)]);

        $resultado = DimensionSearchRunHelper::crear_del_catalogo($this->owner, null, false, null, true);

        $this->assertSame(422, $resultado['status']);
        $this->assertStringContainsString('(rechazados o quitados)', $resultado['message']);
    }
}
