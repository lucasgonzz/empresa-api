<?php

namespace Tests\Feature\DimensionSearch;

use App\Models\DimensionSearchItem;

/**
 * El motor con los datos estructurados (JSON-LD) de las páginas (misión medidas-sitios-de-referencia, 11/11/2026):
 * lo que la IA no pudo citar se completa con el JSON-LD del producto, solo de una página con el código del artículo y
 * solo si la IA no dijo `otro_producto`. Sin la IA, nunca se asigna solo.
 *
 * La página es la ficha real de OBI recortada (Datos_estructurados_Test::pagina_de_obi()).
 */
class Motor_con_datos_estructurados_Test extends DimensionSearchTestCase
{
    const GTIN = '4078500001694';
    const URL  = 'https://www.obi.de/gartenschlauch/gardena-classic-schlauch/p/1234567';

    /**
     * Lo que la IA suele contestar con esta página: cita el JSON-LD tal cual, con la unidad como código («KGM»), y el
     * validador no lo puede comprobar.
     *
     * @return array
     */
    protected function citas_del_json()
    {
        return [
            'peso'  => $this->dato(2.7, 'kg', '"unitCode":"KGM","value":2.7', self::URL),
            'alto'  => $this->dato(9, 'cm', '"unitCode":"CMT","value":"9.00"', self::URL),
            'ancho' => $this->dato(38, 'cm', '"unitCode":"CMT","value":"38.00"', self::URL),
            'largo' => $this->dato(38, 'cm', '"unitCode":"CMT","value":"38.00"', self::URL),
        ];
    }

    /**
     * @param  mixed $ia
     * @return void
     */
    protected function falsear_obi($ia)
    {
        $this->falsear(
            ['"'.self::GTIN.'"' => [$this->resultado(self::URL, 'GARDENA Classic Schlauch')]],
            [self::URL => Datos_estructurados_Test::pagina_de_obi()],
            $ia
        );
    }

    /**
     * @group dimension-search
     * @test
     */
    public function la_ia_exacta_sin_citas_validas_se_completa_con_los_cuatro_estructurados_y_se_asigna()
    {
        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', self::GTIN);

        $this->falsear_obi($this->ficha('exacto', $this->citas_del_json()));

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('codigo_exacto', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);

        $articulo = $articulo->fresh();
        $this->assertEquals(2.7, (float) $articulo->peso);
        $this->assertEquals(9, (float) $articulo->alto);
        $this->assertEquals(38, (float) $articulo->ancho);
        $this->assertEquals(38, (float) $articulo->profundidad);

        $this->assertTrue($item->evidencia['peso']['estructurado']);
        $this->assertSame(self::URL, $item->evidencia['peso']['url']);
        $this->assertStringContainsString('"unitCode":"KGM","value":2.7', $item->evidencia['peso']['cita']);

        // El diagnóstico dice qué salió del JSON-LD y que la IA no pudo citarlo.
        $entrada = $item->diagnostico[0];
        $this->assertEqualsCanonicalizing(['peso', 'alto', 'ancho', 'profundidad'], $entrada['estructurados']);
        $this->assertSame('cita_no_coincide_con_el_valor', $entrada['ia']['campos']['peso']);
        $this->assertEqualsCanonicalizing(['peso', 'alto', 'ancho', 'profundidad'], $entrada['paginas'][0]['datos_estructurados']);
    }

