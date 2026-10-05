<?php

namespace Tests\Feature\EtiquetasIndividuales;

use App\Http\Controllers\Pdf\ArticleTicket\ArticleBarCodeEtiquetasPdf;
use App\Http\Controllers\Pdf\ArticleTicket\DisposicionDeEtiquetaIndividual;

/**
 * La disposición de una etiqueta individual (misión etiquetas-individuales-sin-partir, 4/10/2026):
 * el algoritmo puro que decide qué líneas van, con qué letra y en qué `y`.
 *
 * 🔴 El mismo algoritmo está portado a JS en `empresa-spa` para la vista previa del modal. Los
 * valores exactos que fijan estos tests (factor, alto del código, líneas, omitidos) son también la
 * referencia para la paridad PHP ↔ JS.
 *
 * @group etiquetas_individuales
 */
class Disposicion_de_etiqueta_individual_Test extends EtiquetasIndividualesTestCase
{
    /**
     * Barrido de medidas × letras × interlineados × propiedades × nombres: el contenido nunca pasa
     * de `alto - 2`, cada bloque cae adentro de la etiqueta, cada línea entra en `ancho - 2`, y
     * nada desaparece sin quedar declarado en `omitidos`.
     *
     * @test
     */
    public function la_disposicion_nunca_pasa_del_alto_de_la_etiqueta()
    {
        $medidas = array(
            array(30, 12), array(30, 20), array(40, 15), array(50, 25), array(60, 30),
            array(80, 25), array(80, 50), array(100, 20), array(100, 75),
        );

        $nombres = array_keys(self::ESPATULAS);

        /* El nombre más largo y el más corto de los cuatro. */
        $nombres = array($nombres[2], $nombres[3]);

        $conjuntos = array(
            array('nombre', 'codigo_barras', 'precio'),
            array('nombre', 'precio'),
            array('nombre', 'codigo_barras', 'codigo_proveedor', 'sku', 'precio', 'categoria', 'marca', 'fecha_actual', 'nombre_negocio'),
        );

        /* Un texto para cada propiedad de texto (el nombre se pisa en cada vuelta). */
        $textos = array(
            'codigo_proveedor' => 'PROV-12345',
            'sku'              => 'SKU-778899',
            'precio'           => '$12.345,67',
            'categoria'        => 'Herramientas de construcción en seco',
            'marca'            => 'BIASSONI',
            'fecha_actual'     => '04/10/2026',
            'nombre_negocio'   => 'Ferretería El Tornillo Feliz',
        );

        $casos = 0;

        foreach ($medidas as $medida) {
            list($ancho, $alto) = $medida;

            foreach (array(6, 11, 16, 24) as $pt) {
                foreach (array(0, 1, 3, 8) as $interlineado) {
                    foreach ($conjuntos as $conjunto) {
                        foreach ($nombres as $nombre) {
                            $textos['nombre'] = $nombre;

                            $propiedades = array();

                            foreach ($conjunto as $indice => $key) {
                                /* Una en negrita, para pasar también por la tabla de Helvetica-Bold. */
                                $propiedades[] = array('key' => $key, 'font_size' => $pt, 'negrita' => $indice === 0);
                            }

                            $codigo_alto = ArticleBarCodeEtiquetasPdf::default_code_height_for_etiqueta_height($alto);

                            $resultado = DisposicionDeEtiquetaIndividual::calcular(
                                $ancho, $alto, $propiedades, $codigo_alto, $interlineado, $textos, true
                            );

                            $this->assert_disposicion_valida(
                                $resultado, $ancho, $alto, $conjunto,
                                $ancho.'x'.$alto.' a '.$pt.' pt, interlineado '.$interlineado.', '.implode('+', $conjunto).', '.$nombre
                            );

                            $casos++;
                        }
                    }
                }
            }
        }

        $this->assertSame(864, $casos);
    }

