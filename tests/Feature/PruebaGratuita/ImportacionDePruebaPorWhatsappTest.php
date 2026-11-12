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
use App\Models\Address;
use App\Models\Advise;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\EmbeddingRun;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImportacionDePrueba;
use App\Models\ImportHistory;
use App\Models\PriceType;
use App\Models\PruebaHito;
use App\Models\Provider;
use App\Services\Importacion\ImportacionDePruebaService;
use App\Services\Importacion\PlanillaDePrueba;
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
        /*
         * Misión prueba-e2e-campana-imperio (ítem P): en la prueba, una columna «precio» a secas ya no se da
         * por precio de venta sin preguntar. Este test mide las hojas y los repetidos, no esa pregunta (la
         * cubre ImportacionDePruebaMargenYFilasQueNoSonArticulosTest), así que el precio llega confirmado por
         * el mapeo previo, como cuando soporte ya lo habló con el cliente.
         */
        $run->update(['columnas_previas' => [
            ['hoja' => 'Hoja 0', 'columna' => 'D', 'campo' => 'precio'],
            ['hoja' => 'Hoja 1', 'columna' => 'D', 'campo' => 'precio'],
        ]]);
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
        /*
         * Misión prueba-e2e-campana-imperio (11/11/2026): al contestar «es el costo», la prueba ahora pregunta
         * también el margen de ganancia. Este test mide la descripción y los descuentos laterales, no esa
         * pregunta (la cubre ImportacionDePruebaMargenYFilasQueNoSonArticulosTest), así que la corrida nace con
         * ella ya contestada «lo cargo yo después», que es el comportamiento de siempre.
         */
        $run->update(['decisiones' => [PlanillaDePrueba::CLAVE_MARGEN => PlanillaDePrueba::MARGEN_DESPUES]]);
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
        // Texto en palabras del dueño desde la misión prueba-e2e-campana-imperio (ítem O): sin letra de columna ni hoja.
        $this->assertSame('En tu lista hay una columna que se llama «precio» (por ejemplo: 8.989,30 y 9.580,00).'
            . ' ¿Es lo que te cobra el proveedor o es a lo que vendés vos?'
            . ' Si es otra cosa, decime cuál; y si no la querés cargar, decime que no la cargue.', $aviso->texto);

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
        // Desde la misión prueba-e2e-campana-imperio (ítem O) un aviso lleva tres preguntas como mucho, no seis.
        $this->assertSame([true, true, false, false, false, false], array_column($respuesta->json('pendientes.0.preguntas'), 'ya_consultada'));
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

    public function test_stock_existente_aclara_codigos_reconfirma_cambios_y_solo_aplica_cantidades_confirmadas(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $principal = Address::create(['user_id' => $this->dueno->id, 'street' => 'Depósito Principal']);
        $otro = Address::create(['user_id' => $this->dueno->id, 'street' => 'Depósito Secundario']);
        $articulos = collect(['reel-a' => 4, 'reel-b' => 2, 'vacio' => 6, 'cero' => 3, 'aviso' => 0, 'igual' => 8])
            ->map(function ($stock, $sku) use ($principal) {
                $articulo = Article::create([
                    'user_id' => $this->dueno->id, 'name' => $sku, 'sku' => $sku, 'stock' => $stock,
                    'price' => 10, 'status' => 'active',
                ]);
                $articulo->addresses()->attach($principal->id, ['amount' => $stock, 'stock_min' => 2]);
                return $articulo;
            });
        $articulos['reel-a']->addresses()->attach($otro->id, ['amount' => 5]);
        $articulos['reel-a']->update(['stock' => 9]);
        $aviso = Advise::create(['article_id' => $articulos['aviso']->id, 'email' => 'aviso@example.test']);

        $archivo = $this->planilla([[
            ['SKU', 'Stock contado', 'Precio'],
            ['reel-a', 7, 999], ['reel-a', 11, 999], ['vacio', null, 999], ['cero', 0, 999],
            ['nuevo', 12, 999], ['aviso', 1, 999], ['igual', 8, 999],
        ]], true);
        $cabeceras = ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
        $datos = ['automatizar_soporte' => 1, 'user_id' => $this->dueno->id, 'support_ticket_id' => 74];
        $subida = $datos + ['model' => 'article', 'alcance' => 'stock_existente', 'importacion_clave' => hash_file('sha256', $archivo)];
        $subir = function (string $ruta, array $cabeceras) use ($subida, $archivo) {
            return $this->post(self::BASE . $ruta, $subida + [
                'excel_file' => new UploadedFile($archivo, 'inventario.xlsx', null, null, true),
            ], $cabeceras);
        };
        $subir('analyze-stock-existente', ['Accept' => 'application/json'])->assertUnauthorized();
        $subir('analyze', $cabeceras)->assertStatus(422);
        $uuid = $subir('analyze-stock-existente', $cabeceras)->assertStatus(202)->assertJsonPath('estado', 'analizar')
            ->assertJsonPath('alcance', 'stock_existente')->json('analysis_uuid');
        $subir('analyze-stock-existente', $cabeceras)->assertStatus(202)->assertJsonPath('analysis_uuid', $uuid);
        $run = ImportacionDePrueba::where('uuid', $uuid)->firstOrFail();
        $this->assertSame('stock_analizar', $run->estado);
        $this->archivos[] = storage_path('app/' . $run->archivo);
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '_');
        $this->analizador([['sku' => 0.99, 'stock_actual' => 0.99, 'precio' => 0.99]], true);

        $consulta = self::BASE . 'prueba-pendientes?' . http_build_query($datos);
        $resolver = function (array $respuestas) use ($datos, $uuid, $run, $cabeceras) {
            return $this->postJson(self::BASE . 'prueba-resolver/' . $uuid,
                $datos + ['revision' => $run->fresh()->revision, 'respuestas' => $respuestas], $cabeceras);
        };
        $servicio = app(ImportacionDePruebaService::class);
        $servicio->avanzar($run);
        $this->getJson($consulta, $cabeceras)->assertOk()
            ->assertJsonPath('alcances_soporte', ['stock_existente'])
            ->assertJsonPath('pendientes.0.alcance', 'stock_existente')
            ->assertJsonPath('pendientes.0.preguntas.0.clave', 'stock_deposito');
        $resolver(['stock_deposito' => 'd' . $principal->id])->assertStatus(202);
        $servicio->avanzar($run->fresh());
        $preguntas = $this->getJson($consulta, $cabeceras)->assertOk()->assertJsonPath('pendientes.0.tipo', 'repetidos')
            ->json('pendientes.0.preguntas');
        $this->assertSame(['stock_codigo_fila_0_2', 'stock_codigo_fila_0_3', 'stock_codigo_fila_0_6'], array_column($preguntas, 'clave'));
        $this->assertTrue($preguntas[1]['acepta_codigo_articulo']);
        $resolver([$preguntas[1]['clave'] => 'codigo:inexistente'])->assertStatus(422);
        $resolver([
            $preguntas[0]['clave'] => 'usar_original', $preguntas[1]['clave'] => 'codigo:reel-b', $preguntas[2]['clave'] => 'omitir',
        ])->assertStatus(202);
        $servicio->avanzar($run->fresh());
        $this->assertSame('stock_confirmacion', $run->fresh()->estado);
        $confirmacion = $run->fresh()->preguntas['confirmacion'];
        $this->assertStringContainsString('3 artículos existentes', $confirmacion['texto']);
        $this->assertStringContainsString('avisos de reposición', $confirmacion['texto']);
        $this->assertEquals(9, $articulos['reel-a']->fresh()->stock);

        // Un cambio real en el depósito después de la vista previa invalida la primera confirmación.
        $articulos['reel-a']->addresses()->updateExistingPivot($principal->id, ['amount' => 3]);
        $articulos['reel-a']->update(['stock' => 8]);
        $resolver(['confirmacion' => 'confirmar'])->assertStatus(202);
        $servicio->avanzar($run->fresh());
        $this->assertSame('stock_confirmacion', $run->fresh()->estado);
        $this->assertNotSame($confirmacion['codigo_confirmacion'], $run->fresh()->preguntas['confirmacion']['codigo_confirmacion']);
        $this->assertEquals(8, $articulos['reel-a']->fresh()->stock);

        $resolver(['confirmacion' => 'confirmar'])->assertStatus(202);
        $servicio->avanzar($run->fresh());
        $this->assertSame('terminado', $run->fresh()->estado);
        $this->assertNull($run->fresh()->import_history_id);
        $this->assertEquals(12, $articulos['reel-a']->fresh()->stock);
        $this->assertEquals(11, $articulos['reel-b']->fresh()->stock);
        $this->assertEquals(6, $articulos['vacio']->fresh()->stock);
        $this->assertEquals(0, $articulos['cero']->fresh()->stock);
        $this->assertEquals(0, $articulos['aviso']->fresh()->stock);
        $this->assertDatabaseHas('advises', ['id' => $aviso->id]);
        $this->assertDatabaseHas('articles', ['id' => $articulos['reel-a']->id, 'price' => 10, 'name' => 'reel-a']);
        $this->assertDatabaseMissing('articles', ['user_id' => $this->dueno->id, 'sku' => 'nuevo']);
        $this->assertDatabaseHas('address_article', ['article_id' => $articulos['reel-a']->id, 'address_id' => $otro->id, 'amount' => 5]);
        $this->assertDatabaseHas('address_article', ['article_id' => $articulos['reel-a']->id, 'address_id' => $principal->id, 'amount' => 7, 'stock_min' => 2]);
        $this->assertDatabaseHas('stock_movements', ['article_id' => $articulos['reel-a']->id, 'to_address_id' => $principal->id, 'amount' => 4]);
        $resultado = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 74)->firstOrFail();
        $this->assertStringContainsString('Apliqué y verifiqué 3 ajustes', $resultado->texto);
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

    /**
     * Mapeo previo de Martín que cubre todas las columnas dudosas: no sale ninguna pregunta de
     * columnas, la corrida pasa directo a la confirmación y el mapeo final usa los campos previos
     * (incluido un depósito por su etiqueta y una columna ignorada).
     */
    public function test_mapeo_previo_completo_no_pregunta_columnas_y_va_a_confirmar(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        // Calle sin tildes a propósito: el nombre de columna del importador sale de strtolower() y,
        // con una tilde, el texto de la confirmación falla al guardarse como JSON (defecto previo, aparte).
        $deposito = Address::create(['user_id' => $this->dueno->id, 'street' => 'Galpon Norte']);
        $archivo = $this->planilla_del_ticket();
        $previas = [
            ['hoja' => 'Hoja 0', 'columna' => 'A', 'campo' => 'ignorar'],
            ['hoja' => 'HOJA 0', 'columna' => 'b', 'campo' => 'nombre'],
            ['columna' => 'C', 'campo' => 'precio'],
            ['columna' => 'D', 'campo' => 'Depósito Galpón Norte'],
        ];
        $this->subir_de_soporte($archivo, $previas)->assertStatus(202)
            ->assertJsonPath('columnas_previas', 4)->assertJsonPath('corrida_existente', false);
        // El reenvío idempotente no pisa lo guardado y avisa que la corrida ya existía.
        $respuesta = $this->subir_de_soporte($archivo, [['columna' => 'A', 'campo' => 'nombre']])->assertStatus(202)
            ->assertJsonPath('columnas_previas', 4)->assertJsonPath('corrida_existente', true);
        $run = $this->corrida_subida($respuesta->json('analysis_uuid'));
        $this->assertSame([
            ['hoja' => 'Hoja 0', 'columna' => 'A', 'campo' => null],
            ['hoja' => 'Hoja 0', 'columna' => 'B', 'campo' => 'nombre'],
            ['hoja' => 'Hoja 0', 'columna' => 'C', 'campo' => 'precio'],
            ['hoja' => 'Hoja 0', 'columna' => 'D', 'campo' => 'address_' . $deposito->id . '_amount'],
        ], $run->columnas_previas);

        // Sin el mapeo previo, este análisis pregunta las cuatro columnas.
        $planilla = new PlanillaDePrueba($run);
        $this->analizador([$this->analisis_del_ticket(), $this->analisis_del_ticket()]);
        $sin_previas = $planilla->analizar(app(\App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer::class));
        $this->assertSame(['columna_0_0', 'columna_0_1', 'columna_0_2', 'columna_0_3'], array_keys($planilla->preguntas_columnas($sin_previas)));
        app(ImportacionDePruebaService::class)->avanzar($run);
        $run->refresh();

        $this->assertSame('confirmacion', $run->estado);
        $this->assertSame(['confirmacion'], array_keys($run->preguntas));
        $textos = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 74)->pluck('texto')->all();
        $this->assertCount(1, $textos);
        $this->assertStringNotContainsString('¿Qué dato', $textos[0]);
        $this->assertStringContainsString('CONFIRMAR', $textos[0]);

        $columnas = $run->hojas[0]['columnas'];
        $this->assertSame([null, 'nombre', 'precio', 'address_' . $deposito->id . '_amount'], array_column($columnas, 'campo'));
        $this->assertSame([true, true, true, true], array_column($columnas, 'segura'));
        $this->assertSame([true, true, true, true], array_column($columnas, 'previa'));
        $mapeo = (new PlanillaDePrueba($run))->resolver_columnas($run->hojas, [])[0]['mapeo'];
        $this->assertSame(1, $mapeo['nombre']);
        $this->assertSame(2, $mapeo['precio']);
        $this->assertSame(3, $mapeo[str_replace(' ', '_', strtolower($deposito->street))]);
        $this->assertNotContains(0, $mapeo);
    }

    /**
     * Mapeo previo parcial: el aviso pregunta solo las columnas que no cubrió, y sus opciones
     * siguen ofreciendo «Ignorar» pero no el campo que ya tomó una previa.
     */
    public function test_mapeo_previo_parcial_pregunta_solo_lo_no_cubierto(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $respuesta = $this->subir_de_soporte($this->planilla_del_ticket(), [
            ['columna' => 'A', 'campo' => 'ignorar'],
            ['columna' => 'B', 'campo' => 'nombre'],
        ])->assertStatus(202)->assertJsonPath('columnas_previas', 2);
        $run = $this->corrida_subida($respuesta->json('analysis_uuid'));
        $this->analizador([$this->analisis_del_ticket()]);
        app(ImportacionDePruebaService::class)->avanzar($run);
        $run->refresh();

        $this->assertSame('columnas', $run->estado);
        $this->assertSame(['columna_0_2', 'columna_0_3'], array_keys($run->preguntas));
        $aviso = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 74)->firstOrFail();
        // Desde la misión prueba-e2e-campana-imperio (ítem O) la pregunta nombra la columna por su título, no por su letra.
        $this->assertStringContainsString('«precio»', $aviso->texto);
        $this->assertStringContainsString('«stock_actual»', $aviso->texto);
        $this->assertStringNotContainsString('«columna_a»', $aviso->texto);
        $this->assertStringNotContainsString('«marca»', $aviso->texto);
        $opciones = array_column($run->preguntas['columna_0_3']['opciones'], 'valor');
        $this->assertContains('ignorar', $opciones);
        $this->assertNotContains('nombre', $opciones);
    }

    /**
     * Un campo que el negocio no tiene corta el pedido con 422, nombra la columna y lista los
     * campos posibles; no se crea la corrida ni se guarda el archivo.
     */
    public function test_mapeo_previo_con_campo_inexistente_devuelve_422_sin_crear_corrida(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $respuesta = $this->subir_de_soporte($this->planilla_del_ticket(), [
            ['columna' => 'B', 'campo' => 'nombre'],
            ['hoja' => 'Hoja 0', 'columna' => 'C', 'campo' => 'valor del dolar'],
        ])->assertStatus(422);
        $mensaje = $respuesta->json('message');
        $this->assertStringContainsString('«valor del dolar»', $mensaje);
        $this->assertStringContainsString('columna C de «Hoja 0»', $mensaje);
        $this->assertStringContainsString('Campos posibles: ignorar, ', $mensaje);
        $this->assertStringContainsString('nombre', $mensaje);
        $this->assertSame([$mensaje], $respuesta->json('errors.columnas_previas'));
        $this->assertFalse(ImportacionDePrueba::where('user_id', $this->dueno->id)->where('support_ticket_id', 74)->exists());
    }

    /**
     * El mismo campo en dos columnas, la misma columna dos veces, un JSON que no es lista y el
     * alcance de stock con mapeo previo: todos 422 sin corrida.
     */
    public function test_mapeo_previo_duplicado_o_invalido_devuelve_422(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $archivo = $this->planilla_del_ticket();
        $this->subir_de_soporte($archivo, [
            ['columna' => 'B', 'campo' => 'nombre'],
            ['columna' => 'C', 'campo' => 'Nombre'],
        ])->assertStatus(422)->assertJsonPath('message', 'Las columnas B y C de «Hoja 0» quedaron asignadas al mismo campo «nombre».');
        $this->subir_de_soporte($archivo, [
            ['columna' => 'B', 'campo' => 'nombre'],
            ['columna' => 'b', 'campo' => 'ignorar'],
        ])->assertStatus(422)->assertJsonPath('message', 'La columna B de «Hoja 0» aparece dos veces en el mapeo previo.');
        $this->subir_de_soporte($archivo, '{"columna":"B","campo":"nombre"}')->assertStatus(422);
        $this->subir_de_soporte($archivo, [['columna' => 'B1', 'campo' => 'nombre']])->assertStatus(422);
        // Dos columnas ignoradas no chocan entre sí.
        $this->subir_de_soporte($archivo, [
            ['columna' => 'A', 'campo' => 'ignorar'],
            ['columna' => 'C', 'campo' => 'Ignorar'],
        ])->assertStatus(202)->assertJsonPath('columnas_previas', 2);
        ImportacionDePrueba::where('user_id', $this->dueno->id)->where('support_ticket_id', 74)->get()
            ->each(function (ImportacionDePrueba $run) {
                $this->archivos[] = storage_path('app/' . $run->archivo);
            });
        $this->assertSame(1, ImportacionDePrueba::where('user_id', $this->dueno->id)->where('support_ticket_id', 74)->count());

        $this->post(self::BASE . 'analyze-stock-existente', [
            'user_id' => $this->dueno->id, 'model' => 'article', 'automatizar_soporte' => 1, 'alcance' => 'stock_existente',
            'support_ticket_id' => 75, 'importacion_clave' => hash_file('sha256', $archivo),
            'columnas_previas' => json_encode([['columna' => 'B', 'campo' => 'nombre']]),
            'excel_file' => new UploadedFile($archivo, 'inventario.xlsx', null, null, true),
        ], ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'El mapeo previo es para la importación general de artículos.');
        $this->assertFalse(ImportacionDePrueba::where('user_id', $this->dueno->id)->where('support_ticket_id', 75)->exists());
    }

    /**
     * Una previa toma el campo que el análisis le había dado a otra columna: esa otra columna se
     * pregunta (sin el campo tomado entre sus opciones) y la corrida sigue sin el error de
     * «Dos columnas … al mismo dato».
     */
    public function test_mapeo_previo_que_toma_el_campo_de_otra_columna_la_pregunta_sin_explotar(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $archivo = $this->planilla([[['Código', 'Descripción'], ['A-1', 'Martillo galponero']]]);
        $respuesta = $this->subir_de_soporte($archivo, [['columna' => 'B', 'campo' => 'nombre']])->assertStatus(202);
        $run = $this->corrida_subida($respuesta->json('analysis_uuid'));
        $this->analizador([['nombre' => 0.99, 'descripcion' => 0.50]]);
        $servicio = app(ImportacionDePruebaService::class);
        $servicio->avanzar($run);
        $run->refresh();

        $this->assertSame('columnas', $run->estado);
        $this->assertSame(['columna_0_0'], array_keys($run->preguntas));
        $this->assertNotContains('nombre', array_column($run->preguntas['columna_0_0']['opciones'], 'valor'));
        $this->assertSame([false, true], array_column($run->hojas[0]['columnas'], 'segura'));

        $this->postJson(self::BASE . 'prueba-resolver/' . $run->uuid, [
            'automatizar_soporte' => 1, 'user_id' => $this->dueno->id, 'support_ticket_id' => 74,
            'revision' => $run->revision, 'respuestas' => ['columna_0_0' => 'sku'],
        ], ['X-Admin-Api-Key' => self::CLAVE])->assertStatus(202);
        $servicio->avanzar($run->fresh());
        $run->refresh();
        $this->assertSame('confirmacion', $run->estado);
        $this->assertStringNotContainsString('mismo dato', $run->preguntas['confirmacion']['texto']);
    }

    /**
     * Sin el campo, la respuesta de iniciar es la de siempre: sin las claves nuevas.
     */
    public function test_sin_mapeo_previo_la_respuesta_no_cambia(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $respuesta = $this->subir_de_soporte($this->planilla_del_ticket(), null)->assertStatus(202);
        $this->corrida_subida($respuesta->json('analysis_uuid'));
        $this->assertSame(['analysis_uuid', 'estado', 'automatizar_soporte'], array_keys($respuesta->json()));
        $this->assertNull(ImportacionDePrueba::where('uuid', $respuesta->json('analysis_uuid'))->value('columnas_previas'));
    }

    /**
     * Reglas de `aplicar_columnas_previas`: sin hoja solo vale con una única hoja, una letra sin
     * datos se saltea y `ignorar` nunca queda como campo.
     */
    public function test_aplicar_columnas_previas_respeta_hojas_y_columnas_vacias(): void
    {
        $planilla = new PlanillaDePrueba(new ImportacionDePrueba(['user_id' => $this->dueno->id]));
        $columna = function (?string $campo, bool $segura) {
            return ['campo' => $campo, 'segura' => $segura, 'titulo' => '', 'muestra' => 'x'];
        };
        $hojas = [
            ['indice' => 0, 'nombre' => 'Artículos', 'columnas' => [0 => $columna(null, false), 1 => $columna('precio', true)]],
            ['indice' => 1, 'nombre' => 'Otra', 'columnas' => [0 => $columna(null, false)]],
        ];
        $resultado = $planilla->aplicar_columnas_previas($hojas, [
            ['hoja' => null, 'columna' => 'A', 'campo' => 'nombre'],
            ['hoja' => 'ARTICULOS', 'columna' => 'A', 'campo' => null],
            ['hoja' => 'Artículos', 'columna' => 'Z', 'campo' => 'sku'],
            ['hoja' => 'Otra', 'columna' => 'A', 'campo' => 'ignorar'],
        ]);
        $this->assertSame(['campo' => null, 'segura' => true, 'titulo' => '', 'muestra' => 'x', 'previa' => true], $resultado[0]['columnas'][0]);
        $this->assertSame($hojas[0]['columnas'][1], $resultado[0]['columnas'][1]);
        $this->assertArrayNotHasKey(25, $resultado[0]['columnas']);
        $this->assertNull($resultado[1]['columnas'][0]['campo']);
        $this->assertTrue($resultado[1]['columnas'][0]['segura']);

        // Con una sola hoja, la previa sin hoja sí se aplica.
        $una = $planilla->aplicar_columnas_previas([$hojas[1]], [['hoja' => null, 'columna' => 'A', 'campo' => 'nombre']]);
        $this->assertSame('nombre', $una[0]['columnas'][0]['campo']);
    }

    /**
     * Listas de precio: el nombre pelado de una lista no resuelve en silencio al porcentaje; vale la
     * clave exacta o la etiqueta completa.
     */
    public function test_mapeo_previo_con_lista_de_precio_exige_clave_o_etiqueta_completa(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $lista = PriceType::create(['name' => 'Mayorista Pesca', 'user_id' => $this->dueno->id]);
        $archivo = $this->planilla_del_ticket();
        foreach (['Mayorista Pesca', 'Lista Mayorista Pesca', '%_mayorista_pesca'] as $pelado) {
            $mensaje = $this->subir_de_soporte($archivo, [['columna' => 'C', 'campo' => $pelado]])->assertStatus(422)->json('message');
            $this->assertStringContainsString('El campo «' . $pelado . '» de la columna C no existe en este negocio.', $mensaje);
            $this->assertStringContainsString('price_type_' . $lista->id . '_percentage (Lista Mayorista Pesca (percentage))', $mensaje);
        }
        $this->assertFalse(ImportacionDePrueba::where('user_id', $this->dueno->id)->where('support_ticket_id', 74)->exists());

        $respuesta = $this->subir_de_soporte($archivo, [
            ['columna' => 'C', 'campo' => 'Lista Mayorista Pesca (final_price)'],
            ['columna' => 'D', 'campo' => 'price_type_' . $lista->id . '_percentage'],
        ])->assertStatus(202)->assertJsonPath('columnas_previas', 2);
        $run = $this->corrida_subida($respuesta->json('analysis_uuid'));
        $this->assertSame(['price_type_' . $lista->id . '_final_price', 'price_type_' . $lista->id . '_percentage'],
            array_column($run->columnas_previas, 'campo'));
    }

    /**
     * Hojas: una hoja que no está en el libro, o la falta de hoja en un libro de varias, es 422
     * nombrando las hojas; una hoja escrita distinto se guarda con su nombre real.
     */
    public function test_mapeo_previo_valida_las_hojas_contra_el_libro(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $archivo = $this->planilla([
            [['Nombre', 'Precio'], ['Reel', 10]],
            [['Nombre', 'Precio'], ['Caña', 20]],
        ]);
        $this->subir_de_soporte($archivo, [['hoja' => 'Precios', 'columna' => 'B', 'campo' => 'precio']])->assertStatus(422)
            ->assertJsonPath('message', 'La hoja «Precios» de la columna B no existe en el archivo. Hojas del archivo: «Hoja 0», «Hoja 1».')
            ->assertJsonPath('errors.columnas_previas.0', 'La hoja «Precios» de la columna B no existe en el archivo. Hojas del archivo: «Hoja 0», «Hoja 1».');
        $this->subir_de_soporte($archivo, [['columna' => 'B', 'campo' => 'precio']])->assertStatus(422)
            ->assertJsonPath('message', 'Falta la hoja de la columna B: el archivo tiene varias hojas («Hoja 0», «Hoja 1»). Indicá en cuál está.');
        $this->assertFalse(ImportacionDePrueba::where('user_id', $this->dueno->id)->where('support_ticket_id', 74)->exists());

        // La misma columna en dos hojas distintas no choca, y la hoja queda con su nombre real.
        $respuesta = $this->subir_de_soporte($archivo, [
            ['hoja' => 'hoja 1', 'columna' => 'B', 'campo' => 'precio'],
            ['hoja' => 'Hoja 0', 'columna' => 'B', 'campo' => 'costo'],
        ])->assertStatus(202)->assertJsonPath('columnas_previas', 2);
        $run = $this->corrida_subida($respuesta->json('analysis_uuid'));
        $this->assertSame(['Hoja 1', 'Hoja 0'], array_column($run->columnas_previas, 'hoja'));

        $this->analizador([['nombre' => 0.99, 'columna_b' => 0], ['nombre' => 0.99, 'columna_b' => 0]]);
        app(ImportacionDePruebaService::class)->avanzar($run);
        $run->refresh();
        $this->assertSame('costo', $run->hojas[0]['columnas'][1]['campo']);
        $this->assertSame('precio', $run->hojas[1]['columnas'][1]['campo']);
        $this->assertSame('confirmacion', $run->estado);
    }

    /**
     * En un libro de una sola hoja, una previa sin hoja es de esa hoja: con otra previa que sí la
     * nombra, los duplicados de columna y de campo se detectan después de normalizar.
     */
    public function test_mapeo_previo_con_y_sin_hoja_en_la_misma_hoja_choca(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $archivo = $this->planilla_del_ticket();
        $this->subir_de_soporte($archivo, [
            ['hoja' => 'Hoja 0', 'columna' => 'B', 'campo' => 'nombre'],
            ['columna' => 'B', 'campo' => 'precio'],
        ])->assertStatus(422)->assertJsonPath('message', 'La columna B de «Hoja 0» aparece dos veces en el mapeo previo.');
        $this->subir_de_soporte($archivo, [
            ['hoja' => 'hoja 0', 'columna' => 'B', 'campo' => 'nombre'],
            ['columna' => 'C', 'campo' => 'nombre'],
        ])->assertStatus(422)->assertJsonPath('message', 'Las columnas B y C de «Hoja 0» quedaron asignadas al mismo campo «nombre».');
        $this->assertFalse(ImportacionDePrueba::where('user_id', $this->dueno->id)->where('support_ticket_id', 74)->exists());
    }

    /**
     * Base sin la migración: la importación sigue como hoy, sin guardar previas, y responde
     * `columnas_previas: 0`. Se simula con una gramática que oculta la columna en el listado de
     * columnas (sin DDL, que en MySQL confirmaría la transacción que aísla el test).
     */
    public function test_mapeo_previo_sin_la_migracion_sigue_sin_previas(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $conexion = DB::connection();
        $conexion->setSchemaGrammar($conexion->withTablePrefix(new class extends \Illuminate\Database\Schema\Grammars\MySqlGrammar {
            public function compileColumnListing()
            {
                return parent::compileColumnListing() . " and column_name <> 'columnas_previas'";
            }
        }));
        try {
            $this->assertFalse(\App\Http\Controllers\AdminSync\ImportacionDePruebaController::soporta_columnas_previas());
            $respuesta = $this->subir_de_soporte($this->planilla_del_ticket(), [['columna' => 'B', 'campo' => 'nombre']])
                ->assertStatus(202)->assertJsonPath('columnas_previas', 0)->assertJsonPath('corrida_existente', false);
        } finally {
            $conexion->useDefaultSchemaGrammar();
        }
        $this->assertTrue(\App\Http\Controllers\AdminSync\ImportacionDePruebaController::soporta_columnas_previas());
        $run = $this->corrida_subida($respuesta->json('analysis_uuid'));
        $this->assertNull($run->columnas_previas);
    }

    /**
     * Planilla parecida a la del ticket 74: columna A sin título, nombres, precio y stock.
     */
    private function planilla_del_ticket(): string
    {
        return $this->planilla([[
            ['', 'ARTICULOS NUEVOS', 'PRECIO', 'STOCK'],
            ['x', 'Reel frontal', 15000, 4],
            ['', 'Caña telescópica', 23000, 2],
        ]]);
    }

    /**
     * Análisis simulado sin ninguna columna segura, como el que disparó las preguntas del ticket 74.
     * El título de B no es «descripcion» a propósito: `descripcion_como_nombre` la volvería segura.
     */
    private function analisis_del_ticket(): array
    {
        return ['columna_a' => 0, 'marca' => 0.50, 'precio' => 0.60, 'stock_actual' => 0.50];
    }

    /**
     * Sube el Excel como lo reenvía el admin desde un ticket de soporte, con o sin mapeo previo.
     *
     * @param  string            $archivo  Ruta del Excel
     * @param  array|string|null $previas  Lista (se codifica a JSON), JSON crudo, o null para no mandar el campo
     */
    private function subir_de_soporte(string $archivo, $previas, int $ticket = 74)
    {
        $datos = [
            'user_id' => $this->dueno->id, 'model' => 'article', 'automatizar_soporte' => 1,
            'support_ticket_id' => $ticket, 'importacion_clave' => hash_file('sha256', $archivo),
            'excel_file' => new UploadedFile($archivo, 'lista.xlsx', null, null, true),
        ];
        if ($previas !== null) {
            $datos['columnas_previas'] = is_string($previas) ? $previas : json_encode($previas, JSON_UNESCAPED_UNICODE);
        }

        return $this->post(self::BASE . 'analyze', $datos, ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json']);
    }

    /**
     * Busca la corrida creada por la subida y registra sus archivos para limpiarlos.
     */
    private function corrida_subida(string $uuid): ImportacionDePrueba
    {
        $run = ImportacionDePrueba::where('uuid', $uuid)->firstOrFail();
        $this->archivos[] = storage_path('app/' . $run->archivo);
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '_');
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '.preparado');

        return $run;
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

    private function planilla(array $hojas, bool $comparacion_estricta = false): string
    {
        $libro = new Spreadsheet();
        foreach ($hojas as $indice => $filas) {
            $hoja = $indice === 0 ? $libro->getActiveSheet() : $libro->createSheet();
            $hoja->setTitle('Hoja ' . $indice)->fromArray($filas, null, 'A1', $comparacion_estricta);
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
