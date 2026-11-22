<?php

namespace Tests\Feature\HistorialPreciosProveedor;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\PriceChange;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Compras\ComprasTestCase;

/**
 * Misión historial-precios-proveedor: la compra (alta de `POST api/provider-order`) deja registrado a
 * qué costo le compró cada proveedor y si ese costo se usó (CA9 del plan).
 *
 * Escenario: el Taladro es de "Buenos Aires" (costo 1200, margen 30%) y se le compra a "Rosario" a un
 * costo mayor. Por el endpoint real de compras se miden tres casos:
 *   1. "Actualizar precios" prendido: el costo del artículo cambia, la oferta de Rosario queda marcada
 *      como usada, con el costo anterior, y vinculada al cambio de precio del recálculo diferido de la
 *      compra (`origen = recalculo_lote`).
 *   2. "Actualizar precios" apagado: la oferta queda registrada pero NO usada; el costo del artículo
 *      no se mueve y no hay vínculo.
 *   3. "Actualizar proveedor" explícito (con "actualizar precios" apagado): el artículo pasa a ser de
 *      Rosario y la oferta lo dice, con el titular anterior.
 *
 * Stock, totales y cuenta corriente de la compra no se miden acá: los cubren los tests de
 * `tests/Feature/Compras/**`, que no se tocan.
 *
 * 🔴 Rojo esperado ANTES de construir la misión: columnas de uso inexistentes en
 * `provider_price_offers`. La oferta de la compra y el cambio de precio del recálculo ya existen hoy,
 * así que las aserciones previas a la columna nueva corren contra el sistema actual.
 *
 * IMPORTANTE: PHP 7.4 estricto (ver las restricciones de la misión: nada de sintaxis de PHP 8).
 */
class PurchaseOfferUsageTest extends ComprasTestCase
{
    /** Columnas que agrega la migración de la misión a provider_price_offers. */
    const COLUMNAS_DE_USO = [
        'updated_article_cost',
        'previous_article_cost',
        'price_change_id',
        'changed_article_provider',
        'previous_article_provider_id',
        'last_seen_at',
        'last_seen_reference_id',
    ];

    /** Nombre del artículo de estos tests (único en el fixture). */
    const TALADRO = 'Taladro historial compra';

    /** @var \App\Models\User Dueño del fixture. */
    protected $owner;

    /** @var \App\Models\Provider Titular del Taladro. */
    protected $ba;

    /** @var \App\Models\Provider Proveedor al que se le compra. */
    protected $rosario;

    /** @var \App\Models\Article */
    protected $taladro;

    /**
     * Arma el Taladro (titular BA, costo 1200, margen 30) con su precio derivado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = auth()->user();
        $this->ba      = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $this->rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $taladro = new Article();

        $taladro->user_id         = $this->owner->id;
        $taladro->name            = self::TALADRO;
        $taladro->bar_code        = '8802001';
        $taladro->provider_id     = $this->ba->id;
        $taladro->provider_code   = 'BA-1';
        $taladro->cost            = 1200;
        $taladro->percentage_gain = 30;
        $taladro->iva_id          = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->iva_id;
        $taladro->status          = 'active';
        $taladro->save();

        ArticleHelper::setFinalPrice($taladro->fresh(), $this->owner->id);

        $this->taladro = $taladro->fresh();
    }

    /**
     * CA9 (1): compra con "actualizar precios": la oferta queda usada y vinculada al cambio de precio
     * del recálculo de la compra.
     *
     * @return void
     */
    public function test_purchase_with_update_prices_links_recalculation_event()
    {
        $evento_previo = (int) PriceChange::max('id');

        $compra_id = $this->comprar(['update_prices' => 1], ['update_provider' => 0]);

        $articulo = $this->taladro->fresh();

        $this->assertEquals(
            1300.0,
            (float) $articulo->cost,
            'Premisa: con "actualizar precios" el costo del Taladro pasa a lo que se pagó.'
        );

        $evento = PriceChange::where('article_id', $this->taladro->id)
            ->where('id', '>', $evento_previo)
            ->where('origen', 'recalculo_lote')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($evento, 'La compra deja un cambio de precio del recálculo diferido (origen recalculo_lote).');

        $this->assert_columnas_de_uso();

        $oferta = $this->oferta_de_rosario();

        $this->assertNotNull($oferta, 'La compra deja la oferta de Rosario (origen compra).');
        $this->assertSame('compra', $oferta->origen);
        $this->assertSame($compra_id, (int) $oferta->referencia_id);
        $this->assertEquals(1300.0, (float) $oferta->cost);
        $this->assertSame(1, (int) $oferta->updated_article_cost, 'El costo de la compra se usó para actualizar el artículo.');
        $this->assertEquals(1200.0, (float) $oferta->previous_article_cost);
        $this->assertSame($this->ba->id, (int) $oferta->previous_article_provider_id);
        $this->assertSame((int) $evento->id, (int) $oferta->price_change_id, 'Vinculada al evento del recálculo de la compra.');
        $this->assertSame(
            (int) $articulo->provider_id === $this->rosario->id ? 1 : 0,
            (int) $oferta->changed_article_provider,
            'La marca de cambio de proveedor coincide con lo que realmente pasó con articles.provider_id.'
        );
    }

