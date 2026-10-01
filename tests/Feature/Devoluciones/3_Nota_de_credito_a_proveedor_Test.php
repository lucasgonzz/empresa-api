<?php

namespace Tests\Feature\Devoluciones;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\StockMovement;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Devolución de COMPRA: la nota de crédito a proveedor que nace en el módulo de Devoluciones
 * (misión devoluciones-compras-y-rediseno, 1/10/2026). Ver NotaCreditoProveedorHelper y
 * ValidarDevolucionCompraHelper.
 *
 * Todo por el camino real: la compra se crea con `POST api/provider-order` (la misma que arma el
 * stock, el débito en la cuenta del proveedor y los totales), se busca con
 * `GET api/devoluciones/search-provider-order/{num}` y se devuelve con `POST api/devoluciones/`
 * con `tipo = 'compra'`, que es exactamente lo que hace la pantalla.
 *
 * Los helpers (crear la compra, buscarla, armar el POST) viven en NotaCreditoProveedorTestCase.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group devoluciones
 */
class Nota_de_credito_a_proveedor_Test extends NotaCreditoProveedorTestCase
{
    /**
     * NC sobre una compra, artículo con stock GLOBAL: sale el stock, el movimiento lleva el
     * concepto nuevo y queda atado a la compra y a la NC, la NC queda atada al proveedor y a la
     * compra, y el GET muestra lo ya devuelto. El artículo no cambia de proveedor.
     *
     * @test
     */
    public function nc_sobre_una_compra_con_stock_global_saca_el_stock_y_ata_compra_y_nc()
    {
        $articulo = $this->crear_articulo('zz NC proveedor global');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);
        $proveedor_original_del_articulo = $articulo->provider_id;

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);

        $this->assertEquals(9, $this->stock($articulo), 'La compra no sumó al stock global; el escenario no sirve.');

        $buscada = $this->buscar_compra($compra);
        $renglon = $buscada['articles'][$articulo->id];

        $this->assertEquals(4, $renglon['cantidad_efectiva']);
        $this->assertEquals(0, $renglon['ya_devueltas']);

        // Devolver todo tiene que dar lo que la compra le cobró (sin costos extra): 4 × 121.
        $this->assertEqualsWithDelta(121, $renglon['costo_unitario_devolucion'], 0.01);
        $this->assertEqualsWithDelta((float) $compra->total, $renglon['costo_unitario_devolucion'] * 4, 0.01);

        $costo = $renglon['costo_unitario_devolucion'];

        $response = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, $costo, 3)],
            $costo * 3
        ));

        $response->assertStatus(201);

        $this->assertEquals(6, $this->stock($articulo), 'Las 3 unidades devueltas tenían que salir del stock.');

        $nota_credito = $this->nota_credito_de($compra);

        $this->assertNotNull($nota_credito, 'No se creó la NC atada a la compra.');
        $this->assertEquals($proveedor->id, $nota_credito->provider_id);
        $this->assertNull($nota_credito->client_id);
        $this->assertNull($nota_credito->credit_account_id, 'Sin C/C la NC no entra a ninguna cuenta.');
        $this->assertNull($nota_credito->provider_order_id, 'La NC no puede usar la columna del débito de la compra.');
        $this->assertEqualsWithDelta($costo * 3, (float) $nota_credito->haber, 0.01);
        $this->assertEquals('Devolución de compra N° '.$compra->num, $nota_credito->description);

        $articulo_de_la_nc = $nota_credito->articles()->where('articles.id', $articulo->id)->first();
        $this->assertNotNull($articulo_de_la_nc);
        $this->assertEquals(3, (float) $articulo_de_la_nc->pivot->amount);
        $this->assertEqualsWithDelta($costo, (float) $articulo_de_la_nc->pivot->cost, 0.01, 'El costo de la NC es el que se le devolvió al proveedor, no el bruto de la compra.');

        $movimiento = StockMovement::where('article_id', $articulo->id)
                                    ->where('concepto_stock_movement_id', $this->concepto_nc_proveedor())
                                    ->first();

        $this->assertNotNull($movimiento, 'No hay movimiento con el concepto "Nota de credito proveedor".');
        $this->assertEquals(-3, (float) $movimiento->amount);
        $this->assertEquals($compra->id, $movimiento->provider_order_id);
        $this->assertEquals($nota_credito->id, $movimiento->nota_credito_id);
        $this->assertEquals($proveedor->id, $movimiento->provider_id);
        $this->assertNull($movimiento->from_address_id);

        $this->assertEquals($proveedor_original_del_articulo, Article::find($articulo->id)->provider_id, 'Devolverle al proveedor no puede cambiar el proveedor del artículo.');

        $buscada = $this->buscar_compra($compra);
        $this->assertEquals(3, $buscada['articles'][$articulo->id]['ya_devueltas']);
    }

    /**
     * Artículo con depósitos: lo devuelto sale del depósito ELEGIDO (no del de la compra), y el
     * stock total del artículo baja lo mismo.
     *
     * @test
     */
    public function nc_con_depositos_saca_del_deposito_elegido()
    {
        $articulo = $this->crear_articulo('zz NC proveedor depositos', ['stock' => 0]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();
        $segundo = Address::create([
            'street'          => 'zz Deposito NC proveedor',
            'user_id'         => $this->usuario()->id,
            'default_address' => 0,
        ]);

        // La compra entra al Principal (el artículo sin stock abre ahí su primer depósito).
        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 5)], ['address_id' => $principal->id]);

        // Y el segundo depósito tiene 10 propias.
        $articulo->addresses()->attach($segundo->id, ['amount' => 10]);
        ArticleHelper::setArticleStockFromAddresses(Article::find($articulo->id), false);

        $this->assertEquals(15, $this->stock($articulo), 'El escenario de depósitos no quedó como se esperaba.');

        $response = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 2)],
            242,
            ['address_id' => $segundo->id]
        ));

        $response->assertStatus(201);

        $deposito = function ($address_id) use ($articulo) {
            return (float) DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $address_id)->value('amount');
        };

        $this->assertEquals(8, $deposito($segundo->id), 'Lo devuelto tenía que salir del depósito elegido.');
        $this->assertEquals(5, $deposito($principal->id), 'El depósito de la compra no se tenía que tocar.');
        $this->assertEquals(13, $this->stock($articulo));

        $movimiento = StockMovement::where('article_id', $articulo->id)
                                    ->where('concepto_stock_movement_id', $this->concepto_nc_proveedor())
                                    ->first();

        $this->assertEquals($segundo->id, $movimiento->from_address_id);
    }

    /**
     * Sin `address_id` en el request sale del depósito de la compra; y si no hay de dónde sacar
     * (NC libre, artículo con depósitos), 422 sin escribir nada.
     *
     * @test
     */
    public function nc_con_depositos_sin_elegir_sale_del_de_la_compra_y_sin_compra_pide_deposito()
    {
        $articulo = $this->crear_articulo('zz NC proveedor deposito de la compra', ['stock' => 0]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);
        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 5)], ['address_id' => $principal->id]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 1)],
            121
        ))->assertStatus(201);

        $this->assertEquals(4, (float) DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $principal->id)->value('amount'));

        $notas_antes = CurrentAcount::where('status', 'nota_credito')->where('provider_id', $proveedor->id)->count();

        $response = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            null,
            [$this->item_devolucion($articulo, 121, 1)],
            121
        ));

        $response->assertStatus(422);
        $this->assertStringContainsString('depósito', $response->json('message'));
        $this->assertEquals($notas_antes, CurrentAcount::where('status', 'nota_credito')->where('provider_id', $proveedor->id)->count());
    }

    /**
     * Artículo que se compra por bulto (`unidades_individuales` = 12): la compra se carga y se
     * devuelve en bultos, y el stock se mueve en unidades. El GET muestra todo en bultos.
     *
     * @test
     */
    public function nc_de_un_articulo_por_bulto_saca_las_unidades_del_bulto()
    {
        $articulo = $this->crear_articulo('zz NC proveedor por bulto', ['stock' => 5, 'unidades_individuales' => 12]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 1200, 2)]);

        $this->assertEquals(29, $this->stock($articulo), 'La compra de 2 bultos de 12 tenía que sumar 24 unidades.');

        $buscada = $this->buscar_compra($compra);
        $renglon = $buscada['articles'][$articulo->id];

        $this->assertEquals(2, $renglon['cantidad_efectiva'], 'La cantidad comprada va en bultos.');
        $this->assertEqualsWithDelta(1452, $renglon['costo_unitario_devolucion'], 0.01, 'El costo es por bulto (1200 + 21%).');

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 1452, 1)],
            1452
        ))->assertStatus(201);

        $this->assertEquals(17, $this->stock($articulo), 'Devolver 1 bulto tiene que sacar 12 unidades.');

        $movimiento = StockMovement::where('article_id', $articulo->id)
                                    ->where('concepto_stock_movement_id', $this->concepto_nc_proveedor())
                                    ->first();
        $this->assertEquals(-12, (float) $movimiento->amount);

        $buscada = $this->buscar_compra($compra);
        $this->assertEquals(1, $buscada['articles'][$articulo->id]['ya_devueltas'], 'Lo ya devuelto también va en bultos.');

        // Y el tope también razona en bultos: queda 1 bulto, 2 no entran.
        $response = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 1452, 2)],
            2904
        ));

        $response->assertStatus(422);
        $this->assertTrue($response->json('devolucion_excedida'));
    }

    /**
     * Tope: devolver más de lo que la compra ingresó responde 422 con `devolucion_excedida` y no
     * escribe nada (ni NC, ni stock, ni movimiento).
     *
     * @test
     */
    public function devolver_mas_de_lo_comprado_responde_422_y_no_escribe_nada()
    {
        $articulo = $this->crear_articulo('zz NC proveedor tope');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);

        $response = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 5)],
            605
        ));

        $response->assertStatus(422);
        $this->assertTrue($response->json('devolucion_excedida'));
        $this->assertStringContainsString('4 compradas', $response->json('message'));

        $this->assertNull($this->nota_credito_de($compra), 'Un 422 no puede dejar una NC.');
        $this->assertEquals(9, $this->stock($articulo));
        $this->assertEquals(0, StockMovement::where('article_id', $articulo->id)->where('concepto_stock_movement_id', $this->concepto_nc_proveedor())->count());
    }

    /**
     * La segunda NC por lo mismo (el reintento del doble clic) se rechaza: el libro ya tiene todo
     * devuelto.
     *
     * @test
     */
    public function una_segunda_nc_por_lo_mismo_se_rechaza()
    {
        $articulo = $this->crear_articulo('zz NC proveedor duplicada');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);

        $payload = $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 4)],
            484
        );

        $this->postJson('api/devoluciones/', $payload)->assertStatus(201);

        $segunda = $this->postJson('api/devoluciones/', $payload);

        $segunda->assertStatus(422);
        $this->assertTrue($segunda->json('devolucion_excedida'));

        $this->assertEquals(1, CurrentAcount::where('status', 'nota_credito')->where('devolucion_provider_order_id', $compra->id)->count());
        $this->assertEquals(5, $this->stock($articulo), 'El stock tiene que haber bajado una sola vez.');
    }

    /**
     * Con C/C, compra en DÓLARES: el haber entra a la cuenta del proveedor en dólares, se imputa
     * al débito de ESA compra y el saldo baja lo devuelto. La cuenta en pesos no se toca.
     *
     * @test
     */
    public function con_cuenta_corriente_entra_en_la_moneda_de_la_compra_imputada_a_la_compra()
    {
        $articulo = $this->crear_articulo('zz NC proveedor cc dolares');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $cuenta_usd = $this->cuenta_del_proveedor($proveedor, 2);
        $cuenta_pesos = $this->cuenta_del_proveedor($proveedor, 1);
        $saldo_pesos_antes = (float) $cuenta_pesos->fresh()->saldo;

        // Una deuda VIEJA en dólares antes de la compra: sin la imputación dirigida, la NC la
        // saldaría primero (FIFO).
        $compra_vieja = $this->crear_compra([$this->renglon_compra($articulo, 10, 1)], ['moneda_id' => 2]);
        $compra = $this->crear_compra([$this->renglon_compra($articulo, 10, 4)], ['moneda_id' => 2]);

        $debito_viejo = CurrentAcount::where('provider_order_id', $compra_vieja->id)->first();
        $debito = CurrentAcount::where('provider_order_id', $compra->id)->first();

        $this->assertNotNull($debito, 'La compra a C/C no generó su débito; el escenario no sirve.');
        $this->assertEquals($cuenta_usd->id, $debito->credit_account_id);

        $saldo_usd_antes = (float) $cuenta_usd->fresh()->saldo;

        $renglon = $this->buscar_compra($compra)['articles'][$articulo->id];
        $this->assertEqualsWithDelta(12.1, $renglon['costo_unitario_devolucion'], 0.01, 'Compra en dólares: el costo va en dólares.');

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 12.1, 2)],
            24.2,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        $nota_credito = $this->nota_credito_de($compra);

        $this->assertEquals($cuenta_usd->id, $nota_credito->credit_account_id, 'La NC tiene que entrar a la cuenta en la moneda de la compra.');
        $this->assertEquals(2, (int) $nota_credito->moneda_id);
        $this->assertEquals($debito->id, $nota_credito->to_pay_id, 'La NC tiene que imputarse al débito de su compra.');

        $debito = $debito->fresh();
        $this->assertEquals('pagandose', $debito->status);
        $this->assertEqualsWithDelta(24.2, (float) $debito->pagandose, 0.01);

        $debito_viejo = $debito_viejo->fresh();
        $this->assertEquals('sin_pagar', $debito_viejo->status, 'La NC no puede saldar la deuda más vieja antes que la de su compra.');

        $this->assertEqualsWithDelta($saldo_usd_antes - 24.2, (float) $cuenta_usd->fresh()->saldo, 0.01, 'El saldo con el proveedor tenía que bajar lo devuelto.');
        $this->assertEqualsWithDelta($saldo_pesos_antes, (float) $cuenta_pesos->fresh()->saldo, 0.01, 'La cuenta en pesos no se toca.');
    }

    /**
     * Sin C/C: ningún movimiento en la cuenta del proveedor (el débito de la compra sigue igual),
     * pero la NC igual nace con el proveedor (es el discriminador de los reportes).
     *
     * @test
     */
    public function sin_cuenta_corriente_no_toca_la_cuenta_pero_lleva_el_proveedor()
    {
        $articulo = $this->crear_articulo('zz NC proveedor sin cc');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);

        $cuenta = $this->cuenta_del_proveedor($proveedor, 1);
        $saldo_antes = (float) $cuenta->fresh()->saldo;
        $movimientos_antes = CurrentAcount::where('credit_account_id', $cuenta->id)->count();

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 1)],
            121,
            ['generar_current_acount' => 0, 'observaciones' => 'zz mercadería fallada']
        ))->assertStatus(201);

        $nota_credito = $this->nota_credito_de($compra);

        $this->assertNotNull($nota_credito);
        $this->assertEquals($proveedor->id, $nota_credito->provider_id);
        $this->assertNull($nota_credito->credit_account_id);
        $this->assertEquals('zz mercadería fallada', $nota_credito->description);

        $this->assertEquals($movimientos_antes, CurrentAcount::where('credit_account_id', $cuenta->id)->count());
        $this->assertEqualsWithDelta($saldo_antes, (float) $cuenta->fresh()->saldo, 0.01);
        $this->assertEquals('sin_pagar', CurrentAcount::where('provider_order_id', $compra->id)->first()->status);
    }

    /**
     * NC libre (sin compra) a un proveedor que no tiene cuentas corrientes: se crea la cuenta en
     * pesos, el haber entra ahí, y el stock sale con lo que cargó el usuario.
     *
     * @test
     */
    public function nc_libre_sin_compra_crea_la_cuenta_si_no_existe_y_saca_el_stock()
    {
        $articulo = $this->crear_articulo('zz NC proveedor libre');

        $proveedor = Provider::create([
            'name'    => 'zz Proveedor sin cuentas',
            'user_id' => $this->usuario()->id,
        ]);

        $this->assertNull($this->cuenta_del_proveedor($proveedor, 1), 'El proveedor nuevo no tenía que tener cuenta.');

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            null,
            [$this->item_devolucion($articulo, 80, 2)],
            160,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        $cuenta = $this->cuenta_del_proveedor($proveedor, 1);
        $this->assertNotNull($cuenta, 'La cuenta en pesos del proveedor se tenía que crear.');

        $nota_credito = CurrentAcount::where('status', 'nota_credito')->where('provider_id', $proveedor->id)->first();

        $this->assertNotNull($nota_credito);
        $this->assertEquals($cuenta->id, $nota_credito->credit_account_id);
        $this->assertNull($nota_credito->devolucion_provider_order_id);
        $this->assertEqualsWithDelta(-160, (float) $cuenta->fresh()->saldo, 0.01, 'Sin deuda, la NC deja saldo a favor del comercio.');

        $this->assertEquals(3, $this->stock($articulo));

        $movimiento = StockMovement::where('article_id', $articulo->id)
                                    ->where('concepto_stock_movement_id', $this->concepto_nc_proveedor())
                                    ->first();
        $this->assertNotNull($movimiento);
        $this->assertNull($movimiento->provider_order_id);
        $this->assertEquals(-2, (float) $movimiento->amount);
    }

    /**
     * Una compra de OTRO proveedor: 422 con mensaje (no un 500), sin escribir nada. Y sin
     * proveedor, también 422.
     *
     * @test
     */
    public function proveedor_que_no_es_el_de_la_compra_o_sin_proveedor_responde_422()
    {
        $articulo = $this->crear_articulo('zz NC proveedor equivocado');
        $otro = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);

        $response = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $otro->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 1)],
            121
        ));

        $response->assertStatus(422);
        $this->assertStringContainsString('no es el de la compra', $response->json('message'));

        $sin_proveedor = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            null,
            $compra,
            [$this->item_devolucion($articulo, 121, 1)],
            121
        ));

        $sin_proveedor->assertStatus(422);

        $this->assertNull($this->nota_credito_de($compra));
        $this->assertEquals(9, $this->stock($articulo));
    }

    /**
     * Las bonificaciones de la compra (las de Buenos Aires: 10% y después 5%) se prorratean en el
     * costo de la devolución: devolver todo da exactamente el total de la compra.
     *
     * @test
     */
    public function el_costo_de_la_devolucion_lleva_las_bonificaciones_de_la_compra()
    {
        $articulo = $this->crear_articulo('zz NC proveedor bonificado');
        $bsas = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 1000, 2)], ['provider_id' => $bsas->id]);

        $this->assertGreaterThan(0, (float) $compra->descuentos_compra, 'La compra de Buenos Aires no tomó las bonificaciones; el escenario no sirve.');

        $renglon = $this->buscar_compra($compra)['articles'][$articulo->id];

        // 1000 − 10% = 900 − 5% = 855, + 21% = 1034,55 por unidad.
        $this->assertEqualsWithDelta(1034.55, $renglon['costo_unitario_devolucion'], 0.01);
        $this->assertEqualsWithDelta((float) $compra->total, $renglon['costo_unitario_devolucion'] * 2, 0.02);
    }

    /**
     * Sin `tipo` el POST sigue siendo una devolución de VENTA: NC del cliente, sin proveedor.
     *
     * @test
     */
    public function sin_tipo_sigue_siendo_una_devolucion_de_venta()
    {
        $cliente = \App\Models\Client::create([
            'name'    => 'zz Cliente devolucion sin tipo',
            'user_id' => $this->usuario()->id,
        ]);

        $response = $this->postJson('api/devoluciones/', [
            'sale_id'                   => null,
            'client_id'                 => $cliente->id,
            'generar_current_acount'    => false,
            'total_devolucion'          => 100,
            'observaciones'             => 'zz sin tipo',
            'items'                     => [],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => false,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => null,
        ]);

        $response->assertStatus(201);

        $nota_credito = CurrentAcount::where('status', 'nota_credito')->where('description', 'zz sin tipo')->latest('id')->first();

        $this->assertNotNull($nota_credito);
        $this->assertNull($nota_credito->provider_id);
        $this->assertNull($nota_credito->devolucion_provider_order_id);
    }

    /**
     * 🔴 El doble clic SIN mover stock (sin "descontar del stock"): la segunda NC idéntica se
     * rechaza igual. El tope cuenta lo devuelto por las NC de la compra (sus renglones), no por el
     * libro de stock, que en este caso no tiene nada. El GET muestra lo mismo que cuenta el tope.
     *
     * @test
     */
    public function una_segunda_nc_identica_sin_mover_stock_se_rechaza()
    {
        $articulo = $this->crear_articulo('zz NC proveedor duplicada sin stock');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);

        $payload = $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 4)],
            484,
            ['regresar_stock' => 0]
        );

        $this->postJson('api/devoluciones/', $payload)->assertStatus(201);

        $this->assertEquals(9, $this->stock($articulo), 'Sin "descontar del stock" el stock no se toca.');
        $this->assertEquals(4, $this->buscar_compra($compra)['articles'][$articulo->id]['ya_devueltas'], 'El GET tiene que contar lo devuelto por la NC aunque no haya movido stock.');

        $segunda = $this->postJson('api/devoluciones/', $payload);

        $segunda->assertStatus(422);
        $this->assertTrue($segunda->json('devolucion_excedida'));
        $this->assertEquals(1, CurrentAcount::where('status', 'nota_credito')->where('devolucion_provider_order_id', $compra->id)->count());
    }

    /**
     * Lo que SALE del stock sigue topado por el libro: una NC sin stock y después otra con stock
     * por el resto no sacan más de lo que la compra ingresó.
     *
     * @test
     */
    public function lo_que_sale_del_stock_sigue_topado_por_lo_que_la_compra_ingreso()
    {
        $articulo = $this->crear_articulo('zz NC proveedor stock topado');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        // La compra NO mueve stock: el libro no tiene nada ingresado.
        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)], ['update_stock' => 0]);

        $this->assertEquals(5, $this->stock($articulo));

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 2)],
            242
        ))->assertStatus(201);

        $this->assertEquals(5, $this->stock($articulo), 'No puede salir del stock algo que la compra nunca metió.');
        $this->assertEquals(2, $this->buscar_compra($compra)['articles'][$articulo->id]['ya_devueltas']);
    }

    /**
     * 🔴 Guarda del concepto: si la base no tiene "Nota de credito proveedor" (el seeder del
     * despliegue no corrió), se crea en el momento. Sin él el movimiento quedaba sin concepto: no
     * multiplicaba los bultos y el libro no veía lo devuelto.
     *
     * @test
     */
    public function sin_el_concepto_en_la_base_se_crea_y_el_bulto_se_multiplica()
    {
        DB::table('concepto_stock_movements')->where('name', 'Nota de credito proveedor')->delete();

        $articulo = $this->crear_articulo('zz NC proveedor sin concepto', ['stock' => 5, 'unidades_individuales' => 12]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 1200, 2)]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 1452, 1)],
            1452
        ))->assertStatus(201);

        $this->assertEquals(1, DB::table('concepto_stock_movements')->where('name', 'Nota de credito proveedor')->count(), 'El concepto se tenía que crear una sola vez.');
        $this->assertEquals(17, $this->stock($articulo), 'Con el concepto creado, 1 bulto saca 12 unidades.');

        $movimiento = StockMovement::where('article_id', $articulo->id)
                                    ->where('nota_credito_id', $this->nota_credito_de($compra)->id)
                                    ->first();
        $this->assertEquals($this->concepto_nc_proveedor(), $movimiento->concepto_stock_movement_id);
    }
}
