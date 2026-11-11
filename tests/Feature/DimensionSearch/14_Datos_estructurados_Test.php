<?php

namespace Tests\Feature\DimensionSearch;

use App\Services\DimensionSearch\DimensionStructuredData;
use App\Services\DimensionSearch\DimensionValueValidator;
use PHPUnit\Framework\TestCase;

/**
 * Los datos estructurados (JSON-LD de schema.org) de una página de producto (misión medidas-sitios-de-referencia,
 * 11/11/2026): DimensionStructuredData los lee por código, con la unidad como código UN/CEFACT (KGM, CMT) o como
 * texto, y el validador les aplica los topes de cordura.
 *
 * El caso real es la ficha de la manguera Gardena de OBI (EAN 4078500001694): «"weight":{…"unitCode":"KGM","value":2.7}».
 *
 * Puro texto y números: no toca la base ni la red, por eso extiende el TestCase de PHPUnit pelado.
 */
class Datos_estructurados_Test extends TestCase
{
    /**
     * El JSON-LD de la ficha real de OBI, recortado.
     *
     * @return string  HTML.
     */
    public static function pagina_de_obi()
    {
        return '<html><head><title>GARDENA Classic Schlauch 13 mm 20 m</title>'
            .'<script type="application/ld+json">{"@context":"http://schema.org","@type":"Product","name":"GARDENA Classic Schlauch",'
            .'"gtin13":"4078500001694","sku":"1234567",'
            .'"weight":{"@context":"http://schema.org","@type":"QuantitativeValue","unitCode":"KGM","value":2.7},'
            .'"width":{"@context":"http://schema.org","@type":"QuantitativeValue","unitCode":"CMT","value":"38.00"},'
            .'"height":{"@context":"http://schema.org","@type":"QuantitativeValue","unitCode":"CMT","value":"9.00"},'
            .'"depth":{"@context":"http://schema.org","@type":"QuantitativeValue","unitCode":"CMT","value":"38.00"},'
            .'"offers":{"@type":"Offer","price":"24.99","priceCurrency":"EUR"}}</script>'
            .'</head><body><main><h1>GARDENA Classic Schlauch</h1>'
            .'<p>Maße &amp; Gewicht Gewicht 2,70 kg Höhe 9,0 cm Breite 38,0 cm Tiefe 38,0 cm</p></main></body></html>';
    }

