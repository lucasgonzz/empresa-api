<?php

namespace Tests\Feature\AuditoriaDeCambios;

use Illuminate\Support\Facades\DB;

/**
 * Test 10 del plan: guardar un artículo agrega EXACTAMENTE un INSERT a `audit_logs` y ninguna otra
 * consulta; guardar 50 agrega 50 inserts y CERO selects a `users` (misión auditoria-de-cambios,
 * 30/9/2026).
 *
 * Es la prueba de la regla "cero consultas extra por fila": el listener corre por cada fila que el
 * sistema guarda, así que una sola consulta de más (por ejemplo, `UserHelper::user()`, que hace un
 * `User::find()` en cada llamada) se multiplica por el tamaño de una importación. Se mide
 * comparando las consultas de la MISMA operación con el interruptor apagado y prendido: la
 * diferencia tiene que ser solo los INSERT de auditoría, sin depender de cuántas consultas haga
 * por su cuenta el observer del artículo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class ConsultasExtraTest extends AuditoriaTestCase
{
    /**
     * Ejecuta el callback con el log de consultas prendido y devuelve las consultas que hizo.
     *
     * @param callable $accion
     * @return array Lista de textos SQL.
     */
    protected function consultas_de(callable $accion)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $accion();

        $consultas = array_map(function ($consulta) {
            return $consulta['query'];
        }, DB::getQueryLog());

        DB::disableQueryLog();

        return $consultas;
    }

    /**
     * Cuenta las consultas cuyo SQL contiene el texto.
     *
     * @param array $consultas
     * @param string $texto
     * @return int
     */
    protected function contar($consultas, $texto)
    {
        return count(array_filter($consultas, function ($sql) use ($texto) {
            return strpos($sql, $texto) !== false;
        }));
    }

    /**
     * Un save() agrega un solo INSERT a audit_logs y nada más.
     *
     * @return void
     */
    public function test_guardar_un_articulo_agrega_exactamente_un_insert_y_ninguna_otra_consulta()
    {
        $articulo = $this->crear_articulo(['name' => 'ZZ Auditoria consultas', 'cost' => 100]);

        // Con la auditoría apagada: las consultas que hace el guardado por sí solo.
        config(['audit_log.habilitado' => false]);

        $sin = $this->consultas_de(function () use ($articulo) {
            $articulo->cost = 101;
            $articulo->save();
        });

        // Con la auditoría prendida: las mismas, más las de la auditoría.
        config(['audit_log.habilitado' => true]);

        $con = $this->consultas_de(function () use ($articulo) {
            $articulo->cost = 102;
            $articulo->save();
        });

        $this->assertSame(0, $this->contar($sin, 'audit_logs'), 'Apagada, la auditoría no toca su tabla.');
        $this->assertSame(1, $this->contar($con, 'insert into `audit_logs`'), 'Un save() = un INSERT de auditoría.');
        $this->assertSame(
            count($sin) + 1,
            count($con),
            'La auditoría tiene que agregar UNA consulta en total (el INSERT), ninguna otra.'
        );
    }

    /**
     * Un alta también: un INSERT de auditoría por el INSERT del artículo.
     *
     * @return void
     */
    public function test_un_alta_agrega_un_solo_insert_de_auditoria()
    {
        config(['audit_log.habilitado' => false]);

        $sin = $this->consultas_de(function () {
            $this->crear_articulo(['name' => 'ZZ Auditoria alta sin']);
        });

        config(['audit_log.habilitado' => true]);

        $con = $this->consultas_de(function () {
            $this->crear_articulo(['name' => 'ZZ Auditoria alta con']);
        });

        $this->assertSame(1, $this->contar($con, 'insert into `audit_logs`'));
        $this->assertSame(count($sin) + 1, count($con));
    }

    /**
     * Guardar 50 artículos = 50 inserts y ningún select a `users` de más.
     *
     * @return void
     */
    public function test_guardar_cincuenta_articulos_agrega_cincuenta_inserts_y_cero_selects_a_users()
    {
        $articulos = [];

        for ($i = 1; $i <= 50; $i++) {
            $articulos[] = $this->crear_articulo(['name' => 'ZZ Auditoria lote ' . $i, 'cost' => 100]);
        }

        config(['audit_log.habilitado' => false]);

        $sin = $this->consultas_de(function () use ($articulos) {
            foreach ($articulos as $articulo) {
                $articulo->cost = 200;
                $articulo->save();
            }
        });

        config(['audit_log.habilitado' => true]);

        $con = $this->consultas_de(function () use ($articulos) {
            foreach ($articulos as $articulo) {
                $articulo->cost = 300;
                $articulo->save();
            }
        });

        $this->assertSame(50, $this->contar($con, 'insert into `audit_logs`'), 'Un INSERT por artículo guardado.');
        $this->assertSame(
            $this->contar($sin, 'from `users`'),
            $this->contar($con, 'from `users`'),
            'La auditoría no puede agregar ningún SELECT a users (nada de UserHelper::user() por fila).'
        );
        $this->assertSame(count($sin) + 50, count($con), 'Cincuenta guardados: cincuenta consultas de más, ninguna otra.');
    }
}
