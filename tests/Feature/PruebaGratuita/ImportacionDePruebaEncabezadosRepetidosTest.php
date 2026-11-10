<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Jobs\ProcessArticleChunk;
use App\Models\ImportacionDePrueba;
use App\Services\Importacion\ImportacionDePruebaService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

/**
 * El circuito de WhatsApp frente a una planilla con encabezados repetidos (misión
 * importacion-ia-mapeo-y-recomendacion, 10/11/2026): solo pregunta por códigos de proveedor
 * repetidos de verdad, y las filas de encabezado no llegan a la importación.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionDePruebaEncabezadosRepetidosTest extends EmpresaTestCase
{
    private $dueno;
    private $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->dueno = auth()->user();

        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID'  => $this->dueno->id,
            'services.openai.embeddings_generacion_pausada' => true,
        ]);

        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);

        $dueno = $this->dueno;

        $this->app->bind(AiExcelAnalyzer::class, function () use ($dueno) {
            return new AnalizadorDeWhatsappFalso((int) $dueno->id);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            foreach (glob($archivo . '*') ?: [] as $ruta) {
                @unlink($ruta);
            }
        }

        parent::tearDown();
    }

    private function encabezado(): array
    {
        return ['Nro', 'CODIGO', 'Nombre', 'COSTO', 'MARCA', 'STOCK'];
    }

    private function fila_de_seccion(int $numero, string $titulo): array
    {
        return [$numero, 'Articulo', $titulo, null, null, null];
    }

    private function corrida(array $filas): ImportacionDePrueba
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->setTitle('Hoja 0')->fromArray($filas, null, 'A1', true);

        $uuid     = (string) Str::uuid();
        $relativa = 'imported_files/prueba_' . $uuid . '.xlsx';
        $destino  = storage_path('app/' . $relativa);

        (new Xlsx($libro))->save($destino);
        $libro->disconnectWorksheets();

        $this->archivos[] = $destino;
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '_');

        return ImportacionDePrueba::create([
            'uuid' => $uuid, 'user_id' => $this->dueno->id, 'support_ticket_id' => 20,
            'clave' => hash_file('sha256', $destino), 'archivo' => $relativa, 'nombre' => 'Catálogo.xlsx',
            'origen' => 'martin', 'estado' => 'analizar',
        ]);
    }

    public function test_sin_codigos_repetidos_no_pregunta_y_los_encabezados_no_se_importan(): void
    {
        $run = $this->corrida([
            $this->encabezado(),
            [1, 'S-1', 'Anzuelo uno', 100, 'SURFISH', 5],
            [2, 'S-2', 'Anzuelo dos', 200, 'SURFISH', 5],
            $this->fila_de_seccion(3, 'Sección uno'),
            [4, 'S-3', 'Anzuelo tres', 300, 'SURFISH', 5],
            $this->fila_de_seccion(5, 'Sección dos'),
            [6, 'S-4', 'Anzuelo cuatro', 400, 'SURFISH', 5],
            $this->fila_de_seccion(7, 'Sección tres'),
            [8, 'S-5', 'Anzuelo cinco', 500, 'SURFISH', 5],
        ]);

        Bus::fake();

        app(ImportacionDePruebaService::class)->avanzar($run);
        $run->refresh();

        $this->assertNotSame('repetidos', $run->estado);
        $this->assertSame('importando', $run->estado);

        $job = Bus::dispatched(ProcessArticleChunk::class)->first();
        $this->assertInstanceOf(ProcessArticleChunk::class, $job);

        $filas = $job->get_row_from_csv();
        $this->assertCount(5, $filas);
        $this->assertStringNotContainsString('Articulo', json_encode($filas));
        $this->assertStringNotContainsString('Secci', json_encode($filas, JSON_UNESCAPED_UNICODE));
    }

    public function test_con_un_codigo_repetido_de_verdad_pregunta_solo_por_ese(): void
    {
        $run = $this->corrida([
            $this->encabezado(),
            [1, 'S-1', 'Anzuelo uno', 100, 'SURFISH', 5],
            [2, 'S-2', 'Anzuelo dos', 200, 'SURFISH', 5],
            $this->fila_de_seccion(3, 'Sección uno'),
            [4, 'S-3', 'Anzuelo tres', 300, 'SURFISH', 5],
            $this->fila_de_seccion(5, 'Sección dos'),
            [6, 'S-9', 'Sin costo', null, 'SURFISH', 5],
            $this->fila_de_seccion(7, 'Sección tres'),
            [8, 'S-9', 'Sin costo', null, 'SURFISH', 5],
        ]);

        app(ImportacionDePruebaService::class)->avanzar($run);
        $run->refresh();

        $this->assertSame('repetidos', $run->estado);
        $this->assertCount(1, $run->preguntas);

        $texto = array_values($run->preguntas)[0]['texto'];

        $this->assertStringContainsString('«S-9»', $texto);
        $this->assertStringNotContainsString('Articulo', json_encode($run->preguntas));
    }
}

/**
 * El analizador real con una respuesta de IA fija para la planilla de estos tests.
 */
class AnalizadorDeWhatsappFalso extends AiExcelAnalyzer
{
    /**
     * @param  string $prompt
     * @return string
     */
    protected function call_claude(string $prompt): string
    {
        $mapeo = [];

        foreach (['Nro' => null, 'CODIGO' => 'codigo_de_proveedor', 'Nombre' => 'nombre', 'COSTO' => 'costo', 'MARCA' => null, 'STOCK' => 'stock_actual'] as $titulo => $propiedad) {
            $mapeo[] = ['excel_column' => $titulo, 'system_property' => $propiedad, 'confidence' => 0.95, 'interpretation_note' => null];
        }

        return json_encode([
            'column_mapping'        => $mapeo,
            'provider_id'           => null,
            'provider_confidence'   => 'bajo',
            'assistant_notes'       => [],
            'encabezados_repetidos' => [['columna' => 1, 'valor' => 'Articulo']],
        ]);
    }
}
