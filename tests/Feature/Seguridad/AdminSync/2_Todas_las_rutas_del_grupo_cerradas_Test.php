<?php

namespace Tests\Feature\Seguridad\AdminSync;

use App\Http\Middleware\AdminApiKey;
use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * TODAS las rutas del grupo `admin.api.key` (las `api/admin-sync/*`) quedan cerradas con la clave de la
 * instancia cargada y la variable ADMIN_SYNC_REQUIRE_API_KEY sin definir (misión cerrar-admin-sync,
 * 10/10/2026).
 *
 * EL CASO: de las 43 rutas, 24 no tenían ninguna validación propia (leer ventas y clientes del dueño con
 * `sistema-query`, importar artículos, cambiar el plan de pago con `mensualidad-update`, reemitir el token
 * de la demo, cambiar a dónde manda el login). Solo las cerraba el flag, que en producción nadie define.
 *
 * El recorrido sale del ROUTER, no de una lista a mano: una ruta nueva que se sume al grupo entra sola al
 * test. Para cada una:
 *
 *   - Sin header: 401 `{"error":"unauthorized"}` y CERO consultas a la base durante el request (la puerta se
 *     cierra antes del controlador, no después de haber leído algo). Con un control positivo de que el
 *     contador cuenta: el mismo request con la clave sí consulta.
 *   - Con header malo: 401 también.
 *   - Con el header correcto: NO es 401 (puede ser 404/409/422/200 del controlador; lo que importa es que la
 *     clave abre la puerta). Corre dentro de la transacción del test y con el cuerpo vacío.
 */
class Todas_las_rutas_del_grupo_cerradas_Test extends AdminSyncTestCase
{
    /**
     * Las rutas del router que pasan por AdminApiKey (con el middleware ya resuelto: grupos expandidos).
     *
     * @return array<int, RutaDeLaravel>
     */
    protected function rutas_del_grupo()
    {
        $rutas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $middleware = app('router')->gatherRouteMiddleware($ruta);

            if (in_array(AdminApiKey::class, $middleware, true)) {
                $rutas[] = $ruta;
            }
        }

