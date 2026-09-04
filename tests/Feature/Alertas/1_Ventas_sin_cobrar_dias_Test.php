<?php

namespace Tests\Feature\Alertas;

use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Mision recordatorio-cobro-whatsapp — Pieza 1: el input de `dias` en `GET
 * api/sales-ventas-sin-cobrar`.
 *
 * El endpoint del modulo de alertas resolvia el umbral de antiguedad con una cascada por rol
 * (dueño -> `dias_alertar_administradores_ventas_no_cobradas`; empleado con columna propia -> la
 * suya; admin sin columna propia -> la de administradores) y no habia forma de mirar otra
 * ventana sin cambiarle la configuracion a la empresa. Ahora acepta `?dias=N`.
 *
 * 🔴 Lo que este archivo tiene que clavar, en este orden de importancia:
 *
 * 1. QUE SIN `?dias` NO CAMBIE NADA. La SPA que hoy corre en produccion pega a este endpoint sin
 *    query string. Si la cascada por rol se movio un milimetro, el test 1 lo tiene que ver antes
 *    que cualquier otra cosa: todo lo demas de la mision se apoya en este endpoint.
 * 2. Que el `?dias` mas grande devuelva un SUBCONJUNTO del mas chico (mas dias = mas exigente).
 * 3. Que el umbral por venta (`sales.dias_alerta_venta_no_cobrada_personalizado`, que entra por el
 *    `COALESCE` del `whereRaw`) le siga GANANDO al input. Es lo que hace que una venta marcada a
 *    mano no se pierda cuando el usuario mueve el filtro.
 * 4. Que un `dias` invalido caiga al default en vez de romper o de traer cualquier cosa.
 *
 * 🔴 TODAS las aserciones filtran por el cliente que crea este test. La base del slot arrastra
 * ventas de otras suites y de otros dueños; una aserción sobre el total de filas de la respuesta
 * seria verdadera hoy y falsa mañana.
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`).
 */
class Ventas_sin_cobrar_dias_Test extends EmpresaTestCase
{
    /** La ruta bajo prueba. */
    const RUTA = 'api/sales-ventas-sin-cobrar';

    /** Umbral de la empresa para administradores. Es el que le toca al dueño. */
    const DIAS_ADMINISTRADORES = 20;

    /** Umbral de la empresa para empleados. A proposito distinto del de arriba y del propio. */
    const DIAS_EMPLEADOS_DE_LA_EMPRESA = 45;

    /** Umbral propio del empleado, el que tiene que ganarle al de la empresa. */
    const DIAS_PROPIOS_DEL_EMPLEADO = 5;

    /** @var \App\Models\User Dueño de la empresa. */
    protected $owner;

    /** @var \App\Models\User Empleado comun, con umbral propio y sin ver las ventas de los demas. */
    protected $empleado;

    /** @var \App\Models\User Empleado con `admin_access` y sin umbral propio. */
    protected $admin;

    /** @var \App\Models\Client Cliente por el que se filtran TODAS las aserciones. */
    protected $cliente;

    /**
     * Las cuatro ventas base, indexadas por su antiguedad en dias: 3, 10, 20 y 40.
     *
     * @var array<int, \App\Models\Sale>
     */
    protected $ventas = [];

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = uniqid();

        $this->owner = User::create([
            'name'         => 'Dueño alertas cobros',
            'company_name' => 'Ferreteria alertas cobros',
            'email'        => 'alertas-cobros-owner-'.$sufijo.'@test.local',
            'password'     => Hash::make('secret'),
            'dias_alertar_administradores_ventas_no_cobradas' => self::DIAS_ADMINISTRADORES,
            'dias_alertar_empleados_ventas_no_cobradas'       => self::DIAS_EMPLEADOS_DE_LA_EMPRESA,
        ]);

        $this->empleado = User::create([
            'name'         => 'Empleado alertas cobros',
            'company_name' => 'Ferreteria alertas cobros',
            'email'        => 'alertas-cobros-empleado-'.$sufijo.'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->owner->id,
            'admin_access' => 0,
            // Umbral propio: es el que tiene que ganar en la cascada.
            'dias_alertar_empleados_ventas_no_cobradas' => self::DIAS_PROPIOS_DEL_EMPLEADO,
        ]);

        $this->admin = User::create([
            'name'         => 'Admin alertas cobros',
            'company_name' => 'Ferreteria alertas cobros',
            'email'        => 'alertas-cobros-admin-'.$sufijo.'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->owner->id,
            'admin_access' => 1,
            // Sin umbral propio: por eso cae al de administradores.
            'dias_alertar_empleados_ventas_no_cobradas' => null,
            // Sin esto el admin solo veria SUS ventas y no habria nada que medir.
            'ver_alertas_de_todos_los_empleados' => 1,
        ]);

        $this->cliente = Client::create([
            'name'    => 'Cliente deudor de alertas',
            'user_id' => $this->owner->id,
            'phone'   => '5493416001122',
        ]);

        // Las cuatro ventas del fixture: todas del mismo empleado, para que el recorte por
        // `ver_solo_las_ventas_suyas` tenga algo que devolver cuando actua como empleado.
        foreach (array(3, 10, 20, 40) as $antiguedad) {
            $this->ventas[$antiguedad] = $this->venta_sin_cobrar($antiguedad);
        }

        $this->actuar_como($this->owner);
    }

    /**
     * Crea una venta impaga del cliente del test, con la antiguedad pedida.
     *
     * La `current_acount` va con `debe > 0` y `status = 'sin_pagar'`, que es la primera rama del
     * `whereHas` del endpoint (la otra es `pagandose`, que no hace falta ejercitar acá).
     *
     * @param int      $dias_de_antiguedad       Cuantos dias atras se creo la venta.
     * @param int|null $umbral_personalizado     Valor de `dias_alerta_venta_no_cobrada_personalizado`.
     * @return \App\Models\Sale
     */
    protected function venta_sin_cobrar($dias_de_antiguedad, $umbral_personalizado = null)
    {
        $creada_at = now()->subDays($dias_de_antiguedad);

        $sale = Sale::create([
            'user_id'     => $this->owner->id,
            'client_id'   => $this->cliente->id,
            'employee_id' => $this->empleado->id,
            'moneda_id'   => 1,
            'total'       => 1000,
            'terminada'   => 1,
            'dias_alerta_venta_no_cobrada_personalizado' => $umbral_personalizado,
            // Se pasa en el create a proposito: Eloquent respeta `created_at` si ya viene sucio.
            'created_at'  => $creada_at,
            'updated_at'  => $creada_at,
        ]);

        CurrentAcount::create([
            'user_id'    => $this->owner->id,
            'client_id'  => $this->cliente->id,
            'sale_id'    => $sale->id,
            'moneda_id'  => 1,
            'debe'       => 1000,
            'haber'      => 0,
            'saldo'      => 1000,
            'status'     => 'sin_pagar',
            'created_at' => $creada_at,
            'updated_at' => $creada_at,
        ]);

        return $sale;
    }

    /**
     * Cambia el usuario autenticado para las requests que siguen.
     *
     * 🔴 El `Auth::forgetGuards()` no es decorativo y costo una corrida en rojo: la ruta esta
     * bajo `auth:sanctum`, y el middleware `Authenticate` termina haciendo
     * `shouldUse('sanctum')`. El guard de sanctum es un `RequestGuard` que CACHEA el usuario que
     * resolvio la primera vez, y vive en el mismo container durante todo el test. Sin olvidarlo,
     * el segundo `actingAs()` del test escribe en el guard `web` pero la request sigue
     * resolviendo al usuario de la primera: los tres roles del test 1 daban el mismo resultado
     * y parecia que la cascada estaba rota.
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Pega al endpoint y devuelve los ids de las ventas DEL CLIENTE DEL TEST, ordenados.
     *
     * 🔴 El filtro por cliente no es una comodidad: la base del slot tiene ventas de otras suites,
     * asi que cualquier aserción sobre el total de la respuesta seria falsa apenas alguien sume
     * un test en otra carpeta.
     *
     * @param string $query_string Lo que va despues de la ruta (ej. '?dias=30'). Vacio = sin `dias`.
     * @return array<int, int>
     */
    protected function ids_de_mi_cliente($query_string = '')
    {
        $respuesta = $this->getJson(self::RUTA.$query_string);

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertArrayHasKey('models', $json, 'La respuesta perdio la clave "models".');

        $ids = [];

        foreach ($json['models'] as $fila) {

            if (is_null($fila['client']) || (int) $fila['client']['id'] !== (int) $this->cliente->id) {
                continue;
            }

            foreach ($fila['ventas_sin_cobrar'] as $venta) {
                $ids[] = (int) $venta['id'];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Devuelve los ids de las ventas del fixture con las antiguedades pedidas, ordenados, para
     * comparar contra lo que trae el endpoint.
     *
     * @param array<int, int> $antiguedades
     * @return array<int, int>
     */
    protected function ids_esperados(array $antiguedades)
    {
        $ids = [];

        foreach ($antiguedades as $antiguedad) {
            $ids[] = (int) $this->ventas[$antiguedad]->id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * TEST 1 — EL DE NO REGRESION, el mas importante del archivo.
     *
     * Sin `?dias` en el query string, los tres roles tienen que resolver exactamente el mismo
     * umbral que resolvian antes de esta mision:
     *
     *  a) dueño            -> `dias_alertar_administradores_ventas_no_cobradas` (20) -> {20, 40}
     *  b) empleado propio  -> su `dias_alertar_empleados_ventas_no_cobradas` (5)     -> {10, 20, 40}
     *  c) admin sin propio -> cae a administradores (20)                             -> {20, 40}
     *
     * Los tres umbrales de la empresa son distintos a proposito (20 / 45 / 5): si la cascada se
     * cayera al de empleados de la empresa (45) no volveria ninguna venta, y si el input pisara
     * la cascada cuando no vino, (b) traeria {20, 40} en vez de {10, 20, 40}.
     *
     * @test
     * @return void
     */
    public function sin_dias_el_endpoint_se_comporta_igual_que_siempre()
    {
        // (a) El dueño: manda el umbral de administradores.
        $this->actuar_como($this->owner);

        $this->assertSame(
            $this->ids_esperados(array(20, 40)),
            $this->ids_de_mi_cliente(),
            'El dueño sin ?dias tiene que ver las ventas de 20 dias o mas (umbral de administradores).'
        );

        // (b) El empleado con umbral propio: manda el suyo, y solo ve SUS ventas.
        $this->actuar_como($this->empleado);

        $this->assertSame(
            $this->ids_esperados(array(10, 20, 40)),
            $this->ids_de_mi_cliente(),
            'El empleado sin ?dias tiene que ver las ventas de 5 dias o mas (su umbral propio).'
        );

        // (c) El admin sin umbral propio: cae al de administradores, no al de empleados.
        $this->actuar_como($this->admin);

        $this->assertSame(
            $this->ids_esperados(array(20, 40)),
            $this->ids_de_mi_cliente(),
            'El admin con la columna propia en null tiene que caer al umbral de administradores.'
        );
    }

    /**
     * TEST 2 — mas dias es mas exigente.
     *
     * Se afirma la RELACION (subconjunto estricto) y no una lista fija: lo que importa es que
     * subir el filtro nunca puede hacer aparecer una venta que con el filtro mas bajo no estaba.
     *
     * @test
     * @return void
     */
    public function con_dias_30_devuelve_un_subconjunto_de_dias_5()
    {
        $this->actuar_como($this->owner);

        $ids_5  = $this->ids_de_mi_cliente('?dias=5');
        $ids_30 = $this->ids_de_mi_cliente('?dias=30');

        $this->assertNotEmpty($ids_5, 'Con ?dias=5 tendrian que volver las ventas de 10, 20 y 40 dias.');
        $this->assertNotEmpty($ids_30, 'Con ?dias=30 tendria que volver la venta de 40 dias.');

        $this->assertSame(
            [],
            array_values(array_diff($ids_30, $ids_5)),
            'Con ?dias=30 aparecio una venta que con ?dias=5 no estaba: el filtro no es monotono.'
        );

        $this->assertLessThan(
            count($ids_5),
            count($ids_30),
            'Subir el filtro de 5 a 30 dias tiene que recortar el conjunto, no dejarlo igual.'
        );
    }

    /**
     * TEST 3 — el umbral por venta le gana al `dias` del input.
     *
     * Las dos mitades van en el mismo test a proposito: sola, cualquiera de las dos es trivial.
     * Juntas dicen que el `COALESCE(sales.dias_alerta_venta_no_cobrada_personalizado, ?)` sigue
     * mandando y que el `?` sigue siendo el umbral general.
     *
     * Con `?dias=90` ninguna de las cuatro ventas del fixture (3, 10, 20 y 40 dias) entra, asi
     * que lo unico que puede volver es la venta marcada a mano.
     *
     * @test
     * @return void
     */
    public function el_umbral_por_venta_le_gana_al_dias_del_input()
    {
        $this->actuar_como($this->owner);

        // Hace 10 dias, pero marcada a mano para alertar a los 3: tiene que aparecer igual.
        $con_umbral_propio = $this->venta_sin_cobrar(10, 3);

        // Su hermana: mismos 10 dias, sin umbral propio. Con ?dias=90 no le toca aparecer.
        $sin_umbral_propio = $this->venta_sin_cobrar(10);

        $ids = $this->ids_de_mi_cliente('?dias=90');

        $this->assertContains(
            (int) $con_umbral_propio->id,
            $ids,
            'La venta con dias_alerta_venta_no_cobrada_personalizado = 3 tiene que aparecer aunque el input pida 90.'
        );

        $this->assertNotContains(
            (int) $sin_umbral_propio->id,
            $ids,
            'La venta de 10 dias SIN umbral propio no puede aparecer con ?dias=90.'
        );
    }

    /**
     * TEST 4 — un `dias` invalido cae al default sin romper.
     *
     * `-5`, `abc`, `5.5` y el vacio no son "cero dias" ni un error 422: son "no vino nada usable",
     * y el endpoint tiene que responder 200 con exactamente el mismo set que sin `?dias`. El
     * `0`, en cambio, SI es un pedido valido ("todas las ventas sin cobrar, sin importar la
     * antiguedad") y tiene que traer las cuatro.
     *
     * @test
     * @return void
     */
    public function un_dias_invalido_cae_al_default_sin_romper()
    {
        $this->actuar_como($this->owner);

        $default = $this->ids_de_mi_cliente();

        $this->assertSame(
            $this->ids_esperados(array(20, 40)),
            $default,
            'El default del dueño se movio: el resto del test compara contra el.'
        );

        $invalidos = array('?dias=-5', '?dias=abc', '?dias=5.5', '?dias=');

        foreach ($invalidos as $query_string) {

            $this->assertSame(
                $default,
                $this->ids_de_mi_cliente($query_string),
                'Con "'.$query_string.'" el endpoint tendria que comportarse como sin ?dias.'
            );
        }

        // El 0 es valido: no hay recorte por antiguedad y vuelven las cuatro ventas del fixture.
        $this->assertSame(
            $this->ids_esperados(array(3, 10, 20, 40)),
            $this->ids_de_mi_cliente('?dias=0'),
            'Con ?dias=0 tienen que volver todas las ventas sin cobrar del cliente.'
        );
    }
}
