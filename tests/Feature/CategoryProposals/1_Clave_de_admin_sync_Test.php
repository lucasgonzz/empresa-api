<?php

namespace Tests\Feature\CategoryProposals;

use Illuminate\Support\Facades\Route;

/**
 * La clave de `admin-sync/catalogo/*` en CADA ruta (misión categorizacion-tres-modelos, 5/10/2026).
 * Plan §2.3 y contrato A.
 *
 * Las nueve rutas exigen `X-Admin-Api-Key` ADENTRO del controlador, aunque `ADMIN_SYNC_REQUIRE_API_KEY`
 * esté apagado (hoy lo está en toda la flota): "no hay clave" nunca es un pase libre. Es el molde de
 * `AsistenteWhatsapp/1_Esquema_y_gate_Test.php:111-153` aplicado a cada ruta nueva, no a una sola:
 *
 *   - sin clave → 401 `{"error":"unauthorized"}`, con `require_api_key=false`;
 *   - clave equivocada → 401, exactamente el mismo cuerpo;
 *   - clave NO configurada de este lado → 401, el mismo cuerpo (no se le dice a quien prueba claves si
 *     este cliente tiene una cargada);
 *   - clave bien → pasa (con la exigencia global apagada y con ella prendida);
 *   - dueño no resoluble → 409 `{"message": ...}` y la clave se mira PRIMERO.
 *
 * Por qué hace falta un test que pegue a cada ruta: un middleware que corta antes del controlador no lo
 * ve la suite (clase de error del 15/8/2026), y una ruta nueva que se registre sin pasar por el trait
 * quedaría abierta sin que nada lo avise. Por eso el primer test compara las rutas del router con la
 * matriz.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class Clave_de_admin_sync_Test extends CategoryProposalsTestCase
{
    use ArmaCorridasDeCategorias;

    /**
     * Las nueve rutas, con el método y la URL (con un id de relleno donde va `{run_id}`: la clave se
     * mira antes de buscar nada, así que la corrida no tiene que existir).
     *
     * @return array  [método, url]
     */
    protected function rutas()
    {
        return [
            ['GET',  'api/admin-sync/catalogo/resumen'],
            ['GET',  'api/admin-sync/catalogo/articulos'],
            ['POST', 'api/admin-sync/catalogo/categorias/propuestas'],
            ['GET',  'api/admin-sync/catalogo/categorias/propuestas/actual'],
            ['GET',  'api/admin-sync/catalogo/categorias/propuestas/999999'],
            ['POST', 'api/admin-sync/catalogo/categorias/propuestas/999999/asignaciones'],
            ['GET',  'api/admin-sync/catalogo/categorias/propuestas/999999/pendientes'],
            ['POST', 'api/admin-sync/catalogo/categorias/propuestas/999999/listo'],
            ['POST', 'api/admin-sync/catalogo/categorias/propuestas/999999/descartar'],
        ];
    }

    /**
     * Pega a una ruta con las cabeceras dadas.
     *
     * @param  array $ruta  [método, url]
     * @param  array $cabeceras
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir(array $ruta, array $cabeceras)
    {
        return $this->json($ruta[0], $ruta[1], [], $cabeceras);
    }

    /**
     * 🔴 Las rutas `catalogo/*` del router son EXACTAMENTE las de la matriz de este test: una ruta nueva
     * que nadie probó contra la clave no puede colarse.
     *
     * @group categorias_ia
     * @test
     */
    public function las_rutas_del_router_son_las_de_la_matriz()
    {
        $en_el_router = [];

        foreach (Route::getRoutes() as $route) {

            if (strpos($route->uri(), 'api/admin-sync/catalogo/') === 0) {

                foreach (array_diff($route->methods(), ['HEAD']) as $metodo) {

                    $en_el_router[] = $metodo.' '.preg_replace('/\{[^}]+\}/', '999999', $route->uri());
                }
            }
        }

        $en_la_matriz = [];

        foreach ($this->rutas() as $ruta) {

            $en_la_matriz[] = $ruta[0].' '.$ruta[1];
        }

        sort($en_el_router);
        sort($en_la_matriz);

        $this->assertSame($en_la_matriz, $en_el_router, 'Las rutas admin-sync/catalogo/* del router no coinciden con la matriz de este test: probá la clave de la nueva.');
    }

    /**
     * 🔴 Sin la clave son 401 aunque `require_api_key` esté apagado: es la guarda que evita que el canal
     * quede abierto en producción.
     *
     * @group categorias_ia
     * @test
     */
    public function sin_la_clave_son_401_aunque_require_api_key_este_apagado()
    {
        $this->assertFalse((bool) config('services.admin_api.require_api_key'), 'Este test no prueba nada si require_api_key está prendido.');

        foreach ($this->rutas() as $ruta) {

            $this->pedir($ruta, ['Accept' => 'application/json'])
                ->assertStatus(401)
                ->assertExactJson(['error' => 'unauthorized']);
        }
    }

    /**
     * @group categorias_ia
     * @test
     */
    public function con_una_clave_equivocada_tambien_es_401_con_el_mismo_cuerpo()
    {
        foreach ($this->rutas() as $ruta) {

            $this->pedir($ruta, $this->cabeceras_de_admin(true, 'otra-clave'))
                ->assertStatus(401)
                ->assertExactJson(['error' => 'unauthorized']);
        }

        // Una clave que SOLO difiere en el último carácter tampoco pasa (comparación entera, no prefijo).
        foreach ($this->rutas() as $ruta) {

            $this->pedir($ruta, $this->cabeceras_de_admin(true, self::CLAVE_DE_ADMIN.'X'))
                ->assertStatus(401);
        }
    }

    /**
     * Una instancia que no tiene cargada su propia clave no puede autenticar a nadie: se corta con 401
     * en vez de dejar pasar, y con el MISMO cuerpo que una clave mala. "No hay clave configurada" nunca
     * es un pase libre (ni con el header vacío ni con uno cualquiera).
     *
     * @group categorias_ia
     * @test
     */
    public function sin_clave_configurada_de_este_lado_tambien_es_401()
    {
        foreach (['', null] as $configurada) {

            config(['services.admin_api.api_key' => $configurada]);

            foreach ($this->rutas() as $ruta) {

                // Con un header cualquiera...
                $this->pedir($ruta, $this->cabeceras_de_admin(true, 'cualquier-cosa'))
                    ->assertStatus(401)
                    ->assertExactJson(['error' => 'unauthorized']);

                // ...con un header vacío...
                $this->pedir($ruta, ['Accept' => 'application/json', 'X-Admin-Api-Key' => ''])
                    ->assertStatus(401)
                    ->assertExactJson(['error' => 'unauthorized']);

                // ...y sin header.
                $this->pedir($ruta, $this->cabeceras_de_admin(false))
                    ->assertStatus(401)
                    ->assertExactJson(['error' => 'unauthorized']);
            }
        }
    }

    /**
     * Con la clave bien, cada ruta pasa la guarda y llega a su lógica: ni 401 ni 409 ni el 501 del
     * esqueleto. Los estados esperados son los de una corrida que no existe.
     *
     * @group categorias_ia
     * @test
     */
    public function con_la_clave_bien_cada_ruta_pasa_la_guarda()
    {
        $esperados = [
            'GET api/admin-sync/catalogo/resumen'                                          => 200,
            'GET api/admin-sync/catalogo/articulos'                                        => 200,
            'POST api/admin-sync/catalogo/categorias/propuestas'                           => 422,
            'GET api/admin-sync/catalogo/categorias/propuestas/actual'                     => 404,
            'GET api/admin-sync/catalogo/categorias/propuestas/999999'                     => 404,
            'POST api/admin-sync/catalogo/categorias/propuestas/999999/asignaciones'       => 404,
            'GET api/admin-sync/catalogo/categorias/propuestas/999999/pendientes'          => 404,
            'POST api/admin-sync/catalogo/categorias/propuestas/999999/listo'              => 404,
            'POST api/admin-sync/catalogo/categorias/propuestas/999999/descartar'          => 404,
        ];

        foreach ($this->rutas() as $ruta) {

            $respuesta = $this->pedir($ruta, $this->cabeceras_de_admin());

            $this->assertSame(
                $esperados[$ruta[0].' '.$ruta[1]],
                $respuesta->getStatusCode(),
                $ruta[0].' '.$ruta[1].' con la clave bien contestó '.$respuesta->getStatusCode().': '.$respuesta->getContent()
            );
        }
    }

    /**
     * La clave bien también pasa con la exigencia global PRENDIDA (el middleware `admin.api.key` y el
     * controlador piden lo mismo), y sin clave sigue siendo el mismo 401.
     *
     * @group categorias_ia
     * @test
     */
    public function con_la_exigencia_global_prendida_la_clave_bien_pasa_y_sin_clave_es_401()
    {
        config(['services.admin_api.require_api_key' => true]);

        foreach ($this->rutas() as $ruta) {

            $this->pedir($ruta, ['Accept' => 'application/json'])
                ->assertStatus(401)
                ->assertExactJson(['error' => 'unauthorized']);

            $respuesta = $this->pedir($ruta, $this->cabeceras_de_admin());

            $this->assertNotSame(401, $respuesta->getStatusCode(), $ruta[0].' '.$ruta[1].' no dejó pasar la clave bien con la exigencia prendida.');
            $this->assertNotSame(409, $respuesta->getStatusCode());
        }
    }

    /**
     * 🔴 Dueño no resoluble: con varios comercios en la base y sin `USER_ID` el pedido se corta con 409
     * `{"message": ...}` (no 404: para la skill un 404 es "versión vieja"). Y la CLAVE va primero: sin ella
     * es 401 aunque el dueño tampoco se pueda resolver.
     *
     * @group categorias_ia
     * @test
     */
    public function sin_dueno_resoluble_es_409_y_la_clave_se_mira_primero()
    {
        // En la base de testing hay varios dueños (el fixture, el del test y el vecino).
        config(['app.USER_ID' => null]);

        foreach ($this->rutas() as $ruta) {

            $con_clave = $this->pedir($ruta, $this->cabeceras_de_admin());

            $con_clave->assertStatus(409);
            $this->assertArrayHasKey('message', $con_clave->json(), $ruta[0].' '.$ruta[1]);
            $this->assertStringContainsString('USER_ID', $con_clave->json()['message']);

            $this->pedir($ruta, $this->cabeceras_de_admin(false))
                ->assertStatus(401)
                ->assertExactJson(['error' => 'unauthorized']);
        }
    }

    /**
     * El dueño de `admin-sync` es el de `app.USER_ID` y no el de la sesión: la misma ruta con la sesión
     * del vecino responde sobre el comercio de `USER_ID`. (La ruta no mira la sesión: no es de la SPA.)
     *
     * @group categorias_ia
     * @test
     */
    public function el_dueno_sale_de_user_id_y_no_de_la_sesion()
    {
        $this->crear_articulo('Articulo del dueno de USER_ID');

        $this->actuar_como($this->vecino);

        $respuesta = $this->get_admin('resumen');

        $respuesta->assertStatus(200);
        $this->assertSame((int) $this->owner->id, $respuesta->json()['dueno']['id']);
        $this->assertSame(1, $respuesta->json()['articulos']['total']);
    }
}
