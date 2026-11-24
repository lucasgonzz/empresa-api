<?php

namespace Tests\Feature\Caja;

use App\Models\Address;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión cajas-permisos-claros (24/11/2026, ticket 167 de Trama).
 *
 * Hasta hoy la regla «quién ve una caja en Tesorería» vivía sólo en la SPA (`Caja.vue`) y el
 * servidor entregaba todas las cajas del dueño, con saldos, aperturas y movimientos, a cualquier
 * empleado. Además el buscador de Tesorería (`global-search/caja`) ignoraba esa regla: Brisa, de la
 * sucursal A, escribía en el buscador y le aparecían las cajas de las demás sucursales.
 *
 * La regla (no cambia de significado para nadie):
 *  - dueño o `admin_access` → ven y operan todas;
 *  - lista de Tesorería (`treasury_users`) con gente → sólo ellos ven la caja en Tesorería;
 *  - si no, lista de Vender (`users`) con gente → sólo ellos (herencia);
 *  - las dos vacías → la ven todos.
 *
 * Escenario «Brisa»: sucursales A y B; «Caja A» (sólo Brisa en Tesorería), «Caja B» (sólo Beto,
 * con saldo 5000) y «Caja libre» (sin listas).
 *
 * @group caja
 */
class Visibilidad_en_tesoreria_Test extends EmpresaTestCase
{
    /** Mensaje exacto del 403 cuando un empleado abre por id una caja que no ve en Tesorería. */
    const MENSAJE_403 = 'No tenés permiso para ver esta caja en Tesorería. Pedile al dueño que te agregue en la caja, en «Quiénes la ven en Tesorería».';

    /** Saldo de la «Caja B»: tiene que viajar al dueño y NO a Brisa. */
    const SALDO_CAJA_B = 5000;

    /** @var \App\Models\User Dueño del fixture de testing. */
    protected $owner;

    /** @var \App\Models\User Empleada de la sucursal A, sin admin_access. */
    protected $brisa;

    /** @var \App\Models\User Empleado de la sucursal B, sin admin_access. */
    protected $beto;

    /** @var \App\Models\User Empleada con admin_access. */
    protected $admin_empleada;

    /** @var \App\Models\Caja Caja de la sucursal A: sólo Brisa en Tesorería. */
    protected $caja_a;

    /** @var \App\Models\Caja Caja de la sucursal B: sólo Beto en Tesorería, con saldo. */
    protected $caja_b;

    /** @var \App\Models\Caja Caja sin ninguna lista. */
    protected $caja_libre;

    /** @var string Sufijo único para encontrar las cajas del test en el buscador. */
    protected $sufijo;

    /**
     * Crea el escenario «Brisa» con datos propios (nada de residuos: DatabaseTransactions).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->sufijo = 'vt' . substr(uniqid(), -7);

        $direccion_a = Address::create(['street' => 'Sucursal A ' . $this->sufijo, 'user_id' => $this->owner->id]);
        $direccion_b = Address::create(['street' => 'Sucursal B ' . $this->sufijo, 'user_id' => $this->owner->id]);

        $this->brisa = $this->crear_empleado('Brisa', $direccion_a->id, 0);
        $this->beto = $this->crear_empleado('Beto', $direccion_b->id, 0);
        $this->admin_empleada = $this->crear_empleado('Admin', $direccion_a->id, 1);

        $this->caja_a = $this->crear_caja('Caja A', $direccion_a->id, 0);
        $this->caja_b = $this->crear_caja('Caja B', $direccion_b->id, self::SALDO_CAJA_B);
        $this->caja_libre = $this->crear_caja('Caja libre', null, 0);

        $this->caja_a->treasury_users()->attach($this->brisa->id);
        $this->caja_b->treasury_users()->attach($this->beto->id);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::forgetGuards();

        parent::tearDown();
    }

    /**
     * Crea un empleado colgado del dueño.
     *
     * @param string $nombre Nombre de pila.
     * @param int $address_id Sucursal del empleado.
     * @param int $admin_access 1 si es administrador.
     * @return \App\Models\User
     */
    protected function crear_empleado($nombre, $address_id, $admin_access)
    {
        return User::create([
            'name'         => $nombre . ' ' . $this->sufijo,
            'company_name' => 'Ferreteria visibilidad',
            'email'        => strtolower($nombre) . '-' . $this->sufijo . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->owner->id,
            'address_id'   => $address_id,
            'admin_access' => $admin_access,
        ]);
    }