    /**
     * 80x50 con la config por defecto (nombre 11 + código de 11 mm, interlineado 1): modo normal,
     * factor 1, nada ajustado. Lo que hoy sale bien no cambia.
     *
     * @test
     */
    public function en_ochenta_por_cincuenta_la_config_por_defecto_sale_sin_ajustar()
    {
        $propiedades = array(
            array('key' => 'nombre', 'font_size' => 11, 'negrita' => false),
            array('key' => 'codigo_barras', 'font_size' => 11, 'negrita' => false),
        );

        $codigo_alto = ArticleBarCodeEtiquetasPdf::default_code_height_for_etiqueta_height(50);

        $this->assertSame(11, $codigo_alto);

        foreach (array_keys(self::ESPATULAS) as $nombre) {
            $resultado = DisposicionDeEtiquetaIndividual::calcular(80, 50, $propiedades, $codigo_alto, 1, array('nombre' => $nombre), true);

            $this->assertSame('normal', $resultado['modo'], $nombre);
            $this->assertEquals(1, $resultado['factor'], $nombre);
            $this->assertFalse($resultado['ajustado'], $nombre);
            $this->assertFalse($resultado['recortado'], $nombre);
            $this->assertSame(array(), $resultado['omitidos'], $nombre);
            $this->assertEquals(11, $resultado['codigo_alto'], $nombre);
            $this->assertEquals(1, $resultado['interlineado'], $nombre);

            $this->assertSame(array('nombre', 'codigo_barras'), array_column($resultado['bloques'], 'key'), $nombre);

            /* La letra pedida y el alto de línea de siempre: max(4, floor(11 * 0.55)) = 6 mm. */
            $this->assertEquals(11, $resultado['bloques'][0]['tamano'], $nombre);
            $this->assertEquals(6, $resultado['bloques'][0]['alto_linea'], $nombre);

            /* Centrado: lo que sobra, mitad arriba y mitad abajo. */
            $this->assertEquals((50 - $resultado['alto_total']) / 2, $resultado['y_inicio'], $nombre);
            $this->assertEquals($resultado['y_inicio'], $resultado['bloques'][0]['y'], $nombre);
        }

        /* Con el precio a 16 pt también entra sin ajustar. */
        $propiedades[] = array('key' => 'precio', 'font_size' => 16, 'negrita' => false);

        $resultado = DisposicionDeEtiquetaIndividual::calcular(
            80, 50, $propiedades, $codigo_alto, 1,
            array('nombre' => 'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI', 'precio' => '$12.345,67'), true
        );

        $this->assertSame('normal', $resultado['modo']);
        $this->assertFalse($resultado['ajustado']);
        $this->assertSame(array('ESPATULA PARA JUNTAS 150MM CONST.', 'EN SECO BIASSONI'), $resultado['bloques'][0]['lineas']);
    }

