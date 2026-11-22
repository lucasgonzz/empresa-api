<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible as Writer;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\PriceChange;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Misión historial-precios-proveedor: la importación de artículos deja registrado, SIEMPRE, a qué
 * costo tiene cada proveedor cada artículo (CA1, CA2, CA3, CA6 y CA7 del plan).
 *
 * Hasta hoy `provider_price_offers` sólo se escribía cuando la fila se salteaba por ser de OTRO
 * proveedor: el propio proveedor no dejaba rastro y la oferta no decía si se usó para actualizar el
 * costo, ni a qué cambio de precio llevó. Estos tests entran por el endpoint real del importador de
 * 4 pasos (`POST /api/ai-excel-import/import`, con el Excel en storage como lo deja /analyze) y
 * miden la tabla y `price_changes`.
 *
 * Escenario propio (no usa los A1..A15 del seeder): el proveedor "BA" tiene el Taladro (barras
 * 8800001, código BA-1, costo 1000, margen 30) y "Rosario" manda una lista con el mismo Taladro
 * bajo otro código (R-77) y otro costo (1200).
 *
 * 🔴 Rojo esperado ANTES de construir la misión: columnas de uso (`updated_article_cost`,
 * `previous_article_cost`, `price_change_id`, `changed_article_provider`, `previous_article_provider_id`,
 * `last_seen_at`, `last_seen_reference_id`) inexistentes en `provider_price_offers`, filas que hoy no
 * se escriben (propio proveedor, artículo creado) y evento de precio que hoy no se crea cuando sólo
 * cambia el costo. Ningún test falla por sintaxis ni por fixture roto: las aserciones previas a la
 * columna nueva (artículo, conteos) corren contra el sistema de hoy.
 *
 * IMPORTANTE: PHP 7.4 estricto (ver las restricciones de la misión: nada de sintaxis de PHP 8).
 */
class ProviderOfferHistoryImportTest extends ImportTestCase
{
    /** Columnas que agrega la migración de la misión a provider_price_offers. */
    const COLUMNAS_DE_USO = [
        'updated_article_cost',
        'previous_article_cost',
        'price_change_id',
        'changed_article_provider',
        'previous_article_provider_id',
        'last_seen_at',
        'last_seen_reference_id',
    ];

    /** @var \App\Models\Provider Proveedor titular del Taladro. */
    protected $ba;

    /** @var \App\Models\Provider Proveedor que manda la lista con el mismo Taladro. */
    protected $rosario;

    /** @var \App\Models\Article Taladro de BA: costo 1000, margen 30%. */
    protected $taladro;

    /** @var string[] Rutas relativas (a storage/app) de los Excel que dejó el test. */
    protected $excels_en_storage = [];

    /**
     * Siembra proveedores y Taladro por encima del escenario de ImportTestSeeder.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ba      = $this->crear_proveedor('BA historial');
        $this->rosario = $this->crear_proveedor('Rosario historial');

        $this->taladro = $this->crear_articulo([
            'name'            => 'Taladro historial',
            'bar_code'        => '8800001',
            'provider_code'   => 'BA-1',
            'provider_id'     => $this->ba->id,
            'cost'            => 1000,
            'percentage_gain' => 30,
        ]);

        $this->taladro->providers()->attach($this->ba->id, ['provider_code' => 'BA-1', 'cost' => 1000]);
    }

    /**
     * Vuelve el reloj a la normalidad (CA6 lo adelanta) y borra los Excel de storage.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach ($this->excels_en_storage as $relativa) {
            foreach (glob(storage_path('app/' . $relativa) . '*') ?: [] as $residuo) {
                @unlink($residuo);
            }
        }

        parent::tearDown();
    }

    /* ==================================================================
     * CA1 — el precio se registra aunque no se use
     * ================================================================== */

