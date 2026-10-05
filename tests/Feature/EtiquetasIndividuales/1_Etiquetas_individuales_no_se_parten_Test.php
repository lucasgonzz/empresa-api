<?php

namespace Tests\Feature\EtiquetasIndividuales;

/**
 * El PDF de etiquetas individuales nunca parte una etiqueta en dos hojas (misión
 * etiquetas-individuales-sin-partir, 4/10/2026).
 *
 * El caso de Lucas: Listado → filtro "espatula" (4 artículos) → Etiquetas individuales → Generar PDF.
 * Con el código de antes, en medidas chicas la estimación de alto no coincidía con el corte real y el
 * salto de página automático de FPDF mandaba el resto de la etiqueta a otra hoja: el precio solo en
 * la hoja siguiente, o el nombre repartido en tres. Ahora: 4 artículos = 4 hojas, siempre, y el
 * precio de cada uno en SU hoja.
 *
 * Los PDF se generan instanciando la clase directo con `$enviar = false` (el camino del controlador
 * termina en `exit` y mataría el proceso de PHPUnit) y con la compresión apagada, para poder buscar
 * los textos en el binario.
 *
 * @group etiquetas_individuales
 */
class Etiquetas_individuales_no_se_parten_Test extends EtiquetasIndividualesTestCase
{
    /**
     * Las medidas por defecto del modal, con nombre + código + precio (precio a 10 y a 16 pt).
     *
     * @test
     */
    public function cuatro_espatulas_salen_en_cuatro_hojas_en_todas_las_medidas()
    {
        $dueno = $this->crear_dueno();
        $ids = $this->crear_espatulas($dueno);

        $this->actingAs($dueno, 'web');

        $medidas = array(array(30, 20), array(50, 25), array(80, 25), array(80, 50), array(100, 75));

        foreach ($medidas as $medida) {
            foreach (array(10, 16) as $precio_pt) {
                $pdf = $this->pdf_plano($ids, $medida[0], $medida[1], $this->nombre_codigo_precio(11, $precio_pt));

                $this->assert_una_etiqueta_por_hoja($pdf, $medida[0], $medida[1], $medida[0].'x'.$medida[1].' con el precio a '.$precio_pt.' pt');
            }
        }
    }

    /**
     * Las configuraciones exactas que hoy parten la etiqueta (medidas con el código de `develop`
     * del 4/10/2026, mismos 4 artículos):
     *
     *   - 50x25, nombre 11, precio 10, código 8 → 8 hojas (el precio solo en la hoja siguiente).
     *   - 50x25, nombre 6,  precio 16, código 8 → 7 hojas (lo que vio Lucas).
     *   - 80x25, nombre 9,  precio 16, código 8 → 7 hojas (lo que vio Lucas).
     *   - 30x20, nombre 11, precio 10, código 8 → 16 hojas (el nombre repartido en 3 hojas).
     *
     * @test
     */
    public function las_configuraciones_que_partian_la_etiqueta_salen_en_cuatro_hojas()
    {
        $dueno = $this->crear_dueno();
        $ids = $this->crear_espatulas($dueno);

        $this->actingAs($dueno, 'web');

        /* ancho, alto, nombre pt, precio pt, alto del código. */
        $casos = array(
            array(50, 25, 11, 10, 8),
            array(50, 25, 6, 16, 8),
            array(80, 25, 9, 16, 8),
            array(30, 20, 11, 10, 8),
        );

        foreach ($casos as $caso) {
            list($ancho, $alto, $nombre_pt, $precio_pt, $codigo_alto) = $caso;

            $pdf = $this->pdf_plano($ids, $ancho, $alto, $this->nombre_codigo_precio($nombre_pt, $precio_pt), $codigo_alto, 1);

            $this->assert_una_etiqueta_por_hoja(
                $pdf,
                $ancho,
                $alto,
                $ancho.'x'.$alto.', nombre '.$nombre_pt.', precio '.$precio_pt.', código '.$codigo_alto
            );
        }
    }

    /**
     * Una medida vertical (más alta que ancha): la hoja sale con ESA medida y no girada. Con la
     * orientación fija en 'L', FPDF armaba 30x50 como una hoja de 50x30 y, sin el salto de página
     * automático, el contenido quedaba afuera de la hoja.
     *
     * @test
     */
    public function una_medida_vertical_sale_en_una_hoja_vertical()
    {
        $dueno = $this->crear_dueno();
        $ids = $this->crear_espatulas($dueno);

        $this->actingAs($dueno, 'web');

        /* Puntos por mm (el `k` de FPDF): el MediaBox va en puntos. */
        $k = 72 / 25.4;

        foreach (array(array(30, 50), array(40, 60)) as $medida) {
            list($ancho, $alto) = $medida;

            $pdf = $this->pdf_plano($ids, $ancho, $alto, $this->nombre_codigo_precio(11, 16));

            $this->assert_una_etiqueta_por_hoja($pdf, $ancho, $alto, $ancho.'x'.$alto.' vertical');

            /* Cada hoja declara su tamaño: ancho x alto, no al revés. */
            $media_box = sprintf('/MediaBox [0 0 %.2F %.2F]', $ancho * $k, $alto * $k);
            $this->assertSame(4, substr_count($pdf, $media_box), $ancho.'x'.$alto.': las 4 hojas miden '.$media_box.'.');
        }
    }

