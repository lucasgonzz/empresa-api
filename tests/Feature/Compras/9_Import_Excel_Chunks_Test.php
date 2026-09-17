<?php

namespace Tests\Feature\Compras;

use App\Jobs\ProcessProviderOrderArticleImport;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\ProviderOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/**
 * Tests de la importación de Excel de artículos de una compra a proveedor procesando
 * volúmenes de 500-700 filas (misión `import-excel-compras-chunks`, 14/9/2026).
 *
 * Antes de esta misión, `ProviderOrderController::import_excel_articles()` corría
 * `Excel::import()` síncrono dentro del request HTTP, con 1 query por fila en la búsqueda del
 * artículo (`ProviderOrderArticleImport::get_article()`). Con 500-700 filas eso arriesgaba el
 * `max_execution_time` del servidor. Ahora el controller solo prepara `ImportStatus`/
 * `ImportHistory` y despacha `ProcessProviderOrderArticleImport` a la cola; el pipeline de
 * negocio (`attach_articles` → `check_modo_facturacion` → `procesar_pedido`) sigue corriendo UNA
 * sola vez, tal cual antes — no se trocea en varios jobs porque ese pipeline opera sobre el
 * conjunto completo de líneas de la compra.
 *
 * `Bus::fake()` en vez de una cola real: permite verificar por separado (a) que el controller
 * responde SIN esperar el procesamiento, y (b) que el job, corrido a mano, deja el resultado
 * correcto — mismo patrón estándar de Laravel para testear jobs sin levantar `queue:work`.
 */
class Import_Excel_Chunks_Test extends ComprasTestCase
{
    const CANTIDAD_FILAS = 700;

