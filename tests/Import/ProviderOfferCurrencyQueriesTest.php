<?php

namespace Tests\Import;

use Illuminate\Support\Facades\DB;

/**
 * Misión historial-precios-proveedor, ronda 3 (A2-1): la moneda de la oferta no se consulta fila por fila.
 *
 * Con un Excel SIN columna de moneda, cada fila bloqueada por código de proveedor (sólo se conocen los
 * ids de los artículos, no los modelos) necesitaba la moneda del artículo y la pedía con un SELECT
 * suelto a `articles`: hasta 1.000 por chunk. Regla: a lo sumo UNA consulta de moneda a `articles` por
 * chunk, sin importar cuántas filas bloqueadas haya.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
class ProviderOfferCurrencyQueriesTest extends ProviderOfferImportTestCase
{
    /**
     * La cantidad de consultas de moneda a `articles` no crece con la cantidad de filas bloqueadas, y
     * las monedas guardadas siguen siendo las de cada artículo.
     *
     * @return void
     */
    public function test_blocked_rows_do_not_query_article_currency_per_row()
    {
        $consultas_con_pocas = $this->consultas_de_moneda_importando(3, 'A');
        $consultas_con_muchas = $this->consultas_de_moneda_importando(12, 'B');

        $this->assertLessThanOrEqual(1, $consultas_con_pocas, 'A lo sumo una consulta de moneda por chunk (3 filas).');
        $this->assertLessThanOrEqual(1, $consultas_con_muchas, 'A lo sumo una consulta de moneda por chunk (12 filas).');
        $this->assertLessThanOrEqual($consultas_con_pocas, $consultas_con_muchas, 'La cantidad de consultas no crece con N.');
    }

    /**
     * Importa $cantidad filas bloqueadas por código de proveedor (artículos de BA en dólares, la lista
     * la manda Rosario sin columna de moneda) y devuelve cuántas consultas de moneda a `articles` hubo.
     * También verifica que cada oferta quedó en dólares.
     *
     * @param  int    $cantidad
     * @param  string $prefijo prefijo de los códigos de proveedor (para no chocar entre corridas)
     * @return int
     */
    protected function consultas_de_moneda_importando($cantidad, $prefijo)
    {
        $filas = [];
        $articulos = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $codigo = 'BA-' . $prefijo . $i;

            $articulos[] = $this->crear_articulo([
                'name'            => 'Articulo bloqueado ' . $prefijo . $i,
                'provider_code'   => $codigo,
                'provider_id'     => $this->ba->id,
                'cost'            => 10,
                'cost_in_dollars' => 1,
            ]);

            $articulos[$i - 1]->providers()->attach($this->ba->id, ['provider_code' => $codigo, 'cost' => 10]);

            $filas[] = ['', $codigo, 'Nombre distinto ' . $prefijo . $i, 14];
        }

        $consultas = [];

        DB::listen(function ($query) use (&$consultas) {
            // Consulta de moneda al estilo Article::where('id', ?)->value('cost_in_dollars') (o su equivalente por lote).
            if (stripos($query->sql, 'cost_in_dollars') !== false && stripos($query->sql, 'from `articles`') !== false
                && stripos(ltrim($query->sql), 'select') === 0) {
                $consultas[] = $query->sql;
            }
        });

        $this->importar_por_ia($filas, $this->rosario->id, false, [
            'actualizar_articulos_de_otro_proveedor'             => false,
            'permitir_provider_code_repetido_en_multi_providers' => false,
        ]);

        foreach ($articulos as $articulo) {
            $ofertas = $this->ofertas($articulo->id, $this->rosario->id);

            $this->assertCount(1, $ofertas);
            $this->assertSame(2, (int) $ofertas[0]->moneda_id, 'La moneda de la oferta sigue siendo la del artículo (dólares).');
        }

        return count($consultas);
    }
}