    /**
     * CA1: el Excel de Rosario con la bandera "actualizar artículos de otro proveedor" APAGADA (el
     * default del importador de 4 pasos) no toca el Taladro, pero deja la oferta de Rosario con la
     * marca de "no se usó".
     *
     * @return void
     */
    public function test_offer_is_recorded_without_usage_when_other_provider_flag_is_off()
    {
        $import = $this->importar_por_ia(
            [['8800001', 'R-77', 'Taladro historial', 1200]],
            $this->rosario->id,
            ['actualizar_articulos_de_otro_proveedor' => false]
        );

        $articulo = $this->taladro->fresh();

        $this->assertDecimal(1000, $articulo->cost, 'Con la bandera apagada el Taladro de BA conserva su costo.');
        $this->assertSame($this->ba->id, (int) $articulo->provider_id);
        $this->assertSame('BA-1', $articulo->provider_code);

        $this->assert_columnas_de_uso();

        $ofertas = $this->ofertas($this->taladro->id);

        $this->assertCount(1, $ofertas, 'Tiene que quedar exactamente una oferta (la de Rosario).');

        $oferta = $ofertas[0];

        $this->assertSame($this->rosario->id, (int) $oferta->provider_id);
        $this->assertDecimal(1200, $oferta->cost);
        $this->assertSame('R-77', $oferta->provider_code);
        $this->assertSame('importacion', $oferta->origen);
        $this->assertSame($import->id, (int) $oferta->referencia_id);
        $this->assertSame(0, (int) $oferta->updated_article_cost, 'No se usó para actualizar el costo: 0, nunca null.');
        $this->assertNotNull($oferta->updated_article_cost);
        $this->assertSame(0, (int) $oferta->changed_article_provider);
        $this->assertNotNull($oferta->changed_article_provider);
        $this->assertSame($this->ba->id, (int) $oferta->previous_article_provider_id, 'Guarda quién era el titular antes de la operación.');
        $this->assertNull($oferta->price_change_id, 'Sin uso no hay cambio de precio vinculado.');
        $this->assertNull($oferta->previous_article_cost);
    }

    /* ==================================================================
     * CA2 — el propio proveedor actualiza y la oferta queda vinculada
     * ================================================================== */

    /**
     * CA2: el Excel de BA (el titular) con un costo nuevo actualiza el artículo y deja una oferta de
     * BA marcada como usada, con el costo anterior y el id del cambio de precio de esa importación.
     *
     * @return void
     */
    public function test_own_provider_update_records_usage_and_links_price_change()
    {
        $evento_previo = $this->max_price_change_id();

        $this->importar_por_ia(
            [['8800001', 'BA-1', 'Taladro historial', 1100]],
            $this->ba->id
        );

        $this->assertDecimal(1100, $this->taladro->fresh()->cost, 'La importación del titular actualiza el costo.');

        $evento = $this->evento_de_importacion($this->taladro->id, $evento_previo);

        $this->assertNotNull($evento, 'La importación tiene que dejar un cambio de precio del Taladro (origen importacion).');

        $this->assert_columnas_de_uso();

        $ofertas = $this->ofertas($this->taladro->id, $this->ba->id);

        $this->assertCount(1, $ofertas, 'Hoy el propio proveedor no deja oferta: tiene que dejar una.');

        $oferta = $ofertas[0];

        $this->assertDecimal(1100, $oferta->cost);
        $this->assertSame(1, (int) $oferta->updated_article_cost);
        $this->assertDecimal(1000, $oferta->previous_article_cost);
        $this->assertSame(0, (int) $oferta->changed_article_provider);
        $this->assertSame($this->ba->id, (int) $oferta->previous_article_provider_id);
        $this->assertSame((int) $evento->id, (int) $oferta->price_change_id, 'La oferta apunta al evento de price_changes que produjo.');
    }

    /* ==================================================================
     * CA3 — artículo creado
     * ================================================================== */

    /**
     * CA3: una fila nueva del proveedor crea el artículo y deja la oferta como usada (el costo
     * nació con ella), sin costo anterior ni titular anterior, vinculada al evento de precio.
     *
     * @return void
     */
    public function test_created_article_records_offer_as_used()
    {
        $evento_previo = $this->max_price_change_id();

        $this->importar_por_ia(
            [['8800002', 'BA-2', 'Martillo historial', 500]],
            $this->ba->id
        );

        $martillo = Article::where('user_id', $this->tenant->id)->where('bar_code', '8800002')->first();

        $this->assertNotNull($martillo, 'La fila nueva tiene que crear el artículo.');
        $this->assertDecimal(500, $martillo->cost);

        $this->assert_columnas_de_uso();

        $ofertas = $this->ofertas($martillo->id, $this->ba->id);

        $this->assertCount(1, $ofertas, 'El artículo creado tiene que dejar su oferta.');

        $oferta = $ofertas[0];

        $this->assertDecimal(500, $oferta->cost);
        $this->assertSame('BA-2', $oferta->provider_code);
        $this->assertSame(1, (int) $oferta->updated_article_cost, 'El costo nació con esta oferta: usada.');
        $this->assertNull($oferta->previous_article_cost);
        $this->assertNull($oferta->previous_article_provider_id);

        $evento = $this->evento_de_importacion($martillo->id, $evento_previo);

        $this->assertNotNull($evento, 'El alta con costo y margen deja su evento de precio (origen importacion).');
        $this->assertSame((int) $evento->id, (int) $oferta->price_change_id);
    }

    /* ==================================================================
     * CA6 — dedupe con "visto por última vez"
     * ================================================================== */

