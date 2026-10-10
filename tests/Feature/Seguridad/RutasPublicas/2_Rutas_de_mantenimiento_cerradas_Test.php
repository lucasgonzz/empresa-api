<?php

namespace Tests\Feature\Seguridad\RutasPublicas;

use App\Http\Middleware\RutaDeMantenimiento;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * En producción y sin clave, CADA ruta de `routes/mantenimiento.php` da 404 y no toca la base (misión
 * cerrar-rutas-web-publicas, 10/10/2026).
 *
 * Las rutas no se listan a mano: se recorren desde el router (todas las que pasan por
 * RutaDeMantenimiento), así que una ruta que se agregue a ese archivo queda cubierta sin tocar este test.
 * Para cada una se verifica que:
 *   - el pedido llegó a ESA ruta (evento RouteMatched): un 404 de "ninguna ruta coincide" no cuenta;
 *   - el 404 lo dio el middleware (dejó su línea de log con el patrón de la ruta);
 *   - durante el request no se ejecutó NINGUNA consulta a la base (DB::listen registrado justo antes del
 *     request y medido después; la sesión de phpunit es `array`, no consulta nada).
 *
 * 🔴 EL CSRF SE APAGA EN ESTOS TESTS, A PROPÓSITO: VerifyCsrfToken está en el grupo `web`, que corre ANTES
 * que `solo.mantenimiento` (el middleware necesita la sesión). Con `app['env']` en production deja de
 * saltearse y un POST/PUT sin token recibiría su 419 antes de llegar a la puerta. Eso también es "cerrado",
 * pero lo que se quiere medir acá es el 404 de la puerta, que es la que protege también el día que alguien
 * consiga un token (lo da cualquier GET de `web`).
 *
 * Y los casos con estado, por si algún día la puerta dejara pasar: el configurador no cambia extensiones,
 * `helpers/servian` no deja la sesión abierta, `helpers/limpiar_sesiones` no toca `users.session_id`,
 * `register-user` no crea un dueño y `PUT users/payment-expired-at` no cambia la fecha.
 */
class Rutas_de_mantenimiento_cerradas_Test extends RutasPublicasTestCase
{
    /** El dueño de la base de testing (USER_ID del .env.testing). */
    const DUENO_ID = 500;

    /** @var \Illuminate\Routing\Route|null La última ruta que matcheó el router. */
    protected $ruta_que_matcheo;

    /** @var bool Si DB::listen está contando. */
    protected $midiendo = false;

    /** @var array<int, string> Las consultas que se ejecutaron mientras se medía. */
    protected $consultas = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;

        Event::listen(RouteMatched::class, function (RouteMatched $evento) use ($test) {
            $test->ruta_que_matcheo = $evento->route;
        });

        DB::listen(function ($consulta) use ($test) {
            if ($test->midiendo) {
                $test->consultas[] = $consulta->sql;
            }
        });

