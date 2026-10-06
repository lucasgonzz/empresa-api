<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * `GET api/price-type/{id}/habilitados-en-tienda` → `{"habilitados": int, "total": int}`: el
 * contador "X habilitados de Y" del interruptor de la lista (misión catalogo-por-lista-tienda,
 * 5/10/2026, contrato C2).
 *
 *  - `total`: artículos vivos (sin soft delete) del dueño.
 *  - `habilitados`: los que tienen alguna fila de pivote con ESA lista en `visible_en_tienda = 1`,
 *    contados una vez aunque la lista esté atada dos veces.
 *  - Lista de otro comercio o inexistente → 404, sin números.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class Contador_de_habilitados_Test extends CatalogoPorListaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_comercios();
    }

    /**
     * El escenario completo:
     *
     *   artículo        pivote de Mayorista                 cuenta como
     *   --------------  ----------------------------------  ---------------------------
     *   habilitado      1                                   habilitado
     *   duplicado       dos filas: NULL y 1                 habilitado (una vez)
     *   en_cero         0 (y 1 en Minorista)                no habilitado
     *   en_null         NULL                                no habilitado
     *   sin_fila        sin Mayorista                       no habilitado
     *   borrado         1, con soft delete                  no cuenta en ninguno
     *   de_otro_dueno   1 en la lista ajena                 no cuenta en ninguno
     *
     * Total del dueño: 5 (los cinco vivos). Habilitados en Mayorista: 2.
     *
     * @return void
     */
    public function test_cuenta_habilitados_y_total_del_dueno()
    {
        $habilitado = $this->crear_articulo($this->dueno);
        $duplicado  = $this->crear_articulo($this->dueno);
        $en_cero    = $this->crear_articulo($this->dueno);
        $en_null    = $this->crear_articulo($this->dueno);
        $sin_fila   = $this->crear_articulo($this->dueno);
        $borrado    = $this->crear_articulo($this->dueno);

        $de_otro_dueno = $this->crear_articulo($this->otro_dueno);

        $this->atar($habilitado->id, $this->mayorista->id, 1);
        $this->atar($duplicado->id, $this->mayorista->id, null);
        $this->atar($duplicado->id, $this->mayorista->id, 1);
        $this->atar($en_cero->id, $this->mayorista->id, 0);
        $this->atar($en_cero->id, $this->minorista->id, 1);
        $this->atar($en_null->id, $this->mayorista->id, null);
        $this->atar($borrado->id, $this->mayorista->id, 1);
        $this->atar($de_otro_dueno->id, $this->lista_ajena->id, 1);

        Article::find($borrado->id)->delete();

        $this->getJson('api/price-type/' . $this->mayorista->id . '/habilitados-en-tienda')
            ->assertStatus(200)
            ->assertExactJson([
                'habilitados' => 2,
                'total'       => 5,
            ]);

        // En Minorista el único habilitado es en_cero.
        $this->getJson('api/price-type/' . $this->minorista->id . '/habilitados-en-tienda')
            ->assertStatus(200)
            ->assertExactJson([
                'habilitados' => 1,
                'total'       => 5,
            ]);
    }

    /**
     * Un comercio sin artículos: cero de cero.
     *
     * @return void
     */
    public function test_sin_articulos_es_cero_de_cero()
    {
        $this->getJson('api/price-type/' . $this->mayorista->id . '/habilitados-en-tienda')
            ->assertStatus(200)
            ->assertExactJson([
                'habilitados' => 0,
                'total'       => 0,
            ]);
    }

    /**
     * La lista de otro comercio, o una que no existe: 404 y ningún número.
     *
     * @return void
     */
    public function test_lista_ajena_o_inexistente_es_404()
    {
        $de_otro_dueno = $this->crear_articulo($this->otro_dueno);
        $this->atar($de_otro_dueno->id, $this->lista_ajena->id, 1);

        $respuesta = $this->getJson('api/price-type/' . $this->lista_ajena->id . '/habilitados-en-tienda');

        $respuesta->assertStatus(404);
        $this->assertNull($respuesta->json('habilitados'), 'El 404 no puede devolver los números de una lista ajena.');
        $this->assertNull($respuesta->json('total'));

        $inexistente = (int) DB::table('price_types')->max('id') + 1000;

        $this->getJson('api/price-type/' . $inexistente . '/habilitados-en-tienda')->assertStatus(404);
    }
}
