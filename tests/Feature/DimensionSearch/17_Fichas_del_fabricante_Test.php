<?php

namespace Tests\Feature\DimensionSearch;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Jobs\ProcessDimensionSearchRunJob;
use App\Models\Article;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use App\Services\DimensionSearch\DimensionLawnBarnIndex;
use App\Services\DimensionSearch\DimensionManufacturerSheets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * «Fichas del fabricante» (misión medidas-masquito-fichas, 14/11/2026): el peso de los artículos Stihl, Husqvarna y
 * Oregon leído de las fichas públicas de maxparts.hu, lawnbarn.com, kalohaz.hu y oregonproducts.com, con la URL armada
 * desde el código del artículo y SIN Serper ni IA. Más los dos ajustes de las corridas normales: el snippet del
 * resultado de búsqueda en el diagnóstico y los valores de plantilla de algunas tiendas, que bajan a «a revisar».
 *
 * Los HTML son recortes chicos de las fichas reales (tests/Feature/DimensionSearch/fixtures/fichas/). Todo pasa por el
 * Http::fake de DimensionSearchTestCase: nada sale a internet.
 */
class Fichas_del_fabricante_Test extends DimensionSearchTestCase
{
    const URL_MAXPARTS = 'https://maxparts.hu/spd/HU10_41470300403/';
    const URL_MAXPARTS_HIP_PAD = 'https://maxparts.hu/spd/HU10_41477402500/';
    const URL_LAWNBARN = 'https://lawnbarn.com/hip-pad-4147-740-2500/';
    const URL_KALOHAZ  = 'https://kalohaz.hu/spd/537271302/';
    const URL_OREGON   = 'https://www.oregonproducts.com/en/x/p/65-006';

    /** @var array Los índices de Lawn Barn que se crearon en el test (se borran al terminar). */
    protected $indices = [];

