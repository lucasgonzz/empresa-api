<?php

namespace Tests\Feature\Compras\PreciosEnLote;

/**
 * Las consultas del recálculo de precios de una compra NO crecen con los artículos en el camino
 * nuevo, y en el de hoy sí (misión compras-precios-en-lote, 29/9/2026).
 *
 * Se cuenta con DB::listen() sobre la compra entera (POST api/provider-order) con 10 y con 40
 * artículos, y se miran las dos escrituras del cálculo:
 *  - los INSERT a price_changes: hoy uno por cada cambio de precio; con el motor, uno por tanda
 *    (INSERT multi-fila);
 *  - los UPDATE a `articles` del recálculo (los que escriben final_price o costo_real): hoy los
 *    save() de setFinalPrice(), dos por cálculo; con el motor, el UPDATE ... CASE en bloque.
 *
 * El resto de las consultas de la compra (renglones, stock, costo, proveedor, descuentos por
 * artículo, cuenta corriente) sigue creciendo con los artículos en los dos caminos: no es lo que
 * esta misión cambia, y la medición (medicion/ en la carpeta de la misión) dice cuánto pesa.
 *
 * Con bonificaciones y flete, así hoy cada artículo pasa por las tres llamadas que mueven precio.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Consultas_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * @group compras
     * @test
     */
    public function las_escrituras_del_recalculo_no_crecen_con_los_articulos()
    {
        $this->set_condicion_iva('RRII');

        $articulos = [];

        for ($i = 0; $i < 40; $i++) {
            $articulos[] = $this->crear_articulo([
                'cost'            => 100 + $i,
                'percentage_gain' => null,
            ]);
        }

        $medido = [];

        foreach ([10, 40] as $cantidad) {

            $compra = $this->compra_de(array_slice($articulos, 0, $cantidad));

            foreach (['hoy' => true, 'motor' => false] as $camino => $por_articulo) {

                $consultas = $this->consultas_de_la_compra($por_articulo, $compra);

                $medido[$camino][$cantidad] = [
                    'inserts_price_changes' => $this->inserts_de_cambios_de_precio($consultas),
                    'updates_de_precios'    => $this->updates_de_precios_de_articulos($consultas),
                    'consultas'             => count($consultas),
                ];
            }
        }

        $detalle = ' Medido: ' . json_encode($medido);

        /* Referencia: hoy crecen con los artículos (al menos una escritura por artículo). */
        $this->assertGreaterThanOrEqual(10, $medido['hoy'][10]['inserts_price_changes'], 'Referencia: hoy, al menos un INSERT a price_changes por artículo.' . $detalle);
        $this->assertGreaterThan($medido['hoy'][10]['inserts_price_changes'], $medido['hoy'][40]['inserts_price_changes'], 'Referencia: hoy los INSERT a price_changes crecen con los artículos.' . $detalle);
        $this->assertGreaterThan($medido['hoy'][10]['updates_de_precios'], $medido['hoy'][40]['updates_de_precios'], 'Referencia: hoy los UPDATE de precios de articles crecen con los artículos.' . $detalle);

        /* Camino nuevo: constantes. */
        $this->assertGreaterThanOrEqual(1, $medido['motor'][10]['inserts_price_changes'], 'Guarda: el motor tenía que grabar los cambios de precio.' . $detalle);
        $this->assertGreaterThanOrEqual(1, $medido['motor'][10]['updates_de_precios'], 'Guarda: el motor tenía que escribir los precios.' . $detalle);

        $this->assertSame($medido['motor'][10]['inserts_price_changes'], $medido['motor'][40]['inserts_price_changes'], 'Con el motor, los INSERT a price_changes no crecen con los artículos.' . $detalle);
        $this->assertSame($medido['motor'][10]['updates_de_precios'], $medido['motor'][40]['updates_de_precios'], 'Con el motor, los UPDATE de precios de articles no crecen con los artículos.' . $detalle);

        $archivo = getenv('PRECIOS_EN_LOTE_RESUMEN');

        if ($archivo !== false && $archivo !== '') {
            file_put_contents($archivo, json_encode(['escenario' => 'consultas', 'medido' => $medido]) . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * Una compra con bonificaciones (las de Buenos Aires, precargadas), un flete y costo nuevo en
     * todos los renglones.
     *
     * @param  \App\Models\Article[] $articulos
     * @return callable
     */
    protected function compra_de(array $articulos)
    {
        return function () use ($articulos) {

            $renglones = [];

            foreach ($articulos as $article) {
                $renglones[] = $this->renglon($article, (float) $article->cost + 7, 3);
            }

            $flete = $this->costo_extra_del_alta(['value' => 2500]);

            $compra_id = $this->alta($this->payload_compra([
                'childrens' => [$flete],
                'articles'  => $renglones,
            ]));

            return [
                'articulos' => [],
                'compra_id' => $compra_id,
            ];
        };
    }
}
