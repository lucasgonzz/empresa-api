<?php

namespace Tests\Feature\Cheques;

use App\Http\Controllers\ChequeBancoController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\EtiquetaMedida;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Misión cheques-filtro-por-dueno (3/10/2026) — la TENENCIA de los ids que llegan a los
 * controllers de cheques (ChequeController y ChequeBancoController).
 *
 * La clase de defecto es una sola: un id que llega en el pedido (en la ruta o en el cuerpo) se
 * resolvía sin cruzarlo con el dueño de la sesión. En una base compartida (`u767360347_empresa`
 * tiene 51 comercios adentro) los ids son correlativos entre comercios, así que el banco, el
 * cheque, la caja o el proveedor de otro comercio están a un "+1" de distancia. Hasta esta misión:
 *
 * - `GET cheque-banco/{id}` devolvía el banco de cualquier comercio (y un 200 con `model: null`
 *   para un id que no existe);
 * - `PUT cheque/cobrar|pagar|rechazar` marcaban el cheque de cualquier comercio, y cobrar/pagar
 *   metían la plata en la caja que dijera el cuerpo, fuera de quien fuera;
 * - `DELETE cheque/{id}` borraba el cheque de cualquier comercio (y con un id inexistente
 *   reventaba en 500);
 * - `PUT cheque/endosar` registraba el pago en la cuenta corriente de un proveedor ajeno;
 * - un cheque nuevo (o la copia de un endoso) quedaba atado al banco del catálogo de otro comercio.
 *
 * El mecanismo que lo cierra: todo id del pedido se resuelve por un resolvedor que filtra por
 * dueño ANTES de escribir nada, y un id ajeno se contesta EXACTAMENTE igual que uno inexistente
 * (404 si vino en la ruta, 422 con un mensaje de comerciante si vino en el cuerpo). El último test
 * de este archivo es el que lo sostiene en el tiempo: toda ruta de esos dos controllers tiene que
 * estar declarada en una matriz con cómo resuelve la tenencia.
 *
 * Lo AJENO se crea a mano con el `user_id` de otro dueño (es la combinación real: en una base
 * compartida lo del otro comercio existe con su `user_id`); lo PROPIO, por los endpoints reales.
 *
 * Los casos 1 a 5 se escribieron ANTES del arreglo y se corrieron contra develop: dieron rojo.
 *
 * La segunda vuelta (lo que encontraron los verificadores) suma la misma clase por otras puertas:
 * los `*_id` que ChequeHelper lee de la FILA de un pago o de un gasto (con un test-mecanismo que
 * pone un centinela en cada uno), el banco viejo que el endoso propagaba, el destino del endoso
 * por la fila de pago, una sola lectura estricta de ids y los 404 con mensaje de comerciante. Sus
 * casos de los puntos 1 a 4 también se escribieron antes del arreglo y dieron rojo.
 *
 * @group cheques
 */
class Tenencia_de_cheques_Test extends ChequesTestCase
{
    /** El 422 de un `cheque_id` del cuerpo que no es de esta cuenta (cobrar, pagar, rechazar). */
    const MENSAJE_CHEQUE = 'El cheque elegido no existe o no es de tu cuenta.';

    /** El 422 de un `caja_id` del cuerpo que no es de esta cuenta (cobrar, pagar). */
    const MENSAJE_CAJA = 'La caja elegida no existe o no es de tu cuenta.';

    /** El 422 de un `provider_id` del cuerpo que no es de esta cuenta (endosar). */
    const MENSAJE_PROVEEDOR = 'El proveedor elegido no existe o no es de tu cuenta.';

    /**
     * Saldo con el que nace la caja del otro comercio. Distinto de cero a propósito: con 0, un
     * `(float) null` compararía igual y "el saldo no cambió" pasaría aunque se hubiera pisado.
     */
    const SALDO_CAJA_AJENA = 50000;

    /** Marca de la matriz de tenencia para las rutas de Route::resource sin método en el controller. */
    const METODO_INEXISTENTE = 'NO EXISTE EL MÉTODO: la ruta la registra Route::resource y no se llama';

    /** La excepción del endoso cuando el gasto destino no es de esta cuenta (ChequeHelper::endosar()). */
    const MENSAJE_GASTO_AJENO = 'El gasto en el que se endosa no es de tu cuenta.';

    /**
     * Un id que no existe en ninguna tabla, para el test-mecanismo de los `*_id` de la fila. Entra en
     * un `int` con signo (las columnas `*_id` de `cheques` lo son) y está muy lejos de cualquier
     * autoincremental de una base de testing.
     */
    const CENTINELA = 2000000001;

    /** @var User|null El otro comercio: un dueño (sin owner_id) que vive en la misma base. */
    protected $otro_dueno = null;

    /** @var array<int, int> Usuarios creados a mano por este test. */
    protected $usuarios_creados = [];

    /** @var array<int, int> Cajas creadas a mano por este test (con su apertura). */
    protected $cajas_creadas = [];

    /** @var array<int, int> Proveedores creados a mano por este test (con sus cuentas corrientes). */
    protected $proveedores_creados = [];

    /** @var array<int, int> Clientes creados a mano por este test. */
    protected $clientes_creados = [];

    /** @var array<int, int> Gastos creados a mano por este test (los de otro comercio). */
    protected $gastos_creados_a_mano = [];

