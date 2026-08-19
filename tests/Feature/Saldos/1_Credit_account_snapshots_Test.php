<?php

namespace Tests\Feature\Saldos;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CreditAccountSnapshot;
use App\Models\DebtSnapshot;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión sugerencias inteligentes de stock — pieza P9 (parte C): saldo diario por entidad.
 *
 * `debt:snapshot` pasa a persistir, además de los 4 totales agregados de `debt_snapshots`,
 * el saldo de CADA credit_account (cliente/proveedor × moneda) en `credit_account_snapshots`,
 * escribiendo fila SOLO cuando el saldo cambió respecto del último snapshot de esa cuenta.
 *
 * Lo que estos tests protegen:
 *  - que "guardar solo cambios" sea eso de verdad (una cuenta quieta no engorda la tabla,
 *    una cuenta en 0 sin historia no existe en el historial);
 *  - que el unique (credit_account_id, date) haga idempotente al comando dentro del día;
 *  - que la regla de lectura (scopeSaldoAlDia: último snapshot con date <= d, null = saldo 0)
 *    devuelva lo que promete, porque la misión B va a leer el historial solo por ahí;
 *  - que `debt_snapshots` se siga poblando exactamente igual que antes (la parte nueva se
 *    agregó DESPUÉS de process_user, no en el medio).
 *
 * Las aserciones van siempre scopeadas a las cuentas que crea el propio test: la base del
 * slot puede tener credit_accounts de otras suites y el total global no es de nadie.
 */
class Credit_account_snapshots_Test extends EmpresaTestCase
{
    /**
     * Delta para comparar montos decimales (misma tolerancia que usa el comando).
     */
    const DELTA = 0.01;

    /**
     * Días ficticios y fijos (lejos de cualquier "hoy" real) para que el test no dependa
     * del día en que se corre. El comando usa today(), que respeta Carbon::setTestNow().
     */
    const DIA_1 = '2030-01-10';
    const DIA_2 = '2030-01-11';
    const DIA_3 = '2030-01-12';

    /**
     * Ids de las credit_accounts creadas por el test, para el cleanup explícito.
     *
     * @var array<int,int>
     */
    protected $cuentas_creadas = [];

    /**
     * Devuelve el reloj a su lugar y borra lo propio del test (las cuentas creadas y sus
     * snapshots). DatabaseTransactions debería alcanzar; el cleanup explícito es la misma
     * red de seguridad que usan las demás suites.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        if (count($this->cuentas_creadas)) {
            CreditAccountSnapshot::whereIn('credit_account_id', $this->cuentas_creadas)->delete();
            CreditAccount::whereIn('id', $this->cuentas_creadas)->delete();
            $this->cuentas_creadas = [];
        }

        parent::tearDown();
    }

    /**
     * Usuario dueño del fixture, resuelto por email (nunca por id hardcodeado).
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Client del fixture por nombre, del usuario del fixture.
     *
     * @param string $nombre
     * @return \App\Models\Client
     */
    protected function cliente($nombre)
    {
        $cliente = Client::where('user_id', $this->usuario_de_testing()->id)
            ->where('name', $nombre)
            ->first();

        if (is_null($cliente)) {
            $this->fail('No existe el cliente "'.$nombre.'" en el fixture de testing.');
        }

        return $cliente;
    }

    /**
     * Provider del fixture por nombre, del usuario del fixture.
     *
     * @param string $nombre
     * @return \App\Models\Provider
     */
    protected function proveedor_del_fixture($nombre)
    {
        $proveedor = Provider::where('user_id', $this->usuario_de_testing()->id)
            ->where('name', $nombre)
            ->first();

        if (is_null($proveedor)) {
            $this->fail('No existe el proveedor "'.$nombre.'" en el fixture de testing.');
        }

        return $proveedor;
    }

