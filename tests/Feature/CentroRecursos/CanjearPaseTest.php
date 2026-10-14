<?php

namespace Tests\Feature\CentroRecursos;

use App\Http\Controllers\Helpers\CentroRecursosPaseHelper;
use App\Models\CentroRecursosPase;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * POST api/admin-sync/recursos/canjear-pase — el admin canjea el pase que la página le mandó
 * (misión centro-de-recursos-clientes, 13/10/2026, contrato C3 del plan).
 *
 * 🔴 QUÉ PROTEGE ESTE ARCHIVO.
 *  - Un pase sirve UNA vez y por 5 minutos. Y la decisión la toma el UPDATE condicionado, no la
 *    lectura previa: dos canjes simultáneos del mismo pase no pueden ganar los dos.
 *  - En una base compartida (51 comercios en `u767360347_empresa`), un pase de un comercio no se
 *    canjea por la API de otro (`app.USER_ID`), y ese intento no le quema el pase al dueño.
 *  - La forma exacta de la respuesta, que lee admin-api: `user.{id,name,email,owner_id}`, `video` y
 *    `soporte`, y el 404 `{"error": "pase_invalido"}` para todo lo que no vale.
 *  - `soporte` (misión centro-recursos-soporte-y-tema, 14/10/2026): true cuando el pase lo emitió un
 *    login maestro (el admin abre entonces una sesión de SOLO LECTURA), false en todo lo demás. Se
 *    agrega a la respuesta sin sacar ni renombrar nada: un admin que no lo conoce lo ignora.
 *  - La clave del admin, cuando el grupo la exige.
 *
 * @group centro-recursos
 * @group admin-sync
 */
class CanjearPaseTest extends EmpresaTestCase
{
    /** Ruta del contrato C3. */
    const RUTA = 'api/admin-sync/recursos/canjear-pase';

    /** Clave de admin de la instancia (como un cliente de producción con la clave cargada). */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-del-centro-de-recursos';

    /** @var User Dueño del comercio de esta instancia (el del fixture). */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // La instancia es la de este comercio, como en producción (cada .env trae su USER_ID).
        config(['app.USER_ID' => $this->dueno->id]);

