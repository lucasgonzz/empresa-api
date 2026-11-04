<?php

namespace Tests\Feature\ListasSiempre;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * D2 de la misión prueba-listas-siempre: lo que la persona edita en la tarjeta de la lista «Precio
 * final» (con dos o más listas la tarjeta se dibuja) se escribe en el ARTÍCULO —margen de ganancia y
 * precio manual—, y lo que edita en el recuadro de siempre del artículo (una sola lista: la tarjeta
 * no se dibuja) no lo pisa el espejo viejo que la ficha manda igual en `price_types`.
 *
 * Por el endpoint real de la ficha: `PUT api/article/{id}`, con el payload que manda el modal.
 */
class Write_through_del_pivote_Test extends ListasSiempreTestCase
{
    /**
     * El payload de la ficha: las columnas reales del artículo más las listas con su pivote.
     *
     * @param  \App\Models\Article  $articulo
     * @param  array                $overrides
     * @param  array                $pivotes    [price_type_id => [columna => valor]] para cambiar.
     * @return array
     */
    protected function payload($articulo, array $overrides = array(), array $pivotes = array())
    {
        $articulo = Article::find($articulo->id);

        $price_types = array();

        foreach ($articulo->price_types as $lista) {

            $pivot = array(
                'percentage'          => $lista->pivot->percentage,
                'final_price'         => $lista->pivot->final_price,
                'setear_precio_final' => $lista->pivot->setear_precio_final,
                'incluir_en_excel_para_clientes' => $lista->pivot->incluir_en_excel_para_clientes,
            );

            if (isset($pivotes[$lista->id])) {
                $pivot = array_merge($pivot, $pivotes[$lista->id]);
            }

            $price_types[] = array('id' => $lista->id, 'pivot' => $pivot);
        }

        return array_merge($articulo->getAttributes(), array(
            'id'                 => $articulo->id,
            'aplicar_iva'        => $articulo->aplicar_iva,
            'cost_incluye_iva'   => 0,
            'price_types'        => $price_types,
            'price_type_monedas' => array(),
            'tags'               => array(),
        ), $overrides);
    }

    /** @test */
    public function el_margen_de_la_tarjeta_precio_final_escribe_el_margen_del_articulo()
    {
        $dueno = $this->dueno_nuevo('write-through');
        list($dueno, $precio_final) = $this->con_precio_final($dueno);
        $this->otra_lista($dueno);

        $articulo = $this->articulo($dueno, 'editado por la tarjeta', array('cost' => 1000, 'percentage_gain' => 50));
        $this->recalcular($articulo, $dueno);
        $antes = $this->final_price_de($articulo);

        $this->actingAs($dueno, 'web');

        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, array(), array(
            $precio_final->id => array('percentage' => 70),
        )))->assertStatus(200);

        $this->assertEqualsWithDelta(70, (float) DB::table('articles')->where('id', $articulo->id)->value('percentage_gain'), 0.001, 'El margen de la tarjeta «Precio final» es el margen del artículo.');
        $this->assertGreaterThan($antes, $this->final_price_de($articulo), 'Y el precio final se recalculó con el margen nuevo.');
        $this->assertMismoPrecio($this->final_price_de($articulo), (float) $this->pivote($articulo, $precio_final)->final_price);
        $this->assertEqualsWithDelta(70, (float) $this->pivote($articulo, $precio_final)->percentage, 0.001);
    }

    /** @test */
    public function setear_precio_final_en_la_tarjeta_escribe_el_precio_manual_y_destildar_lo_saca()
    {
        $dueno = $this->dueno_nuevo('manual-por-tarjeta');
        list($dueno, $precio_final) = $this->con_precio_final($dueno);
        $this->otra_lista($dueno);

        $articulo = $this->articulo($dueno, 'manual por la tarjeta', array('cost' => 1000, 'percentage_gain' => 50));
        $this->recalcular($articulo, $dueno);
        $calculado = $this->final_price_de($articulo);

        $this->actingAs($dueno, 'web');

        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, array(), array(
            $precio_final->id => array('setear_precio_final' => 1, 'final_price' => 999),
        )))->assertStatus(200);

        $this->assertEqualsWithDelta(999, (float) DB::table('articles')->where('id', $articulo->id)->value('price'), self::DELTA, 'El precio fijado a mano en la tarjeta es el precio manual del artículo.');
        $this->assertNull(DB::table('articles')->where('id', $articulo->id)->value('percentage_gain'), 'Un precio manual no convive con un margen del artículo (regla de siempre del recuadro): se vacía.');
        $this->assertEqualsWithDelta(999, $this->final_price_de($articulo), self::DELTA);
        $this->assertSame(1, (int) $this->pivote($articulo, $precio_final)->setear_precio_final);

        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, array(), array(
            $precio_final->id => array('setear_precio_final' => 0),
        )))->assertStatus(200);

        $this->assertNull(DB::table('articles')->where('id', $articulo->id)->value('price'), 'Destildar saca el precio manual.');
        $sin_margen = $this->final_price_de($articulo);
        $this->assertNotNull($sin_margen);
        $this->assertLessThan($calculado, $sin_margen, 'Vuelve el precio calculado, ahora sin margen propio (hasta que cargue uno).');
        $this->assertMismoPrecio($sin_margen, (float) $this->pivote($articulo, $precio_final)->final_price);
        $this->assertSame(0, (int) $this->pivote($articulo, $precio_final)->setear_precio_final);
    }

    /**
     * Una sola lista: la ficha manda el pivote tal cual lo recibió (el espejo viejo) y el margen nuevo
     * en el recuadro del artículo. Gana el recuadro.
     *
     * @test
     */
    public function con_una_sola_lista_el_recuadro_del_articulo_manda_y_el_espejo_viejo_no_lo_pisa()
    {
        $dueno = $this->dueno_nuevo('una-lista');
        list($dueno, $precio_final) = $this->con_precio_final($dueno);

        $articulo = $this->articulo($dueno, 'editado por el recuadro', array('cost' => 1000, 'percentage_gain' => 50));
        $this->recalcular($articulo, $dueno);

        $this->assertEqualsWithDelta(50, (float) $this->pivote($articulo, $precio_final)->percentage, 0.001);

        $this->actingAs($dueno, 'web');

        $this->putJson('api/article/'.$articulo->id, $this->payload($articulo, array('percentage_gain' => 80)))->assertStatus(200);

        $this->assertEqualsWithDelta(80, (float) DB::table('articles')->where('id', $articulo->id)->value('percentage_gain'), 0.001, 'El margen del recuadro no lo pisa el pivote viejo que viajó en el request.');
        $this->assertEqualsWithDelta(80, (float) $this->pivote($articulo, $precio_final)->percentage, 0.001, 'Y el espejo lo sigue.');
    }
}
