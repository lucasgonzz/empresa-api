<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Models\Budget;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 4 — un PUT de presupuesto SIN la clave (el form generico del modulo Presupuestos) no
 * borra la base de descuentos: la preserva mientras el precio del renglon no cambie.
 *
 * `BudgetController::update()` lo pegan dos frentes: VENDER y el form generico, que re-adjunta los
 * renglones mandando el `pivot` tal cual lo recibio y no sabe nada de descuentos. Sin preservar,
 * guardar desde ahi —cambiando una observacion— dejaria todas las bases en NULL y el presupuesto
 * sin su precio original. La regla (`BudgetHelper::bases_para_renglon()`), por cada base y por
 * separado:
 *
 *  - clave ausente + precio IGUAL al guardado  -> se preserva la base guardada;
 *  - clave ausente + precio DISTINTO           -> NULL (la base vieja ya no describe ese precio);
 *  - clave presente (aunque sea null)          -> lo que vino.
 *
 * ⚠️ El form generico re-manda el pivot que recibio de la API, y ahi la base se llama
 * `price_sin_descuentos_de_venta` —el nombre de la COLUMNA—, no `price_vender_sin_descuentos`. Por
 * eso el payload de estos tests la incluye (con un valor que NO es la base guardada): es
 * exactamente lo que hace el form, y prueba que ese nombre NO se toma como "la clave vino".
 *
 * @group descuentos_en_precios
 */
class Presupuesto_form_generico_preserva_la_base_Test extends DescuentosEnPreciosTestCase
{
    /** @var \App\Models\Article */
    protected $articulo;

    /** @var \App\Models\Service */
    protected $servicio;

    /** @var \App\Models\Combo */
    protected $combo_modelo;

    /** @var \App\Models\PromocionVinoteca */
    protected $promo;

