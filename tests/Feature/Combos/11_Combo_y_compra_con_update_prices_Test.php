<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Models\Combo;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Compras\ComprasTestCase;

/**
 * Una compra con "actualizar precios" (`update_prices = 1`, `NewProviderOrderHelper`) que cambia el
 * costo de un artículo recalcula los combos calculados que lo incluyen (misión combos-calculados,
 * F7). Es uno de los caminos de la guía "cada vez que cambia el precio o el costo de un artículo":
 * la compra escribe el costo y el precio por `setFinalPrice`, y de ahí sale el disparador.
 *
 * Sobre el fixture de compras (artículo "Pinza"), con el molde de `Compras\Costeo_RRII_Test`.
 *
 * @group combos-calculados
 */
class Combo_y_compra_con_update_prices_Test extends ComprasTestCase
{
    /**
     * @test
     */
    public function una_compra_que_actualiza_precios_recalcula_el_combo_que_incluye_al_articulo()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');

        $combo = Combo::create([
            'num'                      => 971001,
            'name'                     => 'zz Combo de la compra ' . uniqid(),
            'user_id'                  => $pinza->user_id,
            'calcular_desde_articulos' => 1,
        ]);

        $combo->articles()->attach($pinza->id, ['amount' => 2]);

        ComboCalculadoHelper::guardar($combo);

        $costo_antes  = (float) DB::table('combos')->where('id', $combo->id)->value('cost');
        $precio_antes = (float) DB::table('combos')->where('id', $combo->id)->value('price');

        // Compra a un costo que NO es el que tiene la pinza hoy.
        $costo_nuevo = 7777;

        $this->postJson('api/provider-order', $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles'             => [$this->item('Pinza', $costo_nuevo, 10)],
        ]))->assertStatus(201);

        $fila = DB::table('articles')->where('id', $pinza->id)->first();

        $this->assertEqualsWithDelta($costo_nuevo, (float) $fila->cost, 0.01, 'Precondición: la compra actualizó el costo de la pinza.');

        $costo_del_articulo = !is_null($fila->costo_real) ? (float) $fila->costo_real : (float) $fila->cost;

        $combo_fila = DB::table('combos')->where('id', $combo->id)->first();

        $this->assertNotEquals($costo_antes, (float) $combo_fila->cost, 'El costo del combo se movió con la compra.');
        $this->assertEqualsWithDelta(round($costo_del_articulo * 2, 2), (float) $combo_fila->cost, 0.01, 'El combo dice 2 x el costo de la pinza.');
        $this->assertEqualsWithDelta(round((float) $fila->final_price * 2, 2), (float) $combo_fila->price, 0.01, 'Y 2 x su precio.');
        $this->assertNotEquals($precio_antes, (float) $combo_fila->price, 'El precio del combo también se movió.');
    }
}
