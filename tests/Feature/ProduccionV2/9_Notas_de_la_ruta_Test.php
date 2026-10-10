<?php

namespace Tests\Feature\ProduccionV2;

use Illuminate\Support\Facades\DB;

/**
 * Las notas de la ruta se guardan (mision produccion-v2-defectos, 10/10/2026).
 *
 * 🔴 EL DEFECTO: la columna `recipe_routes.notes` existe desde la migracion del 5/3/2026 y la
 * SPA tiene el campo "Notas" en el formulario de la ruta, pero `RecipeRouteController@store` y
 * `@update` nunca la nombraban. El usuario escribia la nota, guardaba, la API respondia 201/200
 * y la nota se perdia sin error y sin log: al reabrir la ruta el campo estaba vacio.
 *
 * El `update` solo escribe `notes` si el request trae la clave. No es un detalle: un cliente que
 * no manda `notes` (el SPA de un cliente que todavia no actualizo, o cualquier otro consumidor)
 * no tiene que borrarle la nota a la ruta con un null que nunca pidio.
 *
 * Todo se verifica leyendo la BASE despues de pegarle al endpoint real.
 */
class Notas_de_la_ruta_Test extends ProduccionV2TestCase
{
    /**
     * Los insumos tal como los manda la SPA: el articulo con su `pivot`.
     *
     * Llevan estado porque desde esta misma mision un insumo sin estado se rechaza con 422, y
     * este archivo no esta probando eso.
     *
     * @param  \App\Models\Article                $articulo
     * @param  \App\Models\OrderProductionStatus  $estado
     * @return array
     */
    private function insumos_del_request($articulo, $estado)
    {
        return [
            [
                'id'    => $articulo->id,
                'name'  => $articulo->name,
                'pivot' => [
                    'amount'                        => 2,
                    'notes'                         => null,
                    'order_production_status_id'    => $estado->id,
                    'address_id'                    => null,
                ],
            ],
        ];
    }

    /**
     * Una receta con un insumo y un estado, el fixture comun de los tests de este archivo.
     *
     * @return array [Recipe, Article, OrderProductionStatus]
     */
    private function fixture_basico()
    {
        $estado = $this->crear_estado('Corte notas de ruta', 1);
        $cano   = $this->crear_articulo('Cano notas de ruta test', 100);
        $silla  = $this->crear_articulo('Silla notas de ruta test', 0);
        $receta = $this->crear_receta($silla);

        return [$receta, $cano, $estado];
    }

    /**
     * @group produccion_v2
     * @test
     */
    public function crear_una_ruta_guarda_sus_notas()
    {
        list($receta, $cano, $estado) = $this->fixture_basico();

        $respuesta = $this->postJson('api/recipe-route', [
            'model_id'  => $receta->id,
            'notes'     => 'Cortar primero los canos y recien despues soldar.',
            'articles'  => $this->insumos_del_request($cano, $estado),
        ]);

        $respuesta->assertStatus(201);

        $ruta_id = $respuesta->json('model.id');

        $this->assertDatabaseHas('recipe_routes', [
            'id'        => $ruta_id,
            'recipe_id' => $receta->id,
            'notes'     => 'Cortar primero los canos y recien despues soldar.',
        ]);

        /* Y la respuesta, que es lo que la SPA vuelve a pintar, trae la misma nota. */
        $this->assertSame('Cortar primero los canos y recien despues soldar.', $respuesta->json('model.notes'));
    }

    /**
     * @group produccion_v2
     * @test
     */
    public function editar_una_ruta_cambia_sus_notas()
    {
        list($receta, $cano, $estado) = $this->fixture_basico();

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano, 'amount' => 2, 'order_production_status_id' => $estado->id],
        ]);

        \Illuminate\Support\Facades\DB::table('recipe_routes')->where('id', $ruta->id)->update(['notes' => 'Nota de antes']);

        $respuesta = $this->putJson('api/recipe-route/'.$ruta->id, [
            'notes'     => 'Nota nueva',
            'articles'  => $this->insumos_del_request($cano, $estado),
        ]);

        $respuesta->assertStatus(200);

        $this->assertDatabaseHas('recipe_routes', ['id' => $ruta->id, 'notes' => 'Nota nueva']);
        $this->assertSame('Nota nueva', $respuesta->json('model.notes'));
    }

    /**
     * 🔴 UN PUT SIN LA CLAVE `notes` NO LA PISA CON NULL.
     *
     * El resto de los campos del update se asignan siempre (si faltan quedan en null, como
     * siempre fue), pero la nota es el campo nuevo: un consumidor que no la conoce no puede
     * borrarla sin querer.
     *
     * @group produccion_v2
     * @test
     */
    public function editar_una_ruta_sin_mandar_notas_no_las_pisa()
    {
        list($receta, $cano, $estado) = $this->fixture_basico();

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano, 'amount' => 2, 'order_production_status_id' => $estado->id],
        ]);

        \Illuminate\Support\Facades\DB::table('recipe_routes')->where('id', $ruta->id)->update(['notes' => 'Esta nota tiene que sobrevivir']);

        $respuesta = $this->putJson('api/recipe-route/'.$ruta->id, [
            'articles' => $this->insumos_del_request($cano, $estado),
        ]);

        $respuesta->assertStatus(200);

        $this->assertDatabaseHas('recipe_routes', ['id' => $ruta->id, 'notes' => 'Esta nota tiene que sobrevivir']);
    }

    /**
     * Si el usuario BORRA la nota en el formulario, la SPA manda la clave vacia y la nota se
     * limpia: es el otro lado de la regla de arriba (la clave presente SI se escribe).
     *
     * @group produccion_v2
     * @test
     */
    public function mandar_las_notas_vacias_las_limpia()
    {
        list($receta, $cano, $estado) = $this->fixture_basico();

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano, 'amount' => 2, 'order_production_status_id' => $estado->id],
        ]);

        \Illuminate\Support\Facades\DB::table('recipe_routes')->where('id', $ruta->id)->update(['notes' => 'Nota que el usuario borra']);

        $respuesta = $this->putJson('api/recipe-route/'.$ruta->id, [
            'notes'     => '',
            'articles'  => $this->insumos_del_request($cano, $estado),
        ]);

        $respuesta->assertStatus(200);

        $this->assertNull(\Illuminate\Support\Facades\DB::table('recipe_routes')->where('id', $ruta->id)->value('notes'));
    }
}