        return $rutas;
    }

    /**
     * La URL concreta de una ruta: los parámetros (`{id}`, `{user_id?}`) se rellenan con 1.
     *
     * @param  RutaDeLaravel $ruta
     * @return string
     */
    protected function url_de($ruta)
    {
        return '/' . preg_replace('/\{[^}]+\}/', '1', $ruta->uri());
    }

    /**
     * El método con el que se prueba la ruta (el primero que no sea HEAD).
     *
     * @param  RutaDeLaravel $ruta
     * @return string
     */
    protected function metodo_de($ruta)
    {
        foreach ($ruta->methods() as $metodo) {
            if ($metodo !== 'HEAD') {
                return $metodo;
            }
        }

        return 'GET';
    }

    /**
     * Cuenta las consultas a la base que ocurren mientras corre el callback.
     *
     * @param  callable $callback
     * @return array{0: mixed, 1: int} [lo que devolvió el callback, cantidad de consultas]
     */
    protected function contando_consultas(callable $callback)
    {
        $consultas = 0;

        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $resultado = $callback();

        return [$resultado, $consultas];
    }

    /** @test */
    public function el_recorrido_encuentra_las_rutas_del_grupo()
    {
        $rutas = $this->rutas_del_grupo();

        /* 43 al armar la misión. Si baja, el recorrido dejó de ver el grupo y los demás tests no prueban nada. */
        $this->assertGreaterThanOrEqual(43, count($rutas), 'El recorrido no encontró las rutas del grupo admin.api.key.');

        foreach ($rutas as $ruta) {
            $this->assertStringStartsWith('api/admin-sync/', $ruta->uri());
        }
    }

    /** @test */
    public function el_contador_de_consultas_cuenta()
    {
        $this->configurar(null, self::CLAVE);

        /* Control positivo: con la clave el request llega al controlador y ahí SÍ se consulta la base. */
        list($respuesta, $consultas) = $this->contando_consultas(function () {
            return $this->withHeaders($this->con_clave(self::CLAVE))->json('GET', '/api/admin-sync/employees');
        });

        $this->assertNotSame(401, $respuesta->getStatusCode());
        $this->assertGreaterThan(0, $consultas, 'El contador no vio ninguna consulta: el test de "cero consultas" no prueba nada.');
    }

    /** @test */
    public function sin_header_todas_las_rutas_son_401_y_no_consultan_la_base()
    {
        $this->configurar(null, self::CLAVE);

        $fallas = [];

        foreach ($this->rutas_del_grupo() as $ruta) {
            $metodo = $this->metodo_de($ruta);
            $url    = $this->url_de($ruta);

            list($respuesta, $consultas) = $this->contando_consultas(function () use ($metodo, $url) {
                return $this->json($metodo, $url, []);
            });

            if ($respuesta->getStatusCode() !== 401 || $respuesta->getContent() !== '{"error":"unauthorized"}') {
                $fallas[] = $metodo . ' ' . $url . ' => ' . $respuesta->getStatusCode() . ' (esperaba 401)';
            }

            if ($consultas !== 0) {
                $fallas[] = $metodo . ' ' . $url . ' => ' . $consultas . ' consulta(s) a la base antes de rechazar';
            }
        }

        $this->assertSame([], $fallas, "Rutas abiertas sin la clave:\n" . implode("\n", $fallas));
    }

    /** @test */
    public function con_header_malo_todas_las_rutas_son_401_y_no_consultan_la_base()
    {
        $this->configurar(null, self::CLAVE);

        $fallas = [];

        foreach ($this->rutas_del_grupo() as $ruta) {
            $metodo = $this->metodo_de($ruta);
            $url    = $this->url_de($ruta);

            list($respuesta, $consultas) = $this->contando_consultas(function () use ($metodo, $url) {
                return $this->withHeaders($this->con_clave(self::CLAVE_MALA))->json($metodo, $url, []);
            });

            if ($respuesta->getStatusCode() !== 401) {
                $fallas[] = $metodo . ' ' . $url . ' => ' . $respuesta->getStatusCode() . ' (esperaba 401)';
            }

            if ($consultas !== 0) {
                $fallas[] = $metodo . ' ' . $url . ' => ' . $consultas . ' consulta(s) a la base antes de rechazar';
            }
        }

        $this->assertSame([], $fallas, "Rutas que no rechazan una clave mala:\n" . implode("\n", $fallas));
    }

    /** @test */
    public function con_el_header_correcto_ninguna_ruta_es_401()
    {
        $this->configurar(null, self::CLAVE);

        $fallas = [];

        foreach ($this->rutas_del_grupo() as $ruta) {
            $metodo = $this->metodo_de($ruta);
            $url    = $this->url_de($ruta);

            $respuesta = $this->withHeaders($this->con_clave(self::CLAVE))->json($metodo, $url, []);

            if ($respuesta->getStatusCode() === 401 && $respuesta->getContent() === '{"error":"unauthorized"}') {
                $fallas[] = $metodo . ' ' . $url . ' => 401 de la puerta aun con la clave correcta';
            }
        }

        $this->assertSame([], $fallas, "Rutas que rechazan la clave correcta:\n" . implode("\n", $fallas));
    }

    /** @test */
    public function con_la_variable_en_true_tambien_se_cierran_todas()
    {
        $this->configurar(true, self::CLAVE);

        $fallas = [];

        foreach ($this->rutas_del_grupo() as $ruta) {
            $metodo = $this->metodo_de($ruta);
            $url    = $this->url_de($ruta);

            $respuesta = $this->json($metodo, $url, []);

            if ($respuesta->getStatusCode() !== 401) {
                $fallas[] = $metodo . ' ' . $url . ' => ' . $respuesta->getStatusCode();
            }
        }

        $this->assertSame([], $fallas, "Rutas abiertas con la variable en true:\n" . implode("\n", $fallas));
    }
}