    /** @var \App\Models\Discount */
    protected $descuento_modelo;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->articulo         = $this->articulo_centinela();
        $this->servicio         = $this->servicio(50);
        $this->combo_modelo     = $this->combo(200);
        $this->promo            = $this->promocion(300);
        $this->descuento_modelo = $this->descuento();
    }

    /**
     * Presupuesto prendido con los cuatro tipos de renglon, creado por el endpoint.
     *
     * @return \App\Models\Budget
     */
    protected function presupuesto_prendido()
    {
        $response = $this->postJson('api/budget', $this->payload_presupuesto(585.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles'            => [$this->renglon_articulo_presupuesto($this->articulo->id, 90, 100, false)],
            'services'            => [$this->renglon_pivot_presupuesto($this->servicio->id, 45, 50)],
            'combos'              => [$this->renglon_pivot_presupuesto($this->combo_modelo->id, 180, 200)],
            'promocion_vinotecas' => [$this->renglon_pivot_presupuesto($this->promo->id, 270, 300)],
        ]));

        $response->assertStatus(201);

        return Budget::find($response->json('model.id'));
    }

    /**
     * Un renglon como lo re-manda el form generico: el pivot recibido, con el precio que tenga, las
     * bases bajo el nombre de la COLUMNA y sin `price_vender_sin_descuentos` ni
     * `price_vender_sin_recargos`.
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
                'amount'                       => 1,
                'bonus'                        => null,
                'location'                     => null,
                'price'                        => number_format($price, 2, '.', ''),
                'price_sin_descuentos_de_venta' => '888.000000',
                'price_sin_recargos_de_venta'  => '999.000000',
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

        $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto(585.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles'            => [$this->renglon_del_form($this->articulo->id, 90, true)],
            'services'            => [$this->renglon_del_form($this->servicio->id, 45, false)],
            'combos'              => [$this->renglon_del_form($this->combo_modelo->id, 180, false)],
            'promocion_vinotecas' => [$this->renglon_del_form($this->promo->id, 270, false)],
        ], ['observations' => 'guardado desde el form generico']))->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_budget', 'budget_id', $budget->id, 'article_id', $this->articulo->id), 90, 100, 'articulo, mismo precio');
        $this->assert_precio_y_base($this->fila('budget_service', 'budget_id', $budget->id, 'service_id', $this->servicio->id), 45, 50, 'servicio, mismo precio');
        $this->assert_precio_y_base($this->fila('budget_combo', 'budget_id', $budget->id, 'combo_id', $this->combo_modelo->id), 180, 200, 'combo, mismo precio');
        $this->assert_precio_y_base($this->fila('budget_promocion_vinoteca', 'budget_id', $budget->id, 'promocion_vinoteca_id', $this->promo->id), 270, 300, 'promocion, mismo precio');
    }

    /**
     * Test 2 — precio cambiado, sin la clave: NULL. La base vieja ya no describe ese precio, y
     * dejarla haria que VENDER parta de un precio original que el usuario nunca escribio.
     *
     * @test
     */
    public function con_el_precio_cambiado_y_sin_la_clave_la_base_queda_en_null()
    {
        $budget = $this->presupuesto_prendido();

        $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto(700.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
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
     * VENDER diciendo "este precio no tiene el descuento adentro", no alguien que no sabe.
     *
     * @test
     */
    public function con_la_clave_en_null_no_preserva_aunque_el_precio_sea_el_mismo()
    {
        $budget = $this->presupuesto_prendido();

        $this->putJson('api/budget/'.$budget->id, $this->payload_presupuesto(585.00, 0, [], [
            'articles'            => [$this->renglon_articulo_presupuesto($this->articulo->id, 90, null, true)],
            'services'            => [$this->renglon_pivot_presupuesto($this->servicio->id, 45, null)],
            'combos'              => [$this->renglon_pivot_presupuesto($this->combo_modelo->id, 180, null)],
            'promocion_vinotecas' => [$this->renglon_pivot_presupuesto($this->promo->id, 270, null)],
        ]))->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_budget', 'budget_id', $budget->id, 'article_id', $this->articulo->id), 90, null, 'articulo, clave en null');
        $this->assert_precio_y_base($this->fila('budget_service', 'budget_id', $budget->id, 'service_id', $this->servicio->id), 45, null, 'servicio, clave en null');
        $this->assert_precio_y_base($this->fila('budget_combo', 'budget_id', $budget->id, 'combo_id', $this->combo_modelo->id), 180, null, 'combo, clave en null');
        $this->assert_precio_y_base($this->fila('budget_promocion_vinoteca', 'budget_id', $budget->id, 'promocion_vinoteca_id', $this->promo->id), 270, null, 'promocion, clave en null');
    }

    /**
     * Test 4 — el mismo articulo DOS veces con precios distintos: cada renglon recupera SU base, no
     * la del otro. Es por lo que el snapshot es una lista por id y no un valor.
     *
     * @test
     */
    public function el_mismo_articulo_dos_veces_recupera_cada_uno_su_base()
    {
        /* Dos renglones del mismo articulo: 90 (original 100) y 81 (original 90). */
        $response = $this->postJson('api/budget', $this->payload_presupuesto(171.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [
                $this->renglon_articulo_presupuesto($this->articulo->id, 90, 100, false),
                $this->renglon_articulo_presupuesto($this->articulo->id, 81, 90, false),
            ],
        ]));

        $response->assertStatus(201);

        $budget_id = $response->json('model.id');

        /* El form los re-manda en el orden inverso, sin la clave. */
        $this->putJson('api/budget/'.$budget_id, $this->payload_presupuesto(171.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [
                $this->renglon_del_form($this->articulo->id, 81, true),
                $this->renglon_del_form($this->articulo->id, 90, true),
            ],
        ]))->assertStatus(200);

        $filas = DB::table('article_budget')
                    ->where('budget_id', $budget_id)
                    ->where('article_id', $this->articulo->id)
                    ->orderBy('price')
                    ->get();

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 81, 90, 'renglon de 81');
        $this->assert_precio_y_base($filas[1], 90, 100, 'renglon de 90');
    }

    /**
     * Test 5 — LAS DOS BASES SE RESUELVEN POR SEPARADO. Una SPA de la mision de recargos manda
     * `price_vender_sin_recargos` y NO `price_vender_sin_descuentos`: la de recargos sale de lo que
     * mando (aunque sea null) y la de descuentos se PRESERVA si el precio no cambio. Y al reves: una
     * que manda solo la de descuentos conserva la de recargos.
     *
     * El presupuesto de partida tiene descuento Y recargo adentro: original 100, sin recargos 90,
     * final 99.
     *
     * @test
     */
    public function cada_base_se_resuelve_por_su_propia_clave()
    {
        $recargo = $this->recargo();

        $partida = $this->postJson('api/budget', $this->payload_presupuesto(99.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [$this->renglon_articulo_presupuesto($this->articulo->id, 99, 100, false, 1, 90)],
        ], [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => [$this->recargo_del_payload($recargo)],
        ]));

        $partida->assertStatus(201);

        $budget_id = $partida->json('model.id');

        $this->assert_precio_y_base(
            DB::table('article_budget')->where('budget_id', $budget_id)->first(),
            99, 100, 'partida con descuento y recargo adentro', 90
        );

        /* Solo la clave de recargos (en null): los recargos se apagan, la base de descuentos se preserva. */
        $payload = $this->payload_presupuesto(99.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [$this->renglon_articulo_presupuesto($this->articulo->id, 99, false, true, 1, null)],
        ], [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => [$this->recargo_del_payload($recargo)],
        ]);

        $this->putJson('api/budget/'.$budget_id, $payload)->assertStatus(200);

        $this->assert_precio_y_base(
            DB::table('article_budget')->where('budget_id', $budget_id)->first(),
            99, 100, 'solo la clave de recargos', null
        );

        /* Solo la clave de descuentos (null): la de recargos, que ya quedo en NULL, sigue en NULL; la de descuentos sale de lo que vino. */
        $payload = $this->payload_presupuesto(99.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [$this->renglon_articulo_presupuesto($this->articulo->id, 99, null, true)],
        ], [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => [$this->recargo_del_payload($recargo)],
        ]);

        $this->putJson('api/budget/'.$budget_id, $payload)->assertStatus(200);

        $this->assert_precio_y_base(
            DB::table('article_budget')->where('budget_id', $budget_id)->first(),
            99, null, 'solo la clave de descuentos, en null', null
        );
    }
}
