<?php

namespace Tests\Feature\Busqueda;

use App\Models\Article;
use App\Models\Category;
use App\Models\Provider;

/**
 * Filtro "En blanco" / "Que no este en blanco" cuando el FK apunta a un registro borrado.
 *
 * Bug real (servian2, 23/9/2026): el articulo 669790 tenia `provider_id = 6` y ese proveedor se
 * habia borrado (soft delete) el 17/9. El listado no carga la relacion `provider` de un borrado, asi
 * que la celda Proveedor se veia vacia, pero el filtro "En blanco" (que solo miraba FK nulo o 0)
 * devolvia 0 resultados. `ProviderController::destroy` no toca los articulos, asi que el dato
 * queda asi hasta que alguien lo corrija: en servian2 eran 29.682 articulos.
 *
 * Contrato que fijan estos tests: "en blanco" == "lo que el listado muestra en blanco".
 */
class Filtro_En_Blanco_Con_Relacion_Borrada_Test extends BusquedaTestCase
{
    /**
     * Devuelve los ids del listado de articulos con el filtro dado, acotado por texto a los articulos
     * de la prueba (nombre unico) para no depender del resto del fixture.
     *
     * @param array  $filtro  Filtro de columna tal cual lo manda el SPA.
     * @param string $nombre  Texto unico que comparten los articulos creados por el test.
     * @return int[]
     */
    protected function ids_con_filtro(array $filtro, $nombre)
    {
        $response = $this->postJson('api/global-search/article', $this->payload_global_search([
            'query_value' => $nombre,
            'props'       => [['text' => 'nombre', 'key' => 'name', 'keyword_mode' => 'todas']],
            'filters'     => [$filtro],
        ]));

        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('models.data'), 'id'));
    }

    /**
     * Crea un articulo del fixture con el proveedor/categoria dados.
     *
     * @param string   $nombre
     * @param int|null $provider_id
     * @param int|null $category_id
     * @return Article
     */
    protected function articulo_de_prueba($nombre, $provider_id, $category_id = null)
    {
        $base = $this->articulo('Pinza');

        $articulo = $base->replicate();
        $articulo->name        = $nombre;
        $articulo->provider_id = $provider_id;
        $articulo->category_id = $category_id;
        $articulo->save();

        return $articulo;
    }

    /**
     * @group busqueda
     * @test
     */
    public function en_blanco_incluye_al_articulo_cuyo_proveedor_fue_borrado()
    {
        $nombre = 'ZZBLANCO'.uniqid();

        $vivo    = Provider::find($this->articulo('Pinza')->provider_id);
        $borrado = $vivo->replicate();
        $borrado->name = 'Proveedor borrado '.uniqid();
        $borrado->save();

        $sin_proveedor  = $this->articulo_de_prueba($nombre.' sin', null);
        $con_borrado    = $this->articulo_de_prueba($nombre.' borrado', $borrado->id);
        $con_vivo       = $this->articulo_de_prueba($nombre.' vivo', $vivo->id);

        $borrado->delete();

        $ids = $this->ids_con_filtro(['key' => 'provider_id', 'type' => 'search', 'en_blanco' => true], $nombre);

        $this->assertContains($sin_proveedor->id, $ids, 'el articulo sin proveedor debe aparecer en "en blanco"');
        $this->assertContains(
            $con_borrado->id,
            $ids,
            'el articulo cuyo proveedor fue borrado se ve sin proveedor en el listado: "en blanco" tiene que encontrarlo'
        );
        $this->assertNotContains($con_vivo->id, $ids, 'un articulo con proveedor vivo no es "en blanco"');
    }

    /**
     * @group busqueda
     * @test
     */
    public function no_en_blanco_excluye_al_articulo_cuyo_proveedor_fue_borrado()
    {
        $nombre = 'ZZNOBLANCO'.uniqid();

        $vivo    = Provider::find($this->articulo('Pinza')->provider_id);
        $borrado = $vivo->replicate();
        $borrado->name = 'Proveedor borrado '.uniqid();
        $borrado->save();

        $sin_proveedor = $this->articulo_de_prueba($nombre.' sin', null);
        $con_borrado   = $this->articulo_de_prueba($nombre.' borrado', $borrado->id);
        $con_vivo      = $this->articulo_de_prueba($nombre.' vivo', $vivo->id);

        $borrado->delete();

        $ids = $this->ids_con_filtro(['key' => 'provider_id', 'type' => 'search', 'no_en_blanco' => true], $nombre);

        $this->assertContains($con_vivo->id, $ids, 'el articulo con proveedor vivo debe aparecer en "no en blanco"');
        $this->assertNotContains($sin_proveedor->id, $ids, 'sin proveedor no es "no en blanco"');
        $this->assertNotContains(
            $con_borrado->id,
            $ids,
            'con proveedor borrado el listado lo muestra vacio: no puede estar en "no en blanco"'
        );
    }

    /**
     * Mismo defecto en cualquier FK belongsTo a un modelo con soft delete: la categoria tambien.
     *
     * @group busqueda
     * @test
     */
    public function en_blanco_vale_tambien_para_una_categoria_borrada()
    {
        $nombre = 'ZZCATEGORIA'.uniqid();

        $categoria = new Category();
        $categoria->name    = 'Categoria borrada '.uniqid();
        $categoria->user_id = $this->articulo('Pinza')->user_id;
        $categoria->save();

        $con_borrada = $this->articulo_de_prueba($nombre.' borrada', $this->articulo('Pinza')->provider_id, $categoria->id);

        $categoria->delete();

        $ids = $this->ids_con_filtro(['key' => 'category_id', 'type' => 'search', 'en_blanco' => true], $nombre);

        $this->assertContains($con_borrada->id, $ids, 'categoria borrada = se ve en blanco en el listado');
    }
}
