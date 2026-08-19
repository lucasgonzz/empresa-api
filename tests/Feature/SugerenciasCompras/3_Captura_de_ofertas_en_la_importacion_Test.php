<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Http\Controllers\Helpers\import\article\ProcessRow;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\ImportHistory;
use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderPriceOffer;
use App\Models\User;
use App\Services\Compras\OfertasDeProveedorService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use ReflectionMethod;
use Tests\Import\ImportTestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 3: los dos puntos de
 * captura en la importación (ProcessRow) y el tercero en la compra real
 * (NewProviderOrderHelper::catalogar_costo_proveedor()).
 *
 * R3 es el riesgo central de este archivo: la captura tiene que ser
 * puramente aditiva. Cada test de punto A/B empieza sacando una FOTO del
 * artículo antes de importar y la compara contra la foto de después -- si
 * un solo campo cambió, algo de esta misión dejó de ser un observador y
 * empezó a decidir, que es exactamente lo que el plan prohíbe (D3).
 *
 * R4 (performance) se prueba contando cuántas veces se ejecuta la ÚNICA
 * query de lectura que registrar_lote() hace por invocación (ver su
 * docblock): si ese número no da 1 para un chunk de 50 filas, algo está
 * llamando al service más de una vez por chunk.
 *
 * Extiende Tests\Import\ImportTestCase (no Tests\TestCase) para reusar el
 * escenario ya sembrado (proveedores A/B/C, artículos A1..A15, cada uno
 * documentando qué rama de ArticleIndexCache::find_with_index() habilita) y
 * el endpoint real de importación -- exactamente la misma infraestructura
 * que ya prueba tests/Import al completo. No se agrega ningún fixture .xlsx
 * nuevo bajo tests/Import/fixtures/ (la regla de corte del plan solo exceptúa
 * archivos nuevos bajo tests/Feature/SugerenciasCompras/): los excels de
 * este archivo se generan en memoria con el mismo writer que usa
 * tests/Import/fixtures/generar.php.
 */
class Captura_de_ofertas_en_la_importacion_Test extends ImportTestCase
{
    protected function setUp(): void
    {
        // 🔴 Gotcha de entorno DOCUMENTADO en tests/Import/README.md: "un slot
        // recién provisionado puede no tener... el tenant id=900... no hay
        // seeder para ese tenant todavía, se asume restaurado desde un dump".
        // Este slot (empresa_testing_s1) no lo tiene: verificado a mano que
        // `SELECT id FROM users WHERE id=900` no devuelve filas. Sin este
        // parche, ImportTestCase::setUp() aborta los 7 tests de este archivo
        // (y de hecho, los 90 de tests/Import) con "No existe el usuario de
        // prueba id=900". Se crea acá, defensivo (solo si falta) y de forma
        // PERMANENTE (a propósito: es exactamente lo que el dump que falta
        // dejaría puesto, y el resto de tests/Import lo necesita igual que
        // este archivo) -- no toca ningún archivo fuera de este.
        //
        // Por qué ANTES de parent::setUp() y no después: ImportTestCase::
        // setUp() hace el `User::find(900)` en su primera línea útil, así que
        // tiene que existir antes de que esa cadena arranque. refreshApplication()
        // (heredado de Illuminate\Foundation\Testing\TestCase, protected) es lo
        // que bootea el contenedor de Laravel -- lo mínimo para poder usar
        // Eloquent acá --, sin todavía tocar setUpTraits() (que es quien recién
        // arranca la transacción de DatabaseTransactions). Este insert queda
        // ANTES de que la transacción del test empiece, así que es un commit
        // real y no se revierte en el tearDown -- correcto acá: es fixture de
        // entorno, no dato de un test puntual.
        if (!$this->app) {
            $this->refreshApplication();
        }
        $this->asegurar_tenant_900();

        parent::setUp();

        Notification::fake();
        // Si no, el pipeline sale a la API real de Anthropic. La captura de
        // ofertas de este archivo no usa IA, pero es la regla obligatoria de
        // setUp de toda la misión (§11 del plan), la misma en los 7 archivos.
        config(['services.anthropic.api_key' => null]);
    }

