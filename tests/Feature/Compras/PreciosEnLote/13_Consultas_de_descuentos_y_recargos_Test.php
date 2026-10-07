<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Las consultas de las dos pasadas que la misión compras-precios-en-lote pasó a bloque (§4.4 del
 * plan, 29/9/2026) NO crecen con los artículos en modo diferido, y los artículos que anotan para
 * el recálculo son los mismos que anotaba la pasada por artículo.
 *
 * CÓMO SE AÍSLAN. 10_Consultas_Test cuenta la compra entera por el endpoint, donde estas dos pasadas
 * quedan mezcladas con lo que sigue siendo por artículo (renglones, stock, costo). Acá se llaman las
 * dos pasadas DIRECTO sobre una compra real y se cuentan solo sus consultas:
 *
 *  1. La compra se da de alta por el endpoint con update_prices = 0, así las dos pasadas no corren
 *     adentro del alta y encuentran la base como en una primera confirmación: sin los descuentos ni
 *     los recargos de esta compra.
 *  2. Se arma un NewProviderOrderHelper sobre esa compra con update_prices = 1 en memoria (el gate
 *     de las dos pasadas) y se corre set_totales(), que carga las relaciones y el sub_total igual que
 *     en procesar_pedido().
 *  3. Recién ahí se empiezan a contar las consultas, y se llama a
 *     materializar_descuentos_proveedor_en_articulos() y aplicar_costos_extra_a_recargos_articulos().
 *
 * Todo adentro de un savepoint que se revierte. Con el interruptor prendido ("hoy") las pasadas son
 * las de siempre, con su recálculo por artículo adentro: la referencia de que crecen.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Consultas_de_descuentos_y_recargos_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * Con 10 y con 40 artículos, las dos pasadas en modo diferido hacen las MISMAS consultas: una de
     * existencia, el DELETE y el INSERT de los descuentos; otra de existencia, la de los recargos
     * existentes, el INSERT de los nuevos y el UPDATE de los que cambian. Hoy crecen.
     *
     * La mitad de los artículos ya tiene un recargo de transporte con otro monto (UPDATE) y la otra
     * mitad no (INSERT); un tercio tiene un descuento tagueado de otro proveedor (DELETE). Las
     * bonificaciones de Buenos Aires se precargan en el alta: dos descuentos por artículo (INSERT).
     *
     * @group compras
     * @test
     */
    public function las_dos_pasadas_no_crecen_con_los_articulos_en_modo_diferido()
    {
        $this->set_condicion_iva('RRII');

        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $articulos = [];

        for ($i = 0; $i < 40; $i++) {

            $article = $this->crear_articulo([
                'cost'            => 100 + $i,
                'percentage_gain' => null,
            ]);

            if ($i % 2 === 0) {
                $this->sembrar_recargo($article, ['amount' => 1]);
            }

            if ($i % 3 === 0) {
                $this->sembrar_descuento($article, ['provider_id' => $rosario->id, 'percentage' => 3, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);
            }

            $articulos[] = $article;
        }

        $medido = [];

        foreach ([10, 40] as $cantidad) {

            $de_la_compra = array_slice($articulos, 0, $cantidad);

            foreach (['hoy' => true, 'motor' => false] as $camino => $por_articulo) {

                $medido[$camino][$cantidad] = $this->medir_las_dos_pasadas($por_articulo, $de_la_compra, function ($helper) {
                    $helper->materializar_descuentos_proveedor_en_articulos();
                    $helper->aplicar_costos_extra_a_recargos_articulos();
                });

                $medido[$camino][$cantidad]['esperados'] = $this->ids_ordenados(array_map(function ($article) {
                    return $article->id;
                }, $de_la_compra));
            }
        }

        $resumen = [];

        foreach ($medido as $camino => $por_cantidad) {
            foreach ($por_cantidad as $cantidad => $datos) {
                $resumen[$camino][$cantidad] = [
                    'consultas' => $datos['consultas'],
                    'por_tipo'  => $datos['por_tipo'],
                ];
            }
        }

        $detalle = ' Medido: ' . json_encode($resumen);

        /* Referencia: hoy las dos pasadas crecen con los artículos. */
        $this->assertGreaterThanOrEqual(7 * 10, $medido['hoy'][10]['consultas'], 'Referencia: hoy, al menos siete consultas por artículo (tres de descuentos y cuatro de recargos).' . $detalle);
        $this->assertGreaterThan($medido['hoy'][10]['consultas'], $medido['hoy'][40]['consultas'], 'Referencia: hoy las dos pasadas crecen con los artículos.' . $detalle);

        /* Camino nuevo: las mismas consultas con 10 que con 40. */
        $this->assertSame($medido['motor'][10]['consultas'], $medido['motor'][40]['consultas'], 'Con el recálculo diferido, las dos pasadas no pueden crecer con los artículos.' . $detalle);
        $this->assertSame($medido['motor'][10]['por_tipo'], $medido['motor'][40]['por_tipo'], 'Con el recálculo diferido, las dos pasadas tienen que mandar las mismas sentencias con 10 que con 40 artículos.' . $detalle);

        /* Guarda: las escrituras en bloque existieron (si no, "constante" no prueba nada). */
        foreach (['delete article_discounts', 'insert article_discounts', 'insert article_surchages', 'update article_surchages'] as $sentencia) {
            $this->assertSame(1, isset($medido['motor'][40]['por_tipo'][$sentencia]) ? $medido['motor'][40]['por_tipo'][$sentencia] : 0, 'Guarda: con el recálculo diferido tenía que haber exactamente un "' . $sentencia . '".' . $detalle);
        }

        /* Los artículos anotados para el recálculo: todos los de la compra, como la pasada por artículo. */
        foreach ([10, 40] as $cantidad) {
            $this->assertSame($medido['motor'][$cantidad]['esperados'], $medido['motor'][$cantidad]['pendientes'], 'Con ' . $cantidad . ' artículos, el recálculo diferido tenía que quedar anotado para todos los artículos de la compra.');
            $this->assertSame([], $medido['hoy'][$cantidad]['pendientes'], 'Referencia: con el interruptor prendido el precio se calcula en el momento y no se anota nada.');
        }

        $archivo = getenv('PRECIOS_EN_LOTE_RESUMEN');

        if ($archivo !== false && $archivo !== '') {
            file_put_contents($archivo, json_encode(['escenario' => 'consultas de las dos pasadas', 'medido' => $resumen]) . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * Cada pasada, sola, anota para el recálculo los mismos artículos que su versión por artículo:
     *  - la de descuentos, todos los que existen (el borrado no, porque Article::find() da null);
     *  - la de recargos, los que existen y pasan los saltos (el renglón con cantidad recibida 0 no).
     *
     * @group compras
     * @test
     */
    public function cada_pasada_anota_los_mismos_articulos_que_la_pasada_por_articulo()
    {
        $this->set_condicion_iva('RRII');

        $uno      = $this->crear_articulo(['cost' => 110]);
        $dos      = $this->crear_articulo(['cost' => 220]);
        $no_llego = $this->crear_articulo(['cost' => 330]);
        $borrado  = $this->crear_articulo(['cost' => 440]);

        $renglones = [
            $this->renglon($uno, 120, 2),
            $this->renglon($dos, 230, 3),
            $this->renglon($no_llego, 340, 4, ['received' => 0]),
            $this->renglon($borrado, 450, 5),
        ];

        Article::find($borrado->id)->delete();

        $solo_descuentos = $this->medir_las_dos_pasadas(false, [], function ($helper) {
            $helper->materializar_descuentos_proveedor_en_articulos();
        }, $renglones);

        $solo_recargos = $this->medir_las_dos_pasadas(false, [], function ($helper) {
            $helper->aplicar_costos_extra_a_recargos_articulos();
        }, $renglones);

        $this->assertSame(
            $this->ids_ordenados([$uno->id, $dos->id, $no_llego->id]),
            $solo_descuentos['pendientes'],
            'La pasada de descuentos anota todos los artículos que existen (como el Article::find() + recalcular_precio() de cada uno).'
        );

        $this->assertSame(
            $this->ids_ordenados([$uno->id, $dos->id]),
            $solo_recargos['pendientes'],
            'La pasada de recargos anota solo los que existen y pasan los saltos de subtotal y cantidad.'
        );
    }

    /**
     * Da de alta la compra (sin actualizar precios), arma el helper con update_prices en memoria,
     * corre set_totales() y cuenta SOLO las consultas de $pasadas($helper), en un savepoint que se
     * revierte. Devuelve cuántas fueron, cuántas de cada tipo (verbo + tabla) y los artículos que
     * quedaron anotados para el recálculo diferido.
     *
     * @param  bool     $por_articulo  El interruptor: true = las pasadas de siempre.
     * @param  array    $articulos     Artículos de la compra (un renglón de costo + 7 y cantidad 3 cada
     *                                 uno), si no vienen $renglones.
     * @param  callable $pasadas       function (NewProviderOrderHelper $helper): lo que se cuenta.
     * @param  array    $renglones     Renglones ya armados (pisa $articulos).
     * @return array ['consultas' => int, 'por_tipo' => array, 'pendientes' => int[]]
     */
    protected function medir_las_dos_pasadas($por_articulo, array $articulos, callable $pasadas, array $renglones = [])
    {
        if (!$this->escucha_registrada) {

            DB::listen(function ($consulta) {
                if (is_array($this->consultas_anotadas)) {
                    $this->consultas_anotadas[] = $consulta->sql;
                }
            });

            $this->escucha_registrada = true;
        }

        if (count($renglones) === 0) {
            foreach ($articulos as $article) {
                $renglones[] = $this->renglon($article, (float) $article->cost + 7, 3);
            }
        }

        Carbon::setTestNow(self::AHORA);

        $this->limpiar_estado_del_proceso();

        NewProviderOrderHelper::recalcular_por_articulo($por_articulo);

        $nivel = DB::transactionLevel();

        DB::beginTransaction();

        try {

            $compra_id = $this->alta($this->payload_compra([
                'update_prices' => 0,
                'childrens'     => [$this->costo_extra_del_alta(['value' => 2500])],
                'articles'      => $renglones,
            ]));

            $provider_order = ProviderOrder::find($compra_id);

            $provider_order->update_prices = 1;

            $helper = new NewProviderOrderHelper($provider_order, []);

            $helper->set_totales();

            $this->consultas_anotadas = [];

            $pasadas($helper);

            $consultas = $this->consultas_anotadas;

            $this->consultas_anotadas = null;

            $propiedad = new \ReflectionProperty(NewProviderOrderHelper::class, 'articulos_con_precio_pendiente');
            $propiedad->setAccessible(true);

            $pendientes = $this->ids_ordenados(array_values($propiedad->getValue($helper)));

        } finally {

            $this->consultas_anotadas = null;

            while (DB::transactionLevel() > $nivel) {
                DB::rollBack();
            }

            $this->limpiar_estado_del_proceso();

            Carbon::setTestNow();
        }

        return [
            'consultas'  => count($consultas),
            'por_tipo'   => $this->sentencias_por_tipo($consultas),
            'pendientes' => $pendientes,
        ];
    }

    /**
     * Cuenta las sentencias por verbo y tabla ("select articles", "insert article_surchages", ...),
     * ordenado por clave: así la comparación entre 10 y 40 artículos no depende del largo de los IN.
     *
     * @param  string[] $consultas
     * @return array
     */
    protected function sentencias_por_tipo(array $consultas)
    {
        $por_tipo = [];

        foreach ($consultas as $sql) {

            $clave = 'otra';

            if (preg_match('/^\s*(select)\s.*?\sfrom\s+`?([a-z_]+)`?/is', $sql, $partes)) {
                $clave = strtolower($partes[1]) . ' ' . $partes[2];
            } elseif (preg_match('/^\s*(insert)\s+into\s+`?([a-z_]+)`?/is', $sql, $partes)) {
                $clave = strtolower($partes[1]) . ' ' . $partes[2];
            } elseif (preg_match('/^\s*(update)\s+`?([a-z_]+)`?/is', $sql, $partes)) {
                $clave = strtolower($partes[1]) . ' ' . $partes[2];
            } elseif (preg_match('/^\s*(delete)\s+from\s+`?([a-z_]+)`?/is', $sql, $partes)) {
                $clave = strtolower($partes[1]) . ' ' . $partes[2];
            }

            if (!isset($por_tipo[$clave])) {
                $por_tipo[$clave] = 0;
            }

            $por_tipo[$clave]++;
        }

        ksort($por_tipo);

        return $por_tipo;
    }
}
