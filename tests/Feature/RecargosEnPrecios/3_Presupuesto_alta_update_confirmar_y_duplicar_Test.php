<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Budget;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;

/**
 * Archivo 3 — el PRESUPUESTO guarda la base en el alta y en el update, y la PROPAGA al confirmarlo
 * (la venta hereda precio Y base) y al duplicarlo.
 *
 * Confirmar y duplicar son los dos caminos donde un renglon se COPIA en vez de venir de VENDER: si
 * alguno copiara el precio y no la base, el comprobante nuevo nace con el recargo adentro y sin
 * registro, o sea bloqueado para editar aunque el original no lo este.
 *
 * Formato del payload, calcado de `vender_presupuestos.js`: en el alta los articulos van planos;
 * en el update, los que ya estaban cargados van bajo `pivot`; servicios, promociones y combos,
 * siempre bajo `pivot`. La clave va al lado de `price`.
 *
 * @group recargos_en_precios
 */
class Presupuesto_alta_update_confirmar_y_duplicar_Test extends RecargosEnPreciosTestCase
{
    /** @var \App\Models\Article */
    protected $articulo;

    /** @var \App\Models\Service */
    protected $servicio;

    /** @var \App\Models\Combo */
    protected $combo_modelo;

    /** @var \App\Models\PromocionVinoteca */
    protected $promo;

