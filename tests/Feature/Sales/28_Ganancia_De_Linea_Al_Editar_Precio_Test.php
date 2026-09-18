<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión saneo-ganancia-ventas (17/9/2026) — editar el precio de una venta guardada recalcula la
 * ganancia de la línea.
 *
 * `SaleHelper::updateItemsPrices()` (endpoint `PUT api/sale/update-prices/{id}`) cambiaba `price` y
 * `price_sin_iva` en el pivot y NO tocaba `ganancia`: la línea quedaba con la ganancia del precio
 * VIEJO, `(price_viejo − cost) × amount`. Un dato incorrecto que el sistema seguía generando todos
 * los días, cada vez que alguien corregía el precio de una venta ya cargada.
 *
 * 🔴 Y con una consecuencia que no se ve desde la columna: esa línea desincronizada cumple la firma
 * aritmética con la que `sale:sanear-costo-de-linea` reconoce lo que rompió `set_costo_ventas`, así
 * que el saneo la confundía con una línea a corregir. El comando terminó con una guarda extra para
 * no comérselas; esto saca la causa.
 *
 * Números del archivo, los mismos que fabricaban el falso positivo: costo 100, cantidad 2, precio
 * 300 → ganancia de línea 400. Editado a 250 → **300**, no 400.
 *
 * @group sales
 */
class Ganancia_De_Linea_Al_Editar_Precio_Test extends EmpresaTestCase
{
    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /**
     * Ids de los artículos creados por este archivo.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * Borra los artículos que creó el test antes del rollback de `DatabaseTransactions`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->articulos_creados) >= 1) {
            Article::whereIn('id', $this->articulos_creados)->forceDelete();
        }

        parent::tearDown();
    }

    /**
     * Test 1 — Editar el precio recalcula la ganancia de la línea, con la convención TOTAL.
     *
     * Costo 100, cantidad 2, precio 300 → la venta nace con la línea en 400. Se edita el precio a
     * 250 y la línea tiene que quedar en **300**: `(250 − 100) × 2`.
     *
     * Se assertea primero el 400 de la venta recién creada para que el test pruebe el recálculo y no
     * una coincidencia: si las dos aserciones dieran lo mismo, no se estaría midiendo nada.
     *
     * @group sales
     * @test
     */
    public function editar_el_precio_recalcula_la_ganancia_de_la_linea()
    {
        $venta = $this->crear_venta_con_una_linea(100, 300.00, 2);

        $this->assertEqualsWithDelta(
            400.00,
            (float) $this->linea_de($venta)->ganancia,
            self::DELTA,
            'Guard del escenario: attachArticle() deja la ganancia TOTAL de la línea, (300 - 100) x 2.'
        );

        $this->editar_precio($venta, 250.00);

        $this->assertEqualsWithDelta(
            300.00,
            (float) $this->linea_de($venta)->ganancia,
            self::DELTA,
            'Con el precio nuevo la ganancia de la línea es (250 - 100) x 2 = 300. Si sigue en 400, '.
            'quedó la del precio viejo — que es, además, la línea que le fabrica un falso positivo '.
            'al comando de saneo.'
        );
    }

    /**
     * Test 2 — El precio y el `price_sin_iva` siguen actualizándose igual.
     *
     * No-regresión de lo que el método ya hacía: agregarle la ganancia no puede costarle lo suyo.
     *
     * @group sales
     * @test
     */
    public function editar_el_precio_sigue_escribiendo_el_precio_y_el_precio_sin_iva()
    {
        $venta = $this->crear_venta_con_una_linea(100, 300.00, 2);

        $this->editar_precio($venta, 250.00);

        $linea = $this->linea_de($venta);

        $this->assertEqualsWithDelta(250.00, (float) $linea->price, self::DELTA, 'El precio tiene que quedar en 250.');

        $this->assertEqualsWithDelta(
            206.61,
            (float) $linea->price_sin_iva,
            self::DELTA,
            'El precio sin IVA se recalcula con la alícuota del artículo: 250 / 1,21 = 206,61.'
        );
    }

    /**
     * Test 3 — Con `cost` en NULL no se inventa un costo de cero: la ganancia queda en NULL.
     *
     * Pasa de verdad: `OrderProductionHelper::attachSaleArticles()` deja líneas sin costo. Escribir
     * `(250 − 0) × 2 = 500` informaría como ganancia el precio entero de la línea. NULL ya significa
     * "no se puede calcular" en `sales.ganancia`, y es la única respuesta que no miente.
     *
     * Se ensucia la columna con un número antes de editar: si el método no escribiera nada, la
     * basura quedaría y el test se pondría rojo igual.
     *
     * @group sales
     * @test
     */
    public function sin_costo_en_la_linea_la_ganancia_no_se_inventa()
    {
        $venta = $this->crear_venta_con_una_linea(100, 300.00, 2);

        DB::table('article_sale')
            ->where('sale_id', $venta->id)
            ->update(['cost' => null, 'ganancia' => 999999]);

        $this->editar_precio($venta, 250.00);

        $ganancia = $this->linea_de($venta)->ganancia;

        $this->assertNull(
            $ganancia,
            'Sin costo en la línea no hay ganancia calculable: tiene que quedar en NULL, nunca en '.
            '500 (que sería suponerle costo cero) ni en la basura anterior. Encontrado: '.
            var_export($ganancia, true)
        );
    }

