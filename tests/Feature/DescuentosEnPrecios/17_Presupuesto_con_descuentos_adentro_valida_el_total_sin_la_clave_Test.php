<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\sale\DescuentosEnPreciosEsquemaHelper;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 17 — un PUT de PRESUPUESTO sin la clave `aplicar_descuentos_directo_a_items` sobre un
 * presupuesto con los descuentos ADENTRO de los precios valida el total contra sus renglones
 * (`BudgetController::update()` -> `DescuentosEnPreciosEsquemaHelper::requiere_validar_el_total_del_presupuesto()`).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL HUECO Y POR QUE NO ES UN 409
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `store()` valida el total contra `BudgetHelper::getTotal()` (margen de 3); `update()` no. Una SPA
 *  anterior a la opcion reabre un presupuesto con el flag en 1 y lo guarda con el descuento restado
 *  DOS veces en el total: sin esta validacion el presupuesto queda mal y, al confirmarlo, la venta
 *  hereda ese total.
 *
 *  Pero el form generico del modulo Presupuestos tambien manda el PUT sin la clave, y es legitimo
 *  (re-manda el modelo tal cual, con el `total` en solo lectura). Por eso no se corta por la
 *  ausencia de la clave, y la validacion solo corre cuando el `total` del request SE MUEVE respecto
 *  del guardado: un total que no cambio no puede haber sido doble-descontado.
 *
 *  Lo que se fija:
 *
 *   (a) SPA vieja (sin la clave, total doble-descontado) -> rechazada con el mensaje del alta y NO
 *       queda escrito nada (total, renglones, bases, flag);
 *   (b) form generico, mismo total y mismos precios        -> 200, bases preservadas;
 *   (c) form generico, con un precio cambiado y el total viejo -> 200: pasa como pasa hoy con todos
 *       los presupuestos (el form no recalcula el total). Rechazarlo bloquearia la unica edicion que
 *       ese form ofrece y el usuario no la podria arreglar desde ahi;
 *   (d) con la clave presente, o con el flag en 0 o NULL    -> el comportamiento de siempre;
 *   (e) un total que se mueve DENTRO del margen de 3        -> 200, como en el alta;
 *   (f) la columna del flag todavia no existe               -> la validacion no corre.
 *
 * @group descuentos_en_precios
 */
class Presupuesto_con_descuentos_adentro_valida_el_total_sin_la_clave_Test extends DescuentosEnPreciosTestCase
{
    /** @var \App\Models\Article */
    protected $articulo;

    /** @var \App\Models\Service */
    protected $servicio;

    /** @var \App\Models\Discount */
    protected $descuento_modelo;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_flag_si_quedo_escondido();

