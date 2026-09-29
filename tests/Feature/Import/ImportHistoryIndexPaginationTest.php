<?php

namespace Tests\Feature\Import;

use App\Models\ArticleImportResult;
use App\Models\ArticleImportResultObservation;
use App\Models\ImportHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `ImportHistoryController::index()` paginado y sin relaciones (misión
 * importacion-lento-vender-y-historial, 28/9/2026).
 *
 * EL AGUJERO QUE TAPA
 * -------------------
 * Antes traía las últimas 10 CON `chunks.article_import_result_observations` sin
 * ningún límite. En producción de Servian eso midió consultas de hasta 118.000
 * filas / 113 MB durante una importación en curso -- el mismo endpoint que la
 * pantalla de "Historial de importaciones" pega apenas se abre el modal, compitiendo
 * por conexiones con la importación que el usuario está mirando avanzar. Este test
 * cubre las tres garantías que reemplazan ese comportamiento: pagina de a 5, no trae
 * ninguna relación anidada (ni `chunks` ni sus `article_import_result_observations`,
 * ver medición aparte que confirma que 587 chunks reales alcanzan para agotar la
 * memoria default de un worker), y los cálculos por fila (`can_revert`,
 * `matching_counts_json` decodificado) siguen viniendo igual que antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class ImportHistoryIndexPaginationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'         => 'Comercio historial import',
            'company_name' => 'Ferreteria historial import',
            'email'        => 'historial-import-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->actingAs($this->comercio, 'web');
    }

    /**
     * @param  array  $overrides
     * @return ImportHistory
     */
    protected function crear_historial(array $overrides = [])
    {
        return ImportHistory::create(array_merge([
            'user_id'    => $this->comercio->id,
            'model_name' => 'article',
            'status'     => 'terminado',
        ], $overrides));
    }

    /**
     * Pagina de a 5: con 7 historiales, la página 1 trae 5 y la página 2 trae 2, y
     * el bloque `pagination` informa el total real (7), no el de la página.
     *
     * @return void
     */
    public function test_pagina_de_a_5()
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->crear_historial();
        }

        $pagina_1 = $this->getJson('/api/import-history/article')->assertStatus(200)->json();

        $this->assertCount(5, $pagina_1['models']);
        $this->assertSame(1, $pagina_1['pagination']['current_page']);
        $this->assertSame(2, $pagina_1['pagination']['last_page']);
        $this->assertSame(7, $pagina_1['pagination']['total']);
        $this->assertSame(5, $pagina_1['pagination']['per_page']);

        $pagina_2 = $this->getJson('/api/import-history/article?page=2')->assertStatus(200)->json();

        $this->assertCount(2, $pagina_2['models']);
        $this->assertSame(2, $pagina_2['pagination']['current_page']);
        $this->assertSame(7, $pagina_2['pagination']['total']);
    }

    /**
     * Un historial con chunks y observaciones reales (el caso que rompía en
     * producción) no trae esas relaciones en la respuesta: ni la key `chunks`, ni
     * `article_import_result_observations` anidada en ningún lado del payload.
     *
     * @return void
     */
    public function test_no_trae_chunks_ni_observaciones_anidadas()
    {
        $historial = $this->crear_historial();

        $chunk = ArticleImportResult::create([
            'import_history_id' => $historial->id,
            'chunk_number'       => 1,
            'created_count'      => 3,
            'updated_count'      => 2,
        ]);

        ArticleImportResultObservation::create([
            'article_import_result_id' => $chunk->id,
            'fila'                      => 1,
            'procesos'                  => json_encode(['accion' => 'match']),
        ]);

        $response = $this->getJson('/api/import-history/article')->assertStatus(200)->json();

        $this->assertCount(1, $response['models']);

        $fila = $response['models'][0];

        $this->assertArrayNotHasKey('chunks', $fila);
        $this->assertStringNotContainsString('article_import_result_observations', json_encode($response));
    }

    /**
     * El mismo request no vuelve a traer relaciones vía `->with(...)`: el conteo de
     * consultas se mantiene fijo (no depende de cuántos chunks u observaciones tenga
     * cada importación) y **tampoco** admite las 1-2 consultas extra que agregaría
     * reintroducir `->with('chunks')` (aunque sea SIN `.article_import_result_observations`).
     *
     * 🔴 El tope tiene que ser el número exacto medido, no un "tope generoso": un
     * `->with('chunks')` es eager loading (dos consultas fijas por relación, un
     * `WHERE IN`), no un N+1 clásico que escale con la cantidad de filas -- así que
     * un tope laxo tipo 10 NO detecta que alguien reintrodujo la relación. Medido en
     * esta misma suite (chequeo independiente, 28/9/2026): sin relaciones, **3**
     * consultas (paginate + count + el auth de Sanctum); reintroduciendo
     * `->with('chunks')`, **4**. El tope de abajo tiene que matar ese mutante.
     *
     * @return void
     */
    public function test_no_ejecuta_una_consulta_por_historial()
    {
        for ($i = 1; $i <= 5; $i++) {
            $historial = $this->crear_historial();

            $chunk = ArticleImportResult::create([
                'import_history_id' => $historial->id,
                'chunk_number'       => 1,
            ]);

            ArticleImportResultObservation::create([
                'article_import_result_id' => $chunk->id,
                'fila'                      => 1,
                'procesos'                  => json_encode(['accion' => 'match']),
            ]);
        }

        DB::enableQueryLog();

        $this->getJson('/api/import-history/article')->assertStatus(200);

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            3,
            $consultas,
            'El índice de historial parece estar volviendo a cargar relaciones (with/eager-load).'
        );
    }

    /**
     * `can_revert` y `matching_counts_json` se siguen calculando por fila, igual
     * que antes de paginar: uno se puede revertir (sin rollback), el otro no
     * (ya revertido), y el JSON guardado como texto plano llega decodificado.
     *
     * @return void
     */
    public function test_can_revert_y_matching_counts_siguen_viniendo_bien()
    {
        $revertible = $this->crear_historial([
            'matching_counts_json' => json_encode(['exacto' => 3, 'aproximado' => 1]),
        ]);

        $revertida = $this->crear_historial([
            'rollback_status' => 'revertida',
            'rolled_back_at'  => now(),
        ]);

        $response = $this->getJson('/api/import-history/article')->assertStatus(200)->json();

        $fila_revertible = collect($response['models'])->firstWhere('id', $revertible->id);
        $fila_revertida  = collect($response['models'])->firstWhere('id', $revertida->id);

        $this->assertTrue($fila_revertible['can_revert']);
        $this->assertSame(['exacto' => 3, 'aproximado' => 1], $fila_revertible['matching_counts_json']);

        $this->assertFalse($fila_revertida['can_revert']);
    }
}
