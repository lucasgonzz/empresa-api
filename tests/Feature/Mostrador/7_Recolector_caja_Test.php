<?php

namespace Tests\Feature\Mostrador;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExpenseConcept;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\UnidadFrecuencia;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Services\Mostrador\RecolectorCaja;
use App\Services\Mostrador\RecolectorCompras;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Misión mostrador-caja-vencimientos — §1.5: el recolector de "Caja y vencimientos".
 *
 * Sobre un comercio sembrado a mano (tres cajas en dos monedas, tareas de la Agenda con y sin
 * gasto, cheques emitidos y recibidos en todos sus estados, cuotas de planes de pago, clientes y
 * proveedores con deuda), cada número del JSON es el que se sembró. Las fechas se siembran
 * relativas a hoy porque el informe de caja habla siempre de hoy.
 *
 * Las filas van directo a sus tablas: lo que se prueba es el recolector, no el alta de la Agenda,
 * de los cheques ni de las cajas, que tienen sus propias suites.
 */
class Recolector_caja_Test extends MostradorTestCase
{
    /** @var Carbon Hoy a las 00:00 */
    protected $hoy;

    /** @var array Lo sembrado por sembrar_la_caja(), para que los asserts nombren ids reales */
    protected $s = [];

    protected function setUp(): void
    {
        parent::setUp();

        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto, crear un chat pega en Pusher.
        config(['broadcasting.default' => 'null']);

        $this->hoy = Carbon::today();
    }

