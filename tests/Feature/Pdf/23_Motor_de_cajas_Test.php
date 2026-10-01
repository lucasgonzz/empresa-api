<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfLayout\CamposDeVentaPdf;
use App\Http\Controllers\Helpers\PdfLayout\FuenteDeCamposPdf;
use App\Http\Controllers\Pdf\Layout\MotorDeCajasPdf;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../app/Http/Controllers/CommonLaravel/fpdf/fpdf.php';

/**
 * Fuente de campos de mentira para el motor: devuelve lo que el test le dio, por key.
 */
class FuenteDePruebaParaElMotor implements FuenteDeCamposPdf
{
    /** @var array<string, mixed> */
    public $valores;

    /** @var array<int, string> Las keys que el motor le pidió, en orden. */
    public $pedidas;

    public function __construct(array $valores)
    {
        $this->valores = $valores;
        $this->pedidas = [];
    }

    public function model_name()
    {
        return 'sale';
    }

    public function valor($key, $campo)
    {
        $this->pedidas[] = $key;

        if ($key === 'texto_libre') {
            return CamposDeVentaPdf::texto_libre($campo);
        }

        return array_key_exists($key, $this->valores) ? $this->valores[$key] : null;
    }
}

/**
 * El motor de cajas del diseño de página (misión diseno-pdf-configurable, 1/10/2026), solo, sin
 * base de datos: la grilla de 12 columnas, el salto de fila, la caja vacía que no se dibuja, el
 * alto parejo de la fila, los tres estilos, el rótulo y el valor, las listas, el corte de líneas,
 * y que MEDIR y DIBUJAR den lo mismo (la razón de ser de que sea una sola rutina).
 *
 * Extiende PHPUnit\Framework\TestCase directamente: el motor no lee ningún modelo (los valores los
 * pone una fuente de mentira) y dibuja sobre un FPDF pelado.
 *
 * @group pdf-diseno-de-pagina
 */
class Motor_de_cajas_Test extends TestCase
{
    /** Puntos por milímetro (la escala de FPDF en mm). */
    const K = 72 / 25.4;

