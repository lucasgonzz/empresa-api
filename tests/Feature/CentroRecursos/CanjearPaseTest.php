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
 *  - La forma exacta de la respuesta, que lee admin-api: `user.{id,name,email,owner_id}` y `video`,
 *    y el 404 `{"error": "pase_invalido"}` para todo lo que no vale.
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
                 'video' => 'T1.29',
             ]);

        $fila = $this->fila_de($pase);

        $this->assertNotNull($fila->usado_at, 'El canje lo marca usado.');
        $this->assertTrue(Carbon::now()->equalTo($fila->usado_at));
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
                 'video' => null,
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