        // La instancia tiene su clave y el gate en automático: exige la clave, como un cliente con
        // ADMIN_API_INBOUND_KEY cargada. admin-api la manda.
        config([
            'services.admin_api.api_key'         => self::CLAVE_DE_ADMIN,
            'services.admin_api.require_api_key' => null,
        ]);
        $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN);

        Carbon::setTestNow(Carbon::create(2026, 10, 13, 10, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  string $pase
     * @return \Illuminate\Testing\TestResponse
     */
    protected function canjear($pase)
    {
        return $this->postJson(self::RUTA, ['pase' => $pase]);
    }

    /**
     * La fila de un pase en claro.
     *
     * @param  string $pase
     * @return CentroRecursosPase|null
     */
    protected function fila_de($pase)
    {
        return CentroRecursosPase::where('token_hash', hash('sha256', $pase))->first();
    }

    /**
     * Otro comercio de la misma base.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name'     => 'Otro comercio del centro de recursos',
            'email'    => 'otro-centro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Un empleado de un dueño.
     *
     * @param  User   $dueno
     * @param  string $email
     * @return User
     */
    protected function empleado_de($dueno, $email = null)
    {
        return User::create([
            'name'     => 'Marcela Gómez',
            'email'    => is_null($email) ? 'empleada-centro-' . uniqid() . '@test.local' : $email,
            'password' => Hash::make('secret'),
            'owner_id' => $dueno->id,
        ]);
    }

    /**
     * Un pase válido devuelve el usuario y el video con la forma del contrato, y queda usado.
     */
    public function test_un_pase_valido_devuelve_el_usuario_y_el_video_y_queda_usado()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno, 'T1.29');

        $this->canjear($pase)
             ->assertStatus(200)
             ->assertExactJson([
                 'user' => [
                     'id'       => (int) $this->dueno->id,
                     'name'     => $this->dueno->name,
                     'email'    => $this->dueno->email,
                     'owner_id' => null,
                 ],
                 'video'   => 'T1.29',
                 'soporte' => false,
             ]);

        $fila = $this->fila_de($pase);

        $this->assertNotNull($fila->usado_at, 'El canje lo marca usado.');
        $this->assertTrue(Carbon::now()->equalTo($fila->usado_at));
    }

    /**
     * 🔴 Un pase de soporte (el que emite un login maestro) vuelve con `soporte: true`, el resto de la
     * respuesta igual que siempre. El admin lo usa para abrir una sesión de solo lectura.
     */
    public function test_un_pase_de_soporte_vuelve_con_soporte_true()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno, 'T1.29', true);

        $this->assertTrue($this->fila_de($pase)->soporte);

        $this->canjear($pase)
             ->assertStatus(200)
             ->assertExactJson([
                 'user' => [
                     'id'       => (int) $this->dueno->id,
                     'name'     => $this->dueno->name,
                     'email'    => $this->dueno->email,
                     'owner_id' => null,
                 ],
                 'video'   => 'T1.29',
                 'soporte' => true,
             ]);

        $this->assertNotNull($this->fila_de($pase)->usado_at, 'El pase de soporte también queda usado.');
    }

    /**
     * 🔴 El INSERT de un pase de un usuario común es idéntico al de antes de la misión: no menciona
     * la columna `soporte` (si el código llega a un cliente antes de la migración, no puede romper).
     * Solo el pase de soporte la escribe. Además, un pase normal canjea con `soporte: false`.
     */
    public function test_solo_el_pase_de_soporte_escribe_la_columna_soporte()
    {
        $inserts = [];

        DB::listen(function ($consulta) use (&$inserts) {
            if (strpos($consulta->sql, 'insert into `centro_recursos_pases`') === 0) {
                $inserts[] = $consulta->sql;
            }
        });

        $normal = CentroRecursosPaseHelper::crear($this->dueno);
        $soporte = CentroRecursosPaseHelper::crear($this->dueno, null, true);

        $this->assertCount(2, $inserts, 'Los dos pases se insertaron (y el listener vio los dos).');
        $this->assertStringNotContainsString('soporte', $inserts[0], 'El pase común no escribe `soporte`.');
        $this->assertStringContainsString('`soporte`', $inserts[1], 'El pase de soporte sí.');

        $this->assertFalse($this->fila_de($normal)->soporte, 'El default de la base lo deja en false.');
        $this->assertTrue($this->fila_de($soporte)->soporte);

        $this->canjear($normal)->assertStatus(200)->assertJson(['soporte' => false]);
    }

    /**
     * El pase de soporte es de un solo uso, igual que uno normal: el segundo canje da `pase_invalido`
     * (un segundo después, para que el UPDATE no pueda "no afectar" por escribir los mismos valores).
     */
    public function test_un_pase_de_soporte_sirve_una_sola_vez()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno, null, true);

        $this->canjear($pase)->assertStatus(200)->assertJson(['soporte' => true]);

        Carbon::setTestNow(Carbon::now()->addSecond());

        $this->canjear($pase)
             ->assertStatus(404)
             ->assertExactJson(['error' => 'pase_invalido']);
    }

    /**
     * El canje atómico también vale para un pase de soporte: si otro canje gana entre la lectura y el
     * UPDATE, este pierde y NO devuelve el pase (ni su marca de soporte).
     */
    public function test_el_canje_de_un_pase_de_soporte_tambien_es_atomico()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno, null, true);

        CentroRecursosPase::retrieved(function ($fila) {
            DB::table('centro_recursos_pases')
                ->where('id', $fila->id)
                ->update(['usado_at' => Carbon::now()->subSecond()]);
        });

        try {
            $this->assertNull(CentroRecursosPaseHelper::canjear($pase));
        } finally {
            CentroRecursosPase::flushEventListeners();
        }
    }

    /**
     * Un pase de los que ya existían antes de la columna (o creado sin la marca) vale como normal:
     * `soporte: false`. Es lo que garantiza que los pases vivos al desplegar siguen andando igual.
     */
    public function test_un_pase_sin_la_marca_de_soporte_canjea_como_normal()
    {
        $plano = Str::random(CentroRecursosPaseHelper::LARGO);

        CentroRecursosPase::create([
            'user_id'    => $this->dueno->id,
            'token_hash' => hash('sha256', $plano),
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->canjear($plano)
             ->assertStatus(200)
             ->assertJson(['soporte' => false]);
    }

    /**
     * El helper devuelve `soporte` como booleano estricto, en los dos casos.
     */
    public function test_el_helper_devuelve_soporte_como_booleano()
    {
        $normal = CentroRecursosPaseHelper::canjear(CentroRecursosPaseHelper::crear($this->dueno));
        $soporte = CentroRecursosPaseHelper::canjear(CentroRecursosPaseHelper::crear($this->dueno, null, true));

        $this->assertSame(false, $normal['soporte']);
        $this->assertSame(true, $soporte['soporte']);
    }

    /**
     * Sin video, `video` va null.
     */
    public function test_sin_video_devuelve_video_null()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno);

        $respuesta = $this->canjear($pase)->assertStatus(200);

        $this->assertArrayHasKey('video', $respuesta->json(), 'La clave `video` va siempre, aunque sea null.');
        $this->assertNull($respuesta->json('video'));
        $this->assertArrayHasKey('soporte', $respuesta->json(), 'La clave `soporte` va siempre, aunque sea false.');
        $this->assertFalse($respuesta->json('soporte'));
    }

    /**
     * El segundo canje del mismo pase da `pase_invalido`.
     */
    public function test_un_pase_ya_usado_da_pase_invalido()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno);

        $this->canjear($pase)->assertStatus(200);

        // 🔴 El segundo canje va un segundo después, y no es decorativo: MySQL cuenta como
        // "afectadas" solo las filas que CAMBIAN. Con el reloj congelado, un UPDATE sin el
        // `usado_at IS NULL` volvería a escribir exactamente los mismos valores, afectaría 0 filas y
        // este test pasaría en verde aunque la guarda no existiera (medido al mutar el helper).
        Carbon::setTestNow(Carbon::now()->addSecond());

        $this->canjear($pase)
             ->assertStatus(404)
             ->assertExactJson(['error' => 'pase_invalido']);
    }

    /**
     * Pasados los 5 minutos no vale, y no se marca usado.
     */
    public function test_un_pase_vencido_da_pase_invalido()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno);

        Carbon::setTestNow(Carbon::now()->addMinutes(5)->addSecond());

        $this->canjear($pase)
             ->assertStatus(404)
             ->assertExactJson(['error' => 'pase_invalido']);

        $this->assertNull($this->fila_de($pase)->usado_at);
    }

    /**
     * Un segundo antes de vencer todavía vale.
     */
    public function test_un_segundo_antes_de_vencer_todavia_vale()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno);

        Carbon::setTestNow(Carbon::now()->addMinutes(5)->subSecond());

        $this->canjear($pase)->assertStatus(200);
    }

    /**
     * 🔴 La decisión la toma el UPDATE condicionado, no la lectura. Se simula un canje simultáneo
     * que gana justo entre la lectura del pase y el UPDATE (el evento `retrieved` corre en ese
     * hueco): este canje tiene que perder, aunque cuando leyó el pase estaba sin usar.
     */
    public function test_el_canje_es_atomico_si_otro_canje_gana_entre_la_lectura_y_el_update_este_pierde()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno);

        // El otro canje escribe una hora DISTINTA de la que escribiría este (un segundo antes): MySQL
        // cuenta como afectadas solo las filas que cambian, y con la misma hora un UPDATE sin la
        // guarda también daría 0 filas y el test no probaría nada (ver test_un_pase_ya_usado).
        CentroRecursosPase::retrieved(function ($fila) {
            DB::table('centro_recursos_pases')
                ->where('id', $fila->id)
                ->update(['usado_at' => Carbon::now()->subSecond()]);
        });

        try {
            $this->assertNull(CentroRecursosPaseHelper::canjear($pase), 'Leyó el pase sin usar, pero el UPDATE condicionado no tiene que afectar ninguna fila.');
        } finally {
            CentroRecursosPase::flushEventListeners();
        }
    }

    /**
     * 🔴 Base compartida: un pase de otro comercio no se canjea por la API de este, y el intento no
     * se lo quema (la API correcta lo sigue pudiendo canjear).
     */
    public function test_un_pase_de_otro_dueno_da_pase_invalido_y_no_se_quema()
    {
        $otro = $this->otro_dueno();

        $pase = CentroRecursosPaseHelper::crear($otro);

        $this->canjear($pase)
             ->assertStatus(404)
             ->assertExactJson(['error' => 'pase_invalido']);

        $this->assertNull($this->fila_de($pase)->usado_at, 'El intento por la API equivocada no quema el pase.');

        // La API del otro comercio (su USER_ID) sí lo canjea.
        config(['app.USER_ID' => $otro->id]);

        $this->canjear($pase)->assertStatus(200)->assertJson(['user' => ['id' => (int) $otro->id]]);
    }

    /**
     * El empleado de otro comercio tampoco entra por esta API.
     */
    public function test_un_empleado_de_otro_dueno_da_pase_invalido()
    {
        $pase = CentroRecursosPaseHelper::crear($this->empleado_de($this->otro_dueno()));

        $this->canjear($pase)->assertStatus(404)->assertExactJson(['error' => 'pase_invalido']);
    }

    /**
     * El empleado del dueño canjea y vuelve con su `owner_id` (el admin lo usa para saber que no es
     * el dueño).
     */
    public function test_un_empleado_del_dueno_canjea_y_vuelve_con_owner_id()
    {
        $empleado = $this->empleado_de($this->dueno);

        $pase = CentroRecursosPaseHelper::crear($empleado);

        $this->canjear($pase)
             ->assertStatus(200)
             ->assertExactJson([
                 'user' => [
                     'id'       => (int) $empleado->id,
                     'name'     => 'Marcela Gómez',
                     'email'    => $empleado->email,
                     'owner_id' => (int) $this->dueno->id,
                 ],
                 'video'   => null,
                 'soporte' => false,
             ]);
    }

    /**
     * Sin `app.USER_ID` no hay contra qué comparar: vale el pase de cualquier usuario de la base.
     */
    public function test_sin_user_id_en_la_instancia_vale_cualquier_usuario()
    {
        config(['app.USER_ID' => null]);

        $otro = $this->otro_dueno();

        $this->canjear(CentroRecursosPaseHelper::crear($otro))
             ->assertStatus(200)
             ->assertJson(['user' => ['id' => (int) $otro->id]]);
    }

    /**
     * Lo que no es un pase emitido acá da `pase_invalido`: uno inventado, uno mal formado, uno que
     * no vino, y el HASH guardado en la base (que mide 64 caracteres, como un pase).
     */
    public function test_lo_que_no_es_un_pase_emitido_da_pase_invalido()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno);

        $this->canjear(Str::random(64))->assertStatus(404)->assertExactJson(['error' => 'pase_invalido']);
        $this->canjear('abc')->assertStatus(404)->assertExactJson(['error' => 'pase_invalido']);
        $this->postJson(self::RUTA, [])->assertStatus(404)->assertExactJson(['error' => 'pase_invalido']);
        $this->postJson(self::RUTA, ['pase' => [$pase]])->assertStatus(404)->assertExactJson(['error' => 'pase_invalido']);

        // Con el hash de la base no se entra.
        $this->canjear(hash('sha256', $pase))->assertStatus(404)->assertExactJson(['error' => 'pase_invalido']);

        // Y nada de eso tocó el pase verdadero.
        $this->assertNull($this->fila_de($pase)->usado_at);
        $this->canjear($pase)->assertStatus(200);
    }

    /**
     * Una casilla que no es una dirección válida va `null`, nunca string vacío ni basura.
     */
    public function test_un_email_que_no_es_una_direccion_va_null()
    {
        $empleado = $this->empleado_de($this->dueno, 'no-es-un-mail-' . uniqid());

        $this->canjear(CentroRecursosPaseHelper::crear($empleado))
             ->assertStatus(200)
             ->assertJson(['user' => ['email' => null]]);
    }

    /**
     * Con el gate en automático y la clave cargada en la instancia, sin el header da 401 y el pase
     * queda sin usar.
     */
    public function test_sin_la_clave_cuando_el_grupo_la_exige_da_401_y_no_quema_el_pase()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno);

        $this->flushHeaders();

        $this->canjear($pase)
             ->assertStatus(401)
             ->assertJson(['error' => 'unauthorized']);

        $this->assertNull($this->fila_de($pase)->usado_at);

        $this->postJson(self::RUTA, ['pase' => $pase], ['X-Admin-Api-Key' => self::CLAVE_DE_ADMIN])->assertStatus(200);
    }

    /**
     * Con una clave equivocada, también 401.
     */
    public function test_con_la_clave_equivocada_da_401()
    {
        $pase = CentroRecursosPaseHelper::crear($this->dueno);

        $this->flushHeaders();

        $this->postJson(self::RUTA, ['pase' => $pase], ['X-Admin-Api-Key' => 'otra-clave'])->assertStatus(401);

        $this->assertNull($this->fila_de($pase)->usado_at);
    }
}
