<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible as Writer;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\PriceChange;
use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Misión historial-precios-proveedor: actualizar artículos de OTRO proveedor sin perder el
 * registro (CA4, CA5, CA8 y CA13 del plan).
 *
 * Caso de Lucas: el Taladro es de "BA" (barras 8800001, código BA-1, costo 1000). Llega la lista de
 * "Rosario" con el mismo Taladro por código de barras, otro código (R-77) y otro costo (1200).
 *   - Con "actualizar artículos de otro proveedor" el costo pasa a 1200, pero el artículo SIGUE
 *     siendo de BA y conserva su código BA-1 (P6: el código de Rosario queda en el pivot
 *     `article_provider`, no en la ficha). El historial dice que se usó y que el proveedor no cambió.
 *   - Con "actualizar también el proveedor", el artículo pasa a ser de Rosario (código R-77) y el
 *     historial lo dice.
 *   - La bandera vieja mezclaba dos cosas (P3): pisar por código de barras y cruzar por código de
 *     proveedor. Un parámetro nuevo, `update_other_provider_by_provider_code`, las separa; sin él,
 *     todo se comporta como hoy (importador clásico, SPA vieja, jobs ya encolados).
 *
 * 🔴 Rojo esperado ANTES de construir la misión: columnas de uso inexistentes en
 * `provider_price_offers`; `articles.provider_code` que hoy se pisa con el código del otro proveedor;
 * parámetro `update_other_provider_by_provider_code` que hoy se ignora. Las aserciones que describen
 * el comportamiento de hoy (costo, proveedor, camino legado) se evalúan ANTES de tocar columnas
 * nuevas, así que ningún test cae por sintaxis ni por fixture roto.
 *
 * IMPORTANTE: PHP 7.4 estricto (ver las restricciones de la misión: nada de sintaxis de PHP 8).
 */