    /**
     * Los casos de Lucas: cada uno se resuelve en el paso que corresponde, con estos valores exactos
     * (referencia también para la paridad con JS).
     *
     * @test
     */
    public function los_casos_que_partian_la_etiqueta_se_resuelven_achicando()
    {
        $nombre = 'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI';
        $textos = array('nombre' => $nombre, 'precio' => '$12.345,67');

        /* 50x25 nombre 6, precio 16: con el alto de línea compacto alcanza, la letra no se toca. */
        $resultado = DisposicionDeEtiquetaIndividual::calcular(50, 25, $this->nombre_codigo_precio(6, 16), 8, 1, $textos, true);

        $this->assertSame('compacto', $resultado['modo']);
        $this->assertEquals(1, $resultado['factor']);
        $this->assertEquals(8, $resultado['codigo_alto']);
        $this->assertTrue($resultado['ajustado']);
        $this->assertFalse($resultado['recortado']);

        /* 50x25 nombre 11, precio 10: la letra baja al 75 %. */
        $resultado = DisposicionDeEtiquetaIndividual::calcular(50, 25, $this->nombre_codigo_precio(11, 10), 8, 1, $textos, true);

        $this->assertSame('compacto', $resultado['modo']);
        $this->assertEquals(0.75, $resultado['factor']);
        $this->assertEquals(8.25, $resultado['bloques'][0]['tamano']);
        $this->assertEquals(7.5, $resultado['bloques'][2]['tamano']);
        $this->assertEquals(0.75, $resultado['interlineado']);
        $this->assertSame(array('ESPATULA PARA JUNTAS 150MM', 'CONST. EN SECO BIASSONI'), $resultado['bloques'][0]['lineas']);

        /* 30x20 nombre 11, precio 10: las dos letras en el mínimo (5 pt), el nombre en 3 líneas. */
        $resultado = DisposicionDeEtiquetaIndividual::calcular(30, 20, $this->nombre_codigo_precio(11, 10), 8, 1, $textos, true);

        $this->assertEquals(0.45, $resultado['factor']);
        $this->assertEquals(5, $resultado['bloques'][0]['tamano']);
        $this->assertEquals(5, $resultado['bloques'][2]['tamano']);
        $this->assertEquals(8, $resultado['codigo_alto']);
        $this->assertSame(array('ESPATULA PARA JUNTAS', '150MM CONST. EN SECO', 'BIASSONI'), $resultado['bloques'][0]['lineas']);
        $this->assertSame(array(), $resultado['omitidos']);
    }

    /**
     * Caso extremo, 30x12 con 6 propiedades: la letra al mínimo, el código al mínimo, el nombre
     * recortado a una línea con "..." y, como último recurso, afuera los tres bloques de abajo.
     *
     * @test
     */
    public function en_un_caso_extremo_recorta_con_puntos_y_deja_afuera_los_ultimos_bloques()
    {
        $propiedades = array(
            array('key' => 'nombre', 'font_size' => 11, 'negrita' => false),
            array('key' => 'codigo_barras', 'font_size' => 8, 'negrita' => false),
            array('key' => 'precio', 'font_size' => 10, 'negrita' => false),
            array('key' => 'categoria', 'font_size' => 8, 'negrita' => false),
            array('key' => 'marca', 'font_size' => 8, 'negrita' => false),
            array('key' => 'fecha_actual', 'font_size' => 8, 'negrita' => false),
        );

        $textos = array(
            'nombre'       => 'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI',
            'precio'       => '$12.345,67',
            'categoria'    => 'Herramientas de mano',
            'marca'        => 'Biassoni',
            'fecha_actual' => '04/10/2026',
        );

        $resultado = DisposicionDeEtiquetaIndividual::calcular(30, 12, $propiedades, 8, 1, $textos, true);

        $this->assertSame('compacto', $resultado['modo']);
        $this->assertEquals(0.45, $resultado['factor']);
        $this->assertEquals(4, $resultado['codigo_alto']);
        $this->assertTrue($resultado['ajustado']);
        $this->assertTrue($resultado['recortado']);

        /* Se sacan de abajo hacia arriba: primero la fecha, después la marca, después la categoría. */
        $this->assertSame(array('fecha_actual', 'marca', 'categoria'), $resultado['omitidos']);
        $this->assertSame(array('nombre', 'codigo_barras', 'precio'), array_column($resultado['bloques'], 'key'));

        $this->assertSame(array('ESPATULA PARA JUNTAS...'), $resultado['bloques'][0]['lineas']);
        $this->assertTrue($resultado['bloques'][0]['recortado']);
        $this->assertFalse($resultado['bloques'][2]['recortado']);

        $this->assertLessThanOrEqual(10, $resultado['alto_total']);
    }