    /**
     * 80x50 con la config por defecto (nombre 11 + código): sale con la letra pedida, sin achicar.
     *
     * @test
     */
    public function en_ochenta_por_cincuenta_con_la_config_por_defecto_la_letra_no_cambia()
    {
        $dueno = $this->crear_dueno();
        $ids = $this->crear_espatulas($dueno);

        $this->actingAs($dueno, 'web');

        $pdf = $this->pdf_plano($ids, 80, 50);

        $this->assertSame(4, $this->paginas($pdf));

        /*
         * Una sola letra en todo el PDF: Helvetica a 11 pt. (FPDF vuelve a emitir el `Tf` al abrir
         * cada hoja y en cada `SetFont('Arial')`, así que se mira el tamaño, no cuántas veces sale.)
         */
        preg_match_all('#/F\d+ ([\d.]+) Tf#', $pdf, $tamanos);

        $this->assertSame(array('11.00'), array_values(array_unique($tamanos[1])), 'Ningún texto con otra letra.');

        foreach ($this->contenido_por_pagina($pdf) as $numero => $hoja) {
            $this->assertGreaterThan(0, preg_match_all('#/F1 11\.00 Tf#', $hoja), 'Hoja '.$numero.' a 11 pt.');
        }
    }

    /**
     * Un artículo sin código de barras: una hoja, sin imagen, con su nombre y su precio.
     *
     * @test
     */
    public function un_articulo_sin_codigo_de_barras_sale_en_una_hoja_sin_imagen()
    {
        $dueno = $this->crear_dueno();
        $id = $this->crear_articulo($dueno, array('name' => 'ZZSINCODIGO', 'bar_code' => null, 'final_price' => 777));

        $this->actingAs($dueno, 'web');

        $pdf = $this->pdf_plano(array($id), 50, 25, $this->nombre_codigo_precio(11, 16));

        $this->assertSame(1, $this->paginas($pdf));
        $this->assertSame(0, substr_count($pdf, '/Subtype /Image'));
        $this->assertSame(1, substr_count($pdf, '(ZZSINCODIGO)'));
        $this->assertSame(1, substr_count($pdf, '($777)'));
    }

    /**
     * El PNG temporal del código de barras se borra: generar el PDF no deja basura en el
     * directorio actual.
     *
     * @test
     */
    public function generar_el_pdf_no_deja_archivos_temporales()
    {
        $dueno = $this->crear_dueno();
        $ids = $this->crear_espatulas($dueno);

        $this->actingAs($dueno, 'web');

        /* Lo que ya hubiera de antes (de otra corrida) no cuenta. */
        $antes = glob(getcwd().DIRECTORY_SEPARATOR.'temp_barcode*.png');

        $this->pdf_plano($ids, 30, 20, $this->nombre_codigo_precio(11, 10));

        $despues = glob(getcwd().DIRECTORY_SEPARATOR.'temp_barcode*.png');

        $this->assertSame(array(), array_values(array_diff($despues, $antes)));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     *  Aserciones
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * Las 4 espátulas en 4 hojas, cada una con UN código de barras, UN nombre y SU precio (que
     * aparece una sola vez en todo el PDF), y ningún texto dibujado afuera de la hoja.
     *
     * @param  string  $pdf
     * @param  int     $ancho
     * @param  int     $alto
     * @param  string  $caso   Para el mensaje de error.
     * @return void
     */
    protected function assert_una_etiqueta_por_hoja($pdf, $ancho, $alto, $caso)
    {
        $this->assertSame(4, $this->paginas($pdf), $caso.': 4 artículos tienen que ser 4 hojas.');

        $hojas = $this->contenido_por_pagina($pdf);

        /* Puntos por mm (el `k` de FPDF): las coordenadas del contenido van en puntos. */
        $k = 72 / 25.4;

        foreach (self::PRECIOS as $indice => $precio) {
            /* La hoja de este artículo (las hojas se cuentan desde 1). */
            $hoja = $hojas[$indice + 1];

            $this->assertSame(1, substr_count($pdf, '('.$precio[1].')'), $caso.': el precio '.$precio[1].' sale una sola vez.');
            $this->assertSame(1, substr_count($hoja, '('.$precio[1].')'), $caso.': el precio '.$precio[1].' va en la hoja '.($indice + 1).'.');

            $this->assertSame(1, preg_match_all('#/I\d+ Do#', $hoja), $caso.': un código de barras en la hoja '.($indice + 1).'.');
            $this->assertSame(1, preg_match_all('#\(ESPATULA#', $hoja), $caso.': el nombre arranca una sola vez en la hoja '.($indice + 1).'.');

            /* Cada texto: `BT x y Td`, con `y` desde abajo. Tiene que caer adentro de la hoja. */
            preg_match_all('#BT (-?[\d.]+) (-?[\d.]+) Td#', $hoja, $coordenadas, PREG_SET_ORDER);

            $this->assertGreaterThan(0, count($coordenadas), $caso.': la hoja '.($indice + 1).' tiene texto.');

            foreach ($coordenadas as $coordenada) {
                $this->assertGreaterThanOrEqual(0, (float) $coordenada[1], $caso.': texto afuera por la izquierda.');
                $this->assertLessThanOrEqual($ancho * $k, (float) $coordenada[1], $caso.': texto afuera por la derecha.');
                $this->assertGreaterThan(0, (float) $coordenada[2], $caso.': texto afuera por abajo.');
                $this->assertLessThan($alto * $k, (float) $coordenada[2], $caso.': texto afuera por arriba.');
            }
        }
    }
}
