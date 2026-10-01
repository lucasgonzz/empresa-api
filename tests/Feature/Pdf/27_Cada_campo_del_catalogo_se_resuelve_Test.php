<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Http\Controllers\Helpers\PdfLayout\CamposDePedidoPdf;
use App\Http\Controllers\Helpers\PdfLayout\CamposDePresupuestoPdf;
use App\Http\Controllers\Helpers\PdfLayout\CamposDeVentaPdf;
use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\FuenteDeCamposPdf;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;

/**
 * Cada campo del catálogo de cada modelo (`CatalogoDeCamposPdf`) se resuelve sin excepción sobre
 * un comprobante armado en el test, y dice lo mismo que el PDF de siempre (misión
 * diseno-pdf-configurable, 1/10/2026).
 *
 * 🔴 El catálogo es la única fuente de los campos del diseñador: un campo agregado ahí sin su
 * resolver en la fuente tira `InvalidArgumentException` y este test se pone rojo, antes de que un
 * cliente arme un diseño con un campo que el PDF no sabe dibujar.
 *
 * Los valores se comparan contra las MISMAS expresiones del dibujante de siempre (`NewSalePdf`,
 * `AfipPdfHelper`, `BudgetPdfDocument::totals_rows()`, `OrderPdfDocument::totals_rows()`), y además
 * contra el número escrito a mano: si los dos PDF dejaran de coincidir, el cliente leería dos
 * montos distintos según el diseño.
 *
 * @group pdf-diseno-de-pagina
 */
