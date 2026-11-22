<?php

namespace Tests\Import;

use App\Models\Article;

/**
 * Misión historial-precios-proveedor, ronda 2 (REVISION-1-A #2 y C-api #1): la moneda de la oferta
 * que deja la importación.
 *
 * Regla: la de la fila si el Excel mapea la columna "moneda"; si no, la del artículo
 * (`articles.cost_in_dollars`), como ya hace ProcessRow al convertir el precio de la lista. Antes la
 * oferta salía de `$data['cost_in_dollars']`, que no existe si el Excel no mapea "moneda": un artículo
 * en dólares quedaba con una oferta en pesos (moneda_id 1) y competía mal en mejores_ofertas_para().
 *
 * Los tres puntos que arman ofertas se cubren acá: la fila del propio proveedor / otro proveedor
 * actualizado (buffer_row_offer), la fila salteada de otro proveedor (registrar_oferta_de_otro_proveedor
 * con el modelo a mano) y la fila bloqueada por código de proveedor (sólo ids). El alta de artículos
 * (ofertas_de_articulos_creados) toma la moneda del artículo recién creado.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
class ProviderOfferCurrencyTest extends ProviderOfferImportTestCase
{
    /**
     * Artículo de BA en dólares, Excel de BA SIN columna de moneda: la oferta queda en dólares
     * (moneda_id 2), no en pesos.
     *
     * @return void
     */
    public function test_own_provider_offer_without_currency_column_uses_article_currency()
    {
        $articulo = $this->articulo_de_ba_en_dolares('8800101', 'BA-101');

        $this->importar_por_ia([['8800101', 'BA-101', 'Taladro en dolares', 12]], $this->ba->id, false);

        $this->assertDecimal(12, $articulo->fresh()->cost, 'El costo se actualiza.');
        $this->assertSame(1, (int) $articulo->fresh()->cost_in_dollars, 'Sin columna de moneda el artículo sigue en dólares.');

        $ofertas = $this->ofertas($articulo->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(2, (int) $ofertas[0]->moneda_id, 'La oferta de un artículo en dólares sin columna de moneda va en dólares.');
        $this->assertSame(1, (int) $ofertas[0]->updated_article_cost);
    }

    /**
     * Mismo artículo, pero el Excel mapea la columna de moneda y dice pesos: manda la fila.
     *
     * @return void
     */
    public function test_explicit_currency_column_in_pesos_wins_over_article_currency()
    {
        $articulo = $this->articulo_de_ba_en_dolares('8800102', 'BA-102');

        $this->importar_por_ia([['8800102', 'BA-102', 'Taladro en dolares', 15000, 'pesos']], $this->ba->id, true);

        $ofertas = $this->ofertas($articulo->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(1, (int) $ofertas[0]->moneda_id, 'Con la columna de moneda en pesos la oferta va en pesos.');
    }

    /**
     * Y con la columna en dólares, la oferta va en dólares aunque el artículo estuviera en pesos.
     *
     * @return void
     */
    public function test_explicit_currency_column_in_dollars_wins_over_peso_article()
    {
        $articulo = $this->crear_articulo([
            'name'          => 'Taladro en pesos',
            'bar_code'      => '8800103',
            'provider_code' => 'BA-103',
            'provider_id'   => $this->ba->id,
            'cost'          => 1000,
        ]);

        $articulo->providers()->attach($this->ba->id, ['provider_code' => 'BA-103', 'cost' => 1000]);

        $this->importar_por_ia([['8800103', 'BA-103', 'Taladro en pesos', 11, 'USD']], $this->ba->id, true);

        $ofertas = $this->ofertas($articulo->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(2, (int) $ofertas[0]->moneda_id);
    }

    /**
     * Fila de otro proveedor salteada (bandera apagada) sobre un artículo en dólares, Excel sin
     * columna de moneda: la oferta de Rosario queda en dólares (se toma del modelo ya cargado).
     *
     * @return void
     */
    public function test_skipped_other_provider_row_without_currency_column_uses_article_currency()
    {
        $articulo = $this->articulo_de_ba_en_dolares('8800104', 'BA-104');

        $this->importar_por_ia([['8800104', 'R-104', 'Taladro en dolares', 13]], $this->rosario->id, false, [
            'actualizar_articulos_de_otro_proveedor' => false,
        ]);

        $this->assertDecimal(10, $articulo->fresh()->cost, 'Con la bandera apagada el artículo de BA no cambia.');

        $ofertas = $this->ofertas($articulo->id, $this->rosario->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(0, (int) $ofertas[0]->updated_article_cost);
        $this->assertSame(2, (int) $ofertas[0]->moneda_id, 'La oferta de otro proveedor sobre un artículo en dólares va en dólares.');
    }

    /**
     * Fila bloqueada por código de proveedor en otro proveedor (sólo se conocen los ids): sin columna
     * de moneda, la oferta toma la moneda del artículo.
     *
     * @return void
     */
    public function test_blocked_by_provider_code_row_without_currency_column_uses_article_currency()
    {
        $articulo = $this->articulo_de_ba_en_dolares('', 'BA-105');

        // Sin barras ni nombre en común: sólo coincide el código de proveedor, y no se permite
        // repetir códigos entre proveedores ni actualizar artículos de otro proveedor.
        $this->importar_por_ia([['', 'BA-105', 'Otra cosa distinta', 14]], $this->rosario->id, false, [
            'actualizar_articulos_de_otro_proveedor'             => false,
            'permitir_provider_code_repetido_en_multi_providers' => false,
        ]);

        $this->assertDecimal(10, $articulo->fresh()->cost, 'El artículo de BA no cambia.');

        $ofertas = $this->ofertas($articulo->id, $this->rosario->id);

        $this->assertCount(1, $ofertas, 'La fila bloqueada deja igual la oferta de Rosario.');
        $this->assertSame(2, (int) $ofertas[0]->moneda_id);
    }

    /**
     * Artículo creado por la importación con la columna de moneda en dólares: su oferta nace en dólares.
     *
     * @return void
     */
    public function test_created_article_offer_takes_the_currency_of_the_row()
    {
        $this->importar_por_ia([['8800106', 'BA-106', 'Articulo nuevo en dolares', 9, 'USD']], $this->ba->id, true);

        $creado = Article::where('user_id', $this->tenant->id)->where('bar_code', '8800106')->first();

        $this->assertNotNull($creado, 'La importación crea el artículo.');

        $ofertas = $this->ofertas($creado->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(2, (int) $ofertas[0]->moneda_id);
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Artículo de BA con costo 10 en dólares.
     *
     * @param  string $bar_code
     * @param  string $provider_code
     * @return \App\Models\Article
     */
    protected function articulo_de_ba_en_dolares($bar_code, $provider_code)
    {
        $articulo = $this->crear_articulo([
            'name'            => 'Taladro en dolares',
            'bar_code'        => $bar_code === '' ? null : $bar_code,
            'provider_code'   => $provider_code,
            'provider_id'     => $this->ba->id,
            'cost'            => 10,
            'cost_in_dollars' => 1,
        ]);

        $articulo->providers()->attach($this->ba->id, ['provider_code' => $provider_code, 'cost' => 10]);

        return $articulo;
    }
}
