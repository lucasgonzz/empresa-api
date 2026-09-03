<?php

namespace Tests\Feature\Busqueda;

use App\Models\CurrentAcountPaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El buscador no le puede aplicar el filtro por usuario a una tabla que no lo tiene.
 *
 * 🔴 EL CANDADO DE UN 500 REAL. En la produccion de masquito, el 3/9/2026, aparecio diez veces en
 * un solo dia:
 *
 *     SQLSTATE[42S22]: Unknown column 'user_id' in 'WHERE'
 *     (select count(*) as aggregate from `current_acount_payment_methods` where `user_id` = 2700)
 *
 * `current_acount_payment_methods` es un catalogo GLOBAL de la base: no tiene columna `user_id` y
 * su propio index() no filtra. El buscador se lo aplicaba igual y el paginate() reventaba.
 *
 * Le pasa a cualquier cliente en 4.x que abra ese buscador, porque el endpoint dejo de usarse a
 * mano: hoy lo dispara el listado por defecto de cada modulo con solo entrar.
 */
class BuscadorNoFiltraPorUserEnTablasGlobalesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * La premisa del test: si algun dia le agregan `user_id` a esta tabla, este test deja de medir
     * lo que dice medir. Mejor que falle a que pase por el motivo equivocado.
     *
     * @test
     */
    public function la_tabla_del_escenario_sigue_sin_tener_user_id()
    {
        $this->assertFalse(
            Schema::hasColumn('current_acount_payment_methods', 'user_id'),
            'current_acount_payment_methods ahora tiene user_id: este test ya no prueba lo que dice.'
        );
    }

    /**
     * 🔴 Buscar en un modelo global no puede dar 500.
     *
     * @test
     */
    public function buscar_en_una_tabla_global_no_revienta()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'sanctum');

        CurrentAcountPaymentMethod::create(['name' => 'Efectivo del test']);

        $respuesta = $this->postJson('/api/global-search/current_acount_payment_method', ['query' => 'Efectivo']);

        $respuesta->assertStatus(200);
    }

    /**
     * Y devuelve las filas, que es la otra mitad: no alcanza con no reventar.
     *
     * @test
     */
    public function buscar_en_una_tabla_global_devuelve_las_filas()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'sanctum');

        $metodo = CurrentAcountPaymentMethod::create(['name' => 'MetodoGlobalDelTest']);

        $respuesta = $this->postJson('/api/global-search/current_acount_payment_method', ['query' => 'MetodoGlobalDelTest']);
        $respuesta->assertStatus(200);

        $nombres = collect($respuesta->json('models.data') ?: $respuesta->json('models'))
            ->pluck('name')
            ->all();

        $this->assertContains(
            'MetodoGlobalDelTest',
            $nombres,
            'La tabla global no devolvio la fila que acaba de crearse.'
        );

        $metodo->delete();
    }

    /**
     * 🔴 LA OTRA MITAD DEL CANDADO: en una tabla que SI tiene user_id, el filtro se sigue aplicando.
     *
     * Sin esto, "arreglar" el 500 sacando el filtro de todos lados pasaria igual — y estaria
     * mezclando los datos de dos usuarios del mismo comercio.
     *
     * Se mira el SQL que arma el helper y no el resultado de una busqueda, porque lo que hay que
     * fijar es la DECISION (filtrar o no segun la tabla), no cuantas filas devuelve una base
     * sembrada que puede cambiar.
     *
     * @test
     */
    public function el_helper_filtra_por_usuario_solo_donde_la_tabla_lo_admite()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'sanctum');

        $controlador = new \App\Http\Controllers\CommonLaravel\SearchController();
        $metodo = new \ReflectionMethod($controlador, 'query_base_del_modelo');
        $metodo->setAccessible(true);

        /* Tabla POR USUARIO: el where tiene que estar. */
        $this->assertTrue(Schema::hasColumn('clients', 'user_id'));
        $sql_por_usuario = $metodo->invoke($controlador, \App\Models\Client::class)->toSql();
        $this->assertStringContainsString(
            'user_id',
            $sql_por_usuario,
            'Se dejo de filtrar por usuario en una tabla que SI tiene user_id.'
        );

        /* Tabla GLOBAL: el where no puede estar, porque la columna no existe. */
        $this->assertFalse(Schema::hasColumn('current_acount_payment_methods', 'user_id'));
        $sql_global = $metodo->invoke($controlador, CurrentAcountPaymentMethod::class)->toSql();
        $this->assertStringNotContainsString(
            'user_id',
            $sql_global,
            'Se sigue filtrando por user_id una tabla que no tiene esa columna: es el 500 de masquito.'
        );
    }
}
