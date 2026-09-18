<?php

namespace Tests\Feature\Preferencias;

use App\Models\TableColumnPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Preferencias de columnas de la tabla principal de un modelo, con ámbito de vista (familia `table_*`).
 *
 * Hasta acá un modelo tenía UNA preferencia `table`: las columnas que el usuario eligió para su
 * tabla principal, sin importar desde qué pantalla la estuviera mirando. La familia `table_*` rompe
 * eso: `table_<ámbito>` guarda las columnas de la misma tabla del mismo modelo, pero para una vista
 * puntual. El primer ámbito es `table_por_entregar` sobre `sale` — la vista Ventas > Por Entregar,
 * que hasta hoy mostraba cinco columnas fijas y pasa a ser configurable sin pisar la preferencia
 * `table` de la tabla de ventas común. Es el mismo patrón que `search_*` (test 4 de esta carpeta):
 * un prefijo, un guion bajo obligatorio y un ámbito en minúsculas que arma la SPA.
 *
 * Dos particularidades del payload que nacen con esta vista y este test tiene que probar (no
 * asumir), porque `normalize_column_payload()` no toca ni la key ni el order:
 *
 * - Keys con punto (`client.name`, `client.description`): son propiedades de un modelo relacionado
 *   que la vista muestra como columnas propias. `columns.*.key` es `required|string` y el punto va
 *   en el VALOR, no en el nombre del atributo, así que la validación no debería partirlo — pero se
 *   verifica el round-trip completo (PUT → fila en la base → GET) con la key intacta.
 *
 * - Varias filas con el MISMO `order`: la SPA agrupa las columnas de una relación en un bloque, y
 *   todas las hijas del bloque comparten la posición del bloque. `update()` ordena con `sortBy('order')`
 *   (un `asort()` de PHP 7.4, que no garantiza estabilidad entre empatados), así que el orden
 *   relativo de dos filas con el mismo `order` NO se aserta: se busca cada columna por su key.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Preferencia_de_columnas_de_tabla_por_ambito_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Modelo cuya tabla estrena la familia: las ventas.
     */
    const MODELO = 'sale';

    /**
     * Primer ámbito real: la vista Ventas > Por Entregar.
     */
    const TIPO_CON_AMBITO = 'table_por_entregar';

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
     * Borra las filas del usuario para `sale` en los tipos indicados, para que cada test mida
     * el caso que quiere y no lo que dejó sembrado la base. Va acotado al usuario del test y no a
     * la tabla entera: DatabaseTransactions revierte esto, pero solo mientras el engine sea
     * transaccional, y `config/database.php` deja el engine configurable por DB_ENGINE.
     *
     * @param int   $user_id
     * @param array $preference_types
     * @return void
     */
    protected function limpiar($user_id, array $preference_types)
    {
        TableColumnPreference::where('user_id', $user_id)
            ->where('model_name', self::MODELO)
            ->whereIn('preference_type', $preference_types)
            ->delete();
    }

    /**
     * Indexa una lista de columnas por su key, para asertar sin depender del orden en que vinieron.
     *
     * @param array $columnas
     * @return array
     */
    protected function por_key(array $columnas)
    {
        $indexadas = [];
        foreach ($columnas as $columna) {
            $indexadas[$columna['key']] = $columna;
        }

        return $indexadas;
    }

    /**
     * @group preferencias
     */
    public function test_el_get_de_un_ambito_de_tabla_sin_fila_guardada_no_da_404()
    {
        $owner = $this->autenticar();

        $this->limpiar($owner->id, [self::TIPO_CON_AMBITO]);

        $response = $this->getJson('api/table-column-preference/'.self::MODELO.'/'.self::TIPO_CON_AMBITO);

        $response->assertStatus(200);

        // Sin fila propia la API devuelve model null: es lo que la SPA espera para caer a las
        // columnas por defecto de la vista en vez de mostrar un error.
        $this->assertNull($response->json('model'));
    }

    /**
     * @group preferencias
     */
    public function test_el_put_guarda_y_el_get_devuelve_las_columnas_con_keys_con_punto_y_orden_compartido()
    {
        $owner = $this->autenticar();

        $this->limpiar($owner->id, [self::TIPO_CON_AMBITO]);

        // `client.name` y `client.description` son el bloque del cliente: comparten order=1.
        $columnas = [
            ['key' => 'num', 'visible' => true, 'order' => 0, 'width' => 100, 'wrap_content' => false],
            ['key' => 'client.name', 'visible' => true, 'order' => 1, 'width' => 200, 'wrap_content' => false],
            ['key' => 'client.description', 'visible' => true, 'order' => 1, 'width' => 250, 'wrap_content' => true],
            ['key' => 'total', 'visible' => false, 'order' => 2, 'width' => null, 'wrap_content' => false],
        ];

        $put = $this->putJson(
            'api/table-column-preference/'.self::MODELO.'/'.self::TIPO_CON_AMBITO,
            ['columns' => $columnas]
        );

        $put->assertStatus(200);

        // La fila tiene que quedar guardada con el preference_type completo, no recortado ni
        // colapsado contra el genérico `table`.
        $preferencia = $this->preferencia($owner->id, self::MODELO, self::TIPO_CON_AMBITO);
        $this->assertNotNull($preferencia);
        $this->assertCount(4, (array) $preferencia->columns);

        // Round-trip completo: es la secuencia que hace la SPA al abrir la vista después de guardar.
        $get = $this->getJson('api/table-column-preference/'.self::MODELO.'/'.self::TIPO_CON_AMBITO);
        $get->assertStatus(200);

        $devueltas = $get->json('model.columns');
        $this->assertCount(4, $devueltas);

        // Las keys con punto sobreviven tal cual: ni partidas, ni escapadas, ni convertidas.
        $por_key = $this->por_key($devueltas);
        $this->assertEqualsCanonicalizing(
            ['client.description', 'client.name', 'num', 'total'],
            array_keys($por_key),
            'Las keys devueltas no son las cuatro que se guardaron'
        );

        // El bloque del cliente: las dos filas conservan el mismo order, cada una con sus propios
        // width y wrap_content.
        $this->assertEquals(1, $por_key['client.name']['order']);
        $this->assertEquals(1, $por_key['client.description']['order']);
        $this->assertEquals(200, $por_key['client.name']['width']);
        $this->assertFalse((bool) $por_key['client.name']['wrap_content']);
        $this->assertEquals(250, $por_key['client.description']['width']);
        $this->assertTrue((bool) $por_key['client.description']['wrap_content']);

        // Las columnas simples, alrededor del bloque.
        $this->assertEquals(0, $por_key['num']['order']);
        $this->assertTrue((bool) $por_key['num']['visible']);
        $this->assertEquals(100, $por_key['num']['width']);
        $this->assertEquals(2, $por_key['total']['order']);
        $this->assertFalse((bool) $por_key['total']['visible']);
        $this->assertNull($por_key['total']['width']);

        // Lo que sí garantiza `sortBy('order')`: los bloques quedan ordenados entre sí. La primera
        // fila es `num` y la última `total`; las dos del medio son el bloque del cliente en el orden
        // que haya dejado el sort, que no se aserta.
        $this->assertEquals('num', $devueltas[0]['key']);
        $this->assertEquals('total', $devueltas[3]['key']);
    }

    /**
     * @group preferencias
     */
    public function test_el_ambito_no_pisa_la_preferencia_generica_de_tabla()
    {
        $owner = $this->autenticar();

        $this->limpiar($owner->id, ['table', self::TIPO_CON_AMBITO]);

        $columnas_genericas = [
            ['key' => 'zz_columna_generica', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
        ];

        $columnas_del_ambito = [
            ['key' => 'zz_columna_del_ambito', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
            ['key' => 'client.name', 'visible' => true, 'order' => 1, 'width' => null, 'wrap_content' => false],
        ];

        $this->putJson(
            'api/table-column-preference/'.self::MODELO.'/table',
            ['columns' => $columnas_genericas]
        )->assertStatus(200);

        $this->putJson(
            'api/table-column-preference/'.self::MODELO.'/'.self::TIPO_CON_AMBITO,
            ['columns' => $columnas_del_ambito]
        )->assertStatus(200);

        // El punto entero de la feature: el ámbito guarda en su propia fila. Si el arreglo hubiera
        // sido "aceptar todo y normalizar al genérico", acá habría una sola fila con las columnas
        // del segundo PUT.
        $filas = TableColumnPreference::where('user_id', $owner->id)
            ->where('model_name', self::MODELO)
            ->whereIn('preference_type', ['table', self::TIPO_CON_AMBITO])
            ->count();

        $this->assertEquals(2, $filas);

        $generica = $this->preferencia($owner->id, self::MODELO, 'table');
        $this->assertNotNull($generica);
        $this->assertEquals(['zz_columna_generica'], array_keys($this->por_key((array) $generica->columns)));

        $del_ambito = $this->preferencia($owner->id, self::MODELO, self::TIPO_CON_AMBITO);
        $this->assertNotNull($del_ambito);
        $this->assertEqualsCanonicalizing(
            ['client.name', 'zz_columna_del_ambito'],
            array_keys($this->por_key((array) $del_ambito->columns)),
            'Las columnas del ámbito no son las que se guardaron'
        );
    }

    /**
     * @group preferencias
     */
    public function test_un_preference_type_de_tabla_mal_formado_sigue_dando_404()
    {
        $this->autenticar();

        // `tablex` está a propósito: el patrón exige el guion bajo, así que "empieza con table" no
        // alcanza — lo frena el controller. `table-por-entregar` (guion medio) ni siquiera llega:
        // lo frena el `where('preference_type', '[a-z0-9_]+')` de la ruta. Los dos son 404 para
        // el cliente, y los dos son la red contra un arreglo que termine aceptando cualquier cosa.
        foreach (['tablex', 'table-por-entregar'] as $preference_type) {
            $this->getJson('api/table-column-preference/'.self::MODELO.'/'.$preference_type)
                ->assertStatus(404);

            $this->putJson(
                'api/table-column-preference/'.self::MODELO.'/'.$preference_type,
                ['columns' => [
                    ['key' => 'num', 'visible' => true, 'order' => 0, 'width' => null, 'wrap_content' => false],
                ]]
            )->assertStatus(404);
        }
    }
}
