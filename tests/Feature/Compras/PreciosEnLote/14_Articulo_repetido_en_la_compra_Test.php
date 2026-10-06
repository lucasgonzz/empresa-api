<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\ArticleDiscount;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Un artículo que aparece DOS veces en la misma compra (misión compras-precios-en-lote, §4.4 del
 * plan, 29/9/2026).
 *
 * article_provider_order no tiene índice único por (compra, artículo), así que un dato viejo puede
 * traer el mismo artículo en dos renglones, y la relación de la compra lo devuelve dos veces. La
 * pasada por artículo lo procesa dos veces: la de descuentos borra y vuelve a crear (quedan los de
 * la última pasada), y la de recargos vuelve a leer la fila y decide contra lo que dejó la primera.
 * Las pasadas en bloque lo reproducen sin volver a la base (el repetido en su última posición para
 * los descuentos; el mismo modelo con el original sincronizado para los recargos).
 *
 * Por el endpoint no se puede armar (el alta sincroniza los renglones por id de artículo), así que
 * se siembra el renglón repetido directo en la tabla y se corren las dos pasadas directo, como en
 * 13_Consultas_de_descuentos_y_recargos_Test: alta sin actualizar precios, helper con update_prices
 * en memoria, set_totales() y las dos pasadas, por los dos caminos, cada uno en un savepoint. Se
 * compara la secuencia de filas de article_discounts y article_surchages en orden de id, con los
 * sellos (reloj congelado) y los float a 17 dígitos.
 *
 * El flete es de $3.100, igual a la base del prorrateo: cada renglón se lleva su subtotal, así que
 * el monto unitario de cada pasada es el costo del renglón.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Articulo_repetido_en_la_compra_Test extends ComprasPreciosEnLoteTestCase
{
    /** Columnas de article_surchages que se comparan (todas menos el id). */
    const COLUMNAS_RECARGOS = [
        'article_id', 'percentage', 'amount', 'tipo', 'luego_del_precio_final', 'temporal_id',
        'show_in_online', 'created_at', 'updated_at',
    ];

    /** Columnas de article_discounts que se comparan (todas menos el id). */
    const COLUMNAS_DESCUENTOS = [
        'article_id', 'provider_id', 'percentage', 'amount', 'tipo', 'temporal_id', 'show_in_online',
        'created_at', 'updated_at', 'editado_a_mano', 'origen', 'provider_discount_id', 'nombre',
    ];

    /**
     * Cuatro artículos repetidos, cada uno con un caso distinto del recargo de transporte (la
     * primera pasada con el costo de $100, la segunda con otro), y uno sin repetir:
     *  - x: tenía $70; las dos pasadas dan $100 → se escribe en la primera, la segunda no cambia nada;
     *  - w: tenía $200; la primera da $100 y la segunda vuelve a $200 → queda $200, pero TOCADO (hoy
     *    la segunda pasada compara contra los $100 que escribió la primera);
     *  - q: tenía $100; la primera no cambia nada y la segunda da $300 → se escribe en la segunda;
     *  - z: no tenía; la primera lo crea con $100 y la segunda lo pasa a $50;
     *  - y: no está repetido y no tenía → se crea con $100.
     * Con las bonificaciones de Buenos Aires precargadas: los repetidos también pasan dos veces por
     * la de descuentos, y x tenía un tagueado de otro proveedor que se barre.
     *
     * @group compras
     * @test
     */
    public function un_articulo_repetido_deja_lo_mismo_que_la_pasada_por_articulo()
    {
        $this->set_condicion_iva('RRII');

        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $x = $this->crear_articulo(['cost' => 90]);
        $w = $this->crear_articulo(['cost' => 90]);
        $q = $this->crear_articulo(['cost' => 90]);
        $z = $this->crear_articulo(['cost' => 90]);
        $y = $this->crear_articulo(['cost' => 90]);

        $this->sembrar_recargo($x, ['amount' => 70]);
        $this->sembrar_recargo($w, ['amount' => 200]);
        $this->sembrar_recargo($q, ['amount' => 100]);

        $this->sembrar_descuento($x, ['provider_id' => $rosario->id, 'percentage' => 6, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);

        $ids = [$x->id, $w->id, $q->id, $z->id, $y->id];

        $renglones = [
            $this->renglon($x, 100, 4),
            $this->renglon($w, 100, 4),
            $this->renglon($q, 100, 4),
            $this->renglon($z, 100, 4),
            $this->renglon($y, 100, 4),
        ];

        /* Los segundos renglones: [artículo, costo, cantidad]. */
        $repetidos = [
            [$x, 100, 2],
            [$w, 200, 2],
            [$q, 300, 1],
            [$z, 50, 4],
        ];

        $hoy   = $this->correr_las_pasadas(true, $renglones, $repetidos, $ids);
        $motor = $this->correr_las_pasadas(false, $renglones, $repetidos, $ids);

        /* Guarda: la relación de la compra trajo los repetidos, en el orden de los renglones. */
        $this->assertSame(
            [$x->id, $w->id, $q->id, $z->id, $y->id, $x->id, $w->id, $q->id, $z->id],
            $hoy['articulos_de_la_relacion'],
            'Guarda: la relación de la compra tenía que traer los nueve renglones, con los repetidos al final.'
        );

        $this->assertSame($hoy['articulos_de_la_relacion'], $motor['articulos_de_la_relacion']);

        /* Guardas sobre el camino de hoy: cada caso es el que dice el docblock. */
        $esperado = [
            $x->id => ['100', self::SELLO_VIEJO, self::AHORA],
            $w->id => ['200', self::SELLO_VIEJO, self::AHORA],
            $q->id => ['300', self::SELLO_VIEJO, self::AHORA],
            $z->id => ['50', self::AHORA, self::AHORA],
            $y->id => ['100', self::AHORA, self::AHORA],
        ];

        foreach ($esperado as $article_id => $valores) {

            $filas = [];

            foreach ($hoy['article_surchages'] as $fila) {
                if ($fila['article_id'] === (string) $article_id) {
                    $filas[] = $fila;
                }
            }

            $this->assertCount(1, $filas, 'Guarda: un solo recargo de transporte por artículo.');
            $this->assertSame($valores[0], $filas[0]['amount'], 'Guarda: el monto que deja hoy la última pasada del artículo ' . $article_id . '.');
            $this->assertSame($valores[1], $filas[0]['created_at']);
            $this->assertSame($valores[2], $filas[0]['updated_at'], 'Guarda: el sello que deja hoy el artículo ' . $article_id . '.');
        }

        $this->assertSame($hoy['article_surchages'], $motor['article_surchages'], 'Con un artículo repetido, los recargos en bloque no dejaron lo mismo que la pasada por artículo.');

        $this->assertSame($hoy['article_discounts'], $motor['article_discounts'], 'Con un artículo repetido, los descuentos en bloque no dejaron lo mismo que la pasada por artículo (filas, sellos u orden de ids).');

        $this->assertSame($this->ids_ordenados($ids), $motor['pendientes'], 'Con el recálculo diferido tenían que quedar anotados los cinco artículos, una vez cada uno.');
    }

    /**
     * Alta de la compra sin actualizar precios, los renglones repetidos sembrados directo en la
     * tabla, el helper con update_prices en memoria, set_totales() y las dos pasadas, en un savepoint
     * que se revierte. Devuelve lo que quedó en article_surchages y article_discounts (en orden de
     * id), el orden de los artículos de la relación y los anotados para el recálculo.
     *
     * @param  bool  $por_articulo  El interruptor: true = las pasadas de siempre.
     * @param  array $renglones     Renglones del alta.
     * @param  array $repetidos     [[Article, costo, cantidad], ...] para sembrar como segundo renglón.
     * @param  int[] $ids           Artículos cuyas filas se devuelven.
     * @return array
     */
    protected function correr_las_pasadas($por_articulo, array $renglones, array $repetidos, array $ids)
    {
        Carbon::setTestNow(self::AHORA);

        $this->limpiar_estado_del_proceso();

        NewProviderOrderHelper::recalcular_por_articulo($por_articulo);

        $nivel = DB::transactionLevel();

        DB::beginTransaction();

        try {

            $compra_id = $this->alta($this->payload_compra([
                'update_prices' => 0,
                'childrens'     => [$this->costo_extra_del_alta(['value' => 3100])],
                'articles'      => $renglones,
            ]));

            foreach ($repetidos as $repetido) {

                list($article, $cost, $amount) = $repetido;

                DB::table('article_provider_order')->insert([
                    'provider_order_id' => $compra_id,
                    'article_id'        => $article->id,
                    'cost'              => $cost,
                    'amount'            => $amount,
                    'received'          => $amount,
                    'iva_id'            => $article->iva_id,
                    'cost_in_dollars'   => 0,
                    'update_provider'   => 1,
                ]);
            }

            $provider_order = ProviderOrder::find($compra_id);

            $provider_order->update_prices = 1;

            $helper = new NewProviderOrderHelper($provider_order, []);

            $helper->set_totales();

            $articulos_de_la_relacion = [];

            foreach ($provider_order->articles as $article) {
                $articulos_de_la_relacion[] = (int) $article->id;
            }

            $helper->materializar_descuentos_proveedor_en_articulos();
            $helper->aplicar_costos_extra_a_recargos_articulos();

            $propiedad = new \ReflectionProperty(NewProviderOrderHelper::class, 'articulos_con_precio_pendiente');
            $propiedad->setAccessible(true);

            return [
                'articulos_de_la_relacion' => $articulos_de_la_relacion,
                'article_surchages'        => $this->secuencia_de('article_surchages', $ids, self::COLUMNAS_RECARGOS),
                'article_discounts'        => $this->secuencia_de('article_discounts', $ids, self::COLUMNAS_DESCUENTOS),
                'pendientes'               => $this->ids_ordenados(array_values($propiedad->getValue($helper))),
            ];

        } finally {

            while (DB::transactionLevel() > $nivel) {
                DB::rollBack();
            }

            $this->limpiar_estado_del_proceso();

            Carbon::setTestNow();
        }
    }
}
