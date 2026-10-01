<?php

namespace Tests\Feature\Pdf\Concerns;

use App\Http\Controllers\Pdf\SaleLayoutPdf;
use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Buyer;
use App\Models\Caja;
use App\Models\Client;
use App\Models\Cupon;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Discount;
use App\Models\IvaCondition;
use App\Models\Location;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Models\PriceType;
use App\Models\Provincia;
use App\Models\Sale;
use App\Models\SaleDeliveryInfo;
use App\Models\SaleStatus;
use App\Models\SaleType;
use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\Service;
use App\Models\Surchage;
use App\Models\User;

/**
 * Armadores de los tests del diseño de página (misión diseno-pdf-configurable, 1/10/2026): un
 * perfil de venta con `page_layout`, una venta COMPLETA (cliente con todos sus datos, cuenta
 * corriente con saldo anterior, métodos de pago con caja, descuento y recargo, servicio,
 * comisión, ganancia), la factura de ARCA, un presupuesto y un pedido online completos, y la
 * lectura del PDF (textos, fuentes y tamaños).
 *
 * Todo se crea adentro de la transacción del test (ningún dato de la base se da por sentado) y
 * nada se emite: `render()` + `Output('S')` con la compresión apagada.
 *
 * Requiere `Tests\Concerns\DocumentosParaPdf` (el dueño, `crear_articulo()` y las lecturas del
 * binario del PDF) y `Tests\EmpresaTestCase`.
 */
trait ComprobantesConDisenoDePagina
{
    /**
     * Lo que armó crear_venta_completa(), para afirmar contra los mismos objetos.
     *
     * @var array<string, mixed>
     */
    protected $fixture_de_venta = [];

    // ── Diseño ────────────────────────────────────────────────────────────────────────────

    /**
     * Un campo de una caja, con los estilos en null (los del catálogo) salvo lo que se pise.
     *
     * @param string $key
     * @param array  $extra
     * @return array
     */
    protected function campo_de_caja($key, array $extra = [])
    {
        return array_merge([
            'key' => $key,
            'etiqueta' => null,
            'tamano' => null,
            'negrita' => null,
            'cursiva' => null,
            'alineacion' => null,
        ], $extra);
    }

    /**
     * Una caja de la grilla.
     *
     * @param string $id
     * @param int    $cols
     * @param array  $campos
     * @param string $titulo
     * @param string $estilo borde | gris | ninguno
     * @return array
     */
    protected function caja_de_diseno($id, $cols, array $campos, $titulo = '', $estilo = 'borde')
    {
        return [
            'tipo' => 'caja',
            'id' => $id,
            'cols' => $cols,
            'titulo' => $titulo,
            'estilo' => $estilo,
            'campos' => $campos,
        ];
    }

    /**
     * @param array $superior
     * @param array $pie
     * @return array
     */
    protected function diseno_de_pagina(array $superior, array $pie)
    {
        return [
            'version' => 1,
            'superior' => $superior,
            'pie' => $pie,
        ];
    }

