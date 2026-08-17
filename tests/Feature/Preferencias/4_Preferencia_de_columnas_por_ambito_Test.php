<?php

namespace Tests\Feature\Preferencias;

use App\Models\TableColumnPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Preferencias de columnas del buscador con ámbito propio (familia `search_*`).
 *
 * Cuando una relación declara sus propias `props_to_show`, la SPA no usa la preferencia genérica
 * `search` del modelo: arma un ámbito con `ModelForm.search_preference_scope()` (que devuelve
 * `<modelo padre>_<relación>`) y pide `search_<ámbito>` — por ejemplo, el buscador de artículos
 * dentro de una compra pide `search_provider_order_articles`. Eso permite que el mismo modelo
 * tenga columnas distintas según desde dónde se lo busque.
 *
 * El bug: ese patrón entró en la SPA y nunca en la API. `assert_preference_type()` tenía la
 * whitelist con `search` a secas y el patrón `btm_*`, así que todo `search_*` moría en un
 * `abort(404)`. El barrido sobre `empresa-spa/src/models/*.js` dio 42 relaciones con ámbito
 * propio: las 42 tiraban 404 en GET y en PUT. El GET se lo tragaba el catch del modal (el usuario
 * veía columnas por defecto y creía que "no le guardó"), el PUT sí rompía al apretar Listo.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Preferencia_de_columnas_por_ambito_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Ámbito real que reporta el bug: el buscador de artículos dentro de una compra.
     */
    const TIPO_CON_AMBITO = 'search_provider_order_articles';

    /**
     * Usuario autenticado de los tests de esta rama (mismo patrón que el resto de Preferencias).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Devuelve el usuario ya autenticado, o saltea el test si la base no lo tiene.
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($owner, 'web');

        return $owner;
    }

    /**
     * Fila de preferencia de un usuario/modelo/tipo.
     *
     * @param int    $user_id
     * @param string $model_name
     * @param string $preference_type
     * @return \App\Models\TableColumnPreference|null
     */
    protected function preferencia($user_id, $model_name, $preference_type)
    {
        return TableColumnPreference::where('user_id', $user_id)
            ->where('model_name', $model_name)
            ->where('preference_type', $preference_type)
            ->first();
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_get_de_una_preferencia_con_ambito_no_da_404()
    {
        $owner = $this->autenticar();

        // La base de testing viene sembrada de antes: se limpia el ámbito para que el test mida
        // el caso "todavía no guardó nada". DatabaseTransactions revierte el borrado al terminar.
        TableColumnPreference::where('model_name', 'article')
            ->where('preference_type', self::TIPO_CON_AMBITO)
            ->delete();

        $response = $this->getJson('api/table-column-preference/article/'.self::TIPO_CON_AMBITO);

        $response->assertStatus(200);

        // Sin fila propia la API devuelve model null, que es lo que la SPA espera para caer a los
        // defaults del consumidor en vez de mostrar un error.
        $this->assertNull($response->json('model'));
        $this->assertNull($this->preferencia($owner->id, 'article', self::TIPO_CON_AMBITO));
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_put_de_una_preferencia_con_ambito_guarda_y_el_get_la_devuelve()
    {
        $owner = $this->autenticar();

        $columnas = [
            ['key' => 'name', 'visible' => true, 'order' => 0, 'width' => 250, 'wrap_content' => false],
            ['key' => 'bar_code', 'visible' => false, 'order' => 1, 'width' => null, 'wrap_content' => false],
        ];

        $put = $this->putJson(
            'api/table-column-preference/article/'.self::TIPO_CON_AMBITO,
            ['columns' => $columnas]
        );

        $put->assertStatus(200);

        // La fila tiene que quedar guardada con el preference_type completo, no recortado ni
        // colapsado contra el genérico.
        $preferencia = $this->preferencia($owner->id, 'article', self::TIPO_CON_AMBITO);
        $this->assertNotNull($preferencia);

        $guardadas = (array) $preferencia->columns;
        $this->assertCount(2, $guardadas);
        $this->assertEquals('name', $guardadas[0]['key']);
        $this->assertTrue((bool) $guardadas[0]['visible']);
        $this->assertEquals(250, $guardadas[0]['width']);
        $this->assertEquals('bar_code', $guardadas[1]['key']);
        $this->assertFalse((bool) $guardadas[1]['visible']);
        $this->assertNull($guardadas[1]['width']);

        // Round-trip completo: es la secuencia que hace la SPA al abrir el modal después de guardar.
        $get = $this->getJson('api/table-column-preference/article/'.self::TIPO_CON_AMBITO);
        $get->assertStatus(200);

        $devueltas = $get->json('model.columns');
        $this->assertCount(2, $devueltas);
        $this->assertEquals('name', $devueltas[0]['key']);
        $this->assertEquals('bar_code', $devueltas[1]['key']);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_ambito_no_pisa_la_preferencia_generica_de_busqueda()
    {
        $owner = $this->autenticar();

        $columnas_genericas = [
            ['key' => 'zz_columna_generica', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
        ];

        TableColumnPreference::where('user_id', $owner->id)
            ->where('model_name', 'article')
            ->whereIn('preference_type', ['search', self::TIPO_CON_AMBITO])
            ->delete();

        $generica = TableColumnPreference::create([
            'user_id'         => $owner->id,
            'model_name'      => 'article',
            'preference_type' => 'search',
            'columns'         => $columnas_genericas,
        ]);

        $put = $this->putJson(
            'api/table-column-preference/article/'.self::TIPO_CON_AMBITO,
            ['columns' => [
                ['key' => 'zz_columna_del_ambito', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
            ]]
        );

        $put->assertStatus(200);

        // El punto entero de la feature: el ámbito guarda en su propia fila. Si el arreglo hubiera
        // sido "aceptar todo y normalizar al genérico", esta aserción se cae.
        $this->assertEquals($columnas_genericas, (array) $generica->fresh()->columns);

        $filas = TableColumnPreference::where('user_id', $owner->id)
            ->where('model_name', 'article')
            ->whereIn('preference_type', ['search', self::TIPO_CON_AMBITO])
            ->count();

        $this->assertEquals(2, $filas);
    }

    /**
     * @group preferencias
     * @test
     */
    public function un_preference_type_invalido_sigue_dando_404()
    {
        $this->autenticar();

        // `searchx` está a propósito: el patrón exige el guion bajo, así que "empieza con search"
        // no alcanza. Es la red contra un arreglo que termine aceptando cualquier cosa.
        foreach (['foo_bar', 'searchx'] as $preference_type) {
            $this->getJson('api/table-column-preference/article/'.$preference_type)
                ->assertStatus(404);

            $this->putJson(
                'api/table-column-preference/article/'.$preference_type,
                ['columns' => [
                    ['key' => 'name', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
                ]]
            )->assertStatus(404);
        }
    }
}
