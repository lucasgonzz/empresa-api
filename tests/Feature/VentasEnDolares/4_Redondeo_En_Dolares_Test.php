<?php

namespace Tests\Feature\VentasEnDolares;

use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Redondeo de una venta en dólares con precios de decimales largos.
 *
 * EL PROBLEMA QUE SE BUSCA. La SPA manda `price_vender` SIN redondear (un artículo de $1000 vendido
 * en dólares al 1200 llega como 0,833333...) y la API lo guarda en columnas de 2 decimales:
 * `article_sale.price`, `article_sale.cost` y `article_sale.ganancia` son DECIMAL(25,2), mientras que
 * `sales.total`, `sales.sub_total` y `sales.total_cost` son DECIMAL(22,2) / DECIMAL(30,2). Y hay dos
 * pasos que trabajan sobre lo YA redondeado:
 *
 *  - `SaleTotalesHelper::set_total_cost()` recorre `$sale->articles` (o sea, LEE LA PIVOT de la
 *    base, con el costo unitario ya redondeado a 2 decimales) y suma `cost * amount`.
 *  - `SaleController::store()` guarda `sales.total` / `sub_total` tal cual los mandó la SPA (la suma
 *    de los precios SIN redondear por la cantidad), o sea que el total sí conserva la precisión.
 *
 * De ahí salen dos inconsistencias que crecen con la cantidad (medio centavo por unidad): la suma de
 * `price * amount` de los renglones no cierra con `sales.total`, y `sales.total_cost` sale más chico
 * que el costo real (así una venta hecha AL COSTO muestra ganancia positiva y, con 1000 unidades,
 * 3,33 dólares). Es la clase de inconsistencia "el total no es la suma de los renglones" que ya
 * rebotó en presupuestos.
 *
 * ✅ DECISIÓN DE LUCAS (30/9/2026): este redondeo a 2 decimales se ACEPTA tal como está. Los cuatro
 * tests que lo medían como defecto (el total contra la suma de renglones con cantidades grandes, la
 * ganancia de una venta hecha al costo, la ganancia de la venta contra la de sus renglones y un
 * precio menor a un centavo) se sacaron de este archivo junto con esa decisión. Lo que queda
 * caracteriza el comportamiento vigente (cuántos decimales guarda cada columna). Si algún día se
 * ensanchan esas columnas, esos casos se reescriben contra el número nuevo.
 *
 * @group ventas-en-dolares
 */
class Redondeo_En_Dolares_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    /** Un centavo de dólar: la mínima diferencia que una columna de 2 decimales puede sostener. */
    const CENTAVO = 0.01;

    /** @var \App\Models\Article Artículo en pesos con precio manual 1000 y costo 1000 (margen 0). */
    protected $al_costo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-06-01 10:00:00');

        // Precio manual = costo: vendido, la ganancia tiene que ser CERO en cualquier moneda.
        $this->al_costo = $this->crear_articulo('zz Articulo al costo usd', [
            'cost'            => 1000,
            'cost_in_dollars' => 0,
            'percentage_gain' => null,
            'price'           => 1000,
        ], 1000000);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * Escala (cantidad de decimales) de una columna, leída de information_schema.
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return int
     */
    protected function escala($tabla, $columna)
    {
        $fila = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $tabla)
            ->where('column_name', $columna)
            ->first();

        // information_schema devuelve las claves segun el driver (mayusculas en MySQL 8).
        $fila = (array) $fila;

        return (int) (isset($fila['NUMERIC_SCALE']) ? $fila['NUMERIC_SCALE'] : $fila['numeric_scale']);
    }

    /**
     * Venta en dólares del artículo al costo con la cantidad dada.
     *
     * @param  float  $cantidad
     * @return \App\Models\Sale
     */
    protected function venta_al_costo_en_dolares($cantidad)
    {
        $items = [$this->item($this->al_costo, $cantidad, $this->price_vender_para($this->al_costo, 2, $this->VALOR_DOLAR))];

        return $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, $items));
    }

    /**
     * CARACTERIZACIÓN del esquema: precio, costo y ganancia por renglón guardan 2 decimales, y
     * también el total, el subtotal, el costo total y la ganancia de la venta, y el `final_price` del
     * artículo. Si alguien ensancha alguna columna este test avisa que el escenario de redondeo
     * cambió (y los tests de hallazgo de abajo tienen que revisarse).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function el_esquema_guarda_dos_decimales_en_precios_costos_y_totales()
    {
        $this->assertEquals(2, $this->escala('article_sale', 'price'));
        $this->assertEquals(2, $this->escala('article_sale', 'cost'));
        $this->assertEquals(2, $this->escala('article_sale', 'ganancia'));
        $this->assertEquals(2, $this->escala('sales', 'total'));
        $this->assertEquals(2, $this->escala('sales', 'sub_total'));
        $this->assertEquals(2, $this->escala('sales', 'total_cost'));
        $this->assertEquals(2, $this->escala('sales', 'ganancia'));
        $this->assertEquals(2, $this->escala('articles', 'final_price'));

        // El costo del ARTICULO conserva mas precision (6) que el costo de la LINEA de venta (2).
        $this->assertEquals(6, $this->escala('articles', 'cost'));
        $this->assertEquals(6, $this->escala('articles', 'costo_real'));
    }

    /**
     * CARACTERIZACIÓN con una cantidad chica (3 unidades): 0,8333... por 3 = 2,50 de total; el
     * renglón guarda 0,83 y sus 3 unidades suman 2,49. La diferencia (1 centavo) está dentro de lo
     * que sostiene una columna de 2 decimales, así que la suma de los renglones cierra con el total
     * dentro de un centavo.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function con_cantidad_chica_el_total_cierra_con_la_suma_de_renglones_dentro_de_un_centavo()
    {
        $venta = $this->venta_al_costo_en_dolares(3);

        $p = $this->pivot_de($venta, $this->al_costo);

        $suma_renglones = (float) $p->price * (float) $p->amount;

        $this->assertEqualsWithDelta(
            (float) $venta->total,
            $suma_renglones,
            self::CENTAVO + 0.0001,
            'El total de la venta no cierra ni dentro de un centavo con la suma de sus renglones.'
        );
    }

    /**
     * CONTROL en pesos: la misma venta al costo (1000 unidades a $1000) da ganancia exactamente 0,
     * con el total y el costo iguales. Confirma que la inconsistencia de arriba es propia del
     * redondeo en dólares y no de la construcción del escenario.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function control_una_venta_al_costo_en_pesos_tiene_ganancia_cero()
    {
        $items = [$this->item($this->al_costo, 1000, $this->price_vender_para($this->al_costo, 1, $this->VALOR_DOLAR))];

        $venta = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, $items));

        $this->assertEqualsWithDelta(1000000, (float) $venta->total, self::CENTAVO);
        $this->assertEqualsWithDelta(1000000, (float) $venta->total_cost, self::CENTAVO);
        $this->assertEqualsWithDelta(0, (float) $venta->ganancia, self::CENTAVO);
    }

}
