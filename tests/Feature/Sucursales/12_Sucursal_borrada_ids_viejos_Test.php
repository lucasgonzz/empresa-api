<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Pdf\ResumenCajaPdf;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\Budget;
use App\Models\Client;
use App\Models\ResumenCaja;
use App\Models\Sale;
use App\Models\StockMovement;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 12 — después de borrar una sucursal, su id VIEJO ya no puede reabrir filas fantasma
 * (misión eliminar-sucursal-con-stock, 5/10/2026; decisión D12 del plan, "guarda en dos capas").
 *
 * El caso que originó la misión (3DTisk): se borró el "depósito 3" y siguieron entrando ventas con
 * `address_id = 3` (la cookie de la SPA y `users.address_id` del empleado apuntaban al id muerto).
 * El motor de stock le abría al artículo una fila nueva para esa sucursal, que nadie veía pero que
 * `articles.stock` sumaba: el global dejaba de ser la suma de las sucursales.
 *
 * Lo que fija, con la sucursal ya borrada:
 *
 *  - una venta nueva con el `address_id` muerto (artículo con depósitos, con variantes y sin
 *    depósitos) NO abre ninguna fila para la sucursal muerta, y la venta queda guardada con la
 *    sucursal de reemplazo (la misma de la que sale el stock);
 *  - lo mismo al editar una venta y al crear un presupuesto;
 *  - anular una venta vieja de esa sucursal devuelve el stock al reemplazo;
 *  - nota de crédito, compra a proveedor y producción (todos llegan al motor por `crear()`);
 *  - el alta MANUAL de un movimiento con la sucursal muerta → 422; un "Mov entre depositos" con un
 *    id muerto no mueve nada; "Mover stock" de un traslado con un depósito muerto → 422;
 *  - la edición de stock por sucursal con la sucursal muerta la saltea;
 *  - en todos los casos el global sigue siendo la suma de las sucursales vivas;
 *  - el PDF del resumen de caja de una sucursal borrada no da 500.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Sucursal_borrada_ids_viejos_Test extends SucursalesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SucursalVigenteHelper::olvidar();
    }

    /**
     * Borra la sucursal directo en la tabla, como quedó en 3DTisk (sin pasar por la eliminación
     * nueva): lo que se prueba acá es qué pasa DESPUÉS con el id viejo.
     *
     * @param  \App\Models\Address  $address
     * @return int  El id muerto.
     */
    protected function borrar_a_lo_bruto($address)
    {
        $id = $address->id;

        DB::table('address_article')->where('address_id', $id)->delete();
        DB::table('address_article_variant')->where('address_id', $id)->delete();
        DB::table('addresses')->where('id', $id)->delete();

        SucursalVigenteHelper::olvidar();

        return $id;
    }

    /**
     * @return \App\Models\Client
     */
    protected function cliente_cc()
    {
        $cliente = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->where('user_id', $this->comercio()->id)->first();

        $this->assertNotNull($cliente, 'El fixture no tiene el cliente de cuenta corriente.');

        return $cliente;
    }

    /**
     * El payload de una venta con la forma que manda Vender (mismo armado que
     * Tests\Feature\Stock\AuditoriaStockTestCase::payload_venta).
     *
     * @param  array  $items       [['article' => Article, 'amount' => n, 'price' => p, ...extra]]
     * @param  int    $address_id
     * @param  array  $extra
     * @return array
     */
    protected function payload_venta($items, $address_id, $extra = [])
    {
        $total = 0;
        $renglones = [];

        foreach ($items as $item) {

            $total += $item['price'] * $item['amount'];

            $renglon = [
                'is_article'   => true,
                'id'           => $item['article']->id,
                'name'         => $item['article']->name,
                'price_vender' => $item['price'],
                'amount'       => $item['amount'],
            ];

            foreach ($item as $clave => $valor) {
                if (!in_array($clave, ['article', 'amount', 'price'])) {
                    $renglon[$clave] = $valor;
                }
            }

            $renglones[] = $renglon;
        }

        return array_merge([
            'client_id'                        => $this->cliente_cc()->id,
            'address_id'                       => $address_id,
            'save_current_acount'              => 1,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'checked'                          => 0,
            'confirmed'                        => 0,
            'current_acount_payment_method_id' => 1,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => $renglones,
        ], $extra);
    }

    /**
     * Crea la venta por el endpoint real y la devuelve.
     *
     * @param  array  $items
     * @param  int    $address_id
     * @return \App\Models\Sale
     */
    protected function crear_venta($items, $address_id)
    {
        $payload = $this->payload_venta($items, $address_id);

        $this->postJson('api/sale', $payload)->assertStatus(201);

        $venta = Sale::where('client_id', $payload['client_id'])->orderBy('id', 'DESC')->first();

        $this->assertNotNull($venta, 'El POST no dejó ninguna venta.');

        return $venta;
    }

    /**
     * El invariante: global = suma de las sucursales vivas.
     *
     * @param  \App\Models\Article  $articulo
     * @return void
     */
    protected function assert_global_cuadra($articulo)
    {
        $this->assertEqualsWithDelta(
            $this->suma_de_sucursales_vivas($articulo),
            $this->stock_global($articulo),
            self::DELTA,
            'El stock global de '.$articulo->name.' tiene que ser la suma de las sucursales que existen.'
        );
    }

    /**
     * Test 1 — venta nueva con el address_id muerto: artículo con depósitos, con variantes y sin
     * depósitos. Ninguna fila nueva para la muerta; la venta queda con el reemplazo.
     *
     * @test
     */
    public function una_venta_nueva_con_la_sucursal_muerta_no_abre_filas_fantasma()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Ids viejos venta');

        $con_depositos = $this->nuevo_articulo('zz Ids viejos con depositos');
        $this->cargar_deposito($con_depositos, $principal, 10);

        $con_variantes = $this->nuevo_articulo('zz Ids viejos con variantes');
        $variante      = $this->nueva_variante($con_variantes, 'Rojo');
        $this->cargar_variante($con_variantes, $variante, $principal, 4);

        $sin_depositos = $this->nuevo_articulo('zz Ids viejos sin depositos', ['stock' => 8]);

        $muerta = $this->borrar_a_lo_bruto($borrar);

        $venta = $this->crear_venta([
            ['article' => $con_depositos, 'amount' => 2, 'price' => 100],
            ['article' => $con_variantes, 'amount' => 1, 'price' => 100, 'article_variant_id' => $variante->id],
            ['article' => $sin_depositos, 'amount' => 1, 'price' => 100],
        ], $muerta);

        $this->assertSame(0, $this->filas_de_pivot($muerta), 'Una venta con la sucursal muerta no puede abrirle filas.');

        $this->assertSame($principal->id, (int) $venta->fresh()->address_id, 'La venta queda con la sucursal de reemplazo (la misma de la que salió el stock).');

        $this->assertEquals(8.0, $this->stock_en($con_depositos, $principal->id));
        $this->assertEquals(8.0, $this->stock_global($con_depositos));
        $this->assert_global_cuadra($con_depositos);

        $this->assertEquals(3.0, $this->stock_variante_en($variante, $principal->id));
        $this->assertEquals(3.0, $this->stock_global($con_variantes));

        $this->assertEquals(7.0, $this->stock_global($sin_depositos));

        $movimiento = StockMovement::where('sale_id', $venta->id)->where('article_id', $con_depositos->id)->first();
        $this->assertSame($principal->id, (int) $movimiento->from_address_id, 'El movimiento sale del reemplazo, no de la muerta.');
    }

    /**
     * Test 2 — editar una venta y crear un presupuesto con la sucursal muerta: quedan con el reemplazo.
     *
     * @test
     */
    public function editar_una_venta_y_crear_un_presupuesto_con_la_sucursal_muerta_usan_el_reemplazo()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Ids viejos edicion');

        $articulo = $this->nuevo_articulo('zz Ids viejos edicion A');
        $this->cargar_deposito($articulo, $principal, 10);

        $venta = $this->crear_venta([['article' => $articulo, 'amount' => 1, 'price' => 100]], $principal->id);

        $muerta = $this->borrar_a_lo_bruto($borrar);

        $this->putJson('api/sale/'.$venta->id, $this->payload_venta(
            [['article' => $articulo, 'amount' => 1, 'price' => 100]],
            $muerta,
            ['id' => $venta->id]
        ))->assertStatus(200);

        $this->assertSame($principal->id, (int) $venta->fresh()->address_id);
        $this->assertSame(0, $this->filas_de_pivot($muerta));
        $this->assert_global_cuadra($articulo);

        $budget_id = $this->postJson('api/budget', [
            'client_id'                  => $this->cliente_cc()->id,
            'start_at'                   => null,
            'finish_at'                  => null,
            'observations'               => 'zz presupuesto con sucursal muerta',
            'sale_status_id'             => null,
            'discount_stock'             => 0,
            'iva_aplicado'               => 1,
            'total'                      => 0,
            // "Sin confirmar" del fixture (budgets.budget_status_id es NOT NULL).
            'budget_status_id'           => DB::table('budget_statuses')->where('name', 'Sin confirmar')->orderBy('id')->value('id'),
            'address_id'                 => $muerta,
            'surchages_in_services'      => 1,
            'discounts_in_services'      => 1,
            'moneda_id'                  => 1,
            'omitir_en_cuenta_corriente' => 0,
            'discounts'                  => [],
            'surchages'                  => [],
            'services'                   => [],
            'promocion_vinotecas'        => [],
            'articles'                   => [],
        ])->assertStatus(201)->json('model.id');

        $this->assertSame($principal->id, (int) Budget::find($budget_id)->address_id, 'El presupuesto queda con el reemplazo.');
    }

    /**
     * Test 3 — anular una venta vieja de la sucursal borrada devuelve el stock al reemplazo.
     *
     * @test
     */
    public function anular_una_venta_vieja_devuelve_el_stock_al_reemplazo()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Ids viejos anular');

        $articulo = $this->nuevo_articulo('zz Ids viejos anular A');
        $this->cargar_deposito($articulo, $principal, 10);
        $this->cargar_deposito($articulo, $borrar, 5);

        $venta = $this->crear_venta([['article' => $articulo, 'amount' => 2, 'price' => 100]], $borrar->id);

        $this->assertEquals(3.0, $this->stock_en($articulo, $borrar->id), 'El escenario: la venta salió de la sucursal que después se borra.');

        // Se borra con su stock pasado a la principal (como haría la eliminación nueva).
        DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $principal->id)->update(['amount' => 13]);
        $muerta = $this->borrar_a_lo_bruto($borrar);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertSame(0, $this->filas_de_pivot($muerta), 'Anular la venta vieja no puede reabrir la sucursal muerta.');
        $this->assertEquals(15.0, $this->stock_en($articulo, $principal->id), 'Las 2 unidades vuelven al reemplazo.');
        $this->assert_global_cuadra($articulo);
    }

    /**
     * Test 4 — nota de crédito, compra a proveedor y producción con la sucursal muerta: todos llegan
     * al motor por `StockMovementController::crear()`, que la reemplaza.
     *
     * @test
     */
    public function nota_de_credito_compra_y_produccion_no_abren_filas_fantasma()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Ids viejos conceptos');

        $articulo = $this->nuevo_articulo('zz Ids viejos conceptos A');
        $this->cargar_deposito($articulo, $principal, 10);

        $muerta = $this->borrar_a_lo_bruto($borrar);

        $casos = [
            ['Nota de credito', 1, 'to'],
            ['Compra a proveedor', 3, 'to'],
            ['Produccion', 2, 'to'],
            ['Insumo de produccion', -1, 'from'],
        ];

        $esperado = 10.0;

        foreach ($casos as $caso) {

            list($concepto, $cantidad, $lado) = $caso;

            $ct = new StockMovementController();

            $movimiento = $ct->crear([
                'model_id'                     => $articulo->id,
                'amount'                       => $cantidad,
                $lado.'_address_id'            => $muerta,
                'concepto_stock_movement_name' => $concepto,
            ]);

            $esperado += $cantidad;

            $this->assertNotNull($movimiento, $concepto.': el movimiento se tiene que crear (contra el reemplazo).');
            $this->assertSame($principal->id, (int) $movimiento->{$lado.'_address_id'}, $concepto.': el libro dice el reemplazo, no la muerta.');
            $this->assertSame(0, $this->filas_de_pivot($muerta), $concepto.': no puede abrirle una fila a la sucursal muerta.');
            $this->assertEquals($esperado, $this->stock_en($articulo, $principal->id), $concepto.': el stock va al reemplazo.');
            $this->assert_global_cuadra($articulo);
        }
    }

    /**
     * Test 5 — alta manual de un movimiento con la sucursal muerta: 422. Y un "Mov entre depositos"
     * con un id muerto no mueve nada.
     *
     * @test
     */
    public function un_movimiento_manual_o_entre_depositos_con_la_muerta_no_mueve_nada()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Ids viejos manual');

        $articulo = $this->nuevo_articulo('zz Ids viejos manual A');
        $this->cargar_deposito($articulo, $principal, 10);

        $muerta = $this->borrar_a_lo_bruto($borrar);

        $movimientos = $this->movimientos_de($articulo)->count();

        $respuesta = $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo->id,
            'amount'                       => 5,
            'to_address_id'                => $muerta,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya no existe', $respuesta->json('message'));
        $this->assertSame($movimientos, $this->movimientos_de($articulo)->count());
        $this->assertSame(0, $this->filas_de_pivot($muerta));

        // "Mov entre depositos" con la muerta de origen: no mueve nada.
        $ct = new StockMovementController();

        $resultado = $ct->crear([
            'model_id'                     => $articulo->id,
            'amount'                       => 3,
            'from_address_id'              => $muerta,
            'to_address_id'                => $principal->id,
            'concepto_stock_movement_name' => 'Mov entre depositos',
        ]);

        $this->assertNull($resultado, 'Un concepto que nombra depósitos con un id muerto no mueve nada.');
        $this->assertSame($movimientos, $this->movimientos_de($articulo)->count());
        $this->assertEquals(10.0, $this->stock_en($articulo, $principal->id));
        $this->assertSame(0, $this->filas_de_pivot($muerta));
    }

    /**
     * Test 6 — "Mover stock" de un traslado pendiente con un depósito muerto: 422, sin mover nada.
     *
     * @test
     */
    public function mover_stock_de_un_traslado_con_un_deposito_muerto_se_rechaza()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Ids viejos traslado');

        $articulo = $this->nuevo_articulo('zz Ids viejos traslado A');
        $this->cargar_deposito($articulo, $principal, 10);

        $muerta = $this->borrar_a_lo_bruto($borrar);

        // Un traslado que quedó cargado con la sucursal ya borrada (frente viejo, o de antes de la guarda).
        $traslado_id = DB::table('deposit_movements')->insertGetId([
            'num' => 990031, 'from_address_id' => $principal->id, 'to_address_id' => $muerta,
            'deposit_movement_status_id' => 1, 'user_id' => $this->comercio()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('article_deposit_movement')->insert(['article_id' => $articulo->id, 'deposit_movement_id' => $traslado_id, 'amount' => 4]);

        $respuesta = $this->postJson('api/deposit-movement/'.$traslado_id.'/move-stock');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya no existe', $respuesta->json('message'));

        $this->assertNull(DB::table('deposit_movements')->where('id', $traslado_id)->value('stock_moved_at'));
        $this->assertEquals(10.0, $this->stock_en($articulo, $principal->id));
        $this->assertSame(0, $this->filas_de_pivot($muerta));
    }

    /**
     * Test 7 — la edición de stock por sucursal (store viejo de la SPA) con la sucursal muerta: se
     * saltea, sin error y sin abrir filas.
     *
     * @test
     */
    public function editar_stock_por_sucursal_saltea_la_muerta()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Ids viejos edicion stock');

        $articulo = $this->nuevo_articulo('zz Ids viejos edicion stock A');
        $this->cargar_deposito($articulo, $principal, 10);

        $variante = $this->nueva_variante($articulo, 'Talle M');

        $muerta = $this->borrar_a_lo_bruto($borrar);

        $this->putJson('api/article-update-addresses', [
            'article_id' => $articulo->id,
            'addresses'  => [
                ['id' => $principal->id, 'pivot' => ['amount' => 12, 'stock_min' => 1, 'stock_max' => 20]],
                ['id' => $muerta,        'pivot' => ['amount' => 5,  'stock_min' => 1, 'stock_max' => 20]],
            ],
        ])->assertStatus(200);

        $this->assertSame(0, $this->filas_de_pivot($muerta), 'La sucursal muerta se saltea: ni stock ni mínimos le abren fila.');
        $this->assertEquals(12.0, $this->stock_en($articulo, $principal->id), 'La sucursal viva se actualiza igual.');

        // (la ruta se llama así, "varians", desde siempre)
        $this->putJson('api/article-update-varians-stock', [
            'article_id'         => $articulo->id,
            'variants_to_update' => [[
                'id'        => $variante->id,
                'addresses' => [
                    ['id' => $muerta, 'amount' => 3, 'on_display' => 0],
                ],
            ]],
        ]);

        $this->assertSame(0, $this->filas_de_pivot($muerta), 'Lo mismo para el stock por sucursal de una variante.');
    }

    /**
     * Un domicilio de envío de un comprador del comercio (lo escribe tienda-api: `buyer_id` y SIN
     * `user_id`).
     *
     * @param  string  $calle
     * @return \App\Models\Address
     */
    protected function domicilio_de_comprador($calle)
    {
        $buyer_id = DB::table('buyers')->insertGetId(['name' => 'zz Comprador '.$calle, 'user_id' => $this->comercio()->id, 'isVerified' => 0]);

        return Address::create(['street' => $calle, 'buyer_id' => $buyer_id]);
    }

    /**
     * Test 9 — "Poner stock en 0" con una fila NEGATIVA en el domicilio de un comprador (la dejaban
     * los pedidos de la tienda con envío): todo queda en 0. Con el criterio estricto, el motor mandaba
     * ese id a la sucursal por defecto y la fila negativa quedaba intacta (segunda ronda, hallazgo A).
     *
     * @test
     */
    public function poner_stock_en_0_lleva_a_0_la_fila_de_un_domicilio_de_comprador()
    {
        $principal = $this->sucursal_principal();
        $domicilio = $this->domicilio_de_comprador('zz Domicilio reseteo');

        $articulo = $this->nuevo_articulo('zz Reseteo con domicilio');
        $this->cargar_deposito($articulo, $principal, 5);

        // La fila histórica: un pedido con envío descontó 2 "desde" el domicilio del comprador.
        DB::table('address_article')->insert(['article_id' => $articulo->id, 'address_id' => $domicilio->id, 'amount' => -2]);
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 3]);

        $this->putJson('api/article/reset-stock/to-0', ['articles_id' => [$articulo->id]])->assertStatus(200);

        $this->assertEquals(0.0, $this->stock_en($articulo, $principal->id), 'La sucursal queda en 0.');
        $this->assertEquals(0.0, $this->stock_en($articulo, $domicilio->id), 'La fila del domicilio del comprador también queda en 0.');
        $this->assertEquals(0.0, $this->stock_global($articulo));
    }

    /**
     * Test 10 — una venta con el `address_id` de un domicilio de comprador (pedido de la tienda con
     * envío) conserva el comportamiento histórico: no se reemplaza por otra sucursal.
     *
     * @test
     */
    public function una_venta_con_un_domicilio_de_comprador_conserva_el_comportamiento_historico()
    {
        $principal = $this->sucursal_principal();
        $domicilio = $this->domicilio_de_comprador('zz Domicilio venta');

        $articulo = $this->nuevo_articulo('zz Venta con domicilio');
        $this->cargar_deposito($articulo, $principal, 10);

        $venta = $this->crear_venta([['article' => $articulo, 'amount' => 2, 'price' => 100]], $domicilio->id);

        $this->assertSame($domicilio->id, (int) $venta->fresh()->address_id, 'La venta conserva el domicilio del comprador.');

        $movimiento = StockMovement::where('sale_id', $venta->id)->where('article_id', $articulo->id)->first();

        $this->assertSame($domicilio->id, (int) $movimiento->from_address_id, 'El movimiento conserva el domicilio (no se redirige).');
        $this->assertEquals(10.0, $this->stock_en($articulo, $principal->id), 'La sucursal no se toca.');
        $this->assertEquals(-2.0, $this->stock_en($articulo, $domicilio->id), 'Comportamiento histórico: la fila del domicilio queda en −2.');
    }

    /**
     * Test 8 — el PDF del resumen de caja de una sucursal borrada no da 500.
     *
     * El constructor de ResumenCajaPdf hace `Output(); exit;` (mataría PHPUnit), así que se arma el
     * objeto sin constructor y se llama al bloque que nombraba la sucursal: `info_resumen()`.
     *
     * @test
     */
    public function el_resumen_de_caja_de_una_sucursal_borrada_no_rompe()
    {
        $borrar = $this->nueva_sucursal('zz Ids viejos resumen de caja');

        $muerta = $this->borrar_a_lo_bruto($borrar);

        $resumen = new ResumenCaja();
        $resumen->id = 990041;
        $resumen->address_id = $muerta;
        $resumen->created_at = now();
        $resumen->setRelation('turno_caja', (object) ['name' => 'Mañana']);
        $resumen->setRelation('employee', $this->comercio());

        $this->assertNull($resumen->address, 'El escenario: la sucursal del resumen ya no existe.');

        $reflexion = new \ReflectionClass(ResumenCajaPdf::class);

        $pdf = $reflexion->newInstanceWithoutConstructor();

        $reflexion->getParentClass()->getConstructor()->invoke($pdf, 'P', 'mm', [80, 300]);

        $pdf->line_height  = 5;
        $pdf->resumen_caja = $resumen;
        $pdf->x_incial     = 4;
        $pdf->cell_ancho   = 72;

        $pdf->AddPage();

        $pdf->info_resumen();

        $this->assertTrue(true, 'info_resumen() terminó sin excepción con la sucursal borrada.');
    }
}
