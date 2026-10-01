<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use App\Services\PdfColumnService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Archivo 20 — las ocho columnas de costo y precio de los artículos de una venta en los
 * PDF Column Profile (misión columnas-costo-y-precio-en-articulos-de-venta, 1/10/2026).
 *
 * Lucas pidió poder elegir en el perfil de PDF de ventas: costo unitario, costo total, precio
 * unitario, precio total, precio con IVA unitario/total y precio sin IVA unitario/total.
 * Seis ya existían bajo otro nombre (`item_cost`, `item_price`, `item_subtotal`,
 * `item_price_without_iva`, `item_subtotal_without_iva`, `item_subtotal_with_iva`) y dos son
 * nuevas (`item_cost_total`, `item_price_with_iva`). Lo que se prueba acá:
 *
 *  1. El catálogo de la venta ofrece las ocho y se sincroniza sin duplicar filas. 🔴 Los `name`
 *     de las opciones YA existentes NO se renombran: PdfColumnProfileSeederHelper::
 *     assign_profile_options() y PdfColumnRemitoSetupHelper los usan como CLAVE para armar los
 *     perfiles, y un nombre que no existe se descarta en silencio (el remito nuevo quedaba sin
 *     columna de total). Este test fija los nombres para que el próximo renombre falle acá.
 *  2. Las cuentas: costo total = costo × cantidad; con y sin IVA cierran contra el precio del
 *     renglón, con el descuento de línea aplicado en los totales.
 *  3. Sin comprobante ARCA (remito): "Precio con IVA total" ya no sale vacío y "Precio sin IVA
 *     total" lleva el descuento de línea.
 *  4. Con comprobante ARCA: unitario × cantidad == total, por el mismo cálculo del comprobante.
 *  5. Venta en dólares: los importes salen convertidos a pesos, una sola vez.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group pdf
 */
class Columnas_de_costo_y_precio_de_venta_Test extends EmpresaTestCase
{
    /**
     * Delta para comparaciones de plata (un centavo).
     */
    const DELTA = 0.01;

    /**
     * Arma una venta del comercio del fixture con un renglón del artículo centinela (IVA 21 %).
     *
     * `price_sin_iva` se calcula con `SaleHelper::get_price_sin_iva()`, igual que lo persiste
     * `SaleHelper::attachArticle()` en producción.
     *
     * @param  float       $price      Precio unitario CON IVA.
     * @param  int         $amount     Cantidad.
     * @param  float|null  $cost       Costo unitario congelado (null = sin costo).
     * @param  float|null  $discount   Descuento de línea en %.
     * @param  int         $moneda_id  1 = pesos, 2 = dólares.
     * @param  float|null  $valor_dolar Cotización de la venta (solo si moneda_id = 2).
     * @return array       ['sale' => Sale, 'item' => Article con pivot]
     */
    protected function armar_venta_con_renglon($price, $amount, $cost = null, $discount = null, $moneda_id = 1, $valor_dolar = null)
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();
        $this->assertNotNull($client, 'Falta el cliente Responsable Inscripto del fixture.');

        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $this->assertNotNull($articulo, 'Falta el artículo centinela del fixture.');
        $this->assertNotNull($articulo->iva, 'El artículo centinela del fixture no tiene IVA.');

        $sale = Sale::create([
            'user_id'                          => $user->id,
            'client_id'                        => $client->id,
            'omitir_en_cuenta_corriente'       => 0,
            'save_current_acount'              => 0,
            'terminada'                        => 1,
            'is_cerrada'                       => 0,
            'sub_total'                        => $price * $amount,
            'total'                            => $price * $amount,
            'moneda_id'                        => $moneda_id,
            'valor_dolar'                      => $valor_dolar,
            'descuento'                        => 0,
            'aplicar_recargos_directo_a_items' => 0,
        ]);

        $sale->articles()->attach($articulo->id, [
            'amount'         => $amount,
            'price'          => $price,
            'cost'           => $cost,
            'discount'       => $discount,
            'price_sin_iva'  => SaleHelper::get_price_sin_iva(['id' => $articulo->id], $price),
            'iva_percentage' => $articulo->iva->percentage,
        ]);

        $sale = $sale->fresh();
        $item = $sale->articles->first();
        $this->assertNotNull($item, 'La venta del test se quedó sin renglones.');

        // Misma marca que NewSalePdf::get_sale_items() pone antes de resolver columnas.
        $item->is_article = true;