    /**
     * CA6: reimportar el MISMO Excel de Rosario al día siguiente no suma filas: la oferta vigente
     * recibe `last_seen_at` y `last_seen_reference_id` de la nueva importación. Con un costo
     * distinto sí hay fila nueva.
     *
     * @return void
     */
    public function test_reimport_without_changes_touches_last_seen_instead_of_inserting()
    {
        $this->importar_por_ia([['8800001', 'R-77', 'Taladro historial', 1200]], $this->rosario->id);

        $this->assert_columnas_de_uso();

        $this->assertCount(1, $this->ofertas($this->taladro->id, $this->rosario->id));

        // Un día después: dentro de la ventana de reconfirmación (30 días), mismo costo.
        $manana = Carbon::now()->addDay()->startOfSecond();
        Carbon::setTestNow($manana);

        $segunda = $this->importar_por_ia([['8800001', 'R-77', 'Taladro historial', 1200]], $this->rosario->id);

        $ofertas = $this->ofertas($this->taladro->id, $this->rosario->id);

        $this->assertCount(1, $ofertas, 'Mismo costo dentro de la ventana: no se inserta una fila nueva.');
        $this->assertNotNull($ofertas[0]->last_seen_at, 'Pero queda anotado cuándo se volvió a ver el precio.');
        $this->assertSame($manana->toDateTimeString(), Carbon::parse($ofertas[0]->last_seen_at)->toDateTimeString());
        $this->assertSame($segunda->id, (int) $ofertas[0]->last_seen_reference_id);

        // Otro día más, con otro costo: fila nueva.
        Carbon::setTestNow($manana->copy()->addDay());

        $this->importar_por_ia([['8800001', 'R-77', 'Taladro historial', 1250]], $this->rosario->id);

        $ofertas = $this->ofertas($this->taladro->id, $this->rosario->id);

        $this->assertCount(2, $ofertas, 'Un costo distinto es una fila nueva.');
        $this->assertDecimal(1250, $ofertas[1]->cost);
    }

    /* ==================================================================
     * CA7 — cambio sólo de costo
     * ================================================================== */

