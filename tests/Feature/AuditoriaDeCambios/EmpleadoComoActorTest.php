<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\Article;

/**
 * Test 3 del plan: cuando quien guarda es un EMPLEADO, el actor es el empleado y el `user_id`
 * (dueño del comercio) es su dueño (misión auditoria-de-cambios, 30/9/2026).
 *
 * Es el caso que justifica tener dos columnas: `user_id` sirve para "todo lo de este comercio" y
 * `actor_id` para "todo lo que hizo esta persona". Un empleado tiene `owner_id` apuntando a su
 * dueño; el dueño tiene `owner_id` en null y es su propio comercio.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class EmpleadoComoActorTest extends AuditoriaTestCase
{
    /**
     * Un empleado da de alta un artículo por el endpoint real.
     *
     * @return void
     */
    public function test_un_empleado_que_crea_un_articulo_queda_como_actor_y_su_dueno_como_user_id()
    {
        $empleado = $this->crear_empleado('ZZ Empleado que carga');

        $this->actingAs($empleado, 'web');

        $respuesta = $this->postJson('/api/article', $this->payload_articulo(['name' => 'ZZ Auditoria de empleado']));

        $respuesta->assertStatus(201);

        $fila = $this->filas(Article::class, 'created')->first();

        $this->assertNotNull($fila, 'El alta del empleado no dejó fila.');
        $this->assertSame((int) $empleado->id, (int) $fila->actor_id, 'El actor es el empleado, no el dueño.');
        $this->assertSame((int) $this->dueno->id, (int) $fila->user_id, 'El user_id es el dueño del comercio.');
        $this->assertSame('ZZ Empleado que carga', $fila->actor_name);
    }

    /**
     * Y el dueño es actor y dueño a la vez.
     *
     * @return void
     */
    public function test_el_dueno_es_su_propio_actor_y_su_propio_dueno()
    {
        $this->crear_articulo();

        $fila = $this->filas(Article::class, 'created')->first();

        $this->assertSame((int) $this->dueno->id, (int) $fila->actor_id);
        $this->assertSame((int) $this->dueno->id, (int) $fila->user_id);
    }

    /**
     * Sin sesión (consola, cola) no hay actor: el dueño sale del `user_id` del propio modelo.
     *
     * @return void
     */
    public function test_sin_sesion_no_hay_actor_y_el_dueno_sale_del_modelo()
    {
        // Sin nadie logueado: como un comando de consola o un worker.
        $this->app['auth']->forgetGuards();

        $articulo = $this->crear_articulo(['name' => 'ZZ Auditoria sin sesion']);

        $fila = $this->filas(Article::class, 'created')->where('auditable_id', $articulo->id)->first();

        $this->assertNotNull($fila);
        $this->assertNull($fila->actor_id, 'Sin sesión no hay actor.');
        $this->assertNull($fila->actor_name);
        $this->assertSame((int) $this->dueno->id, (int) $fila->user_id, 'Sin actor, el dueño es el user_id del modelo.');
        $this->assertSame('console', $fila->source, 'Sin request ni job, es consola.');
        $this->assertNull($fila->ip);
    }
}