class UpdateOtherProviderArticlesTest extends ImportTestCase
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

    /** Clave de la API de admin-sync que usan los tests que entran por esa puerta. */
    const CLAVE_ADMIN = 'clave-de-prueba';

    /** @var \App\Models\Provider Proveedor titular del Taladro. */
    protected $ba;

    /** @var \App\Models\Provider Proveedor que manda la lista con el mismo Taladro. */
    protected $rosario;

    /** @var \App\Models\Article Taladro de BA: costo 1000, margen 30%. */
    protected $taladro;

    /** @var string[] Rutas relativas (a storage/app) de los Excel que dejó el test. */
    protected $excels_en_storage = [];

    /** @var string[] Rutas absolutas de los Excel temporales del importador clásico. */
    protected $temporales = [];

    /**
     * Siembra proveedores y Taladro por encima del escenario de ImportTestSeeder.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ba      = $this->crear_proveedor('BA otro proveedor');
        $this->rosario = $this->crear_proveedor('Rosario otro proveedor');

        $this->taladro = $this->crear_articulo([
            'name'            => 'Taladro otro proveedor',
            'bar_code'        => '8800001',
            'provider_code'   => 'BA-1',
            'provider_id'     => $this->ba->id,
            'cost'            => 1000,
            'percentage_gain' => 30,
        ]);

        $this->taladro->providers()->attach($this->ba->id, ['provider_code' => 'BA-1', 'cost' => 1000]);
    }

    /**
     * Borra los Excel que dejó el test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->excels_en_storage as $relativa) {
            foreach (glob(storage_path('app/' . $relativa) . '*') ?: [] as $residuo) {
                @unlink($residuo);
            }
        }

        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    /* ==================================================================
     * CA4 — otro proveedor, sin cambiar el proveedor
     * ================================================================== */

    /**
     * CA4: con "actualizar artículos de otro proveedor" y SIN "actualizar proveedor", el Taladro de
     * BA toma el costo de Rosario y sigue siendo de BA, con su código BA-1. Los pivots de los dos
     * proveedores quedan con su código; el historial registra el uso.
     *
     * @return void
     */
    public function test_other_provider_article_takes_cost_and_keeps_provider_and_code()
    {
        $evento_previo = (int) PriceChange::max('id');

        $this->importar_por_ia([['8800001', 'R-77', 'Taladro otro proveedor', 1200]], $this->rosario->id, [
            'actualizar_articulos_de_otro_proveedor'       => true,
            'actualizar_proveedor'                         => false,
            'update_other_provider_by_provider_code'       => false,
        ]);

        $articulo = $this->taladro->fresh();

        $this->assertDecimal(1200, $articulo->cost, 'Con la bandera prendida el Taladro toma el costo de Rosario.');
        $this->assertSame($this->ba->id, (int) $articulo->provider_id, 'Sin "actualizar proveedor" el artículo sigue siendo de BA.');
        $this->assertSame('BA-1', $articulo->provider_code, 'P6: el código de Rosario no pisa el código de la ficha.');

        $pivot_rosario = DB::table('article_provider')
            ->where('article_id', $this->taladro->id)->where('provider_id', $this->rosario->id)->first();
        $pivot_ba = DB::table('article_provider')
            ->where('article_id', $this->taladro->id)->where('provider_id', $this->ba->id)->first();

        $this->assertNotNull($pivot_rosario, 'Rosario queda como referencia del artículo.');
        $this->assertSame('R-77', $pivot_rosario->provider_code);
        $this->assertDecimal(1200, $pivot_rosario->cost);
        $this->assertNotNull($pivot_ba);
        $this->assertSame('BA-1', $pivot_ba->provider_code, 'El pivot de BA queda intacto.');
        $this->assertDecimal(1000, $pivot_ba->cost, 'El pivot de BA queda intacto.');

        $evento = PriceChange::where('article_id', $this->taladro->id)
            ->where('origen', 'importacion')->where('id', '>', $evento_previo)->orderByDesc('id')->first();

        $this->assertNotNull($evento, 'El cambio de costo con margen deja un evento de precio de la importación.');

        $this->assert_columnas_de_uso();

        $ofertas = $this->ofertas($this->taladro->id, $this->rosario->id);

        $this->assertCount(1, $ofertas);

        $oferta = $ofertas[0];

        $this->assertDecimal(1200, $oferta->cost);
        $this->assertSame('R-77', $oferta->provider_code);
        $this->assertSame(1, (int) $oferta->updated_article_cost);
        $this->assertDecimal(1000, $oferta->previous_article_cost);
        $this->assertSame(0, (int) $oferta->changed_article_provider, 'El artículo siguió siendo de BA.');
        $this->assertSame($this->ba->id, (int) $oferta->previous_article_provider_id);
        $this->assertSame((int) $evento->id, (int) $oferta->price_change_id);
    }

    /* ==================================================================
     * CA5 — otro proveedor, cambiando el proveedor
     * ================================================================== */

    /**
     * CA5: con "actualizar artículos de otro proveedor" Y "actualizar proveedor", el Taladro pasa a
     * ser de Rosario (código R-77); el pivot de BA conserva su código y el historial lo dice.
     *
     * @return void
     */
    public function test_other_provider_article_changes_provider_when_requested()
    {
        $this->importar_por_ia([['8800001', 'R-77', 'Taladro otro proveedor', 1200]], $this->rosario->id, [
            'actualizar_articulos_de_otro_proveedor'       => true,
            'actualizar_proveedor'                         => true,
            'update_other_provider_by_provider_code'       => false,
        ]);

        $articulo = $this->taladro->fresh();

        $this->assertDecimal(1200, $articulo->cost);
        $this->assertSame($this->rosario->id, (int) $articulo->provider_id, 'Con "actualizar proveedor" el artículo pasa a Rosario.');
        $this->assertSame('R-77', $articulo->provider_code);

        $pivot_ba = DB::table('article_provider')
            ->where('article_id', $this->taladro->id)->where('provider_id', $this->ba->id)->first();

        $this->assertNotNull($pivot_ba, 'BA sigue siendo una referencia del artículo.');
        $this->assertSame('BA-1', $pivot_ba->provider_code, 'El pivot de BA conserva su código.');

        $this->assert_columnas_de_uso();

        $ofertas = $this->ofertas($this->taladro->id, $this->rosario->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(1, (int) $ofertas[0]->updated_article_cost);
        $this->assertSame(1, (int) $ofertas[0]->changed_article_provider, 'El historial dice que el artículo cambió de proveedor.');
        $this->assertSame($this->ba->id, (int) $ofertas[0]->previous_article_provider_id, 'Y quién era el titular antes.');
    }

    /* ==================================================================
     * CA8 — el cruce por código de proveedor tiene su propia bandera
     * ================================================================== */

    /**
     * CA8 (a): el Martillo de BA tiene código de proveedor 100 y NO tiene barras. Rosario manda una
     * Pinza (otro producto) con el mismo código 100. Con "actualizar artículos de otro proveedor"
     * PERO `update_other_provider_by_provider_code = 0`, el cruce por código de proveedor no corre:
     * el Martillo no se toca.
     *
     * @return void
     */
    public function test_provider_code_cross_match_needs_its_own_flag()
    {
        $martillo = $this->martillo_de_ba();

        $this->importar_por_ia([['', '100', 'Pinza otro proveedor', 800]], $this->rosario->id, [
            'actualizar_articulos_de_otro_proveedor' => true,
            'update_other_provider_by_provider_code' => false,
        ]);

        $this->assertDecimal(
            500,
            $martillo->fresh()->cost,
            'Sin la bandera propia, un código de proveedor igual NO alcanza para pisar un artículo de otro proveedor.'
        );
        $this->assertSame($this->ba->id, (int) $martillo->fresh()->provider_id);
    }

    /**
     * CA8 (b): sin el parámetro nuevo (importador clásico, SPA vieja, jobs ya encolados) vale lo que
     * valga la bandera principal: el comportamiento es el de hoy y el Martillo SÍ se actualiza.
     * Caracteriza develop: tiene que pasar antes y después de la misión.
     *
     * @return void
     */
    public function test_missing_provider_code_flag_keeps_legacy_behavior()
    {
        $martillo = $this->martillo_de_ba();

        $this->importar_por_ia([['', '100', 'Pinza otro proveedor', 800]], $this->rosario->id, [
            'actualizar_articulos_de_otro_proveedor' => true,
        ]);

        $this->assertDecimal(
            800,
            $martillo->fresh()->cost,
            'Sin el parámetro nuevo el cruce por código de proveedor se comporta como siempre.'
        );
    }

    /* ==================================================================
     * CA13 — las otras puertas no cambian
     * ================================================================== */

    /**
     * CA13 (admin-sync): Martín importa por `api/admin-sync/ai-excel-import/import` con las banderas
     * en false: el Taladro de BA no cambia (como hoy) pero la oferta de Rosario queda registrada con
     * la marca de "no se usó". La importación de prueba gratuita comparte esta configuración
     * (`ImportacionDePruebaService` manda la bandera en false) y su flujo es una máquina de estados
     * con IA que no se ejercita acá.
     *
     * @return void
     */
    public function test_admin_sync_and_trial_imports_keep_behavior_and_record_offer()
    {
        $relativa = $this->excel_en_storage([['8800001', 'R-77', 'Taladro otro proveedor', 1200]]);

        config([
            'services.admin_api.require_api_key' => true,
            'services.admin_api.api_key'         => self::CLAVE_ADMIN,
        ]);

        // El admin no viene logueado: ImportTestCase deja logueado al tenant.
        $this->app['auth']->forgetGuards();

        $this->postJson('api/admin-sync/ai-excel-import/import', [
            'user_id'    => $this->tenant->id,
            'model'      => 'article',
            'excel_path' => $relativa,
            'columns'    => [
                'codigo_de_barras'    => 0,
                'codigo_de_proveedor' => 1,
                'nombre'              => 2,
                'costo'               => 3,
            ],
            'create_and_edit' => true,
            'start_row'       => 2,
            'finish_row'      => 99999,
            'provider_id'     => $this->rosario->id,
            'permitir_provider_code_repetido_en_multi_providers' => true,
        ], ['X-Admin-Api-Key' => self::CLAVE_ADMIN])->assertStatus(200);

        $articulo = $this->taladro->fresh();

        $this->assertDecimal(1000, $articulo->cost, 'Por admin-sync el Taladro de BA no cambia (banderas en false).');
        $this->assertSame($this->ba->id, (int) $articulo->provider_id);
        $this->assertSame('BA-1', $articulo->provider_code);

        $this->assert_columnas_de_uso();

        $ofertas = $this->ofertas($this->taladro->id, $this->rosario->id);

        $this->assertCount(1, $ofertas, 'Igual deja registrado a cuánto lo tiene Rosario.');
        $this->assertDecimal(1200, $ofertas[0]->cost);
        $this->assertSame(0, (int) $ofertas[0]->updated_article_cost);
        $this->assertNotNull($ofertas[0]->updated_article_cost);
    }

    /**
     * CA13 (importador clásico): `ArticleController@import` con (otro proveedor = sí, proveedor = no)
     * deja el mismo costo que develop; la única diferencia es P6: el código de proveedor de la ficha
     * se conserva (hoy se pisa con el de Rosario).
     *
     * @return void
     */
    public function test_classic_import_keeps_cost_and_preserves_scalar_code()
    {
        $this->importar_clasico([['8800001', '', 'R-77', 'Taladro otro proveedor', 1200, '', '', '']], $this->rosario->id, [
            'actualizar_articulos_de_otro_proveedor' => true,
            'actualizar_proveedor'                   => false,
        ]);

        $articulo = $this->taladro->fresh();

        $this->assertDecimal(1200, $articulo->cost, 'El clásico sigue actualizando el costo (igual que develop).');
        $this->assertSame($this->ba->id, (int) $articulo->provider_id, 'Sin "actualizar proveedor" sigue siendo de BA.');
        $this->assertSame('BA-1', $articulo->provider_code, 'P6: también en el clásico, el código de la ficha se conserva.');
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    /**
     * Martillo de BA con código de proveedor 100 y sin código de barras ni SKU.
     *
     * @return \App\Models\Article
     */
    protected function martillo_de_ba()
    {
        $martillo = $this->crear_articulo([
            'name'          => 'Martillo codigo cien',
            'bar_code'      => null,
            'sku'           => null,
            'provider_code' => '100',
            'provider_id'   => $this->ba->id,
            'cost'          => 500,
        ]);

        $martillo->providers()->attach($this->ba->id, ['provider_code' => '100', 'cost' => 500]);

        return $martillo;
    }

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
     * Escribe un Excel [barras, código de proveedor, nombre, costo] en storage/app/imported_files,
     * como lo deja /analyze, y devuelve su ruta relativa.
     *
     * @param  array $filas
     * @return string
     */
    protected function excel_en_storage(array $filas)
    {
        $relativa = 'imported_files/otro_proveedor_' . uniqid() . '.xlsx';
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

        return $relativa;
    }

    /**
     * Importa filas por el endpoint real del importador de 4 pasos.
     *
     * @param  array    $filas
     * @param  int|null $provider_id  proveedor elegido en el paso 1
     * @param  array    $extra        overrides del cuerpo del request
     * @return \App\Models\ImportHistory
     */
    protected function importar_por_ia(array $filas, $provider_id, array $extra = [])
    {
        $relativa = $this->excel_en_storage($filas);

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

    /**
     * Importa por el importador CLÁSICO (`POST /api/article/excel/import`), con las ocho columnas de
     * ImportTestCase::columnas() (barras, SKU, código de proveedor, nombre, costo, precio, stock, IVA).
     *
     * @param  array    $filas
     * @param  int|null $provider_id
     * @param  array    $extra        overrides del cuerpo del request
     * @return \App\Models\ImportHistory
     */
    protected function importar_clasico(array $filas, $provider_id, array $extra = [])
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('otro_proveedor_clasico_') . '.xlsx';

        $this->temporales[] = $ruta;

        $escritor = Writer::createXLSXWriter();
        $escritor->openToFile($ruta);
        $escritor->addRow(Writer::createRowFromArray(['Barras', 'SKU', 'Código proveedor', 'Nombre', 'Costo', 'Precio', 'Stock', 'IVA']));

        foreach ($filas as $fila) {
            $escritor->addRow(Writer::createRowFromArray($fila));
        }

        $escritor->close();

        $payload = array_merge(self::config_por_defecto(), self::columnas(), [
            'models'      => new UploadedFile($ruta, 'otro-proveedor.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'start_row'   => 2,
            'finish_row'  => count($filas) + 1,
            'provider_id' => $provider_id,
        ], $extra);

        $this->postJson('/api/article/excel/import', $payload)->assertOk();

        $import = ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import, 'La importación clásica no dejó ImportHistory.');
        $this->assertInvariantesDeConteo($import);

        return $import;
    }
}
