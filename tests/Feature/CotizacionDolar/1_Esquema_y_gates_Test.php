<?php

namespace Tests\Feature\CotizacionDolar;

use App\Models\Extencion;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión cotizacion-dolar — archivo 1: EL ESQUEMA Y LAS PUERTAS.
 *
 * Lo que protege este archivo no es que los endpoints anden: es QUIÉN PUEDE ENTRAR. Los tres
 * endpoints escriben el dólar con el que se costea el catálogo entero, así que una puerta abierta
 * de más deja a un empleado raso moviéndole los precios a todo el comercio, y una cerrada de más
 * deja al dueño sin poder actualizar la cotización.
 *
 * 🔴 Y el test de la extensión mira LA TABLA, no solo el slug: `costo_en_dolares` vive en
 * `extencion_empresas` (la que siembra ExtencionSeeder, pese al nombre del archivo) y NO en
 * `extencions`, que es otra cosa y cuelga de PermissionBeta. Un middleware apuntado a la tabla
 * equivocada dejaría la funcionalidad abierta —o cerrada— para todos a la vez, y sin ruido.
 *
 * DatabaseTransactions (no RefreshDatabase): la base del slot está sembrada de antes y un refresh
 * la vaciaría, rompiendo el resto de las suites.
 *
 * PHP 7.4 también acá.
 */
class Esquema_y_gates_Test extends TestCase
{
    use DatabaseTransactions;

    /** El slug de la extensión que gatea los tres endpoints. */
    const SLUG = 'costo_en_dolares';

    /**
     * Las siete columnas de selección y preferencias que la misión agrega a `users`.
     *
     * @var array<int, string>
     */
    const COLUMNAS_USERS = [
        'dolar_cotizacion_origen',
        'dolar_cotizacion_casa',
        'dolar_cotizacion_punta',
        'dolar_cotizacion_valor',
        'dolar_cotizacion_actualizada_at',
        'dolar_avisar_cambios',
        'dolar_variacion_minima',
    ];

    /**
     * Las trece columnas de la tabla de historial.
     *
     * @var array<int, string>
     */
    const COLUMNAS_REGISTROS = [
        'id',
        'user_id',
        'auth_user_id',
        'origen',
        'casa',
        'punta',
        'valor_dolar',
        'valor_cotizacion',
        'valor_dolar_anterior',
        'variacion_porcentaje',
        'disparo',
        'created_at',
        'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * 🔴 Fake ANTES de cualquier llamada: sin esto los tests que pegan al GET salen a
         * dolarapi.com de verdad, y una suite que depende de la red es una suite que falla sola.
         */
        Http::fake([
            '*' => Http::response($this->payload_de_dolarapi(), 200),
        ]);
    }

