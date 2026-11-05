<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible;
use App\Jobs\BorrarSemillaDePruebaJob;
use App\Jobs\RunExcelAnalysisJob;
use App\Models\Article;
use App\Models\ExcelAnalysisRun;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\EmpresaTestCase;

class CatalogoPdfTest extends EmpresaTestCase
{
    private $run;

    protected function setUp(): void
    {
        parent::setUp();

        $dueno = auth()->user();
        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID' => $dueno->id,
            'services.anthropic.api_key' => 'clave-falsa',
            'services.anthropic.base_url' => 'https://anthropic.test',
            'services.deepseek.api_key' => null,
        ]);
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($dueno, ['dias' => 7]);
        SemillaRegistroHelper::descartar_lote();
        Bus::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        if ($this->run !== null) {
            $base = pathinfo($this->run->excel_path, PATHINFO_FILENAME);
            foreach (glob(storage_path('app/imported_files/' . $base . '*')) as $archivo) {
                unlink($archivo);
            }
        }

        SemillaRegistroHelper::descartar_lote();
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    public function test_encola_pdf_y_revisa_toda_la_planilla_antes_de_borrar_la_semilla(): void
    {
        $dueno = auth()->user();
        SemillaRegistroHelper::abrir_lote($dueno->id, 'test-pdf');
        $semilla = Article::create(['name' => 'Artículo de ejemplo para PDF', 'user_id' => $dueno->id, 'status' => 'active']);
        SemillaRegistroHelper::cerrar_lote();

        $filas = [['00031', 'Tuerca para estante']];
        for ($indice = 2; $indice <= 1050; $indice++) {
            $filas[] = [(string) (10000 + $indice), 'Producto de prueba ' . $indice];
        }
        Http::fakeSequence('https://anthropic.test/v1/messages')
            ->push($this->respuesta_pdf($filas))
            ->push([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'column_mapping' => [
                        ['excel_column' => 'codigo_de_proveedor', 'system_property' => 'codigo_de_proveedor', 'confidence' => 'alto'],
                        ['excel_column' => 'nombre', 'system_property' => 'nombre', 'confidence' => 'alto'],
                    ],
                    'provider_id' => null,
                    'provider_confidence' => 'bajo',
                ])]],
            ]);

        $run = $this->subir_pdf_y_ejecutar_el_job();

        Http::assertSent(function (Request $request) {
            $documento = $request['messages'][0]['content'][0] ?? [];

            return ($documento['type'] ?? null) === 'document'
                && $documento['source']['media_type'] === 'application/pdf'
                && strpos(base64_decode($documento['source']['data']), '%PDF-') === 0
                && $request['output_config']['format']['type'] === 'json_schema'
                && $request['model'] === 'claude-sonnet-5-5';
        });
        $this->getJson('/api/ai-excel-import/analysis/' . $run->uuid)
            ->assertOk()
            ->assertJsonPath('estado', 'listo')
            ->assertJsonPath('contexto.start_row', 2)
            ->assertJsonPath('contexto.finish_row', 1051)
            ->assertJsonPath('resultado.filas_del_pdf', 1050);

        $lector = OpenSpoutCompatible::createXLSXReader();
        $lector->open(storage_path('app/' . $run->excel_path));
        try {
            foreach ($lector->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $indice => $fila) {
                    if ($indice === 2) {
                        $this->assertSame('00031', $fila->getCells()[0]->getValue());
                        break 2;
                    }
                }
            }
        } finally {
            $lector->close();
        }

        ExcelAnalysisRun::create([
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'user_id' => $dueno->id,
            'tipo' => 'recomendacion',
            'estado' => 'listo',
            'excel_path' => $run->excel_path,
        ]);
        $this->assertSame('pdf', PruebaHelper::origen_del_analisis($dueno->id, $run->excel_path));
        $this->assertDatabaseHas('articles', ['id' => $semilla->id, 'deleted_at' => null]);
        Bus::assertNotDispatched(BorrarSemillaDePruebaJob::class);

        (new RunExcelAnalysisJob($run->id))->handle();
        Http::assertSentCount(2);
    }

    public function test_una_respuesta_cortada_no_deja_planilla_importable(): void
    {
        $respuesta = $this->respuesta_pdf([['00031', 'Tuerca para estante']]);
        $respuesta['stop_reason'] = 'max_tokens';
        Http::fakeSequence('https://anthropic.test/v1/messages')->push($respuesta);

        $run = $this->subir_pdf_y_ejecutar_el_job();

        $this->assertSame('error', $run->estado);
        $this->assertStringContainsString('dividir el archivo', $run->error);
        $this->assertNull($run->resultado);
        $this->assertFileDoesNotExist(storage_path('app/' . $run->excel_path));
        Http::assertSentCount(1);
    }

    public function test_si_la_ia_omite_una_pagina_tampoco_deja_planilla_importable(): void
    {
        Http::fakeSequence('https://anthropic.test/v1/messages')
            ->push($this->respuesta_pdf([['00031', 'Tuerca para estante']], false));

        $run = $this->subir_pdf_y_ejecutar_el_job();

        $this->assertSame('error', $run->estado);
        $this->assertNull($run->resultado);
        $this->assertFileDoesNotExist(storage_path('app/' . $run->excel_path));
        Http::assertSentCount(1);
    }

    public function test_la_prueba_solo_encola_un_pdf_por_vez(): void
    {
        Bus::fake();
        Http::fake();

        $respuesta = $this->post('/api/ai-excel-import/analyze', ['excel_file' => $this->pdf()])
            ->assertStatus(202);
        $this->run = ExcelAnalysisRun::where('uuid', $respuesta->json('analysis_uuid'))->firstOrFail();

        $this->post('/api/ai-excel-import/analyze', ['excel_file' => $this->pdf()])
            ->assertStatus(409);

        Bus::assertDispatchedTimes(RunExcelAnalysisJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_una_cola_caida_no_deja_el_cupo_de_analisis_ocupado(): void
    {
        Http::fake();
        Bus::shouldReceive('dispatch')->once()->andThrow(
            new QueryException('insert into jobs', [], new \RuntimeException('Cola de prueba fuera de servicio'))
        );

        $this->post('/api/ai-excel-import/analyze', ['excel_file' => $this->pdf()])
            ->assertStatus(500);
        $fallida = ExcelAnalysisRun::where('user_id', auth()->id())->whereNotNull('payload->pdf_path')->latest('id')->firstOrFail();
        $this->assertSame('error', $fallida->estado);
        Storage::disk('local')->assertMissing($fallida->payload['pdf_path']);

        Bus::fake();
        $respuesta = $this->post('/api/ai-excel-import/analyze', ['excel_file' => $this->pdf()])
            ->assertStatus(202);
        $this->run = ExcelAnalysisRun::where('uuid', $respuesta->json('analysis_uuid'))->firstOrFail();
        Http::assertNothingSent();
    }

    public function test_pdf_es_solo_para_articulos_de_la_prueba(): void
    {
        Bus::fake();
        Http::fake();

        $this->post('/api/ai-excel-import/analyze', ['excel_file' => $this->pdf(), 'model' => 'client'])
            ->assertStatus(422);

        config(['app.FOR_USER' => 'demo']);
        $this->post('/api/ai-excel-import/analyze', ['excel_file' => $this->pdf()])
            ->assertStatus(422);

        Bus::assertNotDispatched(RunExcelAnalysisJob::class);
        Http::assertNothingSent();
    }

    private function subir_pdf_y_ejecutar_el_job(): ExcelAnalysisRun
    {
        Bus::fake([RunExcelAnalysisJob::class, BorrarSemillaDePruebaJob::class]);
        $respuesta = $this->post('/api/ai-excel-import/analyze', ['excel_file' => $this->pdf()])
            ->assertStatus(202);
        $this->run = ExcelAnalysisRun::where('uuid', $respuesta->json('analysis_uuid'))->firstOrFail();
        $this->assertSame('pendiente', $this->run->estado);
        Bus::assertDispatched(RunExcelAnalysisJob::class);
        Storage::disk('local')->assertExists($this->run->payload['pdf_path']);
        Http::assertNothingSent();

        (new RunExcelAnalysisJob($this->run->id))->handle();
        Storage::disk('local')->assertMissing($this->run->payload['pdf_path']);

        return $this->run->fresh();
    }

    private function pdf(): UploadedFile
    {
        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->Cell(0, 0, '00031 Tuerca para estante');
        $pdf->AddPage();
        $pdf->Cell(0, 0, 'Condiciones de venta');

        return UploadedFile::fake()->createWithContent('Catálogo de prueba.pdf', $pdf->Output('catalogo.pdf', 'S'));
    }

    private function respuesta_pdf(array $filas, bool $incluir_segunda_pagina = true): array
    {
        $paginas = [['numero' => 1, 'filas' => $filas]];
        if ($incluir_segunda_pagina) {
            $paginas[] = ['numero' => 2, 'filas' => []];
        }

        return [
            'stop_reason' => 'end_turn',
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['columnas' => ['codigo_de_proveedor', 'nombre'], 'paginas' => $paginas]),
            ]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 500],
        ];
    }
}
