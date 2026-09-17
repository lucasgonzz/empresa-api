<?php

namespace Tests\Feature\ForzarTotal;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\BudgetPdf;
use App\Http\Controllers\Pdf\SaleAfipTicketPdf;
use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Models\Budget;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 7 — el ajuste del total forzado en los dos comprobantes que faltaban: el TICKET DE 80MM
 * y el cuadro "Total Original / Total final" de la factura A/B.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE EL TICKET DE 80MM NO ERA UN "NICE TO HAVE"
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Redondear $4.012 a $4.000 para no dar cambio es una venta DE MOSTRADOR, y de mostrador sale
 *  ticket de 80mm, no A4. Tener el desglose en `NewSalePdf` y no aca dejaba el arreglo justo
 *  afuera del unico papel que el cliente del caso de uso se lleva en la mano.
 *
 *  Y sin el renglon el ticket no quedaba "incompleto": quedaba CONTRADICTORIO. `total()` compara
 *  `$this->sale->total` contra `$this->total_sale` y, al no restarse el forzado en ningun lado,
 *  imprimia un "Total sin descuentos: $4.012" arriba de un "TOTAL: $4.000" con doce pesos de
 *  diferencia que el papel no explicaba. Es el mismo defecto que ya habia tenido el canje de
 *  puntos, documentado en el comentario de `canje_de_puntos()`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 Y POR QUE ENTRO EL "TOTAL ORIGINAL: $0,00" DE LA FACTURA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `SaleAfipTicketPdf::printDiscounts()` leia `$sale->sub_total` con una variable `$sale` que NO
 *  EXISTE en ese metodo. En PHP 7.4 eso no corta: tira un notice y resuelve a null, asi que el
 *  renglon imprimia $0,00 en TODA factura A/B de una venta con descuentos, desde siempre.
 *
 *  Es preexistente, pero el total forzado lo pone en primer plano: un forzado ES un descuento, y
 *  toda factura de una venta forzada cae en ese `if`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ POR QUE LOS ESPIAS SON CLASES ANONIMAS ADENTRO DE LOS METODOS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Los 42 archivos de `app/Http/Controllers/Pdf/` hacen `require` PELADO de `fpdf.php`, asi que
 *  dos clases de PDF no pueden convivir en un mismo proceso: la segunda re-ejecuta el archivo y
 *  PHP corta con "Cannot declare class FPDF, because the name is already in use".
 *
 *  Un `class EspiaDelTicket extends SaleTicketPdf` a nivel de archivo —como el
 *  `BudgetPdfSinSalir` de tests/Feature/Presupuestos/4— carga su padre cuando PHPUnit ARMA la
 *  suite, o sea antes de que corra un solo test, y ahi choca con el otro espia. Declarandolos
 *  adentro de los metodos, los dos padres se cargan recien al ejecutarse cada test, cuando fpdf ya
 *  esta cargado por el espia de BudgetPdf, y el `require_once` de estos dos archivos lo saltea.
 *
 *  El barrido de los 40 archivos que siguen con `require` pelado quedo REPORTADO y fuera del
 *  alcance de esta mision.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group facturacion
 * @group forzar_total
 */
