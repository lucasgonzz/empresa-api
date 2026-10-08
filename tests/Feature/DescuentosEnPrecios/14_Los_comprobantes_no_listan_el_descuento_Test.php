<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfLayout\TotalesDeVentaPdf;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\BudgetPdf;
use App\Http\Controllers\Pdf\SaleAfipTicketPdf;
use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Models\Budget;
use App\Models\Sale;

/**
 * Archivo 14 — los COMPROBANTES no listan ni restan el descuento que el precio de cada renglon ya
 * trae adentro (`aplicar_descuentos_directo_a_items`): es lo que pide la opcion, que el descuento
 * no aparezca discriminado.
 *
 * Cada comprobante se prueba con la opcion prendida y con su CONTROL apagado (el mismo comprobante
 * con el precio de lista y el 10 % al pie), que SI lista el descuento: sin el control, un
 * comprobante que dejara de listar el descuento siempre daria verde.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ QUE COMPROBANTES ESTAN ACA, Y CUALES NO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Los PDF de `app/Http/Controllers/Pdf/` hacen `require` PELADO de `fpdf.php` salvo tres
 *  (`BudgetPdf`, `SaleTicketPdf` y `SaleAfipTicketPdf`, que usan `require_once`), asi que dos clases
 *  de PDF con `require` pelado no pueden convivir en un proceso: la segunda re-ejecuta el archivo y
 *  PHP corta con "Cannot declare class FPDF". Por eso aca solo se cargan esas tres, con espias
 *  declarados ADENTRO de los metodos (ver el encabezado de `ForzarTotal/7`), mas las dos clases de
 *  "documento" que no extienden FPDF (`TotalesDeVentaPdf` y `BudgetPdfDocument`).
 *
 *  `SalePdf`, `NewSalePdf`, `NotaCreditoPdf` y `AfipTicketPdf` llevan la misma guarda y NO tienen
 *  test: no se pueden cargar sin arriesgar el proceso de toda la suite. La de `NewSalePdf` y la de
 *  `TotalesDeVentaPdf` son gemelas linea por linea (la caja de totales con diseno es copia fiel
 *  del remito), y la de `NotaCreditoPdf` calca la cuenta de `SaleHelper::checkNotaCredito()`, que
 *  si esta cubierta en el archivo 12.
 *
 * @group descuentos_en_precios
 */
