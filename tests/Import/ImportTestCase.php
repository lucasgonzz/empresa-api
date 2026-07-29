<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Models\Article;
use App\Models\ImportConflict;
use App\Models\ImportHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Clase base de los tests de importación de Excel de artículos.
 *
 * Requisitos del entorno (ver README de la carpeta):
 *   - phpunit.xml apunta a la base `empresa_test`
 *   - `empresa_test` tiene el tenant de prueba (id = TENANT_USER_ID) SIN artículos
 *   - QUEUE_CONNECTION=sync, así el Bus::chain de la importación corre inline
 *     dentro del POST y no hace falta ningún queue:work
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class ImportTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Tenant de prueba: tiene que existir en `empresa_test` y no tener artículos propios. */
    const TENANT_USER_ID = 900;

    /** @var \App\Models\User */
    protected $tenant;

    /** @var array ['A' => Provider, 'B' => Provider, 'C' => Provider] */
    protected $providers = [];

    /** @var array ['A1' => Article, ... 'A15' => Article] */
    protected $seed = [];

    /** @var bool El motor se chequea una sola vez por proceso: la query es barata, pero no 15 veces. */
    protected static $motor_innodb_verificado = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verificar_motor_innodb();

        $this->tenant = User::find(self::TENANT_USER_ID);

        $this->assertNotNull(
            $this->tenant,
            'No existe el usuario de prueba id=' . self::TENANT_USER_ID . ' en la base de tests.'
        );

        /*
         * El índice de artículos se guarda EN CACHE y ADEMÁS en propiedades estáticas
         * del proceso PHP (ArticleIndexCache::$runtime_index_by_key / $runtime_loaded_by_key
         * / $runtime_dirty_by_key / $runtime_fake_articles / $ultimo_escalon). PHPUnit corre
         * todos los tests en el mismo proceso,
         * así que sin este reset el test N ve el índice que armó el test N-1 y los
         * resultados son basura silenciosa.
         */
        Cache::flush();
        ArticleIndexCache::reset_runtime_de_tests();

        $this->actingAs($this->tenant, 'web');

        $sembrado        = ImportTestSeeder::sembrar($this->tenant->id);
        $this->providers = $sembrado['providers'];
        $this->seed      = $sembrado['articulos'];
    }

    /**
     * Aborta la suite si alguna tabla de la base de tests no es InnoDB.
     *
     * No es paranoia: `empresa_test` se arma restaurando un dump y, si el MySQL local tiene
     * MyISAM como motor por defecto, las tablas quedan en MyISAM. Sobre MyISAM el BEGIN/ROLLBACK
     * de DatabaseTransactions es un no-op SILENCIOSO: nada se revierte, el test N arranca con los
     * artículos que dejó el N-1, y toda la suite pasa a medir basura sin que nada avise. Pasó de
     * verdad el 28/7/2026 (15 -> 30 -> 45 artículos en dos tests seguidos) y costó un grupo entero.
     *
     * @return void
     */
    protected function verificar_motor_innodb()
    {
        if (static::$motor_innodb_verificado) {
            return;
        }

        $base = DB::connection()->getDatabaseName();

        $no_innodb = DB::table('information_schema.tables')
                        ->where('table_schema', $base)
                        ->where('table_type', 'BASE TABLE')
                        ->whereNotNull('engine')
                        ->where('engine', '<>', 'InnoDB')
                        ->count();

        if ($no_innodb > 0) {
            $this->fail(
                'SEGURO: ' . $no_innodb . ' tabla(s) de la base "' . $base . '" no usan InnoDB. ' .
                'Asi, DatabaseTransactions no aisla nada entre tests y NINGUN resultado de esta ' .
                'suite vale. Fix: correr los ALTER TABLE ... ENGINE=InnoDB de la tarea (00) del ' .
                'prompt 03 del grupo 238 sobre esa base, y volver a intentar.'
            );
        }

        static::$motor_innodb_verificado = true;
    }

    protected function tearDown(): void
    {
        ArticleIndexCache::reset_runtime_de_tests();
        Cache::flush();

        parent::tearDown();
    }

    /**
     * Mapeo de columnas de los fixtures. El orden es FIJO y tiene que coincidir con
     * fixtures/gen_fixtures.py. Los valores son posiciones 1-based; GeneralHelper::
     * getImportColumns() las pasa a índices 0-based.
     *
     * @return array
     */
    public static function columnas()
    {
        return [
            'prop_codigo_de_barras'    => 1,
            'prop_sku'                 => 2,
            'prop_codigo_de_proveedor' => 3,
            'prop_nombre'              => 4,
            'prop_costo'               => 5,
            'prop_precio'              => 6,
            'prop_stock_actual'        => 7,
            'prop_iva'                 => 8,
        ];
    }

    /**
     * Configuración por defecto de la importación: la más conservadora, que es la
     * que el sistema recomienda cuando no hay nada raro en el archivo.
     *
     * @return array
     */
    public static function config_por_defecto()
    {
        return [
            'create_and_edit'                                   => true,
            'permitir_provider_code_repetido'                   => false,
            'permitir_provider_code_repetido_en_multi_providers' => true,
            'actualizar_articulos_de_otro_proveedor'            => false,
            'actualizar_por_provider_code'                      => true,
            'actualizar_proveedor'                              => true,
            'registrar_art_cre'                                 => true,
            'registrar_art_act'                                 => true,
        ];
    }

    /**
     * Dispara la importación de un fixture contra el endpoint real y devuelve el
     * ImportHistory resultante.
     *
     * @param  string $archivo  Nombre del archivo dentro de fixtures/
     * @param  array  $config   Overrides de configuración (y provider_id)
     * @return \App\Models\ImportHistory
     */
    protected function importar($archivo, array $config = [])
    {
        $origen = __DIR__ . '/fixtures/' . $archivo;

        $this->assertFileExists($origen, 'Falta el fixture ' . $archivo);

        /*
         * ArticleController@import hace $request->file('models')->storeAs(...), que
         * MUEVE el archivo. Si le pasáramos el fixture directo, el primer test que
         * corra se lo llevaría de la carpeta y el resto fallaría. Se importa siempre
         * sobre una copia temporal.
         */
        $copia = sys_get_temp_dir() . '/' . uniqid('fixture_') . '_' . $archivo;
        copy($origen, $copia);

        $data = array_merge(
            [
                'models' => new UploadedFile(
                    $copia,
                    $archivo,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
                'start_row'   => 2,
                /* InitExcelImport::ajustar_finish_row_segun_excel_real() lo baja al real. */
                'finish_row'  => 99999,
                'provider_id' => null,
            ],
            self::config_por_defecto(),
            self::columnas(),
            $config
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $import = ImportHistory::where('user_id', $this->tenant->id)
                                ->orderBy('id', 'DESC')
                                ->first();

        $this->assertNotNull($import, 'La importación no dejó ImportHistory.');

        $this->assertInvariantesDeConteo($import);

        return $import;
    }

    /**
     * Invariante que corre en TODAS las importaciones de todos los tests.
     *
     * ProcessRow clasifica cada fila en exactamente un bucket de $conteo_matching y
     * tiene un bucket 'sin_clasificar' como red de seguridad. Si alguien agrega un
     * camino nuevo en procesar() sin contarlo, esto lo caza en todos los tests a la vez.
     *
     * @param  \App\Models\ImportHistory $import
     * @return void
     */
    protected function assertInvariantesDeConteo($import)
    {
        $conteo = $this->conteo($import);

        $this->assertSame(
            0,
            isset($conteo['sin_clasificar']) ? (int) $conteo['sin_clasificar'] : 0,
            'Hubo filas que salieron de ProcessRow::procesar() por un camino no contemplado.'
        );

        $this->assertSame(
            (int) $import->filas_procesadas,
            array_sum(array_map('intval', $conteo)),
            'La suma de los buckets de conteo_matching no da el total de filas procesadas.'
        );
    }

    /**
     * @param  \App\Models\ImportHistory $import
     * @return array
     */
    protected function conteo($import)
    {
        $conteo = json_decode($import->matching_counts_json, true);

        return is_array($conteo) ? $conteo : [];
    }

    /**
     * Asserta los buckets indicados. Los buckets NO indicados se asertan en 0, para
     * que un test no pase de casualidad porque una fila cayó en un bucket inesperado.
     *
     * @param  \App\Models\ImportHistory $import
     * @param  array                     $esperados  ['provider_code' => 1, 'ambiguo' => 2, ...]
     * @return void
     */
    protected function assertBuckets($import, array $esperados)
    {
        $conteo = $this->conteo($import);

        foreach ($conteo as $bucket => $cantidad) {

            $esperado = isset($esperados[$bucket]) ? (int) $esperados[$bucket] : 0;

            $this->assertSame(
                $esperado,
                (int) $cantidad,
                'Bucket "' . $bucket . '": se esperaban ' . $esperado . ' filas y hubo ' . (int) $cantidad . '.'
            );
        }

        foreach ($esperados as $bucket => $cantidad) {
            $this->assertArrayHasKey(
                $bucket,
                $conteo,
                'El bucket esperado "' . $bucket . '" no existe en matching_counts_json.'
            );
        }
    }

    /**
     * @param  \App\Models\ImportHistory $import
     * @param  string                    $tipo  ambiguo | placeholder_descartado | sin_identificador | conflicto_numerico
     * @return int
     */
    protected function conflictos($import, $tipo)
    {
        return ImportConflict::where('import_history_id', $import->id)
                                ->where('tipo', $tipo)
                                ->count();
    }

    /**
     * Recarga un artículo del escenario desde la base.
     *
     * @param  string $clave  A1..A15
     * @return \App\Models\Article
     */
    protected function recargar($clave)
    {
        $this->assertArrayHasKey($clave, $this->seed, 'No existe el artículo sembrado ' . $clave);

        return Article::find($this->seed[$clave]->id);
    }

    /**
     * Artículos del tenant que NO estaban en el escenario sembrado, es decir, los
     * que creó la importación.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function articulos_creados()
    {
        $ids_sembrados = [];

        foreach ($this->seed as $article) {
            $ids_sembrados[] = $article->id;
        }

        return Article::where('user_id', $this->tenant->id)
                        ->whereNotIn('id', $ids_sembrados)
                        ->orderBy('id')
                        ->get();
    }

    /**
     * Compara decimales sin depender del formato con el que MySQL devuelve la columna
     * ("100.00" vs 100 vs "100.000000").
     *
     * @param  float|string|null $esperado
     * @param  float|string|null $real
     * @param  string            $mensaje
     * @return void
     */
    protected function assertDecimal($esperado, $real, $mensaje = '')
    {
        if (is_null($esperado)) {
            $this->assertNull($real, $mensaje);
            return;
        }

        $this->assertNotNull($real, $mensaje);
        $this->assertEquals(round((float) $esperado, 6), round((float) $real, 6), $mensaje);
    }
}