        return ['sale' => $sale, 'item' => $item];
    }

    /**
     * Contexto de PdfColumnService::resolve_value() con las claves que arma NewSalePdf.
     * Con $con_comprobante = true se arma el AfipHelper en memoria (factura A), como el test 5
     * de Facturacion; con false es un remito sin comprobante ARCA.
     *
     * @param  \App\Models\Sale  $sale
     * @param  mixed             $item
     * @param  bool              $con_comprobante
     * @return array
     */
    protected function armar_contexto($sale, $item, $con_comprobante)
    {
        $afip_helper = null;

        if ($con_comprobante) {
            $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
            $afip_ticket = new AfipTicket(['cbte_letra' => 'A', 'cbte_tipo' => 1]);
            $afip_helper = new AfipHelper($afip_ticket, $sale->articles, $sale->services, $user, $sale);
        }

        return [
            'item'        => $item,
            'index'       => 1,
            'sale'        => $sale,
            'afip_ticket' => $afip_helper ? $afip_helper->afip_ticket : null,
            'afip_helper' => $afip_helper,
            'numbers'     => Numbers::class,
        ];
    }

    /**
     * Convierte a float el texto que formatea `Numbers::price()` ("$2.479,35" o "1.200").
     *
     * @param  string  $formateado
     * @return float
     */
    protected function a_numero($formateado)
    {
        $limpio = str_replace('$', '', (string) $formateado);
        $limpio = str_replace('.', '', $limpio);
        $limpio = str_replace(',', '.', $limpio);

        return (float) $limpio;
    }

    /**
     * Resuelve una columna y la devuelve como número.
     *
     * @param  string  $resolver
     * @param  array   $contexto
     * @return float
     */
    protected function valor($resolver, $contexto)
    {
        return $this->a_numero(PdfColumnService::resolve_value($resolver, $contexto));
    }

    /**
     * Test 1 - el catálogo de columnas de venta ofrece las ocho, con los nombres del pedido, y
     * volver a sincronizarlo no duplica filas.
     *
     * @group pdf
     * @test
     */
    public function el_catalogo_de_venta_ofrece_las_ocho_columnas()
    {
        $esperadas = [
            'item_cost'                 => 'Costo',
            'item_cost_total'           => 'Costo total',
            'item_price'                => 'Precio unitario',
            'item_subtotal'             => 'Subtotal línea',
            'item_price_with_iva'       => 'Precio con IVA',
            'item_subtotal_with_iva'    => 'Total con IVA',
            'item_price_without_iva'    => 'Precio sin IVA',
            'item_subtotal_without_iva' => 'Subtotal sin IVA',
        ];

        // Dos lecturas seguidas: la segunda no puede agregar filas (get_options sincroniza siempre).
        $primera = PdfColumnService::get_options('sale');
        $segunda = PdfColumnService::get_options('sale');
        $this->assertCount($primera->count(), $segunda, 'Sincronizar dos veces duplicó columnas.');

        $por_resolver = [];
        foreach ($segunda as $opcion) {
            $por_resolver[$opcion->value_resolver] = $opcion;
        }

        foreach ($esperadas as $resolver => $nombre) {
            $this->assertArrayHasKey($resolver, $por_resolver, 'Falta la columna ' . $resolver . ' en el catálogo de venta.');
            $this->assertSame($nombre, $por_resolver[$resolver]->name, 'Nombre de ' . $resolver);
            $this->assertTrue((bool) $por_resolver[$resolver]->is_active, $resolver . ' tiene que estar activa.');
        }
    }

    /**
     * Test 2 - costo total = costo unitario × cantidad, y sin costo congelado sale vacío (no 0).
     *
     * @group pdf
     * @test
     */
    public function costo_total_es_el_costo_por_la_cantidad_vendida()
    {
        $armado = $this->armar_venta_con_renglon(1000.00, 3, 400.00);
        $contexto = $this->armar_contexto($armado['sale'], $armado['item'], false);

        $this->assertEqualsWithDelta(400.00, $this->valor('item_cost', $contexto), self::DELTA, 'Costo unitario');
        $this->assertEqualsWithDelta(1200.00, $this->valor('item_cost_total', $contexto), self::DELTA, 'Costo total = 400 × 3');

        $sin_costo = $this->armar_venta_con_renglon(1000.00, 3, null);
        $contexto_sin_costo = $this->armar_contexto($sin_costo['sale'], $sin_costo['item'], false);

        $this->assertSame('', PdfColumnService::resolve_value('item_cost_total', $contexto_sin_costo), 'Sin costo no es costo cero.');
    }

    /**
     * Test 3 - remito sin comprobante ARCA, renglón con 10 % de descuento: el precio es con IVA,
     * los totales llevan el descuento, el neto sale de `price_sin_iva` y "Precio con IVA total"
     * ya no sale vacío (antes solo existía con comprobante).
     *
     * Números: precio $1.000 con IVA, cantidad 3, 10 % → total $2.700; neto unitario $826,45
     * (1000 / 1,21), neto total $826,45 × 3 × 0,9 = $2.231,42.
     *
     * @group pdf
     * @test
     */
    public function sin_comprobante_los_totales_llevan_el_descuento_de_linea()
    {
        $armado = $this->armar_venta_con_renglon(1000.00, 3, 400.00, 10);
        $contexto = $this->armar_contexto($armado['sale'], $armado['item'], false);

        $this->assertEqualsWithDelta(1000.00, $this->valor('item_price', $contexto), self::DELTA, 'Precio unitario');
        $this->assertEqualsWithDelta(1000.00, $this->valor('item_price_with_iva', $contexto), self::DELTA, 'Precio con IVA unitario = el del renglón');

        $precio_total = $this->valor('item_subtotal', $contexto);
        $this->assertEqualsWithDelta(2700.00, $precio_total, self::DELTA, 'Precio total');
        $this->assertEqualsWithDelta($precio_total, $this->valor('item_subtotal_with_iva', $contexto), self::DELTA, 'Precio con IVA total no puede quedar vacío ni distinto del total del renglón');

        $this->assertEqualsWithDelta(826.45, $this->valor('item_price_without_iva', $contexto), self::DELTA, 'Precio sin IVA unitario');
        $this->assertEqualsWithDelta(2231.42, $this->valor('item_subtotal_without_iva', $contexto), self::DELTA * 2, 'Precio sin IVA total lleva el descuento de línea');
    }

    /**
     * Test 4 - sin descuento el neto total es el unitario × cantidad (el fallback no inventa nada).
     *
     * @group pdf
     * @test
     */
    public function sin_descuento_el_neto_total_es_el_unitario_por_la_cantidad()
    {
        $armado = $this->armar_venta_con_renglon(1000.00, 3, 400.00, null);
        $contexto = $this->armar_contexto($armado['sale'], $armado['item'], false);

        $this->assertEqualsWithDelta(2479.35, $this->valor('item_subtotal_without_iva', $contexto), self::DELTA, '826,45 × 3');
        $this->assertEqualsWithDelta(3000.00, $this->valor('item_subtotal_with_iva', $contexto), self::DELTA, '1000 × 3');
    }

    /**
     * Test 5 - con comprobante ARCA, "Precio con IVA unitario" × cantidad == "Precio con IVA total"
     * y el neto + el IVA del renglón cierran con el total con IVA (misma fuente de cálculo).
     *
     * @group pdf
     * @test
     */
    public function con_comprobante_el_unitario_por_la_cantidad_es_el_total()
    {
        $armado = $this->armar_venta_con_renglon(1000.00, 3, 400.00, 10);
        $contexto = $this->armar_contexto($armado['sale'], $armado['item'], true);

        $unitario = $this->valor('item_price_with_iva', $contexto);
        $total = $this->valor('item_subtotal_with_iva', $contexto);

        // El comprobante descuenta la bonificación del renglón: 1000 − 10 % = 900.
        $this->assertEqualsWithDelta(900.00, $unitario, self::DELTA, 'Precio con IVA unitario con comprobante');
        $this->assertEqualsWithDelta($unitario * 3, $total, self::DELTA * 3, 'unitario × cantidad == total');

        $neto = $this->valor('item_subtotal_without_iva', $contexto);
        $iva = $this->valor('item_iva_amount', $contexto);
        $this->assertEqualsWithDelta($total, $neto + $iva, self::DELTA * 3, 'neto + IVA == total con IVA');
    }

    /**
     * Test 6 - venta en dólares sin comprobante: precio, total y costo salen convertidos a pesos
     * UNA sola vez (cotización 1.000: USD 10 × 2 unidades → $10.000 unitario, $20.000 total).
     *
     * @group pdf
     * @test
     */
    public function venta_en_dolares_convierte_a_pesos_una_sola_vez()
    {
        $armado = $this->armar_venta_con_renglon(10.00, 2, 4.00, null, 2, 1000);
        $contexto = $this->armar_contexto($armado['sale'], $armado['item'], false);

        $this->assertEqualsWithDelta(10000.00, $this->valor('item_price_with_iva', $contexto), self::DELTA, 'Precio con IVA unitario en pesos');
        $this->assertEqualsWithDelta(20000.00, $this->valor('item_subtotal_with_iva', $contexto), self::DELTA, 'Precio con IVA total en pesos');
        $this->assertEqualsWithDelta(8000.00, $this->valor('item_cost_total', $contexto), self::DELTA, 'Costo total en pesos: 4 × 2 × 1000');
    }

    /**
     * Test 7 - con comprobante y venta en dólares, "Precio con IVA unitario" no se convierte dos
     * veces (la guarda `!$es_usd`: cae al snapshot del pivot, convertido una vez).
     *
     * @group pdf
     * @test
     */
    public function con_comprobante_en_dolares_el_unitario_no_se_convierte_dos_veces()
    {
        $armado = $this->armar_venta_con_renglon(10.00, 2, 4.00, null, 2, 1000);
        $contexto = $this->armar_contexto($armado['sale'], $armado['item'], true);

        $this->assertEqualsWithDelta(10000.00, $this->valor('item_price_with_iva', $contexto), self::DELTA, 'Precio con IVA unitario en pesos, una sola conversión');

        // El total tampoco: USD 10 × 2 × cotización 1000 = $20.000 (antes salía $20.000.000).
        $this->assertEqualsWithDelta(20000.00, $this->valor('item_subtotal_with_iva', $contexto), self::DELTA, 'Precio con IVA total en pesos, una sola conversión');
    }
}