    /**
     * Siembra el escenario completo del informe.
     *
     * @return void
     */
    protected function sembrar_la_caja()
    {
        // Cajas: Efectivo sin moneda (cuenta como pesos), Galicia en pesos con un cobro con tarjeta
        // que se liquida en 5 días, y una en dólares con su propio cobro a liquidar.
        $efectivo = $this->caja('Efectivo', null, true, [[900000, null]]);
        $galicia  = $this->caja('Banco Galicia', 1, false, [[500000, null], [null, 150000], [180000, null, 5]]);
        $dolares  = $this->caja('Dólares', 2, false, [[2000, null], [300, null, 3]]);

        $impuestos = $this->concepto('Impuestos');
        $alquiler  = $this->concepto('Alquiler');
        $sueldos   = $this->concepto('Sueldos');
        $seguros   = $this->concepto('Seguros');

        // Agenda CON gasto: los vencimientos.
        $autonomo = $this->tarea('Pagar autónomo', -5, $this->gasto($impuestos, 85000));

        // Mensual desde hace 20 días: esa ocurrencia está vencida, y la siguiente cae entre hoy+8 y
        // hoy+11 según el largo del mes (fuera de la semana y dentro de los 30 días, siempre).
        $sueldo = $this->tarea_recurrente('Sueldo de Juan', -20, 'month', $this->gasto($sueldos, 300000));

        $iibb = $this->tarea('Pagar IIBB', 3, $this->gasto($impuestos, 420000));

        // $ 1.500.000 no entraba en pendings.expense_amount decimal(8,2): tiene que viajar exacto.
        $alquiler_local = $this->tarea('Alquiler del local', 6, $this->gasto($alquiler, 1500000));

        $monotributo = $this->tarea('Pagar monotributo', 4, $this->gasto($impuestos, null));

        // Semanal desde hace 7 días con monto 0: vencida hace 7, y hoy, +7, +14, +21 y +28.
        $seguro = $this->tarea_recurrente('Seguro del local', -7, 'week', $this->gasto($seguros, 0));

        // Hecha: no es un vencimiento.
        $this->tarea('Pagar la luz', 2, $this->gasto($impuestos, 777000) + ['completado' => 1]);

        // Solo en el total a 30 días; la del día 31 ya no.
        $this->tarea('Aguinaldo', 30, $this->gasto($sueldos, 640000));
        $this->tarea('Patente', 31, $this->gasto($impuestos, 55000));

        // Agenda SIN gasto: la libreta.
        $presupuesto = $this->tarea('Hacerle el presupuesto a Pérez', -3);
        $reclamo     = $this->tarea('Reclamarle al proveedor', -10, ['notas' => 'El pedido vino incompleto']);
        $this->tarea('Ordenar el depósito', -2, ['completado' => 1]);
        $granero     = $this->tarea('Llamar al de la puerta granero', 0, ['notas' => 'pide kit completo']);
        $this->tarea('Pasar por el banco', 0, ['completado' => 1]);
        $this->tarea('Cambiar la vidriera', 1);

        // Clientes: las ventas sin cobrar y el último pago antes que las cuentas, como en el día.
        $perez = $this->cliente_con_telefono('Pérez');
        $norte = $this->cliente('Ferretería Norte');
        $lopez = $this->cliente('López');
        $gomez = $this->cliente('Gómez');

        $this->venta_sin_cobrar($perez, 10);
        $this->venta_sin_cobrar($perez, 20);
        $this->venta_sin_cobrar($perez, 30);
        $this->venta_sin_cobrar($lopez, 15);

        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $perez->id,
            'haber'      => 1000,
            'status'     => 'pago_from_client',
            'created_at' => $this->hoy->copy()->subDays(41)->setTime(12, 0),
        ]);

        $this->deuda('client', $perez->id, 412000);
        $this->deuda('client', $norte->id, 150000);
        $this->deuda('client', $lopez->id, 90000);
        $this->deuda('client', $gomez->id, 0);

        // Proveedores: dos en pesos y uno en dólares, que no suma.
        $sur         = $this->proveedor_nuevo('Distribuidora Sur');
        $acme        = $this->proveedor_nuevo('Acme');
        $importadora = $this->proveedor_nuevo('Importadora');

        $this->deuda('provider', $sur->id, 400000);
        $this->deuda('provider', $acme->id, 500000);
        $this->deuda('provider', $importadora->id, 9999, 2);

        // Cheques emitidos: a un proveedor en la semana, "emitido" sin proveedor (así queda grabado
        // el de una venta de mostrador sin cliente: no es una deuda), a 20 días y uno ya cobrado.
        $emitido = $this->cheque('emitido', 3, 300000, ['provider_id' => $sur->id, 'banco' => 'Galicia', 'numero' => '0034']);
        $this->cheque('emitido', 3, 999000, ['banco' => 'Macro', 'numero' => '7777']);
        $this->cheque('emitido', 20, 250000, ['provider_id' => $sur->id]);
        $this->cheque('emitido', 2, 111000, ['provider_id' => $sur->id, 'estado_manual' => 'cobrado']);

        // Cheques recibidos: tres para depositar (al que le queda 1 día, a 16 y el de hoy), uno de la
        // semana, y los que no entran: vencido, a 8 días, cobrado y endosado.
        $para_depositar = $this->cheque('recibido', -14, 150000, ['client_id' => $norte->id, 'banco' => 'Nación', 'numero' => '1182']);
        $por_vencer     = $this->cheque('recibido', -29, 50000, ['client_id' => $norte->id, 'banco' => 'Nación', 'numero' => '1180']);
        $de_hoy         = $this->cheque('recibido', 0, 10000, ['banco' => 'Provincia', 'numero' => '0001']);
        $proximo        = $this->cheque('recibido', 4, 150000, ['client_id' => $norte->id, 'banco' => 'Nación', 'numero' => '1183']);
        $this->cheque('recibido', -31, 70000, ['client_id' => $norte->id]);
        $this->cheque('recibido', 8, 80000, ['client_id' => $norte->id]);
        $this->cheque('recibido', -3, 60000, ['client_id' => $norte->id, 'estado_manual' => 'cobrado']);
        $this->cheque('recibido', -2, 40000, ['client_id' => $norte->id, 'endosado_a_provider_id' => $sur->id]);

        // Cuotas: de Pérez (que debe) una vencida, una próxima a medio pagar, una pagada y una de
        // hace 40 días; de Gómez (al día) una que quedó pendiente porque el pago no se imputó a ella.
        $cuota_vencida = $this->cuota($perez, -10, 50000);
        $cuota_proxima = $this->cuota($perez, 5, 50000, ['numero_cuota' => 2, 'amount_paid' => 20000]);
        $this->cuota($perez, -3, 50000, ['numero_cuota' => 3, 'estado' => 'pagado']);
        $this->cuota($perez, -40, 50000, ['numero_cuota' => 4]);
        $this->cuota($gomez, -10, 80000);

        $this->s = compact(
            'efectivo', 'galicia', 'dolares',
            'autonomo', 'sueldo', 'iibb', 'alquiler_local', 'monotributo', 'seguro',
            'presupuesto', 'reclamo', 'granero',
            'perez', 'norte', 'lopez', 'sur', 'acme',
            'emitido', 'para_depositar', 'por_vencer', 'de_hoy', 'proximo',
            'cuota_vencida', 'cuota_proxima'
        );
    }

    /**
     * @group mostrador
     * @test
     */
    public function las_cajas_en_pesos_suman_al_disponible_y_la_de_dolares_solo_se_lista()
    {
        $this->sembrar_la_caja();

        $h = $this->recolectar();

        $this->assertTrue($h['aplica']);
        $this->assertSame($this->dia(0), $h['fecha']);
        $this->assertSame(RecolectorCaja::DIAS_SEMANA[$this->hoy->dayOfWeek], $h['dia_semana']);
        $this->assertSame(7, $h['horizonte_dias']);

        $this->assertSame([
            'disponible_pesos' => 1250000.0,
            'a_liquidar_pesos' => 180000.0,
            'por_caja'         => [
                ['caja_id' => $this->s['efectivo'], 'nombre' => 'Efectivo', 'moneda' => 'pesos', 'disponible' => 900000.0, 'a_liquidar' => 0.0, 'abierta' => true],
                ['caja_id' => $this->s['galicia'], 'nombre' => 'Banco Galicia', 'moneda' => 'pesos', 'disponible' => 350000.0, 'a_liquidar' => 180000.0, 'abierta' => false],
                ['caja_id' => $this->s['dolares'], 'nombre' => 'Dólares', 'moneda' => 'dolares', 'disponible' => 2000.0, 'a_liquidar' => 300.0, 'abierta' => false],
            ],
        ], $h['cajas']);

        // Con hasta 10 cajas, el disponible en pesos es la suma de las cajas en pesos del detalle...
        $suma_pesos = 0.0;

        foreach ($h['cajas']['por_caja'] as $caja) {
            if ($caja['moneda'] === 'pesos') {
                $suma_pesos += $caja['disponible'];
            }
        }

        $this->assertSame($h['cajas']['disponible_pesos'], $suma_pesos);

        // ...y el mismo número que el informe de compras le muestra al dueño como saldo de cajas.
        $compras = (new RecolectorCompras())->recolectar($this->comercio, $this->hoy);
        $this->assertSame($compras['contexto_financiero']['saldo_cajas'], $h['cajas']['disponible_pesos']);

        // La plata en tránsito es la de la caja en pesos: los 300 dólares a liquidar no entran.
        $this->assertSame(180000.0, $h['a_cobrar']['liquidaciones_pendientes_pesos']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function lo_que_hay_que_pagar_vencido_y_de_la_semana_con_sus_totales()
    {
        $this->sembrar_la_caja();

        $p = $this->recolectar()['a_pagar'];

        // Lo más viejo primero. La luz está hecha y no aparece.
        $this->assertSame([
            ['origen' => 'agenda', 'pending_id' => $this->s['sueldo'], 'detalle' => 'Sueldo de Juan', 'concepto' => 'Sueldos', 'fecha' => $this->dia(-20), 'dias_vencido' => 20, 'monto' => 300000.0],
            ['origen' => 'agenda', 'pending_id' => $this->s['seguro'], 'detalle' => 'Seguro del local', 'concepto' => 'Seguros', 'fecha' => $this->dia(-7), 'dias_vencido' => 7, 'monto' => 0.0],
            ['origen' => 'agenda', 'pending_id' => $this->s['autonomo'], 'detalle' => 'Pagar autónomo', 'concepto' => 'Impuestos', 'fecha' => $this->dia(-5), 'dias_vencido' => 5, 'monto' => 85000.0],
        ], $p['vencidos']);

        // Por fecha y, el mismo día, el monto más grande primero: el IIBB antes que el cheque. Ni el
        // cheque "emitido" sin proveedor ni el cobrado.
        $this->assertSame([
            $this->proximo_de_agenda('seguro', 'Seguro del local', 'Seguros', 0, 0.0),
            $this->proximo_de_agenda('iibb', 'Pagar IIBB', 'Impuestos', 3, 420000.0),
            [
                'origen'           => 'cheque',
                'pending_id'       => null,
                'cheque_id'        => $this->s['emitido'],
                'detalle'          => 'Cheque emitido a Distribuidora Sur',
                'concepto'         => null,
                'proveedor'        => 'Distribuidora Sur',
                'banco'            => 'Galicia',
                'numero'           => '0034',
                'fecha'            => $this->dia(3),
                'dias_para_vencer' => 3,
                'monto'            => 300000.0,
            ],
            $this->proximo_de_agenda('monotributo', 'Pagar monotributo', 'Impuestos', 4, null),
            $this->proximo_de_agenda('alquiler_local', 'Alquiler del local', 'Alquiler', 6, 1500000.0),
            $this->proximo_de_agenda('seguro', 'Seguro del local', 'Seguros', 7, 0.0),
        ], $p['proximos']);

        // 300.000 + 0 + 85.000.
        $this->assertSame(385000.0, $p['total_vencidos']);

        // IIBB 420.000 + cheque 300.000 + alquiler 1.500.000.
        $this->assertSame(2220000.0, $p['total_proximos']);

        // La semana (2.220.000) + la próxima del sueldo (300.000) + el aguinaldo del día 30 (640.000)
        // + el cheque a 20 días (250.000). La patente del día 31 queda afuera.
        $this->assertSame(3410000.0, $p['total_proximos_30_dias']);

        // TAREAS sin monto: el monotributo (null) y el seguro (0). El seguro cuenta una sola vez
        // aunque tenga tres ocurrencias entre las vencidas y las próximas.
        $this->assertSame(2, $p['sin_monto']);

        $this->assertTrue($p['agenda_con_vencimientos']);
    }

    /**
     * agenda_con_vencimientos: alguna tarea con gasto recurrente o sin hacer. Una tarea con gasto
     * ya hecha y una sin gasto no alcanzan.
     *
     * @group mostrador
     * @test
     */
    public function sin_tareas_con_gasto_por_hacer_la_agenda_no_tiene_vencimientos_cargados()
    {
        $impuestos = $this->concepto('Impuestos');

        $this->caja('Efectivo', 1, true, [[1000, null]]);
        $this->tarea('Pagar la luz', -3, $this->gasto($impuestos, 5000) + ['completado' => 1]);
        $this->tarea('Llamar a Pérez', 1);

        $this->assertFalse($this->recolectar()['a_pagar']['agenda_con_vencimientos']);

        $this->tarea_recurrente('Pagar el alquiler', 10, 'month', $this->gasto($impuestos, 90000));

        $this->assertTrue($this->recolectar()['a_pagar']['agenda_con_vencimientos']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function los_cheques_recibidos_para_depositar_y_los_de_la_semana()
    {
        $this->sembrar_la_caja();

        $c = $this->recolectar()['a_cobrar'];

        // El que vence antes, primero. El de hoy ya se puede depositar y le quedan los 30 días.
        $this->assertSame([
            ['cheque_id' => $this->s['por_vencer'], 'cliente' => 'Ferretería Norte', 'banco' => 'Nación', 'numero' => '1180', 'monto' => 50000.0, 'fecha_pago' => $this->dia(-29), 'vence_en_dias' => 1],
            ['cheque_id' => $this->s['para_depositar'], 'cliente' => 'Ferretería Norte', 'banco' => 'Nación', 'numero' => '1182', 'monto' => 150000.0, 'fecha_pago' => $this->dia(-14), 'vence_en_dias' => 16],
            ['cheque_id' => $this->s['de_hoy'], 'cliente' => null, 'banco' => 'Provincia', 'numero' => '0001', 'monto' => 10000.0, 'fecha_pago' => $this->dia(0), 'vence_en_dias' => 30],
        ], $c['cheques_para_depositar']);

        $this->assertSame([
            ['cheque_id' => $this->s['proximo'], 'cliente' => 'Ferretería Norte', 'banco' => 'Nación', 'numero' => '1183', 'monto' => 150000.0, 'fecha_pago' => $this->dia(4), 'dias_para_cobrar' => 4],
        ], $c['cheques_proximos']);

        $this->assertSame(210000.0, $c['total_cheques_para_depositar']);
        $this->assertSame(150000.0, $c['total_cheques_proximos']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function las_cuotas_pendientes_solo_de_clientes_que_deben()
    {
        $this->sembrar_la_caja();

        $this->assertSame([
            'vencidas' => [
                ['cuota_id' => $this->s['cuota_vencida'], 'client_id' => $this->s['perez']->id, 'cliente' => 'Pérez', 'monto' => 50000.0, 'fecha_vencimiento' => $this->dia(-10), 'dias_vencida' => 10],
            ],
            'proximas' => [
                // Lo que falta pagar: 50.000 − 20.000.
                ['cuota_id' => $this->s['cuota_proxima'], 'client_id' => $this->s['perez']->id, 'cliente' => 'Pérez', 'monto' => 30000.0, 'fecha_vencimiento' => $this->dia(5), 'dias_para_vencer' => 5],
            ],
            'total_vencidas' => 50000.0,
            'total_proximas' => 30000.0,
        ], $this->recolectar()['a_cobrar']['cuotas']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function los_clientes_para_cobrar_los_proveedores_y_la_proyeccion_de_la_semana()
    {
        $this->sembrar_la_caja();

        $h = $this->recolectar();

        // Sin la extensión whatsapp, el recordatorio no está para nadie (ni para el que no tiene
        // ventas: ese corte va primero).
        $sin_modulo = ['disponible' => false, 'motivo' => 'sin_modulo_whatsapp', 'canal' => null];

        $this->assertSame([
            ['client_id' => $this->s['perez']->id, 'nombre' => 'Pérez', 'deuda' => 412000.0, 'dias_sin_pagar' => 41, 'ventas_sin_cobrar' => 3, 'recordatorio' => $sin_modulo],
            ['client_id' => $this->s['norte']->id, 'nombre' => 'Ferretería Norte', 'deuda' => 150000.0, 'dias_sin_pagar' => null, 'ventas_sin_cobrar' => 0, 'recordatorio' => $sin_modulo],
            ['client_id' => $this->s['lopez']->id, 'nombre' => 'López', 'deuda' => 90000.0, 'dias_sin_pagar' => null, 'ventas_sin_cobrar' => 1, 'recordatorio' => $sin_modulo],
        ], $h['a_cobrar']['clientes_para_cobrar']);

        $this->assertSame(652000.0, $h['a_cobrar']['deuda_clientes_total']);

        $this->assertSame([
            'deuda_total'   => 900000.0,
            'con_mas_deuda' => [
                ['provider_id' => $this->s['acme']->id, 'nombre' => 'Acme', 'deuda' => 500000.0],
                ['provider_id' => $this->s['sur']->id, 'nombre' => 'Distribuidora Sur', 'deuda' => 400000.0],
            ],
        ], $h['proveedores']);

        // Entra: los cheques recibidos (210.000 para depositar + 150.000 de la semana), no las deudas
        // ni las liquidaciones. Sale: vencidos (385.000) + próximos (2.220.000).
        // Queda: 1.250.000 + 360.000 − 2.605.000.
        $this->assertSame([
            'disponible_hoy' => 1250000.0,
            'entra'          => 360000.0,
            'sale'           => 2605000.0,
            'queda'          => -995000.0,
            'estado'         => 'no_alcanza',
        ], $h['proyeccion']);
    }

    /**
     * El recordatorio de cada cliente, con la misma regla que el modal y el envío masivo, en el
     * orden en que corta el RecordatorioCobroController.
     *
     * @group mostrador
     * @test
     */
    public function el_recordatorio_de_cada_cliente_sale_de_la_misma_regla_que_el_modal()
    {
        $perez = $this->cliente_con_telefono('Pérez', '5493416009988');
        $norte = $this->cliente_con_telefono('Ferretería Norte', '5493416001122');

        $this->venta_sin_cobrar($perez, 10);
        $this->venta_sin_cobrar($perez, 12);

        $this->deuda('client', $perez->id, 300000);
        $this->deuda('client', $norte->id, 200000);

        // 1. Sin la extensión whatsapp no hay botón que ofrecer.
        $this->assertSame([
            'Pérez'            => ['disponible' => false, 'motivo' => 'sin_modulo_whatsapp', 'canal' => null],
            'Ferretería Norte' => ['disponible' => false, 'motivo' => 'sin_modulo_whatsapp', 'canal' => null],
        ], $this->recordatorios());

        // 2. Con la extensión y sin configuración ACTIVA del bot: sin_configuracion. El que no tiene
        //    ventas sin cobrar corta antes (el preview del modal le responde 422 sin_ventas).
        $this->dar_extension(null, 'whatsapp');
        $this->config_del_bot(false);

        $this->assertSame([
            'Pérez'            => ['disponible' => false, 'motivo' => 'sin_configuracion', 'canal' => null],
            'Ferretería Norte' => ['disponible' => false, 'motivo' => 'sin_ventas', 'canal' => null],
        ], $this->recordatorios());

        // 3. Con la configuración activa y sin chat, la ventana está cerrada: hace falta la plantilla
        //    aprobada en Meta, y no la hay. El motivo es el del servicio, tal cual.
        WhatsappBotConfig::where('user_id', $this->comercio->id)->update(['is_active' => true]);

        $this->assertSame(
            ['disponible' => false, 'motivo' => 'plantilla_no_aprobada', 'canal' => null],
            $this->recordatorios()['Pérez']
        );

        // 4. Con la ventana de 24 h abierta sale por texto libre.
        $this->chat_abierto($perez);

        $this->assertSame([
            'Pérez'            => ['disponible' => true, 'motivo' => null, 'canal' => 'texto_libre'],
            'Ferretería Norte' => ['disponible' => false, 'motivo' => 'sin_ventas', 'canal' => null],
        ], $this->recordatorios());
    }

    /**
     * Las ventas sin cobrar se cuentan con el umbral que usa el modal cuando el dueño no filtró
     * Alertas → Cobros (el store de la SPA arranca con `dias` null y el preview no lo manda):
     * users.dias_alertar_administradores_ventas_no_cobradas. Con un umbral de 5 días, una venta de
     * hace 2 no es una venta sin cobrar para el modal, y el informe no puede ofrecer el botón.
     *
     * @group mostrador
     * @test
     */
    public function las_ventas_sin_cobrar_usan_el_umbral_del_dueno_como_el_modal_del_recordatorio()
    {
        $this->comercio->dias_alertar_administradores_ventas_no_cobradas = 5;
        $this->comercio->save();

        $perez = $this->cliente('Pérez');
        $nuevo = $this->cliente('Cliente nuevo');

        $this->venta_sin_cobrar($perez, 10);
        $this->venta_sin_cobrar($perez, 2);
        $this->venta_sin_cobrar($nuevo, 2);

        $this->deuda('client', $perez->id, 5000);
        $this->deuda('client', $nuevo->id, 3000);

        $this->dar_extension(null, 'whatsapp');

        $filas = $this->recolectar()['a_cobrar']['clientes_para_cobrar'];

        $this->assertSame([$perez->id, $nuevo->id], array_column($filas, 'client_id'));
        $this->assertSame([1, 0], array_column($filas, 'ventas_sin_cobrar'));
        $this->assertSame(['disponible' => false, 'motivo' => 'sin_ventas', 'canal' => null], $filas[1]['recordatorio']);
    }

    /**
     * Los cuatro estados de la proyección (y el borde: quedar justo con la quinta parte de lo que
     * sale ya alcanza).
     *
     * @return array [en caja, a pagar en la semana, cheque que entra, queda, estado]
     */
    public function estados_de_la_proyeccion()
    {
        return [
            'sin nada que pagar'             => [100000, 0, 0, 100000.0, 'sin_vencimientos'],
            'queda en negativo'              => [100000, 200000, 0, -100000.0, 'no_alcanza'],
            'queda menos de la quinta parte' => [110000, 100000, 0, 10000.0, 'ajustado'],
            'queda justo la quinta parte'    => [120000, 100000, 0, 20000.0, 'alcanza'],
            'el cheque que entra la salva'   => [90000, 100000, 50000, 40000.0, 'alcanza'],
        ];
    }

    /**
     * @group mostrador
     * @test
     * @dataProvider estados_de_la_proyeccion
     */
    public function la_proyeccion_de_la_semana_y_su_estado($en_caja, $a_pagar, $entra, $queda, $estado)
    {
        $this->caja('Efectivo', 1, true, [[$en_caja, null]]);

        if ($a_pagar > 0) {
            $this->tarea('Pagar IIBB', 2, $this->gasto($this->concepto('Impuestos'), $a_pagar));
        }

        if ($entra > 0) {
            $this->cheque('recibido', 1, $entra, ['banco' => 'Nación', 'numero' => '0001']);
        }

        $this->assertSame([
            'disponible_hoy' => (float) $en_caja,
            'entra'          => (float) $entra,
            'sale'           => (float) $a_pagar,
            'queda'          => $queda,
            'estado'         => $estado,
        ], $this->recolectar()['proyeccion']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function la_libreta_lista_las_tareas_sin_gasto_vencidas_y_de_hoy()
    {
        $this->sembrar_la_caja();

        // Lo más reciente primero. Las hechas (el depósito, el banco), la de mañana y las que tienen
        // gasto no están.
        $this->assertSame([
            'vencidas' => [
                ['pending_id' => $this->s['presupuesto'], 'detalle' => 'Hacerle el presupuesto a Pérez', 'notas' => null, 'fecha' => $this->dia(-3), 'dias_vencida' => 3],
                ['pending_id' => $this->s['reclamo'], 'detalle' => 'Reclamarle al proveedor', 'notas' => 'El pedido vino incompleto', 'fecha' => $this->dia(-10), 'dias_vencida' => 10],
            ],
            'hoy' => [
                ['pending_id' => $this->s['granero'], 'detalle' => 'Llamar al de la puerta granero', 'notas' => 'pide kit completo'],
            ],
            'vencidas_omitidas' => 0,
        ], $this->recolectar()['agenda']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function la_libreta_lista_diez_vencidas_y_cuenta_las_que_quedan_afuera()
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->tarea('Pendiente ' . $i, -$i);
        }

        $agenda = $this->recolectar()['agenda'];

        $this->assertCount(10, $agenda['vencidas']);
        $this->assertSame($this->dia(-1), $agenda['vencidas'][0]['fecha']);
        $this->assertSame($this->dia(-10), $agenda['vencidas'][9]['fecha']);
        $this->assertSame(2, $agenda['vencidas_omitidas']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function un_comercio_sin_cajas_deudas_cheques_ni_tareas_no_aplica()
    {
        $this->assertSame([
            'aplica' => false,
            'fecha'  => $this->dia(0),
            'motivo' => 'Sin cajas, deudas, cheques ni tareas en la agenda',
        ], $this->recolectar());

        // Un cheque "emitido" sin proveedor no es una deuda: tampoco hace que el informe aplique.
        $this->cheque('emitido', 2, 5000);

        $this->assertFalse($this->recolectar()['aplica']);

        // Con una sola tarea en la libreta ya aplica, con las secciones vacías y todas las claves.
        $this->tarea('Llamar a Pérez', 0);

        $h = $this->recolectar();

        $this->assertTrue($h['aplica']);

        foreach (['aplica', 'fecha', 'dia_semana', 'horizonte_dias', 'cajas', 'a_pagar', 'a_cobrar', 'proveedores', 'proyeccion', 'agenda'] as $clave) {
            $this->assertArrayHasKey($clave, $h);
        }

        $this->assertSame(['disponible_pesos' => 0.0, 'a_liquidar_pesos' => 0.0, 'por_caja' => []], $h['cajas']);
        $this->assertSame([], $h['a_pagar']['vencidos']);
        $this->assertSame([], $h['a_pagar']['proximos']);
        $this->assertSame(0, $h['a_pagar']['sin_monto']);
        $this->assertFalse($h['a_pagar']['agenda_con_vencimientos']);
        $this->assertSame([], $h['a_cobrar']['cheques_para_depositar']);
        $this->assertSame([], $h['a_cobrar']['clientes_para_cobrar']);
        $this->assertSame(['vencidas' => [], 'proximas' => [], 'total_vencidas' => 0.0, 'total_proximas' => 0.0], $h['a_cobrar']['cuotas']);
        $this->assertSame(['deuda_total' => 0.0, 'con_mas_deuda' => []], $h['proveedores']);
        $this->assertSame('sin_vencimientos', $h['proyeccion']['estado']);
        $this->assertCount(1, $h['agenda']['hoy']);
    }

    /**
     * Lectura pura: recolectar no deja filas en ninguna tabla, tampoco cuando el recordatorio
     * recorre buscar_chat() y motivo_de_salteo() con el módulo, la configuración y un chat.
     *
     * @group mostrador
     * @test
     */
    public function recolectar_no_escribe_en_ninguna_tabla()
    {
        $this->sembrar_la_caja();

        $this->dar_extension(null, 'whatsapp');
        $this->config_del_bot(true);
        $this->chat_abierto($this->s['perez']);

        $tablas = ['whatsapp_chats', 'whatsapp_chat_messages', 'pending_completeds', 'expenses', 'movimiento_cajas', 'mostrador_reportes'];
        $antes = [];

        foreach ($tablas as $tabla) {
            $antes[$tabla] = DB::table($tabla)->count();
        }

        $clientes = $this->recolectar()['a_cobrar']['clientes_para_cobrar'];

        // El recordatorio se consultó de punta a punta: Pérez por texto libre, Ferretería Norte sin
        // ventas y López (sin teléfono) con el motivo del servicio.
        $this->assertSame([
            ['disponible' => true, 'motivo' => null, 'canal' => 'texto_libre'],
            ['disponible' => false, 'motivo' => 'sin_ventas', 'canal' => null],
            ['disponible' => false, 'motivo' => 'sin_telefono', 'canal' => null],
        ], array_column($clientes, 'recordatorio'));

        foreach ($tablas as $tabla) {
            $this->assertSame($antes[$tabla], DB::table($tabla)->count(), 'recolectar() escribió en ' . $tabla);
        }
    }

    /**
     * @group mostrador
     * @test
     */
    public function los_datos_de_otro_dueno_no_aparecen()
    {
        $otro = User::create([
            'name'     => 'Otro dueño',
            'email'    => 'otro-caja-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $concepto_otro  = ExpenseConcept::create(['num' => 1, 'name' => 'Impuestos del otro', 'user_id' => $otro->id]);
        $cliente_otro   = Client::create(['name' => 'Cliente del otro', 'user_id' => $otro->id]);
        $proveedor_otro = Provider::create(['name' => 'Proveedor del otro', 'user_id' => $otro->id, 'status' => 'active']);

        $this->caja('Caja del otro', 1, true, [[5000000, null], [70000, null, 4]], $otro->id);
        $this->tarea('Vencimiento del otro', 2, $this->gasto($concepto_otro, 7777), $otro->id);
        $this->tarea('Vencida del otro', -2, $this->gasto($concepto_otro, 8888), $otro->id);
        $this->tarea('Libreta del otro', 0, [], $otro->id);
        $this->deuda('client', $cliente_otro->id, 123456, 1, $otro->id);
        $this->deuda('provider', $proveedor_otro->id, 654321, 1, $otro->id);
        $this->cheque('emitido', 3, 88000, ['provider_id' => $proveedor_otro->id], $otro->id);
        $this->cheque('recibido', -1, 99000, ['client_id' => $cliente_otro->id], $otro->id);
        $this->cuota($cliente_otro, -3, 11000);

        // Del comercio: una caja y un cheque recibido que, por un error de carga, apunta al cliente
        // del otro dueño.
        $this->caja('Efectivo', 1, true, [[1000, null]]);
        $cheque = $this->cheque('recibido', -1, 5000, ['client_id' => $cliente_otro->id, 'banco' => 'Nación', 'numero' => '0009']);

        $h = $this->recolectar();

        $this->assertSame(1000.0, $h['cajas']['disponible_pesos']);
        $this->assertSame(0.0, $h['cajas']['a_liquidar_pesos']);
        $this->assertCount(1, $h['cajas']['por_caja']);
        $this->assertSame([], $h['a_pagar']['vencidos']);
        $this->assertSame([], $h['a_pagar']['proximos']);
        $this->assertSame(0.0, $h['a_pagar']['total_proximos_30_dias']);
        $this->assertSame(0.0, $h['a_cobrar']['liquidaciones_pendientes_pesos']);
        $this->assertSame([], $h['a_cobrar']['cheques_proximos']);
        $this->assertSame([], $h['a_cobrar']['cuotas']['vencidas']);
        $this->assertSame([], $h['a_cobrar']['clientes_para_cobrar']);
        $this->assertSame(0.0, $h['a_cobrar']['deuda_clientes_total']);
        $this->assertSame(['deuda_total' => 0.0, 'con_mas_deuda' => []], $h['proveedores']);
        $this->assertSame(['vencidas' => [], 'hoy' => [], 'vencidas_omitidas' => 0], $h['agenda']);

        // El cheque propio está, pero sin el nombre del cliente ajeno.
        $this->assertSame([$cheque], array_column($h['a_cobrar']['cheques_para_depositar'], 'cheque_id'));
        $this->assertNull($h['a_cobrar']['cheques_para_depositar'][0]['cliente']);
        $this->assertSame(5000.0, $h['a_cobrar']['total_cheques_para_depositar']);
    }

    /**
     * Los hechos del comercio del test, hoy.
     *
     * @return array
     */
    protected function recolectar()
    {
        return (new RecolectorCaja())->recolectar($this->comercio, $this->hoy);
    }

    /**
     * El recordatorio de cada cliente para cobrar, por nombre.
     *
     * @return array
     */
    protected function recordatorios()
    {
        $mapa = [];

        foreach ($this->recolectar()['a_cobrar']['clientes_para_cobrar'] as $fila) {
            $mapa[$fila['nombre']] = $fila['recordatorio'];
        }

        return $mapa;
    }

    /**
     * Fecha relativa a hoy, en Y-m-d.
     *
     * @param int $dias
     * @return string
     */
    protected function dia($dias)
    {
        return $this->hoy->copy()->addDays($dias)->format('Y-m-d');
    }

    /**
     * Una caja con sus movimientos. Cada movimiento es [ingreso, egreso] o [ingreso, egreso,
     * días hasta liquidar]: con días, el ingreso queda a liquidar (tarjetas y QR) hasta esa fecha.
     *
     * @param string $nombre
     * @param int|null $moneda_id
     * @param bool $abierta
     * @param array $movimientos
     * @param int|null $user_id
     * @return int
     */
    protected function caja($nombre, $moneda_id, $abierta, array $movimientos, $user_id = null)
    {
        $user_id = $user_id ?: $this->comercio->id;

        $caja_id = DB::table('cajas')->insertGetId([
            'num'        => DB::table('cajas')->where('user_id', $user_id)->count() + 1,
            'name'       => $nombre,
            'moneda_id'  => $moneda_id,
            'abierta'    => $abierta ? 1 : 0,
            'user_id'    => $user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($movimientos as $movimiento) {
            $liquida = isset($movimiento[2]) ? $this->dia($movimiento[2]) : null;

            DB::table('movimiento_cajas')->insert([
                'caja_id'                    => $caja_id,
                'apertura_caja_id'           => 0,
                'ingreso'                    => $movimiento[0],
                'egreso'                     => $movimiento[1],
                'fecha_liquidacion_estimada' => $liquida,
                'monto_neto_estimado'        => is_null($liquida) ? null : ($movimiento[0] ?: $movimiento[1]),
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]);
        }

        return $caja_id;
    }

    /**
     * Un concepto de gasto del comercio.
     *
     * @param string $nombre
     * @return ExpenseConcept
     */
    protected function concepto($nombre)
    {
        return ExpenseConcept::create([
            'num'     => ExpenseConcept::where('user_id', $this->comercio->id)->count() + 1,
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Las columnas de gasto de una tarea.
     *
     * @param ExpenseConcept $concepto
     * @param float|null $monto
     * @return array
     */
    protected function gasto(ExpenseConcept $concepto, $monto)
    {
        return ['expense_concept_id' => $concepto->id, 'expense_amount' => $monto];
    }

    /**
     * Una tarea puntual de la Agenda.
     *
     * @param string $detalle
     * @param int $dias Fecha relativa a hoy
     * @param array $extra
     * @param int|null $user_id
     * @return int
     */
    protected function tarea($detalle, $dias, array $extra = [], $user_id = null)
    {
        return DB::table('pendings')->insertGetId(array_merge([
            'detalle'           => $detalle,
            'fecha_realizacion' => $this->dia($dias) . ' 00:00:00',
            'es_recurrente'     => 0,
            'user_id'           => $user_id ?: $this->comercio->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $extra));
    }

    /**
     * Una tarea recurrente cada 1 unidad (day, week, month, year) desde una fecha base relativa a hoy.
     *
     * @param string $detalle
     * @param int $dias_base
     * @param string $slug
     * @param array $extra
     * @return int
     */
    protected function tarea_recurrente($detalle, $dias_base, $slug, array $extra = [])
    {
        return $this->tarea($detalle, $dias_base, array_merge([
            'es_recurrente'        => 1,
            'unidad_frecuencia_id' => $this->unidad($slug)->id,
            'cantidad_frecuencia'  => 1,
        ], $extra));
    }

    /**
     * Unidad de frecuencia por slug. El fixture no siembra unidad_frecuencias y el modelo no
     * declara $fillable: se crea atributo por atributo, como en AgendaTestCase.
     *
     * @param string $slug
     * @return UnidadFrecuencia
     */
    protected function unidad($slug)
    {
        $unidad = UnidadFrecuencia::where('slug', $slug)->first();

        if (!is_null($unidad)) {
            return $unidad;
        }

        $unidad = new UnidadFrecuencia();
        $unidad->name = ucfirst($slug);
        $unidad->slug = $slug;
        $unidad->save();

        return $unidad;
    }

    /**
     * Un cheque con fecha de pago relativa a hoy.
     *
     * @param string $tipo 'recibido' | 'emitido'
     * @param int $dias_fecha_pago
     * @param float $monto
     * @param array $extra
     * @param int|null $user_id
     * @return int
     */
    protected function cheque($tipo, $dias_fecha_pago, $monto, array $extra = [], $user_id = null)
    {
        return DB::table('cheques')->insertGetId(array_merge([
            'tipo'       => $tipo,
            'amount'     => $monto,
            'fecha_pago' => $this->dia($dias_fecha_pago),
            'user_id'    => $user_id ?: $this->comercio->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /**
     * Una cuota de plan de pago del cliente, con vencimiento relativo a hoy.
     *
     * @param Client $client
     * @param int $dias_vencimiento
     * @param float $monto
     * @param array $extra
     * @return int
     */
    protected function cuota(Client $client, $dias_vencimiento, $monto, array $extra = [])
    {
        return DB::table('payment_plan_cuotas')->insertGetId(array_merge([
            'payment_plan_id'   => 0,
            'numero_cuota'      => 1,
            'fecha_vencimiento' => $this->dia($dias_vencimiento),
            'amount'            => $monto,
            'estado'            => 'pendiente',
            'client_id'         => $client->id,
            'user_id'           => $client->user_id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $extra));
    }

    /**
     * La cuenta corriente de un cliente o proveedor (credit_accounts es la fuente de la deuda).
     *
     * @param string $model_name
     * @param int $model_id
     * @param float $saldo
     * @param int $moneda_id
     * @param int|null $user_id
     * @return CreditAccount
     */
    protected function deuda($model_name, $model_id, $saldo, $moneda_id = 1, $user_id = null)
    {
        return CreditAccount::create([
            'model_name' => $model_name,
            'model_id'   => $model_id,
            'saldo'      => $saldo,
            'moneda_id'  => $moneda_id,
            'user_id'    => $user_id ?: $this->comercio->id,
        ]);
    }

    /**
     * Un cliente del comercio con teléfono (el recordatorio lo necesita).
     *
     * @param string $nombre
     * @param string $phone
     * @return Client
     */
    protected function cliente_con_telefono($nombre, $phone = '5493416009988')
    {
        return Client::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
            'phone'   => $phone,
        ]);
    }

    /**
     * Una venta impaga del cliente con su movimiento de cuenta corriente, con la antigüedad
     * pedida: lo que VentasSinCobrarHelper cuenta como venta sin cobrar.
     *
     * @param Client $client
     * @param int $dias_de_antiguedad
     * @param float $monto
     * @return Sale
     */
    protected function venta_sin_cobrar(Client $client, $dias_de_antiguedad, $monto = 1000)
    {
        $momento = now()->subDays($dias_de_antiguedad);

        $sale = Sale::create([
            'user_id'    => $client->user_id,
            'client_id'  => $client->id,
            'moneda_id'  => 1,
            'total'      => $monto,
            'terminada'  => 1,
            'created_at' => $momento,
            'updated_at' => $momento,
        ]);

        CurrentAcount::create([
            'user_id'    => $client->user_id,
            'client_id'  => $client->id,
            'sale_id'    => $sale->id,
            'moneda_id'  => 1,
            'debe'       => $monto,
            'haber'      => 0,
            'saldo'      => $monto,
            'status'     => 'sin_pagar',
            'created_at' => $momento,
            'updated_at' => $momento,
        ]);

        return $sale;
    }

    /**
     * La configuración del bot de WhatsApp del comercio.
     *
     * @param bool $activa
     * @return WhatsappBotConfig
     */
    protected function config_del_bot($activa)
    {
        return WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-caja',
            'phone_number_id'    => 'caja-' . uniqid(),
            'webhook_secret'     => 'secreto-caja',
            'is_active'          => $activa,
            'ai_enabled_default' => false,
        ]);
    }

    /**
     * Un chat del cliente con la ventana de 24 h abierta (último entrante hace dos horas).
     *
     * @param Client $client
     * @return WhatsappChat
     */
    protected function chat_abierto(Client $client)
    {
        return WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'client_id'       => $client->id,
            'phone'           => $client->phone,
            'ai_enabled'      => false,
            'unread_count'    => 0,
            'last_message_at' => now()->subHours(2),
            'last_inbound_at' => now()->subHours(2),
        ]);
    }

    /**
     * Una fila de `a_pagar.proximos` que sale de la Agenda.
     *
     * @param string $clave Clave de la tarea en $this->s
     * @param string $detalle
     * @param string $concepto
     * @param int $dias
     * @param float|null $monto
     * @return array
     */
    protected function proximo_de_agenda($clave, $detalle, $concepto, $dias, $monto)
    {
        return [
            'origen'           => 'agenda',
            'pending_id'       => $this->s[$clave],
            'cheque_id'        => null,
            'detalle'          => $detalle,
            'concepto'         => $concepto,
            'proveedor'        => null,
            'banco'            => null,
            'numero'           => null,
            'fecha'            => $this->dia($dias),
            'dias_para_vencer' => $dias,
            'monto'            => $monto,
        ];
    }
}
