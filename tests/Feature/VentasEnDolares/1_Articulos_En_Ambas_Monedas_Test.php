<?php

namespace Tests\Feature\VentasEnDolares;

use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\User;
use Tests\EmpresaTestCase;

/**
 * Artículos en pesos y en dólares para una cuenta como la de 2R (extensión `ventas_en_dolares`,
 * `users.cotizar_precios_en_dolares = 0`).
 *
 * LA REGLA QUE SE VERIFICA. `ArticleHelper::cotizar()` solo multiplica por el dólar si el artículo
 * tiene `cost_in_dollars` Y la cuenta tiene `cotizar_precios_en_dolares = 1`. Con la cuenta en 0, un
 * artículo cargado en dólares conserva `costo_real` y `final_price` EN DÓLARES: 10 USD de costo y
 * 50 % de margen dan un precio de 15, no de 15.000. Lo que decide en qué moneda se cobra es la
 * moneda de la venta (ver `EscenarioDeMonedas`).
 *
 * Todo entra por `POST api/article` / `PUT api/article` (nunca `Article::create`, que se saltearía
 * el cálculo). El dólar global (`users.dollar`) arranca en 1000 a propósito, distinto del 1200 que
 * viaja en las ventas de las otras pruebas: si algún día el precio en dólares se cotizara con el
 * dólar global, se vería.
 *
 * @group ventas-en-dolares
 */