    /**
     * Crea el tenant id=900 que ImportTestCase::TENANT_USER_ID exige, si este
     * slot todavía no lo tiene (ver el comentario largo en setUp()). Sin
     * artículos propios (ImportTestCase lo exige así en su docblock): un
     * usuario recién creado no tiene ninguno.
     *
     * @return void
     */
    protected function asegurar_tenant_900()
    {
        if (User::find(ImportTestCase::TENANT_USER_ID)) {
            return;
        }

        User::create([
            'id'       => ImportTestCase::TENANT_USER_ID,
            'name'     => 'Tenant de pruebas de importación (id 900)',
            'email'    => 'tenant-900-import-tests@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Helper obligatorio de la misión (§11 del plan, los 7 archivos). Este
     * archivo no lo necesita para ningún caso propio -- la captura de ofertas
     * en la importación no está gateada por ninguna extensión, corre siempre
     * -- pero se deja definido por consistencia con los otros 6.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', 'sugerencias_compras')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'sugerencias_compras',
                'name' => 'Sugerencias de compra a proveedores',
            ]);
        }

        $this->tenant->extencions()->attach($extencion->id);
        $this->tenant->load('extencions');
    }

    /**
     * Genera un xlsx AL VUELO (mismo writer -OpenSpout- que
     * tests/Import/fixtures/generar.php) y lo importa contra el endpoint
     * real, reusando exactamente las columnas y los defaults de config de
     * ImportTestCase. No usa ImportTestCase::importar() porque ese helper
     * exige un archivo YA EXISTENTE bajo fixtures/, y este archivo no puede
     * agregar ninguno ahí (regla de corte del plan).
     *
     * @param array $filas
     * @param array $config
     * @return \App\Models\ImportHistory
     */
    protected function importar_filas(array $filas, array $config = [])
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('sugerencias_compras_p3_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock_actual', 'iva',
        ]));
        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($fila));
        }
        $writer->close();

        $data = array_merge(
            [
                'models' => new UploadedFile(
                    $ruta,
                    'import.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
                'start_row'   => 2,
                'finish_row'  => 99999,
                'provider_id' => null,
            ],
            self::config_por_defecto(),
            self::columnas(),
            $config
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        return ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();
    }

    /**
     * Foto de los campos "de negocio" de un artículo, para comparar antes y
     * después de una importación y probar que la captura no le cambió ni un
     * campo (D3: ningún cambio de comportamiento del importador).
     *
     * @param \App\Models\Article $articulo
     * @return array
     */
    protected function snapshot($articulo)
    {
        $articulo->refresh();

        return $articulo->only([
            'name', 'bar_code', 'sku', 'provider_code', 'provider_id', 'cost', 'price', 'status',
        ]);
    }

    /**
     * Instancia mínima de ProcessRow (sin pasar por el endpoint), para probar
     * en aislamiento las guardas de registrar_oferta_de_otro_proveedor(). El
     * constructor de ProcessRow solo arma caches de catálogo scopeadas por
     * usuario (price types, categorías, marcas, iva...) -- el mismo que ya
     * corre sin problemas contra $this->tenant en cada importar_filas() de
     * este archivo.
     *
     * @param array $overrides
     * @return \App\Http\Controllers\Helpers\import\article\ProcessRow
     */
    protected function nueva_instancia_process_row(array $overrides = [])
    {
        $data = array_merge([
            'columns'                                            => self::columnas(),
            'user'                                                => $this->tenant,
            'ct'                                                  => null,
            'provider_id'                                         => $this->providers['A']->id,
            'create_and_edit'                                     => true,
            'actualizar_articulos_de_otro_proveedor'              => false,
            'actualizar_proveedor'                                => true,
            'permitir_provider_code_repetido'                     => false,
            'permitir_provider_code_repetido_en_multi_providers'  => true,
            'actualizar_por_provider_code'                        => true,
        ], $overrides);

        return new ProcessRow($data);
    }

    /**
     * Invoca el método protegido registrar_oferta_de_otro_proveedor() vía
     * Reflection, para probar sus guardas sin pasar por una importación
     * completa.
     *
     * @param \App\Http\Controllers\Helpers\import\article\ProcessRow $instancia
     * @param array $article_ids
     * @param int|null $provider_id
     * @param array $data
     * @return void
     */
    protected function invocar_registrar_oferta(ProcessRow $instancia, $article_ids, $provider_id, array $data)
    {
        $metodo = new ReflectionMethod(ProcessRow::class, 'registrar_oferta_de_otro_proveedor');
        $metodo->setAccessible(true);
        $metodo->invoke($instancia, $article_ids, $provider_id, $data);
    }

    /**
     * Punto A (ProcessRow.php, la fila con provider_code bloqueado por otro
     * proveedor): la fila se sigue descartando IDÉNTICO a como se descartaba
     * antes de esta misión (el artículo no cambia ni un campo), y lo único
     * nuevo es que queda registrada la oferta del proveedor que la ofrecía.
     *
     * A15 (fixture de ImportTestSeeder): provider_code 'PC-1500', existe SOLO
     * en el proveedor C -- "el caso limpio de bloqueo", documentado tal cual
     * en ImportTestSeeder. Se importa como proveedor A, con
     * permitir_provider_code_repetido_en_multi_providers=false (hay que pisar
     * el default de ImportTestCase, que lo trae en true: con true el bloqueo
     * no dispara).
     *
     * @group sugerencias-compras
     * @test
     */
    public function punto_a_la_fila_bloqueada_por_otro_proveedor_no_cambia_el_articulo_y_deja_la_oferta()
    {
        $articulo_bloqueado = $this->seed['A15'];
        $antes = $this->snapshot($articulo_bloqueado);

        $import = $this->importar_filas([
            [null, 'SKU-PUNTO-A-NUEVO', 'PC-1500', 'Nombre que no tiene que pisar nada', 555.0, 999.0, 12.0, '21'],
        ], [
            'provider_id' => $this->providers['A']->id,
            'permitir_provider_code_repetido_en_multi_providers' => false,
        ]);

        $conteo = $this->conteo($import);
        $this->assertEquals(
            1,
            isset($conteo['bloqueado_otro_proveedor']) ? (int) $conteo['bloqueado_otro_proveedor'] : 0,
            'La fila tiene que caer en el bucket de bloqueo (el mismo de siempre, sin esta misión).'
        );

        // La aserción central: NI UN campo del artículo cambió.
        $this->assertEquals(
            $antes,
            $this->snapshot($articulo_bloqueado),
            'Punto A: el artículo bloqueado por otro proveedor no puede cambiar ni un campo.'
        );

        // Lo único nuevo: la oferta del proveedor QUE INTENTÓ importar (A),
        // no la del dueño real (C) -- es A quien "ofrecía" el artículo a este
        // precio, C es simplemente el titular ya existente.
        $oferta = ProviderPriceOffer::where('article_id', $articulo_bloqueado->id)
            ->where('provider_id', $this->providers['A']->id)
            ->where('origen', 'importacion')
            ->first();

        $this->assertNotNull($oferta, 'El punto A tiene que dejar la oferta del proveedor que ofrecía el artículo.');
        $this->assertEquals(555.0, (float) $oferta->cost);
        $this->assertEquals($import->id, $oferta->referencia_id);
        // ProviderPriceOffer no declara $casts para 'fecha': llega como
        // string plano (columna DATE), no como Carbon.
        $this->assertEquals(now()->toDateString(), $oferta->fecha);
        $this->assertEquals($this->tenant->id, (int) $oferta->user_id);

        // Y el proveedor C (el titular real, NO el que trajo esta fila) sigue
        // sin ninguna oferta nueva: la fila nunca lo mencionó.
        $this->assertEquals(
            0,
            ProviderPriceOffer::where('article_id', $articulo_bloqueado->id)
                ->where('provider_id', $this->providers['C']->id)
                ->count()
        );
    }

    /**
     * Punto B (ProcessRow.php, la fila que se saltea por
     * omitir_por_pertencer_a_otro_proveedor()): el artículo no cambia ni un
     * campo, y el pivot article_provider se sigue pisando EXACTAMENTE igual
     * que antes (attach_provider() corre antes de la guarda, sin depender de
     * esta misión) -- lo único nuevo es que la oferta queda con fecha.
     *
     * A2 (fixture): bar_code '7790002', dueño el proveedor B. Importando como
     * A por bar_code (match DIRECTO, no pasa por el escalón provider_code:
     * bar_code no distingue proveedores), A2 "pertenece a otro proveedor" y
     * se saltea con la config por defecto (actualizar_articulos_de_otro_proveedor=false).
     *
     * @group sugerencias-compras
     * @test
     */
    public function punto_b_la_fila_salteada_por_pertenecer_a_otro_proveedor_no_cambia_el_articulo_y_pisa_el_pivot_igual_que_antes()
    {
        $articulo_de_b = $this->seed['A2'];
        $antes = $this->snapshot($articulo_de_b);

        $import = $this->importar_filas([
            ['7790002', null, null, 'Nombre que no tiene que pisar nada B', 777.0, 1500.0, 22.0, '21'],
        ], [
            'provider_id' => $this->providers['A']->id,
        ]);

        // El artículo no cambia: procesar_articulo_ya_creado() nunca corrió
        // para esta fila (se salteó por pertenecer a otro proveedor).
        $this->assertEquals(
            $antes,
            $this->snapshot($articulo_de_b),
            'Punto B: el artículo salteado por pertenecer a otro proveedor no puede cambiar ni un campo.'
        );

        // El pivot (A2, proveedor A) se pisa como siempre: attach_provider()
        // no depende de esta misión y sigue corriendo ANTES de la guarda
        // (línea ~1092 de ProcessRow, fuera del alcance de esta misión).
        $pivot = DB::table('article_provider')
            ->where('article_id', $articulo_de_b->id)
            ->where('provider_id', $this->providers['A']->id)
            ->first();

        $this->assertNotNull($pivot, 'attach_provider() tiene que seguir pisando el pivot exactamente igual que antes de esta misión.');
        $this->assertEquals(777, (int) $pivot->cost);

        // Lo único nuevo: la oferta queda registrada con fecha.
        $oferta = ProviderPriceOffer::where('article_id', $articulo_de_b->id)
            ->where('provider_id', $this->providers['A']->id)
            ->where('origen', 'importacion')
            ->first();

        $this->assertNotNull($oferta);
        $this->assertEquals(777.0, (float) $oferta->cost);
        $this->assertEquals($import->id, $oferta->referencia_id);
    }

    /**
     * R4: 50 filas del mismo chunk (.env.testing fija
     * ARTICLE_EXCEL_CHUNK_SIZE=50: 50 filas de datos son EXACTAMENTE UN
     * chunk) tienen que llamar a OfertasDeProveedorService::registrar_lote()
     * UNA sola vez, no una vez por fila.
     *
     * Contar invocaciones de un método static sin arriesgar un mock de alias
     * (Mockery::mock('alias:...') falla con "cannot redeclare class" si la
     * clase real ya se cargó en este proceso de phpunit -- y con 7 archivos
     * de test corriendo en la misma corrida, no hay forma de garantizar que
     * no se haya cargado ya) se resuelve espiando la ÚNICA query de LECTURA
     * que registrar_lote() hace por invocación (ver su docblock, paso 2: "UNA
     * query de lectura para todo el lote"): si el service se llamara una vez
     * por fila, este número daría 50, no 1.
     *
     * @group sugerencias-compras
     * @test
     */
    public function cincuenta_filas_del_mismo_chunk_llaman_registrar_lote_una_sola_vez()
    {
        $otro_provider = $this->providers['B'];
        $bar_codes = [];

        // 50 artículos frescos, todos del proveedor B: cada fila del import
        // (como proveedor A) los matchea por bar_code y los saltea por
        // pertenecer a otro proveedor (mismo mecanismo del punto B), así los
        // 50 terminan en el buffer de ofertas del mismo chunk.
        for ($i = 1; $i <= 50; $i++) {
            $codigo = '77995' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);

            $articulo = new Article();
            $articulo->user_id     = $this->tenant->id;
            $articulo->name        = 'Bulk otro proveedor ' . $i;
            $articulo->bar_code    = $codigo;
            $articulo->provider_id = $otro_provider->id;
            $articulo->status      = 'active';
            $articulo->save();

            $bar_codes[] = $codigo;
        }

        $filas = [];
        foreach ($bar_codes as $i => $codigo) {
            $filas[] = [$codigo, null, null, 'Bulk import fila ' . $i, 10.0 + $i, 20.0, 1.0, '21'];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->importar_filas($filas, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $selects_al_historico = array_values(array_filter($log, function ($entrada) {
            return stripos(ltrim($entrada['query']), 'select') === 0
                && stripos($entrada['query'], 'provider_price_offers') !== false;
        }));

        $this->assertCount(
            1,
            $selects_al_historico,
            'registrar_lote() hace UNA sola query de lectura por invocación: si esto no da 1, algo está '
            . 'llamando a registrar_lote() más de una vez por chunk (o leyendo fila por fila).'
        );

        $ids_bulk = Article::where('user_id', $this->tenant->id)
            ->where('provider_id', $otro_provider->id)
            ->whereIn('bar_code', $bar_codes)
            ->pluck('id');

        $this->assertEquals(
            50,
            ProviderPriceOffer::where('provider_id', $this->providers['A']->id)
                ->whereIn('article_id', $ids_bulk)
                ->count(),
            'Las 50 ofertas tienen que quedar igual, aunque se hayan escrito en un solo upsert masivo.'
        );
    }

    /**
     * D2: la deduplicación temporal de registrar_lote() -- dos importaciones
     * el mismo día con el mismo costo dejan 1 sola fila; con costo distinto,
     * 1 fila con el costo más nuevo; y en un día DISTINTO con el MISMO costo,
     * sigue siendo 1 sola fila (la regla es "cambió el costo", no "es otro
     * día"). Se llama directo a OfertasDeProveedorService::registrar_lote()
     * (el único punto de escritura, según su propio docblock) para poder
     * mover la fecha con Carbon::setTestNow() sin depender de dos imports en
     * días de reloj distintos de verdad.
     *
     * @group sugerencias-compras
     * @test
     */
    public function registrar_lote_dedupea_por_cambio_de_costo_no_por_dia()
    {
        $articulo = Article::create(['name' => 'Art dedupe temporal P3', 'user_id' => $this->tenant->id]);
        $provider = $this->providers['A'];

        // Mismo día, mismo costo dos veces -> 1 sola fila.
        OfertasDeProveedorService::registrar_lote(
            [$articulo->id => [$provider->id => ['cost' => 1000]]],
            $this->tenant->id,
            'importacion',
            null
        );
        OfertasDeProveedorService::registrar_lote(
            [$articulo->id => [$provider->id => ['cost' => 1000]]],
            $this->tenant->id,
            'importacion',
            null
        );

        $this->assertEquals(
            1,
            ProviderPriceOffer::where('article_id', $articulo->id)->where('provider_id', $provider->id)->count(),
            'Mismo día, mismo costo: no puede haber quedado más de 1 fila.'
        );

        // Mismo día, costo DISTINTO -> sigue siendo 1 fila, pero con el costo nuevo.
        OfertasDeProveedorService::registrar_lote(
            [$articulo->id => [$provider->id => ['cost' => 1200]]],
            $this->tenant->id,
            'importacion',
            null
        );

        $filas = ProviderPriceOffer::where('article_id', $articulo->id)->where('provider_id', $provider->id)->get();
        $this->assertCount(1, $filas, 'Mismo día, costo cambiado: el upsert tiene que pisar la fila existente, no sumar otra.');
        $this->assertEquals(1200.0, (float) $filas[0]->cost);

        // Día DISTINTO, MISMO costo (1200) -> tiene que seguir siendo 1 fila:
        // la regla de dedupe es "cambió el costo", no "es otro día".
        Carbon::setTestNow(now()->addDay());

        try {
            OfertasDeProveedorService::registrar_lote(
                [$articulo->id => [$provider->id => ['cost' => 1200]]],
                $this->tenant->id,
                'importacion',
                null
            );

            $this->assertEquals(
                1,
                ProviderPriceOffer::where('article_id', $articulo->id)->where('provider_id', $provider->id)->count(),
                'Mismo costo en otro día no puede generar una fila nueva: la dedupe es por CAMBIO de costo, no por día.'
            );

            // Día distinto, costo que SÍ cambia -> ahora sí, una fila nueva (2 en total).
            OfertasDeProveedorService::registrar_lote(
                [$articulo->id => [$provider->id => ['cost' => 1300]]],
                $this->tenant->id,
                'importacion',
                null
            );

            $this->assertEquals(
                2,
                ProviderPriceOffer::where('article_id', $articulo->id)->where('provider_id', $provider->id)->count(),
                'Un costo que sí cambió en otro día tiene que dejar una fila nueva (la fecha entra en el unique).'
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Las cuatro guardas de registrar_oferta_de_otro_proveedor() (docblock
     * del método, ProcessRow.php): sin provider_id no bufferea; cost null o
     * <= 0 no bufferea; un fake_id (artículo todavía sin INSERT en este
     * chunk) nunca entra, aunque venga mezclado con ids reales; e ids
     * basura (0, negativos, no numéricos) no rompen nada ni bufferean.
     *
     * @group sugerencias-compras
     * @test
     */
    public function las_guardas_de_registrar_oferta_de_otro_proveedor_descartan_sin_romper_nada()
    {
        $instancia = $this->nueva_instancia_process_row();
        $provider_id = $this->providers['A']->id;

        // Guarda 1: sin provider_id no bufferea.
        $this->invocar_registrar_oferta($instancia, [999999], null, ['cost' => 100]);
        $this->assertEmpty($instancia->get_ofertas_de_precio_buffer(), 'Guarda 1: sin provider_id no puede bufferear nada.');

        // Guarda 2: cost null, cero o negativo no bufferea (misma condición
        // que update_provider_relation(), ProcessRow.php:1560).
        $this->invocar_registrar_oferta($instancia, [999999], $provider_id, ['cost' => null]);
        $this->invocar_registrar_oferta($instancia, [999999], $provider_id, ['cost' => 0]);
        $this->invocar_registrar_oferta($instancia, [999999], $provider_id, ['cost' => -5]);
        $this->assertEmpty($instancia->get_ofertas_de_precio_buffer(), 'Guarda 2: cost null/0/negativo no puede bufferear nada.');

        // Guarda 3: un fake_id nunca entra, ni siquiera mezclado con un id real.
        $this->invocar_registrar_oferta(
            $instancia,
            ['fake_abc123', 777],
            $provider_id,
            ['cost' => 50, 'provider_code' => 'PC-GUARDA']
        );

        $buffer = $instancia->get_ofertas_de_precio_buffer();
        $this->assertArrayNotHasKey('fake_abc123', $buffer, 'Guarda 3: un fake_id (sin INSERT todavía) no puede terminar en el buffer.');
        $this->assertArrayHasKey(777, $buffer, 'El id real de la misma llamada sí tiene que bufferearse.');
        $this->assertEquals(50.0, (float) $buffer[777][$provider_id]['cost']);
        $this->assertEquals('importacion', $buffer[777][$provider_id]['origen']);
        $this->assertEquals('PC-GUARDA', $buffer[777][$provider_id]['provider_code']);

        // Ids basura (0, negativo, no numérico, null): ninguno bufferea, y el
        // método no lanza ninguna excepción (guarda 4: nunca lanza).
        $this->invocar_registrar_oferta($instancia, [0, -1, 'abc', null], $provider_id, ['cost' => 50]);
        $buffer_final = $instancia->get_ofertas_de_precio_buffer();
        $this->assertArrayNotHasKey(0, $buffer_final);
        $this->assertArrayNotHasKey(-1, $buffer_final);
        // El único article_id legítimo que bufereó en todo el test sigue siendo 777.
        $this->assertEquals([777], array_keys($buffer_final));
    }

    /**
     * Guarda 4 del docblock ("nunca lanza"): una excepción real de
     * OfertasDeProveedorService::registrar_lote() no puede voltear la
     * importación completa -- ArticleImport::guardar_articulos() la envuelve
     * en su propio try/catch, separado del de ActualizarBBDD.
     *
     * No se puede forzar la excepción tocando el esquema real
     * (article_provider / provider_price_offers son compartidas con el otro
     * agente de tests que corre en paralelo sobre esta misma base, archivos
     * 4-7 de esta misma misión). DB::connection()->beforeExecuting()
     * intercepta la query ANTES de que llegue a MySQL, sin tocar una sola
     * tabla ni fila ajena: es la forma segura de simular "el INSERT a
     * provider_price_offers reventó" sin arriesgar el resto de la corrida
     * compartida. El callback se neutraliza con un flag por referencia en un
     * finally, para no dejarlo interceptando queries de los tests que corran
     * después en este mismo proceso de phpunit.
     *
     * @group sugerencias-compras
     * @test
     */
    public function una_excepcion_de_registrar_lote_no_voltea_la_importacion()
    {
        $activo = true;

        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use (&$activo) {
            if ($activo && stripos($query, 'insert') !== false && stripos($query, 'provider_price_offers') !== false) {
                throw new \RuntimeException('Fallo simulado de registrar_lote(), para probar que la importación sobrevive.');
            }
        });

        try {
            $articulo_de_b = $this->seed['A2'];
            $antes = $this->snapshot($articulo_de_b);

            $import = $this->importar_filas([
                ['7790002', null, null, 'Nombre resiliencia P3', 888.0, 1600.0, 22.0, '21'],
            ], [
                'provider_id' => $this->providers['A']->id,
            ]);

            // importar_filas() ya exigió status 200: la importación entera NO
            // se cayó por la excepción simulada. Además, el resto del pipeline
            // (que no depende de esta misión) siguió andando normal.
            $this->assertNotNull($import);
            $this->assertEquals(1, (int) $import->filas_procesadas);
            $this->assertInvariantesDeConteo($import);

            $this->assertEquals(
                $antes,
                $this->snapshot($articulo_de_b),
                'El artículo (que ni siquiera participa del histórico roto) tiene que seguir intacto.'
            );

            // El pivot de siempre (attach_provider, ajeno a esta misión) se
            // siguió escribiendo: solo se bloqueó el INSERT a provider_price_offers.
            $pivot = DB::table('article_provider')
                ->where('article_id', $articulo_de_b->id)
                ->where('provider_id', $this->providers['A']->id)
                ->first();
            $this->assertNotNull($pivot, 'El resto de la importación no puede haberse visto afectado por la excepción simulada.');
            $this->assertEquals(888, (int) $pivot->cost);

            // Y el histórico se quedó sin la fila: el INSERT se bloqueó a propósito.
            $this->assertEquals(
                0,
                ProviderPriceOffer::where('article_id', $articulo_de_b->id)
                    ->where('provider_id', $this->providers['A']->id)
                    ->count(),
                'El insert se bloqueó a propósito: no puede haber quedado ninguna fila del histórico.'
            );
        } finally {
            $activo = false;
        }
    }

    /**
     * Tercer punto de captura (fuera de ProcessRow): una compra real. Se
     * invoca NewProviderOrderHelper::attach_articles() directo (sin pasar por
     * procesar_pedido(), que no hace falta para este método puntual) sobre
     * una orden con update_prices=1: catalogar_costo_proveedor() corre desde
     * update_article(), y tiene que dejar la oferta con origen='compra' y
     * referencia_id = el id de la orden -- el dato MÁS confiable del
     * histórico, según su propio docblock.
     *
     * @group sugerencias-compras
     * @test
     */
    public function catalogar_costo_proveedor_de_una_compra_real_deja_la_oferta_con_origen_compra_y_referencia_id()
    {
        $articulo = $this->seed['A1']; // Ya es del proveedor A en el fixture.
        $iva = Iva::first();

        $orden = ProviderOrder::create([
            'num'                      => mt_rand(9000000, 9999999),
            'provider_id'              => $this->providers['A']->id,
            'provider_order_status_id' => 1,
            'update_stock'             => 0,
            'update_prices'            => 1,
            'generate_current_acount'  => 0,
            'precios_incluyen_iva'     => false,
            'moneda_id'                => 1,
            'user_id'                  => $this->tenant->id,
        ]);

        $helper = new NewProviderOrderHelper($orden, [
            [
                'id'            => $articulo->id,
                'bar_code'      => $articulo->bar_code,
                'provider_code' => 'PC-COMPRA-REAL',
                'pivot'         => [
                    'cost'            => 456.789,
                    'amount'          => 3,
                    'received'        => 3,
                    'price'           => null,
                    'discount'        => null,
                    'iva_id'          => $iva ? $iva->id : null,
                    'cost_in_dollars' => 0,
                    'update_provider' => 1,
                ],
            ],
        ]);

        $helper->attach_articles();

        $oferta = ProviderPriceOffer::where('article_id', $articulo->id)
            ->where('provider_id', $this->providers['A']->id)
            ->where('origen', 'compra')
            ->first();

        $this->assertNotNull($oferta, 'catalogar_costo_proveedor() tiene que dejar la oferta con origen=compra.');
        $this->assertEquals(456.789, (float) $oferta->cost);
        $this->assertEquals($orden->id, $oferta->referencia_id);
        $this->assertEquals($this->tenant->id, (int) $oferta->user_id);
    }
}
