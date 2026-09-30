<?php

namespace Tests\Feature\Combos;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * La guía "cada vez que cambia el precio o el costo de un artículo, se recalculan los combos que lo
 * incluyen", probada por los CAMINOS REALES y no llamando al helper (misión combos-calculados, F7):
 *
 *  - guardar un artículo por `PUT api/article/{id}` (cambia costo y margen);
 *  - borrar un artículo por `DELETE api/article/{id}`;
 *  - una corrida de recálculo de precios por la cola `sync`, hasta su cierre: un cambio de margen del
 *    proveedor y un cambio de la condición fiscal de la cuenta.
 *
 * Nunca se compara contra una fórmula escrita a mano: la aserción es "el combo dice lo mismo que los
 * artículos YA guardados" (la cuenta del artículo no es de este archivo) y, antes, que el artículo
 * efectivamente se movió (si no, el test no mediría nada).
 *
 * La compra con actualización de precios está en `11_Combo_y_compra_con_update_prices_Test`.
 *
 * @group combos-calculados
 */
class Disparadores_por_endpoint_y_corrida_Test extends ComboCalculadoTestCase
{
    /**
     * El body de `PUT api/article/{id}`: las columnas reales del artículo más los arrays de
     * relaciones que `update()` recorre sin validar (ver `Costeo\CostoBrutoEnElAbmTest::payload()`).
     *
     * @param  \App\Models\Article  $article
     * @param  array                $overrides
     * @return array
     */
    protected function payload_de_articulo(Article $article, array $overrides = [])
    {
        return array_merge($article->getAttributes(), [
            'id'                 => $article->id,
            'cost_incluye_iva'   => 0,
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ], $overrides);
    }

    /**
     * Lo que el combo debería decir leyendo los artículos tal como están guardados: costo
     * (`costo_real`, o `cost`) y precio (`final_price`) por la cantidad en el combo.
     *
     * @param  \App\Models\Article  $article
     * @param  int                  $cantidad
     * @return array [costo, precio]
     */
    protected function lo_que_dice_el_articulo($article, $cantidad)
    {
        $fila = DB::table('articles')->where('id', $article->id)->first();

        $costo = !is_null($fila->costo_real) ? (float) $fila->costo_real : (float) $fila->cost;

        return [round($costo * $cantidad, 2), round((float) $fila->final_price * $cantidad, 2)];
    }

    /**
     * 🔴 Guardar el artículo por el endpoint real recalcula el combo que lo incluye.
     *
     * @test
     */
    public function guardar_un_articulo_por_el_endpoint_recalcula_los_combos_que_lo_incluyen()
    {
        $this->con_listas(0);

        $articulo = $this->nuevo_articulo(['cost' => 100, 'costo_real' => 100, 'final_price' => 250, 'percentage_gain' => 100]);

        $combo = $this->combo_calculado([[$articulo, 2]]);
        ComboCalculadoHelper::guardar($combo);

        $antes = [$this->costo_en_base($combo), $this->precio_en_base($combo)];

        $this->putJson('api/article/' . $articulo->id, $this->payload_de_articulo($articulo->fresh(), [
            'cost'            => 400,
            'percentage_gain' => 50,
        ]))->assertStatus(200);

        list($costo, $precio) = $this->lo_que_dice_el_articulo($articulo, 2);

        $this->assertNotEquals($antes[0], $costo, 'Precondición: el costo del artículo se movió.');
        $this->assertNotEquals($antes[1], $precio, 'Precondición: el precio del artículo se movió.');

        $this->assertSame($costo, $this->costo_en_base($combo), 'El combo dice lo mismo que el artículo (costo x 2).');
        $this->assertSame($precio, $this->precio_en_base($combo), 'El combo dice lo mismo que el artículo (precio x 2).');
    }

