<?php

namespace Tests\Feature\AdminSync;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * GET api/admin-sync/contacto-dueno/{user_id?} — el contacto del dueño del comercio, que admin-api
 * consume para mandarle el mail con las novedades cuando le actualiza el sistema
 * (misión aviso-de-actualizacion-al-cliente).
 *
 * 🔴 QUÉ PROTEGE ESTE ARCHIVO. Del otro lado, admin-api trata "hay email" como permiso para
 * mandar: si acá sale un string vacío o una casilla rota, allá termina en un `Mail::to('')` que
 * revienta o en un mail que no llega a nadie y nadie vuelve a revisar. Por eso la forma del
 * payload —string con valor real o `null`, nunca `''`— es parte del contrato y no un detalle de
 * implementación.
 *
 * @group admin-sync
 */
class ContactoDuenoTest extends EmpresaTestCase
{
    /** Ruta del contrato con admin-api (sin user_id: resuelve el dueño de la instancia). */
    const RUTA = 'api/admin-sync/contacto-dueno';

    /** @var User Dueño del comercio al que pertenece esta instancia. */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::create([
            'name'         => 'Lucas Dueño',
            'company_name' => 'Ferretería de Prueba',
            'phone'        => '3415123456',
            'email'        => 'dueno-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // La instancia es la de este comercio (igual que en producción, donde el .env de cada
        // carpeta trae su propio USER_ID).
        config(['app.USER_ID' => $this->dueno->id]);
    }

    /**
     * Prende el gate de la clave de API, que en producción hoy está APAGADO.
     *
     * `services.admin_api.require_api_key` sale de `ADMIN_SYNC_REQUIRE_API_KEY`, que no está en
     * ningún `.env` de cliente: su default es `false`, y con eso `AdminApiKey` deja pasar todo sin
     * mirar el header. Los tests de payload de este archivo corren en ese estado (el real), y este
     * helper existe para poder verificar aparte que el día que Lucas prenda el flag, la ruta queda
     * protegida sola, como el resto del grupo.
     *
     * @param  string $clave Clave que la instancia va a considerar válida.
     * @return void
     */
    protected function exigir_clave($clave)
    {
        config([
            'services.admin_api.require_api_key' => true,
            'services.admin_api.api_key'         => $clave,
        ]);
    }

    /**
     * Con el gate prendido y sin el header X-Admin-Api-Key, el middleware corta con 401 antes de
     * llegar al controlador.
     */
    public function test_con_el_gate_prendido_rechaza_el_pedido_sin_la_clave()
    {
        $this->exigir_clave('la-clave-del-admin');

        $this->getJson(self::RUTA)
             ->assertStatus(401)
             ->assertJson(['error' => 'unauthorized']);
    }

    /** Con el gate prendido y una clave equivocada, también corta con 401. */
    public function test_con_el_gate_prendido_rechaza_la_clave_equivocada()
    {
        $this->exigir_clave('la-clave-del-admin');

        $this->getJson(self::RUTA, ['X-Admin-Api-Key' => 'otra-clave'])->assertStatus(401);
    }

    /** Con el gate prendido y la clave correcta, pasa y devuelve el contacto. */
    public function test_con_el_gate_prendido_acepta_la_clave_correcta()
    {
        $this->exigir_clave('la-clave-del-admin');

        $this->getJson(self::RUTA, ['X-Admin-Api-Key' => 'la-clave-del-admin'])
             ->assertStatus(200)
             ->assertJson(['contacto' => ['email' => $this->dueno->email]]);
    }

    /**
     * El estado REAL de producción: con el flag apagado (su default) el middleware no mira el
     * header y la ruta responde igual. Se deja escrito para que si algún día cambia el default,
     * este test lo denuncie en vez de que se entere admin-api con un 401 en medio de un upgrade.
     */
    public function test_con_el_gate_apagado_responde_sin_clave()
    {
        config(['services.admin_api.require_api_key' => false]);

        $this->getJson(self::RUTA)->assertStatus(200);
    }

    /** Sin user_id, resuelve el dueño de la instancia (config('app.USER_ID')). */
    public function test_sin_user_id_devuelve_el_dueno_de_la_instancia()
    {
        $this->getJson(self::RUTA)
             ->assertStatus(200)
             ->assertJson([
                 'contacto' => [
                     'email'        => $this->dueno->email,
                     'name'         => 'Lucas Dueño',
                     'company_name' => 'Ferretería de Prueba',
                     'phone'        => '3415123456',
                 ],
             ]);
    }

    /** El payload tiene exactamente las cuatro claves del contrato, ni una más. */
    public function test_la_forma_del_payload_es_la_del_contrato()
    {
        $respuesta = $this->getJson(self::RUTA)->assertStatus(200);

        $respuesta->assertJsonStructure([
            'contacto' => ['email', 'name', 'company_name', 'phone'],
        ]);

        $this->assertSame(
            ['email', 'name', 'company_name', 'phone'],
            array_keys($respuesta->json('contacto'))
        );
    }

    /** Con un user_id explícito devuelve ese dueño, no el de la instancia. */
    public function test_con_user_id_explicito_devuelve_ese_usuario()
    {
        $otro = User::create([
            'name'     => 'Otro comercio de la misma base',
            'email'    => 'otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->getJson(self::RUTA . '/' . $otro->id)
             ->assertStatus(200)
             ->assertJson(['contacto' => ['email' => $otro->email]]);
    }

    /** 🔴 El email en null NO puede volver como string vacío: allá eso se lee como "hay mail". */
    public function test_el_dueno_sin_email_devuelve_email_null()
    {
        $this->dueno->email = null;
        $this->dueno->save();

        $respuesta = $this->getJson(self::RUTA)->assertStatus(200);

        $this->assertNull($respuesta->json('contacto.email'));
    }

    /** Lo mismo cuando la columna tiene el string vacío, que es como quedan muchas instancias. */
    public function test_el_dueno_con_email_vacio_devuelve_email_null()
    {
        $this->dueno->email = '';
        $this->dueno->save();

        $respuesta = $this->getJson(self::RUTA)->assertStatus(200);

        $this->assertNull($respuesta->json('contacto.email'));
        $this->assertNotSame('', $respuesta->json('contacto.email'));
    }

    /** Un email con solo espacios tampoco es un email. */
    public function test_el_email_con_solo_espacios_devuelve_null()
    {
        $this->dueno->email = '   ';
        $this->dueno->save();

        $this->assertNull($this->getJson(self::RUTA)->assertStatus(200)->json('contacto.email'));
    }

    /**
     * Una casilla rota guardada en la base vale lo mismo que no tener ninguna: si sale de acá,
     * admin-api la manda igual y el mail no llega a nadie.
     */
    public function test_el_email_invalido_devuelve_null()
    {
        foreach (['no-es-un-mail', 'sin-arroba.com', 'dos@@arrobas.com', '-'] as $invalido) {
            $this->dueno->email = $invalido;
            $this->dueno->save();

            $respuesta = $this->getJson(self::RUTA)->assertStatus(200);

            $this->assertNull(
                $respuesta->json('contacto.email'),
                'El email invalido "' . $invalido . '" tendria que volver como null.'
            );
        }
    }

    /** Los otros tres campos siguen el mismo criterio: valor real o null, nunca string vacío. */
    public function test_los_campos_vacios_vuelven_como_null_y_no_como_string_vacio()
    {
        $this->dueno->name         = '';
        $this->dueno->company_name = null;
        $this->dueno->phone        = '  ';
        $this->dueno->save();

        $contacto = $this->getJson(self::RUTA)->assertStatus(200)->json('contacto');

        $this->assertNull($contacto['name']);
        $this->assertNull($contacto['company_name']);
        $this->assertNull($contacto['phone']);
    }

    /** Un user_id que no existe devuelve 404 con el mismo mensaje que branding. */
    public function test_el_user_id_inexistente_devuelve_404()
    {
        $inexistente = User::max('id') + 1000;

        $this->getJson(self::RUTA . '/' . $inexistente)
             ->assertStatus(404)
             ->assertJson(['message' => 'Usuario no encontrado.']);
    }

    /**
     * Sin USER_ID configurado tampoco hay a quién resolver: 404, no una excepción. Es el estado de
     * las instancias viejas que nunca cargaron la variable.
     */
    public function test_sin_user_id_configurado_devuelve_404()
    {
        config(['app.USER_ID' => null]);

        $this->getJson(self::RUTA)->assertStatus(404);
    }
}
