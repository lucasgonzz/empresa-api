<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\ImportacionDePrueba;
use App\Models\ImportHistory;
use App\Models\TableColumnPreference;
use App\Services\ImageAssignment\CupoImagenesDePrueba;
use App\Services\Importacion\ImportacionDePruebaService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

/**
 * El código propio del dueño en la carga asistida de la prueba (misión prueba-e2e-campana-imperio, ronda 3,
 * E1).
 *
 * En el ensayo del 12/11 la columna «Cod.» (1–32) de una planilla de ALTA quedó mapeada a `numero`, que para
 * el importador es el id de un artículo existente: los 32 se crearon con su número correlativo y el código
 * del dueño no quedó en ningún campo buscable. Ahora, en la prueba, esa columna se guarda como SKU, el SKU se
 * suma al buscador del dueño y el resumen lo dice en palabras de comerciante. Una lista de artículos que el
 * negocio ya tiene por su número (mismos ids y mismos nombres) sigue actualizándolos, y fuera de la prueba
 * (modo soporte) nada cambia.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class CodigoDelDuenoEnLaPruebaTest extends EmpresaTestCase
{
    const CLAVE = 'admin-prueba-codigo-del-dueno-test';

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
            'services.admin_api.api_key' => self::CLAVE,
            'services.openai.embeddings_generacion_pausada' => true,
        ]);

        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        // Sin cupo de imágenes: el cierre de la importación no sale a buscar fotos.
        CupoImagenesDePrueba::editar((int) $this->dueno->id, 0);
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

    /**
     * El analizador de producción con una respuesta de IA fija: `titulo de columna => [campo, confianza]`.
     *
     * @param  array $mapeo
     * @return void
     */
    private function analizador(array $mapeo): void
    {
        $dueno = $this->dueno;
        $this->app->bind(AiExcelAnalyzer::class, function () use ($dueno, $mapeo) {
            $analizador = new AnalizadorDelCodigoDelDuenoFalso((int) $dueno->id);
            $analizador->mapeo = $mapeo;

            return $analizador;
        });
    }

    private function corrida(array $filas, array $campos = []): ImportacionDePrueba
    {
        $uuid = (string) Str::uuid();
        $relativa = 'imported_files/prueba_' . $uuid . '.xlsx';
        $destino = storage_path('app/' . $relativa);

        $libro = new Spreadsheet();
        $libro->getActiveSheet()->setTitle('Hoja 0')->fromArray($filas, null, 'A1', true);
        (new Xlsx($libro))->save($destino);
        $libro->disconnectWorksheets();

        $this->archivos[] = $destino;
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '_');
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '.preparado');

        return ImportacionDePrueba::create($campos + [
            'uuid' => $uuid, 'user_id' => $this->dueno->id, 'support_ticket_id' => 41,
            'clave' => hash('sha256', $uuid), 'archivo' => $relativa, 'nombre' => 'costos y margen.xlsx',
            'origen' => 'martin', 'estado' => 'analizar',
        ]);
    }

    private function avanzar(ImportacionDePrueba $run): ImportacionDePrueba
    {
        app(ImportacionDePruebaService::class)->avanzar($run->fresh());

        return $run->fresh();
    }

    /**
     * Corre los lotes despachados y el cierre del historial, y vuelve a avanzar la corrida para el resumen.
     *
     * @param  \App\Models\ImportacionDePrueba $run
     * @return \App\Models\ImportacionDePrueba
     */
    private function importar_y_cerrar(ImportacionDePrueba $run): ImportacionDePrueba
    {
        $inicios = Bus::dispatched(ProcessArticleChunk::class);
        $this->assertCount(1, $inicios, 'La importación tiene que haber despachado su primer lote.');
        $primero = $inicios->first();
        $trabajos = [$primero];
        foreach ($primero->chained as $serializado) {
            $siguiente = unserialize($serializado);
            if ($siguiente instanceof ProcessArticleChunk) {
                $trabajos[] = $siguiente;
            }
        }
        foreach ($trabajos as $job) {
            $job->handle();
        }

        $historial = ImportHistory::findOrFail($run->fresh()->import_history_id);
        (new FinalizeArticleImport($this->dueno->id, $historial->id, $historial->import_status_id))->handle();

        return $this->avanzar($run);
    }

    private function preferencia_del_buscador(): ?TableColumnPreference
    {
        return TableColumnPreference::where('user_id', $this->dueno->id)->where('model_name', 'article')
            ->where('preference_type', 'global_search')->first();
    }

    public function test_la_columna_cod_que_quedo_en_numero_se_guarda_como_sku_y_no_pisa_otro_articulo(): void
    {
        // Un artículo que el negocio ya tenía, cuyo id coincide con uno de los códigos de la planilla nueva.
        $existente = Article::create([
            'name' => 'Pala ancha de punta', 'user_id' => $this->dueno->id, 'status' => 'active', 'price' => 1000,
        ]);
        $codigo_que_coincide = (int) $existente->id;

        // El analizador (o el dueño, o soporte con --columnas) eligió `numero` para «Cod.».
        $this->analizador([
            'Cod.' => ['numero', 0.97], 'Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97],
        ]);
        Bus::fake();
        $run = $this->avanzar($this->corrida([
            ['Cod.', 'Nombre', 'Precio de venta'],
            [$codigo_que_coincide, 'Sierra de arco 300 mm', 16500],
            [990001, 'Tornillo autoperforante 8x1', 450],
            [990002, 'Tornillo fix 6x40', 560],
        ]));

        $this->assertSame('importando', $run->estado, json_encode($run->preguntas, JSON_UNESCAPED_UNICODE));
        $mapeo = $run->hojas[0]['mapeo'];
        $this->assertArrayHasKey('sku', $mapeo, 'El código del dueño va al SKU.');
        $this->assertArrayNotHasKey('numero', $mapeo, 'En una planilla de alta `numero` no queda elegido.');

        $run = $this->importar_y_cerrar($run);

        // Los tres se crearon con su código como SKU, buscable.
        $sierra = Article::where('user_id', $this->dueno->id)->where('sku', (string) $codigo_que_coincide)->first();
        $this->assertNotNull($sierra, 'La sierra quedó con el código del dueño en el SKU.');
        $this->assertSame('Sierra de arco 300 mm', $sierra->name);
        $this->assertNotSame($codigo_que_coincide, (int) $sierra->id, 'Es un artículo nuevo, no el que tenía ese id.');
        $this->assertSame(1, Article::where('user_id', $this->dueno->id)->where('sku', '990001')->count());
        $this->assertSame(1, Article::where('user_id', $this->dueno->id)->where('sku', '990002')->count());

        // El artículo que ya tenía el negocio no se tocó.
        $existente->refresh();
        $this->assertSame('Pala ancha de punta', $existente->name);
        $this->assertNull($existente->sku);

        // El resumen lo dice en palabras del dueño.
        $resultado = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 41)
            ->where('texto', 'like', 'Cargué%')->firstOrFail();
        $this->assertStringContainsString('También tomé de tu lista: tu código de cada artículo.', $resultado->texto);
        $this->assertStringContainsString('Cada artículo lo encontrás por tu código en el Listado y en Vender.', $resultado->texto);
        $this->assertStringNotContainsString('número de artículo', $resultado->texto);
        $this->assertStringNotContainsString('SKU', $resultado->texto);

        // Y el buscador del dueño (Listado y modal de Vender) busca también por SKU.
        $preferencia = $this->preferencia_del_buscador();
        $this->assertNotNull($preferencia);
        $claves = array_column(array_filter($preferencia->columns, function ($columna) {
            return !empty($columna['visible']);
        }), 'key');
        foreach (['bar_code', 'provider_code', 'name', 'sku'] as $clave) {
            $this->assertContains($clave, $claves, $clave);
        }

        // El buscador general (Listado, y Vender con su contexto) encuentra la sierra por el código del dueño.
        foreach ([null, 'vender'] as $contexto) {
            $respuesta = $this->postJson('/api/global-search/article', array_filter([
                'query_value' => (string) $codigo_que_coincide, 'props' => $claves, 'conector' => 'or', 'contexto' => $contexto,
            ]))->assertOk();
            $this->assertStringContainsString('Sierra de arco 300 mm', json_encode($respuesta->json('models'), JSON_UNESCAPED_UNICODE), (string) $contexto);
        }
    }

    public function test_el_sku_que_el_dueno_destildo_en_el_buscador_se_respeta(): void
    {
        $columnas = [
            ['key' => 'name', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false, 'keyword_mode' => 'alguna'],
            ['key' => 'sku', 'visible' => false, 'order' => 1, 'width' => null, 'wrap_content' => false, 'keyword_mode' => 'alguna'],
        ];
        TableColumnPreference::updateOrCreate(
            ['user_id' => $this->dueno->id, 'model_name' => 'article', 'preference_type' => 'global_search'],
            ['columns' => $columnas, 'settings' => ['conector' => 'or']]
        );

        ImportacionDePruebaService::buscar_tambien_por_sku((int) $this->dueno->id);

        $this->assertEquals($columnas, $this->preferencia_del_buscador()->columns, 'Mismas columnas (la columna JSON no conserva el orden de las claves).');
    }

    public function test_una_lista_de_los_articulos_que_ya_tiene_por_su_numero_sigue_actualizandolos(): void
    {
        $martillo = Article::create(['name' => 'Martillo galponero 500 g', 'user_id' => $this->dueno->id, 'status' => 'active', 'price' => 1000]);
        $pinza = Article::create(['name' => 'Pinza universal 8', 'user_id' => $this->dueno->id, 'status' => 'active', 'price' => 2000]);
        $antes = Article::where('user_id', $this->dueno->id)->count();

        $this->analizador([
            'N°' => ['numero', 0.97], 'Artículo' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97],
        ]);
        Bus::fake();
        $run = $this->avanzar($this->corrida([
            ['N°', 'Artículo', 'Precio de venta'],
            [$martillo->id, 'Martillo galponero 500 g', 1500],
            [$pinza->id, 'PINZA UNIVERSAL 8', 2500],
        ]));

        $this->assertSame('importando', $run->estado);
        $this->assertArrayHasKey('numero', $run->hojas[0]['mapeo'], 'Mismos ids y mismos nombres: es una lista para actualizar.');
        $this->assertArrayNotHasKey('sku', $run->hojas[0]['mapeo']);

        $this->importar_y_cerrar($run);

        $this->assertSame($antes, Article::where('user_id', $this->dueno->id)->count(), 'No se creó ninguno.');
        $this->assertNull($martillo->fresh()->sku);
        $this->assertEqualsWithDelta(1500, (float) $martillo->fresh()->price, 0.01);
        $this->assertEqualsWithDelta(2500, (float) $pinza->fresh()->price, 0.01);
    }

    public function test_con_un_nombre_distinto_no_se_actualiza_por_numero(): void
    {
        $martillo = Article::create(['name' => 'Martillo galponero 500 g', 'user_id' => $this->dueno->id, 'status' => 'active', 'price' => 1000]);

        $this->analizador(['Cod.' => ['numero', 0.97], 'Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        Bus::fake();
        $run = $this->avanzar($this->corrida([
            ['Cod.', 'Nombre', 'Precio de venta'],
            [$martillo->id, 'Caño de PVC 40 mm', 900],
        ]));

        $this->assertArrayHasKey('sku', $run->hojas[0]['mapeo'], 'El id coincide pero el artículo es otro: es un código del dueño.');
        $this->assertArrayNotHasKey('numero', $run->hojas[0]['mapeo']);
    }

    public function test_las_columnas_que_fija_soporte_con_numero_tambien_terminan_en_sku(): void
    {
        $this->analizador(['Cod.' => ['codigo_de_proveedor', 0.97], 'Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        Bus::fake();
        $run = $this->avanzar($this->corrida([
            ['Cod.', 'Nombre', 'Precio de venta'],
            [880001, 'Sierra de arco 300 mm', 16500],
        ], ['columnas_previas' => [['hoja' => null, 'columna' => 'A', 'campo' => 'numero']]]));

        $this->assertSame('importando', $run->estado);
        $this->assertArrayHasKey('sku', $run->hojas[0]['mapeo']);
        $this->assertArrayNotHasKey('numero', $run->hojas[0]['mapeo']);
    }

    public function test_en_modo_soporte_numero_queda_como_siempre(): void
    {
        $this->analizador(['Cod.' => ['numero', 0.97], 'Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        $run = $this->corrida([
            ['Cod.', 'Nombre', 'Precio de venta'],
            [880001, 'Sierra de arco 300 mm', 16500],
        ], ['modo_soporte' => true]);

        $planilla = new \App\Services\Importacion\PlanillaDePrueba($run);
        $hojas = $planilla->analizar(app(AiExcelAnalyzer::class));
        $resueltas = $planilla->resolver_columnas($hojas, []);

        $this->assertArrayHasKey('numero', $resueltas[0]['mapeo']);
        $this->assertArrayNotHasKey('sku', $resueltas[0]['mapeo']);
    }
}

/**
 * El analizador de producción con la respuesta de la IA fija (la llamada a la IA es lo único que se reemplaza).
 */
class AnalizadorDelCodigoDelDuenoFalso extends AiExcelAnalyzer
{
    /** @var array título de columna => [campo, confianza] */
    public $mapeo = [];

    /**
     * @param  string $prompt
     * @return string
     */
    protected function call_claude(string $prompt): string
    {
        $columnas = [];
        foreach ($this->mapeo as $titulo => $destino) {
            if (strpos($prompt, (string) $titulo) === false) {
                continue;
            }
            $columnas[] = [
                'excel_column' => $titulo, 'system_property' => $destino[0],
                'confidence' => $destino[1], 'interpretation_note' => null,
            ];
        }

        return json_encode([
            'column_mapping' => $columnas, 'provider_id' => null, 'provider_confidence' => 'bajo',
            'assistant_notes' => [], 'encabezados_repetidos' => [],
        ]);
    }
}
