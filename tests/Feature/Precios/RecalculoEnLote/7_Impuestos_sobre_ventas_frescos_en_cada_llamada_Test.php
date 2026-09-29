<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El motor relee los impuestos sobre ventas del dueño en cada llamada (misión
 * recalculo-precios-motor-rapido, 28/9/2026).
 *
 * ArticlePricesHelper los cachea en una estática que vive lo que vive el proceso, y un worker del
 * VPS (supervisor, --max-jobs=50) procesa decenas de jobs en el mismo proceso. El caso que se
 * rompía: el dueño agrega un impuesto sobre ventas, SaleTaxController despacha el recálculo, y el
 * worker —que ya había calculado precios de esa cuenta en un job anterior— recalculaba con la
 * lista vieja, sin el impuesto nuevo, y dejaba los precios como estaban. El motor borra la entrada
 * de ese dueño antes de empezar: una consulta por llamada.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Impuestos_sobre_ventas_frescos_en_cada_llamada_Test extends RecalculoEnLoteTestCase
{
    /**
     * @return void
     */
    public function test_un_impuesto_nuevo_se_aplica_aunque_el_proceso_ya_haya_calculado_esa_cuenta()
    {
        $dueno = $this->crear_dueno();

        $article = $this->crear_articulo($dueno, ['cost' => 1000]);

        /* Primer recálculo en este proceso: deja cacheado que la cuenta no tiene impuestos. */
        RecalculoDePreciosEnLote::recalcular([$article->id], User::find($dueno->id));

        $sin_impuesto = (float) DB::table('articles')->where('id', $article->id)->value('final_price');

        $this->assertGreaterThan(0, $sin_impuesto);

        /* El dueño agrega un impuesto sobre ventas, y llega el recálculo que eso despacha. */
        $this->impuesto_sobre_ventas($dueno, 3.5);

        $resultado = RecalculoDePreciosEnLote::recalcular([$article->id], User::find($dueno->id));

        $con_impuesto = (float) DB::table('articles')->where('id', $article->id)->value('final_price');

        $this->assertSame([$article->id], $resultado['cambiaron'], 'El recálculo después de agregar el impuesto tenía que cambiar el precio.');
        $this->assertEqualsWithDelta(round($sin_impuesto / (1 - 0.035), 2), $con_impuesto, 0.011, 'El precio no incluye el impuesto nuevo: el motor calculó con la lista cacheada.');
    }
}
