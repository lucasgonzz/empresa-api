<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\Article;
use App\Models\ImportConflict;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El índice de identificación acotado al archivo (misión `importacion-excel-motor-rapido`,
 * 24/9/2026) deja la base EXACTAMENTE igual que el índice completo de siempre.
 *
 * Hasta esta misión ArticleIndexCache::build() indexaba y serializaba el catálogo entero del
 * comercio en cada lote (568.000 artículos en Servian: ~100 MB por operación). Ahora, con el
 * contexto que pone ProcessArticleChunk, se indexan sólo los artículos que alguna fila del
 * archivo puede matchear (<csv>.claves, escrito por InitExcelImport). Lo que este test fija es
 * que eso no cambia NADA de lo que la importación deja: cada fixture se importa dos veces
 * sobre el mismo escenario sembrado —una forzando el índice completo, otra con el acotado—
 * y se comparan las fotos de `articles`, `stock_movements`, los pivots, `import_conflicts` y
 * los contadores del historial (incluido matching_counts_json).
 *
 * Las dos importaciones corren dentro de un SAVEPOINT (DB::beginTransaction() anidado en el
 * DatabaseTransactions de la clase base) y se revierten, así las dos parten del mismo estado.
 * Como los ids de los artículos creados difieren entre corridas (auto_increment), la foto se
 * normaliza por clave natural (provider_code|bar_code|sku|name) y sin las columnas volátiles.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class IndiceAcotadoAlArchivoTest extends ImportTestCase
{
    /** Columnas de `articles` que cambian de corrida a corrida sin que cambie el resultado. */
    const VOLATILES = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'chunk_number',
        'final_price_updated_at', 'stock_updated_at', 'num',
    ];

    /**
     * Escenarios: cada uno ejercita otra parte de la cadena (placeholders, códigos numéricos,
     * nombres repetidos y cinco lotes en Servian; proveedor elegido con códigos repetidos y
     * cruzados en 01; stock global y por diferencia en 04; herencia de identificadores en
     * cascada en 09).
     *
     * @return array
     */
    public function escenarios()
    {
        return [
            'servian, cinco lotes de 10, sin proveedor' => [
                '06_incidente_servian.xlsx',
                ['app.ARTICLE_EXCEL_CHUNK_SIZE' => 10],
                [],
            ],
            'codigos de proveedor, proveedor A, repetidos permitidos' => [
                '01_codigos_de_proveedor.xlsx',
                [],
                ['provider' => 'A', 'permitir_provider_code_repetido' => true],
            ],
            'codigos de proveedor, sin proveedor, otro proveedor actualizable' => [
                '01_codigos_de_proveedor.xlsx',
                [],
                ['actualizar_articulos_de_otro_proveedor' => true],
            ],
            'stock, sin proveedor' => [
                '04_stock.xlsx',
                [],
                [],
            ],
            'cascada de herencia' => [
                '09_cascada_herencia.xlsx',
                [],
                [],
            ],
        ];
    }

    /**
     * @dataProvider escenarios
     *
     * @param  string $archivo
     * @param  array  $config_app  claves de config() a fijar antes de importar
     * @param  array  $config      overrides de la importación ('provider' => 'A' se resuelve al id sembrado)
     * @return void
     */
    public function test_el_indice_acotado_deja_la_base_igual_que_el_indice_completo($archivo, array $config_app, array $config)
    {
        if (isset($config['provider'])) {
            $config['provider_id'] = $this->providers[$config['provider']]->id;
            unset($config['provider']);
        }

        foreach ($config_app as $clave => $valor) {
            config([$clave => $valor]);
        }

        $completo = $this->importar_en_modo('completo', $archivo, $config);
        $acotado  = $this->importar_en_modo('acotado', $archivo, $config);

        /* Guarda: una importación que no hace nada haría pasar la comparación de casualidad. */
        $this->assertGreaterThan(
            0,
            (int) $completo['historial']['created_models'] + (int) $completo['historial']['updated_models'],
            'El fixture ' . $archivo . ' no creó ni actualizó nada: el test no compara nada.'
        );

        /* Sección por sección: si algo difiere, el diff dice DÓNDE en vez de volcar la foto entera. */
        foreach (array_keys($completo) as $seccion) {
            $this->assertEquals(
                $completo[$seccion],
                $acotado[$seccion],
                'Importar ' . $archivo . ' con el índice acotado al archivo no dejó igual la sección "' . $seccion
                    . '" que con el índice completo.'
            );
        }
    }

    /** @var array archivos temporales a borrar al terminar cada test */
    protected $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        $this->temporales = [];

        parent::tearDown();
    }

    /**
     * Como ImportTestCase::importar(), pero mandando el archivo ya guardado en imported_files
     * con un nombre único (archivo_excel_path) en vez de subirlo como `models`.
     *
     * Por qué: ArticleController@import guarda lo subido como imported_files/import_<time()>.xlsx,
     * un nombre de resolución de UN SEGUNDO que comparten todas las importaciones del worktree.
     * Con otras suites corriendo a la vez sobre el mismo storage/ (los cuatro constructores de
     * la misión, en su momento), dos importaciones del mismo segundo se pisan el XLSX y el CSV,
     * y este test compararía dos importaciones de archivos distintos. Con nombre único no hay
     * con quién chocar.
     *
     * @param  string $archivo  nombre del fixture
     * @param  array  $config
     * @return \App\Models\ImportHistory
     */
    protected function importar_con_nombre_unico($archivo, array $config)
    {
        $origen = __DIR__ . '/fixtures/' . $archivo;

        $this->assertFileExists($origen, 'Falta el fixture ' . $archivo);

        $carpeta = storage_path('app/imported_files');

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $nombre  = uniqid('indice_acotado_') . '.xlsx';
        $destino = $carpeta . '/' . $nombre;

        copy($origen, $destino);

        $this->temporales[] = $destino;

        foreach (['.hoja0.csv', '.hoja0.tipos', '.hoja0.json'] as $sufijo) {
            $this->temporales[] = $destino . $sufijo;
        }

        $data = array_merge(
            [
                'archivo_excel_path' => 'imported_files/' . $nombre,
                'start_row'          => 2,
                'finish_row'         => 99999,
                'provider_id'        => null,
            ],
            self::config_por_defecto(),
            self::columnas(),
            $config
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        foreach (glob($carpeta . '/' . pathinfo($nombre, PATHINFO_FILENAME) . '_*.csv') ?: [] as $csv) {
            $this->temporales[] = $csv;
            $this->temporales[] = $csv . '.claves';
        }

        $import = \App\Models\ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import, 'La importación no dejó ImportHistory.');

        $this->assertInvariantesDeConteo($import);

        return $import;
    }

    /**
     * El índice acotado contiene todo lo que el archivo puede matchear y nada que ningún
     * lote vaya a mirar: con el fixture de Servian (que no matchea nada sembrado) el índice
     * arranca sin artículos, y con el 01 arranca sólo con los sembrados que el archivo nombra.
     *
     * @return void
     */
    public function test_el_indice_acotado_solo_trae_lo_que_el_archivo_puede_matchear()
    {
        $modos = [];

        /* Se captura el índice al momento del build, antes de que los lotes lo modifiquen. */
        \Illuminate\Support\Facades\Log::listen(function ($evento) use (&$modos) {
            if (strpos($evento->message, 'ArticleIndexCache::build acotado al archivo') !== false) {
                $modos[] = $evento->context;
            }
        });

        $this->importar_en_modo('acotado', '01_codigos_de_proveedor.xlsx', [
            'provider_id' => $this->providers['A']->id,
        ]);

        $this->assertNotEmpty($modos, 'No se registró ningún build acotado.');

        $ultimo = end($modos);

        /*
         * El 01 nombra PC-100 (A1), PC-DUP (A3 y A4), PC-CROSS (A5 y A6), PC-1500 (A15) y
         * PC-NUEVO (nadie); los placeholders S/N y - se descartan antes de llegar al índice.
         * Ningún nombre del archivo existe en la base. Son 6 artículos de los 15 sembrados.
         */
        $this->assertSame(6, (int) $ultimo['articulos']);
        $this->assertSame(5, (int) $ultimo['claves']['provider_codes']);
        $this->assertSame(0, (int) $ultimo['claves']['bar_codes']);
    }

    /**
     * Importa el fixture con el índice en el modo pedido, dentro de un savepoint que se
     * revierte al salir, y devuelve la foto normalizada del resultado.
     *
     * @param  string $modo     'completo' | 'acotado'
     * @param  string $archivo
     * @param  array  $config
     * @return array
     */
    protected function importar_en_modo($modo, $archivo, array $config)
    {
        DB::beginTransaction();

        try {
            Cache::flush();
            ArticleIndexCache::reset_runtime_de_tests();
            ArticleIndexCache::forzar_indice_completo_de_tests($modo === 'completo');

            $import = $this->importar_con_nombre_unico($archivo, $config);

            $this->assertSame(
                $modo,
                ArticleIndexCache::ultimo_modo_de_build(),
                'La importación tenía que construir el índice en modo ' . $modo . '.'
            );

            return $this->foto($import);
        } finally {
            ArticleIndexCache::forzar_indice_completo_de_tests(false);

            DB::rollBack();

            Cache::flush();
            ArticleIndexCache::reset_runtime_de_tests();
        }
    }

    /**
     * Foto normalizada de lo que dejó una importación en la base.
     *
     * @param  \App\Models\ImportHistory $import
     * @return array
     */
    protected function foto($import)
    {
        $articulos = Article::where('user_id', $this->tenant->id)->orderBy('id')->get();

        $clave_por_id = [];
        $filas        = [];

        foreach ($articulos as $articulo) {
            $atributos = $articulo->getAttributes();

            foreach (self::VOLATILES as $volatil) {
                unset($atributos[$volatil]);
            }

            $clave = implode('|', [$articulo->provider_code, $articulo->bar_code, $articulo->sku, $articulo->name]);

            $clave_por_id[(int) $articulo->id] = $clave;

            $filas[$clave][] = $atributos;
        }

        ksort($filas);

        foreach ($filas as $clave => $lista) {
            $filas[$clave] = $this->ordenar($lista);
        }

        $ids = $articulos->pluck('id')->all();

        $movimientos = [];

        foreach (DB::table('stock_movements')->whereIn('article_id', $ids)->orderBy('id')->get() as $m) {
            $movimientos[] = [
                'articulo'         => $clave_por_id[(int) $m->article_id],
                'amount'           => $m->amount,
                'stock_resultante' => $m->stock_resultante,
                'concepto'         => $m->concepto_stock_movement_id,
                'to_address_id'    => $m->to_address_id,
                'observations'     => $m->observations,
            ];
        }

        $pivots = [];

        foreach (['address_article', 'article_provider', 'article_price_type', 'article_discounts', 'article_surchages'] as $tabla) {
            $pivots[$tabla] = [];

            foreach (DB::table($tabla)->whereIn('article_id', $ids)->orderBy('article_id')->get() as $fila) {
                $fila = (array) $fila;

                unset($fila['id'], $fila['created_at'], $fila['updated_at']);

                $fila['article_id'] = $clave_por_id[(int) $fila['article_id']];

                $pivots[$tabla][] = $fila;
            }

            $pivots[$tabla] = $this->ordenar($pivots[$tabla]);
        }

        $conflictos = [];

        foreach (ImportConflict::where('import_history_id', $import->id)->orderBy('id')->get() as $conflicto) {
            $article_ids = is_array($conflicto->article_ids) ? $conflicto->article_ids : (array) json_decode((string) $conflicto->article_ids, true);

            $conflictos[] = [
                'tipo'          => $conflicto->tipo,
                'campo'         => $conflicto->campo,
                'valor'         => $conflicto->valor,
                'fila'          => (int) $conflicto->fila,
                'fila_ganadora' => is_null($conflicto->fila_ganadora) ? null : (int) $conflicto->fila_ganadora,
                'nombre_excel'  => $conflicto->nombre_excel,
                'article_ids'   => array_map(function ($id) use ($clave_por_id) {
                    return isset($clave_por_id[(int) $id]) ? $clave_por_id[(int) $id] : (int) $id;
                }, $article_ids),
            ];
        }

        $import->refresh();

        return [
            'articulos'   => $filas,
            'movimientos' => $this->ordenar($movimientos),
            'pivots'      => $pivots,
            'conflictos'  => $this->ordenar($conflictos),
            'historial'   => [
                'created_models'       => (int) $import->created_models,
                'updated_models'       => (int) $import->updated_models,
                'articles_match'       => (int) $import->articles_match,
                'articles_repetidos'   => (int) $import->articles_repetidos,
                'filas_procesadas'     => (int) $import->filas_procesadas,
                'conflicts_count'      => (int) $import->conflicts_count,
                'total_chunks'         => (int) $import->total_chunks,
                'status'               => $import->status,
                'matching_counts_json' => json_decode((string) $import->matching_counts_json, true),
            ],
        ];
    }

    /**
     * Orden determinista para listas de filas (por su JSON).
     *
     * @param  array $lista
     * @return array
     */
    protected function ordenar(array $lista)
    {
        usort($lista, function ($a, $b) {
            return strcmp(json_encode($a), json_encode($b));
        });

        return $lista;
    }
}
