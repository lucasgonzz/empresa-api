<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Casos borde del `valor_dolar` y de la clave `cost_in_dollars` en `POST api/sale`.
 *
 * `SaleHelper::getCost()` convierte el costo de cada renglón a la moneda de la venta leyendo
 * `$sale->valor_dolar` y `$item['cost_in_dollars']`:
 *
 *   venta en pesos    -> `isset($item['cost_in_dollars']) && == 1`  => `cost *= (float) valor_dolar`
 *   venta en dólares  -> `$item['cost_in_dollars'] == 0 || null`     => `cost /= (float) valor_dolar`
 *                        (esta rama lee la clave SIN `isset`)
 *
 * Nada de eso se valida antes: `SaleController::store()` no mira `valor_dolar`. Estos tests
 * describen qué pasa hoy cuando la clave falta, es 0 o trae decimales. Los que exigen que un caso
 * mal formado no termine en un 500 mudo o en un costo en cero están marcados `hallazgo-moneda` y se
 * dejan rojos: son comportamiento del sistema, no del test.
 *
 * Payloads calcados de `EscenarioDeMonedas::payload_venta()`. La SPA siempre manda las dos cosas
 * (`valor_dolar` arranca en `owner.dollar` y el artículo entero trae `cost_in_dollars`), así que
 * son casos de otros orígenes (payload directo, asistente, PWA con datos viejos) o de datos
 * incompletos.
 *
 * @group ventas-en-dolares
 */
