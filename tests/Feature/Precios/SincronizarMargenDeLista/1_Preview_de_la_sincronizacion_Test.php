<?php

namespace Tests\Feature\Precios\SincronizarMargenDeLista;

use Illuminate\Support\Facades\DB;

/**
 * `GET api/price-type/{id}/sincronizar-margen/preview`: los cuatro números que el modal
 * "Sincronizar artículos" muestra antes de sincronizar (misión sincronizar-margen-lista-precios,
 * 1/10/2026).
 *
 * Se prueba sobre el escenario mezclado de SincronizarMargenTestCase: el margen actual es el
 * GUARDADO, los conteos son por artículo distinto y no cuentan borrados, otras listas ni otros
 * comercios, y una lista ajena o inexistente es 404.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sincronizar-margen-de-lista
 */
class Preview_de_la_sincronizacion_Test extends SincronizarMargenTestCase
{
    /**
     * Los cuatro números sobre el escenario mezclado.
     *
     * @return void
     */
    public function test_los_cuatro_numeros_en_el_escenario_mezclado()
    {
        $this->armar_escenario(30);

        $this->pedir_preview($this->lista->id)
            ->assertStatus(200)
            ->assertExactJson([
                'porcentaje_actual'        => '30.00',
                'total_articulos'          => 6,
                'coinciden_con_el_actual'  => 3,
                'con_precio_fijado_a_mano' => 2,
            ]);
    }

    /**
     * Un artículo con la lista atada dos veces cuenta una sola vez.
     *
     * @return void
     */
    public function test_un_articulo_atado_dos_veces_cuenta_una_vez()
    {
        $this->armar_escenario(30);

        $this->atar($this->art['en_30'], $this->lista->id, '30.00', 0);

        $this->pedir_preview($this->lista->id)
            ->assertStatus(200)
            ->assertJson([
                'total_articulos'         => 6,
                'coinciden_con_el_actual' => 3,
            ]);
    }

    /**
     * El margen actual es el guardado: si la fila de la lista dice 25 (aunque el formulario tenga
     * otra cosa tipeada), "coinciden" se mide contra 25.
     *
     * @return void
     */
    public function test_coinciden_se_mide_contra_el_margen_guardado()
    {
        $this->armar_escenario(30);

        DB::table('price_types')->where('id', $this->lista->id)->update(['percentage' => 25]);

        // en_null (usa el de la lista) + en_25.
        $this->pedir_preview($this->lista->id)
            ->assertStatus(200)
            ->assertExactJson([
                'porcentaje_actual'        => '25.00',
                'total_articulos'          => 6,
                'coinciden_con_el_actual'  => 2,
                'con_precio_fijado_a_mano' => 2,
            ]);
    }

    /**
     * Lista sin margen: porcentaje_actual null y "coinciden" son solo los que usan el de la lista
     * (pivot NULL) sin precio fijado a mano.
     *
     * @return void
     */
    public function test_lista_sin_margen_coinciden_solo_los_null()
    {
        $this->armar_escenario(null);

        $this->pedir_preview($this->lista->id)
            ->assertStatus(200)
            ->assertExactJson([
                'porcentaje_actual'        => null,
                'total_articulos'          => 6,
                'coinciden_con_el_actual'  => 1,
                'con_precio_fijado_a_mano' => 2,
            ]);
    }

    /**
     * Un artículo con precio fijado a mano y el pivot en NULL tampoco entra en "coinciden".
     *
     * @return void
     */
    public function test_un_fijado_a_mano_con_margen_null_no_coincide()
    {
        $this->armar_escenario(30);

        DB::table('article_price_type')
            ->where('article_id', $this->art['fijado_en_30'])
            ->where('price_type_id', $this->lista->id)
            ->update(['percentage' => null]);

        $this->pedir_preview($this->lista->id)
            ->assertStatus(200)
            ->assertJson([
                'coinciden_con_el_actual'  => 3,
                'con_precio_fijado_a_mano' => 2,
            ]);
    }

    /**
     * La lista de otro comercio, o una que no existe: 404, y no se filtra ningún número.
     *
     * @return void
     */
    public function test_lista_ajena_o_inexistente_es_404()
    {
        $this->armar_escenario(30);

        $respuesta = $this->pedir_preview($this->lista_ajena->id);

        $respuesta->assertStatus(404);
        $this->assertNull($respuesta->json('total_articulos'), 'El 404 no puede devolver los números de una lista ajena.');

        $inexistente = (int) DB::table('price_types')->max('id') + 1000;

        $this->pedir_preview($inexistente)->assertStatus(404);
    }
}
