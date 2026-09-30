<?php

namespace Tests\Feature\AuditoriaDeCambios;

/**
 * Test 2 del plan: un `save()` que no cambia nada, o que solo toca campos ignorados
 * (`updated_at`, el candado de sesión, el token de "recordarme"), NO deja ninguna fila (misión
 * auditoria-de-cambios, 30/9/2026).
 *
 * Es lo que evita que la tabla se llene de ruido: el candado de sesión única de `users`
 * (`session_id` / `last_activity`) se reescribe en cada request autenticado, y sin el filtro cada
 * click de cada usuario dejaría una fila.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class SinCambiosNoAuditaTest extends AuditoriaTestCase
{
    /**
     * Guardar sin tocar nada no dispara ni siquiera el evento `updated`.
     *
     * @return void
     */
    public function test_un_save_sin_cambios_no_deja_filas()
    {
        $articulo = $this->crear_articulo();

        $desde = $this->filas()->count();

        $articulo->save();

        $this->assertSame($desde, $this->filas()->count(), 'Un save() sin cambios no tiene que auditar nada.');
    }

    /**
     * `touch()` solo mueve `updated_at`: es un campo ignorado, no hay nada que registrar.
     *
     * @return void
     */
    public function test_un_touch_que_solo_mueve_updated_at_no_deja_filas()
    {
        $articulo = $this->crear_articulo();

        $desde = $this->filas()->count();

        $articulo->touch();

        $this->assertSame($desde, $this->filas()->count(), 'Mover solo updated_at no es un cambio de negocio.');
    }

    /**
     * El candado de sesión y el remember_token del usuario se tocan en cada request: ignorados.
     *
     * @return void
     */
    public function test_las_columnas_de_sesion_del_usuario_no_dejan_filas()
    {
        $empleado = $this->crear_empleado();

        $desde = $this->filas()->count();

        $empleado->session_id    = 'sesion-de-prueba';
        $empleado->last_activity = now();
        $empleado->remember_token = 'token-de-prueba';
        $empleado->save();

        $this->assertSame($desde, $this->filas()->count(), 'session_id, last_activity y remember_token no cuentan como cambio.');

        // Control: el mismo save con un cambio real SÍ deja una fila, y solo con ese campo.
        $empleado->name = 'ZZ Empleado renombrado';
        $empleado->session_id = 'otra-sesion';
        $empleado->save();

        $filas = $this->filas(\App\Models\User::class, 'updated');

        $this->assertCount(1, $filas);

        $nuevos = json_decode($filas->first()->new_values, true);

        $this->assertSame(['name'], array_keys($nuevos), 'Solo el campo que cambió de verdad, sin los ignorados.');
        $this->assertSame('ZZ Empleado renombrado', $nuevos['name']);
    }
}
