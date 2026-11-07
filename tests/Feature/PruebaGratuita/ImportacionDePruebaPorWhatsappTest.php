<?php

namespace Tests\Feature\PruebaGratuita;

use App\Events\ImplementacionActualizada;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\excel\ExcelHeaderDetector;
use App\Jobs\ProcessArticleChunk;
use App\Jobs\BorrarSemillaDePruebaJob;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\RegistrarHitoDePruebaJob;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\EmbeddingRun;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImportacionDePrueba;
use App\Models\ImportHistory;
use App\Models\PruebaHito;
use App\Models\Provider;
use App\Services\Importacion\ImportacionDePruebaService;
use App\Services\ImageAssignment\CupoImagenesDePrueba;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    public function test_importa_descripcion_y_descuentos_laterales_con_una_sola_consulta(): void
    {
        $proveedor_ajeno = Provider::create(['name' => 'Otro proveedor', 'user_id' => $this->dueno->id]);
        $articulo_ajeno = Article::create([
            'name' => 'Artículo existente', 'user_id' => $this->dueno->id, 'status' => 'active',
            'provider_id' => $proveedor_ajeno->id, 'provider_code' => 'O-1',
        ]);
        $descuento_ajeno = $articulo_ajeno->article_discounts()->create([
            'provider_id' => $proveedor_ajeno->id, 'percentage' => 9, 'origen' => ArticleDiscount::ORIGEN_COMPRA,
        ]);
        $divo = Provider::create(['name' => 'DIVO', 'user_id' => $this->dueno->id]);
        $divo_existente = Article::create([
            'name' => 'Cerradura zincada', 'user_id' => $this->dueno->id, 'status' => 'active',
            'provider_id' => $divo->id, 'provider_code' => 'D-1',
        ]);
        $porcentaje_anterior = $divo_existente->article_discounts()->create([
            'provider_id' => $divo->id, 'percentage' => 8, 'origen' => ArticleDiscount::ORIGEN_IMPORT,
        ]);
        $monto_previo = $divo_existente->article_discounts()->create([
            'provider_id' => $divo->id, 'amount' => 37, 'origen' => ArticleDiscount::ORIGEN_COMPRA,
        ]);
        $bonificacion_de_otro_proveedor = $divo_existente->article_discounts()->create([
            'provider_id' => $proveedor_ajeno->id, 'percentage' => 4, 'origen' => ArticleDiscount::ORIGEN_COMPRA,
        ]);

        $archivo = $this->planilla([[
            ['proveedor', 'código', 'descripción', 'precio', null, null, 'moneda'],
            ['DIVO', 'D-1', 'Cerradura zincada', 8989.30, null, null, 'pesos', 'DESCUENTOS'],
            ['DIVO', 'D-2', 'Cerrojo', 9580, null, null, 'pesos', 'DIVO', '15 Y 22'],
            ['TOUZON (FARK)', 'T-1', 'Giratorio', 84873, null, null, 'pesos', 'TOUZON', 5],
            ['Otro proveedor', 'O-1', 'Artículo existente', 150, null, null, 'pesos'],
        ]]);
        $run = $this->corrida($archivo, 'analizar');
        $this->analizador([[
            'proveedor' => 0.99, 'codigo_de_proveedor' => 0.99, 'descripcion' => 0.60,
            'precio' => 0.97, 'columna_e' => 0, 'columna_f' => 0,
            'moneda' => 0.99, 'columna_h' => 0, 'columna_i' => 0,
        ]], true);
        $servicio = app(ImportacionDePruebaService::class);
        $servicio->avanzar($run);
        $run->refresh();

        $this->assertSame('columnas', $run->estado);
        $this->assertSame(['columna_0_3'], array_keys($run->preguntas));
        $aviso = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 20)->firstOrFail();
        $this->assertSame('¿Me confirmás si el precio de la columna D de «Hoja 0» es de venta o es el costo?', $aviso->texto);

        $this->postJson(self::BASE . 'prueba-resolver/' . $run->uuid, [
            'user_id' => $this->dueno->id, 'support_ticket_id' => 20, 'revision' => 1,
            'respuestas' => ['columna_0_3' => 'costo'],
        ], ['X-Admin-Api-Key' => self::CLAVE])->assertStatus(202);
        Bus::fake();
        $servicio->avanzar($run->fresh());
        $job = Bus::dispatched(ProcessArticleChunk::class)->first();
        $this->assertInstanceOf(ProcessArticleChunk::class, $job);
        $job->handle();

        foreach ([
            ['D-1', 'Cerradura zincada', 'DIVO', [15.0, 22.0]],
            ['D-2', 'Cerrojo', 'DIVO', [15.0, 22.0]],
            ['T-1', 'Giratorio', 'TOUZON (FARK)', [5.0]],
        ] as [$codigo, $nombre, $proveedor, $porcentajes]) {
            $articulo = Article::where('user_id', $this->dueno->id)->where('provider_code', $codigo)->firstOrFail();
            $this->assertSame($nombre, $articulo->name);
            $this->assertSame($proveedor, $articulo->provider->name);
            $descuentos = $articulo->article_discounts()->where('provider_id', $articulo->provider_id)
                ->whereNotNull('percentage')->orderBy('id')->get();
            $this->assertSame($porcentajes, $descuentos->pluck('percentage')->map(function ($porcentaje) {
                return (float) $porcentaje;
            })->all());
            $this->assertSame([$articulo->provider_id], $descuentos->pluck('provider_id')->unique()->values()->all());
            $this->assertSame([ArticleDiscount::ORIGEN_IMPORT], $descuentos->pluck('origen')->unique()->values()->all());
        }
        $this->assertDatabaseHas('article_discounts', ['id' => $descuento_ajeno->id, 'origen' => ArticleDiscount::ORIGEN_COMPRA]);
        $this->assertDatabaseHas('article_discounts', ['id' => $monto_previo->id, 'amount' => 37]);
        $this->assertDatabaseHas('article_discounts', ['id' => $bonificacion_de_otro_proveedor->id, 'percentage' => 4]);
        $this->assertDatabaseMissing('article_discounts', ['id' => $porcentaje_anterior->id]);
    }

    public function test_pendientes_indica_cuales_preguntas_quedaron_fuera_del_aviso(): void
    {
        $run = $this->corrida($this->planilla([[['Nombre'], ['Martillo']]]), 'columnas');
        $preguntas = [];
        for ($indice = 0; $indice < 7; $indice++) {
            $clave = 'columna_0_' . $indice;
            $preguntas[$clave] = ['clave' => $clave, 'texto' => '¿Qué dato es?', 'opciones' => [['valor' => 'ignorar', 'etiqueta' => 'Ignorar']]];
        }
        $run->update(['preguntas' => $preguntas, 'decisiones' => ['columna_0_0' => 'ignorar'], 'revision' => 1]);
        $consulta = self::BASE . 'prueba-pendientes?user_id=' . $this->dueno->id . '&support_ticket_id=20';
        $respuesta = $this->getJson($consulta, ['X-Admin-Api-Key' => self::CLAVE])->assertOk();
        $this->assertSame([true, true, true, true, true, false], array_column($respuesta->json('pendientes.0.preguntas'), 'ya_consultada'));
    }

    public function test_conserva_nombre_y_descripcion_si_existen_y_consulta_una_columna_con_datos_tardios(): void
    {
        $filas = [['Nombre', 'Descripción', '', 'Código de proveedor']];
        $filas[] = ['Cerradura', 'Detalle del acabado', '', 'D-1'];
        $filas = array_merge($filas, array_fill(0, 80, [null]), [['Cerrojo', 'Otro acabado', 'Dato tardío', 'D-2']]);
        $run = $this->corrida($this->planilla([$filas]), 'analizar');
        $this->analizador([['nombre' => 0.99, 'descripcion' => 0.99, 'columna_c' => null, 'codigo_de_proveedor' => 0.99]], true);
        app(ImportacionDePruebaService::class)->avanzar($run);
        $run->refresh();

        $this->assertSame('nombre', $run->hojas[0]['columnas'][0]['campo']);
        $this->assertSame('descripcion', $run->hojas[0]['columnas'][1]['campo']);
        $this->assertSame(['columna_0_2'], array_keys($run->preguntas));
        $this->assertStringContainsString('Dato tardío', $run->preguntas['columna_0_2']['texto']);
    }

    public function test_hitos_persisten_lectura_y_aprobacion_al_avanzar(): void
    {
        Event::fake([ImplementacionActualizada::class]);
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
        $hitos = $run->hitos;
        $this->assertSame(5, $hitos['imagenes']['etapa']);
        $hitos['imagenes']['etapa'] = 6;
        $run->update(['hitos' => $hitos]);
        $this->getJson('/api/implementacion')->assertOk()->assertJsonFragment([
            'id' => $run->uuid . '-imagenes', 'etapa' => 5,
        ]);
        $id = $run->uuid . '-migracion';
        $this->getJson('/api/implementacion')->assertOk()->assertJsonFragment(['id' => $id, 'visto' => false]);
        Event::assertNotDispatched(ImplementacionActualizada::class);
        // Ejecutamos los callbacks sin confirmar la transacción que aísla este test.
        $this->app->make('db.transactions')->commit(DB::connection()->getName());
        Event::assertDispatchedTimes(ImplementacionActualizada::class, 1);
        Event::assertDispatched(ImplementacionActualizada::class, function ($evento) {
            return $evento->broadcastOn()->name === 'private-implementacion.user.' . $this->dueno->id
                && $evento->broadcastAs() === 'ImplementacionActualizada'
                && $evento->broadcastWith() === [];
        });
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
        $this->assertSame(6, $run->fresh()->hitos['agente']['etapa']);
    }

    public function test_usa_el_saldo_para_buscar_imagenes_de_los_mas_nuevos_antes_de_indexar(): void
    {
        CupoImagenesDePrueba::editar((int) $this->dueno->id, 2);
        $run = $this->corrida($this->planilla([[['Nombre'], ['Artículo']]]), 'importando');
        $historial = ImportHistory::create([
            'user_id' => $this->dueno->id, 'status' => 'terminado', 'model_name' => 'article',
            'created_models' => 3, 'updated_models' => 0, 'processed_chunks' => 2, 'total_chunks' => 2,
            'matching_counts_json' => '{}',
        ]);
        $ids = [];
        foreach (['Primero', 'Segundo', 'Tercero'] as $nombre) {
            $ids[] = Article::create(['user_id' => $this->dueno->id, 'status' => 'active', 'name' => $nombre])->id;
        }
        $historial->articulos_creados()->attach($ids[0]);
        $historial->chunks()->create(['import_uuid' => $run->uuid])->articulos_creados()->attach($ids[1]);
        $historial->chunks()->create(['import_uuid' => $run->uuid])->articulos_creados()->attach($ids[2]);
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
        $this->assertSame(5, $run->hitos['imagenes']['etapa']);
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

    public function test_soporte_importa_despues_de_confirmar_y_no_borra_datos_de_ejemplo_del_negocio_convertido(): void
    {
        $cabeceras = ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
        $consulta = self::BASE . 'prueba-pendientes?automatizar_soporte=1&user_id=' . $this->dueno->id . '&support_ticket_id=';
        $this->getJson($consulta . '42', $cabeceras)->assertStatus(409);

        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        $ejemplo = Article::create(['user_id' => $this->dueno->id, 'name' => 'Ejemplo a conservar', 'status' => 'active']);
        SemillaRegistroHelper::cerrar_lote();
        $this->assertTrue(SemillaRegistroHelper::semilla_presente($this->dueno->id));

        $archivo = $this->planilla([[['Nombre', 'Barras'], ['Artículo desde soporte', '789123']]]);
        $datos = [
            'user_id' => $this->dueno->id, 'model' => 'article', 'automatizar_soporte' => 1,
            'support_ticket_id' => 42, 'importacion_clave' => hash_file('sha256', $archivo),
        ];
        $subir = function () use ($datos, $archivo, $cabeceras) {
            return $this->post(self::BASE . 'analyze', $datos + [
                'excel_file' => new UploadedFile($archivo, 'catalogo.xlsx', null, null, true),
            ], $cabeceras)->assertStatus(202)->assertJsonPath('automatizar_soporte', true);
        };
        $uuid = $subir()->json('analysis_uuid');
        $this->assertSame($uuid, $subir()->json('analysis_uuid'));
        $run = ImportacionDePrueba::where('uuid', $uuid)->firstOrFail();
        $this->archivos[] = storage_path('app/' . $run->archivo);
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '_');
        $this->analizador([['nombre' => 0.99, 'codigo_de_barras' => 0.99]]);
        $servicio = app(ImportacionDePruebaService::class);
        $servicio->avanzar($run);
        $run->refresh();
        $this->assertSame('confirmacion', $run->estado);
        $this->assertNull($run->import_history_id);
        $this->assertDatabaseMissing('articles', ['user_id' => $this->dueno->id, 'bar_code' => '789123']);

        $pregunta = $this->getJson($consulta . '42', $cabeceras)->assertOk()->assertJsonPath('automatizar_soporte', true)
            ->assertJsonPath('pendientes.0.tipo', 'confirmacion')->json('pendientes.0.preguntas.0');
        $this->assertSame('confirmacion', $pregunta['clave']);
        $this->assertStringContainsString('CONFIRMAR ' . $pregunta['codigo_confirmacion'], $pregunta['texto']);
        $this->assertArrayNotHasKey('huella_plan', $pregunta);
        $this->getJson($consulta . '43', $cabeceras)->assertOk()->assertJsonPath('pendientes', []);
        $this->getJson(str_replace('automatizar_soporte=1&', '', $consulta . '42'), $cabeceras)->assertStatus(409);

        $resolver = self::BASE . 'prueba-resolver/' . $uuid;
        $decision = ['automatizar_soporte' => 1, 'user_id' => $this->dueno->id, 'support_ticket_id' => 42,
            'revision' => $run->revision, 'respuestas' => ['confirmacion' => 'confirmar']];
        $this->postJson($resolver, array_replace($decision, ['support_ticket_id' => 43]), $cabeceras)->assertNotFound();
        $this->postJson($resolver, array_replace($decision, ['revision' => $run->revision + 1]), $cabeceras)->assertStatus(409);
        $this->postJson($resolver, $decision, $cabeceras)->assertStatus(202);
        $this->postJson($resolver, $decision, $cabeceras)->assertOk();

        Bus::fake();
        $servicio->avanzar($run->fresh());
        $run->refresh();
        $this->assertSame('importando', $run->estado);
        Bus::assertNotDispatched(BorrarSemillaDePruebaJob::class);
        $job = Bus::dispatched(ProcessArticleChunk::class)->first();
        $this->assertInstanceOf(ProcessArticleChunk::class, $job);
        $job->handle();
        $historial = ImportHistory::findOrFail($run->import_history_id);
        (new FinalizeArticleImport($this->dueno->id, $historial->id, $historial->import_status_id))->handle();
        Bus::assertNotDispatched(RegistrarHitoDePruebaJob::class, function ($job) {
            return $job->tipo === 'prueba.importo';
        });
        $servicio->avanzar($run->fresh());

        $this->assertSame('terminado', $run->fresh()->estado);
        $this->assertNull($run->fresh()->hitos);
        $this->assertDatabaseHas('articles', ['id' => $ejemplo->id]);
        $this->assertDatabaseHas('articles', ['user_id' => $this->dueno->id, 'bar_code' => '789123']);
        $this->assertDatabaseMissing((new PruebaHito())->getTable(), ['owner_id' => $this->dueno->id, 'tipo' => 'prueba.importo']);
        $resultado = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 42)->firstOrFail();
        $this->assertStringContainsString('1 artículo creado', $resultado->texto);
    }

    public function test_soporte_cancela_la_corrida_y_acepta_dueños_con_vencimiento_historico(): void
    {
        config(['app.FOR_USER' => null, 'services.admin_api.require_api_key' => false]);
        PruebaHelper::fijar_vencimiento($this->dueno->id, now()->subDay());
        $base = ['automatizar_soporte' => 1, 'user_id' => $this->dueno->id, 'support_ticket_id' => 20];
        $cabeceras = ['X-Admin-Api-Key' => self::CLAVE];
        $this->postJson(self::BASE . 'analyze', $base)->assertStatus(401);
        $this->getJson(self::BASE . 'prueba-pendientes?' . http_build_query($base), $cabeceras)
            ->assertOk()->assertJsonPath('automatizar_soporte', true);
        $this->getJson(self::BASE . 'prueba-pendientes?' . http_build_query(array_replace($base, ['user_id' => $this->dueno->id + 1])), $cabeceras)
            ->assertStatus(409);

        $run = $this->corrida($this->planilla([[['Nombre'], ['Cerradura']]]), 'analizar');
        $run->update(['modo_soporte' => true]);
        $this->analizador([['nombre' => 0.99]]);
        app(ImportacionDePruebaService::class)->avanzar($run);
        $run->refresh();
        $resolver = self::BASE . 'prueba-resolver/' . $run->uuid;
        $decision = $base + ['revision' => $run->revision, 'respuestas' => ['confirmacion' => 'cancelar']];
        $this->postJson($resolver, $decision, $cabeceras)->assertOk()->assertJsonPath('estado', 'cancelado');
        $this->postJson($resolver, $decision, $cabeceras)->assertOk()->assertJsonPath('estado', 'cancelado');
        $this->postJson($resolver, array_replace($decision, ['respuestas' => ['confirmacion' => 'confirmar']]), $cabeceras)->assertStatus(409);
        $this->assertNull($run->fresh()->import_history_id);
        $this->getJson(self::BASE . 'prueba-pendientes?' . http_build_query($base), $cabeceras)
            ->assertOk()->assertJsonPath('en_proceso', false);
    }

    public function test_si_cambia_el_plan_entre_la_confirmacion_y_el_inicio_soporte_vuelve_a_preguntar(): void
    {
        config(['app.FOR_USER' => null]);
        $run = $this->corrida($this->planilla([[['Nombre'], ['Cerradura']]]), 'analizar');
        $run->update(['modo_soporte' => true]);
        $this->analizador([['nombre' => 0.99]]);
        $servicio = app(ImportacionDePruebaService::class);
        $servicio->avanzar($run);
        $run->refresh();
        $codigo_anterior = $run->preguntas['confirmacion']['codigo_confirmacion'];
        $revision_anterior = $run->revision;

        $this->postJson(self::BASE . 'prueba-resolver/' . $run->uuid, [
            'automatizar_soporte' => 1, 'user_id' => $this->dueno->id, 'support_ticket_id' => 20,
            'revision' => $revision_anterior, 'respuestas' => ['confirmacion' => 'confirmar'],
        ], ['X-Admin-Api-Key' => self::CLAVE])->assertStatus(202);
        copy($this->planilla([[['Nombre'], ['Cerrojo'], ['Candado']]]), storage_path('app/' . $run->archivo));
        $servicio->avanzar($run->fresh());

        $run->refresh();
        $this->assertSame('confirmacion', $run->estado);
        $this->assertSame($revision_anterior + 1, $run->revision);
        $this->assertNotSame($codigo_anterior, $run->preguntas['confirmacion']['codigo_confirmacion']);
        $this->assertArrayNotHasKey('confirmacion', $run->decisiones);
        $this->assertNull($run->import_history_id);
    }

    public function test_los_avisos_de_soporte_funcionan_sin_extension_y_no_exponen_otros_avisos(): void
    {
        $this->dueno->extencions()->detach();
        $run = $this->corrida($this->planilla([[['Nombre'], ['Cerradura']]]), 'confirmacion');
        $run->update(['modo_soporte' => true]);
        $run->aviso('resultado', 'Aviso de soporte');
        AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 20)
            ->update(['created_at' => now()->subDays(2)]);
        $otro = AiAvisoDelAsistente::create([
            'owner_id' => $this->dueno->id, 'tipo' => AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA,
            'uuid' => Str::uuid()->toString(), 'texto' => 'Aviso de prueba', 'support_ticket_id' => 20,
        ]);
        AiAvisoDelAsistente::create([
            'owner_id' => $this->dueno->id, 'tipo' => AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA,
            'uuid' => Str::uuid()->toString(), 'texto' => 'Aviso de otro ticket', 'support_ticket_id' => 21,
            'modo_soporte' => true,
        ]);
        $base = '/api/admin-sync/asistente/avisos';
        $cabeceras = ['X-Admin-Api-Key' => self::CLAVE];
        $this->getJson($base . '-pendientes', $cabeceras)->assertStatus(403);
        $this->getJson($base . '-pendientes?solo_importaciones_de_soporte=1')->assertStatus(401);
        $this->getJson($base . '-pendientes?solo_importaciones_de_soporte=1', $cabeceras)->assertStatus(422);
        $pendientes = $base . '-pendientes?' . http_build_query(['solo_importaciones_de_soporte' => 1, 'support_ticket_ids' => [20]]);
        $aviso = $this->getJson($pendientes, $cabeceras)
            ->assertOk()->assertJsonCount(1, 'avisos')->assertJsonPath('avisos.0.support_ticket_id', 20)->json('avisos.0');
        $marca = ['solo_importaciones_de_soporte' => 1, 'whatsapp_message_id' => 'id-enviado'];
        $this->postJson($base . '/avisado', $marca + ['id' => 'importacion_lista:' . $otro->uuid], $cabeceras)->assertNotFound();
        $this->postJson($base . '/avisado', $marca + ['id' => $aviso['id']], $cabeceras)->assertOk();
        $this->postJson($base . '/avisado', $marca + ['id' => $aviso['id']], $cabeceras)->assertOk();
        $this->getJson($pendientes, $cabeceras)->assertOk()->assertJsonPath('avisos', []);
        $this->assertNull($otro->fresh()->avisado_por_whatsapp_at);
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

    private function analizador(array $por_hoja, bool $encabezado_real = false): void
    {
        $analizador = Mockery::mock(AiExcelAnalyzer::class);
        $analizador->shouldReceive('analyze')->times(count($por_hoja))->andReturnUsing(function ($ruta, $nombre, array $opciones) use ($por_hoja, $encabezado_real) {
            $columnas = $por_hoja[$opciones['hoja']];
            $encabezado = $encabezado_real ? ExcelHeaderDetector::detectar_en($ruta, $opciones['hoja'])
                : ['fila' => 1, 'columnas' => array_keys($columnas)];
            $mapeo = [];
            foreach (array_keys($columnas) as $posicion => $campo) {
                if ($columnas[$campo] === null) {
                    continue;
                }
                $mapeo[] = [
                    'system_property' => $campo, 'confidence' => $columnas[$campo],
                    'excel_column_index' => $posicion, 'excel_column' => $campo,
                ];
            }
            return [
                'column_mapping' => $mapeo, 'encabezado_detectado' => $encabezado,
                'columnas_sin_nombre' => $encabezado['columnas_sin_nombre'] ?? [],
            ];
        });
        $this->app->bind(AiExcelAnalyzer::class, function () use ($analizador) { return $analizador; });
    }
}
