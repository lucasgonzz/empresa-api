<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\Order\ComboEsquemaHelper;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Models\ArticleVariant;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\Combo;
use App\Models\Cupon;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\PromocionVinoteca;
use Tests\Concerns\DocumentosParaPdf;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * El PDF del pedido online dibujado con un diseño (`ProfileDocumentPdf` + `OrderPdfDocument`)
 * (misión pdf-presupuestos-y-pedidos-personalizables, 29/9/2026).
 *
 * 🔴 Lo más importante es lo que NO se hace con la plata. `orders.total` guarda SOLO el subtotal
 * de los renglones: no incluye el envío, el cupón ni el recargo o descuento del medio de pago.
 * Imprimir un "Total" que no es lo que pagó el comprador es peor que rotularlo "Subtotal", así que:
 * pedido sin extras -> "Total"; pedido con envío, cupón o ajuste por medio de pago -> "Subtotal" y
 * esas cosas como líneas informativas SIN sumar. El test de los extras verifica las dos cosas:
 * que el rótulo cambia y que NINGÚN importe suma los extras.
 *
 * Igual que en el presupuesto, nada se emite: `render()` + `Output('S')`.
 *
 * @group pdf-documentos
 */
class Render_de_pedido_con_perfil_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use PedidosDePrueba;

    /** CUIT del cliente del ERP vinculado al comprador. */
    const CUIT_VINCULADO = '20333333336';

    /**
     * Subtotal del pedido de prueba: 2 x 100 + 3 x 50 + 1 x 800 + 2 x 1500.
     */
    const SUBTOTAL = 4150;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf();
        $this->sembrar_estados_de_pedido();
    }

    protected function tearDown(): void
    {
        $this->borrar_archivos_de_prueba();

        parent::tearDown();
    }

    /**
     * Pedido online del dueño con artículos (uno con notas y otro con variante), una promoción de
     * vinoteca y un combo. Su subtotal es `self::SUBTOTAL`.
     *
     * @param array $atributos            Atributos del pedido que pisan los de por defecto.
     * @param bool  $con_cliente_vinculado El comprador está vinculado a un cliente del ERP.
     * @param bool  $con_promo_y_combo    Agrega la promoción y el combo.
     * @return \App\Models\Order
     */
    protected function crear_pedido_online($atributos = [], $con_cliente_vinculado = false, $con_promo_y_combo = true)
    {
        $cliente = null;
        if ($con_cliente_vinculado) {
            $cliente = Client::create([
                'name'    => 'Cliente Vinculado ERP',
                'cuit'    => self::CUIT_VINCULADO,
                'user_id' => $this->dueno->id,
            ]);
        }

        $comprador = Buyer::create([
            'name'                    => 'Marta',
            'surname'                 => 'Gomez',
            'phone'                   => '2216001122',
            'email'                   => 'marta-'.uniqid().'@test.local',
            'city'                    => 'La Plata',
            'address'                 => 'Calle 7 N 100',
            'user_id'                 => $this->dueno->id,
            'comercio_city_client_id' => $cliente ? $cliente->id : null,
        ]);

        $pedido = Order::create(array_merge([
            'num'             => 5001,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => $this->estado('Sin confirmar')->id,
            'user_id'         => $this->dueno->id,
            'total'           => self::SUBTOTAL,
            'description'     => 'Tocar timbre dos veces',
            'address'         => 'Calle 7 N 100 piso 2',
        ], $atributos));

        $bolsa = $this->crear_articulo('Tornillos x100', ['bar_code' => '7793333333333']);
        $camisa = $this->crear_articulo('Camisa de trabajo', ['bar_code' => '7794444444444']);
        $variante = ArticleVariant::create(['article_id' => $camisa->id, 'variant_description' => 'Rojo XL']);

        $pedido->articles()->attach($bolsa->id, ['price' => 100, 'amount' => 2, 'notes' => 'Sin bolsa']);
        $pedido->articles()->attach($camisa->id, ['price' => 50, 'amount' => 3, 'variant_id' => $variante->id]);

        if ($con_promo_y_combo) {
            $promo = PromocionVinoteca::create(['name' => 'Promo dos vinos', 'user_id' => $this->dueno->id]);
            $pedido->promocion_vinotecas()->attach($promo->id, ['cost' => 0, 'price' => 800, 'amount' => 1]);

            if (ComboEsquemaHelper::hay_tabla()) {
                $combo = Combo::create(['num' => 1, 'name' => 'Combo asado', 'price' => 1500, 'user_id' => $this->dueno->id]);
                $pedido->combos()->attach($combo->id, ['amount' => 2, 'price' => 1500, 'cost' => 0, 'notes' => 'Sin sal']);
            }
        }

        return $pedido->fresh();
    }

    /**
     * @param \App\Models\Order $pedido
     * @return string
     */
    protected function pdf_del_pedido($pedido)
    {
        return $this->renderizar(new OrderPdfDocument($pedido), $this->diseno('order', 'Pedido online'));
    }

    /**
     * @test
     */
    public function un_pedido_con_articulos_promocion_combo_y_notas_genera_el_pdf_con_todo()
    {
        if (! ComboEsquemaHelper::hay_tabla()) {
            $this->markTestSkipped('La base de testing no tiene order_combo.');
        }

        $pdf = $this->pdf_del_pedido($this->crear_pedido_online());

        $this->assertSame(0, strpos($pdf, '%PDF'));
        $this->assertPdfContiene('(Pedido online)', $pdf, 'El encabezado tiene que decir que es un pedido online.');

        /** Renglones de las tres clases, con la variante y las notas de cada uno. */
        $this->assertPdfContiene('(Tornillos x100)', $pdf);
        $this->assertPdfContiene('(Sin bolsa)', $pdf, 'Faltan las notas del renglon.');
        $this->assertPdfContiene('(Camisa de trabajo Rojo XL)', $pdf, 'Falta la variante que eligio el comprador.');
        $this->assertPdfContiene('(Promo dos vinos)', $pdf, 'Falta la promocion.');
        $this->assertPdfContiene('(Combo asado)', $pdf, 'Falta el combo.');
        $this->assertPdfContiene('(Sin sal)', $pdf, 'Faltan las notas del combo.');

        /** Los importes de cada renglón y el total: nada de esto trae extras, así que es "Total". */
        $this->assertPdfContiene('($200)', $pdf);
        $this->assertPdfContiene('($150)', $pdf);
        $this->assertPdfContiene('($800)', $pdf);
        $this->assertPdfContiene('($3.000)', $pdf);
        $this->assertPdfContiene('(Total: $4.150)', $pdf);
        $this->assertPdfNoContiene('(Subtotal:', $pdf, 'Un pedido sin extras se rotula Total.');

        /** Las notas del pedido, en su recuadro. */
        $this->assertPdfContiene('(NOTAS DEL PEDIDO)', $pdf);
        $this->assertPdfContiene('Tocar timbre dos veces', $pdf);
    }

    /**
     * El comprador NO tiene cliente vinculado (el caso normal): el encabezado imprime lo que hay del
     * comprador y ningún CUIT de cliente. `Buyer->client` no existe y `buyers` no tiene `cuit`.
     *
     * @test
     */
    public function el_comprador_sin_cliente_vinculado_se_imprime_sin_cuit()
    {
        $pdf = $this->pdf_del_pedido($this->crear_pedido_online([], false, false));

        $this->assertPdfContiene('Marta Gomez', $pdf, 'Falta el nombre del comprador.');
        $this->assertPdfContiene('2216001122', $pdf, 'Falta el telefono del comprador.');
        $this->assertPdfContiene('La Plata', $pdf, 'Falta la localidad del comprador.');
        $this->assertPdfContiene('Calle 7 N 100 piso 2', $pdf, 'Falta la direccion del pedido.');
        $this->assertPdfNoContiene(self::CUIT_VINCULADO, $pdf);
    }

    /**
     * @test
     */
    public function el_comprador_con_cliente_vinculado_imprime_el_cuit_del_cliente()
    {
        $pdf = $this->pdf_del_pedido($this->crear_pedido_online([], true, false));

        $this->assertPdfContiene(self::CUIT_VINCULADO, $pdf, 'Falta el CUIT del cliente vinculado.');
        $this->assertPdfContiene('Marta Gomez', $pdf);
    }

    /**
     * 🔴 D6, rama de los extras: con envío, cupón o ajuste por medio de pago la línea se rotula
     * "Subtotal", los extras van como líneas informativas y NINGÚN importe los suma.
     *
     * @test
     */
    public function con_envio_cupon_y_medio_de_pago_se_rotula_subtotal_y_los_extras_no_se_suman()
    {
        $cupon = Cupon::create(['num' => 1, 'code' => 'PROMO10', 'percentage' => 10, 'user_id' => $this->dueno->id]);

        $pedido = $this->crear_pedido_online([
            'cupon_id'                => $cupon->id,
            'payment_method_surchage' => 10,
            'envio_opcion'            => ['correo' => 'Correo de prueba'],
            'envio_precio'            => 500,
        ], false, true);

        $pdf = $this->pdf_del_pedido($pedido);

        $this->assertPdfContiene('(Subtotal: $4.150)', $pdf);
        $this->assertPdfContiene('('.$this->en_el_pdf('Envío: $500').')', $pdf, 'Falta la linea del envio.');
        $this->assertPdfContiene('('.$this->en_el_pdf('Cupón: PROMO10').')', $pdf, 'Falta la linea del cupon.');
        $this->assertPdfContiene('(Medio de pago: +10%)', $pdf, 'Falta la linea del medio de pago.');

        /** No hay "Total" (no se sabe lo que pagó) y ningún importe suma los extras. */
        $this->assertPdfNoContiene('(Total: $', $pdf, 'Con extras no se imprime un Total que no es lo que pago el comprador.');
        foreach (['4.650', '4.565', '4.150,00'] as $suma_indebida) {
            $this->assertPdfNoContiene($suma_indebida, $pdf, 'Un importe suma los extras al subtotal.');
        }
    }

    /**
     * @test
     */
    public function el_descuento_por_medio_de_pago_y_la_zona_de_envio_propia_tambien_son_extras()
    {
        $zona = DeliveryZone::create(['name' => 'Zona centro', 'price' => 300, 'user_id' => $this->dueno->id]);

        $pedido = $this->crear_pedido_online([
            'payment_method_discount' => 5,
            'delivery_zone_id'        => $zona->id,
        ], false, false);

        $pdf = $this->pdf_del_pedido($pedido);

        $this->assertPdfContiene('(Subtotal: $350)', $pdf);
        $this->assertPdfContiene('(Medio de pago: -5%)', $pdf);
        $this->assertPdfContiene('('.$this->en_el_pdf('Envío: $300').')', $pdf);
        $this->assertPdfNoContiene('(Total: $', $pdf);
    }

    /**
     * Un envío gratis (o un cupón que no existe más, o nada) no cambia lo que paga el comprador: el
     * pedido sigue siendo "Total".
     *
     * @test
     */
    public function un_envio_gratis_no_es_un_extra()
    {
        $pedido = $this->crear_pedido_online(['envio_opcion' => ['correo' => 'Correo de prueba'], 'envio_precio' => 0], false, false);

        $pdf = $this->pdf_del_pedido($pedido);

        $this->assertPdfContiene('(Total: $350)', $pdf);
        $this->assertPdfNoContiene('(Subtotal:', $pdf);
    }

    /**
     * `orders.total` es nullable y no se lee: el Total sale de los renglones.
     *
     * @test
     */
    public function un_pedido_con_total_null_no_rompe_el_pdf()
    {
        $pdf = $this->pdf_del_pedido($this->crear_pedido_online(['total' => null], false, false));

        $this->assertSame(0, strpos($pdf, '%PDF'));
        $this->assertPdfContiene('(Total: $350)', $pdf, 'El total sale de los renglones, no de orders.total.');
    }

    /**
     * Un pedido cuyo comprador ya no existe no rompe el PDF: simplemente no hay bloque de cliente.
     *
     * @test
     */
    public function un_pedido_sin_comprador_no_rompe_el_pdf()
    {
        $pedido = $this->crear_pedido_online([], false, false);
        $pedido->buyer_id = 0;
        $pedido->save();

        $pdf = $this->pdf_del_pedido($pedido->fresh());

        $this->assertSame(0, strpos($pdf, '%PDF'));
        $this->assertPdfContiene('(Pedido online)', $pdf);
        $this->assertPdfContiene('(Tornillos x100)', $pdf);
        $this->assertPdfNoContiene('Marta Gomez', $pdf);
    }

    /**
     * Sin notas no se dibuja el recuadro, y el diseño del pedido tiene las columnas del plan.
     *
     * @test
     */
    public function sin_notas_no_hay_recuadro_y_las_columnas_son_las_del_diseno()
    {
        $pdf = $this->pdf_del_pedido($this->crear_pedido_online(['description' => null], false, false));

        $this->assertPdfNoContiene('(NOTAS DEL PEDIDO)', $pdf);

        foreach (['(#)', '(Cod. barras)', '(Nombre)', '(Cant)', '(Precio)', '(Notas)', '(Sub total)'] as $encabezado) {
            $this->assertPdfContiene($encabezado, $pdf, 'Falta la columna '.$encabezado.' del diseno del pedido.');
        }
        $this->assertPdfNoContiene('(Bonif)', $pdf, 'El pedido no tiene bonificacion por renglon.');
    }

    /**
     * El dueño del pedido es quien manda: el logo, el emisor y el diseño salen de `orders.user_id`,
     * no del usuario logueado.
     *
     * @test
     */
    public function el_emisor_del_encabezado_es_el_dueno_del_pedido()
    {
        $pdf = $this->pdf_del_pedido($this->crear_pedido_online([], false, false));

        $this->assertPdfContiene('Razon Social PDF SA', $pdf, 'El emisor es el dueno del pedido.');
        $this->assertPdfContiene('30111111118', $pdf, 'El CUIT del emisor es el del dueno del pedido.');
    }
}
