<?php

namespace Tests\Feature\AdminSync;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Ultimo paso del upgrade: PUT api/admin-sync/update-default-version, que admin-api llama para
 * mandar a los usuarios al frente recien desplegado.
 *
 * 🔴 POR QUE EXISTE ESTE ARCHIVO. Hay bases que comparten varios comercios. En
 * `u767360347_empresa` conviven Fenix, Galvan, HiperMax, El Kiosco Verde y Candyguay, cada uno
 * servido por su propia carpeta con su propio USER_ID en el .env. El 7/9/2026 el upgrade de Fenix
 * a 4.0.16 corrio este endpoint sin filtro de comercio y reescribio los 113 usuarios de la base:
 * los otros cuatro comercios quedaron apuntando al dominio de Fenix, y dos personas reales
 * terminaron redirigidas ahi al iniciar sesion. Los links de PDF que salen por WhatsApp y correo
 * (users.api_url) tambien quedaron con el dominio equivocado.
 *
 * Lo que protege, en orden de gravedad:
 *
 *  1. Actualizar un comercio NO puede tocar a otro comercio de la misma base.
 *  2. Si toca al dueño, tiene que tocar tambien a sus empleados: si no, el dueño se muda de frente
 *     y sus empleados se quedan en el viejo.
 *  3. Sin USER_ID en el .env se conserva el alcance historico (toda la base), porque hay
 *     instancias de un solo comercio que nunca configuraron la variable y romperlas seria peor.
 *
 * @group admin-sync
 */
class DefaultVersionPorComercioTest extends EmpresaTestCase
{
    /** Ruta del contrato con admin-api. */
    const RUTA = 'api/admin-sync/update-default-version';

    /** Frente al que se quiere mudar el comercio de la instancia. */
    const SPA_NUEVA = 'https://galvan2.comerciocity.com';

    /** Frente en el que ya estaba el comercio vecino, y del que no se tiene que mover. */
    const SPA_VECINA = 'https://hipermax.comerciocity.com';

    /** @var User Dueño del comercio al que pertenece esta instancia. */
    protected $comercio;

    /** @var User Empleado del comercio de la instancia. */
    protected $empleado;

    /** @var User Dueño de otro comercio que vive en la misma base. */
    protected $vecino;

    /** @var User Empleado del comercio vecino. */
    protected $empleado_vecino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio de la instancia',
            'email'    => 'instancia-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado propio',
            'owner_id' => $this->comercio->id,
            'email'    => 'empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->vecino = User::create([
            'name'     => 'Comercio vecino',
            'email'    => 'vecino-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->empleado_vecino = User::create([
            'name'     => 'Empleado del vecino',
            'owner_id' => $this->vecino->id,
            'email'    => 'empleado-vecino-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        // El vecino ya esta parado en su propio frente y con una sesion abierta.
        foreach ([$this->vecino, $this->empleado_vecino] as $usuario) {
            $usuario->default_version = self::SPA_VECINA;
            $usuario->api_url         = 'https://api-hipermax.comerciocity.com/public';
            $usuario->session_id      = 'sesion-del-vecino';
            $usuario->save();
        }

        // La instancia es la del primer comercio.
        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Llama al endpoint como lo llama admin-api al terminar un deployment.
     *
     * @param  array $extra Claves que se agregan o pisan en el cuerpo.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function mudar($extra = [])
    {
        return $this->putJson(self::RUTA, array_merge([
            'spa_url' => self::SPA_NUEVA,
        ], $extra));
    }

    /** El dueño de la instancia se muda al frente nuevo. */
    public function test_muda_al_dueno_de_la_instancia()
    {
        $this->mudar()->assertStatus(200);

        $this->comercio->refresh();

        $this->assertSame(self::SPA_NUEVA, $this->comercio->default_version);
        $this->assertSame('https://api-galvan2.comerciocity.com/public', $this->comercio->api_url);
    }

