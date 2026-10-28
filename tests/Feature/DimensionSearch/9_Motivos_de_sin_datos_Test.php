<?php

namespace Tests\Feature\DimensionSearch;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Models\DimensionSearchItem;

/**
 * Los rótulos de «sin datos» dicen lo que pasó de verdad (misión medidas-sin-datos, 28/10/2026): el motivo se
 * calcula del diagnóstico. `sin_medidas_en_la_pagina` ya no es el cajón de todo: queda solo para «se leyeron
 * páginas del mismo producto y de verdad no traían medidas».
 */
class Motivos_de_sin_datos_Test extends DimensionSearchTestCase
{
    const URL_A = 'https://catalogo.test/cuchilla-rotary';
    const URL_B = 'https://tienda.test/producto-b';
    const URL_C = 'https://otra.test/producto-c';

    /** @var string */
    protected $gtin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gtin = $this->con_verificador('779123456841');
    }

    /**
     * @param  string $url
     * @return array
     */
    protected function resultado_con_codigo($url)
    {
        return $this->resultado($url, 'Cuchilla rotary '.$this->gtin);
    }

    /**
     * El producto apareció pero la página bloquea la descarga y el respaldo tampoco la lee.
     *
     * @group dimension-search
     * @test
     */
    public function paginas_bloqueadas_cuando_el_producto_aparecio_pero_no_se_pudo_leer_ni_con_el_respaldo()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A), $this->resultado_con_codigo(self::URL_B)]],
            [self::URL_A => ['status' => 403], self::URL_B => ['status' => 403]],
            $this->ficha('otro_producto'),
            [] // El respaldo tampoco las baja.
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('paginas_bloqueadas', $item->motivo);
        $this->assertStringContainsString('El producto apareció pero 2 páginas no se pudieron leer', $item->motivo_detalle);
        $this->assertStringContainsString('ni con el respaldo', $item->motivo_detalle);
        $this->assertSame('No se pudo leer ni con el respaldo.', $item->diagnostico[0]['paginas'][0]['motivo']);
        $this->assertCount(0, $this->pedidos_ia);
    }

    /**
     * Los PDF que no se pudieron leer.
     *
     * @group dimension-search
     * @test
     */
    public function pdf_no_leido_cuando_las_paginas_que_aparecieron_eran_pdf_que_no_se_pudieron_leer()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);
        $pdf      = 'https://catalogo.test/ficha.pdf';

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo($pdf)]],
            [],
            $this->ficha('otro_producto'),
            []
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('pdf_no_leido', $item->motivo);
        $this->assertStringContainsString('eran PDF que no se pudieron leer', $item->motivo_detalle);
    }

    /**
     * Un PDF cualquiera (sin el código en el título) que nadie pidió leer no hace decir «PDF no leído».
     *
     * @group dimension-search
     * @test
     */
    public function un_pdf_que_no_tenia_el_codigo_en_el_resultado_no_cambia_el_motivo()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado('https://catalogo.test/otra-ficha.pdf', 'Catálogo general')]],
            [],
            $this->ficha('otro_producto'),
            []
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame('no_coincide', $item->motivo);
        $this->assertSame([], $this->scrapes_pedidos);
    }

    /**
     * `sin_medidas_en_la_pagina` queda solo para lo verdadero, y si además hubo páginas bloqueadas lo dice.
     *
     * @group dimension-search
     * @test
     */
    public function sin_medidas_en_la_pagina_es_solo_para_cuando_se_leyo_el_producto_y_no_traia_medidas()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A), $this->resultado_con_codigo(self::URL_B)]],
            [
                self::URL_A => $this->pagina('Cuchilla', 'Código '.$this->gtin.'. Cuchilla rotativa para bordeadora.'),
                self::URL_B => ['status' => 403],
            ],
            $this->ficha('exacto'),
            []
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertSame('sin_medidas_en_la_pagina', $item->motivo);
        $this->assertStringContainsString('la página que se leyó no trae peso ni medidas', $item->motivo_detalle);
        $this->assertStringContainsString('Además, 1 página no se pudo leer', $item->motivo_detalle);
    }

    /**
     * `no_coincide`, `sin_resultados` y la guarda SSRF siguen como siempre.
     *
     * @group dimension-search
     * @test
     */
    public function no_coincide_y_sin_resultados_siguen_y_mencionan_cuantas_paginas_se_leyeron()
    {
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $this->gtin);

        $this->falsear(
            ['"'.$this->gtin.'"' => [$this->resultado_con_codigo(self::URL_A)]],
            [self::URL_A => $this->pagina('Cuchilla', 'Código '.$this->gtin.'. Es otra cosa.')],
            $this->ficha('otro_producto')
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame('no_coincide', $item->motivo);
        $this->assertStringContainsString('Se revisaron 1 página', $item->motivo_detalle);

        // Sin resultados.
        $otro = $this->nuevo_articulo('OTRA CUCHILLA ROTARY', $this->con_verificador('779123456858'));
        $this->falsear([], [], $this->ficha('otro_producto'));

        list($item2) = $this->buscar($otro);

        $this->assertSame('sin_resultados', $item2->motivo);

        // Un destino interno (la guarda SSRF) no es un «bloqueo»: no se scrapea ni se rotula así.
        $tercero = $this->nuevo_articulo('TERCERA CUCHILLA ROTARY', $this->con_verificador('779123456865'));
        $interna = 'https://interno.test/admin';

        $this->falsear(
            ['"'.$this->con_verificador('779123456865').'"' => [$this->resultado($interna, 'Cuchilla '.$this->con_verificador('779123456865'))]],
            [$interna => $this->pagina('Interna', 'x')],
            $this->ficha('otro_producto'),
            [$interna => 'texto']
        );

        list($item3) = $this->buscar($tercero);

        $this->assertSame('no_coincide', $item3->motivo);
        $this->assertSame([], $this->scrapes_pedidos);
    }

    /**
     * Los dos motivos nuevos cuentan como «ya buscado sin éxito» (no se repiten solos en la próxima corrida), y los
     * ítems viejos con motivos viejos siguen igual.
     *
     * @group dimension-search
     * @test
     */
    public function los_motivos_nuevos_cuentan_como_ya_buscado_sin_exito()
    {
        $this->assertContains('paginas_bloqueadas', DimensionSearchRunHelper::MOTIVOS_YA_BUSCADO_SIN_EXITO);
        $this->assertContains('pdf_no_leido', DimensionSearchRunHelper::MOTIVOS_YA_BUSCADO_SIN_EXITO);
        $this->assertContains('sin_medidas_en_la_pagina', DimensionSearchRunHelper::MOTIVOS_YA_BUSCADO_SIN_EXITO);
    }

    /**
     * La columna `motivo` aguanta los motivos nuevos (varchar 40).
     *
     * @group dimension-search
     * @test
     */
    public function la_columna_motivo_aguanta_los_motivos_nuevos()
    {
        foreach (['valor_sin_respaldo', 'paginas_bloqueadas', 'pdf_no_leido'] as $motivo) {
            $this->assertLessThanOrEqual(40, strlen($motivo));
        }

        $articulo = $this->nuevo_articulo('UNO');
        $run      = $this->corrida([$articulo]);
        DimensionSearchItem::where('run_id', $run->id)->delete();

        $item = $this->item_en($run, $articulo, 'sin_datos', ['motivo' => 'paginas_bloqueadas']);

        $this->assertSame('paginas_bloqueadas', $item->fresh()->motivo);
    }
}
