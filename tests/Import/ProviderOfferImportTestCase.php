<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\excel\OpenSpoutCompatible as Writer;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * Base de los tests de la ronda 2 de la misión historial-precios-proveedor que importan por el endpoint
 * real del importador de 4 pasos (`POST /api/ai-excel-import/import`): dos proveedores del tenant
 * ("BA" y "Rosario"), un Excel [barras, código de proveedor, nombre, costo(, moneda)] en storage como
 * lo deja /analyze y las ofertas de `provider_price_offers`.
 *
 * IMPORTANTE: PHP 7.4 estricto.
 */
abstract class ProviderOfferImportTestCase extends ImportTestCase
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

        $this->ba      = $this->crear_proveedor('BA ronda 2');
        $this->rosario = $this->crear_proveedor('Rosario ronda 2');
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
    protected function importar_por_ia(array $filas, $provider_id, $con_columna_de_moneda = false, array $extra = [])
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