        // Ver el docblock: lo que se mide es el 404 de la puerta, no el 419 del CSRF.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->como_produccion();
    }

    /**
     * Pide una URI midiendo las consultas a la base solo durante el request.
     *
     * @param  string  $metodo
     * @param  string  $uri
     * @param  array  $datos
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_midiendo($metodo, $uri, array $datos = [])
    {
        $this->ruta_que_matcheo = null;
        $this->consultas = [];
        $this->midiendo = true;

        try {
            return $this->call($metodo, $uri, $datos);
        } finally {
            $this->midiendo = false;
        }
    }

    /**
     * El centro del cambio: todas las rutas del grupo, una por una, por cada método.
     *
     * @test
     */
    public function cada_ruta_de_mantenimiento_da_404_y_no_toca_la_base()
    {
        $rutas = $this->rutas_de_mantenimiento();

        // Las 67 que mudó la misión. Si el número cambia, que sea a propósito (y este test las cubre igual).
        $this->assertGreaterThanOrEqual(67, count($rutas), 'Se esperaban al menos las 67 rutas que mudó la misión.');

        $pedidos = 0;

        foreach ($rutas as $entrada) {

            $ruta = $entrada['ruta'];
            $uri = $this->uri_de_prueba($ruta);

            foreach ($this->metodos_sin_head($ruta) as $metodo) {

                $this->logs = [];

                $respuesta = $this->pedir_midiendo($metodo, $uri);

                $descripcion = $metodo . ' ' . $uri . ' (ruta ' . $ruta->uri() . ')';

                $this->assertNotNull($this->ruta_que_matcheo, $descripcion . ': no matcheó ninguna ruta, el 404 no es de la puerta.');
                $this->assertSame($ruta->uri(), $this->ruta_que_matcheo->uri(), $descripcion . ': matcheó otra ruta.');

                $this->assertSame(404, $respuesta->getStatusCode(), $descripcion . ' no dio 404 en producción sin clave.');

                $this->assertSame([], $this->consultas, $descripcion . ' tocó la base antes de que la puerta lo cortara.');

                $bloqueos = $this->logs_de_bloqueo();
                $this->assertCount(1, $bloqueos, $descripcion . ': el 404 no lo dio RutaDeMantenimiento (no dejó su log).');
                $this->assertSame($ruta->uri(), $bloqueos[0]['contexto']['ruta']);
                $this->assertSame($metodo, $bloqueos[0]['contexto']['metodo']);
                $this->assertFalse($bloqueos[0]['contexto']['con_clave']);

                $pedidos++;
            }
        }

        $this->assertGreaterThanOrEqual(67, $pedidos);
    }

    /**
     * Cada ruta del grupo tiene UNA sola entrada de la puerta: las del configurador la variante `:demo`
     * (la de sin parámetro, que cortaría primero en una demo, tiene que haber quedado afuera), y el resto
     * la de sin parámetro.
     *
     * @test
     */
    public function el_configurador_cambia_la_guarda_por_la_de_demo_y_el_resto_no()
    {
        $configurador = ['extensiones', 'user/extencions/edit/{user_id?}', 'users/{user_id}/extencions'];

        $vistas = [];

        foreach ($this->rutas_de_mantenimiento() as $entrada) {

            $uri = $entrada['ruta']->uri();

            $esperada = in_array($uri, $configurador, true)
                ? RutaDeMantenimiento::class . ':demo'
                : RutaDeMantenimiento::class;

            $this->assertSame([$esperada], $entrada['guardas'], 'La ruta ' . $uri . ' no tiene exactamente ' . $esperada . '.');

            $vistas[] = $uri;
        }

        foreach ($configurador as $uri) {
            $this->assertContains($uri, $vistas, 'La ruta del configurador ' . $uri . ' no pasa por la puerta.');
        }
    }

    /**
     * `POST /users/{id}/extencions` no le cambia las extensiones a nadie (un envío vacío confirmado se
     * las sacaría TODAS).
     *
     * @test
     */
    public function el_configurador_no_cambia_extensiones()
    {
        $extencion = ExtencionEmpresa::forceCreate([
            'name'        => 'Extension zz_test_puerta',
            'slug'        => 'zz_test_puerta',
            'description' => 'De prueba',
            'modulo'      => 'VENDER',
            'en_desuso'   => false,
        ]);

        $dueno = User::find(self::DUENO_ID);
        $dueno->extencions()->sync([$extencion->id]);

        $this->pedir_midiendo('POST', '/users/' . $dueno->id . '/extencions', [
            'extencions'       => [],
            'confirmar_quitar' => '1',
        ])->assertStatus(404);

        $this->assertSame([$extencion->id], $dueno->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->map(function ($id) {
            return (int) $id;
        })->all());
    }

    /**
     * `helpers/servian` hace `Auth::loginUsingId(2800)`: cerrada, no deja ninguna sesión abierta.
     *
     * @test
     */
    public function helpers_servian_no_deja_la_sesion_autenticada()
    {
        $this->assertGuest('web');

        $this->pedir_midiendo('GET', '/helpers/servian')->assertStatus(404);

        $this->assertGuest('web');
    }

    /**
     * `helpers/limpiar_sesiones` borra `session_id` y `last_activity` de TODOS los usuarios: cerrada, no
     * toca nada.
     *
     * @test
     */
    public function helpers_limpiar_sesiones_no_toca_las_sesiones()
    {
        DB::table('users')->where('id', self::DUENO_ID)->update([
            'session_id'    => 'sesion-del-test-de-la-puerta',
            'last_activity' => Carbon::now(),
        ]);

        $this->pedir_midiendo('GET', '/helpers/limpiar_sesiones')->assertStatus(404);

        $this->assertSame('sesion-del-test-de-la-puerta', DB::table('users')->where('id', self::DUENO_ID)->value('session_id'));
    }

    /**
     * `register-user` crea un dueño con contraseña `123`: cerrada, no crea nada.
     *
     * @test
     */
    public function register_user_no_crea_usuarios()
    {
        $antes = DB::table('users')->count();

        $this->pedir_midiendo('GET', '/register-user/Intruso/99887766/Comercio%20intruso/0/1')->assertStatus(404);

        $this->assertSame($antes, DB::table('users')->count());
        $this->assertSame(0, DB::table('users')->where('doc_number', '99887766')->count());
    }

    /**
     * `PUT users/payment-expired-at/{id}` reescribe el vencimiento del plan: cerrada, no cambia la fecha.
     *
     * @test
     */
    public function la_mensualidad_no_cambia_la_fecha_de_vencimiento()
    {
        DB::table('users')->where('id', self::DUENO_ID)->update(['payment_expired_at' => '2026-12-31 00:00:00']);

        $this->pedir_midiendo('PUT', '/users/payment-expired-at/' . self::DUENO_ID, [
            'payment_expired_at' => '2099-01-01',
            'precio_plan'        => 0,
            'precio_por_cuenta'  => 0,
        ])->assertStatus(404);

        $this->assertSame('2026-12-31 00:00:00', (string) DB::table('users')->where('id', self::DUENO_ID)->value('payment_expired_at'));
    }
}
