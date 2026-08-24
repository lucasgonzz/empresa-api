<?php

namespace Tests\Feature\Busqueda;

/**
 * Tests de filtros de columna en `globalSearch` (Grupo 273, Prompt 02).
 *
 * Bug real reportado por Lucas el 30/7/2026: en el listado de articulos, filtrar por la columna
 * Proveedor devuelve TODOS los articulos, de todos los proveedores. Causa: `globalSearch()` nunca
 * leia `filters`, y el frontend manda ahi todos los filtrados del listado por defecto (el listado
 * dejo de usar `search()` — ver prompt 03 de este grupo para el detalle del lado SPA). El prompt
 * 01 extrajo la traduccion de filtro a SQL a `ColumnFiltersHelper`; este prompt la enchufa en
 * `globalSearch`.
 *
 * Fixture (`TestingFerreteriaSeeder`): 10 articulos, 5 del proveedor "Buenos Aires" (incluida
 * Pinza) y 5 de "Rosario". Ninguno tiene `category_id` cargado (todos null), pero SI tienen
 * `provider_id` cargado — por eso el test de `en_blanco` sobre `provider_id` espera 0 resultados
 * (ver test 3): confirma que el filtro realmente excluye, no que "no hace nada" como el bug.
 */
class Global_Search_Filtros_De_Columna_Test extends BusquedaTestCase
{
    /**
     * Test 1 — sin filtros, devuelve el listado completo (linea base para el test 2).
     *
     * @group busqueda
     * @test
     * @return int Total del listado completo.
     */
    public function sin_filtros_devuelve_el_listado_completo()
    {
        $response = $this->postJson('api/global-search/article', $this->payload_global_search());

        $response->assertStatus(200);

        $total = $response->json('models.total');

        $this->assertGreaterThan(1, $total, 'el listado sin filtros debe devolver mas de un articulo');

        return $total;
    }

    /**
     * Test 2 — filtro `search` por FK (provider_id) acota el resultado. Este es el test que
     * reproduce el bug de Lucas: antes de este prompt, `globalSearch` ignoraba `filters` y
     * devolvia el listado entero (el mismo total que el test 1).
     *
     * @group busqueda
     * @test
     * @depends sin_filtros_devuelve_el_listado_completo
     */
    public function filtro_search_por_fk_acota_el_resultado($total_sin_filtros)
    {
        $pinza = $this->articulo('Pinza');

        $response = $this->postJson('api/global-search/article', $this->payload_global_search([
            'filters' => [
                ['key' => 'provider_id', 'type' => 'search', 'igual_que' => $pinza->provider_id],
            ],
        ]));

        $response->assertStatus(200);

        $total = $response->json('models.total');
        $data  = $response->json('models.data');

        $this->assertLessThan(
            $total_sin_filtros,
            $total,
            'filtrar por proveedor debe devolver MENOS articulos que el listado completo (antes '.
            'del prompt 02, globalSearch ignoraba filters y devolvia todo)'
        );

        foreach ($data as $articulo) {
            $this->assertEquals(
                $pinza->provider_id,
                $articulo['provider_id'],
                'todos los articulos devueltos deben ser del proveedor filtrado'
            );
        }

        $ids = array_column($data, 'id');
        $this->assertContains($pinza->id, $ids, 'Pinza (el articulo del proveedor filtrado) debe estar entre los resultados');
    }

    /**
     * Test 3 — `en_blanco` sobre el mismo FK (provider_id) devuelve solo articulos con
     * `provider_id` nulo o 0. Los 10 articulos del fixture tienen `provider_id` cargado, asi que
     * el resultado esperado es 0 — eso es lo que prueba que el filtro esta excluyendo de verdad
     * (y no ignorando `filters`, como hacia el bug).
     *
     * @group busqueda
     * @test
     */
    public function en_blanco_sobre_el_mismo_fk_excluye_los_que_tienen_proveedor()
    {
        $response = $this->postJson('api/global-search/article', $this->payload_global_search([
            'filters' => [
                ['key' => 'provider_id', 'type' => 'search', 'en_blanco' => true],
            ],
        ]));

        $response->assertStatus(200);

        $this->assertEquals(
            0,
            $response->json('models.total'),
            'ningun articulo del fixture tiene provider_id nulo/0: en_blanco debe devolver 0, no el listado completo'
        );
    }

    /**
     * Test 4 — el filtro de columna se compone (AND) con el criterio de texto del buscador
     * general: mismo filtro de proveedor del test 2 + `query_value` con el nombre de Pinza.
     * Devuelve Pinza y nada de otro proveedor.
     *
     * @group busqueda
     * @test
     */
    public function el_filtro_se_compone_con_el_criterio_de_texto()
    {
        $pinza = $this->articulo('Pinza');

        $response = $this->postJson('api/global-search/article', $this->payload_global_search([
            'query_value' => 'Pinza',
            'props'       => [['key' => 'name']],
            'filters'     => [
                ['key' => 'provider_id', 'type' => 'search', 'igual_que' => $pinza->provider_id],
            ],
        ]));

        $response->assertStatus(200);

        $data = $response->json('models.data');
        $ids  = array_column($data, 'id');

        $this->assertContains($pinza->id, $ids, 'Pinza debe estar en el resultado (matchea texto Y filtro de proveedor)');

        foreach ($data as $articulo) {
            $this->assertEquals(
                $pinza->provider_id,
                $articulo['provider_id'],
                'el filtro de proveedor debe seguir aplicando aunque se combine con texto'
            );
        }
    }

    /**
     * Test 5 — regresion de la extraccion del prompt 01: `POST api/search/article/null/1` con el
     * mismo filtro del test 2 sigue devolviendo los mismos ids que devolvia `globalSearch` (y que
     * devolvia `search()` antes de mover el loop a `ColumnFiltersHelper`). Si el helper se movio
     * mal, este test se pone rojo.
     *
     * Nota de forma de respuesta: a diferencia de `globalSearch` (que envuelve todo bajo
     * `models`), `search()` con `paginate=1` devuelve el paginador de Eloquent directo (sin
     * envoltorio), asi que sus ids se leen de `data`, no de `models.data`.
     *
     * @group busqueda
     * @test
     */
    public function regresion_search_sigue_devolviendo_lo_mismo_que_antes_de_la_extraccion()
    {
        $pinza = $this->articulo('Pinza');

        $filtro = [
            ['key' => 'provider_id', 'type' => 'search', 'igual_que' => $pinza->provider_id],
        ];

        $response_global_search = $this->postJson('api/global-search/article', $this->payload_global_search([
            'filters' => $filtro,
        ]));
        $response_global_search->assertStatus(200);
        $ids_global_search = array_column($response_global_search->json('models.data'), 'id');

        $response_search = $this->postJson('api/search/article/null/1', [
            'filters' => $filtro,
        ]);
        $response_search->assertStatus(200);
        $ids_search = array_column($response_search->json('data'), 'id');

        sort($ids_global_search);
        sort($ids_search);

        $this->assertEquals(
            $ids_global_search,
            $ids_search,
            'search() y globalSearch() deben devolver los mismos articulos para el mismo filtro de '.
            'columna: ambos delegan en el mismo ColumnFiltersHelper'
        );

        $this->assertContains($pinza->id, $ids_search, 'Pinza debe seguir apareciendo en search() tras la extraccion del prompt 01');
    }
}
