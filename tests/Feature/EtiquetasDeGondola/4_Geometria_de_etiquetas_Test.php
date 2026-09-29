<?php

namespace Tests\Feature\EtiquetasDeGondola;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;

/**
 * La geometría de la hoja (§3.3 del plan) y el diseño de siempre como datos (§3.7): son las
 * fórmulas que el SPA espeja en `geometria.js` y `diseno_actual.js`, así que un cambio acá sin
 * cambiar allá hace que el editor muestre una cosa y el PDF imprima otra.
 *
 * @group etiquetas_de_gondola
 */
class Geometria_de_etiquetas_Test extends EtiquetasDeGondolaTestCase
{
    /** @test */
    public function el_diseno_de_siempre_es_3_por_7_de_40_mm_y_66_7_de_ancho()
    {
        $diseno = ArticleTicketDesignHelper::diseno_actual();

        $this->assertSame(1, $diseno['version']);
        $this->assertSame(3, $diseno['columnas']);
        $this->assertSame(7, $diseno['filas']);
        $this->assertSame(40, $diseno['alto_mm']);
        $this->assertTrue($diseno['marco']);

        $this->assertSame(7, ArticleTicketDesignHelper::filas_por_hoja($diseno['alto_mm']));
        $this->assertSame(66.7, ArticleTicketDesignHelper::ancho_etiqueta_redondeado(3));

        $esperado = array(
            array('precio_final',         0,    0,  66.7, 13, 33, true,  false, 'R'),
            array('nombre',               0,    13, 66.7, 15, 12, true,  true,  'L'),
            array('codigo_barras_imagen', 1,    28, 51.7, 6,  8,  false, false, 'L'),
            array('codigo_barras_texto',  0,    34, 51.7, 5,  8,  false, false, 'C'),
            array('fecha_impresion',      51.7, 34, 15,   5,  8,  false, false, 'C'),
        );

        $this->assertCount(5, $diseno['elementos']);

        foreach ($esperado as $i => $fila) {
            $elemento = $diseno['elementos'][$i];

            $this->assertSame($fila[0], $elemento['tipo'], 'Campo '.$i);
            $this->assertEquals($fila[1], $elemento['x'], $fila[0].' x');
            $this->assertEquals($fila[2], $elemento['y'], $fila[0].' y');
            $this->assertEquals($fila[3], $elemento['w'], $fila[0].' w');
            $this->assertEquals($fila[4], $elemento['h'], $fila[0].' h');
            $this->assertEquals($fila[5], $elemento['tamano'], $fila[0].' tamano');
            $this->assertSame($fila[6], $elemento['negrita'], $fila[0].' negrita');
            $this->assertSame($fila[7], $elemento['saltos_de_linea'], $fila[0].' saltos');
            $this->assertSame($fila[8], $elemento['alineacion'], $fila[0].' alineacion');
        }

        /* Con lista: el precio es de esa lista, sin rótulo. */
        $con_lista = ArticleTicketDesignHelper::diseno_actual(12);
        $this->assertSame('precio_lista', $con_lista['elementos'][0]['tipo']);
        $this->assertSame(12, $con_lista['elementos'][0]['price_type_id']);
        $this->assertFalse($con_lista['elementos'][0]['rotulo']);
    }

    /**
     * El catálogo tiene 25 tipos y el SPA lo espeja con las mismas claves.
     *
     * @test
     */
    public function el_catalogo_tiene_los_25_tipos()
    {
        $this->assertSame(array(
            'nombre', 'precio_final', 'precio_lista', 'codigo_barras_imagen', 'codigo_barras_texto',
            'codigo_proveedor', 'codigo_interno', 'categoria', 'sub_categoria', 'marca', 'proveedor',
            'descripcion', 'unidad_medida', 'stock', 'imagen', 'fecha_impresion', 'texto_fijo',
            'precio_anterior', 'precio_promocional', 'contenido', 'plu', 'origen', 'modelo',
            'unidades_por_bulto', 'peso',
        ), array_keys(ArticleTicketDesignHelper::CATALOGO));

        $this->assertSame(
            array('precio_final', 'precio_lista', 'precio_anterior', 'precio_promocional'),
            ArticleTicketDesignHelper::TIPOS_CON_ROTULO
        );

        $this->assertSame(array('w' => 40, 'h' => 8, 'tamano' => 14, 'negrita' => true, 'saltos_de_linea' => false, 'alineacion' => 'R'), ArticleTicketDesignHelper::CATALOGO['precio_anterior']);
        $this->assertSame(array('w' => 40, 'h' => 10, 'tamano' => 20, 'negrita' => true, 'saltos_de_linea' => false, 'alineacion' => 'R'), ArticleTicketDesignHelper::CATALOGO['precio_promocional']);
        $this->assertSame(array('w' => 50, 'h' => 8, 'tamano' => 9, 'negrita' => false, 'saltos_de_linea' => true, 'alineacion' => 'L'), ArticleTicketDesignHelper::CATALOGO['modelo']);
    }

    /** @test */
    public function ancho_por_columnas_filas_por_alto_y_alto_por_filas()
    {
        $this->assertEquals(200, ArticleTicketDesignHelper::ancho_etiqueta(1));
        $this->assertEquals(100, ArticleTicketDesignHelper::ancho_etiqueta(2));
        $this->assertEquals(50, ArticleTicketDesignHelper::ancho_etiqueta(4));
        $this->assertEqualsWithDelta(66.6667, ArticleTicketDesignHelper::ancho_etiqueta(3), 0.001);

        $this->assertSame(7, ArticleTicketDesignHelper::filas_por_hoja(41));
        $this->assertSame(6, ArticleTicketDesignHelper::filas_por_hoja(41.1));
        $this->assertSame(28, ArticleTicketDesignHelper::filas_por_hoja(10));
        $this->assertSame(1, ArticleTicketDesignHelper::filas_por_hoja(287));
        $this->assertSame(1, ArticleTicketDesignHelper::filas_por_hoja(0));

        $this->assertEquals(41, ArticleTicketDesignHelper::alto_para_filas(7));
        $this->assertEquals(95.6, ArticleTicketDesignHelper::alto_para_filas(3));
        $this->assertEquals(14.3, ArticleTicketDesignHelper::alto_para_filas(20));

        /* Ida y vuelta: el alto sugerido para N filas deja entrar N filas. */
        for ($filas = 1; $filas <= 20; $filas++) {
            $this->assertSame($filas, ArticleTicketDesignHelper::filas_por_hoja(ArticleTicketDesignHelper::alto_para_filas($filas)), $filas.' filas');
        }
    }
}
