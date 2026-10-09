<?php

namespace Tests\Feature\Caja;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\ConceptoMovimientoCaja;
use App\Models\EtiquetaMedida;
use App\Models\MovimientoCaja;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión movimientos-caja-manuales (9/10/2026) — en Tesorería → Movimientos de una caja, un
 * movimiento MANUAL del turno abierto se corrige y se elimina; lo que generó el sistema no.
 *
 * Hasta esta misión `MovimientoCajaController` resolvía `index`, `update` y `destroy` por id sin
 * mirar el dueño, el turno ni el origen: el "Eliminar" de la fila de una venta borraba el
 * movimiento y la caja quedaba diciendo otra cosa que la venta; `update` guardaba y reventaba
 * (recalcular_saldos($model) deja `$apertura_caja` sin definir) sin tocar los totales del turno.
 *
 * Qué es "manual" (decisión de Lucas, 9/10/2026): lo nuevo, por la marca `manual` (solo el alta
 * desde Tesorería la pone en true); lo viejo (`manual` NULL), por concepto: "Varios" o uno creado
 * por el comercio, y sin venta ni gasto.
 *
 * Los saldos se comparan SIEMPRE con una foto de antes (patrón delta, como
 * Tesoreria/2_Saldos_Y_Reversion_Test): cada test arranca con un turno NUEVO de la caja de
 * efectivo, abierto por el endpoint real, para que el recálculo de la apertura parta de un saldo de
 * apertura coherente.
 *
 * Lo de OTRO dueño se crea a mano con su `user_id` (como Cheques/9_Tenencia_de_cheques_Test); lo
 * propio, por los endpoints reales. Las únicas filas de `movimiento_cajas` que se insertan a mano
 * son las que ningún endpoint de hoy produce (las viejas, con `manual` NULL, y las de otro
 * comercio), y eso se dice en cada caso.
 *
 * Los textos de los motivos están COPIADOS de la tabla del plan (la SPA los muestra tal cual), no
 * leídos del modelo: si alguien cambia uno, este test lo dice.
 *
 * @group caja
 */
