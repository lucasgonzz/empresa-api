<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\ProviderOrderDiscount;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * La materialización de los descuentos de la compra EN BLOQUE (misión compras-precios-en-lote,
 * §4.4 del plan, 29/9/2026) deja article_discounts exactamente como la pasada por artículo.
 *
 * Con el recálculo diferido, NewProviderOrderHelper::materializar_descuentos_proveedor_en_articulos()
 * ya no hace Article::find() + DELETE + INSERT por artículo: llama una vez a
 * ArticleProviderDiscountHelper::sync_provider_discounts_en_bloque(). Con el interruptor prendido
 * ("hoy") corre la pasada por artículo de siempre, que es la referencia.
 *
 * Los descuentos de antes de la compra se siembran con un sello VIEJO (SELLO_VIEJO), así un
 * updated_at tocado de más, o una fila que no se barrió, se ven en la comparación. Además de la
 * foto de dos_caminos() (que ordena por contenido), se compara la SECUENCIA de filas en orden de id:
 * el cálculo aplica los descuentos de un artículo en cascada y en ese orden.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Descuentos_en_bloque_Test extends ComprasPreciosEnLoteTestCase
{
    /** Columnas de article_discounts que se comparan en orden de id (todas menos el id). */
    const COLUMNAS = [
        'article_id', 'provider_id', 'percentage', 'amount', 'tipo', 'temporal_id', 'show_in_online',
        'created_at', 'updated_at', 'editado_a_mano', 'origen', 'provider_discount_id', 'nombre',
    ];

    /**
     * Los dos descuentos propios de la compra (un porcentaje y un monto) en cada artículo; el
     * barrido de los tagueados de OTRO proveedor y del MISMO; los manuales intactos; y un artículo
     * borrado (la relación de la compra va con withTrashed()) que no se toca.
     *
     * @group compras
     * @test
     */
    public function barre_los_tagueados_respeta_los_manuales_y_crea_los_de_la_compra()
    {
        $this->set_condicion_iva('RRII');

        $bsas    = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $con_tagueados = $this->crear_articulo(['cost' => 800]);
        $con_manual    = $this->crear_articulo(['cost' => 450]);
        $sin_nada      = $this->crear_articulo(['cost' => 300]);
        $borrado       = $this->crear_articulo(['cost' => 200]);

        /* Tagueados de otro proveedor y del mismo, de compras anteriores: el barrido se los lleva. */
        $this->sembrar_descuento($con_tagueados, ['provider_id' => $rosario->id, 'percentage' => 7, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);
        $this->sembrar_descuento($con_tagueados, ['provider_id' => $bsas->id, 'amount' => 12, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_FICHA_PROVEEDOR]);

        /* Manuales (sin proveedor): quedan como están, con su sello viejo. */
        $this->sembrar_descuento($con_manual, ['percentage' => 4, 'origen' => ArticleDiscount::ORIGEN_MANUAL, 'nombre' => 'Descuento a mano']);
        $this->sembrar_descuento($con_tagueados, ['amount' => 3, 'origen' => ArticleDiscount::ORIGEN_MANUAL]);

        /* El borrado: tiene un tagueado que NADIE tiene que tocar (hoy, Article::find() da null). */
        $this->sembrar_descuento($borrado, ['provider_id' => $rosario->id, 'percentage' => 9, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);

        foreach ([$con_tagueados, $con_manual] as $article) {
            $this->calcular_precio($article);
        }

        /* El renglón del borrado se arma antes de borrarlo (renglon() lo lee con Article::find()). */
        $renglon_del_borrado = $this->renglon($borrado, 210, 3);

        Article::find($borrado->id)->delete();

        $ids = [$con_tagueados->id, $con_manual->id, $sin_nada->id, $borrado->id];

        $secuencias = [];

        $r = $this->dos_caminos(function () use ($con_tagueados, $con_manual, $sin_nada, $renglon_del_borrado, $ids, &$secuencias) {

            $compra_id = $this->alta($this->payload_compra([
                'childrens' => [
                    $this->descuento_del_alta(['percentage' => 8, 'description' => 'Bonificación negociada']),
                    $this->descuento_del_alta(['monto' => 25, 'description' => 'Pronto pago']),
                ],
                'articles' => [
                    $this->renglon($con_tagueados, 850, 4),
                    $this->renglon($con_manual, 470, 6),
                    $renglon_del_borrado,
                    $this->renglon($sin_nada, 310, 10),
                ],
            ]));

            $secuencias[] = $this->secuencia_de('article_discounts', $ids, self::COLUMNAS);

            return [
                'articulos' => $ids,
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('descuentos en bloque: barrido, manuales y borrado', $r);

        $this->assert_hubo_cambios_de_precio($r);

        foreach (['hoy', 'motor'] as $camino) {

            $foto = $r[$camino];

            $this->assertSame([], $this->filas_de($foto, 'article_discounts', ['article_id' => $con_tagueados->id, 'provider_id' => $rosario->id]), 'Guarda (' . $camino . '): el tagueado de otro proveedor se tenía que barrer.');

            foreach ([$con_tagueados, $con_manual, $sin_nada] as $article) {

                $nuevos = $this->filas_de($foto, 'article_discounts', ['article_id' => $article->id, 'provider_id' => $bsas->id]);

                $this->assertCount(2, $nuevos, 'Guarda (' . $camino . '): cada artículo que existe tenía que quedar con los dos descuentos de la compra, y nada más de ese proveedor.');

                foreach ($nuevos as $fila) {
                    $this->assertSame(self::AHORA, $fila['created_at'], 'Guarda (' . $camino . '): los descuentos de la compra se crean en la corrida.');
                    $this->assertSame(ArticleDiscount::ORIGEN_COMPRA, $fila['origen']);
                }
            }

            $manual = $this->una_fila_de($foto, 'article_discounts', ['article_id' => $con_manual->id, 'nombre' => 'Descuento a mano']);

            $this->assertNull($manual['provider_id']);
            $this->assertSame(self::SELLO_VIEJO, $manual['updated_at'], 'Guarda (' . $camino . '): el descuento manual no se toca.');

            $del_borrado = $this->una_fila_de($foto, 'article_discounts', ['article_id' => $borrado->id]);

            $this->assertSame((string) $rosario->id, $del_borrado['provider_id']);
            $this->assertSame(self::SELLO_VIEJO, $del_borrado['updated_at'], 'Guarda (' . $camino . '): el artículo borrado no se toca (Article::find() da null).');
        }

        $this->assertCount(2, $secuencias, 'Guarda: la compra corrió dos veces.');

        $this->assertSame($secuencias[0], $secuencias[1], 'Las filas de article_discounts no quedaron en el mismo orden de id que hoy: el cálculo aplica los descuentos en cascada y en ese orden.');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * Una compra SIN descuentos (sin bonificaciones que precargar y sin descuentos propios): la
     * pasada solo barre los tagueados, de cualquier proveedor, y no crea nada.
     *
     * @group compras
     * @test
     */
    public function una_compra_sin_descuentos_solo_barre()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $bsas    = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $uno  = $this->crear_articulo(['cost' => 620]);
        $otro = $this->crear_articulo(['cost' => 140]);

        $this->sembrar_descuento($uno, ['provider_id' => $rosario->id, 'percentage' => 6, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);
        $this->sembrar_descuento($uno, ['provider_id' => $bsas->id, 'percentage' => 10, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_FICHA_PROVEEDOR]);
        $this->sembrar_descuento($otro, ['provider_id' => $bsas->id, 'percentage' => 5, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);
        $this->sembrar_descuento($otro, ['amount' => 15, 'origen' => ArticleDiscount::ORIGEN_MANUAL, 'nombre' => 'Redondeo']);

        foreach ([$uno, $otro] as $article) {
            $this->calcular_precio($article);
        }

        $ids = [$uno->id, $otro->id];

        $descuentos_de_la_compra = [];

        $r = $this->dos_caminos(function () use ($uno, $otro, $ids, &$descuentos_de_la_compra) {

            $compra_id = $this->alta($this->payload_compra([
                'articles' => [
                    $this->renglon($uno, 650, 2),
                    $this->renglon($otro, 150, 12),
                ],
            ]));

            $descuentos_de_la_compra[] = DB::table('provider_order_discounts')->where('provider_order_id', $compra_id)->count();

            return [
                'articulos' => $ids,
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('descuentos en bloque: compra sin descuentos', $r);

        $this->assertSame([0, 0], $descuentos_de_la_compra, 'Guarda: la compra no tenía que tener descuentos (ni precargados ni propios).');

        $this->assert_hubo_cambios_de_precio($r);

        foreach (['hoy', 'motor'] as $camino) {

            $this->assertCount(1, $r[$camino]['article_discounts'], 'Guarda (' . $camino . '): solo tenía que quedar el descuento manual.');

            $manual = $this->una_fila_de($r[$camino], 'article_discounts', ['article_id' => $otro->id, 'nombre' => 'Redondeo']);

            $this->assertSame(self::SELLO_VIEJO, $manual['updated_at']);
        }

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * El contrato del método en bloque, directo y sin compra: para la misma lista de ids (con un
     * repetido, un borrado y uno que no existe) y los mismos descuentos, deja article_discounts
     * EXACTAMENTE como sync_provider_discounts() llamado de a uno y en ese orden. Mismas filas, mismas
     * columnas, mismos sellos y el mismo orden de ids, también entre artículos (el repetido cuenta en
     * su ÚLTIMA posición). Se prueba con descuentos de compra (incluido uno vacío que se saltea), con
     * ítems del import (arrays), con una colección vacía y con null.
     *
     * @group compras
     * @test
     */
    public function el_metodo_en_bloque_deja_lo_mismo_que_sync_provider_discounts_de_a_uno()
    {
        $bsas    = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $a       = $this->crear_articulo(['cost' => 100]);
        $b       = $this->crear_articulo(['cost' => 200]);
        $c       = $this->crear_articulo(['cost' => 300]);
        $borrado = $this->crear_articulo(['cost' => 400]);

        $this->sembrar_descuento($a, ['provider_id' => $rosario->id, 'percentage' => 7, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);
        $this->sembrar_descuento($a, ['percentage' => 2, 'origen' => ArticleDiscount::ORIGEN_MANUAL]);
        $this->sembrar_descuento($b, ['provider_id' => $bsas->id, 'amount' => 11, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_FICHA_PROVEEDOR]);
        $this->sembrar_descuento($borrado, ['provider_id' => $bsas->id, 'percentage' => 9, 'tipo' => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);

        Article::find($borrado->id)->delete();

        $no_existe = (int) DB::table('articles')->max('id') + 1000;

        /* El orden de a uno: a, (borrado), b, a otra vez, (no existe), c. */
        $ids = [$a->id, $borrado->id, $b->id, $a->id, $no_existe, $c->id];

        $de_la_compra = collect([
            new ProviderOrderDiscount(['percentage' => 8, 'description' => 'Bonificación']),
            new ProviderOrderDiscount(['description' => 'Sin porcentaje ni monto: se saltea']),
            new ProviderOrderDiscount(['monto' => 25, 'description' => 'Pronto pago']),
        ]);

        $juegos = [
            'descuentos de una compra' => $de_la_compra,
            'ítems del import'         => [['percentage' => 10], ['amount' => 3], ['percentage' => '']],
            'colección vacía'          => collect([]),
            'null'                     => null,
        ];

        $todos = [$a->id, $b->id, $c->id, $borrado->id];

        foreach ($juegos as $nombre => $discounts) {

            $de_a_uno = $this->en_un_savepoint(function () use ($ids, $bsas, $discounts, $todos) {

                foreach ($ids as $article_id) {
                    ArticleProviderDiscountHelper::sync_provider_discounts(Article::find($article_id), $bsas->id, $discounts, ArticleDiscount::ORIGEN_COMPRA);
                }

                return $this->secuencia_de('article_discounts', $todos, self::COLUMNAS);
            });

            $devueltos = null;

            $en_bloque = $this->en_un_savepoint(function () use ($ids, $bsas, $discounts, $todos, &$devueltos) {

                $devueltos = ArticleProviderDiscountHelper::sync_provider_discounts_en_bloque($ids, $bsas->id, $discounts, ArticleDiscount::ORIGEN_COMPRA);

                return $this->secuencia_de('article_discounts', $todos, self::COLUMNAS);
            });

            $this->assertSame($de_a_uno, $en_bloque, 'Con ' . $nombre . ': el método en bloque no dejó lo mismo que sync_provider_discounts() de a uno (filas, columnas, sellos u orden de ids).');

            $this->assertSame([$b->id, $a->id, $c->id], $devueltos, 'Con ' . $nombre . ': tenía que devolver los que existen, sin repetidos, en el orden en que se escribieron (el repetido en su última posición).');
        }

        /* Guarda: el juego de la compra de verdad creó filas, y el repetido quedó después de b. */
        $con_compra = $this->en_un_savepoint(function () use ($ids, $bsas, $de_la_compra, $todos) {

            ArticleProviderDiscountHelper::sync_provider_discounts_en_bloque($ids, $bsas->id, $de_la_compra, ArticleDiscount::ORIGEN_COMPRA);

            return DB::table('article_discounts')
                        ->whereIn('article_id', $todos)
                        ->where('provider_id', $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS)->id)
                        ->where('created_at', self::AHORA)
                        ->orderBy('id')
                        ->pluck('article_id')
                        ->all();
        });

        $this->assertSame(
            [$b->id, $b->id, $a->id, $a->id, $c->id, $c->id],
            array_map('intval', $con_compra),
            'Guarda: dos descuentos por artículo que existe (el vacío se saltea), en el orden b, a, c.'
        );

        /* Sin proveedor no hace nada, como sync_provider_discounts(). */
        $sin_proveedor = $this->en_un_savepoint(function () use ($ids, $de_la_compra, $todos) {

            $devuelto = ArticleProviderDiscountHelper::sync_provider_discounts_en_bloque($ids, null, $de_la_compra, ArticleDiscount::ORIGEN_COMPRA);

            return [$devuelto, $this->secuencia_de('article_discounts', $todos, self::COLUMNAS)];
        });

        $this->assertSame([], $sin_proveedor[0]);
        $this->assertSame($this->secuencia_de('article_discounts', $todos, self::COLUMNAS), $sin_proveedor[1], 'Sin proveedor no se tenía que tocar nada.');
    }

    /**
     * Corre $callable adentro de un savepoint, con el reloj congelado en AHORA, y lo revierte.
     * Devuelve lo que devuelva $callable.
     *
     * @param  callable $callable
     * @return mixed
     */
    protected function en_un_savepoint(callable $callable)
    {
        Carbon::setTestNow(self::AHORA);

        $nivel = DB::transactionLevel();

        DB::beginTransaction();

        try {

            return $callable();

        } finally {

            while (DB::transactionLevel() > $nivel) {
                DB::rollBack();
            }

            Carbon::setTestNow();
        }
    }
}
