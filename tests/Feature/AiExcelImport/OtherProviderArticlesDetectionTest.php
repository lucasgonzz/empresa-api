<?php

namespace Tests\Feature\AiExcelImport;

use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible as Writer;
use App\Jobs\RunExcelAnalysisJob;
use App\Models\Article;
use App\Models\ExcelAnalysisRun;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Misión historial-precios-proveedor: detección, en la recomendación del importador de 4 pasos, de los
 * artículos del Excel que ya están cargados con OTRO proveedor (CA12 del plan; P2).
 *
 * El paso 3 sólo debe hacer las dos preguntas nuevas ("¿actualizo el costo de los que ya tengo con otro
 * proveedor?" y "¿pasan a ser de este proveedor?") cuando hay algo para preguntar. Con la bandera
 * opcional `detect_other_provider_articles`, `RunExcelAnalysisJob` agrega a su resultado
 * `other_provider_articles`: cuántos artículos y filas del Excel coinciden, por código de barras o SKU,
 * con artículos del dueño que no son del proveedor elegido, de qué proveedores son y hasta cinco
 * ejemplos. Sin la bandera el resultado conserva exactamente las claves de siempre (la SPA vieja).
 *
 * Se corre el job real sobre un .xlsx real (storage/app/imported_files) y la base de testing, con la IA
 * simulada (sin red).
 *
 * 🔴 Rojo esperado ANTES de construir la misión: la clave `other_provider_articles` no existe (la
 * bandera se ignora). El test "sin la bandera" describe el comportamiento de hoy y debe pasar antes y
 * después.
 *
 * IMPORTANTE: PHP 7.4 estricto (ver las restricciones de la misión: nada de sintaxis de PHP 8).
 */
class OtherProviderArticlesDetectionTest extends EmpresaTestCase
{
    /** Claves del resultado de la recomendación sin ninguna bandera opcional (las de siempre). */
    const CLAVES_DE_SIEMPRE = [
        'recomendacion_configuracion',
        'provider_codes_existentes_mismo_proveedor',
        'provider_codes_existentes_otros_proveedores',
        'formatos_numericos',
    ];

    /** @var \App\Models\User Dueño del fixture de testing. */
    protected $owner;

    /** @var \App\Models\Provider Titular de los artículos sembrados ("Buenos Aires"). */
    protected $ba;

    /** @var \App\Models\Provider Proveedor de la lista que se importa ("Rosario"). */
    protected $rosario;

    /** @var string[] Excel creados por el test, para borrarlos al final. */
    protected $archivos = [];

    /**
     * Simula la IA (sin red) y siembra el Taladro (barras) y la Sierra (SKU), ambos de BA.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config([
            'services.anthropic.api_key' => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'  => 'clave-deepseek-de-prueba',
        ]);

        $respuesta = json_encode([
            'politica_colision'      => 'actualizar_todos',
            'politica_intra_archivo' => 'ultima_gana',
            'explicacion'            => 'Recomendación de prueba.',
        ]);

        Http::swap(new HttpFactorySinSalida(app('events')));
        Http::fake(function () use ($respuesta) {
            return Http::response([
                'model'       => 'modelo-de-prueba',
                'content'     => [['type' => 'text', 'text' => $respuesta]],
                'stop_reason' => 'end_turn',
                'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200);
        });

        $this->owner   = auth()->user();
        $this->ba      = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $this->rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $this->sembrar_articulo('Taladro historial deteccion', '8805001', null, 'BA-1');
        $this->sembrar_articulo('Sierra historial deteccion', null, 'SK-805', 'BA-2');
    }

    /**
     * Borra los Excel generados.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos as $ruta) {
            foreach (glob($ruta . '*') ?: [] as $archivo) {
                @unlink($archivo);
            }
        }

        parent::tearDown();
    }

    /**
     * CA12: con la bandera y el proveedor Rosario, el Excel (Taladro por barras, un código inexistente
     * y una fila con el código de BA pero sin barras) detecta UN artículo de otro proveedor: la fila 2.
     * La fila sin barras no cuenta (sólo se cruza por barras/SKU) y la inexistente tampoco.
     *
     * @return void
     */
    public function test_detects_bar_code_match_with_other_provider()
    {
        $run = $this->recomendar($this->filas_del_caso(), $this->rosario->id, true);

        $this->assertSame('listo', $run->estado, json_encode($run->toArray(), JSON_UNESCAPED_UNICODE));

        $this->assertArrayHasKey(
            'other_provider_articles',
            $run->resultado,
            'Con la bandera detect_other_provider_articles la recomendación trae other_provider_articles.'
        );

        $detectado = $run->resultado['other_provider_articles'];

        $this->assertSame(1, $detectado['articles']);
        $this->assertSame(1, $detectado['rows']);
        $this->assertCount(1, $detectado['by_provider']);
        $this->assertSame($this->ba->id, $detectado['by_provider'][0]['provider_id']);
        $this->assertSame(TestingFerreteriaSeeder::PROVIDER_BSAS, $detectado['by_provider'][0]['name']);
        $this->assertSame(1, $detectado['by_provider'][0]['articles']);
        $this->assertCount(1, $detectado['examples']);
        $this->assertSame(2, $detectado['examples'][0]['row'], 'Número de fila del Excel (el encabezado es la 1).');
        $this->assertSame('Taladro historial deteccion', $detectado['examples'][0]['name']);
        $this->assertSame(TestingFerreteriaSeeder::PROVIDER_BSAS, $detectado['examples'][0]['current_provider']);
    }

    /**
     * CA12: el SKU también identifica: una fila sin barras pero con el SKU de la Sierra (de BA) se
     * detecta, y suma a la del Taladro.
     *
     * @return void
     */
    public function test_detects_sku_match_when_row_has_no_bar_code()
    {
        $filas = $this->filas_del_caso();
        $filas[] = ['', 'SK-805', 'R-88', 'Sierra historial deteccion', 90];

        $run = $this->recomendar($filas, $this->rosario->id, true);

        $this->assertSame('listo', $run->estado, json_encode($run->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertArrayHasKey('other_provider_articles', $run->resultado);
        $this->assertSame(2, $run->resultado['other_provider_articles']['articles']);
        $this->assertSame(2, $run->resultado['other_provider_articles']['rows']);
    }

    /**
     * CA12: con el proveedor BA elegido (el titular) no hay artículos "de otro proveedor": cero.
     *
     * @return void
     */
    public function test_selected_provider_counts_zero()
    {
        $run = $this->recomendar($this->filas_del_caso(), $this->ba->id, true);

        $this->assertSame('listo', $run->estado, json_encode($run->toArray(), JSON_UNESCAPED_UNICODE));

        $this->assertArrayHasKey(
            'other_provider_articles',
            $run->resultado,
            'Con la bandera la clave viene siempre, aunque sea en cero.'
        );
        $this->assertSame(0, $run->resultado['other_provider_articles']['articles']);
        $this->assertSame(0, $run->resultado['other_provider_articles']['rows']);
        $this->assertSame([], $run->resultado['other_provider_articles']['examples']);
    }

    /**
     * CA12: sin la bandera (la SPA vieja) la clave no existe y las demás son las de siempre.
     * Caracteriza el comportamiento de hoy: tiene que pasar antes y después de la misión.
     *
     * @return void
     */
    public function test_without_flag_result_keys_unchanged()
    {
        $run = $this->recomendar($this->filas_del_caso(), $this->rosario->id, false);

        $this->assertSame('listo', $run->estado, json_encode($run->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('other_provider_articles', $run->resultado);
        $this->assertEqualsCanonicalizing(self::CLAVES_DE_SIEMPRE, array_keys($run->resultado));
    }

    /**
     * CA12 (endpoint): `POST /ai-excel-import/get-recomendacion` acepta la bandera, la guarda en la
     * corrida y el resultado que devuelve `GET /ai-excel-import/analysis/{uuid}` trae la detección
     * (la cola de testing es sync: el job corre dentro del POST).
     *
     * @return void
     */
    public function test_endpoint_passes_the_flag_to_the_recommendation()
    {
        $respuesta = $this->postJson('/api/ai-excel-import/get-recomendacion', [
            'excel_path'                     => $this->crear_excel($this->filas_del_caso()),
            'provider_id'                    => $this->rosario->id,
            'model'                          => 'article',
            'hoja'                           => 0,
            'header_row'                     => 1,
            'column_mapping'                 => $this->mapeo_de_columnas(),
            'detect_other_provider_articles' => true,
        ]);

        $respuesta->assertStatus(202);

        $estado = $this->getJson('/api/ai-excel-import/analysis/' . $respuesta->json('analysis_uuid'))->assertStatus(200);

        $this->assertSame('listo', $estado->json('estado'));
        $this->assertArrayHasKey('other_provider_articles', $estado->json('resultado'));
        $this->assertSame(1, $estado->json('resultado.other_provider_articles.articles'));
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Filas del Excel del caso: Taladro por barras (fila 2), un código inexistente (fila 3) y una fila
     * con el código de proveedor de BA pero sin barras ni SKU (fila 4).
     *
     * @return array [barras, sku, código de proveedor, nombre, costo]
     */
    protected function filas_del_caso()
    {
        return [
            ['8805001', '', 'R-77', 'Taladro historial deteccion', 1200],
            ['8805009', '', 'R-99', 'Producto inexistente', 100],
            ['', '', 'BA-1', 'Fila sin barras', 50],
        ];
    }

    /**
     * Siembra un artículo de BA del dueño.
     *
     * @param  string      $name
     * @param  string|null $bar_code
     * @param  string|null $sku
     * @param  string      $provider_code
     * @return \App\Models\Article
     */
    protected function sembrar_articulo($name, $bar_code, $sku, $provider_code)
    {
        $article = new Article();

        $article->user_id       = $this->owner->id;
        $article->name          = $name;
        $article->bar_code      = $bar_code;
        $article->sku           = $sku;
        $article->provider_id   = $this->ba->id;
        $article->provider_code = $provider_code;
        $article->cost          = 1000;
        $article->iva_id        = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->iva_id;
        $article->status        = 'active';
        $article->save();

        return $article;
    }

    /**
     * Escribe el Excel en storage/app/imported_files (donde lo deja /analyze) y devuelve su ruta
     * relativa.
     *
     * @param  array $filas  [barras, sku, código de proveedor, nombre, costo]
     * @return string
     */
    protected function crear_excel(array $filas)
    {
        $relativa = 'imported_files/otro_proveedor_deteccion_' . Str::random(8) . '.xlsx';
        $absoluta = storage_path('app/' . $relativa);

        if (!is_dir(dirname($absoluta))) {
            mkdir(dirname($absoluta), 0777, true);
        }

        $this->archivos[] = $absoluta;

        $escritor = Writer::createXLSXWriter();
        $escritor->openToFile($absoluta);
        $escritor->addRow(Writer::createRowFromArray(['Barras', 'SKU', 'Codigo proveedor', 'Nombre', 'Costo']));

        foreach ($filas as $fila) {
            $escritor->addRow(Writer::createRowFromArray($fila));
        }

        $escritor->close();

        return $relativa;
    }

    /**
     * Mapeo de columnas confirmado en el paso 2 para el Excel de crear_excel().
     *
     * @return array
     */
    protected function mapeo_de_columnas()
    {
        return [
            ['system_property' => 'codigo_de_barras',    'excel_column_index' => 0, 'excel_column' => 'Barras'],
            ['system_property' => 'sku',                 'excel_column_index' => 1, 'excel_column' => 'SKU'],
            ['system_property' => 'codigo_de_proveedor', 'excel_column_index' => 2, 'excel_column' => 'Codigo proveedor'],
            ['system_property' => 'nombre',              'excel_column_index' => 3, 'excel_column' => 'Nombre'],
            ['system_property' => 'costo',               'excel_column_index' => 4, 'excel_column' => 'Costo'],
        ];
    }

    /**
     * Crea la corrida de recomendación con el mapeo confirmado y ejecuta el job.
     *
     * @param  array    $filas        [barras, sku, código de proveedor, nombre, costo]
     * @param  int      $provider_id  proveedor elegido en el paso 1
     * @param  bool     $con_bandera  si pide la detección de artículos de otro proveedor
     * @return \App\Models\ExcelAnalysisRun
     */
    protected function recomendar(array $filas, $provider_id, $con_bandera)
    {
        $payload = [
            'model'                      => 'article',
            'provider_id'                => $provider_id,
            'provider_code_column_index' => null,
            'hoja'                       => 0,
            'header_row'                 => 1,
            'column_mapping'             => $this->mapeo_de_columnas(),
        ];

        if ($con_bandera) {
            $payload['detect_other_provider_articles'] = true;
        }

        $run = ExcelAnalysisRun::create([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $this->owner->id,
            'auth_user_id' => $this->owner->id,
            'tipo'         => 'recomendacion',
            'estado'       => 'pendiente',
            'excel_path'   => $this->crear_excel($filas),
            'payload'      => $payload,
        ]);

        (new RunExcelAnalysisJob($run->id))->handle();

        return $run->fresh();
    }
}