class Movimientos_manuales_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta para comparar montos. */
    const DELTA = 0.01;

    const MOTIVO_APERTURA_CERRADA = 'No se pueden editar movimientos de una apertura ya cerrada.';
    const MOTIVO_VENTA = 'Este movimiento lo generó una VENTA: se corrige desde la venta, no desde la caja.';
    const MOTIVO_GASTO = 'Este movimiento lo generó un GASTO: se corrige desde el gasto, no desde la caja.';
    const MOTIVO_PAGO = 'Este movimiento lo generó un PAGO de cuenta corriente: se corrige desde la cuenta corriente, no desde la caja.';
    const MOTIVO_TRANSFERENCIA = 'Este movimiento es parte de una transferencia entre cajas: no se corrige desde la caja.';
    const MOTIVO_COMPENSACION = 'Este movimiento lo generó el sistema al eliminar una venta, un gasto o un pago con «Compensar caja»: no se corrige a mano.';
    const MOTIVO_PAGO_VENDEDOR = 'Este movimiento lo generó un pago de comisión a un vendedor: no se corrige desde la caja.';
    const MOTIVO_SISTEMA = 'Este movimiento lo generó el sistema: se corrige desde la operación que lo originó.';

    /** Los 404 (ajeno = inexistente = no es un id). */
    const MENSAJE_MOVIMIENTO_404 = 'No se encontró el movimiento de caja.';
    const MENSAJE_APERTURA_404 = 'No se encontró la apertura de caja.';

    /** Los 422 del cuerpo del alta. */
    const MENSAJE_CAJA_AJENA = 'La caja elegida no existe o no es de tu cuenta.';
    const MENSAJE_APERTURA_AJENA = 'La apertura elegida no existe o no es de esa caja.';

    /** Los 422 de los importes de una corrección. */
    const MENSAJE_SIN_IMPORTE = 'Cargá el importe del movimiento en Ingreso o en Egreso.';
    const MENSAJE_DOS_IMPORTES = 'Un movimiento es un ingreso o un egreso: cargá el importe en uno solo de los dos.';
    const MENSAJE_IMPORTE_NEGATIVO = 'El importe no puede ser negativo.';
    const MENSAJE_IMPORTE_NO_NUMERICO = 'El importe tiene que ser un número.';

    /**
     * Saldo con el que nace la caja del otro comercio. Distinto de cero a propósito: con 0, un
     * `(float) null` compararía igual y "el saldo no cambió" pasaría aunque se hubiera pisado.
     */
    const SALDO_CAJA_AJENA = 50000;

    /** @var User El dueño del fixture (la sesión de todos los pedidos). */
    protected $dueno;

    /** @var User|null El otro comercio: un dueño (sin owner_id) que vive en la misma base. */
    protected $otro_dueno = null;

    /** @var array<int, int> Usuarios creados a mano por este test. */
    protected $usuarios_creados = [];

    /** @var array<int, int> Cajas de otro comercio creadas a mano (con su apertura). */
    protected $cajas_creadas = [];

    /** @var array<int, int> Conceptos de movimiento de caja creados a mano. */
    protected $conceptos_creados = [];

    /** @var int Marca de agua de `movimiento_cajas`, para borrar en tearDown solo lo de este test. */
    protected $max_movimiento_caja_antes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->max_movimiento_caja_antes = $this->max_id_movimiento_caja();

        // El id de "Pago a Vendedor" y la columna `manual` se recuerdan por proceso: cada test arranca
        // sin lo que haya recordado el anterior.
        MovimientoCaja::olvidar_cache();

        /*
            Turno NUEVO para cada test: la caja de efectivo del fixture arranca cerrada y sin
            apertura en curso, y caja_efectivo_abierta() la abre por el endpoint real. Escritura
            directa a propósito (es infraestructura del test, como en 1_Empleado_De_Cierre_Test);
            resolver_caja_por_nombre() guarda el saldo para que limpiar_escenarios() lo restaure, y
            DatabaseTransactions revierte el resto.
        */
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->assertSame((int) $this->dueno->id, (int) $caja->user_id, 'La caja de efectivo del fixture tiene que ser del dueño.');

        Caja::where('id', $caja->id)->update([
            'abierta'                  => 0,
            'abierta_at'               => null,
            'cerrada_at'               => null,
            'current_apertura_caja_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        // El guard de sanctum cachea el usuario que resolvió: se olvida para no arrastrar nada.
        Auth::forgetGuards();

        $this->limpiar_escenarios();

        // Cinturón y tiradores sobre el rollback de DatabaseTransactions.
        MovimientoCaja::where('id', '>', $this->max_movimiento_caja_antes)->delete();

        if (count($this->cajas_creadas)) {
            AperturaCaja::whereIn('caja_id', $this->cajas_creadas)->delete();
            Caja::whereIn('id', $this->cajas_creadas)->delete();
        }

        if (count($this->conceptos_creados)) {
            ConceptoMovimientoCaja::whereIn('id', $this->conceptos_creados)->delete();
        }

        if (count($this->usuarios_creados)) {
            // El alta de un dueño siembra sus medidas de etiqueta (UserEtiquetaMedidaObserver).
            EtiquetaMedida::whereIn('user_id', $this->usuarios_creados)->delete();
            User::whereIn('id', $this->usuarios_creados)->delete();
        }

        MovimientoCaja::olvidar_cache();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Los casos
    // ---------------------------------------------------------------------------------------------

    /**
     * Pedido 1 de Lucas: un movimiento manual del turno abierto se CORRIGE, y con él se recalculan
     * el saldo de la caja, el de la fila (y el de las que vienen después) y los totales del turno.
     *
     * @test
     */
    public function un_movimiento_manual_se_corrige_y_la_caja_se_recalcula()
    {
        $caja = $this->caja_efectivo_abierta();

        $primero = $this->alta_manual($caja, 1000, null);
        $segundo = $this->alta_manual($caja, null, 300);

        $this->assertTrue($primero->manual, 'El alta desde Tesorería → Movimientos marca el movimiento como manual.');

        $antes = $this->foto($caja, [$primero, $segundo]);

        $response = $this->putJson('api/movimiento-caja/' . $primero->id, [
            'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
            'ingreso'                     => 1500,
            'egreso'                      => null,
            'notas'                       => 'Corregido por el test',
        ]);

        $this->assertSame(200, $response->getStatusCode(), 'PUT de un manual: ' . $this->resumen($response));
        $this->assertTrue($response->json('model.editable'));
        $this->assertNull($response->json('model.motivo_no_editable'));
        $this->assertEqualsWithDelta(1500, (float) $response->json('model.ingreso'), self::DELTA);
        $this->assertSame('Corregido por el test', $response->json('model.notas'));

        $despues = $this->foto($caja, [$primero, $segundo]);

        // +500: de 1000 a 1500.
        $this->assertEqualsWithDelta($antes['caja'] + 500, $despues['caja'], self::DELTA, 'El saldo de la caja se recalcula.');
        $this->assertEqualsWithDelta($antes['filas'][$primero->id] + 500, $despues['filas'][$primero->id], self::DELTA, 'El saldo de la fila corregida se recalcula.');
        $this->assertEqualsWithDelta($antes['filas'][$segundo->id] + 500, $despues['filas'][$segundo->id], self::DELTA, 'El saldo de la fila que viene después también.');
        $this->assertEqualsWithDelta($antes['total_ingresos'] + 500, $despues['total_ingresos'], self::DELTA, 'total_ingresos del turno se recalcula.');
        $this->assertEqualsWithDelta($antes['total_egresos'], $despues['total_egresos'], self::DELTA, 'total_egresos del turno no se mueve.');
    }

    /**
     * Pedido 1 de Lucas: un movimiento manual del turno abierto se ELIMINA, y la caja, las filas
     * que siguen y los totales del turno se recalculan.
     *
     * @test
     */
    public function un_movimiento_manual_se_elimina_y_la_caja_se_recalcula()
    {
        $caja = $this->caja_efectivo_abierta();

        $primero = $this->alta_manual($caja, 1000, null);
        $segundo = $this->alta_manual($caja, null, 300);

        $antes = $this->foto($caja, [$segundo]);

        $response = $this->deleteJson('api/movimiento-caja/' . $primero->id);

        $this->assertSame(200, $response->getStatusCode(), 'DELETE de un manual: ' . $this->resumen($response));
        $this->assertNull(MovimientoCaja::find($primero->id), 'El movimiento manual se eliminó.');

        $despues = $this->foto($caja, [$segundo]);

        $this->assertEqualsWithDelta($antes['caja'] - 1000, $despues['caja'], self::DELTA);
        $this->assertEqualsWithDelta($antes['filas'][$segundo->id] - 1000, $despues['filas'][$segundo->id], self::DELTA);
        $this->assertEqualsWithDelta($antes['total_ingresos'] - 1000, $despues['total_ingresos'], self::DELTA);
        $this->assertEqualsWithDelta($antes['total_egresos'], $despues['total_egresos'], self::DELTA);
    }

    /**
     * Un `ingreso` en 0 con un `egreso` cargado es un EGRESO. Sin la normalización, set_saldos() y
     * recalcular_saldos() miran primero el ingreso si no es null, contaban +0 y el egreso
     * desaparecía del saldo.
     *
     * @test
     */
    public function un_ingreso_en_cero_con_egreso_cuenta_como_egreso()
    {
        $caja = $this->caja_efectivo_abierta();

        $manual = $this->alta_manual($caja, 1000, null);

        $antes = $this->foto($caja, [$manual]);

        $response = $this->putJson('api/movimiento-caja/' . $manual->id, [
            'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
            'ingreso'                     => 0,
            'egreso'                      => 400,
            'notas'                       => 'Era un egreso',
        ]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));

        $fila = MovimientoCaja::find($manual->id);
        $this->assertNull($fila->ingreso, 'El ingreso en 0 se guarda como NULL.');
        $this->assertEqualsWithDelta(400, (float) $fila->egreso, self::DELTA);

        $despues = $this->foto($caja, [$manual]);

        // De +1000 a -400.
        $this->assertEqualsWithDelta($antes['caja'] - 1400, $despues['caja'], self::DELTA);
        $this->assertEqualsWithDelta($antes['filas'][$manual->id] - 1400, $despues['filas'][$manual->id], self::DELTA);
        $this->assertEqualsWithDelta($antes['total_ingresos'] - 1000, $despues['total_ingresos'], self::DELTA);
        $this->assertEqualsWithDelta($antes['total_egresos'] + 400, $despues['total_egresos'], self::DELTA);
    }

    /**
     * Una corrección con los dos importes, con ninguno, con un negativo o con algo que no es un
     * número es un 422 con el motivo, y no toca nada.
     *
     * @test
     */
    public function los_importes_que_no_cierran_son_422_y_no_tocan_nada()
    {
        $caja = $this->caja_efectivo_abierta();

        $manual = $this->alta_manual($caja, 1000, null);

        $casos = [
            'los dos importes'          => [['ingreso' => 500, 'egreso' => 200], self::MENSAJE_DOS_IMPORTES, 'importe'],
            'ningún importe'            => [['ingreso' => null, 'egreso' => ''], self::MENSAJE_SIN_IMPORTE, 'importe'],
            'los dos en cero'           => [['ingreso' => 0, 'egreso' => '0.00'], self::MENSAJE_SIN_IMPORTE, 'importe'],
            'un ingreso negativo'       => [['ingreso' => -50, 'egreso' => null], self::MENSAJE_IMPORTE_NEGATIVO, 'ingreso'],
            'un egreso negativo'        => [['ingreso' => null, 'egreso' => '-10'], self::MENSAJE_IMPORTE_NEGATIVO, 'egreso'],
            'un ingreso que es un texto' => [['ingreso' => 'mil', 'egreso' => null], self::MENSAJE_IMPORTE_NO_NUMERICO, 'ingreso'],
        ];

        foreach ($casos as $nombre => $caso) {

            list($importes, $motivo, $campo) = $caso;

            $foto = $manual->fresh();
            $saldo_caja = (float) Caja::find($caja->id)->saldo;

            $response = $this->putJson('api/movimiento-caja/' . $manual->id, array_merge([
                'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
                'notas'                       => 'No tiene que guardarse: ' . $nombre,
            ], $importes));

            $this->assert_rechazo($response, $motivo, $campo, $nombre);
            $this->assert_no_se_toco($foto, $caja, $saldo_caja, $nombre);
        }
    }

    /**
     * Lo que generó el sistema —una venta, un gasto, un pago de cliente, una transferencia entre
     * cajas, una compensación— no se corrige ni se elimina desde la caja: 422 con el motivo, la
     * fila sigue y el saldo no se movió.
     *
     * @test
     */
    public function lo_que_genero_el_sistema_no_se_corrige_ni_se_elimina_desde_la_caja()
    {
        $caja = $this->caja_efectivo_abierta();
        $efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        // Una venta cobrada en la caja (POST api/sale): el movimiento lleva `sale_id`.
        $venta = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 1000);
        $de_la_venta = MovimientoCaja::where('sale_id', $venta->id)->where('caja_id', $caja->id)->first();

        // Un gasto pagado con la caja (POST api/expense): el movimiento lleva `expense_id`.
        $this->avanzar_reloj_de_ventas();
        $gasto = $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 300, [
            'payment_methods' => [
                [
                    'current_acount_payment_method_id' => $efectivo->id,
                    'amount'                           => 300,
                    'caja_id'                          => $caja->id,
                ],
            ],
        ]);
        $del_gasto = MovimientoCaja::where('expense_id', $gasto->id)->where('caja_id', $caja->id)->first();

        // Un cobro de cuenta corriente (POST api/current-acount/pago): concepto 3 y SIN
        // current_acount_id (está comentado en CurrentAcountCajaHelper).
        $this->avanzar_reloj_de_ventas();
        $antes = $this->max_id_movimiento_caja();
        $this->cobrar_cuenta_corriente(TestingFerreteriaSeeder::CLIENTE_CC, TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 500);
        $del_pago = $this->nuevo_de_la_caja($antes, $caja, MovimientoCaja::CONCEPTO_PAGO_DE_CLIENTE);

        // Una transferencia hacia otra caja (POST api/movimiento-entre-caja): concepto 5, y el
        // movimiento_entre_caja_id se pierde en crear_movimiento().
        $this->avanzar_reloj_de_ventas();
        $antes = $this->max_id_movimiento_caja();
        $this->transferir_entre_cajas(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::CAJA_MP, 200);
        $de_la_transferencia = $this->nuevo_de_la_caja($antes, $caja, MovimientoCaja::CONCEPTO_MOVIMIENTO_ENTRE_CAJAS);

        // La compensación de una venta eliminada con «Compensar caja» (DELETE api/sale): concepto 7.
        $venta_a_borrar = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 700);
        $this->avanzar_reloj_de_ventas();
        $antes = $this->max_id_movimiento_caja();
        $response = $this->deleteJson('api/sale/' . $venta_a_borrar->id, ['compensar_caja' => 1]);
        $this->assertTrue($response->getStatusCode() < 300, 'DELETE api/sale: ' . $this->resumen($response));
        $this->registrar_movimientos_caja_nuevos($antes);
        $de_la_compensacion = $this->nuevo_de_la_caja($antes, $caja, MovimientoCaja::CONCEPTO_ELIMINACION_VENTA);

        $casos = [
            'la venta'         => [$de_la_venta, self::MOTIVO_VENTA],
            'el gasto'         => [$del_gasto, self::MOTIVO_GASTO],
            'el pago'          => [$del_pago, self::MOTIVO_PAGO],
            'la transferencia' => [$de_la_transferencia, self::MOTIVO_TRANSFERENCIA],
            'la compensación'  => [$de_la_compensacion, self::MOTIVO_COMPENSACION],
        ];

        foreach ($casos as $nombre => $caso) {

            list($movimiento, $motivo) = $caso;

            $this->assertNotNull($movimiento, 'No se generó el movimiento de ' . $nombre . ': el escenario no se armó.');
            $this->assertFalse($movimiento->fresh()->manual, 'El movimiento de ' . $nombre . ' nace con `manual` en false.');

            $foto = $movimiento->fresh();
            $saldo_caja = (float) Caja::find($caja->id)->saldo;

            $response = $this->putJson('api/movimiento-caja/' . $movimiento->id, [
                'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
                'ingreso'                     => 1,
                'egreso'                      => null,
                'notas'                       => 'Pisado desde la caja',
            ]);

            $this->assert_rechazo($response, $motivo, 'movimiento_caja', 'PUT de ' . $nombre);

            $response = $this->deleteJson('api/movimiento-caja/' . $movimiento->id);

            $this->assert_rechazo($response, $motivo, 'movimiento_caja', 'DELETE de ' . $nombre);
            $this->assert_no_se_toco($foto, $caja, $saldo_caja, $nombre);
        }
    }

    /**
     * Pedido 2 de Lucas: un movimiento VIEJO (`manual` NULL, anterior a la columna) con concepto
     * "Varios" se corrige como cualquier manual. Insertado a mano: ningún endpoint de hoy deja la
     * marca en NULL.
     *
     * @test
     */
    public function un_manual_viejo_se_corrige()
    {
        $caja = $this->caja_efectivo_abierta();

        $viejo = $this->movimiento_a_mano($caja, [
            'manual'                      => null,
            'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
            'ingreso'                     => 700,
        ]);

        $this->assertNull($viejo->manual, 'La fila simula un movimiento anterior a la columna.');

        $antes = $this->foto($caja, [$viejo]);

        $response = $this->putJson('api/movimiento-caja/' . $viejo->id, [
            'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
            'ingreso'                     => 900,
            'egreso'                      => null,
            'notas'                       => 'Viejo corregido',
        ]);

        $this->assertSame(200, $response->getStatusCode(), 'PUT de un manual viejo: ' . $this->resumen($response));
        $this->assertTrue($response->json('model.editable'));

        $despues = $this->foto($caja, [$viejo]);

        $this->assertEqualsWithDelta($antes['caja'] + 200, $despues['caja'], self::DELTA);
        $this->assertEqualsWithDelta($antes['filas'][$viejo->id] + 200, $despues['filas'][$viejo->id], self::DELTA);
        $this->assertEqualsWithDelta($antes['total_ingresos'] + 200, $despues['total_ingresos'], self::DELTA);
    }

    /**
     * Pedido 2 de Lucas, la tabla completa: lo nuevo se decide por la marca, lo viejo por concepto.
     * Se mira en el listado (`editable` / `motivo_no_editable`) y, para lo bloqueado, en el 422 del
     * PUT. Filas insertadas a mano: las viejas y las "del sistema sin concepto de sistema" no las
     * produce ningún endpoint de hoy.
     *
     * @test
     */
    public function lo_viejo_se_decide_por_concepto_y_lo_nuevo_por_la_marca()
    {
        $caja = $this->caja_efectivo_abierta();

        $del_comercio = $this->concepto_con_id_libre('Retiro del dueño ' . uniqid());
        $pago_a_vendedor = $this->concepto_pago_a_vendedor();

        $casos = [
            'viejo con "Varios"'                   => [['manual' => null, 'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS], null],
            'viejo con un concepto del comercio'   => [['manual' => null, 'concepto_movimiento_caja_id' => $del_comercio->id], null],
            'viejo con concepto Venta'             => [['manual' => null, 'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VENTA], self::MOTIVO_VENTA],
            'viejo con concepto Gasto'             => [['manual' => null, 'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_GASTO], self::MOTIVO_GASTO],
            'viejo con concepto Pago a Proveedor'  => [['manual' => null, 'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_PAGO_A_PROVEEDOR], self::MOTIVO_PAGO],
            'viejo con concepto de compensación'   => [['manual' => null, 'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_ELIMINACION_PAGO_CLIENTE], self::MOTIVO_COMPENSACION],
            'viejo con concepto Pago a Vendedor'   => [['manual' => null, 'concepto_movimiento_caja_id' => $pago_a_vendedor->id], self::MOTIVO_PAGO_VENDEDOR],
            'viejo con venta atada'                => [['manual' => null, 'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS, 'sale_id' => 2000000001], self::MOTIVO_VENTA],
            'nuevo del sistema con "Varios"'       => [['manual' => false, 'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS], self::MOTIVO_SISTEMA],
            'nuevo manual con concepto Venta'      => [['manual' => true, 'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VENTA], null],
        ];

        $filas = [];

        foreach ($casos as $nombre => $caso) {

            $filas[$nombre] = $this->movimiento_a_mano($caja, array_merge(['ingreso' => 100], $caso[0]));
        }

        $listado = $this->listado($caja->current_apertura_caja_id);

        foreach ($casos as $nombre => $caso) {

            $motivo = $caso[1];
            $fila = $listado[$filas[$nombre]->id];

            $this->assertSame(is_null($motivo), $fila['editable'], $nombre . ': `editable` en el listado.');
            $this->assertSame($motivo, $fila['motivo_no_editable'], $nombre . ': `motivo_no_editable` en el listado.');

            if (is_null($motivo)) {

                continue;
            }

            $foto = $filas[$nombre]->fresh();
            $saldo_caja = (float) Caja::find($caja->id)->saldo;

            $response = $this->putJson('api/movimiento-caja/' . $filas[$nombre]->id, [
                'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
                'ingreso'                     => 1,
                'egreso'                      => null,
                'notas'                       => 'Pisado',
            ]);

            $this->assert_rechazo($response, $motivo, 'movimiento_caja', $nombre);
            $this->assert_no_se_toco($foto, $caja, $saldo_caja, $nombre);
        }
    }

    /**
     * Un movimiento de una apertura ya cerrada no se toca, ni siendo manual: 422 en PUT y DELETE, y
     * el listado de esa apertura lo marca no editable con el motivo.
     *
     * @test
     */
    public function un_movimiento_de_una_apertura_cerrada_no_se_toca()
    {
        $caja = $this->caja_efectivo_abierta();

        $manual = $this->alta_manual($caja, 1000, null);
        $apertura_id = (int) $caja->fresh()->current_apertura_caja_id;

        $response = $this->putJson('api/cerrar-caja/' . $caja->id);
        $this->assertSame(200, $response->getStatusCode(), 'PUT cerrar-caja: ' . $this->resumen($response));

        $foto = $manual->fresh();
        $saldo_caja = (float) Caja::find($caja->id)->saldo;

        $response = $this->putJson('api/movimiento-caja/' . $manual->id, [
            'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
            'ingreso'                     => 2000,
            'egreso'                      => null,
            'notas'                       => 'Después del arqueo',
        ]);

        $this->assert_rechazo($response, self::MOTIVO_APERTURA_CERRADA, 'movimiento_caja', 'PUT con la apertura cerrada');

        $response = $this->deleteJson('api/movimiento-caja/' . $manual->id);

        $this->assert_rechazo($response, self::MOTIVO_APERTURA_CERRADA, 'movimiento_caja', 'DELETE con la apertura cerrada');
        $this->assert_no_se_toco($foto, $caja, $saldo_caja, 'apertura cerrada');

        $listado = $this->listado($apertura_id);

        $this->assertFalse($listado[$manual->id]['editable']);
        $this->assertSame(self::MOTIVO_APERTURA_CERRADA, $listado[$manual->id]['motivo_no_editable']);
    }

    /**
     * El movimiento y la apertura de OTRO dueño son 404 (PUT, DELETE y el listado), con el mismo
     * cuerpo que un id inexistente o que no es un id; y no se tocan.
     *
     * @test
     */
    public function el_movimiento_y_la_apertura_de_otro_dueno_son_404()
    {
        $caja_ajena = $this->caja_ajena_con_apertura();

        // Una fila MANUAL del otro comercio: si la tenencia no se mirara, se podría corregir y borrar.
        $ajeno = $this->movimiento_a_mano($caja_ajena, ['manual' => true, 'ingreso' => 800]);

        $foto = $ajeno->fresh();
        $saldo_ajena = (float) Caja::find($caja_ajena->id)->saldo;

        $inexistente = (int) MovimientoCaja::max('id') + 100000;

        foreach (['ajeno' => $ajeno->id, 'inexistente' => $inexistente, 'que no es un id' => $ajeno->id . 'abc'] as $nombre => $id) {

            $response = $this->putJson('api/movimiento-caja/' . $id, [
                'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
                'ingreso'                     => 1,
                'egreso'                      => null,
                'notas'                       => 'Pisado por otro comercio',
            ]);

            $this->assertSame(404, $response->getStatusCode(), 'PUT de un movimiento ' . $nombre . ': ' . $this->resumen($response));
            $this->assertSame(['message' => self::MENSAJE_MOVIMIENTO_404], $response->json(), 'PUT de un movimiento ' . $nombre);

            $response = $this->deleteJson('api/movimiento-caja/' . $id);

            $this->assertSame(404, $response->getStatusCode(), 'DELETE de un movimiento ' . $nombre . ': ' . $this->resumen($response));
            $this->assertSame(['message' => self::MENSAJE_MOVIMIENTO_404], $response->json(), 'DELETE de un movimiento ' . $nombre);
        }

        $apertura_ajena = (int) $caja_ajena->current_apertura_caja_id;
        $apertura_inexistente = (int) AperturaCaja::max('id') + 100000;

        foreach (['ajena' => $apertura_ajena, 'inexistente' => $apertura_inexistente, 'que no es un id' => $apertura_ajena . 'abc'] as $nombre => $id) {

            $response = $this->getJson('api/movimiento-caja/' . $id);

            $this->assertSame(404, $response->getStatusCode(), 'Listado de una apertura ' . $nombre . ': ' . $this->resumen($response));
            $this->assertSame(['message' => self::MENSAJE_APERTURA_404], $response->json(), 'Listado de una apertura ' . $nombre);
        }

        $this->assert_no_se_toco($foto, $caja_ajena, $saldo_ajena, 'movimiento de otro dueño');
    }

    /**
     * El alta en una caja de otro dueño (o inexistente) es 422 con `errors.caja_id`, y una apertura
     * que no es de la caja elegida es 422 con `errors.apertura_caja_id`. No se crea nada y ningún
     * saldo se mueve.
     *
     * @test
     */
    public function el_alta_en_una_caja_o_apertura_ajena_es_422()
    {
        $caja = $this->caja_efectivo_abierta();
        $caja_ajena = $this->caja_ajena_con_apertura();

        $saldo_propia = (float) Caja::find($caja->id)->saldo;
        $saldo_ajena = (float) Caja::find($caja_ajena->id)->saldo;
        $antes = $this->max_id_movimiento_caja();

        $casos = [
            'caja ajena'            => [['caja_id' => $caja_ajena->id, 'apertura_caja_id' => $caja_ajena->current_apertura_caja_id], self::MENSAJE_CAJA_AJENA, 'caja_id'],
            'caja inexistente'      => [['caja_id' => (int) Caja::max('id') + 100000, 'apertura_caja_id' => null], self::MENSAJE_CAJA_AJENA, 'caja_id'],
            'apertura de otra caja' => [['caja_id' => $caja->id, 'apertura_caja_id' => $caja_ajena->current_apertura_caja_id], self::MENSAJE_APERTURA_AJENA, 'apertura_caja_id'],
        ];

        foreach ($casos as $nombre => $caso) {

            list($destino, $motivo, $campo) = $caso;

            $response = $this->postJson('api/movimiento-caja', array_merge([
                'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
                'ingreso'                     => 999,
                'egreso'                      => null,
                'notas'                       => 'Alta que no tiene que entrar: ' . $nombre,
            ], $destino));

            $this->registrar_movimientos_caja_nuevos($antes);

            $this->assert_rechazo($response, $motivo, $campo, $nombre);
        }

        $this->assertSame($antes, $this->max_id_movimiento_caja(), 'No se creó ningún movimiento.');
        $this->assertEqualsWithDelta($saldo_propia, (float) Caja::find($caja->id)->saldo, self::DELTA, 'El saldo de la caja propia no se movió.');
        $this->assertEqualsWithDelta($saldo_ajena, (float) Caja::find($caja_ajena->id)->saldo, self::DELTA, 'El saldo de la caja ajena no se movió.');
    }

    /**
     * El listado de una apertura trae `editable` y `motivo_no_editable` en cada fila, sin perder
     * nada de lo que ya viajaba (los campos de siempre y la venta cargada).
     *
     * @test
     */
    public function el_listado_trae_editable_y_el_motivo_sin_perder_lo_de_siempre()
    {
        $caja = $this->caja_efectivo_abierta();

        $manual = $this->alta_manual($caja, 1000, null);

        $venta = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 500);
        $de_la_venta = MovimientoCaja::where('sale_id', $venta->id)->where('caja_id', $caja->id)->first();

        $listado = $this->listado($caja->fresh()->current_apertura_caja_id);

        $this->assertArrayHasKey($manual->id, $listado);
        $this->assertArrayHasKey($de_la_venta->id, $listado);

        foreach (['id', 'concepto_movimiento_caja_id', 'ingreso', 'egreso', 'saldo', 'notas', 'sale_id', 'expense_id', 'caja_id', 'apertura_caja_id', 'created_at', 'sale'] as $clave) {

            $this->assertArrayHasKey($clave, $listado[$manual->id], 'La fila sigue trayendo `' . $clave . '`.');
        }

        $this->assertTrue($listado[$manual->id]['editable']);
        $this->assertNull($listado[$manual->id]['motivo_no_editable']);

        $this->assertFalse($listado[$de_la_venta->id]['editable']);
        $this->assertSame(self::MOTIVO_VENTA, $listado[$de_la_venta->id]['motivo_no_editable']);
        $this->assertSame((int) $venta->id, (int) $listado[$de_la_venta->id]['sale']['id'], 'La venta sigue viniendo cargada.');
    }

    /**
     * El borrado masivo (`PUT delete/movimiento_caja`) respeta el 422 de un movimiento de una venta:
     * lo devuelve en `not_deleted` con el motivo y no en `models`, y la fila sigue. Uno manual se
     * borra como siempre. De a uno: con más de DeleteModelsHelper::BACKGROUND_THRESHOLD el pedido se
     * encola.
     *
     * @test
     */
    public function el_borrado_masivo_respeta_el_rechazo()
    {
        $caja = $this->caja_efectivo_abierta();

        $manual = $this->alta_manual($caja, 1000, null);

        $venta = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 500);
        $de_la_venta = MovimientoCaja::where('sale_id', $venta->id)->where('caja_id', $caja->id)->first();

        $response = $this->putJson('api/delete/movimiento_caja', ['models_id' => [$de_la_venta->id]]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame((int) $de_la_venta->id, (int) $response->json('not_deleted.0.id'));
        $this->assertSame(self::MOTIVO_VENTA, $response->json('not_deleted.0.message'));
        $this->assertSame([], $response->json('models'), 'El movimiento de la venta no vuelve como eliminado.');
        $this->assertNotNull(MovimientoCaja::find($de_la_venta->id), 'El movimiento de la venta sigue.');

        $response = $this->putJson('api/delete/movimiento_caja', ['models_id' => [$manual->id]]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame([], $response->json('not_deleted'));
        $this->assertSame((int) $manual->id, (int) $response->json('models.0.id'));
        $this->assertNull(MovimientoCaja::find($manual->id), 'El manual se borró.');
    }

    /**
     * Sin sesión, las cuatro rutas contestan 401 y no tocan nada.
     *
     * @test
     */
    public function sin_sesion_es_401()
    {
        $caja = $this->caja_efectivo_abierta();

        $manual = $this->alta_manual($caja, 1000, null);

        $foto = $manual->fresh();
        $saldo_caja = (float) Caja::find($caja->id)->saldo;
        $antes = $this->max_id_movimiento_caja();

        // Los pedidos de abajo van SIN sesión: se olvidan los guards (el de sanctum cachea al dueño
        // del setUp) y no se loguea a nadie.
        Auth::forgetGuards();

        $pedidos = [
            'GET listado' => $this->getJson('api/movimiento-caja/' . $caja->current_apertura_caja_id),
            'POST alta'   => $this->postJson('api/movimiento-caja', [
                'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
                'ingreso'                     => 10,
                'caja_id'                     => $caja->id,
            ]),
            'PUT'         => $this->putJson('api/movimiento-caja/' . $manual->id, ['ingreso' => 5]),
            'DELETE'      => $this->deleteJson('api/movimiento-caja/' . $manual->id),
        ];

        foreach ($pedidos as $nombre => $response) {

            $this->assertSame(401, $response->getStatusCode(), $nombre . ' sin sesión: ' . $this->resumen($response));
        }

        $this->assertSame($antes, $this->max_id_movimiento_caja(), 'Sin sesión no se creó nada.');
        $this->assert_no_se_toco($foto, $caja, $saldo_caja, 'sin sesión');
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudas
    // ---------------------------------------------------------------------------------------------

    /**
     * La caja de efectivo del fixture con un turno nuevo, abierto por el endpoint real.
     *
     * @return Caja
     */
    protected function caja_efectivo_abierta()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->asegurar_caja_abierta($caja);

        $caja = $caja->fresh();

        $this->assertNotNull($caja->current_apertura_caja_id, 'La caja de efectivo tiene que quedar con un turno abierto.');

        return $caja;
    }

    /**
     * El alta de un movimiento manual con el pedido que arma la SPA (los campos del formulario más
     * `caja_id` y `apertura_caja_id` de props_to_send_on_save). El reloj avanza 10 segundos antes,
     * para que el orden por `created_at` del recálculo sea el del alta.
     *
     * @param Caja $caja
     * @param float|null $ingreso
     * @param float|null $egreso
     * @return MovimientoCaja
     */
    protected function alta_manual($caja, $ingreso, $egreso)
    {
        $caja->refresh();

        $this->avanzar_reloj_de_ventas();

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/movimiento-caja', [
            'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
            'ingreso'                     => $ingreso,
            'egreso'                      => $egreso,
            'notas'                       => 'Alta manual de Movimientos_manuales_Test',
            'caja_id'                     => $caja->id,
            'apertura_caja_id'            => $caja->current_apertura_caja_id,
        ]);

        $this->registrar_movimientos_caja_nuevos($antes);

        $this->assertSame(201, $response->getStatusCode(), 'POST movimiento-caja: ' . $this->resumen($response));
        $this->assertTrue($response->json('model.editable'), 'El alta responde `editable` en true.');
        $this->assertNull($response->json('model.motivo_no_editable'), 'El alta responde `motivo_no_editable` en null.');

        return MovimientoCaja::find($response->json('model.id'));
    }

    /**
     * Un movimiento insertado a mano en el turno vigente de la caja, replicando lo que hace
     * MovimientoCajaHelper después de crear uno (el saldo de la fila y de la caja, y los totales del
     * turno). SOLO para lo que ningún endpoint de hoy produce: filas viejas (`manual` NULL), filas
     * "del sistema" sin concepto de sistema y filas de otro comercio.
     *
     * @param Caja $caja
     * @param array $campos
     * @return MovimientoCaja
     */
    protected function movimiento_a_mano($caja, array $campos)
    {
        $caja->refresh();

        $this->avanzar_reloj_de_ventas();

        $ingreso = array_key_exists('ingreso', $campos) ? $campos['ingreso'] : null;
        $egreso = array_key_exists('egreso', $campos) ? $campos['egreso'] : null;

        $saldo = (float) $caja->saldo + (float) $ingreso - (float) $egreso;

        $movimiento = MovimientoCaja::create(array_merge([
            'concepto_movimiento_caja_id' => MovimientoCaja::CONCEPTO_VARIOS,
            'ingreso'                     => null,
            'egreso'                      => null,
            'notas'                       => 'Fila insertada a mano por Movimientos_manuales_Test',
            'employee_id'                 => null,
            'apertura_caja_id'            => $caja->current_apertura_caja_id,
            'caja_id'                     => $caja->id,
            'saldo'                       => $saldo,
        ], $campos));

        Caja::where('id', $caja->id)->update(['saldo' => $saldo]);

        (new MovimientoCajaHelper())->set_apertura_caja_ingresos_egresos($caja->current_apertura_caja_id);

        $this->movimientos_caja_creados_por_escenarios[] = $movimiento->id;

        return $movimiento->fresh();
    }

    /**
     * El movimiento que generó una acción del trait en esta caja con ese concepto (por marca de
     * agua: no todos los caminos guardan el id del movimiento en la operación).
     *
     * @param int $antes
     * @param Caja $caja
     * @param int $concepto_id
     * @return MovimientoCaja|null
     */
    protected function nuevo_de_la_caja($antes, $caja, $concepto_id)
    {
        return MovimientoCaja::where('id', '>', $antes)
                            ->where('caja_id', $caja->id)
                            ->where('concepto_movimiento_caja_id', $concepto_id)
                            ->orderBy('id')
                            ->first();
    }

    /**
     * Foto de saldos: el de la caja, el de cada fila pedida y los totales del turno vigente.
     *
     * @param Caja $caja
     * @param MovimientoCaja[] $movimientos
     * @return array
     */
    protected function foto($caja, array $movimientos)
    {
        $caja = Caja::find($caja->id);
        $apertura = AperturaCaja::find($caja->current_apertura_caja_id);

        $filas = [];

        foreach ($movimientos as $movimiento) {

            $filas[$movimiento->id] = (float) MovimientoCaja::find($movimiento->id)->saldo;
        }

        return [
            'caja'           => (float) $caja->saldo,
            'filas'          => $filas,
            'total_ingresos' => (float) $apertura->total_ingresos,
            'total_egresos'  => (float) $apertura->total_egresos,
        ];
    }

    /**
     * El listado real de una apertura (`GET movimiento-caja/{apertura}`), por id de movimiento.
     *
     * @param int $apertura_caja_id
     * @return array<int, array>
     */
    protected function listado($apertura_caja_id)
    {
        $response = $this->getJson('api/movimiento-caja/' . $apertura_caja_id);

        $this->assertSame(200, $response->getStatusCode(), 'GET movimiento-caja/' . $apertura_caja_id . ': ' . $this->resumen($response));

        $por_id = [];

        foreach ($response->json('models') as $fila) {

            $por_id[(int) $fila['id']] = $fila;
        }

        return $por_id;
    }

    /**
     * Un 422 con la forma que muestra la SPA: `message` con el motivo y `errors` como OBJETO JSON
     * (campo → lista), no como lista.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @param string $motivo
     * @param string $campo
     * @param string $caso
     * @return void
     */
    protected function assert_rechazo($response, $motivo, $campo, $caso)
    {
        $this->assertSame(422, $response->getStatusCode(), $caso . ': ' . $this->resumen($response));

        // Sin el `true`: así un objeto JSON queda como stdClass y una lista como array.
        $cuerpo = json_decode($response->getContent());

        $this->assertSame($motivo, $cuerpo->message, $caso . ': el `message` es el motivo.');
        $this->assertInstanceOf(\stdClass::class, $cuerpo->errors, $caso . ': `errors` tiene que salir como OBJETO JSON (mapa campo → lista). Cuerpo: ' . $response->getContent());
        $this->assertTrue(property_exists($cuerpo->errors, $campo), $caso . ': `errors` trae la clave `' . $campo . '`. Cuerpo: ' . $response->getContent());
        $this->assertSame([$motivo], $cuerpo->errors->{$campo}, $caso . ': `errors.' . $campo . '` es la lista con el motivo.');
    }

    /**
     * La fila sigue, con sus importes y su saldo, y el saldo de la caja no se movió.
     *
     * @param MovimientoCaja $foto La fila tal como estaba antes del pedido.
     * @param Caja $caja
     * @param float $saldo_caja
     * @param string $caso
     * @return void
     */
    protected function assert_no_se_toco($foto, $caja, $saldo_caja, $caso)
    {
        $fila = MovimientoCaja::find($foto->id);

        $this->assertNotNull($fila, $caso . ': la fila sigue.');
        $this->assertSame($foto->ingreso, $fila->ingreso, $caso . ': el ingreso no cambió.');
        $this->assertSame($foto->egreso, $fila->egreso, $caso . ': el egreso no cambió.');
        $this->assertSame($foto->saldo, $fila->saldo, $caso . ': el saldo de la fila no cambió.');
        $this->assertSame($foto->notas, $fila->notas, $caso . ': las notas no cambiaron.');
        $this->assertEqualsWithDelta($saldo_caja, (float) Caja::find($caja->id)->saldo, self::DELTA, $caso . ': el saldo de la caja no se movió.');
    }

    /**
     * Un concepto de movimiento de caja con un id que no choca con los de sistema (1 a 10): la base
     * de testing no siembra `concepto_movimiento_cajas`, y un autoincremental desde 1 caería justo
     * en "Venta".
     *
     * @param string $nombre
     * @return ConceptoMovimientoCaja
     */
    protected function concepto_con_id_libre($nombre)
    {
        $id = max((int) ConceptoMovimientoCaja::max('id'), 10) + 1;

        $concepto = ConceptoMovimientoCaja::create(['id' => $id, 'name' => $nombre]);

        $this->conceptos_creados[] = $concepto->id;

        return $concepto;
    }

    /**
     * El concepto "Pago a Vendedor": el que haya, o uno nuevo con un id libre.
     *
     * @return ConceptoMovimientoCaja
     */
    protected function concepto_pago_a_vendedor()
    {
        $existente = ConceptoMovimientoCaja::where('name', MovimientoCaja::NOMBRE_CONCEPTO_PAGO_A_VENDEDOR)
                                            ->orderBy('id')
                                            ->first();

        $concepto = is_null($existente)
            ? $this->concepto_con_id_libre(MovimientoCaja::NOMBRE_CONCEPTO_PAGO_A_VENDEDOR)
            : $existente;

        MovimientoCaja::olvidar_cache();

        return $concepto;
    }

    /**
     * Una caja de OTRO comercio, abierta como se abre una caja de verdad (con su apertura y
     * marcada), insertada a mano.
     *
     * @return Caja
     */
    protected function caja_ajena_con_apertura()
    {
        $otro = $this->otro_dueno();

        $caja = Caja::create([
            'num'     => 1,
            'name'    => 'Caja ajena movimientos ' . uniqid(),
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
     * El otro comercio: un dueño sin owner_id, creado una vez por test.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {

            $this->otro_dueno = User::create([
                'name'     => 'Otro comercio movimientos de caja',
                'email'    => 'movimientos-caja-otro-' . uniqid() . '@test.local',
                'password' => Hash::make('secret'),
                'owner_id' => null,
            ]);

            $this->usuarios_creados[] = $this->otro_dueno->id;
        }

        return $this->otro_dueno;
    }

    /**
     * Estado y cuerpo de una respuesta, para los mensajes de las aserciones.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @return string
     */
    protected function resumen($response)
    {
        return $response->getStatusCode() . ' ' . substr((string) $response->getContent(), 0, 1500);
    }
}
