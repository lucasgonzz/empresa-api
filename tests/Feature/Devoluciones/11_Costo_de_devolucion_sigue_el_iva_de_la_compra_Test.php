<?php

namespace Tests\Feature\Devoluciones;

use App\Models\Iva;
use App\Models\ProviderOrderAfipTicket;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Misión `devolucion-proveedor-iva-sin-factura` (9/10/2026): el costo con el que se le devuelve
 * mercadería al proveedor sigue el IVA que la compra EFECTIVAMENTE cobró, no la bandera
 * `total_with_iva`.
 *
 * 🔴 EL DEFECTO (medido por Lucas en demo2 4.3.8, video T5.26). La SPA manda `total_with_iva = 1`
 * en TODA compra, pero `NewProviderOrderHelper::set_totales()` suma por encima del total el IVA de
 * los comprobantes de la compra (Σ `provider_order_afip_tickets.total_iva`), nunca uno propio. En
 * `modo_facturacion = 'sin factura'` `ModoFacturacionHelper` borra los comprobantes, así que la
 * compra no cobra IVA; la devolución igual le sumaba el 21 %: compra sin factura de 10 × $2.444,
 * devolver 2 acreditaba $5.914,48 en vez de $4.888.
 *
 * Lo que se prueba, modo por modo, por el camino real de la pantalla: la compra por
 * `POST api/provider-order` (siempre con `total_with_iva = 1`, como la SPA), el costo por
 * `GET api/devoluciones/search-provider-order/{num}` y la NC a cuenta corriente por
 * `POST api/devoluciones/` con ese costo y `total_devolucion` = costo × unidades.
 *
 *   - sin factura: no suma IVA (el caso medido).
 *   - automático: suma el IVA de la factura que arma el sistema.
 *   - manual con factura con IVA: suma el IVA (la factura se carga por los endpoints reales).
 *   - manual sin factura, o con una factura que no discrimina IVA: no suma.
 *   - automática editada a sin factura: deja de sumar (el orden check_modo_facturacion() →
 *     set_totales() de la edición).
 *   - compra vieja (`total_iva` NULL, de antes de que set_totales() lo escribiera): suma si tiene
 *     `total_with_iva`, como la sumaba el ProviderOrderHelper::getTotal() de entonces.
 *   - Monotributista y `precios_incluyen_iva`: no suma (guardas de lo que ya andaba).
 *
 * Y en cada modo el invariante de siempre: devolver todo da exactamente el total de la compra (sin
 * costos extra, que no se le devuelven al proveedor).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group devoluciones
 */
class Costo_de_devolucion_sigue_el_iva_de_la_compra_Test extends NotaCreditoProveedorTestCase
{
    /** Tolerancia de las comparaciones de plata (las columnas son decimal(x,2)). */
    const DELTA = 0.01;

    /** El renglón del video T5.26: LLAVE T 10MM a $2.444. */
    const COSTO = 2444;

    /** Unidades compradas. */
    const CANTIDAD = 10;

    /** Unidades que se devuelven en cada caso. */
    const DEVUELTAS = 2;

    /* ------------------------------------------------------------------ */
    /* Helpers del escenario                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Compra de un artículo propio (IVA 21 %) por el endpoint real: 10 × $2.444, Rosario (sin
     * bonificaciones), en pesos, a cuenta corriente y con `total_with_iva = 1`, que es lo que manda
     * la SPA en toda compra.
     *
     * @param  string               $nombre
     * @param  array<string,mixed>  $overrides  Overrides del payload de la compra.
     * @return array{0: \App\Models\Article, 1: \App\Models\ProviderOrder}
     */
    protected function compra_de_la_llave($nombre, $overrides = [])
    {
        $articulo = $this->crear_articulo($nombre);

        $compra = $this->crear_compra(
            [$this->renglon_compra($articulo, self::COSTO, self::CANTIDAD)],
            array_merge([
                'total_with_iva'          => 1,
                'generate_current_acount' => 1,
            ], $overrides)
        );

        return [$articulo, $compra];
    }