    /**
     * El diseño "remito completo" que usan varios tests: cliente y cuenta corriente arriba, datos
     * de la venta, y totales, comisiones, costos, ganancia, observaciones y un texto libre abajo.
     *
     * @return array
     */
    protected function diseno_de_remito_completo()
    {
        return $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_cliente', 6, [
                $this->campo_de_caja('cliente_nombre'),
                $this->campo_de_caja('cliente_documento'),
                $this->campo_de_caja('cliente_direccion'),
                $this->campo_de_caja('cliente_localidad'),
            ], 'Cliente'),
            $this->caja_de_diseno('caja_cuenta_corriente', 6, [
                $this->campo_de_caja('cc_saldo_anterior'),
                $this->campo_de_caja('cc_compra_actual'),
                $this->campo_de_caja('cc_saldo'),
            ]),
            ['tipo' => 'salto_de_fila', 'id' => 'salto_1'],
            $this->caja_de_diseno('caja_venta', 12, [
                $this->campo_de_caja('venta_vendedor'),
                $this->campo_de_caja('venta_sucursal'),
                $this->campo_de_caja('venta_lista_de_precios'),
                $this->campo_de_caja('venta_tipo'),
                $this->campo_de_caja('venta_metodos_de_pago'),
                $this->campo_de_caja('venta_cajas'),
            ], 'Datos de la venta', 'ninguno'),
        ], [
            $this->caja_de_diseno('caja_totales', 12, [
                $this->campo_de_caja('tot_subtotal'),
                $this->campo_de_caja('tot_descuentos'),
                $this->campo_de_caja('tot_recargos'),
                $this->campo_de_caja('tot_total'),
            ], '', 'gris'),
            $this->caja_de_diseno('caja_comisiones', 6, [
                $this->campo_de_caja('tot_comisiones'),
                $this->campo_de_caja('tot_total_menos_comisiones'),
            ], '', 'ninguno'),
            $this->caja_de_diseno('caja_internos', 6, [
                $this->campo_de_caja('tot_costos'),
                $this->campo_de_caja('tot_ganancia'),
            ], '', 'ninguno'),
            $this->caja_de_diseno('caja_observaciones', 12, [
                $this->campo_de_caja('venta_observaciones', ['etiqueta' => '']),
                $this->campo_de_caja('texto_libre', ['id' => 'texto_gracias', 'texto' => 'Gracias por su compra']),
            ], 'OBSERVACIONES', 'gris'),
        ]);
    }

    /**
     * Perfil de VENTA del dueño con columnas (#, Nombre, Cant, Precio, Sub total: 130 mm, entra
     * hasta en una A5) y, si se pasa, un diseño de página.
     *
     * @param array      $atributos
     * @param array|null $page_layout
     * @return \App\Models\PdfColumnProfile
     */
    protected function perfil_de_venta(array $atributos = [], $page_layout = null)
    {
        $perfil = PdfColumnProfile::create(array_merge([
            'user_id' => $this->dueno->id,
            'model_name' => 'sale',
            'name' => 'zz Remito con cajas '.uniqid(),
            'paper_width_mm' => 210,
            'printable_width_mm' => 210,
            'margin_mm' => 5,
            'columns' => [],
            'is_afip_ticket' => false,
            'page_layout' => $page_layout,
        ], $atributos));

        $anchos = [
            'row_index' => 8,
            'item_name' => 60,
            'item_amount' => 15,
            'item_price' => 22,
            'item_subtotal' => 25,
        ];

        $orden = 0;
        foreach ($anchos as $resolver => $ancho) {
            $opcion = PdfColumnOption::where('model_name', 'sale')->where('value_resolver', $resolver)->first();
            $this->assertNotNull($opcion, 'Falta la opción de columna de venta '.$resolver.' en la base de testing.');

            $perfil->pdf_column_options()->attach($opcion->id, [
                'visible' => true,
                'order' => $orden++,
                'width' => $ancho,
                'wrap_content' => false,
            ]);
        }

        return $perfil->fresh();
    }

    // ── La venta ──────────────────────────────────────────────────────────────────────────

    /**
     * Venta COMPLETA del dueño, con plata conocida:
     *
     * - Taladro 2 x $1.000 con 10% de bonificación ($1.800), Mecha 1 x $500 y el servicio
     *   Instalación 1 x $200: Sub Total $2.500.
     * - Descuento 10% (también a servicios): Menos $250 → $2.250. Recargo 5%: Mas $112,50 →
     *   $2.362,50. Total $2.362,50.
     * - Cuenta corriente con $15.000 de saldo anterior: saldo $17.362,50.
     * - Pagos: Efectivo $2.000 (Caja mostrador) y Debito $362,50 (Caja posnet); la venta en
     *   Caja posnet.
     * - Comisión de Carla Gomez 5% ($118,13), costos $1.500 y ganancia $3.200.
     *
     * @param array $atributos Atributos de la venta que pisan los de por defecto.
     * @return \App\Models\Sale
     */
    protected function crear_venta_completa(array $atributos = [])
    {
        $dueno_id = $this->dueno->id;

        $provincia = Provincia::create(['name' => 'Santa Fe Test', 'user_id' => $dueno_id]);
        $localidad = Location::create(['name' => 'Rosario Test', 'user_id' => $dueno_id, 'provincia_id' => $provincia->id]);
        $condicion = IvaCondition::where('name', 'Responsable inscripto')->first();
        $lista_del_cliente = PriceType::create(['name' => 'Mayorista Test', 'user_id' => $dueno_id]);
        $lista_de_la_venta = PriceType::create(['name' => 'Minorista Test', 'user_id' => $dueno_id]);
        $vendedor_del_cliente = Seller::create(['num' => 1, 'name' => 'Ana Vendedora del Cliente', 'user_id' => $dueno_id]);
        $vendedor = Seller::create(['num' => 2, 'name' => 'Carla Gomez', 'user_id' => $dueno_id]);
        $tipo = SaleType::create(['name' => 'Mostrador Test', 'user_id' => $dueno_id]);
        $estado = SaleStatus::create(['name' => 'Entregada Test', 'position' => 1, 'user_id' => $dueno_id]);
        $sucursal = Address::create(['street' => 'Belgrano', 'street_number' => '450', 'city' => 'Rosario', 'user_id' => $dueno_id]);
        $caja_mostrador = Caja::create(['num' => 901, 'name' => 'Caja mostrador', 'user_id' => $dueno_id]);
        $caja_posnet = Caja::create(['num' => 902, 'name' => 'Caja posnet', 'user_id' => $dueno_id]);

        $empleado = User::create([
            'name' => 'Martin Lopez',
            'email' => 'pdf-empleado-'.uniqid().'@test.local',
            'password' => 'x',
            'owner_id' => $dueno_id,
        ]);

        $cliente = Client::create([
            'name' => 'Juan Perez Test',
            'razon_social' => 'Perez Hnos SA',
            'cuit' => '20123456789',
            'dni' => '12345678',
            'phone' => '1155555555',
            'email' => 'juan-test@correo.local',
            'address' => 'Av San Martin 1234',
            'num' => 154,
            'description' => 'Paga a 30 dias',
            'iva_condition_id' => $condicion ? $condicion->id : null,
            'price_type_id' => $lista_del_cliente->id,
            'seller_id' => $vendedor_del_cliente->id,
            'location_id' => $localidad->id,
            'provincia_id' => $provincia->id,
            'user_id' => $dueno_id,
        ]);

        $taladro = $this->crear_articulo('Taladro percutor 13mm');
        $mecha = $this->crear_articulo('Mecha widia 8mm');
        $instalacion = Service::create(['name' => 'Instalacion', 'price' => 200, 'user_id' => $dueno_id]);

        $venta = Sale::create(array_merge([
            'num' => 1520,
            'user_id' => $dueno_id,
            'client_id' => $cliente->id,
            'employee_id' => $empleado->id,
            'seller_id' => $vendedor->id,
            'address_id' => $sucursal->id,
            'price_type_id' => $lista_de_la_venta->id,
            'sale_type_id' => $tipo->id,
            'sale_status_id' => $estado->id,
            'observations' => 'Entregar por la tarde',
            'numero_orden_de_compra' => 'OC-4471',
            'cantidad_cuotas' => 3,
            'fecha_entrega' => '2026-10-05 10:00:00',
            'caja_id' => $caja_posnet->id,
            'ganancia' => 3200,
            'save_current_acount' => 1,
            'discounts_in_services' => 1,
            'surchages_in_services' => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'moneda_id' => 1,
            'total' => 2362.5,
        ], $atributos));

        $venta->articles()->attach($taladro->id, ['amount' => 2, 'price' => 1000, 'discount' => 10, 'cost' => 600]);
        $venta->articles()->attach($mecha->id, ['amount' => 1, 'price' => 500, 'cost' => 300]);
        $venta->services()->attach($instalacion->id, ['amount' => 1, 'price' => 200]);

        $descuento = Discount::create(['name' => 'Descuento efectivo', 'percentage' => 10, 'user_id' => $dueno_id]);
        $recargo = Surchage::create(['name' => 'Recargo tarjeta', 'percentage' => 5, 'user_id' => $dueno_id]);
        $venta->discounts()->attach($descuento->id, ['percentage' => 10]);
        $venta->surchages()->attach($recargo->id, ['percentage' => 5]);

        $efectivo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->first();
        $debito = CurrentAcountPaymentMethod::where('name', 'Debito')->first();
        $this->assertNotNull($efectivo, 'Falta el método de pago Efectivo en la base de testing.');
        $this->assertNotNull($debito, 'Falta el método de pago Debito en la base de testing.');
        $venta->current_acount_payment_methods()->attach($efectivo->id, ['amount' => 2000, 'caja_id' => $caja_mostrador->id]);
        $venta->current_acount_payment_methods()->attach($debito->id, ['amount' => 362.5, 'caja_id' => $caja_posnet->id]);

        SellerCommission::create([
            'sale_id' => $venta->id,
            'seller_id' => $vendedor->id,
            'percentage' => 5,
            'debe' => 118.13,
            'user_id' => $dueno_id,
        ]);

        /**
         * Cuenta corriente: un movimiento ANTERIOR con saldo $15.000 y el de esta venta. El saldo
         * anterior lo lee CurrentAcountHelper::getSaldo() hasta el movimiento de la venta.
         */
        $cuenta = CreditAccount::create([
            'model_name' => 'client',
            'model_id' => $cliente->id,
            'moneda_id' => 1,
            'user_id' => $dueno_id,
            'saldo' => 17362.5,
        ]);
        CurrentAcount::create([
            'detalle' => 'Movimiento anterior',
            'debe' => 15000,
            'saldo' => 15000,
            'status' => 'sin_pagar',
            'client_id' => $cliente->id,
            'user_id' => $dueno_id,
            'credit_account_id' => $cuenta->id,
            'is_provisorio' => 0,
        ]);
        CurrentAcount::create([
            'detalle' => 'Venta N° 1520',
            'debe' => 2362.5,
            'saldo' => 17362.5,
            'status' => 'sin_pagar',
            'client_id' => $cliente->id,
            'sale_id' => $venta->id,
            'user_id' => $dueno_id,
            'credit_account_id' => $cuenta->id,
            'is_provisorio' => 0,
        ]);

        $this->fixture_de_venta = [
            'cliente' => $cliente,
            'empleado' => $empleado,
            'vendedor' => $vendedor,
            'caja_mostrador' => $caja_mostrador,
            'caja_posnet' => $caja_posnet,
            'condicion' => $condicion,
        ];

        return Sale::find($venta->id);
    }

    /**
     * Completa la venta COMPLETA con lo que piden los campos de la segunda tanda del catálogo: el
     * CUIL del cliente y el código postal de su localidad (2000), el presupuesto (N° 318) y el
     * pedido online (N° 87) de los que salió, su factura B autorizada (00001-00000027, con CAE),
     * el total facturado ($2.362,50), los datos de envío de la etiqueta, el acopio y el incoterm
     * (FOB). Aparte de crear_venta_completa() para no cambiarle la venta a los tests que ya la usan.
     *
     * @param \App\Models\Sale $venta La de crear_venta_completa().
     * @return \App\Models\Sale
     */
    protected function completar_venta_con_origen_factura_y_envio($venta)
    {
        $dueno_id = $this->dueno->id;
        $cliente = $venta->client;

        $cliente->cuil = '20-12345678-3';
        $cliente->save();
        $cliente->location->codigo_postal = '2000';
        $cliente->location->save();

        $presupuesto = Budget::create([
            'num' => 318,
            'user_id' => $dueno_id,
            'client_id' => $cliente->id,
            'budget_status_id' => 1,
            'total' => 0,
            'moneda_id' => 1,
        ]);
        $comprador = Buyer::create(['name' => 'Juan', 'surname' => 'Perez', 'email' => 'comprador-'.uniqid().'@test.local', 'user_id' => $dueno_id]);
        $estado = OrderStatus::firstOrCreate(['name' => 'Sin confirmar']);
        $pedido = Order::create([
            'num' => 87,
            'status' => 'unconfirmed',
            'deliver' => 1,
            'buyer_id' => $comprador->id,
            'order_status_id' => $estado->id,
            'user_id' => $dueno_id,
            'total' => 0,
        ]);

        $venta->budget_id = $presupuesto->id;
        $venta->order_id = $pedido->id;
        $venta->total_facturado = 2362.5;
        $venta->en_acopio = 1;
        $venta->incoterms = 'FOB';
        $venta->save();

        SaleDeliveryInfo::create([
            'sale_id' => $venta->id,
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'phone' => '1155555555',
            'dni' => '12345678',
            'cuit' => '',
            'locality' => 'Rosario',
            'province' => 'Santa Fe',
            'postal_code' => '2000',
            'email' => 'juan-test@correo.local',
        ]);

        $this->crear_factura($venta, 'B');

        return Sale::find($venta->id);
    }

    /**
     * Comprobante de ARCA autorizado (con CAE) de la venta, con los importes ya declarados
     * (snapshot), que es de donde los lee el pie fiscal.
     *
     * @param \App\Models\Sale $venta
     * @param string           $letra
     * @return \App\Models\AfipTicket
     */
    protected function crear_factura($venta, $letra = 'B')
    {
        $afip_information = AfipInformation::where('user_id', $this->dueno->id)->first();

        return AfipTicket::create([
            'sale_id' => $venta->id,
            'afip_information_id' => $afip_information ? $afip_information->id : null,
            'cuit_negocio' => '30111111118',
            'iva_negocio' => 'Responsable inscripto',
            'punto_venta' => 1,
            'cbte_numero' => 27,
            'cbte_letra' => $letra,
            'cbte_tipo' => $letra === 'A' ? 1 : 6,
            'importe_total' => 2362.5,
            'moneda_id' => 'PES',
            'cuit_cliente' => '20123456789',
            'iva_cliente' => 'Responsable inscripto',
            'cae' => '76123456789012',
            'cae_expired_at' => '2026-10-11',
            'imp_total_enviado' => 2362.5,
            'imp_neto_enviado' => 1952.48,
            'imp_iva_enviado' => 410.02,
            'imp_tot_conc_enviado' => 0,
            'imp_op_ex_enviado' => 0,
            /** El modelo lo castea a array: va el array, no el JSON. */
            'iva_detalle_enviado_json' => [['Id' => 5, 'BaseImp' => 1952.48, 'Importe' => 410.02]],
        ]);
    }

    // ── Presupuesto y pedido ───────────────────────────────────────────────────────────────

    /**
     * Presupuesto COMPLETO con la plata del test 10 (la del presupuesto de siempre): 2 x $1.000 con
     * 10% de bonificación ($1.800) y 1 x $500, descuento 10% y recargo 5%: Total $2.173,50. Con
     * vendedor, sucursal, lista de precios, estado y observaciones.
     *
     * @param array $atributos
     * @param bool  $con_ajustes Con el descuento y el recargo.
     * @return \App\Models\Budget
     */
    protected function crear_presupuesto_completo(array $atributos = [], $con_ajustes = true)
    {
        $dueno_id = $this->dueno->id;

        $empleado = User::create([
            'name' => 'Vendedora Presupuesto',
            'email' => 'pdf-vendedora-'.uniqid().'@test.local',
            'password' => 'x',
            'owner_id' => $dueno_id,
        ]);
        $sucursal = Address::create(['street' => 'Mitre', 'street_number' => '980', 'city' => 'La Plata', 'user_id' => $dueno_id]);
        $lista = PriceType::create(['name' => 'Lista Presupuesto Test', 'user_id' => $dueno_id]);
        $estado = BudgetStatus::firstOrCreate(['name' => 'Sin confirmar']);

        $taladro = $this->crear_articulo('Taladro percutor 13mm', ['bar_code' => '7791111111111']);
        $mecha = $this->crear_articulo('Mecha widia 8mm', ['bar_code' => '7792222222222']);

        $budget = $this->crear_presupuesto([
            ['article' => $taladro, 'amount' => 2, 'price' => 1000, 'bonus' => 10],
            ['article' => $mecha, 'amount' => 1, 'price' => 500, 'bonus' => null],
        ], array_merge([
            'employee_id' => $empleado->id,
            'address_id' => $sucursal->id,
            'price_type_id' => $lista->id,
            'budget_status_id' => $estado->id,
            'observations' => 'Entrega en 48 horas habiles',
            'total' => 2173.5,
        ], $atributos));

        if ($con_ajustes) {
            $descuento = Discount::create(['name' => 'Descuento por volumen', 'percentage' => 10, 'user_id' => $dueno_id]);
            $recargo = Surchage::create(['name' => 'Recargo financiero', 'percentage' => 5, 'user_id' => $dueno_id]);
            $budget->discounts()->attach($descuento->id, ['percentage' => 10]);
            $budget->surchages()->attach($recargo->id, ['percentage' => 5]);
        }

        return $budget->fresh();
    }

    /**
     * Pedido online COMPLETO: comprador vinculado a un cliente con CUIT, envío por correo
     * (Andreani · Estándar, $500, CP 1900), cupón PROMO10, recargo del 10% por medio de pago,
     * vendedor, depósito, fecha de entrega y notas. Renglones: 2 x $100 + 3 x $50 = $350.
     *
     * @param array $atributos
     * @param bool  $con_extras Con envío, cupón y recargo por medio de pago (D6: se rotula "Subtotal").
     * @return \App\Models\Order
     */
    protected function crear_pedido_completo(array $atributos = [], $con_extras = true)
    {
        $dueno_id = $this->dueno->id;

        $cliente = Client::create(['name' => 'Cliente Vinculado ERP', 'cuit' => '20333333336', 'user_id' => $dueno_id]);
        $comprador = Buyer::create([
            'name' => 'Marta',
            'surname' => 'Gomez',
            'phone' => '2216001122',
            'email' => 'marta-'.uniqid().'@test.local',
            'city' => 'La Plata',
            'address' => 'Calle 7 N 100',
            'envio_zipcode' => '1900',
            'user_id' => $dueno_id,
            'comercio_city_client_id' => $cliente->id,
        ]);
        $vendedor = Seller::create(['num' => 3, 'name' => 'Carla Pedido', 'user_id' => $dueno_id]);
        $deposito = Address::create(['street' => 'Belgrano', 'street_number' => '450', 'city' => 'Rosario', 'user_id' => $dueno_id]);
        $medio = PaymentMethod::create(['name' => 'Mercado Pago Test', 'user_id' => $dueno_id]);
        $estado = OrderStatus::firstOrCreate(['name' => 'Sin confirmar']);

        $extras = [];
        if ($con_extras) {
            $cupon = Cupon::create(['num' => 1, 'code' => 'PROMO10', 'percentage' => 10, 'user_id' => $dueno_id]);
            $extras = [
                'cupon_id' => $cupon->id,
                'payment_method_surchage' => 10,
                'envio_opcion' => ['carrier_name' => 'Andreani', 'service_name' => 'Estándar'],
                'envio_precio' => 500,
                'envio_destino' => ['codigo_postal' => '1900', 'calle' => 'Calle 7', 'numero' => '100', 'localidad' => 'La Plata'],
            ];
        }

        $pedido = Order::create(array_merge([
            'num' => 5001,
            'status' => 'unconfirmed',
            'deliver' => 1,
            'buyer_id' => $comprador->id,
            'order_status_id' => $estado->id,
            'user_id' => $dueno_id,
            'total' => 350,
            'description' => 'Tocar timbre dos veces',
            'address' => 'Calle 7 N 100 piso 2',
            'seller_id' => $vendedor->id,
            'address_id' => $deposito->id,
            'payment_method_id' => $medio->id,
            'fecha_entrega' => '2026-10-03 00:00:00',
        ], $extras, $atributos));

        $tornillos = $this->crear_articulo('Tornillos x100', ['bar_code' => '7793333333333']);
        $camisa = $this->crear_articulo('Camisa de trabajo', ['bar_code' => '7794444444444']);
        $pedido->articles()->attach($tornillos->id, ['price' => 100, 'amount' => 2]);
        $pedido->articles()->attach($camisa->id, ['price' => 50, 'amount' => 3]);

        return $pedido->fresh();
    }

    /**
     * Arma el PDF de un presupuesto o pedido con un perfil, SIN emitirlo (el render de siempre o
     * con cajas, según el perfil tenga `page_layout`).
     *
     * @param \App\Http\Controllers\Helpers\PdfDocument\PdfDocumentSource $documento
     * @param \App\Models\PdfColumnProfile                                $perfil
     * @return string
     */
    protected function pdf_de_documento($documento, PdfColumnProfile $perfil)
    {
        return $this->renderizar($documento, $perfil);
    }

    // ── El PDF ────────────────────────────────────────────────────────────────────────────

    /**
     * Arma el PDF de la venta con el diseño, SIN emitirlo, con la compresión apagada.
     *
     * @param \App\Models\Sale                  $venta
     * @param \App\Models\PdfColumnProfile      $perfil
     * @param mixed                             $afip_ticket_id
     * @return string
     */
    protected function pdf_de_venta($venta, PdfColumnProfile $perfil, $afip_ticket_id = null)
    {
        $pdf = new SaleLayoutPdf(Sale::find($venta->id), $perfil, $afip_ticket_id);
        $pdf->SetCompression(false);
        $pdf->render();

        return $pdf->Output('S');
    }

    /**
     * Los textos del PDF ya legibles: sin el escape de FPDF (`\(`, `\)`, `\\`) y de vuelta en UTF-8
     * (Cell() los escribe en Latin-1).
     *
     * @param string $pdf
     * @return array<int, string>
     */
    protected function textos_legibles($pdf)
    {
        $textos = [];

        foreach ($this->textos_del_pdf($pdf) as $texto) {
            $sin_escape = preg_replace_callback('~\\\\(.)~s', function ($m) {
                return $m[1];
            }, $texto);
            $textos[] = utf8_encode($sin_escape);
        }

        return $textos;
    }

    /**
     * Afirma que el PDF dibuja un texto entero (un solo `Tj`).
     *
     * @param string $texto
     * @param string $pdf
     * @param string $mensaje
     * @return void
     */
    protected function assertDibujaTexto($texto, $pdf, $mensaje = '')
    {
        $this->assertContains($texto, $this->textos_legibles($pdf), trim($mensaje.' No se dibujó el texto: '.$texto));
    }

    /**
     * Afirma que el PDF NO dibuja un texto entero.
     *
     * @param string $texto
     * @param string $pdf
     * @param string $mensaje
     * @return void
     */
    protected function assertNoDibujaTexto($texto, $pdf, $mensaje = '')
    {
        $this->assertNotContains($texto, $this->textos_legibles($pdf), trim($mensaje.' Se dibujó el texto: '.$texto));
    }

    /**
     * Afirma un renglón "rótulo + valor" a la izquierda: el rótulo y el valor son dos textos
     * seguidos, como los dibuja el bloque del cliente de siempre (print_label_value_line()).
     *
     * @param string $rotulo Con los dos puntos y el espacio ("Cliente: ").
     * @param string $valor
     * @param string $pdf
     * @return void
     */
    protected function assertRenglon($rotulo, $valor, $pdf)
    {
        $textos = $this->textos_legibles($pdf);

        foreach ($textos as $i => $texto) {
            if ($texto === $rotulo && isset($textos[$i + 1]) && $textos[$i + 1] === $valor) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail('No se dibujó el renglón "'.$rotulo.$valor.'".');
    }

    /**
     * Cuántas veces se dibuja un texto entero.
     *
     * @param string $texto
     * @param string $pdf
     * @return int
     */
    protected function veces_que_se_dibuja($texto, $pdf)
    {
        return count(array_keys($this->textos_legibles($pdf), $texto, true));
    }

    /**
     * La fuente y el tamaño con que se dibuja un texto: el último `Tf` antes de su `Tj`, y el
     * nombre de la fuente que esa `/F<n>` tiene en el diccionario de recursos.
     *
     * @param string $texto
     * @param string $pdf
     * @return array{fuente: string, tamano: float}|null
     */
    protected function estilo_del_texto($texto, $pdf)
    {
        $escapado = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], utf8_decode($texto));
        $posicion = strpos($pdf, '('.$escapado.') Tj');

        if ($posicion === false) {
            return null;
        }

        preg_match_all('~/F(\d+) ([\d.]+) Tf~', substr($pdf, 0, $posicion), $tf, PREG_SET_ORDER);
        if (count($tf) === 0) {
            return null;
        }
        $ultimo = end($tf);

        preg_match_all('~/F(\d+) (\d+) 0 R~', $pdf, $recursos, PREG_SET_ORDER);
        $objeto = null;
        foreach ($recursos as $recurso) {
            if ($recurso[1] === $ultimo[1]) {
                $objeto = $recurso[2];
            }
        }

        $fuente = null;
        if (! is_null($objeto) && preg_match('~\n'.$objeto.' 0 obj\s*<</Type /Font\s*/BaseFont /([A-Za-z-]+)~', $pdf, $m)) {
            $fuente = $m[1];
        }

        return ['fuente' => $fuente, 'tamano' => (float) $ultimo[2]];
    }

    /**
     * El punto más bajo dibujado en cada hoja, en mm desde el borde de arriba: la base de cada
     * texto, el borde de abajo de cada rectángulo y de cada imagen, y los extremos de cada línea.
     *
     * 🔴 Acepta coordenadas NEGATIVAS: lo que se dibuja por debajo del papel, FPDF lo escribe con
     * una y negativa (el origen del PDF está abajo). Un lector que solo acepta dígitos no lo ve, y
     * es justamente lo que se quiere detectar.
     *
     * @param string $pdf
     * @return array<int, float> Una entrada por hoja.
     */
    protected function punto_mas_bajo_por_hoja($pdf)
    {
        $k = 72 / 25.4;
        $numero = '(-?[\d.]+)';

        preg_match('~/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]~', $pdf, $mb);
        $alto_de_hoja = (float) $mb[2] / $k;

        $por_hoja = [];
        foreach ($this->hojas($pdf) as $contenido) {
            $mas_bajo = 0;

            preg_match_all('~BT '.$numero.' '.$numero.' Td~', $contenido, $textos, PREG_SET_ORDER);
            foreach ($textos as $t) {
                $mas_bajo = max($mas_bajo, $alto_de_hoja - (float) $t[2] / $k);
            }

            preg_match_all('~'.$numero.' '.$numero.' '.$numero.' '.$numero.' re~', $contenido, $rects, PREG_SET_ORDER);
            foreach ($rects as $r) {
                $mas_bajo = max($mas_bajo, $alto_de_hoja - (float) $r[2] / $k - (float) $r[4] / $k);
            }

            preg_match_all('~'.$numero.' '.$numero.' m '.$numero.' '.$numero.' l S~', $contenido, $lineas, PREG_SET_ORDER);
            foreach ($lineas as $l) {
                $mas_bajo = max($mas_bajo, $alto_de_hoja - (float) $l[2] / $k, $alto_de_hoja - (float) $l[4] / $k);
            }

            preg_match_all('~q '.$numero.' 0 0 '.$numero.' '.$numero.' '.$numero.' cm /I\d+ Do Q~', $contenido, $imagenes, PREG_SET_ORDER);
            foreach ($imagenes as $i) {
                $mas_bajo = max($mas_bajo, $alto_de_hoja - (float) $i[4] / $k);
            }

            $por_hoja[] = $mas_bajo;
        }

        return $por_hoja;
    }

    /**
     * Afirma que en ninguna hoja se dibuja nada por debajo de $limite_mm (mm desde arriba).
     *
     * @param string $pdf
     * @param float  $limite_mm
     * @return void
     */
    protected function assertNadaDebajoDe($pdf, $limite_mm)
    {
        foreach ($this->punto_mas_bajo_por_hoja($pdf) as $numero => $mas_bajo) {
            /** 0,05 mm de tolerancia: FPDF redondea las coordenadas a centésimos de punto. */
            $this->assertLessThanOrEqual(
                $limite_mm + 0.05,
                $mas_bajo,
                'La hoja '.($numero + 1).' dibuja hasta '.round($mas_bajo, 1).' mm, por debajo del límite de '.$limite_mm.' mm.'
            );
        }
    }

    /**
     * Corre $callback con el `https` de PHP cortado: el pie de la factura de ARCA le pega a
     * api.qrserver.com para dibujar el QR (`AfipPdfHelper::print_afip_qr_image()`), y un test no
     * sale a la red. Con la red cortada `GeneralHelper::file_exists_2()` da false y el pie se dibuja
     * igual, sin la imagen del QR (que no es lo que se mide).
     *
     * @param callable $callback
     * @return mixed
     */
    protected function sin_red_https(callable $callback)
    {
        $tenia_https = in_array('https', stream_get_wrappers(), true);
        if ($tenia_https) {
            stream_wrapper_unregister('https');
        }
        stream_wrapper_register('https', RedHttpsCortadaParaTests::class);

        try {
            return $callback();
        } finally {
            stream_wrapper_unregister('https');
            if ($tenia_https) {
                stream_wrapper_restore('https');
            }
        }
    }
}

/**
 * Envoltorio de `https` que no abre nada: lo registra `sin_red_https()` mientras dura un test.
 */
class RedHttpsCortadaParaTests
{
    /** @var resource|null Lo setea PHP en todo envoltorio de flujo. */
    public $context;

    /**
     * @return bool
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        return false;
    }

    /**
     * @return array|false
     */
    public function url_stat($path, $flags)
    {
        return false;
    }
}
