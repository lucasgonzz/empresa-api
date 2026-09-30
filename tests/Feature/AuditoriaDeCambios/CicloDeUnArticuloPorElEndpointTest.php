<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\Article;
use App\Models\AuditLog;

/**
 * Test 1 del plan: crear, editar y borrar un artículo POR EL ENDPOINT REAL deja las filas de
 * auditoría con quién, dónde y qué cambió, y agrupadas por request (misión auditoria-de-cambios,
 * 30/9/2026).
 *
 * Se prueba contra `POST/PUT/DELETE api/article` y no guardando modelos a mano: lo que Lucas quiere
 * poder auditar es lo que hace un usuario desde una pantalla, y solo el request real ejercita el
 * origen (`POST api/article`), la IP, el actor del guard y el lote por request.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class CicloDeUnArticuloPorElEndpointTest extends AuditoriaTestCase
{
    /**
     * Alta: una fila `created` del artículo con el actor, el dueño, el origen y la IP.
     *
     * @return void
     */
    public function test_crear_un_articulo_por_el_endpoint_deja_una_fila_created()
    {
        $respuesta = $this->postJson('/api/article', $this->payload_articulo([
            'name' => 'ZZ Auditoria alta',
            'cost' => 123,
        ]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');
        $this->assertNotNull($id, 'El alta no devolvió el artículo creado.');

        $filas = $this->filas(Article::class, 'created');

        $this->assertCount(1, $filas, 'Un alta tiene que dejar exactamente una fila created del artículo.');

        $fila = $filas->first();

        $this->assertSame((int) $id, (int) $fila->auditable_id);
        $this->assertSame((int) $this->dueno->id, (int) $fila->actor_id, 'El actor es quien está logueado.');
        $this->assertSame((int) $this->dueno->id, (int) $fila->user_id, 'El dueño del comercio.');
        $this->assertSame($this->dueno->name, $fila->actor_name);
        $this->assertSame('http', $fila->source);
        $this->assertSame('POST api/article', $fila->origin, 'Método y ruta, sin query string.');
        $this->assertNotNull($fila->ip, 'En HTTP se guarda la IP.');
        $this->assertNull($fila->old_values, 'Un alta no tiene valores viejos.');

        $nuevos = json_decode($fila->new_values, true);

        $this->assertSame('ZZ Auditoria alta', $nuevos['name']);
        $this->assertEquals(123, $nuevos['cost']);
        $this->assertSame(36, strlen($fila->batch_uuid));
    }

    /**
     * Edición: una fila `updated` con SOLO lo que cambió, el valor viejo y el nuevo.
     *
     * @return void
     */
    public function test_editar_un_articulo_por_el_endpoint_guarda_solo_lo_que_cambio()
    {
        $articulo = $this->crear_articulo(['name' => 'ZZ Auditoria edicion', 'cost' => 100]);

        // Lo que pasó antes (la creación directa) no es lo que se mide acá.
        $desde = (int) AuditLog::max('id');

        /* ArticleController@update lee el id del BODY ($request->id), no del parámetro de ruta. */
        $this->putJson('/api/article/' . $articulo->id, $this->payload_articulo([
            'id'   => $articulo->id,
            'name' => 'ZZ Auditoria edicion',
            'cost' => 250,
        ]))->assertStatus(200);

        $filas = AuditLog::where('id', '>', $desde)
            ->where('auditable_type', Article::class)
            ->where('auditable_id', $articulo->id)
            ->where('event', 'updated')
            ->get();

        $this->assertGreaterThanOrEqual(1, $filas->count(), 'Editar el costo tiene que dejar una fila updated.');

        // El costo aparece en alguna de las filas updated de este request, con viejo y nuevo.
        $fila_costo = null;

        foreach ($filas as $fila) {
            $nuevos = json_decode($fila->new_values, true);

            if (is_array($nuevos) && array_key_exists('cost', $nuevos)) {
                $fila_costo = $fila;
            }
        }

        $this->assertNotNull($fila_costo, 'Ninguna fila updated trae el campo cost.');

        $viejos = json_decode($fila_costo->old_values, true);
        $nuevos = json_decode($fila_costo->new_values, true);

        $this->assertEquals(100, $viejos['cost']);
        $this->assertEquals(250, $nuevos['cost']);
        $this->assertArrayNotHasKey('name', $nuevos, 'El nombre no cambió: no tiene que estar en la fila.');
        $this->assertArrayNotHasKey('updated_at', $nuevos, 'updated_at es un campo ignorado.');
        $this->assertSame('PUT api/article/' . $articulo->id, $fila_costo->origin);
        $this->assertSame('http', $fila_costo->source);
        $this->assertSame((int) $this->dueno->id, (int) $fila_costo->actor_id);
    }

    /**
     * Baja: una fila `deleted` con la fila entera en `old_values`.
     *
     * @return void
     */
    public function test_borrar_un_articulo_por_el_endpoint_deja_una_fila_deleted_con_el_articulo_entero()
    {
        $articulo = $this->crear_articulo(['name' => 'ZZ Auditoria baja', 'cost' => 77]);

        $desde = (int) AuditLog::max('id');

        $this->deleteJson('/api/article/' . $articulo->id)->assertStatus(200);

        $filas = AuditLog::where('id', '>', $desde)
            ->where('auditable_type', Article::class)
            ->where('auditable_id', $articulo->id)
            ->where('event', 'deleted')
            ->get();

        $this->assertCount(1, $filas, 'Borrar tiene que dejar una fila deleted.');

        $fila = $filas->first();
        $viejos = json_decode($fila->old_values, true);

        $this->assertSame('ZZ Auditoria baja', $viejos['name'], 'En un delete queda la fila entera.');
        $this->assertEquals(77, $viejos['cost']);
        $this->assertSame('DELETE api/article/' . $articulo->id, $fila->origin);
        $this->assertSame((int) $this->dueno->id, (int) $fila->actor_id);
    }

    /**
     * Todo lo que salió de UN request comparte el batch_uuid; requests distintos, lotes distintos.
     *
     * @return void
     */
    public function test_todo_lo_de_un_request_comparte_el_lote_y_dos_requests_no()
    {
        $this->postJson('/api/article', $this->payload_articulo(['name' => 'ZZ Auditoria lote 1']))->assertStatus(201);

        $lotes_primero = $this->filas()->pluck('batch_uuid')->unique()->values();
        $this->assertCount(1, $lotes_primero, 'Todo lo que salió del primer request va en un solo lote.');

        $cantidad_primero = $this->filas()->count();

        $this->postJson('/api/article', $this->payload_articulo(['name' => 'ZZ Auditoria lote 2']))->assertStatus(201);

        $lotes = $this->filas()->pluck('batch_uuid')->unique()->values();
        $this->assertCount(2, $lotes, 'Dos requests distintos son dos lotes distintos.');

        $this->assertGreaterThan($cantidad_primero, $this->filas()->count());
    }
}
