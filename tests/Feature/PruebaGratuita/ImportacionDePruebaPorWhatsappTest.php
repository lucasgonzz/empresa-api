<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Jobs\ProcessArticleChunk;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImportacionDePrueba;
use App\Models\ImportHistory;
use App\Services\Importacion\ImportacionDePruebaService;
use App\Services\ImageAssignment\CupoImagenesDePrueba;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

class ImportacionDePruebaPorWhatsappTest extends EmpresaTestCase
{
    private const CLAVE = 'admin-prueba-importacion-whatsapp-test';
    private const BASE = '/api/admin-sync/ai-excel-import/';

    private $dueno;
    private $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dueno = auth()->user();
        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID' => $this->dueno->id,
            'services.admin_api.api_key' => self::CLAVE,
            'services.openai.embeddings_generacion_pausada' => true,
        ]);
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            foreach (glob($archivo . '*') ?: [] as $ruta) {
                @unlink($ruta);
            }
        }
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    public function test_reenvio_de_un_archivo_y_respuestas_parciales_por_ticket(): void
    {
        $archivo = $this->planilla([[['Producto', 'Importe', 'Detalle'], ['Martillo', 100, 'mango corto']]]);
        $datos = [
            'user_id' => $this->dueno->id, 'model' => 'article', 'segundo_plano' => 1, 'automatizar_prueba' => 1,
            'support_ticket_id' => 20, 'importacion_clave' => hash_file('sha256', $archivo),
        ];
        $cabeceras = ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
        $subir = function () use ($archivo, $datos, $cabeceras) {
            return $this->post(self::BASE . 'analyze', $datos + [
                'excel_file' => new UploadedFile($archivo, 'martillos.xlsx', null, null, true),
            ], $cabeceras)->assertStatus(202)->assertJsonPath('automatizar_prueba', true);
        };

        $uuid = $subir()->json('analysis_uuid');
        $this->assertSame($uuid, $subir()->json('analysis_uuid'));
        $run = ImportacionDePrueba::where('uuid', $uuid)->firstOrFail();
        $this->archivos[] = storage_path('app/' . $run->archivo);

        $this->analizador([['nombre' => 0.90, 'precio' => 0.89, 'descripcion' => 0.50]]);
        app(ImportacionDePruebaService::class)->avanzar($run);
        $consulta = self::BASE . 'prueba-pendientes?user_id=' . $this->dueno->id . '&support_ticket_id=';
        $respuesta = $this->getJson($consulta . '20', $cabeceras)->assertOk()->assertJsonPath('en_proceso', true);
        $preguntas = $respuesta->json('pendientes.0.preguntas');
        $this->assertSame(['columna_0_1', 'columna_0_2'], array_column($preguntas, 'clave'));
        $this->getJson($consulta . '21', $cabeceras)->assertOk()->assertJson(['pendientes' => [], 'en_proceso' => false]);

        $avisos = AvisosDelAsistenteHelper::pendientes($this->dueno, 'https://negocio.test');
        $aviso = collect($avisos)->firstWhere('support_ticket_id', 20);
        $this->assertNotNull($aviso);
        $this->assertNull($aviso['ruta']);
        $this->assertNull($aviso['url']);
        $resolver = self::BASE . 'prueba-resolver/' . $uuid;
        $base = ['user_id' => $this->dueno->id, 'support_ticket_id' => 20, 'revision' => 1];
        $this->postJson($resolver, array_merge($base, ['support_ticket_id' => 21, 'respuestas' => ['columna_0_1' => 'precio']]), $cabeceras)
            ->assertStatus(404);
        $this->postJson($resolver, $base + ['respuestas' => ['columna_0_1' => 'precio']], $cabeceras)->assertOk();
        $this->getJson($consulta . '20', $cabeceras)->assertOk()->assertJsonPath('pendientes.0.preguntas.0.clave', 'columna_0_2');
        $this->postJson($resolver, $base + ['respuestas' => ['columna_0_2' => 'descripcion']], $cabeceras)->assertStatus(202);
        $this->assertFalse(AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 20)->exists());
    }

    public function test_recorre_todas_las_hojas_y_resuelve_duplicados_antes_de_importar(): void
    {
        $archivo = $this->planilla([
            [
                ['Nombre', 'Barras', 'Proveedor', 'Precio'],
                ['Fila vieja por barras', '000123', 'A', 15],
                ['Mismo artículo', '', 'C', 15],
                ['Mismo nombre', '', 'D', 50],
                ['Producto sin precio', '', 'E', ''],
            ],
            [
                ['Nombre', 'Barras', 'Proveedor', 'Precio'],
                ['Fila final por barras', '000123', 'A', 30],
                ['Mismo artículo', '', 'C', 15],
                ['Mismo nombre', '', 'D', 90],
                ['Producto sin precio', '', 'E', ''],
            ],
        ]);
        $run = $this->corrida($archivo, 'analizar');
        $this->analizador(array_fill(0, 2, ['nombre' => 0.98, 'codigo_de_barras' => 0.99, 'codigo_de_proveedor' => 0.94, 'precio' => 0.92]));
        $servicio = app(ImportacionDePruebaService::class);
        $servicio->avanzar($run);
        $run->refresh();
        $this->assertSame('repetidos', $run->estado);
        $this->assertCount(1, $run->preguntas);
        $this->assertStringContainsString('«E»', array_values($run->preguntas)[0]['texto']);

        $run->update(['estado' => 'preparar', 'decisiones' => [array_key_first($run->preguntas) => 'mismo']]);
        Bus::fake();
        $servicio->avanzar($run);
        $run->refresh();
        $this->assertSame('importando', $run->estado);
        $historial = ImportHistory::findOrFail($run->import_history_id);
        $this->assertSame('Crear y actualizar', $historial->operacion_a_realizar);

        $job = Bus::dispatched(ProcessArticleChunk::class)->first();
        $this->assertInstanceOf(ProcessArticleChunk::class, $job);
        $filas = $job->get_row_from_csv();
        $this->assertCount(5, $filas);
        $nombres = array_column($filas, 2);
        $this->assertSame(['Mismo nombre', 'Fila final por barras', 'Mismo artículo', 'Mismo nombre', 'Producto sin precio'], $nombres);
        $this->assertSame(['1', '', '', '1', ''], array_column($filas, 1));
        $this->assertSame('000123', $filas[1][3]);
        $job->handle();
        $this->assertSame(2, Article::where('user_id', $this->dueno->id)->where('provider_code', 'D')->where('name', 'Mismo nombre')->count());
        $this->assertSame('Fila final por barras', Article::where('user_id', $this->dueno->id)->where('bar_code', '000123')->value('name'));
    }

    public function test_hitos_persisten_lectura_y_aprobacion_al_avanzar(): void
    {
        CupoImagenesDePrueba::editar((int) $this->dueno->id, 0);
        $run = $this->corrida($this->planilla([[['Nombre'], ['Artículo']]]), 'importando');
        $historial = ImportHistory::create([
            'user_id' => $this->dueno->id, 'status' => 'terminado', 'model_name' => 'article',
            'created_models' => 2, 'updated_models' => 1, 'processed_chunks' => 1, 'total_chunks' => 1,
            'matching_counts_json' => '{}',
        ]);
        $run->update(['import_history_id' => $historial->id]);
        $servicio = app(ImportacionDePruebaService::class);
        $servicio->avanzar($run);
        $run->refresh();
        $this->assertSame('indexar', $run->estado);
        $id = $run->uuid . '-migracion';
        $this->getJson('/api/implementacion')->assertOk()->assertJsonFragment(['id' => $id, 'visto' => false]);
        $this->postJson('/api/implementacion/' . $id . '/visto')->assertOk()->assertJson(['visto' => true]);
        $this->postJson('/api/implementacion/' . $id . '/aprobar')->assertStatus(409);
        Article::create([
            'user_id' => $this->dueno->id, 'status' => 'active', 'name' => 'Artículo de prueba indexado',
            'embedding' => json_encode([1.0]), 'embedding_generated_at' => now(),
        ]);
        $servicio->avanzar($run);
        $this->getJson('/api/implementacion')->assertOk()->assertJsonFragment(['id' => $id, 'visto' => true]);
        $agente = '/api/implementacion/' . $run->uuid . '-agente/aprobar';
        $this->postJson($agente)->assertOk()->assertJson(['aprobado' => true]);
        $this->postJson($agente)->assertOk()->assertJson(['aprobado' => true]);
        $this->assertTrue($run->fresh()->hitos['agente']['aprobado']);
    }

    public function test_usa_el_saldo_para_buscar_imagenes_de_los_mas_nuevos_antes_de_indexar(): void
    {
        CupoImagenesDePrueba::editar((int) $this->dueno->id, 2);
        $run = $this->corrida($this->planilla([[['Nombre'], ['Artículo']]]), 'importando');
        $historial = ImportHistory::create([
            'user_id' => $this->dueno->id, 'status' => 'terminado', 'model_name' => 'article',
            'created_models' => 3, 'updated_models' => 0, 'processed_chunks' => 1, 'total_chunks' => 1,
            'matching_counts_json' => '{}',
        ]);
        $ids = [];
        foreach (['Primero', 'Segundo', 'Tercero'] as $nombre) {
            $ids[] = Article::create(['user_id' => $this->dueno->id, 'status' => 'active', 'name' => $nombre])->id;
        }
        $historial->articulos_creados()->attach($ids);
        $run->update(['import_history_id' => $historial->id]);
        $servicio = app(ImportacionDePruebaService::class);
        $servicio->avanzar($run);
        $run->refresh();
        $this->assertSame('imagenes', $run->estado);
        $lote = ImageAssignmentRun::findOrFail($run->image_assignment_run_id);
        $this->assertSame([$ids[2], $ids[1]], $lote->items()->orderBy('orden')->pluck('article_id')->map(function ($id) { return (int) $id; })->all());
        $servicio->avanzar($run);
        $this->assertSame('imagenes', $run->fresh()->estado);
        $this->assertNull($run->fresh()->embedding_run_id);
        $this->assertFalse(EmbeddingRun::where('user_id', $this->dueno->id)->where('origen', 'prueba')->exists());

        $lote->items()->where('article_id', $ids[2])->update(['status' => ImageAssignmentItem::STATUS_ASIGNADA]);
        $lote->items()->where('article_id', $ids[1])->update(['status' => ImageAssignmentItem::STATUS_A_REVISAR]);
        $lote->update(['status' => ImageAssignmentRun::STATUS_TERMINADA]);
        $servicio->avanzar($run);
        $run->refresh();
        $this->assertSame('indexar', $run->estado);
        $this->assertSame((int) $lote->id, $run->hitos['imagenes']['accion']['lote_id']);
        $this->assertStringContainsString('1 imagen asignada. 1 sugerencia', $run->hitos['imagenes']['detalle']);
    }

    public function test_requiere_firma_aunque_admin_sync_legacy_no_la_exija(): void
    {
        config(['services.admin_api.require_api_key' => false]);
        $this->postJson(self::BASE . 'analyze', ['automatizar_prueba' => 1])->assertStatus(401);
        $this->getJson(self::BASE . 'prueba-pendientes', ['X-Admin-Api-Key' => 'incorrecta'])->assertStatus(401);
        $this->postJson(self::BASE . 'prueba-resolver/' . Str::uuid(), [])->assertStatus(401);
    }

    private function corrida(string $archivo, string $estado): ImportacionDePrueba
    {
        $uuid = (string) Str::uuid();
        $relativa = 'imported_files/prueba_' . $uuid . '.xlsx';
        $destino = storage_path('app/' . $relativa);
        copy($archivo, $destino);
        $this->archivos[] = $destino;
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '_');

        return ImportacionDePrueba::create([
            'uuid' => $uuid, 'user_id' => $this->dueno->id, 'support_ticket_id' => 20, 'clave' => hash_file('sha256', $archivo),
            'archivo' => $relativa, 'nombre' => 'Catálogo.xlsx', 'origen' => 'martin', 'estado' => $estado,
        ]);
    }

    private function planilla(array $hojas): string
    {
        $libro = new Spreadsheet();
        foreach ($hojas as $indice => $filas) {
            $hoja = $indice === 0 ? $libro->getActiveSheet() : $libro->createSheet();
            $hoja->setTitle('Hoja ' . $indice)->fromArray($filas);
        }
        $archivo = tempnam(sys_get_temp_dir(), 'prueba_import_');
        (new Xlsx($libro))->save($archivo);
        $libro->disconnectWorksheets();
        $this->archivos[] = $archivo;

        return $archivo;
    }

    private function analizador(array $por_hoja): void
    {
        $analizador = Mockery::mock(AiExcelAnalyzer::class);
        $analizador->shouldReceive('analyze')->times(count($por_hoja))->andReturnUsing(function ($ruta, $nombre, array $opciones) use ($por_hoja) {
            $mapeo = [];
            foreach ($por_hoja[$opciones['hoja']] as $campo => $confianza) {
                $mapeo[] = [
                    'system_property' => $campo, 'confidence' => $confianza,
                    'excel_column_index' => count($mapeo), 'excel_column' => $campo,
                ];
            }
            return ['column_mapping' => $mapeo, 'encabezado_detectado' => ['fila' => 1, 'columnas' => array_keys($por_hoja[$opciones['hoja']])]];
        });
        $this->app->bind(AiExcelAnalyzer::class, function () use ($analizador) { return $analizador; });
    }
}
