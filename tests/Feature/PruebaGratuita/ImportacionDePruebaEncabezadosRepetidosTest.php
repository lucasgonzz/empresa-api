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

    private function corrida(array $filas, ?string $original = null, bool $segunda_version = false): ImportacionDePrueba
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->setTitle('Hoja 0')->fromArray($filas, null, 'A1', true);

        $uuid     = (string) Str::uuid();
        $relativa = 'imported_files/prueba_' . $uuid . '.xlsx';
        $destino  = storage_path('app/' . $relativa);

        if ($original === null) {
            (new Xlsx($libro))->save($destino);
        } else {
            copy($original, $destino);
        }
        $libro->disconnectWorksheets();

        if ($segunda_version) {
            // Mismo ticket y mismas celdas, pero identidad documental nueva para reimportar.
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($destino));
            $this->assertTrue($zip->setArchiveComment('Reimportacion de regresion'));
            $this->assertTrue($zip->close());
            $this->assertNotSame(hash_file('sha256', $original), hash_file('sha256', $destino));
            $anterior = new \ZipArchive();
            $this->assertTrue($anterior->open($original));
            $this->assertTrue($zip->open($destino));
            $this->assertSame($anterior->numFiles, $zip->numFiles);
            for ($indice = 0; $indice < $anterior->numFiles; $indice++) {
                $entrada = $anterior->getNameIndex($indice);
                $this->assertSame($anterior->getFromName($entrada), $zip->getFromName($entrada));
            }
            $anterior->close();
            $zip->close();
        }

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
    /** @dataProvider mapeos_con_preguntas */
    public function test_cien_articulos_con_cuatro_cabeceras_crean_y_actualizan_unidades(bool $nombre_confundido): void
    {
        $original = __DIR__ . '/../../Import/fixtures/cabeceras_repetidas_100_articulos.xlsx';
        $this->assertSame('d6a474a0bab4c0ca5f9fdd083fe598388272bbbce70146ed5ff8d3456f9c7605', hash_file('sha256', $original));
        $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($original);
        $filas = $libro->getActiveSheet()->toArray(null, false, false, false);
        $libro->disconnectWorksheets();
        $this->assertCount(105, $filas);
        $unidad = \App\Models\UnidadMedida::firstOrCreate(['name' => 'Unidad']);
        $dueno = $this->dueno;
        $this->app->bind(AiExcelAnalyzer::class, function () use ($dueno, $nombre_confundido) {
            $analizador = new AnalizadorDeCienArticulosFalso((int) $dueno->id);
            $analizador->nombre_confundido = $nombre_confundido;
            return $analizador;
        });
        $esperados = [];
        foreach ($filas as $fila) {
            if (strpos($fila[0], 'IMPTEST-') === 0) {
                $esperados[$fila[0]] = $fila;
            }
        }
        $ids = [];

        for ($vuelta = 0; $vuelta < 2; $vuelta++) {
            $run = $this->corrida($filas, $original, $vuelta === 1);
            $ruta = storage_path('app/' . $run->archivo);
            Bus::fake();
            app(ImportacionDePruebaService::class)->avanzar($run);
            $run = $run->fresh();
            $this->assertSame('columnas', $run->estado);
            $respuestas = ['columna_0_0' => 'codigo_de_proveedor'];
            if ($nombre_confundido) {
                $respuestas['columna_0_2'] = 'nombre';
            }
            $respuestas['columna_0_7'] = 'stock_actual';
            $this->assertSame(array_keys($respuestas), array_keys($run->preguntas));
            $regla = \App\Http\Controllers\Helpers\import\excel\EncabezadosRepetidos::leer($ruta, 0);
            $this->assertNotNull($regla);
            $this->assertSame(4, $regla['filas']);
            $this->assertSame([22, 43, 64], $regla['ejemplos']);

            // El endpoint guarda la respuesta y la preparación retoma otra instancia de la corrida.
            config(['services.admin_api.api_key' => 'clave-regresion-cabeceras']);
            $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
            $this->postJson('/api/admin-sync/ai-excel-import/prueba-resolver/' . $run->uuid, [
                'user_id' => $this->dueno->id, 'support_ticket_id' => $run->support_ticket_id, 'revision' => $run->revision,
                'respuestas' => $respuestas,
            ], ['X-Admin-Api-Key' => 'clave-regresion-cabeceras'])->assertStatus(202);
            $run = $run->fresh();
            $this->assertSame('preparar', $run->estado);
            app(ImportacionDePruebaService::class)->avanzar($run);
            $run->refresh();
            $this->assertSame('importando', $run->estado);
            $inicios = Bus::dispatched(ProcessArticleChunk::class);
            $this->assertCount(1, $inicios);
            $primero = $inicios->first();
            $trabajos = [$primero];
            // Bus::fake conserva la cadena: sólo el primer lote figura como despachado.
            foreach ($primero->chained as $serializado) {
                $siguiente = unserialize($serializado);
                if ($siguiente instanceof ProcessArticleChunk) {
                    $trabajos[] = $siguiente;
                }
            }
            $normalizadas = [];
            foreach ($trabajos as $job) {
                $normalizadas = array_merge($normalizadas, $job->get_row_from_csv());
                $job->handle();
            }
            $this->assertCount(100, $normalizadas);
            // La planilla preparada agrega proveedor y separar_codigo antes del resto.
            foreach ($normalizadas as $fila) {
                $esperada = $esperados[$fila[2]];
                $this->assertSame($esperada[3], $fila[0]);
                foreach ([0 => 2, 1 => 3, 2 => 4, 4 => 5, 5 => 6, 6 => 7, 7 => 8, 8 => 9, 9 => 10] as $indice_original => $normalizada) {
                    $this->assertSame((string) $esperada[$indice_original], $fila[$normalizada]);
                }
            }

            $historial = \App\Models\ImportHistory::findOrFail($run->import_history_id);
            (new \App\Jobs\FinalizeArticleImport($this->dueno->id, $historial->id, $historial->import_status_id))->handle();
            $historial->refresh();
            $this->assertSame('terminado', $historial->status);
            $this->assertSame($vuelta === 0 ? 100 : 0, (int) $historial->created_models);
            $this->assertSame($vuelta === 0 ? 0 : 100, (int) $historial->updated_models);
            $this->assertSame(0, \App\Models\ImportConflict::where('import_history_id', $historial->id)->count());
            $articulos = \App\Models\Article::where('user_id', $this->dueno->id)
                ->whereIn('provider_code', array_keys($esperados))->with(['provider', 'category', 'iva'])->orderBy('provider_code')->get();
            $this->assertCount(100, $articulos);
            foreach ($articulos as $articulo) {
                $this->assertSame((int) $unidad->id, (int) $articulo->unidad_medida_id);
                $this->assertSame($esperados[$articulo->provider_code][2], $articulo->name);
                $this->assertSame($esperados[$articulo->provider_code][1], $articulo->bar_code);
                $esperada = $esperados[$articulo->provider_code];
                $this->assertSame($esperada[3], $articulo->provider->name);
                $this->assertSame($esperada[4], $articulo->category->name);
                $this->assertEquals((float) $esperada[5], (float) $articulo->cost);
                $this->assertEquals((float) $esperada[6], (float) $articulo->price);
                $this->assertEquals((float) $esperada[7], (float) $articulo->stock);
                $this->assertEquals((float) $esperada[9], (float) $articulo->iva->percentage);
            }
            $this->assertDatabaseMissing('articles', ['user_id' => $this->dueno->id, 'provider_code' => 'Código']);
            $this->assertDatabaseMissing('providers', ['user_id' => $this->dueno->id, 'name' => 'Proveedor']);
            $this->assertDatabaseMissing('categories', ['user_id' => $this->dueno->id, 'name' => 'Categoría']);
            if ($vuelta === 0) {
                $ids = $articulos->pluck('id')->all();
                // Reproduce los 100 existentes sin unidad que dejó la corrida QA.
                \App\Models\Article::whereIn('id', $ids)->update(['unidad_medida_id' => null]);
            } else {
                $this->assertSame($ids, $articulos->pluck('id')->all());
            }
        }
    }

    public function mapeos_con_preguntas(): array
    {
        return ['A y H' => [false], 'A C y H con nombre ambiguo' => [true]];
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

/** Respuesta IA fija; detección, sidecar, preparación y job son los de producción. */
class AnalizadorDeCienArticulosFalso extends AiExcelAnalyzer
{
    public $nombre_confundido = false;

    protected function call_claude(string $prompt): string
    {
        $mapeo = [];
        foreach ([
            'Código' => $this->nombre_confundido ? 'nombre' : 'codigo_de_proveedor', 'Código de barras' => 'codigo_de_barras',
            'Descripción' => 'nombre', 'Proveedor' => 'proveedor', 'Categoría' => 'categoria',
            'Costo' => 'costo', 'Precio de venta' => 'precio', 'Stock' => 'stock_actual',
            'Unidad' => 'unidad_medida', 'IVA' => 'iva',
        ] as $titulo => $propiedad) {
            $mapeo[] = ['excel_column' => $titulo, 'system_property' => $propiedad, 'confidence' => in_array($propiedad, ['codigo_de_proveedor', 'nombre', 'stock_actual'], true) ? 0.80 : 0.99, 'interpretation_note' => null];
        }

        return json_encode([
            'column_mapping' => $mapeo, 'provider_id' => null, 'provider_confidence' => 'bajo',
            'assistant_notes' => [], 'encabezados_repetidos' => [],
        ]);
    }
}
