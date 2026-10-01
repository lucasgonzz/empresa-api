<?php

namespace Tests\Feature\Precios\SincronizarMargenDeLista;

use App\Jobs\ProcessChunkSetFinalPrices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * La propiedad vieja `update_existing_articles_percentage_mode` ya no actúa sola, y el SPA viejo
 * (cacheado en la PWA) sigue andando (misión sincronizar-margen-lista-precios, 1/10/2026).
 *
 * Se prueba:
 *  - cambiar el margen SIN `sincronizar_margen` y con el modo en 'none' no toca ningún pivot;
 *  - una fila guardada en 'all' antes de la migración tampoco dispara nada (el controller no lee
 *    la fila), y la migración la deja en 'none' para que el formulario no la reenvíe;
 *  - compatibilidad: el SPA viejo manda el modo 'all' / 'only_default_matches' en ESE guardado y
 *    se comporta como hoy (sync_existing_articles_percentage), pero la fila queda en 'none';
 *  - el alta tampoco persiste el modo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sincronizar-margen-de-lista
 */
class El_modo_viejo_ya_no_actua_solo_Test extends SincronizarMargenTestCase
{
    /**
     * Cambiar el margen sin la clave nueva y con el modo en 'none': ningún pivot cambia.
     *
     * @return void
     */
    public function test_cambiar_el_margen_sin_pedir_sincronizar_no_toca_ningun_articulo()
    {
        $this->armar_escenario(30);

        $antes = $this->foto_de_pivots();

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'                               => '35',
            'update_existing_articles_percentage_mode' => 'none',
        ]))->assertStatus(200)
            ->assertJsonPath('notifications', []);

        $this->assertEquals('35.00', DB::table('price_types')->where('id', $this->lista->id)->value('percentage'));
        $this->assertEquals($antes, $this->foto_de_pivots());
        Queue::assertNotPushed(ProcessChunkSetFinalPrices::class);
    }

    /**
     * La fila guardada en 'all' (como quedaba antes de esta misión) y un PUT que no manda el modo
     * (el SPA nuevo ya no tiene la propiedad): no se actualiza nada, y la fila queda en 'none'.
     *
     * @return void
     */
    public function test_una_fila_guardada_en_all_ya_no_actualiza_sola()
    {
        $this->armar_escenario(30);

        DB::table('price_types')->where('id', $this->lista->id)->update(['update_existing_articles_percentage_mode' => 'all']);

        $antes = $this->foto_de_pivots();

        $payload = $this->payload($this->lista, ['percentage' => '35']);
        unset($payload['update_existing_articles_percentage_mode']);

        $this->putJson('api/price-type/' . $this->lista->id, $payload)->assertStatus(200);

        $this->assertEquals($antes, $this->foto_de_pivots());
        Queue::assertNotPushed(ProcessChunkSetFinalPrices::class);
        $this->assertSame('none', DB::table('price_types')->where('id', $this->lista->id)->value('update_existing_articles_percentage_mode'));
    }

    /**
     * La migración de datos pone todas las filas en 'none'; después, el formulario que reenvía la
     * fila tal cual (modo incluido) no actualiza nada.
     *
     * @return void
     */
    public function test_la_migracion_deja_las_filas_en_none_y_el_formulario_no_reenvia_el_modo_viejo()
    {
        $this->armar_escenario(30);

        DB::table('price_types')->where('id', $this->lista->id)->update(['update_existing_articles_percentage_mode' => 'all']);
        DB::table('price_types')->where('id', $this->otra->id)->update(['update_existing_articles_percentage_mode' => 'only_default_matches']);

        require_once database_path('migrations/2026_10_01_120000_reset_update_existing_articles_percentage_mode.php');
        (new \ResetUpdateExistingArticlesPercentageMode())->up();

        $this->assertSame(0, DB::table('price_types')->where('update_existing_articles_percentage_mode', '<>', 'none')->count());
        $this->assertSame(0, DB::table('price_types')->whereNull('update_existing_articles_percentage_mode')->count());

        $antes = $this->foto_de_pivots();

        // payload() copia la fila: el modo viaja como 'none'.
        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, ['percentage' => '35']))
            ->assertStatus(200);

        $this->assertEquals($antes, $this->foto_de_pivots());
        Queue::assertNotPushed(ProcessChunkSetFinalPrices::class);
    }

    /**
     * Compatibilidad con el SPA viejo: modo 'all' en el request y sin la clave nueva → se comporta
     * como hoy (todos los pivots de la lista, incluidos los de precio fijado a mano, porque así
     * era el modo viejo), pero la fila NO guarda el modo.
     *
     * @return void
     */
    public function test_el_spa_viejo_con_all_se_comporta_como_hoy_sin_guardar_el_modo()
    {
        $this->armar_escenario(30);

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'                               => '35',
            'update_existing_articles_percentage_mode' => 'all',
        ]))->assertStatus(200);

        foreach (['en_30', 'en_null', 'en_30_setear_null', 'en_25', 'fijado_en_30', 'fijado_en_40'] as $nombre) {
            $this->assertSame('35.00', $this->pivot($nombre, $this->lista->id)['percentage'], $nombre);
        }

        // El modo viejo nunca tocó setear_precio_final.
        $this->assertSame(1, $this->pivot('fijado_en_30', $this->lista->id)['setear_precio_final']);

        $this->assertSame('30.00', $this->pivot('en_30', $this->otra->id)['percentage']);
        $this->assertSame('30.00', $this->pivot('borrado', $this->lista->id)['percentage']);

        $this->assertSame(
            $this->ids_de(['en_30', 'en_null', 'en_30_setear_null', 'en_25', 'fijado_en_30', 'fijado_en_40']),
            $this->ids_encolados()
        );

        $this->assertSame('none', DB::table('price_types')->where('id', $this->lista->id)->value('update_existing_articles_percentage_mode'));
    }

    /**
     * Compatibilidad con el SPA viejo, modo 'only_default_matches': solo los del margen viejo
     * escrito (el modo viejo NO contaba los NULL: con margen viejo cargado iba por igualdad).
     *
     * @return void
     */
    public function test_el_spa_viejo_con_only_default_matches_se_comporta_como_hoy()
    {
        $this->armar_escenario(30);

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'                               => '35',
            'update_existing_articles_percentage_mode' => 'only_default_matches',
        ]))->assertStatus(200);

        foreach (['en_30', 'en_30_setear_null', 'fijado_en_30'] as $nombre) {
            $this->assertSame('35.00', $this->pivot($nombre, $this->lista->id)['percentage'], $nombre);
        }

        $this->assertNull($this->pivot('en_null', $this->lista->id)['percentage']);
        $this->assertSame('25.00', $this->pivot('en_25', $this->lista->id)['percentage']);

        $this->assertSame($this->ids_de(['en_30', 'en_30_setear_null', 'fijado_en_30']), $this->ids_encolados());
        $this->assertSame('none', DB::table('price_types')->where('id', $this->lista->id)->value('update_existing_articles_percentage_mode'));
    }

    /**
     * Si vienen las dos (no lo hace ningún SPA, pero el contrato lo define): manda la clave nueva y
     * el modo viejo se ignora.
     *
     * @return void
     */
    public function test_con_la_clave_nueva_el_modo_viejo_se_ignora()
    {
        $this->armar_escenario(30);

        $this->putJson('api/price-type/' . $this->lista->id, $this->payload($this->lista, [
            'percentage'                               => '35',
            'update_existing_articles_percentage_mode' => 'all',
            'sincronizar_margen'                       => ['alcance' => 'coinciden', 'incluir_precio_fijado_a_mano' => false],
        ]))->assertStatus(200);

        $this->assertSame('25.00', $this->pivot('en_25', $this->lista->id)['percentage']);
        $this->assertSame($this->ids_de(['en_30', 'en_null', 'en_30_setear_null']), $this->ids_encolados());
    }

    /**
     * El alta tampoco guarda el modo, aunque el SPA viejo lo mande.
     *
     * @return void
     */
    public function test_el_alta_no_guarda_el_modo()
    {
        $this->armar_escenario(30);

        $respuesta = $this->postJson('api/price-type', [
            'name'                                     => 'zz Lista nueva',
            'percentage'                               => '20',
            'update_existing_articles_percentage_mode' => 'all',
            'position'                                 => 3,
            'incluir_en_lista_de_precios_de_excel'     => 1,
            'categories'                               => [],
            'sub_categories'                           => [],
            'childrens'                                => [],
        ])->assertStatus(201);

        $id = $respuesta->json('model.id');

        $this->assertNotNull($id);
        $this->assertSame('none', DB::table('price_types')->where('id', $id)->value('update_existing_articles_percentage_mode'));
    }
}
