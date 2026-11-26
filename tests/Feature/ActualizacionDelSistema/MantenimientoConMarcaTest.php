<?php

namespace Tests\Feature\ActualizacionDelSistema;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA12 de la misión aviso-actualizacion-sistema (25/11/2026): durante el `php artisan down` del deploy el
 * sistema contesta la MISMA marca "estamos actualizando" que el bloqueo por estado, en vez del 503 genérico
 * `{"message":"Service Unavailable"}`, y deja pasar sólo el canal con el que el admin renueva el estado.
 *
 * El `down` del pipeline va sin `--render` ni `--secret` (lo vigila un test del admin), así que la request
 * llega al middleware `PreventRequestsDuringMaintenance` del código ya desplegado: ahí se decide la
 * respuesta. `$except` deja libres `api/admin-sync/actualizacion-del-sistema` y su subárbol (el admin
 * renueva el estado durante el `down`, ya con el código nuevo); todo lo demás sigue cortado.
 *
 * Se escribe `storage/framework/down` a mano, con el payload que deja `artisan down` sin flags, y se borra
 * en `tearDown()` (y también en `setUp()`: un `down` huérfano de una corrida abortada rompería todo el slot).
 *
 * Motivo esperado del rojo ANTES de la implementación: la respuesta del `down` es el 503 genérico sin la
 * marca, y las rutas del admin no existen.
 *
 * PHP 7.4 estricto: nada de la sintaxis de PHP 8 (ni match, ni operador nullsafe, ni funciones str_*_with, ni argumentos con nombre).
 */
class MantenimientoConMarcaTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de admin de la instancia en estos tests. */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-mantenimiento-6a9d';

    /** Texto que contesta el sistema del cliente mientras se actualiza (contrato §2.4). */
    const MENSAJE = 'Estamos actualizando el sistema. Mientras tanto no se puede usar. Cuando termine vas a poder ingresar de nuevo desde esta pantalla.';

    /** @var \App\Models\User El dueño de la base de testing. */
    protected $dueno;

    /**
     * Escenario conocido y sin `down` previo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sacar_el_down();

        $this->dueno = User::whereNull('owner_id')->orderBy('id')->firstOrFail();

        config([
            'app.USER_ID'                        => $this->dueno->id,
            'services.admin_api.api_key'         => self::CLAVE_DE_ADMIN,
            'services.admin_api.require_api_key' => null,
        ]);

        Cache::flush();

        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    /**
     * Nunca deja el modo mantenimiento puesto, pase lo que pase en el test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->sacar_el_down();

        parent::tearDown();
    }

    /**
     * Escribe `storage/framework/down` con el payload de `php artisan down` sin flags (Laravel 8.83).
     *
     * @return void
     */
    protected function poner_el_down()
    {
        file_put_contents(storage_path('framework/down'), json_encode([
            'except'   => [],
            'redirect' => null,
            'retry'    => null,
            'refresh'  => null,
            'secret'   => null,
            'status'   => 503,
            'template' => null,
        ]));
    }

    /**
     * Borra `storage/framework/down` si existe.
     *
     * @return void
     */
    protected function sacar_el_down()
    {
        $archivo = storage_path('framework/down');

        if (file_exists($archivo)) {
            unlink($archivo);
        }
    }

    /**
     * Afirma la marca de "estamos actualizando" que contesta el `down` (contrato §2.4).
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     * @return void
     */
    protected function assert_marca($respuesta)
    {
        $respuesta->assertStatus(503);
        $respuesta->assertHeader('Retry-After', '30');
        $respuesta->assertJsonPath('error', 'sistema_actualizando');
        $respuesta->assertJsonPath('message', self::MENSAJE);
        $respuesta->assertJsonPath('reintentar_en_segundos', 30);
    }

    /**
     * CA12: con el `down` puesto, una ruta con sesión contesta la marca y el admin igual puede escribir el
     * estado por el canal exceptuado.
     *
     * @return void
     */
    public function test_el_down_contesta_la_marca_y_deja_pasar_al_admin()
    {
        $this->actingAs($this->dueno, 'web');

        $this->poner_el_down();

        $this->assert_marca($this->getJson('/api/user'));

        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->putJson('/api/admin-sync/actualizacion-del-sistema', ['estado' => 'actualizando']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'actualizando');
        $this->assertSame(
            'actualizando',
            DB::table('actualizaciones_del_sistema')->where('owner_id', $this->dueno->id)->value('estado')
        );
    }

    /**
     * El `down` también cubre el login (grupo `web`) y las rutas sin sesión: todas contestan la marca, para
     * que la pantalla de login pueda mostrar el aviso en vez de "credenciales incorrectas".
     *
     * @return void
     */
    public function test_el_down_cubre_el_login_y_las_rutas_sin_sesion()
    {
        $this->poner_el_down();

        $this->assert_marca($this->postJson('/login', ['doc_number' => '1234', 'password' => 'x']));
        $this->assert_marca($this->getJson('/api/actualizacion-del-sistema/publico'));
    }

    /**
     * El resto de `admin-sync` NO se exceptúa del `down`: sólo el subárbol `actualizacion-del-sistema`.
     * (Y GET/DELETE del pedido, que viven en ese subárbol, sí pasan.)
     *
     * @return void
     */
    public function test_solo_el_subarbol_de_actualizacion_queda_exceptuado()
    {
        $this->poner_el_down();

        $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->getJson('/api/admin-sync/prueba/estado')
            ->assertStatus(503)
            ->assertJsonPath('error', 'sistema_actualizando');

        $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->getJson('/api/admin-sync/actualizacion-del-sistema')
            ->assertStatus(200);

        $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->deleteJson('/api/admin-sync/actualizacion-del-sistema/pedido')
            ->assertStatus(200);
    }
}
