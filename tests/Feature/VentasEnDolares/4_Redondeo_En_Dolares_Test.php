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
 * Los dos primeros tests miden el esquema (cuántos decimales guarda cada columna) y son de
 * caracterización: documentan cómo está hoy. Los rojos marcados `hallazgo-moneda` son reglas que el
 * sistema debería cumplir y hoy no cumple; se dejan rojos.
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
     * 🔴 HALLAZGO. Con 10 unidades del mismo artículo el total de la venta (8,33) no cierra con la
     * suma de sus renglones (0,83 * 10 = 8,30): faltan 3 centavos. Con 1000 unidades faltan
     * 3,33 dólares. `sales.total` conserva la precisión de lo que mandó la SPA (0,8333... * cantidad),
     * pero `article_sale.price` se guarda redondeado a 2 decimales, así que quien reconstruya la
     * venta desde sus renglones (PDF, reimpresión, devolución, cuenta corriente por renglón) obtiene
     * otro número. Código: `SaleHelper::attachArticle()` (línea del `'price' => $price`) + columna
     * DECIMAL(25,2) de `article_sale.price`.
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @group hallazgo-abierto
     * @test
     */
    public function con_cantidad_grande_el_total_cierra_con_la_suma_de_renglones_dentro_de_un_centavo()
    {
        $this->markTestIncomplete('HALLAZGO ABIERTO (informe 20260929-test-ventas-en-dolares): article_sale.price/cost/ganancia son DECIMAL(x,2): un precio o costo en dolares de menos de un centavo se redondea y la ganancia/total se descuadran. La correccion es ensanchar esas columnas (tabla grande de produccion) y queda a decision de Lucas.');

        $venta = $this->venta_al_costo_en_dolares(1000);

        $p = $this->pivot_de($venta, $this->al_costo);

        $suma_renglones = (float) $p->price * (float) $p->amount;

        $this->assertEqualsWithDelta(
            (float) $venta->total,
            $suma_renglones,
            self::CENTAVO + 0.0001,
            'HALLAZGO: sales.total ('.$venta->total.') no cierra con la suma de los renglones ('.$suma_renglones.'): '
            .'article_sale.price se guarda con 2 decimales ('.$p->price.') y la SPA manda el precio sin redondear.'
        );
    }

    /**
     * 🔴 HALLAZGO. Una venta hecha EXACTAMENTE al costo (artículo de $1000 con precio manual 1000
     * y costo 1000, vendido en dólares) tiene que dar ganancia 0. Con 1000 unidades da 3,33
     * dólares: `sales.total` = 833,33 (precisión de la SPA) contra `sales.total_cost` = 830,00
     * (0,83 redondeado * 1000, porque `SaleTotalesHelper::set_total_cost()` suma sobre la pivot ya
     * redondeada). Mientras tanto `article_sale.ganancia` del renglón queda en 0,00: la venta y su
     * renglón discrepan sobre la ganancia.
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @group hallazgo-abierto
     * @test
     */
    public function una_venta_al_costo_en_dolares_tiene_ganancia_cero()
    {
        $this->markTestIncomplete('HALLAZGO ABIERTO (informe 20260929-test-ventas-en-dolares): article_sale.price/cost/ganancia son DECIMAL(x,2): un precio o costo en dolares de menos de un centavo se redondea y la ganancia/total se descuadran. La correccion es ensanchar esas columnas (tabla grande de produccion) y queda a decision de Lucas.');

        $venta = $this->venta_al_costo_en_dolares(1000);

        $this->assertEqualsWithDelta(
            0,
            (float) $venta->ganancia,
            self::CENTAVO,
            'HALLAZGO: una venta al costo en dolares informa ganancia '.$venta->ganancia
            .' (total '.$venta->total.' - total_cost '.$venta->total_cost.'). El costo unitario de la linea se guarda redondeado a 2 decimales.'
        );
    }

    /**
     * 🔴 HALLAZGO (misma causa): la ganancia de la venta tiene que coincidir con la suma de las
     * ganancias de sus renglones (no hay IVA declarado ni descuentos en este escenario). Con 1000
     * unidades la venta dice 3,33 y el renglón 0,00.
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @group hallazgo-abierto
     * @test
     */
    public function la_ganancia_de_la_venta_en_dolares_coincide_con_la_suma_de_la_de_sus_renglones()
    {
        $this->markTestIncomplete('HALLAZGO ABIERTO (informe 20260929-test-ventas-en-dolares): article_sale.price/cost/ganancia son DECIMAL(x,2): un precio o costo en dolares de menos de un centavo se redondea y la ganancia/total se descuadran. La correccion es ensanchar esas columnas (tabla grande de produccion) y queda a decision de Lucas.');

        $venta = $this->venta_al_costo_en_dolares(1000);

        $suma_ganancias = (float) DB::table('article_sale')->where('sale_id', $venta->id)->sum('ganancia');

        $this->assertEqualsWithDelta(
            $suma_ganancias,
            (float) $venta->ganancia,
            self::CENTAVO,
            'HALLAZGO: sales.ganancia ('.$venta->ganancia.') no coincide con la suma de article_sale.ganancia ('.$suma_ganancias.').'
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

    /**
     * 🔴 HALLAZGO. Un artículo barato (1 peso, costo 0,50) vendido en dólares llega con precio
     * 1/1200 = 0,000833: `article_sale.price` lo guarda como 0,00. El renglón queda con precio CERO
     * y costo cero (aunque `sales.total` sume un centavo por las 10 unidades). Un precio positivo
     * que se persiste como cero pierde el dato para siempre (reimpresión, devolución, reportes por
     * artículo).
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @group hallazgo-abierto
     * @test
     */
    public function un_precio_positivo_muy_chico_en_dolares_no_se_guarda_como_cero()
    {
        $this->markTestIncomplete('HALLAZGO ABIERTO (informe 20260929-test-ventas-en-dolares): article_sale.price/cost/ganancia son DECIMAL(x,2): un precio o costo en dolares de menos de un centavo se redondea y la ganancia/total se descuadran. La correccion es ensanchar esas columnas (tabla grande de produccion) y queda a decision de Lucas.');

        $barato = $this->crear_articulo('zz Articulo barato usd', [
            'cost'            => 0.5,
            'cost_in_dollars' => 0,
            'percentage_gain' => null,
            'price'           => 1,
        ], 1000);

        $precio_spa = $this->price_vender_para($barato, 2, $this->VALOR_DOLAR);

        $this->assertGreaterThan(0, $precio_spa, 'Sanidad del escenario: el precio que manda la SPA es positivo.');

        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, [$this->item($barato, 10, $precio_spa)]));

        $p = $this->pivot_de($venta, $barato);

        $this->assertGreaterThan(
            0,
            (float) $p->price,
            'HALLAZGO: el precio '.$precio_spa.' USD se guardo en article_sale.price como '.$p->price.'. Total de la venta: '.$venta->total.'.'
        );
    }
}