    /**
     * Sin bloques de texto el paso 2 no hace nada: con solo el código y una etiqueta donde ni el
     * código mínimo entra, el código queda afuera (modo compacto, factor 1).
     *
     * @test
     */
    public function solo_el_codigo_en_una_etiqueta_donde_no_entra_lo_deja_afuera()
    {
        $propiedades = array(array('key' => 'codigo_barras', 'font_size' => 11, 'negrita' => false));

        $resultado = DisposicionDeEtiquetaIndividual::calcular(30, 5, $propiedades, 8, 1, array(), true);

        $this->assertSame('compacto', $resultado['modo']);
        $this->assertEquals(1, $resultado['factor']);
        $this->assertEquals(4, $resultado['codigo_alto']);
        $this->assertSame(array('codigo_barras'), $resultado['omitidos']);
        $this->assertSame(array(), $resultado['bloques']);
        $this->assertEquals(0, $resultado['alto_total']);
        $this->assertEquals(2.5, $resultado['y_inicio']);

        /* Sin código de barras en el artículo, el bloque no existe y no hay nada que ajustar. */
        $resultado = DisposicionDeEtiquetaIndividual::calcular(30, 5, $propiedades, 8, 1, array(), false);

        $this->assertSame('normal', $resultado['modo']);
        $this->assertFalse($resultado['ajustado']);
        $this->assertSame(array(), $resultado['omitidos']);
    }

    /**
     * El ancho de un texto es exactamente el `GetStringWidth()` de FPDF sobre el `utf8_decode()`
     * que hace `Cell()`, en normal y en negrita, con tamaños enteros y fraccionarios.
     *
     * @test
     */
    public function el_ancho_de_un_texto_es_el_de_fpdf()
    {
        /* Carga fpdf.php (la clase del PDF lo requiere al cargarse). */
        $this->assertTrue(class_exists(ArticleBarCodeEtiquetasPdf::class));

        $fpdf = new \FPDF('P', 'mm', 'A4');

        $textos = array(
            'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI',
            'Ñandú de peluche, cañería ¿qué?',
            'Precio € 1.234 — oferta',
            '$12.345,67',
            'iiiiWWWW mmm ...',
        );

        foreach (array(false, true) as $negrita) {
            foreach (array(5, 6, 8.25, 11, 14.4, 24) as $pt) {
                $fpdf->SetFont('Arial', $negrita ? 'B' : '', $pt);

                foreach ($textos as $texto) {
                    $this->assertSame(
                        $fpdf->GetStringWidth(utf8_decode($texto)),
                        DisposicionDeEtiquetaIndividual::ancho_de_texto($texto, $pt, $negrita),
                        $texto.' a '.$pt.' pt'.($negrita ? ' en negrita' : '')
                    );
                }
            }
        }
    }