    /**
     * @group dimension-search
     * @test
     */
    public function lee_la_ficha_real_de_obi_con_unit_code_y_la_cita_tal_cual()
    {
        $nodos = DimensionStructuredData::de_html(self::pagina_de_obi());

        $this->assertCount(1, $nodos);
        $this->assertSame('4078500001694', $nodos[0]['codigos']['gtin13']);
        $this->assertSame('1234567', $nodos[0]['codigos']['sku']);

        $campos = $nodos[0]['campos'];

        $this->assertEqualsCanonicalizing(['peso', 'alto', 'ancho', 'profundidad'], array_keys($campos));
        $this->assertEquals(2.7, $campos['peso']['valor']);
        $this->assertSame('kg', $campos['peso']['unidad']);
        $this->assertSame('kg', $campos['peso']['unidad_original']);
        $this->assertFalse($campos['peso']['convertido']);
        $this->assertEquals(9, $campos['alto']['valor']);
        $this->assertEquals(38, $campos['ancho']['valor']);
        $this->assertEquals(38, $campos['profundidad']['valor']);
        $this->assertSame('cm', $campos['profundidad']['unidad']);
        $this->assertSame('producto', $campos['peso']['alcance']);

        // La cita es el fragmento JSON tal como está en la página.
        $this->assertSame('"weight":{"@context":"http://schema.org","@type":"QuantitativeValue","unitCode":"KGM","value":2.7}', $campos['peso']['cita']);
        $this->assertStringContainsString('"unitCode":"CMT","value":"9.00"', $campos['alto']['cita']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function lee_graph_listas_unit_text_gramos_y_milimetros()
    {
        $html = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":['
            .'{"@type":"WebSite","name":"Tienda"},'
            .'{"@type":["Product"],"mpn":"503448371","weight":{"@type":"QuantitativeValue","unitText":"g","value":111},'
            .'"height":{"@type":"QuantitativeValue","unitCode":"MMT","value":90},"width":"6,5 cm"}'
            .']}</script>'
            .'<script type="application/ld+json">[{"@type":"Organization","name":"X"},{"@type":"Product","sku":"ZZ-1","weight":{"@type":"QuantitativeValue","unitCode":"LBR","value":"2"}}]</script>';

        $nodos = DimensionStructuredData::de_html($html);

        $this->assertCount(2, $nodos);

        $meteor = $nodos[0];
        $this->assertSame('503448371', $meteor['codigos']['mpn']);
        $this->assertEquals(0.111, $meteor['campos']['peso']['valor'], 'unitText «g» → kg.');
        $this->assertSame('g', $meteor['campos']['peso']['unidad_original']);
        $this->assertTrue($meteor['campos']['peso']['convertido']);
        $this->assertEquals(9, $meteor['campos']['alto']['valor'], 'MMT → cm.');
        $this->assertEquals(6.5, $meteor['campos']['ancho']['valor'], 'Un texto «6,5 cm» también se lee.');
        $this->assertArrayNotHasKey('profundidad', $meteor['campos']);

        $this->assertEquals(0.9072, $nodos[1]['campos']['peso']['valor'], 'LBR → kg (4 decimales).');
    }

    /**
     * @group dimension-search
     * @test
     */
    public function sin_datos_json_roto_o_unidades_que_no_son_del_campo_no_devuelve_nada()
    {
        // Sin JSON-LD.
        $this->assertSame([], DimensionStructuredData::de_html('<html><body>Peso 2 kg</body></html>'));

        // JSON roto: se saltea sin romper (y el bloque bueno de al lado se lee igual).
        $roto = '<script type="application/ld+json">{"@type":"Product","weight":{"value":2.7,</script>'
            .'<script type="application/ld+json">{"@type":"Product","gtin13":"4078500001694","weight":{"@type":"QuantitativeValue","unitCode":"KGM","value":1.5}}</script>';
        $nodos = DimensionStructuredData::de_html($roto);
        $this->assertCount(1, $nodos);
        $this->assertEquals(1.5, $nodos[0]['campos']['peso']['valor']);

        // Un peso en centímetros, una unidad desconocida, un valor que no es un número o cero: no se toman.
        $malo = '<script type="application/ld+json">{"@type":"Product","sku":"A1",'
            .'"weight":{"@type":"QuantitativeValue","unitCode":"CMT","value":3},'
            .'"height":{"@type":"QuantitativeValue","unitCode":"XYZ","value":3},'
            .'"width":{"@type":"QuantitativeValue","unitCode":"CMT","value":"mucho"},'
            .'"depth":{"@type":"QuantitativeValue","unitCode":"CMT","value":0}}</script>';
        $nodos = DimensionStructuredData::de_html($malo);
        $this->assertCount(1, $nodos, 'El nodo queda por su código…');
        $this->assertSame([], $nodos[0]['campos'], '…pero sin ningún campo.');

        // Un nodo que no es un producto (una oferta suelta, una organización) no cuenta.
        $this->assertSame([], DimensionStructuredData::de_html('<script type="application/ld+json">{"@type":"Organization","weight":{"unitCode":"KGM","value":2}}</script>'));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function elige_el_nodo_del_articulo_por_su_codigo_y_si_no_el_unico_de_una_pagina_con_el_codigo()
    {
        $html = '<script type="application/ld+json">[{"@type":"Product","gtin13":"4006000000007","weight":{"unitCode":"KGM","value":5}},'
            .'{"@type":"Product","gtin13":"4078500001694","weight":{"unitCode":"KGM","value":2.7}},'
            .'{"@type":"Product","mpn":"32 853 63-S","weight":{"unitCode":"KGM","value":0.75}}]</script>';
        $nodos = DimensionStructuredData::de_html($html);

        // Por GTIN (aunque esté escrito en otra longitud: EAN-13 contra GTIN-14).
        $elegido = DimensionStructuredData::del_articulo($nodos, '04078500001694', null, false);
        $this->assertEquals(2.7, $elegido['nodo']['campos']['peso']['valor']);
        $this->assertTrue($elegido['por_codigo']);

        // Por el código de proveedor (sin importar espacios ni guiones).
        $elegido = DimensionStructuredData::del_articulo($nodos, null, '3285363-S', false);
        $this->assertEquals(0.75, $elegido['nodo']['campos']['peso']['valor']);

        // Varios nodos y ninguno con el código: no se sabe cuál es (aunque la página traiga el código en otro lado).
        $this->assertNull(DimensionStructuredData::del_articulo($nodos, '7791234567898', null, true));

        // Un solo nodo sin el código: solo si la página trae el código del artículo.
        $uno = DimensionStructuredData::de_html('<script type="application/ld+json">{"@type":"Product","name":"X","weight":{"unitCode":"KGM","value":2}}</script>');
        $this->assertNull(DimensionStructuredData::del_articulo($uno, null, 'AB-1234', false));
        $this->assertFalse(DimensionStructuredData::del_articulo($uno, null, 'AB-1234', true)['por_codigo']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_validador_aplica_los_topes_de_cordura_a_un_dato_estructurado()
    {
        $validador = new DimensionValueValidator();

        $nodos = DimensionStructuredData::de_html(self::pagina_de_obi());
        $ok    = $validador->validar_estructurado('peso', $nodos[0]['campos']['peso'], 'https://www.obi.de/p/1');

        $this->assertTrue($ok['ok']);
        $this->assertEquals(2.7, $ok['dato']['valor']);
        $this->assertTrue($ok['dato']['etiquetada'], 'La propiedad del JSON-LD dice qué medida es.');
        $this->assertTrue($ok['dato']['estructurado']);
        $this->assertFalse($ok['dato']['ambiguo']);
        $this->assertSame('https://www.obi.de/p/1', $ok['dato']['url']);

        $mucho = $validador->validar_estructurado('peso', ['valor' => 5000, 'unidad' => 'kg'], 'https://x.test');
        $this->assertFalse($mucho['ok']);
        $this->assertSame('fuera_de_rango', $mucho['motivo']);

        $otra_unidad = $validador->validar_estructurado('alto', ['valor' => 2, 'unidad' => 'kg'], 'https://x.test');
        $this->assertSame('unidad_invalida', $otra_unidad['motivo']);
    }
}
