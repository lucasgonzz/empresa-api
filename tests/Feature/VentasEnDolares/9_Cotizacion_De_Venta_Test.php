<?php

namespace Tests\Feature\VentasEnDolares;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\sale\CotizacionDeVentaHelper;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * La cotización del dólar de una venta (misión corregir-ventas-en-dolares, 30/9/2026):
 * el 422 de `SaleController::store()`, la moneda 0 y la red de `SaleHelper::getCost()`.
 *
 * Complementa a `Casos_Borde_De_Cotizacion_Test`, que describe los casos mal formados desde la
 * óptica del hallazgo: acá se fija la regla nueva, con su mensaje y sus límites (qué NO se rechaza).
 *
 * @group ventas-en-dolares
 */
class Cotizacion_De_Venta_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    const DELTA = 0.005;

    /** @var \App\Models\Article Artículo en pesos (costo 1000). */
    protected $en_pesos;

    /** @var \App\Models\Article Artículo en dólares (costo 10 USD). */
    protected $en_dolares;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-07-02 10:00:00');

        $this->en_pesos   = $this->crear_articulo('zz Articulo pesos cotizacion', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50], 500);
        $this->en_dolares = $this->crear_articulo('zz Articulo dolares cotizacion', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50], 500);
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
     * Una venta sin guardar del dueño, para ejercitar `SaleHelper::getCost()` sin pasar por la API.
     *
     * @param  int         $moneda_id
     * @param  float|null  $valor_dolar
     * @return \App\Models\Sale
     */
    protected function venta_sin_guardar($moneda_id, $valor_dolar)
    {
        $venta = new Sale();

        $venta->user_id     = $this->dueno->id;
        $venta->moneda_id   = $moneda_id;
        $venta->valor_dolar = $valor_dolar;

        return $venta;
    }

    /**
     * El dólar del dueño (`users.dollar`) para el test.
     *
     * @param  float|null  $valor
     * @return void
     */
    protected function fijar_dolar_del_dueno($valor)
    {
        User::where('id', $this->dueno->id)->update(['dollar' => $valor]);
    }

    /**
     * Una venta en dólares sin `valor_dolar` se rechaza con un 422 que dice qué falta, y no crea
     * nada (ni la venta ni un número de venta consumido).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_sin_cotizacion_devuelve_422_con_mensaje_y_no_crea_nada()
    {
        $items = [$this->item($this->en_dolares, 1, 15)];

        list($response, $creadas) = $this->postear_crudo($this->payload_venta(2, null, $items));

        $response->assertStatus(422);

        $this->assertTrue((bool) $response->json('sin_cotizacion_dolar'));
        $this->assertStringContainsString('cotización del dólar', (string) $response->json('message'));
        $this->assertEquals(0, $creadas, 'Un rechazo no puede dejar una venta creada.');
    }

    /**
     * Una cotización que no es un número (texto, o con coma decimal) cuenta como ausente.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_cotizacion_no_numerica_devuelve_422()
    {
        $items = [$this->item($this->en_dolares, 1, 15)];

        list($response, $creadas) = $this->postear_crudo($this->payload_venta(2, '1234,56', $items));

        $response->assertStatus(422);

        $this->assertEquals(0, $creadas);
    }

    /**
     * Una cotización negativa tampoco sirve.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_cotizacion_negativa_devuelve_422()
    {
        $items = [$this->item($this->en_dolares, 1, 15)];

        list($response, $creadas) = $this->postear_crudo($this->payload_venta(2, -5, $items));

        $response->assertStatus(422);

        $this->assertEquals(0, $creadas);
    }

    /**
     * Una venta en PESOS con un artículo cargado en dólares y SIN cotización NO se rechaza: se guarda
     * y `getCost()` convierte el costo con el dólar del dueño (`users.dollar`). Antes guardaba el costo
     * en CERO sin avisar (la ganancia era el precio entero). Es el camino del asistente de IA, que arma
     * ventas en pesos con `valor_dolar` null y no puede pedirle nada al usuario: un 422 ahí lo dejaba
     * sin poder vender artículos en dólares.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_pesos_con_articulo_en_dolares_y_sin_cotizacion_se_guarda_con_el_dolar_del_dueno()
    {
        $this->fijar_dolar_del_dueno(1200);

        $items = [$this->item($this->en_dolares, 1, 18000)];

        $venta = $this->guardar_venta($this->payload_venta(1, null, $items));

        $p = $this->pivot_de($venta, $this->en_dolares);

        // Costo 10 USD x 1200 (el dólar del dueño) = 12000; no cero.
        $this->assertEqualsWithDelta(12000, (float) $p->cost, 0.01);
        $this->assertEqualsWithDelta(6000, (float) $p->ganancia, 0.01);
        $this->assertNull($venta->valor_dolar, 'La venta no trajo cotización: sales.valor_dolar queda en NULL (el dólar del dueño solo se usó para el costo).');
    }

    /**
     * El límite del rechazo: una venta en pesos SIN artículos en dólares (la enorme mayoría) no
     * necesita cotización. Con `valor_dolar` ausente, en cero o con basura se guarda igual, y lo que
     * queda en `sales.valor_dolar` es NULL (no el 0 ni el texto que vino).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_pesos_sin_articulos_en_dolares_no_necesita_cotizacion()
    {
        $items = [$this->item($this->en_pesos, 1, 1500)];

        $sin_clave = $this->guardar_venta($this->payload_venta(1, null, $items));

        $this->assertNull($sin_clave->valor_dolar);

        $en_cero = $this->guardar_venta($this->payload_venta(1, 0, $items));

        $this->assertNull($en_cero->valor_dolar, 'Una cotización en cero no sirve: se guarda NULL.');

        $con_basura = $this->guardar_venta($this->payload_venta(1, 'abc', $items));

        $this->assertNull($con_basura->valor_dolar, 'Un texto no se guarda en la columna numérica.');
    }

    /**
     * `moneda_id` 0 es pesos, igual que null o ausente: antes solo el null caía a 1 y el 0 se guardaba
     * como "moneda 0", que ninguna rama de `getCost()` ni de las cuentas reconoce.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function moneda_cero_se_guarda_como_pesos()
    {
        $items = [$this->item($this->en_pesos, 1, 1500)];

        $venta = $this->guardar_venta($this->payload_venta(0, $this->VALOR_DOLAR, $items));

        $this->assertEquals(1, (int) $venta->moneda_id);
    }

    /**
     * La cotización se guarda con sus dos decimales, y el costo de un artículo en dólares es
     * "costo en dólares × la cotización guardada" (redondeada a 2 decimales: el costo no puede
     * calcularse con más precisión que la que después lee ARCA).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function la_cotizacion_se_guarda_redondeada_a_dos_decimales_y_el_costo_usa_esa_misma()
    {
        $items = [$this->item($this->en_dolares, 1, 15 * 1234.5678)];

        $venta = $this->guardar_venta($this->payload_venta(1, 1234.5678, $items));

        $guardado = (float) DB::table('sales')->where('id', $venta->id)->value('valor_dolar');

        $this->assertEqualsWithDelta(1234.57, $guardado, 0.0001);

        $this->assertEqualsWithDelta(10 * $guardado, (float) $this->pivot_de($venta, $this->en_dolares)->cost, 0.01);
    }

    /**
     * La red de `getCost()`: sin cotización en la venta se usa el dólar del dueño. Es el camino de
     * los que no pasan por el 422 de `store()` (confirmar un presupuesto, el asistente de IA...).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function getcost_sin_cotizacion_de_la_venta_usa_el_dolar_del_dueno()
    {
        $this->fijar_dolar_del_dueno(1000);

        // Venta en pesos, artículo en dólares: 10 USD * 1000 del dueño.
        $en_pesos = $this->venta_sin_guardar(1, null);

        $this->assertEqualsWithDelta(10000, SaleHelper::getCost($en_pesos, ['cost' => 10, 'cost_in_dollars' => 1]), self::DELTA);

        // Venta en dólares, artículo en pesos: 1000 pesos / 1000 del dueño.
        $en_dolares = $this->venta_sin_guardar(2, 0);

        $this->assertEqualsWithDelta(1, SaleHelper::getCost($en_dolares, ['cost' => 1000, 'cost_in_dollars' => 0]), self::DELTA);
    }

    /**
     * Y la cotización de la venta, cuando sirve, gana sobre la del dueño.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function getcost_con_cotizacion_de_la_venta_no_mira_el_dolar_del_dueno()
    {
        $this->fijar_dolar_del_dueno(1000);

        $venta = $this->venta_sin_guardar(1, '1200.50');

        $this->assertEqualsWithDelta(12005, SaleHelper::getCost($venta, ['cost' => 10, 'cost_in_dollars' => 1]), self::DELTA);
    }

    /**
     * Sin cotización en la venta NI en el dueño, `getCost()` deja el costo como está: no divide por
     * cero (500) ni multiplica por cero (costo en cero sin avisar).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function getcost_sin_ninguna_cotizacion_deja_el_costo_sin_convertir()
    {
        $this->fijar_dolar_del_dueno(null);

        $en_pesos = $this->venta_sin_guardar(1, null);

        $this->assertEqualsWithDelta(10, SaleHelper::getCost($en_pesos, ['cost' => 10, 'cost_in_dollars' => 1]), self::DELTA);

        $en_dolares = $this->venta_sin_guardar(2, null);

        $this->assertEqualsWithDelta(1000, SaleHelper::getCost($en_dolares, ['cost' => 1000, 'cost_in_dollars' => 0]), self::DELTA);
    }

    /**
     * En la rama de dólares, un renglón sin la clave `cost_in_dollars` (o con ella en null) es un
     * artículo en pesos: se convierte. Antes moría con "Undefined index".
     *
     * @group ventas-en-dolares
     * @test
     */
    public function getcost_en_dolares_trata_la_clave_ausente_como_costo_en_pesos()
    {
        $venta = $this->venta_sin_guardar(2, 1000);

        $this->assertEqualsWithDelta(1, SaleHelper::getCost($venta, ['cost' => 1000]), self::DELTA);
        $this->assertEqualsWithDelta(1, SaleHelper::getCost($venta, ['cost' => 1000, 'cost_in_dollars' => null]), self::DELTA);
        $this->assertEqualsWithDelta(1, SaleHelper::getCost($venta, ['cost' => 1000, 'cost_in_dollars' => '0']), self::DELTA);
        $this->assertEqualsWithDelta(1000, SaleHelper::getCost($venta, ['cost' => 1000, 'cost_in_dollars' => 1]), self::DELTA, 'Un costo que ya está en dólares no se convierte.');
    }

    /**
     * Las reglas puras del helper, sin base.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function el_helper_distingue_cotizaciones_validas_monedas_y_renglones()
    {
        $this->assertTrue(CotizacionDeVentaHelper::es_cotizacion_valida(1200));
        $this->assertTrue(CotizacionDeVentaHelper::es_cotizacion_valida('1234.56'));
        $this->assertFalse(CotizacionDeVentaHelper::es_cotizacion_valida(null));
        $this->assertFalse(CotizacionDeVentaHelper::es_cotizacion_valida(''));
        $this->assertFalse(CotizacionDeVentaHelper::es_cotizacion_valida(0));
        $this->assertFalse(CotizacionDeVentaHelper::es_cotizacion_valida('0.00'));
        $this->assertFalse(CotizacionDeVentaHelper::es_cotizacion_valida(-3));
        $this->assertFalse(CotizacionDeVentaHelper::es_cotizacion_valida('1234,56'));
        $this->assertFalse(CotizacionDeVentaHelper::es_cotizacion_valida(INF));

        $this->assertSame(1, CotizacionDeVentaHelper::normalizar_moneda_id(null));
        $this->assertSame(1, CotizacionDeVentaHelper::normalizar_moneda_id(0));
        $this->assertSame(1, CotizacionDeVentaHelper::normalizar_moneda_id(''));
        $this->assertSame(2, CotizacionDeVentaHelper::normalizar_moneda_id('2'));

        $this->assertTrue(CotizacionDeVentaHelper::item_esta_en_dolares(['cost_in_dollars' => 1]));
        $this->assertTrue(CotizacionDeVentaHelper::item_esta_en_dolares(['cost_in_dollars' => '1']));
        $this->assertTrue(CotizacionDeVentaHelper::item_esta_en_dolares(['cost_in_dollars' => true]));
        $this->assertFalse(CotizacionDeVentaHelper::item_esta_en_dolares(['cost_in_dollars' => 0]));
        $this->assertFalse(CotizacionDeVentaHelper::item_esta_en_dolares([]));

        $this->assertNull(CotizacionDeVentaHelper::valor_dolar_para_guardar(0));
        $this->assertSame(1234.57, CotizacionDeVentaHelper::valor_dolar_para_guardar('1234.5678'));
    }
}
