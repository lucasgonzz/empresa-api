<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible as Writer;
use App\Models\Article;
use App\Models\ImportHistory;
use Illuminate\Http\UploadedFile;

class ReferenciasPorProveedorTest extends ImportTestCase
{
    protected function importar_filas(array $filas, array $config = [])
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('referencias_proveedor_') . '.xlsx';
        $writer = Writer::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(Writer::createRowFromArray(['Barras', 'SKU', 'Código proveedor', 'Nombre', 'Costo', 'Precio', 'Stock', 'IVA']));
        foreach ($filas as $fila) {
            $writer->addRow(Writer::createRowFromArray($fila));
        }
        $writer->close();
        $payload = array_merge(self::config_por_defecto(), self::columnas(), [
            'models' => new UploadedFile($ruta, 'referencias-proveedor.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'start_row' => 2, 'finish_row' => count($filas) + 1, 'provider_id' => $this->providers['A']->id,
            'actualizar_articulos_de_otro_proveedor' => false,
        ], $config);
        $this->postJson('/api/article/excel/import', $payload)->assertOk();
        $import = ImportHistory::where('user_id', $this->tenant->id)->latest('id')->first();
        $this->assertSame('terminado', $import->fresh()->status);
        ArticleIndexCache::reset_runtime_de_tests();
        return $import;
    }

    public function test_excel_de_catalogo_resuelve_codigo_del_pivot_y_guarda_oferta_sin_cambiar_costo_vigente()
    {
        $article = $this->seed['A2'];
        $article->providers()->attach($this->providers['A']->id, ['provider_code' => 'SOLO-PIVOT-A', 'cost' => 180]);
        $total = Article::where('user_id', $this->tenant->id)->count();
        $this->importar_filas([['', '', 'SOLO-PIVOT-A', 'Nombre diferente del proveedor', 170, '', '', '']]);
        $this->assertSame($total, Article::where('user_id', $this->tenant->id)->count());
        $this->assertEquals(200, $article->fresh()->cost);
        $this->assertSame('PC-200', $article->fresh()->provider_code);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->providers['A']->id, 'provider_code' => 'SOLO-PIVOT-A', 'cost' => 170]);
    }

    public function test_excel_sin_costo_aprende_codigo_y_otro_import_lo_reconoce_sin_borrar_referencias()
    {
        $article = $this->seed['A2'];
        $article->providers()->attach($this->providers['A']->id, ['provider_code' => 'ANTERIOR-A', 'cost' => 180]);
        $this->importar_filas([[$article->bar_code, '', 'APRENDIDO-A', 'Nombre en lista A', '', '', '', '']]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->providers['A']->id, 'provider_code' => 'APRENDIDO-A', 'cost' => 180]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->providers['B']->id, 'provider_code' => 'PC-200', 'cost' => 200]);
        $total = Article::where('user_id', $this->tenant->id)->count();
        $this->importar_filas([['', '', 'APRENDIDO-A', 'Otro nombre en la lista', 175, '', '', '']]);
        $this->assertSame($total, Article::where('user_id', $this->tenant->id)->count());
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->providers['A']->id, 'provider_code' => 'APRENDIDO-A', 'cost' => 175]);
        $this->importar_filas([[$article->bar_code, '', '', 'Nombre en lista A', 165, '', '', '']]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->providers['A']->id, 'provider_code' => 'APRENDIDO-A', 'cost' => 165]);
    }

    public function test_excel_completa_codigo_legado_del_pivot_vacio_sin_pisar_su_costo()
    {
        $article = $this->seed['A2'];
        $article->providers()->attach($this->providers['B']->id, ['provider_code' => null, 'cost' => 150]);
        $this->importar_filas([[$article->bar_code, '', 'NUEVO-A', 'Nombre en lista A', 170, '', '', '']]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->providers['B']->id, 'provider_code' => 'PC-200', 'cost' => 150]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->providers['A']->id, 'provider_code' => 'NUEVO-A', 'cost' => 170]);
    }

    public function test_segunda_fila_del_mismo_chunk_usa_referencia_que_aprendio_la_primera_aun_si_no_actualiza_el_articulo()
    {
        $article = $this->seed['A2'];
        $total = Article::where('user_id', $this->tenant->id)->count();
        $this->importar_filas([
            [$article->bar_code, '', 'APRENDIDO-EN-CHUNK', 'Primera descripción', 170, '', '', ''],
            ['', '', 'APRENDIDO-EN-CHUNK', 'Otra descripción del proveedor', 160, '', '', ''],
        ], ['filas_repetidas_del_archivo' => 'productos_distintos']);
        $this->assertSame($total, Article::where('user_id', $this->tenant->id)->count());
        $this->assertEquals(200, $article->fresh()->cost);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->providers['A']->id, 'provider_code' => 'APRENDIDO-EN-CHUNK', 'cost' => 160]);
    }
}
