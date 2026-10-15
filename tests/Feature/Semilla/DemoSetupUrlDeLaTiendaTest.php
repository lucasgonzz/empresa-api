<?php

namespace Tests\Feature\Semilla;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Models\User;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Misión tiendas-demo-y-limpieza-tienda (14/10/2026), pedido de Lucas: el demo setup siempre deja
 * `users.online` cargado.
 *
 * Sin esa URL el Listado no muestra «Página web → Selección especial» y la selección especial no
 * tiene a qué tienda apuntar. admin-api manda `online` desde la `ecommerce_spa_url` de la demo, pero
 * puede faltar; ahí `DemoSetupHelper::online_por_defecto()` la completa:
 *
 *   1. `config('app.DEMO_TIENDA_URL')` (env `DEMO_TIENDA_URL`), si está puesta;
 *   2. si no, el host de `config('app.url')`: demo → tienda, demo2 → tienda2, demo3 → tienda3
 *      (con o sin el `api-` adelante), todas en comerciocity.store;
 *   3. cualquier otro host → null, como antes del cambio.
 *
 * Se fuerza la config (no `$_ENV` ni `putenv()`): es lo que ve la aplicación con o sin
 * `config:cache`. No se corre `run()` entero (arranca con `migrate:fresh`): el caso del setup invoca
 * `create_demo_user()` por reflexión, mismo criterio que DemoSetupTiendaOnlineTest.
 *
 * @group semilla
 */
class DemoSetupUrlDeLaTiendaTest extends EmpresaTestCase
{
    const TIENDA_1 = 'https://tienda.comerciocity.store';
    const TIENDA_2 = 'https://tienda2.comerciocity.store';
    const TIENDA_3 = 'https://tienda3.comerciocity.store';

    /*
    |---------------------------------------------------------------------------------------------
    | Fixtures
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Fuerza las dos claves que lee `online_por_defecto()`.
     *
     * @param string|null $demo_tienda_url
     * @param string      $app_url
     * @return void
     */
    private function configurar($demo_tienda_url, $app_url)
    {
        config([
            'app.DEMO_TIENDA_URL' => $demo_tienda_url,
            'app.url'             => $app_url,
        ]);
    }