    /**
     * Normalizar junta los espacios y saltos de línea; envolver corta por palabras y una palabra que
     * sola no entra, por caracteres.
     *
     * @test
     */
    public function normaliza_y_envuelve_por_palabras_y_por_caracteres()
    {
        $this->assertSame('ESPATULA 80mm BIASSONI', DisposicionDeEtiquetaIndividual::normalizar("  ESPATULA \n\t 80mm\r\n\r\nBIASSONI  "));
        $this->assertSame('a b', DisposicionDeEtiquetaIndividual::normalizar("\xC2\xA0a\xC2\xA0\xC2\xA0b\xC2\xA0"));
        $this->assertSame('', DisposicionDeEtiquetaIndividual::normalizar(" \n "));
        $this->assertSame('', DisposicionDeEtiquetaIndividual::normalizar(null));

        /*
         * Por palabras: ninguna palabra se corta si entra sola en una línea. A 11 pt, 28 mm son
         * 7215,4 milésimas de letra: "ESPATULA PARA" son 8225 (no entra), "PARA JUNTAS" 6890 (entra),
         * "150MM CONST." 7390 (no), "CONST. EN" 5445 (sí), "SECO BIASSONI" 7836 (no).
         */
        $lineas = DisposicionDeEtiquetaIndividual::envolver('ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI', 11, false, 28);

        $this->assertSame(array('ESPATULA', 'PARA JUNTAS', '150MM', 'CONST. EN', 'SECO', 'BIASSONI'), $lineas);

        foreach ($lineas as $linea) {
            $this->assertLessThanOrEqual(28, DisposicionDeEtiquetaIndividual::ancho_de_texto($linea, 11, false));
        }

        /*
         * Una palabra más ancha que la línea: se corta por caracteres, sin perder ninguno. La W mide
         * 944: entran 7 (6608) y 8 no (7552). Lo que sobra de la palabra sigue juntando palabras.
         */
        $palabra = str_repeat('W', 30);
        $lineas = DisposicionDeEtiquetaIndividual::envolver('AB '.$palabra.' CD', 11, false, 28);

        $this->assertSame(array('AB', 'WWWWWWW', 'WWWWWWW', 'WWWWWWW', 'WWWWWWW', 'WW CD'), $lineas);

        foreach ($lineas as $linea) {
            $this->assertLessThanOrEqual(28, DisposicionDeEtiquetaIndividual::ancho_de_texto($linea, 11, false));
        }

        /*
         * Con puntos: entra en el ancho y no deja un espacio antes de los puntos. A 11 pt, 24 mm
         * son 6184,7: "ESPATULA P..." mide 7003 (no entra), y al sacar la P queda "ESPATULA " que
         * pierde el espacio: "ESPATULA..." mide 6058.
         */
        $recortada = DisposicionDeEtiquetaIndividual::con_puntos('ESPATULA PARA JUNTAS', 11, false, 24);

        $this->assertSame('ESPATULA...', $recortada);
        $this->assertLessThanOrEqual(24, DisposicionDeEtiquetaIndividual::ancho_de_texto($recortada, 11, false));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     *  Aserciones
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * Las invariantes de cualquier disposición.
     *
     * @param  array     $resultado
     * @param  int       $ancho
     * @param  int       $alto
     * @param  string[]  $keys   Las keys pedidas, en orden (todas con texto, y con código).
     * @param  string    $caso   Para el mensaje de error.
     * @return void
     */
    protected function assert_disposicion_valida(array $resultado, $ancho, $alto, array $keys, $caso)
    {
        /* Margen para sumas de floats hechas en otro orden que el del algoritmo. */
        $epsilon = 0.000001;

        $this->assertLessThanOrEqual($alto - 2, $resultado['alto_total'], $caso.': el contenido pasa del alto.');

        /* Nada desaparece sin quedar declarado: lo dibujado más lo omitido son las keys pedidas. */
        $dibujadas = array_column($resultado['bloques'], 'key');
        $this->assertSame($keys, array_merge($dibujadas, array_reverse($resultado['omitidos'])), $caso.': bloques + omitidos.');

        if (!$resultado['ajustado']) {
            $this->assertSame('normal', $resultado['modo'], $caso);
            $this->assertEquals(1, $resultado['factor'], $caso);
        }

        foreach ($resultado['bloques'] as $bloque) {
            $this->assertGreaterThanOrEqual(1 - $epsilon, $bloque['y'], $caso.': '.$bloque['key'].' arranca arriba del margen.');
            $this->assertLessThanOrEqual($alto - 1 + $epsilon, $bloque['y'] + $bloque['alto'], $caso.': '.$bloque['key'].' termina abajo del margen.');

            if ($bloque['tipo'] === 'codigo') {
                $this->assertGreaterThanOrEqual(DisposicionDeEtiquetaIndividual::CODIGO_MINIMO, $bloque['alto'], $caso);
                $this->assertEquals(($ancho - $bloque['ancho']) / 2, $bloque['x'], $caso);
                continue;
            }

            $this->assertGreaterThanOrEqual(DisposicionDeEtiquetaIndividual::FUENTE_MINIMA, $bloque['tamano'], $caso);
            $this->assertGreaterThan(0, count($bloque['lineas']), $caso);

            foreach ($bloque['lineas'] as $linea) {
                $this->assertLessThanOrEqual(
                    $ancho - 2,
                    DisposicionDeEtiquetaIndividual::ancho_de_texto($linea, $bloque['tamano'], $bloque['negrita']),
                    $caso.': la línea "'.$linea.'" no entra en el ancho.'
                );
            }
        }
    }
}
