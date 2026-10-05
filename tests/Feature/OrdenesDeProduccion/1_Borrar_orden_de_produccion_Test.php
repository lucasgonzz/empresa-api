<?php

namespace Tests\Feature\OrdenesDeProduccion;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\DeleteModelsHelper;
use App\Http\Controllers\Helpers\OrderProductionHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Escritura;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\AfipTicket;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\OrderProduction;
use App\Models\OrderProductionStatus;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Borrar una orden de producción da de baja su venta por el flujo real, y si esa venta está
 * facturada no se borra nada (misión orden-produccion-baja-de-venta, 5/10/2026).
 *
 * Lo que pasaba con el `OrderProductionController::destroy()` de antes:
 *
 *  - Borraba el movimiento de cuenta corriente de la orden PRIMERO, fuera de toda transacción, y
 *    después llamaba a `CurrentAcountHelper::checkSaldos('client', $client_id)`, una firma que murió
 *    el 10/9/2025: 500 con la cuenta del cliente ya sin el débito y la orden viva. Al reintentar ya
 *    no había movimiento, se salteaba el bloque y la orden se borraba dejando la venta viva.
 *  - Cuando llegaba a la venta, la borraba con `SaleHelper::deleteSaleFrom()`: un `$sale->delete()`
 *    crudo, sin devolver stock ni sacar nada de la cuenta corriente, y sin mirar si estaba
 *    facturada (su factura desaparecía del Libro IVA mientras seguía vigente en ARCA).
 *
 * El destroy() nuevo busca la venta siempre, pregunta
 * `DeleteSaleHelper::motivo_por_el_que_no_se_puede_eliminar()` antes de tocar nada (422 con el motivo
 * de la venta, prefijado con la orden), y si se puede, hace todo en una transacción: la baja real de
 * la venta (`DeleteSaleHelper::eliminar_venta`), los movimientos de la orden con sus cadenas
 * recalculadas por `credit_account_id`, y la orden.
 *
 * ESCENARIO (fixture). La orden se crea por el endpoint real (`POST api/order-production`). El
 * "terminar" por endpoint (`PUT` con `finished = 1`) está roto desde sep-2025 —500 en
 * `getSaldo()`, deja un movimiento con `credit_account_id` NULL y no crea la venta— y arreglarlo
 * queda fuera de esta misión (decisión de Lucas), así que una orden terminada se arma como la
 * dejaba el módulo cuando andaba: un `CurrentAcount` con `order_production_id`, el cliente y la
 * cuenta en pesos del cliente (`CLIENTE_CC` del fixture), `debe` = total de la orden
 * (`OrderProductionHelper::getTotal`), la cadena recalculada con `check_saldos_y_pagos()`, y la
 * venta con el creador real, `OrderProductionHelper::saveSale()`. Esa venta nace SIN movimientos de
 * stock; los casos que necesitan stock descontado lo escriben con `StockMovementController::crear()`
 * (el mismo escritor que usa Vender), que es como queda una venta de orden editada desde Ventas.
 *
 * 🔴 Cada rechazo verifica que no se tocó NADA (ver `foto()`): la orden, sus movimientos, todas las
 * filas de la cuenta corriente del cliente y sus cuentas, la venta (sin `deleted_at`), los tickets,
 * el stock y el libro de stock de sus artículos, y ningún movimiento de caja nuevo.
 *
 * Con los archivos de `app/` de `origin/develop` dan rojo los casos 1, 2, 3, 5, 6, 9 y 10 (y también
 * el 4 y el 8, que son de control). El 7 da verde con los dos.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group order_production
 */
class Borrar_orden_de_produccion_Test extends EmpresaTestCase
{
    /** Motivos de `DeleteSaleHelper::motivo_por_el_que_no_se_puede_eliminar()`, literales (los mismos de `Facturacion/10`). */
    const MENSAJE_CON_CAE = 'La venta tiene factura autorizada: para anularla, hacé una devolución con nota de crédito.';

    const MENSAJE_SIN_CAE = 'La venta tiene una factura sin CAE (rechazada o sin respuesta de ARCA). Consultala o eliminala desde la factura de la venta, y después borrá la venta.';

    /** CAE con forma real (14 dígitos). */
    const CAE = '70123456789012';

