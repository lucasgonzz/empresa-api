<?php

namespace Tests\Feature\Import;

use App\Models\ArticleImportResult;
use App\Models\ArticleImportResultObservation;
use App\Models\ImportHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `ImportHistoryController::chunks()` paginado (misión de seguimiento de
 * importacion-lento-vender-y-historial, 28/9/2026).
 *
 * EL AGUJERO QUE TAPA
 * -------------------
 * `chunks()` (el endpoint del modal "Lotes") traía TODOS los ArticleImportResult de un
 * historial de una sola vez, con `with('article_import_result_observations')` sin
 * ningún límite. La misión original que paginó `index()` midió, sobre este mismo
 * endpoint, que 587 chunks x 200 observaciones (~117.400 filas, el mismo volumen del
 * slow log real de Servian) agotan el memory_limit de 128MB de un worker default. Este
 * test cubre las garantías que reemplazan ese comportamiento: pagina de a 20, cada
 * página sigue trayendo las observaciones de SUS chunks (para que el botón "Filas"
 * siga andando), el orden es estable entre páginas, y una importación ajena sigue sin
 * poder consultarse.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class ImportHistoryChunksPaginationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'         => 'Comercio chunks import',
            'company_name' => 'Ferreteria chunks import',
            'email'        => 'chunks-import-' . uniqid() . '@test.local',
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
     * @param  int    $import_history_id
     * @param  int    $chunk_number
     * @param  array  $overrides
     * @return ArticleImportResult
     */
    protected function crear_chunk($import_history_id, $chunk_number, array $overrides = [])
    {
        $chunk = ArticleImportResult::create(array_merge([
            'import_history_id' => $import_history_id,
            'chunk_number'       => $chunk_number,
            'created_count'      => 1,
            'updated_count'      => 1,
        ], $overrides));

        ArticleImportResultObservation::create([
            'article_import_result_id' => $chunk->id,
            'fila'                      => 1,
            'procesos'                  => json_encode(['accion' => 'match']),
        ]);

        return $chunk;
    }

    /**
     * Pagina de a 20: con 25 chunks, la página 1 trae 20 y la página 2 trae 5, y el
     * bloque `pagination` informa el total real (25), no el de la página.
     *
     * @return void
     */
    public function test_pagina_de_a_20()
    {
        $historial = $this->crear_historial();

        for ($i = 1; $i <= 25; $i++) {
            $this->crear_chunk($historial->id, $i);
        }

        $pagina_1 = $this->getJson('/api/import-history/chunks/' . $historial->id)
                        ->assertStatus(200)->json();

        $this->assertCount(20, $pagina_1['models']);
        $this->assertSame(1, $pagina_1['pagination']['current_page']);
        $this->assertSame(2, $pagina_1['pagination']['last_page']);
        $this->assertSame(25, $pagina_1['pagination']['total']);
        $this->assertSame(20, $pagina_1['pagination']['per_page']);

        $pagina_2 = $this->getJson('/api/import-history/chunks/' . $historial->id . '?page=2')
                        ->assertStatus(200)->json();

        $this->assertCount(5, $pagina_2['models']);
        $this->assertSame(2, $pagina_2['pagination']['current_page']);
        $this->assertSame(25, $pagina_2['pagination']['total']);
    }

    /**
     * El orden es estable por `chunk_number`: la página 1 trae los lotes 1 a 20 y la
     * página 2 los lotes 21 a 25, sin salteos ni superposición entre páginas.
     *
     * @return void
     */
    public function test_orden_estable_entre_paginas()
    {
        $historial = $this->crear_historial();

        for ($i = 1; $i <= 25; $i++) {
            $this->crear_chunk($historial->id, $i);
        }

        $pagina_1 = $this->getJson('/api/import-history/chunks/' . $historial->id)
                        ->assertStatus(200)->json();
        $pagina_2 = $this->getJson('/api/import-history/chunks/' . $historial->id . '?page=2')
                        ->assertStatus(200)->json();

        $numeros_pagina_1 = collect($pagina_1['models'])->pluck('chunk_number')->all();
        $numeros_pagina_2 = collect($pagina_2['models'])->pluck('chunk_number')->all();

        $this->assertSame(range(1, 20), $numeros_pagina_1);
        $this->assertSame(range(21, 25), $numeros_pagina_2);
    }

    /**
     * Cada chunk de la página actual sigue trayendo sus
     * `article_import_result_observations` (el botón "Filas" de chunks/Index.vue las
     * lee ya cargadas en memoria, sin pedirlas aparte) -- lo único que cambió es que
     * ya no se traen TODOS los chunks del historial de una sola vez.
     *
     * @return void
     */
    public function test_la_pagina_trae_las_observaciones_de_sus_chunks()
    {
        $historial = $this->crear_historial();
        $this->crear_chunk($historial->id, 1);
        $this->crear_chunk($historial->id, 2);

        $response = $this->getJson('/api/import-history/chunks/' . $historial->id)
                        ->assertStatus(200)->json();

        $this->assertCount(2, $response['models']);

        foreach ($response['models'] as $fila) {
            $this->assertArrayHasKey('article_import_result_observations', $fila);
            $this->assertCount(1, $fila['article_import_result_observations']);
        }
    }

    /**
     * Un historial ajeno sigue devolviendo 404, sin exponer sus chunks (grupo 240,
     * prompt 01) -- la paginación no debilitó el chequeo de dueño.
     *
     * @return void
     */
    public function test_no_expone_chunks_de_un_historial_ajeno()
    {
        $otro_comercio = User::create([
            'name'         => 'Otro comercio',
            'company_name' => 'Otra ferreteria',
            'email'        => 'otro-comercio-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $historial_ajeno = $this->crear_historial(['user_id' => $otro_comercio->id]);
        $this->crear_chunk($historial_ajeno->id, 1);

        $this->getJson('/api/import-history/chunks/' . $historial_ajeno->id)
            ->assertStatus(404);
    }
}
