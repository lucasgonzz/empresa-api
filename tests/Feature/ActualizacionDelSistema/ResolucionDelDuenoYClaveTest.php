<?php

namespace Tests\Feature\ActualizacionDelSistema;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Ronda 2 de la misión aviso-actualizacion-sistema (25/11/2026), revisiones A y C de la primera entrega:
 *
 *  1. Con varios dueños en la misma base (hay bases viejas con 51 comercios) el admin-sync NO puede escribir
 *     sobre "el primer dueño": sin `user_id` explícito resuelve igual que el resto de `admin-sync`
 *     (`AsistenteCanalHelper::dueno()`): `config('app.USER_ID')`, si no el único dueño de la base, y si hay
 *     varios y no hay `USER_ID` contesta 409 `dueno_no_resoluble` (nunca "el primero"). El GET público, el
 *     middleware y el admin-sync resuelven al mismo dueño. Un `user_id` explícito sigue valiendo si es dueño.
 *  2. Las rutas admin-sync del aviso exigen la clave SIEMPRE (`admin.api.key:siempre`): prender
 *     `actualizando` bloquea a todo el comercio, y "la instancia no tiene clave cargada" no puede significar
 *     "pasá".
 *  3. El GET público lo llama cualquier visitante en cada carga de la pantalla de login: no tiene que arrancar
 *     sesión ni plantar cookie (mismo motivo que `version-activa`).
 *
 * Escenario: dueño A (el id menor, el 500 sembrado) con su empleado EA, y dueño B con su empleado EB.
 *
 * PHP 7.4 estricto: nada de la sintaxis de PHP 8.
 */
class ResolucionDelDuenoYClaveTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de admin de la instancia en estos tests. */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-resolucion-2f6e';

    /** Ruta admin del contrato (§4.1). */
    const URL = '/api/admin-sync/actualizacion-del-sistema';

    /** Contraseña conocida de los empleados del fixture. */
    const PASSWORD = 'clave-del-test-actualizacion-9f3';

    /** @var \App\Models\User Dueño A: el de menor id (el sembrado). */
    protected $dueno_a;

    /** @var \App\Models\User Dueño B: el de la instancia en la mayoría de los tests. */
    protected $dueno_b;

    /** @var \App\Models\User Empleado del dueño A. */
    protected $empleado_a;

    /** @var \App\Models\User Empleado del dueño B. */
    protected $empleado_b;

    /**
     * Escenario: dos dueños en la misma base, un empleado de cada uno, clave de admin cargada y la instancia
     * configurada para B.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno_a = User::whereNull('owner_id')->orderBy('id')->firstOrFail();

        $this->dueno_b = User::factory()->create([
            'owner_id'   => null,
            'name'       => 'Dueno B',
            'doc_number' => '88000001',
            'password'   => Hash::make(self::PASSWORD),
        ]);

        $this->empleado_a = $this->crear_empleado($this->dueno_a, '88000011');
        $this->empleado_b = $this->crear_empleado($this->dueno_b, '88000021');

        config([
            'app.USER_ID'                        => $this->dueno_b->id,
            'services.admin_api.api_key'         => self::CLAVE_DE_ADMIN,
            'services.admin_api.require_api_key' => null,
        ]);

        Cache::flush();

        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    /**
     * Crea un empleado de un dueño, con contraseña conocida.
     *
     * @param \App\Models\User $dueno
     * @param string           $documento
     * @return \App\Models\User
     */
    protected function crear_empleado($dueno, $documento)
    {
        return User::factory()->create([
            'owner_id'         => $dueno->id,
            'name'             => 'Empleado ' . $documento,
            'doc_number'       => $documento,
            'password'         => Hash::make(self::PASSWORD),
            'admin_access'     => false,
            'activity_minutes' => 60,
        ]);
    }

    /**
     * Intenta loguear a un empleado.
     *
     * @param \App\Models\User $empleado
     * @return \Illuminate\Testing\TestResponse
     */
    protected function login_de($empleado)
    {
        return $this->postJson('/login', ['doc_number' => $empleado->doc_number, 'password' => self::PASSWORD]);
    }

    /**
     * Escribe la fila de estado `actualizando` (vence en 60 min) de un dueño.
     *
     * @param \App\Models\User $dueno
     * @return void
     */
    protected function bloquear_a($dueno)
    {
        DB::table('actualizaciones_del_sistema')->where('owner_id', $dueno->id)->delete();

        DB::table('actualizaciones_del_sistema')->insert([
            'owner_id'           => $dueno->id,
            'estado'             => 'actualizando',
            'actualizando_desde' => Carbon::now(),
            'vence_at'           => Carbon::now()->addMinutes(60),
            'created_at'         => Carbon::now(),
            'updated_at'         => Carbon::now(),
        ]);

        Cache::flush();
    }

    /**
     * Cuántas filas de estado tiene un dueño.
     *
     * @param \App\Models\User $dueno
     * @return int
     */
    protected function filas_de($dueno)
    {
        return DB::table('actualizaciones_del_sistema')->where('owner_id', $dueno->id)->count();
    }

    /**
     * Sin `user_id` el PUT del admin escribe la fila del dueño de la instancia (B, `USER_ID`), no la del
     * primer dueño de la base (A): bloquea a los empleados de B y no a los de A.
     *
     * @return void
     */
    public function test_el_put_sin_user_id_escribe_al_dueno_de_la_instancia()
    {
        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->putJson(self::URL, ['estado' => 'actualizando']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('owner_id', $this->dueno_b->id);
        $this->assertSame(1, $this->filas_de($this->dueno_b));
        $this->assertSame(0, $this->filas_de($this->dueno_a), 'No se puede escribir sobre el primer dueño de la base.');

        $this->login_de($this->empleado_b)->assertStatus(503)->assertJsonPath('error', 'sistema_actualizando');
        $this->login_de($this->empleado_a)->assertStatus(200)->assertJsonPath('login', true);
    }

    /**
     * El GET público lee al dueño de la instancia: el bloqueo de B se ve, el de A no.
     *
     * @return void
     */
    public function test_el_publico_lee_al_dueno_de_la_instancia()
    {
        $this->bloquear_a($this->dueno_a);
        $this->getJson('/api/actualizacion-del-sistema/publico')->assertStatus(200)->assertJsonPath('estado', 'libre');

        $this->bloquear_a($this->dueno_b);
        $this->getJson('/api/actualizacion-del-sistema/publico')->assertStatus(503)->assertJsonPath('error', 'sistema_actualizando');
    }

    /**
     * Con dos dueños y sin `USER_ID` las cuatro rutas del admin contestan 409 `dueno_no_resoluble`, sin
     * escribir nada. El GET público contesta `libre` (no sabe de quién es la pantalla).
     *
     * @return void
     */
    public function test_dos_duenos_sin_user_id_es_409()
    {
        config(['app.USER_ID' => null]);

        $cabecera = ['X-Admin-Api-Key' => self::CLAVE_DE_ADMIN];

        $respuestas = [
            $this->withHeaders($cabecera)->getJson(self::URL),
            $this->withHeaders($cabecera)->putJson(self::URL, ['estado' => 'actualizando']),
            $this->withHeaders($cabecera)->postJson(self::URL . '/pedido'),
            $this->withHeaders($cabecera)->deleteJson(self::URL . '/pedido'),
        ];

        foreach ($respuestas as $respuesta) {
            $respuesta->assertStatus(409);
            $respuesta->assertJsonPath('code', 'dueno_no_resoluble');
            $respuesta->assertJsonPath('error', 'dueno_no_resoluble');
        }

        $this->assertSame(0, $this->filas_de($this->dueno_a));
        $this->assertSame(0, $this->filas_de($this->dueno_b));

        $this->getJson('/api/actualizacion-del-sistema/publico')->assertStatus(200)->assertJsonPath('estado', 'libre');
    }

    /**
     * Un `user_id` explícito sigue valiendo si es un dueño, aunque no sea el de la instancia.
     *
     * @return void
     */
    public function test_un_user_id_explicito_sigue_valiendo()
    {
        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->putJson(self::URL, ['estado' => 'actualizando', 'user_id' => $this->dueno_a->id]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('owner_id', $this->dueno_a->id);
        $this->assertSame(1, $this->filas_de($this->dueno_a));
        $this->assertSame(0, $this->filas_de($this->dueno_b));
    }

    /**
     * En una instancia SIN `ADMIN_API_INBOUND_KEY` las rutas del aviso rechazan igual el pedido sin clave
     * (`admin.api.key:siempre`): fallan cerrado, no abiertas.
     *
     * @return void
     */
    public function test_sin_clave_cargada_en_la_instancia_se_rechaza()
    {
        config(['services.admin_api.api_key' => '', 'services.admin_api.require_api_key' => null]);

        $this->getJson(self::URL)->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->putJson(self::URL, ['estado' => 'actualizando'])->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->postJson(self::URL . '/pedido')->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->deleteJson(self::URL . '/pedido')->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        $this->assertSame(0, $this->filas_de($this->dueno_b));
    }

    /**
     * El GET público no arranca sesión ni planta cookie (lo llama cualquier visitante en cada carga del
     * login), ni libre ni con la marca.
     *
     * @return void
     */
    public function test_el_publico_no_arranca_sesion_ni_planta_cookie()
    {
        $libre = $this->getJson('/api/actualizacion-del-sistema/publico');
        $libre->assertStatus(200);
        $libre->assertCookieMissing(config('session.cookie'));

        $this->bloquear_a($this->dueno_b);

        $marca = $this->getJson('/api/actualizacion-del-sistema/publico');
        $marca->assertStatus(503);
        $marca->assertCookieMissing(config('session.cookie'));
    }
}
