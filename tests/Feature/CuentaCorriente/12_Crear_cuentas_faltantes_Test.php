<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión importacion-proveedores-saldo-inicial (8/10/2026) — `cuenta_corriente:crear_cuentas_faltantes`.
 *
 * La reparación de lo ya importado (decisión de Lucas): el comando le crea las cuentas corrientes
 * (pesos y dólares) a todo proveedor que no las tenga —los que dejaron la importación de proveedores
 * y la de artículos antes del arreglo—, sin tocar movimientos ni saldos. El monto perdido no está en
 * la base: vuelve reimportando el Excel. Va en el despliegue, así que sale SIEMPRE con exit 0.
 *
 * Se mide el estado de la base, no la salida sola: sin `--aplicar` no se escribe nada, con
 * `--aplicar` se crean las dos, la segunda corrida no encuentra nada, el `user_id` acota al comercio
 * y uno inválido no recorre la base entera.
 *
 * @group cuenta-corriente
 * @group importacion-proveedores-saldo-inicial
 */
class Crear_cuentas_faltantes_Test extends EmpresaTestCase
{
    /** @var int Dueño de la sesión (el usuario del fixture). */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = $this->app['auth']->user()->id;
    }

    /**
     * Un proveedor sin cuentas, como lo dejaba la importación vieja (`Provider::create()` pelado).
     *
     * @param  int    $user_id
     * @param  string $nombre
     * @param  bool   $borrado  Soft delete: un proveedor restaurado no puede volver sin cuenta.
     * @return \App\Models\Provider
     */
    protected function proveedor_sin_cuenta($user_id, $nombre, $borrado = false)
    {
        $proveedor = Provider::create([
            'num'     => (int) Provider::withTrashed()->where('user_id', $user_id)->max('num') + 1,
            'name'    => 'zz '.$nombre.' '.uniqid(),
            'user_id' => $user_id,
        ]);

        if ($borrado) {
            $proveedor->delete();
        }

        return $proveedor;
    }

    /**
     * Otro comercio de la misma base (en el shared hay bases con decenas). Lo revierte la
     * transacción del test.
     *
     * @return int
     */
    protected function otro_duenio()
    {
        $usuario = User::create([
            'name'     => 'Otro comercio cuentas faltantes',
            'email'    => 'cuentas-faltantes-otro-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => null,
        ]);

        return $usuario->id;
    }

    /**
     * Las cuentas de un proveedor, por moneda, como [moneda_id => [id, saldo, user_id]].
     *
     * @param  int $provider_id
     * @return array
     */
    protected function cuentas_de($provider_id)
    {
        $cuentas = [];

        foreach (CreditAccount::where('model_name', 'provider')->where('model_id', $provider_id)->orderBy('id')->get() as $cuenta) {
            $cuentas[] = [
                'id'        => (int) $cuenta->id,
                'moneda_id' => (int) $cuenta->moneda_id,
                'saldo'     => (float) $cuenta->saldo,
                'user_id'   => (int) $cuenta->user_id,
            ];
        }

        return $cuentas;
    }

    /**
     * Corre el comando y devuelve [exit, salida].
     *
     * @param  array $parametros
     * @return array
     */
    protected function correr(array $parametros)
    {
        $exit = Artisan::call('cuenta_corriente:crear_cuentas_faltantes', $parametros);

        return [$exit, Artisan::output()];
    }

    /**
     * Sin `--aplicar` lista y no escribe; con `--aplicar` crea las dos cuentas con el user_id del
     * proveedor y en cero; la segunda corrida no encuentra nada. Exit 0 en las tres.
     *
     * @test
     */
    public function sin_aplicar_lista_con_aplicar_crea_las_dos_y_la_segunda_corrida_no_hace_nada()
    {
        $proveedor = $this->proveedor_sin_cuenta($this->user_id, 'Prov viejo sin cuenta');

        list($exit, $salida) = $this->correr(['user_id' => $this->user_id]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Proveedor '.$proveedor->id.' ', $salida, 'El listado no nombra al proveedor sin cuenta.');
        $this->assertStringContainsString('Sin cuenta: 1.', $salida);
        $this->assertStringContainsString('no se escribió nada', $salida);
        $this->assertSame([], $this->cuentas_de($proveedor->id), 'Sin --aplicar no se crea nada.');

        list($exit, $salida) = $this->correr(['user_id' => $this->user_id, '--aplicar' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Creados: 1.', $salida);

        $cuentas = $this->cuentas_de($proveedor->id);

        $this->assertCount(2, $cuentas, 'Con --aplicar el proveedor tiene que quedar con sus dos cuentas.');
        $this->assertEquals([1, 2], array_column($cuentas, 'moneda_id'));

        foreach ($cuentas as $cuenta) {
            $this->assertSame($this->user_id, $cuenta['user_id'], 'La cuenta tiene que ser del comercio del proveedor.');
            $this->assertEqualsWithDelta(0, $cuenta['saldo'], 0.001);
        }

        list($exit, $salida) = $this->correr(['user_id' => $this->user_id, '--aplicar' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Sin cuenta: 0.', $salida, 'La segunda corrida no encuentra nada.');
        $this->assertEquals($cuentas, $this->cuentas_de($proveedor->id), 'La segunda corrida tocó las cuentas.');
    }

    /**
     * Un proveedor que ya tenía sus cuentas (con saldo) no cambia; uno al que le falta solo una
     * moneda recibe esa sola; y uno borrado (soft delete) también recibe las suyas.
     *
     * @test
     */
    public function no_toca_las_cuentas_que_existen_completa_la_moneda_que_falta_e_incluye_los_borrados()
    {
        $completo = $this->proveedor_sin_cuenta($this->user_id, 'Prov con sus cuentas');
        CreditAccountHelper::crear_credit_accounts('provider', $completo->id, $this->user_id);
        CreditAccount::where('model_name', 'provider')->where('model_id', $completo->id)->where('moneda_id', 1)->update(['saldo' => 1234.5]);
        $antes_completo = $this->cuentas_de($completo->id);

        $solo_pesos = $this->proveedor_sin_cuenta($this->user_id, 'Prov solo pesos');
        CreditAccount::create([
            'moneda_id'  => 1,
            'model_name' => 'provider',
            'model_id'   => $solo_pesos->id,
            'saldo'      => 500,
            'user_id'    => $this->user_id,
        ]);
        $cuenta_pesos = $this->cuentas_de($solo_pesos->id)[0];

        $borrado = $this->proveedor_sin_cuenta($this->user_id, 'Prov borrado', true);

        list($exit, $salida) = $this->correr(['user_id' => $this->user_id, '--aplicar' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Creados: 2.', $salida);

        $this->assertEquals($antes_completo, $this->cuentas_de($completo->id), 'Las cuentas de un proveedor que ya las tenía cambiaron.');

        $despues_solo_pesos = $this->cuentas_de($solo_pesos->id);
        $this->assertCount(2, $despues_solo_pesos);
        $this->assertEquals($cuenta_pesos, $despues_solo_pesos[0], 'La cuenta en pesos que ya existía cambió.');
        $this->assertSame(2, $despues_solo_pesos[1]['moneda_id']);

        $this->assertCount(2, $this->cuentas_de($borrado->id), 'Un proveedor borrado también tiene que recibir sus cuentas.');
    }

    /**
     * Con `user_id`, los proveedores de otro comercio de la misma base no se tocan.
     *
     * @test
     */
    public function con_user_id_no_toca_los_proveedores_de_otro_comercio()
    {
        $propio = $this->proveedor_sin_cuenta($this->user_id, 'Prov propio');
        $ajeno  = $this->proveedor_sin_cuenta($this->otro_duenio(), 'Prov ajeno');

        list($exit, $salida) = $this->correr(['user_id' => $this->user_id, '--aplicar' => true]);

        $this->assertSame(0, $exit);
        $this->assertCount(2, $this->cuentas_de($propio->id));
        $this->assertSame([], $this->cuentas_de($ajeno->id), 'El comando tocó un proveedor de otro comercio.');
        $this->assertStringNotContainsString('Proveedor '.$ajeno->id.' ', $salida);
    }

    /**
     * Un `user_id` inválido no hace nada (no puede terminar recorriendo la base entera) y sale 0.
     *
     * @test
     */
    public function un_user_id_invalido_no_hace_nada_y_sale_con_cero()
    {
        $proveedor = $this->proveedor_sin_cuenta($this->user_id, 'Prov user_id invalido');

        list($exit, $salida) = $this->correr(['user_id' => 'abc', '--aplicar' => true]);

        $this->assertSame(0, $exit, 'El comando va en el despliegue: tiene que salir siempre con 0.');
        $this->assertStringContainsString('user_id inválido', $salida);
        $this->assertSame([], $this->cuentas_de($proveedor->id), 'Con un user_id inválido se crearon cuentas.');
    }

    /**
     * Desde la consola, SIN sesión (como corre en el despliegue): las cuentas salen con el user_id
     * del DUEÑO DEL PROVEEDOR, no con `config('app.USER_ID')` (que es lo que daría
     * UserHelper::userId() sin sesión; en testing es 500) ni con el `user_id` del argumento. Con un
     * proveedor de otro comercio se ven las tres cosas, sin user_id y con user_id.
     *
     * @test
     */
    public function sin_sesion_las_cuentas_salen_con_el_user_id_del_duenio_del_proveedor()
    {
        $otro = $this->otro_duenio();

        $this->assertNotEquals($otro, (int) config('app.USER_ID'), 'Premisa: el otro comercio no es el de USER_ID.');

        $sin_argumento = $this->proveedor_sin_cuenta($otro, 'Prov ajeno sin argumento');

        // Sin sesión: EmpresaTestCase deja logueado al usuario 500, y la consola no tiene a nadie.
        $this->app['auth']->forgetGuards();

        list($exit, $salida) = $this->correr(['--aplicar' => true]);

        $this->assertSame(0, $exit);

        $cuentas = $this->cuentas_de($sin_argumento->id);

        $this->assertCount(2, $cuentas, 'Sin user_id el comando recorre todos los comercios: el proveedor ajeno tiene que recibir sus cuentas.');

        foreach ($cuentas as $cuenta) {
            $this->assertSame($otro, $cuenta['user_id'], 'Sin user_id, la cuenta salió con el user_id de otro comercio.');
        }

        $con_argumento = $this->proveedor_sin_cuenta($otro, 'Prov ajeno con argumento');

        list($exit, $salida) = $this->correr(['user_id' => $otro, '--aplicar' => true]);

        $this->assertSame(0, $exit);

        $cuentas = $this->cuentas_de($con_argumento->id);

        $this->assertCount(2, $cuentas);

        foreach ($cuentas as $cuenta) {
            $this->assertSame($otro, $cuenta['user_id'], 'Con user_id, la cuenta salió con el user_id de otro comercio.');
        }
    }

    /**
     * Las cuentas de un CLIENTE con el mismo id que el proveedor no cuentan como del proveedor:
     * `credit_accounts` es de clientes y proveedores, y los ids de las dos tablas se pisan. El
     * comando filtra por `model_name = 'provider'`.
     *
     * @test
     */
    public function las_cuentas_de_un_cliente_con_el_mismo_id_no_cuentan_como_del_proveedor()
    {
        $proveedor = $this->proveedor_sin_cuenta($this->user_id, 'Prov con cliente del mismo id');

        $cliente = $this->cliente_con_el_mismo_id($proveedor->id, $this->user_id);

        $this->assertEquals(
            2,
            CreditAccount::where('model_name', 'client')->where('model_id', $proveedor->id)->count(),
            'Premisa: el cliente con el mismo id que el proveedor tiene sus dos cuentas.'
        );

        list($exit, $salida) = $this->correr(['user_id' => $this->user_id, '--aplicar' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Proveedor '.$proveedor->id.' ', $salida, 'El comando tomó las cuentas del cliente como del proveedor.');
        $this->assertCount(2, $this->cuentas_de($proveedor->id), 'El proveedor quedó sin sus cuentas porque había cuentas de un cliente con su mismo id.');
        $this->assertEquals(2, CreditAccount::where('model_name', 'client')->where('model_id', $cliente->id)->count(), 'El comando tocó las cuentas del cliente.');
    }

    /**
     * Un cliente del comercio con el id dado y sus dos cuentas. Si ya existe un cliente con ese id
     * (los ids de clientes y proveedores son independientes), se usa ese.
     *
     * @param  int $id
     * @param  int $user_id
     * @return \App\Models\Client
     */
    protected function cliente_con_el_mismo_id($id, $user_id)
    {
        $cliente = Client::find($id);

        if (is_null($cliente)) {
            $cliente = Client::create([
                'id'      => $id,
                'num'     => (int) Client::where('user_id', $user_id)->max('num') + 1,
                'name'    => 'zz Cliente con el id del proveedor '.uniqid(),
                'user_id' => $user_id,
            ]);
        }

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $cliente->user_id);

        return $cliente;
    }
}