    /**
     * Borrar el artículo por el endpoint real pasa por el disparador: el combo queda recalculado
     * (un componente borrado sigue contando con sus últimos valores) y sin stock para armarse.
     *
     * @test
     */
    public function borrar_un_articulo_por_el_endpoint_recalcula_los_combos_que_lo_incluyen()
    {
        $this->con_listas(0);

        $articulo = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250, 'stock' => 10]);

        $combo = $this->combo_calculado([[$articulo, 2]]);
        ComboCalculadoHelper::guardar($combo);

        // El combo queda viejo por la puerta de atrás: solo el disparador del borrado lo puede arreglar.
        $this->escribir_crudo($articulo, ['costo_real' => 150, 'final_price' => 300]);

        $this->assertSame(200.0, $this->costo_en_base($combo), 'Precondición: el combo sigue viejo.');

        $this->deleteJson('api/article/' . $articulo->id)->assertStatus(200);

        $this->assertNotNull(DB::table('articles')->where('id', $articulo->id)->value('deleted_at'), 'Precondición: el artículo se borró.');

        $this->assertSame(300.0, $this->costo_en_base($combo), 'El borrado recalculó el combo con los últimos valores del componente.');
        $this->assertSame(600.0, $this->precio_en_base($combo));
        $this->assertSame(0, \App\Models\Combo::find($combo->id)->stock_disponible, 'Y con un componente borrado el combo no se puede armar.');
    }

    /**
     * Una corrida de recálculo por proveedor (cambio de margen) por la cola `sync`, hasta su cierre:
     * los combos calculados quedan con los precios nuevos de los artículos.
     *
     * @test
     */
    public function el_cambio_de_margen_de_un_proveedor_recalcula_los_combos_al_cerrar_la_corrida()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->con_listas(0);

        $proveedor = Provider::create(['name' => 'zz Proveedor de combos', 'user_id' => self::DUENO, 'percentage_gain' => 40]);

        $articulo = $this->nuevo_articulo(['cost' => 100, 'costo_real' => 100, 'provider_id' => $proveedor->id, 'final_price' => 1]);

        $combo = $this->combo_calculado([[$articulo, 3]]);

        // Las dos puntas al día con el margen de hoy.
        ProcessSetFinalPrices::dispatch(self::DUENO, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name);

        $this->assertSame($this->lo_que_dice_el_articulo($articulo, 3)[1], $this->precio_en_base($combo), 'Precondición: combo al día con el margen viejo.');

        $precio_viejo = $this->precio_en_base($combo);

        // Sube el margen del proveedor y se recalcula.
        DB::table('providers')->where('id', $proveedor->id)->update(['percentage_gain' => 120]);

        ProcessSetFinalPrices::dispatch(self::DUENO, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name);

        list($costo, $precio) = $this->lo_que_dice_el_articulo($articulo, 3);

        $this->assertNotEquals($precio_viejo, $precio, 'Precondición: el margen nuevo movió el precio del artículo.');
        $this->assertSame($precio, $this->precio_en_base($combo), 'El cierre de la corrida dejó el combo con el precio nuevo.');
        $this->assertSame($costo, $this->costo_en_base($combo));
    }

    /**
     * Un cambio de configuración de la cuenta (la condición fiscal que define si el IVA entra al
     * costo) disparado como corrida global: al cerrar, el combo coincide con los artículos
     * recalculados.
     *
     * @test
     */
    public function el_cambio_de_la_condicion_fiscal_de_la_cuenta_recalcula_los_combos_al_cerrar_la_corrida()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->con_listas(0);

        $iva = \App\Models\Iva::where('percentage', '21')->first();

        $this->assertNotNull($iva, 'La base de testing tiene que traer la alícuota 21.');

        $articulo = $this->nuevo_articulo(['cost' => 1000, 'costo_real' => 1000, 'iva_id' => $iva->id, 'aplicar_iva' => 1, 'percentage_gain' => 40, 'final_price' => 1]);

        $combo = $this->combo_calculado([[$articulo, 2]]);

        $this->dueno->condicion_iva_precios = 'RRII';
        $this->dueno->aplicar_iva_al_costo  = 1;
        $this->dueno->save();

        ProcessSetFinalPrices::dispatch(self::DUENO, null, null, false, 'configuracion');

        $viejo = [$this->costo_en_base($combo), $this->precio_en_base($combo)];

        $this->assertSame($this->lo_que_dice_el_articulo($articulo, 2)[1], $viejo[1], 'Precondición: combo al día.');

        // Pasa a Monotributo: el IVA sale del costeo y los artículos se recalculan.
        $this->dueno->condicion_iva_precios = 'MT';
        $this->dueno->save();

        ProcessSetFinalPrices::dispatch(self::DUENO, null, null, false, 'configuracion');

        list($costo, $precio) = $this->lo_que_dice_el_articulo($articulo, 2);

        $this->assertNotEquals($viejo, [$costo, $precio], 'Precondición: la condición fiscal movió los números del artículo.');
        $this->assertSame($costo, $this->costo_en_base($combo));
        $this->assertSame($precio, $this->precio_en_base($combo));
    }
}