class Los_comprobantes_no_listan_el_descuento_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Devuelve el primer renglon impreso que contiene el texto buscado, o null.
     *
     * @param  array   $renglones
     * @param  string  $buscado
     * @return string|null
     */
    protected function renglon_con($renglones, $buscado)
    {
        foreach ($renglones as $renglon) {
            if (strpos((string) $renglon, $buscado) !== false) {
                return $renglon;
            }
        }

        return null;
    }

    /**
     * Una venta de mostrador armada directo en la base, con el descuento de venta de 10 % en su
     * lista y UN articulo.
     *
     * @param  int    $flag   `aplicar_descuentos_directo_a_items`.
     * @param  float  $price  El precio del renglon (90 prendida / 100 apagada).
     * @param  float  $total
     * @param  float  $sub_total
     * @return \App\Models\Sale
     */
    protected function venta($flag, $price, $total, $sub_total)
    {
        $sale = Sale::create([
            'user_id'                            => $this->comercio()->id,
            'omitir_en_cuenta_corriente'         => 1,
            'save_current_acount'                => 0,
            'terminada'                          => 1,
            'moneda_id'                          => 1,
            'descuento'                          => 0,
            'sub_total'                          => $sub_total,
            'total'                              => $total,
            'aplicar_descuentos_directo_a_items' => $flag,
        ]);

        $sale->discounts()->attach($this->descuento()->id, ['percentage' => self::PORCENTAJE_DESCUENTO]);

        $sale->articles()->attach($this->articulo_centinela()->id, ['amount' => 1, 'price' => $price]);

        $sale = Sale::find($sale->id);

        $sale->load('articles', 'combos', 'promocion_vinotecas', 'services', 'discounts', 'surchages', 'seller_commissions');

        return $sale;
    }

    /**
     * Imprime el pie del ticket de 80mm de una venta y devuelve los renglones, en orden (la misma
     * tecnica y el mismo espia que `ForzarTotal/7`: se llama a `Footer()`, la secuencia real).
     *
     * @param  \App\Models\Sale  $sale
     * @return array
     */
    protected function renglones_del_ticket($sale)
    {
        $espia = new class($sale) extends SaleTicketPdf {

            /** Cada texto que el pie mando a imprimir, en orden. */
            public $renglones = [];

            public function __construct($sale)
            {
                $this->sale = $sale;
                $this->afip_ticket = null;
                $this->line_height = 5;
                $this->x_incial = 4;
                $this->cell_ancho = 72;
                $this->name_font_size = 12;
                $this->price_font_size = 10;
                $this->b = 0;
                $this->x = 0;
                $this->y = 0;
                $this->total_sale = $sale->sub_total;
                $this->user = UserHelper::getFullModel();
            }

            public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
            {
                $this->renglones[] = $txt;
            }

            public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false)
            {
                $this->renglones[] = $txt;
            }

            public function SetFont($family, $style = '', $size = 0) {}

            public function SetFillColor($r, $g = null, $b = null) {}

            public function SetTextColor($r, $g = null, $b = null) {}

            public function SetX($x)
            {
                $this->x = $x;
            }
        };

        $espia->Footer();

        return $espia->renglones;
    }

    /**
     * Imprime el cuadro de descuentos de la factura A4 de AFIP y devuelve los renglones.
     *
     * @param  \App\Models\Sale  $sale
     * @return array
     */
    protected function renglones_del_cuadro_de_la_factura($sale)
    {
        $espia = new class($sale) extends SaleAfipTicketPdf {

            /** Cada texto que el cuadro mando a imprimir, en orden. */
            public $renglones = [];

            public function __construct($sale)
            {
                $this->sale = $sale;
                $this->x = 0;
                $this->y = 0;
            }

            public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
            {
                $this->renglones[] = $txt;
            }

            public function SetFont($family, $style = '', $size = 0) {}

            public function SetX($x)
            {
                $this->x = $x;
            }
        };

        $espia->printDiscounts();

        return $espia->renglones;
    }

    /**
     * Un presupuesto en memoria con el descuento de 10 % en su lista y sin renglones.
     *
     * @param  int  $flag  `aplicar_descuentos_directo_a_items`.
     * @return \App\Models\Budget
     */
    protected function presupuesto_en_memoria($flag)
    {
        $descuento = $this->descuento();

        /* Lo que trae `Budget::discounts()`: el modelo con su pivot de porcentaje. */
        $descuento->setRelation('pivot', (object) ['percentage' => self::PORCENTAJE_DESCUENTO]);

        $budget = new Budget();
        $budget->total = 100;
        $budget->observations = '';
        $budget->aplicar_recargos_directo_a_items = 0;
        $budget->aplicar_descuentos_directo_a_items = $flag;
        $budget->setRelation('articles', collect([]));
        $budget->setRelation('discounts', collect([$descuento]));
        $budget->setRelation('surchages', collect([]));

        return $budget;
    }

    /**
     * Imprime el bloque de descuentos y recargos del PDF del presupuesto y devuelve los renglones.
     *
     * @param  \App\Models\Budget  $budget
     * @return array
     */
    protected function renglones_del_pdf_del_presupuesto($budget)
    {
        $espia = new class($budget) extends BudgetPdf {

            /** Cada texto que el bloque mando a imprimir, en orden. */
            public $renglones = [];

            public function __construct($budget)
            {
                $this->budget = $budget;
                $this->with_prices = true;
                $this->with_images = false;
                $this->b = 0;
                $this->line_height = 7;
                $this->x = 0;
                $this->y = 0;
                $this->total_original = 100;
            }

            public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
            {
                $this->renglones[] = $txt;
            }

            public function SetFont($family, $style = '', $size = 0) {}
        };

        $espia->discountsSurchages();

        return $espia->renglones;
    }

    /**
     * El alto que `BudgetPdf` reserva para su pie.
     *
     * @param  \App\Models\Budget  $budget
     * @return float
     */
    protected function alto_reservado_del_pie($budget)
    {
        $espia = new class($budget) extends BudgetPdf {

            public function __construct($budget)
            {
                $this->budget = $budget;
                $this->with_prices = true;
                $this->with_images = false;
                $this->b = 0;
                $this->line_height = 7;
                $this->x = 0;
                $this->y = 0;
                $this->total_original = 100;
            }

            public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {}

            public function SetFont($family, $style = '', $size = 0) {}
        };

        return (float) $espia->footerHeight();
    }

    /**
     * Test 1 — el TICKET de 80mm: prendida, no imprime la linea "Desc 10%" ni el "Total sin
     * descuentos" (que saldria con un importe por debajo del total real); apagada, SI imprime el
     * descuento.
     *
     * @test
     */
    public function el_ticket_de_80mm_no_lista_el_descuento_que_ya_esta_en_el_precio()
    {
        $prendida = $this->renglones_del_ticket($this->venta(1, 90, 90, 90));

        $this->assertNull($this->renglon_con($prendida, 'Desc '), 'El ticket no tiene que listar el descuento. Renglones: '.json_encode($prendida));
        $this->assertNull($this->renglon_con($prendida, 'Total sin descuentos'), 'Ni un "Total sin descuentos" por debajo del total real. Renglones: '.json_encode($prendida));
        $this->assertNotNull($this->renglon_con($prendida, 'TOTAL'), 'El total final sale igual.');

        $apagada = $this->renglones_del_ticket($this->venta(0, 100, 90, 100));

        $this->assertNotNull($this->renglon_con($apagada, 'Desc 10%'), 'Control: apagada, el ticket lista el descuento. Renglones: '.json_encode($apagada));
    }

    /**
     * Test 2 — la FACTURA A4 de AFIP: prendida, el cuadro "Descuentos" (y su cartel "se estan
     * aplicando los descuentos") no se imprime; apagada, si.
     *
     * @test
     */
    public function el_cuadro_de_la_factura_a4_no_lista_el_descuento_que_ya_esta_en_el_precio()
    {
        $prendida = $this->renglones_del_cuadro_de_la_factura($this->venta(1, 90, 90, 90));

        $this->assertSame([], $prendida, 'Prendida, sin otro ajuste, el cuadro no se imprime. Renglones: '.json_encode($prendida));

        $apagada = $this->renglones_del_cuadro_de_la_factura($this->venta(0, 100, 90, 100));

        $this->assertNotNull($this->renglon_con($apagada, 'Descuentos'), 'Control: apagada, el cuadro lista el descuento. Renglones: '.json_encode($apagada));
    }

    /**
     * Test 3 — el PDF del PRESUPUESTO: prendida, el pie no lista "- 10% <descuento>" y reserva 7 mm
     * menos de alto; apagada, lista el descuento y reserva la fila.
     *
     * @test
     */
    public function el_pdf_del_presupuesto_no_lista_el_descuento_y_no_reserva_su_fila()
    {
        $prendido = $this->presupuesto_en_memoria(1);
        $apagado  = $this->presupuesto_en_memoria(0);

        $renglones_prendido = $this->renglones_del_pdf_del_presupuesto($prendido);
        $renglones_apagado  = $this->renglones_del_pdf_del_presupuesto($apagado);

        $this->assertNull($this->renglon_con($renglones_prendido, '- 10%'), 'Prendida, el pie no lista el descuento. Renglones: '.json_encode($renglones_prendido));
        $this->assertNotNull($this->renglon_con($renglones_apagado, '- 10%'), 'Control: apagada, el pie lista el descuento. Renglones: '.json_encode($renglones_apagado));

        $this->assertEqualsWithDelta(
            7.0,
            $this->alto_reservado_del_pie($apagado) - $this->alto_reservado_del_pie($prendido),
            0.0001,
            'El alto reservado del pie tiene que seguir a lo que se imprime: una fila de 7 mm menos.'
        );
    }

    /**
     * Test 4 — el presupuesto con DISEÑO de perfil (`BudgetPdfDocument`): prendida, no hay renglones
     * de descuento; apagada, uno por descuento.
     *
     * @test
     */
    public function el_presupuesto_con_perfil_no_lista_el_descuento_que_ya_esta_en_el_precio()
    {
        $prendido = new BudgetPdfDocument($this->presupuesto_en_memoria(1));
        $apagado  = new BudgetPdfDocument($this->presupuesto_en_memoria(0));

        $this->assertSame([], $prendido->renglones_de_descuentos());

        $renglones = $apagado->renglones_de_descuentos();

        $this->assertCount(1, $renglones, 'Control: apagado, el presupuesto lista su descuento.');
        $this->assertStringContainsString('10%', $renglones[0]);
    }

    /**
     * Test 5 — la factura/remito con DISEÑO de cajas (`TotalesDeVentaPdf`): con un recargo al pie
     * que abre la diferencia entre el Sub Total y el Total, prendida NO arma el renglon de
     * descuento (y si el del recargo); apagada, arma los dos. Es la cascada que imprime el remito.
     *
     * @test
     */
    public function el_remito_con_diseno_no_arma_el_renglon_de_descuento()
    {
        $user = $this->comercio();

        /* Prendida: 90 con el descuento adentro, mas el 10 % de recargo al pie = 99. */
        $prendida = $this->venta(1, 90, 99, 90);
        $prendida->surchages()->attach($this->recargo()->id, ['percentage' => self::PORCENTAJE_RECARGO]);
        $prendida->load('surchages');

        $totales = new TotalesDeVentaPdf($prendida, $user, 'descriptivo');

        $this->assertSame([], $totales->renglones_de_descuentos(), 'Prendida, el descuento no se lista.');
        $this->assertCount(1, $totales->renglones_de_recargos(), 'El recargo al pie sigue listandose.');

        /* Apagada: 100 menos el 10 % mas el 10 % = 99. */
        $apagada = $this->venta(0, 100, 99, 100);
        $apagada->surchages()->attach($this->recargo()->id, ['percentage' => self::PORCENTAJE_RECARGO]);
        $apagada->load('surchages');

        $totales = new TotalesDeVentaPdf($apagada, $user, 'descriptivo');

        $this->assertNotEmpty($totales->renglones_de_descuentos(), 'Control: apagada, el descuento se lista.');
    }
}
