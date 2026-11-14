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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

/**
 * El stock «junto» de la carga asistida de la prueba va a la sucursal del dueño (misión
 * prueba-e2e-campana-imperio, ronda 4, E5).
 *
 * En la ronda 4 (14/11) «Ferretería La Esquina» tenía cuatro sucursales de ejemplo y su planilla una sola
 * columna «Cant»: los 28 artículos quedaron con `articles.stock` y sin ninguna fila en `address_article` (el
 * «estado mixto» que `MigrarASucursalPrincipal` existe para eliminar), y el Listado mostraba las columnas de
 * las sucursales vacías. Ahora, en la prueba, esa cantidad se carga en la sucursal del dueño con el mismo
 * mecanismo de las columnas de sucursal, y el resumen la nombra. Con columnas de sucursal, sin sucursales o
 * fuera de la prueba (modo soporte), nada cambia.
 *
 * El negocio del fixture tiene dos sucursales: «Principal» (id 1) y «Sucursal Norte» (marcada principal).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class StockJuntoALaSucursalEnLaPruebaTest extends EmpresaTestCase
{
    const CLAVE = 'admin-prueba-stock-junto-test';

    private $dueno;
    private $archivos = [];

    /** @var \App\Models\Address La sucursal con la que vende el dueño. */
    private $suya;

    /** @var \App\Models\Address La otra sucursal del negocio. */
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
        // Como la deja `PrimeraVentaDeLaPruebaHelper`: el dueño vende con la principal.
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

    /**
     * @param  array $mapeo  título de columna => [campo, confianza]
     * @return void
     */
    private function analizador(array $mapeo): void
    {
        $dueno = $this->dueno;
        $this->app->bind(AiExcelAnalyzer::class, function () use ($dueno, $mapeo) {
            $analizador = new AnalizadorDelStockJuntoFalso((int) $dueno->id);
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
            'uuid' => $uuid, 'user_id' => $this->dueno->id, 'support_ticket_id' => 122,
            'clave' => hash('sha256', $uuid), 'archivo' => $relativa, 'nombre' => 'stock deposito con precios.xlsx',
            'origen' => 'martin', 'estado' => 'analizar',
        ]);
    }

    private function avanzar(ImportacionDePrueba $run): ImportacionDePrueba
    {
        app(ImportacionDePruebaService::class)->avanzar($run->fresh());

        return $run->fresh();
    }

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

    /** Fila del artículo en una sucursal (`address_article.amount`), o null si no tiene. */
    private function en_sucursal(Article $articulo, Address $sucursal): ?float
    {
        $valor = DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $sucursal->id)->value('amount');

        return is_null($valor) ? null : (float) $valor;
    }

    private function articulo_llamado(string $nombre): Article
    {
        return Article::where('user_id', $this->dueno->id)->where('name', $nombre)->firstOrFail();
    }

    private function resumen(): string
    {
        return AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 122)
            ->where('texto', 'like', 'Cargué%')->firstOrFail()->texto;
    }

    private static function propiedad(Address $sucursal): string
    {
        return str_replace(' ', '_', strtolower($sucursal->street));
    }

    public function test_con_sucursales_la_cantidad_junta_va_a_la_sucursal_del_dueno(): void
    {
        $this->analizador(['Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97], 'Cant' => ['stock_actual', 0.97]]);
        Bus::fake();
        $run = $this->avanzar($this->corrida([
            ['Nombre', 'Precio de venta', 'Cant'],
            ['Sierra de arco E5', 75121.54, 3],
            ['Llave francesa E5', 9800, 12],
        ]));

        $this->assertSame('importando', $run->estado, json_encode($run->preguntas, JSON_UNESCAPED_UNICODE));
        $mapeo = $run->hojas[0]['mapeo'];
        $this->assertArrayNotHasKey('stock_actual', $mapeo, 'La cantidad junta no queda como stock global.');
        $this->assertArrayHasKey(self::propiedad($this->suya), $mapeo);

        $this->importar_y_cerrar($run);

        $sierra = $this->articulo_llamado('Sierra de arco E5');
        $llave = $this->articulo_llamado('Llave francesa E5');
        $this->assertSame(3.0, $this->en_sucursal($sierra, $this->suya));
        $this->assertSame(12.0, $this->en_sucursal($llave, $this->suya));
        // El total del artículo cierra con la suma de sus filas, y ninguna otra sucursal se tocó.
        $this->assertEqualsWithDelta(3, (float) $sierra->stock, 0.0001);
        $this->assertEqualsWithDelta(12, (float) $llave->stock, 0.0001);
        $this->assertNull($this->en_sucursal($sierra, $this->otra));
        $this->assertSame(1, DB::table('address_article')->where('article_id', $sierra->id)->count());

        $texto = $this->resumen();
        $this->assertStringContainsString('stock en ' . $this->suya->street, $texto);
        $this->assertStringNotContainsString(strtolower($this->suya->street) . '.', $texto, 'No el nombre de columna del importador.');
    }

    public function test_una_cantidad_dudosa_contestada_como_stock_tambien_va_a_la_sucursal_del_dueno(): void
    {
        $this->analizador(['Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97], 'Cant' => ['stock_actual', 0.5]]);
        $run = $this->corrida([
            ['Nombre', 'Precio de venta', 'Cant'],
            ['Sierra de arco E5', 75121.54, 3],
        ]);

        $planilla = new PlanillaDePrueba($run);
        $hojas = $planilla->analizar(app(AiExcelAnalyzer::class));
        $this->assertArrayHasKey('columna_0_2', $planilla->preguntas_columnas($hojas), 'La cantidad dudosa se pregunta.');

        $resueltas = $planilla->resolver_columnas($hojas, ['columna_0_2' => 'stock_actual']);
        $this->assertArrayNotHasKey('stock_actual', $resueltas[0]['mapeo']);
        $this->assertSame(2, $resueltas[0]['mapeo'][self::propiedad($this->suya)]);
        // Renombrada en su lugar: el orden de las columnas de la planilla preparada no cambia.
        $this->assertSame(['nombre', 'precio', self::propiedad($this->suya)], array_keys($resueltas[0]['mapeo']));
    }

    public function test_con_columnas_de_dos_sucursales_queda_como_hoy(): void
    {
        $this->analizador([
            'Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97], 'Cant' => ['stock_actual', 0.97],
        ]);
        Bus::fake();
        $run = $this->avanzar($this->corrida([
            ['Nombre', 'Precio de venta', 'Cant', 'Dep 1', 'Dep 2'],
            ['Sierra de arco E5', 75121.54, 5, 3, 2],
        ], ['columnas_previas' => [
            ['hoja' => null, 'columna' => 'D', 'campo' => 'address_' . $this->suya->id . '_amount'],
            ['hoja' => null, 'columna' => 'E', 'campo' => 'address_' . $this->otra->id . '_amount'],
        ]]));

        $this->assertSame('importando', $run->estado, json_encode($run->preguntas, JSON_UNESCAPED_UNICODE));
        $mapeo = $run->hojas[0]['mapeo'];
        // Igual que hoy: «Cant» sigue en stock_actual (el motor la ignora porque vinieron columnas de sucursal).
        $this->assertSame(2, $mapeo['stock_actual']);
        $this->assertSame(3, $mapeo[self::propiedad($this->suya)]);
        $this->assertSame(4, $mapeo[self::propiedad($this->otra)]);

        $this->importar_y_cerrar($run);

        $sierra = $this->articulo_llamado('Sierra de arco E5');
        $this->assertSame(3.0, $this->en_sucursal($sierra, $this->suya));
        $this->assertSame(2.0, $this->en_sucursal($sierra, $this->otra));
        $this->assertEqualsWithDelta(5, (float) $sierra->stock, 0.0001);
    }

    public function test_sin_sucursales_queda_como_hoy(): void
    {
        // El negocio sin sucursales: se las corre a otro dueño dentro de la transacción del test.
        Address::sucursales_de($this->dueno->id)->update(['user_id' => 987654]);
        User::whereKey($this->dueno->id)->update(['address_id' => null]);

        $this->analizador(['Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97], 'Cant' => ['stock_actual', 0.97]]);
        Bus::fake();
        $run = $this->avanzar($this->corrida([
            ['Nombre', 'Precio de venta', 'Cant'],
            ['Sierra de arco E5', 75121.54, 3],
        ]));

        $this->assertSame('importando', $run->estado, json_encode($run->preguntas, JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, $run->hojas[0]['mapeo']['stock_actual']);

        $this->importar_y_cerrar($run);

        $sierra = $this->articulo_llamado('Sierra de arco E5');
        $this->assertEqualsWithDelta(3, (float) $sierra->stock, 0.0001);
        $this->assertSame(0, DB::table('address_article')->where('article_id', $sierra->id)->count());
        $this->assertStringContainsString('También tomé de tu lista: stock.', $this->resumen());
    }

    public function test_en_modo_soporte_la_cantidad_junta_queda_como_hoy(): void
    {
        $this->analizador(['Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97], 'Cant' => ['stock_actual', 0.5]]);
        $run = $this->corrida([
            ['Nombre', 'Precio de venta', 'Cant'],
            ['Sierra de arco E5', 75121.54, 3],
        ], ['modo_soporte' => true]);

        $planilla = new PlanillaDePrueba($run);
        $hojas = $planilla->analizar(app(AiExcelAnalyzer::class));

        // La pregunta no nombra ninguna sucursal.
        $preguntas = json_encode($planilla->preguntas_columnas($hojas), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('stock en ', $preguntas);
        $this->assertStringContainsString('Puede ser el stock que tenés o', $preguntas);

        $resueltas = $planilla->resolver_columnas($hojas, ['columna_0_2' => 'stock_actual']);
        $this->assertSame(2, $resueltas[0]['mapeo']['stock_actual']);
        $this->assertArrayNotHasKey(self::propiedad($this->suya), $resueltas[0]['mapeo']);
    }
}

/**
 * El analizador de producción con la respuesta de la IA fija (la llamada a la IA es lo único que se reemplaza).
 */
class AnalizadorDelStockJuntoFalso extends AiExcelAnalyzer
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
