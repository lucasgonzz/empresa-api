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
use App\Models\PriceType;
use App\Models\SaleTax;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use Illuminate\Support\Facades\DB;
use App\Services\Importacion\ImportacionDePruebaService;
use App\Services\Importacion\PlanillaDePrueba;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

/**
 * La ganancia al lanzar la importación de artículos de la prueba (misión prueba-e2e-campana-imperio, ronda 8, D2) y
 * los textos de «después» que dicen la verdad (D3):
 *  - `margen_de_ganancia` en `analyze` con `automatizar_prueba`: `40`, `despues` o `precio:N`. La corrida nace con la
 *    decisión y no pregunta; la respuesta devuelve `margen_previo`.
 *  - `precio:N` solo vale con EXACTAMENTE un artículo con costo y sin precio; si no, se descarta y se pregunta.
 *  - La pregunta de un solo artículo acepta un precio (`acepta_precio`).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionDePruebaMargenPrevioTest extends EmpresaTestCase
{
    const CLAVE = 'admin-prueba-margen-previo-test';
    const BASE = '/api/admin-sync/ai-excel-import/';

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
        foreach (ImportacionDePrueba::where('user_id', $this->dueno->id)->get() as $run) {
            foreach (glob(storage_path('app/imported_files/prueba_' . $run->uuid) . '*') ?: [] as $ruta) {
                @unlink($ruta);
            }
        }
        PruebaHelper::olvidar_cache();
        ArticlePricesHelper::$sale_taxes_cache = [];
        parent::tearDown();
    }

    private function cabeceras(): array
    {
        return ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    private function analizador(array $mapeo): void
    {
        $dueno = $this->dueno;
        $this->app->bind(AiExcelAnalyzer::class, function () use ($dueno, $mapeo) {
            $analizador = new AnalizadorDelMargenPrevioFalso((int) $dueno->id);
            $analizador->mapeo = $mapeo;

            return $analizador;
        });
    }

    private function planilla(array $filas): string
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->setTitle('Hoja 0')->fromArray($filas, null, 'A1', true);
        $archivo = tempnam(sys_get_temp_dir(), 'margen_previo_');
        (new Xlsx($libro))->save($archivo);
        $libro->disconnectWorksheets();
        $this->archivos[] = $archivo;

        return $archivo;
    }

    /** Sube por `analyze` como el admin, con o sin `margen_de_ganancia`. */
    private function subir(string $archivo, int $ticket, ?string $margen = null, array $extra = [])
    {
        $datos = [
            'user_id' => $this->dueno->id, 'model' => 'article', 'segundo_plano' => 1, 'automatizar_prueba' => 1,
            'support_ticket_id' => $ticket, 'importacion_clave' => hash('sha256', $archivo . ':' . $ticket . ':' . (string) $margen),
            'excel_file' => new UploadedFile($archivo, 'lista con costos.xlsx', null, null, true),
        ] + ($margen === null ? [] : ['margen_de_ganancia' => $margen]) + $extra;

        return $this->post(self::BASE . 'analyze', $datos, $this->cabeceras());
    }

    private function avanzar(string $uuid): ImportacionDePrueba
    {
        app(ImportacionDePruebaService::class)->avanzar(ImportacionDePrueba::where('uuid', $uuid)->firstOrFail());

        return ImportacionDePrueba::where('uuid', $uuid)->firstOrFail();
    }

    private function responder(ImportacionDePrueba $run, array $respuestas)
    {
        return $this->postJson(self::BASE . 'prueba-resolver/' . $run->uuid, [
            'user_id' => $this->dueno->id, 'support_ticket_id' => $run->support_ticket_id,
            'revision' => $run->revision, 'respuestas' => $respuestas,
        ], $this->cabeceras());
    }

    /** Corre los lotes que despachó la importación (Bus::fake) para que los artículos queden en la base. */
    private function correr_la_importacion(): void
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
    }

    /** Lanza, avanza hasta importar (sin preguntas) y carga los artículos. */
    private function importar_sin_preguntas(string $uuid): ImportacionDePrueba
    {
        Bus::fake();
        $run = $this->avanzar($uuid);
        $this->assertSame('importando', $run->estado, json_encode($run->preguntas));
        $this->assertSame(0, AiAvisoDelAsistente::where('owner_id', $this->dueno->id)
            ->where('support_ticket_id', $run->support_ticket_id)->count(), 'No hubo ninguna pregunta.');
        $this->correr_la_importacion();
        ImportHistory::where('user_id', $this->dueno->id)->whereIn('status', ['en_preparacion', 'en_proceso'])->update(['status' => 'terminado']);

        return $run->fresh();
    }

    private function resumen(ImportacionDePrueba $run, int $creados): string
    {
        $resumen = new \ReflectionMethod(ImportacionDePruebaService::class, 'resumen_en_palabras');
        $resumen->setAccessible(true);

        return $resumen->invoke(app(ImportacionDePruebaService::class), $run, $creados, 0, 0, 0);
    }

    private function mapeo_con_precio(): array
    {
        return ['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97],
            'Costo' => ['costo', 0.97], 'Precio de venta' => ['precio', 0.97]];
    }

    /** Una lista con costo y precio de venta en la que UN artículo no trae el precio. */
    private function lista_con_uno_sin_precio(string $prefijo, int $sin_precio = 1): string
    {
        $filas = [['Código', 'Descripción', 'Costo', 'Precio de venta'],
            [$prefijo . '-1', 'Pinza universal ' . $prefijo, 1000, 1500],
            [$prefijo . '-2', 'Llave francesa ' . $prefijo, 660.42, null],
            [$prefijo . '-3', 'Martillo galponero ' . $prefijo, 500, 800]];
        if ($sin_precio > 1) {
            $filas[3][3] = null;
        }

        return $this->planilla($filas);
    }

    // -----------------------------------------------------------------------------------------------------
    // 11. Un porcentaje al lanzar
    // -----------------------------------------------------------------------------------------------------

    public function test_un_porcentaje_al_lanzar_no_pregunta_y_carga_esa_ganancia(): void
    {
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Costo' => ['costo', 0.97]]);
        $archivo = $this->planilla([['Código', 'Descripción', 'Costo'], ['MPA-1', 'Pinza MPA', 1000], ['MPA-2', 'Tenaza MPA', 500]]);
        $respuesta = $this->subir($archivo, 301, '40')->assertStatus(202)
            ->assertJsonPath('automatizar_prueba', true)->assertJsonPath('margen_previo', '40');
        $uuid = $respuesta->json('analysis_uuid');
        $this->assertSame([PlanillaDePrueba::CLAVE_MARGEN => '40'], ImportacionDePrueba::where('uuid', $uuid)->firstOrFail()->decisiones);

        $run = $this->importar_sin_preguntas($uuid);
        $this->assertSame('40', $run->hojas[0]['margen_respondido']);
        foreach (['MPA-1', 'MPA-2'] as $codigo) {
            $this->assertEqualsWithDelta(40, (float) Article::where('user_id', $this->dueno->id)->where('provider_code', $codigo)
                ->value('percentage_gain'), 0.001, $codigo);
        }
        $this->assertStringStartsWith('Cargué 2 artículos con su costo y un 40 % de ganancia.', $this->resumen($run, 2));

        // El reenvío idempotente devuelve lo guardado.
        $this->subir($archivo, 301, '40')->assertStatus(202)->assertJsonPath('analysis_uuid', $uuid)->assertJsonPath('margen_previo', '40');
        // Un pedido viejo, sin el campo, no lleva la clave nueva.
        $this->assertArrayNotHasKey('margen_previo', $this->subir($archivo, 302)->assertStatus(202)->json());
    }

    // -----------------------------------------------------------------------------------------------------
    // 12. «después»
    // -----------------------------------------------------------------------------------------------------

    public function test_despues_al_lanzar_no_pregunta_y_el_resumen_dice_que_quedo_al_costo_mas_iva(): void
    {
        // La base de testing le tiene IIBB 3,5 % activo al dueño 500; una prueba nace sin impuestos sobre ventas
        // (12/11/2026, ImpuestosSobreVentasDeLaPruebaTest): se apaga para medir lo que ve el dueño de una prueba.
        SaleTax::where('user_id', $this->dueno->id)->update(['activo' => 0]);
        // El cache de impuestos sobrevive entre tests del mismo proceso.
        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->lista_con_uno_sin_precio('MPD'), 303, 'despues')->assertStatus(202)
            ->assertJsonPath('margen_previo', 'despues')->json('analysis_uuid');

        $run = $this->importar_sin_preguntas($uuid);
        $this->assertSame(1, $run->hojas[0]['filas_sin_ganancia']);
        $this->assertSame('Llave francesa MPD', $run->hojas[0]['sin_ganancia_ejemplo']['nombre']);
        $llave = Article::where('user_id', $this->dueno->id)->where('provider_code', 'MPD-2')->firstOrFail();
        $this->assertEqualsWithDelta(660.42, (float) $llave->cost, 0.001);
        $this->assertEqualsWithDelta(799.11, (float) $llave->final_price, 0.001, 'Al costo más IVA 21 %, sin ganancia.');

        // Quinta pasada (B2 y B4): «con su precio de venta» dice cuántos lo traían, y el que no lo traía va con su nombre.
        $this->assertSame('Cargué 3 artículos con su costo; 2 traían también su precio de venta. «Llave francesa MPD» no traía precio de'
            . ' venta: por ahora quedó al costo más IVA ($ 799,11). Cuando me digas a cuánto lo vendés te muestro dónde ponerlo.', $this->resumen($run, 3));
        $this->assertStringNotContainsString('lo actualizo', $this->resumen($run, 3));
    }

    // -----------------------------------------------------------------------------------------------------
    // 13. El precio de UN artículo, de punta a punta
    // -----------------------------------------------------------------------------------------------------

    public function test_precio_de_un_solo_articulo_al_lanzar_queda_como_su_precio_final(): void
    {
        // Como una prueba: la lista «Precio final», propia del test (C1): la base del slot es compartida y otra sesión puede
        // tener marcada otra lista del dueño 500 como de precio final. Todo adentro de la transacción del test. El IIBB de
        // la base de testing queda prendido a propósito: el precio que dijo el dueño es el final igual.
        PriceType::where('user_id', $this->dueno->id)->update(['es_precio_final' => 0]);
        PriceTypeHelper::olvidar_precio_final();
        $lista = PriceTypeHelper::crear_lista_precio_final($this->dueno->id);
        $this->assertSame((int) PriceType::where('user_id', $this->dueno->id)->max('position'), (int) $lista->position,
            'La «Precio final» es la lista por defecto (la de position más alta).');
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->lista_con_uno_sin_precio('MPP'), 304, 'precio:1300')->assertStatus(202)
            ->assertJsonPath('margen_previo', 'precio:1300')->json('analysis_uuid');

        $run = $this->importar_sin_preguntas($uuid);
        $this->assertSame('1300', $run->hojas[0]['precio_para_vacia']);
        $this->assertSame(1, $run->hojas[0]['filas_con_precio_agregado']);
        $this->assertArrayNotHasKey('filas_sin_ganancia', $run->hojas[0]);
        $this->assertSame('precio:1300', $run->decisiones[PlanillaDePrueba::CLAVE_MARGEN]);

        // 🔴 Riesgo del contrato (§5): el precio final que ve el dueño (el de la lista «Precio final» de la prueba, que
        // es el final_price del artículo) tiene que ser exactamente el que dijo.
        $llave = Article::where('user_id', $this->dueno->id)->where('provider_code', 'MPP-2')->firstOrFail();
        $this->assertEqualsWithDelta(660.42, (float) $llave->cost, 0.001);
        $this->assertEqualsWithDelta(1300, (float) $llave->final_price, 0.001);
        // Lo que ve el dueño (Vender y el Listado resuelven el precio con resolver_precio_de_venta): N exacto, por la lista
        // «Precio final», que contesta con el final_price del artículo. Si la lista tiene pivote, también dice N.
        $this->assertTrue(PriceTypeHelper::es_lista_precio_final($lista));
        $visto = ArticlePricesHelper::resolver_precio_de_venta($llave->fresh()->load('price_types'), $this->dueno);
        $this->assertEqualsWithDelta(1300, (float) $visto['final_price'], 0.001, json_encode($visto));
        $pivote = DB::table('article_price_type')->where('article_id', $llave->id)->where('price_type_id', $lista->id)->value('final_price');
        if ($pivote !== null) {
            $this->assertEqualsWithDelta(1300, (float) $pivote, 0.001);
        }
        $this->assertEqualsWithDelta(1500, (float) Article::where('user_id', $this->dueno->id)->where('provider_code', 'MPP-1')
            ->value('final_price'), 0.001, 'El que traía su precio no cambia.');

        // B3 (ronda 8): dice cuál.
        // Quinta pasada (B2 y B4): «con su precio de venta» dice cuántos lo traían, y el que no lo traía va con su nombre.
        $this->assertSame('Cargué 3 artículos con su costo; 2 traían también su precio de venta. Al que no traía precio («Llave francesa MPP») le puse'
            . ' $ 1.300,00 como precio de venta.', $this->resumen($run, 3));
    }

    // -----------------------------------------------------------------------------------------------------
    // 14. `precio:N` cuando no es exactamente uno
    // -----------------------------------------------------------------------------------------------------

    public function test_precio_con_dos_sin_precio_o_con_toda_la_hoja_sin_precio_se_descarta_y_se_pregunta(): void
    {
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->lista_con_uno_sin_precio('MPX', 2), 305, 'precio:1300')->assertStatus(202)
            ->assertJsonPath('margen_previo', 'precio:1300')->json('analysis_uuid');
        $run = $this->avanzar($uuid);
        $this->assertSame('columnas', $run->estado);
        $this->assertSame([PlanillaDePrueba::CLAVE_MARGEN], array_keys($run->preguntas));
        $pregunta = $run->preguntas[PlanillaDePrueba::CLAVE_MARGEN];
        $this->assertArrayNotHasKey('acepta_precio', $pregunta);
        $this->assertStringStartsWith('En tu lista hay 2 artículos que traen el costo', $pregunta['texto']);
        $this->assertArrayNotHasKey(PlanillaDePrueba::CLAVE_MARGEN, $run->decisiones ?? []);
        // Y un precio no vale como respuesta a esa pregunta.
        $this->responder($run, [PlanillaDePrueba::CLAVE_MARGEN => 'precio:1300'])->assertStatus(422);

        // Toda la hoja sin precio: la pregunta es para todos, tampoco acepta un precio.
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Costo' => ['costo', 0.97]]);
        $otra = $this->subir($this->planilla([['Código', 'Descripción', 'Costo'], ['MPY-1', 'Pinza MPY', 1000]]), 306, 'precio:1300')
            ->assertStatus(202)->json('analysis_uuid');
        $run = $this->avanzar($otra);
        $this->assertSame('columnas', $run->estado);
        $pregunta = $run->preguntas[PlanillaDePrueba::CLAVE_MARGEN];
        $this->assertArrayNotHasKey('acepta_precio', $pregunta);
        $this->assertStringStartsWith('Tu lista trae el costo pero no dice a cuánto vendés.', $pregunta['texto']);
        $this->assertStringEndsWith(' Si preferís cargar los precios vos después, decímelo y por ahora quedan sin ganancia (al costo con IVA).',
            $pregunta['texto']);
        $this->assertArrayNotHasKey(PlanillaDePrueba::CLAVE_MARGEN, $run->decisiones ?? []);
    }

    // -----------------------------------------------------------------------------------------------------
    // 15. La pregunta de un solo artículo acepta un precio
    // -----------------------------------------------------------------------------------------------------

    public function test_la_pregunta_de_un_solo_articulo_acepta_un_precio(): void
    {
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->lista_con_uno_sin_precio('MPQ'), 307)->assertStatus(202)->json('analysis_uuid');
        $run = $this->avanzar($uuid);
        $this->assertSame('columnas', $run->estado);
        $pregunta = $run->preguntas[PlanillaDePrueba::CLAVE_MARGEN];
        $this->assertTrue($pregunta['acepta_precio']);
        $this->assertTrue($pregunta['acepta_porcentaje']);
        $this->assertSame('En tu lista hay 1 artículo («Llave francesa MPQ») que trae el costo pero no la ganancia ni el precio de'
            . ' venta. ¿A cuánto lo vendés, o qué ganancia le sumamos? Puede ser 20 %, 30 %, 40 %, 50 % u otro porcentaje, o el'
            . ' precio que me digas. Si preferís cargarlo vos después, decímelo y por ahora queda sin ganancia (al costo con IVA).',
            $pregunta['texto']);
        $this->assertSame(['20', '30', '40', '50', 'despues'], array_column($pregunta['opciones'], 'valor'));
        // La pregunta también sale por prueba-pendientes con la marca.
        $this->getJson(self::BASE . 'prueba-pendientes?user_id=' . $this->dueno->id . '&support_ticket_id=307', $this->cabeceras())
            ->assertOk()->assertJsonPath('pendientes.0.preguntas.0.acepta_precio', true);

        $this->responder($run, [PlanillaDePrueba::CLAVE_MARGEN => '1001'])->assertStatus(422);
        $this->responder($run, [PlanillaDePrueba::CLAVE_MARGEN => 'precio:0'])->assertStatus(422);
        $this->responder($run, [PlanillaDePrueba::CLAVE_MARGEN => 'precio:13,5'])->assertStatus(422);
        $this->responder($run, [PlanillaDePrueba::CLAVE_MARGEN => 'precio:1300'])->assertStatus(202);

        // Y se carga con ese precio.
        $run = $this->importar_sin_preguntas($uuid);
        $this->assertEqualsWithDelta(1300, (float) Article::where('user_id', $this->dueno->id)->where('provider_code', 'MPQ-2')
            ->value('final_price'), 0.001);
    }

    public function test_los_textos_de_despues_y_de_muchos_sin_ganancia_no_prometen_actualizar(): void
    {
        $resumen = new \ReflectionMethod(ImportacionDePruebaService::class, 'resumen_en_palabras');
        $resumen->setAccessible(true);
        $servicio = app(ImportacionDePruebaService::class);
        $corrida = function (array $mapeo, array $extra = [], array $decisiones = []) {
            return new ImportacionDePrueba(['user_id' => $this->dueno->id, 'decisiones' => $decisiones, 'hojas' => [['indice' => 0, 'mapeo' => $mapeo] + $extra]]);
        };
        $this->assertSame(
            // Cuarta pasada, B2: sin «si corresponde»; sin artículos cargados para leer, sin ejemplo.
            // Quinta pasada (B2 y B4): «con su precio de venta» dice cuántos lo traían, y el que no lo traía va con su nombre.
            'Cargué 5 artículos con su costo; 2 traían también su precio de venta. 3 artículos no traían precio de venta: por ahora quedaron sin'
            . ' ganancia, al costo. Cuando me digas a cuánto los vendés te muestro dónde ponerlo.',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'costo' => 2, 'precio' => 3], ['filas_sin_ganancia' => 3]), 5, 0, 0, 0)
        );
        // Uno solo que no se encuentra en la base: sin monto.
        $this->assertSame(
            'Cargué 5 artículos con su costo; 4 traían también su precio de venta. «No existe MPZ» no traía precio de venta: por ahora quedó sin'
            . ' ganancia, al costo. Cuando me digas a cuánto lo vendés te muestro dónde ponerlo.',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'costo' => 2, 'precio' => 3], ['filas_sin_ganancia' => 1,
                'sin_ganancia_ejemplo' => ['nombre' => 'No existe MPZ', 'sku' => null]]), 5, 0, 0, 0)
        );
        // Al costo, sin IVA: «quedó al costo».
        Article::create(['user_id' => $this->dueno->id, 'name' => 'Sin IVA MPZ', 'status' => 'active', 'cost' => 100, 'final_price' => 100]);
        $this->assertStringContainsString(' «Sin IVA MPZ» no traía precio de venta: por ahora quedó al costo ($ 100,00).',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'costo' => 2, 'precio' => 3], ['filas_sin_ganancia' => 1,
                'sin_ganancia_ejemplo' => ['nombre' => 'Sin IVA MPZ', 'sku' => null]]), 5, 0, 0, 0));
        // Todo al costo con «después».
        $this->assertSame(
            'Cargué 2 artículos con su costo y sin ganancia: por ahora se venden al costo (con IVA si corresponde); el precio de'
            . ' venta lo cargás vos después.',
            $resumen->invoke($servicio, $corrida(['nombre' => 1, 'costo' => 2], [], ['margen_de_ganancia' => 'despues']), 2, 0, 0, 0)
        );
    }

    // -----------------------------------------------------------------------------------------------------
    // 16. Validaciones
    // -----------------------------------------------------------------------------------------------------

    public function test_un_margen_invalido_o_en_modo_soporte_da_422_sin_crear_corrida(): void
    {
        $archivo = $this->planilla([['Código', 'Descripción', 'Costo'], ['MPV-1', 'Pinza MPV', 1000]]);
        foreach (['abc', '0', '1001', 'precio:0', 'precio:', '35,5', '40 %', 'precio:1300,50', '12345'] as $ticket => $valor) {
            $this->subir($archivo, 400 + $ticket, $valor)->assertStatus(422)
                ->assertJsonValidationErrors('margen_de_ganancia');
        }
        foreach (['1000', '0.5', '35.5', 'despues', 'DESPUES', 'precio:1300.5'] as $ticket => $valor) {
            $this->subir($archivo, 450 + $ticket, $valor)->assertStatus(202)
                ->assertJsonPath('margen_previo', strtolower($valor));
        }
        $this->assertSame(0, ImportacionDePrueba::where('user_id', $this->dueno->id)->whereBetween('support_ticket_id', [400, 449])->count());

        // Un negocio activo (soporte): el campo no se admite.
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        PruebaHelper::olvidar_cache();
        $this->post(self::BASE . 'analyze', [
            'user_id' => $this->dueno->id, 'model' => 'article', 'automatizar_soporte' => 1,
            'support_ticket_id' => 499, 'importacion_clave' => hash('sha256', 'soporte-margen'), 'margen_de_ganancia' => '40',
            'excel_file' => new UploadedFile($archivo, 'catalogo.xlsx', null, null, true),
        ], $this->cabeceras())->assertStatus(422)->assertJsonValidationErrors('margen_de_ganancia');
        $this->assertSame(0, ImportacionDePrueba::where('user_id', $this->dueno->id)->where('support_ticket_id', 499)->count());
    }

    public function test_decision_previa_de_margen(): void
    {
        $this->assertSame('40', PlanillaDePrueba::decision_previa_de_margen('40'));
        $this->assertSame('35.5', PlanillaDePrueba::decision_previa_de_margen(' 35.5 '));
        $this->assertSame('despues', PlanillaDePrueba::decision_previa_de_margen('despues'));
        $this->assertSame('precio:1300', PlanillaDePrueba::decision_previa_de_margen('precio:1300'));
        $this->assertSame('precio:1300.5', PlanillaDePrueba::decision_previa_de_margen('precio:1300.5'));
        foreach (['abc', '0', '0.00', '1001', 'precio:0', 'precio:0.00', 'después', '', null, [], 'precio:1300,5'] as $valor) {
            $this->assertNull(PlanillaDePrueba::decision_previa_de_margen($valor), json_encode($valor));
        }
        $this->assertSame('1300', PlanillaDePrueba::precio_de_decision('precio:1300.00'));
        $this->assertSame('1300.5', PlanillaDePrueba::precio_de_decision('precio:1300.50'));
        $this->assertNull(PlanillaDePrueba::precio_de_decision('40'));
        // margen_de_respuesta no toma un precio como porcentaje.
        $this->assertNull(PlanillaDePrueba::margen_de_respuesta('precio:40'));
    }
    // -----------------------------------------------------------------------------------------------------
    // Revisión de la ronda 8: B1 a B4
    // -----------------------------------------------------------------------------------------------------

    /** Corre la importación entera (lotes y cierre) y devuelve el aviso del resultado que le llega al dueño. */
    private function importar_y_leer_el_aviso(string $uuid): string
    {
        Bus::fake();
        $run = $this->avanzar($uuid);
        $this->assertSame('importando', $run->estado, json_encode($run->preguntas));
        $this->correr_la_importacion();
        $historial = ImportHistory::findOrFail($run->fresh()->import_history_id);
        (new FinalizeArticleImport($this->dueno->id, $historial->id, $historial->import_status_id))->handle();
        \App\Services\ImageAssignment\CupoImagenesDePrueba::editar((int) $this->dueno->id, 0);
        $this->avanzar($uuid);

        return (string) AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', $run->support_ticket_id)
            ->where('texto', 'like', 'Cargué%')->latest('id')->value('texto');
    }

    public function test_b1_si_hay_articulos_sin_codigo_el_resumen_no_dice_que_todos_se_encuentran_por_codigo(): void
    {
        $this->analizador(['Cod.' => ['numero', 0.97], 'Nombre' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        $uuid = $this->subir($this->planilla([
            ['Cod.', 'Nombre', 'Precio de venta'],
            [880001, 'Sierra MPB', 16500], [880002, 'Tornillo MPB', 450], [null, 'Martillo sin código MPB', 9000],
        ]), 320)->assertStatus(202)->json('analysis_uuid');
        $texto = $this->importar_y_leer_el_aviso($uuid);
        $this->assertStringContainsString(' Los que traían tu código los encontrás por ese código en el Listado y en Vender; 1 quedó'
            . ' sin código («Martillo sin código MPB») y se busca por su nombre.', $texto);
        $this->assertStringNotContainsString('Cada artículo lo encontrás por tu código', $texto);
        // Segunda pasada: si alguno no traía código, no es «tu código de cada artículo».
        $this->assertStringContainsString('También tomé de tu lista: tu código de los que lo traen.', $texto);
        $this->assertStringNotContainsString('de cada artículo', $texto);
        $this->assertNotNull(Article::where('user_id', $this->dueno->id)->where('name', 'Martillo sin código MPB')->first(),
            'El que no traía código se cargó igual.');
        $this->assertSame(1, ImportacionDePrueba::where('uuid', $uuid)->firstOrFail()->hojas[0]['filas_sin_codigo']);
    }

    public function test_b1_los_textos_de_la_busqueda_por_codigo(): void
    {
        $this->assertSame(' Cada artículo lo encontrás por tu código en el Listado y en Vender.',
            ImportacionDePruebaService::texto_de_la_busqueda_por_codigo([['indice' => 0]]));
        $this->assertSame(' Los que traían tu código los encontrás por ese código en el Listado y en Vender; 2 quedaron sin código'
            . ' («A» y «B») y se buscan por su nombre.',
            ImportacionDePruebaService::texto_de_la_busqueda_por_codigo([['filas_sin_codigo' => 2, 'sin_codigo_ejemplos' => ['A', 'B']]]));
        $this->assertSame(' Los que traían tu código los encontrás por ese código en el Listado y en Vender; 7 quedaron sin código'
            . ' (por ejemplo «A», «B», «C») y se buscan por su nombre.', ImportacionDePruebaService::texto_de_la_busqueda_por_codigo([
                ['filas_sin_codigo' => 5, 'sin_codigo_ejemplos' => ['A', 'B', 'C']], ['filas_sin_codigo' => 2, 'sin_codigo_ejemplos' => ['D']],
            ]));
    }

    public function test_b2_las_filas_que_quedaron_afuera_se_nombran_todas_o_con_el_numero_de_las_que_faltan(): void
    {
        $titulos = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
        $this->assertSame(' Dejé afuera 7 filas que no son artículos: “T1”, “T2”, “T3”, “T4”, “T5”, “T6”, “T7”. Si alguna era un'
            . ' producto, decímelo y la cargo.', PlanillaDePrueba::texto_de_filas_salteadas([
                ['filas_salteadas' => ['titulo' => 7, 'textos_titulo' => $titulos]],
            ]));
        // Más de 10: el total a la vista y «y K más» con el número, nunca un «…».
        $doce = ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10'];
        $texto = PlanillaDePrueba::texto_de_filas_salteadas([
            ['filas_salteadas' => ['titulo' => 12, 'total' => 1, 'textos_titulo' => $doce, 'textos_total' => ['TOTAL']]],
        ]);
        $this->assertSame(' Dejé afuera 13 filas que no son artículos: “A1”, “A2”, “A3”, “A4”, “A5”, “A6”, “A7”, “A8”, “A9”, “TOTAL”'
            . ' y 3 más. Si alguna era un producto, decímelo y la cargo.', $texto);
        $this->assertStringNotContainsString('…', $texto);
        // La cabecera repetida se dice, no se esconde.
        $this->assertSame(' Dejé afuera 3 filas que no son artículos: “T1”, “TOTAL” y la cabecera repetida. Si alguna era un producto,'
            . ' decímelo y la cargo.', PlanillaDePrueba::texto_de_filas_salteadas([
                ['filas_salteadas' => ['titulo' => 2, 'cabeceras' => 1, 'total' => 1, 'textos_titulo' => ['T1'], 'textos_total' => ['TOTAL']]],
            ]));
        $this->assertStringEndsWith('y la cabecera repetida 2 veces. Si alguna era un producto, decímelo y la cargo.',
            PlanillaDePrueba::texto_de_filas_salteadas([['filas_salteadas' => ['titulo' => 3, 'cabeceras' => 2, 'textos_titulo' => ['T1']]]]));
    }

    public function test_b2_una_hoja_con_doce_titulos_guarda_diez_y_los_dice(): void
    {
        $filas = [['Código', 'Descripción', 'Precio de venta']];
        for ($i = 1; $i <= 12; $i++) {
            $filas[] = ['--- RUBRO ' . $i . ' ---', null, null];
            $filas[] = ['MPT-' . $i, 'Artículo MPT ' . $i, 100 * $i];
        }
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        $uuid = $this->subir($this->planilla($filas), 321)->assertStatus(202)->json('analysis_uuid');
        Bus::fake();
        $run = $this->avanzar($uuid);
        $this->assertSame('importando', $run->estado);
        $this->assertSame(12, $run->hojas[0]['filas_salteadas']['titulo']);
        $this->assertCount(PlanillaDePrueba::EJEMPLOS_DE_SALTEADAS, $run->hojas[0]['filas_salteadas']['textos_titulo']);
        $this->assertStringEndsWith('“--- RUBRO 10 ---” y 2 más. Si alguna era un producto, decímelo y la cargo.',
            PlanillaDePrueba::texto_de_filas_salteadas($run->hojas));
    }

    public function test_b3_con_un_porcentaje_al_lanzar_dice_cual_y_en_cuanto_quedo(): void
    {
        SaleTax::where('user_id', $this->dueno->id)->update(['activo' => 0]);
        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->lista_con_uno_sin_precio('MPM'), 322, '40')->assertStatus(202)->json('analysis_uuid');
        $run = $this->importar_sin_preguntas($uuid);
        $this->assertSame(1, $run->hojas[0]['filas_con_margen_agregado']);
        $llave = Article::where('user_id', $this->dueno->id)->where('provider_code', 'MPM-2')->firstOrFail();
        // 660,42 + 40 % = 924,59; con IVA 21 % = 1.118,75.
        $this->assertEqualsWithDelta(1118.75, (float) $llave->final_price, 0.001);
        $this->assertStringContainsString(' «Llave francesa MPM» no traía precio de venta: le sumé un 40 % de ganancia y quedó en'
            . ' $ 1.118,75 con IVA.', $this->resumen($run, 3));
        $this->assertStringNotContainsString('Al que no traía ganancia', $this->resumen($run, 3));

        // Dos: cuántos y un ejemplo.
        ImportHistory::where('user_id', $this->dueno->id)->whereIn('status', ['en_preparacion', 'en_proceso'])->update(['status' => 'terminado']);
        $this->analizador($this->mapeo_con_precio());
        $otra = $this->subir($this->lista_con_uno_sin_precio('MPN', 2), 323, '40')->assertStatus(202)->json('analysis_uuid');
        $run = $this->importar_sin_preguntas($otra);
        $this->assertStringContainsString(' 2 artículos no traían precio de venta: les sumé un 40 % de ganancia (por ejemplo, «Llave'
            . ' francesa MPN» quedó en $ 1.118,75 con IVA).', $this->resumen($run, 3));
    }

    public function test_b4_con_ingresos_brutos_prendido_dice_con_impuestos_y_si_ya_tenia_ganancia_lo_dice(): void
    {
        // La base de testing tiene IIBB 3,5 % activo en el dueño 500: 799,11 / 0,965 = 828,09.
        SaleTax::where('user_id', $this->dueno->id)->update(['activo' => 1]);
        $this->assertTrue(SaleTax::where('user_id', $this->dueno->id)->where('activo', 1)->exists(), 'Precondición: IIBB prendido.');
        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->lista_con_uno_sin_precio('MPI'), 324, 'despues')->assertStatus(202)->json('analysis_uuid');
        $run = $this->importar_sin_preguntas($uuid);
        $final = (float) Article::where('user_id', $this->dueno->id)->where('provider_code', 'MPI-2')->value('final_price');
        $this->assertGreaterThan(799.11, $final);
        $this->assertStringContainsString(' «Llave francesa MPI» no traía precio de venta: por ahora quedó al costo con impuestos ($ '
            . number_format($final, 2, ',', '.') . ').', $this->resumen($run, 3));

        // Un artículo que ya existía con su ganancia: no se dice «al costo».
        $resumen = new \ReflectionMethod(ImportacionDePruebaService::class, 'resumen_en_palabras');
        $resumen->setAccessible(true);
        Article::create(['user_id' => $this->dueno->id, 'name' => 'Con margen MPZ', 'status' => 'active', 'cost' => 100,
            'percentage_gain' => 50, 'final_price' => 181.5]);
        $corrida = new ImportacionDePrueba(['user_id' => $this->dueno->id, 'decisiones' => [], 'hojas' => [['indice' => 0,
            'mapeo' => ['nombre' => 1, 'costo' => 2, 'precio' => 3], 'filas_sin_ganancia' => 1,
            'sin_ganancia_ejemplo' => ['nombre' => 'Con margen MPZ', 'sku' => null]]]]);
        $this->assertStringContainsString(' por ahora quedó con la ganancia que ya tenía ($ 181,50).',
            $resumen->invoke(app(ImportacionDePruebaService::class), $corrida, 1, 0, 0, 0));
    }
    public function test_segunda_pasada_las_filas_afuera_van_en_el_orden_de_la_planilla(): void
    {
        $orden = [['titulo', 'RUBRO A'], ['subtotal', 'Subtotal A'], ['titulo', 'RUBRO B'], ['total', 'TOTAL'], ['titulo', 'Nota al pie']];
        $this->assertSame(' Dejé afuera 5 filas que no son artículos: “RUBRO A”, “Subtotal A”, “RUBRO B”, “TOTAL”, “Nota al pie”.'
            . ' Si alguna era un producto, decímelo y la cargo.', PlanillaDePrueba::texto_de_filas_salteadas([['filas_salteadas' => [
                'titulo' => 3, 'subtotal' => 1, 'total' => 1, 'textos_titulo' => ['RUBRO A', 'RUBRO B', 'Nota al pie'],
                'textos_subtotal' => ['Subtotal A'], 'textos_total' => ['TOTAL'], 'orden' => $orden]]]));
        // Con más de 10: las primeras en su orden y el total igual a la vista, en su lugar.
        $orden = [];
        for ($i = 1; $i <= 12; $i++) {
            $orden[] = ['titulo', 'R' . $i];
        }
        $orden[] = ['total', 'TOTAL'];
        $this->assertSame(' Dejé afuera 13 filas que no son artículos: “R1”, “R2”, “R3”, “R4”, “R5”, “R6”, “R7”, “R8”, “R9”, “TOTAL” y 3'
            . ' más. Si alguna era un producto, decímelo y la cargo.', PlanillaDePrueba::texto_de_filas_salteadas([['filas_salteadas' => [
                'titulo' => 12, 'total' => 1, 'textos_titulo' => ['R1'], 'textos_total' => ['TOTAL'], 'orden' => $orden]]]));

        // De punta a punta: un total parcial en el medio y una nota al pie, en una hoja con códigos.
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97]]);
        $uuid = $this->subir($this->planilla([
            ['Código', 'Descripción', 'Precio de venta'], ['--- PLOMERIA ---', null, null], ['MPO-1', 'Caño MPO', 100],
            ['MPO-2', 'Codo MPO', 50], ['--- PINTURERIA ---', null, null], ['MPO-3', 'Látex MPO', 900], ['TOTAL', null, 1050],
            ['Precios con IVA', null, null],
        ]), 325)->assertStatus(202)->json('analysis_uuid');
        Bus::fake();
        $run = $this->avanzar($uuid);
        $this->assertSame('importando', $run->estado);
        $this->assertSame(' Dejé afuera 4 filas que no son artículos: “--- PLOMERIA ---”, “--- PINTURERIA ---”, “TOTAL”, “Precios con IVA”.'
            . ' Si alguna era un producto, decímelo y la cargo.', PlanillaDePrueba::texto_de_filas_salteadas($run->hojas));
    }
    // -----------------------------------------------------------------------------------------------------
    // Cuarta pasada: B1 a B4
    // -----------------------------------------------------------------------------------------------------

    /** Hoja con códigos: 10 artículos con costo y precio, uno sin precio y, al pie, el total largo con la suma del costo. */
    private function lista_con_total_largo(string $prefijo): array
    {
        $filas = [['Código', 'Descripción', 'Costo', 'Precio de venta']];
        $costo = 0.0;
        for ($i = 1; $i <= 10; $i++) {
            $filas[] = [$prefijo . '-' . $i, 'Artículo ' . $prefijo . ' ' . $i, 100 * $i, 150 * $i];
            $costo += 100 * $i;
        }
        $filas[] = [$prefijo . '-11', 'Llave francesa ' . $prefijo, 660.42, null];
        $filas[] = [null, 'TOTAL MERCADERIA (al costo)', $costo + 660.42, null];
        $filas[] = [null, 'Los precios de esta lista pueden cambiar sin previo aviso, consultar siempre antes de confirmar el pedido al cliente', null, null];

        return $filas;
    }

    public function test_b1_el_total_largo_del_pie_no_se_carga_ni_cambia_la_pregunta_de_la_ganancia(): void
    {
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->planilla($this->lista_con_total_largo('MTL')), 330)->assertStatus(202)->json('analysis_uuid');
        $run = $this->avanzar($uuid);
        $this->assertSame('columnas', $run->estado);
        $pregunta = $run->preguntas[PlanillaDePrueba::CLAVE_MARGEN];
        // Un solo artículo sin precio (el total no cuenta): con su nombre y aceptando un precio.
        $this->assertStringStartsWith('En tu lista hay 1 artículo («Llave francesa MTL»)', $pregunta['texto']);
        $this->assertTrue($pregunta['acepta_precio']);
        $this->responder($run, [PlanillaDePrueba::CLAVE_MARGEN => '35'])->assertStatus(202);
        Bus::fake();
        $run = $this->avanzar($uuid);
        $this->assertSame('importando', $run->estado);
        $this->correr_la_importacion();
        $this->assertSame(11, Article::where('user_id', $this->dueno->id)->where('provider_code', 'like', 'MTL-%')->count());
        $this->assertFalse(Article::where('user_id', $this->dueno->id)->where('name', 'like', 'TOTAL MERCADERIA%')->exists());
        $this->assertSame(['TOTAL MERCADERIA (al costo)'], $run->hojas[0]['filas_salteadas']['textos_total']);
        // B3: la nota larga del pie se corta en un espacio y con «…».
        $nota = $run->hojas[0]['filas_salteadas']['textos_titulo'][0];
        $this->assertStringEndsWith('…', $nota);
        $this->assertLessThanOrEqual(PlanillaDePrueba::LARGO_DE_UN_TEXTO_DEL_RESUMEN, mb_strlen($nota));
        $this->assertStringStartsWith('Los precios de esta lista pueden cambiar sin previo aviso, consultar siempre', $nota);
    }

    public function test_b1_las_clases_del_total_largo(): void
    {
        $mapeo = ['codigo_de_proveedor' => 0, 'nombre' => 1, 'costo' => 2, 'precio' => 3];
        $con_codigos = [];
        for ($i = 1; $i <= 12; $i++) {
            $con_codigos[$i + 1] = ['T-' . $i, 'Artículo ' . $i, 100 * $i, 150 * $i];
        }
        $motivos = function (array $pie) use ($con_codigos, $mapeo) {
            $filas = $con_codigos;
            foreach ($pie as $fila) {
                $filas[count($filas) + 2] = $fila;
            }

            return PlanillaDePrueba::motivos_de_la_hoja($filas, $mapeo);
        };
        // (i) Hoja con códigos: sin código y con palabra de total, tenga lo que tenga después, aunque el número no sea la suma.
        $this->assertSame([14 => PlanillaDePrueba::FILA_TOTAL], $motivos([[null, 'TOTAL MERCADERIA (al costo)', 99, null]]));
        $this->assertSame([14 => PlanillaDePrueba::FILA_TOTAL], $motivos([[null, 'Suma de la mercadería en depósito', 7800, null]]));
        // Un artículo con código que se llama «Total…» entra; «Suma dental x 12» con código también.
        $this->assertSame([], $motivos([['T-99', 'Total Gas 10 kg', 5000, 7000], ['T-98', 'Suma dental x 12', 10, 15]]));

        // (ii) Hoja SIN códigos: la suma de lo de arriba lo delata; sin la suma, entra como siempre.
        $sin = ['nombre' => 0, 'costo' => 1, 'precio' => 2, 'stock_actual' => 3];
        $filas = [];
        $valorizado = 0.0;
        $stock = 0;
        for ($i = 1; $i <= 8; $i++) {
            $filas[$i + 1] = ['Artículo sin código ' . $i, 100.5 * $i, 150 * $i, $i];
            $valorizado += 100.5 * $i * $i;
            $stock += $i;
        }
        $con = function (array $pie) use ($filas, $sin) {
            $todas = $filas;
            $todas[10] = $pie;

            return PlanillaDePrueba::motivos_de_la_hoja($todas, $sin);
        };
        $this->assertSame([10 => PlanillaDePrueba::FILA_TOTAL], $con(['Total valorizado de la mercadería', round($valorizado, 2), null, null]));
        // Quinta pasada (B3; cambió a propósito): la suma del stock sola ya no alcanza como evidencia.
        $this->assertSame([], $con(['TOTAL UNIDADES EN EL DEPOSITO', null, null, $stock]));
        $this->assertSame([], $con(['Total valorizado de la mercadería', 12345, null, null]), 'Sin la suma, en una hoja sin códigos, entra.');
        $this->assertSame([], $con(['Total Gas 10 kg', 5000, 7000, 3]));
    }

    public function test_b2_varios_sin_precio_con_despues_dicen_un_ejemplo_real(): void
    {
        SaleTax::where('user_id', $this->dueno->id)->update(['activo' => 0]);
        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->lista_con_uno_sin_precio('MPW', 2), 331, 'despues')->assertStatus(202)->json('analysis_uuid');
        $run = $this->importar_sin_preguntas($uuid);
        $this->assertSame(2, $run->hojas[0]['filas_sin_ganancia']);
        $this->assertStringContainsString(' 2 artículos no traían precio de venta: por ahora quedaron sin ganancia, al costo (por ejemplo,'
            . ' «Llave francesa MPW» quedó en $ 799,11 con IVA). Cuando me digas a cuánto los vendés te muestro dónde ponerlo.', $this->resumen($run, 3));
        $this->assertStringNotContainsString('si corresponde', $this->resumen($run, 3));
    }

    public function test_b3_el_recorte_de_los_textos(): void
    {
        $corto = 'Precios con IVA incluido, válidos hasta el 30/11';
        $this->assertSame($corto, PlanillaDePrueba::recortar_para_el_resumen($corto));
        $justo = str_repeat('a', PlanillaDePrueba::LARGO_DE_UN_TEXTO_DEL_RESUMEN);
        $this->assertSame($justo, PlanillaDePrueba::recortar_para_el_resumen($justo));
        $largo = 'Avisar a los clientes del interior que el flete corre por su cuenta, salvo pedidos de más de cien mil pesos';
        $recortado = PlanillaDePrueba::recortar_para_el_resumen($largo);
        $this->assertStringEndsWith('…', $recortado);
        $this->assertLessThanOrEqual(PlanillaDePrueba::LARGO_DE_UN_TEXTO_DEL_RESUMEN, mb_strlen($recortado));
        $this->assertStringNotContainsString(',…', $recortado);
        $this->assertStringStartsWith(mb_substr($recortado, 0, -1), $largo, 'Se corta en un espacio, sin partir una palabra.');
    }

    public function test_b4_una_columna_que_el_dueno_mando_a_no_cargar_se_dice(): void
    {
        $this->analizador(['Código' => ['codigo_de_proveedor', 0.97], 'Descripción' => ['nombre', 0.97], 'Precio de venta' => ['precio', 0.97],
            'Comentario del vendedor' => ['descripcion', 0.5]]);
        $uuid = $this->subir($this->planilla([
            ['Código', 'Descripción', 'Precio de venta', 'Comentario del vendedor'],
            ['MPC-1', 'Pinza MPC', 1500, 'se vende bien'], ['MPC-2', 'Tenaza MPC', 1200, 'pocas'], ['MPC-3', 'Llave MPC', 900, 'traer más'],
        ]), 332)->assertStatus(202)->json('analysis_uuid');
        $run = $this->avanzar($uuid);
        $this->assertSame('columnas', $run->estado);
        $clave = array_keys($run->preguntas)[0];
        $this->responder($run, [$clave => PlanillaDePrueba::CAMPO_IGNORAR])->assertStatus(202);
        $run = $this->importar_sin_preguntas($uuid);
        $this->assertStringEndsWith(' No cargué la columna «Comentario del vendedor», como me dijiste.', $this->resumen($run, 3));
    }
    // -----------------------------------------------------------------------------------------------------
    // Quinta pasada: importes de comerciante, subtotales cortos, la suma endurecida
    // -----------------------------------------------------------------------------------------------------

    public function test_b2_el_lector_de_importes_de_comerciante(): void
    {
        $casos = [
            '$13500.-' => 13500.0, '$ 1.250.-' => 1250.0, '1250,-' => 1250.0, '$1.250,00.-' => 1250.0, '13500.—' => 13500.0,
            '$ 13.500 .-' => 13500.0, '1.250,50 $' => 1250.5, 'ARS 1250' => 1250.0, '1250 pesos' => 1250.0, '$1250 c/u' => 1250.0,
            '1.250' => 1250.0, '1250.5' => 1250.5, '15600.5' => 15600.5, '1.250,50' => 1250.5, '1,250.50' => 1250.5, '-1200' => -1200.0,
            '$ 37.468,24' => 37468.24,
        ];
        foreach ($casos as $texto => $esperado) {
            $this->assertEqualsWithDelta($esperado, PlanillaDePrueba::importe_de_comerciante((string) $texto), 0.001, (string) $texto);
        }
        // Los números de la celda, tal cual.
        $this->assertSame(1300.0, PlanillaDePrueba::importe_de_comerciante(1300));
        $this->assertSame(1250.75, PlanillaDePrueba::importe_de_comerciante(1250.75));
        foreach (['a convenir', 'consultar', '$', '', '1.5,3', '12 cuotas de 300'] as $texto) {
            $this->assertNull(PlanillaDePrueba::importe_de_comerciante($texto), $texto);
        }
        // En pesos solamente (un saldo), la moneda extranjera no se lee.
        $this->assertNull(PlanillaDePrueba::importe_de_comerciante('USD 1.500', true));
        $this->assertNull(PlanillaDePrueba::importe_de_comerciante('1500 U$S', true));
    }

    public function test_b2_los_precios_con_adornos_se_cargan_y_el_que_no_se_puede_leer_se_dice(): void
    {
        SaleTax::where('user_id', $this->dueno->id)->update(['activo' => 0]);
        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->analizador($this->mapeo_con_precio());
        $uuid = $this->subir($this->planilla([
            ['Código', 'Descripción', 'Costo', 'Precio de venta'],
            ['MPR-1', 'Pinza MPR', 9000, '$13500.-'], ['MPR-2', 'Tenaza MPR', 800, '$ 1.250.-'], ['MPR-3', 'Llave MPR', 900, '1.250,50 $'],
            ['MPR-4', 'Martillo MPR', 1000, 'a convenir'],
        ]), 340)->assertStatus(202)->json('analysis_uuid');
        $run = $this->importar_sin_preguntas($uuid);
        foreach (['MPR-1' => 13500, 'MPR-2' => 1250, 'MPR-3' => 1250.5] as $codigo => $precio) {
            $this->assertEqualsWithDelta($precio, (float) Article::where('user_id', $this->dueno->id)->where('provider_code', $codigo)
                ->value('final_price'), 0.001, $codigo);
        }
        $this->assertSame(1, $run->hojas[0]['importes_ilegibles']['total_precio']);
        $texto = $this->resumen($run, 4);
        $this->assertStringStartsWith('Cargué 4 artículos con su costo; 3 traían también su precio de venta.', $texto);
        $this->assertStringContainsString(' En 1 artículo no pude leer el precio de venta de la planilla («Martillo MPR»: «a convenir»): quedaron'
            . ' al costo más IVA; decime a cuánto los vendés y te muestro dónde ponerlo.', $texto);
        $this->assertStringNotContainsString('no traía precio de venta', $texto, 'Sí lo traía: no se pudo leer.');
    }

    public function test_b1_quinta_pasada_subtotales_de_grupo_con_el_numero_en_cualquier_columna(): void
    {
        $mapeo = ['codigo_de_proveedor' => 0, 'nombre' => 1, 'costo' => 2, 'precio' => 3, 'stock_actual' => 4];
        $con_codigos = [];
        for ($i = 1; $i <= 12; $i++) {
            $con_codigos[$i + 1] = ['S-' . $i, 'Artículo ' . $i, 100 * $i, 150 * $i, $i];
        }
        foreach (['Subtotal', 'Subtotal ferretería', 'Subtotal bulones y tuercas', 'Subtotal grupo de la pinturería', 'Sub total de la sección eléctrica nueva']
            as $rotulo) {
            foreach ([2, 3, 4] as $columna) {
                $filas = $con_codigos;
                $fila = [null, $rotulo, null, null, null];
                $fila[$columna] = 7800;
                $filas[8] = $fila; // en el medio
                ksort($filas);
                $filas[20] = [null, 'TOTAL GENERAL', 9999, null, null];
                $motivos = PlanillaDePrueba::motivos_de_la_hoja($filas, $mapeo);
                $this->assertArrayHasKey(8, $motivos, $rotulo . ' en la columna ' . $columna);
                $this->assertContains($motivos[8], [PlanillaDePrueba::FILA_SUBTOTAL, PlanillaDePrueba::FILA_TOTAL], $rotulo);
                $this->assertSame(PlanillaDePrueba::FILA_TOTAL, $motivos[20]);
            }
        }
    }

    public function test_b3_la_suma_de_arriba_no_saca_un_articulo_que_se_llama_como_un_total(): void
    {
        $sin = ['nombre' => 0, 'costo' => 1, 'precio' => 2, 'stock_actual' => 3];
        // «Total Quartz 10W40» con su stock (la suma de los de arriba) y su precio: entra.
        $filas = [2 => ['Aceite A', 1000, 1500, 5], 3 => ['Aceite B', 1100, 1600, 7], 4 => ['Aceite C', 1200, 1700, 3],
            5 => ['Total Quartz 10W40', 5000, 7000, 15], 6 => ['Totalgaz garrafa', 900, 1300, 2], 7 => ['Suma dental x 12', 10, 15, 4]];
        $this->assertSame([], PlanillaDePrueba::motivos_de_la_hoja($filas, $sin));
        // La suma del stock sola no alcanza; con dos filas tampoco; seguida de artículos (no está en posición de total), tampoco.
        $this->assertSame([], PlanillaDePrueba::motivos_de_la_hoja([2 => ['Aceite A', 1000, null, 5], 3 => ['Aceite B', 1100, null, 7],
            4 => ['Aceite C', 1000, null, 3], 5 => ['Total del stock en el depósito', null, null, 15]], $sin));
        $this->assertSame([], PlanillaDePrueba::motivos_de_la_hoja([2 => ['Aceite A', 1000, null, 5], 3 => ['Aceite B', 1100, null, 7],
            4 => ['Total valorizado de los aceites', 1000 * 5 + 1100 * 7, null, null]], $sin));
        $medio = [2 => ['Aceite A', 1000, null, 5], 3 => ['Aceite B', 1100, null, 7], 4 => ['Aceite C', 1000, null, 3],
            5 => ['Total valorizado de los aceites', 1000 * 5 + 1100 * 7 + 1000 * 3, null, null], 6 => ['Grasa D', 500, null, 2]];
        $this->assertSame([], PlanillaDePrueba::motivos_de_la_hoja($medio, $sin));
        // Al pie, con la suma del costo × cantidad y nada propio: afuera; con su propio precio al lado, entra.
        $pie = $medio;
        unset($pie[6]);
        $this->assertSame([5 => PlanillaDePrueba::FILA_TOTAL], PlanillaDePrueba::motivos_de_la_hoja($pie, $sin));
        $pie[5][2] = 4321;
        $this->assertSame([], PlanillaDePrueba::motivos_de_la_hoja($pie, $sin));
        // Al final de un grupo (seguido de una fila vacía): afuera.
        $grupo = $medio;
        $grupo[6] = [null, null, null, null];
        $grupo[7] = ['Grasa D', 500, null, 2];
        $this->assertSame([5 => PlanillaDePrueba::FILA_TOTAL], PlanillaDePrueba::motivos_de_la_hoja($grupo, $sin));
    }

    public function test_b4_la_columna_ignorada_por_el_mapeo_previo_se_dice_una_sola_vez(): void
    {
        $hojas = [['indice' => 0, 'columnas' => [
            3 => ['titulo' => 'Obs.', 'campo' => null, 'segura' => true, 'sin_destino' => true, 'previa' => true],
            4 => ['titulo' => 'Ubic.', 'campo' => null, 'segura' => true, 'sin_destino' => true],
            5 => ['titulo' => 'Final', 'campo' => null, 'segura' => true, 'previa' => true],
        ]]];
        $this->assertSame(' No cargué la columna «Ubic.»: el sistema no tiene dónde guardarla. Si era otra cosa, decímelo.',
            PlanillaDePrueba::texto_de_columnas_sin_destino($hojas));
        $this->assertSame(' No cargué las columnas «Obs.» y «Final», como me dijiste.', PlanillaDePrueba::texto_de_columnas_que_no_se_cargan($hojas, []));
    }
}

/**
 * El analizador real con una respuesta de IA fija: el mapeo que le dio el test, por título de columna.
 */
class AnalizadorDelMargenPrevioFalso extends AiExcelAnalyzer
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
