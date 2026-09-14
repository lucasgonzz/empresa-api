<?php

namespace Tests\Feature\Mostrador;

use App\Models\Caja;
use App\Models\CompanyPerformance;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\DebtSnapshot;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseConcept;
use App\Models\ProviderOrder;
use App\Services\Mostrador\RecolectorDeHechos;
use App\Services\Mostrador\RecolectorDia;
use Illuminate\Support\Facades\DB;

/**
 * Misión modulo-ia-mostrador — P2: el recolector del "Rendimiento de ayer".
 *
 * Sobre un comercio sembrado a mano (tres ventas ayer en dos sucursales, una a
 * cuenta corriente, una venta la semana pasada y otra hace tres días, un pago de un
 * cliente, una nota de crédito, un artículo que volvió a venderse tras 80 días, uno
 * que quedó en cero, una compra, dos gastos, movimientos y un cierre de caja), cada
 * número del JSON es el que se sembró.
 */
class Recolector_dia_Test extends MostradorTestCase
{
    /** @var array Lo sembrado, para que los asserts nombren ids reales */
    protected $s = [];

    /**
     * Siembra el escenario completo del día.
     *
     * @return void
     */
    protected function sembrar_el_dia()
    {
        $central = $this->sucursal('Casa central');
        $norte   = $this->sucursal('Norte');

        $martillo = $this->articulo('Martillo', ['stock' => 0, 'stock_min' => 5]);
        $pinza    = $this->articulo('Pinza', ['stock' => 10]);
        $cuchara  = $this->articulo('Cuchara', ['stock' => 4, 'stock_min' => 5]);
        $destornillador = $this->articulo('Destornillador', ['stock' => 20]);

        $perez = $this->cliente('Pérez');
        $lopez = $this->cliente('López');
        $gomez = $this->cliente('Gómez');

        $juan = $this->empleado();
        $juan->name = 'Juan';
        $juan->save();

        // Tres ventas ayer.
        $this->venta($this->ayer_a_las(10), [[$martillo, 2, 1000]], [
            'address_id' => $central->id,
            'total_cost' => 1000,
        ]);

        $venta_cc = $this->venta($this->ayer_a_las(11), [[$pinza, 1, 500], [$martillo, 1, 1000]], [
            'address_id'  => $norte->id,
            'employee_id' => $juan->id,
            'client_id'   => $lopez->id,
            'total_cost'  => 700,
        ]);

        // El movimiento de cuenta corriente de esa venta: es lo que la hace "a cuenta".
        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $lopez->id,
            'sale_id'    => $venta_cc->id,
            'debe'       => 1500,
            'status'     => 'sin_pagar',
            'created_at' => $this->ayer_a_las(11),
        ]);

        $venta_transferencia = $this->venta($this->ayer_a_las(15), [[$destornillador, 4, 250]], [
            'address_id' => $central->id,
            'total_cost' => 600,
        ]);

        $transferencia = CurrentAcountPaymentMethod::where('name', 'Transferencia')->first();

        if (!$transferencia) {
            $transferencia = CurrentAcountPaymentMethod::forceCreate(['name' => 'Transferencia']);
        }

        DB::table('current_acount_payment_method_sale')->insert([
            'sale_id'                          => $venta_transferencia->id,
            'current_acount_payment_method_id' => $transferencia->id,
            'amount'                           => 1000,
            'created_at'                       => $this->ayer_a_las(15),
            'updated_at'                       => $this->ayer_a_las(15),
        ]);

        // La semana pasada, mismo día: una venta. Hace tres días: otra.
        $this->venta($this->ayer->copy()->subDays(7)->setTime(12, 0), [[$pinza, 2, 500]]);
        $this->venta($this->ayer->copy()->subDays(3)->setTime(12, 0), [[$pinza, 1, 500]]);

        // El destornillador se había vendido por última vez hace 80 días; el martillo hace 10.
        $this->venta($this->ayer->copy()->subDays(80)->setTime(12, 0), [[$destornillador, 1, 250]]);
        $this->venta($this->ayer->copy()->subDays(10)->setTime(12, 0), [[$martillo, 1, 1000]]);

        // Nota de crédito ayer.
        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $perez->id,
            'haber'      => 200,
            'status'     => 'nota_credito',
            'created_at' => $this->ayer_a_las(16),
        ]);

        // Pagos: Pérez ayer, López hace 20 días, Gómez nunca.
        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $perez->id,
            'haber'      => 300,
            'status'     => 'pago_from_client',
            'created_at' => $this->ayer_a_las(12),
        ]);

        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $lopez->id,
            'haber'      => 100,
            'status'     => 'pago_from_client',
            'created_at' => $this->ayer->copy()->subDays(20)->setTime(12, 0),
        ]);

        // Deudas (credit_accounts es la fuente de verdad).
        foreach ([[$perez, 900], [$lopez, 300], [$gomez, 50]] as $par) {
            CreditAccount::create([
                'model_name' => 'client',
                'model_id'   => $par[0]->id,
                'saldo'      => $par[1],
                'moneda_id'  => 1,
                'user_id'    => $this->comercio->id,
            ]);
        }

        DebtSnapshot::create(['user_id' => $this->comercio->id, 'date' => $this->ayer->format('Y-m-d'), 'deuda_clientes' => 1250]);
        DebtSnapshot::create(['user_id' => $this->comercio->id, 'date' => $this->ayer->copy()->subDay()->format('Y-m-d'), 'deuda_clientes' => 1500]);

        // Una compra a un proveedor.
        $acme = $this->proveedor_nuevo('Acme');

        ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $acme->id,
            'total'       => 5000,
            'created_at'  => $this->ayer_a_las(9),
        ]);

        // Dos gastos: uno con categoría, otro sin.
        $servicios = ExpenseCategory::create(['name' => 'Servicios', 'user_id' => $this->comercio->id]);
        $luz  = ExpenseConcept::create(['num' => 1, 'name' => 'Luz', 'user_id' => $this->comercio->id, 'expense_category_id' => $servicios->id]);
        $cafe = ExpenseConcept::create(['num' => 2, 'name' => 'Café', 'user_id' => $this->comercio->id]);

        Expense::create(['user_id' => $this->comercio->id, 'expense_concept_id' => $luz->id, 'amount' => 700, 'created_at' => $this->ayer_a_las(13)]);
        Expense::create(['user_id' => $this->comercio->id, 'expense_concept_id' => $cafe->id, 'amount' => 300, 'created_at' => $this->ayer_a_las(14)]);

        // Caja: ingresos, egresos y un cierre.
        $caja = Caja::create(['num' => 1, 'name' => 'Principal', 'user_id' => $this->comercio->id]);

        $apertura_id = DB::table('apertura_cajas')->insertGetId([
            'caja_id'        => $caja->id,
            'saldo_apertura' => 0,
            'cerrada_at'     => $this->ayer_a_las(20),
            'created_at'     => $this->ayer_a_las(8),
            'updated_at'     => $this->ayer_a_las(20),
        ]);

        DB::table('movimiento_cajas')->insert([
            ['caja_id' => $caja->id, 'apertura_caja_id' => $apertura_id, 'ingreso' => 4500, 'egreso' => null, 'created_at' => $this->ayer_a_las(10), 'updated_at' => $this->ayer_a_las(10)],
            ['caja_id' => $caja->id, 'apertura_caja_id' => $apertura_id, 'ingreso' => null, 'egreso' => 1000, 'created_at' => $this->ayer_a_las(14), 'updated_at' => $this->ayer_a_las(14)],
        ]);

        $this->s = compact('central', 'norte', 'martillo', 'pinza', 'cuchara', 'destornillador', 'perez', 'lopez', 'gomez', 'juan', 'acme');
    }

    /**
     * @group mostrador
     * @test
     */
    public function la_fecha_por_defecto_es_ayer_para_dia_y_tienda_y_hoy_para_compras_y_stock()
    {
        $hoy = \Carbon\Carbon::parse('2026-09-14 10:30:00');

        $this->assertSame('2026-09-13', RecolectorDeHechos::fecha_por_defecto('dia', $hoy)->format('Y-m-d'));
        $this->assertSame('2026-09-13', RecolectorDeHechos::fecha_por_defecto('tienda', $hoy)->format('Y-m-d'));
        $this->assertSame('2026-09-14', RecolectorDeHechos::fecha_por_defecto('compras', $hoy)->format('Y-m-d'));
        $this->assertSame('2026-09-14', RecolectorDeHechos::fecha_por_defecto('stock', $hoy)->format('Y-m-d'));
    }

    /**
     * @group mostrador
     * @test
     */
    public function un_tipo_desconocido_no_se_recolecta()
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RecolectorDeHechos())->recolectar($this->comercio, 'ventas', $this->ayer);
    }

    /**
     * @group mostrador
     * @test
     */
    public function las_ventas_de_ayer_con_sus_tres_aperturas()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertTrue($h['aplica']);
        $this->assertSame($this->ayer->format('Y-m-d'), $h['fecha']);
        $this->assertSame(RecolectorDia::DIAS_SEMANA[$this->ayer->dayOfWeek], $h['dia_semana']);

        $v = $h['ventas'];
        $this->assertSame(3, $v['cantidad']);
        $this->assertEquals(4500.00, $v['total']);
        $this->assertEquals(1500.00, $v['ticket_promedio']);
        $this->assertEquals(1500.00, $v['a_cuenta_corriente']);
        $this->assertEquals(200.00, $v['devoluciones']);

        $this->assertSame([
            ['address_id' => $this->s['central']->id, 'nombre' => 'Casa central', 'cantidad' => 2, 'total' => 3000.0],
            ['address_id' => $this->s['norte']->id, 'nombre' => 'Norte', 'cantidad' => 1, 'total' => 1500.0],
        ], $v['por_sucursal']);

        $efectivo = CurrentAcountPaymentMethod::find(RecolectorDia::METODO_PAGO_DEFAULT_ID);

        $this->assertSame([
            ['metodo' => $efectivo ? $efectivo->name : 'Método #3', 'total' => 2000.0],
            ['metodo' => 'Cuenta corriente', 'total' => 1500.0],
            ['metodo' => 'Transferencia', 'total' => 1000.0],
        ], $v['por_metodo_pago']);

        $this->assertSame([
            ['empleado' => 'Dueño mostrador', 'cantidad' => 2, 'total' => 3000.0],
            ['empleado' => 'Juan', 'cantidad' => 1, 'total' => 1500.0],
        ], $v['por_vendedor']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function la_comparacion_mira_el_mismo_dia_de_la_semana_pasada_y_el_promedio_de_30_dias()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame([
            'fecha'    => $this->ayer->copy()->subDays(7)->format('Y-m-d'),
            'cantidad' => 1,
            'total'    => 1000.0,
        ], $h['comparacion']['mismo_dia_semana_anterior']);

        // 3 ventas de ayer ($4.500) + 1 de la semana pasada ($1.000) + 1 de hace tres días ($500)
        // + 1 de hace diez días ($1.000) = 6 ventas, $7.000 en la ventana de 30 días.
        $this->assertEquals(round(6 / 30, 1), $h['comparacion']['promedio_diario_30_dias']['cantidad']);
        $this->assertEquals(round(7000 / 30, 2), $h['comparacion']['promedio_diario_30_dias']['total']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function los_articulos_mas_vendidos_los_que_volvieron_y_los_que_quedaron_en_cero()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);
        $a = $h['articulos'];

        // Destornillador 4, Martillo 3, Pinza 1 (por cantidad, desc).
        $this->assertSame(
            [$this->s['destornillador']->id, $this->s['martillo']->id, $this->s['pinza']->id],
            array_column($a['mas_vendidos'], 'article_id')
        );
        $this->assertEquals(3.0, $a['mas_vendidos'][1]['cantidad']);
        $this->assertEquals(3000.00, $a['mas_vendidos'][1]['total']);
        $this->assertSame('Martillo', $a['mas_vendidos'][1]['nombre']);
        $this->assertNull($a['mas_vendidos'][1]['imagen_url']);

        // Solo el destornillador volvió tras 80 días; el martillo se vendió hace 10.
        $this->assertCount(1, $a['volvieron_a_venderse']);
        $this->assertSame($this->s['destornillador']->id, $a['volvieron_a_venderse'][0]['article_id']);
        $this->assertSame(80, $a['volvieron_a_venderse'][0]['dias_sin_venderse']);
        $this->assertEquals(4.0, $a['volvieron_a_venderse'][0]['cantidad']);

        $this->assertSame([
            ['article_id' => $this->s['martillo']->id, 'nombre' => 'Martillo', 'stock' => 0.0, 'stock_minimo' => 5],
        ], $a['quedaron_sin_stock']);

        // Martillo (0 < 5) y Cuchara (4 < 5).
        $this->assertSame(2, $a['bajo_minimo_total']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function las_cobranzas_con_la_deuda_y_su_variacion()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);
        $c = $h['cobranzas'];

        $this->assertSame([
            ['client_id' => $this->s['perez']->id, 'nombre' => 'Pérez', 'monto' => 300.0],
        ], $c['pagos_recibidos']);
        $this->assertEquals(300.00, $c['total_cobrado']);
        $this->assertEquals(1250.00, $c['deuda_clientes_total']);
        $this->assertEquals(-250.00, $c['deuda_clientes_variacion']);

        $this->assertSame([
            ['client_id' => $this->s['perez']->id, 'nombre' => 'Pérez', 'saldo' => 900.0, 'dias_sin_pagar' => 0],
            ['client_id' => $this->s['lopez']->id, 'nombre' => 'López', 'saldo' => 300.0, 'dias_sin_pagar' => 20],
            ['client_id' => $this->s['gomez']->id, 'nombre' => 'Gómez', 'saldo' => 50.0, 'dias_sin_pagar' => null],
        ], $c['clientes_con_mas_deuda']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function compras_gastos_caja_tienda_y_resultado()
    {
        $this->sembrar_el_dia();

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(['cantidad' => 1, 'total' => 5000.0, 'proveedores' => ['Acme']], $h['compras_y_gastos']['compras']);

        $this->assertSame([
            'cantidad'      => 2,
            'total'         => 1000.0,
            'por_categoria' => [
                ['categoria' => 'Servicios', 'total' => 700.0],
                ['categoria' => 'Café', 'total' => 300.0],
            ],
        ], $h['compras_y_gastos']['gastos']);

        $this->assertSame([
            'ingresos' => 4500.0,
            'egresos'  => 1000.0,
            'cierres'  => [['caja' => 'Principal', 'diferencia' => null]],
        ], $h['caja']);

        $this->assertSame(['tiene_tienda' => false, 'pedidos' => 0, 'total' => 0.0], $h['tienda']);

        // Sin fila de company_performances: vendido 4500 - devolución 200 - costo 2300 = 2000;
        // menos los gastos (1000) = 1000.
        $this->assertSame(['ingresos_netos' => 2000.0, 'rentabilidad' => 1000.0], $h['resultado']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function con_la_fila_de_company_performances_el_resultado_sale_de_ahi()
    {
        $this->sembrar_el_dia();

        CompanyPerformance::create([
            'user_id'        => $this->comercio->id,
            'year'           => $this->ayer->year,
            'month'          => $this->ayer->month,
            'day'            => $this->ayer->day,
            'from_today'     => 1,
            'ingresos_netos' => 999.5,
            'rentabilidad'   => 555.25,
        ]);

        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(['ingresos_netos' => 999.5, 'rentabilidad' => 555.25], $h['resultado']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function un_comercio_sin_movimiento_devuelve_ceros_y_listas_vacias_nunca_omite_claves()
    {
        $h = (new RecolectorDia())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(0, $h['ventas']['cantidad']);
        $this->assertEquals(0.0, $h['ventas']['total']);
        $this->assertNull($h['ventas']['ticket_promedio']);
        $this->assertSame([], $h['ventas']['por_sucursal']);
        $this->assertSame([], $h['articulos']['mas_vendidos']);
        $this->assertSame([], $h['cobranzas']['pagos_recibidos']);
        $this->assertNull($h['cobranzas']['deuda_clientes_variacion']);
        $this->assertSame([], $h['compras_y_gastos']['compras']['proveedores']);
        $this->assertSame([], $h['caja']['cierres']);

        foreach (['aplica', 'fecha', 'dia_semana', 'ventas', 'comparacion', 'articulos', 'cobranzas', 'compras_y_gastos', 'caja', 'tienda', 'resultado'] as $clave) {
            $this->assertArrayHasKey($clave, $h);
        }
    }
}