    /** Renglón de cada orden: 2 unidades a $300, total $600. */
    const CANTIDAD = 2;

    const PRECIO = 300;

    // -------------------------------------------------------------------------------------------
    // Escenario
    // -------------------------------------------------------------------------------------------

    /**
     * @return \App\Models\User
     */
    protected function usuario()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Artículo propio con stock global 20, con el precio calculado por el camino real del
     * formulario (mismo molde que `Facturacion/10`).
     *
     * @param string $nombre
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre)
    {
        $user = $this->usuario();

        $provider = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $article = Article::create([
            'name'            => $nombre,
            'user_id'         => $user->id,
            'provider_id'     => !is_null($provider) ? $provider->id : null,
            'cost'            => 100,
            'percentage_gain' => 50,
            'stock'           => 20,
        ]);

        $article = Article::find($article->id);

        ArticleHelper::setFinalPrice($article, $user->id);

        return $article->fresh();
    }

    /**
     * Stock del artículo leído de la base, nunca del modelo en memoria.
     *
     * @param \App\Models\Article $articulo
     * @return float
     */
    protected function stock($articulo)
    {
        return (float) DB::table('articles')->where('id', $articulo->id)->value('stock');
    }

    /**
     * @param string $nombre
     * @return \App\Models\Client
     */
    protected function cliente($nombre)
    {
        $cliente = Client::where('name', $nombre)
            ->where('user_id', $this->usuario()->id)
            ->first();

        $this->assertNotNull($cliente, 'El fixture no tiene el cliente "'.$nombre.'".');

        return $cliente;
    }

    /**
     * La cuenta corriente en pesos del cliente (la crea el seeder con
     * `CreditAccountHelper::crear_credit_accounts`, moneda 1 = Peso).
     *
     * @param \App\Models\Client $cliente
     * @return \App\Models\CreditAccount
     */
    protected function cuenta_en_pesos($cliente)
    {
        $cuenta = CreditAccount::where('model_name', 'client')
            ->where('model_id', $cliente->id)
            ->where('moneda_id', 1)
            ->first();

        $this->assertNotNull($cuenta, 'El cliente '.$cliente->name.' no tiene cuenta corriente en pesos.');

        return $cuenta;
    }

    /**
     * Estado de orden de producción del dueño: `order_productions.order_production_status_id` es NOT
     * NULL y el fixture no siembra ninguno.
     *
     * @return \App\Models\OrderProductionStatus
     */
    protected function estado_de_orden()
    {
        return OrderProductionStatus::create([
            'name'     => 'zz-op Pendiente',
            'position' => 1,
            'user_id'  => $this->usuario()->id,
        ]);
    }

    /**
     * Orden de producción SIN terminar, por el endpoint real, con un renglón de un artículo propio
     * (stock 20).
     *
     * @param string $nombre_articulo
     * @param string|null $nombre_cliente
     * @return \App\Models\OrderProduction
     */
    protected function crear_orden($nombre_articulo, $nombre_cliente = TestingFerreteriaSeeder::CLIENTE_CC)
    {
        $articulo = $this->crear_articulo($nombre_articulo);

        $cliente = $this->cliente($nombre_cliente);

        $response = $this->postJson('api/order-production', [
            'client_id'                  => $cliente->id,
            'observations'               => null,
            'start_at'                   => null,
            'finish_at'                  => null,
            'order_production_status_id' => $this->estado_de_orden()->id,
            'finished'                   => 0,
            'articles'                   => [[
                'id'     => $articulo->id,
                'status' => 'active',
                'pivot'  => [
                    'amount'   => self::CANTIDAD,
                    'price'    => self::PRECIO,
                    'bonus'    => null,
                    'location' => null,
                ],
            ]],
        ]);

        $response->assertStatus(201);

        $orden = OrderProduction::find($response->json('model.id'));

        $this->assertNotNull($orden, 'El POST no dejó la orden. Cuerpo: '.$response->getContent());
        $this->assertCount(1, $orden->articles, 'Precondición: la orden tenía que quedar con su renglón.');

        return $orden;
    }

    /**
     * El movimiento de cuenta corriente de una orden terminada, como lo dejaba el módulo cuando
     * andaba (ver el docblock de la clase), con la cadena del cliente recalculada.
     *
     * @param \App\Models\OrderProduction $orden
     * @return \App\Models\CurrentAcount
     */
    protected function movimiento_de_la_orden($orden)
    {
        $cuenta = $this->cuenta_en_pesos($orden->client);

        $movimiento = CurrentAcount::create([
            'detalle'             => 'Order de Produccion N°'.$orden->num,
            'debe'                => OrderProductionHelper::getTotal($orden),
            'status'              => 'sin_pagar',
            'client_id'           => $orden->client_id,
            'order_production_id' => $orden->id,
            'credit_account_id'   => $cuenta->id,
            'description'         => null,
        ]);

        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);

        $this->assertEquals(
            self::CANTIDAD * self::PRECIO,
            (float) $movimiento->fresh()->saldo,
            'Precondición: la cadena tenía que quedar con el débito de la orden.'
        );

        return $movimiento;
    }

    /**
     * La venta de la orden, con el creador real (`OrderProductionHelper::saveSale`).
     *
     * @param \App\Models\OrderProduction $orden
     * @return \App\Models\Sale
     */
    protected function venta_de_la_orden($orden)
    {
        OrderProductionHelper::saveSale(OrderProduction::find($orden->id));

        $venta = Sale::where('order_production_id', $orden->id)->first();

        $this->assertNotNull($venta, 'Precondición: saveSale() tenía que crear la venta de la orden.');
        $this->assertSame(1, DB::table('article_sale')->where('sale_id', $venta->id)->count(), 'Precondición: la venta con el renglón de la orden.');

        return $venta;
    }

    /**
     * Orden terminada: movimiento de cuenta corriente + venta.
     *
     * @param string $nombre_articulo
     * @param string|null $nombre_cliente
     * @return array{0: \App\Models\OrderProduction, 1: \App\Models\Sale, 2: \App\Models\Article}
     */
    protected function orden_terminada($nombre_articulo, $nombre_cliente = TestingFerreteriaSeeder::CLIENTE_CC)
    {
        $orden = $this->crear_orden($nombre_articulo, $nombre_cliente);

        $this->movimiento_de_la_orden($orden);

        $venta = $this->venta_de_la_orden($orden);

        return [$orden, $venta, $orden->articles()->first()];
    }

    /**
     * Le descuenta a la venta el stock de su renglón, con el mismo escritor que usa Vender
     * (`ArticleHelper::storeStockMovement` → `StockMovementController::crear`, concepto "Venta").
     *
     * @param \App\Models\Sale $venta
     * @param \App\Models\Article $articulo
     * @return void
     */
    protected function descontar_stock($venta, $articulo)
    {
        (new StockMovementController())->crear([
            'model_id'                     => $articulo->id,
            'from_address_id'              => null,
            'to_address_id'                => null,
            'amount'                       => -self::CANTIDAD,
            'sale_id'                      => $venta->id,
            'concepto_stock_movement_name' => 'Venta',
            'article_variant_id'           => null,
        ], false);

        $this->assertEquals(18.0, $this->stock($articulo), 'Precondición: la venta tenía que descontar 2 del stock.');
    }

    /**
     * Le crea a la venta su `AfipTicket` (facturar de verdad sale a ARCA).
     *
     * @param \App\Models\Sale $venta
     * @param string|null $cae
     * @return \App\Models\AfipTicket
     */
    protected function facturar($venta, $cae)
    {
        return AfipTicket::create([
            'sale_id'       => $venta->id,
            'resultado'     => is_null($cae) ? 'R' : 'A',
            'cbte_tipo'     => 6,
            'cbte_letra'    => 'B',
            'punto_venta'   => TestingFerreteriaSeeder::PUNTO_VENTA,
            'cbte_numero'   => '140',
            // La venta de una orden nace sin `total` (saveSale no lo calcula): el de la orden.
            'importe_total' => self::CANTIDAD * self::PRECIO,
            'cae'           => $cae,
        ]);
    }

    /**
     * Orden terminada, con el stock de la venta descontado y la venta facturada: lo que un rechazo
     * no puede tocar.
     *
     * @param string $nombre_articulo
     * @param string|null $cae
     * @return array{0: \App\Models\OrderProduction, 1: \App\Models\Sale, 2: \App\Models\Article}
     */
    protected function orden_con_venta_facturada($nombre_articulo, $cae)
    {
        list($orden, $venta, $articulo) = $this->orden_terminada($nombre_articulo);

        $this->descontar_stock($venta, $articulo);

        $this->facturar($venta, $cae);

        return [$orden, $venta, $articulo];
    }

    /**
     * El mensaje del 422: el prefijo de la orden y el motivo de la venta, tal cual.
     *
     * @param \App\Models\OrderProduction $orden
     * @param \App\Models\Sale $venta
     * @param string $motivo
     * @return string
     */
    protected function mensaje_de_rechazo($orden, $venta, $motivo)
    {
        return 'No se puede eliminar la orden de producción N° '.$orden->num.' porque su venta N° '.$venta->num.' no se puede borrar. '.$motivo;
    }

    /**
     * Todo lo que borrar la orden tocaría, leído crudo de la base.
     *
     * @param \App\Models\OrderProduction $orden
     * @return array
     */
    protected function foto($orden)
    {
        $venta_id = DB::table('sales')->where('order_production_id', $orden->id)->value('id');

        $articulos = DB::table('article_order_production')->where('order_production_id', $orden->id)->pluck('article_id')->all();

        $tickets = DB::table('afip_tickets')
                        ->where(function ($q) use ($venta_id) {
                            $q->where('sale_id', $venta_id)
                              ->orWhere('sale_nota_credito_id', $venta_id);
                        })
                        ->orderBy('id')
                        ->get();

        return [
            'orden'                        => (array) DB::table('order_productions')->where('id', $orden->id)->first(),
            'movimientos_de_la_orden'      => $this->filas(DB::table('current_acounts')->where('order_production_id', $orden->id)->orderBy('id')->get()),
            'cuenta_corriente_del_cliente' => $this->filas(DB::table('current_acounts')->where('client_id', $orden->client_id)->orderBy('id')->get()),
            'cuentas_del_cliente'          => $this->filas(DB::table('credit_accounts')->where('model_name', 'client')->where('model_id', $orden->client_id)->orderBy('id')->get()),
            'venta'                        => (array) DB::table('sales')->where('id', $venta_id)->first(),
            'tickets'                      => $this->filas($tickets),
            'stock'                        => DB::table('articles')->whereIn('id', $articulos)->orderBy('id')->pluck('stock', 'id')->all(),
            'libro_de_stock'               => $this->filas(DB::table('stock_movements')->whereIn('article_id', $articulos)->orderBy('id')->get()),
            'movimiento_caja'              => (int) DB::table('movimiento_cajas')->max('id'),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection $coleccion
     * @return array
     */
    protected function filas($coleccion)
    {
        return $coleccion->map(function ($fila) {
            return (array) $fila;
        })->all();
    }

    /**
     * El rechazo no tocó nada de lo que la foto de antes registró.
     *
     * @param array $antes  foto() tomada antes del intento.
     * @param \App\Models\OrderProduction $orden
     * @param string $caso
     * @return void
     */
    protected function assert_nada_tocado($antes, $orden, $caso)
    {
        $despues = $this->foto($orden);

        $this->assertNotEmpty($despues['orden'], $caso.': la orden se borró.');
        $this->assertSame($antes['orden'], $despues['orden'], $caso.': la orden cambió.');
        $this->assertNotEmpty($despues['movimientos_de_la_orden'], $caso.': se borró el movimiento de cuenta corriente de la orden.');
        $this->assertSame($antes['movimientos_de_la_orden'], $despues['movimientos_de_la_orden'], $caso.': los movimientos de la orden cambiaron.');
        $this->assertSame($antes['cuenta_corriente_del_cliente'], $despues['cuenta_corriente_del_cliente'], $caso.': la cuenta corriente del cliente cambió.');
        $this->assertSame($antes['cuentas_del_cliente'], $despues['cuentas_del_cliente'], $caso.': el saldo de las cuentas del cliente cambió.');
        $this->assertNotEmpty($despues['venta'], $caso.': la venta desapareció.');
        $this->assertNull($despues['venta']['deleted_at'], $caso.': la venta quedó con deleted_at.');
        $this->assertSame($antes['venta'], $despues['venta'], $caso.': la venta cambió.');
        $this->assertSame($antes['tickets'], $despues['tickets'], $caso.': los tickets cambiaron.');
        $this->assertEquals($antes['stock'], $despues['stock'], $caso.': un rechazo no puede mover el stock.');
        $this->assertSame($antes['libro_de_stock'], $despues['libro_de_stock'], $caso.': un rechazo no puede escribir en el libro de stock.');
        $this->assertSame($antes['movimiento_caja'], $despues['movimiento_caja'], $caso.': un rechazo no puede crear movimientos de caja.');
    }

    /**
     * @param \Illuminate\Testing\TestResponse $response
     * @param string $mensaje
     * @return void
     */
    protected function assert_rechazo($response, $mensaje)
    {
        $this->assertSame(
            422,
            $response->getStatusCode(),
            'Una orden cuya venta está facturada no se puede borrar: tiene que ser 422. Cuerpo: '.$response->getContent()
        );

        $this->assertSame($mensaje, $response->json('message'));
        $this->assertSame(true, $response->json('error_venta_facturada'), 'Falta la clave error_venta_facturada (la misma del 422 de SaleController y OrderController).');
    }

    /**
     * La orden se borró con todo lo suyo: ella, sus movimientos de cuenta corriente y, si tenía
     * venta, la venta en la papelera.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @param \App\Models\OrderProduction $orden
     * @param \App\Models\Sale|null $venta
     * @return void
     */
    protected function assert_borrada($response, $orden, $venta = null)
    {
        $this->assertSame(200, $response->getStatusCode(), 'La orden se tenía que poder borrar. Cuerpo: '.$response->getContent());

        $this->assertNull(OrderProduction::find($orden->id), 'La orden sigue viva.');
        $this->assertFalse(CurrentAcount::where('order_production_id', $orden->id)->exists(), 'Quedó un movimiento de cuenta corriente de una orden borrada.');

        if (!is_null($venta)) {
            $this->assertSoftDeleted('sales', ['id' => $venta->id]);
        }
    }

    // -------------------------------------------------------------------------------------------
    // 1 y 2 — Rechazos: la venta de la orden está facturada
    // -------------------------------------------------------------------------------------------

    /**
     * 1. Venta con factura autorizada (con CAE): 422 con el motivo de la venta prefijado por la orden,
     * y ni la orden, ni su cuenta corriente, ni la venta, ni el stock se mueven.
     *
     * @test
     */
    public function una_orden_cuya_venta_tiene_factura_autorizada_no_se_borra()
    {
        list($orden, $venta) = $this->orden_con_venta_facturada('zz-op Facturada con CAE', self::CAE);

        $antes = $this->foto($orden);

        $response = $this->deleteJson('api/order-production/'.$orden->id);

        $this->assert_rechazo($response, $this->mensaje_de_rechazo($orden, $venta, self::MENSAJE_CON_CAE));
        $this->assert_nada_tocado($antes, $orden, 'Venta con CAE');
    }

    /**
     * 2. Venta con un ticket SIN CAE (rechazado o sin respuesta): frena igual, con el mensaje de
     * sin CAE.
     *
     * @test
     */
    public function una_orden_cuya_venta_tiene_factura_sin_cae_no_se_borra()
    {
        list($orden, $venta) = $this->orden_con_venta_facturada('zz-op Facturada sin CAE', null);

        $antes = $this->foto($orden);

        $response = $this->deleteJson('api/order-production/'.$orden->id);

        $this->assert_rechazo($response, $this->mensaje_de_rechazo($orden, $venta, self::MENSAJE_SIN_CAE));
        $this->assert_nada_tocado($antes, $orden, 'Venta sin CAE');
    }

    // -------------------------------------------------------------------------------------------
    // 3 a 7 — Lo que se borra
    // -------------------------------------------------------------------------------------------

    /**
     * 3. Venta sin facturar CON stock descontado (una venta de orden editada desde Ventas): la venta
     * pasa por la baja real y el stock vuelve (20 → 18 → 20), el neto de su libro queda en 0, la
     * orden y su movimiento se van, y la cadena del cliente se recalcula: el movimiento posterior
     * baja el débito de la orden y la cuenta queda con su saldo.
     *
     * @test
     */
    public function una_orden_con_venta_sin_facturar_devuelve_el_stock_y_recalcula_la_cuenta()
    {
        list($orden, $venta, $articulo) = $this->orden_terminada('zz-op Sin facturar con stock');

        $this->descontar_stock($venta, $articulo);

        $cuenta = $this->cuenta_en_pesos($orden->client);

        // Un movimiento POSTERIOR al de la orden: es el que prueba que la cadena se recalcula, no
        // solo que el saldo de la cuenta cambia.
        $posterior = CurrentAcount::create([
            'detalle'           => 'zz-op Nota de debito posterior',
            'debe'              => 1000,
            'status'            => 'sin_pagar',
            'client_id'         => $orden->client_id,
            'credit_account_id' => $cuenta->id,
            'created_at'        => Carbon::now()->addMinute(),
        ]);

        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);

        $this->assertEquals(1600.0, (float) $posterior->fresh()->saldo, 'Precondición: el posterior arrastra el débito de la orden.');
        $this->assertEquals(1600.0, (float) $cuenta->fresh()->saldo, 'Precondición: la cuenta con los dos débitos.');

        $response = $this->deleteJson('api/order-production/'.$orden->id);

        $this->assert_borrada($response, $orden, $venta);

        $this->assertEquals(20.0, $this->stock($articulo), 'El borrado tenía que devolver al stock lo que la venta descontó.');
        $this->assertEquals(0.0, (float) DB::table('stock_movements')->where('sale_id', $venta->id)->sum('amount'), 'El neto del libro de la venta tiene que quedar en 0.');
        $this->assertSame(1, DB::table('stock_movements')
                                ->join('concepto_stock_movements', 'concepto_stock_movements.id', '=', 'stock_movements.concepto_stock_movement_id')
                                ->where('stock_movements.sale_id', $venta->id)
                                ->where('concepto_stock_movements.name', 'Se elimino la venta')
                                ->count(), 'La devolución tiene que quedar en el libro como "Se elimino la venta".');

        $this->assertEquals(1000.0, (float) $posterior->fresh()->saldo, 'La cadena del cliente no se recalculó: el posterior sigue arrastrando el débito de la orden.');
        $this->assertEquals(1000.0, (float) $cuenta->fresh()->saldo, 'El saldo de la cuenta tenía que bajar el débito de la orden.');
    }

    /**
     * 4. Venta sin facturar SIN movimientos de stock (la venta típica de una orden: saveSale no
     * descuenta): se borra todo y el stock no se mueve. Ningún "Se elimino la venta": devolver lo
     * que nunca se descontó es inflar el stock.
     *
     * @test
     */
    public function una_orden_con_venta_que_no_desconto_stock_no_infla_el_stock()
    {
        list($orden, $venta, $articulo) = $this->orden_terminada('zz-op Sin facturar sin stock');

        $this->assertSame(0, DB::table('stock_movements')->where('sale_id', $venta->id)->count(), 'Precondición: la venta de una orden nace sin movimientos de stock.');

        $cuenta = $this->cuenta_en_pesos($orden->client);

        $response = $this->deleteJson('api/order-production/'.$orden->id);

        $this->assert_borrada($response, $orden, $venta);

        $this->assertEquals(20.0, $this->stock($articulo), 'El stock no se tenía que mover: la venta nunca descontó.');
        $this->assertSame(0, DB::table('stock_movements')->where('sale_id', $venta->id)->count(), 'No se puede reponer lo que la venta nunca sacó.');
        $this->assertEquals(0.0, (float) $cuenta->fresh()->saldo, 'La cuenta tenía que quedar sin el débito de la orden.');
    }

    /**
     * 5. Movimiento de cuenta corriente HUÉRFANO (`credit_account_id` NULL, lo que deja hoy el
     * terminar roto) y sin venta: se borra sin excepción. No está en ninguna cadena, así que no hay
     * nada que recalcular; antes daba 500 en `checkSaldos('client', ...)`.
     *
     * @test
     */
    public function una_orden_con_movimiento_huerfano_se_borra_sin_500()
    {
        $orden = $this->crear_orden('zz-op Movimiento huerfano');

        $cuenta = $this->cuenta_en_pesos($orden->client);

        $huerfano = CurrentAcount::create([
            'detalle'             => 'Order de Produccion N°'.$orden->num,
            'debe'                => OrderProductionHelper::getTotal($orden),
            'status'              => 'sin_pagar',
            'client_id'           => $orden->client_id,
            'order_production_id' => $orden->id,
        ]);

        $cuentas_antes = $this->filas(DB::table('credit_accounts')->where('model_name', 'client')->where('model_id', $orden->client_id)->orderBy('id')->get());

        $response = $this->deleteJson('api/order-production/'.$orden->id);

        $this->assert_borrada($response, $orden);

        $this->assertNull(CurrentAcount::find($huerfano->id), 'El movimiento huérfano tenía que irse con la orden.');
        $this->assertSame(
            $cuentas_antes,
            $this->filas(DB::table('credit_accounts')->where('model_name', 'client')->where('model_id', $orden->client_id)->orderBy('id')->get()),
            'Un movimiento sin cadena no tiene por qué tocar las cuentas del cliente.'
        );
        $this->assertEquals(0.0, (float) $cuenta->fresh()->saldo);
    }

    /**
     * 6. Orden con venta y SIN movimiento de cuenta corriente (el medio estado que dejaba el primer
     * intento fallido del borrado viejo): la venta pasa igual por la baja real y queda en la
     * papelera. Antes la orden se borraba y la venta quedaba viva.
     *
     * @test
     */
    public function una_orden_con_venta_y_sin_cuenta_corriente_da_de_baja_la_venta()
    {
        $orden = $this->crear_orden('zz-op Venta sin cuenta corriente');

        $venta = $this->venta_de_la_orden($orden);

        $this->assertFalse(CurrentAcount::where('order_production_id', $orden->id)->exists(), 'Precondición: sin movimiento de cuenta corriente.');

        $response = $this->deleteJson('api/order-production/'.$orden->id);

        $this->assert_borrada($response, $orden, $venta);
    }

    /**
     * 7. Control: una orden sin terminar (sin venta ni cuenta corriente) se borra como siempre.
     *
     * @test
     */
    public function una_orden_sin_terminar_se_borra()
    {
        $orden = $this->crear_orden('zz-op Sin terminar');

        $response = $this->deleteJson('api/order-production/'.$orden->id);

        $this->assert_borrada($response, $orden);
    }

    /**
     * 8. Un id que no existe: 404 con mensaje, en vez del "property of non-object" de antes.
     *
     * @test
     */
    public function una_orden_inexistente_da_404()
    {
        $id = (int) DB::table('order_productions')->max('id') + 1000;

        $response = $this->deleteJson('api/order-production/'.$id);

        $this->assertSame(404, $response->getStatusCode(), 'Cuerpo: '.$response->getContent());
        $this->assertSame('La orden de producción no existe o ya fue eliminada.', $response->json('message'));
    }

    // -------------------------------------------------------------------------------------------
    // 9 y 10 — Las otras dos entradas a destroy()
    // -------------------------------------------------------------------------------------------

    /**
     * 9. El borrado MASIVO respeta el freno (`order_production` en MODELOS_QUE_RESPETAN_RECHAZO): la
     * orden de la venta facturada no vuelve como eliminada, el motivo viaja en `not_deleted`, y la
     * normal se borra entera.
     *
     * ⚠️ La orden normal es de OTRO cliente a propósito (mismo criterio que `Facturacion/10`): si
     * fuera del mismo, borrarla recalcula la cadena de esa cuenta y reescribe filas del cliente de la
     * facturada que el rechazo no tocó.
     *
     * @test
     */
    public function el_borrado_masivo_no_cuenta_como_eliminada_la_orden_con_venta_facturada()
    {
        list($facturada, $venta_facturada) = $this->orden_con_venta_facturada('zz-op Masiva facturada', self::CAE);

        list($normal, $venta_normal, $articulo_normal) = $this->orden_terminada('zz-op Masiva normal', TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $this->descontar_stock($venta_normal, $articulo_normal);

        $mensaje = $this->mensaje_de_rechazo($facturada, $venta_facturada, self::MENSAJE_CON_CAE);

        $antes = $this->foto($facturada);

        // Un solo registro: el camino síncrono de DeleteController (`PUT api/delete/{model_name}`).
        $response = $this->putJson('api/delete/order_production', [
            'from_filter' => 0,
            'models_id'   => [$facturada->id],
        ]);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('models'), 'La orden de la venta facturada no puede volver como eliminada.');
        $this->assertEquals($facturada->id, $response->json('not_deleted.0.id'));
        $this->assertSame($mensaje, $response->json('not_deleted.0.message'));
        $this->assert_nada_tocado($antes, $facturada, 'Masiva síncrona');

        // Varios registros (el camino del job): cuenta solo la que sí se borró.
        $resultado = DeleteModelsHelper::process_delete('order_production', [$facturada->id, $normal->id]);

        $this->assertEquals(1, $resultado['deleted_count']);
        $this->assertCount(1, $resultado['not_deleted']);
        $this->assertEquals($facturada->id, $resultado['not_deleted'][0]['id']);
        $this->assertSame($mensaje, $resultado['not_deleted'][0]['message']);
        $this->assertCount(1, $resultado['deleted_models']);
        $this->assertEquals($normal->id, $resultado['deleted_models'][0]->id);

        $this->assert_nada_tocado($antes, $facturada, 'Masiva de varios registros');

        $this->assertNull(OrderProduction::find($normal->id), 'La orden normal se tenía que borrar.');
        $this->assertFalse(CurrentAcount::where('order_production_id', $normal->id)->exists(), 'La orden normal se tenía que llevar su movimiento.');
        $this->assertSoftDeleted('sales', ['id' => $venta_normal->id]);
        $this->assertEquals(20.0, $this->stock($articulo_normal), 'La venta de la orden normal se tenía que dar de baja entera, stock incluido.');
    }

    /**
     * 10. El borrado por pantalla del asistente (`proponer_borrado_por_pantalla` sobre
     * `DELETE api/order-production/{order_production}` + confirmar la tarjeta) llega al mismo
     * destroy(): la confirmación devuelve el rechazo con su mensaje, la tarjeta no queda
     * confirmada y no se toca nada. La ruta NO se excluye del catálogo a propósito: la guarda vive
     * en destroy() y `EjecutorAccionDePantallaIaHelper` trata el 422 como rechazo.
     *
     * @test
     */
    public function el_borrado_por_pantalla_del_asistente_se_rechaza_con_el_mensaje()
    {
        list($orden, $venta) = $this->orden_con_venta_facturada('zz-op Asistente facturada', self::CAE);

        $mensaje = $this->mensaje_de_rechazo($orden, $venta, self::MENSAJE_CON_CAE);

        $antes = $this->foto($orden);

        // 🔴 Nunca la clave real del .env.testing: este test no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $dueno = $this->usuario();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        Escritura::olvidar();
        Catalogo::olvidar();

        $conversation = AiConversation::create([
            'user_id'      => $dueno->id,
            'auth_user_id' => $dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Borrame la orden de producción',
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_'.uniqid(),
            'name'  => 'proponer_borrado_por_pantalla',
            'input' => [
                'ruta'        => 'api/order-production/{order_production}',
                'parametros'  => ['order_production' => $orden->id],
                'descripcion' => 'Borrar la orden de producción N° '.$orden->num,
            ],
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: '.$resultados[0]['content']);

        $respuesta = json_decode($resultados[0]['content'], true);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_BORRADO_PANTALLA, $respuesta['tipo']);

        // La SPA recién muestra la tarjeta con el mensaje listo.
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $confirmacion = $this->postJson('api/ai-conversations/'.$conversation->id.'/acciones/'.$respuesta['tarjeta_id'].'/confirmar');

        $this->assertSame(422, $confirmacion->getStatusCode(), 'El borrado del asistente tenía que rechazarse, no salir como hecho. Cuerpo: '.$confirmacion->getContent());
        $this->assertSame($mensaje, $confirmacion->json('model.error_mensaje'), 'El mensaje es el de OrderProductionController::destroy(), tal cual.');
        $this->assertSame($mensaje, $confirmacion->json('message'));
        $this->assertSame('propuesta', $confirmacion->json('model.estado'), 'La tarjeta rechazada no puede quedar confirmada.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());

        $this->assert_nada_tocado($antes, $orden, 'Borrado por pantalla del asistente');
    }
}