class Casos_Borde_De_Cotizacion_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    const DELTA = 0.005;

    /** @var \App\Models\Article Artículo en pesos (costo 1000, precio 1500). */
    protected $en_pesos;

    /** @var \App\Models\Article Artículo en dólares (costo 10 USD, precio 15 USD). */
    protected $en_dolares;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-07-01 10:00:00');

        $this->en_pesos   = $this->crear_articulo('zz Articulo pesos borde', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50], 500);
        $this->en_dolares = $this->crear_articulo('zz Articulo dolares borde', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50], 500);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * Postea sin exigir 201 y devuelve `[response, cantidad de ventas creadas]`.
     *
     * @param  array  $payload
     * @return array
     */
    protected function postear_crudo($payload)
    {
        $this->avanzar_reloj();

        $antes = Sale::withTrashed()->count();

        $response = $this->postJson('api/sale', $payload);

        return [$response, Sale::withTrashed()->count() - $antes];
    }

    /**
     * 🔴 HALLAZGO. Venta en dólares SIN la clave `valor_dolar` con un artículo cargado en pesos:
     * `getCost()` hace `$cost /= (float) null` y PHP 7.4 tira "Division by zero", que Laravel
     * convierte en ErrorException y `SaleController::store()` devuelve como HTTP 500
     * (`{"error":true,"message":"Division by zero"}`). No se crea la venta (el rollback anda), pero
     * el error no dice qué falta ni es un 422: el vendedor ve un error genérico. Código:
     * `SaleHelper::getCost()` (rama `moneda_id == 2`, `$cost /= (float)$sale->valor_dolar`) y
     * ausencia de validación de `valor_dolar` en `SaleController::store()`.
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @test
     */
    public function venta_en_dolares_sin_valor_dolar_y_con_articulo_en_pesos_no_termina_en_500()
    {
        $items = [$this->item($this->en_pesos, 2, 1500 / 1200)];

        list($response, $creadas) = $this->postear_crudo($this->payload_venta(2, null, $items));

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'HALLAZGO: venta en dolares sin valor_dolar -> HTTP '.$response->getStatusCode().'. Cuerpo: '.substr($response->getContent(), 0, 200)
        );

        if ($response->getStatusCode() >= 400) {
            $this->assertEquals(0, $creadas, 'Si se rechaza la venta no puede quedar creada.');
        }
    }

    /**
     * 🔴 HALLAZGO (misma causa): `valor_dolar = 0` (la SPA puede mandarlo: el input de la cotización
     * vaciado da `Number('') = 0`) en una venta en dólares con artículo en pesos también es un 500
     * "Division by zero".
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @test
     */
    public function venta_en_dolares_con_valor_dolar_cero_no_termina_en_500()
    {
        $items = [$this->item($this->en_pesos, 2, 1500 / 1200)];

        list($response, $creadas) = $this->postear_crudo($this->payload_venta(2, 0, $items));

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'HALLAZGO: venta en dolares con valor_dolar 0 -> HTTP '.$response->getStatusCode().'. Cuerpo: '.substr($response->getContent(), 0, 200)
        );

        if ($response->getStatusCode() >= 400) {
            $this->assertEquals(0, $creadas);
        }
    }

    /**
     * CARACTERIZACIÓN. Venta en dólares sin `valor_dolar` pero con un artículo que YA está en
     * dólares: no hay nada que convertir, la venta se guarda (201) con precio y costo en dólares y
     * `sales.valor_dolar` en NULL. Costo 10, precio 15, ganancia 5 por unidad.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_sin_valor_dolar_y_con_articulo_en_dolares_se_guarda_con_valor_dolar_null()
    {
        $items = [$this->item($this->en_dolares, 2, 15)];

        $venta = $this->guardar_venta($this->payload_venta(2, null, $items));

        $this->assertNull($venta->valor_dolar, 'La clave no viajo: valor_dolar queda en NULL.');

        $p = $this->pivot_de($venta, $this->en_dolares);

        $this->assertEqualsWithDelta(15, (float) $p->price, self::DELTA);
        $this->assertEqualsWithDelta(10, (float) $p->cost, self::DELTA);
        $this->assertEqualsWithDelta(10, (float) $p->ganancia, self::DELTA);
        $this->assertEqualsWithDelta(30, (float) $venta->total, self::DELTA);
        $this->assertEqualsWithDelta(20, (float) $venta->total_cost, self::DELTA);
    }

    /**
     * 🔴 HALLAZGO. Venta en PESOS sin `valor_dolar` con un artículo cargado en dólares: `getCost()`
     * hace `$cost *= (float) null` = 0 y el costo del renglón se guarda en CERO, sin error. La
     * ganancia del renglón pasa a ser el precio entero. Es peor que un 500: la venta se guarda mal y
     * nadie se entera. Se le manda el precio ya convertido (18000, con el dólar 1200 que el
     * vendedor veía en pantalla) para aislar el costo. Código: `SaleHelper::getCost()`
     * (rama `moneda_id == 1`, `$cost *= (float)$sale->valor_dolar`).
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @test
     */
    public function venta_en_pesos_sin_valor_dolar_no_guarda_en_cero_el_costo_de_un_articulo_en_dolares()
    {
        $items = [$this->item($this->en_dolares, 1, 18000)];

        $venta = $this->guardar_venta($this->payload_venta(1, null, $items));

        $p = $this->pivot_de($venta, $this->en_dolares);

        $this->assertGreaterThan(
            0,
            (float) $p->cost,
            'HALLAZGO: el costo del articulo en dolares (10 USD) quedo en '.$p->cost.' en una venta en pesos sin valor_dolar; la ganancia del renglon quedo en '.$p->ganancia.' (precio entero).'
        );
    }

    /**
     * 🔴 HALLAZGO LATENTE. Venta en dólares con un renglón al que le falta la clave
     * `cost_in_dollars` (artículo en pesos): la rama de dólares de `getCost()` lee
     * `$item['cost_in_dollars']` SIN `isset`, y "Undefined index" (notice -> ErrorException) termina
     * en un HTTP 500 con `{"error":true,"message":"Undefined index: cost_in_dollars"}`. La rama de
     * pesos, en cambio, sí usa `isset` y tolera la ausencia. La SPA y el asistente de IA mandan
     * siempre la clave, así que hoy solo lo dispara un payload directo; queda como riesgo de
     * cualquier origen nuevo que arme renglones a mano.
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @test
     */
    public function venta_en_dolares_con_renglon_sin_la_clave_cost_in_dollars_no_termina_en_500()
    {
        $renglon = $this->item($this->en_pesos, 2, 1500 / 1200);

        unset($renglon['cost_in_dollars']);

        list($response, $creadas) = $this->postear_crudo($this->payload_venta(2, $this->VALOR_DOLAR, [$renglon]));

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'HALLAZGO: renglon sin cost_in_dollars en venta en dolares -> HTTP '.$response->getStatusCode().'. Cuerpo: '.substr($response->getContent(), 0, 200)
        );

        if ($response->getStatusCode() >= 400) {
            $this->assertEquals(0, $creadas);
        }
    }

    /**
     * CARACTERIZACIÓN. La clave `cost_in_dollars` en NULL (artículo sin la marca) sí se tolera en la
     * rama de dólares: `is_null()` lo trata como "costo en pesos" y lo divide por el `valor_dolar`
     * de la venta (1000 / 1200 = 0,8333, guardado como 0,83).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_cost_in_dollars_null_trata_el_costo_como_pesos()
    {
        $renglon = $this->item($this->en_pesos, 2, 1500 / 1200, ['cost_in_dollars' => null]);

        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, [$renglon]));

        $this->assertEqualsWithDelta(1000 / 1200, (float) $this->pivot_de($venta, $this->en_pesos)->cost, self::DELTA);
    }

    /**
     * 🔴 HALLAZGO. `sales.valor_dolar` es una columna ENTERA (migración
     * `2025_08_29_162530_add_moneda_id_to_sales_table.php`: `$table->integer('valor_dolar')`), a
     * diferencia de `budgets.valor_dolar`, que es DECIMAL(20,2). Un dólar con centavos (1234,56;
     * `users.dollar` y las cotizaciones del blue traen decimales) se guarda redondeado a 1235. La
     * cotización con la que se hizo la venta deja de ser la real.
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @test
     */
    public function el_valor_dolar_con_decimales_se_guarda_sin_perder_los_centavos()
    {
        $items = [$this->item($this->en_dolares, 1, 15 * 1234.56)];

        $venta = $this->guardar_venta($this->payload_venta(1, 1234.56, $items));

        $guardado = (float) DB::table('sales')->where('id', $venta->id)->value('valor_dolar');

        $this->assertEqualsWithDelta(
            1234.56,
            $guardado,
            0.001,
            'HALLAZGO: se mando valor_dolar 1234.56 y sales.valor_dolar (columna INT) guardo '.$guardado.'.'
        );
    }

    /**
     * 🔴 HALLAZGO (misma causa): el costo del renglón se calcula en memoria con el `valor_dolar`
     * exacto que mandó la SPA (10 USD * 1234,56 = 12345,60) y la venta guarda 1235, así que el costo
     * en pesos guardado NO es `costo en dólares * sales.valor_dolar`. Cualquier recálculo o
     * auditoría posterior de la venta a partir de su propio `valor_dolar` da otro número (12350).
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @test
     */
    public function el_costo_en_pesos_guardado_es_el_costo_en_dolares_por_el_valor_dolar_guardado()
    {
        $items = [$this->item($this->en_dolares, 1, 15 * 1234.56)];

        $venta = $this->guardar_venta($this->payload_venta(1, 1234.56, $items));

        $valor_guardado = (float) DB::table('sales')->where('id', $venta->id)->value('valor_dolar');
        $costo_guardado = (float) $this->pivot_de($venta, $this->en_dolares)->cost;

        $this->assertEqualsWithDelta(
            10 * $valor_guardado,
            $costo_guardado,
            0.01,
            'HALLAZGO: costo guardado '.$costo_guardado.' vs 10 USD * sales.valor_dolar guardado ('.$valor_guardado.') = '.(10 * $valor_guardado).'.'
        );
    }

    /**
     * `article_purchases` (la tabla que alimenta los reportes por artículo y categoría) separa las
     * monedas en columnas distintas: una venta en pesos llena `cost` / `price` y deja
     * `cost_dolar` / `price_dolar` en NULL; una venta en dólares hace lo inverso
     * (`ArticlePurchaseHelper::set_costo_y_price()`). No se mezclan.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function article_purchases_separa_la_moneda_en_columnas_distintas()
    {
        $en_pesos = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, [
            $this->item($this->en_pesos, 2, 1500),
        ]));

        $en_usd = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, [
            $this->item($this->en_dolares, 3, 15),
        ]));

        $fila_pesos = DB::table('article_purchases')->where('sale_id', $en_pesos->id)->first();
        $fila_usd   = DB::table('article_purchases')->where('sale_id', $en_usd->id)->first();

        $this->assertEqualsWithDelta(1000, (float) $fila_pesos->cost, self::DELTA);
        $this->assertEqualsWithDelta(1500, (float) $fila_pesos->price, self::DELTA);
        $this->assertNull($fila_pesos->cost_dolar, 'Una venta en pesos no puede llenar cost_dolar.');
        $this->assertNull($fila_pesos->price_dolar, 'Una venta en pesos no puede llenar price_dolar.');

        $this->assertEqualsWithDelta(10, (float) $fila_usd->cost_dolar, self::DELTA);
        $this->assertEqualsWithDelta(15, (float) $fila_usd->price_dolar, self::DELTA);
        $this->assertNull($fila_usd->cost, 'Una venta en dolares no puede llenar cost (pesos).');
        $this->assertNull($fila_usd->price, 'Una venta en dolares no puede llenar price (pesos).');
    }
}
