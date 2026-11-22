<?php

namespace Tests\Import;

/**
 * Misión historial-precios-proveedor, ronda 2 (pendiente de REVISION-1-A): una oferta sólo queda marcada
 * `updated_article_cost = 1` si el costo del artículo REALMENTE cambió con ese precio.
 *
 * ProcessRow marca la oferta con `array_key_exists('cost', $cambios)` después del criterio de precio; la
 * pregunta era si algo posterior (ActualizarBBDD, precio manual, redondeo) puede descartar ese `cost` y
 * dejar la oferta "usada" sin cambio real. Estos tests lo miden contra el endpoint real.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
class ProviderOfferUsedCostConsistencyTest extends ProviderOfferImportTestCase
{
    /** @var \App\Models\Article Artículo de BA con precio manual (sin margen), costo 1000. */
    protected $manual;

    /**
     * Artículo de BA con precio manual: sin margen propio, precio fijo 1500.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->manual = $this->crear_articulo([
            'name'            => 'Articulo precio manual',
            'bar_code'        => '8800301',
            'provider_code'   => 'BA-301',
            'provider_id'     => $this->ba->id,
            'cost'            => 1000,
            'percentage_gain' => null,
            'price'           => 1500,
        ]);
        $this->manual->providers()->attach($this->ba->id, ['provider_code' => 'BA-301', 'cost' => 1000]);
    }

    /**
     * Artículo con precio manual: el precio de venta no se mueve, pero el costo sí cambia y la oferta
     * queda usada con el costo anterior.
     *
     * @return void
     */
    public function test_manual_price_article_cost_change_marks_offer_used()
    {
        $this->importar_por_ia([['8800301', 'BA-301', 'Articulo precio manual', 1100]], $this->ba->id);

        $articulo = $this->manual->fresh();

        $this->assertDecimal(1100, $articulo->cost, 'El costo cambia aunque el precio sea manual.');

        $ofertas = $this->ofertas($this->manual->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(1, (int) $ofertas[0]->updated_article_cost);
        $this->assertDecimal(1000, $ofertas[0]->previous_article_cost);
        $this->assertDecimal($articulo->cost, $ofertas[0]->cost, 'La oferta usada es exactamente el costo que quedó en el artículo.');
    }

    /**
     * Costo igual al del artículo: la oferta queda como NO usada y sin costo anterior.
     *
     * @return void
     */
    public function test_same_cost_does_not_mark_offer_used()
    {
        $this->importar_por_ia([['8800301', 'BA-301', 'Articulo precio manual', 1000]], $this->ba->id);

        $this->assertDecimal(1000, $this->manual->fresh()->cost);

        $ofertas = $this->ofertas($this->manual->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(0, (int) $ofertas[0]->updated_article_cost);
        $this->assertNull($ofertas[0]->previous_article_cost);
    }

    /**
     * Costo que sólo difiere más allá de la sexta decimal (la precisión de la columna): igual tras el
     * redondeo, así que no hay cambio real y la oferta no se marca como usada.
     *
     * @return void
     */
    public function test_cost_equal_after_rounding_does_not_mark_offer_used()
    {
        $this->importar_por_ia([['8800301', 'BA-301', 'Articulo precio manual', '1000.0000001']], $this->ba->id);

        $this->assertDecimal(1000, $this->manual->fresh()->cost);

        $ofertas = $this->ofertas($this->manual->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(0, (int) $ofertas[0]->updated_article_cost, 'Igual tras el redondeo: no se usó.');
    }

    /**
     * Dos filas del mismo artículo en el Excel: gana la última y la oferta guardada es la de esa fila.
     * Si queda marcada como usada, el costo del artículo es el de la oferta; si no, el artículo no cambió.
     *
     * @return void
     */
    public function test_repeated_rows_keep_offer_and_article_cost_consistent()
    {
        $this->importar_por_ia([
            ['8800301', 'BA-301', 'Articulo precio manual', 1100],
            ['8800301', 'BA-301', 'Articulo precio manual', 1200],
        ], $this->ba->id);

        $articulo = $this->manual->fresh();
        $ofertas  = $this->ofertas($this->manual->id, $this->ba->id);

        $this->assertCount(1, $ofertas, 'Una oferta por artículo y proveedor.');

        if ((int) $ofertas[0]->updated_article_cost === 1) {
            $this->assertDecimal($articulo->cost, $ofertas[0]->cost, 'Usada: la oferta es el costo que quedó en el artículo.');
        } else {
            $this->assertDecimal(1000, $articulo->cost, 'No usada: el artículo no cambió.');
        }
    }
}
