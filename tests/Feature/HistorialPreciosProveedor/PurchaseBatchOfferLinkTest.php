<?php

namespace Tests\Feature\HistorialPreciosProveedor;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use App\Models\PriceChange;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Compras\ComprasTestCase;

/**
 * Misión historial-precios-proveedor, ronda 2 (REVISION-1-C-api #2 y nota de REVISION-1-B): la oferta
 * de la compra queda vinculada al cambio de precio EXACTO de su artículo.
 *
 *   - Recálculo diferido partido en tandas: RecalculoDePreciosEnLote::recalcular() parte los
 *     artículos en tandas (config app.RECALCULO_PRECIOS_LOTE, 1.000 por defecto) y cada tanda abre
 *     su propio scope del historial, que vacía HistorialPreciosService::last_events(). Antes sólo
 *     quedaba vinculada la última tanda. Con tandas de 2 y 5 artículos que cambian de costo, las
 *     cinco ofertas tienen que quedar usadas y con el id exacto del evento de su artículo.
 *   - Camino sin recálculo diferido (NewProviderOrderHelper::recalcular_por_articulo(true), el de
 *     antes de la misión compras-precios-en-lote): cada setFinalPrice() abre su scope y la oferta se
 *     vincula con el id exacto, sin mezclar artículos.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
class PurchaseBatchOfferLinkTest extends ComprasTestCase
{
    /** Cantidad de artículos de la compra. */
    const CANTIDAD = 5;

    /** @var \App\Models\User Dueño del fixture. */
    protected $owner;

    /** @var \App\Models\Provider Titular de los artículos. */
    protected $ba;

    /** @var \App\Models\Provider Proveedor al que se le compra. */
    protected $rosario;

    /** @var \App\Models\Article[] Artículos de la compra (titular BA, costo 1200, margen 30). */
    protected $articulos = [];

    /**
     * Arma cinco artículos con margen, de BA, con su precio derivado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = auth()->user();
        $this->ba      = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $this->rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $iva_id = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->iva_id;

        for ($i = 1; $i <= self::CANTIDAD; $i++) {
            $articulo = new Article();

            $articulo->user_id         = $this->owner->id;
            $articulo->name            = 'Tanda historial ' . $i;
            $articulo->bar_code        = '88030' . $i;
            $articulo->provider_id     = $this->ba->id;
            $articulo->provider_code   = 'BA-T' . $i;
            $articulo->cost            = 1200;
            $articulo->percentage_gain = 30;
            $articulo->iva_id          = $iva_id;
            $articulo->status          = 'active';
            $articulo->save();

            ArticleHelper::setFinalPrice($articulo->fresh(), $this->owner->id);

            $this->articulos[] = $articulo->fresh();
        }
    }

    /**
     * Deja el interruptor del recálculo como estaba por si un test lo prendió.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        NewProviderOrderHelper::recalcular_por_articulo(false);

        parent::tearDown();
    }

    /**
     * Con tandas de 2 y cinco artículos que cambian de costo (tres tandas), TODAS las ofertas quedan
     * usadas y vinculadas al price_change exacto de su artículo, no sólo las de la última tanda.
     *
     * @return void
     */
    public function test_every_batch_offer_is_linked_to_its_exact_price_change()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $evento_previo = (int) PriceChange::max('id');

        $compra_id = $this->comprar();

        $vistos = [];

        foreach ($this->articulos as $articulo) {
            $this->assertEquals(1300.0, (float) $articulo->fresh()->cost, 'Premisa: con "actualizar precios" el costo pasa a lo pagado.');

            $evento = PriceChange::where('article_id', $articulo->id)
                ->where('id', '>', $evento_previo)
                ->where('origen', 'recalculo_lote')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($evento, 'Cada artículo deja su cambio de precio del recálculo (' . $articulo->name . ').');

            $oferta = $this->oferta_de_rosario($articulo->id);

            $this->assertNotNull($oferta, 'La compra deja la oferta de Rosario (' . $articulo->name . ').');
            $this->assertSame($compra_id, (int) $oferta->referencia_id);
            $this->assertSame(1, (int) $oferta->updated_article_cost, 'El costo se usó (' . $articulo->name . ').');
            $this->assertSame(
                (int) $evento->id,
                (int) $oferta->price_change_id,
                'La oferta de ' . $articulo->name . ' queda vinculada a SU evento, no al de otra tanda ni al de otro artículo.'
            );

            $vistos[(int) $oferta->price_change_id] = true;
        }

        $this->assertCount(self::CANTIDAD, $vistos, 'Cinco artículos, cinco eventos distintos.');
    }

    /**
     * El camino sin recálculo diferido (el de antes: setFinalPrice() por artículo en cada llamada) también
     * vincula cada oferta con el id exacto del evento de su artículo.
     *
     * @return void
     */
    public function test_immediate_path_links_each_offer_to_its_exact_price_change()
    {
        NewProviderOrderHelper::recalcular_por_articulo(true);

        $evento_previo = (int) PriceChange::max('id');

        $this->comprar();

        $vistos = [];

        foreach ($this->articulos as $articulo) {
            $this->assertEquals(1300.0, (float) $articulo->fresh()->cost, 'Premisa: con "actualizar precios" el costo pasa a lo pagado.');

            $evento = PriceChange::where('article_id', $articulo->id)
                ->where('id', '>', $evento_previo)
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($evento, 'El recálculo por artículo deja un cambio de precio (' . $articulo->name . ').');
            $this->assertNotSame('recalculo_lote', $evento->origen, 'Premisa: este camino no pasa por el motor en lote.');

            $oferta = $this->oferta_de_rosario($articulo->id);

            $this->assertNotNull($oferta);
            $this->assertSame(1, (int) $oferta->updated_article_cost);
            $this->assertSame(
                (int) $evento->id,
                (int) $oferta->price_change_id,
                'La oferta de ' . $articulo->name . ' queda vinculada al último evento de SU artículo.'
            );

            $vistos[(int) $oferta->price_change_id] = true;
        }

        $this->assertCount(self::CANTIDAD, $vistos);
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Da de alta, por el endpoint real, una compra a Rosario de los cinco artículos a 1300 en pesos,
     * con "actualizar precios".
     *
     * @return int id de la compra
     */
    protected function comprar()
    {
        $this->set_condicion_iva('RRII');

        $renglones = [];

        foreach ($this->articulos as $articulo) {
            $renglones[] = $this->item($articulo->name, 1300, 1, ['update_provider' => 0]);
        }

        return (int) $this->postJson('api/provider-order', $this->payload_compra([
            'provider_id'   => $this->rosario->id,
            'moneda_id'     => 1,
            'update_prices' => 1,
            'articles'      => $renglones,
        ]))->assertStatus(201)->json('model.id');
    }

    /**
     * Oferta de Rosario sobre un artículo con origen compra.
     *
     * @param  int $article_id
     * @return \stdClass|null fila cruda
     */
    protected function oferta_de_rosario($article_id)
    {
        return DB::table('provider_price_offers')
            ->where('article_id', $article_id)
            ->where('provider_id', $this->rosario->id)
            ->where('origen', 'compra')
            ->orderByDesc('id')
            ->first();
    }
}