    /** @var int Marca de agua de `movimiento_cajas`, para borrar en tearDown solo lo de este test. */
    protected $max_movimiento_caja_antes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->max_movimiento_caja_antes = $this->max_id_movimiento_caja();
    }

    protected function tearDown(): void
    {
        // El guard de sanctum cachea el usuario que resolvió: se olvida para no arrastrar al otro
        // dueño o al empleado a lo que corra después.
        Auth::forgetGuards();

        // Cinturón y tiradores sobre el rollback de DatabaseTransactions, con el criterio de
        // ChequesTestCase (los cheques y los bancos nuevos ya los borra su tearDown por marca de agua).
        MovimientoCaja::where('id', '>', $this->max_movimiento_caja_antes)->delete();

        if (count($this->cajas_creadas)) {
            AperturaCaja::whereIn('caja_id', $this->cajas_creadas)->delete();
            Caja::whereIn('id', $this->cajas_creadas)->delete();
        }

        if (count($this->proveedores_creados)) {
            $cuentas = CreditAccount::where('model_name', 'provider')
                                    ->whereIn('model_id', $this->proveedores_creados)
                                    ->pluck('id');

            CurrentAcount::whereIn('credit_account_id', $cuentas)->delete();
            CreditAccount::whereIn('id', $cuentas)->delete();
            Provider::withTrashed()->whereIn('id', $this->proveedores_creados)->forceDelete();
        }

        if (count($this->clientes_creados)) {
            Client::withTrashed()->whereIn('id', $this->clientes_creados)->forceDelete();
        }

        if (count($this->gastos_creados_a_mano)) {
            Expense::whereIn('id', $this->gastos_creados_a_mano)->delete();
        }

        if (count($this->usuarios_creados)) {
            // El alta de un dueño siembra sus medidas de etiqueta (UserEtiquetaMedidaObserver).
            EtiquetaMedida::whereIn('user_id', $this->usuarios_creados)->delete();
            User::whereIn('id', $this->usuarios_creados)->delete();
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Los casos
    // ---------------------------------------------------------------------------------------------

    /**
     * Pedido 3 de Lucas: un dueño no ve el banco de otro. `GET cheque-banco/{id}` resolvía con
     * fullModel() sin mirar el dueño, mientras update() y destroy() ya iban por banco_del_dueno().
     *
     * @test
     */
    public function un_dueno_no_ve_el_banco_de_otro()
    {
        $nombre_ajeno = 'Banco ajeno tenencia ' . uniqid();
        $ajeno = $this->banco_ajeno($nombre_ajeno);

        // Lo propio, por el endpoint real.
        $response = $this->postJson('api/cheque-banco', ['name' => 'Banco propio tenencia']);

        $response->assertStatus(201);
        $propio_id = (int) $response->json('model.id');

        // El banco de otro comercio: 404, y la respuesta no cuenta nada de él.
        $response = $this->getJson('api/cheque-banco/' . $ajeno->id);

        $this->assertSame(404, $response->getStatusCode(), 'El banco de otro comercio: ' . $this->resumen($response));
        $this->assertStringNotContainsString($nombre_ajeno, $response->getContent(), 'El 404 no puede devolver el nombre del banco ajeno.');

        // Uno que no existe: el MISMO 404 (hasta esta misión era un 200 con `model: null`).
        $response = $this->getJson('api/cheque-banco/' . $this->id_que_no_existe('cheque_bancos'));

        $this->assertSame(404, $response->getStatusCode(), 'Un banco que no existe: ' . $this->resumen($response));

        // El propio: 200, con el cuerpo de siempre.
        $this->getJson('api/cheque-banco/' . $propio_id)
             ->assertStatus(200)
             ->assertJsonPath('model.id', $propio_id)
             ->assertJsonPath('model.name', 'Banco propio tenencia')
             ->assertJsonPath('model.user_id', $this->dueno->id);

        // Un empleado del dueño ve el banco de SU dueño (la tenencia es por cuenta, no por
        // persona) y tampoco ve el ajeno.
        $empleado = $this->crear_usuario('Empleado tenencia', 'cheques-tenencia-empleado-', $this->dueno->id);

        $this->actuar_como($empleado);

        $this->getJson('api/cheque-banco/' . $propio_id)
             ->assertStatus(200)
             ->assertJsonPath('model.name', 'Banco propio tenencia');

        $this->assertSame(404, $this->getJson('api/cheque-banco/' . $ajeno->id)->getStatusCode(), 'El empleado tampoco ve el banco ajeno.');

        // Del otro lado del mostrador: el otro dueño ve SU banco (el 404 de arriba es tenencia, no
        // un show roto) y no ve el nuestro.
        $this->actuar_como($this->otro_dueno());

        $this->getJson('api/cheque-banco/' . $ajeno->id)
             ->assertStatus(200)
             ->assertJsonPath('model.name', $nombre_ajeno);

        $response = $this->getJson('api/cheque-banco/' . $propio_id);

        $this->assertSame(404, $response->getStatusCode(), 'El otro dueño pidiendo nuestro banco: ' . $this->resumen($response));
        $this->assertStringNotContainsString('Banco propio tenencia', $response->getContent());

        $this->actuar_como($this->dueno);
    }

    /**
     * Cobrar, pagar, rechazar y borrar resolvían el cheque con un `Cheque::find()` pelado sobre lo
     * que mandaba el pedido: marcaban (o borraban) el cheque de cualquier comercio. Y los ids que no
     * son un id también entraban: `Cheque::find(true)` es el cheque 1 y `find('12abc')` es el 12.
     *
     * @test
     */
    public function cobrar_pagar_rechazar_y_borrar_un_cheque_ajeno_no_lo_toca()
    {
        // Un cheque de OTRO comercio, insertado a mano (ningún endpoint crea cheques ajenos).
        $ajeno = $this->cheque_a_mano([
            'user_id' => $this->otro_dueno()->id,
            'numero'  => 'AJENO-' . substr(uniqid(), -6),
            'banco'   => 'Banco del otro comercio',
        ]);

        $foto_ajeno = $ajeno->fresh()->toArray();

        // Una caja PROPIA y con apertura: si el cheque ajeno pasara, el cobro movería esta caja.
        $caja = $this->caja_propia_con_apertura();

        $movimientos_antes = $this->max_id_movimiento_caja();

        foreach ($this->pedidos_sobre_un_cheque($ajeno->id, $caja->id) as $accion => $cuerpo) {

            $response = $this->putJson('api/cheque/' . $accion, $cuerpo);

            $this->assertSame(422, $response->getStatusCode(), $accion . ' con el cheque de otro comercio: ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_CHEQUE, $response->json('message'), $accion);
            $this->assertStringNotContainsString($ajeno->numero, $response->getContent(), $accion . ': la respuesta no puede devolver datos del cheque ajeno.');
        }

        // Borrarlo: 404, el mismo que un id que no existe (que hasta esta misión era un 500).
        $response = $this->deleteJson('api/cheque/' . $ajeno->id);

        $this->assertSame(404, $response->getStatusCode(), 'DELETE del cheque de otro comercio: ' . $this->resumen($response));

        $response = $this->deleteJson('api/cheque/' . $this->id_que_no_existe('cheques'));

        $this->assertSame(404, $response->getStatusCode(), 'DELETE de un cheque que no existe: ' . $this->resumen($response));

        $this->assertEquals($foto_ajeno, $ajeno->fresh()->toArray(), 'El cheque de otro comercio tenía que quedar idéntico.');
        $this->assertSame(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Ningún pedido sobre el cheque ajeno puede mover una caja.');
        $this->assertNull($this->cheque_del_listado($ajeno->id), 'GET cheque no lista el cheque de otro comercio.');

        // Ids que no son un id: "sin cheque", con el mismo 422 y sin escribir nada. Nunca el 12 de
        // '12abc' (que acá es un cheque PROPIO) ni el 1 de `true`.
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente tenencia ids basura ' . uniqid());

        $propio = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => 'PROPIO-' . substr(uniqid(), -6)]);

        $foto_propio = $propio->fresh()->toArray();
        $foto_de_la_tabla = $this->foto_de_cheques();

        $basuras = [
            'un id con letras' => $propio->id . 'abc',
            'true'             => true,
            'un array'         => [],
            'un negativo'      => -1,
        ];

        foreach ($basuras as $nombre => $basura) {

            foreach ($this->pedidos_sobre_un_cheque($basura, $caja->id) as $accion => $cuerpo) {

                $response = $this->putJson('api/cheque/' . $accion, $cuerpo);

                $this->assertSame(422, $response->getStatusCode(), $accion . ' con cheque_id ' . $nombre . ': ' . $this->resumen($response));
                $this->assertSame(self::MENSAJE_CHEQUE, $response->json('message'), $accion . ' con cheque_id ' . $nombre);
            }
        }

        // Por la ruta, lo mismo: '{id}abc' y un negativo son un 404, y el propio no se borra.
        $response = $this->deleteJson('api/cheque/' . $propio->id . 'abc');

        $this->assertSame(404, $response->getStatusCode(), 'DELETE cheque/' . $propio->id . 'abc: ' . $this->resumen($response));

        $response = $this->deleteJson('api/cheque/-1');

        $this->assertSame(404, $response->getStatusCode(), 'DELETE cheque/-1: ' . $this->resumen($response));

        $this->assertEquals($foto_propio, $propio->fresh()->toArray(), 'El cheque propio no se tocó: "' . $propio->id . 'abc" no es el ' . $propio->id . '.');
        $this->assertEquals($foto_de_la_tabla, $this->foto_de_cheques(), 'Ningún cheque de la base cambió con los ids basura.');
        $this->assertSame(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Los ids basura no movieron ninguna caja.');

        // Y lo propio sigue andando: rechazar el cheque propio, con el cuerpo que manda la SPA, es
        // un 200 con el cheque rechazado. (El motivo no se mira: la SPA lo manda como `notas`, la API
        // lee `rechazado_observaciones` y esa columna es un entero. Es un defecto aparte, no de
        // tenencia, y queda en los hallazgos de la misión.)
        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $propio->id, 'notas' => 'Sin fondos']);

        $this->assertSame(200, $response->getStatusCode(), 'rechazar un cheque propio: ' . $this->resumen($response));
        $this->assertSame($propio->id, $response->json('model.id'));
        $this->assertSame('rechazado', $response->json('model.estado_manual'));
        $this->assertSame('rechazado', $propio->fresh()->estado_manual);
        $this->assertSame($this->dueno->id, (int) $propio->fresh()->rechazado_por_id);
    }

    /**
     * El `caja_id` de cobrar y pagar iba derecho a CurrentAcountCajaHelper::guardar_pago(), que no
     * mira de quién es la caja: el cobro de un cheque PROPIO entraba como ingreso en la caja de otro
     * comercio y le movía el saldo. Y el cheque se marcaba ANTES de mirar la caja.
     *
     * @test
     */
    public function un_cheque_propio_no_se_cobra_en_la_caja_de_otro_dueno()
    {
        $caja_ajena = $this->caja_ajena_con_apertura();
        $caja_propia = $this->caja_propia_con_apertura();

        $foto_caja_ajena = $this->foto_de_caja($caja_ajena);

        // Un recibido propio para cobrar y un emitido propio para pagar, por los endpoints reales.
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente caja ajena ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor caja ajena ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'REC-' . substr(uniqid(), -6)]);
        $emitido = $this->pagar_con_cheque_nuevo($proveedor, $cuenta_proveedor, ['numero' => 'EMI-' . substr(uniqid(), -6)]);

        $cheque_de = [
            'cobrar' => $recibido,
            'pagar'  => $emitido,
        ];

        $movimientos_antes = $this->max_id_movimiento_caja();

        foreach ($cheque_de as $accion => $cheque) {

            $foto = $cheque->fresh()->toArray();

            $response = $this->putJson('api/cheque/' . $accion, ['cheque_id' => $cheque->id, 'caja_id' => $caja_ajena->id]);

            $this->assertSame(422, $response->getStatusCode(), $accion . ' en la caja de otro comercio: ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_CAJA, $response->json('message'), $accion);
            $this->assertStringNotContainsString($caja_ajena->name, $response->getContent(), $accion . ': la respuesta no puede nombrar la caja ajena.');
            $this->assertEquals($foto, $cheque->fresh()->toArray(), $accion . ': el cheque propio sigue sin marcar (la caja se valida ANTES de escribir).');
        }

        $this->assertEquals($foto_caja_ajena, $this->foto_de_caja($caja_ajena), 'La caja ajena no tiene movimientos nuevos ni cambió su saldo.');

        // Una caja que no existe, o un `caja_id` que no es un id: el mismo 422, sin escribir nada.
        $basuras = [
            'una caja que no existe' => $this->id_que_no_existe('cajas'),
            'un id con letras'       => $caja_propia->id . 'abc',
            'texto'                  => 'abc',
            'true'                   => true,
            'un array'               => [],
            'un negativo'            => -1,
        ];

        foreach ($basuras as $nombre => $basura) {

            foreach ($cheque_de as $accion => $cheque) {

                $foto = $cheque->fresh()->toArray();

                $response = $this->putJson('api/cheque/' . $accion, ['cheque_id' => $cheque->id, 'caja_id' => $basura]);

                $this->assertSame(422, $response->getStatusCode(), $accion . ' con caja_id ' . $nombre . ': ' . $this->resumen($response));
                $this->assertSame(self::MENSAJE_CAJA, $response->json('message'), $accion . ' con caja_id ' . $nombre);
                $this->assertEquals($foto, $cheque->fresh()->toArray(), $accion . ' con caja_id ' . $nombre . ': el cheque no se marcó.');
            }
        }

        $this->assertSame(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Ninguna caja se movió.');
        $this->assertEquals($foto_caja_ajena, $this->foto_de_caja($caja_ajena));

        // --- Lo propio sigue andando, con la respuesta de siempre ---------------------------------

        // Sin caja (0, null y '' son "sin caja", como siempre): 200, el cheque cobrado y ninguna
        // caja movida.
        foreach (['0' => 0, 'null' => null, "''" => ''] as $nombre => $sin_caja) {

            $cheque = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'SINCAJA-' . substr(uniqid(), -6)]);

            $response = $this->putJson('api/cheque/cobrar', ['cheque_id' => $cheque->id, 'caja_id' => $sin_caja]);

            $this->assertSame(200, $response->getStatusCode(), 'cobrar con caja_id ' . $nombre . ': ' . $this->resumen($response));
            $this->assertSame($cheque->id, $response->json('model.id'));
            $this->assertSame('cobrado', $response->json('model.estado_manual'));
            $this->assertSame('cobrado', $cheque->fresh()->estado_manual);
        }

        $this->assertSame(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Sin caja, ninguna caja se mueve.');

        // Con una caja propia: 200, y el movimiento entra en ESA caja (el id como número y como
        // texto, que es como lo puede mandar un formulario).
        $response = $this->putJson('api/cheque/cobrar', ['cheque_id' => $recibido->id, 'caja_id' => $caja_propia->id]);

        $this->assertSame(200, $response->getStatusCode(), 'cobrar en una caja propia: ' . $this->resumen($response));
        $this->assertSame('cobrado', $response->json('model.estado_manual'));
        $this->assertSame($this->dueno->id, (int) $response->json('model.cobrado_por_id'));

        $response = $this->putJson('api/cheque/pagar', ['cheque_id' => $emitido->id, 'caja_id' => (string) $caja_propia->id]);

        $this->assertSame(200, $response->getStatusCode(), 'pagar desde una caja propia: ' . $this->resumen($response));
        $this->assertSame('cobrado', $response->json('model.estado_manual'));

        $nuevos = MovimientoCaja::where('id', '>', $movimientos_antes)->orderBy('id')->get();

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $this->assertCount(2, $nuevos, 'Un movimiento por el cobro y uno por el pago.');
        $this->assertSame($caja_propia->id, (int) $nuevos[0]->caja_id);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $nuevos[0]->ingreso, self::DELTA);
        $this->assertSame($caja_propia->id, (int) $nuevos[1]->caja_id);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $nuevos[1]->egreso, self::DELTA);

        $this->assertEquals($foto_caja_ajena, $this->foto_de_caja($caja_ajena), 'La caja ajena sigue intacta al final.');
    }

    /**
     * El `provider_id` de `PUT cheque/endosar` no se cruzaba con el dueño: get_provider_credit_account()
     * busca la cuenta del proveedor sin dueño y CurrentAcountPagoAltaHelper::registrar() no lo
     * verifica, así que un endoso propio registraba un pago en la cuenta corriente del proveedor de
     * otro comercio y le recalculaba el saldo.
     *
     * @test
     */
    public function no_se_endosa_a_un_proveedor_de_otro_dueno()
    {
        list($proveedor_ajeno, $cuenta_ajena) = $this->proveedor_ajeno_con_cuenta();

        $this->assertNotNull($cuenta_ajena, 'El proveedor ajeno tiene que tener cuenta corriente: sin ella el endoso cortaría por otro motivo y el test no mediría la tenencia.');

        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente endoso ajeno ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'END-' . substr(uniqid(), -6)]);

        $foto = $recibido->fresh()->toArray();
        $cheques_antes = Cheque::count();
        $foto_cuenta_ajena = $this->foto_de_cuenta($cuenta_ajena);

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor_ajeno->id]);

        $this->assertSame(422, $response->getStatusCode(), 'Endosar a un proveedor de otro comercio: ' . $this->resumen($response));
        $this->assertSame(self::MENSAJE_PROVEEDOR, $response->json('message'));
        $this->assertStringNotContainsString($proveedor_ajeno->name, $response->getContent());

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar(), 'El cheque se sigue ofreciendo para endosar.');
        $this->assertCount(0, $this->copias_de($recibido), 'No nació ninguna copia emitida.');
        $this->assertSame($cheques_antes, Cheque::count());
        $this->assertEquals($foto_cuenta_ajena, $this->foto_de_cuenta($cuenta_ajena), 'La cuenta corriente del proveedor ajeno no tiene pagos nuevos y su saldo no cambió.');
        $this->assertSame(0, CurrentAcount::where('provider_id', $proveedor_ajeno->id)->count());

        // Un proveedor que no existe: el MISMO 422 (no se confirma qué hay del otro lado).
        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $this->id_que_no_existe('providers')]);

        $this->assertSame(422, $response->getStatusCode(), 'Endosar a un proveedor que no existe: ' . $this->resumen($response));
        $this->assertSame(self::MENSAJE_PROVEEDOR, $response->json('message'));
        $this->assertEquals($foto, $recibido->fresh()->toArray());

        // Lo propio sigue andando: el mismo cheque, a un proveedor propio, es un 200 con el pago
        // registrado en SU cuenta.
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor endoso propio ' . uniqid(), self::DEUDA_PROVEEDOR);

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);

        $this->assertSame(200, $response->getStatusCode(), 'Endosar a un proveedor propio: ' . $this->resumen($response));
        $this->assertSame($recibido->id, $response->json('model.id'));
        $this->assertSame($proveedor->id, (int) $response->json('model.endosado_a_provider_id'));

        $copia = $this->copias_de($recibido)->first();

        $this->assertNotNull($copia);
        $this->cobros_cc_creados_por_escenarios[] = (int) $copia->current_acount_id;

        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR - self::MONTO_CHEQUE, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);
        $this->assertEquals($foto_cuenta_ajena, $this->foto_de_cuenta($cuenta_ajena));
    }

    /**
     * El `cheque_banco_id` de una fila de pago se guardaba sin cruzarlo con el dueño: el cheque
     * quedaba atado al banco del catálogo de otro comercio y `GET cheque` (que carga `cheque_banco`)
     * mostraba su nombre — la misma fuga del show del banco, por otra puerta. No se rechaza la fila
     * (la arman tres pantallas y el asistente): un banco ajeno se lee como "sin banco", igual que
     * un 0, y el cheque se guarda con su texto `banco`.
     *
     * @test
     */
    public function un_cheque_no_queda_atado_al_banco_de_otro_dueno()
    {
        $nombre_ajeno = 'Banco ajeno atado ' . uniqid();
        $banco_ajeno = $this->banco_ajeno($nombre_ajeno);

        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente banco ajeno ' . uniqid());

        // Un cobro con cheque nuevo que dice ser del banco ajeno: entra (201), sin banco del catálogo.
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, [
            'numero'          => 'BCO-' . substr(uniqid(), -6),
            'banco'           => 'Banco escrito en el papel',
            'cheque_banco_id' => $banco_ajeno->id,
        ]);

        $this->assertNull($recibido->cheque_banco_id, 'El cheque no puede quedar atado al banco de otro comercio.');
        $this->assertSame('Banco escrito en el papel', $recibido->banco, 'El texto del banco queda intacto.');

        $listado = $this->getJson('api/cheque');

        $listado->assertStatus(200);
        $this->assertStringNotContainsString($nombre_ajeno, $listado->getContent(), 'GET cheque no puede mostrar el nombre del banco ajeno.');

        // Un banco que no existe: lo mismo.
        $con_inexistente = $this->cobrar_con_cheque($cliente, $cuenta_cliente, [
            'numero'          => 'BCO-' . substr(uniqid(), -6),
            'banco'           => 'Banco inventado',
            'cheque_banco_id' => $this->id_que_no_existe('cheque_bancos'),
        ]);

        $this->assertNull($con_inexistente->cheque_banco_id, 'Un banco que no existe se lee como "sin banco".');

        // Por el endoso: origen sin banco + fila con el banco ajeno -> la copia, sin banco.
        $origen = $this->cobrar_con_cheque($cliente, $cuenta_cliente, [
            'numero'          => 'ORI-' . substr(uniqid(), -6),
            'banco'           => 'Banco del origen',
            'cheque_banco_id' => 0,
        ]);

        $this->assertNull($origen->cheque_banco_id);

        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor banco ajeno ' . uniqid(), self::DEUDA_PROVEEDOR);

        $fila = $this->fila_de_pago(array_merge($this->claves_de_endoso($origen), ['cheque_banco_id' => $banco_ajeno->id]));

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

        $this->assertSame(201, $response->getStatusCode(), 'El endoso con el banco ajeno en la fila entra igual: ' . $this->resumen($response));
        $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');

        $copia = $this->copias_de($origen)->first();

        $this->assertNotNull($copia, 'El endoso tenía que dejar la copia emitida.');
        $this->assertNull($copia->cheque_banco_id, 'La copia del endoso no puede quedar atada al banco de otro comercio.');
        $this->assertSame('Banco del origen', $copia->banco);
        $this->assertStringNotContainsString($nombre_ajeno, $this->getJson('api/cheque')->getContent());

        // Y un banco PROPIO se sigue guardando: el filtro es por dueño, no "sin banco para todos".
        $propio = ChequeBanco::find((int) $this->postJson('api/cheque-banco', ['name' => 'Banco propio atado'])->json('model.id'));

        $con_propio = $this->cobrar_con_cheque($cliente, $cuenta_cliente, [
            'numero'          => 'BCO-' . substr(uniqid(), -6),
            'banco'           => 'Banco propio atado',
            'cheque_banco_id' => $propio->id,
        ]);

        $this->assertSame($propio->id, (int) $con_propio->cheque_banco_id);
        $this->assertSame('Banco propio atado', $this->cheque_del_listado($con_propio->id)['cheque_banco']['name']);
    }

    /**
     * Segunda vuelta, punto 1. `endosado_desde_client_id` se guardaba CRUDO de la fila en
     * ChequeHelper::crear_cheque(): un cobro (o un gasto) propio con el id de un cliente de otro
     * comercio daba 201 y `GET cheque` —que carga `endosado_desde_client` por withAll, y Client no
     * esconde nada— devolvía su nombre, email, teléfono, CUIT y dirección. Nadie la manda
     * legítimamente (ni la SPA ni el asistente): ahora se lee con dueño, como el banco, y un cliente
     * de otra cuenta (o que no existe) es null.
     *
     * @test
     */
    public function un_cheque_no_queda_atado_al_cliente_de_otro_dueno()
    {
        $ajeno = $this->cliente_ajeno();

        // Por el cobro a un cliente propio, con un cheque nuevo: entra (201) sin ese cliente.
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente desde ajeno ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, [
            'numero'                   => 'DESDE-' . substr(uniqid(), -6),
            'endosado_desde_client_id' => $ajeno->id,
        ]);

        $this->assertNull($recibido->endosado_desde_client_id, 'Por el cobro: el cheque no puede quedar atado al cliente de otro comercio.');

        // Por el gasto, lo mismo.
        $emitido = $this->gastar_con_cheque_nuevo([
            'numero'                   => 'DESDE-' . substr(uniqid(), -6),
            'endosado_desde_client_id' => $ajeno->id,
        ]);

        $this->assertNull($emitido->endosado_desde_client_id, 'Por el gasto: el cheque no puede quedar atado al cliente de otro comercio.');

        // Y GET cheque no cuenta nada de él.
        $listado = $this->getJson('api/cheque');

        $listado->assertStatus(200);
        $this->assertStringNotContainsString($ajeno->name, $listado->getContent(), 'GET cheque no puede devolver el nombre del cliente de otro comercio.');
        $this->assertStringNotContainsString($ajeno->email, $listado->getContent(), 'GET cheque no puede devolver el email del cliente de otro comercio.');

        // Un cliente que no existe: lo mismo.
        $con_inexistente = $this->cobrar_con_cheque($cliente, $cuenta, [
            'numero'                   => 'DESDE-' . substr(uniqid(), -6),
            'endosado_desde_client_id' => $this->id_que_no_existe('clients'),
        ]);

        $this->assertNull($con_inexistente->endosado_desde_client_id, 'Un cliente que no existe se lee como null.');

        // Y un cliente PROPIO se sigue guardando: el filtro es por dueño, no "null para todos".
        list($otro_propio, $otra_cuenta) = $this->cliente_con_cuenta('Cliente propio desde ' . uniqid());

        $con_propio = $this->cobrar_con_cheque($cliente, $cuenta, [
            'numero'                   => 'DESDE-' . substr(uniqid(), -6),
            'endosado_desde_client_id' => $otro_propio->id,
        ]);

        $this->assertSame($otro_propio->id, (int) $con_propio->endosado_desde_client_id);
    }

    /**
     * Segunda vuelta, punto 2 — EL MECANISMO de la clase del punto 1: ningún `*_id` de `cheques`
     * sale crudo de la fila. Por las dos puertas que crean un cheque nuevo desde una fila (el cobro
     * de cuenta corriente y el gasto), la fila lleva un id CENTINELA —que no existe en ninguna
     * tabla— en CADA clave que se llama como una columna `*_id` de `cheques`, leídas del esquema
     * (así una columna nueva entra sola). Ninguna columna del cheque creado puede valer el
     * centinela: o la clave no se lee de la fila (la pone el sistema: el pago, el gasto, la sesión),
     * o se lee con un lector con dueño, que a un id que no es de esta cuenta lo lee como null.
     *
     * `caja_id` va en 0 y no con el centinela: es la caja del movimiento de la fila, la valida la
     * pantalla de pago (CurrentAcountCajaHelper::cajas_sin_apertura_en_payload(), fuera de los
     * archivos de esta misión) y no la lee ChequeHelper.
     *
     * @test
     */
    public function ningun_id_de_un_cheque_sale_crudo_de_la_fila()
    {
        $claves = $this->claves_id_de_cheques();

        // Que la lista salga del esquema y tenga lo que tiene que tener: con una lista vacía este
        // test pasaría sin mirar nada.
        $this->assertContains('endosado_desde_client_id', $claves);
        $this->assertContains('cheque_banco_id', $claves);
        $this->assertContains('client_id', $claves);
        $this->assertNotContains('caja_id', $claves);

        $centinelas = array_fill_keys($claves, self::CENTINELA);

        $datos_del_cheque = [
            'numero'        => 'CEN-' . substr(uniqid(), -6),
            'banco'         => 'Banco del centinela',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(10)->format('Y-m-d'),
        ];

        // Puerta 1: el cobro a un cliente propio, con un cheque nuevo.
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente centinela ' . uniqid());

        $fila = $this->fila_de_pago(array_merge($datos_del_cheque, $centinelas));

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('client', $cliente->id, $cuenta, [$fila]));

        $this->assertSame(201, $response->getStatusCode(), 'El cobro con los centinelas en la fila: ' . $this->resumen($response));

        $pago_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $recibido = Cheque::where('current_acount_id', $pago_id)->first();

        $this->assertNotNull($recibido, 'El cobro tenía que crear el cheque.');
        $this->assertSame([], $this->columnas_con_el_centinela($recibido), 'Por el cobro: estas columnas del cheque salieron crudas de la fila (valen el centinela ' . self::CENTINELA . ').');

        // Puerta 2: el gasto con un cheque nuevo.
        $fila = $this->fila_de_gasto(array_merge($datos_del_cheque, ['numero' => 'CEN-' . substr(uniqid(), -6)], $centinelas));

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

        $this->assertSame(201, $response->getStatusCode(), 'El gasto con los centinelas en la fila: ' . $this->resumen($response));

        $gasto_id = (int) $response->json('model.id');
        $this->gastos_creados_por_escenarios[] = $gasto_id;

        $emitido = Cheque::where('expense_id', $gasto_id)->first();

        $this->assertNotNull($emitido, 'El gasto tenía que crear el cheque.');
        $this->assertSame([], $this->columnas_con_el_centinela($emitido), 'Por el gasto: estas columnas del cheque salieron crudas de la fila (valen el centinela ' . self::CENTINELA . ').');
    }

    /**
     * Segunda vuelta, punto 3. El endoso copiaba el `cheque_banco_id` del ORIGEN sin pasarlo por el
     * lector con dueño: un recibido que quedó atado a un banco ajeno por el hueco viejo (anterior a
     * esta misión) le pasaba ese banco a la copia emitida nueva. Ahora el banco del origen se lee
     * con dueño, igual que el de la fila: si no es de esta cuenta, la copia sale sin banco.
     *
     * El dato viejo se escribe directo en la base porque ningún endpoint lo deja escribir hoy. El
     * propio recibido sigue atado a ese banco (este arreglo no repara datos viejos): por eso lo que
     * se mira del listado es todo MENOS su fila.
     *
     * @test
     */
    public function el_endoso_no_propaga_el_banco_de_otro_dueno()
    {
        $nombre_ajeno = 'Banco ajeno viejo ' . uniqid();
        $banco_ajeno = $this->banco_ajeno($nombre_ajeno);

        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente banco viejo ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, [
            'numero' => 'VIEJO-' . substr(uniqid(), -6),
            'banco'  => 'Banco del papel',
        ]);

        // El dato de antes del arreglo: el recibido quedó atado al banco de otro comercio.
        DB::table('cheques')->where('id', $recibido->id)->update(['cheque_banco_id' => $banco_ajeno->id]);

        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor banco viejo ' . uniqid(), self::DEUDA_PROVEEDOR);

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);

        $this->assertSame(200, $response->getStatusCode(), 'Endosar el recibido con el banco viejo: ' . $this->resumen($response));

        $copia = $this->copias_de($recibido)->first();

        $this->assertNotNull($copia, 'El endoso tenía que dejar la copia emitida.');
        $this->cobros_cc_creados_por_escenarios[] = (int) $copia->current_acount_id;

        $this->assertNull($copia->cheque_banco_id, 'La copia no puede heredar el banco de otro comercio que el recibido arrastraba de antes.');
        $this->assertSame('Banco del papel', $copia->banco, 'El texto del banco viaja igual.');
        $this->assertNull($this->cheque_del_listado($copia->id)['cheque_banco']);
        $this->assertStringNotContainsString($nombre_ajeno, json_encode($this->listado_sin($recibido->id)), 'Fuera del propio recibido, GET cheque no puede nombrar el banco ajeno.');
    }

    /**
     * Segunda vuelta, punto 4. El endoso por la FILA de un pago a proveedor no miraba de quién es el
     * proveedor: `POST current-acount/pago` con la cuenta de un proveedor de otro comercio y una fila
     * que endosa un recibido propio daba 201, dejaba el cheque endosado a ese proveedor y le bajaba
     * la cuenta (de 0 a -45.000). La raíz —pago() y registrar() no cruzan la cuenta con el dueño— es
     * un hallazgo abierto, fuera de esta misión; acá se cierra el endoso en su único camino,
     * ChequeHelper::endosar(), que verifica el destino antes de marcar nada.
     *
     * Hoy el pedido termina en 500: la excepción sale de adentro del DB::transaction de pago(), que
     * no la atrapa (el reporte a GitHub solo actúa con APP_ENV=production). Lo que se pide es que
     * NO sea un 2xx, que lo corte esa verificación y que no quede nada escrito.
     *
     * @test
     */
    public function no_se_endosa_por_la_fila_de_pago_a_un_proveedor_de_otro_dueno()
    {
        list($proveedor_ajeno, $cuenta_ajena) = $this->proveedor_ajeno_con_cuenta();

        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente fila ajena ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'FILA-' . substr(uniqid(), -6)]);

        $foto = $recibido->fresh()->toArray();
        $foto_cuenta_ajena = $this->foto_de_cuenta($cuenta_ajena);
        $current_acounts_antes = CurrentAcount::count();
        $cheques_antes = Cheque::count();

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor_ajeno->id, $cuenta_ajena, [$this->fila_de_pago($this->claves_de_endoso($recibido))]));

        $estado = $response->getStatusCode();

        $this->assertFalse($estado >= 200 && $estado < 300, 'El endoso por la fila a un proveedor de otro comercio no puede salir bien: ' . $this->resumen($response));

        // En el 500 el mensaje de la excepción viaja porque el .env.testing de los slots tiene
        // APP_DEBUG=true; si mañana pago() lo convierte en un 422, viaja igual.
        $this->assertSame(self::MENSAJE_PROVEEDOR, $response->json('message'), 'Lo tiene que cortar la verificación del destino del endoso, y no otra falla (con APP_DEBUG=false el 500 no trae el mensaje).');

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque propio sigue en cartera, sin marca.');
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar(), 'El cheque se sigue ofreciendo para endosar.');
        $this->assertCount(0, $this->copias_de($recibido), 'No nació ninguna copia emitida.');
        $this->assertSame($cheques_antes, Cheque::count());
        $this->assertEquals($foto_cuenta_ajena, $this->foto_de_cuenta($cuenta_ajena), 'La cuenta del proveedor ajeno tiene el mismo saldo y los mismos movimientos.');
        $this->assertSame($current_acounts_antes, CurrentAcount::count(), 'No quedó ningún movimiento de cuenta corriente: la excepción revierte el pago entero.');
    }

    /**
     * Segunda vuelta, punto 4, la otra mitad: el destino de un endoso en un GASTO también tiene que
     * ser de esta cuenta. Ninguna puerta de hoy llega con un gasto ajeno —todas lo crean con el dueño
     * de la sesión en `expenses.user_id`—, así que se prueba el helper directo: es el único camino
     * del endoso y el que tiene que cortar si mañana alguien le pasa un gasto de otro comercio.
     *
     * @test
     */
    public function no_se_endosa_en_un_gasto_de_otro_dueno()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente gasto ajeno ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'GAJ-' . substr(uniqid(), -6)]);

        $foto = $recibido->fresh()->toArray();

        $gasto_ajeno = Expense::create([
            'num'          => 1,
            'amount'       => self::MONTO_CHEQUE,
            'moneda_id'    => 1,
            'observations' => 'Gasto de otro comercio',
            'user_id'      => $this->otro_dueno()->id,
            'caja_id'      => 0,
        ]);

        $this->gastos_creados_a_mano[] = $gasto_ajeno->id;

        $cheques_antes = Cheque::count();
        $excepcion = null;

        try {
            ChequeHelper::endosar($recibido->fresh(), $gasto_ajeno, $this->fila_de_gasto($this->claves_de_endoso($recibido)));
        } catch (\RuntimeException $e) {
            $excepcion = $e;
        }

        $this->assertNotNull($excepcion, 'Endosar en el gasto de otro comercio tenía que cortar con una excepción.');
        $this->assertSame(self::MENSAJE_GASTO_AJENO, $excepcion->getMessage());
        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertSame($cheques_antes, Cheque::count(), 'No nació ninguna copia emitida.');
    }

    /**
     * EL MECANISMO: toda ruta que llega a ChequeController o ChequeBancoController está declarada
     * en matriz_de_tenencia(), con cómo resuelve la tenencia de los ids que recibe y qué test lo
     * prueba. Una ruta nueva en esos controllers pone este test en rojo hasta que alguien la declare,
     * y para declararla tiene que pensar de quién son los ids que le llegan.
     *
     * @test
     */
    public function toda_ruta_de_cheques_esta_en_la_matriz_de_tenencia()
    {
        $en_el_router = [];

        foreach (Route::getRoutes() as $route) {

            $accion = $route->getActionName();

            if (!preg_match('/(^|\\\\)(ChequeController|ChequeBancoController)@\w+$/', $accion)) {

                continue;
            }

            $metodos = array_values(array_diff($route->methods(), ['HEAD']));

            $en_el_router[implode('|', $metodos) . ' ' . $route->uri()] = $accion;
        }

        $matriz = $this->matriz_de_tenencia();

        $sobran = array_values(array_diff(array_keys($en_el_router), array_keys($matriz)));
        $faltan = array_values(array_diff(array_keys($matriz), array_keys($en_el_router)));

        $this->assertSame([], $sobran, 'Rutas de cheques que el router tiene y la matriz de tenencia NO declara: ' . implode(', ', $sobran) . '. Declaralas en matriz_de_tenencia() diciendo de quién son los ids que reciben y cómo se resuelven (y probalo).');
        $this->assertSame([], $faltan, 'Rutas declaradas en la matriz de tenencia que ya no existen en el router: ' . implode(', ', $faltan) . '. Sacalas de la matriz.');

        foreach ($matriz as $clave => $fila) {

            $this->assertSame($fila['accion'], $en_el_router[$clave], $clave . ' apunta a otro método que el que declara la matriz: revisá su tenencia.');

            list($clase, $metodo) = explode('@', $fila['accion']);

            if ($fila['tenencia'] === self::METODO_INEXISTENTE) {

                $this->assertFalse(method_exists($clase, $metodo), $clave . ': la matriz dice que ' . $fila['accion'] . ' no existe, y ahora existe. Declarale la tenencia.');

                continue;
            }

            $this->assertTrue(method_exists($clase, $metodo), $clave . ': ' . $fila['accion'] . ' no existe.');
            $this->assertNotSame('', trim($fila['prueba']), $clave . ': falta decir qué test prueba su tenencia.');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // La matriz
    // ---------------------------------------------------------------------------------------------

    /**
     * Las rutas de ChequeController y ChequeBancoController, por "MÉTODOS uri" (sin HEAD), con la
     * acción a la que llegan, cómo se resuelve la tenencia de lo que reciben y qué test lo prueba.
     *
     * @return array<string, array{accion: string, tenencia: string, prueba: string}>
     */
    protected function matriz_de_tenencia()
    {
        $cheque = ChequeController::class . '@';
        $banco = ChequeBancoController::class . '@';

        return [

            // --- ChequeController (routes/api.php, bajo auth:sanctum) ---------------------------

            'GET api/cheque' => [
                'accion'   => $cheque . 'index',
                'tenencia' => 'no recibe ids: lista solo los cheques del dueño (user_id = userId()).',
                'prueba'   => 'Tenencia_de_cheques_Test::cobrar_pagar_rechazar_y_borrar_un_cheque_ajeno_no_lo_toca (el ajeno no se lista)',
            ],
            'GET api/cheque/disponibles-para-endosar' => [
                'accion'   => $cheque . 'disponibles_para_endosar',
                'tenencia' => 'no recibe ids: ChequeHelper::disponibles_para_endosar(userId()).',
                'prueba'   => 'Prevalidacion_de_endoso_Test::disponibles_para_endosar_no_lista_ninguno_de_los_que_no_se_pueden_endosar (el ajeno no se ofrece)',
            ],
            'PUT api/cheque/cobrar' => [
                'accion'   => $cheque . 'cobrar',
                'tenencia' => 'cuerpo: cheque_id por cheque_del_dueno() y caja_id por caja_id_del_dueno() -> 422, antes de escribir nada.',
                'prueba'   => 'Tenencia_de_cheques_Test::cobrar_pagar_rechazar_y_borrar_un_cheque_ajeno_no_lo_toca y ::un_cheque_propio_no_se_cobra_en_la_caja_de_otro_dueno',
            ],
            'PUT api/cheque/pagar' => [
                'accion'   => $cheque . 'pagar',
                'tenencia' => 'cuerpo: cheque_id por cheque_del_dueno() y caja_id por caja_id_del_dueno() -> 422, antes de escribir nada.',
                'prueba'   => 'Tenencia_de_cheques_Test::cobrar_pagar_rechazar_y_borrar_un_cheque_ajeno_no_lo_toca y ::un_cheque_propio_no_se_cobra_en_la_caja_de_otro_dueno',
            ],
            'PUT api/cheque/rechazar' => [
                'accion'   => $cheque . 'rechazar',
                'tenencia' => 'cuerpo: cheque_id por cheque_del_dueno() -> 422, antes de escribir nada.',
                'prueba'   => 'Tenencia_de_cheques_Test::cobrar_pagar_rechazar_y_borrar_un_cheque_ajeno_no_lo_toca',
            ],
            'PUT api/cheque/endosar' => [
                'accion'   => $cheque . 'endosar',
                'tenencia' => 'cuerpo: cheque_id por cheque_del_dueno() (+ ChequeHelper::problemas_de_endoso_en_payload) y provider_id por proveedor_del_dueno() -> 422, antes de escribir nada; el cheque_banco_id de la copia, por ChequeHelper::cheque_banco_id_de(..., dueño).',
                'prueba'   => 'Tenencia_de_cheques_Test::no_se_endosa_a_un_proveedor_de_otro_dueno; Endosar_desde_el_modulo_Test; Prevalidacion_de_endoso_Test::un_cheque_id_que_no_es_numero_es_sin_cheque_en_las_tres_puertas',
            ],
            'DELETE api/cheque/{id}' => [
                'accion'   => $cheque . 'destroy',
                'tenencia' => 'ruta: consulta_del_cheque_del_dueno($id)->firstOrFail() -> 404.',
                'prueba'   => 'Tenencia_de_cheques_Test::cobrar_pagar_rechazar_y_borrar_un_cheque_ajeno_no_lo_toca',
            ],

            // --- ChequeController (routes/web.php: SIN middleware de autenticación) --------------

            'GET cheque/excel/export' => [
                'accion'   => $cheque . 'excel_export',
                'tenencia' => 'query: cheque_ids, filtrados por user_id = userId() en get_cheques_for_excel_export(). Es una ruta WEB sin auth: sin sesión, userId() cae en config(app.USER_ID) (hallazgo de la misión cheques-filtro-por-dueno; es de todas las exportaciones de routes/web.php, no de este controller).',
                'prueba'   => 'sin test HTTP: la respuesta es un xlsx',
            ],

            // --- ChequeBancoController (Route::resource('cheque-banco'), bajo auth:sanctum) ------

            'GET api/cheque-banco' => [
                'accion'   => $banco . 'index',
                'tenencia' => 'no recibe ids: lista solo los bancos del dueño.',
                'prueba'   => 'Bancos_de_cheques_Test::el_abm_del_catalogo_es_por_dueno',
            ],
            'POST api/cheque-banco' => [
                'accion'   => $banco . 'store',
                'tenencia' => 'no recibe ids: crea con user_id = userId().',
                'prueba'   => 'Bancos_de_cheques_Test::el_abm_del_catalogo_es_por_dueno',
            ],
            'GET api/cheque-banco/{cheque_banco}' => [
                'accion'   => $banco . 'show',
                'tenencia' => 'ruta: banco_del_dueno() -> 404.',
                'prueba'   => 'Tenencia_de_cheques_Test::un_dueno_no_ve_el_banco_de_otro',
            ],
            'PUT|PATCH api/cheque-banco/{cheque_banco}' => [
                'accion'   => $banco . 'update',
                'tenencia' => 'ruta: banco_del_dueno() -> 404.',
                'prueba'   => 'Bancos_de_cheques_Test::el_abm_del_catalogo_es_por_dueno',
            ],
            'DELETE api/cheque-banco/{cheque_banco}' => [
                'accion'   => $banco . 'destroy',
                'tenencia' => 'ruta: banco_del_dueno() -> 404; desasocia solo los cheques del dueño.',
                'prueba'   => 'Bancos_de_cheques_Test::el_abm_del_catalogo_es_por_dueno',
            ],
            'GET api/cheque-banco/create' => [
                'accion'   => $banco . 'create',
                'tenencia' => self::METODO_INEXISTENTE,
                'prueba'   => 'no se llama',
            ],
            'GET api/cheque-banco/{cheque_banco}/edit' => [
                'accion'   => $banco . 'edit',
                'tenencia' => self::METODO_INEXISTENTE,
                'prueba'   => 'no se llama',
            ],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------------

    /**
     * Los tres pedidos que reciben un cheque por el cuerpo, con las claves que manda la SPA
     * (cheques/list/modals/{Cobrar,Pagar,Rechazar}Cheque.vue de develop). Rechazar manda `notas`,
     * no `rechazado_observaciones`.
     *
     * @param mixed $cheque_id
     * @param mixed $caja_id
     * @return array<string, array>
     */
    protected function pedidos_sobre_un_cheque($cheque_id, $caja_id)
    {
        return [
            'cobrar'   => ['cheque_id' => $cheque_id, 'caja_id' => $caja_id],
            'pagar'    => ['cheque_id' => $cheque_id, 'caja_id' => $caja_id],
            'rechazar' => ['cheque_id' => $cheque_id, 'notas' => 'Rechazado por la suite de tenencia'],
        ];
    }

    /**
     * El otro comercio: un dueño sin owner_id, creado una vez por test.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {
            $this->otro_dueno = $this->crear_usuario('Otro comercio tenencia', 'cheques-tenencia-otro-', null);
        }

        return $this->otro_dueno;
    }

    /**
     * Un usuario: dueño si `$owner_id` es null, empleado de ese dueño si no.
     *
     * @param string $nombre
     * @param string $prefijo_email
     * @param int|null $owner_id
     * @return User
     */
    protected function crear_usuario($nombre, $prefijo_email, $owner_id)
    {
        $usuario = User::create([
            'name'     => $nombre,
            'email'    => $prefijo_email . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $owner_id,
        ]);

        $this->usuarios_creados[] = $usuario->id;

        return $usuario;
    }

    /**
     * Cambia el usuario de las requests que siguen. El `Auth::forgetGuards()` no es decorativo: el
     * guard de sanctum es un RequestGuard que cachea el usuario que resolvió la primera vez, y sin
     * olvidarlo el segundo actingAs() no cambia nada (este archivo daría verde en falso).
     *
     * @param User $usuario
     * @return void
     */
    protected function actuar_como(User $usuario)
    {
        Auth::forgetGuards();

        $this->actingAs($usuario, 'web');
    }

    /**
     * Un banco del catálogo de OTRO comercio, insertado a mano.
     *
     * @param string $nombre
     * @return ChequeBanco
     */
    protected function banco_ajeno($nombre)
    {
        return ChequeBanco::create(['name' => $nombre, 'user_id' => $this->otro_dueno()->id]);
    }

    /**
     * Una caja de OTRO comercio, abierta como se abre una caja de verdad (con su apertura y marcada),
     * insertada a mano. Con apertura a propósito: sin ella MovimientoCajaHelper revienta antes de
     * escribir, y el test no mediría que la plata entra en la caja ajena.
     *
     * @return Caja
     */
    protected function caja_ajena_con_apertura()
    {
        $otro = $this->otro_dueno();

        $caja = Caja::create([
            'num'     => 1,
            'name'    => 'Caja ajena tenencia ' . uniqid(),
            'user_id' => $otro->id,
            'saldo'   => self::SALDO_CAJA_AJENA,
        ]);

        $this->cajas_creadas[] = $caja->id;

        $apertura = AperturaCaja::create([
            'saldo_apertura'       => self::SALDO_CAJA_AJENA,
            'apertura_employee_id' => $otro->id,
            'caja_id'              => $caja->id,
        ]);

        Caja::where('id', $caja->id)->update([
            'abierta'                  => 1,
            'abierta_at'               => Carbon::now(),
            'current_apertura_caja_id' => $apertura->id,
        ]);

        return $caja->fresh();
    }

    /**
     * La caja de efectivo del fixture (del dueño), abierta por el endpoint real si hiciera falta.
     * resolver_caja_por_nombre() guarda su saldo para que limpiar_escenarios() lo restaure.
     *
     * @return Caja
     */
    protected function caja_propia_con_apertura()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->assertSame($this->dueno->id, (int) $caja->user_id, 'La caja de efectivo del fixture tiene que ser del dueño.');

        $this->asegurar_caja_abierta($caja);

        return $caja->fresh();
    }

    /**
     * Un proveedor de OTRO comercio con sus cuentas corrientes, insertado a mano (las cuentas, por
     * CreditAccountHelper, como las crea el sistema).
     *
     * @return array{0: Provider, 1: CreditAccount|null}
     */
    protected function proveedor_ajeno_con_cuenta()
    {
        $otro = $this->otro_dueno();

        $proveedor = Provider::create([
            'name'    => 'Proveedor ajeno tenencia ' . uniqid(),
            'user_id' => $otro->id,
            'status'  => 'active',
        ]);

        $this->proveedores_creados[] = $proveedor->id;

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $otro->id);

        return [$proveedor, $this->cuenta_de('provider', $proveedor->id)];
    }

    /**
     * Paga a un proveedor con un cheque NUEVO por el endpoint real y devuelve el cheque emitido.
     *
     * @param Provider $proveedor
     * @param CreditAccount $cuenta
     * @param array $cheque Claves del cheque a pisar.
     * @return Cheque
     */
    protected function pagar_con_cheque_nuevo($proveedor, $cuenta, array $cheque = [])
    {
        $fila = $this->fila_de_pago(array_merge([
            'numero'        => 'EM-' . substr(uniqid(), -6),
            'banco'         => 'Banco Ciudad',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(15)->format('Y-m-d'),
        ], $cheque));

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta, [$fila]));

        if ($response->getStatusCode() !== 201) {
            $this->fail('El pago a proveedor con cheque nuevo tenía que dar 201 y dio ' . $response->getStatusCode() . ': ' . $response->getContent());
        }

        $pago_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $emitido = Cheque::where('current_acount_id', $pago_id)->where('tipo', 'emitido')->first();

        $this->assertNotNull($emitido, 'El pago a proveedor con una fila de tipo cheque tenía que dejar un cheque emitido.');

        return $emitido;
    }

    /**
     * Un gasto con un cheque NUEVO por el endpoint real (`POST api/expense`) y devuelve el cheque
     * emitido que nació colgado del gasto.
     *
     * @param array $cheque Claves de la fila a pisar.
     * @return Cheque
     */
    protected function gastar_con_cheque_nuevo(array $cheque = [])
    {
        $fila = $this->fila_de_gasto(array_merge([
            'numero'        => 'GA-' . substr(uniqid(), -6),
            'banco'         => 'Banco Ciudad',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(15)->format('Y-m-d'),
        ], $cheque));

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

        if ($response->getStatusCode() !== 201) {
            $this->fail('El gasto con cheque nuevo tenía que dar 201 y dio ' . $response->getStatusCode() . ': ' . $response->getContent());
        }

        $gasto_id = (int) $response->json('model.id');
        $this->gastos_creados_por_escenarios[] = $gasto_id;

        $emitido = Cheque::where('expense_id', $gasto_id)->first();

        $this->assertNotNull($emitido, 'El gasto con una fila de tipo cheque tenía que dejar un cheque.');

        return $emitido;
    }

    /**
     * Un cliente de OTRO comercio, insertado a mano, con datos que no se repiten en ningún otro
     * lado (para poder buscarlos en una respuesta).
     *
     * @return Client
     */
    protected function cliente_ajeno()
    {
        $marca = uniqid();

        $cliente = Client::create([
            'num'     => 1,
            'name'    => 'Cliente ajeno tenencia ' . $marca,
            'email'   => 'cliente-ajeno-' . $marca . '@test.local',
            'phone'   => '1155550000',
            'address' => 'Calle del otro comercio ' . $marca,
            'user_id' => $this->otro_dueno()->id,
        ]);

        $this->clientes_creados[] = $cliente->id;

        return $cliente;
    }

    /**
     * Las columnas `*_id` de `cheques`, leídas del esquema (así una columna nueva entra sola al
     * test-mecanismo), menos `caja_id` (ver ningun_id_de_un_cheque_sale_crudo_de_la_fila()).
     *
     * @return array<int, string>
     */
    protected function claves_id_de_cheques()
    {
        $claves = [];

        foreach (Schema::getColumnListing('cheques') as $columna) {

            if (preg_match('/_id$/', $columna) && $columna !== 'caja_id') {

                $claves[] = $columna;
            }
        }

        return $claves;
    }

    /**
     * Las columnas de un cheque que valen el centinela.
     *
     * @param Cheque $cheque
     * @return array<int, string>
     */
    protected function columnas_con_el_centinela(Cheque $cheque)
    {
        $fila = (array) DB::table('cheques')->where('id', $cheque->id)->first();

        $con_el_centinela = [];

        foreach ($fila as $columna => $valor) {

            if (!is_null($valor) && (string) $valor === (string) self::CENTINELA) {

                $con_el_centinela[] = $columna;
            }
        }

        return $con_el_centinela;
    }

    /**
     * Las solapas de `GET cheque` sin la fila de un cheque.
     *
     * @param int $cheque_id
     * @return array
     */
    protected function listado_sin($cheque_id)
    {
        $response = $this->getJson('api/cheque');

        $response->assertStatus(200);

        $solapas_por_tipo = $response->json('models');

        foreach ($solapas_por_tipo as $tipo => $solapas) {
            foreach ($solapas as $solapa => $cheques) {
                $solapas_por_tipo[$tipo][$solapa] = array_values(array_filter($cheques, function ($cheque) use ($cheque_id) {
                    return (int) $cheque['id'] !== (int) $cheque_id;
                }));
            }
        }

        return $solapas_por_tipo;
    }

    /**
     * Lo observable de una caja: su saldo, cuántos movimientos tiene y los totales de sus aperturas.
     *
     * @param Caja $caja
     * @return array
     */
    protected function foto_de_caja(Caja $caja)
    {
        return [
            'saldo'       => round((float) Caja::find($caja->id)->saldo, 2),
            'movimientos' => MovimientoCaja::where('caja_id', $caja->id)->count(),
            'aperturas'   => AperturaCaja::where('caja_id', $caja->id)->orderBy('id')->get(['id', 'total_ingresos', 'total_egresos'])->toArray(),
        ];
    }

    /**
     * Lo observable de una cuenta corriente: su saldo y cuántos movimientos tiene.
     *
     * @param CreditAccount $cuenta
     * @return array
     */
    protected function foto_de_cuenta(CreditAccount $cuenta)
    {
        return [
            'saldo'       => round((float) CreditAccount::find($cuenta->id)->saldo, 2),
            'movimientos' => CurrentAcount::where('credit_account_id', $cuenta->id)->count(),
        ];
    }

    /**
     * Todas las filas de `cheques`, para comparar antes y después.
     *
     * @return array
     */
    protected function foto_de_cheques()
    {
        return DB::table('cheques')->orderBy('id')->get()->map(function ($fila) {
            return (array) $fila;
        })->all();
    }

    /**
     * Un id que no existe en la tabla (más allá del máximo, contando los borrados lógicos).
     *
     * @param string $tabla
     * @return int
     */
    protected function id_que_no_existe($tabla)
    {
        return (int) DB::table($tabla)->max('id') + 1000;
    }

    /**
     * Estado y comienzo del cuerpo de una respuesta, para los mensajes de falla.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @return string
     */
    protected function resumen($response)
    {
        return $response->getStatusCode() . ' ' . mb_substr((string) $response->getContent(), 0, 300);
    }
}
