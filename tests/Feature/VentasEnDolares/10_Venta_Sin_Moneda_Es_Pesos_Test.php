<?php

namespace Tests\Feature\VentasEnDolares;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Una venta sin moneda (`moneda_id` NULL o 0) es SIEMPRE una venta en pesos.
 *
 * Decisión de Lucas (30/9/2026). Antes la trataban distinto cada uno de los lugares que la leen: el
 * Estado de Resultados la contaba como pesos y el listado de Ventas no la sumaba en ningún chip;
 * `getCost()` no le convertía el costo de un artículo en dólares; ARCA la mandaba como `DOL` con la
 * cotización vacía. Acá se fija la regla en los tres frentes donde se hace cumplir:
 *
 *  - el modelo: `Sale` nace con moneda 1 si quien la crea no la manda (o la manda vacía o en 0), y no
 *    deja que un `update` la deje vacía;
 *  - los lectores: `SaleHelper::getCost()` convierte el costo como en una venta en pesos;
 *  - el dato: la migración `2026_09_30_140000_ventas_sin_moneda_a_pesos` (su efecto, que es un
 *    UPDATE acotado a las filas sin moneda, se verifica con las mismas dos condiciones).
 *
 * El listado y el Estado de Resultados contra una venta dejada en NULL en la base están en
 * `Reportes_Por_Moneda_Test`.
 *
 * @group ventas-en-dolares
 */
class Venta_Sin_Moneda_Es_Pesos_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    const DELTA = 0.005;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-08-01 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * Una venta creada sin `moneda_id` (o con null, 0 o vacío) queda en pesos: el modelo la normaliza
     * al crearla, sin importar el camino por el que se crea (el asistente, un pedido, un test).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function una_venta_nueva_sin_moneda_nace_en_pesos()
    {
        foreach ([null, 0, '0', ''] as $indice => $valor) {

            $venta = Sale::create([
                'user_id'   => $this->dueno->id,
                'num'       => 990000 + $indice,
                'total'     => 100,
                'moneda_id' => $valor,
            ]);

            $this->assertEquals(1, Sale::find($venta->id)->moneda_id, 'moneda_id '.var_export($valor, true).' tiene que quedar en pesos.');
        }

        $sin_la_clave = Sale::create([
            'user_id' => $this->dueno->id,
            'num'     => 990010,
            'total'   => 100,
        ]);

        $this->assertEquals(1, Sale::find($sin_la_clave->id)->moneda_id, 'Sin la clave moneda_id tiene que quedar en pesos.');
    }

    /**
     * Una venta en dólares conserva su moneda, y guardarla sin tocar la moneda no la pisa, incluso si
     * el modelo se cargó sin esa columna (el caso que rompería un hook en `saving`).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function una_venta_en_dolares_no_se_pisa_al_guardarla_ni_al_cargarla_sin_la_columna()
    {
        $venta = Sale::create([
            'user_id'     => $this->dueno->id,
            'num'         => 990020,
            'total'       => 100,
            'moneda_id'   => 2,
            'valor_dolar' => 1200,
        ]);

        $parcial = Sale::select('id', 'total')->find($venta->id);
        $parcial->total = 150;
        $parcial->save();

        $this->assertEquals(2, Sale::find($venta->id)->moneda_id, 'Guardar un modelo cargado sin moneda_id no puede pasar la venta a pesos.');
        $this->assertEquals(150, (float) Sale::find($venta->id)->total);
    }

    /**
     * Asignar a mano un `moneda_id` vacío en una venta existente tampoco la deja sin moneda: vuelve a
     * pesos.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function un_update_no_puede_dejar_la_venta_sin_moneda()
    {
        $venta = Sale::create([
            'user_id'   => $this->dueno->id,
            'num'       => 990030,
            'total'     => 100,
            'moneda_id' => 2,
        ]);

        $venta->moneda_id = null;
        $venta->save();

        $this->assertEquals(1, Sale::find($venta->id)->moneda_id);
    }

    /**
     * `getCost()` trata una venta sin moneda como una venta en pesos: el costo de un artículo cargado
     * en dólares se multiplica por la cotización (10 USD x 1200 = 12000). Antes quedaba sin convertir
     * (10, en dólares, dentro de una venta en pesos). La venta se arma sin pasar por `create()` (en
     * memoria y con moneda NULL) porque es justamente el dato viejo el que se quiere cubrir.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function getcost_de_una_venta_sin_moneda_convierte_el_costo_como_en_pesos()
    {
        $venta = new Sale();
        $venta->user_id     = $this->dueno->id;
        $venta->moneda_id   = null;
        $venta->valor_dolar = 1200;

        $en_dolares = ['id' => 1, 'cost' => 10, 'cost_in_dollars' => 1, 'amount' => 1];
        $en_pesos   = ['id' => 2, 'cost' => 1000, 'cost_in_dollars' => 0, 'amount' => 1];

        $this->assertEqualsWithDelta(12000, (float) SaleHelper::getCost($venta, $en_dolares), self::DELTA, 'Un artículo en dólares en una venta sin moneda (pesos) se cotiza.');
        $this->assertEqualsWithDelta(1000, (float) SaleHelper::getCost($venta, $en_pesos), self::DELTA, 'Un artículo en pesos queda igual.');

        $venta->moneda_id = 0;

        $this->assertEqualsWithDelta(12000, (float) SaleHelper::getCost($venta, $en_dolares), self::DELTA, 'Moneda 0 es lo mismo que sin moneda.');
    }

    /**
     * El efecto de la migración de datos: todas las filas sin moneda (NULL o 0) pasan a 1 y las que ya
     * tenían moneda (1 o 2) no se tocan. Se aplica el mismo UPDATE de la migración sobre ventas
     * dejadas en NULL y en 0 directo en la base.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function el_update_de_la_migracion_pasa_las_ventas_sin_moneda_a_pesos_y_no_toca_las_demas()
    {
        $nula = Sale::create(['user_id' => $this->dueno->id, 'num' => 990040, 'total' => 10, 'moneda_id' => 1]);
        $cero = Sale::create(['user_id' => $this->dueno->id, 'num' => 990041, 'total' => 10, 'moneda_id' => 1]);
        $dolar = Sale::create(['user_id' => $this->dueno->id, 'num' => 990042, 'total' => 10, 'moneda_id' => 2]);
        $peso = Sale::create(['user_id' => $this->dueno->id, 'num' => 990043, 'total' => 10, 'moneda_id' => 1]);

        DB::table('sales')->where('id', $nula->id)->update(['moneda_id' => null]);
        DB::table('sales')->where('id', $cero->id)->update(['moneda_id' => 0]);

        DB::statement('UPDATE `sales` SET `moneda_id` = 1 WHERE `moneda_id` IS NULL OR `moneda_id` = 0');

        $this->assertEquals(1, (int) DB::table('sales')->where('id', $nula->id)->value('moneda_id'));
        $this->assertEquals(1, (int) DB::table('sales')->where('id', $cero->id)->value('moneda_id'));
        $this->assertEquals(2, (int) DB::table('sales')->where('id', $dolar->id)->value('moneda_id'), 'Una venta en dólares no se toca.');
        $this->assertEquals(1, (int) DB::table('sales')->where('id', $peso->id)->value('moneda_id'));
    }
}
