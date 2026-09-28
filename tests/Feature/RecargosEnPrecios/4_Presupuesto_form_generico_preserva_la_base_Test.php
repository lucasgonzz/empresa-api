<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Models\Budget;

/**
 * Archivo 4 — un PUT de presupuesto SIN la clave (el form generico del modulo Presupuestos) no
 * borra la base: la preserva mientras el precio del renglon no cambie.
 *
 * `BudgetController::update()` lo pegan dos frentes: VENDER y el form generico, que re-adjunta los
 * renglones mandando el `pivot` tal cual lo recibio y no sabe nada de recargos. Sin preservar,
 * guardar desde ahi —cambiando una observacion— dejaria todas las bases en NULL y el presupuesto
 * bloqueado en VENDER. La regla (`BudgetHelper::base_para_renglon()`):
 *
 *  - clave ausente + precio IGUAL al guardado  -> se preserva la base guardada;
 *  - clave ausente + precio DISTINTO           -> NULL (la base vieja ya no describe ese precio);
 *  - clave presente (aunque sea null)          -> lo que vino.
 *
 * ⚠️ El form generico re-manda el pivot que recibio de la API, y ahi la base se llama
 * `price_sin_recargos_de_venta` —el nombre de la COLUMNA—, no `price_vender_sin_recargos`. Por eso
 * el payload de estos tests la incluye: es exactamente lo que hace el form, y prueba que ese nombre
 * NO se toma como "la clave vino".
 *
 * @group recargos_en_precios
 */
