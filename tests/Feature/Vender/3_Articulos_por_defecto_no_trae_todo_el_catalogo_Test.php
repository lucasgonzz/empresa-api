<?php

namespace Tests\Feature\Vender;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature test del fix a ArticleController::articles_por_defecto() (14/9/2026, reporte de
 * El Kiosco Verde).
 *
 * La columna articles.default_in_vender es un INT que arranca en 0 para los articulos que
 * nunca se marcaron (no en NULL): un articulo viejo, nunca tocado por nadie, tiene 0. El
 * endpoint filtraba con whereNotNull(), que incluye esos 0 igual que los marcados de verdad
 * -en El Kiosco Verde eso era 13.499 articulos en vez de 7-, y con withAll() de por medio
 * (28 relaciones) el endpoint nunca respondia a tiempo. El fix pasa a where(>0).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria. Cada test crea sus propios articulos con user_id 500.
 */
class Articulos_por_defecto_no_trae_todo_el_catalogo_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patron que Catalogos/Sales/Stock).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Crea un articulo activo con el valor de default_in_vender que le pidan.
     *
     * @param  string $nombre
     * @param  int|null $default_in_vender
     * @param  string $status
     * @return Article
     */
    protected function crear_articulo($nombre, $default_in_vender, $status = 'active')
    {
        return Article::create([
            'name' => $nombre,
            'user_id' => 500,
            'status' => $status,
            'default_in_vender' => $default_in_vender,
        ]);
    }

    /**
     * Nombres de los articulos devueltos, en el orden en que vinieron.
     *
     * @param  \Illuminate\Testing\TestResponse $response
     * @return array
     */
    protected function nombres_de($response)
    {
        $nombres = [];
        foreach ($response->json('models') as $model) {
            $nombres[] = $model['name'];
        }

        return $nombres;
    }

    /**
     * El criterio de aceptacion central: un articulo viejo en default_in_vender=0 (el caso de
     * los 13.499 de El Kiosco Verde) o en NULL no tiene que aparecer. Solo los que tienen un
     * valor mayor a 0 -marcados de verdad- aparecen, y en orden DESC por posicion.
     *
     * @group vender
     * @test
     */
    public function solo_trae_los_articulos_marcados_y_en_cero_o_null_los_deja_afuera()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->crear_articulo('zzdefecto Viejo sin marcar (cero)', 0);
        $this->crear_articulo('zzdefecto Nunca tocado (null)', null);
        $this->crear_articulo('zzdefecto Marcado posicion 1', 1);
        $this->crear_articulo('zzdefecto Marcado posicion 3', 3);
        $this->crear_articulo('zzdefecto Marcado pero inactivo', 1, 'inactive');

        $response = $this->getJson('api/articles-por-defecto');

        $response->assertStatus(200);

        $nombres = $this->nombres_de($response);

        $this->assertNotContains('zzdefecto Viejo sin marcar (cero)', $nombres);
        $this->assertNotContains('zzdefecto Nunca tocado (null)', $nombres);
        $this->assertNotContains('zzdefecto Marcado pero inactivo', $nombres);
        $this->assertContains('zzdefecto Marcado posicion 1', $nombres);
        $this->assertContains('zzdefecto Marcado posicion 3', $nombres);

        // orderBy('default_in_vender', 'DESC'): la posicion 3 va antes que la 1.
        $pos_3 = array_search('zzdefecto Marcado posicion 3', $nombres);
        $pos_1 = array_search('zzdefecto Marcado posicion 1', $nombres);
        $this->assertLessThan($pos_1, $pos_3);
    }

    /**
     * Dos articulos nuevos, ninguno marcado (0 y NULL): ninguno de los dos tiene que aparecer.
     * No se asume que la base de testing esta vacia para el usuario 500 -esta rama la deja
     * sembrada de antes-, por eso se busca por nombre y no por conteo total de la respuesta.
     *
     * @group vender
     * @test
     */
    public function articulos_nuevos_sin_marcar_no_aparecen()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->crear_articulo('zzdefecto2 Uno en cero', 0);
        $this->crear_articulo('zzdefecto2 Otro en null', null);

        $response = $this->getJson('api/articles-por-defecto');

        $response->assertStatus(200);

        $nombres = $this->nombres_de($response);

        $this->assertNotContains('zzdefecto2 Uno en cero', $nombres);
        $this->assertNotContains('zzdefecto2 Otro en null', $nombres);
    }
}
