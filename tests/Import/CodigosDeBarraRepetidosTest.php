<?php

namespace Tests\Import;

use App\Models\Article;

/**
 * Códigos de barra repetidos.
 *
 * Regla del negocio: pase lo que pase, un mismo código de barras del Excel tiene
 * que terminar afectando a UN SOLO artículo. Nunca N artículos, nunca N filas
 * pisándose entre sí sin dejar rastro.
 *
 * A diferencia del provider_code, el escalón bar_code NO tiene ninguna bandera de
 * configuración: si el código está duplicado EN BASE, siempre es ambiguo.
 *
 * Archivo 02_codigos_de_barra_repetidos.xlsx (7 filas):
 *   F2, F3, F4  bar_code 7799001  -> no existe en base, repetido 3 veces en el Excel
 *   F5, F6      bar_code 7790001  -> existe (A1), repetido 2 veces en el Excel
 *   F7, F8      bar_code 7790007  -> existe DUPLICADO en base (A7 y A8)
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe, argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class CodigosDeBarraRepetidosTest extends ImportTestCase
{
    const ARCHIVO = '02_codigos_de_barra_repetidos.xlsx';

    /**
     * Cada fila cae exactamente donde tiene que caer.
     *
     * @return void
     */
    public function test_clasificacion_de_las_filas()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $this->assertBuckets($import, [
            'creado_nuevo'             => 1,  // F2
            'merge_bar_code_repetido'  => 3,  // F3, F4 (sobre el nuevo) y F6 (sobre A1)
            'bar_code'                 => 1,  // F5
            'ambiguo'                  => 2,  // F7, F8
        ]);
    }

    /**
     * Tres filas con el mismo código de barras inexistente tienen que producir UN
     * artículo, no tres.
     *
     * @return void
     */
    public function test_tres_filas_con_el_mismo_bar_code_crean_un_solo_articulo()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $articulos = Article::where('user_id', $this->tenant->id)
                            ->where('bar_code', '7799001')
                            ->get();

        $this->assertCount(1, $articulos, 'Tres filas con bar_code 7799001 tienen que dejar un solo artículo');
    }

    /**
     * El merge es "la última fila gana": el artículo queda con los valores de F4,
     * que es la última aparición del código en el archivo.
     *
     * @return void
     */
    public function test_el_merge_deja_los_valores_de_la_ultima_fila()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $articulo = Article::where('user_id', $this->tenant->id)
                            ->where('bar_code', '7799001')
                            ->first();

        $this->assertNotNull($articulo);
        $this->assertSame('Barcode repetido nuevo v3', $articulo->name);
        $this->assertDecimal(300, $articulo->cost);
        $this->assertDecimal(30, $articulo->stock);
    }

    /**
     * Dos filas con un código de barras que YA existe tienen que actualizar ese
     * único artículo, sin crear ninguno nuevo con el mismo código.
     *
     * @return void
     */
    public function test_bar_code_existente_repetido_actualiza_un_solo_articulo()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $con_ese_codigo = Article::where('user_id', $this->tenant->id)
                                    ->where('bar_code', '7790001')
                                    ->get();

        $this->assertCount(1, $con_ese_codigo, 'No se tiene que crear un segundo artículo con 7790001');

        $a1 = $this->recargar('A1');

        /* F6 es la última aparición: costo 250, stock 25. */
        $this->assertSame('Art unico prov A por bc v2', $a1->name);
        $this->assertDecimal(250, $a1->cost);
        $this->assertDecimal(25, $a1->stock);
    }

    /**
     * Si el código de barras está duplicado EN BASE, la importación no adivina:
     * saltea la fila, no toca ninguno de los dos artículos y deja el conflicto
     * registrado para el modal de resultado.
     *
     * @return void
     */
    public function test_bar_code_duplicado_en_base_es_ambiguo_y_no_toca_nada()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $this->assertDecimal(700, $this->recargar('A7')->cost, 'A7 no se tiene que tocar');
        $this->assertDecimal(70,  $this->recargar('A7')->stock);
        $this->assertDecimal(800, $this->recargar('A8')->cost, 'A8 no se tiene que tocar');
        $this->assertDecimal(80,  $this->recargar('A8')->stock);

        $this->assertSame(2, $this->conflictos($import, 'ambiguo'), 'F7 y F8 tienen que quedar reportadas');
    }

    /**
     * Contando todo: el archivo tiene 7 filas y solo puede haber dejado 1 artículo nuevo.
     *
     * @return void
     */
    public function test_en_total_se_crea_un_solo_articulo()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $this->assertCount(1, $this->articulos_creados());
    }

    /**
     * Reimportar el mismo archivo no puede duplicar nada: la segunda corrida tiene
     * que actualizar el artículo que creó la primera.
     *
     * @return void
     */
    public function test_reimportar_el_mismo_archivo_no_duplica()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $creados_primera = $this->articulos_creados()->count();

        /* La segunda importación arranca con el índice ya sucio: hay que rearmarlo. */
        \Illuminate\Support\Facades\Cache::flush();
        \App\Http\Controllers\Helpers\import\article\ArticleIndexCache::reset_runtime_de_tests();

        $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $this->assertSame(
            $creados_primera,
            $this->articulos_creados()->count(),
            'La segunda importación del mismo archivo no puede crear artículos nuevos'
        );
    }
}
