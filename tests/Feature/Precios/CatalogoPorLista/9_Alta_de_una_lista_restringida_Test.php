<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

/**
 * El alta de una lista con el catálogo restringido (misión catalogo-por-lista-tienda, 5/10/2026):
 * decisión 2 de Lucas, "en una lista restringida los artículos nacen sin habilitar" (columna NULL
 * por defecto).
 *
 * Al crear una lista de precios el comercio encola el recálculo de todos sus artículos
 * (ProcessSetFinalPrices), que le ata la lista nueva a cada uno. Acá NO se simula la cola
 * (QUEUE_CONNECTION=sync: el job corre adentro del request), a diferencia de
 * Interruptor_de_la_lista_Test, que la apaga porque no le interesa el recálculo. Lo que se fija es
 * que esos pares nacen con `visible_en_tienda` en NULL —ni 1 ni 0—: la restricción vale desde el
 * primer día y el dueño habilita artículo por artículo (o por masiva, o por Excel).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class Alta_de_una_lista_restringida_Test extends CatalogoPorListaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_comercios();
    }

    /**
     * El body del alta tal como lo arma el formulario.
     *
     * @param  array $cambios
     * @return array
     */
    protected function payload_de_alta(array $cambios = [])
    {
        return array_merge([
            'name'                                 => 'zz Lista restringida nueva',
            'percentage'                           => 15,
            'position'                             => 3,
            'ocultar_al_publico'                   => 0,
            'incluir_en_lista_de_precios_de_excel' => 1,
            'setear_precio_final'                  => 0,
            'se_usa_en_tienda_nube'                => 0,
            'se_usa_en_ml'                         => 0,
            'categories'                           => [],
            'sub_categories'                       => [],
        ], $cambios);
    }

    /**
     * Una lista restringida nueva queda atada a todos los artículos existentes, y todos sus pares
     * nacen en NULL: ninguno queda habilitado. Las listas que ya existían no se tocan.
     *
     * @return void
     */
    public function test_los_pares_de_una_lista_restringida_nueva_nacen_en_null()
    {
        $articulos = [];

        for ($i = 0; $i < 3; $i++) {
            $articulos[] = $this->crear_articulo($this->dueno, ['cost' => 100]);
        }

        // Uno de ellos ya estaba habilitado en Mayorista: la lista nueva no se lo hereda.
        $this->atar($articulos[0]->id, $this->mayorista->id, 1, 20);

        $id = $this->postJson('api/price-type', $this->payload_de_alta(['catalogo_restringido_en_tienda' => 1]))
                    ->assertStatus(201)
                    ->json('model.id');

        $this->assertSame(1, $this->interruptor($id), 'La lista nace restringida.');

        foreach ($articulos as $articulo) {

            $filas = $this->filas($articulo->id, $id);

            $this->assertCount(1, $filas, 'El alta de la lista la ata una sola vez a cada artículo.');
            $this->assertNull($filas->first()->visible_en_tienda, 'Nace sin habilitar: NULL, ni 1 ni 0.');
        }

        // Lo que ya existía queda como estaba.
        $this->assertSame(1, $this->visible($articulos[0]->id, $this->mayorista->id), 'Mayorista no se toca.');

        // Y ni siquiera uno solo quedó habilitado en la lista nueva.
        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('article_price_type')->where('price_type_id', $id)->where('visible_en_tienda', 1)->count()
        );
    }
}
