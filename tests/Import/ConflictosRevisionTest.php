<?php

namespace Tests\Import;

use App\Models\ImportConflict;

/**
 * El contrato del endpoint `GET /api/import-history/{id}/conflicts` (grupo 287,
 * prompt 05): la unica herramienta que tiene el usuario para revisar a mano lo que
 * una importacion no pudo resolver sola. El grupo 278 le puso texto entendible a
 * cada tipo en el SPA, pero nadie testeaba el contrato del backend que ese texto
 * consume.
 *
 * No usa fixtures nuevos: `07_repetidos_en_el_archivo.xlsx` (sobrescrituras +
 * repetidos, ya cubierto por RepetidosEnElArchivoTest para el LADO de la
 * importacion) y `08_match_unico_provider_code.xlsx` (F3 deja un
 * identificador_sin_asignar real sobre PC-DUP, ya cubierto por
 * MatchUnicoProviderCodeTest para el LADO de la importacion). Este archivo cubre
 * el LADO del endpoint: forma de la respuesta, scoping y supervivencia al rollback.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class ConflictosRevisionTest extends ImportTestCase
{
    /**
     * Pega al endpoint de conflictos de una importacion.
     *
     * @param  int $import_history_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function conflictos_response($import_history_id)
    {
        return $this->getJson('/api/import-history/' . $import_history_id . '/conflicts');
    }

    /* ------------------------------------------------------------------
     * 1 - Forma de un conflicto identificador_sin_asignar
     * ------------------------------------------------------------------ */

    /**
     * F3 de 08_match_unico_provider_code.xlsx (PC-DUP, matchea A3 y A4) deja un
     * conflicto identificador_sin_asignar sobre el sku de esa fila. El SPA (grupo
     * 278) cuenta "coincidia con 2 articulos" leyendo article_ids como array: si
     * deja de venir como array, muestra basura sin que nada falle.
     *
     * @return void
     */
    public function test_cada_conflicto_llega_con_los_campos_que_el_historial_necesita()
    {
        $import = $this->importar('08_match_unico_provider_code.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
        ]);

        $response = $this->conflictos_response($import->id);
        $response->assertStatus(200);

        $conflictos = collect($response->json('conflicts'));
        $conflicto  = $conflictos->firstWhere('tipo', 'identificador_sin_asignar');

        $this->assertNotNull($conflicto, 'Tiene que venir el conflicto identificador_sin_asignar de F3.');

        $this->assertIsInt($conflicto['fila']);
        $this->assertSame('sku', $conflicto['campo']);
        $this->assertSame('SKU-MATCH-MULTIPLE', $conflicto['valor'], 'El sku descartado de F3 (leido de generar.php).');

        $a3 = $this->recargar('A3');
        $a4 = $this->recargar('A4');

        $this->assertIsArray($conflicto['article_ids'], 'article_ids tiene que llegar como array, no como escalar ni como string serializado.');
        $this->assertContainsOnly('int', $conflicto['article_ids'], true, 'Cada id tiene que ser un entero, no un string.');
        $this->assertEqualsCanonicalizing(
            [$a3->id, $a4->id],
            $conflicto['article_ids'],
            'article_ids tiene que traer exactamente los ids de A3 y A4, los dos candidatos de PC-DUP.'
        );
    }

    /* ------------------------------------------------------------------
     * 2 - fila_sobrescrita trae la fila ganadora
     * ------------------------------------------------------------------ */

    /**
     * No re-assertea cuantas sobrescrituras hay ni entre que filas (eso ya lo fija
     * RepetidosEnElArchivoTest): solo que la FORMA del conflicto que el endpoint
     * expone es la que el SPA necesita para mostrar "la fila X quedo pisada por la
     * fila Y".
     *
     * @return void
     */
    public function test_fila_sobrescrita_trae_la_fila_ganadora()
    {
        $import = $this->importar('07_repetidos_en_el_archivo.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'      => 'ultima_gana',
        ]);

        $response = $this->conflictos_response($import->id);
        $response->assertStatus(200);

        $sobrescrituras = collect($response->json('conflicts'))
            ->where('tipo', 'fila_sobrescrita');

        $this->assertGreaterThan(0, $sobrescrituras->count(), 'Este fixture tiene que dejar al menos una sobrescritura.');

        foreach ($sobrescrituras as $conflicto) {
            $this->assertIsInt($conflicto['fila_ganadora'], 'fila_ganadora tiene que ser numerica.');
            $this->assertGreaterThan(
                $conflicto['fila'],
                $conflicto['fila_ganadora'],
                'La fila ganadora tiene que ser posterior a la fila pisada.'
            );
        }
    }

    /* ------------------------------------------------------------------
     * 3 - El resumen agrupa por tipo y campo
     * ------------------------------------------------------------------ */

    /**
     * Invariante barata que detecta un resumen desalineado sin acoplarse a los
     * numeros concretos del fixture: la suma de los `total` del resumen tiene que
     * dar exactamente la cantidad de conflictos de la lista.
     *
     * @return void
     */
    public function test_el_resumen_agrupa_por_tipo_y_campo()
    {
        $import = $this->importar('07_repetidos_en_el_archivo.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'      => 'ultima_gana',
        ]);

        $response = $this->conflictos_response($import->id);
        $response->assertStatus(200);

        $data = $response->json();

        $this->assertArrayHasKey('resumen', $data);
        $this->assertIsArray($data['resumen']);
        $this->assertNotEmpty($data['resumen'], 'Este fixture tiene que dejar al menos un conflicto para resumir.');

        $suma_resumen = array_sum(array_column($data['resumen'], 'total'));

        $this->assertSame(
            count($data['conflicts']),
            $suma_resumen,
            'La suma de los totales del resumen tiene que coincidir con la cantidad de conflictos de la lista.'
        );
    }

    /* ------------------------------------------------------------------
     * 4 - Scoping por importacion
     * ------------------------------------------------------------------ */

    /**
     * Dos importaciones de fixtures distintos en el mismo test: el endpoint de
     * cada import_history_id devuelve SOLO los conflictos de esa importacion.
     *
     * @return void
     */
    public function test_los_conflictos_quedan_scopeados_por_importacion()
    {
        $import_08 = $this->importar('08_match_unico_provider_code.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
        ]);

        $import_07 = $this->importar('07_repetidos_en_el_archivo.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'      => 'ultima_gana',
        ]);

        $conflictos_08 = collect($this->conflictos_response($import_08->id)->assertStatus(200)->json('conflicts'));
        $conflictos_07 = collect($this->conflictos_response($import_07->id)->assertStatus(200)->json('conflicts'));

        $this->assertTrue(
            $conflictos_08->contains('tipo', 'identificador_sin_asignar'),
            'La respuesta del 08 tiene que traer su propio identificador_sin_asignar.'
        );
        $this->assertFalse(
            $conflictos_07->contains('tipo', 'identificador_sin_asignar'),
            'El identificador_sin_asignar del 08 no tiene que aparecer en la respuesta del 07.'
        );

        $this->assertTrue(
            $conflictos_07->contains('tipo', 'fila_sobrescrita'),
            'La respuesta del 07 tiene que traer sus propias sobrescrituras.'
        );
        $this->assertFalse(
            $conflictos_08->contains('tipo', 'fila_sobrescrita'),
            'Las sobrescrituras del 07 no tienen que aparecer en la respuesta del 08.'
        );
    }

    /* ------------------------------------------------------------------
     * 5 - Los conflictos sobreviven al rollback
     * ------------------------------------------------------------------ */

    /**
     * El rollback restaura articulos y stock, pero el conflicto es el REGISTRO de
     * que esa fila tuvo un problema: borrarlo dejaria al usuario sin la unica
     * evidencia de que tiene algo que revisar a mano. Hoy el rollback no toca
     * `import_conflicts`; este test hace que cualquier cambio en eso sea una
     * decision consciente.
     *
     * Nota sobre el status code: el prompt esperaba 200, pero
     * ImportHistoryController::rollback() encola el job (RollbackArticleImportHistory::dispatch())
     * y responde 202 (queued), no 200 -- confirmado contra el propio controller y
     * contra RollbackTest::test_el_rollback_deja_todo_exactamente_como_estaba(), que
     * ya usa assertStatus(202). Con QUEUE_CONNECTION=sync el job corre inline antes
     * de que el request termine, asi que el efecto (restaurar) ya esta aplicado
     * cuando se vuelve a pedir el endpoint de conflictos mas abajo.
     *
     * @return void
     */
    public function test_los_conflictos_sobreviven_al_rollback()
    {
        $import = $this->importar('08_match_unico_provider_code.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
        ]);

        $antes = collect($this->conflictos_response($import->id)->assertStatus(200)->json('conflicts'));

        $this->assertGreaterThan(0, $antes->count(), 'Este fixture tiene que dejar al menos un conflicto antes del rollback.');

        $this->postJson('/api/import-history/rollback/' . $import->id)->assertStatus(202);

        $despues = collect($this->conflictos_response($import->id)->assertStatus(200)->json('conflicts'));

        $this->assertSame(
            $antes->count(),
            $despues->count(),
            'El rollback no puede borrar ningun import_conflict: son el registro de lo que paso, no parte de lo revertible.'
        );

        $this->assertSame(
            $antes->pluck('id')->sort()->values()->all(),
            $despues->pluck('id')->sort()->values()->all(),
            'Tienen que ser los MISMOS registros (mismos ids), no unos nuevos equivalentes.'
        );
    }

    /* ------------------------------------------------------------------
     * 6 - El endpoint no expone conflictos ajenos
     * ------------------------------------------------------------------ */

    /**
     * Pedir los conflictos de un import_history_id inexistente: 404 con el
     * mensaje del controller, sin lista.
     *
     * @return void
     */
    public function test_el_endpoint_no_expone_conflictos_ajenos()
    {
        $import = $this->importar('08_match_unico_provider_code.xlsx', [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
        ]);

        $response = $this->conflictos_response($import->id + 99999);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'No se encontro la importacion']);
        $this->assertArrayNotHasKey('conflicts', $response->json(), 'La respuesta 404 no tiene que traer ninguna lista de conflictos.');
    }
}