    /**
     * El payload ENTERO de la compra de la llave, el mismo que arma crear_compra() de
     * NotaCreditoProveedorTestCase: el controller de compras no es un PATCH parcial, para editarla
     * hay que remandar todo.
     *
     * @param  \App\Models\Article  $articulo
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function payload_de_la_llave($articulo, $overrides = [])
    {
        return $this->payload_compra(array_merge([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'moneda_id'               => 1,
            'address_id'              => null,
            'articles'                => [$this->renglon_compra($articulo, self::COSTO, self::CANTIDAD)],
            'total_with_iva'          => 1,
            'generate_current_acount' => 1,
        ], $overrides));
    }

    /**
     * Deja una compra recién creada como una de antes del 2/10/2024: con `total_iva` en NULL.
     *
     * Desde ese día (`149786ab`) set_totales() escribe `total_iva` SIEMPRE, con un número; una
     * compra con NULL nunca pasó por ahí y su total lo armó el ProviderOrderHelper::getTotal() viejo,
     * que sumaba el IVA por renglón con solo `total_with_iva`. Se fuerza por la base porque ningún
     * endpoint de hoy deja ese estado.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  array<string,mixed>        $columnas  Otras columnas a forzar junto con el NULL.
     * @return void
     */
    protected function como_compra_vieja($compra, $columnas = [])
    {
        DB::table('provider_orders')
            ->where('id', $compra->id)
            ->update(array_merge(['total_iva' => null], $columnas));

        $compra->refresh();

        $this->assertNull($compra->total_iva, 'La compra tenía que quedar con total_iva en NULL; el escenario no sirve.');
    }

    /**
     * El `costo_unitario_devolucion` que el GET le da a la pantalla para ese artículo.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  \App\Models\Article        $articulo
     * @return float
     */
    protected function costo_de_devolucion($compra, $articulo)
    {
        $renglon = $this->buscar_compra($compra)['articles'][$articulo->id];

        $this->assertEquals(self::CANTIDAD, $renglon['cantidad_efectiva'], 'La compra no quedó con las 10 unidades; el escenario no sirve.');

        return (float) $renglon['costo_unitario_devolucion'];
    }