    /** @var string Prefijo único por corrida, para no colisionar con otra corrida sobre la misma base. */
    protected $prefijo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefijo = 'CHK' . substr(str_replace('.', '', (string) microtime(true)), -8) . '-';
    }

    /**
     * Arma un .xlsx de $cantidad_filas artículos NUEVOS (provider_code único por fila), con las
     * columnas codigo_de_barras/codigo_de_proveedor/nombre/cantidad/costo/notas — mismo orden que
     * usa el modo 'pedido' del modal de importación de compras (Import.vue).
     *
     * @param int $cantidad_filas
     * @return string Ruta del archivo temporal generado.
     */
    protected function generar_excel_de_pedido($cantidad_filas)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('import_compras_test_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);

        $writer->addRow(WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'codigo_de_proveedor', 'nombre', 'cantidad', 'costo', 'notas',
        ]));

        for ($i = 1; $i <= $cantidad_filas; $i++) {
            $writer->addRow(WriterEntityFactory::createRowFromArray([
                null,
                $this->prefijo . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'Articulo chunk ' . $i,
                2,
                100.50,
                null,
            ]));
        }

        $writer->close();

        return $ruta;
    }

    /**
     * Arma un .xlsx de "recibidos" para artículos YA EXISTENTES en la compra, matcheando por
     * NOMBRE (el fixture de testing no siempre carga bar_code/provider_code — nombre es el único
     * identificador que la suite entera ya usa para resolver artículos, ver EmpresaTestCase::
     * articulo()) y trayendo cantidad_recibida.
     *
     * @param array<int,array{nombre:string,cantidad_recibida:float}> $filas
     * @return string Ruta del archivo temporal generado.
     */
    protected function generar_excel_de_recibido(array $filas)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('import_compras_recibido_test_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);

        $writer->addRow(WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'codigo_de_proveedor', 'nombre', 'cantidad_recibida', 'costo', 'notas',
        ]));

        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray([
                null,
                null,
                $fila['nombre'],
                $fila['cantidad_recibida'],
                null,
                null,
            ]));
        }

        $writer->close();

        return $ruta;
    }

    protected function columnas_pedido()
    {
        return [
            'prop_codigo_de_barras'    => 1,
            'prop_codigo_de_proveedor' => 2,
            'prop_nombre'              => 3,
            'prop_cantidad'            => 4,
            'prop_costo'               => 5,
            'prop_notas'               => 6,
        ];
    }

    protected function columnas_recibido()
    {
        return [
            'prop_codigo_de_barras'     => 1,
            'prop_codigo_de_proveedor'  => 2,
            'prop_nombre'               => 3,
            'prop_cantidad_recibida'    => 4,
            'prop_costo'                => 5,
            'prop_notas'                => 6,
        ];
    }

    /**
     * Crea una ProviderOrder vacía (sin líneas) vía el endpoint real, con las mismas
     * neutralizaciones que el resto de la suite (RRII, sin bonificaciones de catálogo) para que
     * el pipeline de negocio no le agregue nada por su cuenta a las líneas del Excel.
     *
     * @param array $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function crear_orden_vacia($overrides = [])
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $payload = $this->payload_compra(array_merge(['articles' => []], $overrides));

        $response = $this->postJson('api/provider-order', $payload);
        $response->assertStatus(201);

        return ProviderOrder::find($response->json('model.id'));
    }

    /**
     * @group compras
     * @test
     */
    public function el_endpoint_responde_sin_esperar_y_el_job_procesa_las_setecientas_filas()
    {
        Bus::fake([ProcessProviderOrderArticleImport::class]);

        $provider_order = $this->crear_orden_vacia();
        $archivo = $this->generar_excel_de_pedido(self::CANTIDAD_FILAS);

        $data = array_merge([
            'models'             => new UploadedFile($archivo, basename($archivo), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'provider_order_id'  => $provider_order->id,
            'start_row'          => 2,
            'finish_row'         => 1 + self::CANTIDAD_FILAS,
            'import_type'        => 'pedido',
            'overwrite_articles' => 0,
        ], $this->columnas_pedido());

        $response = $this->post('api/provider-order/excel/import', $data);

        $response->assertStatus(200);

        /*
         * Lo que este cambio vino a garantizar: el controller ya respondió, y el trabajo pesado
         * (Excel::import, attach_articles, procesar_pedido) TODAVÍA no corrió — con Bus::fake()
         * nunca corre solo. Antes de esta misión no había ningún job que "fakear": el controller
         * hacía Excel::import() él mismo, síncrono.
         */
        Bus::assertDispatched(ProcessProviderOrderArticleImport::class);

        $import_status = ImportStatus::where('provider_order_id', $provider_order->id)->first();
        $this->assertNotNull($import_status, 'El controller tiene que crear el ImportStatus ANTES de despachar el job.');
        $this->assertSame('pendiente', $import_status->status);
        $this->assertSame(0, (int) $import_status->processed_chunks);
        $this->assertGreaterThan(0, (int) $import_status->total_chunks);

        $import_history = ImportHistory::where('provider_order_id', $provider_order->id)
            ->where('model_name', 'provider_order')
            ->first();
        $this->assertNotNull($import_history, 'El controller tiene que crear el ImportHistory ANTES de despachar el job.');
        $this->assertSame('en_preparacion', $import_history->status);

        $job = Bus::dispatched(ProcessProviderOrderArticleImport::class)->first();

        /*
         * Cuenta específicamente los SELECT a `articles` que filtran por provider_code en
         * comparación EXACTA (no "in (...)"): es el patrón que generaba get_article() antes de
         * esta misión, 1 por fila (hasta 700 con este volumen).
         *
         * Filtrar por "select ... from `articles`" es necesario para no contar el UPDATE
         * legítimo de `article_provider` (set `provider_code` = ?, ...) que
         * NewProviderOrderHelper::update_article() → update_stock() → SetProvider::set_provider()
         * corre una vez por artículo — eso SÍ es esperado y no es el N+1 de esta misión.
         */
        $queries_get_article_por_provider_code = 0;
        DB::listen(function ($query) use (&$queries_get_article_por_provider_code) {
            $es_select_de_articles = stripos($query->sql, 'select') === 0
                && stripos($query->sql, 'from `articles`') !== false;

            $filtra_por_provider_code_exacto = preg_match('/`provider_code`\s*=\s*\?/i', $query->sql) === 1;

            if ($es_select_de_articles && $filtra_por_provider_code_exacto) {
                $queries_get_article_por_provider_code++;
            }
        });

        // Mismo patrón estándar de Laravel para correr un job capturado por Bus::fake().
        $job->handle();

        $this->assertLessThan(
            10,
            $queries_get_article_por_provider_code,
            'get_article() sigue pegándole a la base una vez por fila con provider_code = ?: '.
            'la precarga del índice (whereIn) no está funcionando.'
        );

        $provider_order->refresh();
        $import_status->refresh();
        $import_history->refresh();

        $this->assertSame('completado', $import_status->status);
        $this->assertSame((int) $import_status->total_chunks, (int) $import_status->processed_chunks);
        $this->assertSame(self::CANTIDAD_FILAS, (int) $import_status->created_models);

        $this->assertSame('terminado', $import_history->status);
        $this->assertNotNull($import_history->terminado_at);

        $this->assertSame(
            self::CANTIDAD_FILAS,
            $provider_order->articles()->count(),
            'Las 700 filas del excel tienen que quedar como líneas de la compra.'
        );

        $creados = Article::where('user_id', $provider_order->user_id)
            ->where('provider_code', 'like', $this->prefijo . '%')
            ->count();

        $this->assertSame(
            self::CANTIDAD_FILAS,
            $creados,
            'Se tiene que crear exactamente un artículo por fila, sin duplicados.'
        );
    }

    /**
     * @group compras
     * @test
     */
    public function provider_order_inexistente_da_404_no_una_excepcion_cruda()
    {
        Bus::fake([ProcessProviderOrderArticleImport::class]);

        $archivo = $this->generar_excel_de_pedido(1);

        $data = array_merge([
            'models'             => new UploadedFile($archivo, basename($archivo), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'provider_order_id'  => 999999999,
            'start_row'          => 2,
            'finish_row'         => 2,
            'import_type'        => 'pedido',
            'overwrite_articles' => 0,
        ], $this->columnas_pedido());

        $response = $this->post('api/provider-order/excel/import', $data);

        $response->assertStatus(404);

        Bus::assertNotDispatched(ProcessProviderOrderArticleImport::class);
    }

    /**
     * @group compras
     * @test
     */
    public function una_falla_durante_el_procesamiento_marca_status_fallo_con_mensaje()
    {
        Bus::fake([ProcessProviderOrderArticleImport::class]);

        $provider_order = $this->crear_orden_vacia();
        $archivo = $this->generar_excel_de_pedido(5);

        $data = array_merge([
            'models'             => new UploadedFile($archivo, basename($archivo), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'provider_order_id'  => $provider_order->id,
            'start_row'          => 2,
            'finish_row'         => 6,
            'import_type'        => 'pedido',
            'overwrite_articles' => 0,
        ], $this->columnas_pedido());

        $this->post('api/provider-order/excel/import', $data)->assertStatus(200);

        $job = Bus::dispatched(ProcessProviderOrderArticleImport::class)->first();

        /*
         * Simula el escenario real ya documentado (informe 20260822): el archivo desaparece
         * entre que se guardó y que el worker lo lee (deploy, limpieza de storage, etc.).
         * archivo_excel_path es protected: se lee por Reflection en vez de exponerla solo para
         * el test.
         */
        $reflexion = new \ReflectionProperty($job, 'archivo_excel_path');
        $reflexion->setAccessible(true);
        $ruta_relativa = $reflexion->getValue($job);

        @unlink(storage_path('app/' . $ruta_relativa));

        try {
            $job->handle();
            $this->fail('El job tenía que relanzar la excepción (para que el worker la vea como fallo).');
        } catch (\Throwable $e) {
            // Esperado: el catch de handle() marca el estado ANTES de relanzar.
        }

        $import_status = ImportStatus::where('provider_order_id', $provider_order->id)->first();
        $import_history = ImportHistory::where('provider_order_id', $provider_order->id)
            ->where('model_name', 'provider_order')
            ->first();

        $this->assertSame('fallo', $import_status->status);
        $this->assertNotNull($import_status->error_message);

        $this->assertSame('fallo', $import_history->status);
        $this->assertNotNull($import_history->error_message);
    }

    /**
     * @group compras
     * @test
     */
    public function modo_recibido_calcula_el_diff_y_queda_disponible_por_el_endpoint_dedicado()
    {
        Bus::fake([ProcessProviderOrderArticleImport::class]);

        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $provider_order = $this->crear_orden_vacia([
                'update_stock'  => 0,
                'update_prices' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10, ['received' => null]),
                ],
            ]);

            $archivo = $this->generar_excel_de_recibido([
                ['nombre' => $pinza->name, 'cantidad_recibida' => 8],
            ]);

            $data = array_merge([
                'models'             => new UploadedFile($archivo, basename($archivo), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                'provider_order_id'  => $provider_order->id,
                'start_row'          => 2,
                'finish_row'         => 2,
                'import_type'        => 'recibido',
                'overwrite_articles' => 0,
            ], $this->columnas_recibido());

            $response = $this->post('api/provider-order/excel/import', $data);
            $response->assertStatus(200);

            $job = Bus::dispatched(ProcessProviderOrderArticleImport::class)->first();
            $job->handle();

            $diff_response = $this->getJson('api/provider-order/' . $provider_order->id . '/import-diff');
            $diff_response->assertStatus(200);

            $diff = $diff_response->json('diff');

            $this->assertIsArray($diff);
            $this->assertCount(1, $diff);
            $this->assertSame($pinza->id, $diff[0]['id']);
            $this->assertSame(10.0, (float) $diff[0]['pedida']);
            $this->assertSame(8.0, (float) $diff[0]['recibida']);
            $this->assertSame('parcial', $diff[0]['status']);
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
        }
    }
}