class Cada_campo_del_catalogo_se_resuelve_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use ComprobantesConDisenoDePagina;

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
     * Le pide a la fuente el valor de cada campo del catálogo del modelo y afirma que es null, un
     * texto o una lista de textos. Devuelve key => valor.
     *
     * @param FuenteDeCamposPdf $fuente
     * @return array<string, mixed>
     */
    private function resolver_todo(FuenteDeCamposPdf $fuente)
    {
        $valores = [];

        foreach (CatalogoDeCamposPdf::campos($fuente->model_name()) as $definicion) {
            $key = $definicion['key'];
            $campo = $this->campo_de_caja($key, $key === CatalogoDeCamposPdf::KEY_TEXTO_LIBRE ? ['id' => 'texto_1', 'texto' => 'Gracias por su compra'] : []);

            $valor = $fuente->valor($key, $campo);

            if (is_array($valor)) {
                $this->assertNotEmpty($valor, $key.': una lista vacía tiene que ser null (no se imprime ni el rótulo).');
                foreach ($valor as $elemento) {
                    $this->assertIsString($elemento, $key.': cada elemento de la lista es un texto.');
                }
            } elseif (! is_null($valor)) {
                $this->assertIsString($valor, $key.': el valor es un texto, una lista o null.');
                $this->assertNotSame('', trim($valor), $key.': un valor vacío tiene que ser null.');
            }

            $valores[$key] = $valor;
        }

        $this->assertSame(CatalogoDeCamposPdf::keys($fuente->model_name()), array_keys($valores));

        return $valores;
    }

    /**
     * @test
     */
    public function cada_campo_de_venta_se_resuelve_y_dice_lo_mismo_que_el_remito_de_siempre()
    {
        $venta = $this->crear_venta_completa();
        $fuente = new CamposDeVentaPdf($venta, $this->dueno, false, 'descriptivo');

        $valores = $this->resolver_todo($fuente);

        /** Los únicos sin valor en esta venta: no tiene canje, ni forzado, ni puntos, ni es en dólares. */
        foreach ($valores as $key => $valor) {
            if (in_array($key, ['tot_canje_de_puntos', 'tot_ajuste_del_total', 'tot_puntos', 'venta_cotizacion'], true)) {
                $this->assertNull($valor, $key.' no tendría que tener valor en esta venta.');
                continue;
            }
            $this->assertNotNull($valor, $key.' tendría que tener valor en la venta completa.');
        }

        /** Cliente: los mismos datos que el bloque del cliente de siempre. */
        $this->assertSame('Juan Perez Test', $valores['cliente_nombre']);
        $this->assertSame('Perez Hnos SA', $valores['cliente_razon_social']);
        $this->assertSame('20123456789', $valores['cliente_documento'], 'Con CUIT, el documento es el CUIT (regla del receptor fiscal).');
        $this->assertSame('Responsable inscripto', $valores['cliente_condicion_iva']);
        $this->assertSame('Av San Martin 1234', $valores['cliente_direccion']);
        $this->assertSame('Rosario Test', $valores['cliente_localidad']);
        $this->assertSame('Santa Fe Test', $valores['cliente_provincia']);
        $this->assertSame('154', $valores['cliente_numero']);
        $this->assertSame('Mayorista Test', $valores['cliente_lista_de_precios']);
        $this->assertSame('Ana Vendedora del Cliente', $valores['cliente_vendedor']);
        $this->assertSame('Paga a 30 dias', $valores['cliente_observaciones']);

        /** Venta: todo lo que pidió Lucas. */
        $this->assertSame('1520', $valores['venta_numero']);
        $this->assertSame($venta->created_at->format('d/m/Y'), $valores['venta_fecha']);
        $this->assertSame('Mostrador Test', $valores['venta_tipo']);
        $this->assertSame('Entregada Test', $valores['venta_estado']);
        $this->assertSame('Cuenta corriente', $valores['venta_condicion']);
        $this->assertSame('Carla Gomez', $valores['venta_vendedor']);
        $this->assertSame('Martin Lopez', $valores['venta_empleado']);
        $this->assertSame('Martin Lopez', $valores['venta_atendido_por'], 'Atendido por: el empleado que cargó la venta.');
        $this->assertSame('Belgrano 450, Rosario', $valores['venta_sucursal']);
        $this->assertSame('Minorista Test', $valores['venta_lista_de_precios']);
        $this->assertSame(['Efectivo: $2.000', 'Debito: $362,50'], $valores['venta_metodos_de_pago'], 'Métodos de pago con lo que se pagó con cada uno.');
        $this->assertSame(['Caja mostrador', 'Caja posnet'], $valores['venta_cajas'], 'Las cajas de los pagos y la de la venta, sin repetir.');
        $this->assertSame('Pesos', $valores['venta_moneda']);
        $this->assertSame('3', $valores['venta_cuotas']);
        $this->assertSame('05/10/2026', $valores['venta_fecha_entrega']);
        $this->assertSame('OC-4471', $valores['venta_orden_de_compra']);
        $this->assertSame('4', $valores['venta_cantidad_de_unidades'], '2 taladros, 1 mecha y 1 instalación.');
        $this->assertSame('Entregar por la tarde', $valores['venta_observaciones']);

        /** Cuenta corriente: el formato del cuadrante derecho del remito ('$'.Numbers::price()). */
        $this->assertSame('$15.000', $valores['cc_saldo_anterior']);
        $this->assertSame('$'.Numbers::price($venta->total), $valores['cc_compra_actual']);
        $this->assertSame('$2.362,50', $valores['cc_compra_actual']);
        $this->assertSame('$17.362,50', $valores['cc_saldo']);

        /**
         * 🔴 La plata: los renglones de NewSalePdf::print_totals_box(), escritos con sus mismas
         * expresiones (Sub Total, descuento en cascada y recargo sobre lo ya descontado).
         */
        $descuento = $venta->discounts[0];
        $recargo = $venta->surchages[0];
        $this->assertSame(Numbers::price(2500, true, $venta->moneda_id), $valores['tot_subtotal']);
        $this->assertSame('$2.500', $valores['tot_subtotal']);
        $this->assertSame([
            'Menos '.Numbers::price(250, true, $venta->moneda_id).' ('.$descuento->pivot->percentage.'% '.$descuento->name.') = '.Numbers::price(2250, true),
            'Se aplican descuentos a los servicios',
        ], $valores['tot_descuentos']);
        $this->assertSame('Menos $250 (10% Descuento efectivo) = $2.250', $valores['tot_descuentos'][0]);
        $this->assertSame([
            'Mas '.Numbers::price(112.5, true, $venta->moneda_id).' ('.$recargo->pivot->percentage.'% '.$recargo->name.') = '.Numbers::price(2362.5, true),
            'Se aplican recargos a los servicios',
        ], $valores['tot_recargos']);
        $this->assertSame(Numbers::price($venta->total, true, $venta->moneda_id), $valores['tot_total']);
        $this->assertSame('$2.362,50', $valores['tot_total']);

        /** Comisiones, costos y ganancia: los formatos de print_commissions_block() y print_total_costs_block(). */
        $this->assertSame(['Carla Gomez 5.00%: $118,13'], $valores['tot_comisiones']);
        $this->assertSame(Numbers::price(2362.5 - 118.13, true), $valores['tot_total_menos_comisiones']);
        $this->assertSame('$'.Numbers::price(SaleHelper::getTotalCostSale($venta)), $valores['tot_costos']);
        $this->assertSame('$1.500', $valores['tot_costos']);
        $this->assertSame('$3.200', $valores['tot_ganancia']);

        $this->assertSame('Gracias por su compra', $valores['texto_libre']);
    }

    /**
     * En modo "simple" los descuentos y recargos van como "% Nombre" (discount_display_mode).
     *
     * @test
     */
    public function el_modo_simple_de_descuentos_se_respeta()
    {
        $venta = $this->crear_venta_completa();
        $fuente = new CamposDeVentaPdf($venta, $this->dueno, false, 'simple');

        $descuento = $venta->discounts[0];
        $recargo = $venta->surchages[0];
        $this->assertSame([$descuento->pivot->percentage.'% '.$descuento->name, 'Se aplican descuentos a los servicios'], $fuente->valor('tot_descuentos', $this->campo_de_caja('tot_descuentos')));
        $this->assertSame([$recargo->pivot->percentage.'% '.$recargo->name, 'Se aplican recargos a los servicios'], $fuente->valor('tot_recargos', $this->campo_de_caja('tot_recargos')));
    }

    /**
     * Una venta de contado, sin cliente ni nada: todos los campos se resuelven (sin excepción) y los
     * que no aplican dan null. La cuenta corriente, en particular, no sale.
     *
     * @test
     */
    public function una_venta_minima_resuelve_todo_sin_excepcion()
    {
        $articulo = $this->crear_articulo('Articulo suelto');
        $venta = Sale::create(['num' => 1, 'user_id' => $this->dueno->id, 'total' => 100, 'moneda_id' => 1]);
        $venta->articles()->attach($articulo->id, ['amount' => 1, 'price' => 100]);

        $valores = $this->resolver_todo(new CamposDeVentaPdf(Sale::find($venta->id), $this->dueno, false, 'descriptivo'));

        $this->assertNull($valores['cliente_nombre']);
        $this->assertNull($valores['cc_saldo_anterior'], 'Sin cliente ni cuenta corriente no hay saldo anterior.');
        $this->assertNull($valores['tot_subtotal'], 'Sin nada entre el Sub Total y el Total, no hay Sub Total (la condición de siempre).');
        $this->assertNull($valores['tot_descuentos']);
        $this->assertNull($valores['venta_metodos_de_pago']);
        $this->assertSame('Contado', $valores['venta_condicion']);
        $this->assertSame($this->dueno->name, $valores['venta_atendido_por'], 'Sin empleado, atendió el dueño.');
        $this->assertSame('$100', $valores['tot_total']);
    }

    /**
     * Una venta en dólares imprime la cotización.
     *
     * @test
     */
    public function una_venta_en_dolares_imprime_la_cotizacion()
    {
        $venta = $this->crear_venta_completa(['moneda_id' => 2, 'valor_dolar' => 1050]);
        $fuente = new CamposDeVentaPdf($venta, $this->dueno, false, 'descriptivo');

        $this->assertSame('Dólares', $fuente->valor('venta_moneda', $this->campo_de_caja('venta_moneda')));
        $this->assertSame('$1.050', $fuente->valor('venta_cotizacion', $this->campo_de_caja('venta_cotizacion')));
    }

    /**
     * @test
     */
    public function cada_campo_de_presupuesto_se_resuelve_y_dice_lo_mismo_que_el_presupuesto_de_siempre()
    {
        $budget = $this->crear_presupuesto_completo();
        $documento = new BudgetPdfDocument($budget);
        $valores = $this->resolver_todo(new CamposDePresupuestoPdf($documento, false));

        foreach ($valores as $key => $valor) {
            if (in_array($key, ['tot_ajuste_del_total', 'tot_ajuste_metodo_de_pago', 'presupuesto_cotizacion', 'cliente_razon_social', 'cliente_dni', 'cliente_condicion_iva', 'cliente_email', 'cliente_localidad', 'cliente_provincia', 'cliente_numero', 'cliente_lista_de_precios', 'cliente_vendedor'], true)) {
                continue;
            }
            $this->assertNotNull($valor, $key.' tendría que tener valor en el presupuesto completo.');
        }

        $this->assertSame('Cliente Presupuesto Test', $valores['cliente_nombre']);
        $this->assertSame((string) $budget->num, $valores['presupuesto_numero']);
        $this->assertSame('Vendedora Presupuesto', $valores['presupuesto_vendedor']);
        $this->assertSame('Mitre 980, La Plata', $valores['presupuesto_sucursal']);
        $this->assertSame('Lista Presupuesto Test', $valores['presupuesto_lista_de_precios']);
        $this->assertSame('Sin confirmar', $valores['presupuesto_estado']);
        $this->assertSame('Pesos', $valores['presupuesto_moneda']);
        $this->assertSame('Entrega en 48 horas habiles', $valores['presupuesto_observaciones']);

        /** La plata: las mismas piezas que totals_rows(), y los textos de siempre. */
        $this->assertSame('$2.300', $valores['tot_subtotal']);
        $this->assertSame(['- 10% Descuento por volumen'], $valores['tot_descuentos']);
        $this->assertSame(['+ 5% Recargo financiero'], $valores['tot_recargos']);
        $this->assertSame('$'.Numbers::price(BudgetHelper::getTotal($budget)), $valores['tot_total']);
        $this->assertSame('$2.173,50', $valores['tot_total']);

        /** Y el pie de siempre dice lo mismo, renglón por renglón, con sus rótulos. */
        $filas = array_column($documento->totals_rows(['show_total_in_footer' => true, 'show_subtotal_in_footer' => true]), 'text');
        $this->assertSame([
            'Sub Total sin descuentos: '.$valores['tot_subtotal'],
            $valores['tot_descuentos'][0],
            $valores['tot_recargos'][0],
            'Total: '.$valores['tot_total'],
        ], $filas);
    }

    /**
     * El descuento o recargo por método de pago de un presupuesto de contado: el campo dice lo
     * mismo que el renglón de totals_rows(), y el Sub Total sale cuando lo saca el pie de siempre.
     * En cuenta corriente no hay ajuste (la regla de getTotal()).
     *
     * @test
     */
    public function el_ajuste_por_metodo_de_pago_del_presupuesto_de_contado()
    {
        $articulo = $this->crear_articulo('Taladro percutor 13mm');
        $fila = ['current_acount_payment_method_id' => 3, 'amount' => 950, 'caja_id' => 0, 'discount_amount' => 50];

        $de_contado = $this->crear_presupuesto(
            [['article' => $articulo, 'amount' => 1, 'price' => 1000, 'bonus' => null]],
            ['total' => 950, 'omitir_en_cuenta_corriente' => 1, 'selected_payment_methods' => [$fila]]
        );
        $documento = new BudgetPdfDocument($de_contado);
        $fuente = new CamposDePresupuestoPdf($documento, false);

        $this->assertSame('- $50 Descuento por método de pago', $fuente->valor('tot_ajuste_metodo_de_pago', $this->campo_de_caja('tot_ajuste_metodo_de_pago')));
        $this->assertSame('$1.000', $fuente->valor('tot_subtotal', $this->campo_de_caja('tot_subtotal')), 'El ajuste abre la diferencia: el Sub Total sale.');
        $this->assertSame('$950', $fuente->valor('tot_total', $this->campo_de_caja('tot_total')));
        $this->assertContains(
            $fuente->valor('tot_ajuste_metodo_de_pago', $this->campo_de_caja('tot_ajuste_metodo_de_pago')),
            array_column($documento->totals_rows(['show_total_in_footer' => true, 'show_subtotal_in_footer' => true]), 'text')
        );

        $a_cuenta_corriente = $this->crear_presupuesto(
            [['article' => $articulo, 'amount' => 1, 'price' => 1000, 'bonus' => null]],
            ['total' => 1000, 'omitir_en_cuenta_corriente' => 0, 'selected_payment_methods' => [$fila]]
        );
        $this->assertNull((new CamposDePresupuestoPdf(new BudgetPdfDocument($a_cuenta_corriente), false))->valor('tot_ajuste_metodo_de_pago', $this->campo_de_caja('tot_ajuste_metodo_de_pago')));
    }

    /**
     * El forzado del presupuesto: el ajuste con su signo, y el Sub Total aunque quede menor que el
     * Total.
     *
     * @test
     */
    public function el_ajuste_del_total_del_presupuesto()
    {
        $budget = $this->crear_presupuesto_completo(['total' => 2400, 'forzar_total_monto' => 226.5]);
        $fuente = new CamposDePresupuestoPdf(new BudgetPdfDocument($budget), false);

        $this->assertSame('+ $226,50 Ajuste del total', $fuente->valor('tot_ajuste_del_total', $this->campo_de_caja('tot_ajuste_del_total')));
        $this->assertSame('$2.300', $fuente->valor('tot_subtotal', $this->campo_de_caja('tot_subtotal')));
        $this->assertSame('$2.400', $fuente->valor('tot_total', $this->campo_de_caja('tot_total')));
    }

    /**
     * @test
     */
    public function cada_campo_de_pedido_se_resuelve_y_respeta_la_regla_del_subtotal()
    {
        $pedido = $this->crear_pedido_completo();
        $documento = new OrderPdfDocument($pedido);
        $valores = $this->resolver_todo(new CamposDePedidoPdf($documento, false));

        foreach ($valores as $key => $valor) {
            if ($key === 'tot_total') {
                continue;
            }
            $this->assertNotNull($valor, $key.' tendría que tener valor en el pedido completo.');
        }

        $this->assertSame('Marta Gomez', $valores['comprador_nombre']);
        $this->assertSame('2216001122', $valores['comprador_telefono']);
        $this->assertSame('Calle 7 N 100 piso 2', $valores['comprador_direccion'], 'La dirección del pedido gana a la del comprador.');
        $this->assertSame('La Plata', $valores['comprador_localidad']);
        $this->assertSame('1900', $valores['comprador_codigo_postal']);
        $this->assertSame('20333333336', $valores['comprador_cuit'], 'El CUIT sale del cliente del ERP vinculado.');
        $this->assertSame('5001', $valores['pedido_numero']);
        $this->assertSame('Sin confirmar', $valores['pedido_estado']);
        $this->assertSame('Envío a domicilio', $valores['pedido_modalidad_de_entrega']);
        $this->assertSame('Calle 7 N 100 piso 2', $valores['pedido_direccion_de_envio']);
        $this->assertSame('Andreani · Estándar', $valores['pedido_envio_elegido']);
        $this->assertSame('Mercado Pago Test', $valores['pedido_metodo_de_pago']);
        $this->assertSame('PROMO10', $valores['pedido_cupon']);
        $this->assertSame('03/10/2026', $valores['pedido_fecha_entrega']);
        $this->assertSame('Carla Pedido', $valores['pedido_vendedor']);
        $this->assertSame('Belgrano 450, Rosario', $valores['pedido_deposito']);
        $this->assertSame('Tocar timbre dos veces', $valores['pedido_notas']);

        /** 🔴 D6: con extras, Subtotal (no Total), y los extras como los dice el pedido de siempre. */
        $this->assertSame('$350', $valores['tot_subtotal']);
        $this->assertNull($valores['tot_total'], 'Con extras no se imprime un Total que no es lo que pagó el comprador.');
        $this->assertSame('$500', $valores['tot_envio']);
        $this->assertSame('PROMO10', $valores['tot_cupon']);
        $this->assertSame('+10%', $valores['tot_ajuste_medio_de_pago']);

        $filas = array_column($documento->totals_rows(['show_total_in_footer' => true]), 'text');
        $this->assertSame([
            'Subtotal: '.$valores['tot_subtotal'],
            'Envío: '.$valores['tot_envio'],
            'Cupón: '.$valores['tot_cupon'],
            'Medio de pago: '.$valores['tot_ajuste_medio_de_pago'],
        ], $filas, 'El pie de siempre y los campos dicen lo mismo.');
    }

    /**
     * Un pedido sin extras: Total (y no Subtotal).
     *
     * @test
     */
    public function un_pedido_sin_extras_imprime_el_total()
    {
        $pedido = $this->crear_pedido_completo([], false);
        $fuente = new CamposDePedidoPdf(new OrderPdfDocument($pedido), false);

        $this->assertSame('$350', $fuente->valor('tot_total', $this->campo_de_caja('tot_total')));
        $this->assertNull($fuente->valor('tot_subtotal', $this->campo_de_caja('tot_subtotal')));
        $this->assertNull($fuente->valor('tot_envio', $this->campo_de_caja('tot_envio')));
    }

    /**
     * 🔴 Una key que la fuente no sabe resolver tira: es la señal de un campo agregado al catálogo
     * sin su resolver (el motor nunca pregunta por una key fuera del catálogo).
     *
     * @test
     */
    public function una_key_sin_resolver_tira_en_las_tres_fuentes()
    {
        $fuentes = [
            new CamposDeVentaPdf($this->crear_venta_completa(), $this->dueno, false, 'descriptivo'),
            new CamposDePresupuestoPdf(new BudgetPdfDocument($this->crear_presupuesto_completo()), false),
            new CamposDePedidoPdf(new OrderPdfDocument($this->crear_pedido_completo()), false),
        ];

        foreach ($fuentes as $fuente) {
            try {
                $fuente->valor('campo_inventado', $this->campo_de_caja('campo_inventado'));
                $this->fail(get_class($fuente).' no tiró con una key sin resolver.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('campo_inventado', $e->getMessage());
            }
        }
    }
}
