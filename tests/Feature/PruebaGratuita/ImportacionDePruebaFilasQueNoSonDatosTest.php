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
 * Filas que no son datos, en cualquier parte de la planilla, en la carga asistida de la prueba (misión
 * prueba-e2e-campana-imperio, ronda 6, ítem E8.1, 14/11/2026).
 *
 * E8 arregló «inventario local y galpon.xlsx» (rubros sin decorar y el total de stock al pie). La planilla de la
 * ronda 6, «stock corralon noviembre.xlsx», trae la misma clase de filas en otra forma: dos rubros decorados en
 * la columna del código, dos simples en la del nombre, cuatro «Subtotal <rubro>» EN EL MEDIO con la suma en la
 * columna Stock y «TOTAL GENERAL» al pie. Medido contra 9f300ddb (antes del cambio): llegaban 26 filas al motor
 * (los 20 artículos, los 4 subtotales, «PLOMERIA» y «BULONERIA»), porque los subtotales del medio no eran la
 * fila de cierre y porque esas mismas 5 filas de total bajaban la proporción de filas con código a 20 de 27: la
 * hoja dejaba de contar como «con códigos» y los rubros simples pasaban como artículos sin precio.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionDePruebaFilasQueNoSonDatosTest extends EmpresaTestCase
{
    const CLAVE = 'admin-prueba-filas-que-no-son-datos-test';
    const FIXTURES = __DIR__ . '/../../Import/fixtures/';
    const NO_DATOS = ['--- CONSTRUCCION ---', 'Subtotal construccion', 'PLOMERIA', 'Subtotal plomeria', '--- PINTURERIA ---',
        'Subtotal pintureria', 'BULONERIA', 'Subtotal buloneria', 'TOTAL GENERAL'];

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
            $analizador = new AnalizadorDeNoDatosFalso((int) $dueno->id);
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


    /** El mapeo que da la IA para «stock corralon noviembre.xlsx» (columnas A a G, cabecera en la fila 3). */
    private function mapeo_corralon(): array
    {
        return ['Art' => ['numero', 0.97], 'Descripcion' => ['nombre', 0.97], 'Marca' => ['marca', 0.97],
            'Costo s/IVA' => ['costo', 0.97], 'IVA' => ['iva', 0.97], 'Stock' => ['stock_actual', 0.97], 'Precio lista' => ['precio', 0.97]];
    }

    /** La única pregunta que hace la prueba con el corralón: qué es «Precio lista» (la contesta el dueño). */
    const RESPUESTAS_CORRALON = ['columna_0_6' => 'precio'];

    public function test_el_corralon_carga_los_20_articulos_y_el_resumen_cuenta_las_9_filas_que_dejo_afuera(): void
    {
        $this->assertSame('549251d3867b5c541e44561da409b324a111ace85e5b92c63e282eec7ab8da48', hash_file('sha256', self::FIXTURES . 'stock_corralon_noviembre.xlsx'));
        $this->analizador($this->mapeo_corralon());
        Bus::fake();
        $run = $this->avanzar($this->corrida('stock_corralon_noviembre.xlsx'));
        $this->assertSame('columnas', $run->estado);
        $this->assertSame(array_keys(self::RESPUESTAS_CORRALON), array_keys((array) $run->preguntas), 'Solo se pregunta por «Precio lista».');
        // Lo que hace el admin cuando el dueño contesta (ImportacionDePruebaController::resolver()).
        $run->decisiones = self::RESPUESTAS_CORRALON;
        $run->retirar_pregunta();
        $run->estado = $run->estado_para('preparar');
        $run->save();
        $run = $this->avanzar($run);
        $this->assertSame('importando', $run->estado, json_encode($run->preguntas, JSON_UNESCAPED_UNICODE));

        $salteadas = $run->hojas[0]['filas_salteadas'];
        $this->assertSame(4, $salteadas[PlanillaDePrueba::FILA_TITULO]);
        $this->assertSame(4, $salteadas[PlanillaDePrueba::FILA_SUBTOTAL]);
        $this->assertSame(1, $salteadas[PlanillaDePrueba::FILA_TOTAL]);
        $this->assertSame(['--- CONSTRUCCION ---', 'PLOMERIA', '--- PINTURERIA ---', 'BULONERIA'], $salteadas['textos_titulo']);
        $this->assertSame(['Subtotal construccion', 'Subtotal plomeria', 'Subtotal pintureria', 'Subtotal buloneria'], $salteadas['textos_subtotal']);
        $this->assertSame(['TOTAL GENERAL'], $salteadas['textos_total']);

        // Al motor llegan los 20 artículos y nada más.
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
        $this->assertCount(20, $filas);
        $crudo = json_encode($filas, JSON_UNESCAPED_UNICODE);
        foreach (self::NO_DATOS as $intruso) {
            $this->assertStringNotContainsString($intruso, $crudo, $intruso);
        }

        $historial = ImportHistory::findOrFail($run->import_history_id);
        (new FinalizeArticleImport($this->dueno->id, $historial->id, $historial->import_status_id))->handle();
        $this->assertSame(20, (int) $historial->fresh()->created_models);
        $nombres = array_merge(self::NO_DATOS, ['Subtotal', 'Plomeria', 'Buloneria', 'Construccion', 'Pintureria', 'Total general']);
        $this->assertSame(0, Article::where('user_id', $this->dueno->id)->whereIn('name', $nombres)->count());

        $articulo = function ($sku) {
            return Article::where('user_id', $this->dueno->id)->where('sku', $sku)->with('iva', 'addresses')->firstOrFail();
        };
        // El código del dueño, con sus ceros a la izquierda y con letra, como SKU (texto, tal cual vino).
        foreach (['0012', 'A-0014', '0020', 'A-0060'] as $sku) {
            $this->assertSame($sku, (string) $articulo($sku)->sku);
        }
        $this->assertSame(0, Article::where('user_id', $this->dueno->id)->whereIn('sku', ['12', '20', '34'])->count(), 'Los ceros no se pierden.');

        // Costo en texto con coma decimal («427,95», «3.614,94») o número; IVA «10,5%», «21%», 10.5 o 21.
        $cemento = $articulo('0012');
        $this->assertEquals(427.95, (float) $cemento->cost);
        $this->assertSame(3, (int) $cemento->iva_id, '«10,5%» es el 10.5 de la tabla, no un IVA nuevo «10,5».');
        $this->assertEquals(709.33, (float) $cemento->price);
        $this->assertSame(3, (int) $articulo('A-0014')->iva_id);
        $arena = $articulo('A-0019');
        $this->assertEquals(3614.94, (float) $arena->cost);
        $this->assertSame(2, (int) $arena->iva_id);
        $this->assertEquals(6561.12, (float) $arena->price);
        $this->assertEquals(68884.56, (float) $articulo('A-0021')->cost);
        $this->assertSame(0, \App\Models\Iva::where('percentage', '10,5')->count(), 'No se creó un IVA «10,5».');

        /*
         * Stock: un número va a la sucursal del dueño (E5). VACÍO = sin dato: el artículo queda sin stock cargado
         * (null, sin fila en la sucursal), no en 0, igual que si la planilla no trajera la columna. NEGATIVO se
         * carga tal cual (-2): el sistema admite stock negativo (vender sin stock lo deja así) y el dueño lo ve en el
         * Listado; inventarle un 0 sería esconder su faltante.
         */
        $this->assertNull($arena->stock);
        $this->assertCount(0, $arena->addresses);
        $this->assertNull($articulo('A-0060')->stock);
        $llave = $articulo('A-0031');
        $this->assertEquals(-2, (float) $llave->stock);
        $this->assertEquals(-2, (float) $llave->addresses->first()->pivot->amount);
        $this->assertEquals(0, (float) $articulo('A-0033')->stock);
        $this->assertEquals(40, (float) $cemento->stock);

        $frase = ' Dejé afuera 9 filas que no son artículos: “--- CONSTRUCCION ---”, “PLOMERIA”, “--- PINTURERIA ---”,'
            . ' “Subtotal construccion”, …, “TOTAL GENERAL”. Si alguna era un producto, decímelo y la cargo.';
        $this->assertSame($frase, PlanillaDePrueba::texto_de_filas_salteadas($run->hojas));

        $this->avanzar($run);
        $resumen = AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 125)
            ->where('texto', 'like', 'Cargué%')->firstOrFail()->texto;
        $this->assertStringStartsWith('Cargué 20 artículos', $resumen);
        $this->assertStringContainsString(trim($frase), $resumen);
    }

    public function test_un_subtotal_o_total_en_el_medio_de_una_hoja_con_codigos_se_deja_afuera(): void
    {
        $casos = [
            [null, 'Subtotal herramientas', null, 56, 66], [null, 'SUBTOTAL:', null, 56, null], [null, 'Totales', null, null, null],
            [null, 'total parcial', null, 3, 4], [null, 'Sub-total pinturería', null, 12, null], [null, 'Suma de stock', null, 9, 9],
            // Con el nombre vacío, el rótulo en la columna del código.
            ['SUBTOTAL', null, null, 56, 66],
        ];
        foreach ($casos as $fila) {
            [$filas, $salteadas] = $this->leer($this->con_codigos($fila), $this->mapeo_con_codigos(), [], $this->dos_stocks());
            $this->assertSame(10, $filas, json_encode($fila, JSON_UNESCAPED_UNICODE));
            $this->assertSame(1, $salteadas[0][PlanillaDePrueba::FILA_SUBTOTAL] ?? null, json_encode([$fila, $salteadas], JSON_UNESCAPED_UNICODE));
        }

        // Varios subtotales y el total al pie: un subtotal del medio no abre «el pie de notas», el artículo sin
        // precio que viene debajo («Pinza sin precio», con código) sigue entrando.
        [$filas, $salteadas] = $this->leer($this->con_codigos([null, 'Subtotal herramientas', null, 56, 66], [
            ['R-90', 'Pinza sin precio', null, null, null], [null, 'Subtotal pinzas', null, 1, 1], [null, 'TOTAL GENERAL', null, 57, 67],
        ]), $this->mapeo_con_codigos(), [], $this->dos_stocks());
        $this->assertSame(11, $filas, json_encode($salteadas, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['Subtotal herramientas', 'Subtotal pinzas'], $salteadas[0]['textos_subtotal']);
        $this->assertSame(['TOTAL GENERAL'], $salteadas[0]['textos_total']);
    }

    public function test_lo_que_empieza_parecido_a_un_total_y_es_un_articulo_se_carga(): void
    {
        $articulos = [
            // No es la palabra completa.
            [null, 'Totalgaz garrafa', null, 3, 1], [null, 'Sumatoria', null, 3, 1],
            // Trae números: es un nombre de producto.
            [null, 'Total Quartz 5W40', null, 3, 1], [null, 'Suma dental x 12', null, 2, null],
            // Trae código o precio.
            ['TF-1', 'Total Flex', null, 3, 1], [null, 'Subtotal', 5400, 1, 1], [null, 'Total Flex', 'consultar', 1, 1],
            // «Stock total» no empieza con la palabra.
            [null, 'Stock total depósito', null, 4, 4],
        ];
        foreach ($articulos as $fila) {
            [$filas, $salteadas] = $this->leer($this->con_codigos($fila), $this->mapeo_con_codigos(), [], $this->dos_stocks());
            $this->assertSame(11, $filas, json_encode([$fila, $salteadas], JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_en_una_hoja_sin_codigos_solo_se_deja_afuera_el_total_de_cierre(): void
    {
        /*
         * Lo que queda SIN cubrir a propósito (decisión de E8): en la planilla del dueño, sin códigos, hay artículos
         * sin precio, así que un «Subtotal herramientas | | 30» en el medio no se distingue por la forma de un
         * artículo y se carga. El total de la última fila sí se va, como antes.
         */
        $planilla = [['Producto', 'Precio de venta', 'Stock']];
        foreach (['Martillo carpintero', 'Tenaza', 'Pinza universal', 'Serrucho', 'Destornillador', 'Lima'] as $i => $nombre) {
            $planilla[] = [$nombre, $i % 2 === 0 ? 1000 + $i : null, $i + 1];
        }
        $planilla[] = ['Subtotal herramientas', null, 21];
        foreach (['Cutter', 'Escuadra', 'Nivel', 'Formón', 'Cincel'] as $nombre) {
            $planilla[] = [$nombre, 500, 2];
        }
        $planilla[] = ['TOTAL', null, 31];
        [$filas, $salteadas] = $this->leer($planilla, ['Producto' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97], 'Stock' => ['stock_actual', 0.97]], []);
        $this->assertSame(12, $filas, json_encode($salteadas, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['TOTAL'], $salteadas[0]['textos_total']);
        $this->assertArrayNotHasKey(PlanillaDePrueba::FILA_SUBTOTAL, $salteadas[0]);
    }

    public function test_la_cabecera_repetida_entera_en_el_medio_se_deja_afuera(): void
    {
        foreach ([['Código', 'Descripción', 'Precio de venta', null, null], ['CODIGO', 'descripcion', 'Precio de Venta', 'Local', 'Galpon']] as $fila) {
            [$filas, $salteadas] = $this->leer($this->con_codigos($fila), $this->mapeo_con_codigos(), [], $this->dos_stocks());
            $this->assertSame(10, $filas, json_encode([$fila, $salteadas], JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_en_modo_soporte_el_corralon_y_los_subtotales_siguen_entrando(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        PruebaHelper::olvidar_cache();
        [$filas, $salteadas] = $this->leer('stock_corralon_noviembre.xlsx', $this->mapeo_corralon(), self::RESPUESTAS_CORRALON, ['modo_soporte' => true]);
        $this->assertSame(29, $filas, 'Los 20 artículos más las 9 filas que en la prueba se dejan afuera.');
        $this->assertSame([], $salteadas);

        [$filas, $salteadas] = $this->leer($this->con_codigos([null, 'Subtotal herramientas', null, 56, 66], [[null, 'TOTAL GENERAL', null, 56, 66]]),
            $this->mapeo_con_codigos(), [], $this->dos_stocks() + ['modo_soporte' => true]);
        $this->assertSame(12, $filas);
        $this->assertSame([], $salteadas);
    }

    /* ---------- E8.2 (14/11/2026): el pie que cuenta, la columna del nombre y la sugerencia del margen ---------- */

    public function test_el_pie_que_cuenta_en_su_rotulo_se_deja_afuera(): void
    {
        $pies = ['Total artículos: 21', 'Total: 21 artículos', 'Total de la hoja 1', 'TOTAL 21', 'Cantidad de artículos: 21', 'Son 21 artículos'];
        foreach ($pies as $pie) {
            [$filas, $salteadas] = $this->leer($this->con_codigos(['R-50', 'Pinza', 10, 1, 1], [[null, $pie, null, null, null]]), $this->mapeo_con_codigos(), [], $this->dos_stocks());
            $this->assertSame(11, $filas, json_encode([$pie, $salteadas], JSON_UNESCAPED_UNICODE));
            $this->assertSame([rtrim($pie, '. ')], $salteadas[0]['textos_total'], $pie);
        }
        // También con la cuenta al lado y en el medio de la hoja: es el pie aunque no sea la última fila.
        [$filas, $salteadas] = $this->leer($this->con_codigos([null, 'TOTAL 21', null, 56, 66]), $this->mapeo_con_codigos(), [], $this->dos_stocks());
        $this->assertSame(10, $filas, json_encode($salteadas, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['TOTAL 21'], $salteadas[0]['textos_total']);
    }

    public function test_un_producto_con_numero_en_el_nombre_que_empieza_con_total_se_carga(): void
    {
        foreach ([['TQ-1', 'Total Quartz 5W40', null, 3, 1], [null, 'Total 10W40 aceite', 5400, 1, 1], [null, 'Total 10W40 aceite', null, 1, 1],
            [null, 'Total 3 en 1 aceite', 900, 1, 1], ['T-500', 'Total 500', null, 1, 1]] as $fila) {
            [$filas, $salteadas] = $this->leer($this->con_codigos($fila), $this->mapeo_con_codigos(), [], $this->dos_stocks());
            $this->assertSame(11, $filas, json_encode([$fila, $salteadas], JSON_UNESCAPED_UNICODE));
        }
    }

    /** Analiza la planilla como la prueba y devuelve [hojas, preguntas de columnas]. */
    private function preguntas($fuente, array $mapeo, array $campos = []): array
    {
        $this->analizador($mapeo);
        $planilla = new PlanillaDePrueba($this->corrida($fuente, $campos));
        $hojas = $planilla->analizar(app(AiExcelAnalyzer::class));

        return [$hojas, $planilla->preguntas_columnas($hojas)];
    }

    /** Diez artículos con código, el texto en la columna B (con el título dado) y el precio. */
    private function con_texto_en(string $titulo, array $otras = []): array
    {
        $filas = [array_merge(['Código', $titulo, 'Precio de venta'], array_keys($otras))];
        for ($i = 1; $i <= 10; $i++) {
            $filas[] = array_merge(['R-' . $i, 'Martillo carpintero ' . $i, 100 * $i], array_values($otras));
        }

        return $filas;
    }

    public function test_la_unica_columna_con_titulo_de_texto_del_articulo_es_el_nombre_y_no_se_pregunta(): void
    {
        $sinonimos = ['DETALLE', 'Detalle.', 'Detalles', 'Descripción', 'Descripciones', 'Artículo', 'Artículos', 'Producto', 'Ítem', 'Item',
            'Denominación', 'Concepto', 'Mercadería', 'Nombre', 'Nombre del producto'];
        foreach ($sinonimos as $titulo) {
            // Como lo deja AiExcelAnalyzer con «DETALLE»: nombre, pero con la confianza bajada a 0,78.
            [$hojas, $preguntas] = $this->preguntas($this->con_texto_en($titulo),
                ['Código' => ['codigo_de_proveedor', 0.97], $titulo => ['nombre', 0.78], 'Precio de venta' => ['precio', 0.97]]);
            $this->assertArrayNotHasKey('columna_0_1', $preguntas, $titulo . ': ' . json_encode(array_keys($preguntas)));
            $this->assertSame('nombre', $hojas[0]['columnas'][1]['campo'], $titulo);
        }

        // Control: con un título que no dice «texto del artículo», la misma confianza sí se pregunta.
        [, $preguntas] = $this->preguntas($this->con_texto_en('Texto'),
            ['Código' => ['codigo_de_proveedor', 0.97], 'Texto' => ['nombre', 0.78], 'Precio de venta' => ['precio', 0.97]]);
        $this->assertArrayHasKey('columna_0_1', $preguntas);
    }

    public function test_con_dos_columnas_candidatas_sigue_como_antes(): void
    {
        // «Artículo» y «Descripción»: no se elige ninguna sola, como hasta hoy (con «Artículo» no hay rescate).
        $filas = [['Código', 'Artículo', 'Descripción', 'Precio de venta']];
        for ($i = 1; $i <= 10; $i++) {
            $filas[] = ['R-' . $i, 'Martillo ' . $i, 'Martillo carpintero mango de madera ' . $i, 100 * $i];
        }
        [, $preguntas] = $this->preguntas($filas, ['Código' => ['codigo_de_proveedor', 0.97], 'Artículo' => ['nombre', 0.78],
            'Descripción' => ['descripcion', 0.78], 'Precio de venta' => ['precio', 0.97]]);
        $this->assertArrayHasKey('columna_0_1', $preguntas);

        // Si la IA ya puso el nombre en otra columna, el título no manda.
        [$hojas, $preguntas] = $this->preguntas($this->con_texto_en('Detalle', ['Nombre corto' => 'Martillo']),
            ['Código' => ['codigo_de_proveedor', 0.97], 'Detalle' => ['descripcion', 0.78], 'Nombre corto' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        $this->assertNotSame('nombre', $hojas[0]['columnas'][1]['campo']);
    }

    /* ---------- E8.3 (14/11/2026): el pie es todo lo que está debajo ---------- */

    public function test_la_nota_debajo_del_pie_que_cuenta_no_entra(): void
    {
        // Con códigos: todo lo que está debajo de la última fila con código y no trae código ni precio es pie.
        [$filas, $salteadas] = $this->leer($this->con_codigos(['R-50', 'Pinza', 10, 1, 1], [
            [null, 'Total artículos: 20', null, null, null], [null, 'Actualizó: Mirta 12/11', null, null, null], ['Firma: Carlos', null, null, null, null],
        ]), $this->mapeo_con_codigos(), [], $this->dos_stocks());
        $this->assertSame(11, $filas, json_encode($salteadas, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['Total artículos: 20'], $salteadas[0]['textos_total']);
        $this->assertSame(['Actualizó: Mirta 12/11', 'Firma: Carlos'], $salteadas[0]['textos_titulo']);

        // Sin códigos: el pie lo abre el total, también el que cuenta, y lo que viene debajo es nota.
        $planilla = [['Producto', 'Precio de venta']];
        foreach (['Martillo carpintero', 'Tenaza', 'Pinza universal', 'Serrucho', 'Destornillador', 'Lima'] as $nombre) {
            $planilla[] = [$nombre, 1000];
        }
        $planilla[] = ['TOTAL', 6000];
        $planilla[] = ['Total artículos: 6', null];
        $planilla[] = ['Actualizó: Mirta 12/11', null];
        [$filas, $salteadas] = $this->leer($planilla, ['Producto' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]], []);
        $this->assertSame(6, $filas, json_encode($salteadas, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['TOTAL', 'Total artículos: 6'], $salteadas[0]['textos_total']);
        $this->assertSame(['Actualizó: Mirta 12/11'], $salteadas[0]['textos_titulo']);
    }

    public function test_planillas_generadas_por_la_carga_entera(): void
    {
        /*
         * Unas pocas planillas del generador (GeneradorDePlanillasDesordenadas) por el camino entero: Excel, cabecera
         * debajo del membrete (fila 1 a 7), mapeo, lectura y planilla preparada. El generativo en memoria
         * (ImportacionDePruebaFilasQueNoSonDatosGenerativoTest) juzga cientos; acá se ve que la carga real hace lo mismo.
         */
        mt_srand(77);
        $cabeceras = [];
        for ($caso = 0; $caso < 6; $caso++) {
            $planilla = GeneradorDePlanillasDesordenadas::articulos($caso);
            $cabeceras[] = $planilla['cabecera'];
            $titulos = array_filter($planilla['filas'][$planilla['cabecera']], function ($titulo) { return $titulo !== null; });
            $campos = ['Código' => 'codigo_de_proveedor', 'Descripción' => 'nombre', 'Producto' => 'nombre', 'Precio de venta' => 'precio',
                'Stock' => 'stock_actual', 'Rubro' => 'categoria', 'Marca' => 'marca'];
            $mapeo = [];
            foreach ($titulos as $titulo) {
                if (isset($campos[$titulo])) {
                    $mapeo[$titulo] = [$campos[$titulo], 0.97];
                }
            }
            [$filas, $salteadas] = $this->leer(array_values($planilla['filas']), $mapeo, ['columna_0_4' => 'ignorar']);
            $this->assertSame(count($planilla['datos']), $filas, 'caso ' . $caso . ' (cabecera en la fila ' . $planilla['cabecera'] . '): '
                . json_encode($salteadas, JSON_UNESCAPED_UNICODE));
        }
        fwrite(STDERR, PHP_EOL . 'Cabeceras en las filas: ' . implode(', ', $cabeceras) . PHP_EOL);
    }

    /* ---------- E8.6 (14/11/2026): ninguna fila desaparece y menos preguntas de más ---------- */

    public function test_la_nota_con_numeros_y_la_fecha_debajo_del_pie_quedan_afuera_y_contadas(): void
    {
        // La medición a ciegas: 15 con código y 2 sin código con precio, rubros con asteriscos, «TOTAL DE ARTÍCULOS: 17»,
        // una nota con un importe, una fila vacía y la fecha sola en la columna del código.
        $filas = [['Código', 'Descripción', 'Precio de venta', 'Local', 'Galpon']];
        $n = 0;
        foreach (['*** Herramientas ***', '*** Pinturería ***', '*** Electricidad ***'] as $r => $rubro) {
            $filas[] = [null, $rubro, null, null, null];
            for ($i = 0; $i < 5; $i++) {
                $n++;
                $filas[] = ['R-' . $n, 'Artículo ' . $n, 100 * $n, $n, 1];
            }
        }
        $filas[] = [null, 'Cable sin código', 1500, 2, 1];
        $filas[] = [null, 'Tarugo sin código', 300, 9, 1];
        $filas[] = [null, 'TOTAL DE ARTÍCULOS: 17', null, null, null];
        $filas[] = [null, 'Stock valorizado al costo: $ 1.234.567', null, null, null];
        $filas[] = [null, null, null, null, null];
        $filas[] = ['14/11/2026', null, null, null, null];
        [$cargadas, $salteadas] = $this->leer($filas, $this->mapeo_con_codigos(), [], $this->dos_stocks());

        $this->assertSame(17, $cargadas, json_encode($salteadas, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['TOTAL DE ARTÍCULOS: 17'], $salteadas[0]['textos_total']);
        $this->assertSame(['*** Herramientas ***', '*** Pinturería ***', '*** Electricidad ***', 'Stock valorizado al costo: $ 1.234.567', '14/11/2026'],
            $salteadas[0]['textos_titulo'], 'La fecha también se cuenta.');
        // Filas leídas (sin la vacía) = las que entran + las salteadas.
        $this->assertSame(count($filas) - 2, $cargadas + $salteadas[0]['titulo'] + $salteadas[0]['total']);
    }

    /** Diez artículos con las columnas dadas: [título => [campo de la IA, valor de la fila i]]. */
    private function con_columnas(array $columnas): array
    {
        $filas = [array_keys($columnas)];
        for ($i = 1; $i <= 10; $i++) {
            $filas[] = array_map(function ($columna) use ($i) { return $columna[1]($i); }, array_values($columnas));
        }

        return $filas;
    }

    public function test_las_abreviaturas_de_precio_y_costo_no_se_preguntan(): void
    {
        $ventas = ['P. Venta', 'P.Vta', 'Pr. Venta', 'Pcio. venta', 'PVP', 'P. Público', 'Precio al público', '$ Venta', 'Vta.'];
        $costos = ['P. Costo', 'Pr. Costo', '$ Costo', 'Cto.'];
        foreach ($ventas as $k => $venta) {
            $costo = $costos[$k % count($costos)];
            $filas = $this->con_columnas([
                'Código' => ['codigo_de_proveedor', function ($i) { return 'R-' . $i; }],
                'Descripción' => ['nombre', function ($i) { return 'Martillo ' . $i; }],
                $costo => ['costo', function ($i) { return 100 * $i; }],
                $venta => ['precio', function ($i) { return 150 * $i; }],
            ]);
            [, $preguntas] = $this->preguntas($filas, ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97],
                $costo => ['costo', 0.97], $venta => ['precio', 0.97]]);
            $this->assertSame([], array_keys($preguntas), $costo . ' / ' . $venta);
        }
    }

    public function test_con_dos_candidatas_a_precio_de_venta_se_sigue_preguntando(): void
    {
        foreach ([['P. Lista', 'P. Contado'], ['P. Venta', 'P. Contado']] as $par) {
            $filas = $this->con_columnas([
                'Código' => ['codigo_de_proveedor', function ($i) { return 'R-' . $i; }],
                'Descripción' => ['nombre', function ($i) { return 'Martillo ' . $i; }],
                $par[0] => ['precio', function ($i) { return 150 * $i; }],
                $par[1] => ['precio', function ($i) { return 140 * $i; }],
            ]);
            [$hojas, $preguntas] = $this->preguntas($filas, ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97],
                $par[0] => ['precio', 0.97], $par[1] => ['precio', 0.97]]);
            $this->assertNotSame([], $preguntas, implode(' / ', $par));
            $this->assertFalse($hojas[0]['columnas'][2]['segura'], implode(' / ', $par));
            $this->assertFalse($hojas[0]['columnas'][3]['segura'], implode(' / ', $par));
        }
    }

    public function test_las_columnas_sin_destino_no_se_preguntan_y_el_resumen_lo_dice(): void
    {
        $titulos = ['Obs.', 'Observaciones', 'Notas', 'Comentarios', 'Ubic.', 'Ubicación', 'Estante', 'Valorizado', 'Importe total', 'Subtotal'];
        foreach ($titulos as $titulo) {
            $filas = $this->con_columnas([
                'Código' => ['codigo_de_proveedor', function ($i) { return 'R-' . $i; }],
                'Descripción' => ['nombre', function ($i) { return 'Martillo ' . $i; }],
                'Precio de venta' => ['precio', function ($i) { return 150 * $i; }],
                $titulo => [null, function ($i) { return $i % 2 ? 'pasillo ' . $i : 1500 * $i; }],
            ]);
            [$hojas, $preguntas] = $this->preguntas($filas, ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97],
                'Precio de venta' => ['precio', 0.97]]);
            $this->assertArrayNotHasKey('columna_0_3', $preguntas, $titulo);
            $this->assertTrue($hojas[0]['columnas'][3]['sin_destino'] ?? false, $titulo);
            $this->assertSame(' No cargué la columna «' . $titulo . '»: el sistema no tiene dónde guardarla. Si era otra cosa, decímelo.',
                PlanillaDePrueba::texto_de_columnas_sin_destino($hojas), $titulo);
        }

        // Dos a la vez.
        $filas = $this->con_columnas([
            'Código' => ['codigo_de_proveedor', function ($i) { return 'R-' . $i; }],
            'Descripción' => ['nombre', function ($i) { return 'Martillo ' . $i; }],
            'Obs.' => [null, function ($i) { return 'ver ' . $i; }],
            'Ubic.' => [null, function ($i) { return 'E' . $i; }],
        ]);
        [$hojas, $preguntas] = $this->preguntas($filas, ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97]]);
        $this->assertSame([], array_keys($preguntas));
        $this->assertSame(' No cargué las columnas «Obs.» y «Ubic.»: el sistema no tiene dónde guardarlas. Si alguna era otra cosa, decímelo.',
            PlanillaDePrueba::texto_de_columnas_sin_destino($hojas));
    }

    public function test_un_contador_no_se_pregunta_y_un_numero_que_no_es_contador_si(): void
    {
        foreach (['Nº', '#', 'Ítem'] as $titulo) {
            $filas = $this->con_columnas([
                $titulo => [null, function ($i) { return $i; }],
                'Código' => ['codigo_de_proveedor', function ($i) { return 'R-' . $i; }],
                'Descripción' => ['nombre', function ($i) { return 'Martillo ' . $i; }],
            ]);
            [$hojas, $preguntas] = $this->preguntas($filas, ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97]]);
            $this->assertArrayNotHasKey('columna_0_0', $preguntas, $titulo);
            $this->assertTrue($hojas[0]['columnas'][0]['sin_destino'] ?? false, $titulo);

            // Con números que no son consecutivos, la columna puede ser un dato: se pregunta.
            $filas = $this->con_columnas([
                $titulo => [null, function ($i) { return 100 + $i * 7; }],
                'Código' => ['codigo_de_proveedor', function ($i) { return 'R-' . $i; }],
                'Descripción' => ['nombre', function ($i) { return 'Martillo ' . $i; }],
            ]);
            [$hojas, $preguntas] = $this->preguntas($filas, ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97]]);
            $this->assertArrayHasKey('columna_0_0', $preguntas, $titulo);
        }
    }

    public function test_una_columna_de_utilidad_sugiere_el_margen_de_ganancia(): void
    {
        foreach (['% UTIL', 'Utilidad', 'Ganancia %', 'Margen', 'Recargo'] as $titulo) {
            $filas = [['Código', 'Descripción', 'Costo', $titulo, 'Precio de venta']];
            for ($i = 1; $i <= 10; $i++) {
                $filas[] = ['R-' . $i, 'Martillo ' . $i, 100 * $i, 40, 140 * $i];
            }
            [, $preguntas] = $this->preguntas($filas, ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97],
                'Costo' => ['costo', 0.97], 'Precio de venta' => ['precio', 0.97]]);
            $this->assertArrayHasKey('columna_0_3', $preguntas, $titulo . ': ' . json_encode(array_keys($preguntas)));
            $this->assertSame(['margen_de_ganancia', 'ignorar'], $preguntas['columna_0_3']['sugeridas'], $titulo);
        }
    }
}

/**
 * El analizador real con una respuesta de IA fija: el mapeo que le dio el test, por título de columna.
 */
class AnalizadorDeNoDatosFalso extends AiExcelAnalyzer
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
