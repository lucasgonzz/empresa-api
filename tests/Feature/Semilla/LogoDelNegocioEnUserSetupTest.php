<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\User;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Mision "logo del negocio en el formulario de user-setup" (28/9/2026).
 *
 * `UserSetupHelper::create_user()` armaba `image_url` con un placeholder generico hardcodeado
 * para TODO cliente real. Ahora admin-api puede mandar `logo_url` (opcional) en el payload de
 * `POST /api/admin-sync/user-setup`, y `create_user()` lo usa si viene con contenido -- mismo
 * patron que ya usa este mismo metodo para `google_custom_search_api_key` (fallback explicito
 * con `isset()` + `trim()` + chequeo de string vacio).
 *
 * Este test invoca `create_user()` por reflexion, igual que
 * `SetupsPlantillaYCondicionFiscalTest`, y relee el usuario de la base (no el modelo en
 * memoria) para no depender de que `User` tenga `$guarded = []`.
 *
 * @group semilla
 */
class LogoDelNegocioEnUserSetupTest extends EmpresaTestCase
{
    /**
     * Id de usuario alto a proposito, igual que `SetupsPlantillaYCondicionFiscalTest`: evita
     * chocar con el dueño que siembra `TestingFerreteriaSeeder`. Distinto del que usa ese otro
     * archivo (900001) para no compartir id entre suites aunque cada test corra en su propia
     * transaccion.
     */
    const USER_ID_DE_PRUEBA = 900002;

    /**
     * Placeholder historico que `create_user()` usa cuando no llega `logo_url` (o llega vacio).
     * Se repite aca literal (no se importa ninguna constante del helper, porque no expone
     * ninguna) para que el test detecte si el valor de fallback cambia sin querer.
     */
    const PLACEHOLDER_HISTORICO = 'https://api-demo.comerciocity.com/public/storage/174292591094040.png';

    /**
     * Invoca un metodo privado estatico de un helper de setup.
     *
     * @param string $clase
     * @param string $metodo
     * @param array  $argumentos
     * @return mixed
     */
    protected function invocar_privado($clase, $metodo, array $argumentos)
    {
        $reflection = new ReflectionMethod($clase, $metodo);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $argumentos);
    }

    /**
     * @return void
     */
    public function test_con_logo_url_en_el_payload_el_usuario_nace_con_ese_logo()
    {
        $logo_de_prueba = 'https://ejemplo-test.comerciocity.com/storage/logo-de-prueba.png';

        $user = $this->invocar_privado(UserSetupHelper::class, 'create_user', [[
            'user_id'      => self::USER_ID_DE_PRUEBA,
            'user_name'    => 'Cliente con logo',
            'company_name' => 'Cliente con logo',
            'email'        => 'cliente-con-logo-'.self::USER_ID_DE_PRUEBA.'@comerciocity.test',
            'logo_url'     => $logo_de_prueba,
        ]]);

        // Se relee de la base, no el modelo en memoria devuelto por create_user().
        $guardado = User::find($user->id);

        $this->assertSame(
            $logo_de_prueba,
            $guardado->image_url,
            'Un cliente real con logo_url en el payload no nace con ese logo en image_url.'
        );
    }

    /**
     * @return void
     */
    public function test_sin_logo_url_el_usuario_sigue_naciendo_con_el_placeholder_de_siempre()
    {
        $user = $this->invocar_privado(UserSetupHelper::class, 'create_user', [[
            'user_id'      => self::USER_ID_DE_PRUEBA,
            'user_name'    => 'Cliente sin logo',
            'company_name' => 'Cliente sin logo',
            'email'        => 'cliente-sin-logo-'.self::USER_ID_DE_PRUEBA.'@comerciocity.test',
        ]]);

        $guardado = User::find($user->id);

        $this->assertSame(
            self::PLACEHOLDER_HISTORICO,
            $guardado->image_url,
            'Sin logo_url en el payload, un cliente real deberia seguir naciendo con el placeholder historico.'
        );
    }

    /**
     * Cubre el caso explicito del contrato (Fase 5 del plan): `logo_url` vacio se trata igual
     * que ausente, no como un valor real a guardar.
     *
     * @return void
     */
    public function test_con_logo_url_vacio_el_usuario_tambien_nace_con_el_placeholder_de_siempre()
    {
        $user = $this->invocar_privado(UserSetupHelper::class, 'create_user', [[
            'user_id'      => self::USER_ID_DE_PRUEBA,
            'user_name'    => 'Cliente con logo vacio',
            'company_name' => 'Cliente con logo vacio',
            'email'        => 'cliente-logo-vacio-'.self::USER_ID_DE_PRUEBA.'@comerciocity.test',
            'logo_url'     => '',
        ]]);

        $guardado = User::find($user->id);

        $this->assertSame(
            self::PLACEHOLDER_HISTORICO,
            $guardado->image_url,
            'Con logo_url vacio, un cliente real deberia seguir naciendo con el placeholder historico.'
        );
    }
}
