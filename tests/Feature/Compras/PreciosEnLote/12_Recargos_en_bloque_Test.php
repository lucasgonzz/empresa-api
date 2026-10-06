<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Models\Article;
use App\Models\ProviderOrderExtraCost;
use Illuminate\Support\Facades\DB;

/**
 * Los recargos de los costos extra de la compra EN BLOQUE (misión compras-precios-en-lote, §4.4 del
 * plan, 29/9/2026) dejan article_surchages exactamente como el PASO 2 por artículo.
 *
 * Con el recálculo diferido, NewProviderOrderHelper::aplicar_costos_extra_a_recargos_articulos() ya
 * no hace Article::find() + first() + save() + load() por artículo y por tipo: corre
 * aplicar_recargos_en_bloque(). Con el interruptor prendido ("hoy") corre el PASO 2 de siempre, que
 * es la referencia.
 *
 * Lo delicado es QUÉ se escribe: el bloque decide con el mismo modelo Eloquent (isDirty()) que
 * save(), así que un recargo con el mismo monto no se toca y su updated_at viejo queda como está.
 * Los recargos de antes de la compra se siembran con un sello VIEJO para que eso se vea. Además de
 * la foto de dos_caminos() (que ordena por contenido y pasa los float a texto con 14 dígitos), se
 * compara la SECUENCIA de filas en orden de id con los float a 17 dígitos: mismo DOUBLE, mismo orden.
 *
 * Los montos del primer escenario salen exactos a propósito (1.800 de flete sobre renglones de
 * $400 con una base de $3.600: $200 por renglón, dividido la cantidad), así se puede sembrar "el
 * mismo monto" sin rehacer la cuenta. El segundo usa montos con decimales infinitos.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Recargos_en_bloque_Test extends ComprasPreciosEnLoteTestCase
{
    /** Columnas de article_surchages que se comparan en orden de id (todas menos el id). */
    const COLUMNAS = [
        'article_id', 'percentage', 'amount', 'tipo', 'luego_del_precio_final', 'temporal_id',
        'show_in_online', 'created_at', 'updated_at',
    ];

    /**
     * Un solo tipo (flete de transporte) y un artículo por cada situación de antes:
     *  - igual: ya tiene el recargo con el MISMO monto → no se escribe nada;
     *  - otro_monto: lo tiene con otro monto → se pisa el monto;
     *  - con_porcentaje: lo tiene por porcentaje y con otro monto → monto nuevo y porcentaje en null;
     *  - porcentaje_mismo_monto: mismo monto pero con porcentaje → solo el porcentaje pasa a null;
     *  - dos_del_mismo_tipo: DOS recargos de transporte → se pisa el de menor id, el otro queda;
     *  - sin_recargo: no tiene → se crea;
     *  - otro_tipo: tiene uno de arancel (que la compra no trae) → queda, y se crea el de transporte;
     *  - mayuscula: lo tiene cargado como 'Transporte' → hoy el where('tipo') lo encuentra igual
     *    (collation sin mayúsculas) y lo pisa;
     *  - no_llego: renglón con cantidad recibida 0 → se saltea, su recargo queda;
     *  - borrado: artículo borrado (la relación de la compra va con withTrashed()) → se saltea.
     *
     * @group compras
     * @test
     */
    public function un_tipo_con_cada_situacion_de_antes()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $igual                  = $this->crear_articulo(['cost' => 95]);
        $otro_monto             = $this->crear_articulo(['cost' => 190]);
        $con_porcentaje         = $this->crear_articulo(['cost' => 45]);
        $porcentaje_mismo_monto = $this->crear_articulo(['cost' => 75]);
        $dos_del_mismo_tipo     = $this->crear_articulo(['cost' => 380]);
        $sin_recargo            = $this->crear_articulo(['cost' => 20]);
        $otro_tipo              = $this->crear_articulo(['cost' => 95]);
        $mayuscula              = $this->crear_articulo(['cost' => 95]);
        $no_llego               = $this->crear_articulo(['cost' => 95]);
        $borrado                = $this->crear_articulo(['cost' => 95]);

        $this->sembrar_recargo($igual, ['amount' => 50]);
        $this->sembrar_recargo($otro_monto, ['amount' => 70]);
        $this->sembrar_recargo($con_porcentaje, ['amount' => 30, 'percentage' => 5]);
        $this->sembrar_recargo($porcentaje_mismo_monto, ['amount' => 40, 'percentage' => 5]);
        $this->sembrar_recargo($dos_del_mismo_tipo, ['amount' => 77, 'created_at' => self::SELLO_MAS_VIEJO]);
        $this->sembrar_recargo($dos_del_mismo_tipo, ['amount' => 88]);
        $this->sembrar_recargo($otro_tipo, ['tipo' => ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION, 'amount' => 30]);
        $this->sembrar_recargo($mayuscula, ['tipo' => 'Transporte', 'amount' => 60]);
        $this->sembrar_recargo($no_llego, ['amount' => 99]);
        $this->sembrar_recargo($borrado, ['amount' => 44]);

        $todos = [$igual, $otro_monto, $con_porcentaje, $porcentaje_mismo_monto, $dos_del_mismo_tipo, $sin_recargo, $otro_tipo, $mayuscula, $no_llego, $borrado];

        foreach ($todos as $article) {
            $this->calcular_precio($article);
        }

        $ids = [];

        foreach ($todos as $article) {
            $ids[] = $article->id;
        }

        /* El renglón del borrado se arma antes de borrarlo (renglon() lo lee con Article::find()). */
        $renglon_del_borrado = $this->renglon($borrado, 100, 4);

        Article::find($borrado->id)->delete();

        $secuencias = [];

        $r = $this->dos_caminos(function () use ($igual, $otro_monto, $con_porcentaje, $porcentaje_mismo_monto, $dos_del_mismo_tipo, $sin_recargo, $otro_tipo, $mayuscula, $no_llego, $renglon_del_borrado, $ids, &$secuencias) {

            $flete = $this->costo_extra_del_alta(['value' => 1800]);

            /*
             * Todos los renglones que cuentan suman $400 (la base del prorrateo, sub_total, es
             * $3.600, con el del borrado adentro: la relación lo trae). Cada uno se lleva $200 del
             * flete, dividido su cantidad.
             */
            $compra_id = $this->alta($this->payload_compra([
                'childrens' => [$flete],
                'articles'  => [
                    $this->renglon($igual, 100, 4),
                    $this->renglon($otro_monto, 200, 2),
                    $this->renglon($con_porcentaje, 50, 8),
                    $this->renglon($porcentaje_mismo_monto, 80, 5),
                    $this->renglon($dos_del_mismo_tipo, 400, 1),
                    $this->renglon($sin_recargo, 25, 16),
                    $this->renglon($otro_tipo, 100, 4),
                    $this->renglon($mayuscula, 100, 4),
                    $this->renglon($no_llego, 100, 4, ['received' => 0]),
                    $renglon_del_borrado,
                ],
            ]));

            $secuencias[] = $this->secuencia_de('article_surchages', $ids, self::COLUMNAS);

            return [
                'articulos' => $ids,
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('recargos en bloque: un tipo, cada situación', $r);

        $this->assert_hubo_cambios_de_precio($r);

        $t = ProviderOrderExtraCost::TIPO_TRANSPORTE;

        foreach (['hoy', 'motor'] as $camino) {

            $f = $r[$camino];
            $m = ' (' . $camino . ')';

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $igual->id]);
            $this->assertSame('50', $fila['amount']);
            $this->assertSame(self::SELLO_VIEJO, $fila['updated_at'], 'Guarda' . $m . ': con el mismo monto el recargo no se escribe (save() no emite nada).');

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $otro_monto->id]);
            $this->assertSame('100', $fila['amount']);
            $this->assertSame(self::SELLO_VIEJO, $fila['created_at']);
            $this->assertSame(self::AHORA, $fila['updated_at'], 'Guarda' . $m . ': con otro monto se escribe.');

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $con_porcentaje->id]);
            $this->assertSame('25', $fila['amount']);
            $this->assertNull($fila['percentage'], 'Guarda' . $m . ': el porcentaje pasa a null.');
            $this->assertSame(self::AHORA, $fila['updated_at']);

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $porcentaje_mismo_monto->id]);
            $this->assertSame('40', $fila['amount']);
            $this->assertNull($fila['percentage']);
            $this->assertSame(self::AHORA, $fila['updated_at'], 'Guarda' . $m . ': con el mismo monto pero con porcentaje, se escribe (el porcentaje queda sucio).');

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $dos_del_mismo_tipo->id, 'created_at' => self::SELLO_MAS_VIEJO]);
            $this->assertSame('200', $fila['amount'], 'Guarda' . $m . ': de los dos del mismo tipo se pisa el de menor id.');
            $this->assertSame(self::AHORA, $fila['updated_at']);

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $dos_del_mismo_tipo->id, 'created_at' => self::SELLO_VIEJO]);
            $this->assertSame('88', $fila['amount'], 'Guarda' . $m . ': el otro del mismo tipo queda como estaba.');
            $this->assertSame(self::SELLO_VIEJO, $fila['updated_at']);

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $sin_recargo->id]);
            $this->assertSame($t, $fila['tipo']);
            $this->assertSame('12.5', $fila['amount']);
            $this->assertNull($fila['percentage']);
            $this->assertSame('0', $fila['luego_del_precio_final']);
            $this->assertSame(self::AHORA, $fila['created_at'], 'Guarda' . $m . ': sin recargo, se crea en la corrida.');
            $this->assertSame(self::AHORA, $fila['updated_at']);

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $otro_tipo->id, 'tipo' => ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION]);
            $this->assertSame('30', $fila['amount']);
            $this->assertSame(self::SELLO_VIEJO, $fila['updated_at'], 'Guarda' . $m . ': un recargo de un tipo que la compra no trae no se toca.');

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $otro_tipo->id, 'tipo' => $t]);
            $this->assertSame('50', $fila['amount']);
            $this->assertSame(self::AHORA, $fila['created_at']);

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $mayuscula->id]);
            $this->assertSame('Transporte', $fila['tipo'], 'Guarda' . $m . ': el recargo con el tipo en mayúscula se pisa, no se crea otro.');
            $this->assertSame('50', $fila['amount']);
            $this->assertSame(self::AHORA, $fila['updated_at']);

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $no_llego->id]);
            $this->assertSame('99', $fila['amount']);
            $this->assertSame(self::SELLO_VIEJO, $fila['updated_at'], 'Guarda' . $m . ': el renglón con cantidad recibida 0 se saltea.');

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $borrado->id]);
            $this->assertSame('44', $fila['amount']);
            $this->assertSame(self::SELLO_VIEJO, $fila['updated_at'], 'Guarda' . $m . ': el artículo borrado se saltea.');
        }

        $this->assertCount(2, $secuencias, 'Guarda: la compra corrió dos veces.');

        $this->assertSame($secuencias[0], $secuencias[1], 'Las filas de article_surchages no quedaron iguales a las de hoy en orden de id (o algún DOUBLE difiere en los últimos dígitos).');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * Dos tipos en la misma compra (flete de transporte y seguro) con montos de decimales infinitos:
     *  - a: tiene los dos; el de transporte con otro monto (se pisa) y el de seguro con EL MISMO
     *    monto que va a calcular la compra (no se escribe);
     *  - b: no tiene ninguno → se crean los dos, primero el de transporte y después el de seguro;
     *  - c: tiene solo el de seguro, con otro monto → se pisa, y se crea el de transporte.
     *
     * @group compras
     * @test
     */
    public function dos_tipos_en_la_misma_compra_con_montos_con_decimales()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $a = $this->crear_articulo(['cost' => 120]);
        $b = $this->crear_articulo(['cost' => 66]);
        $c = $this->crear_articulo(['cost' => 41]);

        /*
         * El monto de seguro de "a" que va a calcular la compra, con la MISMA expresión y las mismas
         * operaciones que el PASO 2: valor del tipo * subtotal del renglón / base / cantidad. La base
         * es la suma de los subtotales: 130 x 3 + 70 x 10 + 45 x 7 = 1.405.
         */
        $base          = 390.0 + 700.0 + 315.0;
        $seguro_de_a   = 700.0 * 390.0 / $base / 3.0;

        $this->sembrar_recargo($a, ['amount' => 1]);
        $this->sembrar_recargo($a, ['tipo' => ProviderOrderExtraCost::TIPO_SEGURO, 'amount' => $seguro_de_a]);
        $this->sembrar_recargo($c, ['tipo' => ProviderOrderExtraCost::TIPO_SEGURO, 'amount' => 1]);

        foreach ([$a, $b, $c] as $article) {
            $this->calcular_precio($article);
        }

        $ids = [$a->id, $b->id, $c->id];

        $secuencias = [];

        $r = $this->dos_caminos(function () use ($a, $b, $c, $ids, &$secuencias) {

            $flete = $this->costo_extra_del_alta(['description' => 'Flete', 'value' => 1000]);

            $seguro = $this->costo_extra_del_alta([
                'description' => 'Seguro de carga',
                'value'       => 700,
                'tipo'        => ProviderOrderExtraCost::TIPO_SEGURO,
            ]);

            $compra_id = $this->alta($this->payload_compra([
                'childrens' => [$flete, $seguro],
                'articles'  => [
                    $this->renglon($a, 130, 3),
                    $this->renglon($b, 70, 10),
                    $this->renglon($c, 45, 7),
                ],
            ]));

            $secuencias[] = $this->secuencia_de('article_surchages', $ids, self::COLUMNAS);

            return [
                'articulos' => $ids,
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('recargos en bloque: dos tipos con decimales', $r);

        $this->assert_hubo_cambios_de_precio($r);

        $t = ProviderOrderExtraCost::TIPO_TRANSPORTE;
        $s = ProviderOrderExtraCost::TIPO_SEGURO;

        foreach (['hoy', 'motor'] as $camino) {

            $f = $r[$camino];
            $m = ' (' . $camino . ')';

            $this->assertCount(6, $f['article_surchages'], 'Guarda' . $m . ': dos recargos por artículo, ni uno más.');

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $a->id, 'tipo' => $t]);
            $this->assertNotSame('1', $fila['amount']);
            $this->assertSame(self::AHORA, $fila['updated_at'], 'Guarda' . $m . ': el transporte de "a" cambia de monto.');

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $a->id, 'tipo' => $s]);
            $this->assertSame(self::SELLO_VIEJO, $fila['updated_at'], 'Guarda' . $m . ': el seguro de "a" tiene el mismo monto (con decimales infinitos) y no se escribe.');

            foreach ([$t, $s] as $tipo) {
                $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $b->id, 'tipo' => $tipo]);
                $this->assertSame(self::AHORA, $fila['created_at'], 'Guarda' . $m . ': los dos de "b" se crean en la corrida.');
            }

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $c->id, 'tipo' => $s]);
            $this->assertSame(self::SELLO_VIEJO, $fila['created_at']);
            $this->assertSame(self::AHORA, $fila['updated_at'], 'Guarda' . $m . ': el seguro de "c" cambia de monto.');

            $fila = $this->una_fila_de($f, 'article_surchages', ['article_id' => $c->id, 'tipo' => $t]);
            $this->assertSame(self::AHORA, $fila['created_at']);
        }

        /* Guarda del orden: en "b", el de transporte se creó antes que el de seguro (orden de los tipos). */
        $tipos_de_b = [];

        foreach ($secuencias[1] as $fila) {
            if ($fila['article_id'] === (string) $b->id) {
                $tipos_de_b[] = $fila['tipo'];
            }
        }

        $this->assertSame([$t, $s], $tipos_de_b, 'Guarda: los recargos nuevos de un artículo se crean en el orden de los tipos de la compra.');

        $this->assertSame($secuencias[0], $secuencias[1], 'Las filas de article_surchages no quedaron iguales a las de hoy en orden de id (o algún DOUBLE difiere en los últimos dígitos).');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }

    /**
     * Reconfirmar una compra (PUT) sin cambiar los renglones: los recargos que dejó la primera
     * confirmación tienen el mismo monto (con decimales, calculado por el sistema, no por el test),
     * así que no se escriben y su updated_at queda como estaba. La edición le suma un seguro, que sí
     * crea recargos nuevos.
     *
     * @group compras
     * @test
     */
    public function reconfirmar_la_compra_no_toca_los_recargos_que_no_cambian()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $a = $this->crear_articulo(['cost' => 120]);
        $b = $this->crear_articulo(['cost' => 66]);
        $c = $this->crear_articulo(['cost' => 41]);

        $ids = [$a->id, $b->id, $c->id];

        $renglones = [
            $this->renglon($a, 130, 3),
            $this->renglon($b, 70, 10),
            $this->renglon($c, 45, 7),
        ];

        /* La primera confirmación, una sola vez y antes de las corridas: deja los recargos de transporte. */
        $compra_id = $this->alta($this->payload_compra([
            'childrens' => [$this->costo_extra_del_alta(['value' => 1000])],
            'articles'  => $renglones,
        ]));

        $this->assertSame(3, DB::table('article_surchages')->whereIn('article_id', $ids)->count(), 'Guarda: la primera confirmación tenía que dejar un recargo por artículo.');

        /* Con el sello viejo, para que un updated_at tocado en la reconfirmación se vea. */
        DB::table('article_surchages')->whereIn('article_id', $ids)->update([
            'created_at' => self::SELLO_VIEJO,
            'updated_at' => self::SELLO_VIEJO,
        ]);

        $secuencias = [];

        $r = $this->dos_caminos(function () use ($compra_id, $renglones, $ids, &$secuencias) {

            ProviderOrderExtraCost::create([
                'provider_order_id' => $compra_id,
                'description'       => 'Seguro de carga',
                'value'             => 350,
                'tipo'              => ProviderOrderExtraCost::TIPO_SEGURO,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            $this->edicion($compra_id, $this->payload_compra([
                'articles' => $renglones,
            ]));

            $secuencias[] = $this->secuencia_de('article_surchages', $ids, self::COLUMNAS);

            return [
                'articulos' => $ids,
                'compra_id' => $compra_id,
            ];
        });

        $this->anotar_resumen('recargos en bloque: reconfirmación', $r);

        $this->assert_hubo_cambios_de_precio($r);

        foreach (['hoy', 'motor'] as $camino) {

            foreach ($ids as $article_id) {

                $fila = $this->una_fila_de($r[$camino], 'article_surchages', ['article_id' => $article_id, 'tipo' => ProviderOrderExtraCost::TIPO_TRANSPORTE]);
                $this->assertSame(self::SELLO_VIEJO, $fila['updated_at'], 'Guarda (' . $camino . '): el transporte no cambia de monto al reconfirmar y no se escribe.');

                $fila = $this->una_fila_de($r[$camino], 'article_surchages', ['article_id' => $article_id, 'tipo' => ProviderOrderExtraCost::TIPO_SEGURO]);
                $this->assertSame(self::AHORA, $fila['created_at'], 'Guarda (' . $camino . '): el seguro nuevo crea su recargo.');
            }
        }

        $this->assertSame($secuencias[0], $secuencias[1], 'Las filas de article_surchages no quedaron iguales a las de hoy en orden de id (o algún DOUBLE difiere en los últimos dígitos).');

        $this->assert_mismo_resultado($r['antes'], $r['hoy'], $r['motor']);
    }
}
