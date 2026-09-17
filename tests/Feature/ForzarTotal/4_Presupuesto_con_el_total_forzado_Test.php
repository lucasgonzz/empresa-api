<?php

namespace Tests\Feature\ForzarTotal;

use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 4 — presupuestos: el forzado se guarda, no lo rechaza la validacion del total, y
 * SOBREVIVE a la conversion en venta.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA VALIDACION QUE HABIA QUE CONTEMPLAR, Y EL TEST QUE PRUEBA QUE SIGUE VIVA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `BudgetController::store()` compara `BudgetHelper::getTotal($model)` contra `budgets.total` y,
 *  si difieren en mas de 3, corta con "El total del presupuesto no corresponde con los productos
 *  ingresados" (500, con rollback). Con un total forzado, `budgets.total` ES el forzado y los
 *  renglones suman el bruto: la diferencia es exactamente el monto del forzado.
 *
 *  Contemplar el monto en esa cuenta se podia hacer de dos maneras, y una es una trampa: aflojar
 *  el margen de 3, o restarle el monto a la comparacion. Las dos APAGAN la validacion para todo lo
 *  demas. Lo que se hizo es sumar el monto en `BudgetHelper::getTotal()`, que es el metodo que
 *  dice cuanto vale el presupuesto — y que ademas es el que usa `saveCurrentAcount()` para el
 *  `debe`. La validacion queda intacta.
 *
 *  Por eso el test 2 de este archivo es el de la validacion TODAVIA RECHAZANDO un total que no
 *  cuadra. Sin ese test, el test 1 no prueba que la validacion contemple el forzado: prueba que la
 *  validacion no existe mas.
 *
 * @group presupuestos
 * @group forzar_total
 */
class Presupuesto_con_el_total_forzado_Test extends ForzarTotalTestCase
{
    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /**
     * ⚠️ `budget_statuses` puede venir VACIA en la base del slot. Es una tabla global y
     * `BudgetHelper::checkStatus()` hace `$budget->budget_status->name` sin chequear null, asi que
     * sin estas filas confirmar no falla con un assert: revienta. Se siembra con ids explicitos y
     * `DatabaseTransactions` lo revierte.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $estados = [
            self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            if (is_null(BudgetStatus::find($id))) {

                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }
    }

    /**
     * Un renglon de articulo del payload de presupuesto.
     *
     * @param  float  $price
     * @param  float  $amount
     * @return array
     */
    protected function renglon($price, $amount = 1)
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        return [
            'id'     => $articulo->id,
            'status' => 'active',
            'pivot'  => [
                'amount'   => $amount,
                'bonus'    => null,
                'location' => null,
                'price'    => $price,
            ],
        ];
    }

    /**
     * Payload de POST api/budget.
     *
     * Los cuatro arrays de items van vacios pero PRESENTES: `attachArticles`/`attachServices`/
     * `attachPromocionVinotecas` hacen foreach sin chequear null.
     *
     * @param  float  $total       El total del presupuesto (el forzado, si lo hay).
     * @param  array  $articles
     * @param  array  $overrides
     * @return array
     */
    protected function payload_presupuesto($total, $articles, $overrides = [])
    {
        return array_merge([
            'client_id'                        => $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC)->id,
            'start_at'                         => null,
            'finish_at'                        => null,
            'observations'                     => null,
            'price_type_id'                    => null,
            'sale_status_id'                   => null,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 1,
            'total'                            => $total,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                       => null,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'moneda_id'                        => 1,
            'valor_dolar'                      => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'combos'                           => [],
            'articles'                         => $articles,
        ], $overrides);
    }

    /**
     * Test 1 — un presupuesto con el total forzado se guarda, con el monto en su columna.
     *
     * @group forzar_total
     * @test
     */
    public function un_presupuesto_con_el_total_forzado_se_guarda()
    {
        $payload = $this->payload_presupuesto(
            self::FORZADO,
            [$this->renglon(self::BRUTO, 1)],
            ['forzar_total_monto' => self::MONTO]
        );

        $budget_id = $this->postJson('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $budget = Budget::find($budget_id);

        $this->assertNotNull($budget, 'El presupuesto tiene que haberse creado.');

        $this->assertEqualsWithDelta(
            self::FORZADO,
            (float) $budget->total,
            self::DELTA,
            'budgets.total tiene que ser el total forzado'
        );

        $this->assertEqualsWithDelta(
            self::MONTO,
            (float) $budget->forzar_total_monto,
            self::DELTA,
            'budgets.forzar_total_monto tiene que guardar los -12'
        );
    }

    /**
     * Test 2 — LA VALIDACION SIGUE VIVA. Un total que no cuadra con los renglones y sin forzado
     * se sigue rechazando, con rollback.
     *
     * Este test es el que le da sentido al de arriba: sin el, el test 1 solo probaria que la
     * validacion dejo de existir.
     *
     * @group forzar_total
     * @test
     */
    public function un_total_que_no_cuadra_y_sin_forzado_se_sigue_rechazando()
    {
        $marca = 'presupuesto descuadrado de la suite de forzar total';

        $payload = $this->payload_presupuesto(
            3000.00,
            [$this->renglon(self::BRUTO, 1)],
            ['observations' => $marca]
        );

        $this->postJson('api/budget', $payload)->assertStatus(500);

        $this->assertEquals(
            0,
            Budget::where('observations', $marca)->count(),
            'el rollback tiene que haber dejado el presupuesto sin crear'
        );
    }

    /**
     * Test 3 — un monto de forzado que NO explica la diferencia tampoco pasa.
     *
     * El presupuesto dice 3.000 sobre renglones de 4.012 y declara un forzado de apenas -12: la
     * cuenta sigue sin cerrar por mil pesos. Si esto pasara, el campo seria una puerta para
     * guardar cualquier total.
     *
     * @group forzar_total
     * @test
     */
    public function un_monto_que_no_explica_la_diferencia_tampoco_pasa()
    {
        $marca = 'presupuesto con forzado insuficiente de la suite de forzar total';

        $payload = $this->payload_presupuesto(
            3000.00,
            [$this->renglon(self::BRUTO, 1)],
            ['forzar_total_monto' => self::MONTO, 'observations' => $marca]
        );

        $this->postJson('api/budget', $payload)->assertStatus(500);

        $this->assertEquals(
            0,
            Budget::where('observations', $marca)->count(),
            'el monto forzado no puede servir para guardar un total que no cierra'
        );
    }

    /**
     * Test 4 — al CONFIRMAR el presupuesto, el monto viaja a la venta junto con el total.
     *
     * Es el paso donde el numero pasa a ser plata de verdad: la venta que nace acá es la que va a
     * la cuenta corriente, a la caja y a la factura.
     *
     * @group forzar_total
     * @test
     */
    public function al_confirmar_el_presupuesto_el_monto_viaja_a_la_venta()
    {
        $budget_id = $this->postJson('api/budget', $this->payload_presupuesto(
            self::FORZADO,
            [$this->renglon(self::BRUTO, 1)],
            ['forzar_total_monto' => self::MONTO]
        ))->assertStatus(201)->json('model.id');

        $this->postJson('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget_id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        $this->assertEqualsWithDelta(
            self::FORZADO,
            (float) $sale->total,
            self::DELTA,
            'la venta tiene que nacer con el total forzado del presupuesto'
        );

        $this->assertEqualsWithDelta(
            self::MONTO,
            (float) $sale->forzar_total_monto,
            self::DELTA,
            'el monto tiene que viajar del presupuesto a la venta: sin el, nadie puede volver a explicar de donde sale ese total'
        );
    }
}
