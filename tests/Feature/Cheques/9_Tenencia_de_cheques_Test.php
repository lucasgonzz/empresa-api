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

    /** El 404 de un cheque de la ruta (DELETE cheque/{id}) ajeno, inexistente o que no es un id. */
    const MENSAJE_CHEQUE_404 = 'El cheque no existe o no es de tu cuenta.';

    /** El 404 de un banco de la ruta (show, update, destroy) ajeno, inexistente o que no es un id. */
    const MENSAJE_BANCO_404 = 'El banco no existe o no es de tu cuenta.';

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
     * El corte de un endoso cuyo cheque no es de esta cuenta o no existe: el texto de
     * ChequeHelper::problemas_de_endoso() (sin punto final: así lo arma el helper).
     */
    const MENSAJE_CHEQUE_DE_ENDOSO = 'El cheque elegido para endosar no existe o no es de tu cuenta';

    /**
     * Un id que no existe en ninguna tabla, para el test-mecanismo de los `*_id` de la fila. Entra en
     * un `int` con signo (las columnas `*_id` de `cheques` lo son) y está muy lejos de cualquier
     * autoincremental de una base de testing.
     */
    const CENTINELA = 2000000001;

    /** Marca, en las listas de valores de los tests, de "la clave no viene en la fila". */
    const CLAVE_AUSENTE = '__la_clave_no_viene__';

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
        $max_cheque = $this->max_id_de('cheques');
        $foto_cuenta_ajena = $this->foto_de_cuenta($cuenta_ajena);

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor_ajeno->id]);

        $this->assertSame(422, $response->getStatusCode(), 'Endosar a un proveedor de otro comercio: ' . $this->resumen($response));
        $this->assertSame(self::MENSAJE_PROVEEDOR, $response->json('message'));
        $this->assertStringNotContainsString($proveedor_ajeno->name, $response->getContent());

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar(), 'El cheque se sigue ofreciendo para endosar.');
        $this->assertCount(0, $this->copias_de($recibido), 'No nació ninguna copia emitida.');
        $this->assertSame(0, Cheque::where('id', '>', $max_cheque)->count(), 'No nació ningún cheque.');
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
     * sale crudo de la fila. La fila lleva un valor en CADA clave que se llama como una columna
     * `*_id` de `cheques`, leídas del esquema (así una columna nueva entra sola), y ninguna columna
     * del cheque puede terminar valiendo eso: o la clave no se lee de la fila (la pone el sistema:
     * el pago, el gasto, el origen del endoso, la sesión), o se lee con un lector con dueño, que a un
     * id que no es de esta cuenta lo lee como null.
     *
     * Tercera vuelta, punto 7: el centinela solo (un id que no existe en ninguna tabla) medía
     * "crudo" y no "sin dueño" —un lector que mirara solo si la fila EXISTE lo dejaba pasar— y no
     * pasaba por el endoso. Ahora cada clave va con DOS valores, el centinela y un id AJENO QUE
     * EXISTE en la tabla de esa columna (mapa columna → tabla: si aparece una columna `*_id` nueva
     * que el mapa no conoce, el test falla), y por CUATRO puertas: las dos que crean un cheque nuevo
     * (el cobro y el gasto) y las dos que endosan (la fila de un pago a proveedor y la de un gasto,
     * con el `cheque_id` de un recibido propio), mirando la COPIA.
     *
     * `caja_id` va en 0 y no con el centinela: es la caja del movimiento de la fila, la valida la
     * pantalla de pago (CurrentAcountCajaHelper::cajas_sin_apertura_en_payload(), fuera de los
     * archivos de esta misión) y en un endoso tiene que venir vacía.
     *
     * @test
     */
    public function ningun_id_de_un_cheque_sale_crudo_de_la_fila()
    {
        $claves = $this->claves_id_de_cheques();
        $mapa = $this->tabla_de_cada_columna_id();

        // Que la lista salga del esquema y tenga lo que tiene que tener: con una lista vacía este
        // test pasaría sin mirar nada.
        $this->assertContains('endosado_desde_client_id', $claves);
        $this->assertContains('cheque_banco_id', $claves);
        $this->assertContains('client_id', $claves);
        $this->assertNotContains('caja_id', $claves);

        $sin_mapa = array_values(array_diff($claves, array_keys($mapa)));

        $this->assertSame([], $sin_mapa, 'Columnas *_id de cheques que el mapa no conoce: ' . implode(', ', $sin_mapa) . '. Sumalas a tabla_de_cada_columna_id() con su tabla, y pensá cómo se lee esa clave cuando viene en una fila.');

        $ajeno_de_cada_tabla = $this->un_id_ajeno_de_cada_tabla();

        $variantes = [
            'el centinela'           => array_fill_keys($claves, self::CENTINELA),
            'un id ajeno que existe' => [],
        ];

        foreach ($claves as $columna) {
            $variantes['un id ajeno que existe'][$columna] = $ajeno_de_cada_tabla[$mapa[$columna]];
        }

        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente centinela ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor centinela ' . uniqid(), self::DEUDA_PROVEEDOR * 10);

        foreach ($variantes as $variante => $valores) {

            $cheque_nuevo = [
                'numero'        => 'CEN-' . substr(uniqid(), -6),
                'banco'         => 'Banco del centinela',
                'fecha_emision' => Carbon::today()->format('Y-m-d'),
                'fecha_pago'    => Carbon::today()->addDays(10)->format('Y-m-d'),
            ];

            // Puerta 1: el cobro a un cliente propio, con un cheque nuevo.
            $fila = $this->fila_de_pago(array_merge($cheque_nuevo, $valores));

            $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('client', $cliente->id, $cuenta_cliente, [$fila]));

            $this->assertSame(201, $response->getStatusCode(), 'El cobro con ' . $variante . ' en la fila: ' . $this->resumen($response));

            $pago_id = (int) $response->json('current_acount.id');
            $this->cobros_cc_creados_por_escenarios[] = $pago_id;

            $this->assert_ningun_id_crudo($valores, Cheque::where('current_acount_id', $pago_id)->first(), 'el cobro', $variante);

            // Puerta 2: el gasto, con un cheque nuevo.
            $fila = $this->fila_de_gasto(array_merge($cheque_nuevo, ['numero' => 'CEN-' . substr(uniqid(), -6)], $valores));

            $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

            $this->assertSame(201, $response->getStatusCode(), 'El gasto con ' . $variante . ' en la fila: ' . $this->resumen($response));

            $gasto_id = (int) $response->json('model.id');
            $this->gastos_creados_por_escenarios[] = $gasto_id;

            $this->assert_ningun_id_crudo($valores, Cheque::where('expense_id', $gasto_id)->first(), 'el gasto', $variante);

            // Puerta 3: la fila de endoso de un pago a proveedor; se mira la COPIA.
            $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'CENE-' . substr(uniqid(), -6)]);

            $fila = $this->fila_de_pago(array_merge($this->claves_de_endoso($recibido), $valores));

            $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

            $this->assertSame(201, $response->getStatusCode(), 'El endoso por el pago con ' . $variante . ' en la fila: ' . $this->resumen($response));
            $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');

            $this->assert_ningun_id_crudo($valores, $this->copias_de($recibido)->first(), 'el endoso por el pago', $variante);

            // Puerta 4: la fila de endoso de un gasto; se mira la COPIA.
            $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'CENG-' . substr(uniqid(), -6)]);

            $fila = $this->fila_de_gasto(array_merge($this->claves_de_endoso($recibido), $valores));

            $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

            $this->assertSame(201, $response->getStatusCode(), 'El endoso por el gasto con ' . $variante . ' en la fila: ' . $this->resumen($response));
            $this->gastos_creados_por_escenarios[] = (int) $response->json('model.id');

            $this->assert_ningun_id_crudo($valores, $this->copias_de($recibido)->first(), 'el endoso por el gasto', $variante);
        }
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
     * Segunda vuelta, punto 3, la misma clase con el CLIENTE: la copia emitida guardaba como
     * `endosado_desde_client_id` el `client_id` del origen tal cual. Un recibido que quedó colgado
     * del cliente de otro comercio (hoy se puede: `POST current-acount/pago` no cruza la cuenta con
     * el dueño, hallazgo abierto fuera de esta misión) le pasaba ese cliente a la copia, y GET
     * cheque devolvía sus datos por `endosado_desde_client`. Lo heredado se lee con dueño.
     *
     * @test
     */
    public function el_endoso_no_propaga_el_cliente_de_otro_dueno()
    {
        $ajeno = $this->cliente_ajeno();

        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente heredado ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'HEREDA-' . substr(uniqid(), -6)]);

        // El dato viejo: el recibido colgado del cliente de otro comercio.
        DB::table('cheques')->where('id', $recibido->id)->update(['client_id' => $ajeno->id]);

        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor cliente heredado ' . uniqid(), self::DEUDA_PROVEEDOR);

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);

        $this->assertSame(200, $response->getStatusCode(), 'Endosar el recibido con el cliente viejo: ' . $this->resumen($response));

        $copia = $this->copias_de($recibido)->first();

        $this->assertNotNull($copia, 'El endoso tenía que dejar la copia emitida.');
        $this->cobros_cc_creados_por_escenarios[] = (int) $copia->current_acount_id;

        $this->assertNull($copia->endosado_desde_client_id, 'La copia no puede heredar el cliente de otro comercio que el recibido arrastraba.');

        $sin_el_recibido = json_encode($this->listado_sin($recibido->id));

        $this->assertStringNotContainsString($ajeno->name, $sin_el_recibido, 'Fuera del propio recibido, GET cheque no puede nombrar al cliente ajeno.');
        $this->assertStringNotContainsString($ajeno->email, $sin_el_recibido);
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
     * no la atrapa (el reporte a GitHub solo actúa con APP_ENV=production). Se la captura con
     * withoutExceptionHandling() y se mira SU mensaje, no el cuerpo del 500, que con APP_DEBUG=false
     * dice solo "Server Error" (tercera vuelta, punto 9: el .env.testing.example no declara
     * APP_DEBUG). Y no tiene que quedar nada escrito.
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
        $max_current_acount = $this->max_id_de('current_acounts');
        $max_cheque = $this->max_id_de('cheques');

        $payload = $this->payload_de_pago('provider', $proveedor_ajeno->id, $cuenta_ajena, [$this->fila_de_pago($this->claves_de_endoso($recibido))]);

        list($excepcion, $response) = $this->pedido_que_tiene_que_cortar(function () use ($payload) {
            return $this->postJson('api/current-acount/pago', $payload);
        });

        $this->assertNotNull($excepcion, 'El endoso por la fila a un proveedor de otro comercio no puede salir bien: ' . (is_null($response) ? '' : $this->resumen($response)));
        $this->assertSame(self::MENSAJE_PROVEEDOR, $excepcion->getMessage(), 'Lo tiene que cortar la verificación del destino del endoso, y no otra falla.');

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque propio sigue en cartera, sin marca.');
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar(), 'El cheque se sigue ofreciendo para endosar.');
        $this->assertCount(0, $this->copias_de($recibido), 'No nació ninguna copia emitida.');
        $this->assertSame(0, Cheque::where('id', '>', $max_cheque)->count(), 'No nació ningún cheque.');
        $this->assertEquals($foto_cuenta_ajena, $this->foto_de_cuenta($cuenta_ajena), 'La cuenta del proveedor ajeno tiene el mismo saldo y los mismos movimientos.');
        $this->assertSame(0, CurrentAcount::where('id', '>', $max_current_acount)->count(), 'No quedó ningún movimiento de cuenta corriente: la excepción revierte el pago entero.');
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

        $max_cheque = $this->max_id_de('cheques');
        $excepcion = null;

        try {
            ChequeHelper::endosar($recibido->fresh(), $gasto_ajeno, $this->fila_de_gasto($this->claves_de_endoso($recibido)));
        } catch (\RuntimeException $e) {
            $excepcion = $e;
        }

        $this->assertNotNull($excepcion, 'Endosar en el gasto de otro comercio tenía que cortar con una excepción.');
        $this->assertSame(self::MENSAJE_GASTO_AJENO, $excepcion->getMessage());
        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertSame(0, Cheque::where('id', '>', $max_cheque)->count(), 'No nació ninguna copia emitida.');
    }

    /**
     * Segunda vuelta, punto 5. ChequeHelper::crear_cheque() buscaba el `cheque_id` de la fila con un
     * Cheque::find() pelado y contestaba distinto un id que no existe ("ya no existe.") que uno de
     * otro comercio ("no existe o no es de tu cuenta"): por la puerta de VENDER, que no prevalida,
     * con APP_DEBUG=true se distinguía uno del otro. Ahora el origen se busca scopeado por dueño y
     * los dos se contestan con el mismo texto de problemas_de_endoso(). Se prueba el helper directo
     * porque es la puerta común (el pago y el gasto ya cortan antes, en la prevalidación, que
     * también busca scopeado y contesta igual).
     *
     * @test
     */
    public function un_cheque_ajeno_y_uno_inexistente_se_contestan_igual_al_endosar()
    {
        $ajeno = $this->cheque_a_mano([
            'user_id' => $this->otro_dueno()->id,
            'numero'  => 'IGUAL-' . substr(uniqid(), -6),
        ]);

        $foto = $ajeno->fresh()->toArray();
        $inexistente = $this->id_que_no_existe('cheques');

        $mensaje_ajeno = $this->mensaje_de_crear_cheque($ajeno->id);
        $mensaje_inexistente = $this->mensaje_de_crear_cheque($inexistente);

        $this->assertSame(self::MENSAJE_CHEQUE_DE_ENDOSO, $mensaje_ajeno);
        $this->assertSame($mensaje_ajeno, $mensaje_inexistente, 'Un cheque de otro comercio y uno que no existe se tienen que contestar igual.');
        $this->assertEquals($foto, $ajeno->fresh()->toArray(), 'El cheque ajeno no se tocó.');

        // La prevalidación, igual.
        $this->assertSame(
            ChequeHelper::problemas_de_endoso_en_payload([$this->fila_de_pago(['cheque_id' => $ajeno->id])], $this->dueno->id),
            ChequeHelper::problemas_de_endoso_en_payload([$this->fila_de_pago(['cheque_id' => $inexistente])], $this->dueno->id)
        );
    }

    /**
     * Segunda vuelta, punto 6 — UNA sola lectura de un id. Convivían tres: cheque_id_de() con
     * is_numeric ('12.5' era el 12, '80e1' el 800, '+12' el 12), el (int) del provider_id de endosar
     * (true era el 1, [5] el 1, '5abc' el 5) y el where('id', $id) de banco_del_dueno() (MySQL
     * castea: GET cheque-banco/84abc daba el 84). Ahora todos pasan por ChequeHelper::id_del_pedido():
     * un entero mayor a 0, o un texto de solo dígitos; todo lo demás es "sin id".
     *
     * Este caso es el `cheque_id` (cuerpo y ruta). Los textos se arman con el id de un cheque PROPIO,
     * para que una lectura floja lo encontrara —y el pedido "anduviera"— en vez de caer en un id que
     * no existe. Los espacios no se prueban por el cuerpo: TrimStrings los saca antes del controller.
     *
     * @test
     */
    public function un_cheque_id_que_no_es_un_entero_no_es_ningun_cheque()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente lectura estricta ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'EST-' . substr(uniqid(), -6)]);

        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor lectura estricta ' . uniqid(), self::DEUDA_PROVEEDOR);

        $caja = $this->caja_propia_con_apertura();
        $id = $recibido->id;

        $foto = $recibido->fresh()->toArray();
        $movimientos_antes = $this->max_id_movimiento_caja();
        $max_current_acount = $this->max_id_de('current_acounts');

        $flojos = [
            'un decimal'          => $id . '.5',
            'un decimal redondo'  => $id . '.0',
            'un exponente'        => $id . 'e0',
            'un signo'            => '+' . $id,
            // Un número (no un texto) con decimales. Uno redondo como 12.0 no sirve: json_encode lo
            // manda como 12 y llega entero.
            'un número decimal'   => $id + 0.5,
        ];

        foreach ($flojos as $nombre => $flojo) {

            foreach ($this->pedidos_sobre_un_cheque($flojo, $caja->id) as $accion => $cuerpo) {

                $response = $this->putJson('api/cheque/' . $accion, $cuerpo);

                $this->assertSame(422, $response->getStatusCode(), $accion . ' con cheque_id ' . $nombre . ': ' . $this->resumen($response));
                $this->assertSame(self::MENSAJE_CHEQUE, $response->json('message'), $accion . ' con cheque_id ' . $nombre);
            }

            $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $flojo, 'provider_id' => $proveedor->id]);

            $this->assertSame(422, $response->getStatusCode(), 'endosar con cheque_id ' . $nombre . ': ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_CHEQUE_DE_ENDOSO . '.', $response->json('message'), 'endosar con cheque_id ' . $nombre);

            if (is_string($flojo)) {

                $response = $this->deleteJson('api/cheque/' . rawurlencode($flojo));

                $this->assertSame(404, $response->getStatusCode(), 'DELETE cheque/' . $flojo . ': ' . $this->resumen($response));
            }
        }

        // Y por la ruta, un espacio adelante (ahí no hay TrimStrings).
        $response = $this->deleteJson('api/cheque/%20' . $id);

        $this->assertSame(404, $response->getStatusCode(), 'DELETE cheque/" ' . $id . '": ' . $this->resumen($response));

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque ' . $id . ' no se tocó: ninguno de esos textos es él.');
        $this->assertSame(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Ninguna caja se movió.');
        $this->assertSame(0, CurrentAcount::where('id', '>', $max_current_acount)->count(), 'No se registró ningún pago.');
        $this->assertCount(0, $this->copias_de($recibido), 'No se endosó.');

        // Un texto de solo dígitos sí es el id (es como lo puede mandar un formulario).
        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => (string) $id, 'notas' => 'Texto de dígitos']);

        $this->assertSame(200, $response->getStatusCode(), 'rechazar con el id como texto de dígitos: ' . $this->resumen($response));
    }

    /**
     * Segunda vuelta, punto 6 — la ruta de un banco: ' 84', '84abc', '84.0' o '+84' no son el banco
     * 84. Hasta este arreglo banco_del_dueno() hacía where('id', $id) con el texto de la ruta y MySQL
     * lo casteaba: GET cheque-banco/84abc devolvía el banco 84 (propio, pero leído de un texto que no
     * es un id), mientras DELETE cheque/84abc ya daba 404.
     *
     * @test
     */
    public function la_ruta_de_un_banco_que_no_es_un_entero_no_es_ningun_banco()
    {
        $banco_id = (int) $this->postJson('api/cheque-banco', ['name' => 'Banco lectura estricta'])->json('model.id');

        $flojos = [
            'un espacio adelante' => '%20' . $banco_id,
            'letras atrás'        => $banco_id . 'abc',
            'un decimal redondo'  => $banco_id . '.0',
            'un signo'            => '%2B' . $banco_id,
        ];

        foreach ($flojos as $nombre => $flojo) {

            $response = $this->getJson('api/cheque-banco/' . $flojo);
            $this->assertSame(404, $response->getStatusCode(), 'GET cheque-banco con ' . $nombre . ': ' . $this->resumen($response));

            $response = $this->putJson('api/cheque-banco/' . $flojo, ['name' => 'Pisado por ' . $nombre]);
            $this->assertSame(404, $response->getStatusCode(), 'PUT cheque-banco con ' . $nombre . ': ' . $this->resumen($response));

            $response = $this->deleteJson('api/cheque-banco/' . $flojo);
            $this->assertSame(404, $response->getStatusCode(), 'DELETE cheque-banco con ' . $nombre . ': ' . $this->resumen($response));
        }

        $banco = ChequeBanco::find($banco_id);

        $this->assertNotNull($banco, 'El banco no se borró.');
        $this->assertSame('Banco lectura estricta', $banco->name, 'El banco no se renombró.');

        // Su id de verdad sigue andando.
        $this->getJson('api/cheque-banco/' . $banco_id)->assertStatus(200)->assertJsonPath('model.name', 'Banco lectura estricta');
    }

    /**
     * Segunda vuelta, punto 6 — el `provider_id` de endosar: true, [N], 'Nabc' y 'N.0' son "sin
     * proveedor" (el 422 de siempre, "Elegí el proveedor…"), no el proveedor 1 ni el N. Hasta este
     * arreglo era un (int) pelado: true y [N] eran el proveedor 1, y 'Nabc' el N.
     *
     * @test
     */
    public function un_provider_id_que_no_es_un_entero_es_sin_proveedor()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente proveedor flojo ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'PFL-' . substr(uniqid(), -6)]);

        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor flojo ' . uniqid(), self::DEUDA_PROVEEDOR);

        $foto = $recibido->fresh()->toArray();
        $foto_cuenta = $this->foto_de_cuenta($cuenta_proveedor);
        $max_current_acount = $this->max_id_de('current_acounts');
        $max_cheque = $this->max_id_de('cheques');

        $flojos = [
            'true'               => true,
            'un array'           => [$proveedor->id],
            'letras atrás'       => $proveedor->id . 'abc',
            'un decimal redondo' => $proveedor->id . '.0',
        ];

        foreach ($flojos as $nombre => $flojo) {

            $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $flojo]);

            $this->assertSame(422, $response->getStatusCode(), 'endosar con provider_id ' . $nombre . ': ' . $this->resumen($response));
            $this->assertSame('Elegí el proveedor al que le endosás el cheque.', $response->json('message'), 'endosar con provider_id ' . $nombre);
        }

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertCount(0, $this->copias_de($recibido));
        $this->assertSame(0, Cheque::where('id', '>', $max_cheque)->count(), 'No nació ningún cheque.');
        $this->assertSame(0, CurrentAcount::where('id', '>', $max_current_acount)->count(), 'No se registró ningún pago.');
        $this->assertEquals($foto_cuenta, $this->foto_de_cuenta($cuenta_proveedor));
    }

    /**
     * Segunda vuelta, punto 7. El 404 de un id de la ruta salía con el mensaje técnico de Laravel
     * ("No query results for model [App\Models\Cheque]...", que la SPA muestra tal cual en un aviso,
     * y con APP_DEBUG la traza entera). Ahora es un JsonResponse con un mensaje de comerciante, con
     * el MISMO cuerpo para un id ajeno, uno inexistente y uno que no es un id. JsonResponse y no
     * abort(): el ejecutor del asistente traduce un JsonResponse con estado >= 400 a un 422 con su
     * mensaje.
     *
     * @test
     */
    public function el_404_de_una_ruta_es_igual_para_un_id_ajeno_y_uno_inexistente()
    {
        $ajeno = $this->cheque_a_mano([
            'user_id' => $this->otro_dueno()->id,
            'numero'  => 'R404-' . substr(uniqid(), -6),
        ]);

        $foto_ajeno = $ajeno->fresh()->toArray();

        $nombre_banco_ajeno = 'Banco ajeno 404 ' . uniqid();
        $banco_ajeno = $this->banco_ajeno($nombre_banco_ajeno);

        // El cheque (DELETE cheque/{id}).
        $respuestas = [
            'ajeno'       => $this->deleteJson('api/cheque/' . $ajeno->id),
            'inexistente' => $this->deleteJson('api/cheque/' . $this->id_que_no_existe('cheques')),
            'no es un id' => $this->deleteJson('api/cheque/' . $ajeno->id . 'abc'),
        ];

        foreach ($respuestas as $nombre => $response) {

            $this->assertSame(404, $response->getStatusCode(), 'DELETE cheque, id ' . $nombre . ': ' . $this->resumen($response));
            $this->assertSame(['message' => self::MENSAJE_CHEQUE_404], $response->json(), 'DELETE cheque, id ' . $nombre);
        }

        $this->assertSame($respuestas['ajeno']->getContent(), $respuestas['inexistente']->getContent(), 'El cuerpo de un cheque ajeno y el de uno que no existe tienen que ser idénticos.');
        $this->assertEquals($foto_ajeno, $ajeno->fresh()->toArray(), 'El cheque ajeno no se tocó.');

        // El banco (show, update y destroy).
        $pedidos = [
            'GET'    => function ($id) { return $this->getJson('api/cheque-banco/' . $id); },
            'PUT'    => function ($id) { return $this->putJson('api/cheque-banco/' . $id, ['name' => 'Pisado']); },
            'DELETE' => function ($id) { return $this->deleteJson('api/cheque-banco/' . $id); },
        ];

        foreach ($pedidos as $metodo => $pedido) {

            $respuestas = [
                'ajeno'       => $pedido($banco_ajeno->id),
                'inexistente' => $pedido($this->id_que_no_existe('cheque_bancos')),
                'no es un id' => $pedido($banco_ajeno->id . 'abc'),
            ];

            foreach ($respuestas as $nombre => $response) {

                $this->assertSame(404, $response->getStatusCode(), $metodo . ' cheque-banco, id ' . $nombre . ': ' . $this->resumen($response));
                $this->assertSame(['message' => self::MENSAJE_BANCO_404], $response->json(), $metodo . ' cheque-banco, id ' . $nombre);
            }

            $this->assertSame($respuestas['ajeno']->getContent(), $respuestas['inexistente']->getContent(), $metodo . ': el cuerpo de un banco ajeno y el de uno que no existe tienen que ser idénticos.');
        }

        $this->assertSame($nombre_banco_ajeno, ChequeBanco::find($banco_ajeno->id)->name, 'El banco ajeno no se tocó.');
    }

    /**
     * Segunda vuelta, punto 8a: borrar un cheque PROPIO sigue andando — 200, la fila ya no está y
     * GET cheque no lo lista. La primera vuelta solo probaba los 404.
     *
     * @test
     */
    public function borrar_un_cheque_propio_lo_borra()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente borrar propio ' . uniqid());

        $propio = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => 'BORRAR-' . substr(uniqid(), -6)]);

        $this->assertNotNull($this->cheque_del_listado($propio->id), 'Antes de borrarlo, GET cheque lo lista.');

        $response = $this->deleteJson('api/cheque/' . $propio->id);

        $this->assertSame(200, $response->getStatusCode(), 'Borrar un cheque propio: ' . $this->resumen($response));
        $this->assertNull(Cheque::find($propio->id), 'La fila ya no está.');
        $this->assertNull($this->cheque_del_listado($propio->id), 'GET cheque ya no lo lista.');
    }

    /**
     * Segunda vuelta, punto 8b: un proveedor PROPIO borrado (soft delete) cuenta como "no existe"
     * para el endoso: 422 con el mensaje del proveedor y nada escrito. Antes de la primera vuelta era
     * un 500 que se revertía (el morphTo de la cuenta corriente no ve los borrados).
     *
     * @test
     */
    public function no_se_endosa_a_un_proveedor_propio_borrado()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente proveedor borrado ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'PBOR-' . substr(uniqid(), -6)]);

        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor borrado ' . uniqid(), self::DEUDA_PROVEEDOR);

        // Borrado como lo borra el sistema: SoftDeletes.
        $proveedor->delete();

        $this->assertNotNull(Provider::withTrashed()->find($proveedor->id), 'El proveedor quedó borrado lógicamente, no físicamente.');

        $foto = $recibido->fresh()->toArray();
        $foto_cuenta = $this->foto_de_cuenta($cuenta_proveedor);
        $max_cheque = $this->max_id_de('cheques');
        $max_current_acount = $this->max_id_de('current_acounts');

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);

        $this->assertSame(422, $response->getStatusCode(), 'Endosar a un proveedor propio borrado: ' . $this->resumen($response));
        $this->assertSame(self::MENSAJE_PROVEEDOR, $response->json('message'));

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertCount(0, $this->copias_de($recibido));
        $this->assertSame(0, Cheque::where('id', '>', $max_cheque)->count(), 'No nació ningún cheque.');
        $this->assertSame(0, CurrentAcount::where('id', '>', $max_current_acount)->count(), 'No se registró ningún pago.');
        $this->assertEquals($foto_cuenta, $this->foto_de_cuenta($cuenta_proveedor));
    }

    /**
     * Segunda vuelta, punto 8c: un EMPLEADO del dueño trabaja con lo de su dueño —cobra un cheque
     * propio en una caja propia (el movimiento entra en esa caja y `cobrado_por_id` es él), endosa
     * a un proveedor propio y borra un cheque propio— y con los ids del otro comercio recibe los
     * mismos 422/404 que el dueño. La tenencia es por cuenta, no por persona.
     *
     * @test
     */
    public function un_empleado_trabaja_con_lo_de_su_dueno_y_no_con_lo_ajeno()
    {
        // Lo propio lo arma el dueño, por los endpoints reales.
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente del empleado ' . uniqid());

        $a_cobrar = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'EMPC-' . substr(uniqid(), -6)]);
        $a_endosar = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'EMPE-' . substr(uniqid(), -6)]);
        $a_borrar = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'EMPB-' . substr(uniqid(), -6)]);
        $otro_propio = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'EMPO-' . substr(uniqid(), -6)]);

        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor del empleado ' . uniqid(), self::DEUDA_PROVEEDOR);

        $caja = $this->caja_propia_con_apertura();

        // Lo del otro comercio, a mano.
        $cheque_ajeno = $this->cheque_a_mano(['user_id' => $this->otro_dueno()->id, 'numero' => 'EMPAJ-' . substr(uniqid(), -6)]);
        $caja_ajena = $this->caja_ajena_con_apertura();

        list($proveedor_ajeno, $cuenta_ajena) = $this->proveedor_ajeno_con_cuenta();

        $nombre_banco_ajeno = 'Banco ajeno del empleado ' . uniqid();
        $banco_ajeno = $this->banco_ajeno($nombre_banco_ajeno);

        $foto_cheque_ajeno = $cheque_ajeno->fresh()->toArray();
        $foto_caja_ajena = $this->foto_de_caja($caja_ajena);
        $foto_cuenta_ajena = $this->foto_de_cuenta($cuenta_ajena);
        $foto_otro_propio = $otro_propio->fresh()->toArray();

        $empleado = $this->crear_usuario('Empleado de cheques', 'cheques-tenencia-empleado-', $this->dueno->id);

        $this->actuar_como($empleado);

        $movimientos_antes = $this->max_id_movimiento_caja();

        // Cobra un cheque propio en una caja propia.
        $response = $this->putJson('api/cheque/cobrar', ['cheque_id' => $a_cobrar->id, 'caja_id' => $caja->id]);

        $this->assertSame(200, $response->getStatusCode(), 'El empleado cobra un cheque propio: ' . $this->resumen($response));
        $this->assertSame('cobrado', $a_cobrar->fresh()->estado_manual);
        $this->assertSame($empleado->id, (int) $a_cobrar->fresh()->cobrado_por_id, 'cobrado_por_id es la persona que cobró, no el dueño.');

        $movimientos = MovimientoCaja::where('id', '>', $movimientos_antes)->get();

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $this->assertCount(1, $movimientos, 'El cobro deja un movimiento.');
        $this->assertSame($caja->id, (int) $movimientos[0]->caja_id, 'El movimiento entra en la caja propia.');
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $movimientos[0]->ingreso, self::DELTA);
        $this->assertSame($empleado->id, (int) $movimientos[0]->employee_id);

        // Endosa uno propio a un proveedor propio.
        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $a_endosar->id, 'provider_id' => $proveedor->id]);

        $this->assertSame(200, $response->getStatusCode(), 'El empleado endosa un cheque propio: ' . $this->resumen($response));
        $this->assertSame($proveedor->id, (int) $a_endosar->fresh()->endosado_a_provider_id);

        $copia = $this->copias_de($a_endosar)->first();

        $this->assertNotNull($copia);
        $this->cobros_cc_creados_por_escenarios[] = (int) $copia->current_acount_id;

        // Borra uno propio.
        $response = $this->deleteJson('api/cheque/' . $a_borrar->id);

        $this->assertSame(200, $response->getStatusCode(), 'El empleado borra un cheque propio: ' . $this->resumen($response));
        $this->assertNull(Cheque::find($a_borrar->id));

        // Con lo del otro comercio, los mismos 422/404 que el dueño.
        $movimientos_antes = $this->max_id_movimiento_caja();

        foreach ($this->pedidos_sobre_un_cheque($cheque_ajeno->id, $caja->id) as $accion => $cuerpo) {

            $response = $this->putJson('api/cheque/' . $accion, $cuerpo);

            $this->assertSame(422, $response->getStatusCode(), 'El empleado, ' . $accion . ' con el cheque de otro comercio: ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_CHEQUE, $response->json('message'));
        }

        $response = $this->putJson('api/cheque/cobrar', ['cheque_id' => $otro_propio->id, 'caja_id' => $caja_ajena->id]);

        $this->assertSame(422, $response->getStatusCode(), 'El empleado, cobrar en la caja de otro comercio: ' . $this->resumen($response));
        $this->assertSame(self::MENSAJE_CAJA, $response->json('message'));

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $otro_propio->id, 'provider_id' => $proveedor_ajeno->id]);

        $this->assertSame(422, $response->getStatusCode(), 'El empleado, endosar a un proveedor de otro comercio: ' . $this->resumen($response));
        $this->assertSame(self::MENSAJE_PROVEEDOR, $response->json('message'));

        $response = $this->deleteJson('api/cheque/' . $cheque_ajeno->id);

        $this->assertSame(404, $response->getStatusCode(), 'El empleado, borrar el cheque de otro comercio: ' . $this->resumen($response));
        $this->assertSame(['message' => self::MENSAJE_CHEQUE_404], $response->json());

        $response = $this->getJson('api/cheque-banco/' . $banco_ajeno->id);

        $this->assertSame(404, $response->getStatusCode(), 'El empleado, el banco de otro comercio: ' . $this->resumen($response));
        $this->assertStringNotContainsString($nombre_banco_ajeno, $response->getContent());

        $this->assertEquals($foto_cheque_ajeno, $cheque_ajeno->fresh()->toArray(), 'El cheque ajeno no se tocó.');
        $this->assertEquals($foto_otro_propio, $otro_propio->fresh()->toArray(), 'El cheque propio de los pedidos rechazados no se tocó.');
        $this->assertEquals($foto_caja_ajena, $this->foto_de_caja($caja_ajena), 'La caja ajena no se movió.');
        $this->assertEquals($foto_cuenta_ajena, $this->foto_de_cuenta($cuenta_ajena), 'La cuenta del proveedor ajeno no se movió.');
        $this->assertSame(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Ningún pedido rechazado movió una caja.');

        $this->actuar_como($this->dueno);
    }

    /**
     * Tercera vuelta, punto 1. La regla "un endoso va sin caja" de la prevalidación decidía "sin
     * caja" con is_numeric + (int), mientras el alta (PaymentMethodHelper, fuera de esta misión)
     * mueve caja si la fila trae `caja_id` y es `!= 0`: `true`, `0.5` y `"0.5"` pasaban la regla y el
     * alta sacaba la plata de la caja 1 (medido: egreso de 45.000), aunque fuera de otro comercio.
     * Ahora "sin caja" es una LISTA BLANCA en una sola función (ChequeHelper::es_sin_caja()): ausente,
     * null, '', el entero 0 o un texto de solo ceros. Todo lo demás es "con caja" y la fila de endoso
     * se rechaza con 422, sin escribir nada.
     *
     * @test
     */
    public function una_fila_de_endoso_con_algo_que_no_es_sin_caja_no_se_endosa()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente caja de endoso ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor caja de endoso ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'CAJE-' . substr(uniqid(), -6)]);

        $foto = $recibido->fresh()->toArray();
        $foto_cuenta = $this->foto_de_cuenta($cuenta_proveedor);
        $max_movimiento = $this->max_id_de('movimiento_cajas');
        $max_current_acount = $this->max_id_de('current_acounts');
        $max_expense = $this->max_id_de('expenses');
        $max_cheque = $this->max_id_de('cheques');

        $cajas_que_no_son_sin_caja = [
            'true'           => true,
            'un decimal'     => 0.5,
            'un texto 0.5'   => '0.5',
            'un texto'       => 'abc',
        ];

        foreach ($cajas_que_no_son_sin_caja as $nombre => $caja_id) {

            $fila_de_pago = $this->fila_de_pago(array_merge($this->claves_de_endoso($recibido), ['caja_id' => $caja_id]));

            $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila_de_pago]));

            $this->assertSame(422, $response->getStatusCode(), 'Endoso por el pago con caja_id ' . $nombre . ': ' . $this->resumen($response));
            $this->assertStringContainsString('se endosa sin caja', (string) $response->json('message'), 'Endoso por el pago con caja_id ' . $nombre);

            $fila_de_gasto = $this->fila_de_gasto(array_merge($this->claves_de_endoso($recibido), ['caja_id' => $caja_id]));

            $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila_de_gasto]));

            $this->assertSame(422, $response->getStatusCode(), 'Endoso por el gasto con caja_id ' . $nombre . ': ' . $this->resumen($response));
            $this->assertStringContainsString('se endosa sin caja', (string) $response->json('message'), 'Endoso por el gasto con caja_id ' . $nombre);
        }

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());
        $this->assertCount(0, $this->copias_de($recibido), 'No nació ninguna copia.');
        $this->assertSame(0, MovimientoCaja::where('id', '>', $max_movimiento)->count(), 'Ninguna caja se movió.');
        $this->assertSame(0, CurrentAcount::where('id', '>', $max_current_acount)->count(), 'No se registró ningún pago.');
        $this->assertSame(0, Expense::where('id', '>', $max_expense)->count(), 'No se registró ningún gasto.');
        $this->assertSame(0, Cheque::where('id', '>', $max_cheque)->count(), 'No nació ningún cheque.');
        $this->assertEquals($foto_cuenta, $this->foto_de_cuenta($cuenta_proveedor), 'La cuenta del proveedor no se movió.');
    }

    /**
     * Tercera vuelta, punto 1, el otro lado de la lista blanca: lo que SÍ es "sin caja" (0, '0',
     * null, '' y la clave ausente) sigue endosando, por el pago y por el gasto, sin mover ninguna
     * caja.
     *
     * @test
     */
    public function una_fila_de_endoso_sin_caja_sigue_endosando()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente sin caja de endoso ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor sin caja de endoso ' . uniqid(), self::DEUDA_PROVEEDOR * 10);

        $max_movimiento = $this->max_id_de('movimiento_cajas');

        $sin_caja = [
            'el entero 0' => 0,
            'el texto 0'  => '0',
            'null'        => null,
            'vacío'       => '',
            'ausente'     => self::CLAVE_AUSENTE,
        ];

        foreach ($sin_caja as $nombre => $caja_id) {

            foreach (['pago', 'gasto'] as $puerta) {

                $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'SCE-' . substr(uniqid(), -6)]);

                $fila = $puerta === 'pago'
                    ? $this->fila_de_pago($this->claves_de_endoso($recibido))
                    : $this->fila_de_gasto($this->claves_de_endoso($recibido));

                if ($caja_id === self::CLAVE_AUSENTE) {
                    unset($fila['caja_id']);
                } else {
                    $fila['caja_id'] = $caja_id;
                }

                $response = $puerta === 'pago'
                    ? $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]))
                    : $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

                $this->assertSame(201, $response->getStatusCode(), 'Endoso por el ' . $puerta . ' con caja_id ' . $nombre . ': ' . $this->resumen($response));

                if ($puerta === 'pago') {
                    $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');
                } else {
                    $this->gastos_creados_por_escenarios[] = (int) $response->json('model.id');
                }

                $this->assertFalse(ChequeHelper::en_cartera($recibido->fresh()), 'Por el ' . $puerta . ' con caja_id ' . $nombre . ': el cheque tenía que quedar endosado.');
                $this->assertCount(1, $this->copias_de($recibido), 'Por el ' . $puerta . ' con caja_id ' . $nombre . ': una copia.');
            }
        }

        $this->assertSame(0, MovimientoCaja::where('id', '>', $max_movimiento)->count(), 'Ningún endoso movió una caja.');
    }

    /**
     * Tercera vuelta, punto 2. El destino de un endoso se verificaba por el RÓTULO del pago
     * (`provider_id`) y no por adónde va la plata (`credit_account_id`): `POST current-acount/pago`
     * con un proveedor PROPIO en `model_id` y la cuenta corriente del proveedor de OTRO comercio en
     * `credit_account_id`, más una fila que endosa un recibido propio, daba 201 y la cuenta ajena
     * pasaba de 0 a -45.000. Ahora la cuenta tiene que ser una cuenta DE ese proveedor.
     *
     * La excepción sale de adentro del DB::transaction de pago(), que no la atrapa: se captura con
     * withoutExceptionHandling() (así el test no depende de APP_DEBUG) y no queda nada escrito.
     *
     * @test
     */
    public function no_se_endosa_a_un_proveedor_propio_con_la_cuenta_de_otro()
    {
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor rótulo propio ' . uniqid(), self::DEUDA_PROVEEDOR);
        list($proveedor_ajeno, $cuenta_ajena) = $this->proveedor_ajeno_con_cuenta();

        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente cuenta ajena ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'ROT-' . substr(uniqid(), -6)]);

        $foto = $recibido->fresh()->toArray();
        $foto_cuenta_ajena = $this->foto_de_cuenta($cuenta_ajena);
        $foto_cuenta_propia = $this->foto_de_cuenta($cuenta_proveedor);
        $max_current_acount = $this->max_id_de('current_acounts');
        $max_cheque = $this->max_id_de('cheques');

        // El rótulo dice el proveedor propio; la plata iría a la cuenta del otro comercio.
        $payload = $this->payload_de_pago('provider', $proveedor->id, $cuenta_ajena, [$this->fila_de_pago($this->claves_de_endoso($recibido))]);

        list($excepcion, $response) = $this->pedido_que_tiene_que_cortar(function () use ($payload) {
            return $this->postJson('api/current-acount/pago', $payload);
        });

        $this->assertNotNull($excepcion, 'El endoso con la cuenta de otro comercio tenía que cortar con una excepción: ' . (is_null($response) ? '' : $this->resumen($response)));
        $this->assertSame(self::MENSAJE_PROVEEDOR, $excepcion->getMessage());

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());
        $this->assertCount(0, $this->copias_de($recibido), 'No nació ninguna copia.');
        $this->assertSame(0, Cheque::where('id', '>', $max_cheque)->count());
        $this->assertSame(0, CurrentAcount::where('id', '>', $max_current_acount)->count(), 'No quedó ningún movimiento de cuenta corriente: la excepción revierte el pago entero.');
        $this->assertEquals($foto_cuenta_ajena, $this->foto_de_cuenta($cuenta_ajena), 'La cuenta del otro comercio tiene el mismo saldo y los mismos movimientos.');
        $this->assertEquals($foto_cuenta_propia, $this->foto_de_cuenta($cuenta_proveedor), 'La del proveedor propio tampoco se movió.');
    }

    /**
     * Tercera vuelta, punto 3. El `current_acount_payment_method_id` de la fila se leía flojo en la
     * prevalidación (is_numeric + (int): "1.5" era el método 1, Cheque) y el alta lo busca crudo
     * (find("1.5") no encuentra nada) y saltea la fila: el pago quedaba registrado SIN métodos, con
     * el cheque en cartera y la cuenta del proveedor bajada (medido: 60.000 a 15.000). Ahora se lee
     * con id_del_pedido(): "1.5" no es ningún método, la fila "no es de tipo cheque" y es 422.
     *
     * @test
     */
    public function una_fila_de_endoso_con_un_metodo_que_no_es_un_id_no_se_endosa()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente método flojo ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor método flojo ' . uniqid(), self::DEUDA_PROVEEDOR * 10);

        $id_metodo = (int) $this->metodo_cheque->id;

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'MET-' . substr(uniqid(), -6)]);

        $foto = $recibido->fresh()->toArray();
        $foto_cuenta = $this->foto_de_cuenta($cuenta_proveedor);
        $max_current_acount = $this->max_id_de('current_acounts');

        $flojos = [
            'un texto decimal' => $id_metodo . '.5',
            'un número decimal' => $id_metodo + 0.5,
        ];

        foreach ($flojos as $nombre => $metodo) {

            $fila = $this->fila_de_pago(array_merge($this->claves_de_endoso($recibido), ['current_acount_payment_method_id' => $metodo]));

            $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

            $this->assertSame(422, $response->getStatusCode(), 'Endoso con current_acount_payment_method_id ' . $nombre . ': ' . $this->resumen($response));
            $this->assertStringContainsString('no es de tipo cheque', (string) $response->json('message'), 'Endoso con current_acount_payment_method_id ' . $nombre);
        }

        $this->assertEquals($foto, $recibido->fresh()->toArray(), 'El cheque sigue en cartera, sin marca.');
        $this->assertCount(0, $this->copias_de($recibido));
        $this->assertSame(0, CurrentAcount::where('id', '>', $max_current_acount)->count(), 'No se registró ningún pago sin métodos.');
        $this->assertEquals($foto_cuenta, $this->foto_de_cuenta($cuenta_proveedor), 'La cuenta del proveedor no bajó.');

        // El id del método como entero o como texto de dígitos sigue andando.
        foreach (['un texto de dígitos' => (string) $id_metodo, 'un entero' => $id_metodo] as $nombre => $metodo) {

            $otro = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'MOK-' . substr(uniqid(), -6)]);

            $fila = $this->fila_de_pago(array_merge($this->claves_de_endoso($otro), ['current_acount_payment_method_id' => $metodo]));

            $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

            $this->assertSame(201, $response->getStatusCode(), 'Endoso con current_acount_payment_method_id ' . $nombre . ': ' . $this->resumen($response));
            $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');

            $this->assertFalse(ChequeHelper::en_cartera($otro->fresh()), 'Con ' . $nombre . ' el cheque tenía que quedar endosado.');
        }
    }

    /**
     * Tercera vuelta, punto 4. id_del_pedido() decidía "solo dígitos" con ctype_digit, que depende
     * del locale: en el PHP de esta máquina (LC_CTYPE = Spanish_Argentina.1252) `"1\xB2"` (un 1 y
     * un "²") es "solo dígitos" y era el id 1; en el Linux de producción no. Medido: `PUT
     * cheque/rechazar` por formulario con `cheque_id="<id>\xB2"` rechazaba el cheque. Ahora es
     * preg_match('/\A[0-9]+\z/'): ASCII y con \z (un $ aceptaría un "\n" final).
     *
     * @test
     */
    public function la_lectura_de_un_id_no_depende_del_locale()
    {
        $this->assertSame(0, ChequeHelper::id_del_pedido("1\xB2"), 'Un 1 seguido de un "²" (Windows-1252) no es un id.');
        $this->assertSame(0, ChequeHelper::id_del_pedido("12\n"), 'Un salto de línea al final no es parte de un id.');
        $this->assertSame(0, ChequeHelper::id_del_pedido('١٢'), 'Los dígitos arábigo-índicos no son un id.');
        $this->assertSame(12, ChequeHelper::id_del_pedido('12'));
        $this->assertSame(12, ChequeHelper::id_del_pedido(12));

        // Por la puerta de verdad: un formulario (el byte no viaja en JSON).
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente locale ' . uniqid());

        $propio = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => 'LOC-' . substr(uniqid(), -6)]);

        $foto = $propio->fresh()->toArray();

        $response = $this->put('api/cheque/rechazar', ['cheque_id' => $propio->id . "\xB2", 'notas' => 'Por formulario']);

        $this->assertSame(422, $response->getStatusCode(), 'rechazar con cheque_id "' . $propio->id . '\xB2": ' . $this->resumen($response));
        $this->assertEquals($foto, $propio->fresh()->toArray(), 'El cheque no se rechazó.');
    }

    /**
     * Tercera vuelta, punto 5. Lo que la copia del endoso HEREDA del origen se leía con el scope de
     * SoftDeletes: si el cliente propio del recibido estaba borrado, la copia perdía su
     * `endosado_desde_client_id` (y al restaurar el cliente seguía sin él; antes de esta misión lo
     * conservaba). Borrado no es ajeno: lo heredado se lee con dueño INCLUYENDO los borrados.
     *
     * @test
     */
    public function el_endoso_conserva_el_cliente_propio_aunque_este_borrado()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente que se borra ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => 'CBOR-' . substr(uniqid(), -6)]);

        // El cliente se borra por el endpoint real (SoftDeletes).
        $this->deleteJson('api/client/' . $cliente->id)->assertStatus(200);

        $this->assertNull(Client::find($cliente->id), 'El cliente quedó borrado.');
        $this->assertNotNull(Client::withTrashed()->find($cliente->id), 'Borrado lógicamente, no físicamente.');

        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor cliente borrado ' . uniqid(), self::DEUDA_PROVEEDOR);

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);

        $this->assertSame(200, $response->getStatusCode(), 'Endosar el recibido de un cliente borrado: ' . $this->resumen($response));

        $copia = $this->copias_de($recibido)->first();

        $this->assertNotNull($copia);
        $this->cobros_cc_creados_por_escenarios[] = (int) $copia->current_acount_id;

        $this->assertSame($cliente->id, (int) $copia->endosado_desde_client_id, 'La copia conserva el cliente propio aunque esté borrado.');

        // Al restaurarlo, el listado lo vuelve a mostrar en la copia.
        Client::withTrashed()->where('id', $cliente->id)->restore();

        $this->assertSame($cliente->name, $this->cheque_del_listado($copia->id)['endosado_desde_client']['name']);
    }

    /**
     * Tercera vuelta, punto 6. id_del_dueno() con el dueño null armaba `where user_id is null` y
     * devolvía el id de una fila SIN dueño: fallaba abierta. Es latente (solo si
     * UserHelper::userId() diera null), pero es tenencia: sin dueño no hay nada de nadie.
     *
     * @test
     */
    public function la_tenencia_sin_dueno_no_encuentra_nada()
    {
        $sin_dueno = ChequeBanco::create(['name' => 'Banco sin dueño ' . uniqid(), 'user_id' => null]);

        $this->assertNull(ChequeHelper::id_del_dueno(ChequeBanco::class, $sin_dueno->id, null), 'Con el dueño null no se encuentra una fila sin dueño.');
        $this->assertNull(ChequeHelper::id_del_dueno(ChequeBanco::class, $sin_dueno->id, 0));
        $this->assertNull(ChequeHelper::id_del_dueno(ChequeBanco::class, $sin_dueno->id, ''));
    }

    /**
     * EL MECANISMO: toda ruta que llega a ChequeController o ChequeBancoController está declarada
     * en matriz_de_tenencia(), con cómo resuelve la tenencia de los ids que recibe y qué test lo
     * prueba. Una ruta nueva en esos controllers pone este test en rojo hasta que alguien la declare,
     * y para declararla tiene que pensar de quién son los ids que le llegan.
     *
     * Y la autenticación (segunda vuelta, punto 8d), MEDIDA y no leída (tercera vuelta, punto 8):
     * toda ruta de la matriz, salvo las declaradas SIN AUTH con la referencia al hallazgo (hoy solo
     * el Excel de routes/web.php) y las de método inexistente, recibe un pedido SIN sesión con ids de
     * relleno y tiene que contestar 401. Hasta la tercera vuelta se miraba `gatherMiddleware()`, que
     * no descuenta un `->withoutMiddleware('auth:sanctum')`: con eso puesto en `cheque/cobrar` la
     * matriz seguía verde y el cobro sin sesión cobraba el cheque del dueño `USER_ID`. Para las SIN
     * AUTH sigue la declaración: si una pasa a tener `auth:sanctum`, el test pide sacarle la marca.
     *
     * @test
     */
    public function toda_ruta_de_cheques_esta_en_la_matriz_de_tenencia()
    {
        $en_el_router = [];
        $rutas = [];

        foreach (Route::getRoutes() as $route) {

            $accion = $route->getActionName();

            if (!preg_match('/(^|\\\\)(ChequeController|ChequeBancoController)@\w+$/', $accion)) {

                continue;
            }

            $metodos = array_values(array_diff($route->methods(), ['HEAD']));
            $clave = implode('|', $metodos) . ' ' . $route->uri();

            $en_el_router[$clave] = $accion;
            $rutas[$clave] = $route;
        }

        $matriz = $this->matriz_de_tenencia();

        $sobran = array_values(array_diff(array_keys($en_el_router), array_keys($matriz)));
        $faltan = array_values(array_diff(array_keys($matriz), array_keys($en_el_router)));

        $this->assertSame([], $sobran, 'Rutas de cheques que el router tiene y la matriz de tenencia NO declara: ' . implode(', ', $sobran) . '. Declaralas en matriz_de_tenencia() diciendo de quién son los ids que reciben y cómo se resuelven (y probalo).');
        $this->assertSame([], $faltan, 'Rutas declaradas en la matriz de tenencia que ya no existen en el router: ' . implode(', ', $faltan) . '. Sacalas de la matriz.');

        // Los pedidos de abajo van SIN sesión: se olvidan los guards (el de sanctum cachea al dueño
        // del setUp) y no se loguea a nadie.
        Auth::forgetGuards();

        foreach ($matriz as $clave => $fila) {

            $this->assertSame($fila['accion'], $en_el_router[$clave], $clave . ' apunta a otro método que el que declara la matriz: revisá su tenencia.');

            list($clase, $metodo) = explode('@', $fila['accion']);

            if (isset($fila['sin_auth'])) {

                $this->assertNotSame('', trim($fila['sin_auth']), $clave . ': una ruta SIN AUTH tiene que decir a qué hallazgo responde.');
                $this->assertFalse(in_array('auth:sanctum', $rutas[$clave]->gatherMiddleware(), true), $clave . ' está declarada SIN AUTH en la matriz y ahora tiene auth:sanctum: sacale "sin_auth" a la matriz (y cerrá el hallazgo).');
                $this->assertTrue(method_exists($clase, $metodo), $clave . ': ' . $fila['accion'] . ' no existe.');
                $this->assertNotSame('', trim($fila['prueba']), $clave . ': falta decir qué test prueba su tenencia.');

                continue;
            }

            if ($fila['tenencia'] === self::METODO_INEXISTENTE) {

                $this->assertFalse(method_exists($clase, $metodo), $clave . ': la matriz dice que ' . $fila['accion'] . ' no existe, y ahora existe. Declarale la tenencia.');

                continue;
            }

            $this->assertTrue(method_exists($clase, $metodo), $clave . ': ' . $fila['accion'] . ' no existe.');
            $this->assertNotSame('', trim($fila['prueba']), $clave . ': falta decir qué test prueba su tenencia.');

            $response = $this->pedido_sin_sesion($rutas[$clave]);

            $this->assertSame(401, $response->getStatusCode(), $clave . ' sin sesión tenía que contestar 401 y contestó ' . $this->resumen($response) . '. Si la ruta perdió la autenticación (por ejemplo, con un withoutMiddleware), devolvésela; si es a propósito, declarala SIN AUTH con la referencia al hallazgo.');
        }

        $this->actuar_como($this->dueno);
    }

    /**
     * Un pedido a la ruta SIN sesión (los guards ya olvidados por el llamador), con ids de relleno
     * en la URI y en el cuerpo. Si la autenticación anda, no llega al controller.
     *
     * @param \Illuminate\Routing\Route $route
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedido_sin_sesion($route)
    {
        $metodos = array_values(array_diff($route->methods(), ['HEAD']));

        $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());

        return $this->json($metodos[0], $uri, [
            'cheque_id'   => 1,
            'caja_id'     => 0,
            'provider_id' => 1,
            'name'        => 'Relleno sin sesión',
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // La matriz
    // ---------------------------------------------------------------------------------------------

    /**
     * Las rutas de ChequeController y ChequeBancoController, por "MÉTODOS uri" (sin HEAD), con la
     * acción a la que llegan, cómo se resuelve la tenencia de lo que reciben y qué test lo prueba.
     *
     * `sin_auth` va solo en una ruta sin `auth:sanctum`, con la referencia al hallazgo.
     *
     * @return array<string, array{accion: string, tenencia: string, prueba: string, sin_auth?: string}>
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
                'tenencia' => 'ruta: cheque_del_dueno($id) (id leído con ChequeHelper::id_del_pedido()) -> 404 JSON "El cheque no existe o no es de tu cuenta.", el mismo cuerpo para ajeno, inexistente y basura.',
                'prueba'   => 'Tenencia_de_cheques_Test::cobrar_pagar_rechazar_y_borrar_un_cheque_ajeno_no_lo_toca',
            ],

            // --- ChequeController (routes/web.php: SIN middleware de autenticación) --------------

            'GET cheque/excel/export' => [
                'accion'   => $cheque . 'excel_export',
                'tenencia' => 'query: cheque_ids (leídos con ChequeHelper::id_del_pedido()), filtrados por user_id = userId() en get_cheques_for_excel_export().',
                'sin_auth' => 'HALLAZGO de la misión cheques-filtro-por-dueno (3/10/2026): es una ruta de routes/web.php sin middleware de autenticación, y sin sesión userId() cae en config(app.USER_ID). Es de todas las exportaciones de web.php, no de este controller, y tiene su propia tarea.',
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
                'tenencia' => 'ruta: banco_del_dueno() (id leído con ChequeHelper::id_del_pedido()) -> 404 JSON "El banco no existe o no es de tu cuenta.".',
                'prueba'   => 'Tenencia_de_cheques_Test::un_dueno_no_ve_el_banco_de_otro y ::el_404_de_una_ruta_es_igual_para_un_id_ajeno_y_uno_inexistente',
            ],
            'PUT|PATCH api/cheque-banco/{cheque_banco}' => [
                'accion'   => $banco . 'update',
                'tenencia' => 'ruta: banco_del_dueno() (id leído con ChequeHelper::id_del_pedido()) -> 404 JSON "El banco no existe o no es de tu cuenta.".',
                'prueba'   => 'Bancos_de_cheques_Test::el_abm_del_catalogo_es_por_dueno; Tenencia_de_cheques_Test::el_404_de_una_ruta_es_igual_para_un_id_ajeno_y_uno_inexistente',
            ],
            'DELETE api/cheque-banco/{cheque_banco}' => [
                'accion'   => $banco . 'destroy',
                'tenencia' => 'ruta: banco_del_dueno() (id leído con ChequeHelper::id_del_pedido()) -> 404 JSON "El banco no existe o no es de tu cuenta."; desasocia solo los cheques del dueño.',
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
     * El mensaje con el que ChequeHelper::crear_cheque() corta una fila que pide endosar ese cheque.
     * El modelo destino es un pago sin guardar: la búsqueda del cheque corta antes de usarlo.
     *
     * @param mixed $cheque_id
     * @return string
     */
    protected function mensaje_de_crear_cheque($cheque_id)
    {
        try {
            ChequeHelper::crear_cheque(new CurrentAcount(), $this->fila_de_pago(['cheque_id' => $cheque_id]));
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('crear_cheque() tenía que cortar con una excepción para el cheque_id ' . json_encode($cheque_id) . '.');
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
     * La tabla de cada columna `*_id` de `cheques` (menos `caja_id`), para el test-mecanismo: de
     * cada una se crea una fila de OTRO comercio y su id va en la clave de la fila. Si el esquema
     * suma una columna que no está acá, el test-mecanismo falla y pide declararla.
     *
     * @return array<string, string>
     */
    protected function tabla_de_cada_columna_id()
    {
        return [
            'cheque_banco_id'          => 'cheque_bancos',
            'client_id'                => 'clients',
            'provider_id'              => 'providers',
            'current_acount_id'        => 'current_acounts',
            'expense_id'               => 'expenses',
            'employee_id'              => 'users',
            'user_id'                  => 'users',
            'endosado_a_provider_id'   => 'providers',
            'endosado_en_expense_id'   => 'expenses',
            'cobrado_por_id'           => 'users',
            'rechazado_por_id'         => 'users',
            'endosado_desde_client_id' => 'clients',
            'endosado_desde_cheque_id' => 'cheques',
        ];
    }

    /**
     * El id de una fila de OTRO comercio en cada tabla del mapa (lo mínimo, insertado a mano):
     * existe, pero no es de esta cuenta.
     *
     * @return array<string, int>
     */
    protected function un_id_ajeno_de_cada_tabla()
    {
        $otro = $this->otro_dueno();

        list($proveedor_ajeno, $cuenta_ajena) = $this->proveedor_ajeno_con_cuenta();

        $gasto_ajeno = Expense::create([
            'num'          => 1,
            'amount'       => 1,
            'moneda_id'    => 1,
            'observations' => 'Gasto de otro comercio (centinela)',
            'user_id'      => $otro->id,
            'caja_id'      => 0,
        ]);

        $this->gastos_creados_a_mano[] = $gasto_ajeno->id;

        // Va en la cuenta del proveedor ajeno: el tearDown borra los movimientos de esas cuentas.
        $movimiento_ajeno = CurrentAcount::create([
            'haber'             => 1,
            'status'            => 'pago_from_client',
            'detalle'           => 'Pago de otro comercio (centinela)',
            'user_id'           => $otro->id,
            'provider_id'       => $proveedor_ajeno->id,
            'credit_account_id' => $cuenta_ajena->id,
        ]);

        return [
            'cheque_bancos'   => $this->banco_ajeno('Banco ajeno centinela ' . uniqid())->id,
            'clients'         => $this->cliente_ajeno()->id,
            'providers'       => $proveedor_ajeno->id,
            'current_acounts' => $movimiento_ajeno->id,
            'expenses'        => $gasto_ajeno->id,
            'users'           => $otro->id,
            'cheques'         => $this->cheque_a_mano(['user_id' => $otro->id, 'numero' => 'CENAJ-' . substr(uniqid(), -6)])->id,
        ];
    }

    /**
     * Que ninguna columna del cheque valga lo que la fila traía en la clave del mismo nombre.
     *
     * @param array $valores Columna => valor que viajó en la fila.
     * @param Cheque|null $cheque El cheque nuevo o la copia del endoso.
     * @param string $puerta
     * @param string $variante
     * @return void
     */
    protected function assert_ningun_id_crudo(array $valores, $cheque, $puerta, $variante)
    {
        $this->assertNotNull($cheque, 'Por ' . $puerta . ' con ' . $variante . ': no se encontró el cheque.');

        $fila = (array) DB::table('cheques')->where('id', $cheque->id)->first();

        $crudas = [];

        foreach ($valores as $columna => $valor) {

            if (array_key_exists($columna, $fila) && !is_null($fila[$columna]) && (string) $fila[$columna] === (string) $valor) {

                $crudas[] = $columna;
            }
        }

        $this->assertSame([], $crudas, 'Por ' . $puerta . ', con ' . $variante . ' en la fila: estas columnas del cheque salieron de la fila sin pasar por un lector con dueño.');
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
     * Es la tabla ENTERA a propósito, y no solo lo que el test creó: la usa el caso de los ids
     * basura, donde lo que se quiere ver es que `true` no tocó el cheque 1 ni '12abc' el 12, que
     * pueden ser de cualquier dueño. Acotarla a los cheques del test dejaría afuera justo esos. En
     * una base de testing de slot la tabla es chica (arranca vacía y el rollback la deja así).
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
     * El id más alto de una tabla (con los borrados lógicos), para contar lo que se creó después.
     *
     * @param string $tabla
     * @return int
     */
    protected function max_id_de($tabla)
    {
        return (int) DB::table($tabla)->max('id');
    }

    /**
     * Corre un pedido SIN el manejador de excepciones de Laravel y devuelve la excepción con la que
     * cortó el controller (o null y la respuesta, si no cortó). Así un corte que hoy termina en 500
     * se mira por su excepción y no por el cuerpo del 500, que con APP_DEBUG=false dice solo
     * "Server Error".
     *
     * @param callable $pedido
     * @return array{0: \RuntimeException|null, 1: \Illuminate\Testing\TestResponse|null}
     */
    protected function pedido_que_tiene_que_cortar(callable $pedido)
    {
        $excepcion = null;
        $response = null;

        $this->withoutExceptionHandling();

        try {
            $response = $pedido();
        } catch (\RuntimeException $e) {
            $excepcion = $e;
        } finally {
            $this->withExceptionHandling();
        }

        return [$excepcion, $response];
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