    /**
     * Corre el `create_demo_user()` privado con el payload dado y devuelve el User que dejó. El id
     * es uno libre (el de `app.USER_ID` en la suite ya es el dueño del fixture); la transacción del
     * test lo deshace.
     *
     * @param array<string, mixed> $data
     * @return \App\Models\User
     */
    private function crear_usuario_de_la_demo(array $data)
    {
        config(['app.USER_ID' => ((int) User::max('id')) + 1000]);

        $metodo = new ReflectionMethod(DemoSetupHelper::class, 'create_demo_user');
        $metodo->setAccessible(true);

        $user = $metodo->invoke(null, $data);

        return User::findOrFail($user->id);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | El setup
    |---------------------------------------------------------------------------------------------
    */

    /**
     * Lo que manda admin-api no se toca: la URL explícita gana sobre la variable y sobre el host.
     *
     * @return void
     */
    public function test_la_url_que_manda_el_payload_gana()
    {
        $this->configurar('https://otra.comerciocity.store', 'https://api-demo.comerciocity.com');

        $user = $this->crear_usuario_de_la_demo([
            'business_type' => 'ferreteria',
            'online'        => 'https://mi-tienda.com.ar',
        ]);

        $this->assertSame('https://mi-tienda.com.ar', $user->online);
    }

    /**
     * 🔴 EL CASO QUE JUSTIFICA EL CAMBIO: el payload no trae `online` y la demo igual queda con su
     * tienda.
     *
     * @return void
     */
    public function test_sin_online_en_el_payload_la_demo_queda_con_su_tienda()
    {
        $this->configurar(null, 'https://api-demo2.comerciocity.com');

        $user = $this->crear_usuario_de_la_demo(['business_type' => 'ferreteria']);

        $this->assertSame(self::TIENDA_2, $user->online, 'Sin users.online no aparece «Página web → Selección especial».');
    }

    /**
     * admin-api manda `ecommerce_spa_url` tal cual, y vacía también es "no vino".
     *
     * @return void
     */
    public function test_online_vacio_en_el_payload_tambien_cae_al_default()
    {
        $this->configurar(null, 'https://api-demo3.comerciocity.com');

        foreach (['', '   '] as $vacio) {
            $user = $this->crear_usuario_de_la_demo(['business_type' => 'ferreteria', 'online' => $vacio]);

            $this->assertSame(self::TIENDA_3, $user->online, 'online = "' . $vacio . '" tiene que caer al default.');
        }
    }

    /**
     * Fuera de las tres demos el setup deja `online` en null, como antes del cambio (un user setup
     * de un cliente real pasa por el mismo método).
     *
     * @return void
     */
    public function test_fuera_de_una_demo_el_setup_sigue_dejando_null()
    {
        $this->configurar(null, 'https://api-ferretotal.comerciocity.com');

        $user = $this->crear_usuario_de_la_demo(['business_type' => 'ferreteria']);

        $this->assertNull($user->online);
    }

    /*
    |---------------------------------------------------------------------------------------------
    | online_por_defecto()
    |---------------------------------------------------------------------------------------------
    */

    /**
     * La variable gana sobre el host: con DEMO_TIENDA_URL puesta, no importa qué diga APP_URL.
     *
     * @return void
     */
    public function test_la_variable_gana_sobre_el_host()
    {
        $this->configurar('https://otra.comerciocity.store', 'https://api-demo3.comerciocity.com');

        $this->assertSame('https://otra.comerciocity.store', DemoSetupHelper::online_por_defecto());

        // Y vale también en un host ajeno, que sin la variable daría null.
        $this->configurar('https://otra.comerciocity.store', 'http://localhost');

        $this->assertSame('https://otra.comerciocity.store', DemoSetupHelper::online_por_defecto());
    }

    /**
     * Una variable vacía (`DEMO_TIENDA_URL=` en el .env) cuenta como no puesta.
     *
     * @return void
     */
    public function test_la_variable_vacia_cuenta_como_no_puesta()
    {
        foreach ([null, '', '   '] as $vacia) {
            $this->configurar($vacia, 'https://api-demo.comerciocity.com');

            $this->assertSame(self::TIENDA_1, DemoSetupHelper::online_por_defecto(), 'DEMO_TIENDA_URL = ' . var_export($vacia, true));
        }
    }

    /**
     * Los tres hosts de demo, con y sin el `api-` adelante, y las formas en que puede venir APP_URL
     * en un .env real: con `/public` (VPS), con barra final, en mayúsculas o sin esquema.
     *
     * @return void
     */
    public function test_los_tres_hosts_de_demo()
    {
        $casos = [
            'https://demo.comerciocity.com'             => self::TIENDA_1,
            'https://api-demo.comerciocity.com'         => self::TIENDA_1,
            'https://api-demo.comerciocity.com/public'  => self::TIENDA_1,
            'https://demo2.comerciocity.com/'           => self::TIENDA_2,
            'https://api-demo2.comerciocity.com'        => self::TIENDA_2,
            'HTTPS://API-DEMO2.COMERCIOCITY.COM'        => self::TIENDA_2,
            'https://demo3.comerciocity.com'            => self::TIENDA_3,
            'api-demo3.comerciocity.com'                => self::TIENDA_3,
        ];

        foreach ($casos as $app_url => $esperada) {
            $this->configurar(null, $app_url);

            $this->assertSame($esperada, DemoSetupHelper::online_por_defecto(), 'APP_URL = ' . $app_url);
        }
    }

    /**
     * Cualquier otro host da null, incluidos los que se parecen a una demo sin serlo.
     *
     * @return void
     */
    public function test_un_host_ajeno_da_null()
    {
        $ajenos = [
            'https://api-ferretotal.comerciocity.com',
            'http://localhost',
            'http://empresa.local:8108',
            'https://demo4.comerciocity.com',
            'https://otrademo.comerciocity.com',
            'https://demo.comerciocity.com.ar',
            'https://demo.comerciocity.com.otro.net',
            '',
        ];

        foreach ($ajenos as $app_url) {
            $this->configurar(null, $app_url);

            $this->assertNull(DemoSetupHelper::online_por_defecto(), 'APP_URL = "' . $app_url . '"');
        }
    }
}