class Presupuesto_form_generico_preserva_la_base_Test extends RecargosEnPreciosTestCase
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
     * Presupuesto prendido con los cuatro tipos de renglon, creado por el endpoint.
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
     * Un renglon como lo re-manda el form generico: el pivot recibido, con el precio que tenga,
     * la base bajo el nombre de la COLUMNA y sin `price_vender_sin_recargos`.
     *
     * @param  int    $id
     * @param  float  $price
     * @param  bool   $con_status  Los articulos necesitan `status`; el resto no.
     * @return array
     */
    protected function renglon_del_form($id, $price, $con_status)
    {
        $renglon = [
            'id'    => $id,
            'pivot' => [
                'amount'                      => 1,
                'bonus'                       => null,
                'location'                    => null,
                'price'                       => number_format($price, 2, '.', ''),
                'price_sin_recargos_de_venta' => '999.000000',
            ],
        ];

        if ($con_status) {
            $renglon['status'] = 'active';
        }

        return $renglon;
    }

    /**
     * Test 1 — mismo precio, sin la clave: la base se preserva en los cuatro tipos de renglon.
     *
     * @test
     */
    public function con_el_mismo_precio_y_sin_la_clave_preserva_la_base()
    {
        $budget = $this->presupuesto_prendido();

        $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto(715.00, 1, [$this->recargo_del_payload($this->recargo_modelo)], [
            'articles'            => [$this->renglon_del_form($this->articulo->id, 110, true)],
            'services'            => [$this->renglon_del_form($this->servicio->id, 55, false)],
            'combos'              => [$this->renglon_del_form($this->combo_modelo->id, 220, false)],
            'promocion_vinotecas' => [$this->renglon_del_form($this->promo->id, 330, false)],
        ], ['observations' => 'guardado desde el form generico']))->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_budget', 'budget_id', $budget->id, 'article_id', $this->articulo->id), 110, 100, 'articulo, mismo precio');
        $this->assert_precio_y_base($this->fila('budget_service', 'budget_id', $budget->id, 'service_id', $this->servicio->id), 55, 50, 'servicio, mismo precio');
        $this->assert_precio_y_base($this->fila('budget_combo', 'budget_id', $budget->id, 'combo_id', $this->combo_modelo->id), 220, 200, 'combo, mismo precio');
        $this->assert_precio_y_base($this->fila('budget_promocion_vinoteca', 'budget_id', $budget->id, 'promocion_vinoteca_id', $this->promo->id), 330, 300, 'promocion, mismo precio');
    }

    /**
     * Test 2 — precio cambiado, sin la clave: NULL. La base vieja ya no describe ese precio, y
     * dejarla haria que VENDER recalcule desde un numero que el usuario nunca escribio.
     *
     * @test
     */
    public function con_el_precio_cambiado_y_sin_la_clave_la_base_queda_en_null()
    {
        $budget = $this->presupuesto_prendido();

        $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto(800.00, 1, [$this->recargo_del_payload($this->recargo_modelo)], [
            'articles'            => [$this->renglon_del_form($this->articulo->id, 120, true)],
            'services'            => [$this->renglon_del_form($this->servicio->id, 60, false)],
            'combos'              => [$this->renglon_del_form($this->combo_modelo->id, 240, false)],
            'promocion_vinotecas' => [$this->renglon_del_form($this->promo->id, 380, false)],
        ]))->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_budget', 'budget_id', $budget->id, 'article_id', $this->articulo->id), 120, null, 'articulo, precio cambiado');
        $this->assert_precio_y_base($this->fila('budget_service', 'budget_id', $budget->id, 'service_id', $this->servicio->id), 60, null, 'servicio, precio cambiado');
        $this->assert_precio_y_base($this->fila('budget_combo', 'budget_id', $budget->id, 'combo_id', $this->combo_modelo->id), 240, null, 'combo, precio cambiado');
        $this->assert_precio_y_base($this->fila('budget_promocion_vinoteca', 'budget_id', $budget->id, 'promocion_vinoteca_id', $this->promo->id), 380, null, 'promocion, precio cambiado');
    }

    /**
     * Test 3 — con la clave presente en null, manda lo que vino aunque el precio sea el mismo: es
     * VENDER diciendo "este precio no tiene el recargo adentro", no alguien que no sabe.
     *
     * @test
     */
    public function con_la_clave_en_null_no_preserva_aunque_el_precio_sea_el_mismo()
    {
        $budget = $this->presupuesto_prendido();

        $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto(715.00, 0, [], [
            'articles'            => [$this->renglon_articulo_presupuesto($this->articulo->id, 110, null, true)],
            'services'            => [$this->renglon_pivot_presupuesto($this->servicio->id, 55, null)],
            'combos'              => [$this->renglon_pivot_presupuesto($this->combo_modelo->id, 220, null)],
            'promocion_vinotecas' => [$this->renglon_pivot_presupuesto($this->promo->id, 330, null)],
        ]))->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_budget', 'budget_id', $budget->id, 'article_id', $this->articulo->id), 110, null, 'articulo, clave en null');
        $this->assert_precio_y_base($this->fila('budget_service', 'budget_id', $budget->id, 'service_id', $this->servicio->id), 55, null, 'servicio, clave en null');
        $this->assert_precio_y_base($this->fila('budget_combo', 'budget_id', $budget->id, 'combo_id', $this->combo_modelo->id), 220, null, 'combo, clave en null');
        $this->assert_precio_y_base($this->fila('budget_promocion_vinoteca', 'budget_id', $budget->id, 'promocion_vinoteca_id', $this->promo->id), 330, null, 'promocion, clave en null');
    }

    /**
     * Test 4 — el mismo articulo DOS veces con precios distintos: cada renglon recupera SU base, no
     * la del otro. Es por lo que el snapshot es una lista por id y no un valor.
     *
     * @test
     */
    public function el_mismo_articulo_dos_veces_recupera_cada_uno_su_base()
    {
        /* Dos renglones del mismo articulo: 110 (base 100) y 99 (base 90). */
        $response = $this->postJson('api/budget', $this->payload_presupuesto(209.00, 1, [$this->recargo_del_payload($this->recargo_modelo)], [
            'articles' => [
                $this->renglon_articulo_presupuesto($this->articulo->id, 110, 100, false),
                $this->renglon_articulo_presupuesto($this->articulo->id, 99, 90, false),
            ],
        ]));

        $response->assertStatus(201);

        $budget_id = $response->json('model.id');

        /* El form los re-manda en el orden inverso, sin la clave. */
        $this->putJson('api/budget/'.$budget_id, $this->payload_presupuesto(209.00, 1, [$this->recargo_del_payload($this->recargo_modelo)], [
            'articles' => [
                $this->renglon_del_form($this->articulo->id, 99, true),
                $this->renglon_del_form($this->articulo->id, 110, true),
            ],
        ]))->assertStatus(200);

        $filas = \Illuminate\Support\Facades\DB::table('article_budget')
                    ->where('budget_id', $budget_id)
                    ->where('article_id', $this->articulo->id)
                    ->orderBy('price')
                    ->get();

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 99, 90, 'renglon de 99');
        $this->assert_precio_y_base($filas[1], 110, 100, 'renglon de 110');
    }
}