    /**
     * CA9 (2): compra con "actualizar precios" apagado: la oferta se registra pero no se usó; el costo
     * del artículo queda como estaba y no hay vínculo con ningún cambio de precio.
     *
     * @return void
     */
    public function test_purchase_without_update_prices_is_only_recorded()
    {
        $compra_id = $this->comprar(['update_prices' => 0], ['update_provider' => 0]);

        $articulo = $this->taladro->fresh();

        $this->assertEquals(1200.0, (float) $articulo->cost, 'Sin "actualizar precios" el costo no se mueve.');
        $this->assertSame($this->ba->id, (int) $articulo->provider_id, 'Ni el titular.');

        $this->assert_columnas_de_uso();

        $oferta = $this->oferta_de_rosario();

        $this->assertNotNull($oferta, 'Aunque no actualice precios, la compra deja la oferta.');
        $this->assertSame($compra_id, (int) $oferta->referencia_id);
        $this->assertEquals(1300.0, (float) $oferta->cost);
        $this->assertSame(0, (int) $oferta->updated_article_cost);
        $this->assertNotNull($oferta->updated_article_cost, '0, nunca null.');
        $this->assertNull($oferta->price_change_id);
        $this->assertNull($oferta->previous_article_cost);
        $this->assertSame(0, (int) $oferta->changed_article_provider);
        $this->assertSame($this->ba->id, (int) $oferta->previous_article_provider_id);
    }

    /**
     * CA9 (3): compra con "actualizar proveedor" explícito en el renglón (y "actualizar precios"
     * apagado): el artículo pasa a ser de Rosario y la oferta lo registra con el titular anterior.
     *
     * @return void
     */
    public function test_purchase_with_explicit_provider_update_marks_change()
    {
        $this->comprar(['update_prices' => 0], ['update_provider' => 1]);

        $articulo = $this->taladro->fresh();

        $this->assertSame(
            $this->rosario->id,
            (int) $articulo->provider_id,
            'Premisa: con "actualizar proveedor" el Taladro pasa a ser de Rosario.'
        );
        $this->assertEquals(1200.0, (float) $articulo->cost, 'Sin "actualizar precios" el costo no se mueve.');

        $this->assert_columnas_de_uso();

        $oferta = $this->oferta_de_rosario();

        $this->assertNotNull($oferta);
        $this->assertSame(1, (int) $oferta->changed_article_provider, 'La oferta registra que el artículo cambió de proveedor.');
        $this->assertSame($this->ba->id, (int) $oferta->previous_article_provider_id, 'Y quién era el titular antes.');
        $this->assertSame(0, (int) $oferta->updated_article_cost, 'El costo no se usó.');
        $this->assertNull($oferta->price_change_id);
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Falla con un mensaje claro si la migración de la misión todavía no corrió.
     *
     * @return void
     */
    protected function assert_columnas_de_uso()
    {
        foreach (self::COLUMNAS_DE_USO as $columna) {
            $this->assertTrue(
                Schema::hasColumn('provider_price_offers', $columna),
                'Falta la columna provider_price_offers.' . $columna . ' (la migración de la misión no existe todavía).'
            );
        }
    }

    /**
     * Da de alta, por el endpoint real, una compra a Rosario de 3 Taladros a 1300 en pesos.
     *
     * @param  array $compra  overrides del payload de la compra (update_prices, ...)
     * @param  array $pivot   overrides del renglón (update_provider, ...)
     * @return int            id de la compra
     */
    protected function comprar(array $compra, array $pivot)
    {
        $this->set_condicion_iva('RRII');

        return (int) $this->postJson('api/provider-order', $this->payload_compra(array_merge([
            'provider_id' => $this->rosario->id,
            'moneda_id'   => 1,
            'articles'    => [$this->item(self::TALADRO, 1300, 3, $pivot)],
        ], $compra)))->assertStatus(201)->json('model.id');
    }

    /**
     * Oferta de Rosario sobre el Taladro con origen compra.
     *
     * @return \stdClass|null fila cruda (incluye las columnas nuevas)
     */
    protected function oferta_de_rosario()
    {
        return DB::table('provider_price_offers')
            ->where('article_id', $this->taladro->id)
            ->where('provider_id', $this->rosario->id)
            ->where('origen', 'compra')
            ->orderByDesc('id')
            ->first();
    }
}