    /**
     * Test 4 — 🔴 DÓNDE TERMINA ESTE ARREGLO: el renglón se actualiza, la VENTA no.
     *
     * `update-prices` es el PASO 1 de un flujo de DOS, aclarado por Lucas el 2/9/2026 y fijado en
     * `17_Actualizar_venta_stock_y_precio_congelado_Test`: el modal persiste los precios de los
     * renglones y después la SPA carga la venta en Vender; el total y la cuenta corriente se
     * recalculan recién cuando el operador guarda ahí (`PUT api/sale/{id}`, que termina en
     * `attachProperies()` → `set_total_cost()` → `set_sale_ganancia()`).
     *
     * Por eso este arreglo llega hasta el pivot y no más. Recalcular `sales.total` acá le cambiaría
     * el contrato al paso 1 —y con él la deuda que ese paso recrea, que lo lee—, y eso es una
     * decisión de flujo, no un arreglo de un número mal calculado. Se probó: rompe las dos
     * aserciones de aquel test, y esas aserciones están escritas a propósito.
     *
     * La ganancia de la LÍNEA sí corresponde: sale del precio de esa misma línea, que es
     * exactamente lo que el paso 1 acaba de persistir. Dejarla vieja es tener el pivot peleado
     * consigo mismo.
     *
     * ⚠️ Lo que queda abierto y no es de este arreglo: si el operador abandona el flujo entre los
     * dos pasos, la venta queda con los renglones nuevos y el total viejo — la "ventana de
     * abandono" que documenta aquel test. Ahora `article_sale.ganancia` acompaña al renglón, así
     * que en esa ventana la suma de las líneas tampoco cuadra con `sales.ganancia`. Cuadraba antes
     * sólo porque las dos estaban viejas.
     *
     * @group sales
     * @test
     */
    public function editar_el_precio_no_recalcula_el_total_de_la_venta_que_es_del_paso_2()
    {
        $venta = $this->crear_venta_con_una_linea(100, 300.00, 2);

        $this->assertEqualsWithDelta(
            600.00,
            (float) Sale::find($venta->id)->total,
            self::DELTA,
            'Guard del escenario: la venta nace con total 300 x 2.'
        );

        $this->editar_precio($venta, 250.00);

        $venta_actualizada = Sale::find($venta->id);

        $this->assertEqualsWithDelta(
            600.00,
            (float) $venta_actualizada->total,
            self::DELTA,
            'El paso 1 NO recalcula el total: eso es del paso 2 (guardar en Vender). Si esta '.
            'aserción se pone roja, el contrato del flujo de dos pasos cambió y hay que releerlo '.
            'junto con 17_Actualizar_venta_stock_y_precio_congelado_Test.'
        );

        $this->assertEqualsWithDelta(
            300.00,
            (float) $this->linea_de($venta)->ganancia,
            self::DELTA,
            'Pero la ganancia del RENGLÓN sí, porque sale del precio que este mismo paso acabó de '.
            'escribir: (250 - 100) x 2 = 300.'
        );
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Id del usuario dueño del fixture de testing.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Fila cruda del pivot `article_sale` de la (única) línea de la venta.
     *
     * Se lee por query builder y no por la relación para que no haya caché de Eloquent de por medio:
     * lo que interesa es lo que quedó GUARDADO.
     *
     * @param  \App\Models\Sale $venta
     * @return mixed
     */
    protected function linea_de($venta)
    {
        $linea = DB::table('article_sale')->where('sale_id', $venta->id)->first();

        if (is_null($linea)) {
            $this->fail('La venta '.$venta->id.' no tiene ninguna línea en article_sale.');
        }

        return $linea;
    }

    /**
     * Pega contra `PUT api/sale/update-prices/{id}` con el precio nuevo del (único) artículo de la
     * venta, que es exactamente lo que manda la SPA desde el modal de Ventas.
     *
     * @param  \App\Models\Sale $venta
     * @param  float $price_vender
     * @return void
     */
    protected function editar_precio($venta, $price_vender)
    {
        $linea = $this->linea_de($venta);

        $response = $this->putJson('api/sale/update-prices/'.$venta->id, [
            'items' => [[
                'is_article'   => true,
                'id'           => $linea->article_id,
                'price_vender' => $price_vender,
            ]],
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->fail('PUT api/sale/update-prices devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }
    }

    /**
     * Crea una venta de una sola línea por el endpoint real y devuelve el modelo ya guardado.
     *
     * @param  float $costo_real Costo unitario del artículo.
     * @param  float $price_vender Precio unitario de venta.
     * @param  int $amount Unidades.
     * @return \App\Models\Sale
     */
    protected function crear_venta_con_una_linea($costo_real, $price_vender, $amount)
    {
        $articulo = Article::create([
            'name'       => 'zz Test ganancia al editar precio '.uniqid(),
            'user_id'    => $this->user_id(),
            'costo_real' => $costo_real,
        ]);

        $this->articulos_creados[] = $articulo->id;

        $total = round((float) $price_vender * (int) $amount, 2);

        $response = $this->postJson('api/sale', [
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
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
            'items'                            => [[
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => $price_vender,
                'amount'       => $amount,
                'costo_real'   => $costo_real,
            ]],
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $venta = Sale::find($response->json('model.id'));

        if (is_null($venta->total_cost) || (float) $venta->total_cost == 0.0) {
            $this->fail(
                'La venta recién creada quedó con total_cost '.var_export($venta->total_cost, true).
                '. Sin costo persistido este test no prueba nada.'
            );
        }

        return $venta;
    }
}
