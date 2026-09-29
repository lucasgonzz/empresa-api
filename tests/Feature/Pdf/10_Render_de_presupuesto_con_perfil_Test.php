<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Models\Discount;
use App\Models\Surchage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;

/**
 * El PDF del presupuesto dibujado con un diseño (`ProfileDocumentPdf` + `BudgetPdfDocument`)
 * (misión pdf-presupuestos-y-pedidos-personalizables, 29/9/2026).
 *
 * Ningún test emite el PDF: `render()` + `Output('S')` con la compresión apagada, para poder
 * buscar el texto dentro del binario. Emitir hace `Output(); exit;` y se lleva puesto a PHPUnit.
 *
 * Lo que más importa acá es la PLATA: los renglones de la caja de totales son una copia fiel de
 * `BudgetPdf` y el Total sale de `BudgetHelper::getTotal()`. Los tests comparan contra esa
 * función y no contra un número escrito a mano solamente: si el PDF y el resto del sistema
 * dejaran de coincidir, el cliente firma un presupuesto cuyo total no es el que se guardó.
 *
 * @group pdf-documentos
 */
class Render_de_presupuesto_con_perfil_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;

    /** Empleado que cargó el presupuesto (el encabezado lo imprime). */
    const VENDEDOR = 'Vendedora Test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf();
    }

    protected function tearDown(): void
    {
        $this->borrar_archivos_de_prueba();

        parent::tearDown();
    }

    /**
     * Presupuesto de plata conocida: 2 x $1.000 con 10% de bonificación ($1.800) y 1 x $500,
     * o sea $2.300 de renglones. Con un descuento del 10% y un recargo del 5% el Total es
     * $2.173,50.
     *
     * @param array $atributos Atributos del presupuesto que pisan los de por defecto.
     * @param bool  $con_ajustes Si tiene el descuento y el recargo.
     * @return \App\Models\Budget
     */
    protected function presupuesto_con_plata($atributos = [], $con_ajustes = true)
    {
        $empleado = User::create([
            'name'     => self::VENDEDOR,
            'email'    => 'pdf-vendedora-'.uniqid().'@test.local',
            'password' => 'x',
            'owner_id' => $this->dueno->id,
        ]);

        $taladro = $this->crear_articulo('Taladro percutor 13mm', ['bar_code' => '7791111111111']);
        $mecha = $this->crear_articulo('Mecha widia 8mm', ['bar_code' => '7792222222222']);

        $budget = $this->crear_presupuesto([
            ['article' => $taladro, 'amount' => 2, 'price' => 1000, 'bonus' => 10],
            ['article' => $mecha, 'amount' => 1, 'price' => 500, 'bonus' => null],
        ], array_merge([
            'employee_id'  => $empleado->id,
            'observations' => 'Entrega en 48 horas habiles',
            'total'        => 2173.5,
        ], $atributos));

        if ($con_ajustes) {
            $descuento = Discount::create(['name' => 'Descuento por volumen', 'percentage' => 10, 'user_id' => $this->dueno->id]);
            $recargo = Surchage::create(['name' => 'Recargo financiero', 'percentage' => 5, 'user_id' => $this->dueno->id]);

            $budget->discounts()->attach($descuento->id, ['percentage' => 10]);
            $budget->surchages()->attach($recargo->id, ['percentage' => 5]);
        }

        return $budget->fresh();
    }

    /**
     * @param \App\Models\Budget $budget
     * @param string             $nombre_del_diseno
     * @return string
     */
    protected function pdf_de($budget, $nombre_del_diseno)
    {
        return $this->renderizar(new BudgetPdfDocument($budget), $this->diseno('budget', $nombre_del_diseno));
    }

    /**
     * @test
     */
    public function los_tres_disenos_generan_un_pdf_valido_que_dice_presupuesto()
    {
        $budget = $this->presupuesto_con_plata();

        foreach (['Presupuesto', 'Presupuesto sin precios', 'Presupuesto con imágenes'] as $nombre) {
            $pdf = $this->pdf_de($budget, $nombre);

            $this->assertSame(0, strpos($pdf, '%PDF'), '"'.$nombre.'" no genero un PDF.');
            $this->assertSame("%%EOF\n", substr($pdf, -6), '"'.$nombre.'" no cierra el PDF.');

            /** El título del comprobante va a la derecha del encabezado; sin él no se sabe qué es. */
            $this->assertPdfContiene('(Presupuesto)', $pdf, '"'.$nombre.'" no dice "Presupuesto" en el encabezado.');
            $this->assertPdfContiene('(Taladro percutor 13mm)', $pdf, '"'.$nombre.'" no imprimio los renglones.');
            $this->assertPdfContiene('(Mecha widia 8mm)', $pdf);
        }
    }

    /**
     * El encabezado es el del remito: el cliente, sus observaciones y el vendedor (el `BudgetPdf` de
     * antes lo imprimía y el diseño por defecto lo conserva).
     *
     * @test
     */
    public function el_encabezado_trae_al_cliente_sus_observaciones_y_al_vendedor()
    {
        $pdf = $this->pdf_de($this->presupuesto_con_plata(), 'Presupuesto');

        $this->assertPdfContiene('Cliente Presupuesto Test', $pdf);
        $this->assertPdfContiene('20222222229', $pdf, 'Falta el CUIT del cliente.');
        $this->assertPdfContiene('(Observaciones: Paga a 30 dias)', $pdf, 'Falta la descripcion del cliente.');
        $this->assertPdfContiene(self::VENDEDOR, $pdf, 'Falta el vendedor en el encabezado del cliente.');
        /**
         * El rótulo es el de siempre: "Vendedor:". El campo `empleado` del encabezado del remito
         * rotula "Empleado:", que el cliente final nunca leyó en un presupuesto.
         */
        $this->assertPdfContiene('Vendedor:', $pdf, 'El empleado del presupuesto tiene que rotularse "Vendedor:".');
        $this->assertPdfNoContiene('Empleado:', $pdf, 'No debe aparecer el rotulo "Empleado:" en el presupuesto.');
        $this->assertPdfContiene('Razon Social PDF SA', $pdf, 'Falta la razon social del emisor.');
    }

    /**
     * Las observaciones del presupuesto van en el recuadro gris del pie, como las del remito.
     *
     * @test
     */
    public function las_observaciones_del_presupuesto_van_en_su_recuadro()
    {
        $pdf = $this->pdf_de($this->presupuesto_con_plata(), 'Presupuesto');

        $this->assertPdfContiene('(OBSERVACIONES)', $pdf);
        $this->assertPdfContiene('Entrega en 48 horas habiles', $pdf);

        $sin = $this->pdf_de($this->presupuesto_con_plata(['observations' => null]), 'Presupuesto');
        $this->assertPdfNoContiene('(OBSERVACIONES)', $sin, 'Sin observaciones no se dibuja el recuadro.');
    }

    /**
     * 🔴 La plata. Sub Total, descuento, recargo y Total, contra `BudgetHelper::getTotal()`.
     *
     * @test
     */
    public function los_totales_coinciden_con_get_total()
    {
        $budget = $this->presupuesto_con_plata();

        $total_del_sistema = BudgetHelper::getTotal($budget);
        $this->assertEqualsWithDelta(2173.5, $total_del_sistema, 0.001, 'El fixture no da la plata que este test supone.');

        $pdf = $this->pdf_de($budget, 'Presupuesto');

        $this->assertPdfContiene('(Sub Total sin descuentos: $2.300)', $pdf);
        $this->assertPdfContiene('(- 10% Descuento por volumen)', $pdf);
        $this->assertPdfContiene('(+ 5% Recargo financiero)', $pdf);
        $this->assertPdfContiene('(Total: $'.Numbers::price($total_del_sistema).')', $pdf);
        $this->assertPdfContiene('(Total: $2.173,50)', $pdf);

        /** Y los renglones: 2 x 1000 con 10% de bonificacion = 1800, y el otro 500. */
        $this->assertPdfContiene('($1.800)', $pdf);
        $this->assertPdfContiene('($500)', $pdf);
        $this->assertPdfContiene('(10%)', $pdf, 'Falta la bonificacion del renglon.');
    }

    /**
     * Sin descuentos ni recargos que separen el subtotal del total, no se imprime el Sub Total.
     *
     * @test
     */
    public function sin_descuentos_ni_recargos_no_hay_sub_total()
    {
        $pdf = $this->pdf_de($this->presupuesto_con_plata(['total' => 2300], false), 'Presupuesto');

        $this->assertPdfNoContiene('Sub Total sin descuentos', $pdf);
        $this->assertPdfContiene('(Total: $2.300)', $pdf);
    }

    /**
     * El total forzado: el ajuste se imprime con su signo entre el recargo y el Total, y el Total
     * es el forzado.
     *
     * @test
     */
    public function el_ajuste_del_total_forzado_se_imprime_y_el_total_es_el_forzado()
    {
        $budget = $this->presupuesto_con_plata(['total' => 2000, 'forzar_total_monto' => -173.5]);

        $this->assertEqualsWithDelta(2000, BudgetHelper::getTotal($budget), 0.001);

        $pdf = $this->pdf_de($budget, 'Presupuesto');

        $this->assertPdfContiene('(- $173,50 Ajuste del total)', $pdf);
        $this->assertPdfContiene('(Total: $2.000)', $pdf);
        $this->assertPdfContiene('(Sub Total sin descuentos: $2.300)', $pdf);

        /** Forzado HACIA ARRIBA: el subtotal queda menor que el total y el renglón se imprime igual. */
        $arriba = $this->presupuesto_con_plata(['total' => 2400, 'forzar_total_monto' => 226.5]);
        $pdf_arriba = $this->pdf_de($arriba, 'Presupuesto');

        $this->assertPdfContiene('(+ $226,50 Ajuste del total)', $pdf_arriba);
        $this->assertPdfContiene('(Total: $2.400)', $pdf_arriba);
    }

    /**
     * Con `aplicar_recargos_directo_a_items` el recargo YA ESTÁ en el precio de cada renglón:
     * listarlo en el pie se lee como que se suma dos veces. Los descuentos sí se listan.
     *
     * @test
     */
    public function con_recargo_directo_a_items_el_recargo_no_se_lista_y_el_total_no_lo_suma()
    {
        $budget = $this->presupuesto_con_plata(['aplicar_recargos_directo_a_items' => 1, 'total' => 2070]);

        $this->assertEqualsWithDelta(2070, BudgetHelper::getTotal($budget), 0.001);

        $pdf = $this->pdf_de($budget, 'Presupuesto');

        $this->assertPdfNoContiene('Recargo financiero', $pdf);
        $this->assertPdfContiene('(- 10% Descuento por volumen)', $pdf, 'Los descuentos se siguen listando.');
        $this->assertPdfContiene('(Total: $2.070)', $pdf);

        /** El control: sin la opcion, el mismo presupuesto SI lista el recargo. */
        $sin_opcion = $this->pdf_de($this->presupuesto_con_plata(), 'Presupuesto');
        $this->assertPdfContiene('Recargo financiero', $sin_opcion);
    }

    /**
     * 🔴 "Sin precios": ningún importe en todo el PDF, ni columnas de plata ni caja de totales.
     *
     * @test
     */
    public function el_diseno_sin_precios_no_imprime_ningun_importe()
    {
        $budget = $this->presupuesto_con_plata();

        $pdf = $this->pdf_de($budget, 'Presupuesto sin precios');

        $this->assertPdfSinImportes($pdf, 'El diseno sin precios imprimio un importe.');
        foreach (['Total:', 'Sub Total', 'Descuento por volumen', 'Recargo financiero', 'Ajuste del total'] as $texto) {
            $this->assertPdfNoContiene($texto, $pdf, 'El diseno sin precios imprimio "'.$texto.'".');
        }
        foreach (['(Precio)', '(Bonif)', '(Sub total)'] as $encabezado) {
            $this->assertPdfNoContiene($encabezado, $pdf, 'El diseno sin precios tiene la columna '.$encabezado);
        }

        /** Lo que SÍ lleva: código, nombre, cantidad, y las observaciones. */
        $this->assertPdfContiene('(7791111111111)', $pdf);
        $this->assertPdfContiene('(Taladro percutor 13mm)', $pdf);
        $this->assertPdfContiene('(Cant)', $pdf);
        $this->assertPdfContiene('Entrega en 48 horas habiles', $pdf);

        /** El control: el mismo presupuesto con el diseño con precios SÍ los imprime (si no, el test no prueba nada). */
        $con_precios = $this->pdf_de($budget, 'Presupuesto');
        $this->assertNotEmpty($this->textos_con_importe($con_precios), 'El diseno con precios tiene que imprimir importes.');
        $this->assertPdfContiene('(Precio)', $con_precios);
    }

    /**
     * Muchos renglones: más de una hoja, el encabezado de la tabla se repite en cada hoja y la
     * caja de totales aparece UNA sola vez, en la última.
     *
     * @test
     */
    public function muchos_renglones_generan_varias_hojas_con_los_totales_solo_en_la_ultima()
    {
        $renglones = [];
        for ($i = 1; $i <= 70; $i++) {
            $renglones[] = ['article' => $this->crear_articulo('Articulo de relleno '.$i), 'amount' => 1, 'price' => 100 + $i];
        }

        $budget = $this->crear_presupuesto($renglones, ['total' => 7000 + 2485, 'observations' => 'Nota al pie']);

        $pdf = $this->pdf_de($budget, 'Presupuesto');

        $hojas = $this->cantidad_de_hojas($pdf);
        $this->assertGreaterThanOrEqual(2, $hojas, '70 renglones tienen que ocupar mas de una hoja.');

        /** El encabezado gris de la tabla se repite en TODAS las hojas. */
        $this->assertSame($hojas, substr_count($pdf, '(Sub total)'), 'El encabezado de la tabla no se repite en cada hoja.');
        $this->assertSame($hojas, substr_count($pdf, '(Presupuesto)'), 'El titulo del encabezado no se repite en cada hoja.');

        /** Los totales, UNA vez y en la última. */
        $por_hoja = $this->hojas($pdf);
        $this->assertSame(1, substr_count($pdf, '(Total: $'), 'La caja de totales se dibujo mas de una vez.');
        $this->assertPdfContiene('(Total: $', end($por_hoja), 'La caja de totales tiene que estar en la ultima hoja.');
        $this->assertPdfContiene('(Nota al pie)', end($por_hoja));

        /** Y ningún renglón se perdió en el salto de hoja. */
        $this->assertPdfContiene('(Articulo de relleno 1)', $pdf);
        $this->assertPdfContiene('(Articulo de relleno 70)', $pdf);
    }

    /**
     * 🔴 El bloque final (totales + observaciones) NUNCA se sale de la hoja. Se barre la cantidad
     * de renglones para pasar por todos los casos: cabe debajo del último renglón, no cabe y salta
     * a la hoja siguiente, y los renglones mismos desbordan. En cada uno, los recuadros de 200 mm
     * (totales y observaciones) tienen que quedar adentro del papel.
     *
     * @test
     */
    public function el_bloque_final_nunca_se_sale_de_la_hoja()
    {
        $this->barrer_el_bloque_final(18, 48, 2);
    }

    /**
     * El mismo barrido con el LOGO del negocio (35 mm): el encabezado es mucho más alto que sin
     * logo, y los umbrales de salto de hoja están en milímetros absolutos, no relativos al
     * encabezado. Es el caso de la mayoría de los clientes reales.
     *
     * @test
     */
    public function con_logo_el_bloque_final_tampoco_se_sale_de_la_hoja()
    {
        $this->dueno->image_url = $this->crear_jpg_de_prueba(200, 200);
        $this->dueno->save();

        $this->barrer_el_bloque_final(10, 40, 3);
    }

    /**
     * Renderiza presupuestos de `$desde` a `$hasta` renglones (de a `$paso`) con observaciones
     * largas y verifica, en cada uno, que la caja de totales sale UNA vez y en la última hoja y que
     * ningún recuadro de 200 mm se sale del papel. Exige que el barrido pase por al menos un caso
     * en que el bloque final salta de hoja (si no, el rango no está probando lo que dice).
     *
     * @param int $desde
     * @param int $hasta
     * @param int $paso
     * @return void
     */
    protected function barrer_el_bloque_final($desde, $hasta, $paso)
    {
        $articulos = [];
        for ($i = 1; $i <= $hasta; $i++) {
            $articulos[] = $this->crear_articulo('Barrido '.$i);
        }

        $saltos_de_hoja_por_el_bloque = 0;

        for ($cantidad = $desde; $cantidad <= $hasta; $cantidad += $paso) {
            $renglones = [];
            for ($i = 0; $i < $cantidad; $i++) {
                $renglones[] = ['article' => $articulos[$i], 'amount' => 1, 'price' => 1000];
            }

            $budget = $this->crear_presupuesto($renglones, [
                'total'        => 1000 * $cantidad,
                'observations' => str_repeat('Una observacion bastante larga para ocupar varias lineas. ', 6),
            ]);

            $pdf = $this->pdf_de($budget, 'Presupuesto');
            $por_hoja = $this->hojas($pdf);

            $this->assertSame(1, substr_count($pdf, '(Total: $'), 'Con '.$cantidad.' renglones la caja de totales no salio exactamente una vez.');
            $this->assertPdfContiene('(Total: $', end($por_hoja), 'Con '.$cantidad.' renglones el total no quedo en la ultima hoja.');

            /** Los rectángulos de 200 mm: `x y ancho -alto re B`. Abajo del papel, el borde inferior <= 287 mm. */
            $k = 72 / 25.4;
            preg_match_all('~[\d.]+ ([\d.]+) 566\.93 (-[\d.]+) re B~', $pdf, $rects, PREG_SET_ORDER);
            $this->assertNotEmpty($rects, 'Con '.$cantidad.' renglones no se dibujo ningun recuadro.');

            foreach ($rects as $rect) {
                $borde_inferior_mm = 297 - (((float) $rect[1] + (float) $rect[2]) / $k);
                $this->assertLessThanOrEqual(
                    287,
                    $borde_inferior_mm,
                    'Con '.$cantidad.' renglones un recuadro del pie se sale de la hoja (borde inferior a '.round($borde_inferior_mm, 1).' mm).'
                );
            }

            /** El caso que interesa: la última hoja NO tiene ningún renglón, o sea que existe solo porque el bloque final no entraba. */
            if (count($por_hoja) > 1 && strpos(end($por_hoja), '(Barrido ') === false) {
                $saltos_de_hoja_por_el_bloque++;
            }
        }

        $this->assertGreaterThan(0, $saltos_de_hoja_por_el_bloque, 'El barrido no paso por el caso "el bloque final salta de hoja": ajustar el rango.');
    }

    /**
     * Con imágenes: la imagen se dibuja respetando su proporción, una imagen ROTA (archivo que no es
     * una imagen) y una que no se puede bajar NO abortan el PDF, y sin la columna no se dibuja nada.
     *
     * @test
     */
    public function con_imagenes_dibuja_la_imagen_y_una_imagen_rota_no_aborta_el_pdf()
    {
        Http::fake(['*' => Http::response('', 404)]);

        $con_foto = $this->crear_articulo('Articulo con foto');
        $con_foto_rota = $this->crear_articulo('Articulo con foto rota');
        $con_foto_remota = $this->crear_articulo('Articulo con foto que no baja');
        $sin_foto = $this->crear_articulo('Articulo sin foto');

        $this->colgar_imagen_jpg($con_foto, 60, 40);
        $this->colgar_imagen_rota($con_foto_rota);
        \App\Models\Image::create([
            'hosting_url'    => 'https://cdn-ajeno.example.com/fotos/no-baja.jpg',
            'imageable_id'   => $con_foto_remota->id,
            'imageable_type' => 'article',
        ]);

        $budget = $this->crear_presupuesto([
            ['article' => $con_foto, 'amount' => 1, 'price' => 100],
            ['article' => $con_foto_rota, 'amount' => 1, 'price' => 200],
            ['article' => $con_foto_remota, 'amount' => 1, 'price' => 300],
            ['article' => $sin_foto, 'amount' => 1, 'price' => 400],
        ], ['total' => 1000]);

        $pdf = $this->pdf_de($budget, 'Presupuesto con imágenes');

        $this->assertSame(0, strpos($pdf, '%PDF'), 'Una imagen rota abortaria el PDF entero.');
        $this->assertSame(1, substr_count($pdf, '/Subtype /Image'), 'Tiene que dibujarse la unica imagen que es valida.');
        $this->assertPdfContiene('(Articulo con foto rota)', $pdf, 'El renglon con la imagen rota se imprime igual.');
        $this->assertPdfContiene('(Articulo sin foto)', $pdf);
        $this->assertPdfContiene('(Total: $1.000)', $pdf);

        /**
         * La proporción de la imagen (60 x 40 = 1,5): `q ancho 0 0 alto x y cm /I1 Do Q`. Si se
         * estirara a un cuadrado, la relación sería 1.
         */
        $this->assertSame(1, preg_match('~q ([\d.]+) 0 0 ([\d.]+) [\d.]+ [\d.]+ cm /I\d+ Do Q~', $pdf, $m));
        $this->assertEqualsWithDelta(1.5, (float) $m[1] / (float) $m[2], 0.02, 'La imagen no respeta su proporcion.');

        /** El control: el diseño SIN la columna de imagen no dibuja ninguna. */
        $sin_columna = $this->pdf_de($budget, 'Presupuesto');
        $this->assertPdfNoContiene('/Subtype /Image', $sin_columna);
    }

    /**
     * Las filas con imagen son más altas que las de texto: el alto de la fila reserva el de la
     * imagen ANTES de decidir el salto de hoja, así que varias filas con imagen ocupan varias
     * hojas y ninguna imagen se sale del papel.
     *
     * @test
     */
    public function las_filas_con_imagen_reservan_su_alto_y_pasan_de_hoja_sin_salirse()
    {
        $renglones = [];
        for ($i = 1; $i <= 14; $i++) {
            $articulo = $this->crear_articulo('Con foto '.$i);
            $this->colgar_imagen_jpg($articulo, 40, 60);
            $renglones[] = ['article' => $articulo, 'amount' => 1, 'price' => 10 * $i];
        }

        $budget = $this->crear_presupuesto($renglones, ['total' => 1050]);

        $pdf = $this->pdf_de($budget, 'Presupuesto con imágenes');

        /** 14 filas de ~40 mm no entran en una hoja (quedan ~215 mm útiles debajo del encabezado). */
        $this->assertGreaterThanOrEqual(2, $this->cantidad_de_hojas($pdf));
        $this->assertSame(14, substr_count($pdf, '/Subtype /Image'), 'Se tienen que dibujar las 14 imagenes.');

        /** Ninguna imagen se sale del papel: `q ancho 0 0 alto x y cm`, y = borde inferior en pt. */
        preg_match_all('~q ([\d.]+) 0 0 ([\d.]+) ([\d.]+) ([\d.]+) cm /I\d+ Do Q~', $pdf, $imagenes, PREG_SET_ORDER);
        $this->assertCount(14, $imagenes);
        foreach ($imagenes as $imagen) {
            $this->assertGreaterThanOrEqual(0, (float) $imagen[4], 'Una imagen se dibujo por debajo del borde inferior de la hoja.');
        }
    }
}