    /**
     * Crea una caja del dueño del fixture.
     *
     * @param string $nombre Nombre base (se le agrega el sufijo del test).
     * @param int|null $address_id Sucursal de la caja.
     * @param float $saldo Saldo inicial.
     * @return \App\Models\Caja
     */
    protected function crear_caja($nombre, $address_id, $saldo)
    {
        return Caja::create([
            'num'        => random_int(100000, 999999),
            'name'       => $nombre . ' ' . $this->sufijo,
            'user_id'    => $this->owner->id,
            'address_id' => $address_id,
            'saldo'      => $saldo,
        ]);
    }

    /**
     * Cambia el usuario autenticado.
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
     * Busca una caja por id dentro de la lista `models` de una respuesta.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @param int $caja_id
     * @return array|null
     */
    protected function fila_de($response, $caja_id)
    {
        foreach ($response->json('models') as $fila) {
            if ((int) $fila['id'] === (int) $caja_id) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * Crea una apertura (con un movimiento) para una caja y devuelve la apertura.
     *
     * @param \App\Models\Caja $caja
     * @return \App\Models\AperturaCaja
     */
    protected function crear_apertura_con_movimiento($caja)
    {
        $apertura = AperturaCaja::create(['caja_id' => $caja->id, 'saldo_apertura' => 0]);

        MovimientoCaja::create([
            'caja_id'          => $caja->id,
            'apertura_caja_id' => $apertura->id,
            'ingreso'          => 10,
            'saldo'            => 10,
            'notas'            => 'Movimiento de prueba de visibilidad',
        ]);

        return $apertura;
    }

    /**
     * CA1: el listado marca por caja si Brisa la ve y oculta saldos de las ajenas; el dueño lo ve todo.
     *
     * @test
     * @return void
     */
    public function index_marca_visibilidad_y_oculta_saldos_ajenos()
    {
        $this->actuar_como($this->brisa);

        $response = $this->getJson('api/caja');
        $response->assertStatus(200);

        $a = $this->fila_de($response, $this->caja_a->id);
        $b = $this->fila_de($response, $this->caja_b->id);
        $libre = $this->fila_de($response, $this->caja_libre->id);

        $this->assertNotNull($a, 'Caja A tiene que seguir en el catálogo.');
        $this->assertNotNull($b, 'Caja B tiene que seguir en el catálogo (Vender, cheques y gastos la necesitan).');
        $this->assertNotNull($libre, 'Caja libre tiene que seguir en el catálogo.');

        $this->assertTrue($a['ve_en_tesoreria'] ?? null, 'Brisa está en la lista de Tesorería de Caja A.');
        $this->assertSame('lista_tesoreria', $a['origen_visibilidad_tesoreria'] ?? null);

        $this->assertFalse($b['ve_en_tesoreria'] ?? null, 'Brisa no está en la lista de Tesorería de Caja B.');
        $this->assertArrayHasKey('saldo', $b, 'El campo saldo sigue existiendo (compatibilidad), en null.');
        $this->assertNull($b['saldo']);
        $this->assertNull($b['saldo_contable'] ?? 'falta');
        $this->assertNull($b['saldo_disponible'] ?? 'falta');
        $this->assertNull($b['saldo_a_liquidar'] ?? 'falta');

        $this->assertTrue($libre['ve_en_tesoreria'] ?? null);
        $this->assertSame('todos', $libre['origen_visibilidad_tesoreria'] ?? null);

        // El dueño ve las tres y el saldo de Caja B.
        $this->actuar_como($this->owner);

        $response = $this->getJson('api/caja');
        $response->assertStatus(200);

        foreach ([$this->caja_a, $this->caja_b, $this->caja_libre] as $caja) {
            $fila = $this->fila_de($response, $caja->id);
            $this->assertTrue($fila['ve_en_tesoreria'] ?? null, 'El dueño ve todas las cajas.');
        }

        $b = $this->fila_de($response, $this->caja_b->id);
        $this->assertEquals(self::SALDO_CAJA_B, (float) $b['saldo']);
    }

    /**
     * CA2: lista de Tesorería vacía + lista de Vender con gente → hereda, y lo dice el origen.
     *
     * @test
     * @return void
     */
    public function lista_de_tesoreria_vacia_hereda_de_vender_de_forma_explicita()
    {
        $this->caja_libre->users()->attach($this->brisa->id);

        $this->actuar_como($this->brisa);
        $fila = $this->fila_de($this->getJson('api/caja'), $this->caja_libre->id);

        $this->assertTrue($fila['ve_en_tesoreria'] ?? null);
        $this->assertSame('lista_vender', $fila['origen_visibilidad_tesoreria'] ?? null);
        $this->assertTrue($fila['opera_en_vender'] ?? null);

        $this->actuar_como($this->beto);
        $fila = $this->fila_de($this->getJson('api/caja'), $this->caja_libre->id);

        $this->assertFalse($fila['ve_en_tesoreria'] ?? null, 'Beto no está en la lista de Vender que se hereda.');
        $this->assertFalse($fila['opera_en_vender'] ?? null);
    }

    /**
     * CA3: el buscador de Tesorería no devuelve cajas ajenas a Brisa.
     *
     * @test
     * @return void
     */
    public function buscador_de_tesoreria_no_muestra_cajas_ajenas()
    {
        $this->actuar_como($this->brisa);

        $response = $this->postJson('api/global-search/caja', [
            'query_value' => $this->sufijo,
            'props'       => ['name'],
        ]);

        $response->assertStatus(200);

        $ids = collect($response->json('models.data'))->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        $this->assertContains((int) $this->caja_a->id, $ids);
        $this->assertContains((int) $this->caja_libre->id, $ids);
        $this->assertNotContains((int) $this->caja_b->id, $ids, 'El buscador no puede mostrarle a Brisa la Caja B.');
    }

    /**
     * CA3 (bis): dueño y empleado con admin_access ven las tres en el buscador.
     *
     * @test
     * @return void
     */
    public function buscador_admin_y_dueno_ven_todas()
    {
        foreach ([$this->owner, $this->admin_empleada] as $usuario) {
            $this->actuar_como($usuario);

            $response = $this->postJson('api/global-search/caja', [
                'query_value' => $this->sufijo,
                'props'       => ['name'],
            ]);

            $response->assertStatus(200);

            $ids = collect($response->json('models.data'))->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all();

            foreach ([$this->caja_a, $this->caja_b, $this->caja_libre] as $caja) {
                $this->assertContains((int) $caja->id, $ids, 'Dueño y administrador ven todas las cajas.');
            }
        }
    }

    /**
     * CA4 y CA11: aperturas y movimientos de una caja ajena dan 403 con el mensaje claro; la propia, 200.
     *
     * @test
     * @return void
     */
    public function aperturas_y_movimientos_de_caja_ajena_dan_403_con_mensaje()
    {
        $apertura_b = $this->crear_apertura_con_movimiento($this->caja_b);
        $apertura_a = $this->crear_apertura_con_movimiento($this->caja_a);

        $this->actuar_como($this->brisa);

        $this->getJson('api/apertura-caja/' . $this->caja_b->id)
            ->assertStatus(403)
            ->assertJson(['message' => self::MENSAJE_403]);

        $this->getJson('api/apertura-caja/show/' . $apertura_b->id)
            ->assertStatus(403)
            ->assertJson(['message' => self::MENSAJE_403]);

        $this->getJson('api/movimiento-caja/' . $apertura_b->id)
            ->assertStatus(403)
            ->assertJson(['message' => self::MENSAJE_403]);

        // Sobre la caja propia sigue andando como hoy.
        $this->getJson('api/apertura-caja/' . $this->caja_a->id)->assertStatus(200);
        $this->getJson('api/apertura-caja/show/' . $apertura_a->id)->assertStatus(200);
        $this->getJson('api/movimiento-caja/' . $apertura_a->id)->assertStatus(200);

        // El dueño abre la caja de cualquiera.
        $this->actuar_como($this->owner);
        $this->getJson('api/apertura-caja/' . $this->caja_b->id)->assertStatus(200);
    }

    /**
     * CA5: compatibilidad. Con las dos listas vacías la ve y la opera cualquier empleado.
     *
     * @test
     * @return void
     */
    public function cajas_sin_listas_siguen_abiertas_para_todos()
    {
        $this->crear_apertura_con_movimiento($this->caja_libre);

        foreach ([$this->brisa, $this->beto] as $empleado) {
            $this->actuar_como($empleado);

            $fila = $this->fila_de($this->getJson('api/caja'), $this->caja_libre->id);

            $this->assertTrue($fila['ve_en_tesoreria'] ?? null);
            $this->assertTrue($fila['opera_en_vender'] ?? null);
            $this->assertSame('todos', $fila['origen_visibilidad_tesoreria'] ?? null);

            $this->getJson('api/apertura-caja/' . $this->caja_libre->id)->assertStatus(200);
        }
    }
}