class El_ajuste_en_el_ticket_y_en_la_factura_Test extends ForzarTotalTestCase
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
     * Una venta de mostrador de 4.012 con un renglon del articulo centinela.
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function venta($overrides = [])
    {
        $sale = $this->crear_venta_en_base($overrides);

        $this->enganchar_articulo($sale, TestingFerreteriaSeeder::ARTICULO_CENTINELA, self::BRUTO, 1);

        return $sale->fresh();
    }

    /**
     * Imprime el pie del ticket de 80mm de una venta y devuelve los renglones, en orden.
     *
     * 🔴 SE LLAMA A `Footer()`, QUE ES LA SECUENCIA REAL DE PRODUCCION: el test no replica ni un
     * renglon de esa logica ni el orden en que se aplican los descuentos. Lo unico que se
     * reemplaza es el dibujo (`Cell`/`MultiCell` guardan el texto) y el constructor, que en la
     * clase real termina en `$this->Output(); exit;` y mataria el proceso de PHPUnit en el acto
     * —sin resumen y sin rojo: la corrida entera "terminaria bien"—.
     *
     * Con `afip_ticket` en null, `iva_discriminado()` y `qr()` salen en su primera linea, que es
     * exactamente lo que pasa con el ticket no fiscal de mostrador.
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

                /*
                 * `$this->user` lo setea el constructor real con `UserHelper::getFullModel()` y lo
                 * necesita `get_puntos_renglones()`, que corre en el pie. Sin esta linea el pie
                 * muere con "Undefined property", que es EXACTAMENTE la familia de error que
                 * vigila tests/Unit/Pdf/PropiedadesDePdfInicializadasTest.
                 */
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
     * Imprime el cuadro de descuentos de la factura A/B y devuelve los renglones.
     *
     * Mismo criterio y mismo motivo que el espia del ticket: el constructor real de
     * `SaleAfipTicketPdf` tambien termina en `Output(); exit;`.
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
     * Imprime el bloque de descuentos y recargos del PDF del PRESUPUESTO y devuelve los renglones.
     *
     * Mismo criterio que los otros dos espias. `BudgetPdf` es la tercera clase de PDF de esta
     * suite, asi que tambien se declara adentro del metodo: ver el bloque del encabezado.
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

                /*
                 * `total_original` lo acumula el recorrido de los renglones del PDF, uno por uno.
                 * Acá se setea a mano con el bruto porque lo que se mide es el BLOQUE DE TOTALES,
                 * no el dibujo de la tabla de items.
                 */
                $this->total_original = ForzarTotalTestCase::BRUTO;
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
     * El alto que `BudgetPdf` reserva para su pie, con el presupuesto dado.
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
                $this->total_original = ForzarTotalTestCase::BRUTO;
            }

            public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {}

            public function SetFont($family, $style = '', $size = 0) {}
        };

        return (float) $espia->footerHeight();
    }

    /**
     * Un presupuesto en memoria, sin bonificaciones, descuentos ni recargos.
     *
     * Es el escenario del caso de uso: el vendedor forzo el total y no hay nada mas en el pie.
     *
     * @param  float|null  $monto
     * @return \App\Models\Budget
     */
    protected function presupuesto_en_memoria($monto)
    {
        $budget = new Budget();
        $budget->total = is_null($monto) ? self::BRUTO : self::BRUTO + $monto;
        $budget->forzar_total_monto = $monto;
        $budget->observations = '';
        $budget->aplicar_recargos_directo_a_items = 0;
        $budget->setRelation('articles', collect([]));
        $budget->setRelation('discounts', collect([]));
        $budget->setRelation('surchages', collect([]));

        return $budget;
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  El ticket de 80mm
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Test 1 — el ticket de una venta forzada muestra el desglose entero y no deja el hueco.
     *
     * @group forzar_total
     * @test
     */
    public function el_ticket_de_80mm_muestra_el_ajuste_y_el_desglose_cierra()
    {
        $renglones = $this->renglones_del_ticket($this->venta());

        $this->assertNotNull(
            $this->renglon_con($renglones, 'Total $4.012'),
            'el ticket tiene que arrancar diciendo de cuanto se partia: "Total $4.012,00". Renglones: '.json_encode($renglones)
        );

        $this->assertNotNull(
            $this->renglon_con($renglones, 'Ajuste -$12'),
            'el ticket tiene que tener el renglon del ajuste. Renglones: '.json_encode($renglones)
        );

        $this->assertNotNull(
            $this->renglon_con($renglones, 'TOTAL: $4.000'),
            'el ticket tiene que cerrar con el total forzado. Renglones: '.json_encode($renglones)
        );

        /*
         * 🔴 LA ASERCION QUE FIJA EL ARREGLO DE VERDAD. "Total sin descuentos" es el renglon que
         * `total()` imprime cuando `total_sale` quedo distinto de `sale->total`, o sea cuando el
         * papel NO pudo explicar la diferencia renglon por renglon. Con el ajuste ya restado, los
         * dos numeros coinciden y ese renglon de emergencia no tiene por que aparecer.
         */
        $this->assertNull(
            $this->renglon_con($renglones, 'Total sin descuentos'),
            'con el ajuste ya desglosado, "Total sin descuentos" no tiene que imprimirse: era el sintoma del hueco'
        );
    }

    /**
     * Test 2 — un recargo se imprime con el signo para el otro lado.
     *
     * @group forzar_total
     * @test
     */
    public function el_ticket_muestra_el_ajuste_positivo_como_recargo()
    {
        $renglones = $this->renglones_del_ticket($this->venta([
            'total'              => 4020.00,
            'forzar_total_monto' => 8.00,
        ]));

        $this->assertNotNull(
            $this->renglon_con($renglones, 'Ajuste +$8'),
            'un monto positivo tiene que imprimirse como recargo. Renglones: '.json_encode($renglones)
        );

        $this->assertNotNull(
            $this->renglon_con($renglones, 'TOTAL: $4.020'),
            'el total del ticket tiene que ser el forzado hacia arriba'
        );
    }

    /**
     * Test 3 — NO REGRESION: una venta sin forzar imprime el ticket exactamente como antes.
     *
     * Sin descuentos, sin recargos y sin canje, el ticket no tiene ni el renglon del sub total ni
     * el del ajuste: arranca directo en el TOTAL. Es el ticket de la enorme mayoria de las ventas.
     *
     * @group forzar_total
     * @test
     */
    public function una_venta_sin_forzar_imprime_el_ticket_de_siempre()
    {
        $renglones = $this->renglones_del_ticket($this->venta([
            'total'              => self::BRUTO,
            'forzar_total_monto' => null,
        ]));

        $this->assertNull(
            $this->renglon_con($renglones, 'Ajuste'),
            'sin forzado no puede aparecer ningun renglon de ajuste'
        );

        $this->assertNull(
            $this->renglon_con($renglones, 'Total $4.012'),
            'sin forzado ni descuentos tampoco se imprime el renglon del sub total'
        );

        $this->assertNotNull(
            $this->renglon_con($renglones, 'TOTAL: $4.012'),
            'el ticket tiene que cerrar con el total de siempre'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  El cuadro de descuentos de la factura A/B
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Test 4 — "Total Original" muestra el sub total de la venta, no $0,00.
     *
     * Es el arreglo de `$sale` -> `$this->sale`.
     *
     * @group forzar_total
     * @test
     */
    public function total_original_de_la_factura_muestra_el_sub_total_y_no_cero()
    {
        $renglones = $this->renglones_del_cuadro_de_la_factura($this->venta());

        $this->assertNotNull(
            $this->renglon_con($renglones, '$4.012'),
            '"Total Original" tiene que mostrar el sub total de la venta. Renglones: '.json_encode($renglones)
        );

        $this->assertNull(
            $this->renglon_con($renglones, '$0'),
            '"Total Original" imprimia $0,00 por leer una variable $sale que no existia'
        );
    }

    /**
     * Test 5 — el cuadro se imprime con SOLO un total forzado, sin ningun otro descuento.
     *
     * Es el caso de uso entero: el vendedor redondea y no hay ni un descuento de venta ni un
     * `sales.descuento`. Sin la condicion nueva, el cuadro no se imprimia y la factura no
     * explicaba de donde salia el total.
     *
     * @group forzar_total
     * @test
     */
    public function el_cuadro_se_imprime_con_solo_un_total_forzado()
    {
        $sale = $this->venta();

        $this->assertCount(0, $sale->discounts, 'el escenario tiene que no tener descuentos de venta, o no mide lo que dice');
        $this->assertEquals(0, (float) $sale->descuento, 'el escenario tiene que no tener `sales.descuento`, o no mide lo que dice');

        $renglones = $this->renglones_del_cuadro_de_la_factura($sale);

        $this->assertNotEmpty(
            $renglones,
            'con un total forzado, el cuadro de descuentos tiene que imprimirse igual'
        );

        $this->assertNotNull(
            $this->renglon_con($renglones, 'Ajuste del total -$12'),
            'el cuadro tiene que nombrar el ajuste, no dejar la etiqueta "Descuentos" sin una sola fila debajo. Renglones: '.json_encode($renglones)
        );

        $this->assertNotNull(
            $this->renglon_con($renglones, '$4.000'),
            '"Total final" tiene que ser el total forzado'
        );
    }

    /**
     * Test 6 — NO REGRESION del cuadro: una venta sin forzar y sin descuentos no lo imprime.
     *
     * @group forzar_total
     * @test
     */
    public function sin_descuentos_ni_forzado_el_cuadro_no_se_imprime()
    {
        $renglones = $this->renglones_del_cuadro_de_la_factura($this->venta([
            'total'              => self::BRUTO,
            'forzar_total_monto' => null,
        ]));

        $this->assertEmpty(
            $renglones,
            'sin descuentos ni forzado, el cuadro no tiene que imprimir nada: es el comportamiento de siempre'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  El PDF del presupuesto
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Test 7 — el PDF del presupuesto explica el ajuste.
     *
     * Imprimia "Sub Total sin descuentos: $4.012" y abajo "Total: $4.000" —porque
     * `BudgetHelper::getTotal()` si contempla el forzado— y NINGUNA fila explicaba los doce pesos.
     * Los presupuestos estan adentro del alcance que fijo Lucas, y el eligio el desglose.
     *
     * @group forzar_total
     * @test
     */
    public function el_pdf_del_presupuesto_muestra_el_ajuste()
    {
        $budget = new Budget();
        $budget->total = self::FORZADO;
        $budget->forzar_total_monto = self::MONTO;
        $budget->aplicar_recargos_directo_a_items = 0;
        $budget->setRelation('discounts', collect([]));
        $budget->setRelation('surchages', collect([]));

        $renglones = $this->renglones_del_pdf_del_presupuesto($budget);

        $this->assertNotNull(
            $this->renglon_con($renglones, 'Sub Total sin descuentos: $4.012'),
            'el PDF tiene que decir de cuanto se partia. Renglones: '.json_encode($renglones)
        );

        $this->assertNotNull(
            $this->renglon_con($renglones, '- $12 Ajuste del total'),
            'el PDF tiene que tener la fila del ajuste. Renglones: '.json_encode($renglones)
        );
    }

    /**
     * Test 8 — NO REGRESION del PDF del presupuesto: sin forzado y sin descuentos no imprime nada.
     *
     * @group forzar_total
     * @test
     */
    public function el_pdf_de_un_presupuesto_sin_forzar_no_cambia()
    {
        $budget = new Budget();
        $budget->total = self::BRUTO;
        $budget->forzar_total_monto = null;
        $budget->aplicar_recargos_directo_a_items = 0;
        $budget->setRelation('discounts', collect([]));
        $budget->setRelation('surchages', collect([]));

        $renglones = $this->renglones_del_pdf_del_presupuesto($budget);

        $this->assertEmpty(
            $renglones,
            'sin forzado ni descuentos, el bloque de totales del presupuesto no imprime nada'
        );
    }

    /**
     * Test 9 — EL ALTO RESERVADO. El pie de un presupuesto forzado mide 19mm mas, y `footerHeight()`
     * tiene que saberlo.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 LO QUE SE PIERDE SI ESTE TEST SE PONE EN ROJO NO ES UN RENGLON DECORATIVO
     * ─────────────────────────────────────────────────────────────────────────────
     *
     *  `fits()` decide el corte de pagina con `y + articleRowHeight + footerHeight() <= 296`, y
     *  fpdf NO PAGINA ADENTRO DE `Footer()`: las dos ramas de salto automatico (`fpdf.php` 581 y
     *  912) piden `&& !$this->InFooter`. Todo lo que el pie dibuje mas alla de 296 se escribe fuera
     *  de la hoja y desaparece, sin error y sin aviso.
     *
     *  Y la ULTIMA fila del pie es `total()`. O sea que reservar de menos se lleva el "Total: $X"
     *  del papel que se le da al cliente.
     *
     *  Los 19mm son las dos filas que agrego esta mision: "Sub Total sin descuentos" (12mm, que
     *  antes solo se reservaba si algun articulo tenia bonificacion) y "Ajuste del total" (7mm,
     *  nuevo). El escenario es un presupuesto forzado SIN bonificacion, sin descuentos y sin
     *  recargos, que es exactamente el caso de uso.
     *
     * @group forzar_total
     * @test
     */
    public function el_pie_del_presupuesto_forzado_reserva_sus_dos_renglones()
    {
        $sin_forzar = $this->alto_reservado_del_pie($this->presupuesto_en_memoria(null));
        $forzado    = $this->alto_reservado_del_pie($this->presupuesto_en_memoria(self::MONTO));

        $this->assertEqualsWithDelta(
            19.0,
            $forzado - $sin_forzar,
            0.01,
            'el pie de un presupuesto forzado tiene que reservar 19mm mas: 12 del "Sub Total sin '.
            'descuentos" y 7 del "Ajuste del total". Si no, el renglon "Total" se dibuja fuera de la hoja'
        );
    }

    /**
     * Test 10 — NO REGRESION del alto: sin forzado, `footerHeight()` devuelve exactamente lo de
     * siempre. Un presupuesto comun no puede empezar a cortar paginas antes por culpa de esto.
     *
     * @group forzar_total
     * @test
     */
    public function el_pie_de_un_presupuesto_sin_forzar_reserva_lo_de_siempre()
    {
        // 6 de margen fijo + 10 del renglon del total. Calculado a mano sobre `footerHeight()`.
        $this->assertEqualsWithDelta(
            16.0,
            $this->alto_reservado_del_pie($this->presupuesto_en_memoria(null)),
            0.01,
            'sin forzado, sin observaciones, sin bonificaciones y sin descuentos, el pie reserva lo mismo que antes de esta mision'
        );
    }
}
