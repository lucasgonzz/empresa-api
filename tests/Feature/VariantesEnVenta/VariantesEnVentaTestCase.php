<?php

namespace Tests\Feature\VariantesEnVenta;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ArticleVariant;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Base de los tests de "el mismo artículo con variantes distintas en una venta" (misión
 * variantes-mismo-articulo-en-vender, 8/10/2026).
 *
 * El escenario de todos los tests es UN artículo con tres variantes (M, L y S) que reparten su stock
 * por DOS sucursales, más un artículo testigo sin variantes con stock global:
 *
 *              suc1   suc2   total
 *      M         6      4      10
 *      L         5      5      10
 *      S         3      2       5
 *   artículo    14     11      25     (el pivot del artículo es la suma de sus variantes)
 *   testigo     — stock global 10 —
 *
 * Lo que se mide siempre en la base (`DB::table()`), nunca en el modelo en memoria:
 * `address_article_variant` (el depósito de cada variante), `article_variants.stock`,
 * `address_article` y `articles.stock` del artículo, y los renglones de `article_sale`.
 *
 * Hereda los helpers de la auditoría de stock (`payload_venta`, `crear_venta`, `actualizar_venta`,
 * que mandan la venta con la forma real de Vender y la sucursal 1 del fixture).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class VariantesEnVentaTestCase extends AuditoriaStockTestCase
{
    /** Precio de lista de los renglones del artículo con variantes. */
    const PRECIO = 300;

    /**
     * Arma el escenario del docblock por el camino real: las filas de depósito de cada variante y
     * después `setArticleStockFromAddresses()`, que reconstruye el pivot del artículo.
     *
     * @return array  articulo, m, l, s, testigo, suc1, suc2.
     */
    protected function escenario()
    {
        $user = $this->usuario();

        $suc1 = $this->sucursal();
        $suc2 = $this->segunda_sucursal();

        $articulo = $this->crear_articulo('zz Remera variantes '.uniqid(), ['stock' => 25]);

        $m = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle M', 'stock' => 10]);
        $l = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle L', 'stock' => 10]);
        $s = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle S', 'stock' => 5]);

        DB::table('address_article_variant')->insert([
            ['article_variant_id' => $m->id, 'address_id' => $suc1->id, 'amount' => 6],
            ['article_variant_id' => $m->id, 'address_id' => $suc2->id, 'amount' => 4],
            ['article_variant_id' => $l->id, 'address_id' => $suc1->id, 'amount' => 5],
            ['article_variant_id' => $l->id, 'address_id' => $suc2->id, 'amount' => 5],
            ['article_variant_id' => $s->id, 'address_id' => $suc1->id, 'amount' => 3],
            ['article_variant_id' => $s->id, 'address_id' => $suc2->id, 'amount' => 2],
        ]);

        ArticleHelper::setArticleStockFromAddresses($articulo->fresh(), false, $user->id);

        $testigo = $this->crear_articulo('zz Testigo variantes '.uniqid(), ['stock' => 10]);

        $e = compact('articulo', 'm', 'l', 's', 'testigo', 'suc1', 'suc2');

        // El escenario quedó como dice el docblock (si no, ningún número de abajo significa nada).
        $this->assert_variante($e, 'm', 6, 4, 'Escenario');
        $this->assert_variante($e, 'l', 5, 5, 'Escenario');
        $this->assert_variante($e, 's', 3, 2, 'Escenario');
        $this->assertEquals(25.0, $this->stock($articulo), 'Escenario: el artículo tiene que sumar sus variantes.');
        $this->assertEquals(10.0, $this->stock($testigo), 'Escenario: el testigo arranca con 10 de stock global.');

        return $e;
    }

    /**
     * Un renglón de Vender de una variante del artículo.
     *
     * @param  array                       $e
     * @param  \App\Models\ArticleVariant  $variante
     * @param  float                       $amount
     * @param  float                       $price
     * @return array
     */
    protected function renglon($e, $variante, $amount, $price = self::PRECIO)
    {
        return [
            'article'             => $e['articulo'],
            'amount'              => $amount,
            'price'               => $price,
            'article_variant_id'  => $variante->id,
            'variant_description' => $variante->variant_description,
            // Vender manda el costo del artículo: sin él la fila queda sin costo y sin ganancia.
            'cost'                => (float) $e['articulo']->cost,
        ];
    }

    /**
     * El renglón del artículo testigo (sin variante).
     *
     * @param  array  $e
     * @param  float  $amount
     * @return array
     */
    protected function renglon_testigo($e, $amount = 1)
    {
        return ['article' => $e['testigo'], 'amount' => $amount, 'price' => 100, 'cost' => (float) $e['testigo']->cost];
    }

    /**
     * Stock de una variante en una sucursal (null si no hay fila).
     *
     * @param  \App\Models\ArticleVariant  $variante
     * @param  int                         $address_id
     * @return float|null
     */
    protected function deposito($variante, $address_id)
    {
        $fila = DB::table('address_article_variant')
                    ->where('article_variant_id', $variante->id)
                    ->where('address_id', $address_id)
                    ->first();

        return is_null($fila) || is_null($fila->amount) ? null : (float) $fila->amount;
    }

    /**
     * @param  \App\Models\ArticleVariant  $variante
     * @return float
     */
    protected function stock_variante($variante)
    {
        return (float) DB::table('article_variants')->where('id', $variante->id)->value('stock');
    }

    /**
     * Afirma el depósito de una variante en las dos sucursales, su stock global (la suma) y que el
     * artículo siga siendo la suma de sus variantes, por sucursal y en total.
     *
     * @param  array   $e
     * @param  string  $clave    'm' | 'l' | 's'
     * @param  float   $en_suc1
     * @param  float   $en_suc2
     * @param  string  $paso
     * @return void
     */
    protected function assert_variante($e, $clave, $en_suc1, $en_suc2, $paso)
    {
        $variante = $e[$clave];
        $nombre = $variante->variant_description;

        $this->assertEquals((float) $en_suc1, $this->deposito($variante, $e['suc1']->id), $paso.': '.$nombre.' en la sucursal 1.');
        $this->assertEquals((float) $en_suc2, $this->deposito($variante, $e['suc2']->id), $paso.': '.$nombre.' en la sucursal 2.');
        $this->assertEquals((float) ($en_suc1 + $en_suc2), $this->stock_variante($variante), $paso.': el stock global de '.$nombre.' es la suma de sus depósitos.');

        $this->assert_articulo_coherente($e, $paso);
    }

    /**
     * El pivot del artículo por sucursal y `articles.stock` son la suma de las variantes.
     *
     * @param  array   $e
     * @param  string  $paso
     * @return void
     */
    protected function assert_articulo_coherente($e, $paso)
    {
        $suma_suc1 = 0;
        $suma_suc2 = 0;
        $suma = 0;

        foreach (['m', 'l', 's'] as $clave) {
            $suma_suc1 += (float) $this->deposito($e[$clave], $e['suc1']->id);
            $suma_suc2 += (float) $this->deposito($e[$clave], $e['suc2']->id);
            $suma += $this->stock_variante($e[$clave]);
        }

        $this->assertEquals($suma_suc1, (float) $this->stock_en_deposito($e['articulo'], $e['suc1']->id), $paso.': el artículo en la sucursal 1 es la suma de sus variantes ahí.');
        $this->assertEquals($suma_suc2, (float) $this->stock_en_deposito($e['articulo'], $e['suc2']->id), $paso.': el artículo en la sucursal 2 es la suma de sus variantes ahí.');
        $this->assertEquals($suma, $this->stock($e['articulo']), $paso.': articles.stock es la suma de las variantes.');
    }

    /**
     * Los renglones de la venta para el artículo con variantes, en orden de id, como arreglos.
     *
     * @param  \App\Models\Sale  $venta
     * @param  \App\Models\Article  $articulo
     * @return array
     */
    protected function filas($venta, $articulo)
    {
        return DB::table('article_sale')
                    ->where('sale_id', $venta->id)
                    ->where('article_id', $articulo->id)
                    ->orderBy('id')
                    ->get()
                    ->all();
    }

    /**
     * La fila de la venta de una variante (null = sin variante). Falla si hay más de una.
     *
     * @param  \App\Models\Sale  $venta
     * @param  \App\Models\Article  $articulo
     * @param  \App\Models\ArticleVariant|null  $variante
     * @return object|null
     */
    protected function fila_de($venta, $articulo, $variante)
    {
        $query = DB::table('article_sale')
                    ->where('sale_id', $venta->id)
                    ->where('article_id', $articulo->id);

        if (is_null($variante)) {
            $query->where(function ($q) {
                $q->whereNull('article_variant_id')->orWhere('article_variant_id', 0);
            });
        } else {
            $query->where('article_variant_id', $variante->id);
        }

        $filas = $query->get();

        $this->assertLessThanOrEqual(1, $filas->count(), 'Hay más de una fila de la misma variante en la venta.');

        return $filas->first();
    }

    /**
     * Cuántos movimientos de stock tiene la venta.
     *
     * @param  \App\Models\Sale  $venta
     * @return int
     */
    protected function cantidad_de_movimientos($venta)
    {
        return DB::table('stock_movements')->where('sale_id', $venta->id)->count();
    }

    /**
     * Un ítem de Devoluciones con la forma con la que lo arma la SPA (`store/devoluciones.js`,
     * `format_items`): el artículo entero, el pivot de su fila, el precio, la cantidad, la variante y
     * lo ya devuelto; más lo que se devuelve ahora.
     *
     * @param  object  $fila      La fila de `article_sale`.
     * @param  float   $devolver  Unidades que se devuelven ahora.
     * @return array
     */
    protected function item_devolucion($fila, $devolver)
    {
        $articulo = DB::table('articles')->where('id', $fila->article_id)->first();

        $ya = (float) $fila->returned_amount;

        return [
            'is_article'         => true,
            'id'                 => (int) $fila->article_id,
            'name'               => $articulo->name,
            'stock'              => $articulo->stock,
            'costo_real'         => $articulo->costo_real,
            'pivot'              => (array) $fila,
            'price_vender'       => (float) $fila->price,
            'amount'             => (float) $fila->amount,
            'article_variant_id' => $fila->article_variant_id,
            'discount'           => $fila->discount,
            'ya_devueltas'       => $ya,
            'returned_amount'    => $ya + $devolver,
            'unidades_devueltas' => $devolver,
        ];
    }

    /**
     * POST api/devoluciones de una venta, a la cuenta corriente del cliente.
     *
     * @param  \App\Models\Sale  $venta
     * @param  array             $items
     * @param  int|null          $address_id
     * @param  array             $extra
     * @return \App\Models\CurrentAcount  La NC creada.
     */
    protected function devolver($venta, $items, $address_id, $extra = [])
    {
        $total = 0;

        foreach ($items as $item) {
            $total += (float) $item['price_vender'] * (float) $item['unidades_devueltas'];
        }

        $this->postJson('api/devoluciones', array_merge([
            'tipo'                      => 'venta',
            'sale_id'                   => $venta->id,
            'client_id'                 => $venta->client_id,
            'address_id'                => $address_id,
            'generar_current_acount'    => true,
            'total_devolucion'          => $total,
            'observaciones'             => 'Devolución de variantes',
            'items'                     => $items,
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => true,
            'update_unidades_devueltas' => true,
            'facturar_nota_credito'     => null,
        ], $extra))->assertStatus(201);

        $nota_credito = CurrentAcount::where('sale_id', $venta->id)
                                    ->where('status', 'nota_credito')
                                    ->orderBy('id', 'DESC')
                                    ->first();

        $this->assertNotNull($nota_credito, 'La devolución no dejó la nota de crédito.');

        return $nota_credito;
    }
}