    /** @var \App\Models\Surchage */
    protected $recargo_modelo;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->articulo       = $this->articulo_centinela();
        $this->servicio       = $this->servicio(50);
        $this->combo_modelo   = $this->combo(200);
        $this->promo          = $this->promocion(300);
        $this->recargo_modelo = $this->recargo();
    }

    /**
     * Crea por el endpoint un presupuesto con la opcion prendida y los cuatro tipos de renglon:
     * articulo 110 (base 100), servicio 55 (50), combo 220 (200), promo 330 (300). Total 715.
     *
     * @return \App\Models\Budget
     */
    protected function presupuesto_prendido()
    {
        $response = $this->postJson('api/budget', $this->payload_presupuesto(715.00, 1, [$this->recargo_del_payload($this->recargo_modelo)], [
            'articles'            => [$this->renglon_articulo_presupuesto($this->articulo->id, 110, 100, false)],
            'services'            => [$this->renglon_pivot_presupuesto($this->servicio->id, 55, 50)],
            'combos'              => [$this->renglon_pivot_presupuesto($this->combo_modelo->id, 220, 200)],
            'promocion_vinotecas' => [$this->renglon_pivot_presupuesto($this->promo->id, 330, 300)],
        ]));

        $response->assertStatus(201);

        return Budget::find($response->json('model.id'));
    }

    /**
     * Afirma precio y base de los cuatro renglones de un presupuesto.
     *
     * @param  int    $budget_id
     * @param  array  $esperado  ['article' => [price, base], 'service' => ..., 'combo' => ..., 'promo' => ...]
     * @param  string $que
     * @return void
     */
    protected function assert_renglones_presupuesto($budget_id, $esperado, $que)
    {
        $this->assert_precio_y_base($this->fila('article_budget', 'budget_id', $budget_id, 'article_id', $this->articulo->id), $esperado['article'][0], $esperado['article'][1], $que.' / articulo');
        $this->assert_precio_y_base($this->fila('budget_service', 'budget_id', $budget_id, 'service_id', $this->servicio->id), $esperado['service'][0], $esperado['service'][1], $que.' / servicio');
        $this->assert_precio_y_base($this->fila('budget_combo', 'budget_id', $budget_id, 'combo_id', $this->combo_modelo->id), $esperado['combo'][0], $esperado['combo'][1], $que.' / combo');
        $this->assert_precio_y_base($this->fila('budget_promocion_vinoteca', 'budget_id', $budget_id, 'promocion_vinoteca_id', $this->promo->id), $esperado['promo'][0], $esperado['promo'][1], $que.' / promocion');
    }

    /**
     * Test 1 — el alta guarda la base en los cuatro tipos de renglon, y `getTotal()` no vuelve a
     * sumar el recargo (el alta valida el total contra `getTotal()` con margen de 3: si lo sumara,
     * el POST ya hubiera respondido 500).
     *
     * @test
     */
    public function el_alta_guarda_la_base_de_los_cuatro_tipos_de_renglon()
    {
        $budget = $this->presupuesto_prendido();

        $this->assert_renglones_presupuesto($budget->id, [
            'article' => [110, 100],
            'service' => [55, 50],
            'combo'   => [220, 200],
            'promo'   => [330, 300],
        ], 'alta');

        $this->assertEqualsWithDelta(715.00, BudgetHelper::getTotal(Budget::withAll()->find($budget->id)), self::DELTA);
    }

    /**
     * Test 2 — el update de VENDER (con la clave) apaga la opcion: precios a la base, base en NULL,
     * y el mismo total con el recargo al pie.
     *
     * @test
     */
    public function el_update_de_vender_apaga_la_opcion_y_deja_la_base_en_null()
    {
        $budget = $this->presupuesto_prendido();

        /* Apagada: 650 de renglones mas el 10 % al pie = 715. */
        $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto(715.00, 0, [$this->recargo_del_payload($this->recargo_modelo)], [
            'articles'            => [$this->renglon_articulo_presupuesto($this->articulo->id, 100, null, true)],
            'services'            => [$this->renglon_pivot_presupuesto($this->servicio->id, 50, null)],
            'combos'              => [$this->renglon_pivot_presupuesto($this->combo_modelo->id, 200, null)],
            'promocion_vinotecas' => [$this->renglon_pivot_presupuesto($this->promo->id, 300, null)],
        ]))->assertStatus(200);

        $budget = Budget::withAll()->find($budget->id);

        $this->assertEquals(0, (int) $budget->aplicar_recargos_directo_a_items);

        $this->assert_renglones_presupuesto($budget->id, [
            'article' => [100, null],
            'service' => [50, null],
            'combo'   => [200, null],
            'promo'   => [300, null],
        ], 'update apagado');

        $this->assertEqualsWithDelta(715.00, BudgetHelper::getTotal($budget), self::DELTA, 'Con estos numeros, que dan exacto, apagar no cambia el total: el 10 % pasa al pie.');
    }

    /**
     * Test 3 — y un update de VENDER que vuelve a PRENDERLA guarda la base nueva, con el
     * articulo ya cargado (bajo `pivot`) y uno nuevo (plano) en el mismo PUT.
     *
     * @test
     */
    public function el_update_de_vender_prende_la_opcion_con_un_renglon_viejo_y_uno_nuevo()
    {
        $budget = $this->presupuesto_prendido();

        $otro_servicio = $this->servicio(80);

        /* 110 + 55 + 220 + 330 + 88 = 803. */
        $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto(803.00, 1, [$this->recargo_del_payload($this->recargo_modelo)], [
            'articles'            => [$this->renglon_articulo_presupuesto($this->articulo->id, 110, 100, true)],
            'services'            => [
                $this->renglon_pivot_presupuesto($this->servicio->id, 55, 50),
                $this->renglon_pivot_presupuesto($otro_servicio->id, 88, 80),
            ],
            'combos'              => [$this->renglon_pivot_presupuesto($this->combo_modelo->id, 220, 200)],
            'promocion_vinotecas' => [$this->renglon_pivot_presupuesto($this->promo->id, 330, 300)],
        ]))->assertStatus(200);

        $this->assert_renglones_presupuesto($budget->id, [
            'article' => [110, 100],
            'service' => [55, 50],
            'combo'   => [220, 200],
            'promo'   => [330, 300],
        ], 'update prendido');

        $this->assert_precio_y_base($this->fila('budget_service', 'budget_id', $budget->id, 'service_id', $otro_servicio->id), 88, 80, 'servicio nuevo del update');
    }

    /**
     * Test 4 — CONFIRMAR: la venta que nace hereda el precio Y la base de cada renglon, y su total
     * con `getTotalSale()` es el del presupuesto.
     *
     * @test
     */
    public function confirmar_pasa_precio_y_base_a_la_venta()
    {
        $budget = $this->presupuesto_prendido();

        $this->postJson('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        $this->assertEquals(1, (int) $sale->aplicar_recargos_directo_a_items, 'La venta hereda la opcion.');

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $this->articulo->id), 110, 100, 'venta confirmada / articulo');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $this->servicio->id), 55, 50, 'venta confirmada / servicio');
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $this->combo_modelo->id), 220, 200, 'venta confirmada / combo');
        $this->assert_precio_y_base($this->fila('promocion_vinoteca_sale', 'sale_id', $sale->id, 'promocion_vinoteca_id', $this->promo->id), 330, 300, 'venta confirmada / promocion');

        $this->assertEqualsWithDelta(715.00, SaleHelper::getTotalSale($sale, true, true, false, true), self::DELTA);
    }

    /**
     * Test 5 — DUPLICAR copia la base de cada renglon.
     *
     * @test
     */
    public function duplicar_copia_la_base()
    {
        $extencion = ExtencionEmpresa::where('slug', 'duplicar_presupuestos')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'duplicar_presupuestos',
                'name' => 'Duplicar presupuestos',
            ]);
        }

        $this->comercio()->extencions()->syncWithoutDetaching([$extencion->id]);

        $origen = $this->presupuesto_prendido();

        $response = $this->postJson('api/budget/'.$origen->id.'/duplicate');

        $response->assertStatus(201);

        $duplicado_id = $response->json('model.id');

        $this->assertNotEquals($origen->id, $duplicado_id, 'Duplicar tiene que crear un presupuesto nuevo.');

        $this->assert_renglones_presupuesto($duplicado_id, [
            'article' => [110, 100],
            'service' => [55, 50],
            'combo'   => [220, 200],
            'promo'   => [330, 300],
        ], 'duplicado');
    }
}
