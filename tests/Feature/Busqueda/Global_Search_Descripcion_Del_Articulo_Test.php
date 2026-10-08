<?php

namespace Tests\Feature\Busqueda;

use App\Models\Article;

/**
 * Busqueda de articulos por la propiedad `descripcion` (mision descripcion-como-criterio-de-busqueda-en-vender).
 *
 * El cliente servian no podia tildar "Descripcion" en el desplegable de propiedades del buscador de
 * Vender: la SPA no la ofrecia (le faltaba `text` en el modelo). La API ya buscaba por cualquier
 * columna real que llegara en `props`; este test lo fija, para que ofrecerla en la SPA no sea una
 * promesa vacia.
 */
class Global_Search_Descripcion_Del_Articulo_Test extends BusquedaTestCase
{
    /**
     * @group busqueda
     * @test
     */
    public function un_articulo_se_encuentra_por_su_descripcion_solo_si_la_propiedad_esta_tildada()
    {
        $articulo = Article::where('status', 'active')->orderBy('id')->first();

        $this->assertNotNull($articulo, 'el fixture tiene que traer al menos un articulo activo');

        $descripcion_original = $articulo->descripcion;
        $palabra = 'zzdescripcionunica';

        $articulo->descripcion = 'Informacion complementaria '.$palabra;
        $articulo->save();

        try {
            foreach (['vender', 'provider_order'] as $contexto) {
                // Con la descripcion tildada, el articulo aparece.
                $response = $this->postJson('api/global-search/article', $this->payload_global_search([
                    'query_value' => $palabra,
                    'props'       => [['key' => 'descripcion', 'keyword_mode' => 'alguna']],
                    'contexto'    => $contexto,
                ]));

                $response->assertStatus(200);

                $this->assertContains(
                    $articulo->id,
                    array_column($response->json('models.data'), 'id'),
                    "con 'descripcion' tildada (contexto $contexto) el articulo tiene que aparecer"
                );

                // Sin tildarla (solo name, provider_code, bar_code, que son los defaults), no aparece:
                // buscar por descripcion es opt-in y no ensucia los resultados de quien no la pidio.
                $response = $this->postJson('api/global-search/article', $this->payload_global_search([
                    'query_value' => $palabra,
                    'props'       => [
                        ['key' => 'name', 'keyword_mode' => 'alguna'],
                        ['key' => 'provider_code', 'keyword_mode' => 'alguna'],
                        ['key' => 'bar_code', 'keyword_mode' => 'alguna'],
                    ],
                    'contexto'    => $contexto,
                ]));

                $response->assertStatus(200);

                $this->assertNotContains(
                    $articulo->id,
                    array_column($response->json('models.data'), 'id'),
                    "sin 'descripcion' tildada (contexto $contexto) el articulo NO tiene que aparecer"
                );
            }
        } finally {
            // Fixture determinista compartido: se deja como estaba.
            $articulo->descripcion = $descripcion_original;
            $articulo->save();
        }
    }
}
