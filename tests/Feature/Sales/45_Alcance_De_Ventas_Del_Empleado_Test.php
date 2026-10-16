<?php

namespace Tests\Feature\Sales;

use App\Models\PermissionEmpresa;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión ventas-total-visible-al-empleado (16/10/2026).
 *
 * Desde esta misión todo empleado ve el Total en el módulo de Ventas, aunque no tenga "Ver el total
 * vendido" (`sale.index.total`). Para que eso no deje ver el total de ventas que NO tiene permitido
 * ver, el alcance (`sale.index.addresses.*` / `sale.index.employees.*`) lo impone la API a partir de
 * los permisos del usuario de la sesión y no de las solapas que mande la SPA.
 *
 * Lo que se protege acá, en orden de importancia:
 *
 *   1. Un empleado acotado ("solo sus ventas" / "solo las de su sucursal") recibe filas, cantidad y
 *      totales de su alcance, y NO se amplía ni se cambia pidiendo la solapa de otro o la del dueño.
 *   2. Dueño y administrador siguen viéndolo todo, aunque tengan "solo las mías" tildado.
 *   3. "todos" gana sobre "solo las mías", y sin ninguno de los permisos de un eje no se acota
 *      (nadie pierde visibilidad por un permiso que nunca tuvo tildado).
 *   4. Un empleado con "todos" que elige la solapa de otro sigue pudiendo hacerlo.
 *   5. La API devuelve `totales` sin mirar `sale.index.total`: la SPA nueva depende de eso.
 *   6. Las filas de la página y `totales.cantidad` salen del mismo conjunto.
 *
 * @group sales
 */
class Alcance_De_Ventas_Del_Empleado_Test extends EmpresaTestCase
{
    /** Día fijo y lejano, que ningún otro test usa. */
    public $dia = '2037-08-10';

    /** `sales.address_id` no tiene FK: alcanza con dos ids distintos. */
    public $sucursal_1 = 1;
    public $sucursal_2 = 2;

    /** @var \App\Models\User */
    protected $owner;

    /** @var \App\Models\User */
    protected $empleado_a;

    /** @var \App\Models\User */
    protected $empleado_b;

    /** @var array Ventas del test, por nombre: ['dueno_s1' => Sale, ...]. */
    protected $ventas = [];

    /**
     * Seis ventas del dueño en dos sucursales y de tres vendedores (dueño sin empleado, A y B):
     *
     *   dueño_s1 100 · dueño_s2 200 · A_s1 300 · A_s2 400 · B_s1 500 · B_s2 600
     *
     * Total 2100 · A 700 · B 1100 · solo dueño 300 · sucursal 1 = 900 · sucursal 2 = 1200.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->empleado_a = $this->crear_empleado([], $this->sucursal_1);
        $this->empleado_b = $this->crear_empleado([], $this->sucursal_2);

        $this->ventas['dueno_s1'] = $this->crear_venta(100, null, $this->sucursal_1);
        $this->ventas['dueno_s2'] = $this->crear_venta(200, null, $this->sucursal_2);
        $this->ventas['a_s1'] = $this->crear_venta(300, $this->empleado_a->id, $this->sucursal_1);
        $this->ventas['a_s2'] = $this->crear_venta(400, $this->empleado_a->id, $this->sucursal_2);
        $this->ventas['b_s1'] = $this->crear_venta(500, $this->empleado_b->id, $this->sucursal_1);
        $this->ventas['b_s2'] = $this->crear_venta(600, $this->empleado_b->id, $this->sucursal_2);
    }

    // ---------------------------------------------------------------------------------------
    // 1. Un empleado acotado ve solo su alcance, y no lo amplía con el pedido
    // ---------------------------------------------------------------------------------------

    /**
     * "Solo sus propias ventas": aunque el pedido no traiga solapa, la API le devuelve solo las
     * suyas; y pidiendo la solapa de otro empleado o la del dueño, el resultado es el mismo.
     *
     * @test
     */
    public function empleado_con_solo_las_suyas_recibe_solo_las_suyas_y_no_puede_ampliarlo()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.employees.only_your']));
        $yo = Auth::user();
        $mia = $this->crear_venta(70, $yo->id, $this->sucursal_1);