        $this->articulo         = $this->articulo_centinela();
        $this->servicio         = $this->servicio(50);
        $this->descuento_modelo = $this->descuento();
    }

    /**
     * Crea por el endpoint un presupuesto de un articulo y un servicio con el 10 % de descuento en su
     * lista. Con el flag en 1 los renglones van descontados (90 y 45; originales 100 y 50); con 0 van
     * a precio de lista y el descuento queda al pie. En los dos casos el total es 135.
     *
     * @param  int  $flag
     * @return \App\Models\Budget
     */
    protected function presupuesto($flag)
    {
        $articulo = $flag ? $this->renglon_articulo_presupuesto($this->articulo->id, 90, 100, false) : $this->renglon_articulo_presupuesto($this->articulo->id, 100, false, false);
        $servicio = $flag ? $this->renglon_pivot_presupuesto($this->servicio->id, 45, 50) : $this->renglon_pivot_presupuesto($this->servicio->id, 50, false);

        $response = $this->postJson('api/budget', $this->payload_presupuesto(135.00, $flag, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [$articulo],
            'services' => [$servicio],
        ]));

        $response->assertStatus(201);

        return Budget::find($response->json('model.id'));
    }

    /**
     * El PUT de una SPA anterior o del form generico: la clave del flag NO viaja (ni las de las
     * bases). Los precios y el total son los que se pidan.
     *
     * @param  float  $precio_articulo
     * @param  float  $precio_servicio
     * @param  float  $total
     * @return array
     */
    protected function put_sin_la_clave($precio_articulo, $precio_servicio, $total)
    {
        $payload = $this->payload_presupuesto($total, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [$this->renglon_articulo_presupuesto($this->articulo->id, $precio_articulo, false, true)],
            'services' => [$this->renglon_pivot_presupuesto($this->servicio->id, $precio_servicio, false)],
        ]);

        unset($payload['aplicar_descuentos_directo_a_items']);

        return $payload;
    }

    /**
     * Lo que importa del presupuesto, leido DIRECTO de la base: total, flag y los dos renglones con
     * sus bases.
     *
     * @param  \App\Models\Budget  $budget
     * @return array
     */
    protected function foto($budget)
    {
        $articulo = DB::table('article_budget')->where('budget_id', $budget->id)->first();
        $servicio = DB::table('budget_service')->where('budget_id', $budget->id)->first();

        return [
            'total'    => (float) DB::table('budgets')->where('id', $budget->id)->value('total'),
            'flag'     => DB::table('budgets')->where('id', $budget->id)->value(DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG),
            'articulo' => [(float) $articulo->price, $articulo->price_sin_descuentos_de_venta],
            'servicio' => [(float) $servicio->price, $servicio->price_sin_descuentos_de_venta],
        ];
    }

    /**
     * Test (a) — la SPA vieja reabre el presupuesto prendido y lo guarda: su pie vuelve a restar el
     * descuento y manda 121,50 (135 × 0,9). Se rechaza con el mensaje del alta y NO queda escrito
     * nada: ni el total, ni los renglones con sus bases, ni el flag.
     *
     * @test
     */
    public function la_spa_vieja_con_el_total_doble_descontado_se_rechaza_y_no_queda_escrito_nada()
    {
        $budget = $this->presupuesto(1);

        $antes = $this->foto($budget);

        $this->assertSame([90.0, '100.000000'], $antes['articulo'], 'Control: el presupuesto nace descontado con su base.');

        $response = $this->putJson('api/budget/'.$budget->id, $this->put_sin_la_clave(90, 45, 121.50));

        $response->assertStatus(500);

        $this->assertStringContainsString('El total del presupuesto no corresponde con los productos ingresados', (string) $response->json('message'));

        $this->assertSame($antes, $this->foto($budget), 'El rechazo revierte la transaccion entera: ni el total doble-descontado ni los renglones sin base.');
    }

    /**
     * Test (b) — el form generico, sin cambiar nada: re-manda el pivot (con la base bajo el nombre de
     * la COLUMNA) y el mismo total. Pasa con 200 y las bases se preservan.
     *
     * @test
     */
    public function el_form_generico_sin_cambiar_precios_pasa_y_preserva_las_bases()
    {
        $budget = $this->presupuesto(1);

        $this->putJson('api/budget/'.$budget->id, $this->put_sin_la_clave(90, 45, 135.00))->assertStatus(200);

        $despues = $this->foto($budget);

        $this->assertSame([90.0, '100.000000'], $despues['articulo']);
        $this->assertSame([45.0, '50.000000'], $despues['servicio']);
        $this->assertSame(1, (int) $despues['flag']);
        $this->assertEqualsWithDelta(135.00, $despues['total'], self::DELTA);

        $this->assertEqualsWithDelta(135.00, BudgetHelper::getTotal(Budget::withAll()->find($budget->id)), self::DELTA, 'El presupuesto sigue cerrando.');
    }

    /**
     * Test (c) — el form generico CON un precio cambiado (el articulo pasa de 90 a 120) y el total
     * viejo: PASA. El total del form es de solo lectura y no se recalcula al editar un renglon, asi
     * que hoy cualquier presupuesto queda con el total viejo; rechazarlo aca bloquearia la unica
     * edicion que ese form ofrece sin que el usuario pueda arreglarlo desde ahi. El renglon cambiado
     * pierde su base (la base vieja ya no describe ese precio) y el flag se preserva.
     *
     * @test
     */
    public function el_form_generico_con_un_precio_cambiado_y_el_total_viejo_pasa_como_pasa_hoy()
    {
        $budget = $this->presupuesto(1);

        $this->putJson('api/budget/'.$budget->id, $this->put_sin_la_clave(120, 45, 135.00))->assertStatus(200);

        $despues = $this->foto($budget);

        $this->assertSame([120.0, null], $despues['articulo'], 'El precio cambiado pierde su base: ya no la describe.');
        $this->assertSame([45.0, '50.000000'], $despues['servicio'], 'El renglon que no se toco conserva la suya.');
        $this->assertSame(1, (int) $despues['flag']);
        $this->assertEqualsWithDelta(135.00, $despues['total'], self::DELTA, 'El total queda el que el form re-mando: igual que con cualquier presupuesto de hoy.');
    }

    /**
     * Test (d) — con la clave presente, o con el flag en 0 o NULL, la API se comporta como siempre:
     * no se agrega ninguna validacion. (Un total que se mueve sin que ningun renglon cambie pasaba
     * antes y pasa ahora.)
     *
     * @test
     */
    public function con_la_clave_presente_o_con_el_flag_apagado_no_se_agrega_ninguna_validacion()
    {
        /* Clave presente en un presupuesto prendido: el update de VENDER nuevo, con su total. */
        $con_clave = $this->presupuesto(1);

        $this->putJson('api/budget/'.$con_clave->id, $this->payload_presupuesto(200.00, 1, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [$this->renglon_articulo_presupuesto($this->articulo->id, 90, 100, true)],
            'services' => [$this->renglon_pivot_presupuesto($this->servicio->id, 45, 50)],
        ]))->assertStatus(200);

        $this->assertEqualsWithDelta(200.00, $this->foto($con_clave)['total'], self::DELTA, 'Con la clave presente no se valida: el comportamiento de siempre.');

        /* Flag en 0, sin la clave y con el total movido: como siempre. */
        $en_cero = $this->presupuesto(0);

        $payload = $this->payload_presupuesto(121.50, 0, [$this->descuento_del_payload($this->descuento_modelo)], [
            'articles' => [$this->renglon_articulo_presupuesto($this->articulo->id, 100, false, true)],
            'services' => [$this->renglon_pivot_presupuesto($this->servicio->id, 50, false)],
        ]);

        unset($payload['aplicar_descuentos_directo_a_items']);

        $this->putJson('api/budget/'.$en_cero->id, $payload)->assertStatus(200);

        $this->assertEqualsWithDelta(121.50, $this->foto($en_cero)['total'], self::DELTA);
    }

    /**
     * Test (e) — un total que se mueve DENTRO del margen de 3 pasa, igual que en el alta (el mismo
     * `(int)` y el mismo `> 3`).
     *
     * @test
     */
    public function un_total_que_se_mueve_dentro_del_margen_pasa_como_en_el_alta()
    {
        $budget = $this->presupuesto(1);

        $this->putJson('api/budget/'.$budget->id, $this->put_sin_la_clave(90, 45, 133.00))->assertStatus(200);

        $this->assertEqualsWithDelta(133.00, $this->foto($budget)['total'], self::DELTA);
    }

    /**
     * Test (f) — con la columna del flag todavia sin crear (ventana del deploy) la validacion no
     * corre: ningun presupuesto puede tener el flag en 1, aunque el modelo en memoria diga otra cosa.
     *
     * @test
     */
    public function sin_la_columna_la_validacion_no_corre()
    {
        $self = $this;

        $this->con_el_flag_escondido(function () use ($self) {

            $articulo = $self->articulo_centinela();
            $servicio = $self->servicio(50);
            $descuento = $self->descuento();

            $response = $self->postJson('api/budget', $self->payload_presupuesto(135.00, 0, [$self->descuento_del_payload($descuento)], [
                'articles' => [$self->renglon_articulo_presupuesto($articulo->id, 100, false, false)],
                'services' => [$self->renglon_pivot_presupuesto($servicio->id, 50, false)],
            ]));

            $response->assertStatus(201);

            $budget = Budget::find($response->json('model.id'));

            /* El helper mira la guarda ANTES que el modelo: ni con un atributo en 1 en memoria valida. */
            $budget->aplicar_descuentos_directo_a_items = 1;

            $self->assertFalse(
                DescuentosEnPreciosEsquemaHelper::requiere_validar_el_total_del_presupuesto($budget, Request::create('api/budget/'.$budget->id, 'PUT', ['total' => 1])),
                'Sin la columna no hay nada que proteger.'
            );

            $payload = $self->payload_presupuesto(50.00, 0, [$self->descuento_del_payload($descuento)], [
                'articles' => [$self->renglon_articulo_presupuesto($articulo->id, 100, false, true)],
                'services' => [$self->renglon_pivot_presupuesto($servicio->id, 50, false)],
            ]);

            unset($payload['aplicar_descuentos_directo_a_items']);

            $self->putJson('api/budget/'.$budget->id, $payload)->assertStatus(200);
        });
    }
}
