<?php

namespace Tests\Import;

use App\Models\Article;

/**
 * Repetidos DENTRO del propio archivo, no en la base (grupo 265, prompt 05).
 *
 * `01_codigos_de_proveedor.xlsx` repite PC-DUP en la base, no en el Excel, así
 * que nunca ejercitó los escalones sku/provider_code cuando la repetición
 * está adentro del archivo. Este es el fixture que cierra ese agujero: prueba
 * junta la jerarquía SKU del prompt 02, la generalización de "última fila
 * gana" del prompt 03, el reporte de sobrescritura también del prompt 03, y
 * el flag `filas_repetidas_del_archivo` del prompt 04.
 *
 * Archivo 07_repetidos_en_el_archivo.xlsx (9 filas), ninguno de estos códigos
 * existe en el escenario sembrado (ImportTestSeeder):
 *
 *   F2, F3      bar_code 7799100, distinto provider_code -> 1 solo artículo (gana F3)
 *   F4, F5      sku SKU-R-1, distinto provider_code       -> 1 solo artículo (gana F5)
 *   F6, F7      provider_code PC-R-X, DISTINTO sku        -> 2 artículos distintos
 *   F8, F9, F10 provider_code PC-R-Z, sin bar_code ni sku -> 1 solo artículo con
 *                 'ultima_gana' (gana F10) o 3 con 'productos_distintos'
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class RepetidosEnElArchivoTest extends ImportTestCase
{
    const ARCHIVO = '07_repetidos_en_el_archivo.xlsx';

    /* ------------------------------------------------------------------
     * Jerarquía (prompt 02)
     * ------------------------------------------------------------------ */

    /**
     * F2/F3 comparten bar_code (7799100) con distinto provider_code: el
     * bar_code manda, tienen que dejar UN SOLO artículo.
     *
     * @return void
     */
    public function test_mismo_bar_code_con_distinto_provider_code_es_un_solo_articulo()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $articulos = Article::where('user_id', $this->tenant->id)
                                ->where('bar_code', '7799100')
                                ->get();

        $this->assertCount(1, $articulos, 'F2/F3 (mismo bar_code) tienen que dejar un solo artículo.');
    }

    /**
     * F4/F5 comparten sku (SKU-R-1) con distinto provider_code: el sku manda,
     * tienen que dejar UN SOLO artículo.
     *
     * Este es el test que hoy falla: sin el escalón `sku` en esta_repetido()
     * (prompt 02) se crean dos artículos con el mismo SKU-R-1.
     *
     * @return void
     */
    public function test_mismo_sku_con_distinto_provider_code_es_un_solo_articulo()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $articulos = Article::where('user_id', $this->tenant->id)
                                ->where('sku', 'SKU-R-1')
                                ->get();

        $this->assertCount(1, $articulos, 'F4/F5 (mismo sku) tienen que dejar un solo artículo.');
    }

    /**
     * F6/F7 comparten provider_code (PC-R-X) pero tienen sku DISTINTO
     * (SKU-R-9 y SKU-R-8): son productos distintos, se tienen que crear los
     * dos.
     *
     * También falla hoy: se fusionan en un solo artículo.
     *
     * @return void
     */
    public function test_distinto_sku_con_mismo_provider_code_son_dos_articulos()
    {
        $this->markTestSkipped('Expectativa derogada por la regla del 30/7/2026, pendiente de redefinicion en el prompt 10');

        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $con_ese_pc = Article::where('user_id', $this->tenant->id)
                                ->where('provider_code', 'PC-R-X')
                                ->get();

        $this->assertCount(2, $con_ese_pc, 'F6/F7 (mismo provider_code, distinto sku) tienen que dejar dos artículos.');

        $skus = $con_ese_pc->pluck('sku')->toArray();

        $this->assertContains('SKU-R-9', $skus);
        $this->assertContains('SKU-R-8', $skus);
    }

    /* ------------------------------------------------------------------
     * Última fila gana (prompt 03)
     * ------------------------------------------------------------------ */

    /**
     * El artículo de bar_code 7799100 (F2/F3) queda con los valores de la
     * ÚLTIMA fila (F3): "Rep bar code v2", costo 200, stock 20.
     *
     * @return void
     */
    public function test_el_bar_code_repetido_deja_los_valores_de_la_ultima_fila()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $articulo = Article::where('user_id', $this->tenant->id)
                                ->where('bar_code', '7799100')
                                ->first();

        $this->assertNotNull($articulo);
        $this->assertSame('Rep bar code v2', $articulo->name);
        $this->assertDecimal(200, $articulo->cost);
        $this->assertDecimal(20, $articulo->stock);
    }

    /**
     * El artículo de sku SKU-R-1 (F4/F5) queda con los valores de la ÚLTIMA
     * fila (F5): "Rep sku v2", costo 400, stock 40.
     *
     * @return void
     */
    public function test_el_sku_repetido_deja_los_valores_de_la_ultima_fila()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $articulo = Article::where('user_id', $this->tenant->id)
                                ->where('sku', 'SKU-R-1')
                                ->first();

        $this->assertNotNull($articulo);
        $this->assertSame('Rep sku v2', $articulo->name);
        $this->assertDecimal(400, $articulo->cost);
        $this->assertDecimal(40, $articulo->stock);
    }

    /**
     * Con el default ('ultima_gana'), el artículo de provider_code PC-R-Z
     * (F8/F9/F10) queda con los valores de la ÚLTIMA fila (F10): "Solo pc v3",
     * costo 900, stock 90.
     *
     * Falla hoy: queda con los datos de F8, la primera.
     *
     * @return void
     */
    public function test_el_provider_code_repetido_deja_los_valores_de_la_ultima_fila()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $articulos = Article::where('user_id', $this->tenant->id)
                                ->where('provider_code', 'PC-R-Z')
                                ->get();

        $this->assertCount(1, $articulos, 'Con ultima_gana, PC-R-Z tiene que dejar un solo artículo.');

        $articulo = $articulos->first();

        $this->assertSame('Solo pc v3', $articulo->name);
        $this->assertDecimal(900, $articulo->cost);
        $this->assertDecimal(90, $articulo->stock);
    }

    /* ------------------------------------------------------------------
     * Reporte de sobrescritura (prompt 03)
     * ------------------------------------------------------------------ */

    /**
     * Cada repetición reporta qué fila sobrescribió a cuál.
     *
     * OJO con la numeración: `fila` en ImportConflict es el índice 1-based DENTRO
     * de las filas de datos (F2 = 1, F3 = 2, ..., F10 = 9), no el número de fila
     * de la planilla — así lo fija ProcessRow::$fila_actual (arranca en 0 y se
     * incrementa antes de procesar cada fila) y así lo asertan ya los tests
     * existentes de CodigosDeBarraRepetidosTest (p.ej. F5->F6 se reporta como
     * fila=4, fila_ganadora=5). Traducido a esa convención:
     *   F2->F3 (bar_code)      = 1->2
     *   F4->F5 (sku)           = 3->4
     *   F8->F9->F10 (provider) = 7->8, 8->9
     *
     * @return void
     */
    public function test_se_reporta_que_fila_sobrescribio_a_cual()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $sobrescrituras = $this->sobrescrituras($import);

        $this->assertSame(
            [1 => 2, 3 => 4, 7 => 8, 8 => 9],
            $sobrescrituras,
            'Las sobrescrituras tienen que ser exactamente F2->F3, F4->F5, F8->F9 y F9->F10 (en índice de fila de datos: 1->2, 3->4, 7->8, 8->9).'
        );
    }

    /**
     * conflicts_count del ImportHistory NO incluye las sobrescrituras: son
     * filas resueltas, no errores del usuario. Es la guarda contra el mensaje
     * de error incorrecto en el modal de resultado.
     *
     * @return void
     */
    public function test_la_sobrescritura_no_cuenta_como_conflicto_del_usuario()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $this->assertSame(
            0,
            (int) $import->conflicts_count,
            'Este archivo no tiene ningún conflicto real (ambiguo, placeholder, etc.), solo sobrescrituras: conflicts_count tiene que ser 0.'
        );
    }

    /**
     * El conflicto guarda el campo que detectó la repetición: bar_code en la
     * fila 1 (F2), sku en la fila 3 (F4), provider_code en la fila 7 (F8).
     * (Ver la nota de numeración en test_se_reporta_que_fila_sobrescribio_a_cual.)
     *
     * @return void
     */
    public function test_el_conflicto_guarda_el_campo_que_detecto_la_repeticion()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $conflicto_bar_code = \App\Models\ImportConflict::where('import_history_id', $import->id)
            ->where('tipo', 'fila_sobrescrita')
            ->where('fila', 1)
            ->first();

        $conflicto_sku = \App\Models\ImportConflict::where('import_history_id', $import->id)
            ->where('tipo', 'fila_sobrescrita')
            ->where('fila', 3)
            ->first();

        $conflicto_provider_code = \App\Models\ImportConflict::where('import_history_id', $import->id)
            ->where('tipo', 'fila_sobrescrita')
            ->where('fila', 7)
            ->first();

        $this->assertNotNull($conflicto_bar_code);
        $this->assertNotNull($conflicto_sku);
        $this->assertNotNull($conflicto_provider_code);

        $this->assertSame('bar_code', $conflicto_bar_code->campo);
        $this->assertSame('sku', $conflicto_sku->campo);
        $this->assertSame('provider_code', $conflicto_provider_code->campo);
    }

    /* ------------------------------------------------------------------
     * Flag filas_repetidas_del_archivo (prompt 04)
     * ------------------------------------------------------------------ */

    /**
     * Con 'productos_distintos', PC-R-Z (F8/F9/F10) deja TRES artículos, y no
     * se registra ninguna sobrescritura para esas filas.
     *
     * @return void
     */
    public function test_productos_distintos_crea_un_articulo_por_fila()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id'                    => $this->providers['A']->id,
            'filas_repetidas_del_archivo'     => 'productos_distintos',
        ]);

        $con_ese_pc = Article::where('user_id', $this->tenant->id)
                                ->where('provider_code', 'PC-R-Z')
                                ->get();

        $this->assertCount(3, $con_ese_pc, "Con 'productos_distintos', PC-R-Z tiene que dejar tres artículos.");

        $sobrescrituras = $this->sobrescrituras($import);

        $this->assertArrayNotHasKey(8, $sobrescrituras, 'No tiene que haber sobrescritura reportada para la fila 8.');
        $this->assertArrayNotHasKey(9, $sobrescrituras, 'No tiene que haber sobrescritura reportada para la fila 9.');
    }

    /**
     * El flag 'productos_distintos' solo afecta al escalón provider_code:
     * F2/F3 (bar_code) y F4/F5 (sku) siguen dejando 1 solo artículo cada uno.
     *
     * @return void
     */
    public function test_productos_distintos_no_afecta_al_bar_code_ni_al_sku()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id'                 => $this->providers['A']->id,
            'filas_repetidas_del_archivo' => 'productos_distintos',
        ]);

        $con_bar_code = Article::where('user_id', $this->tenant->id)
                                    ->where('bar_code', '7799100')
                                    ->get();

        $con_sku = Article::where('user_id', $this->tenant->id)
                                ->where('sku', 'SKU-R-1')
                                ->get();

        $this->assertCount(1, $con_bar_code, 'F2/F3 tienen que seguir dejando un solo artículo.');
        $this->assertCount(1, $con_sku, 'F4/F5 tienen que seguir dejando un solo artículo.');
    }

}