    /** Los empleados del comercio se mudan con el dueño, o quedarian en el frente viejo. */
    public function test_muda_tambien_a_los_empleados_del_comercio()
    {
        $this->mudar()->assertStatus(200);

        $this->empleado->refresh();

        $this->assertSame(self::SPA_NUEVA, $this->empleado->default_version);
        $this->assertSame('https://api-galvan2.comerciocity.com/public', $this->empleado->api_url);
    }

    /**
     * 🔴 El test que hubiera evitado el incidente del 7/9/2026: el comercio vecino de la misma
     * base no se mueve de su frente ni le cambia la URL con la que arma los PDF.
     */
    public function test_no_toca_al_comercio_vecino_de_la_misma_base()
    {
        $this->mudar()->assertStatus(200);

        $this->vecino->refresh();

        $this->assertSame(self::SPA_VECINA, $this->vecino->default_version);
        $this->assertSame('https://api-hipermax.comerciocity.com/public', $this->vecino->api_url);
    }

    /** Tampoco a los empleados del vecino, que son los que mas filas suman en una base compartida. */
    public function test_no_toca_a_los_empleados_del_comercio_vecino()
    {
        $this->mudar()->assertStatus(200);

        $this->empleado_vecino->refresh();

        $this->assertSame(self::SPA_VECINA, $this->empleado_vecino->default_version);
    }

    /** La sesion del vecino sigue viva: mudar un comercio no puede desloguear a otro. */
    public function test_no_cierra_la_sesion_del_comercio_vecino()
    {
        $this->mudar()->assertStatus(200);

        $this->vecino->refresh();

        $this->assertSame('sesion-del-vecino', $this->vecino->session_id);
    }

    /** La sesion del comercio propio si se libera, para que vuelva a entrar en el frente nuevo. */
    public function test_libera_la_sesion_del_comercio_propio()
    {
        $this->comercio->session_id = 'sesion-vieja';
        $this->comercio->save();

        $this->mudar()->assertStatus(200);

        $this->comercio->refresh();

        $this->assertNull($this->comercio->session_id);
    }

    /** La cuenta de filas que vuelve es la del comercio, no la de la base entera. */
    public function test_informa_cuantos_usuarios_del_comercio_actualizo()
    {
        $respuesta = $this->mudar()->assertStatus(200);

        $respuesta->assertJson([
            'ok'            => true,
            'owner_id'      => $this->comercio->id,
            'users_updated' => 2,
        ]);
    }

    /**
     * Sin USER_ID configurado se conserva el alcance historico. Hay instancias de un solo comercio
     * que nunca seteraron la variable, y acotarlas a nadie las dejaria sin mudar.
     */
    public function test_sin_user_id_configurado_escribe_toda_la_base()
    {
        config(['app.USER_ID' => null]);

        $this->mudar()->assertStatus(200);

        $this->vecino->refresh();

        $this->assertSame(self::SPA_NUEVA, $this->vecino->default_version);
    }

    /** Sin USER_ID la respuesta lo dice, para que el log del deploy muestre el alcance real. */
    public function test_sin_user_id_la_respuesta_informa_owner_id_nulo()
    {
        config(['app.USER_ID' => null]);

        $this->mudar()->assertStatus(200)->assertJson(['owner_id' => null]);
    }

    /** El api_url explicito se respeta y se normaliza a la forma canonica con /public. */
    public function test_respeta_el_api_url_explicito()
    {
        $this->mudar(['api_url' => 'https://api-galvan2.comerciocity.com'])->assertStatus(200);

        $this->comercio->refresh();

        $this->assertSame('https://api-galvan2.comerciocity.com/public', $this->comercio->api_url);
    }

    /** Sigue siendo obligatorio decir a que SPA se muda. */
    public function test_rechaza_el_pedido_sin_spa_url()
    {
        $this->putJson(self::RUTA, [])->assertStatus(422);
    }

    /** Un pedido rechazado no puede haber escrito nada. */
    public function test_el_pedido_rechazado_no_escribe()
    {
        $this->comercio->default_version = self::SPA_VECINA;
        $this->comercio->save();

        $this->putJson(self::RUTA, [])->assertStatus(422);

        $this->comercio->refresh();

        $this->assertSame(self::SPA_VECINA, $this->comercio->default_version);
    }
}