    /**
     * CA7 (importación): con un artículo de precio manual (sin margen, precio de venta fijo), un
     * Excel que sólo cambia el costo deja IGUAL un cambio de precio de origen `importacion` (costo
     * nuevo, precio final sin cambio) y la oferta apunta a él. La ficha, en cambio, sigue sin
     * registrar un cambio sólo de costo (P4: sólo importaciones).
     *
     * @return void
     */
    public function test_cost_only_change_in_import_creates_price_change()
    {
        $sierra = $this->crear_articulo([
            'name'            => 'Sierra manual historial',
            'bar_code'        => '8800003',
            'provider_code'   => 'BA-3',
            'provider_id'     => $this->ba->id,
            'cost'            => 1000,
            'percentage_gain' => null,
            'price'           => 3000,
        ]);

        $this->assertDecimal(3000, $sierra->fresh()->final_price, 'Premisa: el precio manual fija el precio final en 3000.');

        $evento_previo = $this->max_price_change_id();
        $cantidad_previa = PriceChange::where('article_id', $sierra->id)->count();

        $this->importar_por_ia([['8800003', 'BA-3', 'Sierra manual historial', 1100]], $this->ba->id);

        $this->assertDecimal(1100, $sierra->fresh()->cost);
        $this->assertDecimal(3000, $sierra->fresh()->final_price, 'El precio de venta manual no se mueve.');

        $evento = $this->evento_de_importacion($sierra->id, $evento_previo);

        $this->assertNotNull(
            $evento,
            'Un cambio sólo de costo en una importación tiene que dejar un cambio de precio (P4); hoy no genera evento.'
        );
        $this->assertDecimal(1100, $evento->cost);
        $this->assertDecimal(3000, $evento->final_price);

        $this->assert_columnas_de_uso();

        $ofertas = $this->ofertas($sierra->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(1, (int) $ofertas[0]->updated_article_cost);
        $this->assertDecimal(1000, $ofertas[0]->previous_article_cost);
        $this->assertSame((int) $evento->id, (int) $ofertas[0]->price_change_id);

        // La ficha (PUT del artículo) cambiando sólo el costo NO crea evento: queda como hoy.
        $cantidad_tras_importar = PriceChange::where('article_id', $sierra->id)->count();

        $this->assertSame($cantidad_previa + 1, $cantidad_tras_importar);

        $payload = array_merge($sierra->fresh()->getAttributes(), [
            'id'                 => $sierra->id,
            'cost'               => 1200,
            'price_types'        => [],
            'price_type_monedas' => [],
            'cost_incluye_iva'   => 0,
            'tags'               => [],
        ]);

        $this->putJson('api/article/' . $sierra->id, $payload)->assertOk();

        $this->assertDecimal(1200, $sierra->fresh()->cost, 'Premisa: la ficha guardó el costo nuevo.');
        $this->assertSame(
            $cantidad_tras_importar,
            PriceChange::where('article_id', $sierra->id)->count(),
            'La ficha no registra cambios sólo de costo: la opción es exclusiva de la importación.'
        );
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Falla con un mensaje claro si la migración de la misión todavía no corrió.
     *
     * @return void
     */
    protected function assert_columnas_de_uso()
    {
        foreach (self::COLUMNAS_DE_USO as $columna) {
            $this->assertTrue(
                Schema::hasColumn('provider_price_offers', $columna),
                'Falta la columna provider_price_offers.' . $columna . ' (la migración de la misión no existe todavía).'
            );
        }
    }

    /**
     * Crea un proveedor del tenant de prueba.
     *
     * @param  string $name
     * @return \App\Models\Provider
     */
    protected function crear_proveedor($name)
    {
        $provider = new Provider();

        $provider->name    = $name;
        $provider->user_id = $this->tenant->id;
        $provider->status  = 'active';

        $provider->save();

        return $provider;
    }

    /**
     * Crea un artículo del tenant y deriva su precio por la misma vía que la importación.
     *
     * @param  array $props
     * @return \App\Models\Article
     */
    protected function crear_articulo(array $props)
    {
        $article = new Article();

        $props = array_merge([
            'user_id' => $this->tenant->id,
            'iva_id'  => 2,
            'status'  => 'active',
            'online'  => 1,
            'stock'   => 5,
        ], $props);

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        ImportTestSeeder::derivar_precio($article, $this->tenant->id);

        return $article->fresh();
    }

    /**
     * Ofertas del artículo, de la más vieja a la más nueva.
     *
     * @param  int      $article_id
     * @param  int|null $provider_id
     * @return array    de stdClass (fila cruda: incluye las columnas nuevas)
     */
    protected function ofertas($article_id, $provider_id = null)
    {
        $consulta = DB::table('provider_price_offers')->where('article_id', $article_id);

        if (!is_null($provider_id)) {
            $consulta->where('provider_id', $provider_id);
        }

        return $consulta->orderBy('id')->get()->all();
    }

    /**
     * Mayor id de price_changes, para distinguir los eventos que crea la importación del test.
     *
     * @return int
     */
    protected function max_price_change_id()
    {
        return (int) PriceChange::max('id');
    }

    /**
     * Último cambio de precio de origen `importacion` del artículo creado después de $id_previo.
     *
     * @param  int $article_id
     * @param  int $id_previo
     * @return \App\Models\PriceChange|null
     */
    protected function evento_de_importacion($article_id, $id_previo)
    {
        return PriceChange::where('article_id', $article_id)
                            ->where('origen', 'importacion')
                            ->where('id', '>', $id_previo)
                            ->orderByDesc('id')
                            ->first();
    }

    /**
     * Importa filas [barras, código de proveedor, nombre, costo] por el endpoint real del
     * importador de 4 pasos, con el Excel en storage como lo deja /analyze.
     *
     * @param  array    $filas
     * @param  int|null $provider_id  proveedor elegido en el paso 1
     * @param  array    $extra        overrides del cuerpo del request
     * @return \App\Models\ImportHistory
     */
    protected function importar_por_ia(array $filas, $provider_id, array $extra = [])
    {
        $relativa = 'imported_files/historial_proveedor_' . uniqid() . '.xlsx';
        $absoluta = storage_path('app/' . $relativa);

        if (!is_dir(dirname($absoluta))) {
            mkdir(dirname($absoluta), 0777, true);
        }

        $this->excels_en_storage[] = $relativa;

        $escritor = Writer::createXLSXWriter();
        $escritor->openToFile($absoluta);
        $escritor->addRow(Writer::createRowFromArray(['Barras', 'Codigo proveedor', 'Nombre', 'Costo']));

        foreach ($filas as $fila) {
            $escritor->addRow(Writer::createRowFromArray($fila));
        }

        $escritor->close();

        $this->postJson('/api/ai-excel-import/import', array_merge([
            'excel_path' => $relativa,
            'model'      => 'article',
            'columns'    => [
                'codigo_de_barras'    => 0,
                'codigo_de_proveedor' => 1,
                'nombre'              => 2,
                'costo'               => 3,
            ],
            'start_row'       => 2,
            'finish_row'      => 99999,
            'provider_id'     => $provider_id,
            'create_and_edit' => true,
            'registrar_art_cre'                                  => true,
            'registrar_art_act'                                  => true,
            'actualizar_por_provider_code'                       => true,
            'actualizar_proveedor'                               => false,
            'permitir_provider_code_repetido'                    => false,
            'permitir_provider_code_repetido_en_multi_providers' => true,
            'actualizar_articulos_de_otro_proveedor'             => false,
        ], $extra))->assertStatus(200);

        $import = ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import, 'La importación no dejó ImportHistory.');
        $this->assertInvariantesDeConteo($import);

        return $import;
    }
}