    /**
     * Un FPDF A4 con una hoja abierta y la compresión apagada.
     *
     * @return \FPDF
     */
    private function pdf_nuevo()
    {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetCompression(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        return $pdf;
    }

    /**
     * @param array $valores
     * @param float $x0
     * @param float $ancho
     * @return MotorDeCajasPdf
     */
    private function motor(array $valores, $x0 = 5, $ancho = 200)
    {
        return new MotorDeCajasPdf(new FuenteDePruebaParaElMotor($valores), $x0, $ancho);
    }

    private function campo($key, array $extra = [])
    {
        return array_merge(['key' => $key, 'etiqueta' => null, 'tamano' => null, 'negrita' => null, 'cursiva' => null, 'alineacion' => null], $extra);
    }

    private function caja($id, $cols, array $campos, $titulo = '', $estilo = 'borde')
    {
        return ['tipo' => 'caja', 'id' => $id, 'cols' => $cols, 'titulo' => $titulo, 'estilo' => $estilo, 'campos' => $campos];
    }

    /**
     * Rectángulos dibujados: [x, y, ancho, alto, operador] en mm (FPDF los escribe en puntos y con
     * el origen abajo).
     *
     * @param string $pdf
     * @return array<int, array>
     */
    private function rectangulos($pdf)
    {
        preg_match_all('~([\d.]+) ([\d.]+) ([\d.]+) (-[\d.]+) re (S|B|f)~', $pdf, $m, PREG_SET_ORDER);

        $rects = [];
        foreach ($m as $r) {
            $alto = -((float) $r[4]) / self::K;
            $rects[] = [
                'x' => (float) $r[1] / self::K,
                'y' => 297 - ((float) $r[2] / self::K),
                'ancho' => (float) $r[3] / self::K,
                'alto' => $alto,
                'op' => $r[5],
            ];
        }

        return $rects;
    }

    /**
     * Textos dibujados con su x (mm), sin el escape de FPDF y en UTF-8.
     *
     * @param string $pdf
     * @return array<int, array{x: float, texto: string}>
     */
    private function textos($pdf)
    {
        preg_match_all('~BT ([\d.]+) ([\d.]+) Td \(((?:[^()\\\\]|\\\\.)*)\) Tj ET~s', $pdf, $m, PREG_SET_ORDER);

        $textos = [];
        foreach ($m as $t) {
            $textos[] = [
                'x' => (float) $t[1] / self::K,
                'texto' => utf8_encode(preg_replace('~\\\\(.)~s', '$1', $t[3])),
            ];
        }

        return $textos;
    }

    private function solo_textos($pdf)
    {
        $textos = [];
        foreach ($this->textos($pdf) as $t) {
            $textos[] = $t['texto'];
        }

        return $textos;
    }

    /**
     * @test
     */
    public function las_cajas_fluyen_en_filas_de_doce_columnas_y_bajan_si_no_entran()
    {
        $motor = $this->motor([]);

        $filas = $motor->filas([
            $this->caja('a', 6, []),
            $this->caja('b', 4, []),
            $this->caja('c', 4, []),
            ['tipo' => 'salto_de_fila', 'id' => 'salto'],
            $this->caja('d', 12, []),
            ['tipo' => 'fijo', 'key' => 'afip_pie', 'importes' => true],
            $this->caja('e', 3, []),
        ]);

        $this->assertCount(5, $filas, 'a+b | c (no entraba) | d | el fijo | e');
        $this->assertSame(['a', 'b'], array_column(array_column($filas[0]['cajas'], 'caja'), 'id'));
        $this->assertSame(['c'], array_column(array_column($filas[1]['cajas'], 'caja'), 'id'), 'La caja que no entra en lo que queda de la fila baja a la siguiente.');
        $this->assertSame('fijo', $filas[3]['tipo'], 'Un bloque fijo ocupa una fila entera.');

        /** U = (200 + 2) / 12; una caja de N columnas mide N·U − 2 y arranca en x0 + columna·U. */
        $unidad = 202 / 12;
        $this->assertEqualsWithDelta(5, $filas[0]['cajas'][0]['x'], 0.001);
        $this->assertEqualsWithDelta(6 * $unidad - 2, $filas[0]['cajas'][0]['ancho'], 0.001);
        $this->assertEqualsWithDelta(5 + 6 * $unidad, $filas[0]['cajas'][1]['x'], 0.001);
        $this->assertEqualsWithDelta(4 * $unidad - 2, $filas[0]['cajas'][1]['ancho'], 0.001);
        $this->assertEqualsWithDelta(5, $filas[1]['cajas'][0]['x'], 0.001, 'La caja que bajó arranca en la columna 0.');
        $this->assertEqualsWithDelta(200, $filas[2]['cajas'][0]['ancho'], 0.001, 'Una caja de 12 columnas mide el ancho útil entero.');
    }

    /**
     * @test
     */
    public function un_salto_de_fila_corta_la_fila_aunque_quede_lugar()
    {
        $filas = $this->motor([])->filas([
            $this->caja('a', 3, []),
            ['tipo' => 'salto_de_fila', 'id' => 'salto'],
            $this->caja('b', 3, []),
        ]);

        $this->assertCount(2, $filas);
        $this->assertEqualsWithDelta(5, $filas[1]['cajas'][0]['x'], 0.001);
    }

    /**
     * 🔴 Una caja sin ningún campo con valor no se dibuja (ni su título), y sus columnas quedan
     * vacías: la caja de al lado NO se corre (la de cuenta corriente en una venta de contado).
     *
     * @test
     */
    public function una_caja_sin_valores_no_se_dibuja_y_no_corre_a_las_demas()
    {
        $pdf = $this->pdf_nuevo();
        $motor = $this->motor(['cliente_nombre' => 'Juan']);

        $motor->dibujar_zona($pdf, [
            $this->caja('vacia', 6, [$this->campo('cc_saldo')], 'Cuenta corriente'),
            $this->caja('llena', 6, [$this->campo('cliente_nombre')]),
        ], 20);
        $salida = $pdf->Output('S');

        $rects = $this->rectangulos($salida);
        $this->assertCount(1, $rects, 'Solo se dibuja la caja con valores.');
        $this->assertEqualsWithDelta(5 + 6 * (202 / 12), $rects[0]['x'], 0.01, 'La caja con valores quedó en su columna (no se corrió a la izquierda).');
        $this->assertNotContains('Cuenta corriente', $this->solo_textos($salida), 'El título de una caja vacía no se imprime.');
    }

    /**
     * Una fila sin nada dibujado no ocupa alto: ni la fila ni la separación.
     *
     * @test
     */
    public function una_fila_sin_nada_dibujado_no_ocupa_alto()
    {
        $motor = $this->motor(['cliente_nombre' => 'Juan']);
        $pdf = $this->pdf_nuevo();

        $con_fila_vacia = $motor->medir_zona($pdf, [
            $this->caja('vacia', 12, [$this->campo('cc_saldo')]),
            $this->caja('llena', 12, [$this->campo('cliente_nombre')]),
        ]);
        $sola = $motor->medir_zona($pdf, [
            $this->caja('llena', 12, [$this->campo('cliente_nombre')]),
        ]);

        $this->assertEqualsWithDelta($sola, $con_fila_vacia, 0.0001);
        $this->assertSame(0.0, (float) $motor->medir_zona($pdf, [$this->caja('vacia', 12, [$this->campo('cc_saldo')])]));
    }

    /**
     * Todas las cajas de una fila se dibujan con el alto de la más alta (bordes parejos).
     *
     * @test
     */
    public function las_cajas_de_una_fila_tienen_el_alto_de_la_mas_alta()
    {
        $pdf = $this->pdf_nuevo();
        $motor = $this->motor([
            'cliente_nombre' => 'Juan',
            'cliente_telefono' => '1155',
            'cliente_email' => 'juan@correo',
            'cliente_direccion' => 'Mitre 1',
        ]);

        $motor->dibujar_zona($pdf, [
            $this->caja('baja', 6, [$this->campo('cliente_nombre')]),
            $this->caja('alta', 6, [$this->campo('cliente_telefono'), $this->campo('cliente_email'), $this->campo('cliente_direccion')]),
        ], 20);

        $rects = $this->rectangulos($pdf->Output('S'));
        $this->assertCount(2, $rects);
        $this->assertEqualsWithDelta($rects[0]['alto'], $rects[1]['alto'], 0.01, 'Las dos cajas de la fila tienen que tener el mismo alto.');

        /** El alto es el de la más alta: 1,5 + 3 renglones de 4,5 + 1,5. */
        $this->assertEqualsWithDelta(1.5 + 3 * 4.5 + 1.5, $rects[0]['alto'], 0.01);
    }

    /**
     * borde = línea negra; gris = fondo 247 con borde 210; ninguno = sin recuadro.
     *
     * @test
     */
    public function los_tres_estilos_de_caja()
    {
        foreach (['borde' => 'S', 'gris' => 'B'] as $estilo => $operador) {
            $pdf = $this->pdf_nuevo();
            $this->motor(['cliente_nombre' => 'Juan'])->dibujar_zona($pdf, [
                $this->caja('una', 12, [$this->campo('cliente_nombre')], '', $estilo),
            ], 20);
            $salida = $pdf->Output('S');

            $rects = $this->rectangulos($salida);
            $this->assertCount(1, $rects, 'El estilo '.$estilo.' dibuja un recuadro.');
            $this->assertSame($operador, $rects[0]['op'], 'El estilo '.$estilo.' dibuja con '.$operador.'.');

            if ($estilo === 'gris') {
                /** FPDF escribe un gris (r = g = b) distinto de negro como color RGB. */
                $this->assertTrue(strpos($salida, '0.969 0.969 0.969 rg') !== false, 'El fondo de la caja gris es 247.');
                $this->assertTrue(strpos($salida, '0.824 0.824 0.824 RG') !== false, 'El borde de la caja gris es 210.');
            }
        }

        $pdf = $this->pdf_nuevo();
        $this->motor(['cliente_nombre' => 'Juan'])->dibujar_zona($pdf, [
            $this->caja('una', 12, [$this->campo('cliente_nombre')], '', 'ninguno'),
        ], 20);
        $salida = $pdf->Output('S');
        $this->assertCount(0, $this->rectangulos($salida), 'El estilo ninguno no dibuja recuadro.');
        $this->assertContains('Juan', $this->solo_textos($salida), 'Sin recuadro el campo se imprime igual.');
    }

    /**
     * 🔴 Medir y dibujar dan el MISMO alto, con títulos, listas, cortes de línea, varias filas y
     * separaciones: es la razón de que el motor sea una sola rutina.
     *
     * @test
     */
    public function medir_y_dibujar_dan_el_mismo_alto()
    {
        $valores = [
            'cliente_nombre' => 'Juan Perez',
            'cliente_observaciones' => str_repeat('Una observación larga que va a ocupar varias líneas. ', 8),
            'venta_metodos_de_pago' => ['Efectivo: $10.000', 'Débito: $2.500', 'Transferencia: $1'],
            'tot_total' => '$12.500',
        ];
        $items = [
            $this->caja('a', 4, [$this->campo('cliente_nombre'), $this->campo('cliente_observaciones')], 'Un título bastante largo para una caja angosta'),
            $this->caja('b', 8, [$this->campo('venta_metodos_de_pago')], '', 'gris'),
            ['tipo' => 'salto_de_fila', 'id' => 's'],
            $this->caja('c', 12, [$this->campo('tot_total', ['tamano' => 16])], '', 'ninguno'),
        ];

        $pdf = $this->pdf_nuevo();
        $motor = $this->motor($valores);
        $medido = $motor->medir_zona($pdf, $items);
        $dibujado = $motor->dibujar_zona($pdf, $items, 30);

        $this->assertGreaterThan(30, $medido, 'La zona de prueba tiene que ser alta (cortes de línea de verdad).');
        $this->assertEqualsWithDelta($medido, $dibujado, 0.0001);
        $this->assertEqualsWithDelta(30 + $medido, $pdf->y, 0.0001, 'dibujar_zona() deja la y debajo de lo dibujado.');
    }

    /**
     * Rótulo y valor: a la izquierda dos textos (rótulo en negrita, valor normal, como el bloque
     * del cliente de siempre); a la derecha el renglón entero en un texto; con etiqueta '' solo el
     * valor; con una etiqueta propia, esa.
     *
     * @test
     */
    public function el_rotulo_y_el_valor_segun_la_alineacion_y_la_etiqueta()
    {
        $pdf = $this->pdf_nuevo();
        $this->motor([
            'cliente_nombre' => 'Juan Perez',
            'tot_total' => '$12.500',
            'cliente_telefono' => '1155',
            'cliente_email' => 'juan@correo',
        ])->dibujar_zona($pdf, [
            $this->caja('a', 12, [
                $this->campo('cliente_nombre'),
                $this->campo('tot_total'),
                $this->campo('cliente_telefono', ['etiqueta' => '']),
                $this->campo('cliente_email', ['etiqueta' => 'Correo']),
            ]),
        ], 20);
        $textos = $this->solo_textos($pdf->Output('S'));

        $i = array_search('Cliente: ', $textos, true);
        $this->assertNotFalse($i, 'Falta el rótulo del catálogo.');
        $this->assertSame('Juan Perez', $textos[$i + 1], 'El valor va pegado al rótulo, en su propio texto.');
        $this->assertContains('Total: $12.500', $textos, 'A la derecha el renglón va entero, como los totales de siempre.');
        $this->assertContains('1155', $textos);
        $this->assertNotContains('Teléfono: ', $textos, 'Con etiqueta vacía no hay rótulo.');
        $j = array_search('Correo: ', $textos, true);
        $this->assertNotFalse($j, 'La etiqueta propia reemplaza a la del catálogo.');
        $this->assertSame('juan@correo', $textos[$j + 1]);
    }

    /**
     * Un campo sin valor no imprime nada, ni el rótulo; una key que el catálogo no conoce se
     * saltea sin error (y a la fuente ni se le pregunta).
     *
     * @test
     */
    public function un_campo_sin_valor_o_desconocido_no_imprime_nada()
    {
        $pdf = $this->pdf_nuevo();
        $fuente = new FuenteDePruebaParaElMotor(['cliente_nombre' => 'Juan', 'cliente_telefono' => '   ']);
        $motor = new MotorDeCajasPdf($fuente, 5, 200);

        $motor->dibujar_zona($pdf, [
            $this->caja('a', 12, [
                $this->campo('cliente_nombre'),
                $this->campo('cliente_telefono'),
                $this->campo('cliente_email'),
                $this->campo('una_key_que_no_existe'),
            ]),
        ], 20);
        $textos = $this->solo_textos($pdf->Output('S'));

        $this->assertNotContains('Teléfono: ', $textos, 'Un valor en blanco no imprime su rótulo.');
        $this->assertNotContains('Email: ', $textos, 'Un campo sin valor no imprime su rótulo.');
        $this->assertNotContains('una_key_que_no_existe', $fuente->pedidas, 'A la fuente no se le pide una key fuera del catálogo.');
        $this->assertContains('Juan', $textos);
    }

    /**
     * Una lista: un renglón por elemento, el rótulo solo en el primero.
     *
     * @test
     */
    public function una_lista_va_un_renglon_por_elemento_con_el_rotulo_solo_en_el_primero()
    {
        $pdf = $this->pdf_nuevo();
        $this->motor([
            'venta_metodos_de_pago' => ['Efectivo: $10.000', 'Débito: $2.500'],
            'tot_descuentos' => ['Menos $1.390 (10% Efectivo) = $12.510', 'Menos $10 (1% Otro) = $12.500'],
        ])->dibujar_zona($pdf, [
            $this->caja('a', 12, [$this->campo('venta_metodos_de_pago'), $this->campo('tot_descuentos')]),
        ], 20);
        $textos = $this->solo_textos($pdf->Output('S'));

        $this->assertSame(1, count(array_keys($textos, 'Métodos de pago: ', true)), 'El rótulo va una sola vez.');
        $i = array_search('Métodos de pago: ', $textos, true);
        $this->assertSame('Efectivo: $10.000', $textos[$i + 1]);
        $this->assertSame('Débito: $2.500', $textos[$i + 2], 'El segundo elemento va en su propio renglón.');
        $this->assertContains('Menos $1.390 (10% Efectivo) = $12.510', $textos, 'Los descuentos van enteros, tal cual el PDF de siempre.');
        $this->assertContains('Menos $10 (1% Otro) = $12.500', $textos);
    }

    /**
     * Un valor largo corta en varias líneas, todas adentro de la caja (las siguientes arrancan en
     * el borde de la caja, como print_label_value_multiline()).
     *
     * @test
     */
    public function un_valor_largo_corta_en_varias_lineas_dentro_de_la_caja()
    {
        $largo = 'Entregar por la tarde, tocar el timbre del fondo, dejar con el encargado del edificio si no hay nadie';
        $pdf = $this->pdf_nuevo();
        $this->motor(['venta_observaciones' => $largo])->dibujar_zona($pdf, [
            $this->caja('a', 4, [$this->campo('venta_observaciones')]),
        ], 20);
        $salida = $pdf->Output('S');

        $textos = $this->textos($salida);
        $this->assertGreaterThan(3, count($textos), 'En una caja de 4 columnas el texto ocupa varias líneas.');

        $caja = $this->rectangulos($salida)[0];
        $palabras = [];
        foreach ($textos as $t) {
            $this->assertGreaterThanOrEqual($caja['x'], $t['x'], 'Un renglón arrancó afuera de la caja.');
            /** El rótulo va en negrita; el valor, normal (los anchos con las mismas métricas que el motor). */
            $estilo = $t['texto'] === 'Observaciones: ' ? 'B' : '';
            $this->assertLessThan($caja['x'] + $caja['ancho'], $t['x'] + MotorDeCajasPdf::ancho_de_texto($t['texto'], $estilo, 9), 'Un renglón se salió de la caja: '.$t['texto']);
            $palabras[] = trim($t['texto']);
        }

        /** No se pierde ni se repite ninguna palabra al cortar. */
        $this->assertSame('Observaciones: '.$largo, preg_replace('/\s+/', ' ', implode(' ', $palabras)));
    }

    /**
     * El tamaño, la negrita y la cursiva del campo, y el alto de línea (tamaño × 0,5).
     *
     * @test
     */
    public function el_tamano_la_negrita_y_la_cursiva_del_campo()
    {
        $pdf = $this->pdf_nuevo();
        $motor = $this->motor(['tot_total' => '$12.500']);

        $alto = $motor->medir_zona($pdf, [
            $this->caja('a', 12, [$this->campo('tot_total', ['tamano' => 20, 'negrita' => false, 'cursiva' => true])]),
        ]);
        $this->assertEqualsWithDelta(1.5 + 20 * 0.5 + 1.5, $alto, 0.0001, 'Alto de línea = tamaño × 0,5.');

        $motor->dibujar_zona($pdf, [
            $this->caja('a', 12, [$this->campo('tot_total', ['tamano' => 20, 'negrita' => false, 'cursiva' => true])]),
        ], 20);
        $salida = $pdf->Output('S');

        $posicion = strpos($salida, '(Total: $12.500) Tj');
        $this->assertNotFalse($posicion);
        preg_match_all('~/F(\d+) ([\d.]+) Tf~', substr($salida, 0, $posicion), $tf, PREG_SET_ORDER);
        $ultimo = end($tf);
        $this->assertSame('20.00', $ultimo[2], 'El texto se dibuja con el tamaño del campo.');
        $this->assertSame(1, preg_match('~/F'.$ultimo[1].' (\d+) 0 R~', $salida, $objeto));
        $this->assertSame(1, preg_match('~\n'.$objeto[1].' 0 obj\s*<</Type /Font\s*/BaseFont /Helvetica-Oblique~', $salida), 'Cursiva sin negrita: Helvetica-Oblique.');
    }
}