    /**
     * Crea una credit_account del usuario del fixture y la registra para el cleanup.
     *
     * @param string $model_name 'client' o 'provider'
     * @param int $model_id
     * @param int $moneda_id 1 = ARS, 2 = USD
     * @param float $saldo
     * @return \App\Models\CreditAccount
     */
    protected function crear_cuenta($model_name, $model_id, $moneda_id, $saldo)
    {
        $cuenta = CreditAccount::create([
            'model_name' => $model_name,
            'model_id'   => $model_id,
            'moneda_id'  => $moneda_id,
            'saldo'      => $saldo,
            'user_id'    => $this->usuario_de_testing()->id,
        ]);

        $this->cuentas_creadas[] = $cuenta->id;

        return $cuenta;
    }

    /**
     * Corre el comando real, el mismo que agenda el Kernel a las 23:59.
     *
     * @return void
     */
    protected function correr_snapshot()
    {
        $this->artisan('debt:snapshot')->assertExitCode(0);
    }

    /**
     * Snapshots de una cuenta, ordenados por fecha.
     *
     * @param int $cuenta_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function snapshots_de($cuenta_id)
    {
        return CreditAccountSnapshot::where('credit_account_id', $cuenta_id)
            ->orderBy('date')
            ->get();
    }

    /**
     * Test 1 — la primera corrida crea fila para las cuentas con saldo distinto de 0 (con
     * los campos denormalizados completos) y NO para las que están en 0 sin historia: una
     * cuenta que nunca se movió no necesita existir en el historial (sin snapshot, se lee 0).
     *
     * @test
     * @return void
     */
    public function la_primera_corrida_crea_filas_solo_para_cuentas_con_saldo()
    {
        Carbon::setTestNow(Carbon::parse(self::DIA_1.' 23:59:00'));

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $proveedor = $this->proveedor_del_fixture(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $cliente_quieto = $this->cliente(TestingFerreteriaSeeder::CLIENTE_EXENTO);

        $cuenta_cliente_ars = $this->crear_cuenta('client', $cliente->id, 1, 1500.50);
        $cuenta_proveedor_usd = $this->crear_cuenta('provider', $proveedor->id, 2, -800);
        $cuenta_en_cero = $this->crear_cuenta('client', $cliente_quieto->id, 1, 0);

        $this->correr_snapshot();

        // La cuenta del cliente en ARS quedó fotografiada, con la denormalización completa.
        $snapshots_cliente = $this->snapshots_de($cuenta_cliente_ars->id);
        $this->assertCount(1, $snapshots_cliente, 'La cuenta con saldo tiene que generar exactamente un snapshot en la primera corrida.');

        $foto = $snapshots_cliente->first();
        $this->assertEquals(self::DIA_1, $foto->date);
        $this->assertEqualsWithDelta(1500.50, (float) $foto->saldo, self::DELTA);
        $this->assertEquals($this->usuario_de_testing()->id, $foto->user_id);
        $this->assertEquals('client', $foto->model_name);
        $this->assertEquals($cliente->id, $foto->model_id);
        $this->assertEquals(1, $foto->moneda_id);

        // La del proveedor en USD también, con su saldo negativo tal cual.
        $snapshots_proveedor = $this->snapshots_de($cuenta_proveedor_usd->id);
        $this->assertCount(1, $snapshots_proveedor);

        $foto_proveedor = $snapshots_proveedor->first();
        $this->assertEqualsWithDelta(-800, (float) $foto_proveedor->saldo, self::DELTA);
        $this->assertEquals('provider', $foto_proveedor->model_name);
        $this->assertEquals($proveedor->id, $foto_proveedor->model_id);
        $this->assertEquals(2, $foto_proveedor->moneda_id);

        // Y la cuenta en 0 sin historia NO existe en el historial. Es la mitad del diseño:
        // sin esta regla, cada corrida escribe todas las cuentas y la tabla explota.
        $this->assertCount(
            0,
            $this->snapshots_de($cuenta_en_cero->id),
            'Una cuenta en 0 y sin snapshots previos no tiene que generar fila.'
        );
    }

    /**
     * Test 2 — correr el comando dos veces el mismo día con el mismo saldo no agrega filas
     * ni revienta por el unique (credit_account_id, date): la segunda pasada ve que el saldo
     * no cambió respecto del último snapshot (el de hoy) y no escribe nada.
     *
     * @test
     * @return void
     */
    public function la_segunda_corrida_del_mismo_dia_con_el_mismo_saldo_no_agrega_filas()
    {
        Carbon::setTestNow(Carbon::parse(self::DIA_1.' 23:59:00'));

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $cuenta = $this->crear_cuenta('client', $cliente->id, 1, 3000);

        $this->correr_snapshot();
        $this->correr_snapshot();

        $snapshots = $this->snapshots_de($cuenta->id);

        $this->assertCount(
            1,
            $snapshots,
            'Dos corridas el mismo día con el mismo saldo tienen que dejar UNA sola fila.'
        );
        $this->assertEquals(self::DIA_1, $snapshots->first()->date);
        $this->assertEqualsWithDelta(3000, (float) $snapshots->first()->saldo, self::DELTA);
    }

    /**
     * Test 3 — el historial completo: un cambio de saldo en otro día agrega snapshot; un
     * segundo cambio el mismo día ACTUALIZA la fila del día (unique respetado); un día sin
     * cambios no escribe nada.
     *
     * @test
     * @return void
     */
    public function un_cambio_de_saldo_agrega_snapshot_y_dentro_del_dia_se_actualiza()
    {
        Carbon::setTestNow(Carbon::parse(self::DIA_1.' 23:59:00'));

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $cuenta = $this->crear_cuenta('client', $cliente->id, 1, 1000);

        $this->correr_snapshot();

        // Día 2: el saldo cambió — snapshot nuevo con la fecha nueva.
        Carbon::setTestNow(Carbon::parse(self::DIA_2.' 23:59:00'));
        $cuenta->saldo = 1800;
        $cuenta->save();

        $this->correr_snapshot();

        $snapshots = $this->snapshots_de($cuenta->id);
        $this->assertCount(2, $snapshots, 'Un cambio de saldo en un día nuevo tiene que agregar exactamente un snapshot.');
        $this->assertEquals(self::DIA_2, $snapshots->last()->date);
        $this->assertEqualsWithDelta(1800, (float) $snapshots->last()->saldo, self::DELTA);

        // Mismo día 2, el saldo vuelve a cambiar: se actualiza la fila del día, no se duplica.
        $cuenta->saldo = 2500;
        $cuenta->save();

        $this->correr_snapshot();

        $snapshots = $this->snapshots_de($cuenta->id);
        $this->assertCount(2, $snapshots, 'Un segundo cambio el mismo día tiene que actualizar la fila del día, no duplicarla.');
        $this->assertEqualsWithDelta(2500, (float) $snapshots->last()->saldo, self::DELTA);

        // Día 3 sin movimientos: no se escribe nada (es lo que mantiene chica la tabla).
        Carbon::setTestNow(Carbon::parse(self::DIA_3.' 23:59:00'));

        $this->correr_snapshot();

        $this->assertCount(
            2,
            $this->snapshots_de($cuenta->id),
            'Un día sin cambios de saldo no tiene que agregar filas al historial.'
        );
    }

    /**
     * Test 4 — la regla de lectura (scopeSaldoAlDia): el saldo al día d es el último
     * snapshot con date <= d, y sin snapshot previo el resultado es null (que el consumidor
     * lee como saldo 0). Es la contracara de "guardar solo cambios": la misión B va a leer
     * el historial únicamente por acá.
     *
     * @test
     * @return void
     */
    public function el_scope_saldo_al_dia_devuelve_el_ultimo_snapshot_hasta_la_fecha()
    {
        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $cuenta = $this->crear_cuenta('client', $cliente->id, 1, 250);

        // Historial armado a mano: cambio el 2030-01-01 (100) y el 2030-01-05 (250).
        foreach ([['2030-01-01', 100], ['2030-01-05', 250]] as $par) {
            CreditAccountSnapshot::create([
                'user_id'           => $this->usuario_de_testing()->id,
                'credit_account_id' => $cuenta->id,
                'date'              => $par[0],
                'saldo'             => $par[1],
                'model_name'        => 'client',
                'model_id'          => $cliente->id,
                'moneda_id'         => 1,
            ]);
        }

        $consulta = CreditAccountSnapshot::where('credit_account_id', $cuenta->id);

        // Un día intermedio sin fila propia hereda el último snapshot anterior.
        $al_03 = (clone $consulta)->saldoAlDia('2030-01-03')->first();
        $this->assertNotNull($al_03);
        $this->assertEquals('2030-01-01', $al_03->date);
        $this->assertEqualsWithDelta(100, (float) $al_03->saldo, self::DELTA);

        // El día exacto del cambio devuelve ese cambio.
        $al_05 = (clone $consulta)->saldoAlDia('2030-01-05')->first();
        $this->assertEqualsWithDelta(250, (float) $al_05->saldo, self::DELTA);

        // Mucho después del último cambio, sigue rigiendo el último snapshot.
        $al_futuro = (clone $consulta)->saldoAlDia('2030-02-20')->first();
        $this->assertEquals('2030-01-05', $al_futuro->date);
        $this->assertEqualsWithDelta(250, (float) $al_futuro->saldo, self::DELTA);

        // Antes del primer snapshot no hay historia: null, que se lee como saldo 0.
        $this->assertNull(
            (clone $consulta)->saldoAlDia('2029-12-31')->first(),
            'Sin snapshots con date <= d, el scope tiene que devolver vacío (saldo 0 por regla de lectura).'
        );
    }

    /**
     * Test 5 — `debt_snapshots` se sigue poblando exactamente igual que antes: una sola fila
     * por (usuario, día) y los totales agregados reflejan los saldos vigentes, incluidas las
     * cuentas nuevas del test. La parte por entidad corre DESPUÉS de process_user y no le
     * cambia nada al agregado.
     *
     * @test
     * @return void
     */
    public function debt_snapshots_se_sigue_poblando_igual_que_antes()
    {
        Carbon::setTestNow(Carbon::parse(self::DIA_1.' 23:59:00'));

        $user_id = $this->usuario_de_testing()->id;

        // Línea de base ANTES de sembrar las cuentas del test (la base del slot puede traer
        // credit_accounts propias del fixture): lo que se asevera es el desplazamiento exacto
        // que producen las cuentas nuevas, no un total global que no es de nadie.
        $base_clientes_ars = (float) CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'client')
            ->where('moneda_id', 1)
            ->sum('saldo');

        $base_proveedores_usd = (float) CreditAccount::where('user_id', $user_id)
            ->where('model_name', 'provider')
            ->where('moneda_id', 2)
            ->sum('saldo');

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $proveedor = $this->proveedor_del_fixture(TestingFerreteriaSeeder::PROVIDER_BSAS);

        $this->crear_cuenta('client', $cliente->id, 1, 1500.50);
        $this->crear_cuenta('provider', $proveedor->id, 2, -800);

        $this->correr_snapshot();

        $filas = DebtSnapshot::where('user_id', $user_id)
            ->where('date', self::DIA_1)
            ->get();

        $this->assertCount(1, $filas, 'Tiene que haber exactamente un debt_snapshot por usuario y día, como siempre.');

        $agregado = $filas->first();
        $this->assertEqualsWithDelta($base_clientes_ars + 1500.50, (float) $agregado->deuda_clientes, self::DELTA);
        $this->assertEqualsWithDelta($base_proveedores_usd - 800, (float) $agregado->deuda_proveedores_usd, self::DELTA);
    }
}
