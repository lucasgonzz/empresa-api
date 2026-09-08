<?php

namespace Tests\Feature\Paginacion;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests de la paginación de `POST /api/global-search/{model}`, el endpoint por el que
 * paginan TODOS los listados del sistema (8/9/2026).
 *
 * Nacieron de un bug que dejó la paginación de todos los módulos clavada en la página 1, y que
 * sobrevivió a un primer arreglo porque tenía DOS causas encimadas:
 *
 * 1. El front mandaba `{ page: 1 }` adentro del cuerpo del POST (se le colaba desde el payload
 *    persistido del buscador). Laravel resuelve `page` con $request->input(), que une body +
 *    query string con el BODY como operando izquierdo: el `?page=N` de la URL quedaba anulado.
 *    Medido: con `?page=3` y `{"page":1}`, resolveCurrentPage() devuelve 1.
 * 2. El ORDER BY no tenía desempate por clave primaria, así que con LIMIT/OFFSET sobre una
 *    columna con valores repetidos (`created_at` es el fallback, y se repite en todo catálogo
 *    importado en lote) MySQL podía devolver la misma fila en dos páginas distintas.
 *
 * Los cuatro escenarios de abajo son los cuatro caminos por los que un usuario llega a paginar,
 * y los pidió Lucas explícitamente: listado por defecto, buscador general, ordenar por columna y
 * filtrar por columna. Cada uno arma el cuerpo del POST como lo arma el front en ese camino.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Paginacion_de_global_search_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patrón que el resto de la suite).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Crea proveedores con el MISMO created_at a propósito: es el escenario que rompe la
     * paginación cuando el ORDER BY no tiene desempate único, y es lo que pasa de verdad en un
     * catálogo importado en lote.
     *
     * @param  int $user_id
     * @param  int $cantidad
     * @return void
     */
    protected function crear_providers_empatados($user_id, $cantidad)
    {
        $fecha_compartida = '2026-01-15 10:00:00';

        for ($i = 0; $i < $cantidad; $i++) {
            $provider = Provider::create([
                'name'    => 'zz-paginacion-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'user_id' => $user_id,
                'status'  => 'active',
            ]);

            // Se fuerza por query: Eloquent pisa created_at con el timestamp actual al crear.
            DB::table('providers')
                ->where('id', $provider->id)
                ->update(['created_at' => $fecha_compartida, 'updated_at' => $fecha_compartida]);
        }
    }

    /**
     * Pega al endpoint de paginación tal como lo hace el front: página en el query string y el
     * criterio de búsqueda en el cuerpo.
     *
     * @param  int   $page
     * @param  array $cuerpo
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_pagina($page, array $cuerpo)
    {
        return $this->postJson('api/global-search/provider?page=' . $page, $cuerpo);
    }

    /**
     * Ids devueltos por una respuesta del endpoint.
     *
     * @param  \Illuminate\Testing\TestResponse $response
     * @return array
     */
    protected function ids_de($response)
    {
        $filas = $response->json('models.data');

        if (!is_array($filas)) {
            return [];
        }

        $ids = [];

        foreach ($filas as $fila) {
            $ids[] = $fila['id'];
        }

        return $ids;
    }

    /**
     * Prepara el escenario común: usuario logueado y 30 proveedores empatados en created_at.
     *
     * @return \App\Models\User
     */
    protected function preparar_escenario()
    {
        $user = $this->usuario_de_testing();

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');
        $this->crear_providers_empatados($user->id, 30);

        return $user;
    }

    /**
     * EL CANDADO DE LA REGRESIÓN. Un `page` en el cuerpo del POST no puede anular al de la URL.
     *
     * Este es exactamente el request que mandaba el front y que dejó la paginación clavada: la
     * URL pedía la página 2 y el cuerpo traía `page: 1` colado desde el payload persistido.
     *
     * @group paginacion
     * @test
     */
    public function un_page_en_el_cuerpo_no_pisa_al_de_la_url()
    {
        $this->preparar_escenario();

        $cuerpo_con_page_colado = [
            'query_value'     => '',
            'props'           => [],
            'order_by'        => 'id',
            'order_direction' => 'DESC',
            'per_page'        => 5,
            // El intruso: así venía el cuerpo antes del arreglo del 8/9/2026.
            'page'            => 1,
        ];

        $response = $this->pedir_pagina(2, $cuerpo_con_page_colado);

        $response->assertStatus(200);
        $response->assertJsonPath('models.current_page', 2);
    }

    /**
     * Escenario 1 de Lucas: entrar a un módulo (listado por defecto, ordenado por id DESC) y
     * empezar a paginar.
     *
     * @group paginacion
     * @test
     */
    public function pagina_bien_en_el_listado_por_defecto()
    {
        $this->preparar_escenario();

        $cuerpo = [
            'query_value'     => '',
            'props'           => [],
            'relation_props'  => [],
            'order_by'        => 'id',
            'order_direction' => 'DESC',
            'per_page'        => 5,
            // 🔴 `page` va adentro del cuerpo A PROPÓSITO: así lo mandaba el front cuando el bug
            // estaba vivo. Sin esto el test pasa con el backend roto (medido el 8/9/2026) y deja
            // de ser un candado: probaría que el endpoint pagina, no que el bug no vuelve.
            'page'            => 1,
        ];

        $ids_pagina_1 = $this->ids_de($this->pedir_pagina(1, $cuerpo));
        $ids_pagina_2 = $this->ids_de($this->pedir_pagina(2, $cuerpo));

        $this->assertCount(5, $ids_pagina_1);
        $this->assertCount(5, $ids_pagina_2);
        $this->assertEmpty(
            array_intersect($ids_pagina_1, $ids_pagina_2),
            'La pagina 2 del listado por defecto repite filas de la pagina 1.'
        );
    }

    /**
     * Escenario 2 de Lucas: buscar con el buscador general y paginar los resultados.
     *
     * El buscador general NO manda `order_by`, así que el backend cae al fallback `created_at`
     * — que acá está empatado a propósito en las 30 filas. Sin el desempate por clave primaria,
     * este test es el que se pone rojo.
     *
     * @group paginacion
     * @test
     */
    public function pagina_bien_con_el_buscador_general()
    {
        $this->preparar_escenario();

        $cuerpo = [
            'query_value'    => 'zz-paginacion',
            'props'          => ['name'],
            'relation_props' => [],
            'conector'       => 'or',
            'per_page'       => 5,
            // 🔴 `page` va adentro del cuerpo A PROPÓSITO: así lo mandaba el front cuando el bug
            // estaba vivo. Sin esto el test pasa con el backend roto (medido el 8/9/2026) y deja
            // de ser un candado: probaría que el endpoint pagina, no que el bug no vuelve.
            'page'           => 1,
        ];

        $ids_pagina_1 = $this->ids_de($this->pedir_pagina(1, $cuerpo));
        $ids_pagina_2 = $this->ids_de($this->pedir_pagina(2, $cuerpo));

        $this->assertCount(5, $ids_pagina_1);
        $this->assertCount(5, $ids_pagina_2);
        $this->assertEmpty(
            array_intersect($ids_pagina_1, $ids_pagina_2),
            'La pagina 2 del buscador general repite filas de la pagina 1.'
        );
    }

    /**
     * Escenario 3 de Lucas: ordenar por una columna y paginar.
     *
     * El orden de columna viaja en `filters[].ordenar_de` y lo aplica ColumnFiltersHelper ANTES
     * del orderBy general, así que manda como criterio primario. Se ordena por `status`, que
     * está en 'active' en las 30 filas: empate total, el peor caso posible.
     *
     * @group paginacion
     * @test
     */
    public function pagina_bien_ordenando_por_una_columna_con_empates()
    {
        $this->preparar_escenario();

        $cuerpo = [
            'query_value' => '',
            'props'       => [],
            'per_page'    => 5,
            // 🔴 `page` va adentro del cuerpo A PROPÓSITO: así lo mandaba el front cuando el bug
            // estaba vivo. Sin esto el test pasa con el backend roto (medido el 8/9/2026) y deja
            // de ser un candado: probaría que el endpoint pagina, no que el bug no vuelve.
            'page'        => 1,
            'filters'     => [
                [
                    'key'         => 'status',
                    'type'        => 'text',
                    'ordenar_de'  => 'ASC',
                    'que_contenga' => '',
                    'igual_que'   => '',
                ],
            ],
        ];

        $ids_pagina_1 = $this->ids_de($this->pedir_pagina(1, $cuerpo));
        $ids_pagina_2 = $this->ids_de($this->pedir_pagina(2, $cuerpo));

        $this->assertCount(5, $ids_pagina_1);
        $this->assertCount(5, $ids_pagina_2);
        $this->assertEmpty(
            array_intersect($ids_pagina_1, $ids_pagina_2),
            'La pagina 2 ordenando por una columna con empates repite filas de la pagina 1.'
        );
    }

    /**
     * Escenario 4 de Lucas: filtrar por una columna y paginar. Es el único que ya andaba antes
     * del arreglo, así que este test es una red contra la regresión.
     *
     * @group paginacion
     * @test
     */
    public function pagina_bien_filtrando_por_una_columna()
    {
        $this->preparar_escenario();

        $cuerpo = [
            'query_value'     => '',
            'props'           => [],
            'order_by'        => 'id',
            'order_direction' => 'DESC',
            'per_page'        => 5,
            // 🔴 `page` va adentro del cuerpo A PROPÓSITO: así lo mandaba el front cuando el bug
            // estaba vivo. Sin esto el test pasa con el backend roto (medido el 8/9/2026) y deja
            // de ser un candado: probaría que el endpoint pagina, no que el bug no vuelve.
            'page'            => 1,
            'filters'         => [
                [
                    'key'          => 'name',
                    'type'         => 'text',
                    'que_contenga' => 'zz-paginacion',
                ],
            ],
        ];

        $ids_pagina_1 = $this->ids_de($this->pedir_pagina(1, $cuerpo));
        $ids_pagina_2 = $this->ids_de($this->pedir_pagina(2, $cuerpo));

        $this->assertCount(5, $ids_pagina_1);
        $this->assertCount(5, $ids_pagina_2);
        $this->assertEmpty(
            array_intersect($ids_pagina_1, $ids_pagina_2),
            'La pagina 2 filtrando por columna repite filas de la pagina 1.'
        );
    }

    /**
     * El ORDER BY que llega a MySQL tiene que terminar SIEMPRE con la clave primaria.
     *
     * 🔴 Este test mira el SQL y no los resultados, y es a propósito. Un test que compara las
     * filas de dos páginas NO detecta la falta de desempate: con pocas filas y sin concurrencia
     * InnoDB suele devolverlas en orden de clave primaria igual, así que pasa en verde con y sin
     * el arreglo (medido el 8/9/2026: sacando el desempate, los otros cinco tests de esta clase
     * siguen verdes). Lo que el desempate evita es un comportamiento que MySQL no garantiza pero
     * tampoco prohíbe, y eso solo se puede fijar mirando la consulta.
     *
     * @group paginacion
     * @test
     */
    public function el_order_by_termina_siempre_en_la_clave_primaria()
    {
        $this->preparar_escenario();

        $consultas = [];

        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = $query->sql;
        });

        // Sin order_by y ordenando por una columna empatada: el peor caso de los cuatro caminos.
        $this->pedir_pagina(2, [
            'query_value' => 'zz-paginacion',
            'props'       => ['name'],
            'per_page'    => 5,
            'filters'     => [
                [
                    'key'        => 'status',
                    'type'       => 'text',
                    'ordenar_de' => 'ASC',
                ],
            ],
        ]);

        $selects_con_order = [];

        foreach ($consultas as $sql) {
            if (stripos($sql, 'order by') !== false) {
                $selects_con_order[] = $sql;
            }
        }

        $this->assertNotEmpty($selects_con_order, 'Ninguna consulta llevo order by.');

        foreach ($selects_con_order as $sql) {
            $order_by_final = substr($sql, stripos($sql, 'order by'));

            // La última columna del ORDER BY tiene que ser `id`. Lo que sigue (limit/offset) no
            // forma parte del criterio de orden, por eso el patrón lo admite después.
            $this->assertMatchesRegularExpression(
                '/`?id`?\s+(asc|desc)\s*(limit\b.*)?$/i',
                trim($order_by_final),
                'El ORDER BY no termina en la clave primaria, la paginacion no es determinista: ' . $order_by_final
            );
        }
    }

    /**
     * El endpoint hermano `search()` -por donde pagina la papelera de cada módulo- tiene el mismo
     * contrato y arrastraba el mismo patrón: paginate() sin página explícita.
     *
     * Se blindó junto con globalSearch en vez de esperar a que alguien le mande `page` en el
     * cuerpo: el error ya se cometió una vez en el endpoint de al lado, y el modo de falla es
     * silencioso (200, filas correctas, página equivocada).
     *
     * @group paginacion
     * @test
     */
    public function search_tampoco_deja_que_el_cuerpo_pise_la_pagina_de_la_url()
    {
        $this->preparar_escenario();

        $response = $this->postJson('api/search/provider/null/1?page=2', [
            'per_page' => 5,
            'page'     => 1,
        ]);

        $response->assertStatus(200);
        // search() devuelve el paginador en la raíz (no envuelto en `models`): con $_filters como
        // el string 'null' cae en la rama que retorna $models pelado. Así lo consume la papelera.
        $response->assertJsonPath('current_page', 2);
    }

    /**
     * `search()` ordenaba solo por `created_at DESC`, sin desempate: mismo problema de
     * determinismo que globalSearch, y encima acá pagina la papelera, donde `deleted_at` (un
     * borrado masivo) empata igual de fácil.
     *
     * @group paginacion
     * @test
     */
    public function el_order_by_de_search_termina_en_la_clave_primaria()
    {
        $this->preparar_escenario();

        $consultas = [];

        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = $query->sql;
        });

        $this->postJson('api/search/provider/null/1?page=2', ['per_page' => 5]);

        $con_order = [];

        foreach ($consultas as $sql) {
            if (stripos($sql, 'order by') !== false) {
                $con_order[] = $sql;
            }
        }

        $this->assertNotEmpty($con_order, 'search() no genero ninguna consulta con order by.');

        foreach ($con_order as $sql) {
            $order_by_final = substr($sql, stripos($sql, 'order by'));

            $this->assertMatchesRegularExpression(
                '/`?id`?\s+(asc|desc)\s*(limit\b.*)?$/i',
                trim($order_by_final),
                'El ORDER BY de search() no termina en la clave primaria: ' . $order_by_final
            );
        }
    }

    /**
     * Recorrer todas las páginas tiene que devolver cada fila exactamente una vez: ni repetida
     * ni salteada. Es la propiedad que de verdad importa y la que un test de dos páginas no
     * alcanza a ver.
     *
     * @group paginacion
     * @test
     */
    public function recorrer_todas_las_paginas_no_repite_ni_saltea_filas()
    {
        $this->preparar_escenario();

        // Sin order_by: el peor caso (fallback created_at, empatado en las 30 filas).
        $cuerpo = [
            'query_value'    => 'zz-paginacion',
            'props'          => ['name'],
            'relation_props' => [],
            'conector'       => 'or',
            'per_page'       => 7,
            // Mismo motivo que en los tests de arriba: el cuerpo del front roto.
            'page'           => 1,
        ];

        $vistos = [];

        for ($page = 1; $page <= 5; $page++) {
            $vistos = array_merge($vistos, $this->ids_de($this->pedir_pagina($page, $cuerpo)));
        }

        $this->assertCount(30, $vistos, 'Recorriendo las 5 paginas no salieron las 30 filas.');
        $this->assertCount(30, array_unique($vistos), 'Alguna fila aparecio en mas de una pagina.');
    }
}
