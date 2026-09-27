<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Http\Controllers\Helpers\ColumnFiltersHelper;
use App\Models\Article;
use App\Models\Image;

/**
 * El filtro de la columna de imágenes del listado: "Sin imágenes" (en_blanco) / "Con imágenes"
 * (no_en_blanco), contrato §5.5 del plan. Va por POST global-search/article, que es lo que usa el
 * listado (y ColumnFiltersHelper, el mismo que usan la masiva y el borrado por filtro).
 */
class Filtro_con_y_sin_imagenes_Test extends ImagenesInteligentesTestCase
{
    /**
     * Los ids que devuelve el listado de artículos con ese filtro de columna, acotado por nombre a
     * los artículos de la prueba.
     *
     * @param  array  $filtro
     * @param  string $nombre
     * @return array
     */
    protected function ids_con_filtro(array $filtro, $nombre)
    {
        $respuesta = $this->postJson('api/global-search/article', [
            'query_value'    => $nombre,
            'props'          => [['text' => 'nombre', 'key' => 'name', 'keyword_mode' => 'todas']],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => [$filtro],
            'conector'       => 'or',
        ]);

        $respuesta->assertStatus(200);

        return array_map('intval', array_column($respuesta->json('models.data'), 'id'));
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_imagenes_y_con_imagenes_filtran_por_la_relacion()
    {
        $nombre = 'ZZIMAGENES'.uniqid();

        $con = $this->nuevo_articulo($nombre.' con foto');
        $sin = $this->nuevo_articulo($nombre.' sin foto');

        Image::create(['hosting_url' => 'https://ejemplo.test/con.webp', 'imageable_id' => $con->id, 'imageable_type' => 'article']);

        $sin_imagenes = $this->ids_con_filtro(['key' => 'images', 'type' => 'images', 'en_blanco' => 1], $nombre);
        $this->assertSame([(int) $sin->id], $sin_imagenes);

        $con_imagenes = $this->ids_con_filtro(['key' => 'images', 'type' => 'images', 'no_en_blanco' => 1], $nombre);
        $this->assertSame([(int) $con->id], $con_imagenes);

        // Sin ninguno de los dos, el filtro no filtra nada.
        $todos = $this->ids_con_filtro(['key' => 'images', 'type' => 'images'], $nombre);
        sort($todos);
        $esperados = [(int) $con->id, (int) $sin->id];
        sort($esperados);
        $this->assertSame($esperados, $todos);
    }

    /**
     * Se anota en used_filters como los demás (lo usan el historial de filtrados y la masiva).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function queda_anotado_en_los_filtros_usados()
    {
        $resultado = ColumnFiltersHelper::apply(Article::query(), [['key' => 'images', 'type' => 'images', 'en_blanco' => 1]], 'article', Article::class);

        $this->assertSame([['key' => 'images', 'operator' => 'en_blanco', 'value' => true, 'type' => 'images']], $resultado['used_filters']);
        $this->assertStringContainsString('not exists', $resultado['models']->toSql());
    }

    /**
     * La key sale del request: si no es una relación declarada en el propio modelo, el filtro se
     * ignora (no se llama a ningún método, no rompe la búsqueda).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_key_que_no_es_una_relacion_del_modelo_no_ejecuta_nada()
    {
        $antes = Article::withTrashed()->count();

        foreach (['restore', 'save', 'forceDelete', 'no_existe', 'images; drop'] as $key) {
            $resultado = ColumnFiltersHelper::apply(Article::query(), [['key' => $key, 'type' => 'images', 'en_blanco' => 1]], 'article', Article::class);

            $this->assertSame([], $resultado['used_filters'], 'La key '.$key.' no es una relación: el filtro se ignora.');
            $this->assertStringNotContainsString('exists', $resultado['models']->toSql());
        }

        $this->assertSame($antes, Article::withTrashed()->count(), 'No se insertó ni se borró nada.');
    }
}
