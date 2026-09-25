<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\article\ArticleUbicationsHelper;
use App\Http\Controllers\Helpers\import\article\ActualizarBBDD;
use App\Http\Controllers\Helpers\import\article\motor\RelacionesEnLote;
use App\Models\Article;
use App\Models\ArticleImportResult;
use App\Models\ArticleUbication;
use App\Models\PriceType;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * RelacionesEnLote (y los métodos de ActualizarBBDD que lo usan) dejan en la base exactamente lo
 * mismo que el camino viejo, con un número de consultas que no crece con la cantidad de artículos.
 *
 * Misión `importacion-excel-motor-rapido` (24/9/2026), constructor B1. Camino viejo:
 *   - `article_provider` de creados: `$article->providers()->syncWithoutDetaching([...])` por artículo;
 *   - ubicaciones: `ArticleUbicationsHelper::init_ubications()` (un `attach()` por ubicación por artículo);
 *   - pivot del historial: `$import_result->articulos_actualizados()->attach($id, ['updated_props' => json])` por artículo;
 *   - tracking de diffs: un `SELECT` por artículo (descuentos/recargos), por par artículo × lista y
 *     por par artículo × proveedor.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class EscrituraEnLoteTest extends ImportTestCase
{
    /**
     * Artículo mínimo del tenant.
     *
     * @param  string $nombre
     * @param  array  $props
     * @return \App\Models\Article
     */
    protected function articulo($nombre, array $props = [])
    {
        $article = new Article();
        $article->user_id = $this->tenant->id;
        $article->name    = $nombre;
        $article->status  = 'active';

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        return Article::find($article->id);
    }

    /**
     * Fila de `article_provider` de un artículo, sin id ni article_id, con los timestamps como booleanos.
     *
     * @param  \App\Models\Article $article
     * @return array
     */
    protected function filas_de_proveedor($article)
    {
        return DB::table('article_provider')->where('article_id', $article->id)->orderBy('provider_id')->get()
            ->map(function ($fila) {
                return [
                    'provider_id'      => (int) $fila->provider_id,
                    'provider_code'    => $fila->provider_code,
                    'cost'             => $fila->cost,
                    'amount'           => $fila->amount,
                    'price'            => $fila->price,
                    'created_at_null'  => is_null($fila->created_at),
                    'updated_at_null'  => is_null($fila->updated_at),
                ];
            })->all();
    }

    /**
     * Instancia de ActualizarBBDD sin pasar por el constructor (que dispara el pipeline entero),
     * con lo mínimo que necesita el tracking en lote.
     *
     * @param  array $cache  entradas del cache de actualización (con 'id')
     * @return \App\Http\Controllers\Helpers\import\article\ActualizarBBDD
     */
    protected function actualizar_bbdd_con_cache(array $cache)
    {
        $instancia = (new ReflectionClass(ActualizarBBDD::class))->newInstanceWithoutConstructor();
        $instancia->observations = [];
        $instancia->import_history_id = 12345;
        $instancia->articulos_para_actualizar_CACHE = $cache;

        $this->invocar($instancia, 'indexar_actualizados_por_id');

        return $instancia;
    }

    /**
     * Invoca un método protegido.
     *
     * @param  object $instancia
     * @param  string $metodo
     * @param  array  $args
     * @return mixed
     */
    protected function invocar($instancia, $metodo, array $args = [])
    {
        $reflexion = new ReflectionMethod($instancia, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($instancia, $args);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // article_provider de creados
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_vincular_proveedores_deja_la_misma_fila_que_sync_without_detaching()
    {
        $provider = $this->providers['A'];

        $viejo = $this->articulo('VIEJO prov', ['provider_id' => $provider->id, 'provider_code' => 'PC-LOTE-1', 'cost' => 111]);
        $nuevo = $this->articulo('NUEVO prov', ['provider_id' => $provider->id, 'provider_code' => 'PC-LOTE-1', 'cost' => 111]);
        $sin   = $this->articulo('NUEVO sin proveedor', ['provider_code' => 'PC-LOTE-2', 'cost' => 5]);

        $viejo->providers()->syncWithoutDetaching([$provider->id => ['provider_code' => 'PC-LOTE-1', 'cost' => 111]]);

        RelacionesEnLote::vincular_proveedores_de_creados(collect([$nuevo, $sin]));

        $this->assertEquals($this->filas_de_proveedor($viejo), $this->filas_de_proveedor($nuevo));
        $this->assertCount(1, $this->filas_de_proveedor($nuevo));
        $this->assertSame('PC-LOTE-1', $this->filas_de_proveedor($nuevo)[0]['provider_code']);
        $this->assertFalse($this->filas_de_proveedor($nuevo)[0]['created_at_null']);
        $this->assertEmpty($this->filas_de_proveedor($sin), 'Sin provider_id no se relaciona nada');

        // Dos veces no duplica ni lanza; el par existente se actualiza (cost) como con syncWithoutDetaching.
        $nuevo->cost = 222;
        RelacionesEnLote::vincular_proveedores_de_creados(collect([$nuevo]));
        $viejo->providers()->syncWithoutDetaching([$provider->id => ['provider_code' => 'PC-LOTE-1', 'cost' => 222]]);

        $this->assertCount(1, $this->filas_de_proveedor($nuevo));
        $this->assertEquals($this->filas_de_proveedor($viejo), $this->filas_de_proveedor($nuevo));
        $this->assertEquals(222, (int) $this->filas_de_proveedor($nuevo)[0]['cost']);
    }

    /**
     * set_articles_providers() sobre una instancia sin constructor (como lo usa
     * Dedupe_del_pivot_Test) sigue funcionando y no depende de nada más que
     * articulos_creados_models y observations.
     *
     * @return void
     */
    public function test_set_articles_providers_sin_constructor_relaciona_a_los_creados()
    {
        $provider = $this->providers['B'];
        $article  = $this->articulo('Creado con proveedor', ['provider_id' => $provider->id, 'provider_code' => 'PC-SAP', 'cost' => 9]);

        $instancia = (new ReflectionClass(ActualizarBBDD::class))->newInstanceWithoutConstructor();
        $instancia->observations = [];
        $instancia->articulos_creados_models = collect([$article]);

        $instancia->set_articles_providers();
        $instancia->set_articles_providers();

        $filas = $this->filas_de_proveedor($article);

        $this->assertCount(1, $filas);
        $this->assertSame('PC-SAP', $filas[0]['provider_code']);
        $this->assertEquals(9, (int) $filas[0]['cost']);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Ubicaciones
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_vincular_ubicaciones_deja_las_mismas_filas_que_init_ubications()
    {
        $ubicaciones = collect([
            ArticleUbication::create(['name' => 'Estante 1', 'user_id' => $this->tenant->id]),
            ArticleUbication::create(['name' => 'Estante 2', 'user_id' => $this->tenant->id]),
        ]);

        $viejo  = $this->articulo('VIEJO ubic');
        $nuevo1 = $this->articulo('NUEVO ubic 1');
        $nuevo2 = $this->articulo('NUEVO ubic 2');

        ArticleUbicationsHelper::init_ubications($viejo, $ubicaciones);

        $filas = RelacionesEnLote::vincular_ubicaciones(collect([$nuevo1, $nuevo2]), $ubicaciones);

        $this->assertSame(4, $filas);

        $foto = function ($article) {
            return DB::table('article_article_ubication')->where('article_id', $article->id)->orderBy('article_ubication_id')->get()
                ->map(function ($fila) {
                    return [
                        'article_ubication_id' => (int) $fila->article_ubication_id,
                        'ubication'            => $fila->ubication,
                        'notes'                => $fila->notes,
                        'created_at_null'      => is_null($fila->created_at),
                        'updated_at_null'      => is_null($fila->updated_at),
                    ];
                })->all();
        };

        $this->assertCount(2, $foto($viejo));
        $this->assertEquals($foto($viejo), $foto($nuevo1));
        $this->assertEquals($foto($viejo), $foto($nuevo2));
        $this->assertTrue($foto($nuevo1)[0]['created_at_null'], 'attach() no escribe timestamps en este pivot');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Pivot del historial del chunk
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_el_pivot_del_chunk_lleva_el_mismo_json_que_el_attach_por_articulo()
    {
        $chunk_viejo = ArticleImportResult::create(['chunk_number' => 1]);
        $chunk_nuevo = ArticleImportResult::create(['chunk_number' => 2]);

        $a1 = $this->articulo('Pivot 1');
        $a2 = $this->articulo('Pivot 2');

        $entradas = [
            [
                'id'           => $a1->id,
                'cost'         => 10.0,
                '__diff__cost' => ['old' => '9.50', 'new' => 10.0],
                'stock_global' => ['__diff__stock' => ['old' => 1, 'new' => 4.0, 'delta' => 3.0]],
                'name'         => 'Ñandú "grande" ☕',
                '__diff__discounts_percent' => ['old' => [10.0, 5.0], 'new' => [8.0]],
            ],
            ['id' => $a2->id, 'cost' => 3],
            ['cost' => 99],            // sin id: se saltea
            ['id' => $a2->id, 'cost' => 4], // el mismo artículo dos veces: dos filas, como hoy
        ];

        // Camino viejo, literal.
        foreach ($entradas as $article) {
            if (empty($article['id'])) {
                continue;
            }
            $props = $article;
            unset($props['id']);
            $chunk_viejo->articulos_actualizados()->attach((int) $article['id'], [
                'updated_props' => json_encode($props, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            ]);
        }

        $insertadas = RelacionesEnLote::registrar_actualizados_en_el_chunk($chunk_nuevo->id, $entradas);

        $this->assertSame(3, $insertadas);

        $foto = function ($chunk) {
            return DB::table('article_actualizados_article_import_result')
                ->where('article_import_result_id', $chunk->id)
                ->orderBy('id')
                ->get()
                ->map(function ($fila) {
                    return [
                        'article_id'      => (int) $fila->article_id,
                        'updated_props'   => json_decode($fila->updated_props, true),
                        'created_at_null' => is_null($fila->created_at),
                        'updated_at_null' => is_null($fila->updated_at),
                    ];
                })->all();
        };

        $this->assertCount(3, $foto($chunk_viejo));
        $this->assertEquals($foto($chunk_viejo), $foto($chunk_nuevo));

        // Y el JSON crudo también es el mismo (mismo encoder, mismas flags).
        $crudo = function ($chunk) {
            return DB::table('article_actualizados_article_import_result')
                ->where('article_import_result_id', $chunk->id)->orderBy('id')->pluck('updated_props')->all();
        };
        $this->assertSame($crudo($chunk_viejo), $crudo($chunk_nuevo));

        // La relación de Eloquent lo lee igual (es lo que usa el rollback).
        $chunk_nuevo->refresh();
        $leidos = $chunk_nuevo->articulos_actualizados;
        $this->assertCount(3, $leidos);
        $this->assertArrayHasKey('__diff__cost', json_decode($leidos[0]->pivot->updated_props, true));
        $this->assertArrayNotHasKey('id', json_decode($leidos[0]->pivot->updated_props, true));
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Tracking de diffs en lote
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Descuentos: el `old` es lo que había en la base (floats, en orden de id), el `new` sale del
     * diff de ProcessRow, y el diff cae en las entradas vivas del cache (las que expone
     * get_articulos_para_actualizar()).
     *
     * @return void
     */
    public function test_el_tracking_de_descuentos_registra_el_mismo_old_y_new_que_antes()
    {
        $a1 = $this->articulo('Track desc 1');
        $a2 = $this->articulo('Track desc 2');
        $a3 = $this->articulo('Track desc 3 sin descuentos');

        DB::table('article_discounts')->insert([
            ['article_id' => $a1->id, 'percentage' => 10, 'amount' => null],
            ['article_id' => $a1->id, 'percentage' => 5.5, 'amount' => null],
            ['article_id' => $a1->id, 'percentage' => null, 'amount' => 100],
            ['article_id' => $a2->id, 'percentage' => 20, 'amount' => null],
        ]);

        $cache = [
            ['id' => $a1->id, 'cost' => 1, 'discounts' => [['type' => '%', '__diff__discounts_percent' => ['old' => [10.0, 5.5], 'new' => [8.0]]]]],
            ['id' => $a2->id, 'cost' => 2, 'discounts' => [['type' => '%', '__diff__discounts_percent' => ['old' => [20.0], 'new' => [1.0, 2.0]]]]],
            ['id' => $a3->id, 'cost' => 3, 'discounts' => [['type' => '%', '__diff__discounts_percent' => ['old' => [], 'new' => [3.0]]]]],
        ];

        $instancia = $this->actualizar_bbdd_con_cache($cache);

        $this->invocar($instancia, 'track_discounts_or_surchages_en_lote', [
            [$a1->id, $a2->id, $a3->id], 'discounts', 'discounts_percent', 'article_discounts', 'percentage',
        ]);

        $vivas = $instancia->get_articulos_para_actualizar();

        $this->assertSame(['old' => [10.0, 5.5], 'new' => [8.0]], $vivas[0]['__diff__discounts_percent']);
        $this->assertSame(['old' => [20.0], 'new' => [1.0, 2.0]], $vivas[1]['__diff__discounts_percent']);
        $this->assertSame(['old' => [], 'new' => [3.0]], $vivas[2]['__diff__discounts_percent']);

        // Los montos no se mezclan con los porcentajes.
        $this->invocar($instancia, 'track_discounts_or_surchages_en_lote', [
            [$a1->id], 'discounts', 'discounts_amount', 'article_discounts', 'amount',
        ]);
        $vivas = $instancia->get_articulos_para_actualizar();
        $this->assertSame([100.0], $vivas[0]['__diff__discounts_amount']['old']);

        // Sin import_history_id no se registra nada (ni se consulta).
        $sin_historial = $this->actualizar_bbdd_con_cache($cache);
        $sin_historial->import_history_id = null;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->invocar($sin_historial, 'track_discounts_or_surchages_en_lote', [
            [$a1->id], 'discounts', 'discounts_percent', 'article_discounts', 'percentage',
        ]);
        $this->assertSame(0, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertArrayNotHasKey('__diff__discounts_percent', $sin_historial->get_articulos_para_actualizar()[0]);
    }

    /**
     * Listas de precio: sólo se registra el par que existe y cambió; primer old gana.
     *
     * @return void
     */
    public function test_el_tracking_de_listas_registra_solo_los_pares_que_existen_y_cambian()
    {
        $lista1 = PriceType::create(['name' => 'Lista 1 lote', 'user_id' => $this->tenant->id, 'percentage' => 30, 'position' => 1]);
        $lista2 = PriceType::create(['name' => 'Lista 2 lote', 'user_id' => $this->tenant->id, 'percentage' => 40, 'position' => 2]);

        $a1 = $this->articulo('Track lista 1');
        $a2 = $this->articulo('Track lista 2');

        DB::table('article_price_type')->insert([
            ['article_id' => $a1->id, 'price_type_id' => $lista1->id, 'percentage' => 30, 'final_price' => 130],
            ['article_id' => $a1->id, 'price_type_id' => $lista2->id, 'percentage' => null, 'final_price' => null],
            ['article_id' => $a2->id, 'price_type_id' => $lista1->id, 'percentage' => 30, 'final_price' => 260],
            ['article_id' => $a2->id, 'price_type_id' => $lista2->id, 'percentage' => 40, 'final_price' => null],
        ]);

        $cache = [
            ['id' => $a1->id, 'cost' => 1],
            ['id' => $a2->id, 'cost' => 2],
        ];

        $instancia = $this->actualizar_bbdd_con_cache($cache);

        $this->invocar($instancia, 'track_price_types_en_lote', [[
            [$a1->id, $lista1->id, 35, null],          // cambia percentage
            [$a1->id, $lista2->id, '', null],          // igual (null y null): no se registra
            [$a2->id, $lista2->id, 40, ''],            // "40.00" en la base vs 40.0: el criterio de siempre lo toma como cambio
            [$a2->id, $lista1->id, 'NULL', 300],       // cambia final_price
        ]]);

        $vivas = $instancia->get_articulos_para_actualizar();

        $this->assertArrayHasKey('__diff__price_type_'.$lista1->id, $vivas[0]);
        $this->assertArrayNotHasKey('__diff__price_type_'.$lista2->id, $vivas[0]);

        /*
         * Criterio heredado tal cual: la comparación es entre el string de la columna decimal
         * ("40.00") y el string del float nuevo ("40"), así que un porcentaje numéricamente
         * igual se registra igual. No se "arregla" acá: el rollback lo restaura al mismo valor.
         */
        $this->assertEquals(
            ['old' => ['percentage' => '40.00', 'final_price' => null], 'new' => ['percentage' => 40.0, 'final_price' => null]],
            $vivas[1]['__diff__price_type_'.$lista2->id]
        );
        $this->assertEquals(
            ['old' => ['percentage' => '30.00', 'final_price' => '130.00'], 'new' => ['percentage' => 35.0, 'final_price' => null]],
            $vivas[0]['__diff__price_type_'.$lista1->id]
        );

        $this->assertEquals(
            ['old' => ['percentage' => '30.00', 'final_price' => '260.00'], 'new' => ['percentage' => null, 'final_price' => 300.0]],
            $vivas[1]['__diff__price_type_'.$lista1->id]
        );

        // Par inexistente (a1 con una lista que no tiene): no se registra nada.
        $instancia_sin_par = $this->actualizar_bbdd_con_cache([['id' => $a1->id, 'cost' => 1]]);
        $lista3 = PriceType::create(['name' => 'Lista 3 lote', 'user_id' => $this->tenant->id, 'percentage' => 50, 'position' => 3]);
        $this->invocar($instancia_sin_par, 'track_price_types_en_lote', [[[$a1->id, $lista3->id, 50, null]]]);
        $this->assertArrayNotHasKey('__diff__price_type_'.$lista3->id, $instancia_sin_par->get_articulos_para_actualizar()[0]);

        // Primer old gana: una segunda pasada con otro "nuevo" no pisa el old ya registrado.
        $this->invocar($instancia, 'track_price_types_en_lote', [[[$a1->id, $lista1->id, 99, null]]]);
        $vivas = $instancia->get_articulos_para_actualizar();
        $this->assertEquals(35.0, $vivas[0]['__diff__price_type_'.$lista1->id]['new']['percentage']);
    }

    /**
     * Pivot de proveedor: mismo criterio que antes.
     *
     * @return void
     */
    public function test_el_tracking_del_pivot_de_proveedor_registra_el_mismo_old_y_new_que_antes()
    {
        $prov = $this->providers['C'];
        $a1 = $this->articulo('Track prov 1');
        $a2 = $this->articulo('Track prov 2 sin pivot');

        DB::table('article_provider')->insert([
            'article_id' => $a1->id, 'provider_id' => $prov->id, 'provider_code' => 'VIEJO-1', 'cost' => 50,
        ]);

        $instancia = $this->actualizar_bbdd_con_cache([['id' => $a1->id, 'cost' => 1], ['id' => $a2->id, 'cost' => 2]]);

        $this->invocar($instancia, 'track_provider_pivots_en_lote', [[
            [$a1->id, $prov->id, ['provider_code' => 'NUEVO-1', 'cost' => 60]],
            [$a2->id, $prov->id, ['provider_code' => 'NUEVO-2', 'cost' => 70]],
        ]]);

        $vivas = $instancia->get_articulos_para_actualizar();

        $this->assertEquals(
            ['old' => ['provider_id' => $prov->id, 'provider_code' => 'VIEJO-1', 'cost' => 50], 'new' => ['provider_code' => 'NUEVO-1', 'cost' => 60]],
            $vivas[0]['__diff__provider_pivot']
        );
        $this->assertArrayNotHasKey('__diff__provider_pivot', $vivas[1]);

        // Mismo valor: no se registra.
        $instancia2 = $this->actualizar_bbdd_con_cache([['id' => $a1->id, 'cost' => 1]]);
        $this->invocar($instancia2, 'track_provider_pivots_en_lote', [[[$a1->id, $prov->id, ['provider_code' => 'VIEJO-1', 'cost' => 50]]]]);
        $this->assertArrayNotHasKey('__diff__provider_pivot', $instancia2->get_articulos_para_actualizar()[0]);
    }

    /**
     * Las tres lecturas del tracking hacen UNA consulta cada una, tenga el lote 2 o 20 artículos.
     *
     * @return void
     */
    public function test_las_consultas_del_tracking_no_crecen_con_la_cantidad_de_articulos()
    {
        $prov  = $this->providers['A'];
        $lista = PriceType::create(['name' => 'Lista consultas', 'user_id' => $this->tenant->id, 'percentage' => 30, 'position' => 1]);

        $consultas_con = function ($cantidad) use ($prov, $lista) {

            $cache = [];
            $ids = [];
            $pares_listas = [];
            $pares_prov = [];

            for ($i = 0; $i < $cantidad; $i++) {
                $a = $this->articulo('Consultas tracking '.$cantidad.'-'.$i);
                DB::table('article_discounts')->insert(['article_id' => $a->id, 'percentage' => 10, 'amount' => null]);
                DB::table('article_price_type')->insert(['article_id' => $a->id, 'price_type_id' => $lista->id, 'percentage' => 30, 'final_price' => 130]);
                DB::table('article_provider')->insert(['article_id' => $a->id, 'provider_id' => $prov->id, 'provider_code' => 'X', 'cost' => 1]);

                $cache[] = ['id' => $a->id, 'cost' => 1, 'discounts' => [['type' => '%', '__diff__discounts_percent' => ['old' => [10.0], 'new' => [5.0]]]]];
                $ids[] = $a->id;
                $pares_listas[] = [$a->id, $lista->id, 35, null];
                $pares_prov[] = [$a->id, $prov->id, ['provider_code' => 'Y', 'cost' => 2]];
            }

            $instancia = $this->actualizar_bbdd_con_cache($cache);

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->invocar($instancia, 'track_discounts_or_surchages_en_lote', [$ids, 'discounts', 'discounts_percent', 'article_discounts', 'percentage']);
            $this->invocar($instancia, 'track_price_types_en_lote', [$pares_listas]);
            $this->invocar($instancia, 'track_provider_pivots_en_lote', [$pares_prov]);

            $consultas = count(DB::getQueryLog());
            DB::disableQueryLog();

            // Y todos los artículos recibieron sus tres diffs.
            foreach ($instancia->get_articulos_para_actualizar() as $entrada) {
                $this->assertArrayHasKey('__diff__discounts_percent', $entrada);
                $this->assertArrayHasKey('__diff__price_type_'.$lista->id, $entrada);
                $this->assertArrayHasKey('__diff__provider_pivot', $entrada);
            }

            return $consultas;
        };

        $con_dos    = $consultas_con(2);
        $con_veinte = $consultas_con(20);

        $this->assertSame(3, $con_dos, 'Una consulta por relación, no una por artículo.');
        $this->assertSame($con_dos, $con_veinte);
    }

    /**
     * El defecto de las referencias: después del merge "última fila gana" los diffs tienen que
     * caer en las entradas fusionadas (las vivas), no en el array viejo. Con dos entradas del
     * mismo id, queda una sola y esa es la que recibe el diff.
     *
     * @return void
     */
    public function test_los_diffs_caen_en_las_entradas_fusionadas_por_ultima_fila_gana()
    {
        $a1 = $this->articulo('Merge 1');
        DB::table('article_discounts')->insert(['article_id' => $a1->id, 'percentage' => 10, 'amount' => null]);

        $instancia = (new ReflectionClass(ActualizarBBDD::class))->newInstanceWithoutConstructor();
        $instancia->observations = [];
        $instancia->import_history_id = 777;
        $instancia->articulos_para_actualizar_CACHE = [
            ['id' => $a1->id, 'cost' => 1],
            ['id' => $a1->id, 'cost' => 2, 'discounts' => [['type' => '%', '__diff__discounts_percent' => ['old' => [10.0], 'new' => [7.0]]]]],
        ];

        // Lo mismo que hace guardar_articulos(): merge y reindexado.
        $this->invocar($instancia, 'indexar_actualizados_por_id');
        $instancia->articulos_para_actualizar_CACHE = $this->invocar($instancia, 'merge_articulos_para_actualizar_ultima_fila_gana', [$instancia->articulos_para_actualizar_CACHE]);
        $this->invocar($instancia, 'indexar_actualizados_por_id');

        $this->invocar($instancia, 'track_discounts_or_surchages_en_lote', [[$a1->id], 'discounts', 'discounts_percent', 'article_discounts', 'percentage']);

        $vivas = $instancia->get_articulos_para_actualizar();

        $this->assertCount(1, $vivas);
        $this->assertSame(2, $vivas[0]['cost'], 'Última fila gana');
        $this->assertSame(['old' => [10.0], 'new' => [7.0]], $vivas[0]['__diff__discounts_percent']);
    }
}