    /**
     * Payload con la forma real de dolarapi (verificada el 20/8/2026): siete casas, y el MEP
     * llamándose `bolsa`.
     *
     * @return array
     */
    protected function payload_de_dolarapi()
    {
        return [
            ['moneda' => 'USD', 'casa' => 'oficial', 'nombre' => 'Oficial', 'compra' => 1465, 'venta' => 1515, 'fechaActualizacion' => '2026-08-20T11:00:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'blue', 'nombre' => 'Blue', 'compra' => 1540, 'venta' => 1560, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'bolsa', 'nombre' => 'Bolsa', 'compra' => 1523.6, 'venta' => 1523.6, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
            ['moneda' => 'USD', 'casa' => 'contadoconliqui', 'nombre' => 'Contado con liqui', 'compra' => 1600, 'venta' => 1610, 'fechaActualizacion' => '2026-08-20T13:56:00.000Z'],
        ];
    }

    /**
     * El comercio de los tests de esta rama.
     *
     * @return \App\Models\User|null
     */
    protected function comercio()
    {
        return User::find(500);
    }

    /**
     * La extensión que gatea los endpoints, creándola si la base del slot no la tiene sembrada.
     *
     * 🔴 forceCreate y no create: ExtencionEmpresa no declara $fillable y fuera de
     * Model::unguarded() (que solo aplica en db:seed) el create falla.
     *
     * @return \App\Models\ExtencionEmpresa
     */
    protected function extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'name' => 'Costo en Dolares',
                'slug' => self::SLUG,
            ]);
        }

        return $extencion;
    }

    /**
     * Le da la extensión al comercio. 🔴 syncWithoutDetaching y no attach: el usuario 500 de la
     * base sembrada puede ya tenerla y un attach duplicado deja dos filas en el pivot.
     *
     * @param  \App\Models\User $user
     * @return void
     */
    protected function dar_extension($user)
    {
        $user->extencions()->syncWithoutDetaching([$this->extension()->id]);
        $user->load('extencions');
    }

    /**
     * Un empleado del comercio 500.
     *
     * @param  int $admin_access
     * @return \App\Models\User
     */
    protected function empleado($admin_access)
    {
        return User::create([
            'name'         => 'zz-cotizacion-empleado-' . uniqid(),
            'email'        => 'zz-cotizacion-' . uniqid() . '@test.local',
            'password'     => Hash::make('zz-password-testing'),
            'status'       => 'commerce',
            'owner_id'     => 500,
            'admin_access' => $admin_access,
        ]);
    }

    /**
     * Pega a los tres endpoints y devuelve los tres status.
     *
     * @return array<int, int>
     */
    protected function status_de_los_tres()
    {
        return [
            $this->getJson('api/dolar-cotizacion')->status(),
            $this->postJson('api/dolar-cotizacion', [])->status(),
            $this->putJson('api/dolar-cotizacion/preferencias', [])->status(),
        ];
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function las_siete_columnas_existen_en_users()
    {
        foreach (self::COLUMNAS_USERS as $columna) {
            $this->assertTrue(
                Schema::hasColumn('users', $columna),
                'Falta la columna users.' . $columna
            );
        }
    }

    /**
     * Los defaults importan tanto como las columnas: `dolar_avisar_cambios` en 1 es la
     * funcionalidad prendida de fábrica, y `dolar_variacion_minima` en 1.00 es el umbral que evita
     * que el modal aparezca en cada login ante el movimiento más chico.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function una_fila_nueva_arranca_con_el_aviso_prendido_y_el_umbral_en_uno()
    {
        $nuevo = User::create([
            'name'     => 'zz-cotizacion-defaults-' . uniqid(),
            'email'    => 'zz-cotizacion-defaults-' . uniqid() . '@test.local',
            'password' => Hash::make('zz-password-testing'),
            'status'   => 'commerce',
        ]);

        $fresco = $nuevo->fresh();

        $this->assertEquals(1, (int) $fresco->dolar_avisar_cambios);
        $this->assertEquals(1.00, (float) $fresco->dolar_variacion_minima);

        /*
         * 🔴 Y el origen arranca en NULL, que es "nunca eligió nada" y NO es lo mismo que 'manual'.
         * De eso depende que el modal del login no le salte en la cara a ninguna cuenta existente.
         */
        $this->assertNull($fresco->dolar_cotizacion_origen);
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function la_tabla_de_historial_existe_con_sus_trece_columnas()
    {
        $this->assertTrue(Schema::hasTable('dolar_cotizacion_registros'));

        foreach (self::COLUMNAS_REGISTROS as $columna) {
            $this->assertTrue(
                Schema::hasColumn('dolar_cotizacion_registros', $columna),
                'Falta la columna dolar_cotizacion_registros.' . $columna
            );
        }
    }

    /**
     * 🔴 EL TEST DE LA TABLA, no el del slug.
     *
     * `ExtencionSeeder.php` se llama así pero importa `App\Models\ExtencionEmpresa` y siembra
     * `extencion_empresas`; `App\Models\Extencion` existe pero es otra cosa (cuelga de
     * PermissionBeta) y no tiene nada que ver con este gate. Si el middleware
     * `check_extencion_empresa` mirara la tabla equivocada, el gate le contestaría lo mismo a
     * TODOS —siempre 403, o siempre pasa— y ningún test de "el endpoint responde" lo notaría.
     *
     * En una base sin el seeder corrido, el setUp de esta suite crea la fila: lo que este test
     * afirma es DÓNDE vive, no que alguien la haya sembrado.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function la_extension_vive_en_extencion_empresas_y_no_en_extencions()
    {
        $this->extension();

        $this->assertTrue(
            ExtencionEmpresa::where('slug', self::SLUG)->exists(),
            'La extensión costo_en_dolares tiene que vivir en extencion_empresas.'
        );

        $this->assertFalse(
            Extencion::where('slug', self::SLUG)->exists(),
            'costo_en_dolares NO va en la tabla extencions: esa es otra cosa (PermissionBeta).'
        );
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function sin_sesion_los_tres_endpoints_devuelven_401()
    {
        $this->assertEquals([401, 401, 401], $this->status_de_los_tres());
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function con_sesion_pero_sin_la_extension_los_tres_devuelven_403()
    {
        $user = $this->comercio();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $user->extencions()->detach($this->extension()->id);
        $user->load('extencions');

        $this->actingAs($user, 'web');

        $this->assertEquals([403, 403, 403], $this->status_de_los_tres());
    }

    /**
     * La extensión es de la EMPRESA y el rol es de la PERSONA: son dos preguntas distintas, y el
     * empleado raso tiene la primera respondida que sí y la segunda que no.
     *
     * @group cotizacion-dolar
     * @test
     */
    public function un_empleado_sin_admin_access_recibe_403_en_los_tres()
    {
        $user = $this->comercio();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->dar_extension($user);

        $this->actingAs($this->empleado(0), 'web');

        $this->assertEquals([403, 403, 403], $this->status_de_los_tres());
    }

    /**
     * @group cotizacion-dolar
     * @test
     */
    public function un_empleado_con_admin_access_puede_leer()
    {
        $user = $this->comercio();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->dar_extension($user);

        $this->actingAs($this->empleado(1), 'web');

        $this->getJson('api/dolar-cotizacion')->assertStatus(200);
    }
}
