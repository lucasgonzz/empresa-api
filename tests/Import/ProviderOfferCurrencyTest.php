<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible as Writer;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * Misión historial-precios-proveedor, ronda 2 (REVISION-1-A #2 y C-api #1): la moneda de la oferta
 * que deja la importación.
 *
 * Regla: la de la fila si el Excel mapea la columna "moneda"; si no, la del artículo
 * (`articles.cost_in_dollars`), como ya hace ProcessRow al convertir el precio de la lista. Antes la
 * oferta salía de `$data['cost_in_dollars']`, que no existe si el Excel no mapea "moneda": un artículo
 * en dólares quedaba con una oferta en pesos (moneda_id 1) y competía mal en mejores_ofertas_para().
 *
 * Los tres puntos que arman ofertas se cubren acá: la fila del propio proveedor / otro proveedor
 * actualizado (buffer_row_offer), la fila salteada de otro proveedor (registrar_oferta_de_otro_proveedor
 * con el modelo a mano) y la fila bloqueada por código de proveedor (sólo ids). El alta de artículos
 * (ofertas_de_articulos_creados) toma la moneda del artículo recién creado.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
class ProviderOfferCurrencyTest extends ImportTestCase
{
    /** @var \App\Models\Provider Proveedor titular de los artículos. */
    protected $ba;

    /** @var \App\Models\Provider Proveedor que manda la lista. */
    protected $rosario;

    /** @var string[] Rutas relativas (a storage/app) de los Excel que dejó el test. */
    protected $excels_en_storage = [];

    /**
     * Siembra los dos proveedores.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ba      = $this->crear_proveedor('BA moneda');
        $this->rosario = $this->crear_proveedor('Rosario moneda');
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

        parent::tearDown();
    }

    /**
     * Artículo de BA en dólares, Excel de BA SIN columna de moneda: la oferta queda en dólares
     * (moneda_id 2), no en pesos.
     *
     * @return void
     */
    public function test_own_provider_offer_without_currency_column_uses_article_currency()
    {
        $articulo = $this->articulo_de_ba_en_dolares('8800101', 'BA-101');

        $this->importar_por_ia([['8800101', 'BA-101', 'Taladro en dolares', 12]], $this->ba->id, false);

        $this->assertDecimal(12, $articulo->fresh()->cost, 'El costo se actualiza.');
        $this->assertSame(1, (int) $articulo->fresh()->cost_in_dollars, 'Sin columna de moneda el artículo sigue en dólares.');

        $ofertas = $this->ofertas($articulo->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(2, (int) $ofertas[0]->moneda_id, 'La oferta de un artículo en dólares sin columna de moneda va en dólares.');
        $this->assertSame(1, (int) $ofertas[0]->updated_article_cost);
    }

    /**
     * Mismo artículo, pero el Excel mapea la columna de moneda y dice pesos: manda la fila.
     *
     * @return void
     */
    public function test_explicit_currency_column_in_pesos_wins_over_article_currency()
    {
        $articulo = $this->articulo_de_ba_en_dolares('8800102', 'BA-102');

        $this->importar_por_ia([['8800102', 'BA-102', 'Taladro en dolares', 15000, 'pesos']], $this->ba->id, true);

        $ofertas = $this->ofertas($articulo->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(1, (int) $ofertas[0]->moneda_id, 'Con la columna de moneda en pesos la oferta va en pesos.');
    }

    /**
     * Y con la columna en dólares, la oferta va en dólares aunque el artículo estuviera en pesos.
     *
     * @return void
     */
    public function test_explicit_currency_column_in_dollars_wins_over_peso_article()
    {
        $articulo = $this->crear_articulo([
            'name'          => 'Taladro en pesos',
            'bar_code'      => '8800103',
            'provider_code' => 'BA-103',
            'provider_id'   => $this->ba->id,
            'cost'          => 1000,
        ]);

        $articulo->providers()->attach($this->ba->id, ['provider_code' => 'BA-103', 'cost' => 1000]);

        $this->importar_por_ia([['8800103', 'BA-103', 'Taladro en pesos', 11, 'USD']], $this->ba->id, true);

        $ofertas = $this->ofertas($articulo->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(2, (int) $ofertas[0]->moneda_id);
    }

    /**
     * Fila de otro proveedor salteada (bandera apagada) sobre un artículo en dólares, Excel sin
     * columna de moneda: la oferta de Rosario queda en dólares (se toma del modelo ya cargado).
     *
     * @return void
     */
    public function test_skipped_other_provider_row_without_currency_column_uses_article_currency()
    {
        $articulo = $this->articulo_de_ba_en_dolares('8800104', 'BA-104');

        $this->importar_por_ia([['8800104', 'R-104', 'Taladro en dolares', 13]], $this->rosario->id, false, [
            'actualizar_articulos_de_otro_proveedor' => false,
        ]);

        $this->assertDecimal(10, $articulo->fresh()->cost, 'Con la bandera apagada el artículo de BA no cambia.');

        $ofertas = $this->ofertas($articulo->id, $this->rosario->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(0, (int) $ofertas[0]->updated_article_cost);
        $this->assertSame(2, (int) $ofertas[0]->moneda_id, 'La oferta de otro proveedor sobre un artículo en dólares va en dólares.');
    }

    /**
     * Fila bloqueada por código de proveedor en otro proveedor (sólo se conocen los ids): sin columna
     * de moneda, la oferta toma la moneda del artículo.
     *
     * @return void
     */
    public function test_blocked_by_provider_code_row_without_currency_column_uses_article_currency()
    {
        $articulo = $this->articulo_de_ba_en_dolares('', 'BA-105');

        // Sin barras ni nombre en común: sólo coincide el código de proveedor, y no se permite
        // repetir códigos entre proveedores ni actualizar artículos de otro proveedor.
        $this->importar_por_ia([['', 'BA-105', 'Otra cosa distinta', 14]], $this->rosario->id, false, [
            'actualizar_articulos_de_otro_proveedor'             => false,
            'permitir_provider_code_repetido_en_multi_providers' => false,
        ]);

        $this->assertDecimal(10, $articulo->fresh()->cost, 'El artículo de BA no cambia.');

        $ofertas = $this->ofertas($articulo->id, $this->rosario->id);

        $this->assertCount(1, $ofertas, 'La fila bloqueada deja igual la oferta de Rosario.');
        $this->assertSame(2, (int) $ofertas[0]->moneda_id);
    }

    /**
     * Artículo creado por la importación con la columna de moneda en dólares: su oferta nace en dólares.
     *
     * @return void
     */
    public function test_created_article_offer_takes_the_currency_of_the_row()
    {
        $this->importar_por_ia([['8800106', 'BA-106', 'Articulo nuevo en dolares', 9, 'USD']], $this->ba->id, true);

        $creado = Article::where('user_id', $this->tenant->id)->where('bar_code', '8800106')->first();

        $this->assertNotNull($creado, 'La importación crea el artículo.');

        $ofertas = $this->ofertas($creado->id, $this->ba->id);

        $this->assertCount(1, $ofertas);
        $this->assertSame(2, (int) $ofertas[0]->moneda_id);
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

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
     * Artículo de BA con costo 10 en dólares.
     *
     * @param  string $bar_code
     * @param  string $provider_code
     * @return \App\Models\Article
     */
    protected function articulo_de_ba_en_dolares($bar_code, $provider_code)
    {
        $articulo = $this->crear_articulo([
            'name'            => 'Taladro en dolares',
            'bar_code'        => $bar_code === '' ? null : $bar_code,
            'provider_code'   => $provider_code,
            'provider_id'     => $this->ba->id,
            'cost'            => 10,
            'cost_in_dollars' => 1,
        ]);

        $articulo->providers()->attach($this->ba->id, ['provider_code' => $provider_code, 'cost' => 10]);

        return $articulo;
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
            'user_id'         => $this->tenant->id,
            'iva_id'          => 2,
            'status'          => 'active',
            'online'          => 1,
            'stock'           => 5,
            'percentage_gain' => 30,
        ], $props);

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        ImportTestSeeder::derivar_precio($article, $this->tenant->id);

        return $article->fresh();
    }

    /**
     * Ofertas del artículo para un proveedor, de la más vieja a la más nueva.
     *
     * @param  int $article_id
     * @param  int $provider_id
     * @return array de stdClass
     */
    protected function ofertas($article_id, $provider_id)
    {
        return DB::table('provider_price_offers')
            ->where('article_id', $article_id)
            ->where('provider_id', $provider_id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Importa filas [barras, código de proveedor, nombre, costo(, moneda)] por el endpoint real del
     * importador de 4 pasos.
     *
     * @param  array    $filas
     * @param  int      $provider_id
     * @param  bool     $con_columna_de_moneda si el Excel mapea la columna "moneda" (5ª)
     * @param  array    $extra overrides del cuerpo del request
     * @return \App\Models\ImportHistory
     */
    protected function importar_por_ia(array $filas, $provider_id, $con_columna_de_moneda, array $extra = [])
    {
        $relativa = 'imported_files/moneda_oferta_' . uniqid() . '.xlsx';
        $absoluta = storage_path('app/' . $relativa);

        if (!is_dir(dirname($absoluta))) {
            mkdir(dirname($absoluta), 0777, true);
        }

        $this->excels_en_storage[] = $relativa;

        $columnas = [
            'codigo_de_barras'    => 0,
            'codigo_de_proveedor' => 1,
            'nombre'              => 2,
            'costo'               => 3,
        ];

        $encabezado = ['Barras', 'Codigo proveedor', 'Nombre', 'Costo'];

        if ($con_columna_de_moneda) {
            $columnas['moneda'] = 4;
            $encabezado[]       = 'Moneda';
        }

        $escritor = Writer::createXLSXWriter();
        $escritor->openToFile($absoluta);
        $escritor->addRow(Writer::createRowFromArray($encabezado));

        foreach ($filas as $fila) {
            $escritor->addRow(Writer::createRowFromArray($fila));
        }

        $escritor->close();

        $this->postJson('/api/ai-excel-import/import', array_merge([
            'excel_path'      => $relativa,
            'model'           => 'article',
            'columns'         => $columnas,
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