    /**
     * Lo que la IA SÍ citó bien no se pisa con el JSON-LD: solo se completan los que faltan.
     *
     * @group dimension-search
     * @test
     */
    public function lo_que_la_ia_cito_bien_se_queda_y_el_json_ld_solo_completa_lo_que_falta()
    {
        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', self::GTIN);

        $citas = $this->citas_del_json();
        $citas['ancho'] = $this->dato(38, 'cm', 'Ancho: 38 cm', self::URL);

        $this->falsear(
            ['"'.self::GTIN.'"' => [$this->resultado(self::URL, 'GARDENA Classic Schlauch')]],
            [self::URL => str_replace('</main>', '<p>Ancho: 38 cm</p></main>', Datos_estructurados_Test::pagina_de_obi())],
            $this->ficha('exacto', $citas)
        );

        list($item) = $this->buscar($articulo);

        $this->assertArrayNotHasKey('estructurado', $item->evidencia['ancho']);
        $this->assertSame('Ancho: 38 cm', $item->evidencia['ancho']['cita']);
        $this->assertEqualsCanonicalizing(['peso', 'alto', 'profundidad'], $item->diagnostico[0]['estructurados']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function si_la_ia_dice_otro_producto_no_se_usan_los_estructurados()
    {
        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', self::GTIN);

        $this->falsear_obi($this->ficha('otro_producto'));

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertNull($item->peso_kg);
        $this->assertNull($articulo->fresh()->peso);
        $this->assertSame('otro_producto', $item->diagnostico[0]['resultado']);
        $this->assertArrayNotHasKey('estructurados', $item->diagnostico[0]);
    }

    /**
     * Sin la IA no hay `match`: los estructurados con el código dejan el artículo a revisar, nunca asignado.
     *
     * @group dimension-search
     * @test
     */
    public function si_la_ia_no_responde_los_estructurados_quedan_a_revisar_y_no_se_escribe_nada()
    {
        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', self::GTIN);

        $this->falsear_obi('caida');

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('coincidencia_probable', $item->motivo);
        $this->assertEquals(2.7, (float) $item->peso_kg);
        $this->assertEquals(38, (float) $item->profundidad_cm);
        $this->assertStringContainsString('La IA no respondió', $item->nota_ia);
        $this->assertNull($articulo->fresh()->peso, 'Nada se escribe solo.');
        $this->assertTrue($resultado['ia_sin_respuesta'], 'Para el job, la IA sigue sin responder.');
        $this->assertSame(1, $resultado['busquedas_intentadas'], 'Con datos, no sigue buscando.');
    }

    /**
     * Una página sin el código del artículo cuyo JSON-LD es de otro GTIN no aporta nada (plan de nombre).
     *
     * @group dimension-search
     * @test
     */
    public function el_json_ld_de_otro_producto_en_el_plan_de_nombre_no_se_usa()
    {
        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M');
        $url      = 'https://tienda.test/manguera';

        $html = '<html><head><title>Manguera</title><script type="application/ld+json">{"@type":"Product","gtin13":"4006000000007",'
            .'"weight":{"@type":"QuantitativeValue","unitCode":"KGM","value":5}}</script></head><body><p>Manguera de jardín.</p></body></html>';

        $this->falsear(
            ['MANGUERA GARDENA CLASSIC 13MM 20M ficha técnica peso medidas' => [$this->resultado($url, 'Manguera')]],
            [$url => $html],
            $this->ficha('probable')
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
        $this->assertNull($item->peso_kg);
    }

    /**
     * 🔴 Lo encontró la revisión independiente: el JSON-LD del artículo trae ANIDADO un producto relacionado
     * (`isRelatedTo`) con otro GTIN y su propio peso. La IA dice `exacto` y cita alto, ancho y largo; el peso NO puede
     * salir del producto relacionado (antes quedaba asignada con 9 kg, el de la bordeadora).
     *
     * @group dimension-search
     * @test
     */
    public function el_peso_de_un_producto_relacionado_anidado_no_completa_al_articulo()
    {
        $gtin     = $this->con_verificador('779123456810');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);
        $url      = 'https://tienda.test/cuchilla';

        $html = '<html><head><title>Cuchilla</title><script type="application/ld+json">{"@type":"Product","name":"Cuchilla","gtin13":"'.$gtin.'",'
            .'"isRelatedTo":{"@type":"Product","name":"Bordeadora","gtin13":"4006000000007","weight":{"@type":"QuantitativeValue","unitCode":"KGM","value":9}}}</script></head>'
            .'<body><main><h1>Cuchilla</h1><p>EAN '.$gtin.'. Alto: 5 cm. Ancho: 50 cm. Largo: 3 cm.</p></main></body></html>';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url, 'Cuchilla')]],
            [$url => $html],
            $this->ficha('exacto', $this->tres_medidas_citadas($url))
        );

        list($item) = $this->buscar($articulo);

        $this->assertNotSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('medidas_parciales', $item->motivo);
        $this->assertNull($item->peso_kg);
        $this->assertNull($articulo->fresh()->peso);
        $this->assertArrayNotHasKey('estructurados', $item->diagnostico[0]);
    }

    /**
     * Un `@graph` con dos productos: el del artículo (por su GTIN) sin peso y uno ajeno con peso. Se usa SOLO el del
     * artículo, aunque no traiga medidas.
     *
     * @group dimension-search
     * @test
     */
    public function en_un_graph_con_dos_productos_solo_cuenta_el_del_articulo_aunque_no_tenga_peso()
    {
        $gtin     = $this->con_verificador('779123456828');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);
        $url      = 'https://tienda.test/cuchilla-2';

        $html = '<html><head><title>Cuchilla</title><script type="application/ld+json">{"@context":"https://schema.org","@graph":['
            .'{"@type":"Product","name":"Cuchilla","gtin13":"'.$gtin.'"},'
            .'{"@type":"Product","name":"Bordeadora","gtin13":"4006000000007","weight":{"@type":"QuantitativeValue","unitCode":"KGM","value":9}}'
            .']}</script></head><body><main><p>EAN '.$gtin.'. Alto: 5 cm. Ancho: 50 cm. Largo: 3 cm.</p></main></body></html>';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url, 'Cuchilla')]],
            [$url => $html],
            $this->ficha('exacto', $this->tres_medidas_citadas($url))
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame('medidas_parciales', $item->motivo);
        $this->assertNull($item->peso_kg);
        $this->assertNull($articulo->fresh()->peso);
    }

    /**
     * Alto, ancho y largo citados bien por la IA (sin el peso).
     *
     * @param  string $url
     * @return array
     */
    protected function tres_medidas_citadas($url)
    {
        return [
            'alto'  => $this->dato(5, 'cm', 'Alto: 5 cm', $url),
            'ancho' => $this->dato(50, 'cm', 'Ancho: 50 cm', $url),
            'largo' => $this->dato(3, 'cm', 'Largo: 3 cm', $url),
        ];
    }

    /**
     * Un peso del JSON-LD escrito «1.200» en gramos (¿1,2 o 1.200?) no se asigna solo aunque la IA diga `exacto` y
     * cite bien las otras tres medidas: queda a revisar como `valor_ambiguo`.
     *
     * @group dimension-search
     * @test
     */
    public function un_peso_ambiguo_del_json_ld_queda_a_revisar()
    {
        $gtin     = $this->con_verificador('779123456836');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);
        $url      = 'https://tienda.test/cuchilla-3';

        $html = '<html><head><title>Cuchilla</title><script type="application/ld+json">{"@type":"Product","gtin13":"'.$gtin.'",'
            .'"weight":{"@type":"QuantitativeValue","unitCode":"GRM","value":"1.200"}}</script></head>'
            .'<body><main><p>EAN '.$gtin.'. Alto: 5 cm. Ancho: 50 cm. Largo: 3 cm.</p></main></body></html>';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url, 'Cuchilla')]],
            [$url => $html],
            $this->ficha('exacto', $this->tres_medidas_citadas($url))
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('valor_ambiguo', $item->motivo);
        $this->assertNull($articulo->fresh()->peso);
    }

    /**
     * Revisión independiente: muchas tiendas (Shopify, WooCommerce) ponen en el JSON-LD su `sku` INTERNO. Un Product
     * principal sin gtin, con un sku de la tienda, en una página que trae el GTIN del artículo en el texto: sus medidas
     * sirven (solo un gtin o un mpn distinto dicen que es otro producto).
     *
     * @group dimension-search
     * @test
     */
    public function el_sku_interno_de_la_tienda_no_descarta_las_medidas_del_producto_de_la_pagina()
    {
        $gtin     = $this->con_verificador('779123456844');
        $articulo = $this->nuevo_articulo('CUCHILLA ROTARY', $gtin);
        $url      = 'https://tienda.test/cuchilla-4';

        $html = '<html><head><title>Cuchilla</title><script type="application/ld+json">{"@type":"Product","name":"Cuchilla","sku":"SHOP-998",'
            .'"weight":{"@type":"QuantitativeValue","unitCode":"KGM","value":0.82}}</script></head>'
            .'<body><main><p>Código de barras '.$gtin.'. Alto: 5 cm. Ancho: 50 cm. Largo: 3 cm.</p></main></body></html>';

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url, 'Cuchilla')]],
            [$url => $html],
            $this->ficha('exacto', $this->tres_medidas_citadas($url))
        );

        list($item) = $this->buscar($articulo);

        $this->assertEquals(0.82, (float) $item->peso_kg);
        $this->assertTrue($item->evidencia['peso']['estructurado']);
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status, (string) $item->motivo_detalle);
    }
}