        foreach ([[], ['employee_id' => $this->empleado_b->id], ['only_owner' => 1], ['employee_id' => $yo->id]] as $pedido) {
            $resultado = $this->pedir($pedido);

            $this->assertSame(1, $resultado['totales']['cantidad'], 'Pedido: '.json_encode($pedido));
            $this->assertEquals(70, $resultado['totales']['pesos']['total'], 'Pedido: '.json_encode($pedido));
            $this->assertSame([$mia->id], $resultado['ids'], 'Pedido: '.json_encode($pedido));
        }
    }

    /**
     * "Solo las de su sucursal": con sucursal asignada recibe solo esa, pida la que pida.
     *
     * @test
     */
    public function empleado_con_solo_su_sucursal_recibe_solo_esa_y_no_puede_ampliarlo()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1));

        foreach ([[], ['address_id' => $this->sucursal_2]] as $pedido) {
            $resultado = $this->pedir($pedido);

            $this->assertSame(3, $resultado['totales']['cantidad'], 'Pedido: '.json_encode($pedido));
            $this->assertEquals(900, $resultado['totales']['pesos']['total'], 'Pedido: '.json_encode($pedido));
            $this->assertEqualsCanonicalizing($this->ids(['dueno_s1', 'a_s1', 'b_s1']), $resultado['ids']);
        }
    }

    /**
     * Los dos ejes a la vez acotan juntos: solo sus ventas Y solo en su sucursal.
     *
     * @test
     */
    public function los_dos_ejes_acotan_juntos()
    {
        $this->actuar_como($this->crear_empleado(
            ['sale.index.employees.only_your', 'sale.index.addresses.only_your'],
            $this->sucursal_1
        ));
        $yo = Auth::user();
        $mia_s1 = $this->crear_venta(30, $yo->id, $this->sucursal_1);
        $this->crear_venta(40, $yo->id, $this->sucursal_2);
        $this->crear_venta(50, $this->empleado_a->id, $this->sucursal_1);

        $resultado = $this->pedir([]);

        $this->assertSame(1, $resultado['totales']['cantidad']);
        $this->assertEquals(30, $resultado['totales']['pesos']['total']);
        $this->assertSame([$mia_s1->id], $resultado['ids']);
    }

    // ---------------------------------------------------------------------------------------
    // 2. Dueño y administrador no se acotan
    // ---------------------------------------------------------------------------------------

    /**
     * El dueño ve todo; las solapas siguen funcionando para él.
     *
     * @test
     */
    public function el_dueno_ve_todo_y_sus_solapas_siguen_funcionando()
    {
        $this->actuar_como($this->owner);

        $todo = $this->pedir([]);
        $this->assertSame(6, $todo['totales']['cantidad']);
        $this->assertEquals(2100, $todo['totales']['pesos']['total']);

        $de_a = $this->pedir(['employee_id' => $this->empleado_a->id]);
        $this->assertSame(2, $de_a['totales']['cantidad']);
        $this->assertEquals(700, $de_a['totales']['pesos']['total']);

        $de_la_sucursal_2 = $this->pedir(['address_id' => $this->sucursal_2]);
        $this->assertEquals(1200, $de_la_sucursal_2['totales']['pesos']['total']);

        $solo_dueno = $this->pedir(['only_owner' => 1]);
        $this->assertEquals(300, $solo_dueno['totales']['pesos']['total']);
    }

    /**
     * Un empleado administrador (`admin_access`) lo ve todo aunque tenga "solo las mías" tildado:
     * es lo que dice `can()` de la SPA y `Controller::is_admin()`.
     *
     * @test
     */
    public function el_administrador_ve_todo_aunque_tenga_solo_las_mias_tildado()
    {
        $this->actuar_como($this->crear_empleado(
            ['sale.index.employees.only_your', 'sale.index.addresses.only_your'],
            $this->sucursal_1,
            ['admin_access' => 1]
        ));

        $resultado = $this->pedir([]);

        $this->assertSame(6, $resultado['totales']['cantidad']);
        $this->assertEquals(2100, $resultado['totales']['pesos']['total']);
    }

    // ---------------------------------------------------------------------------------------
    // 3. "todos" gana, y sin permisos de alcance no se acota
    // ---------------------------------------------------------------------------------------

    /**
     * "todos" y "solo las mías" del mismo eje: gana "todos" (lo dice el catálogo de permisos).
     *
     * @test
     */
    public function todos_gana_sobre_solo_las_mias()
    {
        $this->actuar_como($this->crear_empleado(
            ['sale.index.employees.all', 'sale.index.employees.only_your', 'sale.index.addresses.all', 'sale.index.addresses.only_your'],
            $this->sucursal_1
        ));

        $resultado = $this->pedir([]);

        $this->assertSame(6, $resultado['totales']['cantidad']);
        $this->assertEquals(2100, $resultado['totales']['pesos']['total']);
    }

    /**
     * Sin ninguno de los cuatro permisos ve todo, como hasta hoy. Es el caso de un empleado al que
     * el dueño nunca le tildó nada de "qué ventas ver": no pierde visibilidad.
     *
     * @test
     */
    public function sin_permisos_de_alcance_ve_todo()
    {
        $this->actuar_como($this->crear_empleado([], $this->sucursal_1));

        $resultado = $this->pedir([]);

        $this->assertSame(6, $resultado['totales']['cantidad']);
        $this->assertEquals(2100, $resultado['totales']['pesos']['total']);
    }

    /**
     * "Solo mi sucursal" sin sucursal asignada no acota: la SPA tampoco lo hace
     * (`get_address_param` cae a "todas"), y acotar a "ninguna" le sacaría todo de golpe.
     *
     * @test
     */
    public function solo_mi_sucursal_sin_sucursal_asignada_no_acota()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], null));

        $resultado = $this->pedir([]);

        $this->assertSame(6, $resultado['totales']['cantidad']);
        $this->assertEquals(2100, $resultado['totales']['pesos']['total']);
    }

    // ---------------------------------------------------------------------------------------
    // 4. Con "todos" puede elegir solapa
    // ---------------------------------------------------------------------------------------

    /**
     * Un empleado con "todos" que elige la solapa de otro empleado recibe las de ese empleado.
     *
     * @test
     */
    public function empleado_con_todos_puede_elegir_la_solapa_de_otro()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.employees.all', 'sale.index.addresses.all']));

        $de_b = $this->pedir(['employee_id' => $this->empleado_b->id]);
        $this->assertSame(2, $de_b['totales']['cantidad']);
        $this->assertEquals(1100, $de_b['totales']['pesos']['total']);

        $de_b_en_s1 = $this->pedir(['employee_id' => $this->empleado_b->id, 'address_id' => $this->sucursal_1]);
        $this->assertSame(1, $de_b_en_s1['totales']['cantidad']);
        $this->assertEquals(500, $de_b_en_s1['totales']['pesos']['total']);
    }

    // ---------------------------------------------------------------------------------------
    // 5. La API no condiciona los totales a `sale.index.total`
    // ---------------------------------------------------------------------------------------

    /**
     * Un empleado SIN `sale.index.total` recibe `totales` completos. La SPA nueva le muestra el
     * Total a todo empleado y lo lee de acá: si la API empezara a condicionarlo a ese permiso, el
     * chip quedaría en cero sin ningún error.
     *
     * @test
     */
    public function la_api_devuelve_totales_aunque_el_empleado_no_tenga_ver_el_total()
    {
        $empleado = $this->crear_empleado(['sale.index.employees.only_your']);
        $this->assertFalse($empleado->permissions->contains('slug', 'sale.index.total'));
        $this->actuar_como($empleado);
        $this->crear_venta(70, $empleado->id, $this->sucursal_1);

        $resultado = $this->pedir([]);

        $this->assertArrayHasKey('pesos', $resultado['totales']);
        $this->assertArrayHasKey('dolares', $resultado['totales']);
        $this->assertEquals(70, $resultado['totales']['pesos']['total']);
    }

    // ---------------------------------------------------------------------------------------
    // 6. Filas y totales salen del mismo conjunto
    // ---------------------------------------------------------------------------------------

    /**
     * Página por página: la unión de las filas es el conjunto acotado, y el total del paginador es
     * `totales.cantidad`. Un empleado acotado no ve nunca una fila que no suma en su total.
     *
     * @test
     */
    public function las_filas_de_las_paginas_y_la_cantidad_salen_del_mismo_conjunto()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.employees.only_your']));
        $yo = Auth::user();
        $esperadas = [];
        for ($i = 0; $i < 5; $i++) {
            $esperadas[] = $this->crear_venta(10 * ($i + 1), $yo->id, $this->sucursal_1)->id;
        }

        $vistas = [];
        $total_de_filas = 0;
        foreach ([1, 2, 3] as $pagina) {
            $resultado = $this->pedir(['per_page' => 2, 'page' => $pagina]);
            $vistas = array_merge($vistas, $resultado['ids']);
            $total_de_filas = $resultado['paginador_total'];

            $this->assertSame(5, $resultado['totales']['cantidad']);
            $this->assertEquals(150, $resultado['totales']['pesos']['total']);
        }

        $this->assertSame(5, $total_de_filas);
        $this->assertEqualsCanonicalizing($esperadas, $vistas);
        $this->assertCount(5, array_unique($vistas), 'Una fila no puede repetirse entre páginas.');
    }

    // ---------------------------------------------------------------------------------------
    // 7. Casos que encontró el chequeo independiente
    // ---------------------------------------------------------------------------------------

    /**
     * El dueño no se acota aunque tenga "solo las mías" tildado (hay dueños que se lo tildaron por
     * costumbre al copiar los permisos de un empleado). Sin este caso, sacar el chequeo de
     * `owner_id` del helper no ponía rojo ningún test: el dueño del fixture no tiene permisos.
     *
     * @test
     */
    public function el_dueno_con_solo_las_mias_tildado_sigue_viendo_todo()
    {
        $this->dar_permisos($this->owner, ['sale.index.employees.only_your', 'sale.index.addresses.only_your']);
        $this->actuar_como($this->owner->fresh());

        $resultado = $this->pedir([]);

        $this->assertSame(6, $resultado['totales']['cantidad']);
        $this->assertEquals(2100, $resultado['totales']['pesos']['total']);
    }

    /**
     * 🔴 Una sucursal borrada deja `users.address_id` colgado (no tiene FK). La SPA la busca en el
     * catálogo, no la encuentra y cae a "todas": el servidor tiene que hacer lo mismo y no acotar a
     * una sucursal que ya no existe, que le dejaría el listado en cero.
     *
     * @test
     */
    public function solo_mi_sucursal_con_la_sucursal_borrada_no_acota()
    {
        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], 987654));

        $resultado = $this->pedir([]);

        $this->assertSame(6, $resultado['totales']['cantidad']);
        $this->assertEquals(2100, $resultado['totales']['pesos']['total']);
    }

    /**
     * 🔴 Con "ver consolidadas" las contenedoras de facturación (sin sucursal ni empleado, con el
     * total de ventas de cualquiera) NO se le suman a un empleado acotado: no son suyas. Para
     * quien eligió la solapa (dueño, "todos") la excepción sigue como siempre, y este mismo test lo
     * fija para que no se "arregle" también ahí.
     *
     * @test
     */
    public function las_contenedoras_consolidadas_no_se_suman_al_empleado_acotado()
    {
        $this->crear_venta(900, null, null, ['is_consolidacion_facturacion' => 1]);

        // Acotado a sus ventas, con "ver consolidadas" prendido: solo las suyas.
        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));
        $acotado = $this->pedir(['mostrar_consolidadas' => 1]);
        $this->assertSame(2, $acotado['totales']['cantidad']);
        $this->assertEquals(700, $acotado['totales']['pesos']['total']);

        // Acotado a su sucursal: tampoco.
        $this->actuar_como($this->crear_empleado(['sale.index.addresses.only_your'], $this->sucursal_1));
        $de_sucursal = $this->pedir(['mostrar_consolidadas' => 1]);
        $this->assertSame(3, $de_sucursal['totales']['cantidad']);
        $this->assertEquals(900, $de_sucursal['totales']['pesos']['total']);

        // El dueño, sin solapa, ve todo más la contenedora; y con la solapa de A, las de A más la contenedora (como siempre).
        $this->actuar_como($this->owner);
        $this->assertEquals(3000, $this->pedir(['mostrar_consolidadas' => 1])['totales']['pesos']['total']);
        $this->assertEquals(1600, $this->pedir(['mostrar_consolidadas' => 1, 'employee_id' => $this->empleado_a->id])['totales']['pesos']['total']);

        // Sin "ver consolidadas" la contenedora no entra para nadie.
        $this->assertEquals(2100, $this->pedir([])['totales']['pesos']['total']);
    }

    /**
     * El sub-total del método de pago también sale del conjunto acotado: un empleado acotado no
     * recibe lo cobrado con ese método en ventas de otros.
     *
     * @test
     */
    public function el_subtotal_del_metodo_de_pago_sale_del_conjunto_acotado()
    {
        $efectivo = 3;
        $this->pagar_con($this->ventas['dueno_s1']->id, $efectivo, 100);
        $this->pagar_con($this->ventas['a_s1']->id, $efectivo, 300);
        $this->pagar_con($this->ventas['b_s1']->id, $efectivo, 500);

        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $resultado = $this->pedir(['payment_method_show_option' => $efectivo]);

        $this->assertSame(1, $resultado['totales']['cantidad']);
        $this->assertSame([$this->ventas['a_s1']->id], $resultado['ids']);
        $this->assertEquals(300, $resultado['totales']['metodo_de_pago']['total']);
    }

    /**
     * Las ventas en dólares también salen del alcance: el chip Total USD del empleado es el suyo.
     *
     * @test
     */
    public function los_totales_en_dolares_tambien_salen_del_alcance()
    {
        $this->crear_venta(50, null, $this->sucursal_1, ['moneda_id' => 2]);
        $this->crear_venta(40, $this->empleado_a->id, $this->sucursal_1, ['moneda_id' => 2]);

        $this->actuar_como($this->empleado_acotado_a_sus_ventas($this->empleado_a));

        $resultado = $this->pedir([]);

        $this->assertEquals(40, $resultado['totales']['dolares']['total']);
        $this->assertEquals(700, $resultado['totales']['pesos']['total'], 'Las ventas en dólares no se mezclan en los pesos.');
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * Cambia el usuario autenticado (el guard cachea al primero: mismo recurso que usan los tests
     * de Hojas de ruta y Alertas).
     *
     * @param  \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Empleado del dueño del fixture con los permisos pedidos.
     *
     * @param  array    $slugs      Permisos que se le dan.
     * @param  int|null $address_id Sucursal asignada (`users.address_id`).
     * @param  array    $extra      Campos extra del usuario (`admin_access`, ...).
     * @return \App\Models\User
     */
    protected function crear_empleado($slugs = [], $address_id = null, $extra = [])
    {
        $user = User::create(array_merge([
            'name'         => 'Empleado '.uniqid(),
            'company_name' => 'Ferreteria alcance de ventas',
            'email'        => 'alcance-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $this->owner->id,
            'address_id'   => $address_id,
        ], $extra));

        $this->dar_permisos($user, $slugs);

        return $user->fresh();
    }

    /**
     * Le da "solo sus propias ventas" a un empleado que ya tiene ventas cargadas en el fixture
     * (A o B) y lo devuelve listo para `actuar_como()`.
     *
     * @param  \App\Models\User $empleado
     * @return \App\Models\User
     */
    protected function empleado_acotado_a_sus_ventas($empleado)
    {
        $this->dar_permisos($empleado, ['sale.index.employees.only_your']);

        return $empleado->fresh();
    }

    /**
     * Deja al usuario con exactamente esos permisos (crea el permiso si la base no lo tiene).
     *
     * @param  \App\Models\User $user
     * @param  array            $slugs
     * @return void
     */
    protected function dar_permisos($user, $slugs)
    {
        $ids = [];
        foreach ($slugs as $slug) {
            $permiso = PermissionEmpresa::where('slug', $slug)->first();
            if (is_null($permiso)) {
                $permiso = PermissionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug, 'model_name' => 'Ventas']);
            }
            $ids[] = $permiso->id;
        }
        if (count($ids)) {
            $user->permissions()->sync($ids);
        }
    }

    /**
     * Venta terminada del dueño, en pesos, el día del test.
     *
     * @param  float    $total
     * @param  int|null $employee_id
     * @param  int|null $address_id
     * @param  array    $extra       Campos que pisan a los de arriba (`moneda_id`, `is_consolidacion_facturacion`...).
     * @return \App\Models\Sale
     */
    protected function crear_venta($total, $employee_id, $address_id, $extra = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $this->owner->id,
            'employee_id'                => $employee_id,
            'address_id'                 => $address_id,
            'moneda_id'                  => 1,
            'total'                      => $total,
            'sub_total'                  => $total,
            'total_cost'                 => 0,
            'ganancia'                   => $total,
            'terminada'                  => 1,
            'confirmed'                  => 1,
            'omitir_en_cuenta_corriente' => 1,
            'created_at'                 => $this->dia.' 12:00:00',
            'terminada_at'               => $this->dia.' 12:00:00',
        ], $extra));
    }

    /**
     * Pivot de pago de la venta con un método, tal cual lo deja el módulo de vender.
     *
     * @param  int   $sale_id
     * @param  int   $metodo_id
     * @param  float $amount
     * @return void
     */
    protected function pagar_con($sale_id, $metodo_id, $amount)
    {
        DB::table('current_acount_payment_method_sale')->insert([
            'sale_id'                          => $sale_id,
            'current_acount_payment_method_id' => $metodo_id,
            'amount'                           => $amount,
            'created_at'                       => now(),
            'updated_at'                       => now(),
        ]);
    }

    /**
     * Ids de las ventas del fixture por nombre.
     *
     * @param  array $nombres
     * @return array
     */
    protected function ids($nombres)
    {
        return array_map(function ($nombre) {
            return $this->ventas[$nombre]->id;
        }, $nombres);
    }

    /**
     * Pega al listado paginado del día como lo hace la pantalla de Ventas y devuelve lo que se mira.
     * Sin `per_page` explícito pide 50 (una sola página con todo).
     *
     * @param  array $params Solapas / paginado extra.
     * @return array ['totales' => array, 'ids' => array, 'paginador_total' => int]
     */
    protected function pedir($params)
    {
        $query = array_merge(['per_page' => 50, 'page' => 1], $params);

        $response = $this->getJson('api/sale/from-date/ventas/'.$this->dia.'?'.http_build_query($query));
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertArrayHasKey('totales', $json, 'Con per_page la respuesta tiene que traer totales.');

        return [
            'totales'         => $json['totales'],
            'ids'             => array_column($json['models']['data'], 'id'),
            'paginador_total' => $json['models']['total'],
        ];
    }
}