    /** @var mixed */
    protected $user_id_anterior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id_anterior = config('app.USER_ID');
    }

    protected function tearDown(): void
    {
        foreach ($this->indices as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        config(['app.USER_ID' => $this->user_id_anterior]);

        parent::tearDown();
    }

    /**
     * Un recorte de ficha real.
     *
     * @param  string $archivo
     * @return string
     */
    protected function recorte($archivo)
    {
        return (string) file_get_contents(__DIR__.'/fixtures/fichas/'.$archivo);
    }

    /**
     * Una marca del comercio del test.
     *
     * @param  string $nombre
     * @return int
     */
    protected function marca($nombre)
    {
        return (int) DB::table('brands')->insertGetId([
            'name'       => $nombre,
            'user_id'    => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un artículo de una marca.
     *
     * @param  string      $marca
     * @param  string|null $barras
     * @param  string|null $proveedor
     * @param  array       $extra
     * @return \App\Models\Article
     */
    protected function articulo_de($marca, $barras, $proveedor, array $extra = [])
    {
        return $this->nuevo_articulo('REPUESTO '.$marca.' '.$proveedor, $barras, array_merge([
            'provider_code' => $proveedor,
            'brand_id'      => $this->marca($marca),
        ], $extra));
    }

    /**
     * Una corrida de fichas con esos artículos (sin despachar de verdad el job).
     *
     * @param  array $articulos
     * @return \App\Models\DimensionSearchRun
     */
    protected function corrida_de_fichas(array $articulos)
    {
        Queue::fake();

        $ids = [];

        foreach ($articulos as $articulo) {
            $ids[] = (int) $articulo->id;
        }

        $run = DimensionSearchRunHelper::crear($this->owner, $ids, DimensionSearchRun::ORIGEN_SELECCION, null, [
            'proveedor'            => DimensionSearchRun::PROVEEDOR_FICHAS,
            'sitios_de_referencia' => [],
        ]);

        $this->indices[] = DimensionLawnBarnIndex::ruta($run->id);

        // Un índice viejo de otra corrida con el mismo id (bases de testing re-migradas) no puede contar.
        if (is_file(DimensionLawnBarnIndex::ruta($run->id))) {
            @unlink(DimensionLawnBarnIndex::ruta($run->id));
        }

        return $run;
    }

    /**
     * Corre el paso de fichas sobre un artículo (corrida nueva con solo ese artículo).
     *
     * @param  \App\Models\Article $articulo
     * @return array  [item, resultado]
     */
    protected function buscar_fichas(Article $articulo)
    {
        return $this->procesar($this->corrida_de_fichas([$articulo]), $articulo);
    }

    /**
     * Las páginas del sitemap de Lawn Barn (la 3 no existe: es el fin).
     *
     * @return array
     */
    protected function sitemap()
    {
        return [
            DimensionLawnBarnIndex::URL_SITEMAP.'1' => ['html' => $this->recorte('lawnbarn_sitemap_p1.xml'), 'tipo' => 'application/xml'],
            DimensionLawnBarnIndex::URL_SITEMAP.'2' => ['html' => $this->recorte('lawnbarn_sitemap_p2.xml'), 'tipo' => 'application/xml'],
        ];
    }

    /**
     * Ningún pedido a Serper (búsqueda ni scrape) ni a la IA.
     *
     * @return void
     */
    protected function sin_serper_ni_ia()
    {
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'google.serper.dev') !== false;
        });
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'scrape.serper.dev') !== false;
        });
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'ia.test') !== false;
        });

        $this->assertSame([], $this->consultas_serper);
        $this->assertSame([], $this->pedidos_ia);
    }

    /**
     * La entrada del diagnóstico de un criterio.
     *
     * @param  \App\Models\DimensionSearchItem $item
     * @param  string                          $criterio
     * @return array|null
     */
    protected function entrada(DimensionSearchItem $item, $criterio)
    {
        foreach ((array) $item->diagnostico as $entrada) {
            if (isset($entrada['criterio']) && $entrada['criterio'] === $criterio) {
                return $entrada;
            }
        }

        return null;
    }

    /* ----------------------------------------------------------------------------------------
     * maxparts.hu
     * -------------------------------------------------------------------------------------- */

    /**
     * @group dimension-search
     * @test
     */
    public function maxparts_con_el_ean_igual_asigna_el_peso_neto_y_deja_las_medidas_vacias()
    {
        $articulo = $this->articulo_de('STIHL', '0886661008414', '4147-030-0403');

        $this->falsear([], [self::URL_MAXPARTS => $this->recorte('maxparts_41470300403.html')], 'caida');

        list($item, $resultado) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('peso_de_ficha', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);
        $this->assertLessThanOrEqual(20, strlen($item->criterio_usado));
        $this->assertSame('Peso de la ficha de maxparts.hu, con el código de barras del artículo. Las medidas no se publican ahí.', $item->motivo_detalle);
        $this->assertEquals(0.341, (float) $item->peso_kg);
        $this->assertNull($item->alto_cm);
        $this->assertNull($item->ancho_cm);
        $this->assertNull($item->profundidad_cm);

        // La forma de la evidencia es la del validador.
        $this->assertSame('Nettó tömeg (kg) 0.341', $item->evidencia['peso']['cita']);
        $this->assertSame(self::URL_MAXPARTS, $item->evidencia['peso']['url']);
        $this->assertSame('producto', $item->evidencia['peso']['alcance']);
        $this->assertSame('kg', $item->evidencia['peso']['unidad']);
        $this->assertFalse($item->evidencia['peso']['convertido']);
        $this->assertTrue($item->evidencia['peso']['etiquetada']);
        $this->assertFalse($item->evidencia['peso']['ambiguo']);
        $this->assertFalse($item->evidencia['peso']['rango']);
        $this->assertSame([['url' => self::URL_MAXPARTS, 'titulo' => 'FŐTENGELY STIHL | HU10_41470300403 | MaxParts']], $item->fuentes);

        // Se escribió por DimensionApplier: solo el peso.
        $articulo = $articulo->fresh();
        $this->assertEquals(0.341, (float) $articulo->peso);
        $this->assertNull($articulo->alto);
        $this->assertNull($articulo->ancho);
        $this->assertNull($articulo->profundidad);
        $this->assertSame(['peso'], array_keys($item->aplicado));

        // El diagnóstico: la ficha, su identidad y el bruto aparte.
        $maxparts = $this->entrada($item, DimensionManufacturerSheets::CRITERIO_MAXPARTS);
        $this->assertNotNull($maxparts);
        $this->assertSame(self::URL_MAXPARTS, $maxparts['url']);
        $this->assertTrue($maxparts['encontrada']);
        $this->assertSame('ean', $maxparts['identidad']);
        $this->assertEquals(0.341, $maxparts['valor_kg']);
        $this->assertEquals(0.4026, $maxparts['bruto_kg']);
        $this->assertSame(0, $maxparts['busquedas']);
        $this->assertNull($this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN), 'Con el dato de maxparts no se prueba Lawn Barn.');

        // Gasto cero y sin Serper ni IA.
        $this->assertSame(0, (int) $item->busquedas);
        $this->assertSame(0, (int) $item->validaciones_ia);
        $this->assertSame(0, $resultado['busquedas_intentadas']);
        $this->assertSame(0, (int) $item->run->fresh()->busquedas);
        $this->sin_serper_ni_ia();
    }

    /**
     * @group dimension-search
     * @test
     */
    public function maxparts_con_otro_ean_y_sin_el_codigo_en_el_titulo_no_asigna()
    {
        $articulo = $this->articulo_de('Stihl', $this->con_verificador('779123456789'), '4147-030-0403');

        $html = str_replace('<title>FŐTENGELY STIHL | HU10_41470300403 | MaxParts</title>', '<title>FŐTENGELY STIHL | MaxParts</title>', $this->recorte('maxparts_41470300403.html'));

        $this->falsear([], [self::URL_MAXPARTS => $html], 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_ficha', $item->motivo);
        $this->assertSame('No se encontró la ficha de este artículo en maxparts.hu ni lawnbarn.com.', $item->motivo_detalle);
        $this->assertNull($item->criterio_usado);
        $this->assertNull($articulo->fresh()->peso);

        $maxparts = $this->entrada($item, DimensionManufacturerSheets::CRITERIO_MAXPARTS);
        $this->assertTrue($maxparts['encontrada']);
        $this->assertNull($maxparts['identidad']);
        $this->assertSame('ficha_sin_el_codigo', $maxparts['resultado']);

        // Lawn Barn se probó: el sitemap no bajó y el adaptador quedó apagado para la corrida.
        $lawnbarn = $this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN);
        $this->assertNotNull($lawnbarn);
        $this->assertFalse($lawnbarn['usado']);
        $this->assertStringContainsString('índice de Lawn Barn no se pudo armar', $lawnbarn['motivo']);

        $this->sin_serper_ni_ia();
    }

    /**
     * El título con el código alcanza aunque el EAN de la ficha no sea el del artículo: identidad por código.
     *
     * @group dimension-search
     * @test
     */
    public function maxparts_con_el_codigo_en_el_titulo_asigna_por_codigo_de_proveedor()
    {
        $articulo = $this->articulo_de('STIHL', null, '41470300403');

        $this->falsear([], [self::URL_MAXPARTS => $this->recorte('maxparts_41470300403.html')], 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
        $this->assertSame('codigo', $this->entrada($item, DimensionManufacturerSheets::CRITERIO_MAXPARTS)['identidad']);
        $this->assertStringContainsString('con el código de proveedor del artículo', $item->motivo_detalle);
        $this->assertEquals(0.341, (float) $articulo->fresh()->peso);
    }

    /* ----------------------------------------------------------------------------------------
     * Lawn Barn
     * -------------------------------------------------------------------------------------- */

    /**
     * @group dimension-search
     * @test
     */
    public function lawnbarn_arma_el_indice_desde_el_sitemap_y_con_el_upc_igual_asigna_el_peso_en_kg()
    {
        $articulo = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');

        $this->falsear([], array_merge($this->sitemap(), [
            self::URL_LAWNBARN => $this->recorte('lawnbarn_hip-pad.html'),
        ]), 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('peso_de_ficha', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);
        // 0,60 lb × 0,45359237 = 0,27216 → 0,2722 kg.
        $this->assertEquals(0.2722, (float) $item->peso_kg);
        $this->assertEquals(0.2722, (float) $articulo->fresh()->peso);
        $this->assertSame('Weight: 0.60 LBS', $item->evidencia['peso']['cita']);
        $this->assertSame('lb', $item->evidencia['peso']['unidad_original']);
        $this->assertTrue($item->evidencia['peso']['convertido']);
        $this->assertSame('Puede ser el peso con embalaje.', $item->evidencia['peso']['aviso']);
        $this->assertSame(self::URL_LAWNBARN, $item->fuentes[0]['url']);

        // maxparts no tenía la ficha: quedó en el diagnóstico con el motivo del lector.
        $maxparts = $this->entrada($item, DimensionManufacturerSheets::CRITERIO_MAXPARTS);
        $this->assertSame(self::URL_MAXPARTS_HIP_PAD, $maxparts['url']);
        $this->assertFalse($maxparts['encontrada']);
        $this->assertSame('no_existe', $maxparts['fallo']);

        $lawnbarn = $this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN);
        $this->assertSame('ean', $lawnbarn['identidad']);
        $this->assertEquals(0.2722, $lawnbarn['valor_kg']);

        // El índice quedó guardado con SOLO el código de la corrida (no la URL del otro host).
        $indice = json_decode(file_get_contents(DimensionLawnBarnIndex::ruta($item->run_id)), true);
        $this->assertTrue($indice['ok']);
        $this->assertSame(['41477402500' => self::URL_LAWNBARN], $indice['urls']);

        $this->sin_serper_ni_ia();
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_indice_de_lawnbarn_se_arma_una_sola_vez_por_corrida()
    {
        $primero = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');
        $segundo = $this->articulo_de('STIHL', '795711696924', '4147 740 2500');

        $run = $this->corrida_de_fichas([$primero, $segundo]);

        $this->falsear([], array_merge($this->sitemap(), [
            self::URL_LAWNBARN => $this->recorte('lawnbarn_hip-pad.html'),
        ]), 'caida');

        $this->procesar($run, $primero);

        $this->assertSame(3, $this->requests_a('xmlsitemap.php'), 'Páginas 1, 2 y la 3, que ya no existe.');

        // Otro motor (otro tramo) usa el archivo guardado: no vuelve a bajar el sitemap.
        list($item) = $this->procesar($run, $segundo);

        $this->assertSame(3, $this->requests_a('xmlsitemap.php'));
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function lawnbarn_con_otro_upc_no_asigna()
    {
        $articulo = $this->articulo_de('STIHL', $this->con_verificador('779123456781'), '4147-740-2500');

        $this->falsear([], array_merge($this->sitemap(), [
            self::URL_LAWNBARN => $this->recorte('lawnbarn_hip-pad.html'),
        ]), 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_ficha', $item->motivo);
        $this->assertNull($articulo->fresh()->peso);
        $this->assertNull($this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN)['identidad']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function lawnbarn_con_peso_cero_no_asigna()
    {
        $articulo = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');

        $html = str_replace('0.60 LBS', '0.00 LBS', $this->recorte('lawnbarn_hip-pad.html'));

        $this->falsear([], array_merge($this->sitemap(), [self::URL_LAWNBARN => $html]), 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_ficha', $item->motivo);
        $this->assertNull($articulo->fresh()->peso);

        $lawnbarn = $this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN);
        $this->assertSame('ean', $lawnbarn['identidad']);
        $this->assertNull($lawnbarn['valor_kg']);
        $this->assertSame('sin_medidas', $lawnbarn['resultado']);
    }

    /* ----------------------------------------------------------------------------------------
     * kalohaz.hu y Oregon
     * -------------------------------------------------------------------------------------- */

    /**
     * @group dimension-search
     * @test
     */
    public function kalohaz_lee_el_neto_en_gramos_y_lo_asigna_en_kg()
    {
        $articulo = $this->articulo_de('HUSQVARNA', '7391883143445', '537271302');

        $this->falsear([], [self::URL_KALOHAZ => $this->recorte('kalohaz_537271302.html')], 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
        $this->assertEquals(0.047, (float) $item->peso_kg);
        $this->assertEquals(0.047, (float) $articulo->fresh()->peso);
        $this->assertSame('Nettó tömeg 47 g', $item->evidencia['peso']['cita']);
        $this->assertSame('g', $item->evidencia['peso']['unidad_original']);
        $this->assertSame('codigo', $this->entrada($item, DimensionManufacturerSheets::CRITERIO_KALOHAZ)['identidad']);
        $this->sin_serper_ni_ia();
    }

    /**
     * La página de «no encontrada» de kalohaz responde 200: sin el «Cikkszám» del número no es la ficha.
     *
     * @group dimension-search
     * @test
     */
    public function kalohaz_con_la_pagina_de_no_encontrada_no_asigna()
    {
        $articulo = $this->articulo_de('HUSQVARNA', null, '537271302');

        $this->falsear([], [self::URL_KALOHAZ => $this->recorte('kalohaz_404.html')], 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('No se encontró la ficha de este artículo en kalohaz.hu.', $item->motivo_detalle);
        $this->assertNull($articulo->fresh()->peso);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function oregon_da_el_peso_con_embalaje_y_queda_a_revisar_sin_tocar_el_articulo()
    {
        $articulo = $this->articulo_de('OREGON', null, '55.65-006');

        $this->falsear([], [self::URL_OREGON => $this->recorte('oregon_65-006.html')], 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('peso_de_envio', $item->motivo);
        $this->assertSame('Peso con embalaje de la ficha del fabricante (oregonproducts.com): revisalo antes de aprobarlo.', $item->motivo_detalle);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
        // 0,62 lb = 0,2812 kg.
        $this->assertEquals(0.2812, (float) $item->peso_kg);
        $this->assertSame('envio', $item->evidencia['peso']['alcance']);
        $this->assertSame('Weight (with Packaging) 0.62 Lb', $item->evidencia['peso']['cita']);
        $this->assertNull($item->aplicado);

        $this->assertNull($articulo->fresh()->peso, 'A revisar: no se escribe nada hasta que una persona apruebe.');
        $this->sin_serper_ni_ia();
    }

    /* ----------------------------------------------------------------------------------------
     * Lo que no se busca
     * -------------------------------------------------------------------------------------- */

    /**
     * @group dimension-search
     * @test
     */
    public function un_articulo_con_el_peso_cargado_no_se_pisa_ni_se_pide_nada()
    {
        $articulo = $this->articulo_de('STIHL', '0886661008414', '4147-030-0403', ['peso' => 2.5]);

        $this->falsear([], [self::URL_MAXPARTS => $this->recorte('maxparts_41470300403.html')], 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_ficha', $item->motivo);
        $this->assertEquals(2.5, (float) $articulo->fresh()->peso);
        $this->assertCount(0, Http::recorded());
    }

    /**
     * @group dimension-search
     * @test
     */
    public function una_marca_sin_fichas_queda_sin_ficha_sin_ningun_pedido()
    {
        $echo     = $this->articulo_de('ECHO', '0886661008414', '4147-030-0403');
        $sin_marca = $this->nuevo_articulo('SIN MARCA', '0886661008414', ['provider_code' => '4147-030-0403']);

        $this->falsear([], [self::URL_MAXPARTS => $this->recorte('maxparts_41470300403.html')], 'caida');

        $run = $this->corrida_de_fichas([$echo, $sin_marca]);

        list($item_echo)      = $this->procesar($run, $echo);
        list($item_sin_marca) = $this->procesar($run, $sin_marca);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item_echo->status);
        $this->assertSame('sin_ficha', $item_echo->motivo);
        $this->assertStringContainsString('«ECHO»', $item_echo->motivo_detalle);
        $this->assertSame('sin_ficha', $item_sin_marca->motivo);
        $this->assertStringContainsString('no tiene marca', $item_sin_marca->motivo_detalle);

        Http::assertNothingSent();
        $this->assertNull($echo->fresh()->peso);
    }

    /* ----------------------------------------------------------------------------------------
     * La corrida entera: el job, sin Serper ni IA
     * -------------------------------------------------------------------------------------- */

    /**
     * Sin claves de Serper ni de IA, la corrida de fichas corre igual, no llama a ninguno y no cuenta errores de
     * proveedor.
     *
     * @group dimension-search
     * @test
     */
    public function la_corrida_de_fichas_corre_sin_claves_y_no_llama_nunca_a_serper_ni_a_la_ia()
    {
        config(['services.serper.api_key' => '', 'services.deepseek.api_key' => '', 'services.anthropic.api_key' => '']);

        $stihl     = $this->articulo_de('STIHL', '0886661008414', '4147-030-0403');
        $husqvarna = $this->articulo_de('HUSQVARNA', '7391883143445', '537271302');
        $oregon    = $this->articulo_de('OREGON', null, '55.65-006');
        $nada      = $this->articulo_de('STIHL', null, 'XX');

        $run = $this->corrida_de_fichas([$stihl, $husqvarna, $oregon, $nada]);

        $this->falsear([], [
            self::URL_MAXPARTS => $this->recorte('maxparts_41470300403.html'),
            self::URL_KALOHAZ  => $this->recorte('kalohaz_537271302.html'),
            self::URL_OREGON   => $this->recorte('oregon_65-006.html'),
        ], $this->ficha('exacto'));

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->status, (string) $run->motivo_estado);
        $this->assertSame(4, (int) $run->procesados);
        $this->assertSame(0, (int) $run->busquedas);
        $this->assertSame(0, (int) $run->validaciones_ia);
        $this->assertSame(0, (int) $run->errores_proveedor_seguidos);
        $this->assertSame(0, (int) $run->errores_ia_seguidos);

        $estados = DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->pluck('status')->all();
        $this->assertSame(['asignada', 'asignada', 'a_revisar', 'sin_datos'], $estados);

        foreach (DimensionSearchItem::where('run_id', $run->id)->get() as $item) {
            $this->assertTrue(is_null($item->criterio_usado) || in_array($item->criterio_usado, ['codigo_de_barras', 'codigo_proveedor', 'nombre'], true));
            $this->assertLessThanOrEqual(20, strlen((string) $item->criterio_usado));
            $this->assertLessThanOrEqual(40, strlen((string) $item->motivo));
        }

        $this->sin_serper_ni_ia();
    }

    /* ----------------------------------------------------------------------------------------
     * El comando
     * -------------------------------------------------------------------------------------- */

    /**
     * Los artículos del comando: uno que entra limpio, uno con una propuesta a revisar (entra), uno rechazado (no),
     * uno quitado (no), uno con peso (no), uno de otra marca (no).
     *
     * @return array
     */
    protected function universo_del_comando()
    {
        $limpio    = $this->articulo_de('STIHL', null, '4147-030-0403');
        $a_revisar = $this->articulo_de('Husqvarna', null, '537271302');
        $rechazado = $this->articulo_de('OREGON', null, '55.65-006');
        $quitado   = $this->articulo_de('STIHL', null, '4147-740-2500');
        $con_peso  = $this->articulo_de('STIHL', null, '1111-222-3333', ['peso' => 1.5]);
        $otra      = $this->articulo_de('ECHO', null, '1234-567-8901');

        $vieja = $this->corrida([$a_revisar, $rechazado, $quitado]);
        DimensionSearchRunHelper::terminar($vieja, DimensionSearchRun::STATUS_TERMINADA);

        DimensionSearchItem::where('run_id', $vieja->id)->where('article_id', $a_revisar->id)->update(['status' => DimensionSearchItem::STATUS_A_REVISAR, 'motivo' => 'medidas_parciales']);
        DimensionSearchItem::where('run_id', $vieja->id)->where('article_id', $rechazado->id)->update(['status' => DimensionSearchItem::STATUS_RECHAZADA, 'motivo' => 'rechazada', 'revisado_at' => now()]);
        DimensionSearchItem::where('run_id', $vieja->id)->where('article_id', $quitado->id)->update(['status' => DimensionSearchItem::STATUS_QUITADA, 'motivo' => 'quitada', 'revisado_at' => now()]);

        return compact('limpio', 'a_revisar', 'rechazado', 'quitado', 'con_peso', 'otra');
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_dry_run_de_fichas_cuenta_por_marca_y_no_crea_nada()
    {
        config(['app.USER_ID' => $this->owner->id, 'services.serper.api_key' => '']);
        $this->universo_del_comando();
        $corridas = DimensionSearchRun::count();

        Queue::fake();

        $this->artisan('medidas:buscar', ['--fichas' => true, '--dry-run' => true])
            ->expectsOutput('  Stihl:      1')
            ->expectsOutput('  Husqvarna:  1')
            ->expectsOutput('  Oregon:     0')
            ->expectsOutput('A buscar en esta corrida:                   2')
            ->expectsOutput('Consultas a Serper: 0 · llamadas a la IA: 0 (las fichas se leen directo).')
            ->expectsOutput('Es un --dry-run: no se creó ni se despachó nada.')
            ->assertExitCode(0);

        $this->assertSame($corridas, DimensionSearchRun::count());
        Queue::assertNothingPushed();
    }

    /**
     * @group dimension-search
     * @test
     */
    public function fichas_crea_la_corrida_por_seleccion_con_proveedor_fichas_y_sin_clave_de_serper()
    {
        config(['app.USER_ID' => $this->owner->id, 'services.serper.api_key' => '', 'services.deepseek.api_key' => '']);
        $universo = $this->universo_del_comando();

        Queue::fake();

        $this->artisan('medidas:buscar', ['--fichas' => true])->assertExitCode(0);

        $run = DimensionSearchRun::where('proveedor', DimensionSearchRun::PROVEEDOR_FICHAS)->first();

        $this->assertNotNull($run);
        $this->indices[] = DimensionLawnBarnIndex::ruta($run->id);
        $this->assertSame(DimensionSearchRun::ORIGEN_SELECCION, $run->origen);
        $this->assertNull($run->sitios_de_referencia);
        $this->assertNull($run->creada_por);

        // Entran el limpio y el que esperaba revisión; no el rechazado, el quitado, el que tiene peso ni el de otra marca.
        $this->assertSame(
            [$universo['limpio']->id, $universo['a_revisar']->id],
            DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->pluck('article_id')->map(function ($id) {
                return (int) $id;
            })->all()
        );

        Queue::assertPushed(ProcessDimensionSearchRunJob::class, 1);

        // Con los artículos ya en una corrida en curso, una segunda no tiene nada que buscar.
        $this->artisan('medidas:buscar', ['--fichas' => true])->assertExitCode(1);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function fichas_con_sitios_o_con_reintentar_sin_datos_es_un_error()
    {
        config(['app.USER_ID' => $this->owner->id]);
        Queue::fake();

        $this->artisan('medidas:buscar', ['--fichas' => true, '--sitios' => 'obi.de'])
            ->expectsOutput('--fichas no usa sitios de referencia: sacá --sitios.')
            ->assertExitCode(1);

        $this->artisan('medidas:buscar', ['--fichas' => true, '--reintentar-sin-datos' => true])
            ->expectsOutput('--fichas ya incluye los que quedaron sin datos: sacá --reintentar-sin-datos.')
            ->assertExitCode(1);

        $this->assertSame(0, DimensionSearchRun::count());
    }

    /**
     * @group dimension-search
     * @test
     */
    public function ids_para_fichas_respeta_solo_con_stock_y_el_limite()
    {
        $con_stock = $this->articulo_de('STIHL', null, '4147-030-0403', ['stock' => 3]);
        $this->articulo_de('STIHL', null, '4147-740-2500', ['stock' => 0]);
        $otro      = $this->articulo_de('OREGON', null, '55.65-006', ['stock' => 5]);

        $this->assertSame([$con_stock->id, $otro->id], DimensionSearchRunHelper::ids_para_fichas($this->owner->id, true));
        $this->assertSame([$con_stock->id], DimensionSearchRunHelper::ids_para_fichas($this->owner->id, true, 1));
        $this->assertCount(3, DimensionSearchRunHelper::ids_para_fichas($this->owner->id, false));
    }

    /* ----------------------------------------------------------------------------------------
     * Corridas normales: el snippet y los valores de plantilla
     * -------------------------------------------------------------------------------------- */

    /**
     * @group dimension-search
     * @test
     */
    public function en_una_corrida_normal_el_snippet_del_resultado_queda_en_el_diagnostico()
    {
        $gtin     = $this->con_verificador('779123456789');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20" AC 21MM', $gtin);
        $url      = 'https://tienda.test/cuchilla';

        $snippet = str_repeat('Cuchilla rotary con peso 820 g y medidas 70 x 11 x 100 mm. ', 10);

        $this->falsear(
            ['"'.$gtin.'"' => [['title' => 'Cuchilla', 'link' => $url, 'snippet' => $snippet, 'position' => 1]]],
            [$url => $this->pagina('Cuchilla', $gtin.' Peso: 820 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.')],
            $this->ficha('exacto', [
                'peso'  => $this->dato(820, 'g', 'Peso: 820 g', $url),
                'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
                'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
                'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
            ])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);

        $pagina = $item->diagnostico[0]['paginas'][0];
        $this->assertSame($url, $pagina['url']);
        $this->assertSame(mb_substr(trim($snippet), 0, 300), $pagina['snippet']);
        $this->assertSame(300, mb_strlen($pagina['snippet']));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function una_asignada_con_el_valor_de_plantilla_de_newgardenstore_baja_a_revisar()
    {
        $gtin     = $this->con_verificador('779123456781');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20" AC 21MM', $gtin);
        $url      = 'https://www.newgardenstore.eu/cuchilla-rotary';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url, 'Cuchilla')]],
            [$url => $this->pagina('Cuchilla', $gtin.' Peso: 1 kg. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.')],
            $this->ficha('exacto', [
                'peso'  => $this->dato(1, 'kg', 'Peso: 1 kg', $url),
                'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
                'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
                'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
            ])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('valor_de_plantilla', $item->motivo);
        $this->assertStringContainsString('newgardenstore.eu', $item->motivo_detalle);
        $this->assertEquals(1, (float) $item->peso_kg);
        $this->assertEquals(7, (float) $item->ancho_cm, 'La propuesta se conserva entera para revisarla.');
        $this->assertNull($item->aplicado);

        $articulo = $articulo->fresh();
        $this->assertNull($articulo->peso);
        $this->assertNull($articulo->ancho);
    }

    /**
     * El mismo dominio con otro peso sigue asignándose solo.
     *
     * @group dimension-search
     * @test
     */
    public function newgardenstore_con_otro_peso_se_asigna_como_siempre()
    {
        $gtin     = $this->con_verificador('779123456782');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20" AC 21MM', $gtin);
        $url      = 'https://www.newgardenstore.eu/cuchilla-rotary';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url, 'Cuchilla')]],
            [$url => $this->pagina('Cuchilla', $gtin.' Peso: 820 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.')],
            $this->ficha('exacto', [
                'peso'  => $this->dato(820, 'g', 'Peso: 820 g', $url),
                'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
                'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
                'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
            ])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('codigo_exacto', $item->motivo);
        $this->assertEquals(0.82, (float) $articulo->fresh()->peso);
    }

    /* ----------------------------------------------------------------------------------------
     * Piezas sueltas
     * -------------------------------------------------------------------------------------- */

    /**
     * @group dimension-search
     * @test
     */
    public function los_compactos_del_slug_y_la_parte_de_oregon()
    {
        $this->assertContains('41477402500', DimensionLawnBarnIndex::compactos_del_slug(self::URL_LAWNBARN));
        $this->assertContains('63740113451', DimensionLawnBarnIndex::compactos_del_slug('https://lawnbarn.com/rm-655-1-vs-usa-lawn-mower-6374-011-3451-us/'));
        $this->assertContains('00008853841', DimensionLawnBarnIndex::compactos_del_slug('https://lawnbarn.com/stihl-promark-advance-gtx-boots-size-8-8-5-0000-885-3841/'));

        $this->assertSame('75-299', DimensionManufacturerSheets::parte_de_oregon('55.75-299'));
        $this->assertSame('65-006', DimensionManufacturerSheets::parte_de_oregon(' 65-006 '));
        $this->assertNull(DimensionManufacturerSheets::parte_de_oregon('CADENA'));

        $this->assertSame('STIHL', DimensionManufacturerSheets::marca_normalizada(' stihl '));
        $this->assertSame('HUSQVARNA', DimensionManufacturerSheets::marca_normalizada('Husqvárna'));

        $this->assertTrue(DimensionManufacturerSheets::mismo_codigo_de_barras('886661008414', '0886661008414'));
        $this->assertFalse(DimensionManufacturerSheets::mismo_codigo_de_barras('886661008414', $this->con_verificador('779123456789')));
    }

    /* ----------------------------------------------------------------------------------------
     * Correcciones de las revisiones independientes (14/11/2026)
     * -------------------------------------------------------------------------------------- */

    /**
     * 1. Un EAN que contradice al artículo frena la asignación aunque el título traiga el código.
     *
     * @group dimension-search
     * @test
     */
    public function maxparts_con_otro_ean_frena_aunque_el_titulo_traiga_el_codigo()
    {
        $articulo = $this->articulo_de('STIHL', $this->con_verificador('779123456789'), '4147-030-0403');

        $this->falsear([], [self::URL_MAXPARTS => $this->recorte('maxparts_41470300403.html')], 'caida');

        list($item, $resultado) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_ficha', $item->motivo);
        $this->assertNull($articulo->fresh()->peso);
        $this->assertFalse($resultado['error_de_proveedor']);

        $maxparts = $this->entrada($item, DimensionManufacturerSheets::CRITERIO_MAXPARTS);
        $this->assertNull($maxparts['identidad']);
        $this->assertTrue($maxparts['ean_distinto']);
        $this->assertSame('ficha_sin_el_codigo', $maxparts['resultado']);
        $this->assertStringContainsString('otro EAN', $maxparts['resumen']);

        // Se pasó a la fuente siguiente.
        $this->assertNotNull($this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN));
    }

    /**
     * 1. En kalohaz igual: con un GTIN válido que no es el «EAN / GTIN» de la ficha, el Cikkszám no alcanza.
     *
     * @group dimension-search
     * @test
     */
    public function kalohaz_con_otro_ean_no_asigna_aunque_el_cikkszam_coincida()
    {
        $articulo = $this->articulo_de('HUSQVARNA', $this->con_verificador('779123456781'), '537271302');

        $this->falsear([], [self::URL_KALOHAZ => $this->recorte('kalohaz_537271302.html')], 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertNull($articulo->fresh()->peso);
        $this->assertTrue($this->entrada($item, DimensionManufacturerSheets::CRITERIO_KALOHAZ)['ean_distinto']);
    }

    /**
     * 2. Bloqueo y timeout en todas las fuentes: es un error del proveedor, no «no hay ficha».
     *
     * @group dimension-search
     * @test
     */
    public function maxparts_bloqueado_y_lawnbarn_sin_respuesta_queda_sin_procesar_como_error_de_busqueda()
    {
        $articulo = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');

        $this->falsear([], array_merge($this->sitemap(), [
            self::URL_MAXPARTS_HIP_PAD => ['status' => 429],
            self::URL_LAWNBARN         => ['excepcion' => true],
        ]), 'caida');

        list($item, $resultado) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_PROCESAR, $item->status);
        $this->assertSame('error_de_busqueda', $item->motivo);
        $this->assertStringContainsString('Se puede volver a intentar', $item->motivo_detalle);
        $this->assertTrue($resultado['error_de_proveedor']);
        $this->assertSame(2, $resultado['fichas_intentadas']);
        $this->assertSame(0, $resultado['busquedas_intentadas'], 'No es una búsqueda de Serper.');
        $this->assertNull($articulo->fresh()->peso);

        $this->assertSame('bloqueada', $this->entrada($item, DimensionManufacturerSheets::CRITERIO_MAXPARTS)['fallo']);
        $this->assertSame('sin_respuesta', $this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN)['fallo']);
        $this->sin_serper_ni_ia();
    }

    /**
     * 2. Con una fuente que respondió (un 404, un índice sin el código), es «sin ficha» como siempre.
     *
     * @group dimension-search
     * @test
     */
    public function maxparts_404_y_lawnbarn_sin_el_codigo_es_sin_ficha()
    {
        $articulo = $this->articulo_de('STIHL', '0886661008414', '4147-030-0403');

        $this->falsear([], $this->sitemap(), 'caida');

        list($item, $resultado) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_ficha', $item->motivo);
        $this->assertFalse($resultado['error_de_proveedor']);
        $this->assertSame('no_existe', $this->entrada($item, DimensionManufacturerSheets::CRITERIO_MAXPARTS)['fallo']);
        $this->assertTrue($this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN)['consultada']);
    }

    /**
     * 2. Varios artículos seguidos con las fichas bloqueadas: la corrida se corta con el mecanismo de siempre y
     * reanudar() los vuelve a la fila.
     *
     * @group dimension-search
     * @test
     */
    public function varios_bloqueados_seguidos_cortan_la_corrida_y_reanudar_los_reintenta()
    {
        $articulos = [];
        $paginas   = [];

        for ($i = 0; $i < 6; $i++) {
            $codigo      = '4147-030-04'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $articulos[] = $this->articulo_de('STIHL', null, $codigo);
            $paginas['https://maxparts.hu/spd/HU10_'.DimensionLawnBarnIndex::compacto($codigo).'/'] = ['status' => 429];
        }

        $run = $this->corrida_de_fichas($articulos);

        $this->falsear([], $paginas, 'caida');

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(DimensionSearchRun::STATUS_FALLIDA, $run->status);
        $this->assertStringContainsString('falló en '.ProcessDimensionSearchRunJob::MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR.' artículos seguidos', $run->motivo_estado);
        $this->assertStringContainsString('maxparts.hu', $run->motivo_estado);
        $this->assertSame(5, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_SIN_PROCESAR)->where('motivo', 'error_de_busqueda')->count());
        $this->assertSame(1, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_PENDIENTE)->count());

        $this->assertSame(200, DimensionSearchRunHelper::reanudar($run)['status']);
        $this->assertSame(6, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_PENDIENTE)->count());
    }

    /**
     * 3. Una página posterior a la 1 que falla (no un 404) deja el índice incompleto; el tramo siguiente lo reintenta
     * una sola vez.
     *
     * @group dimension-search
     * @test
     */
    public function el_indice_truncado_queda_incompleto_y_se_reintenta_una_sola_vez()
    {
        $articulos = [];

        for ($i = 0; $i < 3; $i++) {
            $articulos[] = $this->articulo_de('STIHL', '0886661008414', '4147-030-04'.$i.'3');
        }

        $run = $this->corrida_de_fichas($articulos);

        $this->falsear([], [
            DimensionLawnBarnIndex::URL_SITEMAP.'1' => ['html' => $this->recorte('lawnbarn_sitemap_p1.xml'), 'tipo' => 'application/xml'],
            DimensionLawnBarnIndex::URL_SITEMAP.'2' => ['status' => 503],
        ], 'caida');

        list($primero) = $this->procesar($run, $articulos[0]);

        $this->assertSame(2, $this->requests_a('xmlsitemap.php'));

        $indice = json_decode(file_get_contents(DimensionLawnBarnIndex::ruta($run->id)), true);
        $this->assertTrue($indice['ok']);
        $this->assertTrue($indice['incompleto']);
        $this->assertSame(1, $indice['armados']);
        $this->assertStringContainsString('el índice quedó incompleto', $this->entrada($primero, DimensionManufacturerSheets::CRITERIO_LAWNBARN)['motivo']);

        // Otro tramo (otro motor): lo vuelve a armar una vez.
        $this->procesar($run, $articulos[1]);
        $this->assertSame(4, $this->requests_a('xmlsitemap.php'));
        $this->assertSame(2, json_decode(file_get_contents(DimensionLawnBarnIndex::ruta($run->id)), true)['armados']);

        // Y no más.
        $this->procesar($run, $articulos[2]);
        $this->assertSame(4, $this->requests_a('xmlsitemap.php'));
    }

    /**
     * 3. Página 1 con 200 pero sin ningún <loc>: el índice queda apagado, con el error, y no se reintenta.
     *
     * @group dimension-search
     * @test
     */
    public function la_primera_pagina_sin_loc_apaga_el_indice_sin_reintentar()
    {
        $primero = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');
        $segundo = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');

        $run = $this->corrida_de_fichas([$primero, $segundo]);

        $this->falsear([], [
            DimensionLawnBarnIndex::URL_SITEMAP.'1' => ['html' => '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>', 'tipo' => 'application/xml'],
        ], 'caida');

        list($item) = $this->procesar($run, $primero);

        $indice = json_decode(file_get_contents(DimensionLawnBarnIndex::ruta($run->id)), true);
        $this->assertFalse($indice['ok']);
        $this->assertSame('El índice de Lawn Barn no trae productos.', $indice['error']);
        $this->assertNull($indice['fallo']);
        $this->assertStringContainsString('no trae productos', $this->entrada($item, DimensionManufacturerSheets::CRITERIO_LAWNBARN)['motivo']);

        $this->procesar($run, $segundo);
        $this->assertSame(1, $this->requests_a('xmlsitemap.php'));
    }

    /**
     * 4. Después de armar el índice, el lector arranca su presupuesto de cero antes de bajar la ficha de Lawn Barn.
     *
     * @group dimension-search
     * @test
     */
    public function despues_de_armar_el_indice_el_lector_reinicia_el_presupuesto_antes_de_la_ficha()
    {
        LectorQueAnota::$eventos = [];
        app()->bind(\App\Services\DimensionSearch\DimensionPageReader::class, LectorQueAnota::class);

        $articulo = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');

        $this->falsear([], array_merge($this->sitemap(), [self::URL_LAWNBARN => $this->recorte('lawnbarn_hip-pad.html')]), 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);

        $eventos = LectorQueAnota::$eventos;
        $maxparts = array_search('leer '.self::URL_MAXPARTS_HIP_PAD, $eventos, true);
        $lawnbarn = array_search('leer '.self::URL_LAWNBARN, $eventos, true);

        $this->assertNotFalse($maxparts);
        $this->assertNotFalse($lawnbarn);
        $this->assertSame('reinicio', $eventos[$lawnbarn - 1], 'El presupuesto se reinicia justo antes de leer la ficha de Lawn Barn.');
        $this->assertGreaterThan($maxparts, $lawnbarn - 1);
    }

    /**
     * 5. La selección de fichas, como el catálogo, solo toma artículos activos.
     *
     * @group dimension-search
     * @test
     */
    public function ids_para_fichas_deja_afuera_los_inactivos()
    {
        $activo   = $this->articulo_de('STIHL', null, '4147-030-0403');
        $this->articulo_de('STIHL', null, '4147-740-2500', ['status' => 'inactive']);

        $this->assertSame([$activo->id], DimensionSearchRunHelper::ids_para_fichas($this->owner->id));
    }

    /**
     * 6. Con el peso ya cargado, el valor de plantilla no baja la asignada (ese peso no se iba a escribir).
     *
     * @group dimension-search
     * @test
     */
    public function el_valor_de_plantilla_no_frena_si_el_articulo_ya_tiene_peso()
    {
        $gtin     = $this->con_verificador('779123456783');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY 20" AC 21MM', $gtin, ['peso' => 1]);
        $url      = 'https://www.newgardenstore.eu/cuchilla-rotary';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url, 'Cuchilla')]],
            [$url => $this->pagina('Cuchilla', $gtin.' Peso: 1 kg. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.')],
            $this->ficha('exacto', [
                'peso'  => $this->dato(1, 'kg', 'Peso: 1 kg', $url),
                'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
                'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
                'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
            ])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('codigo_exacto', $item->motivo);

        $articulo = $articulo->fresh();
        $this->assertEquals(1, (float) $articulo->peso);
        $this->assertEquals(7, (float) $articulo->ancho);
    }

    /**
     * 7. kalohaz: el número sale primero del código de proveedor; si sale del de barras, el criterio es el de barras.
     *
     * @group dimension-search
     * @test
     */
    public function kalohaz_dice_de_que_codigo_salio_el_numero()
    {
        $por_barras = $this->articulo_de('HUSQVARNA', '537271302', null);

        $this->falsear([], [self::URL_KALOHAZ => $this->recorte('kalohaz_537271302.html')], 'caida');

        list($item) = $this->buscar_fichas($por_barras);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);
        $this->assertSame('Peso de la ficha de kalohaz.hu, con el código de barras del artículo. Las medidas no se publican ahí.', $item->motivo_detalle);

        // Con los dos: manda el de proveedor (la URL es la suya, no la del de barras).
        $los_dos = $this->articulo_de('HUSQVARNA', '537271399', '537271302');

        list($item) = $this->buscar_fichas($los_dos);

        $this->assertSame(self::URL_KALOHAZ, $this->entrada($item, DimensionManufacturerSheets::CRITERIO_KALOHAZ)['url']);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
        $this->assertStringContainsString('con el código de proveedor del artículo', $item->motivo_detalle);
    }

    /**
     * 8. En una demo sin búsquedas, una corrida de fichas se puede reanudar (no usa Serper); una normal no.
     *
     * @group dimension-search
     * @test
     */
    public function reanudar_una_corrida_de_fichas_no_mira_el_tope_de_la_demo()
    {
        $anterior = [config('app.FOR_USER'), config('app.USER_ID')];
        config(['app.FOR_USER' => 'demo', 'app.USER_ID' => $this->owner->id]);

        try {
            $fichas  = $this->corrida_de_fichas([$this->articulo_de('STIHL', null, '4147-030-0403')]);
            $normal  = $this->corrida([$this->nuevo_articulo('CUCHILLA ROTARY 20"')]);

            DimensionSearchRunHelper::terminar($fichas, DimensionSearchRun::STATUS_DETENIDA);
            DimensionSearchRunHelper::terminar($normal, DimensionSearchRun::STATUS_DETENIDA);

            $ahora = now();
            $filas = [];

            for ($i = 0; $i < 25; $i++) {
                $filas[] = [
                    'event_uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->owner->id, 'process' => 'imagenes_seleccion',
                    'endpoint' => 'images', 'query' => 'previa '.$i, 'ok' => true, 'occurred_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }

            \App\Models\SerperUsageEvent::insert($filas);

            $this->assertSame(422, DimensionSearchRunHelper::reanudar($normal->fresh())['status'], 'Control: la normal sí la frena el tope.');
            $this->assertSame(200, DimensionSearchRunHelper::reanudar($fichas->fresh())['status']);
            $this->assertSame(DimensionSearchRun::STATUS_PENDIENTE, $fichas->fresh()->status);
        } finally {
            config(['app.FOR_USER' => $anterior[0], 'app.USER_ID' => $anterior[1]]);
        }
    }

    /**
     * 9. El índice de Lawn Barn se borra al terminar la corrida y al purgarla.
     *
     * @group dimension-search
     * @test
     */
    public function el_indice_se_borra_al_terminar_y_al_purgar_la_corrida()
    {
        $articulo = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');
        $run      = $this->corrida_de_fichas([$articulo]);

        $this->falsear([], array_merge($this->sitemap(), [self::URL_LAWNBARN => $this->recorte('lawnbarn_hip-pad.html')]), 'caida');

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->fresh()->status);
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, DimensionSearchItem::where('run_id', $run->id)->value('status'));
        $this->assertFileDoesNotExist(DimensionLawnBarnIndex::ruta($run->id));

        // Una corrida vieja con un índice que quedó (de antes de esta regla): la purga se lo lleva.
        $vieja = $this->corrida_de_fichas([$this->articulo_de('STIHL', null, '4147-030-0403')]);
        DimensionSearchRunHelper::terminar($vieja, DimensionSearchRun::STATUS_TERMINADA);
        DB::table('dimension_search_runs')->where('id', $vieja->id)->update(['finished_at' => now()->subDays(DimensionSearchRunHelper::DIAS_PARA_PURGAR + 1)]);

        @mkdir(dirname(DimensionLawnBarnIndex::ruta($vieja->id)), 0775, true);
        file_put_contents(DimensionLawnBarnIndex::ruta($vieja->id), '{"ok":true,"urls":{}}');

        $this->assertGreaterThanOrEqual(1, DimensionSearchRunHelper::purgar_viejas($this->owner->id));
        $this->assertFileDoesNotExist(DimensionLawnBarnIndex::ruta($vieja->id));
        $this->assertNull(DimensionSearchRun::find($vieja->id));
    }

    /**
     * 10. Sin la etiqueta «Weight:», la cita de Lawn Barn es el atributo literal del HTML.
     *
     * @group dimension-search
     * @test
     */
    public function lawnbarn_sin_la_etiqueta_cita_el_atributo_literal()
    {
        $articulo = $this->articulo_de('STIHL', '795711696924', '4147-740-2500');

        $html = str_replace('<dt class="productView-info-name">Weight:</dt>', '', $this->recorte('lawnbarn_hip-pad.html'));

        $this->falsear([], array_merge($this->sitemap(), [self::URL_LAWNBARN => $html]), 'caida');

        list($item) = $this->buscar_fichas($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('data-product-weight>0.60 LBS', $item->evidencia['peso']['cita']);
        $this->assertStringContainsString($item->evidencia['peso']['cita'], $html, 'La cita está tal cual en la página.');
    }
}

/**
 * El lector de los tests (DNS de prueba) que anota en orden cuándo reinicia el presupuesto y qué lee.
 */
class LectorQueAnota extends \Tests\Feature\DimensionSearch\Dobles\PaginasConDnsDePrueba
{
    /** @var array */
    public static $eventos = [];

    public function reiniciar_presupuesto($segundos = null)
    {
        self::$eventos[] = 'reinicio';

        return parent::reiniciar_presupuesto($segundos);
    }

    public function leer($url)
    {
        self::$eventos[] = 'leer '.$url;

        return parent::leer($url);
    }
}