class Articulos_En_Ambas_Monedas_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-03-02 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * Body de `PUT api/article`. El controller resuelve el modelo por `$request->id` (no por la
     * ruta), así que el id viaja en el body.
     *
     * @param  \App\Models\Article  $articulo
     * @param  array  $overrides
     * @return array
     */
    protected function payload_edicion($articulo, $overrides = [])
    {
        return array_merge($articulo->getAttributes(), [
            'id'                 => $articulo->id,
            'cost_incluye_iva'   => 0,
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ], $overrides);
    }

    /**
     * Artículo en pesos: costo 1000, sin `cost_in_dollars`, margen 50 %. El precio sale en pesos y
     * el `costo_real` es el costo (sin IVA ni descuentos en este escenario): 1000 * 1,5 = 1500.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function articulo_en_pesos_guarda_costo_real_y_precio_en_pesos()
    {
        $a = $this->crear_articulo('zz Articulo pesos', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50]);

        $this->assertEquals(1000, (float) $a->cost);
        $this->assertEquals(1000, (float) $a->costo_real, 'El costo real de un articulo en pesos sin IVA ni descuentos es su costo.');
        $this->assertEquals(1500, (float) $a->final_price, 'final_price = costo real + 50% de margen, en pesos.');
        $this->assertEquals(0, (int) $a->cost_in_dollars);
    }

    /**
     * Artículo en dólares con la cuenta sin cotizar: costo 10 USD, margen 50 % => `final_price` 15
     * (dólares). NO se multiplica por `users.dollar` (1000): si saliera 15.000 el artículo estaría
     * cotizado a pesos aunque la cuenta pidió no hacerlo.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function articulo_en_dolares_guarda_costo_real_y_precio_en_dolares_sin_multiplicar_por_el_dolar()
    {
        $b = $this->crear_articulo('zz Articulo dolares', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50]);

        $this->assertEquals(10, (float) $b->cost);
        $this->assertEquals(10, (float) $b->costo_real, 'El costo real de un articulo en dolares queda en dolares.');
        $this->assertEquals(15, (float) $b->final_price, 'final_price = 10 USD + 50% = 15 USD. Si dio 15000 se cotizo con users.dollar (1000) a pesar de cotizar_precios_en_dolares = 0.');
        $this->assertEquals(1, (int) $b->cost_in_dollars);
    }

    /**
     * Precio MANUAL en pesos: se vende por ese número, tal cual (el margen queda en null porque
     * `store()` normaliza un margen en 0/vacío a null y un margen > 0 borra el precio manual).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function articulo_en_pesos_con_precio_manual_conserva_el_precio_manual()
    {
        $a = $this->crear_articulo('zz Articulo pesos manual', [
            'cost'            => 1000,
            'cost_in_dollars' => 0,
            'percentage_gain' => null,
            'price'           => 2000,
        ]);

        $this->assertEquals(2000, (float) $a->price);
        $this->assertEquals(2000, (float) $a->final_price, 'Un precio manual en pesos se vende exactamente por ese valor.');
        $this->assertEquals(1000, (float) $a->costo_real);
    }

    /**
     * Precio MANUAL en dólares (artículo con `cost_in_dollars`): 25 USD se guardan como 25, no
     * como 25 * dólar.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function articulo_en_dolares_con_precio_manual_conserva_el_precio_manual_en_dolares()
    {
        $b = $this->crear_articulo('zz Articulo dolares manual', [
            'cost'            => 10,
            'cost_in_dollars' => 1,
            'percentage_gain' => null,
            'price'           => 25,
        ]);

        $this->assertEquals(25, (float) $b->price);
        $this->assertEquals(25, (float) $b->final_price, 'Un precio manual en dolares queda en dolares (25), sin cotizar.');
        $this->assertEquals(10, (float) $b->costo_real);
    }

    /**
     * Cambiar el dólar global no mueve ningún precio si la cuenta no cotiza: se sube `users.dollar`
     * de 1000 a 1500 y se recalcula POR LOS DOS CAMINOS que existen (guardar cada artículo por
     * `PUT api/article` y el recálculo masivo por dólar `ProcessSetFinalPrices`, que en testing
     * corre en línea). El artículo en dólares sigue en 15 y el de pesos en 1500.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function cambiar_el_dolar_global_no_cambia_el_final_price_de_ninguno_de_los_dos()
    {
        $a = $this->crear_articulo('zz Articulo pesos', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50]);
        $b = $this->crear_articulo('zz Articulo dolares', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50]);

        User::where('id', $this->dueno->id)->update(['dollar' => 1500]);

        // Camino 1: guardar el articulo (recalcula su precio).
        $this->putJson('api/article/'.$a->id, $this->payload_edicion($a))->assertStatus(200);
        $this->putJson('api/article/'.$b->id, $this->payload_edicion($b))->assertStatus(200);

        $this->assertEquals(1500, (float) Article::find($a->id)->final_price, 'El articulo en pesos cambio de precio al subir el dolar y guardarlo.');
        $this->assertEquals(15, (float) Article::find($b->id)->final_price, 'El articulo en dolares se cotizo al guardarlo con el dolar nuevo (deberia seguir en 15 USD).');

        // Camino 2: el recalculo masivo que se dispara cuando cambia el dolar global.
        ProcessSetFinalPrices::dispatchSync($this->dueno->id, null, null, true, 'dolar');

        $this->assertEquals(1500, (float) Article::find($a->id)->final_price, 'El recalculo por dolar movio el precio del articulo en pesos.');
        $this->assertEquals(15, (float) Article::find($b->id)->final_price, 'El recalculo por dolar cotizo el articulo en dolares a pesos (deberia seguir en 15 USD).');
        $this->assertEquals(10, (float) Article::find($b->id)->costo_real);
    }

    /**
     * CONTROL de que la prueba anterior detecta el cambio: con `cotizar_precios_en_dolares = 1` el
     * mismo artículo en dólares SÍ se cotiza (costo real 10 * dólar 1500 = 15.000, + 50 % =
     * 22.500) y el de pesos no se mueve. Si este control diera 15, la prueba de arriba sería
     * vacía (no estaría midiendo nada).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function control_con_cotizar_prendido_el_articulo_en_dolares_si_se_cotiza_a_pesos()
    {
        $a = $this->crear_articulo('zz Articulo pesos', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50]);
        $b = $this->crear_articulo('zz Articulo dolares', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50]);

        User::where('id', $this->dueno->id)->update(['dollar' => 1500, 'cotizar_precios_en_dolares' => 1]);

        ProcessSetFinalPrices::dispatchSync($this->dueno->id, null, null, true, 'dolar');

        $this->assertEquals(22500, (float) Article::find($b->id)->final_price, 'Con cotizar prendido el articulo en dolares tiene que salir en pesos: 10 * 1500 * 1,5.');
        $this->assertEquals(1500, (float) Article::find($a->id)->final_price);
    }

    /**
     * Con el IVA 21 % sumado al vender (fixture: `usar_condicion_fiscal_en_costeo = 1`, Responsable
     * Inscripto: el IVA NO va al costo, se suma al precio), el precio con IVA sale en cada moneda:
     * 1500 * 1,21 = 1815 pesos y 15 * 1,21 = 18,15 dólares. El costo real queda neto.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function el_iva_21_se_suma_al_precio_en_cada_moneda_y_no_al_costo()
    {
        $a = $this->crear_articulo('zz Articulo pesos iva', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50, 'aplicar_iva' => 1, 'iva_id' => 2]);
        $b = $this->crear_articulo('zz Articulo dolares iva', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50, 'aplicar_iva' => 1, 'iva_id' => 2]);

        $this->assertEquals(1000, (float) $a->costo_real, 'El IVA no tiene que entrar al costo en esta cuenta.');
        $this->assertEquals(1815, (float) $a->final_price);
        $this->assertEquals(10, (float) $b->costo_real);
        $this->assertEquals(18.15, (float) $b->final_price);
    }

    /**
     * La percepción IIBB del fixture (3,5 %, `sale_taxes`) se aplica por división sobre el precio y
     * de igual forma en las dos monedas: 1500 / 0,965 = 1554,40 y 15 / 0,965 = 15,54 (la base
     * guarda `final_price` con 2 decimales).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function la_percepcion_iibb_se_aplica_igual_a_los_dos_articulos()
    {
        $this->fijar_percepcion_iibb(true);

        $a = $this->crear_articulo('zz Articulo pesos iibb', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50]);
        $b = $this->crear_articulo('zz Articulo dolares iibb', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50]);

        $this->assertEquals(round(1500 / 0.965, 2), (float) $a->final_price);
        $this->assertEquals(round(15 / 0.965, 2), (float) $b->final_price);
    }
}