    /**
     * Devuelve unidades a cuenta corriente como lo hace la pantalla: el costo que dio el GET en
     * `price_vender`/`costo_real` y `total_devolucion` = costo × unidades. Devuelve la NC.
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  \App\Models\Article        $articulo
     * @param  float                      $costo
     * @param  float                      $unidades
     * @return \App\Models\CurrentAcount
     */
    protected function devolver_a_cuenta_corriente($compra, $articulo, $costo, $unidades)
    {
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, $costo, $unidades)],
            $costo * $unidades,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        $nota_credito = $this->nota_credito_de($compra);

        $this->assertNotNull($nota_credito, 'No se creó la NC atada a la compra.');
        $this->assertNotNull($nota_credito->credit_account_id, 'Con C/C la NC tiene que entrar a la cuenta del proveedor.');

        return $nota_credito;
    }

    /**
     * Carga una factura a una compra manual por el endpoint real (mismo request que
     * `Factura_De_Compra_Total_Y_Percepciones_Test::crear_factura()`). El controller recalcula la
     * compra al guardarla (`FacturaDeCompraHelper::recalcular_compra()` → `set_totales()`).
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return \App\Models\ProviderOrderAfipTicket
     */
    protected function crear_factura($compra)
    {
        $response = $this->postJson('api/provider-order-afip-ticket', [
            'model_id'        => $compra->id,
            'code'            => '0001-00000001',
            'issued_at'       => '2026-10-09',
            'percepcion_iibb' => null,
            'percepcion_iva'  => null,
            'total'           => 0,
        ]);

        $response->assertStatus(201);

        return ProviderOrderAfipTicket::find($response->json('model.id'));
    }

    /**
     * Agrega una alícuota a una factura por el endpoint real (también recalcula la compra).
     *
     * @param  \App\Models\ProviderOrderAfipTicket  $factura
     * @param  float                                $neto
     * @param  float                                $iva_importe
     * @return void
     */
    protected function agregar_alicuota_21($factura, $neto, $iva_importe)
    {
        $iva_21 = Iva::where('percentage', '21')->first();

        $this->assertNotNull($iva_21, 'La base de testing tiene que tener sembrada la alícuota "21".');

        $this->postJson('api/provider-order-afip-ticket-iva', [
            'model_id'    => $factura->id,
            'iva_id'      => $iva_21->id,
            'neto'        => $neto,
            'iva_importe' => $iva_importe,
        ])->assertStatus(201);
    }

    /**
     * Devolver todo tiene que dar exactamente el total de la compra (sin costos extra).
     *
     * @param  float                      $costo
     * @param  \App\Models\ProviderOrder  $compra
     * @return void
     */
    protected function assert_devolver_todo_da_el_total($costo, $compra)
    {
        $this->assertEqualsWithDelta(
            (float) $compra->total,
            $costo * self::CANTIDAD,
            self::DELTA,
            'Devolver las 10 unidades tiene que dar exactamente lo que la compra cobró.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Los modos de facturación                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 El caso medido (video T5.26): compra SIN FACTURA. La compra no cobra IVA (no tiene
     * comprobantes), así que la devolución tampoco: 2 unidades acreditan $4.888, no $5.914,48.
     *
     * @test
     */
    public function sin_factura_la_devolucion_no_suma_el_iva_que_la_compra_no_cobro()
    {
        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm sin factura', [
            'modo_facturacion' => 'sin factura',
        ]);

        $this->assertEquals(0, ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->count(), 'Una compra sin factura no puede tener comprobantes.');
        $this->assertEqualsWithDelta(24440, (float) $compra->total, self::DELTA, 'Sin factura la compra no suma IVA: 10 × 2444.');
        $this->assertEqualsWithDelta(0, (float) $compra->total_iva, self::DELTA);

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2444, $costo, self::DELTA, 'La compra no cobró IVA: la devolución no se lo puede sumar.');
        $this->assert_devolver_todo_da_el_total($costo, $compra);

        $nota_credito = $this->devolver_a_cuenta_corriente($compra, $articulo, $costo, self::DEVUELTAS);

        $this->assertEqualsWithDelta(4888, (float) $nota_credito->haber, self::DELTA, 'Devolver 2 acredita 2 × 2444, no 5914,48.');
    }

    /**
     * Compra AUTOMÁTICA: el sistema arma la factura con el IVA por renglón y la compra lo suma.
     * La devolución lo suma igual: 2444 + 21 % = 2957,24 por unidad.
     *
     * @test
     */
    public function automatica_la_devolucion_suma_el_iva_de_la_factura()
    {
        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm automatica', [
            'modo_facturacion' => 'automatico',
        ]);

        $this->assertEqualsWithDelta(5132.40, (float) $compra->total_iva, self::DELTA, 'La factura automática lleva el 21 % de 24440.');
        $this->assertEqualsWithDelta(29572.40, (float) $compra->total, self::DELTA, 'La compra suma el IVA de su factura: 24440 + 5132,40.');

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2957.24, $costo, self::DELTA);
        $this->assert_devolver_todo_da_el_total($costo, $compra);

        $nota_credito = $this->devolver_a_cuenta_corriente($compra, $articulo, $costo, self::DEVUELTAS);

        $this->assertEqualsWithDelta(5914.48, (float) $nota_credito->haber, self::DELTA);
    }

    /**
     * Compra MANUAL con la factura del proveedor cargada a mano, con su IVA: la compra lo suma al
     * guardar la factura, y la devolución también.
     *
     * @test
     */
    public function manual_con_factura_con_iva_la_devolucion_suma_el_iva()
    {
        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm manual con iva', [
            'modo_facturacion' => 'manual',
        ]);

        $this->assertEqualsWithDelta(24440, (float) $compra->total, self::DELTA, 'Antes de cargar la factura la compra manual no tiene IVA que sumar.');

        $factura = $this->crear_factura($compra);
        $this->agregar_alicuota_21($factura, 24440, 5132.40);

        $compra->refresh();

        $this->assertEqualsWithDelta(5132.40, (float) $compra->total_iva, self::DELTA, 'El IVA de la compra es el de la factura cargada.');
        $this->assertEqualsWithDelta(29572.40, (float) $compra->total, self::DELTA, 'Guardar la factura recalcula la compra: 24440 + 5132,40.');

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2957.24, $costo, self::DELTA);
        $this->assert_devolver_todo_da_el_total($costo, $compra);

        $nota_credito = $this->devolver_a_cuenta_corriente($compra, $articulo, $costo, self::DEVUELTAS);

        $this->assertEqualsWithDelta(5914.48, (float) $nota_credito->haber, self::DELTA);
    }

    /**
     * Compra MANUAL sin factura cargada, y después con una factura que no discrimina IVA (Factura
     * C/B, sin alícuotas): la compra no suma IVA en ningún momento, y la devolución tampoco.
     *
     * @test
     */
    public function manual_sin_factura_o_con_factura_sin_iva_la_devolucion_no_suma_el_iva()
    {
        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm manual sin iva', [
            'modo_facturacion' => 'manual',
        ]);

        $this->assertEqualsWithDelta(24440, (float) $compra->total, self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $compra->total_iva, self::DELTA);

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2444, $costo, self::DELTA, 'Sin factura cargada la compra manual no cobró IVA.');
        $this->assert_devolver_todo_da_el_total($costo, $compra);

        // La factura llega y no discrimina IVA: se carga sin alícuotas.
        $this->crear_factura($compra);

        $compra->refresh();

        $this->assertEqualsWithDelta(24440, (float) $compra->total, self::DELTA, 'Una factura sin alícuotas no le agrega IVA a la compra.');
        $this->assertEqualsWithDelta(0, (float) $compra->total_iva, self::DELTA);

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2444, $costo, self::DELTA, 'Con una factura que no discrimina IVA la devolución tampoco lo suma.');
        $this->assert_devolver_todo_da_el_total($costo, $compra);

        $nota_credito = $this->devolver_a_cuenta_corriente($compra, $articulo, $costo, self::DEVUELTAS);

        $this->assertEqualsWithDelta(4888, (float) $nota_credito->haber, self::DELTA);
    }

    /**
     * Compra AUTOMÁTICA editada a SIN FACTURA por el endpoint real: la edición borra la factura
     * (check_modo_facturacion()) ANTES de recalcular la compra (set_totales()), así que el total
     * baja al neto y la devolución deja de sumar el IVA. Si ese orden se diera vuelta, la compra
     * se quedaría con el `total_iva` de la factura borrada y la devolución lo seguiría sumando.
     *
     * @test
     */
    public function automatica_editada_a_sin_factura_deja_de_sumar_el_iva()
    {
        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm editada a sin factura', [
            'modo_facturacion' => 'automatico',
        ]);

        $this->assertEqualsWithDelta(29572.40, (float) $compra->total, self::DELTA, 'Punto de partida: la compra automática suma el IVA de su factura.');
        $this->assertEqualsWithDelta(2957.24, $this->costo_de_devolucion($compra, $articulo), self::DELTA);

        $this->putJson('api/provider-order/'.$compra->id, $this->payload_de_la_llave($articulo, [
            'modo_facturacion' => 'sin factura',
        ]))->assertStatus(200);

        $compra->refresh();

        $this->assertEquals(0, ProviderOrderAfipTicket::where('provider_order_id', $compra->id)->count(), 'Pasar a sin factura tiene que borrar la factura automática.');
        $this->assertEqualsWithDelta(24440, (float) $compra->total, self::DELTA, 'Sin factura la compra vuelve al neto: 10 × 2444.');
        $this->assertEqualsWithDelta(0, (float) $compra->total_iva, self::DELTA);

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2444, $costo, self::DELTA, 'La compra ya no cobra IVA: la devolución tampoco.');
        $this->assert_devolver_todo_da_el_total($costo, $compra);
    }

    /* ------------------------------------------------------------------ */
    /* Las compras viejas (de antes de que set_totales() escribiera el IVA) */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 Compra vieja (`total_iva` NULL) con `total_with_iva`: el getTotal() de entonces le sumó
     * el IVA por renglón, así que la devolución lo sigue sumando. Sin esta guarda, el arreglo de
     * "sin factura" les sacaba el IVA a todas las compras de antes del 2/10/2024.
     *
     * @test
     */
    public function compra_vieja_sin_total_iva_con_total_with_iva_devuelve_con_iva()
    {
        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm compra vieja con iva', [
            'modo_facturacion' => 'automatico',
        ]);

        $this->como_compra_vieja($compra);

        $this->assertEqualsWithDelta(29572.40, (float) $compra->total, self::DELTA, 'El total de la compra vieja trae el IVA sumado.');

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2957.24, $costo, self::DELTA, 'Una compra vieja con total_with_iva cobró el IVA: la devolución lo suma.');
        $this->assert_devolver_todo_da_el_total($costo, $compra);
    }

    /**
     * Compra vieja (`total_iva` NULL) SIN `total_with_iva`: el getTotal() de entonces no le sumó
     * IVA (total = neto), y la devolución tampoco.
     *
     * @test
     */
    public function compra_vieja_sin_total_iva_ni_total_with_iva_devuelve_sin_iva()
    {
        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm compra vieja sin iva', [
            'modo_facturacion' => 'automatico',
        ]);

        // Sin `total_with_iva` el getTotal() viejo dejaba el total en el neto: se fuerza también.
        $this->como_compra_vieja($compra, [
            'total_with_iva' => 0,
            'total'          => 24440,
        ]);

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2444, $costo, self::DELTA, 'Una compra vieja sin total_with_iva no cobró IVA.');
        $this->assert_devolver_todo_da_el_total($costo, $compra);
    }

    /* ------------------------------------------------------------------ */
    /* Las otras dos patas de la condición (ya andaban: quedan de guarda)   */
    /* ------------------------------------------------------------------ */

    /**
     * Monotributista: la compra no suma IVA por encima aunque sea automática, y la devolución
     * tampoco.
     *
     * @test
     */
    public function monotributista_no_suma_iva_aunque_la_compra_sea_automatica()
    {
        $this->set_condicion_iva('MT');

        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm monotributista', [
            'modo_facturacion' => 'automatico',
        ]);

        $this->assertEqualsWithDelta(24440, (float) $compra->total, self::DELTA, 'Un Monotributista no suma el IVA por encima del total.');

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2444, $costo, self::DELTA);
        $this->assert_devolver_todo_da_el_total($costo, $compra);
    }

    /**
     * `precios_incluyen_iva`: el costo tipeado ya trae el IVA adentro, la compra no lo vuelve a
     * sumar y la devolución tampoco.
     *
     * @test
     */
    public function con_precios_que_incluyen_iva_el_costo_no_le_vuelve_a_sumar_iva()
    {
        list($articulo, $compra) = $this->compra_de_la_llave('zz Llave T 10mm iva incluido', [
            'modo_facturacion'     => 'automatico',
            'precios_incluyen_iva' => 1,
        ]);

        $this->assertEqualsWithDelta(24440, (float) $compra->total, self::DELTA, 'Con el IVA adentro del costo la compra no lo suma de nuevo.');

        $costo = $this->costo_de_devolucion($compra, $articulo);

        $this->assertEqualsWithDelta(2444, $costo, self::DELTA);
        $this->assert_devolver_todo_da_el_total($costo, $compra);
    }
}
