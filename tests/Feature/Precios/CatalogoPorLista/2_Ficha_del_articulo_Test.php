<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * El check "Visible en la tienda para esta lista" de la ficha del artículo por
 * `POST/PUT api/article` (misión catalogo-por-lista-tienda, 5/10/2026, contrato C2):
 * `price_types[].pivot.visible_en_tienda`, que escribe ArticlePriceTypeHelper::attach_price_types().
 *
 *  - Con la clave, se escribe saneada a 1 o 0.
 *  - 🔴 Sin la clave (SPA viejo cacheado, o el asistente de IA, que arma `price_types` sin ella),
 *    el valor guardado NO se toca. Es lo contrario de `incluir_en_excel_para_clientes`, que pisa
 *    con el default de la lista.
 *  - '' o null con la clave presente tampoco se escriben.
 *  - Un artículo nuevo sin la clave nace en NULL (= no habilitado en las listas restringidas).
 *  - El valor viaja de vuelta en la respuesta de la ficha (withPivot).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class Ficha_del_articulo_Test extends CatalogoPorListaTestCase
{
    /** Marca de lista_del_body(): la lista viaja SIN la clave visible_en_tienda (SPA viejo). */
    const SIN_CLAVE = '__sin_clave__';

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_comercios();
    }

    /**
     * Una lista del body de la ficha. `$visible` en SIN_CLAVE = la lista viaja SIN la clave (lo
     * que manda un SPA viejo); cualquier otro valor viaja tal cual (incluidos null, '' y false).
     *
     * @param  \App\Models\PriceType $lista
     * @param  float                 $porcentaje
     * @param  mixed                 $visible
     * @return array
     */
    protected function lista_del_body($lista, $porcentaje, $visible = self::SIN_CLAVE)
    {
        $pivot = [
            'percentage'          => $porcentaje,
            'final_price'         => null,
            'setear_precio_final' => 0,
        ];

        if ($visible !== self::SIN_CLAVE) {
            $pivot['visible_en_tienda'] = $visible;
        }

        return ['id' => $lista->id, 'pivot' => $pivot];
    }

    /**
     * El body de `PUT api/article/{id}` (mismo armado que Combos/10 y Listado/6): las columnas del
     * artículo más los arrays de relaciones que update() recorre sin validar.
     *
     * @param  \App\Models\Article $article
     * @param  array               $price_types
     * @return array
     */
    protected function payload_de_edicion($article, array $price_types)
    {
        return array_merge(Article::find($article->id)->getAttributes(), [
            'id'                 => $article->id,
            'cost_incluye_iva'   => 0,
            'price_types'        => $price_types,
            'price_type_monedas' => [],
            'tags'               => [],
        ]);
    }

    /**
     * El body de `POST api/article` (mismo armado que Listado/6): las columnas NOT NULL que store()
     * asigna derecho desde el request.
     *
     * @param  array $price_types
     * @return array
     */
    protected function payload_de_alta(array $price_types)
    {
        return [
            'name'                           => 'zz Articulo catalogo por lista ' . uniqid(),
            'cost'                           => 100,
            'online'                         => 1,
            'precio_pausado'                 => 0,
            'aplicar_iva'                    => 1,
            'apply_provider_percentage_gain' => 0,
            'status'                         => 'active',
            'in_offer'                       => 0,
            'default_in_vender'              => 0,
            'cost_incluye_iva'               => 0,
            'price_types'                    => $price_types,
            'price_type_monedas'             => [],
            'tags'                           => [],
        ];
    }

    /**
     * Con la clave, el guardado de la ficha escribe la visibilidad de cada lista.
     *
     * @return void
     */
    public function test_guardar_la_ficha_con_la_clave_escribe_la_visibilidad()
    {
        $articulo = $this->crear_articulo($this->dueno, ['cost' => 100]);
        $this->atar($articulo->id, $this->minorista->id, null, 30);
        $this->atar($articulo->id, $this->mayorista->id, null, 20);

        $this->putJson('api/article/' . $articulo->id, $this->payload_de_edicion($articulo, [
            $this->lista_del_body($this->minorista, 30, 0),
            $this->lista_del_body($this->mayorista, 20, 1),
        ]))->assertStatus(200);

        $this->assertSame(0, $this->visible($articulo->id, $this->minorista->id));
        $this->assertSame(1, $this->visible($articulo->id, $this->mayorista->id));

        // Y un booleano de JSON vale lo mismo que el entero.
        $this->putJson('api/article/' . $articulo->id, $this->payload_de_edicion($articulo, [
            $this->lista_del_body($this->minorista, 30, 0),
            $this->lista_del_body($this->mayorista, 20, false),
        ]))->assertStatus(200);

        $this->assertSame(0, $this->visible($articulo->id, $this->mayorista->id), 'false (booleano) es 0');
    }

    /**
     * 🔴 El SPA viejo: un guardado de la ficha SIN la clave no toca la visibilidad guardada, ni la
     * habilitada ni la deshabilitada. El resto del pivote sí se guarda (cambia el margen).
     *
     * @return void
     */
    public function test_un_spa_viejo_sin_la_clave_no_pisa_lo_guardado()
    {
        $articulo = $this->crear_articulo($this->dueno, ['cost' => 100]);
        $this->atar($articulo->id, $this->minorista->id, 0, 30);
        $this->atar($articulo->id, $this->mayorista->id, 1, 20);

        $this->putJson('api/article/' . $articulo->id, $this->payload_de_edicion($articulo, [
            $this->lista_del_body($this->minorista, 35),
            $this->lista_del_body($this->mayorista, 25),
        ]))->assertStatus(200);

        $this->assertSame(1, $this->visible($articulo->id, $this->mayorista->id), 'Mayorista sigue habilitado.');
        $this->assertSame(0, $this->visible($articulo->id, $this->minorista->id), 'Minorista sigue en 0.');

        $this->assertEquals(25, (float) $this->filas($articulo->id, $this->mayorista->id)->first()->percentage, 'El guardado del pivote ocurrió (margen nuevo).');
    }

    /**
     * La clave presente con '' o null tampoco se escribe ('' en MySQL estricto revienta un
     * tinyint; null es "no informado").
     *
     * @return void
     */
    public function test_la_clave_vacia_o_null_no_se_escribe()
    {
        $articulo = $this->crear_articulo($this->dueno, ['cost' => 100]);
        $this->atar($articulo->id, $this->minorista->id, 0, 30);
        $this->atar($articulo->id, $this->mayorista->id, 1, 20);

        $this->putJson('api/article/' . $articulo->id, $this->payload_de_edicion($articulo, [
            $this->lista_del_body($this->minorista, 30, null),
            $this->lista_del_body($this->mayorista, 20, ''),
        ]))->assertStatus(200);

        $this->assertSame(1, $this->visible($articulo->id, $this->mayorista->id));
        $this->assertSame(0, $this->visible($articulo->id, $this->minorista->id));
    }

    /**
     * Alta de un artículo sin la clave: nace sin habilitar (NULL) en todas las listas.
     *
     * @return void
     */
    public function test_el_alta_sin_la_clave_nace_en_null()
    {
        $id = $this->postJson('api/article', $this->payload_de_alta([
            $this->lista_del_body($this->minorista, 30),
            $this->lista_del_body($this->mayorista, 20),
        ]))->assertStatus(201)->json('model.id');

        $this->assertNotNull($id);
        $this->assertNull($this->visible($id, $this->minorista->id));
        $this->assertNull($this->visible($id, $this->mayorista->id));
    }

    /**
     * Alta con la clave: se guarda lo que vino.
     *
     * @return void
     */
    public function test_el_alta_con_la_clave_guarda_la_visibilidad()
    {
        $id = $this->postJson('api/article', $this->payload_de_alta([
            $this->lista_del_body($this->minorista, 30, 0),
            $this->lista_del_body($this->mayorista, 20, 1),
        ]))->assertStatus(201)->json('model.id');

        $this->assertSame(0, $this->visible($id, $this->minorista->id));
        $this->assertSame(1, $this->visible($id, $this->mayorista->id));
    }

    /**
     * El valor viaja de vuelta: `model.price_types[].pivot.visible_en_tienda` en la respuesta de la
     * ficha (es lo que el SPA muestra en el check).
     *
     * @return void
     */
    public function test_la_ficha_devuelve_la_visibilidad_en_el_pivote()
    {
        $articulo = $this->crear_articulo($this->dueno, ['cost' => 100]);
        $this->atar($articulo->id, $this->minorista->id, null, 30);
        $this->atar($articulo->id, $this->mayorista->id, 1, 20);

        $respuesta = $this->putJson('api/article/' . $articulo->id, $this->payload_de_edicion($articulo, [
            $this->lista_del_body($this->minorista, 30),
            $this->lista_del_body($this->mayorista, 20),
        ]))->assertStatus(200);

        $por_lista = [];
        foreach ($respuesta->json('model.price_types') as $price_type) {
            $por_lista[$price_type['id']] = $price_type['pivot'];
        }

        $this->assertArrayHasKey('visible_en_tienda', $por_lista[$this->mayorista->id]);
        $this->assertEquals(1, $por_lista[$this->mayorista->id]['visible_en_tienda']);
        $this->assertNull($por_lista[$this->minorista->id]['visible_en_tienda']);

        // Y el SPA nuevo puede mandar de vuelta exactamente lo que recibió sin cambiar nada.
        $this->assertSame(1, (int) DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $this->mayorista->id)->value('visible_en_tienda'));
    }
}
