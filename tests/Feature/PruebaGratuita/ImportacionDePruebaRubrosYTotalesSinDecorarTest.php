<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\Address;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\ImportacionDePrueba;
use App\Models\ImportHistory;
use App\Models\User;
use App\Services\ImageAssignment\CupoImagenesDePrueba;
use App\Services\Importacion\ImportacionDePruebaService;
use App\Services\Importacion\PlanillaDePrueba;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

/**
 * Títulos de rubro sin decorar y totales de stock en la carga asistida de la prueba (misión
 * prueba-e2e-campana-imperio, ronda 5, ítem E8, 14/11/2026).
 *
 * En producción (4.3.61) «inventario local y galpon.xlsx» terminó con cinco artículos que no lo son:
 * «HERRAMIENTAS», «PINTURERIA», «ELECTRICIDAD», «SANITARIOS» (solo el nombre, en la columna del artículo) y
 * «TOTAL UNIDADES» (sin código ni precio, con la suma de las dos columnas de stock). El resumen dijo
 * «Cargué 29 artículos» y eran 24.
 *
 * Las otras cinco planillas del ensayo (la lista del proveedor, mis productos, costos y margen, stock con
 * precios y «me faltaron estos») tienen que seguir cargando exactamente las mismas filas que antes del
 * cambio (medido contra origin/develop 3541eafd, ver CUENTAS_DE_DEVELOP).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionDePruebaRubrosYTotalesSinDecorarTest extends EmpresaTestCase
{
    const CLAVE = 'admin-prueba-rubros-y-totales-test';
    const FIXTURES = __DIR__ . '/../../Import/fixtures/';
    const RUBROS = ['HERRAMIENTAS', 'PINTURERIA', 'ELECTRICIDAD', 'SANITARIOS'];

    private $dueno;
    private $archivos = [];

    /** @var \App\Models\Address */
    private $suya;

    /** @var \App\Models\Address */
    private $otra;

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
        CupoImagenesDePrueba::editar((int) $this->dueno->id, 0);

        $sucursales = Address::sucursales_de($this->dueno->id)->get();
        $this->assertGreaterThanOrEqual(2, $sucursales->count(), 'El fixture tiene que traer dos sucursales.');
        $this->suya = Address::principal_de($this->dueno->id);
        $this->otra = $sucursales->first(function ($sucursal) { return (int) $sucursal->id !== (int) $this->suya->id; });
        User::whereKey($this->dueno->id)->update(['address_id' => $this->suya->id]);
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

    private function analizador(array $mapeo): void
    {
        $dueno = $this->dueno;
        $this->app->bind(AiExcelAnalyzer::class, function () use ($dueno, $mapeo) {
            $analizador = new AnalizadorDeRubrosYTotalesFalso((int) $dueno->id);
            $analizador->mapeo = $mapeo;

            return $analizador;
        });
    }

    /**
     * Una corrida de la prueba sobre un fixture o sobre una planilla armada con las filas dadas.
     */
    private function corrida($fuente, array $campos = []): ImportacionDePrueba
    {
        $uuid = (string) Str::uuid();
        $relativa = 'imported_files/prueba_' . $uuid . '.xlsx';
        $destino = storage_path('app/' . $relativa);

        if (is_string($fuente)) {
            copy(self::FIXTURES . $fuente, $destino);
        } else {
            $libro = new Spreadsheet();
            $libro->getActiveSheet()->setTitle('Hoja 0')->fromArray($fuente, null, 'A1', true);
            (new Xlsx($libro))->save($destino);
            $libro->disconnectWorksheets();
        }

        $this->archivos[] = $destino;
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '_');
        $this->archivos[] = storage_path('app/imported_files/prueba_' . $uuid . '.preparado');

        return ImportacionDePrueba::create($campos + [
            'uuid' => $uuid, 'user_id' => $this->dueno->id, 'support_ticket_id' => 125,
            'clave' => hash('sha256', $uuid), 'archivo' => $relativa, 'nombre' => is_string($fuente) ? $fuente : 'armada.xlsx',
            'origen' => 'martin', 'estado' => 'analizar',
        ]);
    }

    private function avanzar(ImportacionDePrueba $run): ImportacionDePrueba
    {
        app(ImportacionDePruebaService::class)->avanzar($run->fresh());

        return $run->fresh();
    }

    /** El mapeo de la IA y las columnas previas de Martín para «inventario local y galpon.xlsx». */
    private function inventario(): array
    {
        return [
            ['Cod.' => ['numero', 0.97], 'Articulo' => ['nombre', 0.97], 'Ubic.' => [null, 0.97], 'P. Venta' => ['precio', 0.97]],
            ['columnas_previas' => [
                ['hoja' => null, 'columna' => 'C', 'campo' => null],
                ['hoja' => null, 'columna' => 'D', 'campo' => 'address_' . $this->suya->id . '_amount'],
                ['hoja' => null, 'columna' => 'E', 'campo' => 'address_' . $this->otra->id . '_amount'],
                ['hoja' => null, 'columna' => 'F', 'campo' => 'precio'],
            ]],
        ];
    }

    /**
     * Lee la planilla como la lee la carga de la prueba hasta escribir la planilla preparada, con las
     * respuestas dadas a las preguntas de columnas, y devuelve cuántas filas llegan al motor y cuáles se
     * dejaron afuera. Sin repetidos: lo que se mide acá es solo el descarte de filas que no son artículos.
     *
     * @return array [filas que llegan al motor, filas_salteadas por hoja]
     */
    private function leer($fuente, array $mapeo, array $respuestas, array $campos = []): array
    {
        $this->analizador($mapeo);
        $run = $this->corrida($fuente, $campos);
        $planilla = new PlanillaDePrueba($run);
        $hojas = $planilla->analizar(app(AiExcelAnalyzer::class));
        if (!empty($run->columnas_previas)) {
            $hojas = $planilla->aplicar_columnas_previas($hojas, $run->columnas_previas);
        }
        $preguntas = $planilla->preguntas_columnas($hojas);
        $this->assertSame([], array_values(array_diff(array_keys($preguntas), array_keys($respuestas))), 'Preguntas sin respuesta: ' . json_encode($preguntas, JSON_UNESCAPED_UNICODE));

        $resueltas = PlanillaDePrueba::aplicar_margen($planilla->resolver_columnas($hojas, $respuestas), ['margen_de_ganancia' => '40']);
        $plan = $planilla->escribir($resueltas, ['descartar' => [], 'separar' => []]);
        @unlink($plan['ruta']);

        return [$plan['filas'], $plan['salteadas']];
    }

    /**
     * Las seis planillas del ensayo: fixture, mapeo de la IA, respuestas, columnas previas, filas que cargaba
     * origin/develop (3541eafd) antes del cambio y filas que carga ahora.
     */
    private function planillas(): array
    {
        [$mapeo_inventario, $previas_inventario] = $this->inventario();

        return [
            'inventario' => ['inventario_local_y_galpon.xlsx', $mapeo_inventario, [], $previas_inventario, 29, 24],
            'lista del proveedor' => ['lista_distribuidora_del_litoral_nov.xlsx', [
                'COD' => ['codigo_de_proveedor', 0.97], 'DESCRIPCION' => ['nombre', 0.97], 'PRECIO' => ['precio', 0.99],
                'IVA' => ['iva', 0.97], 'U.M.' => [null, 0.97], 'BULTO' => [null, 0.97],
            ], ['columna_0_2' => 'costo'], [], 50, 50],
            'mis productos' => ['mis_productos.xlsx', ['articulo' => ['nombre', 0.97], 'precio' => ['precio', 0.99], 'hay' => [null, 0.97]],
                ['columna_0_1' => 'precio', 'columna_0_2' => 'ignorar'], [], 36, 36],
            'costos y margen' => ['costos_y_margen.xlsx', [
                'Cod.' => ['codigo_de_proveedor', 0.97], 'Detalle' => ['nombre', 0.97], 'Costo s/IVA' => ['costo', 0.97],
                '%' => ['margen_de_ganancia', 0.50], 'Final' => ['precio', 0.97],
            ], ['columna_0_1' => 'nombre', 'columna_0_3' => 'margen_de_ganancia', 'columna_0_4' => 'ignorar'], [], 32, 32],
            'stock con precios' => ['stock_deposito_con_precios.xlsx', [
                'Codigo de barras' => ['codigo_de_barras', 0.97], 'Producto' => ['nombre', 0.97], 'Cant' => ['stock_actual', 0.97],
                'Dep 1' => [null, 0.97], 'Dep 2' => [null, 0.97], 'Precio Lista' => ['precio', 0.97],
                'Precio Contado' => [null, 0.97], 'Marca' => ['marca', 0.97],
            ], ['precios_0' => 'columna_0_5'], [], 28, 28],
            'me faltaron estos' => ['me_faltaron_estos.xlsx', [
                'codigo' => ['numero', 0.97], 'detalle' => ['nombre', 0.97], 'cant' => ['stock_actual', 0.97], 'precio' => ['precio', 0.99],
            ], ['columna_0_1' => 'nombre', 'columna_0_3' => 'precio'], [], 6, 6],
        ];
    }

    public function test_las_seis_planillas_del_ensayo_cargan_las_mismas_filas_salvo_los_rubros_y_el_total_del_inventario(): void
    {
        $cuentas = [];
        foreach ($this->planillas() as $caso => $planilla) {
            [$fixture, $mapeo, $respuestas, $previas, $antes, $despues] = $planilla;
            [$filas, $salteadas] = $this->leer($fixture, $mapeo, $respuestas, $previas);
            $cuentas[$caso] = $filas;
            $this->assertSame($despues, $filas, $caso . ' (develop cargaba ' . $antes . '): ' . json_encode($salteadas, JSON_UNESCAPED_UNICODE));
        }
        fwrite(STDERR, PHP_EOL . 'Filas al motor: ' . json_encode($cuentas, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    public function test_el_inventario_carga_24_articulos_y_el_resumen_nombra_las_cinco_filas_que_dejo_afuera(): void
    {
        $this->assertSame('9d36064c596042f200678972d7c1fcccdb07a180c822a608a4210d70c1dce048', hash_file('sha256', self::FIXTURES . 'inventario_local_y_galpon.xlsx'));
        [$mapeo, $previas] = $this->inventario();
        $this->analizador($mapeo);
        Bus::fake();
        $run = $this->avanzar($this->corrida('inventario_local_y_galpon.xlsx', $previas));
        $this->assertSame('importando', $run->estado, json_encode($run->preguntas, JSON_UNESCAPED_UNICODE));

        $salteadas = $run->hojas[0]['filas_salteadas'];
        $this->assertSame(4, $salteadas['titulo']);
        $this->assertSame(1, $salteadas['total']);
        $this->assertSame(self::RUBROS, $salteadas['textos_titulo']);
        $this->assertSame(['TOTAL UNIDADES'], $salteadas['textos_total']);

        // Al motor llegan los 24 artículos y nada más.
        $inicios = Bus::dispatched(ProcessArticleChunk::class);
        $this->assertCount(1, $inicios);
        $primero = $inicios->first();
        $trabajos = [$primero];
        foreach ($primero->chained as $serializado) {
            $siguiente = unserialize($serializado);
            if ($siguiente instanceof ProcessArticleChunk) {
                $trabajos[] = $siguiente;
            }
        }
        $filas = [];
        foreach ($trabajos as $job) {
            $filas = array_merge($filas, $job->get_row_from_csv());
            $job->handle();
        }
        $this->assertCount(24, $filas);
        $crudo = json_encode($filas, JSON_UNESCAPED_UNICODE);
        foreach (array_merge(self::RUBROS, ['TOTAL UNIDADES']) as $intruso) {
            $this->assertStringNotContainsString($intruso, $crudo, $intruso);
        }

        $historial = ImportHistory::findOrFail($run->import_history_id);
        (new FinalizeArticleImport($this->dueno->id, $historial->id, $historial->import_status_id))->handle();
        $this->assertSame(24, (int) $historial->fresh()->created_models);
        $this->assertSame(0, Article::where('user_id', $this->dueno->id)
            ->whereIn('name', array_merge(self::RUBROS, ['TOTAL UNIDADES', 'Herramientas', 'Pintureria', 'Electricidad', 'Sanitarios', 'Total unidades']))->count());
        $this->assertSame(1, Article::where('user_id', $this->dueno->id)->where('sku', 'F-101')->count(), 'El código con letra sigue entrando.');

        $frase = ' Dejé afuera 5 filas que no son artículos: “HERRAMIENTAS”, “PINTURERIA”, “ELECTRICIDAD”, “SANITARIOS”, “TOTAL UNIDADES”.'
            . ' Si alguna era un producto, decímelo y la cargo.';
        $this->assertSame($frase, PlanillaDePrueba::texto_de_filas_salteadas($run->hojas));

        $this->avanzar($run);
        $resumen = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 125)
            ->where('texto', 'like', 'Cargué%')->firstOrFail()->texto;
        $this->assertStringStartsWith('Cargué 24 artículos', $resumen);
        $this->assertStringContainsString(trim($frase), $resumen);
    }

    /** Una planilla con código en la mayoría de las filas: diez artículos completos alrededor de la fila dada. */
    private function con_codigos(array $fila_del_medio, array $al_pie = []): array
    {
        $filas = [['Código', 'Descripción', 'Precio de venta', 'Local', 'Galpon']];
        for ($i = 1; $i <= 10; $i++) {
            $filas[] = ['R-' . $i, 'Artículo de prueba ' . $i, 100 * $i, $i, $i + 1];
            if ($i === 5) {
                $filas[] = $fila_del_medio;
            }
        }
        foreach ($al_pie as $fila) {
            $filas[] = $fila;
        }

        return $filas;
    }

    private function mapeo_con_codigos(): array
    {
        return ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]];
    }

    /** El stock de las dos sucursales, como lo deja Martín en las columnas previas. */
    private function dos_stocks(): array
    {
        return ['columnas_previas' => [
            ['hoja' => null, 'columna' => 'D', 'campo' => 'address_' . $this->suya->id . '_amount'],
            ['hoja' => null, 'columna' => 'E', 'campo' => 'address_' . $this->otra->id . '_amount'],
        ]];
    }

    public function test_un_articulo_que_empieza_con_total_y_trae_codigo_o_precio_se_carga(): void
    {
        // En el medio y al pie (la fila de cierre), con código y precio.
        [$filas, $salteadas] = $this->leer($this->con_codigos(['TF-1', 'Total Flex 1L', 5400, 3, 1], [['TF-2', 'Total Flex 4L', 18000, 2, 4]]), $this->mapeo_con_codigos(), [], $this->dos_stocks());
        $this->assertSame(12, $filas, json_encode($salteadas, JSON_UNESCAPED_UNICODE));

        // Al pie, con precio y sin código: tampoco es el total (la regla del total sin código ni precio no lo toca).
        [$filas, $salteadas] = $this->leer($this->con_codigos(['R-50', 'Pinza', 10, 1, 1], [[null, 'Total Flex', 5400, 3, 4]]), $this->mapeo_con_codigos(), [], $this->dos_stocks());
        $this->assertSame(12, $filas, json_encode($salteadas, JSON_UNESCAPED_UNICODE));
    }

    public function test_el_total_de_las_columnas_de_stock_al_pie_se_deja_afuera(): void
    {
        foreach (['TOTAL UNIDADES', 'Totales:', 'SUBTOTAL', 'suma de stock'] as $rotulo) {
            [$filas, $salteadas] = $this->leer($this->con_codigos(['R-50', 'Pinza', 10, 1, 1], [[null, $rotulo, null, 56, 66]]), $this->mapeo_con_codigos(), [], $this->dos_stocks());
            $this->assertSame(11, $filas, $rotulo);
            $this->assertSame([rtrim($rotulo, '. ')], $salteadas[0]['textos_total'], $rotulo);
        }
    }

    public function test_un_titulo_de_rubro_sin_decorar_se_deja_afuera_en_una_planilla_con_codigos(): void
    {
        foreach (['HERRAMIENTAS', 'Herramientas manuales', 'pinturería'] as $rubro) {
            [$filas, $salteadas] = $this->leer($this->con_codigos([null, $rubro, null, null, null]), $this->mapeo_con_codigos(), [], $this->dos_stocks());
            $this->assertSame(10, $filas, $rubro);
            $this->assertSame([$rubro], $salteadas[0]['textos_titulo'], $rubro);
        }
        // Con un número, un signo o algún otro dato al lado, es un artículo.
        foreach ([[null, 'Martillo 500g', null, null, null], [null, '**Martillo carpintero', null, null, null], [null, 'Martillo carpintero', null, 3, null], [null, 'Martillo carpintero', 'consultar', null, null]] as $fila) {
            [$filas, $salteadas] = $this->leer($this->con_codigos($fila), $this->mapeo_con_codigos(), [], $this->dos_stocks());
            $this->assertSame(11, $filas, json_encode($fila, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_un_articulo_con_solo_el_nombre_se_carga_si_la_planilla_no_trae_codigos_en_la_mayoria(): void
    {
        // Sin columna de código: una planilla del dueño, con o sin precio.
        [$filas] = $this->leer([
            ['Producto', 'Precio de venta'],
            ['Martillo carpintero', 1000], ['Tenaza', null], ['Pinza universal', 500], ['Serrucho', null],
            ['Destornillador', 300], ['Lima', 200], ['Cutter', 150], ['Escuadra', 900], ['Nivel', 800], ['Formón', 700], ['Cincel', 600],
        ], ['Producto' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]], []);
        $this->assertSame(11, $filas, 'Tenaza y Serrucho son artículos sin precio.');

        // Con columna de código, pero con código en menos de tres de cada cuatro filas.
        $planilla = [['Código', 'Descripción', 'Precio de venta']];
        for ($i = 1; $i <= 10; $i++) {
            $planilla[] = ['C-' . $i, 'Con código ' . $i, 100];
            $planilla[] = [null, 'Sin codigo', 100];
        }
        $planilla[] = [null, 'Tenaza', null];
        [$filas] = $this->leer($planilla, ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]], []);
        $this->assertSame(21, $filas, 'La mitad sin código: «Tenaza» es un artículo.');

        // Con menos de diez filas con código no alcanza para decir que la planilla los trae.
        [$filas] = $this->leer([
            ['Código', 'Descripción', 'Precio de venta'],
            ['A-1', 'Martillo', 100], [null, 'Tenaza', null], ['A-2', 'Pinza', 200],
        ], ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]], []);
        $this->assertSame(3, $filas);
    }

    public function test_en_modo_soporte_los_rubros_y_el_total_del_inventario_siguen_entrando(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        PruebaHelper::olvidar_cache();
        [$mapeo, $previas] = $this->inventario();
        [$filas, $salteadas] = $this->leer('inventario_local_y_galpon.xlsx', $mapeo, [], $previas + ['modo_soporte' => true]);
        $this->assertSame(29, $filas);
        $this->assertSame([], $salteadas);
    }
}

/**
 * El analizador real con una respuesta de IA fija: el mapeo que le dio el test, por título de columna.
 */
class AnalizadorDeRubrosYTotalesFalso extends AiExcelAnalyzer
{
    /** @var array título de columna => [campo, confianza] */
    public $mapeo = [];

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
