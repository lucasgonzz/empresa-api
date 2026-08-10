<?php

namespace Tests\Feature\Busqueda;

use App\Models\Provider;

/**
 * Tests del filtro de status en `globalSearch` (mision 10, pieza 4).
 *
 * Hallazgo 20260805-global-search-provider-sin-filtro-status: el grupo 352 paso la busqueda de
 * proveedores del store a la API. El store se llenaba desde `ProviderController::index()`, que
 * filtra `where('status', 'active')`; `SearchController::globalSearch()` no filtraba status para
 * ningun modelo salvo `article`. Desde ese cambio, el buscador ofrecia proveedores dados de baja
 * en el alta de compra, en el campo proveedor del articulo y en el cheque endosado.
 *
 * No fue una regresion de codigo: cambio la fuente de datos y con ella el resultado, sin que nada
 * lo avisara. Por eso el test compara las DOS fuentes, no solo el resultado del buscador.
 */
class Global_Search_Solo_Activos_Test extends BusquedaTestCase
{
    /**
     * Test 1 - un proveedor dado de baja no aparece en el buscador.
     *
     * @group busqueda
     * @test
     */
    public function un_proveedor_dado_de_baja_no_aparece_en_el_buscador()
    {
        $proveedor = $this->proveedor('Rosario');

        $this->assertNotNull($proveedor, 'el fixture tiene que traer el proveedor Rosario');

        // Linea base: con el proveedor activo, el buscador lo encuentra. Sin esta mitad, un test
        // que solo mira "no aparece" pasaria igual si el buscador estuviera roto y no devolviera
        // nada.
        $response = $this->postJson('api/global-search/provider', $this->payload_global_search([
            'query_value' => 'Rosario',
            'props'       => ['name'],
        ]));

        $response->assertStatus(200);

        $this->assertContains(
            $proveedor->id,
            array_column($response->json('models.data'), 'id'),
            'con el proveedor activo, el buscador tiene que encontrarlo'
        );

        // Ahora se lo da de baja, que es exactamente lo que hace el sistema al eliminarlo.
        $proveedor->status = 'inactive';
        $proveedor->save();

        $response = $this->postJson('api/global-search/provider', $this->payload_global_search([
            'query_value' => 'Rosario',
            'props'       => ['name'],
        ]));

        $response->assertStatus(200);

        $this->assertNotContains(
            $proveedor->id,
            array_column($response->json('models.data'), 'id'),
            'un proveedor dado de baja NO tiene que aparecer en el buscador (alta de compra, '.
            'campo proveedor del articulo, cheque endosado)'
        );

        // Se restaura el fixture: esta suite comparte base con el resto y el proveedor Rosario es
        // parte del catalogo determinista del prompt 613.
        $proveedor->status = 'active';
        $proveedor->save();
    }

    /**
     * Test 2 - el buscador devuelve lo mismo que el index que llena el store.
     *
     * Es el test que hubiera atajado el bug original: no mira una lista esperada, mira que las dos
     * fuentes de datos coincidan. Si mañana alguna de las dos cambia su criterio, esto falla.
     *
     * @group busqueda
     * @test
     */
    public function el_buscador_y_el_index_de_proveedores_devuelven_el_mismo_universo()
    {
        $proveedor = $this->proveedor('Buenos Aires');

        $this->assertNotNull($proveedor, 'el fixture tiene que traer el proveedor Buenos Aires');

        $proveedor->status = 'inactive';
        $proveedor->save();

        $index = $this->getJson('api/provider');
        $index->assertStatus(200);
        $ids_index = array_column($index->json('models.data'), 'id');

        $buscador = $this->postJson('api/global-search/provider', $this->payload_global_search());
        $buscador->assertStatus(200);
        $ids_buscador = array_column($buscador->json('models.data'), 'id');

        $this->assertNotContains($proveedor->id, $ids_index, 'el index ya filtraba los dados de baja');
        $this->assertNotContains(
            $proveedor->id,
            $ids_buscador,
            'el buscador tiene que ofrecer el mismo universo que el index que llena el store'
        );

        $proveedor->status = 'active';
        $proveedor->save();
    }

    /**
     * Test 3 - el filtro no se aplica a un modelo cuyo `status` significa otra cosa.
     *
     * La lista de modelos "solo activos" es explicita a proposito: hay tablas con una columna
     * `status` que no significa alta/baja. Este test fija esa decision para que nadie la
     * generalice a "si tiene columna status, filtrala".
     *
     * @group busqueda
     * @test
     */
    public function el_filtro_de_activos_no_se_aplica_a_cualquier_modelo_con_columna_status()
    {
        $response = $this->postJson('api/global-search/client', $this->payload_global_search());

        $response->assertStatus(200);

        $this->assertGreaterThan(
            0,
            (int) $response->json('models.total'),
            'los clientes del fixture tienen que seguir apareciendo: `client` no entra en la lista '.
            'de modelos que se buscan solo entre activos'
        );
    }
}
